# PinLightning Engagement Architecture — Claude Code Implementation Guide

## CRITICAL RULES (Read Before Anything Else)

1. **DO NOT break PageSpeed 100/100/100/100.** Every byte you add must be justified. Test with Lighthouse after every major step.
2. **DO NOT duplicate existing PinLightning systems.** The theme already has: `.pl-pin-wrap`, `.pl-pin-btn`, `.pl-section-heading`, `.pl-section-bounce`, `plShimmerSweep`, `pl-achievement-container`, `pl-collectible-pop`, `pl-collect-counter`, `pl-reader-stat`, heart progress indicator, GA4 deferred integration, critical CSS splitting, and `img-resize.php` for hero images.
3. **ALL new CSS/JS must be deferred.** Zero render-blocking resources. Critical engagement CSS (progress bar + item counter only) can go inline. Everything else is deferred.
4. **Skeleton screens and dark mode inject via JS ONLY after real user scroll** — not at page load. See Section 8 for the exact scroll-gate pattern.
5. **The scroll character avatar already exists on the live site** — do NOT create a new one. Only add the speech bubble message system that wires to the existing character.
6. **Deployment is via GitHub Actions CI/CD to Hostinger.** All files go into the theme directory at the correct paths.

---

## Project Context

- **Site:** cheerlives.com (fashion/lifestyle blog, primary traffic from Pinterest)
- **Theme:** PinLightning (custom WordPress theme, located in `wp-content/themes/pinlightning/`)
- **Hosting:** Hostinger + Contabo CDN (myquickurl.com)
- **Image delivery:** Hero images from local `img-resize.php` on Hostinger, content images from CDN with srcset (240w/360w/480w/665w)
- **Current PageSpeed:** 100/100/100/100 — this MUST be maintained
- **Post structure:** Listicle format with numbered H2 headings (`#1 Title`, `#2 Title`, etc.)

---

## Files To Create/Modify

### New Files:
```
pinlightning/
├── css/
│   └── engagement.css          ← ALL engagement styles (deferred load)
├── js/
│   └── engagement.js           ← ALL engagement JS (defer attribute)
├── inc/
│   ├── engagement-breaks.php   ← Content filter: injects breaks between H2s
│   ├── engagement-config.php   ← Per-category configuration arrays
│   └── engagement-customizer.php ← WordPress Customizer controls
```

### Modified Files:
```
pinlightning/
├── functions.php               ← Register/enqueue engagement assets + filters
├── single.php                  ← Add fixed UI element markup (progress bar, counter, etc.)
├── css/
│   └── critical.css            ← Add ONLY progress bar + item counter (6 lines)
```

---

## STEP 1: Create `css/engagement.css` (Deferred Stylesheet)

This file contains ALL engagement styles EXCEPT the progress bar and item counter (those go in critical.css for above-fold rendering).

**Load method:** `<link rel="preload" href="engagement.css" as="style" onload="this.onload=null;this.rel='stylesheet'">` — this is how PinLightning already handles deferred CSS.

Extract ALL the CSS from the prototype file `cheerlives-42-techniques-prototype-v2.html` between the `<style>` tags, but with these modifications:

### What to INCLUDE in engagement.css:
Every `.eb-*` class from the prototype EXCEPT:
- `.eb-progress` / `.eb-progress-fill` (goes in critical.css)
- `.eb-counter` / `.eb-counter .num` / `.eb-counter.show` / `.eb-counter-live` (goes in critical.css)

### What to EXCLUDE (already exists in theme):
- `.pl-pin-wrap` — already defined (just confirm `position: relative` is set)
- `.pl-pin-btn` and related — already defined
- `.site-header`, `.single-article`, `.single-content`, etc. — already defined

### Exact CSS to include (copy from prototype, organized by system):

