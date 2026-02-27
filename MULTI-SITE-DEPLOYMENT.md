# Multi-Site Deployment Guide

## Sites
| Site | Status | GA4 ID |
|------|--------|--------|
| cheerlives.com | Live | G-TD7Z2RMZ1C |
| inspireinlet.com | Deploying | G-TLFCKLVE30 |
| pulsepathlife.com | Deploying | G-1ZRM1FTWRB |

## Shared Configuration (same across all sites)
- InMobi CMP Property ID: M65A7dGLumC_E (auto-detects domain via `window.location.hostname`)
- Ad.Plus Network ID: 21849154601,22953639975
- Theme: PinLightning (pinlightning folder on all sites)

## What's Site-Specific
- **GA4 Measurement ID** — set via Customizer (`pl_ga4_measurement_id`) on each site
- **Brand name** — set via Customizer (`pl_brand_name`) on each site
- **Content** — each site has its own posts, categories, images

## What's Dynamic (no config needed)
- **SLOT_PATH** — reads from `plAds.slotPrefix` (Ad Engine settings page)
- **Video player C_WEBSITE** — uses `window.location.hostname`
- **InMobi CMP** — uses `window.location.hostname`
- **CDN proxy** — `cdn/img.php` allows all 3 domains

## Post-Deployment Checklist (per site)

### WordPress Admin Setup
- [ ] Activate PinLightning theme (Appearance → Themes)
- [ ] Go to Customizer → set `Brand Name` for the site
- [ ] Go to Customizer → set `GA4 Measurement ID`
- [ ] Go to Ad Engine → Settings
- [ ] Verify Ad.Plus Network ID is correct (22953639975)
- [ ] Verify Slot Prefix is correct (/21849154601,22953639975/)
- [ ] Toggle Enabled → ON
- [ ] Toggle Dummy Mode → OFF (once verified)
- [ ] Paste ads.txt content from Ad.Plus dashboard
- [ ] Save settings

### Verify ads.txt
- [ ] Visit https://[site]/ads.txt — must return Ad.Plus entries

### Required Pages
- [ ] Privacy Policy page exists (required for Ad.Plus compliance)
- [ ] About page exists
- [ ] Contact page exists
- [ ] Homepage set as static front page (Settings → Reading)

### Verification
- [ ] Visit a post → scroll → ads appear
- [ ] F12 Console → no JS errors
- [ ] CMP consent popup appears for EU visitors (test with VPN or check InMobi dashboard)
- [ ] PageSpeed Insights → target 90+ on all metrics
- [ ] Check Ad.Plus dashboard → impressions showing for this domain
- [ ] Check GA4 → realtime shows traffic
- [ ] Live Sessions admin page → sessions appearing
- [ ] Injection Lab → data populating

### Performance Targets
- [ ] PageSpeed Performance: 95+
- [ ] Fill Rate: 80%+ (may start lower, give 48hrs)
- [ ] Viewability: 50%+ (should match cheerlives)
