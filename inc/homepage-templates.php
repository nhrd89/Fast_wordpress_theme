<?php
/**
 * Homepage Template Router
 *
 * Routes homepage to different templates based on domain or Customizer setting.
 * Keeps each site's homepage independent without affecting others.
 *
 * @package PinLightning
 */

defined( 'ABSPATH' ) || exit;

/**
 * Add Customizer setting for homepage template override.
 */
function pl_homepage_template_customizer( $wp_customize ) {
	$wp_customize->add_setting( 'pl_homepage_template', array(
		'default'           => 'auto',
		'sanitize_callback' => 'sanitize_text_field',
	) );
	$wp_customize->add_control( 'pl_homepage_template', array(
		'label'       => 'Homepage Template',
		'description' => 'Auto detects by domain. Override here.',
		'section'     => 'pl_homepage_general',
		'type'        => 'select',
		'choices'     => array(
			'auto'    => 'Auto (by domain)',
			'default' => 'Default (CheeLives bento)',
			'emerald' => 'Emerald Editorial',
			'coral'   => 'Coral Breeze',
		),
	) );
}
add_action( 'customize_register', 'pl_homepage_template_customizer', 20 );

/**
 * Resolve which homepage template to use.
 *
 * Priority: Customizer override > domain auto-detect > default.
 *
 * @return string Template slug: 'default', 'emerald', or 'coral'.
 */
function pl_resolve_homepage_template() {
	$override = get_theme_mod( 'pl_homepage_template', 'auto' );

	if ( 'auto' !== $override ) {
		return $override;
	}

	$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( $_SERVER['HTTP_HOST'] ) : '';

	if ( false !== strpos( $host, 'inspireinlet' ) ) {
		return 'emerald';
	}
	if ( false !== strpos( $host, 'pulsepathlife' ) ) {
		return 'coral';
	}

	return 'default';
}

/**
 * Filter template_include to load domain-specific homepage.
 */
function pl_homepage_template_include( $template ) {
	if ( ! is_front_page() ) {
		return $template;
	}

	$slug = pl_resolve_homepage_template();

	if ( 'default' === $slug ) {
		return $template;
	}

	$map = array(
		'emerald' => 'template-emerald-editorial.php',
		'coral'   => 'template-coral-breeze.php',
	);

	if ( isset( $map[ $slug ] ) ) {
		$file = PINLIGHTNING_DIR . '/' . $map[ $slug ];
		if ( file_exists( $file ) ) {
			return $file;
		}
	}

	return $template;
}
add_filter( 'template_include', 'pl_homepage_template_include', 99 );

/**
 * Inline critical CSS for emerald homepage (header + hero only).
 * Hooked at wp_head priority 3 — after theme critical.css (p1) and main.css (p2).
 */
function pl_emerald_critical_css() {
	if ( ! is_front_page() || 'emerald' !== pl_resolve_homepage_template() ) {
		return;
	}

	$file = PINLIGHTNING_DIR . '/assets/css/homepage-emerald.css';
	if ( ! file_exists( $file ) ) {
		return;
	}

	$css = file_get_contents( $file );
	if ( $css ) {
		echo '<style id="emerald-homepage">' . $css . '</style>' . "\n";
	}
}
add_action( 'wp_head', 'pl_emerald_critical_css', 3 );

/**
 * Inline critical CSS for coral homepage.
 * Hooked at wp_head priority 3 — after theme critical.css (p1) and main.css (p2).
 */
function pl_coral_critical_css() {
	if ( ! is_front_page() || 'coral' !== pl_resolve_homepage_template() ) {
		return;
	}

	$file = PINLIGHTNING_DIR . '/assets/css/homepage-coral.css';
	if ( ! file_exists( $file ) ) {
		return;
	}

	$css = file_get_contents( $file );
	if ( $css ) {
		echo '<style id="coral-homepage">' . $css . '</style>' . "\n";
	}
}
add_action( 'wp_head', 'pl_coral_critical_css', 3 );

/**
 * Get category circles data with transient caching.
 *
 * @param int $count Number of categories.
 * @return array Array of category data with thumbnail URLs.
 */
function pl_get_category_circles( $count = 8 ) {
	$cache_key = 'pl_cat_circles_' . $count;
	$cached    = get_transient( $cache_key );

	if ( false !== $cached ) {
		return $cached;
	}

	$categories = get_categories( array(
		'orderby'    => 'count',
		'order'      => 'DESC',
		'number'     => $count,
		'hide_empty' => true,
	) );

	$result = array();
	foreach ( $categories as $cat ) {
		$thumb_url = '';
		$posts     = get_posts( array(
			'category'        => $cat->term_id,
			'numberposts'     => 1,
			'post_status'     => 'publish',
			'no_found_rows'   => true,
			'fields'          => 'ids',
		) );
		if ( ! empty( $posts ) && has_post_thumbnail( $posts[0] ) ) {
			$thumb_url = get_the_post_thumbnail_url( $posts[0], 'thumbnail' );
		}
		$result[] = array(
			'name'  => $cat->name,
			'url'   => get_category_link( $cat->term_id ),
			'count' => $cat->count,
			'thumb' => $thumb_url,
		);
	}

	set_transient( $cache_key, $result, HOUR_IN_SECONDS );
	return $result;
}
