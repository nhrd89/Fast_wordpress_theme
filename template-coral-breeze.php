<?php
/**
 * Template: Coral Breeze Homepage
 *
 * Design B — loads on pulsepathlife.com (or when Customizer override is set to 'coral').
 * Uses own header markup, shared footer via get_footer() (coral/navy colors via CSS overrides).
 * Fires wp_head/wp_footer for scripts/styles.
 *
 * @package PinLightning
 */

defined( 'ABSPATH' ) || exit;

// ── Collect all post IDs shown so far (for deduplication) ──
$shown_ids = array();

// ── Shared category list (used by Trending + Latest) ──
$cb_cats = get_categories( array(
	'orderby'    => 'count',
	'order'      => 'DESC',
	'number'     => 20,
	'hide_empty' => true,
) );

// ── Hero posts: 2 most recent from different categories ──
$hero_posts = array();
$hero_cat_ids = array();
foreach ( $cb_cats as $cat ) {
	if ( count( $hero_posts ) >= 2 ) {
		break;
	}
	$q = new WP_Query( array(
		'posts_per_page'         => 1,
		'post_status'            => 'publish',
		'cat'                    => $cat->term_id,
		'post__not_in'           => $shown_ids,
		'ignore_sticky_posts'    => true,
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
	) );
	if ( $q->have_posts() ) {
		$hero_posts[]   = $q->posts[0];
		$hero_cat_ids[] = $cat->term_id;
		$shown_ids[]    = $q->posts[0]->ID;
	}
	wp_reset_postdata();
}
// Backfill if fewer than 2 categories had posts.
if ( count( $hero_posts ) < 2 ) {
	$backfill = new WP_Query( array(
		'posts_per_page'         => 2 - count( $hero_posts ),
		'post_status'            => 'publish',
		'post__not_in'           => $shown_ids,
		'orderby'                => 'date',
		'order'                  => 'DESC',
		'ignore_sticky_posts'    => true,
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
	) );
	foreach ( $backfill->posts as $p ) {
		$hero_posts[] = $p;
		$shown_ids[]  = $p->ID;
	}
	wp_reset_postdata();
}

// ── Trending posts (6, per-category diversity, max 2 per category) ──
$trending_posts  = array();
$trend_cat_used  = array();
// Pass 1: one post per category.
foreach ( $cb_cats as $cat ) {
	if ( count( $trending_posts ) >= 6 ) {
		break;
	}
	$q = new WP_Query( array(
		'posts_per_page'         => 1,
		'post_status'            => 'publish',
		'cat'                    => $cat->term_id,
		'post__not_in'           => $shown_ids,
		'ignore_sticky_posts'    => true,
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
	) );
	if ( $q->have_posts() ) {
		$trending_posts[] = $q->posts[0];
		$shown_ids[]      = $q->posts[0]->ID;
		$trend_cat_used[ $cat->term_id ] = 1;
	}
	wp_reset_postdata();
}
// Pass 2: second post per category if still need more.
if ( count( $trending_posts ) < 6 ) {
	foreach ( $cb_cats as $cat ) {
		if ( count( $trending_posts ) >= 6 ) {
			break;
		}
		if ( ( $trend_cat_used[ $cat->term_id ] ?? 0 ) >= 2 ) {
			continue;
		}
		$q = new WP_Query( array(
			'posts_per_page'         => 1,
			'post_status'            => 'publish',
			'cat'                    => $cat->term_id,
			'post__not_in'           => $shown_ids,
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
		) );
		if ( $q->have_posts() ) {
			$trending_posts[] = $q->posts[0];
			$shown_ids[]      = $q->posts[0]->ID;
			$trend_cat_used[ $cat->term_id ] = ( $trend_cat_used[ $cat->term_id ] ?? 0 ) + 1;
		}
		wp_reset_postdata();
	}
}
// Backfill with date-ordered posts.
if ( count( $trending_posts ) < 6 ) {
	$backfill = new WP_Query( array(
		'posts_per_page'         => 6 - count( $trending_posts ),
		'post_status'            => 'publish',
		'post__not_in'           => $shown_ids,
		'orderby'                => 'date',
		'order'                  => 'DESC',
		'ignore_sticky_posts'    => true,
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
	) );
	foreach ( $backfill->posts as $p ) {
		$trending_posts[] = $p;
		$shown_ids[]      = $p->ID;
	}
	wp_reset_postdata();
}

