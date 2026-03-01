# Audit 3: Layer 2 — Smart Dynamic Ads (`smart-ads.js`)

## Engine Loop (`engineLoop()`, 100ms interval)

Runs every 100ms via `setInterval`. Gated by `_tabVisible` — stops all ad operations when tab is hidden.

```
engineLoop() — called every 100ms
  ├─ if (!_tabVisible) return          // Tab hidden → skip everything
  ├─ checkVideoInjection()             // Runs independently of display ad guards
  ├─ checkViewportRefresh()            // Per-slot visibility tracking, runs every tick
  ├─ if (dynamic ads disabled) return  // FMT.dynamic check
  ├─ if (_scrollDirection === 'up') return  // DISABLED: scroll-up injection (8% viewability)
  ├─ Count active (non-destroyed) slots
  ├─ Viewport density guard:
  │   ├─ Desktop: max 2 ads in view (DESKTOP_MAX_IN_VIEW)
  │   ├─ Mobile: max 1 ad in view (MOBILE_MAX_IN_VIEW)
  │   └─ Max 30% of viewport height occupied (MAX_AD_DENSITY_PERCENT)
  │
  ├─ Strategy 1: PAUSE
  │   ├─ Condition: _isPaused AND (Date.now() - _pauseStartT) >= PAUSE_THRESHOLD_MS (120ms)
  │   ├─ Target: findPauseTarget() → viewport center paragraph
  │   └─ recycleSlots() then injectDynamicAd(target, 'pause')
  │
  ├─ Strategy 1.5: SLOW SCROLL
  │   ├─ Condition: speed 100-120 px/s, NOT paused, NOT decelerating
  │   ├─ Sustained check: 4 of last 6 velocity samples under 120 px/s
  │   ├─ Target: findScrollTarget() → paragraph ahead of scroll
  │   └─ recycleSlots() then injectDynamicAd(target, 'slow_scroll')
  │
  └─ Strategy 2: PREDICTIVE
      ├─ Condition: _isDecelerating AND speed < PREDICTIVE_SPEED_CAP (300 px/s)
      ├─ Target: findScrollTarget() → paragraph at predicted stop point
      └─ recycleSlots() then injectDynamicAd(target, 'predictive')
```

Only ONE injection per tick (early return after each strategy fires).

---

## Injection Strategies

### Strategy 1: PAUSE (highest viewability — ~50%)
- **Trigger:** Speed drops below 100 px/s for 120ms continuously
- **Rapid deceleration detection:** Speed drops from >150 px/s to <100 px/s within 300ms → treated as immediate pause
- **Target:** `findPauseTarget()` — paragraph nearest viewport center
- **Rationale:** User has stopped reading; ad appears right where they're looking

### Strategy 1.5: SLOW SCROLL (sustained reading pace)
- **Trigger:** Speed between 100-120 px/s, sustained (4/6 samples under 120)
- **Target:** `findScrollTarget()` — paragraph ahead of scroll position
- **Rationale:** User is reading consistently but not pausing; still high-viewability window

### Strategy 2: PREDICTIVE (deceleration-based)
- **Trigger:** Speed peak decaying (>50% drop from recent peak) AND speed < 300 px/s
- **Speed cap reasoning:** At 300 px/s, a 250px ad is visible for 0.83s — near the 1s viewability threshold
- **Target:** `findScrollTarget()` — paragraph at predicted stop point (speed × PREDICTIVE_WINDOW)

---

## Scroll Speed & Direction Measurement

### Velocity Sampling (`sampleVelocity()`, 50ms interval + passive scroll listener)

```
sampleVelocity():
  1. Calculate raw velocity: (currentY - _lastSampleY) / (now - _lastSampleT) * 1000  // px/s
  2. Push to _velocitySamples (keep last 10)
  3. Smooth: average of last 3 samples → _velocity (signed: + = down)
  4. _speed = Math.abs(_velocity)
  5. Classify visitor type based on _speed
  6. Update _scrollDirection ('up' or 'down')
  7. Track direction changes (_dirChanges)
  8. Update _maxScrollDepth
  9. Update _timeOnPage
  10. Deceleration detection:
      - Peak tracking with 2s decay
      - _isDecelerating = peak dropped >50%
      - _isRapidDecel = speed went from >150 to <100 in <300ms
  11. Pause detection:
      - _isPaused = speed < PAUSE_VELOCITY (100 px/s)
      - _pauseStartT = when current pause began
```

