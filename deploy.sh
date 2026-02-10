#!/usr/bin/env bash
#
# PinLightning SFTP deploy script.
#
# Uploads the theme to the remote WordPress installation via SFTP.
# Reads connection details from .env in the same directory.
#
# Usage:
#   ./deploy.sh            # full deploy
#   ./deploy.sh --dry-run  # show what would be transferred
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ---------------------------------------------------------------------------
# Load .env
# ---------------------------------------------------------------------------
ENV_FILE="${SCRIPT_DIR}/.env"
if [[ ! -f "$ENV_FILE" ]]; then
    echo "Error: .env file not found at ${ENV_FILE}" >&2
    echo "Copy .env.example to .env and fill in your credentials." >&2
    exit 1
fi

# shellcheck source=/dev/null
source "$ENV_FILE"

# Validate required vars.
for var in SFTP_HOST SFTP_PORT SFTP_USER SFTP_PASS SFTP_REMOTE_PATH; do
    if [[ -z "${!var:-}" ]]; then
        echo "Error: ${var} is not set in .env" >&2
        exit 1
    fi
done

# ---------------------------------------------------------------------------
# Flags
# ---------------------------------------------------------------------------
DRY_RUN=false
if [[ "${1:-}" == "--dry-run" ]]; then
    DRY_RUN=true
    echo "==> Dry-run mode — no files will be transferred."
fi

# ---------------------------------------------------------------------------
# Files to exclude from upload
# ---------------------------------------------------------------------------
EXCLUDES=(
    ".git/"
    ".gitignore"
    ".env"
    ".env.*"
    "node_modules/"
    ".DS_Store"
    "deploy.sh"
    ".idea/"
    ".vscode/"
)

# Build lftp exclude flags.
EXCLUDE_FLAGS=""
for pattern in "${EXCLUDES[@]}"; do
    EXCLUDE_FLAGS="${EXCLUDE_FLAGS} --exclude ${pattern}"
done

# ---------------------------------------------------------------------------
# Deploy via lftp mirror
# ---------------------------------------------------------------------------
echo "==> Deploying PinLightning to ${SFTP_HOST}:${SFTP_PORT}${SFTP_REMOTE_PATH}"

LFTP_CMD="mirror --reverse --delete --verbose --parallel=4 ${EXCLUDE_FLAGS}"

if $DRY_RUN; then
    LFTP_CMD="${LFTP_CMD} --dry-run"
fi

lftp -u "${SFTP_USER},${SFTP_PASS}" \
     -p "${SFTP_PORT}" \
     -e "set sftp:auto-confirm yes; set net:max-retries 3; set net:reconnect-interval-base 5; ${LFTP_CMD} ${SCRIPT_DIR}/ ${SFTP_REMOTE_PATH}/; quit" \
     "sftp://${SFTP_HOST}"

echo ""
if $DRY_RUN; then
    echo "==> Dry-run complete. No files were changed on the server."
else
    echo "==> Deploy complete."

    # Flush theme cache if CACHE_SECRET is set.
    if [[ -n "${CACHE_SECRET:-}" && -n "${SITE_URL:-}" ]]; then
        echo "==> Flushing remote cache..."
        HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" \
            -X POST \
            -H "X-Cache-Secret: ${CACHE_SECRET}" \
            "${SITE_URL}/wp-json/pinlightning/v1/flush-cache")

        if [[ "$HTTP_CODE" == "200" ]]; then
            echo "    Cache flushed successfully."
        else
            echo "    Warning: cache flush returned HTTP ${HTTP_CODE}." >&2
        fi
    fi
fi