// ── Category Spotlight: top category with featured image + 3 posts ──
$spotlight_cat   = ! empty( $cb_cats ) ? $cb_cats[0] : null;
$spotlight_posts = array();
if ( $spotlight_cat ) {
	$sq = new WP_Query( array(
		'posts_per_page'         => 4,
		'post_status'            => 'publish',
		'cat'                    => $spotlight_cat->term_id,
		'post__not_in'           => $shown_ids,
		'ignore_sticky_posts'    => true,
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
	) );
	$spotlight_posts = $sq->posts;
	foreach ( $spotlight_posts as $p ) {
		$shown_ids[] = $p->ID;
	}
	wp_reset_postdata();
}

// ── Latest posts (8, per-category diversity, max 2 per category) ──
$latest_posts    = array();
$latest_cat_used = array();
// Pass 1: one post per category.
foreach ( $cb_cats as $cat ) {
	if ( count( $latest_posts ) >= 8 ) {
		break;
	}
	$q = new WP_Query( array(
		'posts_per_page'         => 1,
		'post_status'            => 'publish',
		'cat'                    => $cat->term_id,
		'post__not_in'           => $shown_ids,
		'ignore_sticky_posts'    => true,
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
	) );
	if ( $q->have_posts() ) {
		$latest_posts[] = $q->posts[0];
		$shown_ids[]    = $q->posts[0]->ID;
		$latest_cat_used[ $cat->term_id ] = 1;
	}
	wp_reset_postdata();
}
// Pass 2: second post per category.
if ( count( $latest_posts ) < 8 ) {
	foreach ( $cb_cats as $cat ) {
		if ( count( $latest_posts ) >= 8 ) {
			break;
		}
		if ( ( $latest_cat_used[ $cat->term_id ] ?? 0 ) >= 2 ) {
			continue;
		}
		$q = new WP_Query( array(
			'posts_per_page'         => 1,
			'post_status'            => 'publish',
			'cat'                    => $cat->term_id,
			'post__not_in'           => $shown_ids,
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
		) );
		if ( $q->have_posts() ) {
			$latest_posts[] = $q->posts[0];
			$shown_ids[]    = $q->posts[0]->ID;
			$latest_cat_used[ $cat->term_id ] = ( $latest_cat_used[ $cat->term_id ] ?? 0 ) + 1;
		}
		wp_reset_postdata();
	}
}
// Backfill.
if ( count( $latest_posts ) < 8 ) {
	$backfill = new WP_Query( array(
		'posts_per_page'         => 8 - count( $latest_posts ),
		'post_status'            => 'publish',
		'post__not_in'           => $shown_ids,
		'orderby'                => 'date',
		'order'                  => 'DESC',
		'ignore_sticky_posts'    => true,
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
	) );
	foreach ( $backfill->posts as $p ) {
		$latest_posts[] = $p;
		$shown_ids[]    = $p->ID;
	}
	wp_reset_postdata();
}

// ── Category pills ──
$cat_pills = get_categories( array(
	'orderby'    => 'count',
	'order'      => 'DESC',
	'number'     => 8,
	'hide_empty' => true,
) );

// ── Site info ──
$brand_name = get_theme_mod( 'pl_brand_name', get_bloginfo( 'name' ) );

// ── Categories for nav ──
$nav_cats = get_categories( array(
	'orderby'    => 'count',
	'order'      => 'DESC',
	'number'     => 6,
	'hide_empty' => true,
) );

// ── Newsletter settings ──
$nl_heading = get_theme_mod( 'pl_newsletter_heading', 'Stay Inspired' );
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
<body class="cb-home">

<!-- ====== SECTION 1: HEADER ====== -->
<header class="cb-header">
	<div class="cb-header-inner">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="cb-brand">
			<?php echo esc_html( $brand_name ); ?>
		</a>
		<nav class="cb-nav">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>">Home</a>
			<?php foreach ( array_slice( $nav_cats, 0, 4 ) as $cat ) : ?>
				<a href="<?php echo esc_url( get_category_link( $cat->term_id ) ); ?>">
					<?php echo esc_html( $cat->name ); ?>
				</a>
			<?php endforeach; ?>
			<button class="cb-search-toggle" aria-label="Search" onclick="document.querySelector('.cb-search-bar').classList.toggle('show')">
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
			</button>
		</nav>
		<button class="cb-hamburger" aria-label="Menu" onclick="document.querySelector('.cb-mobile-menu').classList.toggle('show')">
			<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h18M3 6h18M3 18h18"/></svg>
		</button>
	</div>
	<div class="cb-search-bar">
		<form role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
			<input type="search" name="s" placeholder="Search articles..." aria-label="Search">
		</form>
	</div>
	<div class="cb-mobile-menu">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>">Home</a>
		<?php foreach ( $nav_cats as $cat ) : ?>
			<a href="<?php echo esc_url( get_category_link( $cat->term_id ) ); ?>">
				<?php echo esc_html( $cat->name ); ?>
			</a>
		<?php endforeach; ?>
	</div>
