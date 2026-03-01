# Audit 4: Page Ad System (`page-ads.js` + `page-ad-engine.php`)

## How Page Ads Differ from Post Ads

| Aspect | Post Ads (Layer 1 + 2) | Page Ads |
|--------|----------------------|----------|
| **PHP guard** | `is_single()` | `!is_single()` |
| **JS guard** | N/A | Bail if `.single-post` or `window.plAds` |
| **JS global** | `window.plAds` | `window.plPageAds` |
| **CSS class** | `.pl-initial-ad`, `.pl-dynamic-ad` | `.pl-page-ad-slot`, `.pl-page-ad-anchor` |
| **GPT ID prefix** | `initial-ad-`, `smart-ad-` | `pl-page-ad-` |
| **Slot name** | `{site}_{format}` | `{site}_pg_{format}` |
| **REST endpoint** | `/pinlightning/v1/ad-data` | `/pl/v1/page-ad-event` |
| **Settings option** | `pl_ad_settings` | `pl_page_ad_settings` |
| **Admin tab** | Global Controls / Ad Codes | Page Ads |
| **Boot trigger** | window.load + 100ms / user interaction | window.load + DOMContentLoaded |
| **Injection** | Dynamic (scroll-based) | Static (predefined positions) |
| **Ezoic guard** | `pl_is_ezoic_site()` per function | `pl_is_ezoic_site()` per function |

---

## PHP Settings (`page-ad-engine.php`)

### Defaults (`pl_page_ad_defaults()`)
```php
'enabled'          => true,
'dummy_mode'       => true,          // Colored placeholders — safe testing default
'slot_prefix'      => '/21849154601,22953639975/',
'network_code'     => '22953639975',
'homepage_enabled' => true,
'category_enabled' => true,
'page_enabled'     => true,
'homepage_max'     => 3,
'category_max'     => 10,
'page_max'         => 3,
'fmt_anchor'       => true,
'fmt_interstitial' => false,         // OFF by default
'desktop_spacing'  => 300,
'mobile_spacing'   => 250,
'category_every_n' => 3,
```

### Enqueue Guards (`pl_page_ads_enqueue()`)
```
pl_is_ezoic_site() → return
is_single() → return
!$s['enabled'] → return
Per-page-type check:
  is_front_page()/is_home() + homepage_enabled
  is_category() + category_enabled
  is_page() + page_enabled
  else → return
```

Config output: `<script>var plPageAds={...};</script>` at `wp_footer` priority 97.
Script load: `window.addEventListener('load', ...)` creates `<script>` tag at priority 100.

---

## Homepage Ads

### Anchor Placement
Three fixed-position `<div class="pl-page-ad-anchor">` in each homepage template:

| Template | Position 1 | Position 2 | Position 3 |
|----------|-----------|-----------|-----------|
| `front-page.php` | After bento hero section | After smart grid | Before footer |
| `template-emerald-editorial.php` | After hero | After trending | After latest grid |
| `template-coral-breeze.php` | After hero | After trending | After latest stories |
| `template-tec-slate.php` | **NONE** — Ezoic site | | |

Each anchor has: `data-slot="homepage-N"` and `data-format="leaderboard"` or `"rectangle"`.

Fixed format per position:
1. TOP → `leaderboard`
2. MIDDLE → `rectangle`
3. BOTTOM → `leaderboard`

All homepage anchors wrapped in `<?php if ( ! pl_is_ezoic_site() ) : ?>` guards.

### Spacing
- Desktop: 600px (enforced at runtime)
- Mobile: 400px (enforced at runtime)

---

## Category Page Inline Grid Cards

### PHP Interleaving (`category.php`)
Category pages use a custom `pl_get_category_posts()` query, NOT the standard WordPress loop. Ads are interleaved as grid cards at predefined positions:

```php
$pa_positions = [1, 6, 11, 16, 22, 27, 32, 38, 43, 48];
// Spacing pattern: 5, 5, 5, 6, 5, 5, 6, 5, 5 (varied for natural feel)
```

PHP while loop logic:
```
$pa_item_idx = 0;
while (posts remain OR ad positions not exhausted):
    if ($pa_item_idx in $pa_positions):
        Output: <div class="pl-cat-ad-card pl-page-ad-anchor" data-format="rectangle" data-slot="cat-ad-N">
    else:
        Output post card, increment $pa_post_idx
    $pa_item_idx++
```

### Card Styling
`.pl-cat-ad-card` matches `.pl-cat-card` exactly:
- `break-inside: avoid`
- `border-radius: 16px`
- `box-shadow` matching post cards
- `background: #fff`
- `margin-bottom: 18px`
- **No "Advertisement" label**

### JS Rendering for Category Cards (`page-ads.js`)
`renderSlot()` detects `isCatCard` when `PAGE_TYPE === 'category'`:
- Skips spacing check (positions predefined in PHP)
- Container: `width: 100%` instead of forced `max-width`/`margin` wrapper
- Dummy mode fills card naturally (no fixed pixel dimensions or dashed border)

### Format
Category ads use rectangle only — triple-enforced:
1. PHP anchors: `data-format="rectangle"`
2. `page-ads.js`: forces `fmt='rectangle'` for category
3. `getSizesForFormat()`: returns `CAT_SIZES` (300x250 + 336x280)