### Visitor Classification

| Type | Speed Threshold | Time Between Ads |
|------|----------------|-----------------|
| `reader` | <100 px/s | 2.5s |
| `scanner` | 100-400 px/s | 3.0s |
| `fast-scanner` | >400 px/s | 3.5s |

Classification is continuous — `_visitorType` updates every sample.

---

## Dynamic Size Selection (`getDynamicAdSizes()`)

| Viewport | Sizes Array | Size Mapping | Rationale |
|----------|------------|-------------|-----------|
| Mobile (<768px) | `[[300, 250]]` | All breakpoints → `[[300, 250]]` | Single auction, ~200ms faster fill |
| Tablet (768-1024px) | `[[300, 250], [336, 280]]` | 768+ → both, <768 → 300x250 only | Modest multi-size |
| Desktop (≥1025px) | `[[336, 280], [300, 250], [300, 600]]` | 1025+ → all three, 768+ → two, <768 → one | Full multi-size for higher RPM |

All dynamic ads use unit path `{SLOT_PATH}Ad.Plus-300x250`.

---

## Spacing Rules

### Speed-Based Spacing
```
spacing = speed × timeBetween  (clamped [400, 1000] px)

Where timeBetween:
  reader (< 100 px/s):       2.5s
  scanner (100-400 px/s):    3.0s
  fast-scanner (> 400 px/s): 3.5s
```

### Directional Modifier
- Scroll-up: `spacing × 1.3` (users scan back too fast for viewability)
- Scroll-down: base spacing

### Enforcement Points
1. **PHP config:** `MIN_PIXEL_SPACING` initialized with `Math.max(400, L2.minPixelSpacing)`
2. **JS init:** `MIN_PIXEL_SPACING = Math.max(400, ...)`
3. **Runtime guard:** `if (adSpacing < MIN_PIXEL_SPACING)` → abort injection, remove container
4. **Layer 1 exclusion zones:** `getNearestAdDistance()` checks `__initialAds.getExclusionZones()` (250px buffer each side)

---

## Slot Recycling

- **Trigger:** When active (non-destroyed) slots exceed `MAX_DYNAMIC_SLOTS` (20)
- **Target:** Oldest non-destroyed slot that is 1000px+ above viewport (`RECYCLE_DISTANCE`)
- **Process:** `destroySlot()` → sets `destroyed=true`, disconnects all IntersectionObservers, calls `googletag.destroySlots([slot])`, removes DOM element
- **One at a time:** Only recycles one slot per call
- **Aggressive recycle on rescan:** `rescanAnchors()` destroys ALL above-viewport slots (not just >1000px) to free budget for auto-loaded posts

---

## Viewability Tracking

### Per-Slot Viewport Refresh (`checkViewportRefresh()`)
Runs every engine tick (100ms):
1. For each filled, non-destroyed slot with `refreshCount < MAX_REFRESH_DYN` (2)
2. Check 30s minimum since last refresh (Google policy)
3. Calculate visibility: `visibleHeight / slotHeight ≥ 0.5`
4. Track continuous visibility via `_slotViewStart[divId]`
5. Must be visible for `VP_REFRESH_DELAY` (3s) continuously
6. User must be nearly stopped: `_speed < 20 px/s`
7. Skip if 2+ other filled ads within ±200px of viewport
8. Refresh via `googletag.pubads().refresh([slot])`

### Last-Chance Refresh (`observeForLastChanceRefresh()`)
IntersectionObserver watches each filled ad:
- Triggers when ad exits viewport TOP (scrolled past, going down)
- Guards: `viewableEver`, `refreshCount < MAX_REFRESH_DYN`, 30s cooldown, tab visible
- Refreshes the slot so a new creative is ready if user scrolls back up

### GPT `impressionViewable` Handler (`onDynamicImpressionViewable()`)
- Sets `rec.viewable = true`, `rec.viewableEver = true`
- Increments `window.__plViewableCount` (shared counter)
- Caps time-to-viewable at 30s (outliers >30s skew averages)
- Tracks via both `pushEvent` and `__plAdTracker.track('viewable', ...)`

---

## Render Pipeline

