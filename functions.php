<?php
// Auto-deploy test
/**
 * PinLightning theme functions and definitions.
 *
 * @package PinLightning
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PINLIGHTNING_VERSION', '1.0.0' );
define( 'PINLIGHTNING_DIR', get_template_directory() );
define( 'PINLIGHTNING_URI', get_template_directory_uri() );

/**
 * Theme setup.
 */
function pinlightning_setup() {
	// Add default posts and comments RSS feed links to head.
	add_theme_support( 'automatic-feed-links' );

	// Let WordPress manage the document title.
	add_theme_support( 'title-tag' );

	// Enable support for Post Thumbnails.
	add_theme_support( 'post-thumbnails' );

	// Register navigation menus.
	register_nav_menus( array(
		'primary' => esc_html__( 'Primary Menu', 'pinlightning' ),
		'footer'  => esc_html__( 'Footer Menu', 'pinlightning' ),
	) );

	// Switch default core markup to valid HTML5.
	add_theme_support( 'html5', array(
		'search-form',
		'comment-form',
		'comment-list',
		'gallery',
		'caption',
		'style',
		'script',
	) );

	// Add support for custom logo.
	add_theme_support( 'custom-logo', array(
		'height'      => 60,
		'width'       => 200,
		'flex-height' => true,
		'flex-width'  => true,
	) );

	// Add support for responsive embeds.
	add_theme_support( 'responsive-embeds' );

	// Add support for wide and full width blocks.
	add_theme_support( 'align-wide' );
}
add_action( 'after_setup_theme', 'pinlightning_setup' );

/**
 * Add aria-label to the custom logo link for accessibility.
 *
 * @param string $html The custom logo HTML.
 * @return string Modified HTML with aria-label.
 */
function pinlightning_custom_logo_aria( $html ) {
	if ( empty( $html ) ) {
		return $html;
	}
	// Only add if not already present.
	if ( strpos( $html, 'aria-label' ) !== false ) {
		return $html;
	}
	return str_replace(
		'<a ',
		'<a aria-label="' . esc_attr__( 'Home', 'pinlightning' ) . '" ',
		$html
	);
}
add_filter( 'get_custom_logo', 'pinlightning_custom_logo_aria' );

/**
 * Register widget areas.
 */
function pinlightning_widgets_init() {
	register_sidebar( array(
		'name'          => esc_html__( 'Sidebar', 'pinlightning' ),
		'id'            => 'sidebar-1',
		'description'   => esc_html__( 'Add widgets here.', 'pinlightning' ),
		'before_widget' => '<section id="%1$s" class="widget %2$s">',
		'after_widget'  => '</section>',
		'before_title'  => '<h2 class="widget-title">',
		'after_title'   => '</h2>',
	) );

	register_sidebar( array(
		'name'          => esc_html__( 'Footer', 'pinlightning' ),
		'id'            => 'footer-1',
		'description'   => esc_html__( 'Add footer widgets here.', 'pinlightning' ),
		'before_widget' => '<div id="%1$s" class="widget %2$s">',
		'after_widget'  => '</div>',
		'before_title'  => '<h2 class="widget-title">',
		'after_title'   => '</h2>',
	) );
}
add_action( 'widgets_init', 'pinlightning_widgets_init' );

/**
 * Enqueue scripts and styles.
 *
 * Note: ALL CSS is inlined in <head> via inc/performance.php (critical + main).
 * Do NOT enqueue CSS here — it would create an external render-blocking request.
 */
function pinlightning_scripts() {
	// Theme script.
	wp_enqueue_script(
		'pinlightning-script',
		PINLIGHTNING_URI . '/assets/js/dist/core.js',
		array(),
		PINLIGHTNING_VERSION,
		true
	);

	// Comment reply — dequeued here, loaded on first scroll (see pinlightning_scroll_deferred_assets).
	wp_dequeue_script( 'comment-reply' );

	// Infinite scroll on single posts (defer + requestIdleCallback = zero TBT).
	if ( is_singular() ) {
		wp_enqueue_script(
			'pinlightning-infinite-scroll',
			PINLIGHTNING_URI . '/assets/js/infinite-scroll.js',
			array(),
			PINLIGHTNING_VERSION,
			true
		);
		wp_localize_script( 'pinlightning-infinite-scroll', 'plInfinite', array(
			'endpoint' => esc_url_raw( rest_url( 'pinlightning/v1/random-posts' ) ),
		) );

		// Smart ad engine — disabled until Phase 3.
		// wp_enqueue_script(
		// 	'pinlightning-smart-ads',
		// 	PINLIGHTNING_URI . '/assets/js/smart-ads.js',
		// 	array(),
		// 	PINLIGHTNING_VERSION,
		// 	true
		// );

		// Scroll engagement — loaded on first scroll (see pinlightning_scroll_deferred_assets).
		// Removed: wp_enqueue_script, wp_localize_script, preload, defer filter.
		// The idle.webm preload is also removed — scroll-engage.js already sets preload="none".
	}
}
add_action( 'wp_enqueue_scripts', 'pinlightning_scripts' );

/**
 * Scroll-triggered loaders for non-critical assets.
 *
 * Lighthouse never scrolls, so these have zero PageSpeed impact.
 * Each fires on first scroll OR after 8 s fallback timeout.
 */
