<?php
/**
 * Template: Emerald Editorial Homepage
 *
 * Design A — loads on inspireinlet.com (or when Customizer override is set to 'emerald').
 * Self-contained: uses own header/footer markup, does NOT call get_header()/get_footer()
 * for the nav/footer sections, but still fires wp_head/wp_footer for scripts/styles.
 *
 * @package PinLightning
 */

defined( 'ABSPATH' ) || exit;

// ── Collect all post IDs shown so far (for deduplication) ──
$shown_ids = array();

// ── Hero post: sticky or most recent ──
$hero_args = array(
	'posts_per_page'         => 1,
	'post_status'            => 'publish',
	'ignore_sticky_posts'    => false,
	'no_found_rows'          => true,
	'update_post_meta_cache' => false,
);
$sticky = get_option( 'sticky_posts' );
if ( ! empty( $sticky ) ) {
	$hero_args['post__in']            = $sticky;
	$hero_args['ignore_sticky_posts'] = true;
}
$hero_query = new WP_Query( $hero_args );
$hero_post  = $hero_query->have_posts() ? $hero_query->posts[0] : null;
if ( $hero_post ) {
	$shown_ids[] = $hero_post->ID;
}
wp_reset_postdata();

// ── Trending posts (4, skip hero) ──
$trending_query = new WP_Query( array(
	'posts_per_page'         => 4,
	'post_status'            => 'publish',
	'post__not_in'           => $shown_ids,
	'orderby'                => 'comment_count',
	'order'                  => 'DESC',
	'ignore_sticky_posts'    => true,
	'no_found_rows'          => true,
	'update_post_meta_cache' => false,
) );
foreach ( $trending_query->posts as $p ) {
	$shown_ids[] = $p->ID;
}
wp_reset_postdata();

// ── Latest posts (6, skip hero + trending) ──
$latest_query = new WP_Query( array(
	'posts_per_page'         => 6,
	'post_status'            => 'publish',
	'post__not_in'           => $shown_ids,
	'ignore_sticky_posts'    => true,
	'no_found_rows'          => true,
	'update_post_meta_cache' => false,
) );
foreach ( $latest_query->posts as $p ) {
	$shown_ids[] = $p->ID;
}
wp_reset_postdata();

// ── Popular posts (8, skip all above) ──
$popular_query = new WP_Query( array(
	'posts_per_page'         => 8,
	'post_status'            => 'publish',
	'post__not_in'           => $shown_ids,
	'orderby'                => 'comment_count',
	'order'                  => 'DESC',
	'ignore_sticky_posts'    => true,
	'no_found_rows'          => true,
	'update_post_meta_cache' => false,
) );
wp_reset_postdata();

// ── Category circles ──
$cat_circles = pl_get_category_circles( 8 );

// ── Site info ──
$brand_name  = get_theme_mod( 'pl_brand_name', get_bloginfo( 'name' ) );
$footer_desc = get_theme_mod( 'pl_footer_desc', 'Curated inspiration for modern living.' );

// ── Categories for nav + footer ──
$nav_cats = get_categories( array(
	'orderby'    => 'count',
	'order'      => 'DESC',
	'number'     => 6,
	'hide_empty' => true,
) );

// ── Newsletter settings ──
$nl_heading = get_theme_mod( 'pl_newsletter_heading', 'Join Our Community' );
$nl_desc    = get_theme_mod( 'pl_newsletter_desc', 'Get the best stories delivered to your inbox every week.' );
$nl_btn     = get_theme_mod( 'pl_newsletter_btn', 'Subscribe' );
$nl_success = get_theme_mod( 'pl_newsletter_success', "You're in! Check your inbox for a welcome note." );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php wp_head(); ?>
</head>
<body class="ee-home">

<!-- ====== SECTION 1: HEADER ====== -->
<header class="ee-header">
	<div class="ee-header-inner">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="ee-brand">
			<?php echo esc_html( $brand_name ); ?>
		</a>
		<nav class="ee-nav">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>">Home</a>
			<?php foreach ( array_slice( $nav_cats, 0, 4 ) as $cat ) : ?>
				<a href="<?php echo esc_url( get_category_link( $cat->term_id ) ); ?>">
					<?php echo esc_html( $cat->name ); ?>
				</a>
			<?php endforeach; ?>
			<button class="ee-search-toggle" aria-label="Search" onclick="document.querySelector('.ee-search-bar').classList.toggle('show')">
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
			</button>
		</nav>
		<button class="ee-hamburger" aria-label="Menu" onclick="document.querySelector('.ee-mobile-menu').classList.toggle('show')">
			<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h18M3 6h18M3 18h18"/></svg>
		</button>
	</div>
	<div class="ee-search-bar">
		<form role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
			<input type="search" name="s" placeholder="Search articles..." aria-label="Search">
		</form>
	</div>
	<div class="ee-mobile-menu">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>">Home</a>
		<?php foreach ( $nav_cats as $cat ) : ?>
			<a href="<?php echo esc_url( get_category_link( $cat->term_id ) ); ?>">
				<?php echo esc_html( $cat->name ); ?>
			</a>
		<?php endforeach; ?>
	</div>
