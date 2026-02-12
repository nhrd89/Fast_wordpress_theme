<?php
/**
 * PinLightning Smart Ad Engine
 *
 * Scans post content structure and injects ad zone placeholders
 * at optimal positions based on content analysis.
 */

// Configuration
define('PL_ADS_ENABLED', true);
define('PL_ADS_DUMMY_MODE', true);     // true = colored boxes, false = real AdPlus
define('PL_ADS_DEBUG', true);           // Show debug overlay
define('PL_ADS_MIN_PARAGRAPHS', 4);    // Minimum paragraphs before any ad
define('PL_ADS_MIN_GAP_PARAGRAPHS', 3); // Minimum paragraphs between ads
define('PL_ADS_MAX_PER_POST', 8);       // Maximum ads per post

/**
 * Content Scanner — analyzes content and finds optimal ad break points
 *
 * Rules for optimal placement:
 * - After a paragraph that ends a thought (before a new <h2>, <h3>, or <hr>)
 * - After long paragraphs (300+ chars) — natural reading pause
 * - After images/figures — visual break, user is pausing
 * - Never inside a list, blockquote, or table
 * - Never between consecutive headings
 * - Never immediately before/after another ad zone
 * - Minimum gap of PL_ADS_MIN_GAP_PARAGRAPHS paragraphs between ads
 */