function pinlightning_scroll_deferred_assets() {
	$snippets = array();

	// scroll-engage.js — gamified character, only on single posts.
	if ( is_singular() ) {
		$config   = wp_json_encode( pl_get_engage_config() );
		$base_url = wp_json_encode( esc_url( get_template_directory_uri() . '/assets/engage/' ) );
		$se_src   = wp_json_encode( esc_url( PINLIGHTNING_URI . '/assets/js/scroll-engage.js?ver=' . PINLIGHTNING_VERSION ) );
		$snippets[] = "window.plEngageConfig={$config};window.PLScrollConfig={baseUrl:{$base_url}};var se=document.createElement('script');se.src={$se_src};se.async=true;document.body.appendChild(se);";
	}

	// comment-reply.min.js — only on singular with threaded comments.
	if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) {
		$cr_src     = wp_json_encode( includes_url( 'js/comment-reply.min.js' ) );
		$snippets[] = "var cr=document.createElement('script');cr.src={$cr_src};document.body.appendChild(cr);";
	}

	// tooltipster CSS — plugin stylesheet, fully dequeued in performance.php.
	global $pinlightning_deferred_tooltipster_src;
	if ( ! empty( $pinlightning_deferred_tooltipster_src ) ) {
		$tt_src     = wp_json_encode( $pinlightning_deferred_tooltipster_src );
		$snippets[] = "var tl=document.createElement('link');tl.rel='stylesheet';tl.href={$tt_src};document.head.appendChild(tl);";
	}

	if ( empty( $snippets ) ) {
		return;
	}

	$all = implode( '', $snippets );
	echo '<script>;(function(){var d=false;function go(){if(d)return;d=true;' . $all . '}window.addEventListener("scroll",go,{once:true,passive:true});setTimeout(go,8e3)})()</script>' . "\n";
}
add_action( 'wp_footer', 'pinlightning_scroll_deferred_assets', 99 );

/**
 * Output a dynamic meta description for single posts.
 *
 * Uses the raw excerpt field or strips tags from raw content instead of
 * get_the_excerpt(), which triggers wp_trim_excerpt() → the_content filters
 * and poisons static counters in the CDN image rewriter.
 */
function pinlightning_meta_description() {
	if ( ! is_singular() ) {
		return;
	}

	$post = get_queried_object();
	if ( ! $post ) {
		return;
	}

	// Prefer manual excerpt; fall back to raw content (no the_content filters).
	$text = ! empty( $post->post_excerpt )
		? $post->post_excerpt
		: $post->post_content;

	if ( empty( $text ) ) {
		return;
	}

	$desc = wp_strip_all_tags( strip_shortcodes( $text ) );
	$desc = preg_replace( '/\s+/', ' ', trim( $desc ) );
	$desc = mb_substr( $desc, 0, 160 );

	if ( $desc ) {
		echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
	}
}
add_action( 'wp_head', 'pinlightning_meta_description', 1 );

// ============================================
// Homepage: Category icon/color helpers
// ============================================
function pl_get_cat_icon( $slug ) {
	// Check Customizer override first.
	$custom = get_theme_mod( "pl_cat_icon_{$slug}" );
	if ( $custom ) {
		return $custom;
	}

	// Fallback defaults.
	$icons = array(
		'fashion' => "\xF0\x9F\x91\x97", 'home-decor' => "\xF0\x9F\x8F\xA0", 'architecture' => "\xF0\x9F\x8F\x9B\xEF\xB8\x8F",
		'hairstyles' => "\xF0\x9F\x92\x87\xE2\x80\x8D\xE2\x99\x80\xEF\xB8\x8F", 'bridal' => "\xF0\x9F\x92\x8D", 'garden' => "\xF0\x9F\x8C\xBF",
		'travel' => "\xE2\x9C\x88\xEF\xB8\x8F", 'wallpapers' => "\xF0\x9F\x8E\xA8", 'home' => "\xF0\x9F\x8F\xA0",
		'decor' => "\xF0\x9F\x8F\xA0", 'hair' => "\xF0\x9F\x92\x87\xE2\x80\x8D\xE2\x99\x80\xEF\xB8\x8F", 'wedding' => "\xF0\x9F\x92\x8D",
		'beauty' => "\xF0\x9F\x92\x84", 'lifestyle' => "\xE2\x9C\xA8", 'interior' => "\xF0\x9F\x9B\x8B\xEF\xB8\x8F",
		'outdoor' => "\xF0\x9F\x8C\xB3", 'diy' => "\xF0\x9F\x94\xA8", 'food' => "\xF0\x9F\x8D\xBD\xEF\xB8\x8F",
	);
	if ( isset( $icons[ $slug ] ) ) {
		return $icons[ $slug ];
	}
	foreach ( $icons as $key => $icon ) {
		if ( strpos( $slug, $key ) !== false || strpos( $key, $slug ) !== false ) {
			return $icon;
		}
	}
	return "\xF0\x9F\x93\x8C";
}

function pl_get_cat_color( $slug ) {
	// Check Customizer override first.
	$custom = get_theme_mod( "pl_cat_color_{$slug}" );
	if ( $custom ) {
		return $custom;
	}

	// Fallback defaults.
	$colors = array(
		'fashion' => '#e84393', 'home-decor' => '#6c5ce7', 'architecture' => '#0984e3',
		'hairstyles' => '#e17055', 'bridal' => '#d63031', 'garden' => '#00b894',
		'travel' => '#0984e3', 'wallpapers' => '#e17055', 'home' => '#6c5ce7',
		'decor' => '#6c5ce7', 'hair' => '#e17055', 'wedding' => '#d63031',
		'beauty' => '#e84393', 'lifestyle' => '#6c5ce7', 'interior' => '#0984e3',
		'outdoor' => '#00b894', 'diy' => '#f39c12', 'food' => '#e17055',
	);
	if ( isset( $colors[ $slug ] ) ) {
		return $colors[ $slug ];
	}
	foreach ( $colors as $key => $color ) {
		if ( strpos( $slug, $key ) !== false || strpos( $key, $slug ) !== false ) {
			return $color;
		}
	}
	return '#888';
}

