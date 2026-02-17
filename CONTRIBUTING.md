# PinLightning Development Guidelines

## Performance Baseline (v1.0 — Feb 12, 2026)

| Metric | Baseline | Budget (never exceed) |
|--------|----------|----------------------|
| Performance | 100 | Min 95 |
| Accessibility | 100 | Min 95 |
| Best Practices | 100 | Min 95 |
| SEO | 100 | Min 95 |
| FCP | 0.9s | Max 1.5s |
| LCP | 1.0s | Max 1.5s |
| TBT | 0ms | Max 50ms |
| CLS | 0 | Max 0.05 |
| SI | 1.3s | Max 2.0s |

## Core Principle: ALL New Features Load AFTER Initial Page Render

The initial page load is sacred. Every new feature MUST load after the site renders. No exceptions.

### Never Do:
- Add render-blocking CSS or JS
- Add external fonts (Google Fonts, Adobe Fonts, etc.)
- Add jQuery or heavy JS libraries
- Add CSS frameworks (Bootstrap, Tailwind CDN, etc.)
- Move hero images to cross-origin CDN
- Add synchronous third-party scripts
- Remove the preload hint for hero images
- Add above-fold content that isn't in critical.css
- Add new CSS to critical.css (unless fixing CLS)
- Add new `<link>` or `<script>` tags without `defer` or `async`
- Add new server-side WP_Query calls that block the critical rendering path
- Add new preconnect/preload hints (unless replacing an existing one)

### Always Do:
- Run `npm run build` before committing
- Test PageSpeed after deploy: `bash scripts/perf-check.sh`
- Add `loading="lazy"` to all non-hero images
- Add `defer` to any new scripts
- Use `requestIdleCallback` for non-critical JS initialization
- Keep new CSS in main.css (async loaded), not critical.css
- Use `display: none` on mobile for desktop-only features
- New images always use `loading="lazy"` and `fetchpriority="low"`

## Template for Adding a New Feature

Every new feature MUST follow this exact pattern:

### PHP (server-rendered features):
```php
// Only enqueue on pages that need it
if (is_single()) {
    wp_enqueue_script('my-feature', get_template_directory_uri() . '/assets/js/my-feature.js', array(), null, true);
}
// Always add defer
add_filter('script_loader_tag', function($tag, $handle) {
    if ($handle === 'my-feature') {
        return str_replace(' src', ' defer src', $tag);
    }
    return $tag;
}, 10, 2);
```

### JS (feature initialization):
```javascript
(function() {
    function init() {
        // Feature code here — runs AFTER page is idle
    }

    // NEVER run on DOMContentLoaded or immediately
    // ALWAYS defer to browser idle time
    if ('requestIdleCallback' in window) {
        requestIdleCallback(init);
    } else {
        setTimeout(init, 200);
    }
})();
```

### CSS:
```css
/* Goes in main.css ONLY — never in critical.css */
/* Mobile-hidden if desktop-only: */
.my-feature { display: none; }
@media (min-width: 1024px) {
    .my-feature { display: block; }
}
```

### REST API (for dynamically loaded content):
```php
// Register endpoint in inc/rest-my-feature.php
register_rest_route('pinlightning/v1', '/my-feature', array(
    'methods' => 'GET',
    'callback' => 'pinlightning_my_feature',
    'permission_callback' => '__return_true',
));
// Include in functions.php:
require_once get_template_directory() . '/inc/rest-my-feature.php';
```

## Performance Checklist for Every Change

Before committing ANY change, verify:

- [ ] No new render-blocking resources added
- [ ] All new JS uses `defer` attribute + `requestIdleCallback` initialization
- [ ] All new CSS is in main.css only (not critical.css)
- [ ] All new images use `loading="lazy"` and `fetchpriority="low"`
- [ ] No new above-fold DOM elements added
- [ ] No new server-side queries in the critical rendering path
- [ ] Mobile features hidden with `display: none` if desktop-only
- [ ] `npm run build` runs without errors
- [ ] After deploy: Mobile PageSpeed Performance still 95+
- [ ] After deploy: TBT still 0ms
- [ ] After deploy: CLS still 0
- [ ] After deploy: LCP under 1.5s
- [ ] Run `bash scripts/perf-check.sh` — all checks pass

## Git Workflow

- `v1.0-baseline` tag = golden baseline (NEVER delete this tag)
- `setup-pinlightning-theme` = production branch
- `develop` = feature development branch
- Create feature branches from `develop`: `git checkout -b feature/my-feature develop`
- Test on `develop` first, then merge to production
- If performance regresses, revert to `v1.0-baseline`: `git revert --no-commit v1.0-baseline..HEAD`

## Architecture Quick Reference

### Image Delivery
| Type | Server | Why |
|------|--------|-----|
| Hero image | Hostinger (same-origin) via img-resize.php | LCP — must be same-origin |
| Content images | Contabo CDN via myquickurl.com/img.php | Offloads bandwidth |
| Card thumbnails | WordPress default uploads | Below fold, lazy loaded |

### CSS Strategy
- **critical.css** — Inlined in `<head>`, layout-only rules for above-fold. DO NOT ADD TO THIS.
- **main.css** — Async loaded via `media="print"` pattern. All visual + new feature styles go here.

### JavaScript
- Zero JS on initial render (TBT = 0ms)
- infinite-scroll.js — deferred, inits via requestIdleCallback
- Any new JS must follow the same pattern

### Key Directories
```
pinlightning/
├── assets/css/critical.css    ← DO NOT MODIFY (unless fixing CLS)
├── assets/css/main.css        ← All new CSS goes here
├── assets/css/dist/           ← Built files (committed, auto-deployed)
├── assets/js/                 ← New JS files go here
├── assets/js/dist/            ← Built files (committed, auto-deployed)
├── inc/                       ← PHP includes (image handler, performance, REST endpoints)
├── scripts/perf-check.sh      ← Run after every deploy
└── performance-budget.json    ← Budget thresholds
```