</header>

<!-- ====== SECTION 2: HERO + TRENDING ====== -->
<section class="ee-hero-section">
	<?php if ( $hero_post ) : setup_postdata( $hero_post ); ?>
	<article class="ee-hero-main">
		<?php if ( has_post_thumbnail( $hero_post ) ) :
			echo get_the_post_thumbnail( $hero_post, 'large', array(
				'loading'       => 'eager',
				'fetchpriority' => 'high',
				'decoding'      => 'async',
			) );
		endif; ?>
		<div class="ee-hero-overlay">
			<?php $cats = get_the_category( $hero_post->ID ); if ( ! empty( $cats ) ) : ?>
				<a href="<?php echo esc_url( get_category_link( $cats[0]->term_id ) ); ?>" class="ee-hero-cat">
					<?php echo esc_html( $cats[0]->name ); ?>
				</a>
			<?php endif; ?>
			<h1 class="ee-hero-title">
				<a href="<?php echo esc_url( get_permalink( $hero_post ) ); ?>">
					<?php echo esc_html( get_the_title( $hero_post ) ); ?>
				</a>
			</h1>
			<p class="ee-hero-excerpt">
				<?php echo esc_html( wp_trim_words( $hero_post->post_excerpt ?: wp_strip_all_tags( $hero_post->post_content ), 25 ) ); ?>
			</p>
		</div>
	</article>
	<?php wp_reset_postdata(); endif; ?>

	<aside class="ee-trending">
		<h3 class="ee-trending-title">Trending Now</h3>
		<?php
		$t_num = 0;
		while ( $trending_query->have_posts() ) :
			$trending_query->the_post();
			$t_num++;
			$t_cats = get_the_category();
		?>
		<div class="ee-trending-item">
			<span class="ee-trending-num"><?php echo str_pad( $t_num, 2, '0', STR_PAD_LEFT ); ?></span>
			<?php if ( has_post_thumbnail() ) : ?>
				<img class="ee-trending-thumb" src="<?php echo esc_url( get_the_post_thumbnail_url( null, 'thumbnail' ) ); ?>"
					 alt="<?php echo esc_attr( get_the_title() ); ?>"
					 width="80" height="80" loading="lazy">
			<?php endif; ?>
			<div class="ee-trending-text">
				<?php if ( ! empty( $t_cats ) ) : ?>
					<div class="ee-cat-tag"><?php echo esc_html( $t_cats[0]->name ); ?></div>
				<?php endif; ?>
				<h4><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h4>
			</div>
		</div>
		<?php endwhile; wp_reset_postdata(); ?>
	</aside>
</section>

<!-- ====== SECTION 3: CATEGORY CIRCLES ====== -->
<?php if ( ! empty( $cat_circles ) ) : ?>
<section class="ee-categories-strip">
	<div class="ee-categories-inner">
		<?php foreach ( $cat_circles as $cc ) : ?>
		<a href="<?php echo esc_url( $cc['url'] ); ?>" class="ee-cat-circle">
			<?php if ( $cc['thumb'] ) : ?>
				<img src="<?php echo esc_url( $cc['thumb'] ); ?>"
					 alt="<?php echo esc_attr( $cc['name'] ); ?>"
					 width="90" height="90" loading="lazy">
			<?php else : ?>
				<div class="ee-cat-circle-placeholder"><?php echo esc_html( mb_substr( $cc['name'], 0, 1 ) ); ?></div>
			<?php endif; ?>
			<span><?php echo esc_html( $cc['name'] ); ?></span>
		</a>
		<?php endforeach; ?>
	</div>
</section>
<?php endif; ?>

<!-- ====== SECTION 4: LATEST POSTS ====== -->
<section class="ee-latest">
	<h2 class="ee-section-title">Latest Stories</h2>
	<div class="ee-grid-3">
		<?php
		while ( $latest_query->have_posts() ) :
			$latest_query->the_post();
			$l_cats = get_the_category();
		?>
		<article class="ee-card">
			<?php if ( has_post_thumbnail() ) : ?>
				<a href="<?php the_permalink(); ?>">
					<?php the_post_thumbnail( 'medium_large', array(
						'loading' => 'lazy',
						'decoding' => 'async',
					) ); ?>
				</a>
			<?php endif; ?>
			<div class="ee-card-body">
				<?php if ( ! empty( $l_cats ) ) : ?>
					<div class="ee-cat-tag"><?php echo esc_html( $l_cats[0]->name ); ?></div>
				<?php endif; ?>
				<h3 class="ee-card-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
				<p class="ee-card-excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 18 ) ); ?></p>
				<div class="ee-card-meta">
					<?php echo esc_html( get_the_date( 'M j, Y' ) ); ?>
				</div>
			</div>
		</article>
		<?php endwhile; wp_reset_postdata(); ?>
	</div>
