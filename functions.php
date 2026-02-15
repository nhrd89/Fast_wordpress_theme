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

		// Pass sprite URL for photo character.
		wp_add_inline_script(
			'pinlightning-scroll-engage',
			'window.PLScrollConfig={spriteUrl:"' . esc_url( get_template_directory_uri() . '/assets/engage/sprite-prod.png' ) . '"};',
			'before'
		);

		// Preload sprite — low priority, no LCP impact.
		add_action( 'wp_head', function() {
			echo '<link rel="preload" href="' . esc_url( get_template_directory_uri() . '/assets/engage/sprite-prod.png' ) . '" as="image" fetchpriority="low">' . "\n";
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