</header>

<!-- ====== SECTION 2: TWIN HERO CARDS ====== -->
<section class="cb-hero-section">
	<?php foreach ( $hero_posts as $h_idx => $h_post ) :
		$h_cats = get_the_category( $h_post->ID );
	?>
	<article class="cb-hero-card">
		<?php if ( has_post_thumbnail( $h_post ) ) :
			echo get_the_post_thumbnail( $h_post, 'large', array(
				'loading'       => 'eager',
				'fetchpriority' => 'high',
				'decoding'      => 'async',
			) );
		endif; ?>
		<div class="cb-hero-overlay">
			<?php if ( ! empty( $h_cats ) ) : ?>
				<a href="<?php echo esc_url( get_category_link( $h_cats[0]->term_id ) ); ?>" class="cb-hero-cat">
					<?php echo esc_html( $h_cats[0]->name ); ?>
				</a>
			<?php endif; ?>
			<h2 class="cb-hero-title">
				<a href="<?php echo esc_url( get_permalink( $h_post ) ); ?>">
					<?php echo esc_html( get_the_title( $h_post ) ); ?>
				</a>
			</h2>
			<a href="<?php echo esc_url( get_permalink( $h_post ) ); ?>" class="cb-hero-link">Read Article &rarr;</a>
		</div>
	</article>
	<?php endforeach; ?>
</section>

<!-- Page Ad Anchor: between hero and category pills -->
<div class="pl-page-ad-anchor" data-slot="homepage-1"></div>

<!-- ====== SECTION 3: CATEGORY PILLS ====== -->
<?php if ( ! empty( $cat_pills ) ) : ?>
<section class="cb-cat-pills-section">
	<div class="cb-cat-pills-inner">
		<?php foreach ( $cat_pills as $cp ) : ?>
		<a href="<?php echo esc_url( get_category_link( $cp->term_id ) ); ?>" class="cb-cat-pill">
			<?php echo esc_html( $cp->name ); ?> <span class="cb-pill-count"><?php echo esc_html( $cp->count ); ?></span>
		</a>
		<?php endforeach; ?>
	</div>
</section>
<?php endif; ?>

<!-- ====== SECTION 4: TRENDING NOW ====== -->
<?php if ( ! empty( $trending_posts ) ) : ?>
<section class="cb-trending">
	<h2 class="cb-section-title">Trending Now</h2>
	<div class="cb-grid-3">
		<?php foreach ( $trending_posts as $t_post ) :
			$t_cats = get_the_category( $t_post->ID );
			$word_count = str_word_count( wp_strip_all_tags( $t_post->post_content ) );
			$read_time  = max( 1, (int) ceil( $word_count / 200 ) );
		?>
		<article class="cb-card">
			<?php if ( has_post_thumbnail( $t_post ) ) : ?>
				<a href="<?php echo esc_url( get_permalink( $t_post ) ); ?>">
					<?php echo get_the_post_thumbnail( $t_post, 'medium_large', array(
						'loading' => 'lazy',
						'decoding' => 'async',
						'class' => 'cb-card-img-wide',
					) ); ?>
				</a>
			<?php endif; ?>
			<div class="cb-card-body">
				<?php if ( ! empty( $t_cats ) ) : ?>
					<span class="cb-cat-tag"><?php echo esc_html( $t_cats[0]->name ); ?></span>
				<?php endif; ?>
				<h3 class="cb-card-title"><a href="<?php echo esc_url( get_permalink( $t_post ) ); ?>"><?php echo esc_html( get_the_title( $t_post ) ); ?></a></h3>
				<p class="cb-card-excerpt"><?php echo esc_html( wp_trim_words( $t_post->post_excerpt ?: wp_strip_all_tags( $t_post->post_content ), 18 ) ); ?></p>
				<div class="cb-card-meta">
					<?php echo esc_html( get_the_date( 'M j, Y', $t_post ) ); ?> &middot; <?php echo esc_html( $read_time . ' min read' ); ?>
				</div>
			</div>
		</article>
		<?php endforeach; ?>
	</div>
</section>
<?php endif; ?>

<!-- Page Ad Anchor: between trending and spotlight -->
<div class="pl-page-ad-anchor" data-slot="homepage-2"></div>

