<?php
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
 * Note: main.css is loaded asynchronously via inc/performance.php (critical CSS inlining).
 * Do NOT enqueue main.css here — it would create a render-blocking request.
 */
function pinlightning_scripts() {
	// Theme script.
	wp_enqueue_script(
		'pinlightning-script',
		PINLIGHTNING_URI . '/assets/js/main.js',
		array(),
		PINLIGHTNING_VERSION,
		true
	);

	// Comment reply script.
	if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) {
		wp_enqueue_script( 'comment-reply' );
	}
}
add_action( 'wp_enqueue_scripts', 'pinlightning_scripts' );

// Load helper files.
require_once PINLIGHTNING_DIR . '/inc/template-tags.php';
require_once PINLIGHTNING_DIR . '/inc/customizer.php';
require_once PINLIGHTNING_DIR . '/inc/performance.php';
