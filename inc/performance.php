<?php
/**
 * PinLightning performance optimizations.
 *
 * 1. Remove WordPress bloat
 * 2. Critical CSS inlining
 * 3. HTML minification with gzip
 * 4. Resource hints
 * 5. Script optimization
 * 6. Cache headers
 * 7. Cache flush REST endpoint
 *
 * @package PinLightning
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*--------------------------------------------------------------
 * 1. REMOVE WORDPRESS BLOAT
 *--------------------------------------------------------------*/

/**
 * Remove emoji scripts and styles.
 */
function pinlightning_disable_emojis() {
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_action( 'admin_print_styles', 'print_emoji_styles' );
	remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
	remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
	remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );

	add_filter( 'tiny_mce_plugins', 'pinlightning_disable_emojis_tinymce' );
	add_filter( 'wp_resource_hints', 'pinlightning_disable_emojis_dns_prefetch', 10, 2 );
	add_filter( 'emoji_svg_url', '__return_false' );
}
add_action( 'init', 'pinlightning_disable_emojis' );

/**
 * Remove emoji TinyMCE plugin.
 *
 * @param array $plugins TinyMCE plugins.
 * @return array
 */
function pinlightning_disable_emojis_tinymce( $plugins ) {
	if ( is_array( $plugins ) ) {
		return array_diff( $plugins, array( 'wpemoji' ) );
	}
	return array();
}

/**
 * Remove emoji CDN dns-prefetch.
 *
 * @param array  $urls          URLs to print for resource hints.
 * @param string $relation_type The relation type: dns-prefetch, preconnect, etc.
 * @return array
 */
function pinlightning_disable_emojis_dns_prefetch( $urls, $relation_type ) {
	if ( 'dns-prefetch' === $relation_type ) {
		$urls = array_filter( $urls, function( $url ) {
			return ! str_contains( $url, 'https://s.w.org/images/core/emoji/' );
		} );
	}
	return $urls;
}

/**
 * Remove wp-embed script.
 */
function pinlightning_remove_wp_embed() {
	if ( ! is_admin() ) {
		wp_deregister_script( 'wp-embed' );
	}
}
add_action( 'wp_footer', 'pinlightning_remove_wp_embed' );

/**
 * Remove wp-block-library CSS, dashicons (non-logged-in), and classic-theme-styles.
 */
function pinlightning_remove_bloat_styles() {
	// Remove block library CSS.
	wp_dequeue_style( 'wp-block-library' );
	wp_dequeue_style( 'wp-block-library-theme' );

	// Remove classic theme styles.
	wp_dequeue_style( 'classic-theme-styles' );

	// Remove dashicons for non-logged-in users.
	if ( ! is_user_logged_in() ) {
		wp_dequeue_style( 'dashicons' );
		wp_deregister_style( 'dashicons' );
	}
}
add_action( 'wp_enqueue_scripts', 'pinlightning_remove_bloat_styles', 100 );

/**
 * Remove various wp_head bloat.
 */
function pinlightning_remove_head_bloat() {
	remove_action( 'wp_head', 'wlwmanifest_link' );
	remove_action( 'wp_head', 'rsd_link' );
	remove_action( 'wp_head', 'wp_shortlink_wp_head' );
	remove_action( 'wp_head', 'wp_generator' );
	remove_action( 'wp_head', 'feed_links', 2 );
	remove_action( 'wp_head', 'feed_links_extra', 3 );
	remove_action( 'wp_head', 'rest_output_link_wp_head' );
	remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
}
add_action( 'after_setup_theme', 'pinlightning_remove_head_bloat' );

/**
 * Remove global styles and SVG filters.
 */
function pinlightning_remove_global_styles() {
	remove_action( 'wp_enqueue_scripts', 'wp_enqueue_global_styles' );
	remove_action( 'wp_body_open', 'wp_global_styles_render_svg_filters' );
	remove_action( 'wp_footer', 'wp_enqueue_global_styles', 1 );
}
add_action( 'after_setup_theme', 'pinlightning_remove_global_styles' );

/**
 * Disable self-pingbacks.
 *
 * @param array $links Array of link URLs.
 */
function pinlightning_disable_self_pingbacks( &$links ) {
	$home = home_url();
	foreach ( $links as $l => $link ) {
		if ( str_starts_with( $link, $home ) ) {
			unset( $links[ $l ] );
		}
	}
}
add_action( 'pre_ping', 'pinlightning_disable_self_pingbacks' );

