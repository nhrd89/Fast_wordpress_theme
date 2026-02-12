#!/bin/bash
# PinLightning Performance Budget Check
# Runs after deploy to verify scores haven't regressed

set -e

API_KEY="${PAGESPEED_API_KEY:-}"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo "========================================="
echo "  PinLightning Performance Budget Check"
echo "========================================="

URLS=(
    "https://cheerfultalks.com/chic-comfort-19-outfits-to-rock-your-linen-pants/"
)

FAILED=0

for URL in "${URLS[@]}"; do
    echo ""
    echo "Testing: $URL"
    echo "-----------------------------------------"

    API_URL="https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url=$(python3 -c "import urllib.parse; print(urllib.parse.quote('$URL', safe=''))")&strategy=mobile&category=PERFORMANCE&category=ACCESSIBILITY&category=BEST_PRACTICES&category=SEO"

    if [ -n "$API_KEY" ]; then
        API_URL="${API_URL}&key=${API_KEY}"
    fi

    RESPONSE=$(curl -s "$API_URL")

    PERF=$(echo "$RESPONSE" | python3 -c "import sys,json; d=json.load(sys.stdin); print(int(d['lighthouseResult']['categories']['performance']['score']*100))" 2>/dev/null || echo "0")
    A11Y=$(echo "$RESPONSE" | python3 -c "import sys,json; d=json.load(sys.stdin); print(int(d['lighthouseResult']['categories']['accessibility']['score']*100))" 2>/dev/null || echo "0")
    BP=$(echo "$RESPONSE" | python3 -c "import sys,json; d=json.load(sys.stdin); print(int(d['lighthouseResult']['categories']['best-practices']['score']*100))" 2>/dev/null || echo "0")
    SEO=$(echo "$RESPONSE" | python3 -c "import sys,json; d=json.load(sys.stdin); print(int(d['lighthouseResult']['categories']['seo']['score']*100))" 2>/dev/null || echo "0")

    FCP=$(echo "$RESPONSE" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['lighthouseResult']['audits']['first-contentful-paint']['numericValue'])" 2>/dev/null || echo "0")
    LCP=$(echo "$RESPONSE" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['lighthouseResult']['audits']['largest-contentful-paint']['numericValue'])" 2>/dev/null || echo "0")
    TBT=$(echo "$RESPONSE" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['lighthouseResult']['audits']['total-blocking-time']['numericValue'])" 2>/dev/null || echo "0")
    CLS=$(echo "$RESPONSE" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['lighthouseResult']['audits']['cumulative-layout-shift']['numericValue'])" 2>/dev/null || echo "0")

    echo "Performance:    $PERF/100 (min: 95)"
    echo "Accessibility:  $A11Y/100 (min: 95)"
    echo "Best Practices: $BP/100 (min: 95)"
    echo "SEO:            $SEO/100 (min: 95)"
    echo ""
    echo "FCP: ${FCP}ms  LCP: ${LCP}ms  TBT: ${TBT}ms  CLS: ${CLS}"

    if [ "$PERF" -lt 95 ]; then
        echo -e "${RED}FAIL: Performance $PERF < 95${NC}"
        FAILED=1
    fi
    if [ "$A11Y" -lt 95 ]; then
        echo -e "${RED}FAIL: Accessibility $A11Y < 95${NC}"
        FAILED=1
    fi
    if [ "$BP" -lt 95 ]; then
        echo -e "${RED}FAIL: Best Practices $BP < 95${NC}"
        FAILED=1
    fi
    if [ "$SEO" -lt 95 ]; then
        echo -e "${RED}FAIL: SEO $SEO < 95${NC}"
        FAILED=1
    fi

    LCP_INT=$(printf "%.0f" "$LCP")
    TBT_INT=$(printf "%.0f" "$TBT")
    if [ "$LCP_INT" -gt 1500 ]; then
        echo -e "${RED}FAIL: LCP ${LCP}ms > 1500ms budget${NC}"
        FAILED=1
    fi
    if [ "$TBT_INT" -gt 50 ]; then
        echo -e "${RED}FAIL: TBT ${TBT}ms > 50ms budget${NC}"
        FAILED=1
    fi
done

echo ""
echo "========================================="
if [ "$FAILED" -eq 1 ]; then
    echo -e "${RED}  PERFORMANCE BUDGET EXCEEDED${NC}"
    echo "  Review changes before merging!"
    echo "========================================="
    exit 1
else
    echo -e "${GREEN}  ALL CHECKS PASSED${NC}"
    echo "========================================="
    exit 0
fi
