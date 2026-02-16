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

	// Fully dequeue tooltipster — loaded on first scroll via pinlightning_scroll_deferred_assets().
	global $pinlightning_deferred_tooltipster_src;
	if ( wp_style_is( 'smpush-smiotooltipster', 'enqueued' ) ) {
		$styles = wp_styles();
		if ( isset( $styles->registered['smpush-smiotooltipster'] ) ) {
			$pinlightning_deferred_tooltipster_src = $styles->registered['smpush-smiotooltipster']->src;
		}
		wp_dequeue_style( 'smpush-smiotooltipster' );
	}

	// Defer remaining non-critical plugin CSS via media="print" onload trick.
	$defer_styles = array(
		'wp-block-paragraph',       // WP core block paragraph
	);
	foreach ( $defer_styles as $handle ) {
		if ( wp_style_is( $handle, 'enqueued' ) ) {
			wp_style_add_data( $handle, 'media', 'print' );
		}
	}
}
add_action( 'wp_enqueue_scripts', 'pinlightning_remove_bloat_styles', 100 );

/**
 * Nuclear dequeue for tooltipster — the Smart Notification plugin re-enqueues
 * after priority 100. Fire at wp_print_styles (latest hook) with priority 9999.
 */
add_action( 'wp_print_styles', function() {
	if ( is_admin() ) {
		return;
	}

	// Capture URL before deregistering so scroll-loader can still use it.
	global $pinlightning_deferred_tooltipster_src;
	if ( empty( $pinlightning_deferred_tooltipster_src ) ) {
		$styles = wp_styles();
		foreach ( array( 'smpush-smiotooltipster', 'tooltipster' ) as $h ) {
			if ( isset( $styles->registered[ $h ] ) ) {
				$pinlightning_deferred_tooltipster_src = $styles->registered[ $h ]->src;
				break;
			}
		}
	}

	wp_dequeue_style( 'smpush-smiotooltipster' );
	wp_deregister_style( 'smpush-smiotooltipster' );
	wp_dequeue_style( 'tooltipster' );
	wp_deregister_style( 'tooltipster' );
}, 9999 );

/**
 * Convert deferred plugin stylesheets to non-blocking via media="print" onload trick.
 */
function pinlightning_defer_plugin_styles( $tag, $handle, $src ) {
	if ( is_admin() ) {
		return $tag;
	}

	$defer_handles = array(
		'wp-block-paragraph',
	);

	if ( in_array( $handle, $defer_handles, true ) ) {
		// media="print" onload="this.media='all'" — loads after render, no flash.
		$tag = str_replace(
			"media='all'",
			"media='print' onload=\"this.media='all'\"",
			$tag
		);
		// Also handle double-quote variant.
		$tag = str_replace(
			'media="all"',
			'media="print" onload="this.media=\'all\'"',
			$tag
		);
	}

	return $tag;
}
add_filter( 'style_loader_tag', 'pinlightning_defer_plugin_styles', 10, 3 );

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
 * Inline main.css in <head> as a second style block.
 *
 * At ~2.7 KiB gzipped, main.css is small enough to inline. This eliminates:
 * - The external CSS request (saves one round-trip)
 * - CLS from async media="print" onload trick (which caused ~0.097 CLS)
 * - Render-blocking from synchronous <link> loading
 */