```css
/* === engagement.css === */
/* Loaded via preload+onload swap — zero render blocking */

/* --- Jump Pills --- */
.eb-pills { ... }         /* line 49-54 from prototype */
.eb-pill { ... }
.eb-pill:hover { ... }
.eb-pill.active { ... }

/* --- Live Activity (desktop only) --- */
.eb-live { ... }          /* line 56-59 */
.eb-live.show { ... }
.eb-live-dot { ... }
@keyframes ebLivePulse { ... }

/* --- TOC Teaser --- */
.eb-toc { ... }           /* line 62-67 */
.eb-toc-label { ... }
.eb-toc-img { ... }
.eb-toc-img:hover { ... }

/* --- Scroll Reveal --- */
.eb-item { ... }          /* line 70-72 — opacity:0, translateY(30px) */
.eb-item.eb-visible { ... }

/* --- Heading Bounce --- */
.eb-heading { ... }       /* line 75-77 */
.eb-item.eb-visible .eb-heading { ... }

/* --- Image Hover Lift --- */
.eb-img-wrap img { ... }  /* line 80-81 */
.eb-img-wrap:hover img { ... }

/* --- Ken Burns --- */
@keyframes ebKenBurns { ... }  /* line 84-86 */
.eb-item.eb-visible .eb-img-wrap img { ... }
.eb-item.eb-visible .eb-img-wrap:hover img { ... }

/* --- Shimmer Sweep --- */
@keyframes ebShimmer { ... }   /* line 89-91 */
.eb-item.eb-visible .eb-img-wrap::after { ... }

/* --- Trending Badge --- */
.eb-trending-badge { ... }     /* line 94-96 */
@keyframes ebTrendPulse { ... }

/* --- Save Count Badge --- */
.eb-save-count { ... }         /* line 99 */

/* --- Favorite Heart --- */
.eb-fav-heart { ... }          /* line 102-106 */
.eb-fav-heart:hover { ... }
.eb-fav-heart.faved { ... }
@keyframes ebHeartPop { ... }

/* --- Hidden Collectibles --- */
.eb-collectible { ... }        /* line 109-112 */
.eb-collectible:hover { ... }
.eb-collectible.collected { ... }

/* --- Collectible Counter --- */
.eb-collect-counter { ... }    /* line 115-117 */
.eb-collect-counter.show { ... }

/* --- Reveal Block (blur gate) --- */
.eb-reveal-blur img { ... }    /* line 120-125 */
.eb-reveal-blur.revealed img { ... }
.eb-reveal-overlay { ... }
.eb-reveal-overlay span { ... }
.eb-reveal-blur.revealed .eb-reveal-overlay { ... }

/* --- Engagement Breaks (shared base) --- */
.eb-break { ... }              /* line 128-130 */
.eb-break-badge { ... }
.eb-break-title { ... }

/* --- Poll --- */
.eb-poll { ... }               /* line 133-143 */
/* all .eb-poll-* classes */

/* --- Quiz --- */
.eb-quiz { ... }               /* line 146-159 */
/* all .eb-quiz-* classes */

/* --- Fact Break --- */
.eb-fact { ... }               /* line 162-164 */

/* --- Related Teaser Card --- */
.eb-teaser { ... }             /* line 167-174 */
/* all .eb-teaser-* classes */

/* --- Email Capture --- */
.eb-email { ... }              /* line 177-187 */
/* all .eb-email-* classes */

/* --- Stylist Tip --- */
.eb-stylist { ... }            /* line 190-194 */

/* --- Curiosity Teasers --- */
.eb-curiosity { ... }          /* line 197-199 */
.eb-curiosity.eb-visible { ... }

/* --- Character Speech Bubble (attaches to existing character) --- */
.eb-char-bubble { ... }        /* line 189-190 */
.eb-char-bubble.show { ... }

/* --- Milestone Popup --- */
.eb-milestone { ... }          /* line 200-204 */
/* all .eb-milestone-* classes */

/* --- Achievement Badge --- */
.eb-achievement { ... }        /* line 207-212 */
/* all .eb-achievement-* classes */

/* --- Next Article Bar --- */
.eb-next-bar { ... }           /* line 215-221 */
/* all .eb-next-bar-* classes */

/* --- Favorites Summary --- */
.eb-fav-summary { ... }        /* line 224-230 */
/* all .eb-fav-* classes */

/* --- AI Tip Unlock --- */
.eb-ai-tip { ... }             /* line 233-238 */
.eb-ai-tip.unlocked { ... }

/* --- Exit Intent --- */
.eb-exit { ... }               /* line 241-247 */

/* --- Reading Streak --- */
.eb-streak { ... }             /* line 250-252 */

/* --- Speed Warning --- */
.eb-speed-warn { ... }         /* line 255-257 */

/* --- Responsive (≤768px) --- */
@media(max-width:768px) {
  .eb-pills { display: none }
  .eb-live { display: none }
  .eb-counter-live { display: inline-flex }
  .eb-quiz-grid { grid-template-columns: repeat(3,1fr); gap: 6px }
  .eb-email-form { flex-direction: column }
  .eb-next-bar { padding: 6px 12px }
}

/* --- Reduced Motion --- */
@media(prefers-reduced-motion:reduce) {
  * { animation: none !important; transition: none !important }
}
```

**IMPORTANT:** Copy the EXACT property values from the prototype file. The line numbers above are approximate references to the v2 prototype. Do not invent new values.

---

## STEP 2: Add Critical CSS (Inline in `<head>`)

Add ONLY these rules to the existing `critical.css` (or inline `<style>` block in `header.php`). These are the only engagement styles needed before first paint:

```css
/* Engagement: progress bar + item counter (above fold) */
.eb-progress{position:fixed;top:50px;left:0;width:100%;height:3px;z-index:999;background:transparent}
.eb-progress-fill{height:100%;width:0%;background:linear-gradient(90deg,#e84393,#6c5ce7);transition:width .3s ease-out;border-radius:0 2px 2px 0}
.eb-counter{position:fixed;top:60px;left:50%;transform:translateX(-50%);background:#fff;border:1px solid #eee;border-radius:20px;padding:4px 14px;font-size:12px;font-weight:600;color:#666;z-index:998;box-shadow:0 2px 8px #0001;opacity:0;transition:opacity .3s}
.eb-counter.show{opacity:1}
.eb-counter .num{color:#e84393;font-weight:800}
.eb-counter-live{display:none;align-items:center;gap:3px;color:#999;font-weight:500}
```

This is 7 lines / ~600 bytes — negligible impact on FCP.

---

## STEP 3: Create `js/engagement.js` (Deferred Script)

Extract the entire `<script>` block from the prototype (lines ~803–1377 in v2), wrap it as a standalone file.

**Load method:** `<script defer src="engagement.js"></script>`

### Key modifications for production:

#### 3a. Replace hardcoded data with `wp_localize_script` config:

At the top of the IIFE, replace:
```js
const TOTAL = 15;
const itemTitles = ["Gloss Boss", ...];
const itemPins = ["https://myquickurl.com/...", ...];
const trendingIdx = [4, 5, 14];
const charMsgs = [{...}, ...];
```

With:
```js
const C = window.ebConfig || {};
const TOTAL = C.totalItems || 15;
const itemTitles = C.itemTitles || [];
const itemPins = C.itemPins || [];
const trendingIdx = C.trending || [];
const charMsgs = C.charMsgs || [];
const CATEGORY = C.category || 'hairstyle';
const POST_ID = C.postId || 0;
const NEXT_POST = C.nextPost || { title: '', url: '', img: '' };
const AI_TIP_TEXT = C.aiTip || '';
const EMAIL_ENDPOINT = C.emailEndpoint || '';
```

#### 3b. Replace `onclick` handlers with event delegation:

The prototype uses inline `onclick="ebVote(this)"`, `onclick="ebFav(this,0)"`, etc. In production, use event delegation instead:

```js
// Replace all window.ebVote, window.ebFav, etc. with:
document.addEventListener('click', function(e) {
  const target = e.target.closest('[data-eb-action]');
  if (!target) return;
  
  const action = target.dataset.ebAction;
  
  if (action === 'vote') handleVote(target);
  else if (action === 'quiz') handleQuiz(target);
  else if (action === 'fav') handleFav(target);
  else if (action === 'collect') handleCollect(target);
  else if (action === 'reveal') handleReveal(target);
  else if (action === 'jump') handleJump(target);
  else if (action === 'email') handleEmail(target);
});
```

Then change the HTML output (in engagement-breaks.php) to use `data-eb-action="vote"` instead of `onclick="ebVote(this)"`.

#### 3c. Replace `$charBubble` reference:

The existing character on the live site has its own DOM element. In the JS, find the character's existing bubble element:

