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

	<?php if ( pl_is_listicle_post() ) : ?>
	<!-- Engagement: Sticky bar (desktop) -->
	<div class="eb-sticky-bar">
		<div class="eb-progress"><div class="eb-progress-fill" id="ebProgressFill"></div></div>
		<div class="eb-sticky-inner">
			<div class="eb-pills" id="ebPills">
				<?php for ( $i = 1; $i <= pl_count_listicle_items(); $i++ ) : ?>
					<button class="eb-pill" data-eb-action="jump" data-jump="<?php echo $i; ?>" data-item="<?php echo $i; ?>"><?php echo $i; ?></button>
				<?php endfor; ?>
			</div>
			<div class="eb-counter" id="ebCounter">
				<span class="num" id="ebCountNum">0</span> / <?php echo (int) pl_count_listicle_items(); ?> ideas seen
				<span class="eb-counter-live" id="ebCounterLive"> &middot; <span class="eb-live-dot"></span> <span id="ebCounterLiveNum">47</span> reading</span>
			</div>
		</div>
	</div>

	<div class="eb-streak" id="ebStreak"></div>
	<div class="eb-speed-warn" id="ebSpeedWarn">Slow down! You're about to miss the best one</div>

	<div class="eb-milestone" id="ebMilestone">
		<div class="eb-milestone-emoji" id="ebMilestoneEmoji"></div>
		<div class="eb-milestone-text" id="ebMilestoneText"></div>
		<div class="eb-milestone-sub" id="ebMilestoneSub"></div>
	</div>

	<div class="eb-achievement" id="ebAchievement">
		<span class="eb-achievement-icon"><?php echo "✨"; ?></span>
		<div>
			<div class="eb-achievement-title">Style Expert</div>
			<div class="eb-achievement-sub">You've seen all <?php echo (int) pl_count_listicle_items(); ?> looks!</div>
		</div>
	</div>

	<div class="eb-desktop-only">
	<div class="eb-collect-counter" id="ebCollectCounter">
		<?php echo "\xF0\x9F\x92\x8E"; ?> <span id="ebCollectNum">0</span>/5
	</div>
	</div>

	<?php
		$eb_next = pl_get_next_post_data();
		if ( $eb_next ) :
	?>
	<div class="eb-next-bar" id="ebNextBar">
		<?php if ( $eb_next['img'] ) : ?>
			<img class="eb-next-bar-img" src="<?php echo esc_url( $eb_next['img'] ); ?>" alt="" width="50" height="50" loading="lazy">
		<?php endif; ?>
		<span class="eb-next-bar-title"><?php echo esc_html( $eb_next['title'] ); ?></span>
		<button class="eb-next-bar-btn" data-eb-action="next" data-url="<?php echo esc_url( $eb_next['url'] ); ?>">Read Next <?php echo "\xE2\x86\x92"; ?></button>
	</div>
	<?php endif; ?>

	<div class="eb-exit" id="ebExit">
		<div class="eb-exit-text">Wait — you saved <strong id="ebExitFavCount">0</strong> looks! Want us to <strong>email you the full collection</strong>?</div>
		<button class="eb-exit-btn" data-eb-action="email">Yes Please!</button>
		<button class="eb-exit-close" data-eb-action="exit-close">&times;</button>
	</div>
	<?php endif; ?>

	<?php if ( has_post_thumbnail() && ! pl_is_listicle_post() ) : ?>
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

			<?php echo pl_render_ad_anchor( 'post-top', array( 'location' => 'content' ) ); ?>

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

	<?php echo pl_render_ad_anchor( 'post-bottom', array( 'location' => 'content' ) ); ?>

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
		<!-- Sidebar top ad anchor -->
		<div class="ad-anchor" data-position="sidebar-top" data-item="0" data-location="sidebar-top"></div>

		<!-- Popular Posts -->
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

		<!-- Sidebar bottom ad anchor -->
		<div class="ad-anchor" data-position="sidebar-bottom" data-item="0" data-location="sidebar-bottom"></div>
	</aside>

<?php endwhile; ?>

<?php if ( get_theme_mod( 'pl_pin_button_show', true ) && is_singular( 'post' ) ) : ?>
<script>
(function(){
	var wraps=document.querySelectorAll('.pl-pin-wrap');
	if(!wraps.length)return;
	if('IntersectionObserver' in window){
		var io=new IntersectionObserver(function(entries){
			entries.forEach(function(e){
				if(e.isIntersecting){setTimeout(function(){e.target.classList.add('pl-pin-visible')},1500)}
				else{e.target.classList.remove('pl-pin-visible')}
			});
		},{threshold:0.5});
		wraps.forEach(function(w){io.observe(w)});
		var eo=new IntersectionObserver(function(entries){
			entries.forEach(function(e){
				if(e.isIntersecting){setTimeout(function(){
					if(e.target.getBoundingClientRect().top<window.innerHeight)e.target.classList.add('pl-pin-engaged');
				},2000)}
			});
		},{threshold:0.3});
		wraps.forEach(function(w){eo.observe(w)});
	}
	var pinData={saves:0,images:[]};
	document.addEventListener('click',function(e){
		var btn=e.target.closest('.pl-pin-btn');
		if(!btn)return;
		e.preventDefault();
		btn.classList.add('pl-pin-saved');
		btn.innerHTML='<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg> Saved!';
		pinData.saves++;
		var img=btn.getAttribute('data-img');
		if(img)pinData.images.push(img);
		window.__plPinData=pinData;
		window.open(btn.href,'_blank','noopener');
	});
})();
</script>
<?php endif; ?>

<?php
get_footer();
