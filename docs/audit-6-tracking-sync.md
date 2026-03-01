# Audit 6: Tracking & Data Sync

## Post Ads — Every `__plAdTracker.track()` Call (smart-ads.js)

| Event Type | When | JS Extra Fields |
|-----------|------|----------------|
| `dynamic_inject` | `injectDynamicAd()` — ad container created | `slotType:'dynamic'`, `injectionType`, `scrollDirection`, `scrollSpeed`, `predictedDistance`, `adSpacing`, `nearImage`, `adsInViewport`, `adDensityPercent` |
| `impression` | `onDynamicSlotRenderEnded` — GPT fills | `slotType:'dynamic'`, `creativeSize`, `injectionType`, `scrollSpeed`, `scrollDirection`, `adSpacing`, `nearImage`, `adsInViewport`, `adDensityPercent`, `gptResponseMs` |
| `empty` | `onDynamicSlotRenderEnded` — after retry also empty | `slotType:'dynamic'`, `injectionType`, `scrollSpeed`, `scrollDirection`, `adSpacing`, `nearImage`, `afterRetry:true` |
| `viewable` | `onDynamicImpressionViewable` — GPT impressionViewable fires | `slotType:'dynamic'`, `injectionType`, `scrollDirection`, `scrollSpeed`, `timeToViewable`, `adSpacing`, `nearImage`, `adsInViewport`, `adDensityPercent`, `gptResponseMs` |
| `viewport_refresh` | `checkViewportRefresh()` — 3s continuous visibility + speed <20 | `slotType:'dynamic'`, `injectionType:'viewport_refresh'`, `scrollDirection`, `scrollSpeed` |
| `last_chance_refresh` | IO exit from viewport top | `slotType:'dynamic'`, `refreshCount` |
| `creative_timeout` | 10s patient timeout — GPT never responded | `slotType:'dynamic'`, `timeoutMs:10000` |
| `retry` | 3s after empty, retry with [[300,250]] | `slotType:'dynamic'` |
| `skipped` | Fast-scroller (>200px/s) — ad scrolled away in 500ms | `slotType:'dynamic'`, `reason:'fast_scroll_abandon'` |
| `house_ad_shown` | Retry also empty — email capture CTA shown | `slotType:'house'` |
| `house_ad_click` | User clicks house ad | `slotType:'house'` |
| `video_inject` | Video ad injected | `slotType:'video'` |

## Post Ads — Every `trackAdEvent()` Call (initial-ads.js)

| Event Type | When | JS Extra Fields |
|-----------|------|----------------|
| `impression` | `onSlotRenderEnded` — filled | `creativeSize` |
| `empty` | `onSlotRenderEnded` — isEmpty | — |
| `viewable` | `onImpressionViewable` | — |
| `refresh` | After `googletag.pubads().refresh()` | — |
| `refresh_skip` | Tab hidden or out of viewport | — |

---

## Page Ads — Every `sendEvent()` Call (page-ads.js)

| Event Type | When | Fields Sent |
|-----------|------|-------------|
| `filled` | `slotRenderEnded` — !isEmpty | event, pageType, slotId, adFormat, domain, device, viewportWidth, renderedSize, url, timestamp |
| `empty` | `slotRenderEnded` — isEmpty | Same as above (renderedSize = '') |
| `viewable` | `impressionViewable` GPT event OR IO 50%+1s dwell | Same as above |

**Note:** `impression` event was **removed** from `renderSlot()`, `initAnchorAd()`, `initInterstitialAd()`. PHP auto-increments impressions when `filled`/`empty` arrive.

---

## JS → PHP Field Mapping (Post Ads)

### trackAdEvent() → pl_ad_handle_event_batch()

| JS Short Key | JS Source | PHP Variable | DB Column |
|-------------|----------|-------------|-----------|
| `e` | eventType | `$eventType` | `event_type` |
| `s` | slotId | `$slotId` | `slot_id` |
| `u` | `_unitMap[slotId]` | `$unitName` | `unit_name` |
| `t` | `info.type` or `extra.slotType` | `$slotType` | `slot_type` |
| `cs` | `extra.creativeSize` or renderedSize | `$creativeSize` | `creative_size` |
| `rc` | `info.refreshCount` | `$refreshCount` | `refresh_count` |
| `ts` | `Date.now()` | *(not stored)* | *(not stored)* |
| `it` | `extra.injectionType` | `$injectionType` | `injection_type` |
| `ss` | `Math.round(extra.scrollSpeed)` | `$scrollSpeed` | `scroll_speed` |
| `pd` | `Math.round(extra.predictedDistance)` | `$predictedDist` | `predicted_distance` |
| `sp` | `Math.round(extra.adSpacing)` | `$adSpacing` | `ad_spacing` |
| `ttv` | `Math.round(extra.timeToViewable)` | `$timeToViewable` | `time_to_viewable` |
| `sd` | `extra.scrollDirection` | `$scrollDirection` | `scroll_direction` |
| `ni` | `extra.nearImage ? 1 : 0` | `$nearImage` | `near_image` |
| `aiv` | `extra.adsInViewport` | `$adsInViewport` | `ads_in_viewport` |
| `adp` | `extra.adDensityPercent` | `$adDensityPercent` | `ad_density_percent` |
| `grm` | `Math.round(extra.gptResponseMs)` | `$gptResponseMs` | `gpt_response_ms` |
| `rel` | `extra.relocated ? 1 : 0` | `$relocated` | `relocated` |