```js
// Find existing character's bubble, or create one if not present
const $charBubble = document.querySelector('.pl-char-bubble') 
  || document.querySelector('[data-eb-char-bubble]');
```

Adjust the selector to match whatever class your existing character's speech bubble uses. If the existing character doesn't have a speech bubble element yet, the JS should create one and append it as a sibling to the existing character avatar element.

#### 3d. Wire GA4 events through existing deferred gtag:

The prototype doesn't track to GA4. Add this helper and call it at key interaction points:

```js
function ebTrack(event, params) {
  if (typeof gtag === 'function') {
    gtag('event', 'eb_' + event, {
      ...params,
      post_id: POST_ID,
      category: CATEGORY
    });
  }
}

// Call at: item_seen, poll_vote, quiz_answer, fav_toggle, collectible_found,
// email_capture, ai_tip_unlocked, milestone_hit, achievement_earned,
// blur_revealed, exit_intent_shown, next_article_clicked, speed_warning
```

#### 3e. Keep skeleton + dark mode scroll-gate pattern exactly as prototype:

The prototype v2 has the exact implementation at lines 1085–1345. Copy it verbatim. The key architecture:

1. `setTimeout(200ms)` → registers real scroll/pointerdown/touchstart listeners (skips the synthetic 100ms dispatch)
2. `onFirstRealScroll()` → fires once → `requestIdleCallback` → `initDarkMode()` + `initSkeletons()`
3. `initDarkMode()` checks `prefers-color-scheme: dark`, injects `<style>` if matched
4. `initSkeletons()` injects skeleton CSS + creates IntersectionObserver with `rootMargin: '600px 0px'`

#### 3f. Email capture — send to actual endpoint:

Replace the prototype's fake success with a real fetch:

```js
function handleEmail(btn) {
  const wrap = btn.closest('.eb-email') || btn.closest('.eb-exit');
  const input = wrap.querySelector('.eb-email-input');
  if (!input || !input.value.includes('@')) return;
  
  if (EMAIL_ENDPOINT) {
    fetch(EMAIL_ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        email: input.value.trim(),
        post_id: POST_ID,
        favorites: Array.from(favItems),
        source: wrap.classList.contains('eb-exit') ? 'exit_intent' : 'inline'
      })
    }).catch(() => {});
  }
  
  wrap.classList.add('captured');
  wrap.querySelector('.eb-email-inner').innerHTML = 
    '<div class="eb-email-success">🎉 Check your inbox! Your bonus looks are on the way.</div>';
  ebTrack('email_capture', { source: wrap.dataset.eb || 'inline', fav_count: favItems.size });
}
```

---

## STEP 4: Create `inc/engagement-config.php`

This file defines per-category engagement break configurations. The prototype hardcodes everything for the "hairstyle" category. Production needs different break types per category.

```php
<?php
/**
 * Engagement break configurations per category.
 * 
 * 'position' = inject after which H2 item number
 * 'type' = break template to render
 * 'data' = category-specific content for the break
 */
function pl_get_engagement_config($category_slug) {
    
    $configs = [
        'hairstyle' => [
            'breaks' => [
                ['position' => 3,  'type' => 'poll'],
                ['position' => 4,  'type' => 'curiosity', 'text' => 'The next cut literally made {saves} people hit save this week...'],
                ['position' => 5,  'type' => 'quiz'],
                ['position' => 6,  'type' => 'related'],
                ['position' => 8,  'type' => 'curiosity', 'text' => 'Wait till you see #{next} — it\'s the one stylists are raving about'],
                ['position' => 9,  'type' => 'fact', 'text' => 'Face-framing layers can make you look up to 5 years younger by drawing attention to your eyes and cheekbones.'],
                ['position' => 11, 'type' => 'email'],
                ['position' => 12, 'type' => 'curiosity', 'text' => 'You\'re so close to the end... but #{next} might just steal the whole show'],
                ['position' => 13, 'type' => 'stylist_tip', 'text' => 'When showing your stylist a photo, point out the specific details you love — the layer length, the face-framing angle, or the volume at the crown.'],
            ],
            'poll_options' => [
                ['emoji' => '✨', 'label' => 'Sleek & Polished',  'pct' => 32],
                ['emoji' => '🌊', 'label' => 'Soft & Wavy',       'pct' => 41],
                ['emoji' => '🔥', 'label' => 'Bold & Edgy',       'pct' => 18],
                ['emoji' => '👑', 'label' => 'Classic Elegance',   'pct' => 9],
            ],
            'quiz_styles' => [
                ['slug' => 'boho', 'label' => 'Boho Chic',    'result' => 'Boho Chic ✨'],
                ['slug' => 'glam', 'label' => 'Classic Glam',  'result' => 'Classic Glam 👑'],
                ['slug' => 'edge', 'label' => 'Modern Edge',   'result' => 'Modern Edge 🔥'],
            ],
            'char_messages' => [
                ['at' => 1,  'msg' => 'Ooh this one\'s gorgeous! 😍'],
                ['at' => 3,  'msg' => 'Getting better! Keep scrolling...'],
                ['at' => 5,  'msg' => 'Wait till you see #9 👀'],
                ['at' => 7,  'msg' => 'You have great taste!'],
                ['at' => 9,  'msg' => 'That one gets saved the most!'],
                ['at' => 11, 'msg' => 'Almost there... the best is coming!'],
                ['at' => 13, 'msg' => 'Save your faves before you go! 💕'],
                ['at' => 15, 'msg' => 'You made it! 🎉 Style Expert!'],
            ],
            'email_hook' => [
                'title' => 'Get 5 Exclusive Bonus Cuts',
                'desc'  => 'Not on this list! Plus your free "What to Tell Your Stylist" cheat sheet.',
                'note'  => 'Join 12,400+ style-savvy readers. Unsubscribe anytime.',
            ],
            'blur_item' => 8,
            'trending_count' => 3,
            'collectible_count' => 5,
            'collectible_emojis' => ['💎', '✨', '🦋', '💫', '🌸'],
            'toc_preview_count' => 5,
        ],
        
        'nail-art' => [
            'breaks' => [
                ['position' => 3,  'type' => 'poll'],
                ['position' => 5,  'type' => 'quiz'],
                ['position' => 7,  'type' => 'fact', 'text' => 'Nail art has been around for over 5,000 years — ancient Babylonians used gold and silver to color their nails!'],
                ['position' => 9,  'type' => 'related'],
                ['position' => 11, 'type' => 'email'],
                ['position' => 13, 'type' => 'stylist_tip', 'text' => 'Apply a base coat before any nail art to prevent staining and make your design last 2-3x longer.'],
            ],
            'poll_options' => [
                ['emoji' => '🌸', 'label' => 'Minimalist',   'pct' => 28],
                ['emoji' => '💅', 'label' => 'Full Glam',     'pct' => 35],
                ['emoji' => '🎨', 'label' => 'Abstract Art',  'pct' => 22],
                ['emoji' => '✨', 'label' => 'Sparkle & Gems','pct' => 15],
            ],
            'email_hook' => [
                'title' => 'Get 5 Hidden Nail Art Designs',
                'desc'  => 'Exclusive designs + our step-by-step difficulty guide.',
                'note'  => 'Join 12,400+ nail art lovers. Unsubscribe anytime.',
            ],
            /* ... fill in remaining fields same structure ... */
        ],
        
        'home-decor' => [
            /* budget-estimate breaks instead of stylist tips */
            /* ... fill in per category ... */
        ],
        
        'architecture' => [
            /* ... fill in per category ... */
        ],
    ];
    
    return $configs[$category_slug] ?? $configs['hairstyle']; // fallback
}
```

