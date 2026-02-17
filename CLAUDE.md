# PinLightning Theme — CLAUDE.md

> Complete reference for any Claude Code session to pick up immediately.
> **Branch:** `claude/setup-pinlightning-theme-5lwUG`

---

## 1. Deployment Pipeline

### GitHub Actions (`.github/workflows/deploy.yml`)
- **Triggers:** push to `main` or `claude/setup-pinlightning-theme-5lwUG`
- **Build:** `npm install` → `npm run build` (lightningcss + terser)
- **Deploy method:** rsync over SSH (sshpass) to Hostinger
- **Two sites deployed in sequence:**
  1. **cheerfultalks.com** — primary site
  2. **cheerlives.com** — secondary site
- **Post-deploy:**
  - Flush cache via `POST /wp-json/pinlightning/v1/flush-cache` with `X-Cache-Secret` header
  - PageSpeed Insights API (mobile + desktop) for both sites
  - Screenshots saved as artifacts (30-day retention)
  - Active plugin lists captured via WP REST API
  - Performance budget check via `scripts/perf-check.sh`
- **Excluded from deploy:** `.git/`, `node_modules/`, `.env`, `package.json`, `package-lock.json`, `.github/`, `*.md`, `.gitignore`

### Build System (`package.json`)
```
build:css → lightningcss --minify --bundle → assets/css/dist/critical.css + main.css
build:js  → terser → assets/js/dist/core.js
dev       → chokidar file watcher
```
- `assets/css/dist/` is gitignored — production falls back to source `assets/css/critical.css` and `assets/css/main.css`
- `assets/js/dist/` is gitignored — production falls back to source `assets/js/core.js`

---

## 2. File Map

```
Fast_wordpress_theme/
├── functions.php           # Theme setup, script/style enqueuing, Pinterest save buttons,
│                           # GA4 Measurement Protocol, engagement-weighted scoring,
│                           # homepage/category REST endpoints
├── header.php              # Sticky header, LCP preload, preconnect, .pl-header-stats
├── single.php              # Single post template, engagement UI, Pinterest inline JS
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
│   │   ├── critical.css    # Inlined in <head> at wp_head priority 1 (keep under 3KB)
│   │   ├── main.css        # Inlined in <head> at wp_head priority 2 (~2.7KB gzipped)
│   │   ├── engagement.css  # Non-critical, loaded via preload onload trick (deferred)
│   │   └── dist/           # Build output (gitignored)
│   ├── js/
│   │   ├── core.js         # Minimal theme script (hamburger menu, etc.)
│   │   ├── engagement.js   # Engagement IIFE: observers, counters, milestones, polls
│   │   ├── scroll-engage.js # Gamified character system + header stats reveal
│   │   ├── infinite-scroll.js # Infinite scroll via REST API
│   │   └── dist/           # Build output (gitignored)
│   └── engage/             # Scroll-engage character assets (images, video)
│
├── inc/
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
│   ├── ai-chat.php         # AI chat integration
│   ├── visitor-tracker.php # Visitor tracking server-side handler
│   ├── ad-engine.php       # Ad zone rendering
│   ├── ad-data-recorder.php # Ad performance tracking
│   ├── pinterest-seo.php   # Pinterest SEO optimizations
│   ├── template-tags.php   # Template helper functions
│   ├── customizer.php      # Theme Customizer settings
│   ├── customizer-scroll-engage.php # Scroll-engage Customizer settings
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

---

## 3. CSS Architecture

### Loading Strategy
All CSS is **inlined in `<head>`** — no external stylesheet requests.

| File | Hook | Priority | Method |
|------|------|----------|--------|
| `critical.css` | `wp_head` | 1 | `file_get_contents` → inline `<style>` |
| `main.css` | `wp_head` | 2 | `file_get_contents` → inline `<style>` |
| `engagement.css` | `wp_head` | 99 | `<link rel="preload" onload>` (deferred) |

### Critical CSS Rules (`critical.css`)
- **Must stay under 3KB** — only layout-affecting properties
- Contains: reset, header, card grid, single post layout, engagement UI positioning, homepage bento grid
- All engagement fixed elements (progress bar, counter, pills, live, streak, collect) positioned here
- `.eb-item` — no animation by default; `.eb-item.in-view` enables animation (in engagement.css)
- Mobile breakpoints: 768px and 480px

### Key CSS Decisions
- **Critical CSS is layout-only**: margins, padding, display, flex, grid, position, dimensions, overflow. Visual properties (colors, backgrounds, border-radius, shadows) live in main.css/engagement.css
- **CLS prevention**: aspect-ratio on `<img>` elements (not containers) via inline styles; fixed elements start hidden (`opacity:0; pointer-events:none`)
- **HTML minification was REMOVED** to enable streaming — DO NOT re-add (SI improved 1.8s→1.3s)

### Mobile Header Stats
```css
/* Desktop: hidden */
.pl-header-stats { display: none }