### Envelope Fields (not per-event, per-batch)

| JS Field | PHP Variable | DB Column |
|----------|-------------|-----------|
| `sid` | `$sid` | `session_id` |
| `device` | `$device` | `device` |
| `pageType` | `$pageType` | `page_type` |
| `postId` | `$postId` | `post_id` |
| `scrollPct` | `$scrollPct` | `scroll_percent` |
| `timeOnPage` | `$timeOnPage` | `time_on_page` |
| `visitorType` | `$visitorType` | `visitor_type` |

---

## JS → PHP Field Mapping (Page Ads)

### sendEvent() → pl_page_ad_record_event()

| JS Field | PHP Variable | DB Storage |
|----------|-------------|-----------|
| `event` | `$event` | Counter key in nested structure |
| `pageType` | `$page_type` | Array nesting level |
| `slotId` | `$slot_id` | *(not stored — counter only)* |
| `adFormat` | `$ad_format` | Array nesting level |
| `domain` | `$domain` | Array nesting level |
| `device` | *(not used)* | **NOT STORED** |
| `viewportWidth` | *(not used)* | **NOT STORED** |
| `renderedSize` | *(not used)* | **NOT STORED** |
| `url` | *(not used)* | **NOT STORED** |
| `timestamp` | *(not used)* | Server-side `gmdate()` |

Page ad storage is aggregated counters only (date > domain > page_type > format > counts). No per-event granularity.

---

## DB Schema — `pl_ad_events` (v6)

```sql
id                  bigint(20) unsigned  AUTO_INCREMENT PK
session_id          varchar(32)          -- 'pl_' + 12 random chars
event_type          varchar(20)          -- impression, empty, viewable, dynamic_inject, etc.
slot_id             varchar(30)          -- 'initial-ad-1', 'smart-ad-5', etc.
unit_name           varchar(50)          -- 'Ad.Plus-336x280', etc.
slot_type           varchar(20)          -- 'initial', 'dynamic', 'sidebar', 'house', etc.
creative_size       varchar(15)          -- '300x250', '336x280', etc.
refresh_count       tinyint(3) unsigned  -- 0-255
visitor_type        varchar(15)          -- 'reader', 'scanner', 'fast-scanner'
scroll_percent      tinyint(3) unsigned  -- 0-100
time_on_page        int(10) unsigned     -- seconds
device              varchar(10)          -- 'desktop', 'tablet', 'mobile'
page_type           varchar(10)          -- 'single', 'home', 'archive'
post_id             int(10) unsigned
injection_type      varchar(20)          -- 'pause', 'slow_scroll', 'predictive', etc. (v2)
scroll_speed        int(10) unsigned     -- px/s (v2)
predicted_distance  int(11)              -- px (v2)
ad_spacing          int(10) unsigned     -- px (v2)
time_to_viewable    int(10) unsigned     -- ms (v2)
scroll_direction    varchar(4)           -- 'up', 'down' (v3)
near_image          tinyint(1) unsigned  -- 0/1 (v4)
ads_in_viewport     tinyint(3) unsigned  -- 0-255 (v4)
ad_density_percent  tinyint(3) unsigned  -- 0-100 (v4)
gpt_response_ms     int(10) unsigned     -- ms (v5)
relocated           tinyint(1) unsigned  -- 0/1 (v5)
created_at          datetime             -- CURRENT_TIMESTAMP

INDEXES: idx_created, idx_slot_created, idx_event_created, idx_injection
```

### `pl_ad_hourly_stats` (rollup)

```sql
id           bigint(20) unsigned  AUTO_INCREMENT PK
hour         datetime
slot_type    varchar(20)
slot_id      varchar(30)
device       varchar(10)
impressions  int(10) unsigned
empties      int(10) unsigned
viewables    int(10) unsigned
refreshes    int(10) unsigned
refresh_skips int(10) unsigned
clicks       int(10) unsigned

UNIQUE KEY: idx_hourly (hour, slot_type, slot_id, device)
```

---

## Field Sync Matrix — Post Ads