---

## STEP 5: Create `inc/engagement-breaks.php` (Content Filter)

This is the most important file. It's a WordPress content filter that:
1. Detects if the post is a listicle (has numbered H2s)
2. Counts the total items
3. Injects engagement break HTML between H2s at configured positions
4. Adds `data-eb-*` attributes and engagement overlay elements to each item
5. Adds fixed UI elements (prepended/appended to content)

```php
<?php
/**
 * PinLightning Engagement Breaks — Content Filter
 * 
 * Hooks into the_content to auto-inject engagement architecture
 * into listicle posts. Only activates on single posts with
 * numbered H2 headings.
 */

if (!defined('ABSPATH')) exit;

/**
 * Main content filter
 */
function pl_engagement_filter($content) {
    // Only on single posts, not admin, not REST API, not feed
    if (!is_single() || is_admin() || wp_doing_ajax() || is_feed()) {
        return $content;
    }
    
    // Check if post has listicle structure (numbered H2s)
    if (!preg_match('/<h2[^>]*>.*?#\d+/i', $content)) {
        return $content;
    }
    
    // Get category
    $categories = get_the_category();
    $cat_slug = !empty($categories) ? $categories[0]->slug : 'hairstyle';
    
    // Load config
    $config = pl_get_engagement_config($cat_slug);
    
    // Split content at H2 boundaries
    // Pattern: Split BEFORE each <h2> that contains a number pattern
    $parts = preg_split('/(?=<h2[^>]*>.*?#\d+)/i', $content, -1, PREG_SPLIT_NO_EMPTY);
    
    if (count($parts) < 3) {
        return $content; // Too few items, skip
    }
    
    // Count total items (H2s with numbers)
    preg_match_all('/<h2[^>]*>.*?#(\d+)/i', $content, $h2_matches);
    $total_items = count($h2_matches[0]);
    
    if ($total_items < 5) {
        return $content; // Not enough items for engagement system
    }
    
    // Generate seeded random indices for trending + collectibles
    $post_id = get_the_ID();
    $date_seed = date('Ymd');
    $seed = crc32($post_id . $date_seed);
    mt_srand($seed);
    
    // Trending: pick N random items
    $trending_count = $config['trending_count'] ?? 3;
    $trending_indices = [];
    $available = range(1, $total_items);
    shuffle($available);
    $trending_indices = array_slice($available, 0, $trending_count);
    
    // Collectibles: pick N random items (different from trending)
    $collectible_count = $config['collectible_count'] ?? 5;
    $remaining = array_diff(range(1, $total_items), $trending_indices);
    shuffle($remaining);
    $collectible_indices = array_slice(array_values($remaining), 0, $collectible_count);
    $collectible_emojis = $config['collectible_emojis'] ?? ['💎', '✨', '🦋', '💫', '🌸'];
    
    // Blur gate item
    $blur_item = $config['blur_item'] ?? 0;
    
    // Save count generation (seeded, weighted: earlier items get higher counts)
    $save_counts = [];
    mt_srand($seed + 1);
    for ($i = 1; $i <= $total_items; $i++) {
        $weight = 1 - (($i - 1) / $total_items) * 0.6;
        $save_counts[$i] = round((mt_rand(800, 4800)) * $weight);
    }
    
    // Build break map: position => HTML
    $break_map = [];
    foreach ($config['breaks'] as $break) {
        $pos = $break['position'];
        $break_map[$pos] = pl_render_break($break, $config, $post_id, $total_items);
    }
    
    // Process each part (item)
    $item_index = 0;
    $output_parts = [];
    
    // TOC teaser images (collect from first N items)
    $toc_images = [];
    $toc_count = $config['toc_preview_count'] ?? 5;
    
    foreach ($parts as $i => $part) {
        // Check if this part starts with an H2 (is a listicle item)
        if (preg_match('/<h2[^>]*>.*?#(\d+)/i', $part)) {
            $item_index++;
            
            // Collect TOC images from early items
            if (count($toc_images) < $toc_count) {
                if (preg_match('/data-pin-media="([^"]+)"/', $part, $pin_match)) {
                    $toc_images[] = $pin_match[1];
                }
            }
            
            // Wrap item in .eb-item container with data attributes
            $part = pl_wrap_item($part, $item_index, [
                'trending' => in_array($item_index, $trending_indices),
                'collectible' => in_array($item_index, $collectible_indices) 
                    ? $collectible_emojis[array_search($item_index, $collectible_indices) % count($collectible_emojis)] 
                    : false,
                'save_count' => $save_counts[$item_index] ?? 0,
                'blur' => ($item_index === $blur_item),
            ]);
            
            $output_parts[] = $part;
            
            // Inject engagement break AFTER this item if configured
            if (isset($break_map[$item_index])) {
                $output_parts[] = $break_map[$item_index];
            }
            
        } else {
            // Intro or non-item content — pass through
            $output_parts[] = $part;
        }
    }
    
    $content = implode("\n", $output_parts);
    
    // Prepend: TOC teaser (into intro section)
    $toc_html = pl_render_toc_teaser($toc_images);
    // Insert TOC before the first H2
    $content = preg_replace(
        '/(?=<(?:div class="eb-item"|h2)[^>]*>.*?#1)/i',
        $toc_html,
        $content,
        1
    );
    
    // Append: AI tip placeholder + favorites summary
    $content .= pl_render_ai_tip();
    $content .= pl_render_fav_summary();
    
    return $content;
}
add_filter('the_content', 'pl_engagement_filter', 20);


/**
 * Wrap a listicle item with engagement overlays
 */
function pl_wrap_item($html, $index, $options) {
    $trending = $options['trending'];
    $collectible = $options['collectible'];
    $save_count = $options['save_count'];
    $blur = $options['blur'];
    
    $formatted_count = $save_count >= 1000 
        ? round($save_count / 1000, 1) . 'K' 
        : $save_count;
    
    // Build overlay HTML to inject inside .pl-pin-wrap
    $overlays = '';
    
    if ($trending) {
        $overlays .= '<span class="eb-trending-badge">🔥 Trending</span>';
    }
    
    $overlays .= '<span class="eb-save-count">📌 ' . esc_html($formatted_count) . ' saves</span>';
    $overlays .= '<button class="eb-fav-heart" data-eb-action="fav" data-idx="' . ($index - 1) . '" aria-label="Save to favorites">♡</button>';
    
    if ($collectible) {
        $overlays .= '<span class="eb-collectible" data-eb-action="collect" data-emoji="' . esc_attr($collectible) . '">' . $collectible . '</span>';
    }
    
    if ($blur) {
        $overlays .= '<div class="eb-reveal-overlay" data-eb-action="reveal"><span>✨ Tap to reveal this look</span></div>';
    }
    
    // Inject overlays inside .pl-pin-wrap (before the <img>)
    $html = preg_replace(
        '/(<div class="pl-pin-wrap[^"]*">)/',
        '$1' . $overlays,
        $html,
        1
    );
    
    // Add blur class if needed
    if ($blur) {
        $html = str_replace('pl-pin-wrap', 'pl-pin-wrap eb-reveal-blur', $html);
    }
    
    // Add .eb-img-wrap to pin-wrap
    $html = preg_replace(
        '/class="pl-pin-wrap([^"]*)"/i',
        'class="pl-pin-wrap$1 eb-img-wrap"',
        $html,
        1
    );
    
    // Wrap the entire item block
    // Add .eb-heading to the H2
    $html = preg_replace(
        '/<h2([^>]*)class="([^"]*)"/i',
        '<h2$1class="$2 eb-heading"',
        $html,
        1
    );
    // If H2 has no class attr
    $html = preg_replace(
        '/<h2(?![^>]*class=)([^>]*)>/i',
        '<h2 class="eb-heading"$1>',
        $html,
        1
    );
    
    // Wrap in .eb-item div
    $html = '<div class="eb-item" data-item="' . $index . '" id="item-' . $index . '">' 
          . $html 
          . '</div>';
    
    return $html;
}


/**
 * Render an engagement break
 */
function pl_render_break($break, $config, $post_id, $total_items) {
    $type = $break['type'];
    $output = '';
    
    switch ($type) {
        case 'poll':
            $options_html = '';
            foreach ($config['poll_options'] as $opt) {
                $options_html .= sprintf(
                    '<button class="eb-poll-opt" data-eb-action="vote" data-vote="%s">'
                    . '<span class="eb-poll-emoji">%s</span>%s'
                    . '<span class="eb-poll-bar"><span class="eb-poll-fill" data-pct="%d"></span></span>'
                    . '</button>',
                    esc_attr(sanitize_title($opt['label'])),
                    $opt['emoji'],
                    esc_html($opt['label']),
                    $opt['pct']
                );
            }
            $output = '<div class="eb-break eb-poll" data-eb="poll">'
                . '<div class="eb-break-badge">Quick Poll</div>'
                . '<h3 class="eb-break-title">Which style is calling your name?</h3>'
                . '<div class="eb-poll-options">' . $options_html . '</div>'
                . '<div class="eb-poll-result" style="display:none"><span class="eb-poll-total">2,847 people voted</span></div>'
                . '</div>';
            break;
            
        case 'quiz':
            $quiz_html = '';
            foreach ($config['quiz_styles'] ?? [] as $style) {
                // Use first 3 post images for quiz visual options
                $quiz_html .= sprintf(
                    '<button class="eb-quiz-opt" data-eb-action="quiz" data-style="%s" data-result="%s">'
                    . '<span>%s</span></button>',
                    esc_attr($style['slug']),
                    esc_attr($style['result']),
                    esc_html($style['label'])
                );
            }
            $output = '<div class="eb-break eb-quiz" data-eb="quiz">'
                . '<div class="eb-break-badge">✨ Style Quiz</div>'
                . '<h3 class="eb-break-title">What\'s Your Style Personality?</h3>'
                . '<p class="eb-quiz-sub">Tap the look that speaks to you most:</p>'
                . '<div class="eb-quiz-grid">' . $quiz_html . '</div>'
                . '<div class="eb-quiz-result" style="display:none"></div>'
                . '</div>';
            break;
            
        case 'fact':
            $output = '<div class="eb-break eb-fact" data-eb="fact">'
                . '<div class="eb-fact-icon">💡</div>'
                . '<p class="eb-fact-text">' . esc_html($break['text']) . '</p>'
                . '</div>';
            break;
            
        case 'curiosity':
            $text = $break['text'];
            $next = ($break['position'] + 1);
            $text = str_replace('{next}', $next, $text);
            $text = str_replace('{saves}', number_format(mt_rand(3000, 5000) / 1000, 1) . 'K', $text);
            $output = '<div class="eb-curiosity"><span class="eb-curiosity-icon">👀</span> ' 
                    . esc_html($text) . '</div>';
            break;
            
        case 'email':
            $hook = $config['email_hook'] ?? [];
            $output = '<div class="eb-break eb-email" data-eb="email">'
                . '<div class="eb-email-inner">'
                . '<div class="eb-email-icon">💌</div>'
                . '<h3 class="eb-email-title">' . esc_html($hook['title'] ?? 'Get Exclusive Bonus Content') . '</h3>'
                . '<p class="eb-email-desc">' . esc_html($hook['desc'] ?? '') . '</p>'
                . '<div class="eb-email-form">'
                . '<input type="email" class="eb-email-input" placeholder="Your best email..." aria-label="Email">'
                . '<button class="eb-email-btn" data-eb-action="email">Send Me the Looks →</button>'
                . '</div>'
                . '<span class="eb-email-note">' . esc_html($hook['note'] ?? '') . '</span>'
                . '</div></div>';
            break;
            
        case 'related':
            $related = pl_get_related_post($post_id);
            if ($related) {
                $output = '<div class="eb-break eb-teaser" data-eb="teaser">'
                    . '<div class="eb-teaser-badge">You\'ll Also Love</div>'
                    . '<a class="eb-teaser-card" href="' . esc_url($related['url']) . '">'
                    . '<img class="eb-teaser-img" src="' . esc_url($related['img']) . '" alt="" loading="lazy" width="120" height="63">'
                    . '<div class="eb-teaser-body">'
                    . '<span class="eb-teaser-title">' . esc_html($related['title']) . '</span>'
                    . '<span class="eb-teaser-meta">67% of readers who liked this also read this →</span>'
                    . '</div></a></div>';
            }
            break;
            
        case 'stylist_tip':
            $output = '<div class="eb-break eb-stylist" data-eb="stylist-tip">'
                . '<div class="eb-stylist-icon">💇‍♀️</div>'
                . '<div class="eb-stylist-body">'
                . '<span class="eb-stylist-badge">Stylist Tip</span>'
                . '<p class="eb-stylist-text">' . esc_html($break['text']) . '</p>'
                . '</div></div>';
            break;
    }
    
    return $output;
}


/**
 * TOC Teaser
 */
function pl_render_toc_teaser($images) {
    if (empty($images)) return '';
    $html = '<div class="eb-toc"><span class="eb-toc-label">Preview what\'s ahead →</span>';
    foreach ($images as $i => $url) {
        $html .= '<img src="' . esc_url($url) . '" alt="Preview ' . ($i + 1) . '" loading="lazy" class="eb-toc-img" data-eb-action="jump" data-jump="' . ($i + 1) . '">';
    }
    $html .= '</div>';
    return $html;
}


/**
 * AI Tip placeholder (unlocked by JS based on engagement signals)
 */
function pl_render_ai_tip() {
    return '<div class="eb-ai-tip" id="ebAiTip">'
        . '<div class="eb-ai-tip-badge">🤖 Personalized Tip — Unlocked!</div>'
        . '<p class="eb-ai-tip-text" id="ebAiTipText"></p>'
        . '</div>';
}


/**
 * Favorites summary grid
 */
function pl_render_fav_summary() {
    return '<div class="eb-fav-summary" id="ebFavSummary">'
        . '<h3>💕 Your Favorites Collection</h3>'
        . '<div class="eb-fav-grid" id="ebFavGrid"></div>'
        . '<button class="eb-fav-pin-all" data-eb-action="pin-all">📌 Save All to Pinterest</button>'
        . '</div>';
}


/**
 * Get a related post from same category
 */
function pl_get_related_post($post_id) {
    $cats = wp_get_post_categories($post_id);
    if (empty($cats)) return null;
    
    $related = get_posts([
        'category__in'   => $cats,
        'post__not_in'   => [$post_id],
        'posts_per_page' => 1,
        'orderby'        => 'rand',
        'fields'         => 'ids',
    ]);
    
    if (empty($related)) return null;
    
    $rid = $related[0];
    $thumb = get_the_post_thumbnail_url($rid, 'medium');
    
    return [
        'title' => get_the_title($rid),
        'url'   => get_permalink($rid),
        'img'   => $thumb ?: '',
    ];
}
```

