# PinLightning Theme — CLAUDE.md

> Complete reference for any Claude Code session to pick up immediately.
> **Branch:** `claude/setup-pinlightning-theme-5lwUG`
> **Last updated:** 2026-03-01

---

## 1. Project Overview

**Multi-site network** of content sites targeting Pinterest traffic. Sites publish listicle-style posts (e.g., "25 Stunning Hairstyles") with curated CDN images, engagement gamification, and monetization via display ads. tecarticles.com is the exception — it uses **Ezoic** for ad monetization (NOT our ad engine).

### Sites
| Site | GA4 ID | Ad Network | Status |
|------|--------|------------|--------|
| cheerlives.com | G-TD7Z2RMZ1C | Ad.Plus (GPT) | Live |
| inspireinlet.com | G-TLFCKLVE30 | Ad.Plus (GPT) | Deploying |
| pulsepathlife.com | G-1ZRM1FTWRB | Ad.Plus (GPT) | Deploying |
| tecarticles.com | TBD | **Ezoic** | Deploying |

### Tech Stack
- **CMS:** WordPress (self-hosted)
- **Theme:** PinLightning (custom-built, zero external dependencies)
- **Hosting:** Hostinger (LiteSpeed server + QUIC/HTTP3)
- **CDN:** Contabo (myquickurl.com) for content images
- **CI/CD:** GitHub Actions — push to `main` or dev branch triggers deploy
- **Ad Network:** Ad.Plus (Google Ad Manager / GPT)
- **Consent:** Google Ad Manager built-in CMP (via GPT)

### Revenue Model
- **Primary:** Ad.Plus display ads (GPT programmatic)
- **Planned:** Email funnel → paid PDF product ("Pinterest Niche Site Blueprint")

### Site Goals
1. Maximize ad viewability and revenue (current: 54%, target: 70-80%)
2. Maintain perfect PageSpeed scores (100/100/100/100)
3. Build email list via engagement-driven captures
4. Scale Pinterest traffic through SEO-optimized listicle content

---

## 2. File Architecture

```
Fast_wordpress_theme/
├── CLAUDE.md               # THIS FILE — comprehensive project reference
├── functions.php           # Theme setup, script/style enqueuing, Pinterest save buttons,
│                           # GA4 Measurement Protocol, engagement-weighted scoring,
│                           # homepage/category REST endpoints
├── header.php              # Sticky header, LCP preload, preconnect, ad CDN preconnects,
│                           # CMP consent tag, .pl-header-stats for mobile counters
├── single.php              # Single post template, engagement UI, sidebar ads, Pinterest JS
├── front-page.php          # Homepage: bento hero, smart grid, load more
├── category.php            # Category archive: sort tabs, masonry grid
├── footer.php              # Footer template
├── style.css               # Theme metadata only (not enqueued)
├── img-resize.php          # Local image resizer (wp-content/uploads → resized on-the-fly)
├── pl-visitor-tracker.php  # Server-side visitor session recording (JSON files)
├── performance-budget.json # PageSpeed thresholds for CI checks
│
├── assets/
│   ├── css/
│   │   ├── critical.css    # Inlined in <head> at wp_head p1 (keep under 3KB, layout-only)
│   │   ├── main.css        # Inlined in <head> at wp_head p2 (~2.7KB gzipped, visual styles)
│   │   ├── engagement.css  # Non-critical, loaded via preload onload trick (deferred)
│   │   ├── homepage-emerald.css # Emerald Editorial homepage (inlined at wp_head p3)
│   │   ├── homepage-coral.css # Coral Breeze homepage (inlined at wp_head p3)
│   │   ├── homepage-tec-slate.css # Tec Slate homepage (inlined at wp_head p3)
│   │   └── dist/           # Build output (gitignored — production falls back to source)
│   ├── js/
│   │   ├── core.js         # Minimal theme script (hamburger menu, etc.)
│   │   ├── initial-ads.js  # Layer 1: GPT setup, initial viewport slots, overlay refresh
│   │   ├── smart-ads.js    # Layer 2: Dynamic predictive injection engine
│   │   ├── page-ads.js     # Page ad system: viewport-aware injection for non-post pages
│   │   ├── engagement.js   # Engagement IIFE: observers, counters, milestones, polls
│   │   ├── scroll-engage.js # Gamified character system + header stats reveal
│   │   ├── infinite-scroll.js # Next-post auto-loader (IO at 70% read, max 3 posts)
│   │   └── dist/           # Build output (gitignored)
│   └── engage/             # Scroll-engage character assets (images, video)
│
├── inc/
│   ├── ad-system.php       # Ad container HTML output, sidebar rendering, inline CSS,
│   │                       # content filter for initial-ad-1/2 + pause-ad-1 injection
│   ├── ad-engine.php       # Server-side ad settings, ads.txt, CMP tag output,
│   │                       # frontend config provider (pl_ad_settings), script enqueuing
│   ├── ad-optimizer.php    # Admin control center, daily cron with 12 optimization rules,
│   │                       # presets (light/normal/aggressive), Layer 2 tuning controls,
│   │                       # format toggles, refresh config, viewability settings
│   ├── ad-data-recorder.php # Session beacon + heartbeat REST endpoints, data storage
│   ├── ad-live-sessions.php # Live sessions admin dashboard, heartbeat processing
│   ├── ad-snapshot.php     # Stable snapshot save/revert system (AJAX handlers + admin UI)
│   ├── performance.php     # Bloat removal, critical CSS inlining, gzip, resource hints,
│   │                       # LCP preload, script defer, cache headers, flush endpoint
│   ├── image-handler.php   # Lazy loading, responsive images, Pinterest attrs,
│   │                       # featured image rewriting, CDN image rewriting,
│   │                       # dominant color extraction, WebP <picture> wrapper
│   ├── engagement-breaks.php # Content injection (polls, quizzes, hooks, facts, email captures)
│   ├── engagement-config.php # Per-category engagement configs
│   ├── engagement-customizer.php # Customizer controls for engagement features
│   ├── email-leads.php     # Email capture REST API, lead scoring, admin dashboard, CSV export
│   ├── rest-random-posts.php # Infinite scroll REST endpoint
│   ├── pinterest-seo.php   # Pinterest SEO optimizations
│   ├── template-tags.php   # Template helper functions
│   ├── customizer.php      # Theme Customizer settings
│   ├── customizer-scroll-engage.php # Scroll-engage Customizer settings
│   ├── ai-chat.php         # AI chat integration
│   ├── visitor-tracker.php # Visitor tracking server-side handler
│   ├── contact-messages.php # Contact form message handling
│   ├── homepage-templates.php # Multi-site homepage routing, emerald CSS inliner,
│   │                          # category circles cache, Customizer template setting
│   ├── page-ad-engine.php  # Page ad settings, admin tab, enqueue, config, archive/page hooks
│   └── page-ad-recorder.php # Page ad REST endpoints (impression/viewable/click tracking)
│
├── template-emerald-editorial.php # Emerald Editorial homepage (inspireinlet.com)
├── template-coral-breeze.php # Coral Breeze homepage (pulsepathlife.com)
│
├── template-tec-slate.php      # Tec Slate homepage (tecarticles.com)
│
├── template-parts/
│   └── content-card.php    # Reusable card component for grids
│
├── .github/workflows/
│   ├── deploy.yml          # All 3 sites deploy (parallel jobs) + PageSpeed
│   └── wp-audit.yml        # WordPress audit checks
│
└── scripts/
    └── perf-check.sh       # Performance budget enforcement
```

### Theme Requires (functions.php loads)
```php
inc/template-tags.php
inc/customizer.php
inc/performance.php
inc/image-handler.php
inc/pinterest-seo.php
inc/rest-random-posts.php
inc/ad-engine.php
inc/ad-data-recorder.php
inc/customizer-scroll-engage.php
inc/visitor-tracker.php
inc/ai-chat.php
inc/email-leads.php
inc/contact-messages.php
inc/engagement-config.php
inc/engagement-breaks.php
inc/engagement-customizer.php
inc/homepage-templates.php
inc/page-ad-engine.php
inc/page-ad-recorder.php
```

---

## 3. Ad System Architecture

### Overview
Two separate ad systems, completely isolated:

**Post Ads** (single posts only — `is_single()`):

| Layer | File | Boot Trigger | Purpose |
|-------|------|-------------|---------|
| Layer 1 | `initial-ads.js` | window.load + 100ms | GPT setup, static slots, overlays, refresh |
| Layer 2 | `smart-ads.js` | First user interaction (scroll/click/touch) | Dynamic below-fold injection |

**Page Ads** (non-post pages — `!is_single()`):

| Layer | File | Boot Trigger | Purpose |
|-------|------|-------------|---------|
| Page Ads | `page-ads.js` | window.load + DOMContentLoaded | Viewport-aware injection for homepages, category, static pages |

### Page Ad System Isolation
| Aspect | Post Ads | Page Ads |
|--------|----------|----------|
| PHP guard | `is_single()` | `!is_single()` |
| JS guard | N/A | bail if `.single-post` or `window.plAds` |
| Global | `window.plAds` | `window.plPageAds` |
| CSS class | `.pl-initial-ad`, `.pl-dynamic-ad` | `.pl-page-ad-slot`, `.pl-page-ad-anchor` |
| GPT ID prefix | `initial-ad-`, `smart-ad-` | `pl-page-ad-` |
| Slot name | `{site}_{format}` | `{site}_pg_{format}` |
| REST endpoint | `/pinlightning/v1/ad-data` | `/pl/v1/page-ad-event` |
| Settings key | `pl_ad_settings` | `pl_page_ad_settings` |
| Admin tab | Global Controls / Ad Codes | Page Ads |

### Page Ads — Architecture (`page-ads.js`)

**Two-phase injection:**
- **Phase 1** (DOMContentLoaded): Above-fold `.pl-page-ad-anchor` divs rendered immediately
- **Phase 2** (scroll): Below-fold anchors rendered via IntersectionObserver (400px rootMargin lookahead)