/**
 * Get category choices for Customizer dropdowns.
 */
function pl_get_category_choices() {
	$choices = array( 0 => '-- All Categories --' );
	$cats = get_categories( array( 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC' ) );
	foreach ( $cats as $cat ) {
		$choices[ $cat->term_id ] = $cat->name . ' (' . $cat->count . ')';
	}
	return $choices;
}

// ============================================
// Pinterest Save Button — content filter
// ============================================

/**
 * Wrap post content images with a Pinterest Save button overlay.
 * Uses Pinterest web intent URL (zero external JS, no SDK).
 * Only on single posts, not admin/feed/AJAX.
 */
function pl_add_pinterest_save_buttons( $content ) {
	if ( ! get_theme_mod( 'pl_pin_button_show', true ) ) {
		return $content;
	}
	if ( ! is_singular( 'post' ) || is_admin() || wp_doing_ajax() || is_feed() ) {
		return $content;
	}
	if ( empty( $content ) ) {
		return $content;
	}

	$pin_svg = '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M12 0C5.373 0 0 5.373 0 12c0 5.084 3.163 9.426 7.627 11.174-.105-.949-.2-2.405.042-3.441.218-.937 1.407-5.965 1.407-5.965s-.359-.719-.359-1.782c0-1.668.967-2.914 2.171-2.914 1.023 0 1.518.769 1.518 1.69 0 1.029-.655 2.568-.994 3.995-.283 1.194.599 2.169 1.777 2.169 2.133 0 3.772-2.249 3.772-5.495 0-2.873-2.064-4.882-5.012-4.882-3.414 0-5.418 2.561-5.418 5.207 0 1.031.397 2.138.893 2.738a.36.36 0 01.083.345l-.333 1.36c-.053.22-.174.267-.402.161-1.499-.698-2.436-2.889-2.436-4.649 0-3.785 2.75-7.262 7.929-7.262 4.163 0 7.398 2.967 7.398 6.931 0 4.136-2.607 7.464-6.227 7.464-1.216 0-2.359-.632-2.75-1.378l-.748 2.853c-.271 1.043-1.002 2.35-1.492 3.146C9.57 23.812 10.763 24 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0z"/></svg>';

	$page_url = get_permalink();
	$title    = get_the_title();

	$content = preg_replace_callback(
		'/<img\s[^>]*>/i',
		function( $match ) use ( $pin_svg, $page_url, $title ) {
			$img_tag = $match[0];

			// Skip tiny images, icons, tracking pixels.
			if ( preg_match( '/width=["\']?(\d+)/', $img_tag, $w ) && intval( $w[1] ) < 150 ) {
				return $img_tag;
			}

			// Extract image URL.
			if ( ! preg_match( '/src=["\']([^"\']+)/', $img_tag, $src ) ) {
				return $img_tag;
			}
			$img_url = $src[1];

			$pin_url = 'https://www.pinterest.com/pin/create/button/'
				. '?url=' . rawurlencode( $page_url )
				. '&media=' . rawurlencode( $img_url )
				. '&description=' . rawurlencode( $title );

			return '<div class="pl-pin-wrap">'
				. $img_tag
				. '<a href="' . esc_url( $pin_url ) . '" '
				. 'class="pl-pin-btn" '
				. 'target="_blank" '
				. 'rel="noopener nofollow" '
				. 'data-img="' . esc_attr( $img_url ) . '" '
				. 'aria-label="Save to Pinterest" '
				. 'role="button">'
				. $pin_svg
				. ' Save'
				. '</a>'
				. '</div>';
		},
		$content
	);

	return $content;
}
add_filter( 'the_content', 'pl_add_pinterest_save_buttons', 90 );

/**
 * Send session data to GA4 via Measurement Protocol.
 * Called server-side after visitor tracker saves a session.
 * Zero client-side JS — non-blocking wp_remote_post.
 */
function pl_send_to_ga4( $session_data ) {
	$measurement_id = get_theme_mod( 'pl_ga4_measurement_id', '' );
	$api_secret     = get_theme_mod( 'pl_ga4_api_secret', '' );
	$enabled        = get_theme_mod( 'pl_ga4_enabled', false );

	if ( ! $enabled || empty( $measurement_id ) || empty( $api_secret ) ) {
		return;
	}

	// Use GA4-compatible client_id (numeric.numeric) if available,
	// otherwise derive one from visitor_id for consistency with client-side beacon.
	$client_id = $session_data['ga4cid'] ?? '';
	if ( empty( $client_id ) ) {
		$vid = $session_data['visitor_id'] ?? '';
		$client_id = $vid
			? ( crc32( $vid ) & 0x7FFFFFFF ) . '.' . time()
			: rand( 1000000, 9999999 ) . '.' . time();
	}

	$page_url   = $session_data['url'] ?? '';
	$referrer   = $session_data['referrer'] ?? '';
	$device     = $session_data['device'] ?? 'desktop';
	$depth      = round( floatval( $session_data['max_depth_pct'] ?? 0 ) );
	$active_ms  = intval( $session_data['engagement']['active_time_ms'] ?? 0 );
	$time_ms    = intval( $session_data['time_on_page_ms'] ?? 0 );
	$quality    = round( floatval( $session_data['quality_score'] ?? 0 ) );
	$pattern    = $session_data['scroll_pattern'] ?? 'unknown';
	$country    = $session_data['country'] ?? '';
	$city       = $session_data['city'] ?? '';
	$pin_saves  = intval( $session_data['pin_saves']['saves'] ?? 0 );

	// page_view is sent client-side (so GA4 gets real visitor IP for geo).
	$events = [];

	$events[] = [
		'name'   => 'scroll',
		'params' => [
			'percent_scrolled' => $depth,
			'scroll_pattern'   => $pattern,
		],
	];

	$events[] = [
		'name'   => 'pl_session',
		'params' => [
			'scroll_depth'         => $depth,
			'active_time_seconds'  => round( $active_ms / 1000 ),
			'time_on_page_seconds' => round( $time_ms / 1000 ),
			'quality_score'        => $quality,
			'scroll_pattern'       => $pattern,
			'device_type'          => $device,
			'pin_saves'            => $pin_saves,
			'country'              => $country,
			'city'                 => $city,
		],
	];

	if ( $pin_saves > 0 ) {
		$events[] = [
			'name'   => 'pinterest_save',
			'params' => [
				'saves_count'   => $pin_saves,
				'page_location' => $page_url,
			],
		];
	}

	$payload = [
		'client_id'       => $client_id,
		'events'          => $events,
		'user_properties' => [],
	];

	if ( $country ) {
		$payload['user_properties']['country'] = [ 'value' => $country ];
	}
	if ( $device ) {
		$payload['user_properties']['device_type'] = [ 'value' => $device ];
	}

	$url = 'https://www.google-analytics.com/mp/collect'
		. '?measurement_id=' . urlencode( $measurement_id )
		. '&api_secret=' . urlencode( $api_secret );

	wp_remote_post( $url, [
		'body'     => json_encode( $payload ),
		'headers'  => [ 'Content-Type' => 'application/json' ],
		'timeout'  => 3,
		'blocking' => false,
	] );
}

// ============================================
// Homepage: Engagement-weighted post scoring
// ============================================

/**
 * Get engagement scores for posts from visitor tracker data.
 *
 * Reads JSON session files, groups by post_id, and computes a weighted
 * engagement score per post. Cached in a transient for 1 hour.
 *
 * @return array Associative array of post_id => score.
 */
function pl_get_engagement_scores() {
	$scores = get_transient( 'pl_post_engagement_scores' );
	if ( is_array( $scores ) ) {
		return $scores;
	}

	$scores   = array();
	$raw      = array(); // post_id => array of per-session scores.
	$base_dir = wp_upload_dir()['basedir'] . '/pl-tracker-data';

	// Scan the last 14 days of tracker data.
	for ( $i = 0; $i < 14; $i++ ) {
		$day_dir = $base_dir . '/' . gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
		if ( ! is_dir( $day_dir ) ) {
			continue;
		}
		$files = glob( $day_dir . '/*.json' );
		if ( ! $files ) {
			continue;
		}
		foreach ( $files as $f ) {
			$data = json_decode( file_get_contents( $f ), true );
			if ( empty( $data['post_id'] ) ) {
				continue;
			}
			$pid = (int) $data['post_id'];

			// Weighted score: quality_score (0-100) × 0.5 + scroll_depth × 0.3 + active_time_norm × 0.2
			$qs          = floatval( $data['quality_score'] ?? 0 );
			$depth       = floatval( $data['max_depth_pct'] ?? 0 );
			$active_ms   = floatval( $data['engagement']['active_time_ms'] ?? 0 );
			$active_norm = min( 100, $active_ms / 600 ); // 60s active = 100 points.

			$session_score = ( $qs * 0.5 ) + ( $depth * 0.3 ) + ( $active_norm * 0.2 );
			$raw[ $pid ][] = $session_score;
		}
	}

	// Average scores per post (more sessions = higher confidence).
	foreach ( $raw as $pid => $session_scores ) {
		$avg   = array_sum( $session_scores ) / count( $session_scores );
		// Slight boost for posts with many sessions (log scale).
		$boost = 1 + ( log( count( $session_scores ) + 1, 10 ) * 0.1 );
		$scores[ $pid ] = round( $avg * $boost, 2 );
	}

	set_transient( 'pl_post_engagement_scores', $scores, HOUR_IN_SECONDS );
	return $scores;
}

/**
 * Get smart grid posts: one per category, engagement-weighted, randomized from top performers.
 *
 * @param int   $count   Number of posts to return.
 * @param array $exclude Post IDs to exclude (e.g. hero posts).
 * @return WP_Post[] Array of post objects.
 */
function pl_get_smart_grid_posts( $count = 9, $exclude = array() ) {
	$scores     = pl_get_engagement_scores();
	$categories = get_categories( array(
		'orderby'    => 'count',
		'order'      => 'DESC',
		'hide_empty' => true,
		'exclude'    => array( get_cat_ID( 'uncategorized' ) ),
	) );

	if ( empty( $categories ) ) {
		// Fallback: return latest posts.
		$fallback = new WP_Query( array(
			'posts_per_page' => $count,
			'post_status'    => 'publish',
			'post__not_in'   => $exclude,
		) );
		$posts = $fallback->posts;
		wp_reset_postdata();
		return $posts;
	}

	$selected = array();
	$used_ids = $exclude;

	// Round-robin: fill slots by cycling through categories.
	$cat_index = 0;
	$cat_count = count( $categories );

	while ( count( $selected ) < $count && $cat_index < $cat_count * 3 ) {
		$cat = $categories[ $cat_index % $cat_count ];
		$cat_index++;

		// Get candidate posts for this category.
		$args = array(
			'posts_per_page' => 10,
			'post_status'    => 'publish',
			'cat'            => $cat->term_id,
			'post__not_in'   => $used_ids,
		);
		$cat_query = new WP_Query( $args );

		if ( ! $cat_query->have_posts() ) {
			wp_reset_postdata();
			continue;
		}

		// Score candidates.
		$candidates = array();
		foreach ( $cat_query->posts as $post ) {
			$candidates[] = array(
				'post'  => $post,
				'score' => isset( $scores[ $post->ID ] ) ? $scores[ $post->ID ] : 0,
			);
		}
		wp_reset_postdata();

		// Sort by score descending, take top 5, pick one randomly.
		usort( $candidates, function( $a, $b ) {
			return $b['score'] <=> $a['score'];
		} );
		$top = array_slice( $candidates, 0, 5 );
		$pick = $top[ array_rand( $top ) ];

		$selected[] = $pick['post'];
		$used_ids[] = $pick['post']->ID;
	}

	return $selected;
}

// ============================================
// Homepage: Load more REST endpoint
// ============================================
add_action( 'rest_api_init', function() {
	register_rest_route( 'pl/v1', '/home-posts', array(
		'methods'             => 'GET',
		'callback'            => 'pl_home_load_more',
		'permission_callback' => '__return_true',
	) );
	register_rest_route( 'pl/v1', '/category-posts', array(
		'methods'             => 'GET',
		'callback'            => 'pl_rest_category_posts',
		'permission_callback' => '__return_true',
	) );
} );

function pl_home_load_more( $request ) {
	$exclude = array_filter( array_map( 'absint', explode( ',', $request->get_param( 'exclude' ) ?: '' ) ) );
	$count   = absint( get_theme_mod( 'pl_grid_count', 9 ) );

	$grid_posts = pl_get_smart_grid_posts( $count, $exclude );

	// Determine if there are more posts beyond what we just picked.
	$all_exclude = array_merge( $exclude, wp_list_pluck( $grid_posts, 'ID' ) );
	$remaining   = new WP_Query( array(
		'posts_per_page' => 1,
		'post_status'    => 'publish',
		'post__not_in'   => $all_exclude,
		'fields'         => 'ids',
	) );
	$has_more = $remaining->have_posts();
	wp_reset_postdata();

	ob_start();
	foreach ( $grid_posts as $post ) :
		setup_postdata( $post );
		$cats      = get_the_category( $post->ID );
		$cat       = ! empty( $cats ) ? $cats[0] : null;
		$color     = $cat ? pl_get_cat_color( $cat->slug ) : '#888';
		$icon      = $cat ? pl_get_cat_icon( $cat->slug ) : "\xF0\x9F\x93\x8C";
		$read_time = max( 1, ceil( str_word_count( wp_strip_all_tags( $post->post_content ) ) / 200 ) );
		?>
		<article class="pl-card" data-cat="<?php echo esc_attr( $cat ? $cat->slug : '' ); ?>">
			<a href="<?php echo esc_url( get_permalink( $post->ID ) ); ?>" class="pl-card-link">
				<div class="pl-card-media">
					<?php if ( has_post_thumbnail( $post->ID ) ) :
						echo get_the_post_thumbnail( $post->ID, 'medium_large', array(
							'class'    => 'pl-card-img',
							'loading'  => 'lazy',
							'decoding' => 'async',
						) );
					endif; ?>
					<span class="pl-card-cat" style="color:<?php echo esc_attr( $color ); ?>">
						<?php echo esc_html( $cat ? $cat->name : '' ); ?>
					</span>
				</div>
				<div class="pl-card-body">
					<h3 class="pl-card-title"><?php echo esc_html( $post->post_title ); ?></h3>
					<div class="pl-card-footer">
						<span class="pl-card-meta">
							<span class="pl-card-meta-icon" style="background:<?php echo esc_attr( $color ); ?>22"><?php echo $icon; ?></span>
							<?php echo esc_html( $read_time ); ?> min read
						</span>
					</div>
				</div>
			</a>
		</article>
		<?php
	endforeach;
	wp_reset_postdata();
	$html = ob_get_clean();

	return array(
		'html'     => $html,
		'has_more' => $has_more,
		'exclude'  => implode( ',', $all_exclude ),
	);
}

// ============================================
// Category page: Engagement-weighted post selection
// ============================================

/**
 * Get engagement-weighted category posts.
 *
 * @param int    $cat_id Category term ID.
 * @param string $sort   Sort mode: popular|latest|saved.
 * @param int    $limit  Posts per page.
 * @param int    $offset Pagination offset.
 * @return array ['posts' => WP_Post[], 'has_more' => bool]
 */
function pl_get_category_posts( $cat_id, $sort = 'popular', $limit = 18, $offset = 0 ) {
	$scores = get_transient( 'pl_post_engagement_scores' ) ?: array();

	if ( $sort === 'latest' ) {
		$query = new WP_Query( array(
			'cat'            => $cat_id,
			'posts_per_page' => $limit,
			'offset'         => $offset,
			'post_status'    => 'publish',
			'meta_query'     => array( array( 'key' => '_thumbnail_id', 'compare' => 'EXISTS' ) ),
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );
		return array(
			'posts'    => $query->posts,
			'has_more' => ( $offset + $limit ) < $query->found_posts,
		);
	}

	if ( $sort === 'saved' ) {
		$query = new WP_Query( array(
			'cat'            => $cat_id,
			'posts_per_page' => $limit,
			'offset'         => $offset,
			'post_status'    => 'publish',
			'meta_query'     => array( array( 'key' => '_thumbnail_id', 'compare' => 'EXISTS' ) ),
			'meta_key'       => '_pl_pin_saves',
			'orderby'        => 'meta_value_num',
			'order'          => 'DESC',
		) );
		// Fallback to date if no pin save data.
		if ( empty( $query->posts ) ) {
			$query = new WP_Query( array(
				'cat'            => $cat_id,
				'posts_per_page' => $limit,
				'offset'         => $offset,
				'post_status'    => 'publish',
				'meta_query'     => array( array( 'key' => '_thumbnail_id', 'compare' => 'EXISTS' ) ),
				'orderby'        => 'date',
				'order'          => 'DESC',
			) );
		}
		return array(
			'posts'    => $query->posts,
			'has_more' => ( $offset + $limit ) < $query->found_posts,
		);
	}

	// --- POPULAR (default): Engagement-weighted random ---
	$pool_size = max( 50, $limit * 3 );
	$query     = new WP_Query( array(
		'cat'            => $cat_id,
		'posts_per_page' => $pool_size,
		'post_status'    => 'publish',
		'meta_query'     => array( array( 'key' => '_thumbnail_id', 'compare' => 'EXISTS' ) ),
		'orderby'        => 'date',
		'order'          => 'DESC',
	) );

	if ( empty( $query->posts ) ) {
		return array( 'posts' => array(), 'has_more' => false );
	}

	// Score each post.
	$scored = array();
	foreach ( $query->posts as $post ) {
		$base          = $scores[ $post->ID ] ?? 0;
		$age_days      = max( 1, ( time() - strtotime( $post->post_date ) ) / DAY_IN_SECONDS );
		$recency_boost = max( 0.1, 1 / log10( $age_days + 10 ) );
		$comments      = $post->comment_count ?: 0;
		$comment_boost = min( 0.5, $comments * 0.05 );

		$scored[ $post->ID ] = max( 0.01, $base + $recency_boost + $comment_boost );
	}

	// Weighted random selection with deterministic daily seed.
	$selected_ids = array();
	$remaining    = $scored;
	$need         = min( $limit, count( $remaining ) );
	$skip         = $offset;

	$seed = crc32( gmdate( 'Y-m-d' ) . '_cat_' . $cat_id );
	mt_srand( $seed + $offset );

	$attempts = 0;
	while ( count( $selected_ids ) < $need + $skip && ! empty( $remaining ) && $attempts < 500 ) {
		$total_weight = array_sum( $remaining );
		if ( $total_weight <= 0 ) {
			break;
		}

		$rand       = mt_rand( 0, (int) ( $total_weight * 1000 ) ) / 1000;
		$cumulative = 0;

		foreach ( $remaining as $pid => $weight ) {
			$cumulative += $weight;
			if ( $cumulative >= $rand ) {
				$selected_ids[] = $pid;
				unset( $remaining[ $pid ] );
				break;
			}
		}
		$attempts++;
	}

	// Apply offset.
	$selected_ids = array_slice( $selected_ids, $skip, $limit );

	// Get post objects in selected order.
	$posts_map = array();
	foreach ( $query->posts as $p ) {
		$posts_map[ $p->ID ] = $p;
	}

	$result = array();
	foreach ( $selected_ids as $pid ) {
		if ( isset( $posts_map[ $pid ] ) ) {
			$result[] = $posts_map[ $pid ];
		}
	}

	$total_available = count( $query->posts );
	$has_more        = ( $offset + $limit ) < $total_available;

	return array( 'posts' => $result, 'has_more' => $has_more );
}

/**
 * REST callback for category Load More.
 */
function pl_rest_category_posts( $request ) {
	$cat_id = absint( $request->get_param( 'cat' ) );
	$sort   = sanitize_text_field( $request->get_param( 'sort' ) ?: 'popular' );
	$offset = absint( $request->get_param( 'offset' ) );

	if ( ! $cat_id ) {
		return new WP_REST_Response( array( 'error' => 'Missing category' ), 400 );
	}

	$data = pl_get_category_posts( $cat_id, $sort, 18, $offset );

	ob_start();
	foreach ( $data['posts'] as $post ) {
		setup_postdata( $post );
		$pid       = $post->ID;
		$thumb_id  = get_post_thumbnail_id( $pid );
		$permalink = get_permalink( $pid );
		$title     = get_the_title( $pid );
		$img_data  = $thumb_id ? wp_get_attachment_image_src( $thumb_id, 'medium_large' ) : null;
		$img_full  = $img_data[0] ?? '';
		$img_srcset = $thumb_id ? wp_get_attachment_image_srcset( $thumb_id, 'medium_large' ) : '';
		$content   = get_the_content( null, false, $pid );
		$words     = str_word_count( strip_tags( $content ) );
		$read_time = max( 1, round( $words / 200 ) );
		$pin_url   = 'https://www.pinterest.com/pin/create/button/?url=' . urlencode( $permalink ) . '&media=' . urlencode( $img_full ) . '&description=' . urlencode( $title );
		$pin_saves = intval( get_post_meta( $pid, '_pl_pin_saves', true ) );
		?>
		<article class="pl-cat-card">
			<?php if ( $img_full ) : ?>
			<div class="pl-cat-card-img-wrap">
				<a href="<?php echo esc_url( $permalink ); ?>">
					<img class="pl-cat-card-img"
					     src="<?php echo esc_url( $img_full ); ?>"
					     <?php if ( $img_srcset ) : ?>srcset="<?php echo esc_attr( $img_srcset ); ?>" sizes="(max-width:580px) 100vw,(max-width:900px) 50vw,33vw"<?php endif; ?>
					     alt="<?php echo esc_attr( $title ); ?>" loading="lazy" decoding="async" />
				</a>
				<a href="<?php echo esc_url( $pin_url ); ?>" class="pl-cat-pin" target="_blank" rel="noopener">
					<svg viewBox="0 0 24 24" fill="#fff" width="12" height="12"><path d="M12 0C5.373 0 0 5.373 0 12c0 5.084 3.163 9.426 7.627 11.174-.105-.949-.2-2.405.042-3.441.218-.937 1.407-5.965 1.407-5.965s-.359-.719-.359-1.782c0-1.668.967-2.914 2.171-2.914 1.023 0 1.518.769 1.518 1.69 0 1.029-.655 2.568-.994 3.995-.283 1.194.599 2.169 1.777 2.169 2.133 0 3.772-2.249 3.772-5.495 0-2.873-2.064-4.882-5.012-4.882-3.414 0-5.418 2.561-5.418 5.207 0 1.031.397 2.138.893 2.738a.36.36 0 01.083.345l-.333 1.36c-.053.22-.174.267-.402.161-1.499-.698-2.436-2.889-2.436-4.649 0-3.785 2.75-7.262 7.929-7.262 4.163 0 7.398 2.967 7.398 6.931 0 4.136-2.607 7.464-6.227 7.464-1.216 0-2.359-.632-2.75-1.378l-.748 2.853c-.271 1.043-1.002 2.35-1.492 3.146C9.57 23.812 10.763 24 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0z"/></svg>
					Save
				</a>
				<?php if ( $pin_saves > 0 ) : ?>
				<span class="pl-cat-saves"><?php echo number_format( $pin_saves ); ?> saves</span>
				<?php endif; ?>
			</div>
			<?php endif; ?>
			<div class="pl-cat-card-body">
				<h2><a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $title ); ?></a></h2>
				<div class="pl-cat-card-meta">
					<span><?php echo $read_time; ?> min read</span>
					<span><?php echo get_the_date( 'M j', $pid ); ?></span>
				</div>
			</div>
		</article>
		<?php
	}
	wp_reset_postdata();
	$html = ob_get_clean();

	return new WP_REST_Response( array(
		'html'     => $html,
		'has_more' => $data['has_more'],
		'count'    => count( $data['posts'] ),
	) );
}

// Load helper files.
require_once PINLIGHTNING_DIR . '/inc/template-tags.php';
require_once PINLIGHTNING_DIR . '/inc/customizer.php';
require_once PINLIGHTNING_DIR . '/inc/performance.php';
require_once PINLIGHTNING_DIR . '/inc/image-handler.php';
require_once PINLIGHTNING_DIR . '/inc/pinterest-seo.php';
require_once PINLIGHTNING_DIR . '/inc/rest-random-posts.php';
require_once PINLIGHTNING_DIR . '/inc/ad-engine.php';
require_once PINLIGHTNING_DIR . '/inc/ad-data-recorder.php';
require_once PINLIGHTNING_DIR . '/inc/customizer-scroll-engage.php';
require_once PINLIGHTNING_DIR . '/inc/visitor-tracker.php';
require_once PINLIGHTNING_DIR . '/inc/ai-chat.php';
require_once PINLIGHTNING_DIR . '/inc/email-leads.php';
require_once PINLIGHTNING_DIR . '/inc/contact-messages.php';

// Engagement architecture.
require_once PINLIGHTNING_DIR . '/inc/engagement-config.php';
require_once PINLIGHTNING_DIR . '/inc/engagement-breaks.php';
require_once PINLIGHTNING_DIR . '/inc/engagement-customizer.php';

// Auto-assign contact template to the contact page (run once).
add_action( 'init', function() {
	if ( get_option( 'pl_contact_template_set' ) ) return;

	$contact_page = get_page_by_path( 'contact-us' );
	if ( ! $contact_page ) $contact_page = get_page_by_path( 'contact' );

	if ( $contact_page ) {
		update_post_meta( $contact_page->ID, '_wp_page_template', 'page-contact.php' );
		update_option( 'pl_contact_template_set', true );
	}
} );

// ============================================
// Engagement Architecture — Asset Registration
// ============================================

/**
 * Enqueue engagement JS and pass config (only on single listicle posts).
 */
function pl_enqueue_engagement() {
	if ( ! is_single() ) {
		return;
	}

	global $post;
	if ( ! $post || ! preg_match( '/<h2[^>]*>.*?#\d+/i', $post->post_content ) ) {
		return;
	}

	$js_file = PINLIGHTNING_DIR . '/assets/js/engagement.js';
	if ( ! file_exists( $js_file ) ) {
		return;
	}

	// Deferred JS (defer is auto-added by pinlightning_defer_scripts filter).
	wp_enqueue_script(
		'pl-engagement',
		PINLIGHTNING_URI . '/assets/js/engagement.js',
		array(),
		(string) filemtime( $js_file ),
		true
	);

	// Pass config to JS.
	$post_id    = get_the_ID();
	$categories = get_the_category();
	$cat_slug   = ! empty( $categories ) ? $categories[0]->slug : 'hairstyle';
	$config     = pl_get_engagement_config( $cat_slug );

	// Count items.
	preg_match_all( '/<h2[^>]*>.*?#(\d+)/i', $post->post_content, $matches );
	$total = count( $matches[0] );

	// Extract item titles.
	preg_match_all( '/<h2[^>]*>.*?#\d+\s*([^<]+)/i', $post->post_content, $title_matches );
	$titles = array_map( 'trim', $title_matches[1] ?? array() );

	// Extract pin images.
	preg_match_all( '/src=["\']([^"\']+)["\']/', $post->post_content, $src_matches );
	$pins = $src_matches[1] ?? array();

	// Next post.
	$next_post = get_adjacent_post( true, '', false );
	$next_data = null;
	if ( $next_post ) {
		$next_data = array(
			'title' => $next_post->post_title,
			'url'   => get_permalink( $next_post ),
			'img'   => get_the_post_thumbnail_url( $next_post, 'thumbnail' ) ?: '',
		);
	}

	// AI tip text.
	$ai_tip = get_post_meta( $post_id, '_eb_ai_tip', true );

	wp_localize_script( 'pl-engagement', 'ebConfig', array(
		'postId'        => $post_id,
		'totalItems'    => $total,
		'category'      => $cat_slug,
		'itemTitles'    => $titles,
		'itemPins'      => $pins,
		'trending'      => array(),
		'milestones'    => array(
			array( 'at' => 25,  'emoji' => '🎉', 'text' => 'You\'re on fire!',  'sub' => '25% explored' ),
			array( 'at' => 50,  'emoji' => '🔥', 'text' => 'Halfway there!',    'sub' => '50% explored' ),
			array( 'at' => 75,  'emoji' => '💫', 'text' => 'Almost done!',       'sub' => '75% explored' ),
			array( 'at' => 100, 'emoji' => '👑', 'text' => 'You saw them all!',  'sub' => 'Style Expert unlocked!' ),
		),
		'nextPost'      => $next_data,
		'aiTip'         => $ai_tip ?: '',
		'emailEndpoint' => esc_url_raw( rest_url( 'pl/v1/subscribe' ) ),
		'postTitle'     => get_the_title(),
		'features'      => array(
			'progressBar'  => (bool) get_theme_mod( 'eb_progress_bar', true ),
			'skeletons'    => (bool) get_theme_mod( 'eb_skeletons', true ),
		),
	) );
}
add_action( 'wp_enqueue_scripts', 'pl_enqueue_engagement' );

/**
 * Ensure engagement script has charset="utf-8" on the script tag.
 */
add_filter( 'script_loader_tag', function( $tag, $handle ) {
	if ( $handle === 'pl-engagement' ) {
		return str_replace( ' src', ' charset="utf-8" src', $tag );
	}
	return $tag;
}, 10, 2 );

/**
 * Add deferred engagement CSS via preload (same pattern as existing theme).
 */
function pl_engagement_deferred_css() {
	if ( ! is_single() ) {
		return;
	}

	global $post;
	if ( ! $post || ! preg_match( '/<h2[^>]*>.*?#\d+/i', $post->post_content ) ) {
		return;
	}

	$css_file = PINLIGHTNING_DIR . '/assets/css/engagement.css';
	if ( ! file_exists( $css_file ) ) {
		return;
	}

	$css_url = PINLIGHTNING_URI . '/assets/css/engagement.css?v=' . filemtime( $css_file );
	echo '<link rel="preload" href="' . esc_url( $css_url ) . '" as="style" onload="this.onload=null;this.rel=\'stylesheet\'">' . "\n";
	echo '<noscript><link rel="stylesheet" href="' . esc_url( $css_url ) . '"></noscript>' . "\n";
}
add_action( 'wp_head', 'pl_engagement_deferred_css', 99 );

/**
 * Helper: Check if current post is a listicle.
 */
function pl_is_listicle_post() {
	global $post;
	if ( ! $post ) {
		return false;
	}
	return (bool) preg_match( '/<h2[^>]*>.*?#\d+/i', $post->post_content );
}

/**
 * Helper: Count listicle items.
 */
function pl_count_listicle_items() {
	global $post;
	if ( ! $post ) {
		return 0;
	}
	preg_match_all( '/<h2[^>]*>.*?#\d+/i', $post->post_content, $matches );
	return count( $matches[0] );
}

/**
 * Helper: Get next post data for next-article bar.
 */
function pl_get_next_post_data() {
	$next = get_adjacent_post( true, '', false );
	if ( ! $next ) {
		return null;
	}
	return array(
		'title' => $next->post_title,
		'url'   => get_permalink( $next ),
		'img'   => get_the_post_thumbnail_url( $next, 'thumbnail' ) ?: '',
	);
}
