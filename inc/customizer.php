<?php
/**
 * PinLightning Customizer settings.
 *
 * @package PinLightning
 * @since 1.0.0
 */

/**
 * Register Customizer settings and controls.
 *
 * @param WP_Customize_Manager $wp_customize Theme Customizer object.
 */
function pinlightning_customize_register( $wp_customize ) {
	$wp_customize->get_setting( 'blogname' )->transport        = 'postMessage';
	$wp_customize->get_setting( 'blogdescription' )->transport = 'postMessage';

	// Accent color.
	$wp_customize->add_setting( 'pinlightning_accent_color', array(
		'default'           => '#e91e63',
		'sanitize_callback' => 'sanitize_hex_color',
		'transport'         => 'postMessage',
	) );

	$wp_customize->add_control( new WP_Customize_Color_Control( $wp_customize, 'pinlightning_accent_color', array(
		'label'   => __( 'Accent Color', 'pinlightning' ),
		'section' => 'colors',
	) ) );

	// Social links section.
	$wp_customize->add_section( 'pinlightning_social', array(
		'title'    => __( 'Social Links', 'pinlightning' ),
		'priority' => 120,
	) );

	$social_fields = array(
		'pinlightning_pinterest_url' => __( 'Pinterest URL', 'pinlightning' ),
		'pinlightning_instagram_url' => __( 'Instagram URL', 'pinlightning' ),
		'pinlightning_facebook_url'  => __( 'Facebook URL', 'pinlightning' ),
		'pinlightning_twitter_url'   => __( 'X / Twitter URL', 'pinlightning' ),
	);

	foreach ( $social_fields as $id => $label ) {
		$wp_customize->add_setting( $id, array(
			'default'           => '',
			'sanitize_callback' => 'esc_url_raw',
		) );

		$wp_customize->add_control( $id, array(
			'label'   => $label,
			'section' => 'pinlightning_social',
			'type'    => 'url',
		) );
	}
}
add_action( 'customize_register', 'pinlightning_customize_register' );

/**
 * Output custom CSS for Customizer settings.
 */
function pinlightning_customizer_css() {
	$accent_color = get_theme_mod( 'pinlightning_accent_color', '#e91e63' );
	if ( '#e91e63' !== $accent_color ) {
		printf(
			'<style>:root{--pl-accent:%s}</style>',
			esc_attr( $accent_color )
		);
	}
}
add_action( 'wp_head', 'pinlightning_customizer_css' );
