<?php
/**
 * PinLightning Engagement System — Customizer Controls
 *
 * @package PinLightning
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function pl_engagement_customizer( $wp_customize ) {
	$wp_customize->add_section( 'pl_engagement', array(
		'title'    => 'Engagement System',
		'priority' => 160,
	) );

	$toggles = array(
		'eb_progress_bar'   => 'Progress Bar',
		'eb_item_counter'   => 'Item Counter',
		'eb_jump_pills'     => 'Jump Pills',
		'eb_live_activity'  => 'Live Activity Count',
		'eb_scroll_reveal'  => 'Scroll Reveal Animation',
		'eb_ken_burns'      => 'Image Ken Burns Motion',
		'eb_shimmer'        => 'Image Shimmer Sweep',
		'eb_trending'       => 'Trending Badges',
		'eb_save_counts'    => 'Save Count Badges',
		'eb_favorites'      => 'Favorite Hearts',
		'eb_collectibles'   => 'Hidden Collectibles',
		'eb_blur_reveal'    => 'Blur Reveal Gate',
		'eb_polls'          => 'Style Polls',
		'eb_quizzes'        => 'Style Quiz',
		'eb_curiosity'      => 'Curiosity Teasers',
		'eb_email_capture'  => 'Email Capture Block',
		'eb_milestones'     => 'Milestone Celebrations',
		'eb_achievements'   => 'Achievement Badge',
		'eb_speed_warn'     => 'Scroll Speed Warning',
		'eb_ai_tip'         => 'AI Tip Unlock',
		'eb_exit_intent'    => 'Exit Intent Popup',
		'eb_next_bar'       => 'Next Article Bar',
		'eb_skeletons'      => 'Skeleton Loading Screens',
		'eb_char_messages'  => 'Character Speech Bubbles',
		'eb_reading_streak' => 'Reading Streak',
	);

	foreach ( $toggles as $id => $label ) {
		$wp_customize->add_setting( $id, array(
			'default'           => true,
			'type'              => 'theme_mod',
			'sanitize_callback' => function ( $val ) {
				return (bool) $val;
			},
		) );
		$wp_customize->add_control( $id, array(
			'label'   => $label,
			'section' => 'pl_engagement',
			'type'    => 'checkbox',
		) );
	}
}
add_action( 'customize_register', 'pl_engagement_customizer' );