/*--------------------------------------------------------------
 * 2. CRITICAL CSS INLINING
 *--------------------------------------------------------------*/

/**
 * Inline critical CSS in <head> and load main.css asynchronously.
 */
function pinlightning_critical_css() {
	$critical_file = PINLIGHTNING_DIR . '/assets/css/dist/critical.css';
	if ( ! file_exists( $critical_file ) ) {
		$critical_file = PINLIGHTNING_DIR . '/assets/css/critical.css';
	}

	if ( file_exists( $critical_file ) ) {
		$critical_css = file_get_contents( $critical_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( $critical_css ) {
			echo '<style id="pinlightning-critical">' . $critical_css . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
}
add_action( 'wp_head', 'pinlightning_critical_css', 1 );

/**
 * Output async main.css loader and noscript fallback.
 *
 * Uses media="print" trick: browser fetches the stylesheet at low priority,
 * then onload flips it to media="all" so it applies.
 */
function pinlightning_async_css() {
	$css_url = PINLIGHTNING_URI . '/assets/css/dist/main.css?ver=' . PINLIGHTNING_VERSION;
	echo '<link rel="stylesheet" href="' . esc_url( $css_url ) . '" media="print" onload="this.media=\'all\'">' . "\n";
	echo '<noscript><link rel="stylesheet" href="' . esc_url( $css_url ) . '"></noscript>' . "\n";
}
add_action( 'wp_head', 'pinlightning_async_css', 2 );

/*--------------------------------------------------------------
 * 3. HTML MINIFICATION WITH GZIP
 *--------------------------------------------------------------*/

/**
 * Start output buffering with optional gzip and HTML minification.
 */
function pinlightning_start_html_minify() {
	// Skip in admin, REST, AJAX, or CLI contexts.
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return;
	}

	// Start gzip buffer if client accepts it and zlib isn't already compressing.
	if ( ! ini_get( 'zlib.output_compression' )
		&& isset( $_SERVER['HTTP_ACCEPT_ENCODING'] )
		&& str_contains( $_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip' )
		&& function_exists( 'ob_gzhandler' )
	) {
		ob_start( 'ob_gzhandler' );
	}

	// Start minification buffer.
	ob_start( 'pinlightning_minify_html' );
}
add_action( 'template_redirect', 'pinlightning_start_html_minify', -1 );

/**
 * Minify HTML output.
 *
 * Preserves content inside <pre>, <code>, <script>, and <textarea> tags
 * by extracting them before minification and restoring them after.
 *
 * @param string $html The full HTML output.
 * @return string Minified HTML.
 */
function pinlightning_minify_html( $html ) {
	if ( empty( $html ) ) {
		return $html;
	}

	// Don't minify non-HTML responses (feeds, XML sitemaps, etc.).
	if ( ! preg_match( '/<!doctype\s+html/i', substr( $html, 0, 200 ) ) ) {
		return $html;
	}

	// Preserve content inside protected tags.
	$preserved = array();
	$index     = 0;

	$html = preg_replace_callback(
		'#<(pre|code|script|textarea|style)[\s>].*?</\1>#si',
		function( $match ) use ( &$preserved, &$index ) {
			$placeholder = '<!--PINLIGHTNING_PRESERVE_' . $index . '-->';
			$preserved[ $placeholder ] = $match[0];
			$index++;
			return $placeholder;
		},
		$html
	);

	// Remove HTML comments (but not IE conditionals or preserved placeholders).
	$html = preg_replace( '/<!--(?!\[|PINLIGHTNING_PRESERVE_).*?-->/s', '', $html );

	// Collapse whitespace.
	$html = preg_replace( '/\s+/', ' ', $html );

	// Remove spaces around HTML tags.
	$html = preg_replace( '/>\s+</', '> <', $html );
	$html = preg_replace( '/>\s+/', '>', $html );
	$html = preg_replace( '/\s+</', ' <', $html );

	// Restore preserved content.
	if ( ! empty( $preserved ) ) {
		$html = str_replace( array_keys( $preserved ), array_values( $preserved ), $html );
	}

	return trim( $html );
}

/*--------------------------------------------------------------
 * 4. RESOURCE HINTS
 *--------------------------------------------------------------*/

/**
 * Add resource hints: dns-prefetch and preconnect.
 */
function pinlightning_resource_hints() {
	// DNS prefetch for external domains.
	echo '<link rel="dns-prefetch" href="//pinterest.com">' . "\n";
	echo '<link rel="dns-prefetch" href="//fonts.googleapis.com">' . "\n";
	echo '<link rel="dns-prefetch" href="//myquickurl.com">' . "\n";

	// Preconnect to own domain and CDN.
	$site_domain = wp_parse_url( home_url(), PHP_URL_HOST );
	echo '<link rel="preconnect" href="' . esc_url( '//' . $site_domain ) . '" crossorigin>' . "\n";
	echo '<link rel="preconnect" href="https://myquickurl.com" crossorigin>' . "\n";
}
add_action( 'wp_head', 'pinlightning_resource_hints', 1 );

/**
 * Preload the LCP hero image with fetchpriority and srcset support.
 *
 * Runs at priority 2 so it appears early in <head>, right after critical CSS.
 * If the featured image is in wp-content/uploads/, rewrites to local resizer.
 */
function pinlightning_preload_lcp_image() {
	if ( ! is_singular() || ! has_post_thumbnail() ) {
		return;
	}

	$thumbnail_id  = get_post_thumbnail_id();
	$thumbnail_url = wp_get_attachment_image_url( $thumbnail_id, 'large' );
	if ( ! $thumbnail_url ) {
		return;
	}

	// Check if featured image can go through local resizer.
	$use_resizer = ( strpos( $thumbnail_url, '/wp-content/uploads/' ) !== false );

	if ( $use_resizer ) {
		// Extract uploads-relative path and build local resizer URLs.
		preg_match( '#/wp-content/uploads/(.+?)(?:\?|$)#', $thumbnail_url, $pm );
		$uploads_path = isset( $pm[1] ) ? $pm[1] : '';
		$base_url     = PINLIGHTNING_URI . '/img-resize.php?src=' . rawurlencode( $uploads_path );

		$href   = $base_url . '&w=720&q=80';
		$srcset = implode( ', ', array(
			$base_url . '&w=400&q=80 400w',
			$base_url . '&w=720&q=80 720w',
		) );
		$sizes = '(max-width: 720px) 100vw, 720px';

		$attrs = 'rel="preload" as="image" href="' . esc_url( $href ) . '" fetchpriority="high"';
		$attrs .= ' imagesrcset="' . esc_attr( $srcset ) . '"';
		$attrs .= ' imagesizes="' . esc_attr( $sizes ) . '"';
	} else {
		// Standard WordPress preload (non-local images).
		$attrs = 'rel="preload" as="image" href="' . esc_url( $thumbnail_url ) . '" fetchpriority="high"';

		$srcset = wp_get_attachment_image_srcset( $thumbnail_id, 'large' );
		if ( $srcset ) {
			$attrs .= ' imagesrcset="' . esc_attr( $srcset ) . '"';

			$sizes = wp_get_attachment_image_sizes( $thumbnail_id, 'large' );
			if ( $sizes ) {
				$attrs .= ' imagesizes="' . esc_attr( $sizes ) . '"';
			}
		}
	}

	echo '<link ' . $attrs . '>' . "\n";
}
add_action( 'wp_head', 'pinlightning_preload_lcp_image', 2 );

/*--------------------------------------------------------------
 * 5. SCRIPT OPTIMIZATION
 *--------------------------------------------------------------*/

/**
 * Deregister jQuery on frontend and move scripts to footer.
 */
function pinlightning_optimize_scripts() {
	if ( is_admin() ) {
		return;
	}

	// Deregister jQuery completely on frontend.
	wp_deregister_script( 'jquery-core' );
	wp_deregister_script( 'jquery-migrate' );
	wp_deregister_script( 'jquery' );
}
add_action( 'wp_enqueue_scripts', 'pinlightning_optimize_scripts', 100 );

/**
 * Add defer attribute to all frontend scripts.
 *
 * @param string $tag    The script tag HTML.
 * @param string $handle The script handle.
 * @param string $src    The script source URL.
 * @return string Modified script tag.
 */
function pinlightning_defer_scripts( $tag, $handle, $src ) {
	// Don't defer in admin.
	if ( is_admin() ) {
		return $tag;
	}

	// Don't double-add defer if already present, and skip inline scripts.
	if ( str_contains( $tag, 'defer' ) || str_contains( $tag, 'async' ) || empty( $src ) ) {
		return $tag;
	}

	return str_replace( ' src=', ' defer src=', $tag );
}
add_filter( 'script_loader_tag', 'pinlightning_defer_scripts', 10, 3 );

/**
 * Remove query strings from static resources (?ver=).
 *
 * @param string $src The resource URL.
 * @return string URL with version query string removed.
 */
function pinlightning_remove_script_version( $src ) {
	if ( is_admin() ) {
		return $src;
	}

	if ( $src ) {
		$src = remove_query_arg( 'ver', $src );
	}

	return $src;
}
add_filter( 'style_loader_src', 'pinlightning_remove_script_version', 9999 );
add_filter( 'script_loader_src', 'pinlightning_remove_script_version', 9999 );

/*--------------------------------------------------------------
 * 6. CACHE HEADERS
 *--------------------------------------------------------------*/

/**
 * Set cache headers for static-like page responses.
 */
function pinlightning_cache_headers() {
	// Don't set cache headers in admin, for logged-in users, or on dynamic pages.
	if ( is_admin() || is_user_logged_in() || is_preview() ) {
		return;
	}

	// Vary by encoding so CDNs/proxies cache gzip and non-gzip separately.
	header( 'Vary: Accept-Encoding' );

	// Cache static assets served through PHP (e.g., theme file requests).
	if ( isset( $_SERVER['REQUEST_URI'] ) && preg_match( '/\.(css|js|jpg|jpeg|png|gif|webp|svg|woff2?|ttf|eot|ico)$/i', $_SERVER['REQUEST_URI'] ) ) {
		header( 'Cache-Control: public, max-age=31536000, immutable' );
		return;
	}

	// HTML pages: short cache for CDN, revalidate for browsers.
	header( 'Cache-Control: public, max-age=0, s-maxage=600, must-revalidate' );
}
add_action( 'send_headers', 'pinlightning_cache_headers' );

/*--------------------------------------------------------------
 * 7. CACHE FLUSH REST ENDPOINT
 *--------------------------------------------------------------*/

/**
 * Register the cache flush REST route.
 */
function pinlightning_register_flush_route() {
	register_rest_route( 'pinlightning/v1', '/flush-cache', array(
		'methods'             => 'POST',
		'callback'            => 'pinlightning_flush_cache_handler',
		'permission_callback' => 'pinlightning_flush_cache_permission',
	) );
}
add_action( 'rest_api_init', 'pinlightning_register_flush_route' );

/**
 * Verify the cache flush secret token.
 *
 * @param WP_REST_Request $request The REST request.
 * @return bool|WP_Error True if authorized, WP_Error otherwise.
 */
function pinlightning_flush_cache_permission( $request ) {
	if ( ! defined( 'PINLIGHTNING_CACHE_SECRET' ) || empty( PINLIGHTNING_CACHE_SECRET ) ) {
		return new WP_Error(
			'pinlightning_no_secret',
			'PINLIGHTNING_CACHE_SECRET is not defined in wp-config.php.',
			array( 'status' => 500 )
		);
	}

	$token = $request->get_header( 'X-Cache-Secret' );
	if ( empty( $token ) ) {
		$token = $request->get_param( 'token' );
	}

	if ( ! hash_equals( PINLIGHTNING_CACHE_SECRET, (string) $token ) ) {
		return new WP_Error(
			'pinlightning_unauthorized',
			'Invalid cache secret token.',
			array( 'status' => 403 )
		);
	}

	return true;
}

/**
 * Handle cache flush request.
 *
 * Flushes the object cache and deletes all transients with the pinlightning_ prefix.
 *
 * @param WP_REST_Request $request The REST request.
 * @return WP_REST_Response
 */
function pinlightning_flush_cache_handler( $request ) {
	$flushed = array();

	// Flush object cache.
	if ( function_exists( 'wp_cache_flush' ) ) {
		wp_cache_flush();
		$flushed[] = 'object_cache';
	}

	// Delete all pinlightning_ transients.
	global $wpdb;
	$transients = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( '_transient_pinlightning_' ) . '%'
		)
	);

	$deleted_count = 0;
	foreach ( $transients as $transient ) {
		// Strip the _transient_ prefix to get the transient name.
		$name = str_replace( '_transient_', '', $transient );
		delete_transient( $name );
		$deleted_count++;
	}
	$flushed[] = 'transients';

	return new WP_REST_Response( array(
		'success'            => true,
		'flushed'            => $flushed,
		'transients_deleted' => $deleted_count,
	), 200 );
}
