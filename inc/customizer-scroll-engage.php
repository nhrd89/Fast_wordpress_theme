<?php
/**
 * PinLightning Scroll Engagement — Customizer Controls
 * Real photo sprite system with psychological engagement
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'customize_register', function( $wp_customize ) {

	$wp_customize->add_panel( 'pl_scroll_engage', array(
		'title'       => "\xF0\x9F\x8E\xAE Scroll Engagement",
		'description' => 'Toggle engagement features. All zero CWV impact.',
		'priority'    => 200,
	) );

	$wp_customize->add_section( 'pl_engage_core', array(
		'title' => 'Core Elements',
		'panel' => 'pl_scroll_engage',
	) );

	$wp_customize->add_section( 'pl_engage_visual', array(
		'title' => 'Visual Effects',
		'panel' => 'pl_scroll_engage',
	) );

	$features = array(
		'pl_heart_progress' => array( 'Heart Progress Indicator', 'Filling heart in bottom-right, pulses continuously', 'pl_engage_core' ),
		'pl_dancing_girl'   => array( 'Dancing Character (Photo Sprite)', 'Real photo character with 7 animation states', 'pl_engage_core' ),
		'pl_bg_mood'        => array( 'Background Mood Colors', '11-stop gradient evolves with scroll depth', 'pl_engage_visual' ),
	);

	foreach ( $features as $id => $config ) {
		$wp_customize->add_setting( $id, array(
			'default'           => true,
			'transport'         => 'refresh',
			'sanitize_callback' => function( $val ) {
				return (bool) $val;
			},
		) );
		$wp_customize->add_control( $id, array(
			'label'       => $config[0],
			'description' => $config[1],
			'section'     => $config[2],
			'type'        => 'checkbox',
		) );
	}
} );

function pl_get_engage_config() {
	return array(
		'heart'  => (bool) get_theme_mod( 'pl_heart_progress', true ),
		'dancer' => (bool) get_theme_mod( 'pl_dancing_girl', true ),
		'bgMood' => (bool) get_theme_mod( 'pl_bg_mood', true ),
	);
}