| JS Extra Field | Sent On `dynamic_inject` | Sent On `impression` | Sent On `empty` | Sent On `viewable` | DB Column | Injection Lab Queries | SYNCED? |
|---------------|:-:|:-:|:-:|:-:|-----------|------|:---:|
| `injectionType` | YES | YES (v6 fix) | YES (v6 fix) | YES (v6 fix) | `injection_type` | `WHERE injection_type != ''` | **SYNCED** (was broken pre-v6) |
| `scrollSpeed` | YES | YES (v6 fix) | YES (v6 fix) | YES (v6 fix) | `scroll_speed` | `WHERE scroll_speed > 0` | **SYNCED** |
| `scrollDirection` | YES | YES (v6 fix) | YES (v6 fix) | YES (v6 fix) | `scroll_direction` | `WHERE scroll_direction != ''` | **SYNCED** |
| `adSpacing` | YES | YES (v6 fix) | YES | YES (v6 fix) | `ad_spacing` | `WHERE ad_spacing > 0` | **SYNCED** |
| `nearImage` | YES | YES (v6 fix) | YES | YES (v6 fix) | `near_image` | `GROUP BY near_image` | **SYNCED** |
| `adsInViewport` | YES | YES (v6 fix) | *no* | YES (v6 fix) | `ads_in_viewport` | `AVG(ads_in_viewport)` | **SYNCED** (empty events: N/A) |
| `adDensityPercent` | YES | YES (v6 fix) | *no* | YES (v6 fix) | `ad_density_percent` | `WHERE ad_density_percent` | **SYNCED** |
| `gptResponseMs` | *no* | YES | *no* | YES (v6 fix) | `gpt_response_ms` | `WHERE gpt_response_ms > 0` | **SYNCED** |
| `relocated` | *no* | YES | *no* | *no* | `relocated` | `GROUP BY relocated` | **SYNCED** |
| `predictedDistance` | YES | *no* | *no* | *no* | `predicted_distance` | *(not queried)* | SYNCED (only on inject) |
| `creativeSize` | *no* | YES | *no* | *no* | `creative_size` | *(queried in events analytics)* | SYNCED |
| `timeToViewable` | *no* | *no* | *no* | YES | `time_to_viewable` | `WHERE time_to_viewable > 0` | SYNCED |

---

## Known Tracking Gaps

### 1. `viewport_refresh` Not Visible in Injection Lab
**Symptom:** Viewport refreshes appear in session stats but NOT in injection lab type comparison.
**Root cause:** `viewport_refresh` events have `injectionType: 'viewport_refresh'` but injection lab query #1 groups by `injection_type` from `dynamic_inject` events. `viewport_refresh` is tracked as a separate event_type, not as a `dynamic_inject` with `injection_type='viewport_refresh'`.
**Impact:** Low — viewport refreshes are visible in session-level data.

### 2. `creative_timeout` Events Not Queried
**Symptom:** 10s GPT timeout events stored in DB but no injection lab section displays them.
**Root cause:** No SQL query fetches `event_type = 'creative_timeout'`.
**Impact:** Low — timeouts visible in live feed.

### 3. `retry` Events Not in Fill Rate Calculation
**Symptom:** Retried slots that fill on second attempt may be double-counted.
**Root cause:** The retry creates a new GPT slot but reuses the same `divId`. The `impression` event from the retry has the same `slot_id`. If both the initial empty and retry empty are counted, the fill rate denominator is inflated.
**Impact:** Minor inflation of empty count.

### 4. Page Ads: No Per-Event Granularity
**Symptom:** Page ad stats show only aggregate counters.
**Root cause:** `page-ad-recorder.php` stores data in `wp_option` as nested counters (date > domain > page_type > format > counts). No DB table, no per-slot, per-session detail.
**Impact:** Cannot analyze page ad viewability by device, viewport width, slot position, or session.

### 5. Page Ads: `device`, `viewportWidth`, `renderedSize`, `url` Not Stored
**Symptom:** JS sends these fields but PHP ignores them.
**Root cause:** `pl_page_ad_record_event()` only reads `event`, `pageType`, `slotId`, `domain`, `adFormat`.
**Impact:** Cannot break down page ad performance by device or rendered creative size.

### 6. Exit Interstitial Not in `_dynamicSlots`
**Symptom:** Exit interstitial data only appears in zones array of beacon/heartbeat. Not in `_dynamicSlots`.
**Root cause:** By design — exit interstitial has no DOM element, would crash engine loop.
**Impact:** None — correctly tracked via `_exitRecord` appended to zones.

---

## Injection Lab SQL vs Actual Data Verification

