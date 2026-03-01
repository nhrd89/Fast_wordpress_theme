# Audit 5: Admin Interface, Analytics & Optimizer

## Admin Menu Structure

All admin pages are submenus under `pl-ad-engine` (parent added by `inc/ad-engine.php`).
Hidden on Ezoic sites via `pl_is_ezoic_site()` guard on `pl_ad_admin_menu()`.

| Submenu | File | Slug | Purpose |
|---------|------|------|---------|
| Ad Engine (parent) | `ad-engine.php` | `pl-ad-engine` | Global settings, format toggles, ads.txt |
| Ad Optimizer | `ad-optimizer.php` | `pl-ad-optimizer` | Auto-optimization cron, presets, Layer 2 tuning |
| Analytics Dashboard | `ad-analytics-dashboard.php` | `pl-ad-analytics-dashboard` | Session + event-level analytics |
| Injection Lab | `ad-injection-lab.php` | `pl-injection-lab` | Real-time injection analysis |
| Live Sessions | `ad-live-sessions.php` | `pl-ad-live-sessions` | Active visitor session monitoring |
| Recommendations | `ad-recommendations.php` | `pl-ad-recommendations` | AI-generated optimization advice |
| Page Ads | `page-ad-engine.php` | `pl-page-ad-settings` | Page ad settings + stats dashboard |

---

## Ad Engine (`ad-engine.php`) — Global Settings

### Settings Tabs
1. **Global Controls** — Master enable/disable, debug mode, slot prefix, network code
2. **Ad Codes** — GPT slot paths, custom ad units
3. **Device Controls** — Mobile/desktop toggles, next_post_autoload, exit_interstitial
4. **Page Ads** — Link to separate page ad settings tab

### Key Functions
- `pl_ad_settings()` — Returns merged settings with defaults
- `pl_ad_defaults()` — Default values for all settings
- `pinlightning_ads_enqueue()` — Outputs `plAds` config JSON to frontend (guarded by `pl_is_ezoic_site()`)
- `pinlightning_output_cmp_tag()` — CMP consent tag (Google Ad Manager built-in)
- `pinlightning_ads_txt()` — Generates ads.txt content

### Frontend Config (`plAds` object)
```js
plAds = {
    ajaxUrl, nonce, postId, postSlug, postTitle,
    record: true/false, recordEndpoint, heartbeatEndpoint,
    enabled: true/false, debug: true/false,
    slotPrefix, networkCode,
    formats: { initial1, initial2, sidebar1, sidebar2, pause, dynamic, ... },
    refresh: { initial: {enabled, delay, max}, sidebar: {...}, ... },
    layer2: { maxSlots, pauseThreshold, minPixelSpacing, ... },
    video: { enabled, minScroll, minFilledAds, allowedVisitor },
    viewability: { viewportCheck, minVisibility, skipTabHidden },
    exitInterstitial: true/false,
    autoLoad: true/false
}
```

---

## Ad Optimizer (`ad-optimizer.php`)

### Daily Cron
- Hook: `pl_ad_optimizer_cron` via `wp_schedule_event('daily')`
- Function: `pl_ad_run_optimizer()`
- Reads 2 days of aggregated session stats
- Minimum 30 sessions required (`PL_OPT_MIN_SESSIONS`)
- Max 3 changes per run (`PL_OPT_MAX_CHANGES`)

### 12 Optimization Rules
Each rule evaluates stats and returns a setting adjustment:

| Rule | Evaluates | Adjusts |
|------|----------|---------|
| Fill rate < 40% | total_ad_fills / total_ad_requests | Reduce max slots |
| Fill rate > 70% | Same | Increase max slots |
| Viewability < 40% | total_viewable / total_zones | Increase min spacing |
| Viewability > 65% | Same | Decrease spacing |
| Anchor fill < 30% | anchor_filled / anchor_fired | Disable anchor |
| Interstitial fill > 60% | interstitial metrics | Enable interstitial |
| Mobile viewability low | by_device breakdown | Reduce mobile density |
| Refresh skip rate > 50% | refresh_skips / total_refreshes | Increase refresh delay |
| Avg time < 20s | session time | Reduce max slots |
| Scroll depth < 30% | avg scroll percent | Reduce below-fold ads |
| Pause injection share | pause / total injections | Tune pause threshold |
| High empty rate | empties / impressions | Reduce ad density |

