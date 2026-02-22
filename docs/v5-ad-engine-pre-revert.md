# PinLightning Ad Engine v5 — Pre-Revert Documentation

> **Purpose:** Captures ALL work done in deploys #219–#245 before reverting to commit `18715b7` (deploy #218).
> **Source code preserved at:** commit `b952bc4` (HEAD before revert)
> **Date:** 2026-02-22
> **Branch:** `claude/setup-pinlightning-theme-5lwUG`

---

## 1A. Smart Ad Engine v5 — Complete Algorithm Documentation

**File:** `assets/js/smart-ads.js` — 1567 lines, single IIFE, 19 modules

### Initialization Flow

```
DOMContentLoaded
  → waitForDOMThenLCP()
    → PerformanceObserver watches 'largest-contentful-paint'
      → LCP fires → 200ms buffer → bootAfterLCP()
    → 3s safety net timeout (if LCP never fires)
  → bootAfterLCP()
    → requestIdleCallback → init()
```

### Boot Sequence (exact order)

1. IIFE starts immediately: reads config from `window.plAds`, bot detection via UA, device classification (mobile/tablet/desktop)
2. `DOMContentLoaded` → `waitForDOMThenLCP()`
3. LCP fires (or 3s safety timeout) → `bootAfterLCP()` → `requestIdleCallback` → `init()`
4. `init()`:
   - Load GPT script (`securepubads.g.doubleclick.net/tag/js/gpt.js`)
   - `initOverlays()` — interstitial + `enableSingleRequest()` + bottom anchor at 1s + top anchor at 1s + side rails at 1s
   - `setTimeout(initViewportAds, 2000)` — nav + sidebar + first content anchor
   - `initPauseRefresh()` — scroll pause detection
   - `setInterval(mainLoop, 300)` — main evaluation loop
   - `visibilitychange` + `pagehide` → `finalizeAndReport()`
   - `startHeartbeat()` — 3s beacon interval

### 19 Modules

