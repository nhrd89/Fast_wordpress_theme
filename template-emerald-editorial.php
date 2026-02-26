<?php
/**
 * Template: Emerald Editorial Homepage
 *
 * Design A — loads on inspireinlet.com (or when Customizer override is set to 'emerald').
 * Uses own header markup, shared footer via get_footer() (emerald colors via CSS overrides).
 * Fires wp_head/wp_footer for scripts/styles.
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

// ── Shared category list (used by both Trending + Latest) ──
$ee_cats = get_categories( array(
	'orderby'    => 'count',
	'order'      => 'DESC',
	'number'     => 20,
	'hide_empty' => true,
) );

// ── Trending posts (4, one per category for diversity) ──
$trending_posts = array();
foreach ( $ee_cats as $cat ) {
	if ( count( $trending_posts ) >= 4 ) {
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
	}
	wp_reset_postdata();
}
// Backfill if fewer than 4 categories had posts.
if ( count( $trending_posts ) < 4 ) {
	$backfill = new WP_Query( array(
		'posts_per_page'         => 4 - count( $trending_posts ),
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

// ── Latest posts (6, max 2 per category — round-robin across categories) ──
$latest_posts    = array();
$latest_cat_used = array();
// Pass 1: one post per category.
foreach ( $ee_cats as $cat ) {
	if ( count( $latest_posts ) >= 6 ) {
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
// Pass 2: second post per category if still need more.
if ( count( $latest_posts ) < 6 ) {
	foreach ( $ee_cats as $cat ) {
		if ( count( $latest_posts ) >= 6 ) {
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
// Final backfill: any remaining posts.
if ( count( $latest_posts ) < 6 ) {
	$backfill = new WP_Query( array(
		'posts_per_page'         => 6 - count( $latest_posts ),
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

// ── Category circles ──
$cat_circles = pl_get_category_circles( 8 );

// ── Site info ──
$brand_name = get_theme_mod( 'pl_brand_name', get_bloginfo( 'name' ) );

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
		<?php foreach ( $trending_posts as $t_idx => $t_post ) :
			setup_postdata( $t_post );
			$t_cats = get_the_category( $t_post->ID );
		?>
		<div class="ee-trending-item">
			<span class="ee-trending-num"><?php echo str_pad( $t_idx + 1, 2, '0', STR_PAD_LEFT ); ?></span>
			<?php if ( has_post_thumbnail( $t_post ) ) : ?>
				<img class="ee-trending-thumb" src="<?php echo esc_url( get_the_post_thumbnail_url( $t_post, 'thumbnail' ) ); ?>"
					 alt="<?php echo esc_attr( get_the_title( $t_post ) ); ?>"
					 width="80" height="80" loading="lazy">
			<?php endif; ?>
			<div class="ee-trending-text">
				<?php if ( ! empty( $t_cats ) ) : ?>
					<div class="ee-cat-tag"><?php echo esc_html( $t_cats[0]->name ); ?></div>
				<?php endif; ?>
				<h4><a href="<?php echo esc_url( get_permalink( $t_post ) ); ?>"><?php echo esc_html( get_the_title( $t_post ) ); ?></a></h4>
			</div>
		</div>
		<?php endforeach; wp_reset_postdata(); ?>
	</aside>
</section>

<!-- Page Ad Anchor: between hero and categories -->
<div class="pl-page-ad-anchor" data-slot="homepage-1" data-format="leaderboard"></div>

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

<!-- Page Ad Anchor: between categories and latest -->
<div class="pl-page-ad-anchor" data-slot="homepage-2" data-format="rectangle"></div>

<!-- ====== SECTION 4: LATEST POSTS ====== -->
<section class="ee-latest">
	<h2 class="ee-section-title">Latest Stories</h2>
	<div class="ee-grid-3">
		<?php foreach ( $latest_posts as $l_post ) :
			setup_postdata( $l_post );
			$l_cats = get_the_category( $l_post->ID );
		?>
		<article class="ee-card">
			<?php if ( has_post_thumbnail( $l_post ) ) : ?>
				<a href="<?php echo esc_url( get_permalink( $l_post ) ); ?>">
					<?php echo get_the_post_thumbnail( $l_post, 'medium_large', array(
						'loading' => 'lazy',
						'decoding' => 'async',
					) ); ?>
				</a>
			<?php endif; ?>
			<div class="ee-card-body">
				<?php if ( ! empty( $l_cats ) ) : ?>
					<div class="ee-cat-tag"><?php echo esc_html( $l_cats[0]->name ); ?></div>
				<?php endif; ?>
				<h3 class="ee-card-title"><a href="<?php echo esc_url( get_permalink( $l_post ) ); ?>"><?php echo esc_html( get_the_title( $l_post ) ); ?></a></h3>
				<p class="ee-card-excerpt"><?php echo esc_html( wp_trim_words( $l_post->post_excerpt ?: wp_strip_all_tags( $l_post->post_content ), 18 ) ); ?></p>
				<div class="ee-card-meta">
					<?php echo esc_html( get_the_date( 'M j, Y', $l_post ) ); ?>
				</div>
			</div>
		</article>
		<?php endforeach; wp_reset_postdata(); ?>
	</div>
	<div class="ee-load-more-wrap">
		<button class="ee-load-more" id="eeLoadMore"
			data-exclude="<?php echo esc_attr( implode( ',', $shown_ids ) ); ?>"
			data-page="2"
			data-rest="<?php echo esc_url( rest_url( 'wp/v2/posts' ) ); ?>">
			Load More Stories
		</button>
	</div>
</section>

<!-- Page Ad Anchor: between latest and newsletter -->
<div class="pl-page-ad-anchor" data-slot="homepage-3" data-format="leaderboard"></div>

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

<!-- Load More inline JS (no jQuery) -->
<script>
(function(){
	var btn=document.getElementById('eeLoadMore');
	if(!btn)return;
	var grid=document.querySelector('.ee-grid-3');
	var rest=btn.getAttribute('data-rest');
	var exclude=btn.getAttribute('data-exclude');
	var page=parseInt(btn.getAttribute('data-page'),10);
	var perPage=6;
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
					img='<a href="'+esc(p.link)+'"><img src="'+esc(media.source_url)+'" alt="'+esc(p.title.rendered)+'" loading="lazy" decoding="async" style="width:100%;aspect-ratio:4/3;object-fit:cover;display:block"></a>';
				}
				var catTag='';
				var terms=p._embedded&&p._embedded['wp:term']&&p._embedded['wp:term'][0];
				if(terms&&terms.length){catTag='<div class="ee-cat-tag">'+esc(terms[0].name)+'</div>';}
				var excerpt=p.excerpt&&p.excerpt.rendered?p.excerpt.rendered.replace(/<[^>]+>/g,''):'';
				if(excerpt.length>120)excerpt=excerpt.substring(0,120)+'...';
				var date='';
				if(p.date){var d=new Date(p.date);date=d.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});}
				var card=document.createElement('article');
				card.className='ee-card';
				card.innerHTML=img+'<div class="ee-card-body">'+catTag+'<h3 class="ee-card-title"><a href="'+esc(p.link)+'">'+p.title.rendered+'</a></h3><p class="ee-card-excerpt">'+esc(excerpt)+'</p><div class="ee-card-meta">'+esc(date)+'</div></div>';
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
