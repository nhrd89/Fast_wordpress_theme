<?php
/**
 * Template part for displaying posts in loops.
 *
 * @package PinLightning
 * @since 1.0.0
 */
?>

<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
	<header class="entry-header">
		<?php
		if ( is_singular() ) :
			the_title( '<h1 class="entry-title">', '</h1>' );
		else :
			the_title( '<h2 class="entry-title"><a href="' . esc_url( get_permalink() ) . '" rel="bookmark">', '</a></h2>' );
		endif;
		?>

		<?php if ( 'post' === get_post_type() ) : ?>
			<div class="entry-meta">
				<?php
				pinlightning_posted_on();
				pinlightning_posted_by();
				?>
			</div>
		<?php endif; ?>
	</header>

	<?php if ( has_post_thumbnail() ) : ?>
		<div class="post-thumbnail">
			<?php if ( is_singular() ) : ?>
				<?php the_post_thumbnail( 'large' ); ?>
			<?php else : ?>
				<a href="<?php the_permalink(); ?>">
					<?php the_post_thumbnail( 'medium_large' ); ?>
				</a>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<div class="entry-content">
		<?php
		if ( is_singular() ) :
			the_content();
			wp_link_pages( array(
				'before' => '<div class="page-links">' . esc_html__( 'Pages:', 'pinlightning' ),
				'after'  => '</div>',
			) );
		else :
			the_excerpt();
		endif;
		?>
	</div>

	<footer class="entry-footer">
		<?php pinlightning_entry_footer(); ?>
	</footer>
</article>