<!-- ====== SECTION 5: CATEGORY SPOTLIGHT ====== -->
<?php if ( $spotlight_cat && ! empty( $spotlight_posts ) ) :
	$spot_featured = $spotlight_posts[0];
	$spot_list     = array_slice( $spotlight_posts, 1, 3 );
?>
<section class="cb-spotlight">
	<div class="cb-spotlight-inner">
		<div class="cb-spotlight-image">
			<?php if ( has_post_thumbnail( $spot_featured ) ) :
				echo get_the_post_thumbnail( $spot_featured, 'large', array(
					'loading' => 'lazy',
					'decoding' => 'async',
				) );
			endif; ?>
			<a href="<?php echo esc_url( get_permalink( $spot_featured ) ); ?>" class="cb-spotlight-img-link">
				<span><?php echo esc_html( get_the_title( $spot_featured ) ); ?></span>
			</a>
		</div>
		<div class="cb-spotlight-content">
			<h2 class="cb-spotlight-cat"><?php echo esc_html( $spotlight_cat->name ); ?></h2>
			<?php foreach ( $spot_list as $sp ) : ?>
			<div class="cb-spotlight-item">
				<h3><a href="<?php echo esc_url( get_permalink( $sp ) ); ?>"><?php echo esc_html( get_the_title( $sp ) ); ?></a></h3>
				<p><?php echo esc_html( wp_trim_words( $sp->post_excerpt ?: wp_strip_all_tags( $sp->post_content ), 15 ) ); ?></p>
			</div>
			<?php endforeach; ?>
			<a href="<?php echo esc_url( get_category_link( $spotlight_cat->term_id ) ); ?>" class="cb-spotlight-more">View All &rarr;</a>
		</div>
	</div>
</section>
<?php endif; ?>

<!-- Page Ad Anchor: between spotlight and latest -->
<div class="pl-page-ad-anchor" data-slot="homepage-3"></div>

<!-- ====== SECTION 6: LATEST STORIES ====== -->
<?php if ( ! empty( $latest_posts ) ) : ?>
<section class="cb-latest">
	<h2 class="cb-section-title">Latest Stories</h2>
	<div class="cb-grid-4">
		<?php foreach ( $latest_posts as $l_post ) :
			$l_cats = get_the_category( $l_post->ID );
		?>
		<article class="cb-card-sq">
			<?php if ( has_post_thumbnail( $l_post ) ) : ?>
				<a href="<?php echo esc_url( get_permalink( $l_post ) ); ?>">
					<?php echo get_the_post_thumbnail( $l_post, 'medium_large', array(
						'loading' => 'lazy',
						'decoding' => 'async',
						'class' => 'cb-card-img-sq',
					) ); ?>
				</a>
			<?php endif; ?>
			<div class="cb-card-sq-body">
				<?php if ( ! empty( $l_cats ) ) : ?>
					<span class="cb-cat-tag"><?php echo esc_html( $l_cats[0]->name ); ?></span>
				<?php endif; ?>
				<h3 class="cb-card-sq-title"><a href="<?php echo esc_url( get_permalink( $l_post ) ); ?>"><?php echo esc_html( get_the_title( $l_post ) ); ?></a></h3>
				<div class="cb-card-meta">
					<?php echo esc_html( get_the_date( 'M j, Y', $l_post ) ); ?>
				</div>
			</div>
		</article>
		<?php endforeach; ?>
	</div>

	<!-- ====== SECTION 7: LOAD MORE ====== -->
	<div class="cb-load-more-wrap">
		<button class="cb-load-more" id="cbLoadMore"
			data-exclude="<?php echo esc_attr( implode( ',', $shown_ids ) ); ?>"
			data-page="2"
			data-rest="<?php echo esc_url( rest_url( 'wp/v2/posts' ) ); ?>">
			Load More Stories
		</button>
	</div>
</section>
<?php endif; ?>

<!-- ====== SECTION 8: NEWSLETTER ====== -->
<section class="cb-newsletter">
	<div class="cb-newsletter-inner">
		<h2><?php echo esc_html( $nl_heading ); ?></h2>
		<p><?php echo esc_html( $nl_desc ); ?></p>
		<form class="cb-newsletter-form" id="cbNewsletterForm">
			<input type="email" name="email" placeholder="Your email address" required aria-label="Email address">
			<div style="position:absolute;left:-9999px" aria-hidden="true">
				<input type="text" name="cb_hp" tabindex="-1" autocomplete="off">
			</div>
			<button type="submit"><?php echo esc_html( $nl_btn ); ?></button>
		</form>
		<div class="cb-newsletter-msg" id="cbNewsletterMsg"></div>
	</div>
