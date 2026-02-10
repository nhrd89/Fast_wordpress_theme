#!/usr/bin/env bash
#
# PinLightning deploy script.
#
# Uploads the theme to the remote WordPress installation via rsync/SSH
# (with sftp batch fallback). Reads connection details from .env.
#
# Usage:
#   ./deploy.sh            # full deploy
#   ./deploy.sh --dry-run  # show what would be transferred
#
set -euo pipefail

# ---------------------------------------------------------------------------
# Colors
# ---------------------------------------------------------------------------
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m' # No color

info()    { echo -e "${CYAN}==> ${NC}$*"; }
success() { echo -e "${GREEN}==> ${NC}$*"; }
warn()    { echo -e "${YELLOW}==> WARNING: ${NC}$*" >&2; }
error()   { echo -e "${RED}==> ERROR: ${NC}$*" >&2; }

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ---------------------------------------------------------------------------
# Load .env
# ---------------------------------------------------------------------------
ENV_FILE="${SCRIPT_DIR}/.env"
if [[ ! -f "$ENV_FILE" ]]; then
    error ".env file not found at ${ENV_FILE}"
    echo "  Copy .env.example to .env and fill in your credentials." >&2
    exit 1
fi

# shellcheck source=/dev/null
source "$ENV_FILE"

# Validate required vars.
for var in SFTP_HOST SFTP_PORT SFTP_USER SFTP_PASS SFTP_REMOTE_PATH; do
    if [[ -z "${!var:-}" ]]; then
        error "${var} is not set in .env"
        exit 1
    fi
done

# ---------------------------------------------------------------------------
# Flags
# ---------------------------------------------------------------------------
DRY_RUN=false
if [[ "${1:-}" == "--dry-run" ]]; then
    DRY_RUN=true
    info "Dry-run mode — no files will be transferred."
fi

# ---------------------------------------------------------------------------
# Ensure sshpass is available
# ---------------------------------------------------------------------------
if ! command -v sshpass &>/dev/null; then
    info "sshpass not found, attempting to install..."
    if command -v apt-get &>/dev/null; then
        apt-get update -qq && apt-get install -y -qq sshpass 2>/dev/null
    elif command -v yum &>/dev/null; then
        yum install -y -q sshpass 2>/dev/null
    elif command -v brew &>/dev/null; then
        brew install hudochenkov/sshpass/sshpass 2>/dev/null
    fi

    if ! command -v sshpass &>/dev/null; then
        error "Could not install sshpass. Install it manually:"
        echo "  Ubuntu/Debian: sudo apt install sshpass"
        echo "  macOS:         brew install hudochenkov/sshpass/sshpass"
        exit 1
    fi
    success "sshpass installed."
fi

# ---------------------------------------------------------------------------
# Common SSH options
# ---------------------------------------------------------------------------
SSH_OPTS="-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR -p ${SFTP_PORT}"
export SSHPASS="${SFTP_PASS}"

# ---------------------------------------------------------------------------
# Excludes (shared between rsync and sftp fallback)
# ---------------------------------------------------------------------------
EXCLUDES=(
    ".git/"
    ".gitignore"
    ".env"
    ".env.*"
    ".env.example"
    "node_modules/"
    ".DS_Store"
    "deploy.sh"
    "package.json"
    "package-lock.json"
    ".github/"
    "*.md"
    ".idea/"
    ".vscode/"
)

# ---------------------------------------------------------------------------
# Deploy
# ---------------------------------------------------------------------------
info "Deploying PinLightning to ${SFTP_USER}@${SFTP_HOST}:${SFTP_PORT}"
info "Remote path: ${SFTP_REMOTE_PATH}"
echo ""

if command -v rsync &>/dev/null; then
    # -------------------------------------------------------------------
    # Strategy 1: rsync over SSH (preferred)
    # -------------------------------------------------------------------
    info "Using rsync over SSH..."

    RSYNC_FLAGS=(-avz --delete)

    if $DRY_RUN; then
        RSYNC_FLAGS+=(-n)
    fi

    for pattern in "${EXCLUDES[@]}"; do
        RSYNC_FLAGS+=(--exclude="${pattern}")
    done

    sshpass -e rsync \
        "${RSYNC_FLAGS[@]}" \
        -e "ssh ${SSH_OPTS}" \
        "${SCRIPT_DIR}/" \
        "${SFTP_USER}@${SFTP_HOST}:${SFTP_REMOTE_PATH}/"

