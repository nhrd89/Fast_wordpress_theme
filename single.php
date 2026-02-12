<?php
/**
 * The template for displaying single posts.
 *
 * Hero image, reading time, clean article typography, related posts, infinite scroll.
 *
 * @package PinLightning
 * @since 1.0.0
 */

get_header();

while ( have_posts() ) :
	the_post();

	// Reading time estimate.
	$content    = get_the_content();
	$word_count = str_word_count( wp_strip_all_tags( $content ) );
	$read_time  = max( 1, (int) ceil( $word_count / 250 ) );

?>

	<?php if ( has_post_thumbnail() ) : ?>
		<div class="post-hero">
			<?php the_post_thumbnail( 'large', array( 'class' => 'post-hero-img' ) ); ?>
		</div>
	<?php endif; ?>

	<div class="single-layout">
		<article id="post-<?php the_ID(); ?>" <?php post_class( 'single-article' ); ?>>
			<header class="single-header">
				<?php
				$categories = get_the_category();
				if ( $categories ) :
					?>
					<div class="single-cats">
						<?php foreach ( $categories as $cat ) : ?>
							<a href="<?php echo esc_url( get_category_link( $cat->term_id ) ); ?>" class="cat-label"><?php echo esc_html( $cat->name ); ?></a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<h1 class="single-title"><?php the_title(); ?></h1>

				<div class="single-meta">
					<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
					<span class="meta-sep">&middot;</span>
					<span class="read-time">
						<?php
						printf(
							/* translators: %d: number of minutes */
							esc_html( _n( '%d min read', '%d min read', $read_time, 'pinlightning' ) ),
							$read_time
						);
						?>
					</span>
				</div>
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

			<footer class="single-footer">
				<?php
				$tags = get_the_tags();
				if ( $tags ) :
					?>
					<div class="single-tags">
						<?php foreach ( $tags as $tag ) : ?>
							<a href="<?php echo esc_url( get_tag_link( $tag->term_id ) ); ?>" class="tag-link">#<?php echo esc_html( $tag->name ); ?></a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</footer>
		</article>
	</div>

	<?php if ( comments_open() || get_comments_number() ) : ?>
		<details class="comments-toggle">
			<summary><?php echo get_comments_number() > 0 ? sprintf( esc_html__( 'Comments (%d)', 'pinlightning' ), get_comments_number() ) : esc_html__( 'Leave a Comment', 'pinlightning' ); ?></summary>
			<?php comments_template(); ?>
		</details>
	<?php endif; ?>

	<?php
	// Related posts: same category, 3 posts.
	$related_cats = wp_get_post_categories( get_the_ID(), array( 'fields' => 'ids' ) );
	if ( $related_cats ) :
		$related_query = new WP_Query( array(
			'category__in'        => $related_cats,
			'post__not_in'        => array( get_the_ID() ),
			'posts_per_page'      => 3,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		) );

		if ( $related_query->have_posts() ) :
			?>
			<section class="related-posts">
				<h2 class="related-title"><?php esc_html_e( 'You Might Also Like', 'pinlightning' ); ?></h2>
				<div class="related-grid">
					<?php
					while ( $related_query->have_posts() ) :
						$related_query->the_post();
						get_template_part( 'template-parts/content', 'card' );
					endwhile;
					wp_reset_postdata();
					?>
				</div>
			</section>
		<?php endif; ?>
	<?php endif; ?>

	<aside class="sidebar" aria-label="Sidebar">
		<!-- Ad slot placeholder - replace with ad code later -->
		<div class="sidebar-ad-slot">
			<div class="sidebar-ad-placeholder">Ad Space</div>
		</div>

		<!-- Popular Posts as temporary content -->
		<div class="sidebar-widget">
			<h3 class="sidebar-widget-title">Popular Posts</h3>
			<?php
			$popular = new WP_Query( array(
				'posts_per_page' => 5,
				'orderby'        => 'rand',
				'post_status'    => 'publish',
				'post__not_in'   => array( get_the_ID() ),
			) );
			if ( $popular->have_posts() ) :
				while ( $popular->have_posts() ) :
					$popular->the_post();
			?>
				<a href="<?php the_permalink(); ?>" class="sidebar-post">
					<?php if ( has_post_thumbnail() ) : ?>
						<img src="<?php echo esc_url( get_the_post_thumbnail_url( get_the_ID(), 'thumbnail' ) ); ?>"
							alt="<?php echo esc_attr( get_the_title() ); ?>"
							class="sidebar-post-img"
							loading="lazy"
							width="60" height="60">
					<?php endif; ?>
					<span class="sidebar-post-title"><?php the_title(); ?></span>
				</a>
			<?php
				endwhile;
				wp_reset_postdata();
			endif;
			?>
		</div>

		<!-- Second ad slot placeholder -->
		<div class="sidebar-ad-slot">
			<div class="sidebar-ad-placeholder">Ad Space</div>
		</div>
	</aside>

<?php endwhile; ?>

<?php
get_footer();
