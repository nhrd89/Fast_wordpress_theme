#!/usr/bin/env bash
# ──────────────────────────────────────────────────
# wp-check.sh — Remote WordPress health-check tool
# Uses WP REST API + PageSpeed Insights
#
# Usage:
#   bash wp-check.sh <command> [url]
#
# Commands:
#   plugins     List all plugins with status
#   theme       Show active theme
#   posts       List 5 most recent posts
#   settings    Show site title, tagline, etc.
#   screenshot  Capture a PageSpeed screenshot → screenshot.png
#   speed       Full Lighthouse performance metrics
#
# The optional [url] overrides TEST_URL for screenshot/speed.
# ──────────────────────────────────────────────────

set -euo pipefail

# ── Colors ──
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

# ── Load .env ──
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${SCRIPT_DIR}/.env"

if [[ ! -f "$ENV_FILE" ]]; then
    echo -e "${RED}Error: .env file not found at ${ENV_FILE}${NC}"
    exit 1
fi

# shellcheck disable=SC1090
source "$ENV_FILE"

# ── Validate required vars ──
for var in SITE_URL WP_USER WP_APP_PASSWORD; do
    if [[ -z "${!var:-}" ]]; then
        echo -e "${RED}Error: ${var} is not set in .env${NC}"
        exit 1
    fi
done

# ── Defaults ──
DEFAULT_TEST_URL="https://cheerfultalks.com/wanderweave2/capture-the-aesthetic-15-beautiful-mountain-landscapes-for-photography/"
TEST_URL="${2:-$DEFAULT_TEST_URL}"
COMMAND="${1:-}"

# ── Helper: WP REST request ──
wp_api() {
    local endpoint="$1"
    curl -s -u "${WP_USER}:${WP_APP_PASSWORD}" "${SITE_URL}/wp-json${endpoint}"
}

# ── Helper: Pretty JSON ──
pretty() {
    python3 -m json.tool 2>/dev/null || echo "(failed to parse JSON)"
}

# ── Helper: Color a score ──
color_score() {
    local score=$1
    if (( score >= 90 )); then
        echo -e "${GREEN}${score}${NC}"
    elif (( score >= 50 )); then
        echo -e "${YELLOW}${score}${NC}"
    else
        echo -e "${RED}${score}${NC}"
    fi
}

# ── Commands ──

cmd_plugins() {
    echo -e "${CYAN}── Installed Plugins ──${NC}"
    wp_api "/wp/v2/plugins" | pretty
}

cmd_theme() {
    echo -e "${CYAN}── Themes ──${NC}"
    wp_api "/wp/v2/themes" | python3 -c "
import sys, json
themes = json.load(sys.stdin)
for t in themes:
    status = t.get('status', 'unknown')
    name = t.get('name', {})
    # name can be a dict with 'rendered' or a string
    if isinstance(name, dict):
        name = name.get('rendered', name.get('raw', ''))
    marker = ' ← ACTIVE' if status == 'active' else ''
    print(f'  [{status:>8}] {name}{marker}')
" 2>/dev/null || wp_api "/wp/v2/themes" | pretty
}

cmd_posts() {
    echo -e "${CYAN}── Recent Posts (5) ──${NC}"
    wp_api "/wp/v2/posts?per_page=5" | python3 -c "
import sys, json
posts = json.load(sys.stdin)
for i, p in enumerate(posts, 1):
    title = p.get('title', {}).get('rendered', 'Untitled')
    link = p.get('link', '')
    status = p.get('status', '')
    date = p.get('date', '')[:10]
    print(f'  {i}. [{date}] {title}')
    print(f'     {link}')
    print(f'     Status: {status}')
    print()
" 2>/dev/null || wp_api "/wp/v2/posts?per_page=5" | pretty
}

cmd_settings() {
    echo -e "${CYAN}── Site Settings ──${NC}"
    wp_api "/wp/v2/settings" | python3 -c "
import sys, json
s = json.load(sys.stdin)
fields = [
    ('title', 'Site Title'),
    ('description', 'Tagline'),
    ('url', 'URL'),
    ('timezone_string', 'Timezone'),
    ('date_format', 'Date Format'),
    ('posts_per_page', 'Posts Per Page'),
    ('default_comment_status', 'Comments Default'),
]
for key, label in fields:
    val = s.get(key, '—')
    print(f'  {label:>20}: {val}')
" 2>/dev/null || wp_api "/wp/v2/settings" | pretty
}

