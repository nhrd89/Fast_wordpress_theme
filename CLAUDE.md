# PinLightning Theme — CLAUDE.md

> Complete reference for any Claude Code session to pick up immediately.
> **Branch:** `claude/setup-pinlightning-theme-5lwUG`
> **Last updated:** 2026-02-25

---

## 1. Project Overview

**cheerlives.com** is a fashion/lifestyle content site targeting Pinterest traffic. It publishes listicle-style posts (e.g., "25 Stunning Hairstyles") with curated CDN images, engagement gamification, and monetization via display ads.

### Tech Stack
- **CMS:** WordPress (self-hosted)
- **Theme:** PinLightning (custom-built, zero external dependencies)
- **Hosting:** Hostinger (LiteSpeed server + QUIC/HTTP3)
- **CDN:** Contabo (myquickurl.com) for content images
- **CI/CD:** GitHub Actions — push to `main` or dev branch triggers deploy
- **Ad Network:** Ad.Plus (Google Ad Manager / GPT)
- **Consent:** InMobi CMP (TCF v2.2/v2.3)

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
│   │   └── dist/           # Build output (gitignored — production falls back to source)
│   ├── js/
│   │   ├── core.js         # Minimal theme script (hamburger menu, etc.)
│   │   ├── initial-ads.js  # Layer 1: GPT setup, initial viewport slots, overlay refresh
│   │   ├── smart-ads.js    # Layer 2: Dynamic predictive injection engine
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
│   └── contact-messages.php # Contact form message handling
│
├── template-parts/
│   └── content-card.php    # Reusable card component for grids
│
├── .github/workflows/
│   ├── deploy.yml          # Main deploy + PageSpeed pipeline
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
```

---

## 3. Ad System Architecture

### Overview
Two-layer client-side ad system, both loaded post-`window.load` (invisible to Lighthouse):

| Layer | File | Boot Trigger | Purpose |
|-------|------|-------------|---------|
| Layer 1 | `initial-ads.js` | window.load + 100ms | GPT setup, static slots, overlays, refresh |
| Layer 2 | `smart-ads.js` | First user interaction (scroll/click/touch) | Dynamic below-fold injection |

### Layer 1 — Initial Viewport Ads (`initial-ads.js`)

**Static In-Content Slots** (injected by `ad-system.php` content filter at priority 30):
| Slot ID | Position | Sizes |
|---------|----------|-------|
| `initial-ad-1` | After paragraph 1 | 336x280, 300x250, 250x250, 300x100 |
| `initial-ad-2` | After paragraph 3 | 336x280, 300x250, 300x600, 250x250, 300x100 |
| `pause-ad-1` | After paragraph 6 | 300x250 (GPT contentPause) |

**Sidebar Slots** (desktop only, ≥1025px):
| Slot ID | Sizes | Notes |
|---------|-------|-------|
| `300x600-1` | 300x600, 300x250, 250x250, 200x200, 160x600 | Primary sidebar, tall formats |
| `300x250-sidebar` | 300x250, 250x250, 200x200 | Secondary sidebar, medium formats |

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
| Fast-scanner | >400 px/s | 3.5s |

**Injection Strategies (priority order):**
1. **Pause** (speed <100px/s for 120ms) — inject at viewport center. Highest viewability.
2. **Slow Scroll** (100-120px/s, sustained 4/6 samples) — inject ahead of scroll.
3. **Predictive** (decelerating + <300px/s) — inject at predicted stop point.
4. **Viewport Refresh** (runs independently every tick) — refresh in-view ads after 3s continuous visibility + speed <20px/s.

**Rapid Deceleration Detection:**
- If speed drops from >150px/s to <100px/s within 300ms → treat as immediate pause
- Catches the moment just before a full stop for earlier injection

**Spacing Rules:**
- `MIN_PIXEL_SPACING = 400px` (enforced at PHP config, JS init with `Math.max(400, ...)`, and runtime guard)
- Formula: `speed × timeBetween`, clamped [400, 1000] px
- Scroll-up modifier: `spacing × 1.3` (users scan back too fast for viewability)
- Absolute guard: if actual placement <400px from nearest ad, abort injection

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
| Desktop (≥1025) | [[336, 280], [300, 250], [300, 600], [250, 250]] | Full multi-size for higher RPM |

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

**Slow-Creative Timeout:**
- 3s timer starts after `renderSlot()` is called
- If `rec.filled` is still false, collapses via `showHouseAd()`
- Prevents persistent 250px blank gaps from slow/no-fill auctions

**Exit-Intent Interstitial (`tryExitInterstitial()`):**
- Fires the GPT interstitial slot on user exit (tab switch, mouse-leave, beforeunload)
- `initExitIntent()` called at script load time (NOT inside `init()` — catches exits before engagement gate)
- 3 triggers: `visibilitychange` (tab switch), `mouseleave` (clientY<10), `beforeunload`
- Guards: `exit_interstitial` admin toggle, 15s minimum session, fires once per page
- Accesses Layer 1 interstitial via `window.__initialAds.getSlotMap()['__interstitial'].slot`
- Direct `googletag.pubads().refresh([slot])` (bypasses `refreshSlot()` which checks maxRefresh=0)
- Tracked: `exitInterstitialFired` + `exitInterstitialTrigger` in beacon and heartbeat

**Viewability Tracking:**
- `viewableEver` flag: set once on `impressionViewable`, never cleared (unlike `viewable` which resets on refresh)
- Session reporter uses `viewableEver` for accurate aggregate counts
- `window.__plViewableCount` shared counter incremented by both layers

**Key State Variables:**
```js
var _dynamicSlots   = [];    // all dynamic ad records
var _slotCounter    = 0;     // divId numbering
var _totalSkips     = 0;     // fast-scroll abandoned ads
var _totalRetries   = 0;     // empty slot retries
var _houseAdsShown  = 0;     // house ad backfills (max 2)
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
    destroyed: false, rendered: false, retried: false
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

