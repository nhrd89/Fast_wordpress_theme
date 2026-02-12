<?php
/**
 * PinLightning Smart Ad Engine
 * Content scanner + zone injection
 */

// === Configuration ===
define('PL_ADS_ENABLED', true);
define('PL_ADS_DUMMY_MODE', true);      // true = colored placeholder boxes, false = real AdPlus GPT
define('PL_ADS_DEBUG_OVERLAY', true);    // Visual debug overlay (off in production)
define('PL_ADS_RECORD_DATA', true);      // Log viewability data to server
define('PL_ADS_MIN_PARAGRAPHS', 3);     // Skip first N paragraphs before any ad
define('PL_ADS_MIN_GAP', 3);            // Minimum paragraphs between ad zones
define('PL_ADS_MAX_PER_POST', 6);       // Maximum auto-injected zones per post

// === Ad size strategy based on actual revenue data ===
// Best performers: 300x250 ($0.49 eCPM, highest volume), 970x250 ($0.80 eCPM)
// Avoid: Side-Anchor (negative revenue), 728x90 (low volume/eCPM), small sizes
// Mobile: 300x250 is king. Desktop: alternate 300x250 and 970x250
function pinlightning_get_zone_size($zone_number) {
    // Alternate between best-performing sizes
    $mobile_sizes = array('300x250'); // 300x250 dominates mobile
    $desktop_sizes = array('300x250', '970x250'); // Alternate on desktop

    return array(
        'mobile' => $mobile_sizes[$zone_number % count($mobile_sizes)],
        'desktop' => $desktop_sizes[$zone_number % count($desktop_sizes)]
    );
}

/**
 * Content Scanner — finds optimal ad break points
 *
 * Scoring rules:
 * +5  Before a new section (h2, h3)
 * +4  After an image/figure (natural visual pause)
 * +3  After a long paragraph (300+ chars)
 * +1  After any paragraph
 * -10 Inside list, blockquote, table, pre
 * -5  Directly after a heading
 */
function pinlightning_scan_and_inject_zones($content) {
    if (!PL_ADS_ENABLED) return $content;
    if (!is_singular() && !isset($GLOBALS['pinlightning_rest_content'])) return $content;

    // Count paragraphs — skip short posts
    $p_count = substr_count(strtolower($content), '</p>');
    if ($p_count < PL_ADS_MIN_PARAGRAPHS + 2) return $content;

    // Parse content into block elements
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $wrapped = '<html><body>' . mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8') . '</body></html>';
    $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $body = $dom->getElementsByTagName('body')->item(0);
    if (!$body) return $content;

    // Build block list with scores
    $blocks = array();
    $p_index = 0;

    foreach ($body->childNodes as $node) {
        if ($node->nodeType !== XML_ELEMENT_NODE) continue;

        $tag = strtolower($node->tagName);
        $text_len = mb_strlen(trim($node->textContent));
        $has_img = $node->getElementsByTagName('img')->length > 0 ||
                   $tag === 'figure';

        $score = 0;

        // Positive signals
        if ($tag === 'p' && $text_len > 300) $score += 3;
        elseif ($tag === 'p') $score += 1;
        if ($has_img) $score += 4;

        // Negative signals
        if (in_array($tag, array('ul', 'ol', 'table', 'blockquote', 'pre', 'details'))) $score -= 10;
        if (in_array($tag, array('h1', 'h2', 'h3', 'h4', 'h5', 'h6'))) $score -= 5;

        if ($tag === 'p') $p_index++;

        $blocks[] = array(
            'node' => $node,
            'tag' => $tag,
            'p_index' => $p_index,
            'score' => $score,
            'text_len' => $text_len
        );
    }

    // Boost score if next element is a heading (section break = great ad spot)
    for ($i = 0; $i < count($blocks) - 1; $i++) {
        if (in_array($blocks[$i + 1]['tag'], array('h2', 'h3'))) {
            $blocks[$i]['score'] += 5;
        }
    }

    // Select best positions respecting constraints
    $positions = array();
    $last_ad_p = -PL_ADS_MIN_GAP;
    $zone_num = 0;

    for ($i = 0; $i < count($blocks); $i++) {
        if ($zone_num >= PL_ADS_MAX_PER_POST) break;
        if ($blocks[$i]['score'] < 2) continue;
        if ($blocks[$i]['p_index'] < PL_ADS_MIN_PARAGRAPHS) continue;
        if ($blocks[$i]['p_index'] - $last_ad_p < PL_ADS_MIN_GAP) continue;

        $positions[] = $i;
        $last_ad_p = $blocks[$i]['p_index'];
        $zone_num++;
    }

    // Fallback: evenly spaced if scanner found too few spots
    if ($zone_num < 2 && $p_count >= 8) {
        $positions = array();
        $spacing = max(PL_ADS_MIN_GAP, intval($p_count / 4));
        $p_counter = 0;
        $zone_num = 0;
        for ($i = 0; $i < count($blocks); $i++) {
            if ($blocks[$i]['tag'] === 'p') $p_counter++;
            if ($p_counter >= PL_ADS_MIN_PARAGRAPHS && $p_counter % $spacing === 0) {
                $positions[] = $i;
                $zone_num++;
                if ($zone_num >= PL_ADS_MAX_PER_POST) break;
            }
        }
    }

    // Rebuild HTML with ad zones inserted
    $output = '';
    $zone_counter = 0;

    foreach ($blocks as $idx => $block) {
        $output .= $dom->saveHTML($block['node']);

        if (in_array($idx, $positions)) {
            $zone_counter++;
            $zid = 'auto-' . $zone_counter;
            $sizes = pinlightning_get_zone_size($zone_counter - 1);

            $output .= sprintf(
                '<div class="ad-zone" data-zone-id="%s" data-size-mobile="%s" data-size-desktop="%s" data-injected="false" data-score="%d" aria-hidden="true"></div>',
                esc_attr($zid),
                esc_attr($sizes['mobile']),
                esc_attr($sizes['desktop']),
                $block['score']
            );
        }
    }

    return $output;
}
add_filter('the_content', 'pinlightning_scan_and_inject_zones', 55);

/**
 * Manual zone helper for single.php
 */
function pinlightning_ad_zone($zone_id, $mobile_size = '300x250', $desktop_size = '300x250') {
    if (!PL_ADS_ENABLED) return '';
    return sprintf(
        '<div class="ad-zone" data-zone-id="%s" data-size-mobile="%s" data-size-desktop="%s" data-injected="false" aria-hidden="true"></div>',
        esc_attr($zone_id),
        esc_attr($mobile_size),
        esc_attr($desktop_size)
    );
}

/**
 * Pass config to JS
 */
function pinlightning_ads_enqueue() {
    if (!PL_ADS_ENABLED || !is_singular()) return;

    wp_localize_script('pinlightning-smart-ads', 'plAds', array(
        'dummy' => PL_ADS_DUMMY_MODE,
        'debug' => PL_ADS_DEBUG_OVERLAY,
        'record' => PL_ADS_RECORD_DATA,
        'maxAds' => PL_ADS_MAX_PER_POST,
        'recordEndpoint' => rest_url('pinlightning/v1/ad-data'),
        'nonce' => wp_create_nonce('wp_rest'),
        'postId' => get_the_ID(),
        'postSlug' => get_post_field('post_name', get_the_ID())
    ));
}
add_action('wp_enqueue_scripts', 'pinlightning_ads_enqueue', 20);