</section>

<!-- Newsletter inline JS (no jQuery, tiny) -->
<script>
(function(){
	var f=document.getElementById('cbNewsletterForm'),m=document.getElementById('cbNewsletterMsg');
	if(!f)return;
	f.addEventListener('submit',function(e){
		e.preventDefault();
		if(f.cb_hp&&f.cb_hp.value)return;
		var email=f.email.value.trim();
		if(!email)return;
		var btn=f.querySelector('button');
		btn.disabled=true;btn.textContent='...';
		fetch('<?php echo esc_url( rest_url( 'pl/v1/subscribe' ) ); ?>',{
			method:'POST',
			headers:{'Content-Type':'application/json','X-WP-Nonce':'<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>'},
			body:JSON.stringify({email:email,source:'newsletter',source_detail:'coral_homepage'})
		}).then(function(r){return r.json()}).then(function(d){
			m.textContent=d.message||<?php echo wp_json_encode( $nl_success ); ?>;
			m.className='cb-newsletter-msg '+(d.success?'success':'error');
			if(d.success){f.email.value='';f.email.disabled=true;}
			btn.disabled=false;btn.textContent=<?php echo wp_json_encode( $nl_btn ); ?>;
		}).catch(function(){
			m.textContent='Something went wrong. Please try again.';
			m.className='cb-newsletter-msg error';
			btn.disabled=false;btn.textContent=<?php echo wp_json_encode( $nl_btn ); ?>;
		});
	});
})();
</script>

<!-- Load More inline JS (no jQuery) -->
<script>
(function(){
	var btn=document.getElementById('cbLoadMore');
	if(!btn)return;
	var grid=document.querySelector('.cb-grid-4');
	var rest=btn.getAttribute('data-rest');
	var exclude=btn.getAttribute('data-exclude');
	var page=parseInt(btn.getAttribute('data-page'),10);
	var perPage=8;
	var loading=false;
	var label='Load More Stories';

	btn.addEventListener('click',function(){
		if(loading)return;
		loading=true;
		btn.disabled=true;
		btn.textContent='Loading...';
		var url=rest+'?per_page='+perPage+'&page='+page+'&_embed&exclude='+encodeURIComponent(exclude);
		fetch(url).then(function(r){
			var totalPages=parseInt(r.headers.get('X-WP-TotalPages'),10)||1;
			if(!r.ok)throw new Error(r.status);
			return r.json().then(function(posts){return{posts:posts,totalPages:totalPages}});
		}).then(function(data){
			var posts=data.posts;
			var ids=exclude?exclude.split(','):[];
			for(var i=0;i<posts.length;i++){
				var p=posts[i];
				ids.push(p.id);
				var img='';
				var media=p._embedded&&p._embedded['wp:featuredmedia']&&p._embedded['wp:featuredmedia'][0];
				if(media&&media.source_url){
					img='<a href="'+esc(p.link)+'"><img src="'+esc(media.source_url)+'" alt="'+esc(p.title.rendered)+'" loading="lazy" decoding="async" class="cb-card-img-sq" style="width:100%;aspect-ratio:1/1;object-fit:cover;display:block;border-radius:8px"></a>';
				}
				var catTag='';
				var terms=p._embedded&&p._embedded['wp:term']&&p._embedded['wp:term'][0];
				if(terms&&terms.length){catTag='<span class="cb-cat-tag">'+esc(terms[0].name)+'</span>';}
				var date='';
				if(p.date){var d=new Date(p.date);date=d.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});}
				var card=document.createElement('article');
				card.className='cb-card-sq';
				card.innerHTML=img+'<div class="cb-card-sq-body">'+catTag+'<h3 class="cb-card-sq-title"><a href="'+esc(p.link)+'">'+p.title.rendered+'</a></h3><div class="cb-card-meta">'+esc(date)+'</div></div>';
				grid.appendChild(card);
			}
			exclude=ids.join(',');
			btn.setAttribute('data-exclude',exclude);
			page++;
			btn.setAttribute('data-page',page);
			if(page>data.totalPages||posts.length<perPage){
				btn.style.display='none';
			}else{
				btn.disabled=false;
				btn.textContent=label;
			}
			loading=false;
		}).catch(function(){
			btn.style.display='none';
			loading=false;
		});
	});
	function esc(s){var d=document.createElement('div');d.appendChild(document.createTextNode(s));return d.innerHTML;}
})();
</script>

<?php
// footer.php expects to close </main>, so open one.
echo '<main id="primary" role="main">';
get_footer();
?>