- **Provider:** InMobi CMP (formerly Quantcast Choice)
- **Property ID:** `M65A7dGLumC_E`
- **TCF Version:** v2.2/v2.3 compliant
- **Script:** First `<script>` in `<head>`, output by `pinlightning_output_cmp_tag()`
- **Loading:** `__tcfapi` stub runs sync; `choice.js` loads async
- **Consent Audience:** EEA only (no popup for US/Asia/other)
- **Google Basic Consent:** enabled, all 7 purposes, default denied
- **Google Vendors:** enabled (required for GPT to read consent string)
- **String Format:** Both (GPP + TCF)
- **US Regulation:** enabled (CCPA opt-out for US visitors)
- **CSS:** `.qc-cmp2-container { z-index: 999999 }` (above all content)
- **Complianz plugin:** deactivated (replaced by InMobi CMP)

---

## 5. Deployment Pipeline

### GitHub Actions (`.github/workflows/deploy.yml`)
- **Triggers:** push to `main` or `claude/setup-pinlightning-theme-5lwUG`
- **Build:** `npm install` → `npm run build` (lightningcss + terser)
- **Deploy method:** rsync over SSH (sshpass) to Hostinger
- **Post-deploy:**
  - Flush cache via `POST /wp-json/pinlightning/v1/flush-cache` with `X-Cache-Secret`
  - PageSpeed Insights API (mobile + desktop)
  - Screenshots saved as artifacts (30-day retention)
  - Active plugin lists captured via WP REST API
  - Performance budget check via `scripts/perf-check.sh`
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
| Spacing distribution | 0 below 400px | Keep at 0 | Injection lab histogram |
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

---

## 12. Performance Decisions & History

### Removed/Disabled (DO NOT re-add)
- **HTML minification** — blocks streaming, inflated TTFB (SI 1.8s→1.3s when removed)
- **103 Early Hints** — causes ERR_QUIC_PROTOCOL_ERROR on LiteSpeed/QUIC
- **jQuery** — fully deregistered on frontend
- **wp-embed, Block library CSS, Dashicons (non-logged-in), Emoji, Global styles** — all removed
- **Top Anchor ad** — "Format already created" conflict, zero demand in 27 sessions
- **Read Next bar** (`.eb-next-bar`) — replaced by next-post auto-loader. Don't re-add.

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

### Exit-Intent + Image Tap Tracking (Feb 25, 2026)
- **Exit-intent interstitial** — `tryExitInterstitial()` in smart-ads.js fires the GPT interstitial slot on tab switch (visibilitychange), mouse-leave (clientY<10), or beforeunload. 15s minimum session. Admin toggle `exit_interstitial` in ad-engine.php. `initExitIntent()` runs at script load (not gated by engagement). Tracked in beacon + heartbeat.
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
- `b9d2698`: **Slow-creative timeout** — 3s timer after `renderSlot()`, collapses via `showHouseAd()` if creative hasn't arrived

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
- `504e5f4`: CMP z-index fix
- `4439c1f`: InMobi CMP deployment
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
- Ad injection engine with all 3 rounds of viewability optimization
- InMobi CMP for GDPR/TCF compliance (EEA only)
- Viewport refresh, lazy-render, sticky inline, last-chance refresh
- Tab visibility pause, retry logic, house ad backfill
- Creative timeout, viewport-aware size selection
- 400px minimum spacing enforced everywhere
- Stable snapshot system for ad engine files (save/revert from admin)
- Next-post auto-load (IO trigger at 70% read, max 3 posts, smart-ads rescan)
- Exit-intent interstitial (fires GPT interstitial on tab switch/mouse-leave/beforeunload)
- Image tap tracker (click tracking on post images, stored in session data)
- PageSpeed scores maintained at 100/100/100/100

### Monitoring (check after 24-48 hours)
- Viewability trend: 43.8% → 48.8% → 54% → target 63-70%
- Fill rate stability
- Last-chance refresh count
- Creative timeout frequency
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
MIN_PIXEL_SPACING  = 400   // absolute floor
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
5. **NEVER go below 400px** minimum ad spacing
6. **NEVER refresh ads faster than 30 seconds** (Google GPT policy)
7. **NEVER use `get_the_excerpt()` in filters** — use `$post->post_excerpt`
8. **NEVER use 103 Early Hints** on LiteSpeed/QUIC
9. **NEVER re-add HTML minification** — breaks streaming
10. **ALWAYS gate ad code** behind `pl_ad_settings()['enabled']` check
11. **ALWAYS test** on both mobile (<768px) and desktop (≥1025px)
12. **ALWAYS preserve existing viewability optimizations** when adding features
13. **ALWAYS track new metrics** in beacon/heartbeat payloads
14. **CLAUDE.md lives in theme root** (same directory as style.css, header.php, functions.php)