### Presets
| Preset | Description |
|--------|------------|
| `light` | Conservative: fewer slots, wider spacing, fewer overlays |
| `normal` | Balanced: default settings |
| `aggressive` | Maximum: more slots, tighter spacing, all overlays enabled |

### Adjustable Settings
```php
pl_ad_format_settings       // Format toggles (initial1, sidebar1, anchor, etc.)
pl_ad_refresh_settings      // Per-format refresh config (enabled, delay, max)
pl_ad_layer2_settings       // Layer 2 tuning (maxSlots, pauseThreshold, spacing, etc.)
pl_ad_video_settings        // Video ad config
pl_ad_viewability_settings  // Viewport check, min visibility, tab hidden skip
pl_ad_ecpm_settings         // Per-format eCPM estimates
pl_ad_auto_optimize         // Auto-optimization flags
```

### History
- Last 30 optimization runs stored in `pl_ad_optimizer_log`
- Each entry: time, status, summary (sessions, fill_rate, viewability), changes, warnings, info

---

## Injection Lab (`ad-injection-lab.php`)

### Overview Totals
- Total injections, pause/slow_scroll/predictive counts
- Total filled, total viewable
- Total viewport refreshes
- Avg TTV by strategy (pause, slow, predictive)
- Avg GPT response time (ms)
- Relocated count

### 10 Analysis Sections

| # | Section | Groups By | Key Metrics |
|---|---------|----------|-------------|
| 1 | Type Comparison | `injection_type` | injections, filled, viewable, avg_speed, avg_spacing, avg_ttv |
| 2 | Speed Brackets | 0-50, 50-100, ..., 500+ px/s | injections, viewable, avg_spacing, avg_ttv |
| 3 | Spacing Ranges | 200-400, 400-600, ..., 1000+ px | injections, viewable, avg_ttv |
| 4 | Visitor Types | reader, scanner, fast-scanner | sessions, injections, viewable, per-strategy counts |
| 5 | Scroll Direction | up, down | injections, filled, viewable, avg_speed, avg_spacing |
| 6 | Image Proximity | near_image (0/1) | injections, filled, viewable, avg_ttv, avg_spacing |
| 7 | Viewport Density | 0%, 1-10%, ..., 30%+ | injections, viewable, avg_ttv, avg_ads_in_view |
| 8 | GPT Response Time | 0-1s, 1-2s, ..., 10s+ | total, filled, viewable |
| 9 | Relocation Stats | relocated (0/1) | filled, viewable, avg_ttv, avg_response_ms |
| 10 | Live Feed | Per-slot grouped | time, session, slot, type, direction, speed, fill, viewable |

### Time Ranges
- 1h, 6h, 24h, 7d
- Auto-refresh via AJAX every 30 seconds
- Export as JSON available

### SQL Query Patterns
All queries filter on `slot_type = 'dynamic'` and use `created_at >= cutoff`.
Fill rate computed from `event_type = 'impression'` rows (NOT `dynamic_inject`).
Viewable computed from `event_type = 'viewable'` rows.

**Known query dependency:** `WHERE injection_type != ''` — events without `injection_type` are excluded. This was the root cause of the 0% fill rate bug (fixed in v6 migration with backfill).

---

## Analytics Dashboard (`ad-analytics-dashboard.php`)

### Data Sources
1. **Session-level stats** — Aggregated daily from `pl_ad_daily_stats` option
2. **Event-level stats** — From `pl_ad_events` + `pl_ad_hourly_stats` tables

### Report Sections (10)
1. Overview cards (impressions, fills, viewable, est. revenue)
2. Trend charts (daily fill rate, viewability, sessions)
3. Fill analysis (by slot type)
4. Viewability breakdown (by slot, device)
5. Overlay performance (anchor, interstitial, side rails)
6. Passback report
7. Traffic breakdown (device, referrer, visitor type)
8. Zone performance (per-slot)
9. Top posts (by ad performance)
10. Click tracking