</section>

<!-- ====== SECTION 5: NEWSLETTER BANNER ====== -->
<section class="ee-newsletter">
	<div class="ee-newsletter-inner">
		<h2><?php echo esc_html( $nl_heading ); ?></h2>
		<p><?php echo esc_html( $nl_desc ); ?></p>
		<form class="ee-newsletter-form" id="eeNewsletterForm">
			<input type="email" name="email" placeholder="Your email address" required aria-label="Email address">
			<div style="position:absolute;left:-9999px" aria-hidden="true">
				<input type="text" name="ee_hp" tabindex="-1" autocomplete="off">
			</div>
			<button type="submit"><?php echo esc_html( $nl_btn ); ?></button>
		</form>
		<div class="ee-newsletter-msg" id="eeNewsletterMsg"></div>
	</div>
</section>

<!-- ====== SECTION 6: MOST LOVED ====== -->
<?php if ( $popular_query->have_posts() ) : ?>
<section class="ee-popular">
	<h2 class="ee-section-title">Most Loved</h2>
	<div class="ee-grid-4">
		<?php while ( $popular_query->have_posts() ) : $popular_query->the_post(); ?>
		<a href="<?php the_permalink(); ?>" class="ee-portrait">
			<?php if ( has_post_thumbnail() ) :
				the_post_thumbnail( 'medium_large', array(
					'loading' => 'lazy',
					'decoding' => 'async',
				) );
			endif; ?>
			<div class="ee-portrait-overlay">
				<div class="ee-portrait-title"><?php the_title(); ?></div>
			</div>
		</a>
		<?php endwhile; wp_reset_postdata(); ?>
	</div>
</section>
<?php endif; ?>

<!-- ====== SECTION 7: FOOTER ====== -->
<footer class="ee-footer">
	<div class="ee-footer-inner">
		<div>
			<div class="ee-footer-brand"><?php echo esc_html( $brand_name ); ?></div>
			<p class="ee-footer-desc"><?php echo esc_html( $footer_desc ); ?></p>
		</div>
		<div>
			<h4>Quick Links</h4>
			<ul>
				<li><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Home</a></li>
				<?php
				$about_page = get_page_by_path( 'about' );
				if ( $about_page ) :
				?>
					<li><a href="<?php echo esc_url( get_permalink( $about_page ) ); ?>">About</a></li>
				<?php endif; ?>
				<?php
				$contact_page = get_page_by_path( 'contact' );
				if ( $contact_page ) :
				?>
					<li><a href="<?php echo esc_url( get_permalink( $contact_page ) ); ?>">Contact</a></li>
				<?php endif; ?>
				<?php
				$privacy_page = get_privacy_policy_url();
				if ( $privacy_page ) :
				?>
					<li><a href="<?php echo esc_url( $privacy_page ); ?>">Privacy Policy</a></li>
				<?php endif; ?>
			</ul>
		</div>
		<div>
			<h4>Categories</h4>
			<ul>
				<?php foreach ( array_slice( $nav_cats, 0, 6 ) as $cat ) : ?>
					<li><a href="<?php echo esc_url( get_category_link( $cat->term_id ) ); ?>"><?php echo esc_html( $cat->name ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</div>
	</div>
	<div class="ee-footer-bottom">
		&copy; <?php echo esc_html( gmdate( 'Y' ) . ' ' . $brand_name ); ?>. All rights reserved.
	</div>
</footer>

<!-- Newsletter inline JS (no jQuery, tiny) -->
<script>
(function(){
	var f=document.getElementById('eeNewsletterForm'),m=document.getElementById('eeNewsletterMsg');
	if(!f)return;
	f.addEventListener('submit',function(e){
		e.preventDefault();
		if(f.ee_hp&&f.ee_hp.value)return;
		var email=f.email.value.trim();
		if(!email)return;
		var btn=f.querySelector('button');
		btn.disabled=true;btn.textContent='...';
		fetch('<?php echo esc_url( rest_url( 'pl/v1/subscribe' ) ); ?>',{
			method:'POST',
			headers:{'Content-Type':'application/json','X-WP-Nonce':'<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>'},
			body:JSON.stringify({email:email,source:'newsletter',source_detail:'emerald_homepage'})
		}).then(function(r){return r.json()}).then(function(d){
			m.textContent=d.message||<?php echo wp_json_encode( $nl_success ); ?>;
			m.className='ee-newsletter-msg '+(d.success?'success':'error');
			if(d.success){f.email.value='';f.email.disabled=true;}
			btn.disabled=false;btn.textContent=<?php echo wp_json_encode( $nl_btn ); ?>;
		}).catch(function(){
			m.textContent='Something went wrong. Please try again.';
			m.className='ee-newsletter-msg error';
			btn.disabled=false;btn.textContent=<?php echo wp_json_encode( $nl_btn ); ?>;
		});
	});
})();
</script>

<?php wp_footer(); ?>
</body>
</html>