---

## STEP 6: Modify `single.php` — Add Fixed UI Elements

Add the fixed UI element markup. These are the always-present containers that JS populates. Add them just after `<body>` or at the top of your single post template, BEFORE the content:

```php
<?php if (is_single() && pl_is_listicle_post()): ?>

<!-- Engagement: Fixed UI -->
<div class="eb-progress"><div class="eb-progress-fill" id="ebProgressFill"></div></div>

<div class="eb-counter" id="ebCounter">
  <span class="num" id="ebCountNum">0</span> / <?php echo pl_count_listicle_items(); ?> ideas seen
  <span class="eb-counter-live" id="ebCounterLive"> · <span class="eb-live-dot"></span> <span id="ebCounterLiveNum">47</span> reading</span>
</div>

<div class="eb-live" id="ebLive">
  <span class="eb-live-dot"></span>
  <span id="ebLiveCount">47</span> people reading now
</div>

<div class="eb-pills" id="ebPills">
  <?php for ($i = 1; $i <= pl_count_listicle_items(); $i++): ?>
    <button class="eb-pill" data-eb-action="jump" data-jump="<?php echo $i; ?>" data-item="<?php echo $i; ?>"><?php echo $i; ?></button>
  <?php endfor; ?>
</div>

<div class="eb-streak" id="ebStreak"></div>
<div class="eb-speed-warn" id="ebSpeedWarn">Slow down! You're about to miss the best one 👀</div>

<div class="eb-milestone" id="ebMilestone">
  <div class="eb-milestone-emoji" id="ebMilestoneEmoji"></div>
  <div class="eb-milestone-text" id="ebMilestoneText"></div>
  <div class="eb-milestone-sub" id="ebMilestoneSub"></div>
</div>

<div class="eb-achievement" id="ebAchievement">
  <span class="eb-achievement-icon">✨</span>
  <div>
    <div class="eb-achievement-title">Style Expert</div>
    <div class="eb-achievement-sub">You've seen all <?php echo pl_count_listicle_items(); ?> looks!</div>
  </div>
</div>

<div class="eb-collect-counter" id="ebCollectCounter">
  💎 <span id="ebCollectNum">0</span>/5
</div>

<?php 
  $next = pl_get_next_post_data();
  if ($next): 
?>
<div class="eb-next-bar" id="ebNextBar">
  <?php if ($next['img']): ?>
    <img class="eb-next-bar-img" src="<?php echo esc_url($next['img']); ?>" alt="" width="50" height="50" loading="lazy">
  <?php endif; ?>
  <span class="eb-next-bar-title"><?php echo esc_html($next['title']); ?></span>
  <button class="eb-next-bar-btn" data-eb-action="next" data-url="<?php echo esc_url($next['url']); ?>">Read Next →</button>
</div>
<?php endif; ?>

<div class="eb-exit" id="ebExit">
  <div class="eb-exit-text">Wait — you saved <strong id="ebExitFavCount">0</strong> looks! Want us to <strong>email you the full collection</strong>?</div>
  <button class="eb-exit-btn" data-eb-action="email">Yes Please!</button>
  <button class="eb-exit-close" data-eb-action="exit-close">&times;</button>
</div>

<?php endif; ?>
```

