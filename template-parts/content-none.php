<?php
/**
 * Template part for displaying a message when no posts are found.
 *
 * @package PinLightning
 * @since 1.0.0
 */
?>

<section class="no-results not-found">
	<header class="page-header">
		<h1 class="page-title"><?php esc_html_e( 'Nothing Found', 'pinlightning' ); ?></h1>
	</header>

	<div class="page-content">
		<?php if ( is_search() ) : ?>
			<p><?php esc_html_e( 'Sorry, no results matched your search terms. Please try again with different keywords.', 'pinlightning' ); ?></p>
			<?php get_search_form(); ?>
		<?php else : ?>
			<p><?php esc_html_e( 'It seems we can&rsquo;t find what you&rsquo;re looking for. Perhaps a search might help.', 'pinlightning' ); ?></p>
			<?php get_search_form(); ?>
		<?php endif; ?>
	</div>
</section>
