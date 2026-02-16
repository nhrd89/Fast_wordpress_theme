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

	// ============================================
	// HOMEPAGE PANEL
	// ============================================
	$wp_customize->add_panel( 'pl_homepage', array(
		'title'       => __( 'Homepage', 'pinlightning' ),
		'priority'    => 25,
		'description' => __( 'Customize every section of your homepage.', 'pinlightning' ),
	) );

	// --- General Settings ---
	$wp_customize->add_section( 'pl_home_general', array(
		'title'    => __( 'General Settings', 'pinlightning' ),
		'panel'    => 'pl_homepage',
		'priority' => 10,
	) );

	$wp_customize->add_setting( 'pl_brand_name', array(
		'default'           => 'cheerlives',
		'sanitize_callback' => 'sanitize_text_field',
		'transport'         => 'postMessage',
	) );
	$wp_customize->add_control( 'pl_brand_name', array(
		'label'   => __( 'Brand Name', 'pinlightning' ),
		'section' => 'pl_home_general',
		'type'    => 'text',
	) );

	$wp_customize->add_setting( 'pl_accent_color', array(
		'default'           => '#e84393',
		'sanitize_callback' => 'sanitize_hex_color',
		'transport'         => 'postMessage',
	) );
	$wp_customize->add_control( new WP_Customize_Color_Control( $wp_customize, 'pl_accent_color', array(
		'label'       => __( 'Accent Color', 'pinlightning' ),
		'description' => __( 'Primary accent color used across the homepage.', 'pinlightning' ),
		'section'     => 'pl_home_general',
	) ) );

	$wp_customize->add_setting( 'pl_accent_color_2', array(
		'default'           => '#6c5ce7',
		'sanitize_callback' => 'sanitize_hex_color',
		'transport'         => 'postMessage',
	) );
	$wp_customize->add_control( new WP_Customize_Color_Control( $wp_customize, 'pl_accent_color_2', array(
		'label'       => __( 'Secondary Accent Color', 'pinlightning' ),
		'description' => __( 'Used in gradients alongside the primary accent.', 'pinlightning' ),
		'section'     => 'pl_home_general',
	) ) );

	$wp_customize->add_setting( 'pl_logo_icon', array(
		'default'           => "\xE2\x9A\xA1",
		'sanitize_callback' => 'sanitize_text_field',
	) );
	$wp_customize->add_control( 'pl_logo_icon', array(
		'label'   => __( 'Logo Icon/Emoji', 'pinlightning' ),
		'section' => 'pl_home_general',
		'type'    => 'text',
	) );

	$wp_customize->add_setting( 'pl_logo_image', array(
		'default'           => '',
		'sanitize_callback' => 'esc_url_raw',
	) );
	$wp_customize->add_control( new WP_Customize_Image_Control( $wp_customize, 'pl_logo_image', array(
		'label'       => __( 'Custom Logo Image', 'pinlightning' ),
		'description' => __( 'Upload a logo image. If set, replaces the icon + text logo.', 'pinlightning' ),
		'section'     => 'pl_home_general',
	) ) );

	// --- Hero Section ---
	$wp_customize->add_section( 'pl_home_hero', array(
		'title'    => __( 'Hero Section', 'pinlightning' ),
		'panel'    => 'pl_homepage',
		'priority' => 20,
	) );

	$wp_customize->add_setting( 'pl_hero_show', array(
		'default'           => true,
		'sanitize_callback' => 'wp_validate_boolean',
	) );
	$wp_customize->add_control( 'pl_hero_show', array(
		'label'   => __( 'Show Hero Section', 'pinlightning' ),
		'section' => 'pl_home_hero',
		'type'    => 'checkbox',
	) );

	$wp_customize->add_setting( 'pl_hero_count', array(
		'default'           => 5,
		'sanitize_callback' => 'absint',
	) );
	$wp_customize->add_control( 'pl_hero_count', array(
		'label'       => __( 'Number of Hero Posts', 'pinlightning' ),
		'description' => __( '1 = large only. 3 = large + 2 small. 5 = full bento grid.', 'pinlightning' ),
		'section'     => 'pl_home_hero',
		'type'        => 'select',
		'choices'     => array( 1 => '1 (Featured only)', 3 => '3 (Featured + 2)', 5 => '5 (Full bento)' ),
	) );

	$wp_customize->add_setting( 'pl_hero_badge', array(
		'default'           => "Editor's Pick",
		'sanitize_callback' => 'sanitize_text_field',
	) );
	$wp_customize->add_control( 'pl_hero_badge', array(
		'label'       => __( 'Hero Badge Text', 'pinlightning' ),
		'description' => __( 'Badge shown on the main featured post.', 'pinlightning' ),
		'section'     => 'pl_home_hero',
		'type'        => 'text',
	) );

	$wp_customize->add_setting( 'pl_hero_category', array(
		'default'           => 0,
		'sanitize_callback' => 'absint',
	) );
	$wp_customize->add_control( 'pl_hero_category', array(
		'label'       => __( 'Featured Category', 'pinlightning' ),
		'description' => __( 'Limit hero posts to a specific category. All = no filter.', 'pinlightning' ),
		'section'     => 'pl_home_hero',
		'type'        => 'select',
		'choices'     => pl_get_category_choices(),
	) );

	// --- Liv Welcome Strip ---
	$wp_customize->add_section( 'pl_home_liv', array(
		'title'    => __( 'Liv Welcome Strip', 'pinlightning' ),
		'panel'    => 'pl_homepage',
		'priority' => 30,
	) );

	$wp_customize->add_setting( 'pl_liv_show', array(
		'default'           => true,
		'sanitize_callback' => 'wp_validate_boolean',
	) );
	$wp_customize->add_control( 'pl_liv_show', array(
		'label'   => __( 'Show Liv Welcome Strip', 'pinlightning' ),
		'section' => 'pl_home_liv',
		'type'    => 'checkbox',
	) );

	$wp_customize->add_setting( 'pl_liv_name', array(
		'default'           => 'Liv',
		'sanitize_callback' => 'sanitize_text_field',
	) );
	$wp_customize->add_control( 'pl_liv_name', array(
		'label'   => __( 'Character Name', 'pinlightning' ),
		'section' => 'pl_home_liv',
		'type'    => 'text',
	) );

	$wp_customize->add_setting( 'pl_liv_message', array(
		'default'           => "Hey! I'm {name} \xe2\x80\x94 your style & lifestyle companion. Tap any image while reading to chat with me about it!",
		'sanitize_callback' => 'sanitize_text_field',
	) );
	$wp_customize->add_control( 'pl_liv_message', array(
		'label'       => __( 'Welcome Message', 'pinlightning' ),
		'description' => __( 'Use {name} to insert the character name.', 'pinlightning' ),
		'section'     => 'pl_home_liv',
		'type'        => 'textarea',
	) );

	$wp_customize->add_setting( 'pl_liv_avatar', array(
		'default'           => "\xF0\x9F\x92\x83",
		'sanitize_callback' => 'sanitize_text_field',
	) );
	$wp_customize->add_control( 'pl_liv_avatar', array(
		'label'   => __( 'Avatar Emoji', 'pinlightning' ),
		'section' => 'pl_home_liv',
		'type'    => 'text',
	) );

	$wp_customize->add_setting( 'pl_liv_cta', array(
		'default'           => "Try it \xe2\x86\x92",
		'sanitize_callback' => 'sanitize_text_field',
	) );
	$wp_customize->add_control( 'pl_liv_cta', array(
		'label'       => __( 'CTA Button Text', 'pinlightning' ),
		'description' => __( 'Leave empty to hide the button.', 'pinlightning' ),
		'section'     => 'pl_home_liv',
		'type'        => 'text',
	) );

	// --- Category Pills ---
	$wp_customize->add_section( 'pl_home_cats', array(
		'title'    => __( 'Category Pills', 'pinlightning' ),
		'panel'    => 'pl_homepage',
		'priority' => 40,
	) );

	$wp_customize->add_setting( 'pl_cats_show', array(
		'default'           => true,
		'sanitize_callback' => 'wp_validate_boolean',
	) );
	$wp_customize->add_control( 'pl_cats_show', array(
		'label'   => __( 'Show Category Pills', 'pinlightning' ),
		'section' => 'pl_home_cats',
		'type'    => 'checkbox',
	) );

	$wp_customize->add_setting( 'pl_cats_max', array(
		'default'           => 8,
		'sanitize_callback' => 'absint',
	) );
	$wp_customize->add_control( 'pl_cats_max', array(
		'label'      => __( 'Max Categories to Show', 'pinlightning' ),
		'section'    => 'pl_home_cats',
		'type'       => 'number',
		'input_attrs' => array( 'min' => 3, 'max' => 15 ),
	) );

	$wp_customize->add_setting( 'pl_cats_counts', array(
		'default'           => true,
		'sanitize_callback' => 'wp_validate_boolean',
	) );
	$wp_customize->add_control( 'pl_cats_counts', array(
		'label'   => __( 'Show Post Counts', 'pinlightning' ),
		'section' => 'pl_home_cats',
		'type'    => 'checkbox',
	) );

	// --- Post Grid ---
	$wp_customize->add_section( 'pl_home_grid', array(
		'title'    => __( 'Post Grid', 'pinlightning' ),
		'panel'    => 'pl_homepage',
		'priority' => 50,
	) );

	$wp_customize->add_setting( 'pl_grid_tabs', array(
		'default'           => true,
		'sanitize_callback' => 'wp_validate_boolean',
	) );
	$wp_customize->add_control( 'pl_grid_tabs', array(
		'label'   => __( 'Show Tab Filters', 'pinlightning' ),
		'section' => 'pl_home_grid',
		'type'    => 'checkbox',
	) );

	$wp_customize->add_setting( 'pl_grid_count', array(
		'default'           => 9,
		'sanitize_callback' => 'absint',
	) );
	$wp_customize->add_control( 'pl_grid_count', array(
		'label'   => __( 'Posts Per Page', 'pinlightning' ),
		'section' => 'pl_home_grid',
		'type'    => 'select',
		'choices' => array( 6 => '6', 9 => '9', 12 => '12', 15 => '15' ),
	) );

	$wp_customize->add_setting( 'pl_grid_columns', array(
		'default'           => 3,
		'sanitize_callback' => 'absint',
	) );
	$wp_customize->add_control( 'pl_grid_columns', array(
		'label'   => __( 'Grid Columns (Desktop)', 'pinlightning' ),
		'section' => 'pl_home_grid',
		'type'    => 'select',
		'choices' => array( 2 => '2 Columns', 3 => '3 Columns', 4 => '4 Columns' ),
	) );

	$wp_customize->add_setting( 'pl_grid_readtime', array(
		'default'           => true,
		'sanitize_callback' => 'wp_validate_boolean',
	) );
	$wp_customize->add_control( 'pl_grid_readtime', array(
		'label'   => __( 'Show Read Time', 'pinlightning' ),
		'section' => 'pl_home_grid',
		'type'    => 'checkbox',
	) );

	$wp_customize->add_setting( 'pl_grid_cat_badge', array(
		'default'           => true,
		'sanitize_callback' => 'wp_validate_boolean',
	) );
	$wp_customize->add_control( 'pl_grid_cat_badge', array(
		'label'   => __( 'Show Category Badge on Cards', 'pinlightning' ),
		'section' => 'pl_home_grid',
		'type'    => 'checkbox',
	) );

	$wp_customize->add_setting( 'pl_grid_img_height', array(
		'default'           => '170',
		'sanitize_callback' => 'sanitize_text_field',
	) );
	$wp_customize->add_control( 'pl_grid_img_height', array(
		'label'   => __( 'Card Image Height (px)', 'pinlightning' ),
		'section' => 'pl_home_grid',
		'type'    => 'select',
		'choices' => array(
			'140' => '140px (Compact)',
			'170' => '170px (Default)',
			'200' => '200px (Tall)',
			'240' => '240px (Extra Tall)',
		),
	) );

	$wp_customize->add_setting( 'pl_grid_loadmore_text', array(
		'default'           => 'Load More Inspiration',
		'sanitize_callback' => 'sanitize_text_field',
	) );
	$wp_customize->add_control( 'pl_grid_loadmore_text', array(
		'label'   => __( 'Load More Button Text', 'pinlightning' ),
		'section' => 'pl_home_grid',
		'type'    => 'text',
	) );

	$wp_customize->add_setting( 'pl_grid_loadmore', array(
		'default'           => true,
		'sanitize_callback' => 'wp_validate_boolean',
	) );
	$wp_customize->add_control( 'pl_grid_loadmore', array(
		'label'   => __( 'Show Load More Button', 'pinlightning' ),
		'section' => 'pl_home_grid',
		'type'    => 'checkbox',
	) );

	$wp_customize->add_setting( 'pl_pin_button_show', array(
		'default'           => true,
		'sanitize_callback' => 'wp_validate_boolean',
	) );
	$wp_customize->add_control( 'pl_pin_button_show', array(
		'label'       => __( 'Show Pinterest Save Buttons', 'pinlightning' ),
		'description' => __( 'Display Save to Pinterest buttons on post images and homepage cards.', 'pinlightning' ),
		'section'     => 'pl_home_grid',
		'type'        => 'checkbox',
	) );

	// --- Explore by Category ---
	$wp_customize->add_section( 'pl_home_explore', array(
		'title'    => __( 'Explore Section', 'pinlightning' ),
		'panel'    => 'pl_homepage',
		'priority' => 60,
	) );

	$wp_customize->add_setting( 'pl_explore_show', array(
		'default'           => true,
		'sanitize_callback' => 'wp_validate_boolean',
	) );
	$wp_customize->add_control( 'pl_explore_show', array(
		'label'   => __( 'Show Explore by Category Section', 'pinlightning' ),
		'section' => 'pl_home_explore',
		'type'    => 'checkbox',
	) );

	$wp_customize->add_setting( 'pl_explore_heading', array(
		'default'           => 'Explore by Category',
		'sanitize_callback' => 'sanitize_text_field',
	) );
	$wp_customize->add_control( 'pl_explore_heading', array(
		'label'   => __( 'Section Heading', 'pinlightning' ),
		'section' => 'pl_home_explore',
		'type'    => 'text',
	) );

	// --- Newsletter ---
	$wp_customize->add_section( 'pl_home_newsletter', array(
		'title'    => __( 'Newsletter Section', 'pinlightning' ),
		'panel'    => 'pl_homepage',
		'priority' => 70,
	) );

	$wp_customize->add_setting( 'pl_newsletter_show', array(
		'default'           => true,
		'sanitize_callback' => 'wp_validate_boolean',
	) );
	$wp_customize->add_control( 'pl_newsletter_show', array(
		'label'   => __( 'Show Newsletter Section', 'pinlightning' ),
		'section' => 'pl_home_newsletter',
		'type'    => 'checkbox',
	) );

	$wp_customize->add_setting( 'pl_newsletter_heading', array(
		'default'           => 'Get Inspired Weekly',
		'sanitize_callback' => 'sanitize_text_field',
	) );
	$wp_customize->add_control( 'pl_newsletter_heading', array(
		'label'   => __( 'Heading', 'pinlightning' ),
		'section' => 'pl_home_newsletter',
		'type'    => 'text',
	) );

	$wp_customize->add_setting( 'pl_newsletter_desc', array(
		'default'           => "Curated style tips, home inspo, and beauty trends \xe2\x80\x94 delivered every Friday. No spam, just inspiration.",
		'sanitize_callback' => 'sanitize_text_field',
	) );
	$wp_customize->add_control( 'pl_newsletter_desc', array(
		'label'   => __( 'Description', 'pinlightning' ),
		'section' => 'pl_home_newsletter',
		'type'    => 'textarea',
	) );

	$wp_customize->add_setting( 'pl_newsletter_placeholder', array(
		'default'           => 'Your email address',
		'sanitize_callback' => 'sanitize_text_field',
	) );
	$wp_customize->add_control( 'pl_newsletter_placeholder', array(
		'label'   => __( 'Input Placeholder', 'pinlightning' ),
		'section' => 'pl_home_newsletter',
		'type'    => 'text',
	) );

	$wp_customize->add_setting( 'pl_newsletter_btn', array(
		'default'           => 'Subscribe',
		'sanitize_callback' => 'sanitize_text_field',
	) );
	$wp_customize->add_control( 'pl_newsletter_btn', array(
		'label'   => __( 'Button Text', 'pinlightning' ),
		'section' => 'pl_home_newsletter',
		'type'    => 'text',
	) );

	$wp_customize->add_setting( 'pl_newsletter_success', array(
		'default'           => "You're in! Check your inbox for a welcome surprise.",
		'sanitize_callback' => 'sanitize_text_field',
	) );
	$wp_customize->add_control( 'pl_newsletter_success', array(
		'label'   => __( 'Success Message', 'pinlightning' ),
		'section' => 'pl_home_newsletter',
		'type'    => 'textarea',
	) );

	$wp_customize->add_setting( 'pl_newsletter_note', array(
		'default'           => 'Join 2,400+ style enthusiasts. Unsubscribe anytime.',
		'sanitize_callback' => 'sanitize_text_field',
	) );
	$wp_customize->add_control( 'pl_newsletter_note', array(
		'label'   => __( 'Social Proof Note', 'pinlightning' ),
		'section' => 'pl_home_newsletter',
		'type'    => 'text',
	) );

	$wp_customize->add_setting( 'pl_newsletter_bg', array(
		'default'           => '#1a1a2e',
		'sanitize_callback' => 'sanitize_hex_color',
	) );
	$wp_customize->add_control( new WP_Customize_Color_Control( $wp_customize, 'pl_newsletter_bg', array(
		'label'   => __( 'Background Color', 'pinlightning' ),
		'section' => 'pl_home_newsletter',
	) ) );

	// --- Footer ---
	$wp_customize->add_section( 'pl_home_footer', array(
		'title'    => __( 'Footer', 'pinlightning' ),
		'panel'    => 'pl_homepage',
		'priority' => 80,
	) );

	$wp_customize->add_setting( 'pl_footer_desc', array(
		'default'           => 'Your daily destination for curated style, home design, beauty, and lifestyle inspiration from Pinterest and beyond.',
		'sanitize_callback' => 'sanitize_text_field',
	) );
	$wp_customize->add_control( 'pl_footer_desc', array(
		'label'   => __( 'Footer Description', 'pinlightning' ),
		'section' => 'pl_home_footer',
		'type'    => 'textarea',
	) );

	$wp_customize->add_setting( 'pl_footer_copyright', array(
		'default'           => '{year} Cheerlives. All rights reserved.',
		'sanitize_callback' => 'sanitize_text_field',
	) );
	$wp_customize->add_control( 'pl_footer_copyright', array(
		'label'       => __( 'Copyright Text', 'pinlightning' ),
		'description' => __( 'Use {year} to auto-insert current year.', 'pinlightning' ),
		'section'     => 'pl_home_footer',
		'type'        => 'text',
	) );

	$wp_customize->add_setting( 'pl_footer_tagline', array(
		'default'           => "Made with \xE2\x9A\xA1 by PinLightning",
		'sanitize_callback' => 'sanitize_text_field',
	) );
	$wp_customize->add_control( 'pl_footer_tagline', array(
		'label'   => __( 'Footer Tagline', 'pinlightning' ),
		'section' => 'pl_home_footer',
		'type'    => 'text',
	) );

	$pl_social_fields = array(
		'pinterest' => __( 'Pinterest URL', 'pinlightning' ),
		'instagram' => __( 'Instagram URL', 'pinlightning' ),
		'twitter'   => __( 'Twitter/X URL', 'pinlightning' ),
		'facebook'  => __( 'Facebook URL', 'pinlightning' ),
		'tiktok'    => __( 'TikTok URL', 'pinlightning' ),
	);
	foreach ( $pl_social_fields as $social_key => $social_label ) {
		$wp_customize->add_setting( "pl_social_{$social_key}", array(
			'default'           => '',
			'sanitize_callback' => 'esc_url_raw',
		) );
		$wp_customize->add_control( "pl_social_{$social_key}", array(
			'label'   => $social_label,
			'section' => 'pl_home_footer',
			'type'    => 'url',
		) );
	}

	$wp_customize->add_setting( 'pl_footer_bg', array(
		'default'           => '#111111',
		'sanitize_callback' => 'sanitize_hex_color',
	) );
	$wp_customize->add_control( new WP_Customize_Color_Control( $wp_customize, 'pl_footer_bg', array(
		'label'   => __( 'Footer Background Color', 'pinlightning' ),
		'section' => 'pl_home_footer',
	) ) );

	// --- Category Icons & Colors ---
	$wp_customize->add_section( 'pl_home_cat_icons', array(
		'title'       => __( 'Category Icons & Colors', 'pinlightning' ),
		'panel'       => 'pl_homepage',
		'priority'    => 90,
		'description' => __( 'Set custom icons and colors for each category. Uses emoji or text.', 'pinlightning' ),
	) );

	$all_cats = get_categories( array( 'hide_empty' => true, 'number' => 15 ) );
	foreach ( $all_cats as $acat ) {
		$wp_customize->add_setting( "pl_cat_icon_{$acat->slug}", array(
			'default'           => pl_get_cat_icon( $acat->slug ),
			'sanitize_callback' => 'sanitize_text_field',
		) );
		$wp_customize->add_control( "pl_cat_icon_{$acat->slug}", array(
			'label'   => sprintf( '%s — Icon', $acat->name ),
			'section' => 'pl_home_cat_icons',
			'type'    => 'text',
		) );

		$wp_customize->add_setting( "pl_cat_color_{$acat->slug}", array(
			'default'           => pl_get_cat_color( $acat->slug ),
			'sanitize_callback' => 'sanitize_hex_color',
		) );
		$wp_customize->add_control( new WP_Customize_Color_Control( $wp_customize, "pl_cat_color_{$acat->slug}", array(
			'label'   => sprintf( '%s — Color', $acat->name ),
			'section' => 'pl_home_cat_icons',
		) ) );
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