cmd_screenshot() {
    if [[ -z "${PAGESPEED_API_KEY:-}" ]]; then
        echo -e "${RED}Error: PAGESPEED_API_KEY is not set in .env${NC}"
        exit 1
    fi

    echo -e "${CYAN}── Capturing Screenshot ──${NC}"
    echo -e "  URL: ${TEST_URL}"
    echo -e "  Calling PageSpeed Insights API..."

    local response
    response=$(curl -s "https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url=$(python3 -c "import urllib.parse; print(urllib.parse.quote('${TEST_URL}', safe=''))")&strategy=mobile&key=${PAGESPEED_API_KEY}")

    # Extract and decode screenshot
    echo "$response" | python3 -c "
import sys, json, base64

data = json.load(sys.stdin)

# Check for errors
if 'error' in data:
    print(f'  API Error: {data[\"error\"][\"message\"]}')
    sys.exit(1)

# Extract screenshot
audits = data.get('lighthouseResult', {}).get('audits', {})
screenshot = audits.get('final-screenshot', {}).get('details', {}).get('data', '')

if not screenshot:
    print('  No screenshot data found in response.')
    sys.exit(1)

# Remove data URI prefix if present
if ',' in screenshot:
    screenshot = screenshot.split(',', 1)[1]

# Decode and save
img_data = base64.b64decode(screenshot)
with open('screenshot.png', 'wb') as f:
    f.write(img_data)

print(f'  Saved: screenshot.png ({len(img_data):,} bytes)')

# Also print the perf score
score = data.get('lighthouseResult', {}).get('categories', {}).get('performance', {}).get('score', 0)
print(f'  Performance score: {int(score * 100)}')
"

    if [[ -f screenshot.png ]]; then
        echo -e "${GREEN}  ✓ Screenshot saved as screenshot.png${NC}"
    fi
}

cmd_speed() {
    if [[ -z "${PAGESPEED_API_KEY:-}" ]]; then
        echo -e "${RED}Error: PAGESPEED_API_KEY is not set in .env${NC}"
        exit 1
    fi

    echo -e "${CYAN}── PageSpeed Insights (Mobile) ──${NC}"
    echo -e "  URL: ${TEST_URL}"
    echo ""

    local response
    response=$(curl -s "https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url=$(python3 -c "import urllib.parse; print(urllib.parse.quote('${TEST_URL}', safe=''))")&strategy=mobile&key=${PAGESPEED_API_KEY}")

    echo "$response" | python3 -c "
import sys, json

data = json.load(sys.stdin)

if 'error' in data:
    print(f'  API Error: {data[\"error\"][\"message\"]}')
    sys.exit(1)

lr = data.get('lighthouseResult', {})
categories = lr.get('categories', {})
audits = lr.get('audits', {})

# Performance score
perf = categories.get('performance', {}).get('score', 0)
score = int(perf * 100)

# Core Web Vitals
metrics = {
    'FCP (First Contentful Paint)': audits.get('first-contentful-paint', {}).get('displayValue', '—'),
    'LCP (Largest Contentful Paint)': audits.get('largest-contentful-paint', {}).get('displayValue', '—'),
    'Speed Index': audits.get('speed-index', {}).get('displayValue', '—'),
    'TBT (Total Blocking Time)': audits.get('total-blocking-time', {}).get('displayValue', '—'),
    'CLS (Cumulative Layout Shift)': audits.get('cumulative-layout-shift', {}).get('displayValue', '—'),
}

# Score color
if score >= 90:
    indicator = '🟢'
elif score >= 50:
    indicator = '🟡'
else:
    indicator = '🔴'

print(f'  {indicator} Performance Score: {score}/100')
print()
for label, val in metrics.items():
    print(f'  {label:>35}: {val}')
print()

# Opportunities
opps = [(k, v) for k, v in audits.items()
        if v.get('details', {}).get('type') == 'opportunity'
        and v.get('details', {}).get('overallSavingsMs', 0) > 0]
if opps:
    print('  Opportunities:')
    for key, opp in sorted(opps, key=lambda x: -x[1]['details']['overallSavingsMs'])[:5]:
        savings = opp['details']['overallSavingsMs']
        title = opp.get('title', key)
        print(f'    - {title} (save ~{int(savings)}ms)')
"
}

# ── Usage ──
usage() {
    echo -e "${CYAN}wp-check.sh${NC} — Remote WordPress health-check tool"
    echo ""
    echo "Usage: bash wp-check.sh <command> [url]"
    echo ""
    echo "Commands:"
    echo "  plugins      List all plugins with status"
    echo "  theme        Show active theme"
    echo "  posts        List 5 most recent posts"
    echo "  settings     Show site title, tagline, etc."
    echo "  screenshot   Capture PageSpeed screenshot → screenshot.png"
    echo "  speed        Full Lighthouse performance metrics"
    echo ""
    echo "Options:"
    echo "  [url]        Override TEST_URL for screenshot/speed commands"
    echo "               Default: ${DEFAULT_TEST_URL}"
}

# ── Main ──
case "${COMMAND}" in
    plugins)    cmd_plugins ;;
    theme)      cmd_theme ;;
    posts)      cmd_posts ;;
    settings)   cmd_settings ;;
    screenshot) cmd_screenshot ;;
    speed)      cmd_speed ;;
    -h|--help)  usage ;;
    "")
        echo -e "${RED}Error: No command specified${NC}"
        echo ""
        usage
        exit 1
        ;;
    *)
        echo -e "${RED}Error: Unknown command '${COMMAND}'${NC}"
        echo ""
        usage
        exit 1
        ;;
esac
