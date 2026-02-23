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

	// Build output: ad1 before first paragraph, ad2 after second paragraph.
	$output       = $ad1;
	$p_count      = 0;
	$ad2_inserted = false;

	for ( $i = 0; $i < count( $parts ); $i++ ) {
		$output .= $parts[ $i ];

		if ( strtolower( trim( $parts[ $i ] ) ) === '</p>' ) {
			$p_count++;
			if ( 2 === $p_count && ! $ad2_inserted ) {
				$output      .= $ad2;
				$ad2_inserted = true;
			}
		}
	}

	// Fallback: if fewer than 2 paragraphs found, append ad2 at end.
	if ( ! $ad2_inserted ) {
		$output .= $ad2;
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
	echo '<div class="pl-sidebar-ad" style="min-height:600px;max-width:300px;margin:0 auto 16px;">';
	echo '<div id="300x600-1"></div></div>';
	echo '<div class="pl-sidebar-ad" style="min-height:250px;max-width:300px;margin:0 auto 16px;">';
	echo '<div id="300x250-sidebar"></div></div>';
	echo '</div>';
}

/* ================================================================
 * 3. INLINE CSS — CLS PREVENTION
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