/* Mobile (≤768px): flex, hidden until scroll */
.pl-header-stats {
    display: flex; opacity: 0; transform: translateY(-5px);
    transition: opacity .3s, transform .3s;
}
.pl-header-stats.visible { opacity: 1; transform: translateY(0) }
.pl-header-stats .eb-collect-counter,
.pl-header-stats .eb-live { position: static; opacity: 1 }

/* Hide desktop-only counters on mobile */
.eb-desktop-only .eb-collect-counter,
.eb-desktop-only .eb-live { display: none }
```

---

## 4. JavaScript Architecture

### Script Loading
| Script | Method | Trigger |
|--------|--------|---------|
| `core.js` | `wp_enqueue_script` + defer | Page load |
| `engagement.js` | `wp_enqueue_script` + defer + charset=utf-8 | Page load (listicle posts only) |
| `scroll-engage.js` | Dynamic `<script>` injection | First scroll OR 8s timeout |
| `infinite-scroll.js` | `wp_enqueue_script` + defer | Page load (singular only) |
| `comment-reply.min.js` | Dynamic `<script>` injection | First scroll OR 8s timeout |

### Engagement.js — IIFE Structure
```
;(function() {
"use strict";
// Config from wp_localize_script (ebConfig)
// State: seenItems, favItems, collectedItems, milestonesFired, etc.
// DOM refs: getElementById for all engagement elements
// Functions: updateLiveCount, updateProgress, updatePills, checkMilestones,
//            checkAiTip, checkNextBar, checkScrollSpeed, checkExitIntent
// initObserver() — TWO IntersectionObservers (see below)
// init() — wire up event delegation, start live count interval
})();
```

### Split IntersectionObserver Pattern (CRITICAL)
```js
function initObserver() {
    // Observer 1: seenIO — counting/tracking, triggers early, one-shot per item
    var seenIO = new IntersectionObserver(callback, {
        rootMargin: '0px 0px 100px 0px',  // 100px pre-fetch below viewport
        threshold: 0.15                     // counts as "seen" at 15% visible
    });
    // On intersect: seenItems.add(idx), updateProgress(), updatePills(),
    //               checkMilestones(), checkNextBar(), checkAiTip(), seenIO.unobserve(el)

    // Observer 2: animIO — animation gating, toggles .in-view class
    var animIO = new IntersectionObserver(callback, {
        rootMargin: '0px',
        threshold: 0.8                      // shimmer starts at 80% visible
    });
    // On intersect: el.classList.add('in-view')
    // On exit: el.classList.remove('in-view')

    // Both observe all .eb-item elements
}
```

**Why two observers?** Merging them caused items to not count as "seen" until 80% visible (bad for progress tracking) and delayed skeleton loading. The split lets tracking happen early (15%) while animations only trigger when truly visible (80%).

### Counter Sync (Mobile ↔ Desktop)
- Desktop counters use `getElementById`: `ebCountNum`, `ebCollectNum`, `ebLiveCount`, `ebCounterLiveNum`
- Mobile counters in `.pl-header-stats` use `querySelectorAll`:
  - `.pl-header-stats .eb-collect-num` — seen count
  - `.pl-header-stats .eb-collect-total` — total items (set in `init()`)
  - `.pl-header-stats .eb-counter-live` — live reader count

### Header Stats Scroll Reveal (`scroll-engage.js`)
```js
(function() {
    var headerStats = document.querySelector('.pl-header-stats');
    if (!headerStats) return;
    var revealed = false;
    window.addEventListener('scroll', function() {
        if (revealed) return;
        if (window.scrollY > 50) {
            headerStats.classList.add('visible');
            revealed = true;
        }
    }, { passive: true });
})();
```
One-shot pattern: `revealed` flag prevents repeated class toggling; `passive: true` for zero scroll jank.

---

## 5. PHP Architecture — the_content Filter Chain

Priority order for `the_content` filter:

| Priority | Filter | File | Purpose |
|----------|--------|------|---------|
| 10 | `pinlightning_webp_picture_wrap` | `image-handler.php` | WebP `<picture>` wrapper |
| 10 | `pinlightning_pinterest_content_images` | `image-handler.php` | Pinterest data attrs (moved to p20) |
| 20 | `pinlightning_pinterest_content_images` | `image-handler.php` | Pinterest attrs on wp-image-{id} |
| 25 | `pinlightning_rewrite_cdn_images` | `image-handler.php` | CDN image rewriting with srcset |
| 90 | `pl_add_pinterest_save_buttons` | `functions.php` | Pinterest Save button overlay |
| default | `pinlightning_inject_engagement_breaks` | `engagement-breaks.php` | Engagement element injection |

### post_thumbnail_html Filter Chain

| Priority | Filter | Purpose |
|----------|--------|---------|
| 10 | `pinlightning_pinterest_thumbnail_attrs` | Pinterest data attrs |
| 20 | `pinlightning_rewrite_featured_image_cdn` | Local resizer rewrite + srcset |

---

## 6. Image Handling

### Two Image Pipelines

**1. Local uploads (featured images) → `img-resize.php`**
- Source: `/wp-content/uploads/` images
- Rewriter: `pinlightning_rewrite_featured_image_cdn()` at priority 20
- URL pattern: `PINLIGHTNING_URI/img-resize.php?src={uploads_path}&w={width}&q={quality}`
- Srcset widths: 240w (q50), 360w (q55), 480w (q60), 720w (q65)
- Display dimensions: 720px max width, height proportional to original
- Only processes hero sizes (large, full, post-hero) — skips card thumbnails

**2. CDN images (myquickurl.com) → resizer endpoint**
- Source: `myquickurl.com` URLs in post content
- Rewriter: `pinlightning_rewrite_cdn_images()` at priority 25
- URL pattern: `https://myquickurl.com/img.php?src={path}&w={width}&q={quality}`
- Srcset widths: 240w (q70), 360w (q75), 480w (q80), 665w (q80)
- All CDN originals are 1080x1920 (9:16 portrait), displayed at 720x1280
- Has re-entry guard (`static $running`) to prevent nested `the_content` calls from poisoning static counters