### Standard Path (speed ≤ 200 px/s)
```
injectDynamicAd()
  └─ observeForLazyRender() — IO with 400px rootMargin
      └─ When entering viewport (400px ahead):
          └─ renderSlot(record, sizes, sizeMapping)
              ├─ googletag.defineSlot() + defineSizeMapping() + addService()
              ├─ googletag.display(divId)
              ├─ googletag.pubads().refresh([slot])  // Required for SRA mode
              └─ 10s patient timeout (collapse if GPT never responds)
```

### Fast-Scroller Path (speed > 200 px/s)
```
injectDynamicAd()
  └─ setTimeout(500ms)
      ├─ Check: div still near viewport? (within 1.5x viewport height)
      │   ├─ YES → renderSlot()
      │   └─ NO → destroy container, increment _totalSkips
      └─ Check: _tabVisible? If not, defer
```

---

## Exit-Intent Interstitial

### Initialization
`initExitIntent()` runs at script load time (NOT inside `init()` — catches exits before engagement gate).

### Triggers
1. `visibilitychange` (tab switch) → `tryExitInterstitial('visibility')`
2. `mouseleave` (clientY < 10) → `tryExitInterstitial('mouseleave')`
3. `beforeunload` → `tryExitInterstitial('beforeunload')`

### Guards
- `_exitFired` — fires once per page
- `plAds.exitInterstitial` admin toggle
- Session must be ≥15 seconds (`EXIT_MIN_SESSION_S`)
- GPT must be loaded (`googletag.apiReady`)

### Strategy Based on Layer 1 Status
```
Check __plOverlayStatus.interstitial:
  'pending' → Refresh existing Layer 1 slot (nudge GPT to show now)
  'filled'/'viewable'/'empty' → Destroy Layer 1 slot, create new exit slot
  'off' → Create new exit slot
```

### Tracking
`_exitRecord` tracks fill/viewable/size but is NOT pushed to `_dynamicSlots` (no DOM element — would crash engine loop). Instead appended to beacon/heartbeat zones array.

---

## Disabled Features (DO NOT RE-ENABLE)

### Ad Relocation — DISABLED (Feb 27, 2026)
- **Data:** 82 relocated ads had 0% viewability
- **Code:** `relocateFilledAd()` function remains but is never called
- **Removed from:** `onDynamicSlotRenderEnded` — both immediate check and 2s delayed recheck

### Scroll-Up Injection — DISABLED (Feb 27, 2026)
- **Data:** 8% viewability vs 28% for scroll-down
- **Implementation:** `if (_scrollDirection === 'up') return;` in `engineLoop()` after dynamic ads check
- **Note:** Viewport refresh and video injection still run on scroll-up (only new ad injection blocked)

### 250x250 Format — REMOVED (Feb 28, 2026)
- **Data:** $0.03 total revenue, 12-60% viewability
- **Removed from:** All GPT size arrays in both initial-ads.js and smart-ads.js

---

## Key State Variables

```js
_dynamicSlots[]     // All dynamic ad records (array)
_slotCounter        // divId numbering (smart-ad-1, smart-ad-2, ...)
_totalSkips         // Fast-scroll abandoned ads
_totalRetries       // Empty slot retry attempts
_houseAdsShown      // House ad backfills (max 2 per page)
_activeStickyAd     // Current sticky ad container (only one at a time)
_tabVisible         // Gates engine when tab hidden
_engineInterval     // Stored interval ID
_slotViewStart{}    // Per-slot viewport visibility timestamps
_isPaused           // Speed < 100 px/s
_isDecelerating     // Speed dropped >50% from peak
_isRapidDecel       // >150 to <100 px/s in <300ms
_exitFired          // Exit interstitial fired (once per page)
_exitTrigger        // Which trigger fired it
_exitRecord         // Exit interstitial tracking record
```

---

## Public API

```js
window.SmartAds = {
    rescanAnchors: function()  // Notify Layer 2 of new content (next-post loader)
                               // Destroys all above-viewport slots
                               // Resets _houseAdsShown to 0
                               // Clears _slotViewStart
};
```

---

## Beacon & Heartbeat

### Beacon (`sendBeacon()`)
- Fires on: `visibilitychange` (hidden) + `pagehide`
- Method: `navigator.sendBeacon` to `plAds.recordEndpoint`
- Payload: Full session data + zones array with all dynamic slot details + exit interstitial record

### Heartbeat (`sendHeartbeat()`)
- Fires every 5s via `setInterval`
- Method: `fetch()` to `plAds.heartbeatEndpoint`
- Payload: Same totals as beacon + zones for non-destroyed slots only
- First heartbeat sent immediately on start
