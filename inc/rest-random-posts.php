<?php
/**
 * REST API endpoint for random posts (infinite scroll).
 *
 * GET /wp-json/pinlightning/v1/random-posts?exclude=1,2,3
 * Returns lightweight card HTML for appending below the article.
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
			'exclude' => array(
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
		),
	) );
}
add_action( 'rest_api_init', 'pinlightning_register_random_posts_route' );

/**
 * Return 3 random posts as lightweight HTML cards.
 *
 * @param WP_REST_Request $request The REST request.
 * @return WP_REST_Response
 */
function pinlightning_random_posts_handler( $request ) {
	$exclude_raw = $request->get_param( 'exclude' );
	$exclude_ids = array();
	if ( $exclude_raw ) {
		$exclude_ids = array_map( 'absint', explode( ',', $exclude_raw ) );
		$exclude_ids = array_filter( $exclude_ids );
	}

	$query = new WP_Query( array(
		'posts_per_page'      => 3,
		'orderby'             => 'rand',
		'post__not_in'        => $exclude_ids,
		'ignore_sticky_posts' => true,
		'no_found_rows'       => true,
		'post_status'         => 'publish',
	) );

	if ( ! $query->have_posts() ) {
		return new WP_REST_Response( array( 'html' => '', 'ids' => array() ), 200 );
	}

	$ids = array();
	ob_start();

	while ( $query->have_posts() ) {
		$query->the_post();
		$ids[] = get_the_ID();

		$thumb = '';
		if ( has_post_thumbnail() ) {
			$thumb = get_the_post_thumbnail( get_the_ID(), 'card-thumb', array(
				'class'   => 'infinite-card-img',
				'loading' => 'lazy',
			) );
		}

		$categories = get_the_category();
		$cat_name   = $categories ? esc_html( $categories[0]->name ) : '';
		?>
		<article class="infinite-card">
			<a href="<?php the_permalink(); ?>" class="infinite-card-link">
				<?php if ( $thumb ) : ?>
					<div class="infinite-card-image">
						<?php echo $thumb; ?>
						<?php if ( $cat_name ) : ?>
							<span class="card-cat"><?php echo $cat_name; ?></span>
						<?php endif; ?>
					</div>
				<?php endif; ?>
				<div class="infinite-card-body">
					<h3 class="infinite-card-title"><?php the_title(); ?></h3>
					<p class="infinite-card-excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 15, '...' ) ); ?></p>
				</div>
			</a>
		</article>
		<?php
	}

	wp_reset_postdata();
	$html = ob_get_clean();

	return new WP_REST_Response( array(
		'html' => $html,
		'ids'  => $ids,
	), 200 );
}
