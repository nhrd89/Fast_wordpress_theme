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

// One-time cleanup: remove markdown bold artifacts from post titles.
function pl_fix_title_asterisks() {
	if ( get_option( 'pl_titles_cleaned' ) ) {
		return;
	}
	global $wpdb;
	$wpdb->query( "UPDATE {$wpdb->posts} SET post_title = REPLACE(post_title, '** **', '') WHERE post_title LIKE '%** **%'" );
	$wpdb->query( "UPDATE {$wpdb->posts} SET post_title = REPLACE(post_title, '**', '') WHERE post_title LIKE '%**%'" );
	update_option( 'pl_titles_cleaned', 1 );
}
add_action( 'init', 'pl_fix_title_asterisks' );
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

	// Comment reply script.
	if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) {
		wp_enqueue_script( 'comment-reply' );
	}

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

		// Scroll engagement — heart progress + dancing girl + gamification.
		wp_enqueue_script(
			'pinlightning-scroll-engage',
			PINLIGHTNING_URI . '/assets/js/scroll-engage.js',
			array(),
			PINLIGHTNING_VERSION,
			true
		);
		wp_localize_script( 'pinlightning-scroll-engage', 'plEngageConfig', pl_get_engage_config() );

		// Pass video base URL for character clips.
		wp_add_inline_script(
			'pinlightning-scroll-engage',
			'window.PLScrollConfig={baseUrl:"' . esc_url( get_template_directory_uri() . '/assets/engage/' ) . '"};',
			'before'
		);

		// Preload idle clip — low priority, no LCP impact.
		add_action( 'wp_head', function() {
			echo '<link rel="preload" href="' . esc_url( get_template_directory_uri() . '/assets/engage/idle.webm' ) . '" as="video" fetchpriority="low">' . "\n";
		}, 5 );

		// Defer the scroll engage script.
		add_filter( 'script_loader_tag', function( $tag, $handle ) {
			if ( $handle === 'pinlightning-scroll-engage' ) {
				return str_replace( ' src=', ' defer src=', $tag );
			}
			return $tag;
		}, 10, 2 );
	}
}
add_action( 'wp_enqueue_scripts', 'pinlightning_scripts' );

/**
 * Output a dynamic meta description for single posts.
 */
function pinlightning_meta_description() {
	if ( is_singular() ) {
		$excerpt = get_the_excerpt();
		if ( $excerpt ) {
			$desc = wp_strip_all_tags( $excerpt );
			$desc = mb_substr( $desc, 0, 160 );
			echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
		}
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
