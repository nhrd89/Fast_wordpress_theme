# Audit 1: System Architecture

## Overview

PinLightning is a custom WordPress theme running on 4 sites (cheerlives.com, inspireinlet.com, pulsepathlife.com, tecarticles.com). Three sites use Ad.Plus (GPT) for monetization; tecarticles.com uses Ezoic and loads zero ad engine code.

---

## Two-Layer Ad System (Post Ads — `is_single()` only)

### Layer 1: Initial Viewport Ads (`initial-ads.js`)
- **Boot trigger:** `window.load + 100ms`
- **Purpose:** GPT setup, static in-content slots, sidebar slots, overlay ads, slot refresh
- **Loaded by:** `pinlightning_postload_scripts()` in `functions.php` (line ~270)
- **Guard:** `is_single()` check — never loads on homepages, category, or static pages
- **Ezoic guard:** `pl_is_ezoic_site()` — skips entirely on tecarticles.com

### Layer 2: Smart Dynamic Ads (`smart-ads.js`)
- **Boot trigger:** First user interaction (scroll/click/touch) + 100ms idle
- **Purpose:** Predictive below-fold injection based on scroll behavior
- **Safety net boot:** 15 seconds if no interaction detected
- **Depends on Layer 1:** Waits for `window.__initialAds.ready`, polls with 500ms fallback (30s timeout)

### Page Ads (`page-ads.js`) — Completely Separate System
- **Boot trigger:** `window.load` event
- **Purpose:** Ads on non-post pages (homepage, category, static)
- **Guard:** `!is_single()` in PHP + JS bail-out if `.single-post` or `window.plAds` exists
- **Zero overlap:** Different globals (`plPageAds` vs `plAds`), CSS classes, GPT slot IDs, REST endpoints

---

## Engagement Gate (Layer 2 Deferred Loading)

Layer 2 is invisible to Lighthouse because it loads only after user interaction:

```
DOMContentLoaded
  └─ Attach listeners: scroll (passive, once), click (once), touchstart (passive, once)
      └─ bootOnce() fires on first event
          └─ setTimeout(100ms)
              └─ requestIdleCallback(init) or setTimeout(init, 50ms)
                  └─ init() waits for Layer 1 ready
                      └─ Registers GPT handlers + starts engineLoop (100ms interval)
```

Exception: `initExitIntent()` runs immediately at script load time — does NOT wait for engagement gate. This ensures exit-intent interstitial can fire even if the user never scrolls.

---

## GPT Loading Strategy

### Script Loading
GPT (`securepubads.g.doubleclick.net/tag/js/gpt.js`) is loaded once by Layer 1:

```
header.php → wp_head()
  └─ ad-engine.php: pinlightning_ads_enqueue()
      └─ Outputs GPT script tag with async attribute
      └─ Sets googletag.cmd queue
      └─ Calls enableSingleRequest(), collapseEmptyDivs(), enableServices()
```

### Single Request Architecture (SRA)
- `enableSingleRequest()` is ON — one HTTP request for all initial slots
- Post-initial slots (Layer 2 dynamic, retries) require explicit `googletag.pubads().refresh([slot])` after `display()`
- This was a key fix: without explicit refresh, SRA mode caused zero fills on dynamic slots

### Shared State Between Layers
| Global | Purpose |
|--------|---------|
| `window.__plViewableCount` | Viewable counter incremented by BOTH Layer 1 and Layer 2 |
| `window.__plOverlayStatus` | Object with overlay states (off → pending → empty/filled → viewable) |
| `window.__initialAds` | Layer 1 API: `.ready`, `.onReady()`, `.getExclusionZones()`, `.refreshSlot()`, `.getSlotMap()` |
| `window.SmartAds` | Layer 2 API: `.rescanAnchors()` for next-post auto-loader |
| `window.__plAdTracker` | Event tracker API: `.track()`, `.flush()`, `.sessionId` |
| `window.__plAdDashboard` | Dashboard state updated every 5s by both layers |
| `window.__plNextPostLoader` | Next-post loader API: `.getLoadedIds()`, `.getContainer()` |

---

## LCP-Aware Deferral

### Critical Path (header.php)
```
<head>
  1. LCP preload <link> (fetchpriority="high", imagesrcset, imagesizes) — byte 0
  2. <meta charset>
  3. critical.css inlined (wp_head priority 1, <3KB, layout-only)
  4. main.css inlined (wp_head priority 2, ~2.7KB gzipped)
  5. Homepage CSS inlined (wp_head priority 3, conditional per site)
  6. Ad CDN preconnects (securepubads, pagead2, cdn.ad.plus, tpc)
  7. GA4 tag
  8. CMP tag (Google Ad Manager built-in, via GPT)
```

### Deferred Assets
| Asset | Deferral Method | Trigger |
|-------|----------------|---------|
| `engagement.css` | `<link rel="preload" onload>` | Non-blocking |
| `core.js` | `defer` attribute | DOM parse complete |
| `initial-ads.js` | `window.load + 100ms` | Page fully loaded |
| `smart-ads.js` | First user interaction | Scroll/click/touch |
| `engagement.js` | `defer` | DOM parse complete |
| `scroll-engage.js` | Scroll event | User scrolls |
| `page-ads.js` | `window.load` | Page fully loaded |

---

## Ezoic Guard (`pl_is_ezoic_site()`)

Central domain check in `functions.php` (line ~25):
```php
function pl_is_ezoic_site() {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    return in_array( $host, ['tecarticles.com', 'www.tecarticles.com'], true );
}
```