### LCP Preloading
- Called from `header.php` BEFORE `<meta charset>` (byte 0 of `<head>`)
- `pinlightning_preload_lcp_image()` → detects featured image or first content image
- Preload `<link>` with `fetchpriority="high"`, `imagesrcset`, `imagesizes`
- First image gets `loading="eager"` + `fetchpriority="high"`, all others get `loading="lazy"` + `fetchpriority="low"`

### Custom Image Sizes
| Name | Dimensions | Crop | Use |
|------|-----------|------|-----|
| `card-thumb` | 400x600 | Hard | Archive grid cards |
| `card-thumb-lg` | 600x900 | Hard | Featured/large cards |
| `pinterest-pin` | 1000x1500 | Hard | Pinterest sharing |
| `post-hero` | 1200x800 | Hard | Single post hero |

### CLS Prevention
- `aspect-ratio` set on `<img>` via inline style (NOT on container — avoids box-sizing/padding conflict)
- Width/height attributes on all images (browsers derive aspect-ratio from HTML attrs)
- `img { height: auto }` does NOT prevent aspect-ratio — this is correct behavior

---

## 7. Engagement System

### Features (listicle posts only)
Engagement UI appears only on posts with `<h2>` tags containing `#N` patterns (detected by `pl_is_listicle_post()`).

