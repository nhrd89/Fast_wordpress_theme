<?php
/**
 * PinLightning Ad System — Two-Layer Frontend
 *
 * Layer 1 (initial-ads.js): Loads GPT post-window.load, defines initial
 *         viewport slots via SRA, handles overlay refresh.
 * Layer 2 (smart-ads.js):   Boots on first user interaction, dynamic
 *         injection + smart in-view refresh + slot recycling.
 *
 * This file handles:
 * - Content filter: inject 2 initial ad containers (after para 1, after para 3)
 * - Sidebar rendering function for single.php
 * - Inline CSS for ad containers (CLS prevention)
 *
 * @package PinLightning
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ================================================================
 * 1. CONTENT FILTER — INITIAL AD CONTAINERS
 * ================================================================ */

/**
 * Inject 2 fixed ad container divs into single post content.
 *
 * - initial-ad-1: after paragraph 1 (intro). Engagement-breaks inserts
 *   the hero mosaic after para 1 at priority 95, so in the final DOM
 *   the ad sits right after the social proof bar.
 * - initial-ad-2: after paragraph 3 (3 items deep, user is engaged).
 *
 * These are the only PHP-injected in-content ads. Everything below
 * the fold is handled dynamically by smart-ads.js (Layer 2).
 *
 * @param  string $content Post content HTML.
 * @return string          Content with ad containers inserted.
 */
function pl_inject_initial_ads( $content ) {
	if ( ! is_single() || is_admin() || is_feed() ) {
		return $content;
	}

	// Respect ad engine kill switch.
	$s = function_exists( 'pl_ad_settings' ) ? pl_ad_settings() : array( 'enabled' => false );
	if ( empty( $s['enabled'] ) ) {
		return $content;
	}

	// Skip in REST infinite-scroll context (those have their own rendering).
	if ( isset( $GLOBALS['pinlightning_rest_content'] ) ) {
		return $content;
	}

	// Split on </p> to count paragraphs.
	$parts = preg_split( '/(<\/p>)/i', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
	if ( count( $parts ) < 4 ) {
		return $content; // Too few paragraphs — skip injection.
	}

	$ad1 = '<div class="pl-initial-ad" style="text-align:center;min-height:250px;margin:12px auto;">'
		. '<div id="initial-ad-1"></div></div>';
	$ad2 = '<div class="pl-initial-ad" style="text-align:center;min-height:250px;margin:12px auto;">'
		. '<div id="initial-ad-2"></div></div>';

	// Only inject pause container if pause format is enabled in optimizer.
	$fmt = function_exists( 'pl_opt_get' ) ? pl_opt_get( 'pl_ad_format_settings' ) : array();
	$pause_on = isset( $fmt['pause'] ) ? (bool) $fmt['pause'] : true;
	$pause = $pause_on
		? '<div class="pl-pause-ad" style="text-align:center;min-height:0;overflow:hidden;contain:layout;">'
			. '<div id="pause-ad-1"></div></div>'
		: '';

	// Build output: ad1 after paragraph 1, ad2 after paragraph 3, pause after paragraph 6.
	// After engagement-breaks (p95) inserts hero mosaic after para 1, final DOM order:
	// Para 1 (intro) → hero mosaic + social proof → ad1 → listicle items → ad2 after item 2.
	$output         = '';
	$p_count        = 0;
	$ad1_inserted   = false;
	$ad2_inserted   = false;
	$pause_inserted = false;

	for ( $i = 0; $i < count( $parts ); $i++ ) {
		$output .= $parts[ $i ];

		if ( strtolower( trim( $parts[ $i ] ) ) === '</p>' ) {
			$p_count++;
			if ( 1 === $p_count && ! $ad1_inserted ) {
				$output      .= $ad1;
				$ad1_inserted = true;
			}
			if ( 3 === $p_count && ! $ad2_inserted ) {
				$output      .= $ad2;
				$ad2_inserted = true;
			}
			if ( 6 === $p_count && ! $pause_inserted ) {
				$output         .= $pause;
				$pause_inserted  = true;
			}
		}
	}

	// Fallback: if fewer than 1 paragraph, append ad1 at end.
	if ( ! $ad1_inserted ) {
		$output .= $ad1;
	}
	// Fallback: if fewer than 3 paragraphs, append ad2 at end.
	if ( ! $ad2_inserted ) {
		$output .= $ad2;
	}
	// Fallback: if fewer than 6 paragraphs, append pause ad at end.
	if ( ! $pause_inserted && $pause ) {
		$output .= $pause;
	}

	return $output;
}
add_filter( 'the_content', 'pl_inject_initial_ads', 30 );

/* ================================================================
 * 2. SIDEBAR AD CONTAINERS
 * ================================================================ */

/**
 * Render sidebar ad containers for desktop.
 *
 * Called from single.php inside the <aside> sidebar.
 * CSS hides these on mobile/tablet; initial-ads.js only defines
 * the GPT slots when viewport width >= 1025px.
 */
function pl_render_sidebar_ads() {
	if ( ! is_single() ) {
		return;
	}

	$s = function_exists( 'pl_ad_settings' ) ? pl_ad_settings() : array( 'enabled' => false );
	if ( empty( $s['enabled'] ) ) {
		return;
	}

	echo '<div class="pl-sidebar-ads">';
	echo '<div class="pl-sidebar-ad" style="min-height:600px;width:100%;margin:0 auto 16px;text-align:center;">';
	echo '<div id="300x600-1"></div></div>';
	echo '<div class="pl-sidebar-ad" style="min-height:250px;width:100%;margin:0 auto 16px;text-align:center;">';
	echo '<div id="300x250-sidebar"></div></div>';
	echo '</div>';
}

/* ================================================================
 * 3. NAV AD CONTAINER
 * ================================================================ */

/**
 * Render a leaderboard ad container below the navigation bar.
 *
 * Appears on ALL page types (not just single posts). This is the
 * highest-viewability placement — first thing below the sticky header.
 * initial-ads.js defines the GPT slot with responsive size mapping.
 */
function pl_render_nav_ad() {
	return;
}

/* ================================================================
 * 4. INLINE CSS — CLS PREVENTION
 * ================================================================ */

/**
 * Inline CSS for ad containers.
 *
 * Keeps containers stable during load to prevent CLS.
 * Sidebar ads hidden on mobile/tablet.
 */
function pl_ad_system_css() {
	$s = function_exists( 'pl_ad_settings' ) ? pl_ad_settings() : array( 'enabled' => false );
	if ( empty( $s['enabled'] ) ) {
		return;
	}
	?>
<style>
.pl-initial-ad{position:relative;overflow:hidden;clear:both;contain:layout}
.pl-dynamic-ad{position:relative;overflow:hidden;clear:both;contain:layout}
.pl-sidebar-ads{display:none}
@media(min-width:1025px){.pl-sidebar-ads{display:block;position:sticky;top:80px}}
</style>
	<?php
}
add_action( 'wp_head', 'pl_ad_system_css', 2 );
