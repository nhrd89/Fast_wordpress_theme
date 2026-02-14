<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'customize_register', function( $wp_customize ) {
	// Panel.
	$wp_customize->add_panel( 'pl_scroll_engage', array(
		'title'       => "\xF0\x9F\x8E\xAE Scroll Engagement",
		'description' => 'Toggle gamification and engagement features',
		'priority'    => 200,
	) );

	// Section: Core.
	$wp_customize->add_section( 'pl_engage_core', array(
		'title' => 'Core Elements',
		'panel' => 'pl_scroll_engage',
	) );

	// Section: Gamification.
	$wp_customize->add_section( 'pl_engage_gamification', array(
		'title' => 'Gamification',
		'panel' => 'pl_scroll_engage',
	) );

	// Section: Visual Effects.
	$wp_customize->add_section( 'pl_engage_visual', array(
		'title' => 'Visual Effects',
		'panel' => 'pl_scroll_engage',
	) );

	// Section: Social/Emotional.
	$wp_customize->add_section( 'pl_engage_social', array(
		'title' => 'Social & Emotional',
		'panel' => 'pl_scroll_engage',
	) );

	$all_features = array(
		// Core.
		'pl_heart_progress' => array( 'Heart Progress Indicator', 'Pink heart that fills as user scrolls', 'pl_engage_core' ),
		'pl_dancing_girl'   => array( 'Dancing Girl Character', 'CSS character that dances while scrolling', 'pl_engage_core' ),
		// Gamification.
		'pl_combo_counter'  => array( 'Combo Counter', 'Multiplier that increases with steady scrolling', 'pl_engage_gamification' ),
		'pl_collectibles'   => array( 'Floating Collectibles', 'Gems/stars that pop as user scrolls past', 'pl_engage_gamification' ),
		'pl_achievements'   => array( 'Achievement Badges', 'Unlock badges for scroll behavior', 'pl_engage_gamification' ),
		// Visual.
		'pl_image_shimmer'  => array( 'Image Reveal Shimmer', 'Golden shimmer sweep when images enter viewport', 'pl_engage_visual' ),
		'pl_bg_mood'        => array( 'Background Mood Evolution', 'Page background subtly shifts color with scroll depth', 'pl_engage_visual' ),
		'pl_section_anim'   => array( 'Section Number Animations', 'Numbers bounce/flip in when entering viewport', 'pl_engage_visual' ),
		// Social.
		'pl_reader_stats'   => array( 'Reader Stats Message', 'Shows "Only X% of visitors read this far" after 50%', 'pl_engage_social' ),
		'pl_dancer_evolve'  => array( 'Dancer Evolution', 'Dancer gains accessories as user scrolls deeper', 'pl_engage_social' ),
	);

	foreach ( $all_features as $id => $config ) {
		$wp_customize->add_setting( $id, array(
			'default'           => true,
			'transport'         => 'refresh',
			'sanitize_callback' => 'wp_validate_boolean',
		) );

		$wp_customize->add_control( $id, array(
			'label'       => $config[0],
			'description' => $config[1],
			'section'     => $config[2],
			'type'        => 'checkbox',
		) );
	}
} );

/**
 * Get enabled features as config array for JS.
 */
function pl_get_engage_config() {
	return array(
		'heart'        => (bool) get_theme_mod( 'pl_heart_progress', true ),
		'dancer'       => (bool) get_theme_mod( 'pl_dancing_girl', true ),
		'combo'        => (bool) get_theme_mod( 'pl_combo_counter', true ),
		'collectibles' => (bool) get_theme_mod( 'pl_collectibles', true ),
		'achievements' => (bool) get_theme_mod( 'pl_achievements', true ),
		'shimmer'      => (bool) get_theme_mod( 'pl_image_shimmer', true ),
		'bgMood'       => (bool) get_theme_mod( 'pl_bg_mood', true ),
		'sectionAnim'  => (bool) get_theme_mod( 'pl_section_anim', true ),
		'readerStats'  => (bool) get_theme_mod( 'pl_reader_stats', true ),
		'dancerEvolve' => (bool) get_theme_mod( 'pl_dancer_evolve', true ),
	);
}
