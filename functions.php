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
	$page    = absint( $request->get_param( 'page' ) ?: 2 );
	$exclude = array_filter( array_map( 'absint', explode( ',', $request->get_param( 'exclude' ) ?: '' ) ) );

	$query = new WP_Query( array(
		'posts_per_page' => 9,
		'paged'          => $page,
		'post_status'    => 'publish',
		'post__not_in'   => $exclude,
	) );

	ob_start();
	if ( $query->have_posts() ) :
		while ( $query->have_posts() ) :
			$query->the_post();
			$cats      = get_the_category();
			$cat       = ! empty( $cats ) ? $cats[0] : null;
			$color     = $cat ? pl_get_cat_color( $cat->slug ) : '#888';
			$icon      = $cat ? pl_get_cat_icon( $cat->slug ) : "\xF0\x9F\x93\x8C";
			$read_time = max( 1, ceil( str_word_count( wp_strip_all_tags( get_the_content() ) ) / 200 ) );
			?>
			<article class="pl-card" data-cat="<?php echo esc_attr( $cat ? $cat->slug : '' ); ?>">
				<a href="<?php the_permalink(); ?>" class="pl-card-link">
					<div class="pl-card-media">
						<?php if ( has_post_thumbnail() ) :
							the_post_thumbnail( 'medium_large', array(
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
						<h3 class="pl-card-title"><?php the_title(); ?></h3>
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
		endwhile;
	endif;
	wp_reset_postdata();
	$html = ob_get_clean();

	return array(
		'html'     => $html,
		'has_more' => $query->max_num_pages > $page,
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
