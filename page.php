<?php
/**
 * The template for displaying pages.
 *
 * Uses the single-post article layout without related posts.
 *
 * @package PinLightning
 * @since 1.0.0
 */

get_header();

while ( have_posts() ) :
	the_post();
?>

	<?php if ( has_post_thumbnail() ) : ?>
		<div class="post-hero">
			<?php the_post_thumbnail( 'post-hero', array( 'class' => 'post-hero-img' ) ); ?>
		</div>
	<?php endif; ?>

	<article id="post-<?php the_ID(); ?>" <?php post_class( 'single-article' ); ?>>
		<header class="single-header">
			<h1 class="single-title"><?php the_title(); ?></h1>
		</header>

		<div class="single-content">
			<?php
			the_content();

			wp_link_pages( array(
				'before' => '<div class="page-links">' . esc_html__( 'Pages:', 'pinlightning' ),
				'after'  => '</div>',
			) );
			?>
		</div>
	</article>

	<?php
	if ( comments_open() || get_comments_number() ) :
		comments_template();
	endif;
	?>

<?php endwhile; ?>

<?php
get_footer();