| Feature | Element ID | Behavior |
|---------|-----------|----------|
| Progress bar | `ebProgressFill` | Width tracks % of items seen |
| Item counter | `ebCounter` | "N / M ideas seen", shows after first item |
| Live count | `ebLive` / `ebCounterLive` | Simulated reader count (30-70 range) |
| Jump pills | `ebPills` | Fixed pill nav, click to jump to item |
| Streak | `ebStreak` | Consecutive item viewing counter |
| Speed warning | `ebSpeedWarn` | Shows when scroll speed > 2000px/s |
| Milestones | `ebMilestone` | Toast at 25%, 50%, 75%, 100% |
| Achievement | `ebAchievement` | "Style Expert" badge at 100% |
| Collect counter | `ebCollectCounter` | Collectible emoji tracking |
| Next article bar | `ebNextBar` | Shows at 80% progress |
| Exit intent | `ebExit` | Email capture on mouse-leave |
| AI tip | `ebAiTip` | Unlocks after 2/3 signals (poll + quiz + 50% seen) |

### Engagement Breaks (injected into content)
`engagement-breaks.php` injects elements between H2 items:
- Polls (with options, CSS-animated results)
- Quizzes (with correct answer reveal)
- Curiosity hooks ("Did you know...")
- Style tips
- Facts
- Email capture forms
- Related post suggestions

### Per-Category Config (`engagement-config.php`)
Categories: `hairstyle`, `hairstyles`, `nail-art`, `home-decor`, `architecture`, `fashion`

Each config defines: `breaks` (position + type), `poll` (question + options), `quiz` (question + styles), `character_messages`, `email_hook`, `blur_item`, `trending_count`, `collectible_count`, `collectible_emojis`, `toc_preview_count`

---

## 8. Mobile vs Desktop Differences

| Feature | Desktop | Mobile (≤768px) |
|---------|---------|-----------------|
| Live counter | Fixed position (top-right) | In `.pl-header-stats` (header bar) |
| Collect counter | Fixed position (bottom-left) | In `.pl-header-stats` |
| Jump pills | Fixed position (top center) | Hidden (`display: none`) |
| Streak | Fixed position (top-left) | Hidden (`display: none`) |
| Header stats | Hidden (`display: none`) | Flex, revealed on scroll >50px |
| Navigation | Horizontal flex | CSS-only hamburger menu |
| Card grid | 3 columns | 2 columns (768px), 1 column (480px) |
| Bento hero | 3-column grid | Single column, aspect-ratio 16/9 |

### Mobile counter architecture
- Header has `.pl-header-stats` between logo and hamburger
- Contains duplicate `.eb-collect-counter` and `.eb-live` spans
- Desktop originals wrapped in `.eb-desktop-only` div (hidden on mobile)
- `engagement.js` syncs both via `querySelectorAll` on update

---

## 9. Performance Decisions & History

### What's been removed/disabled (DO NOT re-add)
- **HTML minification** — blocks streaming, inflates TTFB (SI 1.8s→1.3s improvement when removed)
- **103 Early Hints** — causes ERR_QUIC_PROTOCOL_ERROR on LiteSpeed/QUIC (takes site down)
- **jQuery** — fully deregistered on frontend
- **wp-embed** — deregistered
- **Block library CSS** — dequeued
- **Dashicons** — dequeued for non-logged-in users
- **Emoji scripts/styles** — removed
- **Global styles / SVG filters** — removed
- **Smart ads** — disabled until Phase 3

### Performance optimizations active
- All CSS inlined in `<head>` (zero external CSS requests)
- All scripts deferred
- `?ver=` query strings stripped from all resources
- Gzip via `ob_gzhandler` (template_redirect at priority -1)
- Cache headers: 1hr HTML (stale-while-revalidate=86400), 1yr immutable for static assets
- CDN preconnect to `myquickurl.com` at byte 0 of `<head>`
- LCP preload with `fetchpriority="high"` + srcset at byte 0 of `<head>`
- Scroll-deferred loading: `scroll-engage.js`, `comment-reply.min.js`, tooltipster CSS
- `content-visibility: auto` on below-fold engagement items
- Engagement CSS loaded via preload/onload trick (non-blocking)

### Critical performance bugs to avoid
- **NEVER use `get_the_excerpt()` in wp_head or inside the_content filters** — triggers `wp_trim_excerpt()` → `apply_filters('the_content', ...)` which fires CDN/image rewriters and poisons static counters. Use `$post->post_excerpt` or `$post->post_content` with `wp_strip_all_tags()`.
- **aspect-ratio on containers with padding** — causes CLS. Put aspect-ratio on `<img>` element.
- **CDN rewriter re-entry** — `static $running` guard prevents nested `the_content` calls.

---

## 10. REST API Endpoints