**Format system:**
| Format | Desktop Sizes | Mobile Sizes |
|--------|--------------|-------------|
| leaderboard | 970x250, 728x90 | 320x100, 320x50 |
| rectangle | 336x280, 300x250 | 300x250 |
| banner | 728x90 | 320x50 |

**Anchor placement:**
- Homepage templates: 3 anchors with `data-format` attrs (leaderboard→rectangle→leaderboard)
- Category archives: rectangle ads as **grid cards** — ads occupy one cell like a post card (`.pl-cat-ad-card` matches `.pl-cat-card` exactly: border-radius, shadow, break-inside). Predefined positions `[1, 6, 11, 16, 22, 27, 32, 38, 43, 48]` with varied spacing (5,5,5,6,5,5,6,5,5). PHP while loop interleaves posts and ads at predetermined indices. No "Advertisement" label. Injected directly in category.php (NOT via `the_post`/`loop_start` hooks — uses `pl_get_category_posts()` custom query). page-ads.js renders inside card without forced sizing (no max-width/margin wrapper). Load-more JS uses `AD_PATTERN=[5,5,5,6,5,5,6,5,5]` countdown pattern + `__plPageAds.rescan()`.
- Static pages: auto-injected via `the_content` filter, evenly spaced between paragraphs

**Spacing:** 300px desktop, 250px mobile for category; 600px desktop, 400px mobile for homepage/static (enforced at runtime)

**Overlay ads:**
- **Anchor ad:** Fixed bottom bar, close button, once per session (`sessionStorage: pl_pg_anchor_shown`), GPT BOTTOM_ANCHOR format
- **Interstitial:** Full-screen overlay after 3s delay, auto-close 10s, once per session (`sessionStorage: pl_pg_interstitial_shown`), homepage+category only

**Dummy mode:** Colored placeholder boxes (blue gradient inline, yellow anchor, red interstitial) with dashed border + size label. Enabled by default for safe testing.

**Category MutationObserver:** Watches `.pl-cat-grid, main, #primary, .site-main, .cb-grid-4, .ee-grid-3, .pl-post-grid` for dynamically loaded posts, auto-injects ads into new content.

**Settings:** Separate `pl_page_ad_settings` option with per-page-type toggles, slot limits, format toggles, spacing config.

**Viewability:** IntersectionObserver with 50% threshold + 1s dwell → "viewable" event. Beacon on page hide via `navigator.sendBeacon`.

**Event reporting:** `sendEvent()` includes: event, pageType, slotId, adFormat, domain, device, viewportWidth, renderedSize, url, timestamp. Console logging: `[PageAds] Event: type | slot: name | format: fmt | page: type`.

**Analytics:** `/pl/v1/page-ad-event` REST endpoint → `wp_option` storage keyed by date/domain/page-type/format. 30-day retention. Stats endpoint returns structured breakdowns (today/7d/30d, by_domain, by_format) with fill_rate calculation. Stats dashboard in admin "Page Ads" tab.

**Public API:** `window.__plPageAds = { getSlots, getConfig, rescan }`

### Layer 1 — Initial Viewport Ads (`initial-ads.js`)

**Static In-Content Slots** (injected by `ad-system.php` content filter at priority 30):
| Slot ID | Position | Sizes |
|---------|----------|-------|
| `initial-ad-1` | After paragraph 1 | 336x280, 300x250, 300x100 |
| `initial-ad-2` | After paragraph 3 | 336x280, 300x250, 300x600, 300x100 |
| `pause-ad-1` | After paragraph 6 | 300x250 (GPT contentPause) |

**Sidebar Slots** (desktop only, ≥1025px):
| Slot ID | Sizes | Notes |
|---------|-------|-------|
| `300x600-1` | 300x600, 300x250, 200x200, 160x600 | Primary sidebar, tall formats |
| `300x250-sidebar` | 300x250, 200x200 | Secondary sidebar, medium formats |

**Overlay Slots** (all devices):
| Slot | Format | Refresh |
|------|--------|---------|
| Interstitial | `defineOutOfPageSlot` | No refresh (maxRefresh=0) |
| Bottom Anchor | `BOTTOM_ANCHOR` | setInterval (30s default, Google minimum) |
| Left Side Rail | `LEFT_SIDE_RAIL` | setInterval (desktop only) |
| Right Side Rail | `RIGHT_SIDE_RAIL` | setInterval (desktop only) |

**GPT Configuration:**
- `enableSingleRequest()` — one HTTP request for all initial slots (SRA)
- `collapseEmptyDivs()` — shrink unfilled containers
- Display IDs: mobile gets 3 (`initial-ad-1`, `initial-ad-2`, `pause-ad-1`), desktop gets 5 (+ sidebar)
- `SLOT_PATH = '/21849154601,22953639975/'`
- `IS_DESKTOP = window.innerWidth >= 1025`

**Shared State:**
- `window.__plViewableCount` — shared viewable counter incremented by BOTH layers
- `window.__plOverlayStatus` — overlay states: off → pending → empty/filled → viewable
- `window.__initialAds` API: `.ready`, `.onReady()`, `.getExclusionZones()`, `.refreshSlot()`, `.getSlotMap()`
- `window.SmartAds` API: `.rescanAnchors()` — notify Layer 2 of new content (destroys above-viewport slots, resets house ad counter, clears view timestamps)
- `window.__plNextPostLoader` API: `.getLoadedIds()`, `.getContainer()` — next-post loader state

### Layer 2 — Dynamic Predictive Injection (`smart-ads.js`)

**Content Discovery:**
- `findTargetNear()` uses `querySelectorAll('.single-content')` to find paragraphs across ALL content sections (original + auto-loaded posts)
- REST endpoint already wraps auto-loaded content in `<div class="infinite-post-content single-content">` (rest-random-posts.php L98)
- `checkVideoInjection()` still uses `querySelector('.single-content')` (first/original article only — one video per page)

**Visitor Classification:**
| Type | Speed | Time Between Ads |
|------|-------|-----------------|
| Reader | <100 px/s | 2.5s |
| Scanner | 100-400 px/s | 3.0s |
| Fast-scanner | >400 px/s | 5.0s |

**Injection Strategies (priority order):**
1. **Pause** (speed <100px/s for 120ms) — inject at viewport center. Highest viewability.
2. **Slow Scroll** (100-120px/s, sustained 4/6 samples) — inject ahead of scroll.
3. **Predictive** (decelerating + <300px/s) — inject at predicted stop point.
4. **Viewport Refresh** (runs independently every tick) — refresh in-view ads after 3s continuous visibility + speed <20px/s.

**Rapid Deceleration Detection:**
- If speed drops from >150px/s to <100px/s within 300ms → treat as immediate pause
- Catches the moment just before a full stop for earlier injection

**Spacing Rules:**
- `MIN_PIXEL_SPACING = 500px` (enforced at PHP config, JS init with `Math.max(500, ...)`, and runtime guard)
- Fast-scanners get 600px floor (their 21% viewability at 400-600px was too low)
- Formula: `speed × timeBetween`, clamped [500, 1000] px
- Scroll-up modifier: `spacing × 1.3` (users scan back too fast for viewability)
- Absolute guard: if actual placement <500px from nearest ad, abort injection

**Viewport Density Limits:**
- Mobile: max 1 ad visible
- Desktop: max 2 ads visible
- Both: max 30% of viewport height occupied by ads

**Max Slots:** 20 active dynamic slots per page, recycled 1000px+ above viewport

**Lazy-Render System:**
- `renderSlot()` — extracted GPT defineSlot+display logic
- `observeForLazyRender()` — IntersectionObserver with `rootMargin: '400px 0px'`
- Ads start loading 400px before entering viewport
- Fast scrollers (>200px/s): 500ms setTimeout instead of IO, skip if user scrolled away
- `_totalSkips` counter tracks abandoned fast-scroll injections

**Tab Visibility Pause:**
- `_tabVisible` flag gates engineLoop, lazy-render IO callbacks, fast-scroller timeouts
- When `document.hidden`, all ad operations stop (prevents background waste)
- On tab return: `_slotViewStart` timestamps reset (stale from hidden period)
- Does NOT refresh existing ads on tab return — lets natural refresh re-detect

**Viewport-Aware Size Selection (`getDynamicAdSizes()`):**
| Viewport | Sizes | Rationale |
|----------|-------|-----------|
| Mobile (<768px) | [[300, 250]] only | Single auction, ~200ms faster fill |
| Tablet (768-1024) | [[300, 250], [336, 280]] | Modest multi-size |
| Desktop (≥1025) | [[336, 280], [300, 250], [300, 600]] | Full multi-size for higher RPM |

**Sticky Inline (`applyStickyBehavior()`):**
- For fast-scrolling users (injection speed >100px/s, scrolling down)
- `position:sticky` for 1.5s when ad is 50% visible
- Only one sticky ad at a time via `_activeStickyAd`
- Ensures 1s viewability threshold is met

**Last-Chance Refresh (`observeForLastChanceRefresh()`):**
- IntersectionObserver watches filled ads for viewport exit
- When ad exits viewport TOP (scrolled past going down), refreshes it
- New creative ready if user scrolls back up
- Guards: `viewableEver` must be true, `refreshCount < MAX_REFRESH_DYN`, 30s cooldown, tab visible
- Cleaned up in `destroySlot()`

**Retry Logic:**
- On first isEmpty: wait 3s, check proximity, retry with `[[300, 250]]` only
- Max 1 retry per slot (`rec.retried` flag)
- `_totalRetries` counter tracked in beacon/heartbeat

**House Ad Backfill (`showHouseAd()`):**
- When retry also fails, renders email capture CTA (Pinterest guide promo)
- Max 2 per page (`_houseAdsShown` counter)
- Not counted in viewability (`rec.filled = false`)
- Tracks `house_ad_shown` and `house_ad_click` events
- Links to `/free-pinterest-guide` (landing page, not yet built)