---

## STEP 7: Modify `functions.php` — Register & Enqueue

```php
<?php
/**
 * Engagement Architecture — Asset Registration
 */

// Include engagement files
require_once get_template_directory() . '/inc/engagement-config.php';
require_once get_template_directory() . '/inc/engagement-breaks.php';

/**
 * Enqueue engagement assets (only on single listicle posts)
 */
function pl_enqueue_engagement() {
    if (!is_single()) return;
    
    // Check if post is a listicle
    global $post;
    if (!$post || !preg_match('/<h2[^>]*>.*?#\d+/i', $post->post_content)) {
        return;
    }
    
    $theme_uri = get_template_directory_uri();
    $version = filemtime(get_template_directory() . '/css/engagement.css');
    
    // Deferred CSS (preload + onload swap — match existing PinLightning pattern)
    // This is done manually in header.php to use the preload pattern.
    // If PinLightning already has a deferred CSS loader function, use that instead.
    
    // Deferred JS
    wp_enqueue_script(
        'pl-engagement',
        $theme_uri . '/js/engagement.js',
        [], // No dependencies — standalone
        filemtime(get_template_directory() . '/js/engagement.js'),
        true // In footer
    );
    // Add defer attribute
    add_filter('script_loader_tag', function($tag, $handle) {
        if ($handle === 'pl-engagement') {
            return str_replace(' src', ' defer src', $tag);
        }
        return $tag;
    }, 10, 2);
    
    // Pass config to JS
    $post_id = get_the_ID();
    $categories = get_the_category();
    $cat_slug = !empty($categories) ? $categories[0]->slug : 'hairstyle';
    $config = pl_get_engagement_config($cat_slug);
    
    // Count items
    preg_match_all('/<h2[^>]*>.*?#(\d+)/i', $post->post_content, $matches);
    $total = count($matches[0]);
    
    // Extract item titles and pin images
    preg_match_all('/<h2[^>]*>.*?#\d+\s*([^<]+)/i', $post->post_content, $title_matches);
    $titles = array_map('trim', $title_matches[1] ?? []);
    
    preg_match_all('/data-pin-media="([^"]+)"/', $post->post_content, $pin_matches);
    $pins = $pin_matches[1] ?? [];
    
    // Next post
    $next_post = get_adjacent_post(true, '', false); // Next in same category
    $next_data = null;
    if ($next_post) {
        $next_data = [
            'title' => $next_post->post_title,
            'url'   => get_permalink($next_post),
            'img'   => get_the_post_thumbnail_url($next_post, 'thumbnail') ?: '',
        ];
    }
    
    // AI tip text (from post meta, pre-generated by batch script)
    $ai_tip = get_post_meta($post_id, '_eb_ai_tip', true);
    
    wp_localize_script('pl-engagement', 'ebConfig', [
        'postId'        => $post_id,
        'totalItems'    => $total,
        'category'      => $cat_slug,
        'itemTitles'    => $titles,
        'itemPins'      => $pins,
        'trending'      => [], // Computed by PHP in content filter, but JS also needs for pill highlighting
        'charMsgs'      => $config['char_messages'] ?? [],
        'nextPost'      => $next_data,
        'aiTip'         => $ai_tip ?: '',
        'emailEndpoint' => '', // Set your email capture API endpoint here
    ]);
}
add_action('wp_enqueue_scripts', 'pl_enqueue_engagement');


/**
 * Add deferred engagement CSS via preload (match PinLightning pattern)
 * Add this to your existing header.php deferred CSS output section.
 */
function pl_engagement_deferred_css() {
    if (!is_single()) return;
    
    global $post;
    if (!$post || !preg_match('/<h2[^>]*>.*?#\d+/i', $post->post_content)) return;
    
    $css_url = get_template_directory_uri() . '/css/engagement.css?v=' . filemtime(get_template_directory() . '/css/engagement.css');
    echo '<link rel="preload" href="' . esc_url($css_url) . '" as="style" onload="this.onload=null;this.rel=\'stylesheet\'">';
    echo '<noscript><link rel="stylesheet" href="' . esc_url($css_url) . '"></noscript>';
}
add_action('wp_head', 'pl_engagement_deferred_css', 99);


/**
 * Helper: Check if current post is a listicle
 */
function pl_is_listicle_post() {
    global $post;
    if (!$post) return false;
    return (bool) preg_match('/<h2[^>]*>.*?#\d+/i', $post->post_content);
}


/**
 * Helper: Count listicle items
 */
function pl_count_listicle_items() {
    global $post;
    if (!$post) return 0;
    preg_match_all('/<h2[^>]*>.*?#\d+/i', $post->post_content, $matches);
    return count($matches[0]);
}


/**
 * Helper: Get next post data for next-article bar
 */
function pl_get_next_post_data() {
    $next = get_adjacent_post(true, '', false);
    if (!$next) return null;
    return [
        'title' => $next->post_title,
        'url'   => get_permalink($next),
        'img'   => get_the_post_thumbnail_url($next, 'thumbnail') ?: '',
    ];
}
```

