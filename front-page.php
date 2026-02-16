<?php
/**
 * Homepage Template
 *
 * Modern lifestyle homepage with bento hero, category filtering, and newsletter.
 *
 * @package PinLightning
 * @since 1.0.0
 */

get_header();

// Get categories with post counts (exclude uncategorized).
$categories = get_categories( array(
	'orderby'    => 'count',
	'order'      => 'DESC',
	'hide_empty' => true,
	'exclude'    => array( get_cat_ID( 'uncategorized' ) ),
	'number'     => 8,
) );

// Featured posts for hero (latest 5, with thumbnails).
$hero_query = new WP_Query( array(
	'posts_per_page' => 5,
	'post_status'    => 'publish',
	'meta_query'     => array( array( 'key' => '_thumbnail_id' ) ),
	'orderby'        => 'date',
	'order'          => 'DESC',
) );
$hero_posts = $hero_query->posts;
wp_reset_postdata();

// Grid posts (skip the hero ones).
$hero_ids = wp_list_pluck( $hero_posts, 'ID' );
$grid_query = new WP_Query( array(
	'posts_per_page' => 9,
	'post_status'    => 'publish',
	'post__not_in'   => $hero_ids,
	'orderby'        => 'date',
	'order'          => 'DESC',
) );
?>

<div class="pl-home">

	<!-- ========== HERO BENTO GRID ========== -->
	<section class="pl-hero">
		<div class="pl-container">
			<div class="pl-bento">
				<?php if ( ! empty( $hero_posts[0] ) ) :
					$main      = $hero_posts[0];
					$main_cats = get_the_category( $main->ID );
					$main_cat  = ! empty( $main_cats ) ? $main_cats[0] : null;
				?>
				<a href="<?php echo esc_url( get_permalink( $main->ID ) ); ?>" class="pl-bento-main">
					<?php echo get_the_post_thumbnail( $main->ID, 'large', array(
						'class'         => 'pl-bento-img',
						'loading'       => 'eager',
						'fetchpriority' => 'high',
						'decoding'      => 'async',
					) ); ?>
					<div class="pl-bento-overlay">
						<span class="pl-bento-badge">Editor's Pick</span>
						<h2 class="pl-bento-title"><?php echo esc_html( $main->post_title ); ?></h2>
						<span class="pl-bento-meta">
							<?php if ( $main_cat ) : echo esc_html( $main_cat->name ) . ' &middot; '; endif; ?>
							<?php echo esc_html( max( 1, ceil( str_word_count( wp_strip_all_tags( $main->post_content ) ) / 200 ) ) ); ?> min read
						</span>
					</div>
				</a>
				<?php endif; ?>

				<?php for ( $i = 1; $i <= 4; $i++ ) :
					if ( empty( $hero_posts[ $i ] ) ) continue;
					$p       = $hero_posts[ $i ];
					$p_cats  = get_the_category( $p->ID );
					$p_cat   = ! empty( $p_cats ) ? $p_cats[0] : null;
					$p_color = $p_cat ? pl_get_cat_color( $p_cat->slug ) : '#888';
				?>
				<a href="<?php echo esc_url( get_permalink( $p->ID ) ); ?>" class="pl-bento-sm">
					<?php echo get_the_post_thumbnail( $p->ID, 'medium_large', array(
						'class'    => 'pl-bento-img',
						'loading'  => $i <= 2 ? 'eager' : 'lazy',
						'decoding' => 'async',
					) ); ?>
					<div class="pl-bento-overlay pl-bento-overlay-sm">
						<span class="pl-bento-cat" style="background:<?php echo esc_attr( $p_color ); ?>cc">
							<?php echo esc_html( $p_cat ? $p_cat->name : '' ); ?>
						</span>
						<h3 class="pl-bento-title-sm"><?php echo esc_html( $p->post_title ); ?></h3>
					</div>
				</a>
				<?php endfor; ?>
			</div>
		</div>
	</section>

	<!-- ========== LIV WELCOME STRIP ========== -->
	<section class="pl-container">
		<div class="pl-liv-strip">
			<div class="pl-liv-avatar">&#x1F483;</div>
			<p class="pl-liv-text">
				Hey! I'm <strong>Liv</strong> — your style &amp; lifestyle companion.
				Tap any image while reading to chat with me about it!
			</p>
			<span class="pl-liv-cta">Try it &rarr;</span>
		</div>
	</section>

	<!-- ========== CATEGORY PILLS ========== -->
	<section class="pl-container">
		<div class="pl-cat-pills" id="plCatPills">
			<?php foreach ( $categories as $cat ) :
				$icon  = pl_get_cat_icon( $cat->slug );
				$color = pl_get_cat_color( $cat->slug );
			?>
			<a href="<?php echo esc_url( get_category_link( $cat->term_id ) ); ?>"
			   class="pl-cat-pill"
			   data-cat="<?php echo esc_attr( $cat->slug ); ?>"
			   style="--pill-color:<?php echo esc_attr( $color ); ?>">
				<span class="pl-pill-icon"><?php echo $icon; ?></span>
				<span class="pl-pill-name"><?php echo esc_html( $cat->name ); ?></span>
				<span class="pl-pill-count"><?php echo esc_html( $cat->count ); ?></span>
			</a>
			<?php endforeach; ?>
		</div>
	</section>

	<!-- ========== TAB FILTERS ========== -->
	<section class="pl-container">
		<div class="pl-tabs" id="plTabs">
			<button class="pl-tab active" data-cat="all">All</button>
			<?php foreach ( array_slice( $categories, 0, 6 ) as $cat ) : ?>
			<button class="pl-tab" data-cat="<?php echo esc_attr( $cat->slug ); ?>">
				<?php echo esc_html( $cat->name ); ?>
			</button>
			<?php endforeach; ?>
		</div>
	</section>

	<!-- ========== POST GRID ========== -->
	<section class="pl-container">
		<div class="pl-post-grid" id="plPostGrid">
			<?php if ( $grid_query->have_posts() ) : while ( $grid_query->have_posts() ) : $grid_query->the_post();
				$g_cats    = get_the_category();
				$g_cat     = ! empty( $g_cats ) ? $g_cats[0] : null;
				$g_color   = $g_cat ? pl_get_cat_color( $g_cat->slug ) : '#888';
				$g_icon    = $g_cat ? pl_get_cat_icon( $g_cat->slug ) : "\xF0\x9F\x93\x8C";
				$read_time = max( 1, ceil( str_word_count( wp_strip_all_tags( get_the_content() ) ) / 200 ) );
			?>
			<article class="pl-card" data-cat="<?php echo esc_attr( $g_cat ? $g_cat->slug : '' ); ?>">
				<a href="<?php the_permalink(); ?>" class="pl-card-link">
					<div class="pl-card-media">
						<?php if ( has_post_thumbnail() ) :
							the_post_thumbnail( 'medium_large', array(
								'class'    => 'pl-card-img',
								'loading'  => 'lazy',
								'decoding' => 'async',
							) );
						endif; ?>
						<span class="pl-card-cat" style="color:<?php echo esc_attr( $g_color ); ?>">
							<?php echo esc_html( $g_cat ? $g_cat->name : '' ); ?>
						</span>
					</div>
					<div class="pl-card-body">
						<h3 class="pl-card-title"><?php the_title(); ?></h3>
						<div class="pl-card-footer">
							<span class="pl-card-meta">
								<span class="pl-card-meta-icon" style="background:<?php echo esc_attr( $g_color ); ?>22"><?php echo $g_icon; ?></span>
								<?php echo esc_html( $read_time ); ?> min read
							</span>
						</div>
					</div>
				</a>
			</article>
			<?php endwhile; endif; wp_reset_postdata(); ?>
		</div>

		<!-- Load More -->
		<div class="pl-loadmore-wrap">
			<button class="pl-loadmore" id="plLoadMore" data-page="2"
			        data-exclude="<?php echo esc_attr( implode( ',', $hero_ids ) ); ?>">
				Load More Inspiration
			</button>
		</div>
	</section>

	<!-- ========== EXPLORE BY CATEGORY ========== -->
	<section class="pl-container">
		<div class="pl-explore">
			<h2 class="pl-explore-heading">Explore by Category</h2>
			<div class="pl-explore-grid">
				<?php foreach ( $categories as $cat ) :
					$icon  = pl_get_cat_icon( $cat->slug );
					$color = pl_get_cat_color( $cat->slug );
				?>
				<a href="<?php echo esc_url( get_category_link( $cat->term_id ) ); ?>" class="pl-explore-card"
				   style="--card-color:<?php echo esc_attr( $color ); ?>">
					<span class="pl-explore-icon"><?php echo $icon; ?></span>
					<span class="pl-explore-name"><?php echo esc_html( $cat->name ); ?></span>
					<span class="pl-explore-count"><?php echo esc_html( $cat->count ); ?> posts</span>
				</a>
				<?php endforeach; ?>
			</div>
		</div>
	</section>

	<!-- ========== NEWSLETTER ========== -->
	<section class="pl-newsletter">
		<div class="pl-newsletter-inner">
			<div class="pl-newsletter-icon">&#x2709;&#xFE0F;</div>
			<h2 class="pl-newsletter-title">Get Inspired Weekly</h2>
			<p class="pl-newsletter-desc">
				Curated style tips, home inspo, and beauty trends — delivered every Friday. No spam, just inspiration.
			</p>
			<form class="pl-newsletter-form" id="plNewsletterForm">
				<input type="email" class="pl-newsletter-input" placeholder="Your email address" required />
				<button type="submit" class="pl-newsletter-btn">Subscribe</button>
			</form>
			<p class="pl-newsletter-note">Join 2,400+ style enthusiasts. Unsubscribe anytime.</p>
		</div>
	</section>