| Method | Endpoint | File | Purpose |
|--------|----------|------|---------|
| GET | `/pinlightning/v1/random-posts` | `rest-random-posts.php` | Infinite scroll (per_page, exclude) |
| POST | `/pinlightning/v1/flush-cache` | `performance.php` | Cache flush (requires X-Cache-Secret) |
| POST | `/pl/v1/subscribe` | `email-leads.php` | Email capture with lead scoring |
| GET | `/pl/v1/unsubscribe` | `email-leads.php` | Email unsubscribe |
| GET | `/pl/v1/home-posts` | `functions.php` | Homepage load more |
| GET | `/pl/v1/category-posts` | `functions.php` | Category page load more |

---

## 11. Email Capture & Lead Scoring (`email-leads.php`)

- Custom DB table: `wp_pl_email_leads_v2`
- Lead scoring: base 10, up to 100 (factors: scroll depth, engagement time, pin saves, poll/quiz participation)
- Auto-tags: `chat-subscriber`, `newsletter`, `returning-visitor`, `pinterest-saver`, `deep-reader`, `high-engagement`
- Server-side geo lookup via ip-api.com
- Admin page: filterable list, bulk actions, CSV export
- Bridge function `pl_chat_capture_email()` for AI chat → leads pipeline

---

## 12. Infinite Scroll (`rest-random-posts.php`)

- Endpoint: `GET /pinlightning/v1/random-posts?per_page=1&exclude=1,2,3`
- Returns full article HTML (hero image, category, title, meta, content)
- Max 3 posts per request
- Sets `$GLOBALS['pinlightning_rest_content']` flag so CDN rewriter and Pinterest attrs work outside `is_singular()` context
- Content filtered through `the_content` (triggers CDN rewriting, engagement breaks, Pinterest buttons)

---

## 13. Known Pitfalls & Gotchas

1. **LiteSpeed/QUIC + 103 Early Hints** — NEVER use `header(..., false, 103)`. Crashes the site.
2. **get_the_excerpt() in filters** — Causes infinite recursion through the_content filters. Always use raw `$post->post_excerpt`.
3. **Merged IntersectionObservers** — Must keep `seenIO` (tracking) and `animIO` (animation) separate. Different thresholds serve different purposes.
4. **aspect-ratio on containers with padding** — CSS spec applies aspect-ratio to border-box. Put it on `<img>` directly.
5. **Critical CSS visual properties** — Don't add colors/backgrounds/shadows to critical.css. They cause CLS when main.css overrides them differently.
6. **CDN image dimensions** — All myquickurl.com images are 1080x1920. Hardcoded in `PINLIGHTNING_CDN_*` constants. Don't try to detect dynamically.
7. **img-resize.php preload URL matching** — Both `performance.php` and `image-handler.php` must use identical URL construction (`PINLIGHTNING_URI . '/img-resize.php?src=' . rawurlencode($path)`).
8. **`assets/css/dist/` is gitignored** — Production falls back to source files. The build step in CI creates dist/ before deploy.
9. **HTML minification blocks streaming** — Gzip handles compression. Don't re-add ob_start buffering for minification.
10. **Tooltipster nuclear dequeue** — Smart Notification plugin re-enqueues after priority 100. We dequeue at `wp_print_styles` priority 9999 and load on first scroll instead.

---

## 14. PageSpeed Target State

- **Goal:** Quad-100 scores (Performance, Accessibility, Best Practices, SEO)
- **Key metrics:** FCP < 1.0s, LCP < 1.5s, SI < 1.5s, TBT = 0ms, CLS = 0
- **Lighthouse never scrolls** — scroll-deferred assets have zero PageSpeed impact
- **Cache-busting:** deploy workflow flushes all `pinlightning_*` transients + object cache

---

## Quick Reference: Key Constants

```php
PINLIGHTNING_VERSION  = '1.0.0'
PINLIGHTNING_DIR      = get_template_directory()
PINLIGHTNING_URI      = get_template_directory_uri()
PINLIGHTNING_CDN_ORIG_W   = 1080
PINLIGHTNING_CDN_ORIG_H   = 1920
PINLIGHTNING_CDN_DISPLAY_W = 720
PINLIGHTNING_CDN_DISPLAY_H = 1280
```

## Quick Reference: Theme Requires

```php
// functions.php loads:
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