---

## STEP 8: Verify the Deferred CSS Loading

PinLightning already has a pattern for deferred CSS. Make sure `engagement.css` follows the SAME pattern. Look in `header.php` or `functions.php` for how the main stylesheet is deferred — likely one of:

```html
<!-- Pattern A: preload + onload -->
<link rel="preload" href="engagement.css" as="style" onload="this.onload=null;this.rel='stylesheet'">

<!-- Pattern B: media swap -->
<link rel="stylesheet" href="engagement.css" media="print" onload="this.media='all'">
```

Use whichever pattern already exists in the theme. **DO NOT add a regular `<link rel="stylesheet">` — that would be render-blocking.**

---

## STEP 9: Customizer Controls (Optional — `inc/engagement-customizer.php`)

Create basic toggle controls for enabling/disabling technique groups per category:

```php
<?php
function pl_engagement_customizer($wp_customize) {
    $wp_customize->add_section('pl_engagement', [
        'title'    => 'Engagement System',
        'priority' => 160,
    ]);
    
    $toggles = [
        'eb_progress_bar'   => 'Progress Bar',
        'eb_item_counter'   => 'Item Counter',
        'eb_jump_pills'     => 'Jump Pills',
        'eb_live_activity'  => 'Live Activity Count',
        'eb_scroll_reveal'  => 'Scroll Reveal Animation',
        'eb_ken_burns'      => 'Image Ken Burns Motion',
        'eb_shimmer'        => 'Image Shimmer Sweep',
        'eb_trending'       => 'Trending Badges',
        'eb_save_counts'    => 'Save Count Badges',
        'eb_favorites'      => 'Favorite Hearts',
        'eb_collectibles'   => 'Hidden Collectibles',
        'eb_blur_reveal'    => 'Blur Reveal Gate',
        'eb_polls'          => 'Style Polls',
        'eb_quizzes'        => 'Style Quiz',
        'eb_curiosity'      => 'Curiosity Teasers',
        'eb_email_capture'  => 'Email Capture Block',
        'eb_milestones'     => 'Milestone Celebrations',
        'eb_achievements'   => 'Achievement Badge',
        'eb_speed_warn'     => 'Scroll Speed Warning',
        'eb_ai_tip'         => 'AI Tip Unlock',
        'eb_exit_intent'    => 'Exit Intent Popup',
        'eb_next_bar'       => 'Next Article Bar',
        'eb_dark_mode'      => 'Dark Mode Support',
        'eb_skeletons'      => 'Skeleton Loading Screens',
        'eb_char_messages'  => 'Character Speech Bubbles',
        'eb_reading_streak' => 'Reading Streak',
    ];
    
    foreach ($toggles as $id => $label) {
        $wp_customize->add_setting($id, [
            'default' => true,
            'type'    => 'theme_mod',
        ]);
        $wp_customize->add_control($id, [
            'label'   => $label,
            'section' => 'pl_engagement',
            'type'    => 'checkbox',
        ]);
    }
}
add_action('customize_register', 'pl_engagement_customizer');
```