else
    # -------------------------------------------------------------------
    # Strategy 2: sftp batch fallback
    # -------------------------------------------------------------------
    warn "rsync not found, falling back to sftp batch mode."
    warn "sftp cannot delete removed files — clean the remote manually if needed."

    BATCH_FILE=$(mktemp /tmp/pinlightning-sftp-XXXXXX)
    trap 'rm -f "$BATCH_FILE"' EXIT

    # Build exclude patterns for matching.
    should_exclude() {
        local file="$1"
        for pattern in "${EXCLUDES[@]}"; do
            # Strip trailing slash for directory patterns.
            local p="${pattern%/}"
            case "$file" in
                ${p}|${p}/*) return 0 ;;
            esac
            # Glob match (e.g. *.md).
            # shellcheck disable=SC2254
            case "$(basename "$file")" in
                ${p}) return 0 ;;
            esac
        done
        return 1
    }

    # Generate batch commands.
    echo "-mkdir ${SFTP_REMOTE_PATH}" >> "$BATCH_FILE"

    # Collect directories first, then files.
    while IFS= read -r -d '' dir; do
        rel="${dir#"${SCRIPT_DIR}"/}"
        if ! should_exclude "$rel"; then
            echo "-mkdir ${SFTP_REMOTE_PATH}/${rel}" >> "$BATCH_FILE"
        fi
    done < <(find "${SCRIPT_DIR}" -mindepth 1 -type d -print0 | sort -z)

    while IFS= read -r -d '' file; do
        rel="${file#"${SCRIPT_DIR}"/}"
        if ! should_exclude "$rel"; then
            remote_dir="${SFTP_REMOTE_PATH}/$(dirname "$rel")"
            echo "put ${file} ${remote_dir}/$(basename "$rel")" >> "$BATCH_FILE"
        fi
    done < <(find "${SCRIPT_DIR}" -type f -print0 | sort -z)

    echo "quit" >> "$BATCH_FILE"

    if $DRY_RUN; then
        info "Files that would be uploaded:"
        echo ""
        grep '^put ' "$BATCH_FILE" | while read -r _ local_path _; do
            echo "  ${local_path#"${SCRIPT_DIR}"/}"
        done
        echo ""
        TOTAL=$(grep -c '^put ' "$BATCH_FILE")
        info "${TOTAL} file(s) would be transferred."
    else
        sshpass -e sftp \
            -P "${SFTP_PORT}" \
            -oBatchMode=no \
            -oStrictHostKeyChecking=no \
            -oUserKnownHostsFile=/dev/null \
            -oLogLevel=ERROR \
            -b "$BATCH_FILE" \
            "${SFTP_USER}@${SFTP_HOST}"
    fi
fi

echo ""

# ---------------------------------------------------------------------------
# Post-deploy tasks (skip on dry-run)
# ---------------------------------------------------------------------------
if $DRY_RUN; then
    success "Dry-run complete. No files were changed on the server."
    exit 0
fi

success "Deploy complete."

# Flush theme cache.
if [[ -n "${CACHE_SECRET:-}" && -n "${SITE_URL:-}" ]]; then
    info "Flushing remote cache..."
    HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" \
        -X POST \
        -H "X-Cache-Secret: ${CACHE_SECRET}" \
        "${SITE_URL}/wp-json/pinlightning/v1/flush-cache" 2>/dev/null || echo "000")

    if [[ "$HTTP_CODE" == "200" ]]; then
        success "Cache flushed successfully."
    else
        warn "Cache flush returned HTTP ${HTTP_CODE}."
    fi
fi

# PageSpeed check.
if [[ -n "${PAGESPEED_API_KEY:-}" && -n "${SITE_URL:-}" ]]; then
    info "Requesting PageSpeed Insights score..."
    PSI_RESPONSE=$(curl -s "https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url=${SITE_URL}&key=${PAGESPEED_API_KEY}&category=performance&strategy=mobile" 2>/dev/null || echo "{}")

    PSI_SCORE=$(echo "$PSI_RESPONSE" | grep -o '"score":[0-9.]*' | head -1 | cut -d: -f2)
    if [[ -n "$PSI_SCORE" ]]; then
        PSI_PERCENT=$(echo "$PSI_SCORE * 100" | bc 2>/dev/null | cut -d. -f1 || echo "")
        if [[ -n "$PSI_PERCENT" ]]; then
            if [[ "$PSI_PERCENT" -ge 90 ]]; then
                success "PageSpeed score: ${GREEN}${PSI_PERCENT}/100${NC} (mobile)"
            elif [[ "$PSI_PERCENT" -ge 50 ]]; then
                warn "PageSpeed score: ${YELLOW}${PSI_PERCENT}/100${NC} (mobile)"
            else
                error "PageSpeed score: ${RED}${PSI_PERCENT}/100${NC} (mobile)"
            fi
        fi
    else
        warn "Could not retrieve PageSpeed score."
    fi
fi
