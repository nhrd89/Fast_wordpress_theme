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

	// Colors section.
	$wp_customize->add_setting( 'pinlightning_accent_color', array(
		'default'           => '#0066cc',
		'sanitize_callback' => 'sanitize_hex_color',
		'transport'         => 'postMessage',
	) );

	$wp_customize->add_control( new WP_Customize_Color_Control( $wp_customize, 'pinlightning_accent_color', array(
		'label'   => __( 'Accent Color', 'pinlightning' ),
		'section' => 'colors',
	) ) );
}
add_action( 'customize_register', 'pinlightning_customize_register' );

/**
 * Output custom CSS for Customizer settings.
 */
function pinlightning_customizer_css() {
	$accent_color = get_theme_mod( 'pinlightning_accent_color', '#0066cc' );
	if ( '#0066cc' !== $accent_color ) {
		printf(
			'<style>:root { --pinlightning-accent: %s; }</style>',
			esc_attr( $accent_color )
		);
	}
}
add_action( 'wp_head', 'pinlightning_customizer_css' );