### Query 1: Type Comparison
```sql
WHERE slot_type = 'dynamic' AND injection_type != ''
```
**Risk:** Events without `injection_type` excluded. v6 migration backfills from `dynamic_inject` events. Events that never had a corresponding `dynamic_inject` (e.g., orphaned) remain excluded.

### Query 2: Speed Brackets
```sql
WHERE slot_type = 'dynamic' AND scroll_speed > 0
```
**Risk:** Events where `scroll_speed` was not sent (Layer 1 events, house ads) excluded. Correct behavior.

### Query 3: Spacing Ranges
```sql
WHERE slot_type = 'dynamic' AND ad_spacing > 0
```
**Risk:** Pre-v6 `viewable` events had `ad_spacing = 0` → excluded from spacing analysis. v6 backfill partially fixes (only if matching `dynamic_inject` exists).

### Query 8: GPT Response Time
```sql
WHERE slot_type = 'dynamic' AND gpt_response_ms > 0
```
**Risk:** Only events WITH gpt_response_ms included. This correctly showed fills when type_comparison showed 0% (different filter).

### Query 9: Relocation Stats
```sql
WHERE slot_type = 'dynamic' AND event_type IN ('impression', 'viewable')
GROUP BY relocated
```
**Risk:** `relocated` only sent on `impression` events. `viewable` events don't have it, so `relocated = 0` for all viewable rows. Relocation is DISABLED anyway.

---

## Timezone Analysis

| Layer | Timezone Used | Details |
|-------|-------------|---------|
| **JS `Date.now()`** | User's local timezone (UTC offset) | All `ts` fields in event batches. `sendEvent` in page-ads uses `new Date().toISOString()` (UTC). |
| **PHP `current_time('mysql')`** | WordPress configured timezone | `created_at` in `pl_ad_events`. Uses site timezone, NOT UTC. |
| **PHP `gmdate()`** | UTC | `$date_key` in `pl_page_ad_stats`. Page ad dates are UTC. |
| **PHP `current_time('Y-m-d H:00:00')`** | WordPress timezone | `$hour` in hourly stats aggregation. |
| **Ad.Plus / GPT** | UTC | Google's reporting is always UTC. |

### Timezone Mismatches

1. **JS timestamp vs PHP `created_at`**: JS sends `Date.now()` (client time) but PHP ignores it and uses `current_time('mysql')` (server WordPress time). They can differ by hours if user is in a different timezone. This is correct behavior — server time is authoritative.

2. **Page ad dates (UTC) vs Post ad dates (WP timezone)**: `page-ad-recorder.php` uses `gmdate('Y-m-d')` (UTC) for date keys. `ad-data-recorder.php` uses `current_time('mysql')` (WP timezone) for `created_at`. If WP timezone is UTC+5, a midnight event would land on different dates in the two systems.

3. **Hourly stats (WP timezone) vs page ad stats (UTC)**: Hourly stats use `current_time('Y-m-d H:00:00')` while page ads use `gmdate()`. Cross-system analysis will show time offsets equal to the WordPress timezone offset.

4. **Ad.Plus reporting vs internal analytics**: Ad.Plus/Google reports in UTC. Internal analytics use WP timezone. Revenue reconciliation requires timezone alignment.

---

## DB Migration History

| Version | Migration | Added Columns |
|---------|----------|--------------|
| v1 → v2 | injection analytics | `injection_type`, `scroll_speed`, `predicted_distance`, `ad_spacing`, `time_to_viewable` |
| v2 → v3 | direction tracking | `scroll_direction` |
| v3 → v4 | image/density tracking | `near_image`, `ads_in_viewport`, `ad_density_percent` |
| v4 → v5 | GPT response + relocation | `gpt_response_ms`, `relocated` |
| v5 → v6 | backfill injection_type | UPDATE joins `dynamic_inject` events to backfill `injection_type` on `impression`, `viewable`, `empty` rows |

Current version: `'6'` (checked via `get_option('pl_ad_tables_ver')`).
On-demand v5 migration also exists in `pl_ad_handle_event_batch()` for cases where admin_init hasn't run yet.

---

## Data Retention

| Storage | Retention | Cleanup Method |
|---------|----------|---------------|
| `pl_ad_events` | 90 days | `pl_ad_cleanup_old_events()` via admin_init + daily transient guard |
| `pl_ad_hourly_stats` | 90 days | Same cleanup function |
| `pl_page_ad_stats` | 30 days | Auto-pruned on every write in `pl_page_ad_record_event()` |
| Live session transients | 60s active, 2hr recent | Transient TTL |

---

## Rate Limiting

| System | Mechanism | Limit |
|--------|----------|-------|
| Post ad events | IP hash + 5s window transient | 1 batch per 5s per IP |
| Page ad events | **None** (rate limiter was removed — it caused 0% fill rate) | Unlimited |
| Live session heartbeat | **None** | 5s JS interval |