### Load More (JS)
```js
AD_PATTERN = [5, 5, 5, 6, 5, 5, 6, 5, 5];  // Countdown pattern (matches PHP)
adIdx continues from PHP's $pa_ad_idx
Each post card decrements countdown
At zero → inject .pl-cat-ad-card.pl-page-ad-anchor + __plPageAds.rescan()
Pattern index wraps cyclically
```

### MutationObserver
Watches `.pl-cat-grid, main, #primary, .site-main, .cb-grid-4, .ee-grid-3, .pl-post-grid` for dynamically loaded posts. Auto-triggers `__plPageAds.rescan()` on new children.

---

## Static Page Ads

### Content Filter (`pl_page_ad_static_content()`, priority 50)
```
Guards: !is_page() || is_single() || is_admin() || pl_is_ezoic_site() → skip
Count paragraphs (need ≥3)
Calculate interval: floor(p_count / (max + 1)), min 2
Insert <div class="pl-page-ad-anchor" data-slot="page-N"> after every Nth </p>
Max = page_max setting (default 3)
```

---

## Two-Phase Injection (`page-ads.js`)

### Phase 1 (DOMContentLoaded)
- Scans all `.pl-page-ad-anchor` divs
- Renders above-fold anchors immediately (within viewport + 100px)
- Uses IntersectionObserver for fold detection

### Phase 2 (Scroll)
- Below-fold anchors rendered via IntersectionObserver with `rootMargin: '400px 0px'`
- 400px lookahead: ads start loading before entering viewport

---

## Format System

| Format | Desktop Sizes | Mobile Sizes |
|--------|--------------|-------------|
| `leaderboard` | 970x250, 728x90 | 320x100, 320x50 |
| `rectangle` | 336x280, 300x250 | 300x250 |
| `banner` | 728x90 | 320x50 |
| `CAT_SIZES` (category) | 300x250, 336x280 | 300x250, 336x280 |

Format determined by `data-format` attribute on anchor div. Category always forced to rectangle.

---

## Dummy Mode

When `dummy_mode: true` (default), colored placeholder boxes are rendered instead of GPT ads:

| Type | Color | Visual |
|------|-------|--------|
| Inline ads | Blue gradient | Dashed border + size label |
| Anchor ad | Yellow | Fixed bottom bar |
| Interstitial | Red | Full-screen overlay |

Purpose: Safe testing of ad placement before going live.

---

## Overlay Ads on Pages

### Bottom Anchor Ad
- Fixed bottom bar
- Close button
- Once per session: `sessionStorage: pl_pg_anchor_shown`
- GPT format: `BOTTOM_ANCHOR`
- Guard: `plPageAds.fmtAnchor`

### Interstitial
- Full-screen overlay
- 3-second delay before display
- Auto-close after 10 seconds
- Once per session: `sessionStorage: pl_pg_interstitial_shown`
- Only on homepage + category pages
- Guard: `plPageAds.fmtInterstitial` (OFF by default)

---

## Viewability Tracking

### IntersectionObserver (50% threshold + 1s dwell)
Each rendered slot gets an IO that tracks:
1. When 50% of slot is visible → start 1s timer
2. After 1s continuous visibility → mark as "viewable"
3. `sendEvent('viewable', record)` sent via REST API

### Beacon
On page hide: `navigator.sendBeacon` sends final state to `/pl/v1/page-ad-event`.

---

## Event Reporting (`sendEvent()`)

Each event includes:
```js
{
    event:         'filled' | 'empty' | 'viewable' | 'click',
    pageType:      'homepage' | 'category' | 'page',
    slotId:        'pl-page-ad-homepage-1',
    adFormat:      'leaderboard' | 'rectangle' | 'banner',
    domain:        window.location.hostname,
    device:        'desktop' | 'mobile',
    viewportWidth: window.innerWidth,
    renderedSize:  '970x250',
    url:           window.location.href,
    timestamp:     Date.now()
}
```

Console logging: `[PageAds] Event: type | slot: name | format: fmt | page: type`

Note: `sendEvent('impression')` was **removed** from `renderSlot()` to avoid rate-limit collision with `filled`/`empty` events. The PHP recorder auto-increments impressions when `filled` or `empty` arrive.

---

## Public API

```js
window.__plPageAds = {
    getSlots:  function(),  // Returns all slot records
    getConfig: function(),  // Returns current config
    rescan:    function()   // Scan for new .pl-page-ad-anchor divs and render
};
```

`rescan()` is called by:
- Category load-more JS after appending new posts
- MutationObserver on grid containers

---

## REST Endpoints

| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/pl/v1/page-ad-event` | Record impression/viewable/click/empty/filled |
| GET | `/pl/v1/page-ad-stats` | Admin-only: aggregate stats (today/7d/30d, by_domain, by_format) |

### Storage
`wp_option` key: `pl_page_ad_stats`
Structure: `date > domain > page_type > format > counters`
Counters: `impressions`, `viewable`, `clicks`, `empty`, `filled`
30-day retention (auto-pruned on write).

### Auto-Impression Counting
When `filled` or `empty` events arrive, PHP auto-increments `impressions` counter. This replaces the separate JS impression events that were causing rate-limit collisions.