#### MODULE 1: CONFIG
Constants and thresholds:
- `SLOT_BASE`: `/21849154601,22953639975/`
- `MIN_DISTANCE_PX`: 400 (min pixels between injected ads)
- `MIN_TIME_BETWEEN_ADS_MS`: 4000 (cooldown between injections)
- `MAX_SPEED_FOR_INJECT`: 500 (px/s — won't inject above this)
- `VIEWABLE_RATIO`: 0.5 (IAB standard — 50% visible)
- `VIEWABLE_MS`: 1000 (IAB standard — 1 second)
- `SPEED_READER`: 150 (px/s threshold for "reader" pattern)
- `SPEED_SCANNER`: 600 (px/s threshold for "scanner" pattern)
- `SPEED_FAST`: 1200 (px/s threshold for "fast-scanner")
- `PAUSE_THRESHOLD_MS`: 3000 (scroll pause triggers ad refresh)
- `REFRESH_COOLDOWN_MS`: 30000 (per-ad refresh cooldown)
- `MAX_REFRESHES`: 15 (session-wide refresh cap)
- `MAX_PAUSE_BANNERS`: 2
- `WALDO_TAGS`: `{ multi: 3, medium_tall: 2, small: 3 }` (8 total passback tags)

#### MODULE 2: STATE
Tracked variables: `gateOpen`, `gateOpenTime`, `totalInjected`, `totalViewable`, `totalRefreshes`, `lastInjectTime`, `lastInjectY`, `viewportAdsInjected`, `waldoPassbacks`, `waldoScriptLoaded`, `waldoScriptLoading`, `waldoCallbackQueue`, `scrollSpeed`, `scrollDirection`, `maxDepth`, `timeOnPage`, `visitorPattern`, `sessionId`, `adRecords[]`, `anchorElements[]`, `usedAnchors Set`, `usedWaldoTags{}`

#### MODULE 3: SCROLL SPEED TRACKER
- Samples scroll position every 300ms (via mainLoop)
- Weighted average of last 10 samples
- Tracks direction (up/down) and direction changes
- `sampleScrollSpeed()` updates `scrollSpeed` and `scrollDirection`

#### MODULE 4: VISITOR CLASSIFIER
- **bouncer**: `timeOnPage < 5s && maxDepth < 10%`
- **reader**: `scrollSpeed < 150px/s && timeOnPage > 15s`
- **scanner**: `scrollSpeed < 600px/s`
- **fast-scanner**: `scrollSpeed >= 600px/s`
- Classification runs every mainLoop iteration

#### MODULE 5: SIZE SELECTION ENGINE
- **Nav** (device-targeted): mobile → `320x100`, tablet → `728x90`, desktop → `970x250`
- **Sidebar** (desktop only): `160x600`, `300x250`
- **Content** (speed-based):
  - reader → `[[336,280],[300,250]]`
  - scanner → `[[300,600],[250,250],[300,250]]`
  - fast-scanner → `[[300,600]]`
  - too fast (>1200px/s) → `null` (skip injection)

#### MODULE 6: ENGAGEMENT GATE
Two paths to open the gate:
- **Path A**: `scrollDepth >= 3%` AND `timeOnPage >= 2s`
- **Path B**: `timeOnPage >= 4s` AND `scrollDepth < 1%` (for non-scrolling Pinterest users)

#### MODULE 7: VIEWPORT ADS
- Bypass engagement gate entirely
- Inject at 2s after init: nav slot + sidebar slot + first content anchor
- `initViewportAds()` — finds first `.ad-anchor` in viewport, injects immediately

#### MODULE 8: INJECTION DECISION ENGINE (`evaluateInjection()`)
Decision tree (in order):
1. **Emergency inject**: if gate open >10s and 0 ads injected → force inject
2. **Fast-scanner block**: if speed >1200px/s → skip
3. **Viewability wait**: if injected >0 and none viewable after 8s → skip
4. **Cooldown**: if <4s since last injection → skip
5. **Distance**: if <400px from last injected ad → skip
6. **Speed cap**: if >500px/s → skip
7. **Bidirectional predictive targeting**:
   - Scrolling down: lookahead = `scrollSpeed * 1.5` pixels ahead
   - Scrolling up: lookahead = `scrollSpeed * 1.0` pixels behind
   - Find best anchor in predicted viewport window

#### MODULE 9: AD INJECTOR (`injectAd()`)
1. Creates wrapper `<div>` with ad-zone class
2. GPT `googletag.defineSlot(SLOT_BASE + slotName, sizes, divId)`
3. `googletag.display(divId)`
4. Creates `adRecord`: `{ divId, slotName, sizes, anchor, injectTime, visibleMs: 0, viewable: false, refreshCount: 0, lastRefreshTime: 0, isPassback: false, passbackNetwork: null }`
5. Starts viewability tracking via IO

#### MODULE 10: PAUSE BANNER
- Injected at positions: `item3-after-p`, `item6-after-p`, `item9-after-p`, `item12-after-p`
- Only for `reader` pattern visitors
- Requires `timeOnPage > 20s`
- Max 2 per session
- Size: `300x250`

#### MODULE 11: VIEWABILITY TRACKER
- IntersectionObserver with thresholds: `[0, 0.1, 0.25, 0.5, 0.75, 1.0]`
- Accumulates time directly on `adRecord.visibleMs` (NOT a closure variable — prior bug)
- When `visibleMs >= 1000` and `intersectionRatio >= 0.5` → mark `adRecord.viewable = true`
- Retroactive checks at 500ms and 2s after injection (for ads that start visible)

#### MODULE 12: DUMMY MODE
- Colored placeholder boxes instead of real ads
- Colors by size: blue (300x250), green (300x600), orange (728x90), etc.
- Enabled via `window.plAds.dummy_mode`

#### MODULE 13: OVERLAYS (`initOverlays()`)
- `enableSingleRequest()` — batch all overlay slots
- `collapseEmptyDivs(true)` — hide unfilled slots
- **Interstitial**: `Ad.Plus-Interstitial` (GPT out-of-page, immediate)
- **Bottom anchor**: `Ad.Plus-Anchor` (GPT out-of-page, 1s delay)
- **Top anchor**: `Ad.Plus-AnchorSmall` (GPT out-of-page, 1s delay)
- **Side rails**: `Ad.Plus-SideAnchor` left + right (desktop ≥1200px, 1s delay)
- All overlays have 30s refresh intervals

#### MODULE 13b: WALDO TAG SELECTOR (`getNextWaldoTag()`)
- Size → pool mapping:
  - `[300,250]` or `[336,280]` or `[250,250]` → `multi` pool
  - `[300,600]` or `[160,600]` → `medium_tall` pool
  - `[320,100]` or `[728,90]` → `small` pool
- Fallback: if primary pool exhausted, try any remaining pool
- Returns `null` when all 8 tags used

#### MODULE 14: WALDO LAZY LOADER
- `loadWaldoScript()`: loads `cdn.thisiswaldo.com/static/js/24273.js` only on first isEmpty event
- Callback queue: functions queued before script loads, executed after
- `collapseAd(divId)`: sets `height:0; overflow:hidden; margin:0` (NOT `display:none` — breaks IO)
- `executeWaldoPassback(adRecord)`: calls `waldo.serve({slotId})` with try-catch
- `onSlotRenderEnded`: checks `event.isEmpty` → triggers passback if true

#### MODULE 15: VIDEO INJECTION
- PlayerPro inpage video
- Position: `intro-after-p3` (after 3rd paragraph in intro)
- Gate: `timeOnPage >= 5s` AND `scrollDepth >= 5%`

#### MODULE 16: PAUSE REFRESH
- Detects 3s scroll pause
- Finds most-visible ad (highest intersection ratio)
- Refreshes via `googletag.pubads().refresh([slot])`
- Per-ad cooldown: 30s
- Session max: 15 refreshes

#### MODULE 17: SESSION REPORTER (`buildSessionReport()`)
Beacon payload:
```json
{
  "sid": "session-id",
  "postId": 123,
  "device": "mobile|tablet|desktop",
  "timeOnPage": 45.2,
  "maxDepth": 0.78,
  "scrollSpeed": 234,
  "pattern": "reader|scanner|fast-scanner|bouncer",
  "dirChanges": 12,
  "itemsSeen": 15,
  "gateOpen": true,
  "viewportAdsInjected": 3,
  "totalInjected": 8,
  "totalViewable": 5,
  "viewabilityRate": 0.625,
  "totalRefreshes": 3,
  "fillRate": 0.875,
  "waldoPassbacks": 2,
  "overlays": { "anchor": {}, "topAnchor": {}, "interstitial": {}, "sideRails": {} },
  "zones": [{ "divId": "...", "slotName": "...", "sizes": [], "viewable": true, "visibleMs": 2340, "isPassback": false }]
}
```

#### MODULE 18: HEARTBEAT
- 3s interval via `setInterval`
- `navigator.sendBeacon` to `heartbeatEndpoint`
- Payload: current session state snapshot
- Max 3 consecutive failures → stops heartbeat

#### MODULE 19: MAIN LOOP (every 300ms)
```
sampleScrollSpeed()
  → classifyVisitor()
  → checkGate()
  → if gateOpen: evaluateInjection()
  → tryInjectVideo()
```

### Ad Zone Types Summary

| Type | Sizes | Trigger | Gate Required |
|------|-------|---------|---------------|
| Content display | 300x250, 336x280, 250x250, 300x600 | Scroll-driven | Yes |
| Nav | 320x100 (m), 728x90 (t), 970x250 (d) | Viewport at 2s | No |
| Sidebar | 160x600, 300x250 | Viewport at 2s (desktop) | No |
| Pause banner | 300x250 | Reader pattern, 20s+ | Yes |
| Video | PlayerPro inpage | 5s + 5% scroll | Yes |
| Bottom anchor | Out-of-page | 1s after init | No |
| Top anchor | Out-of-page | 1s after init | No |
| Interstitial | Out-of-page | Immediate | No |
| Side rails | Out-of-page | 1s (desktop ≥1200px) | No |

### Passback System (Waldo/Newor Media)

- **Site ID**: 24273
- **Script**: `cdn.thisiswaldo.com/static/js/24273.js`
- **Loading**: Lazy — only loads on first `isEmpty` event from GPT
- **Tag pools**: multi (3 tags), medium_tall (2 tags), small (3 tags) = 8 total
- **Size mapping**: `[300,250]/[336,280]/[250,250]` → multi; `[300,600]/[160,600]` → medium_tall; `[320,100]/[728,90]` → small
- **Fallback**: if primary pool exhausted, try any remaining pool
- **Error handling**: try-catch with collapse fallback
- **CRITICAL**: Waldo's header script hooks into GPT globally — MUST be lazy-loaded, never at page start

---

## 1B. Scroll-Engage.js — Complete Documentation

**File:** `assets/js/scroll-engage.js` — 1132 lines

### Psychology Triggers (10 documented techniques)

1. **Variable Ratio Schedule (Skinner)** — random 12–35s intervals between micro-interactions; unpredictable timing maximizes engagement
2. **Novelty Effect** — 8 different micro-interaction types, never same twice in a row
3. **Escalating Rewards** — depth-based message pools: early (0–30%), mid (30–60%), deep (60%+)
4. **Curiosity Gap** — peek-a-boo animation after 5s idle triggers "what's next?" response
5. **Peak-End Rule** — victory celebration at 100% progress creates positive memory
6. **Endowed Progress** — heart fill starts at 20%, making completion feel closer
7. **Zeigarnik Effect** — loss-aversion messages ("Don't miss the best ones below!")
8. **Reciprocity** — compliments trigger reciprocal engagement behavior
9. **Mere Exposure** — repeated character appearances build familiarity and trust
10. **Parasocial Bonding** — time-aware greetings create personal connection illusion

### Components

- **Animated video character**: 14 webm sprite clips — idle, catwalk, dance, welcome, victory, peekaboo, shhh, peace, blowkiss, beckon, surprise, excited, omg, clap
- **Heart fill indicator**: SVG with clip-path animation, 20% → 100% based on scroll depth
- **Speech bubble system**: Currently disabled (showSpeech() is a no-op)
- **AI Chat panel**: Floating panel connected to `window.__plChat` API
- **Sparkle effects**: Emoji particle system on milestones
- **Mood background**: Disabled — causes scroll performance lag

### State Machine

States: `idle` → `catwalk` → `dancing` → `welcome` → `victory` → `peekaboo` → `milestone` → `micro`

Transitions:
- Page load → idle → catwalk (entrance animation)
- First interaction → welcome
- 100% progress → victory
- 5s idle → peekaboo
- Milestone hit → milestone animation
- Random interval → micro-interaction

### Message System (MSG object)

- **4 depth zones**: early (0–30%), mid (30–60%), deep (60–90%), final (90–100%)
- **23+ message categories**: greetings, encouragement, milestones, tips, curiosity hooks, loss aversion, compliments, tap responses, rapid-tap responses, idle prompts, returning visitor, time-aware welcome
- **Time-aware welcome**: morning / afternoon / evening / night variants
- **Returning visitor recognition**: different messages for repeat visits

### AI Chat Integration

- Opens on: character tap, heart tap, or image tap
- **Image tap context**: sends `{ src, alt, caption, section, position, depth }` to chat API
- **Session management**: start/message/end API endpoints via `window.__plChat`
- **UI**: typing indicator, minimize/resume, message bubbles, floating panel
- **Endpoint**: `window.__plChat.endpoint`

### Interaction Tracking

- Character taps, heart taps, rapid tap detection (3+ taps in 2s)
- Image taps with full context (position, section, scroll depth)
- Exposed via `window.__plt` for visitor tracker plugin
- Metrics: `tapCount`, `heartTaps`, `imageTaps`, `chatOpened`, `chatMessages`

---

## 1C. Analytics Dashboard Documentation

### Data Flow

```
smart-ads.js sendBeacon
  → POST /pl/v1/ad-session (ad-data-recorder.php)
    → Validate + store raw session
    → pl_ad_aggregate_session() (ad-analytics-aggregator.php)
      → wp_options: pl_ad_stats_YYYY-MM-DD
        → Dashboard reads via pl_ad_get_stats_range()
```

### What's Tracked (per-day aggregate)

**Sessions**: device breakdown, referrer sources, languages, visitor patterns (reader/scanner/fast-scanner/bouncer), hourly distribution

**Engagement**: total time on page, average scroll depth, gate open rate

**Ad Performance**:
- Zones: activated, viewable, requests, fills, empty
- Clicks: display, anchor, interstitial, pause banner
- Fill rate, viewability rate
- Revenue estimation (based on eCPM × impressions)

**Overlay Performance**: anchor/top-anchor/interstitial — fired, filled, viewable, duration

**v5 Additions**: pause banners served, refresh count, video injections, avg scroll speed, bidirectional injection count

**Per-Zone Breakdown**: activations, filled, empty, viewable, visible_ms, clicks, passback count

**Per-Post Breakdown**: sessions, gate opens, ads filled, viewable, clicks, avg time

### Live Sessions (ad-live-sessions.php)

- 3s heartbeat from smart-ads.js
- Real-time session viewer in WP admin
- Columns: SID, post title, device, time on page, scroll depth, speed, pattern, ads injected, viewable, fill rate
- Auto-refresh with AJAX polling
- Session expiry: 60s without heartbeat

### Aggregator Details (ad-analytics-aggregator.php — 391 lines)

- Storage: `wp_options` table with key `pl_ad_stats_YYYY-MM-DD`
- Retention: 90 days, cleaned by daily WP cron
- Incremental: each session updates daily totals
- Thread-safe: uses `wp_cache_delete` before reads

---

## 1D. Deployment Changelog (#219–#245)

### Deploy #219 — `3a605c6` — chore: remove legacy analytics dashboard
- Deleted `inc/ad-analytics.php` (660 lines)
- Removed `require` from `functions.php`
- Replaced by new analytics aggregator + dashboard system

### Deploy #220 — `a7dbc31` — fix: ad engine bugs
- Viewability tracking fix (IO callback timing)
- Side-anchor slot naming fix
- Click inflation fix (deduplication)
- Zone aggregation fix in dashboard
- 60 lines changed in `smart-ads.js`

### Deploy #221 — `b5cea2b` — feat: full-article ad injection
- `engagement-breaks.php`: added `pl_inject_item_ads()` + `pl_inject_intro_ads()` inserting `.ad-anchor` divs throughout content
- `header.php`: nav ad anchor divs added
- `single.php`: sidebar ad anchor divs added

### Deploy #222 — `065f9c9` — feat: v4 smart-ads.js
- Major rewrite: multi-format zones, triple overlay (interstitial + bottom anchor + top anchor), pause refresh system
- 1223 lines changed

### Deploy #223 — `828af9d` — feat: v4 settings defaults + zone CSS
- `critical.css`: ad zone positioning styles
- `ad-engine.php`: new format toggles (`top_anchor`, `320x100`, `160x600`, `video`, `728x90`), relaxed engagement gate

### Deploy #224 — `0a4cf3d` — feat: v4 analytics
- Dashboard: top anchor tracking, revenue estimation, refresh/pause tracking
- `ad-data-recorder.php`: new v4 fields

### Deploy #225 — `7e28a76` — feat: v4 live session tracking
- `ad-live-sessions.php`: top anchor, refresh count, pause banner columns

### Deploy #226 — `d9af1a2` — EMERGENCY: force dummy_mode=true
- eCPM crashed from $0.80 → $0.19
- Emergency dummy mode activation to stop revenue bleed

### Deploy #227 — `3eb854c` — Fix 3 viewability killers
- Fixed hidden ad zones (zero dimensions)
- Added retroactive IO checks
- Capped at 12 ads per session

### Deploy #228 — `1caaa68` — refactor: dynamic injection anchors
- Replaced fixed ad zone divs with `.ad-anchor` markers in `engagement-breaks.php`
- Removed old zone injection from `header.php` and `single.php`

### Deploy #229 — `bfad763` — feat: v5 complete rewrite
- 1795 lines changed — scroll-driven dynamic injection engine
- Zero ads in initial HTML, all injected based on scroll behavior
- 19-module architecture

### Deploy #230 — `43ab260` — chore: v5 settings + CSS
- Updated `ad-engine.php` for dynamic injection mode
- Updated `critical.css` for new ad zone styles

### Deploy #231 — `b990ada` — feat: v5 analytics dashboard + live sessions
- Rewrote `ad-data-recorder.php` for v5 session fields
- Rewrote `ad-live-sessions.php` for v5 heartbeat fields
- Updated aggregator + dashboard

### Deploy #232 — `9d2f319` — feat: disable dummy mode, enable real ads
- Switched from dummy placeholders to real ad serving

### Deploy #233 — `97f1b12` — fix: time-only gate path
- Path B: `timeOnPage >= 4s` AND `scrollDepth < 1%` for non-scrolling Pinterest visitors
- `injectFirstVisibleAd()` for immediate ad at first visible anchor

### Deploy #234 — `5fd8c59` — fix: 3 quality fixes
- Sidebar hidden on mobile (was consuming impressions invisibly)
- Speed cap 500px/s (was allowing fast injections)
- Viewability calculation fix

### Deploy #235 — `0469e24` — feat: viewport ads bypass gate
- `initViewportAds()` at 2s: nav + sidebar + first content anchor
- Overlay delay reduced from default to 1s

### Deploy #236 — `4d54221` — Lower gate thresholds
- Gate: 3% scroll + 2s time (was higher values)
- Redundant with quality controls in injection decision engine

### Deploy #237 — `bf732d2` — feat: predictive injection
- Scroll velocity lookahead: faster scrollers get more lookahead distance
- Finds best anchor in predicted future viewport

### Deploy #238 — `d52c3c4` — feat: bidirectional predictive injection
- Scroll-up targeting for re-reading visitors
- Down: lookahead = `speed × 1.5`
- Up: lookahead = `speed × 1.0`

### Deploy #239 — `91ae738` — fix: debug initViewportAds
- Added extensive `console.log` debug logging
- Stored `viewport_ads_injected` in PHP config

### Deploy #240 — `97204d6` — fix: viewport ads delay 1s→2s
- GPT needs more time to initialize before slot display
- Improved debug logging

### Deploy #241 — `06c68ed` — fix: top anchor, side rails, emergency inject
- Fixed slot name: `Ad.Plus-AnchorSmall` (was wrong)
- Added `finalizeVisibility()` for accurate session-end reporting
- Emergency injection for engaged sessions with 0 ads
- Desktop side rails (`Ad.Plus-SideAnchor` left + right, ≥1200px)

### Deploy #242 — `5cda9e9` — feat: Waldo passback + 7 audit fixes
- Waldo tag pools: multi(3), medium_tall(2), small(3)
- `getNextWaldoTag()` with size-to-pool mapping + fallback
- Video viewability tracking
- PHP recorder updates: waldo_passbacks, waldo_tags_used, side rail fields, per-zone passback data

### Deploy #243 — `7f1b695` — fix: fill rate crash + vis_ms=0
- **ROOT CAUSE**: closure variable `totalVisibleMs` was desynced from `adRecord.visibleMs`
- Fix: removed closure variable, accumulate directly on `adRecord.visibleMs`
- Added try-catch around Waldo passback
- Added debug console.logs to `onSlotRenderEnded` and IO callbacks

### Deploy #244 — `3bd030c` — fix: lazy-load Waldo script
- **ROOT CAUSE**: Waldo header script (`cdn.thisiswaldo.com/static/js/24273.js`) was hooking into GPT globally at page load, hijacking all ad requests, killing Ad.Plus fill rate (97% → 29%)
- Fix: created `loadWaldoScript()` lazy loader — only loads on first `isEmpty` event
- Created `collapseAd()`, `executeWaldoPassback()` infrastructure

### Deploy #245 — `b952bc4` — fix: defer CMP + ad scripts post-LCP
- LCP-aware boot: PerformanceObserver + 200ms buffer + 3s safety net
- CMP deferral: CSS `position:fixed!important` on CMP containers + script deferring InMobi choice.js
- `engagement.css`: all `transition:all` → explicit compositable properties (`transform`, `opacity`)
- Added `will-change` hints for animated elements
- **NOTE**: CMP script may cause double-load (theme loads + Ad.Plus network also loads independently)

---

## 1E. File Inventory

Files modified in deploys #219–#245 (source preserved at commit `b952bc4`):

| File | Lines | Status | Description |
|------|-------|--------|-------------|
| `assets/js/smart-ads.js` | 1567 | Rewritten | v5 complete dynamic injection engine |
| `assets/js/scroll-engage.js` | 1132 | Unchanged | Psychology-driven engagement (pre-#219) |
| `assets/css/engagement.css` | ~300 | Modified | Compositable animation fixes |
| `assets/css/critical.css` | ~170 | Modified | Ad zone positioning styles |
| `header.php` | ~120 | Modified | CMP deferral script + nav anchors |
| `single.php` | ~180 | Modified | Sidebar ad anchors |
| `functions.php` | ~700 | Modified | Removed 1 legacy require |
| `inc/ad-engine.php` | ~990 | Modified | v5 settings, admin page, wp_localize_script |
| `inc/ad-data-recorder.php` | ~400 | Modified | v5 session fields + Waldo tracking |
| `inc/ad-live-sessions.php` | ~350 | Modified | v5 heartbeat fields + Waldo columns |
| `inc/ad-analytics-aggregator.php` | 391 | Modified | Daily aggregation with v5 metrics |
| `inc/ad-analytics-dashboard.php` | ~500 | Modified | v5 dashboard UI |
| `inc/engagement-breaks.php` | ~600 | Modified | Ad anchor injection |
| `inc/ad-analytics.php` | — | DELETED | Replaced by aggregator (deploy #219) |

---

## Key Lessons Learned

1. **Waldo header bidding script hooks into GPT globally** — MUST be lazy-loaded only on first `isEmpty` event, never at page start. Loading it eagerly killed Ad.Plus fill rate from 97% → 29%.

2. **CMP popup (InMobi) is loaded by Ad.Plus network, not theme** — don't load it yourself or you get double-load causing CLS.

3. **Viewability tracking: never use closure variables** — accumulate directly on `adRecord.visibleMs`. Closure variables desync when IO callbacks fire asynchronously.

4. **LCP-aware deferral is critical** — ad scripts compete with hero image for network/CPU. Use PerformanceObserver to wait for LCP before booting ad engine.

5. **IO thresholds need 0.1 for early detection** — some ads start with 0 dimensions and expand. Without 0.1 threshold, you miss the first visibility transition.

6. **`position:fixed` on CMP containers prevents CLS** — fixed-position elements don't participate in layout flow, so injecting them won't shift content.

7. **`transition:all` triggers non-composited animations** — always use explicit properties (`transform`, `opacity`). `transition:all` causes layout/paint on every property change.

8. **`display:none` breaks IntersectionObserver** — use `height:0; overflow:hidden; margin:0` instead to collapse empty ad slots while keeping them in the document flow.

9. **GPT `enableSingleRequest()` batches slots** — call once during overlay initialization, not per-slot.

10. **3s safety net prevents revenue loss** — if LCP PerformanceObserver never fires (old browsers, prerendered pages), still boot ad engine after 3s timeout.

11. **`collapseEmptyDivs(true)` is essential** — without it, unfilled GPT slots leave visible empty space.

12. **Per-ad refresh cooldown prevents impression fraud** — 30s per-ad minimum + 15 session-wide max keeps refresh rates within acceptable bounds.

---

## Re-introduction Strategy

When re-introducing features after green baseline:

1. **Start with engagement-breaks ad anchors** — `.ad-anchor` divs in content (no JS needed)
2. **Add basic smart-ads.js** — gate + single-format injection only, no overlays
3. **Add overlays one at a time** — bottom anchor first, then interstitial, then top anchor
4. **Add viewport ads** — nav + sidebar after confirming zero CLS impact
5. **Add predictive injection** — only after confirming viewability rates
6. **Add Waldo passback** — lazy-load only, with fill rate monitoring
7. **Do NOT add CMP deferral script** — let Ad.Plus handle it natively
8. **Add pause refresh** — last, after all other components stable
9. **Monitor PageSpeed after each deploy** — revert immediately if scores drop

---

*Document generated 2026-02-22. Source code preserved at commit `b952bc4`.*
