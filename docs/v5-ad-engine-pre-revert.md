# PinLightning v5 Ad Engine — Pre-Revert Reference

> **Scope:** All work from Deploy #219 (`3a605c6`) through Deploy #245 (`b952bc4`).
> **Revert target:** Commit `18715b7` (state before #219).
> **Purpose:** Preserve the complete algorithm/architecture so features can be re-introduced later.
> **Git preservation:** Full source code is intact in git history at commit `b952bc4`.

---

## 1A. Smart Ad Engine v5 — Complete Algorithm Documentation

**File:** `assets/js/smart-ads.js` — 1567 lines, 19 modules, single IIFE.

---

### Initialization Flow

```
Page Load
  └─ DOMContentLoaded
       └─ waitForDOMThenLCP()
            ├─ PerformanceObserver('largest-contentful-paint')
            │    └─ LCP fires → 200ms buffer → bootAfterLCP()
            └─ Safety net: setTimeout(bootAfterLCP, 3000)
                 └─ bootAfterLCP()
                      └─ requestIdleCallback → init()
                           ├─ Load GPT script (<script async>)
                           ├─ initOverlays()
                           │    ├─ googletag.enableSingleRequest()
                           │    ├─ googletag.collapseEmptyDivs(true)
                           │    ├─ defineSlot: interstitial (Ad.Plus-Interstitial)
                           │    ├─ setTimeout(1000):
                           │    │    ├─ defineSlot: bottom anchor (Ad.Plus-Anchor)
                           │    │    ├─ defineSlot: top anchor (Ad.Plus-AnchorSmall)
                           │    │    └─ defineSlot: side rails L+R (Ad.Plus-SideAnchor, ≥1200px)
                           │    └─ googletag.enableServices()
                           ├─ setTimeout(initViewportAds, 2000)
                           │    ├─ inject nav ad (device-targeted size)
                           │    ├─ inject sidebar ad (desktop only)
                           │    └─ inject first content anchor
                           ├─ initPauseRefresh()
                           ├─ setInterval(mainLoop, 300)
                           ├─ visibilitychange → finalizeVisibility + sendReport
                           ├─ pagehide → finalizeVisibility + sendReport
                           └─ startHeartbeat()
```

### Boot Sequence (Exact Order)

1. **IIFE starts:** Read config from `window.plAds`, bot detection (navigator.webdriver + UA regex), device classification (mobile ≤768, tablet ≤1024, desktop >1024).
2. **DOMContentLoaded** fires → `waitForDOMThenLCP()` begins.
3. **LCP fires** (PerformanceObserver) or **3s safety net** expires → `bootAfterLCP()`.
4. **requestIdleCallback** → `init()` runs during idle time.
5. **init():** Load GPT → `initOverlays()` (interstitial immediately + enableSingleRequest + anchors/side rails at 1s) → `initViewportAds` at 2s → `initPauseRefresh` → `mainLoop` every 300ms → event listeners → `startHeartbeat()`.

---

### 19 Modules

#### MODULE 1: CONFIG

Configuration constants and slot definitions.

| Constant | Value | Purpose |
|----------|-------|---------|
| `SLOT_BASE` | From `window.plAds.slotBase` | GPT slot path prefix |
| `MIN_DISTANCE_PX` | 400 | Minimum pixels between injected ads |
| `MIN_TIME_BETWEEN_ADS_MS` | 4000 | Cooldown between ad injections |
| `MAX_SPEED_FOR_INJECT` | 500 | Max scroll speed (px/s) to allow injection |
| `VIEWABLE_RATIO` | 0.5 | IO threshold for "viewable" |
| `VIEWABLE_MS` | 1000 | Time visible to count as "viewable" (MRC standard) |
| `SPEED_READER` | 150 | px/s threshold: below = reader |
| `SPEED_SCANNER` | 600 | px/s threshold: below = scanner |
| `SPEED_FAST` | 1200 | px/s threshold: below = fast-scanner |
| `PAUSE_THRESHOLD_MS` | 3000 | Scroll pause duration to trigger refresh |
| `REFRESH_COOLDOWN_MS` | 30000 | Per-ad refresh cooldown |
| `MAX_REFRESHES` | 15 | Session-wide refresh cap |
| `MAX_PAUSE_BANNERS` | 2 | Max pause banner ads per session |

**Waldo Tag Pools (Site ID 24273):**

| Pool | Tag Count | Purpose |
|------|-----------|---------|
| `multi` | 3 tags | Multi-size passback (300x250, 336x280, 300x600) |
| `medium_tall` | 2 tags | Medium/tall passback (300x600, 160x600) |
| `small` | 3 tags | Small passback (300x250, 250x250) |

#### MODULE 2: STATE

All tracked state variables:

- `scrollY`, `lastScrollY`, `scrollSpeed`, `scrollDir` — scroll position/velocity
- `speedSamples[]` — last 10 scroll speed measurements
- `timeOnPage`, `startTime` — session timing
- `maxDepth` — deepest scroll percentage reached
- `dirChanges` — number of scroll direction reversals
- `pattern` — visitor classification string
- `gateOpen` — boolean, engagement gate passed
- `gateOpenTime` — timestamp when gate opened
- `lastInjectTime` — timestamp of last ad injection
- `lastInjectY` — scroll position of last injection
- `totalInjected`, `totalViewable`, `totalRequests`, `totalFills`, `totalEmpty` — aggregate counters
- `viewportAdsInjected` — boolean, viewport ads placed
- `totalRefreshes` — session refresh count
- `waldoPassbacks` — count of Waldo passback executions
- `adRecords[]` — per-ad tracking objects
- `pauseBannerCount` — pause banners injected this session

#### MODULE 3: SCROLL SPEED TRACKER

- Samples `window.scrollY` on every `mainLoop` tick (300ms).
- Maintains weighted moving average of last 10 samples.
- Recent samples weighted more heavily than older ones.
- Tracks scroll direction (up/down) and direction change count.
- `sampleScrollSpeed()` called at top of every mainLoop iteration.

#### MODULE 4: VISITOR CLASSIFIER

Classifies visitor into one of four patterns based on behavior:

| Pattern | Criteria | Ad Strategy |
|---------|----------|-------------|
| `bouncer` | <5s on page AND <10% scroll depth | No ads injected |
| `reader` | scrollSpeed < 150px/s AND timeOnPage > 15s | Full ad load, pause banners eligible |
| `scanner` | scrollSpeed < 600px/s | Medium ad sizes |
| `fast-scanner` | scrollSpeed > 600px/s | Large vertical ads only, reduced frequency |

Classification runs on every mainLoop tick. Pattern can change as behavior evolves.

#### MODULE 5: SIZE SELECTION ENGINE

Selects ad size based on zone type, device, and scroll speed:

**Nav zone (device-targeted):**

| Device | Size |
|--------|------|
| Mobile | 320x100 |
| Tablet | 728x90 |
| Desktop | 970x250 |

**Sidebar zone (desktop only):** 160x600 or 300x250.

**Content zone (speed-based):**

| Pattern | Sizes (priority order) |
|---------|----------------------|
| reader | 336x280, 300x250 |
| scanner | 300x600, 250x250, 300x250 |
| fast-scanner | 300x600 |
| too fast (>1200px/s) | `null` (skip injection) |

#### MODULE 6: ENGAGEMENT GATE

Two paths to open the gate — whichever fires first:

| Path | Conditions | Rationale |
|------|------------|-----------|
| **Path A** (scroller) | scrollDepth >= 3% AND timeOnPage >= 2s | Standard engaged visitor |
| **Path B** (non-scroller) | timeOnPage >= 4s AND scrollDepth < 1% | Pinterest visitors who read without scrolling |

Gate check runs on every mainLoop tick. Once open, stays open for the session.

#### MODULE 7: VIEWPORT ADS

Bypasses the engagement gate entirely. Fires at 2s after init():

1. **Nav ad** — injected at `.ad-anchor-nav`, device-targeted size.
2. **Sidebar ad** — injected at `.ad-anchor-sidebar`, desktop only (hidden on mobile via CSS).
3. **First content anchor** — injected at first `.ad-anchor` in content.

These are "above the fold" ads that fire regardless of scroll behavior.

#### MODULE 8: INJECTION DECISION ENGINE

Called on every mainLoop tick after gate opens. Decision tree:

```
1. Emergency inject?
   └─ YES if: gateOpen AND (now - gateOpenTime > 10000ms) AND totalInjected === 0
   └─ Injects at first visible anchor immediately

2. Fast-scanner block?
   └─ YES if: pattern === 'fast-scanner' AND scrollSpeed > MAX_SPEED_FOR_INJECT
   └─ Skip this tick

3. Viewability wait?
   └─ YES if: any ad has visibleMs < 8000 AND was injected < 8s ago
   └─ Wait for existing ads to accumulate viewability

4. Cooldown check?
   └─ YES if: (now - lastInjectTime) < MIN_TIME_BETWEEN_ADS_MS (4000ms)
   └─ Skip this tick

5. Distance check?
   └─ YES if: abs(scrollY - lastInjectY) < MIN_DISTANCE_PX (400px)
   └─ Skip this tick

6. Speed check?
   └─ YES if: scrollSpeed > MAX_SPEED_FOR_INJECT (500px/s)
   └─ Skip this tick

7. Bidirectional predictive targeting:
   └─ Calculate lookahead: scrollSpeed * (scrollDir === 'down' ? 1.5 : 1.0)
   └─ Faster scrollers get more lookahead distance
   └─ Find nearest .ad-anchor in predicted viewport
   └─ Scroll-up visitors get targeted for re-reading zones
```

#### MODULE 9: AD INJECTOR

Creates and displays a GPT ad slot:

1. Creates `<div>` with unique ID (`pl-ad-{index}`).
2. Inserts div into the target `.ad-anchor` element.
3. `googletag.cmd.push()`: `defineSlot(slotPath, size, divId)` → `addService(pubads)` → `display(divId)`.
4. Creates `adRecord` object: `{ id, zone, size, requestTime, fillTime, visibleMs, viewable, refreshCount, lastRefreshTime, waldoPassback }`.
5. Starts viewability tracking via IntersectionObserver.

#### MODULE 10: PAUSE BANNER

Specialized ad injection for paused readers:

- **Positions:** After items 3, 6, 9, 12 (`.ad-anchor-item3-after-p`, etc.).
- **Eligibility:** `pattern === 'reader'` AND `timeOnPage > 20000ms`.
- **Cap:** `MAX_PAUSE_BANNERS` (2) per session.
- **Size:** 300x250.
- **Trigger:** User scrolls past the anchor position at reader speed.

#### MODULE 11: VIEWABILITY TRACKER

IntersectionObserver-based MRC viewability measurement:

- **IO thresholds:** `[0, 0.1, 0.25, 0.5, 0.75, 1.0]` — 0.1 needed for early detection of ads that start with 0 dimensions.
- **Tracking:** When `intersectionRatio >= VIEWABLE_RATIO (0.5)`, accumulates time directly on `adRecord.visibleMs` (no closure variable — closure approach caused vis_ms=0 bugs).
- **Viewable flag:** Set when `adRecord.visibleMs >= VIEWABLE_MS (1000ms)`.
- **Retroactive checks:** Additional checks at 500ms and 2s after injection to catch ads that were already visible but IO didn't fire synchronously.
- **Finalization:** `finalizeVisibility()` called on pagehide/visibilitychange to flush pending time.

**Critical lesson:** Never use a closure variable like `totalVisibleMs` in the IO callback. Multiple IOs can fire for the same ad, and closure variables don't share state. Accumulate directly on the shared `adRecord` object.

#### MODULE 12: DUMMY MODE

Development/testing mode that renders colored placeholder boxes instead of real ads:

- Boxes sized to match the ad size that would have been served.
- Color-coded by zone type (content = blue, nav = green, sidebar = orange, pause = purple).
- Displays size text and zone name.
- All tracking/reporting still fires normally.

#### MODULE 13: OVERLAYS

GPT out-of-page ad formats:

| Format | Slot Name | Timing | Conditions |
|--------|-----------|--------|------------|
| Interstitial | `Ad.Plus-Interstitial` | Immediately in initOverlays | Always |
| Bottom anchor | `Ad.Plus-Anchor` | 1s after initOverlays | Always |
| Top anchor | `Ad.Plus-AnchorSmall` | 1s after initOverlays | Always |
| Side rail left | `Ad.Plus-SideAnchor` | 1s after initOverlays | Viewport >= 1200px |
| Side rail right | `Ad.Plus-SideAnchor` | 1s after initOverlays | Viewport >= 1200px |

- `googletag.enableSingleRequest()` called once in initOverlays to batch all overlay slots.
- `googletag.collapseEmptyDivs(true)` prevents empty ad containers from taking space.
- Overlays use 30s refresh intervals managed by GPT's built-in refresh.

#### MODULE 13b: WALDO TAG SELECTOR

Maps ad sizes to Waldo tag pools:

| Ad Size | Pool | Fallback |
|---------|------|----------|
| 300x250, 336x280 | `multi` | Any remaining tag from any pool |
| 300x600, 160x600 | `medium_tall` | Any remaining tag from any pool |
| 250x250 | `small` | Any remaining tag from any pool |

- `getNextWaldoTag(size)` returns next unused tag from the appropriate pool.
- Tags are consumed sequentially; once a pool is exhausted, falls back to any remaining tag across all pools.
- Total: 8 tags across 3 pools (3 multi + 2 medium_tall + 3 small).

#### MODULE 14: WALDO LAZY LOADER

Lazy-loads the Waldo/Newor Media script to prevent GPT hijacking:

- **loadWaldoScript():** Dynamically creates `<script>` element for Waldo SDK. Uses callback queue pattern — multiple callers can request load, all callbacks fire when script is ready.
- **collapseAd(divId):** Sets `height:0; overflow:hidden; margin:0` on the ad container. Does NOT use `display:none` (breaks IntersectionObserver).
- **executeWaldoPassback(adRecord):** Wrapped in try-catch. Calls Waldo tag code, increments `waldoPassbacks` counter. On error, collapses the ad div.
- **onSlotRenderEnded:** GPT event handler. If `event.isEmpty === true`, triggers Waldo passback for that slot. If filled, records fill time and updates `totalFills`.

**Critical lesson:** The Waldo header bidding script hooks into `googletag.pubads()` globally when loaded at page start. This hijacks ALL GPT ad requests, causing Ad.Plus fill rate to drop to near zero. The script MUST be lazy-loaded only on the first `isEmpty` event, after GPT has already initialized and served its own ads.

#### MODULE 15: VIDEO INJECTION

PlayerPro inpage video ad:

- **Position:** `.ad-anchor-intro-after-p3` (after 3rd paragraph of intro).
- **Conditions:** `timeOnPage > 5000ms` AND `scrollDepth > 5%`.
- **Single injection:** Boolean guard prevents multiple video ads.
- **Viewability:** Tracked via same IO system as display ads.

#### MODULE 16: PAUSE REFRESH

Refreshes the most-visible ad when user pauses scrolling:

1. **Detection:** Scroll speed drops to 0 for `PAUSE_THRESHOLD_MS` (3s).
2. **Selection:** Find the ad with highest current `intersectionRatio`.
3. **Refresh:** `googletag.pubads().refresh([slot])` on the selected ad.
4. **Cooldown:** `REFRESH_COOLDOWN_MS` (30s) per individual ad.
5. **Cap:** `MAX_REFRESHES` (15) per session.
6. **Tracking:** `adRecord.refreshCount` incremented, `totalRefreshes` incremented.

#### MODULE 17: SESSION REPORTER

Builds and sends a complete session analytics beacon:

**Report fields:**
- `sid` — session ID (random hex)
- `postId` — WordPress post ID
- `device` — mobile/tablet/desktop
- `timeOnPage` — seconds
- `maxDepth` — percentage (0-100)
- `scrollSpeed` — average px/s
- `pattern` — visitor classification
- `dirChanges` — direction reversal count
- `itemsSeen` — engagement items seen (from `window.__plEngagement`)
- `gateOpen` — boolean
- `viewportAdsInjected` — boolean
- `totalInjected`, `totalViewable`, `totalRequests`, `totalFills`, `totalEmpty`
- `viewabilityRate` — totalViewable / totalInjected
- `totalRefreshes`
- `fillRate` — totalFills / totalRequests
- `waldoPassbacks`
- `overlays` — object with anchor/topAnchor/interstitial/sideRail status
- `zones[]` — per-ad array: `{ id, zone, size, fillTime, visibleMs, viewable, refreshCount, waldoPassback }`

**Send method:** `navigator.sendBeacon()` to REST endpoint. Called on `visibilitychange` (hidden) and `pagehide`.

`finalizeVisibility()` flushes all pending viewability timers before building the report.

#### MODULE 18: HEARTBEAT

Periodic keep-alive signal for live session tracking:

- **Interval:** 3s via `setInterval`.
- **Method:** `navigator.sendBeacon()` to heartbeat endpoint.
- **Payload:** `{ sid, postId, device, timeOnPage, maxDepth, scrollSpeed, pattern, totalInjected, totalViewable, fillRate }`.
- **Failure handling:** Counter increments on failed sends; stops after 3 consecutive failures.

#### MODULE 19: MAIN LOOP

Central loop running every 300ms via `setInterval`:

```
mainLoop():
  1. sampleScrollSpeed()      — update speed/direction
  2. classifyVisitor()        — update pattern
  3. checkGate()              — evaluate engagement gate
  4. if (gateOpen):
       evaluateInjection()    — decision engine → inject if eligible
  5. tryInjectVideo()         — check video eligibility
```

---

### Ad Zone Types Summary

| Zone | Injection | Sizes | Targeting |
|------|-----------|-------|-----------|
| Content display | Dynamic, scroll-driven | 300x250, 336x280, 250x250, 300x600 | Speed-based size selection |
| Nav | Viewport (2s) | 320x100 / 728x90 / 970x250 | Device-targeted |
| Sidebar | Viewport (2s), desktop only | 160x600, 300x250 | Desktop only |
| Pause banner | Reader pause | 300x250 | Item positions 3/6/9/12, reader pattern, 20s+ |
| Video | Engagement threshold | playerPro inpage | After intro p3, 5s + 5% scroll |
| Bottom anchor | Overlay (1s) | GPT out-of-page | All visitors |
| Top anchor | Overlay (1s) | GPT out-of-page | All visitors |
| Interstitial | Overlay (immediate) | GPT out-of-page | All visitors |
| Side rails | Overlay (1s) | GPT out-of-page | Viewport >= 1200px |

### Passback System (Waldo / Newor Media)

- **Trigger:** GPT `onSlotRenderEnded` with `event.isEmpty === true`.
- **Lazy-loaded:** Waldo SDK script only loads on first isEmpty event (never at page start).
- **Tag pools:** 3 multi + 2 medium_tall + 3 small = 8 total tags for Site ID 24273.
- **Size mapping:** Ad size determines which pool is tried first, with cross-pool fallback.
- **Error handling:** try-catch wrapped; on failure, ad container is collapsed (height:0, overflow:hidden).
- **Tracking:** `waldoPassbacks` counter in session report; per-ad `waldoPassback` boolean.

### Analytics Beacon

Session report sent via `navigator.sendBeacon()` on page exit:

```json
{
  "sid": "a1b2c3d4",
  "postId": 12345,
  "device": "mobile",
  "timeOnPage": 45.2,
  "maxDepth": 78,
  "scrollSpeed": 230,
  "pattern": "reader",
  "dirChanges": 12,
  "itemsSeen": 18,
  "gateOpen": true,
  "viewportAdsInjected": true,
  "totalInjected": 6,
  "totalViewable": 4,
  "viewabilityRate": 0.67,
  "totalRefreshes": 2,
  "fillRate": 0.83,
  "waldoPassbacks": 1,
  "overlays": {
    "anchor": { "fired": true, "filled": true, "viewable": true },
    "topAnchor": { "fired": true, "filled": true, "viewable": false },
    "interstitial": { "fired": true, "filled": false },
    "sideRail": { "fired": false }
  },
  "zones": [
    { "id": "pl-ad-0", "zone": "nav", "size": "320x100", "fillTime": 1200, "visibleMs": 8500, "viewable": true, "refreshCount": 0, "waldoPassback": false }
  ]
}
```

---

## 1B. Scroll-Engage.js Documentation

**File:** `assets/js/scroll-engage.js` — 1132 lines, single IIFE.
Unchanged during deploys #219-#245 but integral to the engagement system.

---

### Psychology Triggers (10 Documented)

| # | Principle | Implementation |
|---|-----------|----------------|
| 1 | **Variable Ratio Schedule** (Skinner) | Random 12-35s intervals between micro-interactions. Unpredictable timing maximizes engagement. |
| 2 | **Novelty Effect** | 8 different micro-interaction types. Never shows the same one twice in a row. |
| 3 | **Escalating Rewards** | Depth-based message pools: early (0-30%), mid (30-70%), deep (70-100%). Messages get more enthusiastic with depth. |
| 4 | **Curiosity Gap** | Peek-a-boo character animation after 5s idle. Character partially appears then hides, drawing attention. |
| 5 | **Peak-End Rule** | Victory animation at 100% scroll depth. Memorable positive ending anchors the experience. |
| 6 | **Endowed Progress** | Heart fill indicator starts at 20% filled. Pre-existing progress motivates completion. |
| 7 | **Zeigarnik Effect** | Loss aversion messages when scroll-up is detected. Incomplete tasks create psychological tension. |
| 8 | **Reciprocity** | Compliment messages trigger emotional reciprocity, increasing willingness to engage further. |
| 9 | **Mere Exposure** | Repeated character appearances build familiarity and positive association over the session. |
| 10 | **Parasocial Bonding** | Time-aware greetings (morning/afternoon/evening/night). Character feels "real" and contextual. |

### Components

| Component | Description |
|-----------|-------------|
| **Animated character** | WebM sprite-based video element. Animations: idle, catwalk, dance, welcome, victory, peekaboo, shhh, peace, blowkiss, beckon, surprise, excited, omg, clap. Assets in `assets/engage/`. |
| **Heart fill indicator** | SVG with clip-path animation. Starts at 20% fill (endowed progress), grows to 100% as scroll depth increases. |
| **Speech bubble system** | Positioned relative to character. Currently disabled — `showSpeech()` is a no-op. Infrastructure exists for re-enabling. |
| **AI Chat panel** | Floating panel triggered by character tap or heart tap. Connected to `window.__plChat` API. Supports session management, typing indicator, minimize/resume. |
| **Sparkle effects** | Emoji particle system. Fires on milestones, character interactions, and deep engagement. |
| **Mood background** | Full-page color overlay tied to scroll depth. Disabled — causes scroll lag on mobile due to repaints. |

### State Machine

```
States: idle → catwalk → dancing → welcome → victory → peekaboo → milestone → micro

Transitions:
  idle → catwalk         (random timer, 12-35s)
  idle → peekaboo        (5s idle timeout)
  idle → welcome         (page load, first visit)
  any  → milestone       (25%, 50%, 75% depth)
  any  → victory         (100% depth)
  any  → micro           (random timer, depth-based pool)
  catwalk → idle         (animation complete)
  dancing → idle         (animation complete)
  milestone → idle       (3s display)
  micro → idle           (3s display)
```

### Message System (MSG Object)

**4 depth zones:**

| Zone | Depth Range | Tone |
|------|-------------|------|
| `early` | 0-30% | Welcoming, encouraging |
| `mid` | 30-70% | Engaged, enthusiastic |
| `deep` | 70-100% | Impressed, rewarding |
| `victory` | 100% | Celebratory |

**23+ message categories** including: greetings, encouragement, scroll-up warnings, milestone celebrations, tap responses, rapid-tap easter eggs, returning visitor recognition, time-aware welcomes (morning/afternoon/evening/night), idle prompts, and curiosity hooks.

### AI Chat Integration

- **Open triggers:** Character tap, heart tap, or image tap.
- **Image context:** On image tap, sends full context to chat API: `{ src, alt, caption, section, position, depth }`.
- **Session management:** `start` → `message` → `end` API endpoints.
- **UI:** Typing indicator, minimize/resume toggle, message bubbles with timestamps.
- **API bridge:** `window.__plChat` object exposes `open()`, `close()`, `send()`, `onMessage()`.

### Interaction Tracking

| Interaction | Data Captured |
|-------------|---------------|
| Character taps | Count, timestamp |
| Heart taps | Count, timestamp |
| Rapid taps | Detected at 3+ taps in 1s, triggers easter egg |
| Image taps | Full context: src, alt, caption, section heading, position index, scroll depth |

All interactions exposed via `window.__plt` for the visitor tracker plugin.

---

## 1C. Analytics Dashboard

---

### Data Flow

```
Browser                    Server                          Storage
-------                    ------                          -------
smart-ads.js
  |-- sendBeacon ---------> ad-data-recorder.php
  |   (session report)      |-- validates fields
  |                         |-- stores raw session
  |                         +-- calls pl_ad_aggregate_session()
  |                              +-- ad-analytics-aggregator.php
  |                                   +-- wp_options: pl_ad_stats_YYYY-MM-DD
  |
  +-- sendBeacon ---------> ad-live-sessions.php (heartbeat endpoint)
      (3s heartbeat)         +-- transient: pl_ad_live_{sid} (TTL 10s)

Dashboard reads:
  ad-analytics-dashboard.php --> pl_ad_get_stats_range() --> wp_options

Cleanup:
  Daily WP-Cron --> delete pl_ad_stats_* older than 90 days
```

### What's Tracked (Per-Day Aggregate)

**Session demographics:**
- Sessions by device (mobile/tablet/desktop)
- Sessions by referrer source
- Sessions by language
- Sessions by visitor pattern (reader/scanner/fast-scanner/bouncer)
- Sessions by hour of day

**Engagement metrics:**
- Total time on page (sum and average)
- Scroll depth (distribution and average)
- Gate open count and rate

**Ad performance:**
- Zones activated (total slot requests)
- Viewable impressions (MRC standard: 50% visible for 1s)
- Requests, fills, empty counts
- Fill rate (fills / requests)
- Viewability rate (viewable / injected)

**Click tracking:**
- Display ad clicks
- Anchor ad clicks
- Interstitial clicks
- Pause banner clicks

**Overlay performance:**
- Anchor: fired / filled / viewable / avg duration
- Top anchor: fired / filled / viewable / avg duration
- Interstitial: fired / filled / viewable
- Side rail: fired / filled / viewable

**v5-specific fields:**
- Pause banner injections and clicks
- Refresh count (total and per-ad)
- Video injections and viewability
- Scroll speed distribution
- Waldo passback count and fill rate

**Per-zone breakdown:**
- Activations, filled, empty, viewable, visible_ms, clicks, passback count

**Per-post breakdown:**
- Sessions, gate opens, ads filled, viewable, clicks, avg time on page

### Live Sessions (ad-live-sessions.php)

Real-time session viewer in WP admin:

- **Source:** 3s heartbeat beacons from smart-ads.js.
- **Storage:** WordPress transients with 10s TTL (`pl_ad_live_{sid}`).
- **Display columns:** SID, post title, device, time on page, scroll depth, scroll speed, visitor pattern, ads injected, viewable count, fill rate.
- **Auto-refresh:** Dashboard polls every 5s via AJAX.
- **v5 additions:** Waldo passback column, side rail status, video injection status.

---

## 1D. Deployment Changelog

### Deploy #219 — `3a605c6` — chore: remove legacy analytics dashboard

- Deleted `inc/ad-analytics.php` (660 lines).
- Removed `require` from `functions.php`.
- Replaced by new analytics aggregator + dashboard architecture.

### Deploy #220 — `a7dbc31` — fix: ad engine bugs

- Viewability tracking fix (IO callback timing).
- Side-anchor slot name fix.
- Click inflation fix (double-counting on refresh).
- Zone aggregation fix in recorder.
- ~60 lines changed in `smart-ads.js`.

### Deploy #221 — `b5cea2b` — feat: full-article ad injection

- `engagement-breaks.php`: Added `pl_inject_item_ads()` and `pl_inject_intro_ads()` inserting `.ad-anchor` divs between content items.
- `header.php`: Nav ad anchors added.
- `single.php`: Sidebar ad anchors added.

### Deploy #222 — `065f9c9` — feat: v4 smart-ads.js

- Major rewrite: multi-format zone support, triple overlay (interstitial + bottom anchor + top anchor), pause refresh system.
- 1223 lines changed.

### Deploy #223 — `828af9d` — feat: v4 settings defaults + zone CSS

- `critical.css`: Ad zone positioning styles.
- `ad-engine.php`: New format toggles (top_anchor, 320x100, 160x600, video, 728x90), relaxed engagement gate thresholds.

### Deploy #224 — `0a4cf3d` — feat: v4 analytics

- Dashboard: top anchor tracking, revenue estimation columns, refresh/pause tracking.
- `ad-data-recorder.php`: New fields for v4 data structure.

### Deploy #225 — `7e28a76` — feat: v4 live session tracking

- `ad-live-sessions.php`: Added top anchor, refresh count, pause banner columns.

### Deploy #226 — `d9af1a2` — EMERGENCY: force dummy_mode=true

- **eCPM crashed $0.80 to $0.19.**
- Emergency intervention: forced `dummy_mode=true` in ad-engine.php to stop revenue loss while debugging.

### Deploy #227 — `3eb854c` — fix: 3 viewability killers

- Hidden zones causing 0% viewability — fixed zone visibility.
- Retroactive IO checks — added 500ms and 2s post-injection checks.
- Capped at 12 ads per session to prevent quality dilution.

### Deploy #228 — `1caaa68` — refactor: dynamic injection anchors

- Replaced fixed ad zones with `.ad-anchor` div injection in `engagement-breaks.php`.
- Removed old zone injection code from `header.php` and `single.php`.
- Anchors placed between content items for scroll-driven injection.

### Deploy #229 — `bfad763` — feat: v5 complete rewrite

- **1795 lines changed** — full scroll-driven dynamic injection engine.
- Zero ads in initial HTML. All ads injected based on real-time scroll behavior.
- 19-module IIFE architecture.

### Deploy #230 — `43ab260` — chore: v5 settings + CSS

- Updated `ad-engine.php` for dynamic injection mode settings.
- Updated `critical.css` for new ad zone container styles.

### Deploy #231 — `b990ada` — feat: v5 analytics dashboard + live sessions

- Rewrote `ad-data-recorder.php` for v5 field structure.
- Rewrote `ad-live-sessions.php` for v5 columns.
- Updated aggregator and dashboard for v5 metrics.

### Deploy #232 — `9d2f319` — feat: disable dummy mode, enable real ads

- Switched from dummy placeholders to real GPT ad serving.
- v5 dynamic injection engine now live.

### Deploy #233 — `97f1b12` — fix: time-only gate path

- Added Path B: 4s time + <1% scroll for non-scrolling Pinterest visitors.
- Added `injectFirstVisibleAd()` for immediate ad at first visible anchor when gate opens.

### Deploy #234 — `5fd8c59` — fix: 3 quality fixes

- Sidebar hidden on mobile (was rendering off-screen, wasting impressions).
- Speed cap at 500px/s (was allowing injection during fast scrolls).
- Viewability calculation fix (was double-counting refreshed ads).

### Deploy #235 — `0469e24` — feat: viewport ads bypass gate

- `initViewportAds()` at 2s: nav + sidebar + first content anchor bypass engagement gate.
- Overlay delay reduced from default to 1s.
- Ensures revenue from visitors who leave before gate opens.

### Deploy #236 — `4d54221` — lower gate thresholds

- Gate thresholds reduced: 3% scroll, 2s time (was higher values).
- Made redundant by quality controls (speed cap, distance, cooldown).

### Deploy #237 — `bf732d2` — feat: predictive injection

- Scroll velocity lookahead: faster scrollers get more lookahead distance.
- Ads placed ahead of current viewport based on predicted scroll position.

### Deploy #238 — `d52c3c4` — feat: bidirectional predictive injection

- Scroll-up targeting for re-reading visitors.
- When scrolling up, predicts upward viewport position and targets anchors above.

### Deploy #239 — `91ae738` — fix: debug initViewportAds

- Added extensive `console.log` debug logging throughout initViewportAds.
- Stored `viewport_ads_injected` flag in PHP settings for server-side diagnostics.

### Deploy #240 — `97204d6` — fix: viewport ads delay 1s to 2s

- GPT needs more time to initialize before `googletag.display()` calls.
- Delay increased from 1s to 2s to prevent empty renders.

### Deploy #241 — `06c68ed` — fix: top anchor, side rails, emergency inject

- Fixed slot name: `Ad.Plus-AnchorSmall` (was using wrong name).
- Added `finalizeVisibility()` — flushes pending viewability timers on page exit.
- Added emergency injection: if gate is open for 10s with 0 ads, force inject.
- Added desktop side rails at >= 1200px viewport.

### Deploy #242 — `5cda9e9` — feat: Waldo passback + 7 audit fixes

- Waldo tag pools: `getNextWaldoTag(size)` with size-to-pool mapping.
- Video viewability tracking via same IO system.
- PHP recorder updates: new fields for waldo passback count, side rail status, video status.
- 7 audit fixes across viewability, fill tracking, and zone aggregation.

### Deploy #243 — `7f1b695` — fix: fill rate crash + vis_ms=0

- **Root cause (vis_ms=0):** Closure variable `totalVisibleMs` in IO callback was not shared across multiple IO instances for the same ad. Removed closure variable; accumulate directly on `adRecord.visibleMs`.
- Added try-catch around Waldo passback execution.
- Added debug `console.log` statements to `onSlotRenderEnded` and IO callbacks.

### Deploy #244 — `3bd030c` — fix: lazy-load Waldo script

- **Root cause (fill rate crash):** Waldo header bidding script was loading at page start and globally hooking into `googletag.pubads()`. This hijacked ALL GPT ad requests, causing Ad.Plus network fill rate to drop to near zero.
- Created `loadWaldoScript()` lazy loader — script only loads on first `isEmpty` event.
- Created `collapseAd(divId)` — uses `height:0; overflow:hidden` instead of `display:none`.
- Created `executeWaldoPassback(adRecord)` — try-catch wrapped.
- Waldo script no longer competes with primary ad network.

### Deploy #245 — `b952bc4` — fix: defer CMP + ad scripts post-LCP

- **LCP-aware boot:** PerformanceObserver watches for `largest-contentful-paint` entry, adds 200ms buffer, then calls `bootAfterLCP()`. 3s safety net timeout.
- **CMP deferral:** CSS `position:fixed` on CMP container prevents CLS. Script deferred in header.php.
- **engagement.css:** Changed all `transition: all` to explicit compositable properties (`transform`, `opacity`) to avoid non-composited animation warnings.
- **NOTE:** CMP script may cause double-load — InMobi CMP is also loaded by Ad.Plus network's own scripts. May need to remove theme-side CMP loading.

---

## 1E. File Inventory

Files modified or created during deploys #219-#245, with approximate line counts:

| File | Lines | Status | Notes |
|------|-------|--------|-------|
| `assets/js/smart-ads.js` | 1567 | Modified | v5 complete rewrite (19 modules) |
| `assets/js/scroll-engage.js` | 1132 | Unchanged | Existed before #219, included for reference |
| `assets/css/engagement.css` | — | Modified | Compositable animation properties (deploy #245) |
| `assets/css/critical.css` | — | Modified | Ad zone container styles |
| `header.php` | — | Modified | CMP deferral + nav ad anchors |
| `single.php` | — | Modified | Sidebar ad anchors |
| `functions.php` | — | Modified | 1 line removed (legacy analytics require) |
| `inc/ad-engine.php` | ~990 | Modified | Settings page, content scanner, wp_localize_script |
| `inc/ad-data-recorder.php` | — | Modified | v5 session fields, Waldo fields, side rail/video |
| `inc/ad-live-sessions.php` | — | Modified | v5 live session columns, Waldo/video |
| `inc/ad-analytics-aggregator.php` | ~391 | Created | Daily stat aggregation, 90-day retention, cron cleanup |
| `inc/ad-analytics-dashboard.php` | — | Modified | v5 dashboard with all new metrics |
| `inc/engagement-breaks.php` | — | Modified | `.ad-anchor` div injection between content items |
| `inc/ad-analytics.php` | 660 | **Deleted** | Replaced by aggregator + dashboard (deploy #219) |

---

## Key Lessons Learned

### 1. Waldo header bidding script hooks into GPT globally

When loaded at page start, Waldo's script intercepts ALL `googletag.pubads()` calls. This hijacks the primary ad network's fill path. **MUST be lazy-loaded** — load only on first `isEmpty` event from GPT, after primary network has had its chance.

### 2. CMP popup (InMobi) is loaded by Ad.Plus network, not theme

Ad.Plus injects its own CMP consent popup. If the theme also loads the CMP script, it double-loads — potentially causing consent race conditions and extra network requests. **Don't load CMP yourself** if the ad network already handles it.

### 3. Viewability tracking: never use closure variables

IO callbacks can fire multiple times for the same ad (resize, scroll, refresh). A closure variable like `let totalVisibleMs = 0` inside the IO creation scope does not share state across these firings correctly. **Accumulate directly on the shared `adRecord` object** (`adRecord.visibleMs += elapsed`).

### 4. LCP-aware deferral is critical for Core Web Vitals

Ad scripts (GPT, Waldo, CMP) compete with the hero image for bandwidth and main thread. Deferring ad boot until after LCP fires (with 200ms buffer) prevents LCP regression. The 3s safety net ensures revenue is not lost if LCP never fires (e.g., no images on page).

### 5. IO thresholds need 0.1 for early detection

Some ad containers start with 0 dimensions (before GPT fills them). An IO threshold array of `[0, 0.5, 1.0]` misses the transition from 0 to small. Including `0.1` catches ads that start empty and expand into view.

### 6. position:fixed on CMP containers prevents CLS

CMP consent popups are overlays. If they use `position:absolute` or `position:relative`, they push page content down when they appear, causing Cumulative Layout Shift. `position:fixed` takes them out of document flow entirely.

### 7. transition:all triggers non-composited animations

Browsers can only GPU-accelerate `transform` and `opacity` transitions. `transition: all` includes properties like `width`, `height`, `margin`, and `background-color`, which trigger layout/paint and run on the main thread. **Always use explicit properties:** `transition: transform 0.3s, opacity 0.3s`.

### 8. googletag.enableSingleRequest() batches slots

Call once during overlay initialization. All slots defined before `enableServices()` are batched into a single GPT request. Slots defined after are requested individually. Order matters for reducing network requests.

### 9. console.log in IO callbacks helps debug viewability — remove for production

During deploys #239-#243, debug logging in IO callbacks was essential for diagnosing vis_ms=0 and fill rate issues. These logs should be removed before production to avoid console noise and minor perf overhead.

### 10. 3s safety net prevents revenue loss

If `PerformanceObserver` for LCP never fires (browser support gaps, no images, synthetic pages), the 3s timeout ensures ad boot still happens. Without it, some sessions would never show ads at all.

### 11. display:none breaks IntersectionObserver

Collapsed ad containers must use `height:0; overflow:hidden; margin:0` instead of `display:none`. IO does not observe elements with `display:none` — they are removed from the rendering tree entirely. The height:0 approach keeps the element in the document flow for IO while making it invisible.

### 12. wp_localize_script converts all values to strings

JavaScript receives all values from `wp_localize_script()` as strings. Loose comparisons (`>=`, `==`) handle coercion automatically, but strict comparisons (`===`) will fail for numeric values. Either use loose comparisons in JS or parse values with `parseInt()`/`parseFloat()`.

### 13. eCPM can crash from viewability problems

Deploy #226 emergency: eCPM dropped from $0.80 to $0.19 due to low viewability. Ad networks reduce bids for inventory with poor viewability scores. Even a few hours of low-viewability traffic can tank eCPM for days. Monitor viewability rate continuously and have a kill switch (dummy_mode) ready.

---

*Document generated 2026-02-22. Source code preserved at git commit `b952bc4`.*