### Export/Clear
- JSON export with date range selection (today, yesterday, 7d, 30d, custom)
- Clear all stats (with admin nonce)

---

## Live Sessions (`ad-live-sessions.php`)

### Architecture
```
Browser: smart-ads.js heartbeat (5s interval)
  → POST /pl-ads/v1/heartbeat
  → Server stores in transient 'pl_live_sess_index' (keyed by sid)
  → Stale after 60s no heartbeat → moved to 'pl_live_recent_sessions' (2hr TTL)

Admin: JS polls GET /pl-ads/v1/live-sessions every 5s
  → Returns active + recent sessions
```

### Session Data (per heartbeat)
- `sid`, `ts`, `postId`, `postSlug`, `postTitle`
- `device`, `viewportW`, `viewportH`
- `timeOnPage`, `activeTime`, `maxDepth`, `scrollPct`
- `scrollSpeed`, `scrollPattern`, `dirChanges`
- `totalInjected`, `totalViewable`, `viewabilityRate`
- `totalRequested`, `totalFilled`, `fillRate`
- `totalRefreshes`, `totalSkips`, `totalRetries`
- Overlay status (anchor, interstitial, side rails)
- `zones[]` — Per-ad detail (divId, size, viewable, filled, injectionType, speed, spacing, GPT ms)

### Clear All
AJAX `pl_live_clear_all`: Deletes transients + TRUNCATEs `pl_ad_events` and `pl_ad_hourly_stats`.

---

## Recommendations (`ad-recommendations.php`)

### Rule Categories
| Category | Triggers | Priority |
|----------|----------|----------|
| Fill Rate | <50% (danger), 50-75% (warning), ≥85% (success) | 1-5 |
| Viewability | <50% (danger), 50-70% (warning), ≥80% (success) | 1-5 |
| Engagement | Time <15s (warning), ≥60s (success) | 2-5 |
| Scroll Depth | <40% (warning) | 3 |
| Overlays | Anchor fill <50%, interstitial not firing, interstitial viewability ≥80% | 2-5 |
| Refresh | Skip rate >50% (warning), <20% with >100 refreshes (success) | 3-5 |
| Fill by Slot | Any slot type <40% fill (danger) | 2 |
| Device | Mobile viewability lags desktop by >20% (warning) | 3 |
| Zone-Level | Zero-viewability zones, low-fill zones | 2-3 |

### Output
Each recommendation: `type` (success/warning/danger/info), `category`, `title`, `detail`, `metric`, `priority` (1=critical, 5=nice-to-have).

Also embedded in injection lab as inline recommendations based on speed, spacing, and visitor analysis.

---

## Known Issues & Disabled Features

### Disabled (tracked in optimizer/admin)
- **Ad relocation** — 0% viewability, code remains but never called
- **Scroll-up injection** — 8% viewability, guard in engineLoop
- **250x250 format** — $0.03 revenue, removed from all size arrays
- **Top anchor ad** — "Format already created" conflict
- **HTML minification** — Blocks streaming, inflated TTFB

### Known Tracking Gaps
- Viewport refresh appears in session stats but NOT in injection lab (injection_type tracking gap)
- Pause injection share dropped from 9% to 3.3% after Round 1 (may need PAUSE_VELOCITY tuning)
- `time_to_viewable` outliers >30s capped at 30000ms (since Feb 27)

---

## Ezoic Compatibility

### What's Hidden on Ezoic Sites
- Entire Ad Engine admin menu (`pl_ad_admin_menu()` returns early)
- All ad-related JavaScript (initial-ads, smart-ads, page-ads)
- All ad container HTML (sidebar, in-content, page anchors)
- Ad CDN preconnects in `<head>`
- Content filter ad injection

### What Still Works on Ezoic Sites
- All engagement features (progress bar, milestones, polls, quizzes)
- Pinterest Save buttons and SEO
- Image handling (lazy load, CDN rewriting, WebP)
- GA4 analytics
- Visitor tracking
- Email capture system
- Homepage templates (Tec Slate has no ad anchors)
- `wp_head()` and `wp_footer()` called normally (Ezoic hooks into these)
