<?php
/**
 * The main template file.
 *
 * Pinterest-style masonry grid with CSS columns.
 *
 * @package PinLightning
 * @since 1.0.0
 */

get_header();
?>

<?php if ( is_home() && is_front_page() ) : ?>
	<div class="category-filter">
		<div class="category-filter-inner">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="cat-pill <?php echo ! is_category() ? 'active' : ''; ?>"><?php esc_html_e( 'All', 'pinlightning' ); ?></a>
			<?php
			$categories = get_categories( array( 'hide_empty' => true ) );
			foreach ( $categories as $cat ) :
				?>
				<a href="<?php echo esc_url( get_category_link( $cat->term_id ) ); ?>" class="cat-pill"><?php echo esc_html( $cat->name ); ?></a>
			<?php endforeach; ?>
		</div>
	</div>
<?php endif; ?>

<?php if ( is_archive() ) : ?>
	<header class="archive-header">
		<?php
		the_archive_title( '<h1 class="archive-title">', '</h1>' );
		the_archive_description( '<p class="archive-description">', '</p>' );
		?>
	</header>
<?php endif; ?>

<?php if ( is_home() && ! is_front_page() ) : ?>
	<header class="archive-header">
		<h1 class="archive-title"><?php single_post_title(); ?></h1>
	</header>
<?php endif; ?>

<?php if ( have_posts() ) : ?>
	<div class="card-grid">
		<?php
		while ( have_posts() ) :
			the_post();
			get_template_part( 'template-parts/content', 'card' );
		endwhile;
		?>
	</div>

	<nav class="posts-nav">
		<?php
		$prev = get_previous_posts_link( '&larr; ' . esc_html__( 'Newer Posts', 'pinlightning' ) );
		$next = get_next_posts_link( esc_html__( 'Older Posts', 'pinlightning' ) . ' &rarr;' );
		if ( $prev ) :
			?>
			<span class="posts-nav-prev"><?php echo $prev; // phpcs:ignore ?></span>
		<?php endif; ?>
		<?php if ( $next ) : ?>
			<span class="posts-nav-next"><?php echo $next; // phpcs:ignore ?></span>
		<?php endif; ?>
	</nav>
<?php else : ?>
	<div class="no-results">
		<h2><?php esc_html_e( 'Nothing Found', 'pinlightning' ); ?></h2>
		<p><?php esc_html_e( 'Try a search?', 'pinlightning' ); ?></p>
		<?php get_search_form(); ?>
	</div>
<?php endif; ?>

<?php
get_footer();