### Guard Placement (17 locations)
| File | Function / Hook | What it prevents |
|------|----------------|-----------------|
| `functions.php` | `pinlightning_postload_scripts()` | initial-ads.js + smart-ads.js loading |
| `inc/ad-engine.php` | `pinlightning_ads_enqueue()` | plAds config + GPT script |
| `inc/ad-engine.php` | `pl_ad_admin_menu()` | Ad Engine admin menu |
| `inc/ad-system.php` | `pl_inject_initial_ads()` | Content filter ad containers |
| `inc/ad-system.php` | `pl_render_sidebar_ads()` | Sidebar ad HTML |
| `inc/page-ad-engine.php` | `pl_page_ads_enqueue()` | page-ads.js + config |
| `inc/page-ad-engine.php` | `pl_page_ad_category_anchors()` | Category ad anchor divs |
| `inc/page-ad-engine.php` | `pl_page_ad_static_content()` | Static page ad anchors |
| `header.php` | Ad CDN preconnects | preconnect/dns-prefetch tags |
| `category.php` | `.pl-cat-ad-card` CSS + ad cards | Category grid ad interleaving |
| `front-page.php` | `.pl-page-ad-anchor` divs | Homepage ad anchors |
| `template-emerald-editorial.php` | `.pl-page-ad-anchor` divs | Emerald homepage ad anchors |
| `template-coral-breeze.php` | `.pl-page-ad-anchor` divs | Coral homepage ad anchors |

`template-tec-slate.php` has NO ad anchors at all (Ezoic site by design).

---

## Domain Routing (Homepage Templates)

`inc/homepage-templates.php` resolves the homepage template per domain:

| Domain | Template | CSS File |
|--------|----------|----------|
| cheerlives.com | `front-page.php` (default) | None (uses main.css) |
| inspireinlet.com | `template-emerald-editorial.php` | `homepage-emerald.css` |
| pulsepathlife.com | `template-coral-breeze.php` | `homepage-coral.css` |
| tecarticles.com | `template-tec-slate.php` | `homepage-tec-slate.css` |

Customizer override: `pl_homepage_template` setting (auto/default/emerald/coral/tec-slate).
Template applied via `template_include` filter at priority 99.

---

## Script Loading Order (Single Post)

```
1. header.php renders <head>
   - critical.css (inline, p1)
   - main.css (inline, p2)
   - Ad CDN preconnects
   - GPT script tag (async)
   - CMP consent tag

2. wp_enqueue_scripts (priority 1)
   - core.js (defer)
   - engagement.js (defer)

3. Body renders (single.php)
   - Content filtered: WebP wrap (p10) → Pinterest attrs (p20) → CDN rewrite (p25)
     → ad container injection (p30) → Pinterest Save (p90) → engagement breaks (p95)

4. wp_footer
   - plAds config JSON output (priority 97)
   - infinite-scroll.js, scroll-engage.js (priority 98-99)

5. window.load + 100ms
   - initial-ads.js boots → GPT defineSlot for all static slots → SRA display

6. First user interaction (scroll/click/touch)
   - smart-ads.js boots → waits for Layer 1 ready → starts engine loop

7. 15s safety net
   - smart-ads.js boots even without interaction
```

---

## PHP `the_content` Filter Chain

| Priority | Filter | File | Purpose |
|----------|--------|------|---------|
| 10 | `pinlightning_webp_picture_wrap` | image-handler.php | WebP `<picture>` wrapper |
| 20 | `pinlightning_pinterest_content_images` | image-handler.php | Pinterest data attributes |
| 25 | `pinlightning_rewrite_cdn_images` | image-handler.php | CDN image rewriting + srcset |
| 30 | `pl_inject_initial_ads` | ad-system.php | Ad container `<div>` injection |
| 50 | `pl_page_ad_static_content` | page-ad-engine.php | Static page ad anchors |
| 90 | `pl_add_pinterest_save_buttons` | functions.php | Pinterest Save button overlay |
| 95 | `pinlightning_inject_engagement_breaks` | engagement-breaks.php | Polls, quizzes, email captures |

---

## Theme Requires Chain (functions.php)

```php
inc/template-tags.php          // Template helper functions
inc/customizer.php             // Theme Customizer settings
inc/performance.php            // Bloat removal, critical CSS, cache headers
inc/image-handler.php          // Lazy loading, responsive images, CDN rewriting
inc/pinterest-seo.php          // Pinterest SEO optimizations
inc/rest-random-posts.php      // Infinite scroll REST endpoint
inc/ad-engine.php              // Ad settings, ads.txt, CMP tag, script enqueuing
inc/ad-data-recorder.php       // Session beacon + event tracking DB tables
inc/customizer-scroll-engage.php
inc/visitor-tracker.php
inc/ai-chat.php
inc/email-leads.php
inc/contact-messages.php
inc/engagement-config.php
inc/engagement-breaks.php
inc/engagement-customizer.php
inc/homepage-templates.php     // Multi-site homepage routing
inc/page-ad-engine.php         // Page ad settings + enqueue
inc/page-ad-recorder.php       // Page ad REST endpoints
```

Files loaded via `admin_menu` hooks (not `require_once`):
- `inc/ad-system.php` — loaded by ad-engine.php
- `inc/ad-optimizer.php` — loaded by ad-engine.php
- `inc/ad-injection-lab.php` — loaded by ad-engine.php
- `inc/ad-analytics-dashboard.php` — loaded by ad-engine.php
- `inc/ad-live-sessions.php` — loaded by ad-engine.php
- `inc/ad-recommendations.php` — loaded by ad-engine.php
- `inc/ad-snapshot.php` — loaded by ad-engine.php
