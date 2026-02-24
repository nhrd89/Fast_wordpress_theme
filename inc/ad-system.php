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
 * - Content filter: inject 2 initial ad containers (before para 1, after para 2)
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
 * - initial-ad-1: before first paragraph (top of content)
 * - initial-ad-2: after second paragraph
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

	$ad1 = '<div class="pl-initial-ad" style="text-align:center;min-height:280px;margin:12px auto;">'
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

	// Build output: ad1 before first paragraph, ad2 after paragraph 2, pause after paragraph 5.
	$output         = $ad1;
	$p_count        = 0;
	$ad2_inserted   = false;
	$pause_inserted = false;

	for ( $i = 0; $i < count( $parts ); $i++ ) {
		$output .= $parts[ $i ];

		if ( strtolower( trim( $parts[ $i ] ) ) === '</p>' ) {
			$p_count++;
			if ( 2 === $p_count && ! $ad2_inserted ) {
				$output      .= $ad2;
				$ad2_inserted = true;
			}
			if ( 5 === $p_count && ! $pause_inserted ) {
				$output         .= $pause;
				$pause_inserted  = true;
			}
		}
	}

	// Fallback: if fewer than 2 paragraphs found, append ad2 at end.
	if ( ! $ad2_inserted ) {
		$output .= $ad2;
	}
	// Fallback: if fewer than 5 paragraphs, append pause ad at end.
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
	$s = function_exists( 'pl_ad_settings' ) ? pl_ad_settings() : array( 'enabled' => false );
	if ( empty( $s['enabled'] ) ) {
		return;
	}

	echo '<div class="pl-nav-ad" style="text-align:center;min-height:90px;margin:0 auto;overflow:hidden;width:100%;">';
	echo '<div id="nav-ad-1"></div>';
	echo '</div>';
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
.pl-nav-ad{position:relative;overflow:hidden;contain:layout;min-height:100px}
@media(min-width:768px){.pl-nav-ad{min-height:90px}}
.pl-sidebar-ads{display:none}
@media(min-width:1025px){.pl-sidebar-ads{display:block;position:sticky;top:80px}}
</style>
	<?php
}
add_action( 'wp_head', 'pl_ad_system_css', 2 );
