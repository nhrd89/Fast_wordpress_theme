<?php
/**
 * REST API endpoint for random posts (infinite scroll).
 *
 * GET /wp-json/pinlightning/v1/random-posts?per_page=1&exclude=1,2,3
 * Returns full post HTML for seamless reading experience.
 *
 * @package PinLightning
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the random-posts REST route.
 */
function pinlightning_register_random_posts_route() {
	register_rest_route( 'pinlightning/v1', '/random-posts', array(
		'methods'             => 'GET',
		'callback'            => 'pinlightning_random_posts_handler',
		'permission_callback' => '__return_true',
		'args'                => array(
			'per_page' => array(
				'default'           => 1,
				'sanitize_callback' => 'absint',
			),
			'exclude' => array(
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
		),
	) );
}
add_action( 'rest_api_init', 'pinlightning_register_random_posts_route' );

/**
 * Return random posts as full article HTML.
 *
 * @param WP_REST_Request $request The REST request.
 * @return WP_REST_Response
 */
function pinlightning_random_posts_handler( $request ) {
	$per_page    = min( (int) $request->get_param( 'per_page' ), 3 );
	$exclude_raw = $request->get_param( 'exclude' );
	$exclude_ids = array();
	if ( $exclude_raw ) {
		$exclude_ids = array_map( 'absint', explode( ',', $exclude_raw ) );
		$exclude_ids = array_filter( $exclude_ids );
	}

	$query = new WP_Query( array(
		'posts_per_page'      => $per_page,
		'orderby'             => 'rand',
		'post__not_in'        => $exclude_ids,
		'ignore_sticky_posts' => true,
		'no_found_rows'       => true,
		'post_status'         => 'publish',
	) );

	if ( ! $query->have_posts() ) {
		return new WP_REST_Response( array( 'posts' => array(), 'ids' => array() ), 200 );
	}

	$posts = array();

	// Enable content filters (CDN rewrite, Pinterest attrs) that normally require is_singular().
	$GLOBALS['pinlightning_rest_content'] = true;

	while ( $query->have_posts() ) {
		$query->the_post();

		$thumb_url = get_the_post_thumbnail_url( get_the_ID(), 'large' );
		$cats      = get_the_category();
		$cat_name  = ! empty( $cats ) ? $cats[0]->name : '';
		$cat_link  = ! empty( $cats ) ? get_category_link( $cats[0]->term_id ) : '';

		// Get full post content with formatting (triggers the_content filters incl. CDN rewrite).
		$content = apply_filters( 'the_content', get_the_content() );

		$word_count = str_word_count( wp_strip_all_tags( get_the_content() ) );
		$read_time  = max( 1, (int) ceil( $word_count / 250 ) );

		$html = '<article class="infinite-post">';
		$html .= '<div class="infinite-post-header">';
		if ( $thumb_url ) {
			$html .= '<div class="infinite-post-hero">';
			$html .= '<img src="' . esc_url( $thumb_url ) . '" alt="' . esc_attr( get_the_title() ) . '" class="infinite-post-hero-img" loading="lazy">';
			$html .= '</div>';
		}
		if ( $cat_name ) {
			$html .= '<a href="' . esc_url( $cat_link ) . '" class="cat-label">' . esc_html( $cat_name ) . '</a>';
		}
		$html .= '<h2 class="infinite-post-title"><a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a></h2>';
		$html .= '<div class="single-meta">' . esc_html( get_the_date() ) . ' &middot; ' . $read_time . ' min read</div>';
		$html .= '</div>';
		$html .= '<div class="infinite-post-content single-content">' . $content . '</div>';
		$html .= '</article>';

		$posts[] = array(
			'id'   => get_the_ID(),
			'html' => $html,
		);
	}

	wp_reset_postdata();
	unset( $GLOBALS['pinlightning_rest_content'] );

	return new WP_REST_Response( array(
		'posts' => $posts,
		'ids'   => wp_list_pluck( $posts, 'id' ),
	), 200 );
}
