<?php
/**
 * The template for displaying 404 pages.
 *
 * Minimal 404 with search form and recent posts.
 *
 * @package PinLightning
 * @since 1.0.0
 */

get_header();
?>

<div class="error-404">
	<h1 class="error-code">404</h1>
	<p class="error-message"><?php esc_html_e( 'The page you are looking for does not exist.', 'pinlightning' ); ?></p>

	<?php get_search_form(); ?>

	<?php
	$recent = new WP_Query( array(
		'posts_per_page'      => 6,
		'ignore_sticky_posts' => true,
		'no_found_rows'       => true,
	) );

	if ( $recent->have_posts() ) :
		?>
		<section class="recent-posts-404">
			<h2><?php esc_html_e( 'Recent Posts', 'pinlightning' ); ?></h2>
			<div class="related-grid">
				<?php
				while ( $recent->have_posts() ) :
					$recent->the_post();
					get_template_part( 'template-parts/content', 'card' );
				endwhile;
				wp_reset_postdata();
				?>
			</div>
		</section>
	<?php endif; ?>
</div>

<?php
get_footer();