</div><!-- .pl-home -->

<script>
(function(){
	var tabs=document.querySelectorAll('.pl-tab');
	var cards=document.querySelectorAll('.pl-card');
	tabs.forEach(function(tab){
		tab.addEventListener('click',function(){
			tabs.forEach(function(t){t.classList.remove('active')});
			this.classList.add('active');
			var cat=this.dataset.cat;
			cards.forEach(function(card){
				card.style.display=(cat==='all'||card.dataset.cat===cat)?'':'none';
			});
		});
	});
	var btn=document.getElementById('plLoadMore');
	if(btn){
		btn.addEventListener('click',function(){
			var page=parseInt(this.dataset.page);
			var exclude=this.dataset.exclude;
			this.textContent='Loading...';
			this.disabled=true;
			var xhr=new XMLHttpRequest();
			xhr.open('GET',<?php echo wp_json_encode( esc_url_raw( rest_url( 'pl/v1/home-posts' ) ) ); ?>+'?page='+page+'&exclude='+exclude);
			xhr.onload=function(){
				if(xhr.status===200){
					var data=JSON.parse(xhr.responseText);
					if(data.html){
						document.getElementById('plPostGrid').insertAdjacentHTML('beforeend',data.html);
						cards=document.querySelectorAll('.pl-card');
						btn.dataset.page=page+1;
						btn.textContent='Load More Inspiration';
						btn.disabled=false;
						if(!data.has_more){btn.parentElement.style.display='none'}
					}
				}
			};
			xhr.send();
		});
	}
	var form=document.getElementById('plNewsletterForm');
	if(form){
		form.addEventListener('submit',function(e){
			e.preventDefault();
			var input=this.querySelector('input[type="email"]');
			if(!input.value)return;
			this.innerHTML='<div style="background:rgba(0,184,148,.15);border:1px solid rgba(0,184,148,.3);border-radius:14px;padding:14px 24px;font-size:14px;color:#00b894;font-weight:500">You\'re in! Check your inbox for a welcome surprise.</div>';
		});
	}
})();
</script>

<?php get_footer(); ?>