function pinlightning_scan_content_for_ad_breaks($content) {
	if (!PL_ADS_ENABLED) return $content;
	if (!is_singular() && !isset($GLOBALS['pinlightning_rest_content'])) {
		return $content;
	}

	// Don't process if content is too short
	$paragraph_count = substr_count($content, '</p>');
	if ($paragraph_count < PL_ADS_MIN_PARAGRAPHS) return $content;

	// Split content into tokens (preserve HTML structure)
	$dom = new DOMDocument();
	// Suppress warnings from malformed HTML
	libxml_use_internal_errors(true);
	$dom->loadHTML(
		'<html><body>' . mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8') . '</body></html>',
		LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
	);
	libxml_clear_errors();

	$body = $dom->getElementsByTagName('body')->item(0);
	if (!$body) return $content;

	// Analyze each block element and score for ad placement suitability
	$blocks = array();
	$paragraph_index = 0;

	foreach ($body->childNodes as $node) {
		if ($node->nodeType !== XML_ELEMENT_NODE) continue;

		$tag = strtolower($node->tagName);
		$text_length = strlen(trim($node->textContent));
		$has_image = $node->getElementsByTagName('img')->length > 0;

		$block = array(
			'node' => $node,
			'tag' => $tag,
			'text_length' => $text_length,
			'has_image' => $has_image,
			'paragraph_index' => $paragraph_index,
			'score' => 0 // Higher = better ad break point
		);

		// Score the break point AFTER this element
		if ($tag === 'p' && $text_length > 300) {
			$block['score'] += 3; // Long paragraph = natural reading pause
		} elseif ($tag === 'p') {
			$block['score'] += 1;
		}

		if ($has_image || $tag === 'figure') {
			$block['score'] += 4; // After image = excellent break
		}

		// Bad positions (negative score)
		if (in_array($tag, array('ul', 'ol', 'table', 'blockquote', 'pre'))) {
			$block['score'] -= 10; // Never break inside these
		}
		if (in_array($tag, array('h1', 'h2', 'h3', 'h4'))) {
			$block['score'] -= 5; // Don't place right after a heading
		}

		if ($tag === 'p') $paragraph_index++;

		$blocks[] = $block;
	}

	// Now determine which blocks to place ads after
	// Look ahead: if next element is a heading, boost current score (ad before section break)
	for ($i = 0; $i < count($blocks) - 1; $i++) {
		$next_tag = $blocks[$i + 1]['tag'];
		if (in_array($next_tag, array('h2', 'h3'))) {
			$blocks[$i]['score'] += 5; // Great spot: between content and new section
		}
	}

	// Select ad positions
	$ad_positions = array(); // indices into $blocks
	$last_ad_paragraph = -PL_ADS_MIN_GAP_PARAGRAPHS; // Allow first ad after minimum
	$ad_count = 0;

	// First pass: skip the first PL_ADS_MIN_PARAGRAPHS paragraphs
	$min_start_index = 0;
	$p_count = 0;
	for ($i = 0; $i < count($blocks); $i++) {
		if ($blocks[$i]['tag'] === 'p') $p_count++;
		if ($p_count >= PL_ADS_MIN_PARAGRAPHS) {
			$min_start_index = $i;
			break;
		}
	}

	// Second pass: find best positions with minimum gap
	for ($i = $min_start_index; $i < count($blocks); $i++) {
		if ($ad_count >= PL_ADS_MAX_PER_POST) break;
		if ($blocks[$i]['score'] < 2) continue; // Skip low-score positions

		$current_p = $blocks[$i]['paragraph_index'];
		if ($current_p - $last_ad_paragraph < PL_ADS_MIN_GAP_PARAGRAPHS) continue;

		$ad_positions[] = $i;
		$last_ad_paragraph = $current_p;
		$ad_count++;
	}

	// If we got too few ads (content is long but no good spots),
	// fall back to evenly spaced positions
	if ($ad_count < 2 && $paragraph_count >= 10) {
		$ad_positions = array();
		$spacing = max(PL_ADS_MIN_GAP_PARAGRAPHS, intval($paragraph_count / 4));
		$p_count = 0;
		for ($i = 0; $i < count($blocks); $i++) {
			if ($blocks[$i]['tag'] === 'p') $p_count++;
			if ($p_count > 0 && $p_count % $spacing === 0 && $p_count >= PL_ADS_MIN_PARAGRAPHS) {
				$ad_positions[] = $i;
				if (count($ad_positions) >= PL_ADS_MAX_PER_POST) break;
			}
		}
	}

	// Build output HTML — inject ad zones at selected positions
	$output = '';
	$zone_counter = 0;

	foreach ($blocks as $index => $block) {
		$output .= $dom->saveHTML($block['node']);

		if (in_array($index, $ad_positions)) {
			$zone_counter++;
			$zone_id = 'auto-' . $zone_counter;

			// Determine ad size based on position
			// Odd zones: 300x250 (works everywhere)
			// Even zones: responsive (728x90 desktop / 320x100 mobile)
			$size = ($zone_counter % 2 === 1) ? '300x250' : 'responsive';

			$output .= sprintf(
				'<div class="ad-zone" data-zone-id="%s" data-ad-size="%s" data-injected="false" data-score="%d" aria-hidden="true"></div>',
				esc_attr($zone_id),
				esc_attr($size),
				$block['score']
			);
		}
	}

	return $output;
}
add_filter('the_content', 'pinlightning_scan_content_for_ad_breaks', 55); // After CDN rewrite (25) and other filters

/**
 * Add post-top and post-bottom ad zones
 * Called from single.php
 */
function pinlightning_ad_zone($zone_id, $size = '300x250') {
	if (!PL_ADS_ENABLED) return '';
	return sprintf(
		'<div class="ad-zone" data-zone-id="%s" data-ad-size="%s" data-injected="false" aria-hidden="true"></div>',
		esc_attr($zone_id),
		esc_attr($size)
	);
}

/**
 * Pass config to JS
 */
function pinlightning_ad_engine_config() {
	if (!PL_ADS_ENABLED || !is_singular()) return;

	$config = array(
		'dummyMode' => PL_ADS_DUMMY_MODE,
		'debug' => PL_ADS_DEBUG,
		'maxAds' => PL_ADS_MAX_PER_POST,
		'viewableThreshold' => 0.5,
		'viewableTimeMs' => 1000,
		'maxScrollSpeed' => 800,
		'minReadTimeMs' => 2000,
	);

	wp_localize_script('pinlightning-smart-ads', 'plAdsConfig', $config);
}
add_action('wp_enqueue_scripts', 'pinlightning_ad_engine_config', 20);