**GPT Patient Timeout (10s):**
- 10s timer starts after `renderSlot()` is called
- If `rec.filled` is still false after 10s, collapses the container (height:0, margin:0)
- Does NOT show house ad — real empties handled by `slotRenderEnded` (house ad on isEmpty:true)
- 10s catches truly stuck GPT requests (programmatic ads commonly take 2-5s through RTB waterfall)
- `rec._fillTimeout` cleared in `slotRenderEnded` when GPT responds

**renderSlot() Explicit Refresh:**
- After `googletag.display(record.divId)`, calls `googletag.pubads().refresh([slot])`
- Required because `enableSingleRequest()` (SRA) mode is ON — post-initial slots need explicit refresh
- Retry logic already had this pattern, but initial render was missing it

**Ad Relocation (`relocateFilledAd()`) — DISABLED:**
- **DISABLED** (Feb 27, 2026): 82 relocated ads had 0% viewability. Relocation code remains but is never called.
- Originally: When GPT responds with a fill after user has scrolled 200+ px past the ad, relocated it
- Immediate check and 2s delayed recheck both removed from `onDynamicSlotRenderEnded`
- Moves the existing DOM element (keeps GPT iframe alive) to a paragraph near viewport center
- Guards: only once per ad (`rec.relocated`), respects `MIN_PIXEL_SPACING` + Layer 1 exclusion zones
- Does NOT relocate if user is scrolling toward the ad (it'll come into view naturally)
- Max distance: 800px from viewport center to best target paragraph
- Tracked: `rec.relocated`, `rec.relocatedFromY`, `rec.relocatedToY`, `rec.relocatedAt`
- Resets `rec.viewable` and `_slotViewStart` (fresh viewability measurement at new position)

**GPT Response Time Tracking:**
- `rec.renderRequestedAt` — timestamp set when `renderSlot()` is called
- `rec.gptResponseMs` — calculated in `slotRenderEnded` as `Date.now() - rec.renderRequestedAt`
- Sent in beacon/heartbeat zones arrays and `dynamic_ad_filled` events
- Stored in `pl_ad_events` table (`gpt_response_ms` column, v5 migration)
- Injection Lab: response time bracket analysis (0-1s, 1-2s, 2-3s, 3-5s, 5-8s, 8-10s, 10s+)

**Exit-Intent Interstitial (`tryExitInterstitial()`):**
- Fires a **new** GPT interstitial slot on user exit (tab switch, mouse-leave, beforeunload)
- `initExitIntent()` called at script load time (NOT inside `init()` — catches exits before engagement gate)
- 3 triggers: `visibilitychange` (tab switch), `mouseleave` (clientY<10), `beforeunload`
- Guards: `exit_interstitial` admin toggle, 15s minimum session, fires once per page
- **Checks `__plOverlayStatus.interstitial` before acting:**
  - If `'pending'`: Layer 1 interstitial not yet shown by GPT. Don't destroy — `refresh()` it to nudge GPT into showing now. Layer 1's existing handlers track the result.
  - If `'filled'`/`'viewable'`/`'empty'`: Already showed/failed. Safe to destroy and create a new exit-intent slot.
- Cannot refresh a SHOWN interstitial — GPT interstitials are one-shot (`maxRefresh: 0`), but refreshing a pending one nudges GPT to attempt display
- Full analytics: `_exitRecord` tracks fill/viewable/size via targeted `slotRenderEnded` + `impressionViewable` listeners
- `_exitRecord` NOT pushed to `_dynamicSlots` (no DOM element — would crash engine loop); instead appended to zones in beacon/heartbeat
- Shows as zone row with `injectionType: 'exit_intent'` in Live Sessions Ad Detail (highlighted yellow)
- Totals NOT double-counted (interstitial already in Layer 1 slotMap counts)

**Viewability Tracking:**
- `viewableEver` flag: set once on `impressionViewable`, never cleared (unlike `viewable` which resets on refresh)
- Session reporter uses `viewableEver` for accurate aggregate counts
- `window.__plViewableCount` shared counter incremented by both layers

**Key State Variables:**
```js
var _dynamicSlots   = [];    // all dynamic ad records
var _slotCounter    = 0;     // divId numbering
var _totalSkips     = 0;     // fast-scroll abandoned ads
var _totalRetries       = 0;     // empty slot retries
var _consecutiveEmpties = 0;     // consecutive empties — stop at 3 (demand exhausted)
var _houseAdsShown      = 0;     // house ad backfills (max 2)
var _activeStickyAd = null;  // current sticky ad container
var _tabVisible     = true;  // gates engine when tab hidden
var _engineInterval = null;  // stored interval ID
var _slotViewStart  = {};    // per-slot viewport visibility timestamps
var _isPaused       = false; // velocity < 100px/s
var _isDecelerating = false; // speed dropped >50% from peak
var _isRapidDecel   = false; // >150 to <100 in 300ms
var _exitFired      = false; // exit interstitial fired (once per page)
var _exitTrigger    = '';    // which trigger fired it
```

**Dynamic Ad Record Fields:**
```js
{
    divId, slot, el, adDiv, anchorEl, injectedAt, injectedY,
    injectionType, injectionSpeed, scrollDirection, nearImage,
    adsInViewport, adDensityPercent, adSpacing, predictedDistance,
    viewable: false, viewableEver: false, refreshCount: 0,
    lastRefresh: 0, renderedSize: null, filled: false,
    destroyed: false, rendered: false, retried: false,
    renderRequestedAt: 0, gptResponseMs: 0,
    relocated: false, relocatedFromY: 0, relocatedToY: 0, relocatedAt: 0
}
```

### Size Hints CSS (`ad-system.php`)
```css
.pl-initial-ad { min-height:250px; display:flex; align-items:center; justify-content:center }
.pl-dynamic-ad { min-height:250px; display:flex; align-items:center; justify-content:center }
.pl-sidebar-ads { display:none }
@media(min-width:1025px) {
    .pl-sidebar-ads { display:block; position:sticky; top:80px; max-height:90vh }
}
```

### Ad CDN Preconnects (`header.php`)
```
securepubads.g.doubleclick.net (preconnect + dns-prefetch)
pagead2.googlesyndication.com  (preconnect + dns-prefetch)
cdn.ad.plus                    (preconnect)
tpc.googlesyndication.com      (preconnect)
```
Gated behind `pl_ad_settings()['enabled']` check.

---

## 4. CMP / Consent

- **Provider:** Google Ad Manager built-in CMP (delivered via GPT)
- **TCF Version:** v2.2 compliant
- **How it works:** GPT (`securepubads.g.doubleclick.net/tag/js/gpt.js`) includes Google's own CMP. No separate consent script needed.
- **Consent Audience:** EEA visitors see consent popup automatically; US/Asia/other do not.
- **Previous provider:** InMobi CMP (removed Feb 2026) — `pinlightning_output_cmp_tag()`, `choice.js`, `.qc-cmp2-container` CSS all deleted.

---

## 5. Deployment Pipeline

### Multi-Site Deploy (4 parallel jobs in `deploy.yml`)
One push triggers a single workflow with 4 parallel jobs (no `needs:` dependency):

| Job | Site | Ad Network | Secrets Suffix |
|-----|------|------------|----------------|
| `deploy` | cheerlives.com | Ad.Plus | `_CHEERLIVES` |
| `deploy-inspireinlet` | inspireinlet.com | Ad.Plus | `_INSPIREINLET` |
| `deploy-pulsepathlife` | pulsepathlife.com | Ad.Plus | `_PULSEPATHLIFE` |
| `deploy-tecarticles` | tecarticles.com | **Ezoic** | `_TECARTICLES` |

- **Triggers:** push to `main` or `claude/setup-pinlightning-theme-5lwUG`
- **Build:** `npm install` → `npm run build` (lightningcss + terser)
- **Deploy method:** rsync over SSH (sshpass) to Hostinger
- **Post-deploy:**
  - Flush cache via `POST /wp-json/pinlightning/v1/flush-cache` with `X-Cache-Secret`
  - PageSpeed Insights API (mobile + desktop)
  - Screenshots saved as artifacts (30-day retention)
  - Active plugin lists captured via WP REST API
  - Performance budget check via `scripts/perf-check.sh` (cheerlives only)
- **Excluded from deploy:** `.git/`, `node_modules/`, `.env`, `package.json`, `.github/`, `*.md`, `.gitignore`

### Build System (`package.json`)
```
build:css → lightningcss --minify --bundle → assets/css/dist/critical.css + main.css
build:js  → terser → assets/js/dist/core.js
dev       → chokidar file watcher
```
- `assets/css/dist/` is gitignored — production falls back to source `assets/css/critical.css`
- `assets/js/dist/` is gitignored — production falls back to source `assets/js/core.js`

### Commit Convention
```
feat:  — new feature
fix:   — bug fix
perf:  — performance optimization
docs:  — documentation only
```

### Testing Checklist After Deploy
1. **PageSpeed Insights** — must maintain 100/100/100/100
2. **Browser console** — check for GPT warnings, JS errors
3. **Incognito test** — verify ads load, CMP behavior correct
4. **Injection lab** — monitor viewability, fill rate, spacing
5. **Live sessions dashboard** — check session-level metrics

---

## 6. Analytics / Monitoring

### Custom Analytics Tools
- **Injection Lab:** Tracks all dynamic injections with type, speed, spacing, viewability, fill, visitor type, direction, near-image flag
- **Live Sessions Dashboard:** Real-time session data — device, scroll %, pattern, filled, injected, viewable, pause, refresh counts
- **Event Tracker:** Per-event stats via `__plAdTracker` — impressions, empties, fill rate, viewability, refreshes, skips

### Key Metrics to Monitor
| Metric | Baseline | Target | How to Check |
|--------|----------|--------|-------------|
| Viewability % | 54% | 70-80% | Session reporter + injection lab |
| Fill rate | ~47-53% | 60%+ | Beacon payload `fillRate` |
| Spacing distribution | 0 below 500px | Keep at 0 | Injection lab histogram |
| Viewport refreshes | >0 | Higher is better | Session stats |
| Skips (fast-scroll) | Low | Minimize waste | Beacon `totalSkips` |
| Retries | Per session | Track fill recovery | Beacon `totalRetries` |
| Last-chance refreshes | New metric | Monitor count | Event logs |
| Creative timeouts | New metric | Monitor frequency | Event logs |
| House ad impressions | Per page max 2 | Track clicks | Event logs |

### Beacon + Heartbeat
- **Beacon:** Sent on tab hide + page hide via `navigator.sendBeacon`
- **Heartbeat:** Every 5s via `fetch()` to `/wp-json/` endpoint
- Both include: session data, slot details, viewability, fill, refresh, skip, retry counts

---

## 7. CSS Architecture

### Loading Strategy
All CSS is **inlined in `<head>`** — zero external stylesheet requests.

| File | Hook | Priority | Method |
|------|------|----------|--------|
| `critical.css` | `wp_head` | 1 | `file_get_contents` → inline `<style>` |
| `main.css` | `wp_head` | 2 | `file_get_contents` → inline `<style>` |
| `engagement.css` | `wp_head` | 99 | `<link rel="preload" onload>` (deferred) |
| `homepage-emerald.css` | `wp_head` | 3 | `file_get_contents` → inline `<style>` (emerald homepage only) |
| `homepage-coral.css` | `wp_head` | 3 | `file_get_contents` → inline `<style>` (coral homepage only) |
| `homepage-tec-slate.css` | `wp_head` | 3 | `file_get_contents` → inline `<style>` (tec-slate homepage only) |

### Critical CSS Rules
- **Must stay under 3KB** — layout-only properties
- Includes: reset, header, card grid, single post layout, engagement UI positioning, bento grid
- **NO visual properties** in critical.css (colors, backgrounds, border-radius, shadows) — they cause CLS

### CLS Prevention
- `aspect-ratio` set on `<img>` via inline style (NOT on container)
- Width/height attributes on all images
- Fixed elements start hidden (`opacity:0; pointer-events:none`)
- Ad containers have `min-height:250px` to prevent shifts

---

## 8. PHP Architecture — the_content Filter Chain

| Priority | Filter | File | Purpose |
|----------|--------|------|---------|
| 10 | `pinlightning_webp_picture_wrap` | `image-handler.php` | WebP `<picture>` wrapper |
| 20 | `pinlightning_pinterest_content_images` | `image-handler.php` | Pinterest data attrs |
| 25 | `pinlightning_rewrite_cdn_images` | `image-handler.php` | CDN image rewriting with srcset |
| 30 | `pl_inject_initial_ads` | `ad-system.php` | Initial ad container injection |
| 90 | `pl_add_pinterest_save_buttons` | `functions.php` | Pinterest Save button overlay |
| 95 | `pinlightning_inject_engagement_breaks` | `engagement-breaks.php` | Engagement elements |

---

## 9. Image Handling

### Two Image Pipelines

**1. Local uploads → `img-resize.php`**
- Source: `/wp-content/uploads/` images
- URL: `PINLIGHTNING_URI/img-resize.php?src={uploads_path}&w={width}&q={quality}`
- Srcset widths: 240w (q50), 360w (q55), 480w (q60), 720w (q65)
- Only hero sizes (large, full, post-hero)

**2. CDN images → myquickurl.com resizer**
- Source: `myquickurl.com` URLs in post content
- URL: `https://myquickurl.com/img.php?src={path}&w={width}&q={quality}`
- All CDN originals: 1080x1920 (9:16), displayed at 720x1280
- Has re-entry guard (`static $running`)

### LCP Preloading
- Called from `header.php` BEFORE `<meta charset>` (byte 0 of `<head>`)
- `fetchpriority="high"` + `imagesrcset` + `imagesizes`
- First image: `loading="eager"`, all others: `loading="lazy"`

---

## 10. Engagement System

Engagement UI on listicle posts only (posts with `<h2>` containing `#N` patterns).

| Feature | Element | Behavior |
|---------|---------|----------|
| Progress bar | `#ebProgressFill` | Tracks % items seen |
| Item counter | `#ebCounter` | "N / M ideas seen" |
| Live count | `.eb-counter-live` | Simulated reader count (30-70) |
| Jump pills | `#ebPills` | Fixed pill nav (desktop only) |
| Milestones | `#ebMilestone` | Toast at 25%, 50%, 75%, 100% |
| Collect counter | `#ebCollectCounter` | Collectible emoji tracking |
| Exit intent | `#ebExit` | Email capture on mouse-leave |
| Image tap tracker | `window._imageTaps` | Click/tap tracking on post images |

### Image Tap Tracker (`engagement.js`)
- `initImageTapTracker()` — delegated click listener on `.single-content img` + `.infinite-post-content img`
- Stores: `{ src, alt, heading, ts }` per tap in `window._imageTaps` array
- Heading context: finds nearest `<h2>`/`<h3>` in `.eb-item`, `article`, or `.infinite-post-wrapper`
- Sent in beacon as `imageTaps` (full array) and heartbeat as `imageTapCount`
- Stored in ad-data-recorder.php session JSON and displayed in Live Sessions detail

### Split IntersectionObserver (CRITICAL)
- **seenIO** (threshold 0.15): counting/tracking, one-shot per item
- **animIO** (threshold 0.8): animation gating, toggles `.in-view`
- Must stay separate — merging breaks tracking accuracy

---

## 11. REST API Endpoints

| Method | Endpoint | File | Purpose |
|--------|----------|------|---------|
| GET | `/pinlightning/v1/random-posts` | `rest-random-posts.php` | Next-post auto-load (returns full article HTML with .single-content wrapper) |
| POST | `/pinlightning/v1/flush-cache` | `performance.php` | Cache flush (X-Cache-Secret) |
| POST | `/pl/v1/subscribe` | `email-leads.php` | Email capture + lead scoring |
| GET | `/pl/v1/unsubscribe` | `email-leads.php` | Email unsubscribe |
| GET | `/pl/v1/home-posts` | `functions.php` | Homepage load more |
| GET | `/pl/v1/category-posts` | `functions.php` | Category page load more |
| POST | `/pl/v1/page-ad-event` | `page-ad-recorder.php` | Page ad impression/viewable/click tracking |
| GET | `/pl/v1/page-ad-stats` | `page-ad-recorder.php` | Page ad aggregate stats (admin-only) |

---

## 12. Performance Decisions & History

### Removed/Disabled (DO NOT re-add)
- **HTML minification** — blocks streaming, inflated TTFB (SI 1.8s→1.3s when removed)
- **103 Early Hints** — causes ERR_QUIC_PROTOCOL_ERROR on LiteSpeed/QUIC
- **jQuery** — fully deregistered on frontend
- **wp-embed, Block library CSS, Dashicons (non-logged-in), Emoji, Global styles** — all removed
- **Top Anchor ad** — "Format already created" conflict, zero demand in 27 sessions
- **Read Next bar** (`.eb-next-bar`) — replaced by next-post auto-loader. Don't re-add.
- **Scroll-up ad injection** — 8% viewability vs 28% scroll-down. Disabled in engineLoop(). Don't re-enable.
- **Ad relocation** — 0% viewability on 82 relocated ads. Disabled in onDynamicSlotRenderEnded. Don't re-enable.
- **250x250 ad format** — $0.03 total revenue with poor viewability. Removed from all GPT size arrays. Don't re-add.

### Active Optimizations
- All CSS inlined in `<head>` (zero external CSS requests)
- All scripts deferred
- `?ver=` query strings stripped
- Gzip via `ob_gzhandler` (template_redirect at priority -1)
- Cache: 1hr HTML (stale-while-revalidate=86400), 1yr immutable static
- CDN preconnect at byte 0 of `<head>`
- LCP preload with `fetchpriority="high"` + srcset
- Scroll-deferred: `scroll-engage.js`, `comment-reply.min.js`, tooltipster CSS
- `content-visibility: auto` on `.eb-break` and `.eb-curiosity` (NOT `.eb-item`)
- Ad CDN preconnects (GPT, syndication, Ad.Plus, TPC)

### PageSpeed Target
- **Goal:** 100/100/100/100 (Performance, Accessibility, Best Practices, SEO)
- **Key metrics:** FCP < 1.0s, LCP < 1.5s, SI < 1.5s, TBT = 0ms, CLS = 0
- Lighthouse never scrolls → scroll-deferred assets have zero impact

---

## 13. Recent Changes Log

### Perf: Improve Fill Rate + Viewability — 6 Fixes (Mar 1, 2026)
- **FIX 1: Consecutive empty abort (fill rate)** — Added `_consecutiveEmpties` counter in `onDynamicSlotRenderEnded`. Increments on empty (after retry), resets to 0 on fill. `engineLoop()` guard: `if (_consecutiveEmpties >= 3) return;` — stops injecting when GPT demand is exhausted. Prevents the "22 injected, 1 filled, 21 wasted" pattern. Counter reset in `rescanAnchors()` for new auto-loaded content. Tracked in beacon/heartbeat as `consecutiveEmpties`.
- **FIX 2: Reduce fast-scanner injection rate (viewability)** — Fast-scanners had 5.3 ads/session but only 21% viewability. `FAST_SCANNER_TIME` raised from 3.5s to 5.0s (time between injections). Added 600px spacing floor for fast-scanners in `getSpacing()` (vs 500px for others). At >400px/s with 5.0s gap, natural spacing is 2000px (clamped to MAX 1000px).
- **FIX 3: Widen minimum spacing from 400px to 500px (viewability)** — Data showed 400-600px spacing bracket had only 22% viewability vs 30% at 600-800px. `MIN_PIXEL_SPACING` raised from 400 to 500 in smart-ads.js init, PHP default (`ad-optimizer.php`), PHP sanitization clamp, admin UI min attribute, and normal/aggressive presets. Spacing clamp formula now [500, 1000].
- **FIX 4: Reduce predictive window by 20% (viewability)** — Predictive strategy avg TTV was 6022ms — ads placed too far ahead of where user stops. `PREDICTIVE_WINDOW` default reduced from 1.0 to 0.8. PHP default in `ad-optimizer.php` also updated. Ads now land ~20% closer to predicted stop point.
- **FIX 5: Delay initial-ad-1 on mobile (viewability)** — Layer 1 initial-ad-1 (after paragraph 1) scrolled past before GPT fills (~1s). On mobile (<768px), `googletag.display('initial-ad-1')` delayed by 2s via setTimeout. Other slots (initial-ad-2, pause-ad-1) display immediately. After delay, explicit `refresh()` ensures SRA-fetched creative renders. Desktop/tablet unchanged.
- **FIX 6: Sidebar viewability check (viewability)** — Sidebar ads (300x600-1, 300x250-sidebar) may render below visible sidebar area. In `onSlotRenderEnded`, 5s setTimeout checks if sidebar ad is in viewport (<10px visible). If not visible, collapses container (height:0, overflow:hidden). Tracked as `sidebar_collapsed_invisible` event.

### Fix: Viewable Tracking Fields + Remove 250x250 Format (Feb 28, 2026)
- **Fix viewable tracking breakdown fields** — Injection lab by_spacing, by_density, and by_response_time breakdowns showed 0 viewable despite 210 viewable in overview. Root cause: `track('viewable', ...)` call in `onDynamicImpressionViewable` (smart-ads.js) was missing `adSpacing`, `nearImage`, `adsInViewport`, `adDensityPercent`, `gptResponseMs` fields. SQL queries filter on these columns (e.g. `WHERE ad_spacing > 0`), so viewable events with 0 values were excluded from breakdowns. Fix: added all 5 fields from the ad record, matching the `track('impression', ...)` call pattern.
- **Remove 250x250 ad format** — 250x250 earned $0.03 total with 12-60% viewability. Removed from ALL GPT size arrays in both initial-ads.js and smart-ads.js: contentSizeMap1/2 (all breakpoints), slot1/slot2 defineSlot, sidebarSizeMap1/2, sb1/sb2 defineSlot, getDynamicAdSizes desktop sizes/sizeMapping. Secondary sidebar slot name updated from `Ad.Plus-250x250` to `Ad.Plus-300x250`. Small viewport fallback changed from `[[250, 250]]` to `[[300, 250]]`.
- **Interstitial investigation** — Verified the scroll-direction guard (`if (_scrollDirection === 'up') return;`) in `engineLoop()` does NOT block interstitials. `checkViewportRefresh()` runs before the guard. Layer 1 interstitial (initial-ads.js `defineOutOfPageSlot`) and exit-intent interstitial (smart-ads.js event listeners) are completely outside `engineLoop()`.

### Perf: Disable Relocation + Scroll-Up Injection, Cap TTV (Feb 27, 2026)
- **Disable ad relocation** — 82 relocated ads had 0% viewability. Removed immediate relocation check and 2s delayed recheck from `onDynamicSlotRenderEnded`. `relocateFilledAd()` function remains but is never called. Ads stay where originally injected.
- **Disable scroll-up injection** — Only 8% viewability vs 28% for scroll-down. Added `if (_scrollDirection === 'up') return;` guard in `engineLoop()` after dynamic ads enabled check. Viewport refresh and video injection still run on scroll-up (only new ad injection is blocked).
- **Cap TTV tracking at 30s** — Predictive strategy showed avg TTV of 110,665ms due to outlier sessions. In `onDynamicImpressionViewable`, `timeToViewable` is now capped at 30000ms. Values above 30s are not meaningful viewability signals and were skewing averages.

### Fix: Hide All Ad HTML on Ezoic Sites (Feb 27, 2026)
- **Root cause:** JS ad scripts were blocked by `pl_is_ezoic_site()` guards, but hardcoded ad anchor `<div>` elements in PHP templates still rendered as empty containers on tecarticles.com. These included `.pl-page-ad-anchor` divs in homepage templates and `.pl-cat-ad-card` divs in the category grid.
- **Fix (category.php):** Wrapped `.pl-cat-ad-card` CSS rule in `<?php if ( ! pl_is_ezoic_site() ) : ?>` guard. Added `! pl_is_ezoic_site()` to the loop condition that outputs ad card divs. Set `adsEnabled=false` in Load More JS when Ezoic site (prevents JS ad card injection on load-more).
- **Fix (front-page.php):** Wrapped all 3 `pl-page-ad-anchor` divs (homepage-1, homepage-2, homepage-3) in `<?php if ( ! pl_is_ezoic_site() ) : ?>` guards.
- **Fix (template-emerald-editorial.php):** Wrapped all 3 `pl-page-ad-anchor` divs in Ezoic guards.
- **Fix (template-coral-breeze.php):** Wrapped all 3 `pl-page-ad-anchor` divs in Ezoic guards.
- **Already guarded (no changes needed):** `template-tec-slate.php` (no ad anchors), `single.php` (`pl_render_sidebar_ads()` already checks `pl_is_ezoic_site()`), `footer.php` (no ad output), `inc/page-ad-engine.php` (category/static content filters already early-return for Ezoic).

### Add tecarticles.com as 4th Site — Ezoic Ad Monetization (Feb 27, 2026)
- **feat: add tecarticles.com with Ezoic-compatible PinLightning deployment** — tecarticles.com is a tech/general content site using Ezoic (NOT our ad engine) for ad monetization. ZERO ad-related JS/CSS loads on this domain.
- **`pl_is_ezoic_site()` helper** — Central domain check in `functions.php`. Returns `true` for `tecarticles.com` / `www.tecarticles.com`. All ad code paths check this before executing.
- **Ad engine guards** — Added `pl_is_ezoic_site()` early-return to: `pinlightning_postload_scripts()` (skips initial-ads.js + smart-ads.js), `pinlightning_ads_enqueue()` (skips plAds config), `pl_ad_admin_menu()` (hides Ad Engine admin menu), `pl_page_ads_enqueue()` (skips page-ads.js), `pl_page_ad_category_anchors()`, `pl_page_ad_static_content()`, `pl_inject_initial_ads()` (skips content filter), `pl_render_sidebar_ads()`. Header.php: ad CDN preconnects gated behind `!pl_is_ezoic_site()`.
- **Tec Slate homepage** — `template-tec-slate.php` + `assets/css/homepage-tec-slate.css`. Slate Modern design: dark slate (#2d3748) + electric blue (#3b82f6) + white. System font stack (NO external fonts — maximum speed). Layout: sticky header, hero+trending sidebar, category pills, 3-column latest grid, load more, newsletter, shared footer with slate/blue overrides. Per-category diversity (top 20 cats, round-robin, max 2 per cat, `$shown_ids` chain). CSS prefix: `ts-`.
- **Homepage routing** — `inc/homepage-templates.php`: `tecarticles.com` → `tec-slate`. Customizer choice added. `pl_tec_slate_critical_css()` inliner at wp_head p3. `template-tec-slate.php` mapped in `template_include`.
- **Deploy pipeline** — `deploy-tecarticles` job added to `deploy.yml` (parallel, no `needs:`). Uses `_TECARTICLES` secret suffix. Separate host/port secrets (may be on different server). No PageSpeed test yet (add after site is live with content).
- **Ezoic compatibility** — Template calls `wp_head()` and `wp_footer()` (Ezoic hooks into these). No CSP headers. No ad anchor divs on Ezoic pages. WordPress-native lazy loading preserved (compatible with Ezoic's lazy loader). Engagement system works normally.
- **Required GitHub secrets:** `SFTP_HOST_TECARTICLES`, `SFTP_PORT_TECARTICLES`, `SFTP_USER_TECARTICLES`, `SFTP_PASS_TECARTICLES`, `SFTP_REMOTE_PATH_TECARTICLES`, `SITE_URL_TECARTICLES`, `CACHE_SECRET_TECARTICLES`, `WP_USER_TECARTICLES`, `WP_APP_PASSWORD_TECARTICLES`.

### Fix: Page Ads 0% Fill Rate — Rate Limiter Blocking filled/empty Events (Feb 27, 2026)
- **Root cause:** `sendEvent('impression', record)` fired in `renderSlot()` at GPT display time, hitting the PHP rate limiter (1 event per 2 seconds per IP via transient). When `slotRenderEnded` fired shortly after (typically <2s), the `sendEvent('filled'/'empty', ...)` was silently dropped as rate-limited. Result: impressions recorded, viewable events recorded (arrive >2s later via IO tracker), but filled=0 and empty=0 on every row → 0% fill rate in CSV and stats dashboard.
- **Fix (page-ads.js):** Removed premature `sendEvent('impression', ...)` from `renderSlot()`, `initAnchorAd()`, and `initInterstitialAd()`. The `slotRenderEnded` handler (which already sent 'filled'/'empty' correctly) is now the sole event sender per slot — no more rate-limit collision. Added `slot === e.slot` fallback matching in `slotRenderEnded` and `impressionViewable` handlers for overlay ads (out-of-page slots have auto-generated element IDs). Added `viewableEver` guard in `impressionViewable` handler to prevent double viewable sends (IO-based tracker already had this guard).
- **Fix (page-ad-recorder.php):** Removed the aggressive per-IP rate limiter (2s transient) that was systematically blocking filled/empty events. Added auto-increment of 'impressions' counter when 'filled' or 'empty' events arrive — replaces the separate JS impression events. Bot detection remains.
- **Pattern:** Matches smart-ads.js approach where impression tracking happens at `slotRenderEnded` time (not at slot creation time).

### Fix: Injection Lab 0% Fill Rate — Missing injection_type on Events (Feb 27, 2026)
- **Root cause:** `__plAdTracker.track('impression', ...)` and `track('empty', ...)` in smart-ads.js were NOT passing `injectionType` in the extras. This caused `injection_type` column in `pl_ad_events` DB table to be empty (`''`) for `impression` and `empty` event rows. The injection lab's `type_comparison` SQL query filters `WHERE injection_type != ''`, which excluded ALL impression rows → overview showed 0% fill rate despite 1,201 actual fills.
- **Why by_response_time showed fills correctly:** That query filters by `gpt_response_ms > 0` (not `injection_type`), so impression events with GPT response times were correctly included.
- **Fix (smart-ads.js):** Added `injectionType`, `scrollSpeed`, `scrollDirection`, `adSpacing`, `nearImage`, `adsInViewport`, `adDensityPercent` to the `impression` track call in `onDynamicSlotRenderEnded`. Added `injectionType`, `scrollSpeed`, `scrollDirection`, `adSpacing`, `nearImage` to the `empty` track call. All fields sourced from the ad record (`rec.injectionType`, etc.).
- **Fix (ad-data-recorder.php):** v5→v6 migration backfills `injection_type` on existing `impression`, `viewable`, and `empty` events from their corresponding `dynamic_inject` event (matched by `session_id + slot_id`). One-time UPDATE query runs on admin_init.
- **DB version:** bumped from `'5'` to `'6'`.

### Category Ads as Grid Cards (Feb 26, 2026)
- **feat: category ads as grid cards at varying natural positions** — Ads occupy ONE cell like a post card (not full-width column-spanning). `.pl-cat-ad-card` matches `.pl-cat-card` exactly: `break-inside:avoid`, `border-radius:16px`, `box-shadow`, `background:#fff`, `margin-bottom:18px`. No "Advertisement" label.
- **Predefined positions** — `$pa_positions = [1, 6, 11, 16, 22, 27, 32, 38, 43, 48]` with varied spacing (5,5,5,6,5,5,6,5,5) for natural feel. PHP while loop interleaves posts and ads: `$pa_item_idx++`, if index in `$pa_positions` → output ad card, else → output post card and increment `$pa_post_idx`.
- **page-ads.js card mode** — `renderSlot()` detects `isCatCard` (`PAGE_TYPE === 'category'`): skips spacing check (positions predefined in PHP), renders container with `width:100%` instead of forced `max-width`/`margin`, dummy mode fills card naturally (no fixed pixel dimensions or dashed border).
- **Load-more JS** — Uses `AD_PATTERN=[5,5,5,6,5,5,6,5,5]` countdown pattern. Continues `adIdx` from PHP `$pa_ad_idx`. Each post card decrements countdown; at zero, injects `.pl-cat-ad-card.pl-page-ad-anchor` + calls `__plPageAds.rescan()`. Pattern index wraps cyclically.
- **Category ads: rectangle only** — Triple-enforced: (1) PHP anchors `data-format="rectangle"`, (2) page-ads.js forces `fmt='rectangle'` for category, (3) `getSizesForFormat()` returns `CAT_SIZES` (300x250 + 336x280).
- **MutationObserver + rescan** — MutationObserver watches `.pl-cat-grid` for new children. Load-more JS calls `__plPageAds.rescan()` after appending.

### Page Ad System Enhancement (Feb 26, 2026)
- **feat: anchor/interstitial ads, 3-position homepage, aggressive category ads, stats dashboard**
- **Overlay ads** — Anchor ad (fixed bottom bar, close button, once per session via sessionStorage `pl_pg_anchor_shown`, GPT BOTTOM_ANCHOR). Interstitial (full-screen overlay, 3s delay, 10s auto-close, once per session via `pl_pg_interstitial_shown`, homepage+category only). Both have dummy mode placeholders.
- **Format system** — Three format types with responsive sizes: leaderboard (970x250/728x90 desktop, 320x100/320x50 mobile), rectangle (336x280/300x250 desktop, 300x250 mobile), banner (728x90 desktop, 320x50 mobile). Homepage uses fixed format-per-position via `data-format` attribute.
- **Homepage ads** — Exactly 3 fixed-position inline ads: TOP (leaderboard), MIDDLE (rectangle), BOTTOM (leaderboard). All 3 templates (front-page.php, emerald, coral) updated with `data-format` attributes on anchor divs.
- **Category ads** — Rectangle only. First ad before grid, then every 3 posts. Reduced spacing: 300px desktop, 250px mobile. MutationObserver for dynamically loaded posts (watches `.pl-cat-grid, main, .cb-grid-4, .ee-grid-3, .pl-post-grid`).
- **Settings defaults** — `homepage_max=3`, `category_max=10`, `page_max=3`, `desktop_spacing=300`, `mobile_spacing=250`, `category_every_n=3`.
- **Stats dashboard** — Admin "Page Ads" tab shows today/7d/30d stats fetched from REST API. Stats endpoint returns structured breakdowns: `{ today, last_7_days, last_30_days, by_domain, by_format }` with fill_rate calculation. Storage now keyed by date/domain/page-type/format (backwards compatible with legacy flat structure).
- **Enhanced event reporting** — `sendEvent()` includes adFormat, device, viewportWidth, renderedSize, url, timestamp. Console logging: `[PageAds] Event: type | slot: name | format: fmt | page: type`.
- **Public API** — `window.__plPageAds = { getSlots, getConfig, rescan }`.

### Page Ad System (Feb 26, 2026)
- **feat: separate page ad system for non-post pages** — Completely isolated ad system for homepages, category archives, and static pages. Zero overlap with post ads (smart-ads.js / initial-ads.js).
- **inc/page-ad-engine.php** — Settings (`pl_page_ad_settings` option, `enabled` defaults to `true`, `dummy_mode` defaults to `true`), "Page Ads" admin tab in Ad Engine, enqueue with `!is_single()` guard, `plPageAds` config output, category archive injection via `the_post` hook (every Nth post), static page injection via `the_content` filter (priority 50).
- **inc/page-ad-recorder.php** — POST `/pl/v1/page-ad-event` (impression/viewable/click/empty/filled), GET `/pl/v1/page-ad-stats` (admin-only). Storage in `wp_option` keyed by date/domain/page-type/format, 30-day retention.
- **assets/js/page-ads.js** — IIFE with runtime bail-out (`document.querySelector('.single-post') || window.plAds`). Two-phase injection: Phase 1 (above-fold immediate), Phase 2 (IO with 400px rootMargin). Responsive sizing per format type. Dummy mode with colored placeholders. Viewability tracking (IO 50% threshold + 1s dwell). Beacon on page hide. GPT slot names use `_pg_` infix.
- **inc/ad-engine.php** — Added `is_single()` guard to `pinlightning_ads_enqueue()` (post ads skip non-single pages). Added "Page Ads" tab link in admin nav.
- **functions.php** — Added `require_once` for `page-ad-engine.php` and `page-ad-recorder.php`. Added `is_single()` guard to `initial-ads.js` loading in `pinlightning_postload_scripts()` (was loading on all pages without config).
- **Homepage templates** — Added 3 `.pl-page-ad-anchor` divs each to `front-page.php`, `template-emerald-editorial.php`, `template-coral-breeze.php` (between sections, with `data-format` attributes).
- **fix: page ads not loading** — Three bugs: (1) `enabled` defaulted to `false` so enqueue returned early, changed to `true`. (2) `initial-ads.js` loaded on all pages via `pinlightning_postload_scripts()` without `is_single()` guard — ran on homepages with hardcoded fallbacks creating rogue overlays. Added guard. (3) page-ads.js cache-busting used static version instead of `filemtime()`.

### Coral Breeze Homepage (Feb 26, 2026)
- **feat: Coral Breeze homepage for pulsepathlife.com** — 9-section layout: sticky white header, twin hero cards (2-col, diverse categories), category pills (top 8, coral outline), trending now (6 posts, 3-col, per-category diversity), category spotlight (navy strip, top category featured image + 3 posts), latest stories (8 posts, 4-col, per-category diversity), load more (REST API inline JS), newsletter (`/pl/v1/subscribe`), shared footer via `get_footer()` with coral/navy CSS overrides.
- **template-coral-breeze.php** — Per-category round-robin queries for hero, trending, latest. Category spotlight uses top category by count. `$shown_ids` accumulates across all sections into Load More `data-exclude`. All WP_Query use `no_found_rows => true`. CSS prefix: `cb-`.
- **assets/css/homepage-coral.css** — Playfair Display headings (font-display:swap), coral/navy palette (CSS custom properties), responsive 1200/768/480px breakpoints. Inlined at wp_head priority 3 by `pl_coral_critical_css()`.
- **inc/homepage-templates.php** — Added `pl_coral_critical_css()` inliner. Domain routing and template_include already wired from initial build.

### Emerald Editorial Homepage (Feb 26, 2026)
- **feat: Emerald Editorial homepage for inspireinlet.com** — Domain-based routing via `pl_resolve_homepage_template()`: inspireinlet→emerald, pulsepathlife→coral, cheerlives→default. Customizer override `pl_homepage_template` (auto/default/emerald/coral). `template_include` filter at priority 99.
- **template-emerald-editorial.php** — 5-section layout: hero (sticky post preferred, eager+fetchpriority), trending sidebar (4 posts, 1 per category), category circles (transient-cached), latest grid (6 posts + Load More, max 2 per category), newsletter CTA (inline JS, `/pl/v1/subscribe` endpoint). Uses `get_footer()` for shared footer with emerald CSS overrides. Post deduplication via `$shown_ids` across all queries. All queries use `no_found_rows => true`.
- **assets/css/homepage-emerald.css** — Playfair Display headings (font-display:swap from gstatic), emerald/gold palette (CSS custom properties), responsive breakpoints at 1200/768/480px. Inlined at wp_head priority 3 by `pl_emerald_critical_css()`.
- **inc/homepage-templates.php** — Router, Customizer setting, CSS inliner, `pl_get_category_circles()` with 1hr transient cache.
- **fix: per-category diversity** — Both Trending and Latest use per-category queries from shared `$ee_cats` (top 20 by count). Trending: 1 post per category, 4 slots. Latest: round-robin pass 1 (1 per cat) + pass 2 (2nd per cat), max 2 per category. Both backfill with date-ordered posts if categories < slots. Most Loved removed. Load More button (date-ordered REST API) after Latest. Footer: `get_footer()` + `.ee-home .pl-footer` CSS overrides.

### Multi-Site Deployment (Feb 26, 2026)
- **feat: make ad slot path and video config dynamic** — `SLOT_PATH` in initial-ads.js and smart-ads.js now reads from `plAds.slotPrefix` (falls back to hardcoded default). Video player `C_WEBSITE` uses `window.location.hostname`, `C_NETWORK_CODE` reads from `plAds.networkCode`. CDN proxy (`cdn/img.php`) allowlists all 3 domains.
- **ci: deploy to all 3 sites as parallel jobs** — Added `deploy-inspireinlet` and `deploy-pulsepathlife` jobs to `deploy.yml` (parallel, no `needs:`). Each job: checkout, build, rsync, cache flush, plugin list. PageSpeed + perf-check remain cheerlives-only for now. Separate workflow files don't trigger from non-default branches, so all jobs are in one workflow.
- **GA4 already configurable** — `pl_ga4_measurement_id` via Customizer. Ad.Plus network settings already in ad-engine.php settings page.

### Engagement UI Fix (Feb 26, 2026)
- **Fix: move engagement sprite + heart above anchor ad** — Girl sprite (`bottom:70px`) and heart button (`bottom:20px`) were covered by GPT bottom anchor ad on mobile. Moved to right-center: heart at `bottom:50%; transform:translateY(50%)`, sprite at `bottom:calc(50%+30px)`, speech bubble/chat at `bottom:calc(50%+120px)`. Z-index lowered from 1000 to 99 so anchor ad stays on top. Updated `.pl-v-wrap`, `.pl-e-heart`, `.pl-e-sp`, `.pl-chat-wrap`, `.pl-e-sk` in scroll-engage.js, `.eb-char-bubble` in engagement.css, `.pl-heart-progress`/`.pl-combo` in main.css.
- **Fix: sparkle position + gap between girl and heart** — Sparkle effects (`.pl-e-sk`) were spawned at hardcoded `bottom:70-160px` (old position). Now uses `getBoundingClientRect()` on `.pl-v-wrap` for dynamic positioning. Girl sprite gap increased from 10px to 30px above heart center to prevent overlap.

### Bug Fixes — Event Pipeline + Interstitial + Relocation (Feb 26, 2026)
- **Fix: event tracking pipeline — force v5 migration, defensive INSERT** — `pl_ad_ensure_tables()` early-exit checked for version '4' but v5 migration was added after. Existing v4 installs returned early, ALTER TABLE for `gpt_response_ms` + `relocated` never ran, every `$wpdb->insert()` failed (0 events stored). Fix: early-exit now checks '5', on-demand migration in event handler with static guard, defensive INSERT fallback without v5 columns.
- **Fix: Layer 1 interstitial — prevent exit-intent from destroying pending slot** — `tryExitInterstitial()` unconditionally destroyed the Layer 1 interstitial before GPT could show it (interstitials show at GPT's discretion, not immediately). Fix: check `__plOverlayStatus.interstitial` — if 'pending', refresh() existing slot to nudge GPT; if already showed/failed, destroy and create new exit slot.
- **Fix: lower relocation threshold to 200px, add 2s delayed recheck** — Relocation showed 0 despite 47% of filled ads not being viewable. Root cause: 500px threshold too conservative — ads 200-499px outside viewport weren't relocated. Fix: threshold 500→200px, added 2s delayed recheck (if ad filled near viewport but user scrolled past, relocate after 2s), added detailed console.log throughout relocation pipeline for diagnostics.

### Round 4 — Fill Rate + Response Tracking (Feb 26, 2026)
- **10s patient GPT timeout** — Replaced aggressive 3s `showHouseAd()` timeout with patient 10s collapse-only timeout. Real empties handled by `slotRenderEnded` (house ad on isEmpty:true). 10s catches truly stuck GPT, avoids killing ads that would fill in 4-5s.
- **Explicit refresh() after display()** — Added `googletag.pubads().refresh([slot])` after `googletag.display()` in `renderSlot()`. Required for SRA mode — post-initial slots need explicit refresh for reliable fill.
- **Ad relocation** — `relocateFilledAd()` moves DOM element (keeps GPT iframe alive) when user has scrolled 200+ px past a filled ad. Immediate check on GPT fill + 2s delayed recheck for near-viewport fills. Respects spacing rules + Layer 1 exclusion zones. Tracked in beacon/heartbeat.
- **GPT response time tracking** — `renderRequestedAt` timestamp in `renderSlot()`, `gptResponseMs` calculated in `slotRenderEnded`. Stored in `pl_ad_events` table (v5 migration: `gpt_response_ms`, `relocated` columns). Injection Lab: response time bracket table + relocation stats table. Live Sessions: GPT ms + Relocated columns in Ad Detail.
- **Event tracking pipeline update** — `trackAdEvent()` in initial-ads.js: new short keys `grm` (GPT response ms) and `rel` (relocated). ad-data-recorder.php: v4→v5 migration for new columns. Beacon/heartbeat zones: `gptResponseMs`, `relocated`, `relocatedFromY`, `relocatedToY` fields.

### Exit-Intent + Image Tap Tracking (Feb 25, 2026)
- **Fix: exit interstitial uses new slot instead of refresh** — GPT interstitials are one-shot (`maxRefresh: 0`), refreshing yields 0 fills (108 fires, 0 fills). Fix: destroy Layer 1 interstitial via `googletag.destroySlots()`, then `defineOutOfPageSlot` + `display` for a fresh slot. GPT only allows one interstitial per page, so the old one must be destroyed first.
- **Exit-intent interstitial** — `tryExitInterstitial()` in smart-ads.js fires a new GPT interstitial slot on tab switch (visibilitychange), mouse-leave (clientY<10), or beforeunload. 15s minimum session. Admin toggle `exit_interstitial` in ad-engine.php. `initExitIntent()` runs at script load (not gated by engagement). Tracked in beacon + heartbeat.
- **Exit interstitial full analytics** — `_exitRecord` tracks fill/viewable/size via targeted GPT `slotRenderEnded` + `impressionViewable` listeners. Record appended to beacon/heartbeat zones (NOT in `_dynamicSlots` — no DOM element). Shows as zone row in Live Sessions Ad Detail with `injection_type: 'exit_intent'` (highlighted yellow). Totals not double-counted (already in Layer 1 slotMap). Zone storage updated with `injection_type`, `trigger`, and `divId` fallback fields.
- **Image tap tracker** — `initImageTapTracker()` in engagement.js. Delegated click listener on `.single-content img` + `.infinite-post-content img`. Stores `{src, alt, heading, ts}` in `window._imageTaps`. Sent via beacon (full array) and heartbeat (count). Stored in ad-data-recorder.php session JSON. Displayed in Live Sessions detail panel.

### Next-Post Auto-Load (Feb 25, 2026)
- `3d37051`: **Next-post auto-load with smart-ads rescan** — Rewrote `infinite-scroll.js` as a next-post auto-loader (IO trigger at 70% read, max 3 posts/session). smart-ads.js `findTargetNear()` now uses `querySelectorAll('.single-content')` to discover paragraphs in all content sections (original + auto-loaded). Exposed `window.SmartAds.rescanAnchors()` API from the IIFE. engagement.js `handleNext()` smooth-scrolls to auto-loaded post if present instead of navigating. Admin toggle `next_post_autoload` added to Device Controls in ad-engine.php. PHP passes `autoLoad` flag via `plInfinite` localized config.
- `2bac6de`: **Fix auto-loaded posts getting zero ads** — `rescanAnchors()` now aggressively destroys all dynamic slots above the viewport (frees activeCount budget + removes spacing blockers), resets `_houseAdsShown` to 0 (fresh quota), and clears `_slotViewStart`. Root cause: engine loop never stops but `activeCount >= MAX_DYNAMIC_SLOTS` and `checkSpacing()` rejecting positions near old slots blocked all injection into new content.
- `97abcdd`: **Remove Read Next bar** — `.eb-next-bar` fixed bottom bar removed from single.php, engagement.js, engagement.css, critical.css, functions.php (`pl_get_next_post_data()` + `nextPost` config key), engagement-customizer.php toggle. Replaced by next-post auto-loader.

### Admin Tooling (Feb 25, 2026)
- `a223aba`: **Stable snapshot system** — save/revert all ad engine files from admin UI. `inc/ad-snapshot.php` handles AJAX save (copies 13 files to `backup/ad-engine-stable/` with `snapshot.json` metadata) and revert (restores from backup). UI panel at top of Ad Engine settings page. `backup/` gitignored.

### Round 3 — Tab Pause + Last-Chance + Sizes (Feb 25, 2026)
- `8634570`: **Tab visibility pause** — `_tabVisible` gates engineLoop, lazy-render, fast-scroller timeout; resets `_slotViewStart` on tab return
- `64a0eca`: **Viewport-aware sizes** — `getDynamicAdSizes()`: mobile (<768px) = 300x250 only (single auction, ~200ms faster); desktop = full multi-size
- `d142cbe`: **Last-chance refresh** — `observeForLastChanceRefresh()`: IO watches filled ads exiting viewport top, refreshes if viewableEver + 30s cooldown
- `b9d2698`: **Slow-creative timeout** — 3s timer after `renderSlot()`, collapses via `showHouseAd()` if creative hasn't arrived (replaced in Round 4 with patient 10s collapse-only timeout)

### Round 2 — Preconnect + Retry + Backfill (Feb 25, 2026)
- `5a92fd8`: Preconnect to ad CDNs (GPT, syndication, Ad.Plus, TPC)
- `1a0dcb5`: Lazy-render rootMargin 200→400px
- `a007904`: min-height size hints + flex centering on ad containers
- `f323dc5`: Sticky sidebar max-height:90vh
- **SKIPPED**: Anchor refresh 30→20s (violates Google 30s minimum policy)
- `7fe537a`: Retry empty slots once after 3s with [[300,250]] fallback
- `de64131`: House ad backfill for empty slots (email capture CTA)

### Round 1 — Spacing + Core Viewability (Feb 25, 2026)
- `8473caf`: 400px minimum spacing enforced at PHP config, JS init, runtime guard
- `40fa376`: Viewport refresh per-slot tracking (replaces broken `_isPaused` check)
- `1a69b1b`: Lazy-render with IntersectionObserver, 200px rootMargin
- `19d9143`: Fast-scroller defer 500ms, skip if scrolled away
- `d655469`: Pause share: PAUSE_VELOCITY 80→100, sustain 150→120ms, rapid-decel
- `e98c2de`: Sticky inline 1.5s hold for viewability

### Pre-Round Fixes (Feb 24-25, 2026)
- `341f0a5`: Skip sidebar display() on mobile (GPT warning fix)
- `e8156ba`: Sync viewability tracking (viewableEver flag)
- `3ebd483`: MIN_PIXEL_SPACING 200→400px
- `4f21aa3`: Scroll-up spacing 0.8x→1.3x
- `301e82a`: Pause frequency: speed 50→80, sustain 200→150ms
- `504e5f4`: CMP z-index fix (InMobi — now removed)
- `4439c1f`: InMobi CMP deployment (now removed — using Google Ad Manager built-in CMP)
- `a3f4ff6`: Top anchor removal

### Earlier (Feb 2026)
- Ad Engine v5 complete rebuild with two-layer architecture
- Injection Lab + Live Sessions dashboard
- Bidirectional predictive injection
- Speed-based spacing, viewport density control
- Image targeting, slow scroll strategy
- 6 critical overlay/viewable/placement fixes
- Event-driven overlay status tracking

---

## 14. Current Status

### Working
- Ad injection engine with all 4 rounds of viewability + fill rate optimization
- Google Ad Manager built-in CMP for GDPR/TCF compliance (EEA only)
- Viewport refresh, lazy-render, sticky inline, last-chance refresh
- Tab visibility pause, retry logic, house ad backfill
- Patient 10s GPT timeout (replaces aggressive 3s), explicit refresh() after display()
- Ad relocation DISABLED (0% viewability on 82 relocated ads — code remains but never called)
- GPT response time tracking (beacon/heartbeat/DB/injection lab/live sessions)
- Viewport-aware size selection
- 500px minimum spacing enforced everywhere (raised from 400px, fast-scanners get 600px floor)
- Consecutive empty abort (3 empties in a row → stop injecting, demand exhausted)
- Mobile initial-ad-1 delayed 2s (prevents scrolling past before GPT fills)
- Sidebar viewability check (collapse invisible sidebar ads after 5s)
- Predictive window reduced 20% (0.8 vs 1.0 — ads land closer to user stop point)
- Stable snapshot system for ad engine files (save/revert from admin)
- Next-post auto-load (IO trigger at 70% read, max 3 posts, smart-ads rescan)
- Exit-intent interstitial (fires GPT interstitial on tab switch/mouse-leave/beforeunload)
- Image tap tracker (click tracking on post images, stored in session data)
- Page ad system for non-post pages (homepage, category, static) — fully isolated from post ads
- PageSpeed scores maintained at 100/100/100/100
- tecarticles.com: Ezoic-compatible deployment with zero ad engine code, Tec Slate homepage template

### Monitoring (check after 24-48 hours)
- Viewability trend: 43.8% → 48.8% → 54% → target 63-70%
- Fill rate improvement from consecutive empty abort (should reduce wasted slots significantly)
- Fast-scanner viewability improvement from 21% with wider spacing + longer gap
- Predictive TTV improvement from 6022ms with reduced window
- Mobile initial-ad-1 viewability after 2s delay
- Sidebar collapse frequency (sidebar_collapsed_invisible events)
- GPT response time distribution (injection lab brackets)
- Last-chance refresh count
- House ad impressions and clicks

### Known Issues
- Pause injection share dropped to 3.3% (was 9%) after round 1 — may need PAUSE_VELOCITY tuning
- Viewport refresh shows in session stats but not injection lab — minor tracking gap

---

## 15. Future Plans

### Email Capture Funnel (Next Major Project)
- **Email provider:** MailerLite free account
- **Lead magnet:** Free PDF "How I Get 5,000+ Pinterest Visitors/Month"
- **Landing page:** cheerlives.com/free-pinterest-guide (custom template, no sidebar, no ads)
- **Top sticky banner** on all posts promoting the free guide
- **Banner click** → popup with name + email fields → MailerLite API
- **7-email automation** (welcome → tips → story → sales)
- **Paid product:** "Pinterest Niche Site Blueprint" ($27-47 PDF)

### Future Ad Optimizations
- Continue monitoring viewability toward 80% target
- A/B test house ad copy for email capture
- Evaluate reducing injection count to only high-confidence positions

---

## 16. Known Pitfalls & Gotchas

1. **LiteSpeed/QUIC + 103 Early Hints** — NEVER use `header(..., false, 103)`. Crashes the site.
2. **get_the_excerpt() in filters** — Causes infinite recursion. Always use `$post->post_excerpt`.
3. **Merged IntersectionObservers** — Must keep seenIO and animIO separate. Different thresholds.
4. **aspect-ratio on containers with padding** — CSS applies to border-box. Put on `<img>`.
5. **Critical CSS visual properties** — Colors/backgrounds in critical.css cause CLS.
6. **CDN image dimensions** — All myquickurl.com images are 1080x1920. Hardcoded constants.
7. **img-resize.php preload URL matching** — Both performance.php and image-handler.php must use identical URL construction.
8. **assets/css/dist/ is gitignored** — Production falls back to source. CI creates dist/.
9. **HTML minification blocks streaming** — Gzip handles compression. Don't re-add.
10. **content-visibility:auto on .eb-item** — Breaks IntersectionObserver on mobile. Only on .eb-break/.eb-curiosity.
11. **Floating .eb-live removed** — Live count inside .eb-counter bar. Don't re-add separate element.
12. **display:none breaks IntersectionObserver** — Use `height:0;overflow:hidden;margin:0` instead.
13. **wp_localize_script converts values to strings** — JS `>=` works but `===` doesn't.
14. **Google 30s refresh minimum** — Never refresh GPT slots faster than 30 seconds.
15. **Sidebar GPT slot warnings** — display() gated behind IS_DESKTOP. Don't call display() without matching defineSlot().
16. **Ezoic sites (tecarticles.com)** — NEVER load GPT, smart-ads, initial-ads, page-ads, or any ad engine code. Use `pl_is_ezoic_site()` guard. Ezoic handles all ad injection via their DNS/plugin integration.

---

## 17. Key Constants

```php
PINLIGHTNING_VERSION       = '1.0.0'
PINLIGHTNING_DIR           = get_template_directory()
PINLIGHTNING_URI           = get_template_directory_uri()
PINLIGHTNING_CDN_ORIG_W    = 1080
PINLIGHTNING_CDN_ORIG_H    = 1920
PINLIGHTNING_CDN_DISPLAY_W = 720
PINLIGHTNING_CDN_DISPLAY_H = 1280
```

```js
SLOT_PATH         = '/21849154601,22953639975/'
IS_DESKTOP        = window.innerWidth >= 1025
PAUSE_VELOCITY    = 100    // px/s
PAUSE_THRESHOLD_MS = 120   // ms (configurable via optimizer)
MIN_PIXEL_SPACING  = 500   // absolute floor (raised from 400, 400-600px had 22% viewability)
MAX_PIXEL_SPACING  = 1000  // ceiling
REFRESH_INTERVAL   = 30000 // 30s (Google policy)
MAX_REFRESH_DYN    = 2     // max refreshes per dynamic slot
MAX_DYNAMIC_SLOTS  = 20    // recycled when exceeded
```

---

## 18. Standing Rules for All Future Sessions

1. **ALWAYS update CLAUDE.md** after ANY changes, commit with "docs: update CLAUDE.md", and push
2. **ALWAYS read smart-ads.js and initial-ads.js fully** before making ad changes
3. **ALWAYS commit** with descriptive conventional messages (feat:, fix:, perf:, docs:)
4. **ALWAYS check PageSpeed** after CSS/HTML changes in header or critical path
5. **NEVER go below 500px** minimum ad spacing
6. **NEVER refresh ads faster than 30 seconds** (Google GPT policy)
7. **NEVER use `get_the_excerpt()` in filters** — use `$post->post_excerpt`
8. **NEVER use 103 Early Hints** on LiteSpeed/QUIC
9. **NEVER re-add HTML minification** — breaks streaming
10. **ALWAYS gate ad code** behind `pl_ad_settings()['enabled']` check
11. **ALWAYS test** on both mobile (<768px) and desktop (≥1025px)
12. **ALWAYS preserve existing viewability optimizations** when adding features
13. **ALWAYS track new metrics** in beacon/heartbeat payloads
14. **CLAUDE.md lives in theme root** (same directory as style.css, header.php, functions.php)
15. **ALWAYS guard ad code with `pl_is_ezoic_site()`** on any new ad-related hooks or output
16. **NEVER load GPT/ad scripts on Ezoic domains** — Ezoic handles its own ad injection