function pinlightning_inline_main_css() {
	$main_file = PINLIGHTNING_DIR . '/assets/css/dist/main.css';
	if ( ! file_exists( $main_file ) ) {
		$main_file = PINLIGHTNING_DIR . '/assets/css/main.css';
	}

	if ( file_exists( $main_file ) ) {
		$main_css = file_get_contents( $main_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( $main_css ) {
			echo '<style id="pinlightning-main">' . $main_css . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
}
add_action( 'wp_head', 'pinlightning_inline_main_css', 2 );

/*--------------------------------------------------------------
 * 3. GZIP COMPRESSION
 *--------------------------------------------------------------*/

/**
 * Start gzip output buffering.
 *
 * HTML minification was removed because it requires full-page buffering
 * (ob_start callback), which prevents streaming and inflates TTFB.
 * Gzip alone provides excellent compression and allows chunked delivery
 * so the browser can start parsing <head> while the body is still generating.
 */
function pinlightning_start_gzip() {
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
}
add_action( 'template_redirect', 'pinlightning_start_gzip', -1 );

/*--------------------------------------------------------------
 * 4. RESOURCE HINTS
 *--------------------------------------------------------------*/

/**
 * Preconnect to CDN as early as possible (before preload, before CSS).
 */
function pinlightning_early_preconnect() {
	echo '<link rel="preconnect" href="https://myquickurl.com" crossorigin>' . "\n";
}
add_action( 'wp_head', 'pinlightning_early_preconnect', -1 );

/**
 * Add resource hints: dns-prefetch and preconnect.
 */
function pinlightning_resource_hints() {
	// DNS prefetch for external domains.
	echo '<link rel="dns-prefetch" href="//fonts.googleapis.com">' . "\n";

	// Preconnect to own domain.
	$site_domain = wp_parse_url( home_url(), PHP_URL_HOST );
	echo '<link rel="preconnect" href="' . esc_url( '//' . $site_domain ) . '" crossorigin>' . "\n";
}
add_action( 'wp_head', 'pinlightning_resource_hints', 1 );

/**
 * Preload the LCP hero image with fetchpriority and srcset support.
 *
 * Runs at priority 0 so it appears first in <head> (before CSS), starting
 * the image download as early as possible to reduce LCP resource load delay.
 *
 * Handles two cases:
 * 1. Post has a featured image → preload the resized featured image.
 * 2. Post has no featured image → scan content for the first image and preload it.
 */
function pinlightning_preload_lcp_image() {
	if ( ! is_singular() ) {
		return;
	}

	if ( has_post_thumbnail() ) {
		pinlightning_preload_featured_image();
	} else {
		pinlightning_preload_first_content_image();
	}
}
add_action( 'wp_head', 'pinlightning_preload_lcp_image', 0 );

/**
 * Preload a featured image (post thumbnail) as LCP.
 */
function pinlightning_preload_featured_image() {
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

		// href must match the rendered img src (720w) for preload to hit cache.
		$href   = $base_url . '&w=720&q=65';
		$srcset = implode( ', ', array(
			$base_url . '&w=360&q=65 360w',
			$base_url . '&w=480&q=65 480w',
			$base_url . '&w=720&q=65 720w',
		) );
		$sizes = '(max-width: 480px) 100vw, 720px';

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

/**
 * Preload the first content image as LCP when no featured image is set.
 *
 * Parses raw post content to find the first <img> src, then builds a
 * preload link matching the CDN resizer URLs that pinlightning_rewrite_cdn_img
 * will produce at render time.
 */
function pinlightning_preload_first_content_image() {
	$post = get_queried_object();
	if ( ! $post || empty( $post->post_content ) ) {
		return;
	}

	// Find the first <img> src in raw post content.
	if ( ! preg_match( '/<img\b[^>]*\bsrc=["\']([^"\']+)["\']/i', $post->post_content, $m ) ) {
		return;
	}

	$first_src = $m[1];

	// Handle myquickurl.com CDN images (most common on cheerlives.com).
	if ( strpos( $first_src, 'myquickurl.com' ) !== false ) {
		// Extract the CDN path (matches logic in pinlightning_rewrite_cdn_img).
		$cdn_path = '';
		if ( preg_match( '/myquickurl\.com\/img\.php\?.*?src=([^&"\']+)/', $first_src, $cm ) ) {
			$cdn_path = urldecode( $cm[1] );
		} elseif ( preg_match( '/myquickurl\.com\/img\/(.+?)(?:\?|$)/', $first_src, $cm ) ) {
			$cdn_path = $cm[1];
		} elseif ( preg_match( '/myquickurl\.com\/(.+?)(?:\?|$)/', $first_src, $cm ) ) {
			$cdn_path = $cm[1];
		}

		if ( empty( $cdn_path ) ) {
			return;
		}

		$cdn_path_encoded = rawurlencode( $cdn_path );
		$cdn_path_encoded = str_replace( '%2F', '/', $cdn_path_encoded );
		$base_url = 'https://myquickurl.com/img.php?src=' . $cdn_path_encoded;

		$href   = $base_url . '&w=665&q=80';
		$srcset = implode( ', ', array(
			$base_url . '&w=360&q=80 360w',
			$base_url . '&w=480&q=80 480w',
			$base_url . '&w=665&q=80 665w',
		) );
		$sizes = '(max-width: 480px) 100vw, 665px';

		$attrs  = 'rel="preload" as="image" href="' . esc_url( $href ) . '" fetchpriority="high"';
		$attrs .= ' imagesrcset="' . esc_attr( $srcset ) . '"';
		$attrs .= ' imagesizes="' . esc_attr( $sizes ) . '"';

		echo '<link ' . $attrs . '>' . "\n";
		return;
	}

	// Handle local upload images.
	if ( strpos( $first_src, '/wp-content/uploads/' ) !== false ) {
		preg_match( '#/wp-content/uploads/(.+?)(?:\?|$)#', $first_src, $pm );
		$uploads_path = isset( $pm[1] ) ? $pm[1] : '';
		if ( empty( $uploads_path ) ) {
			return;
		}
		$base_url = PINLIGHTNING_URI . '/img-resize.php?src=' . rawurlencode( $uploads_path );

		// href must match the rendered img src (720w) for preload to hit cache.
		$href   = $base_url . '&w=720&q=65';
		$srcset = implode( ', ', array(
			$base_url . '&w=360&q=65 360w',
			$base_url . '&w=480&q=65 480w',
			$base_url . '&w=720&q=65 720w',
		) );
		$sizes = '(max-width: 480px) 100vw, 720px';

		$attrs  = 'rel="preload" as="image" href="' . esc_url( $href ) . '" fetchpriority="high"';
		$attrs .= ' imagesrcset="' . esc_attr( $srcset ) . '"';
		$attrs .= ' imagesizes="' . esc_attr( $sizes ) . '"';

		echo '<link ' . $attrs . '>' . "\n";
		return;
	}

	// Fallback: preload the raw src as-is.
	echo '<link rel="preload" as="image" href="' . esc_url( $first_src ) . '" fetchpriority="high">' . "\n";
}

/*--------------------------------------------------------------
 * 5. SCRIPT OPTIMIZATION
 *--------------------------------------------------------------*/

/**
 * Deregister jQuery on frontend, dequeue unused scripts.
 */
function pinlightning_optimize_scripts() {
	if ( is_admin() ) {
		return;
	}

	// Deregister jQuery completely on frontend.
	wp_deregister_script( 'jquery-core' );
	wp_deregister_script( 'jquery-migrate' );
	wp_deregister_script( 'jquery' );

	// Always dequeue comment-reply — loaded on first scroll via pinlightning_scroll_deferred_assets().
	wp_dequeue_script( 'comment-reply' );
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

	// HTML pages: 1-hour browser cache + stale-while-revalidate for instant repeat visits.
	header( 'Cache-Control: public, max-age=3600, stale-while-revalidate=86400' );
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