Then in `engagement-breaks.php` and `single.php`, wrap each feature output in:
```php
<?php if (get_theme_mod('eb_progress_bar', true)): ?>
  <!-- progress bar markup -->
<?php endif; ?>
```

And pass enabled flags to JS via `ebConfig`:
```php
'features' => [
    'progressBar'  => get_theme_mod('eb_progress_bar', true),
    'darkMode'     => get_theme_mod('eb_dark_mode', true),
    'skeletons'    => get_theme_mod('eb_skeletons', true),
    // ... etc
],
```

---

## STEP 10: Implementation Order (Do This Sequence)

```
1. Create css/engagement.css        ← Copy from prototype, exclude critical rules
2. Add critical CSS to critical.css ← 7 lines only (progress bar + counter)
3. Create js/engagement.js          ← Copy from prototype, apply 3a-3f modifications
4. Create inc/engagement-config.php ← Category-specific break configs
5. Create inc/engagement-breaks.php ← Content filter (the big one)
6. Modify single.php               ← Add fixed UI element markup
7. Modify functions.php             ← Register assets, add filter includes
8. Create inc/engagement-customizer.php ← Customizer toggles
9. Test locally with Lighthouse     ← MUST still be 100/100/100/100
10. Commit + push via GitHub Actions ← Deploy to Hostinger
```

---

## STEP 11: Post-Deploy Verification Checklist

After deployment, verify:

- [ ] PageSpeed Insights: still 100/100/100/100 on mobile and desktop
- [ ] Progress bar appears and fills on scroll
- [ ] Item counter shows and increments
- [ ] Jump pills work on desktop, hidden on mobile
- [ ] Live activity shows on desktop, merged into counter on mobile
- [ ] Scroll-reveal animations fire on each item
- [ ] Trending badges appear on 3 random items (consistent within session)
- [ ] Save counts display on all items (higher for earlier items)
- [ ] Favorite hearts toggle and build summary grid at bottom
- [ ] Collectibles appear on 5 items and counter updates
- [ ] Blur gate on configured item works (tap to reveal)
- [ ] Poll vote shows animated result bars
- [ ] Quiz selection shows personality result
- [ ] Curiosity teasers appear between configured items
- [ ] Email capture form submits (or shows success state)
- [ ] Milestones fire at 25%/50%/75%/100%
- [ ] Achievement badge slides in at 100%
- [ ] Scroll speed warning appears at >2000px/s
- [ ] AI tip unlocks after 2-of-3 signals
- [ ] Exit intent detects scroll-up pattern
- [ ] Next article bar appears at 80% scroll
- [ ] Reading streak shows on return visits
- [ ] Character speech bubbles fire at configured items
- [ ] TOC teaser thumbnails link to correct items
- [ ] Dark mode activates for dark-pref users AFTER scroll (not at load)
- [ ] Skeleton screens show for below-fold items AFTER scroll (not at load)
- [ ] `prefers-reduced-motion` disables all animations
- [ ] GA4 events fire: check Realtime report for `eb_*` events
- [ ] No console errors
- [ ] No CLS shifts from injected content

---

## Reference: Prototype File

The complete working prototype is `cheerlives-42-techniques-prototype-v2.html` (1377 lines). It contains ALL the CSS, HTML structure, and JS that needs to be split into the production files above. When in doubt about exact CSS values, HTML structure, or JS logic — refer to that file as the source of truth.

Key line ranges in the prototype:
- **CSS:** Lines 9–259
- **Fixed UI HTML:** Lines 262–300
- **Content + Engagement Breaks:** Lines 302–795
- **JS (all systems):** Lines 803–1377
- **Skeleton system:** Lines 1085–1160
- **Dark mode system:** Lines 1168–1335
- **Scroll gate:** Lines 1340–1365
