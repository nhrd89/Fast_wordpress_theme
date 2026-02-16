<?php
/**
 * Homepage Template
 *
 * Modern lifestyle homepage with bento hero, category filtering, and newsletter.
 * All sections controllable via Appearance > Customize > Homepage.
 *
 * @package PinLightning
 * @since 1.0.0
 */

get_header();

// --- Load all Customizer settings ---
$pl_hero_show          = get_theme_mod( 'pl_hero_show', true );
$pl_hero_count         = get_theme_mod( 'pl_hero_count', 5 );
$pl_hero_badge         = get_theme_mod( 'pl_hero_badge', "Editor's Pick" );
$pl_hero_category      = get_theme_mod( 'pl_hero_category', 0 );
$pl_liv_show           = get_theme_mod( 'pl_liv_show', true );
$pl_liv_name           = get_theme_mod( 'pl_liv_name', 'Liv' );
$pl_liv_message        = str_replace( '{name}', '<strong>' . esc_html( $pl_liv_name ) . '</strong>',
	get_theme_mod( 'pl_liv_message', "Hey! I'm {name} \xe2\x80\x94 your style & lifestyle companion. Tap any image while reading to chat with me about it!" )
);
$pl_liv_avatar         = get_theme_mod( 'pl_liv_avatar', "\xF0\x9F\x92\x83" );
$pl_liv_cta            = get_theme_mod( 'pl_liv_cta', "Try it \xe2\x86\x92" );
$pl_cats_show          = get_theme_mod( 'pl_cats_show', true );
$pl_cats_max           = get_theme_mod( 'pl_cats_max', 8 );
$pl_cats_counts        = get_theme_mod( 'pl_cats_counts', true );
$pl_grid_tabs          = get_theme_mod( 'pl_grid_tabs', true );
$pl_grid_count         = get_theme_mod( 'pl_grid_count', 9 );
$pl_grid_columns       = get_theme_mod( 'pl_grid_columns', 3 );
$pl_grid_readtime      = get_theme_mod( 'pl_grid_readtime', true );
$pl_grid_cat_badge     = get_theme_mod( 'pl_grid_cat_badge', true );
$pl_grid_img_height    = get_theme_mod( 'pl_grid_img_height', '170' );
$pl_grid_loadmore      = get_theme_mod( 'pl_grid_loadmore', true );
$pl_grid_loadmore_text = get_theme_mod( 'pl_grid_loadmore_text', 'Load More Inspiration' );
$pl_explore_show       = get_theme_mod( 'pl_explore_show', true );
$pl_explore_heading    = get_theme_mod( 'pl_explore_heading', 'Explore by Category' );
$pl_newsletter_show    = get_theme_mod( 'pl_newsletter_show', true );
$pl_newsletter_heading = get_theme_mod( 'pl_newsletter_heading', 'Get Inspired Weekly' );
$pl_newsletter_desc    = get_theme_mod( 'pl_newsletter_desc', "Curated style tips, home inspo, and beauty trends \xe2\x80\x94 delivered every Friday. No spam, just inspiration." );
$pl_newsletter_placeholder = get_theme_mod( 'pl_newsletter_placeholder', 'Your email address' );
$pl_newsletter_btn     = get_theme_mod( 'pl_newsletter_btn', 'Subscribe' );
$pl_newsletter_success = get_theme_mod( 'pl_newsletter_success', "You're in! Check your inbox for a welcome surprise." );
$pl_newsletter_note    = get_theme_mod( 'pl_newsletter_note', 'Join 2,400+ style enthusiasts. Unsubscribe anytime.' );
$pl_newsletter_bg      = get_theme_mod( 'pl_newsletter_bg', '#1a1a2e' );
$pl_accent             = get_theme_mod( 'pl_accent_color', '#e84393' );
$pl_accent2            = get_theme_mod( 'pl_accent_color_2', '#6c5ce7' );

// Get categories with post counts (exclude uncategorized).
$categories = get_categories( array(
	'orderby'    => 'count',
	'order'      => 'DESC',
	'hide_empty' => true,
	'exclude'    => array( get_cat_ID( 'uncategorized' ) ),
	'number'     => $pl_cats_max,
) );

// Featured posts for hero (with thumbnails).
$hero_posts = array();
$hero_ids   = array();
if ( $pl_hero_show ) {
	$hero_args = array(
		'posts_per_page' => $pl_hero_count,
		'post_status'    => 'publish',
		'meta_query'     => array( array( 'key' => '_thumbnail_id' ) ),
		'orderby'        => 'date',
		'order'          => 'DESC',
	);
	if ( $pl_hero_category > 0 ) {
		$hero_args['cat'] = $pl_hero_category;
	}
	$hero_query = new WP_Query( $hero_args );
	$hero_posts = $hero_query->posts;
	wp_reset_postdata();
	$hero_ids = wp_list_pluck( $hero_posts, 'ID' );
}

// Grid posts — smart selection: one per category, engagement-weighted.
$grid_posts = pl_get_smart_grid_posts( $pl_grid_count, $hero_ids );
?>

<div class="pl-home">

	<?php if ( $pl_hero_show && ! empty( $hero_posts ) ) : ?>
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
						<?php if ( $pl_hero_badge ) : ?>
						<span class="pl-bento-badge"><?php echo esc_html( $pl_hero_badge ); ?></span>
						<?php endif; ?>
						<h2 class="pl-bento-title"><?php echo esc_html( $main->post_title ); ?></h2>
						<span class="pl-bento-meta">
							<?php if ( $main_cat ) : echo esc_html( $main_cat->name ) . ' &middot; '; endif; ?>
							<?php echo esc_html( max( 1, ceil( str_word_count( wp_strip_all_tags( $main->post_content ) ) / 200 ) ) ); ?> min read
						</span>
					</div>
				</a>
				<?php endif; ?>

				<?php for ( $i = 1; $i < $pl_hero_count; $i++ ) :
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
	<?php endif; ?>

	<?php if ( $pl_liv_show ) : ?>
	<!-- ========== LIV WELCOME STRIP ========== -->
	<section class="pl-container">
		<div class="pl-liv-strip" style="border-color:<?php echo esc_attr( $pl_accent ); ?>22">
			<div class="pl-liv-avatar" style="background:linear-gradient(135deg,<?php echo esc_attr( $pl_accent ); ?>,<?php echo esc_attr( $pl_accent2 ); ?>)"><?php echo esc_html( $pl_liv_avatar ); ?></div>
			<p class="pl-liv-text">
				<?php echo wp_kses( $pl_liv_message, array( 'strong' => array() ) ); ?>
			</p>
			<?php if ( $pl_liv_cta ) : ?>
			<span class="pl-liv-cta"><?php echo esc_html( $pl_liv_cta ); ?></span>
			<?php endif; ?>
		</div>
	</section>
	<?php endif; ?>

	<?php if ( $pl_cats_show ) : ?>
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
				<?php if ( $pl_cats_counts ) : ?>
				<span class="pl-pill-count"><?php echo esc_html( $cat->count ); ?></span>
				<?php endif; ?>
			</a>
			<?php endforeach; ?>
		</div>
	</section>
	<?php endif; ?>

	<?php if ( $pl_grid_tabs ) : ?>
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
	<?php endif; ?>

	<!-- ========== POST GRID ========== -->
	<section class="pl-container">
		<div class="pl-post-grid" id="plPostGrid" style="grid-template-columns:repeat(<?php echo esc_attr( $pl_grid_columns ); ?>,1fr)">
			<?php foreach ( $grid_posts as $g_post ) :
				setup_postdata( $g_post );
				$g_cats    = get_the_category( $g_post->ID );
				$g_cat     = ! empty( $g_cats ) ? $g_cats[0] : null;
				$g_color   = $g_cat ? pl_get_cat_color( $g_cat->slug ) : '#888';
				$g_icon    = $g_cat ? pl_get_cat_icon( $g_cat->slug ) : "\xF0\x9F\x93\x8C";
				$read_time = max( 1, ceil( str_word_count( wp_strip_all_tags( $g_post->post_content ) ) / 200 ) );
			?>
			<article class="pl-card" data-cat="<?php echo esc_attr( $g_cat ? $g_cat->slug : '' ); ?>">
				<a href="<?php echo esc_url( get_permalink( $g_post->ID ) ); ?>" class="pl-card-link">
					<div class="pl-card-media" style="height:<?php echo esc_attr( $pl_grid_img_height ); ?>px">
						<?php if ( has_post_thumbnail( $g_post->ID ) ) :
							echo get_the_post_thumbnail( $g_post->ID, 'medium_large', array(
								'class'    => 'pl-card-img',
								'loading'  => 'lazy',
								'decoding' => 'async',
							) );
						endif; ?>
						<?php if ( $pl_grid_cat_badge ) : ?>
						<span class="pl-card-cat" style="color:<?php echo esc_attr( $g_color ); ?>">
							<?php echo esc_html( $g_cat ? $g_cat->name : '' ); ?>
						</span>
						<?php endif; ?>
					</div>
					<div class="pl-card-body">
						<h3 class="pl-card-title"><?php echo esc_html( $g_post->post_title ); ?></h3>
						<?php if ( $pl_grid_readtime ) : ?>
						<div class="pl-card-footer">
							<span class="pl-card-meta">
								<span class="pl-card-meta-icon" style="background:<?php echo esc_attr( $g_color ); ?>22"><?php echo $g_icon; ?></span>
								<?php echo esc_html( $read_time ); ?> min read
							</span>
						</div>
						<?php endif; ?>
					</div>
				</a>
			</article>
			<?php endforeach; wp_reset_postdata(); ?>
		</div>

		<?php if ( $pl_grid_loadmore ) : ?>
		<!-- Load More -->
		<div class="pl-loadmore-wrap">
			<button class="pl-loadmore" id="plLoadMore"
			        data-exclude="<?php echo esc_attr( implode( ',', array_merge( $hero_ids, wp_list_pluck( $grid_posts, 'ID' ) ) ) ); ?>">
				<?php echo esc_html( $pl_grid_loadmore_text ); ?>
			</button>
		</div>
		<?php endif; ?>
	</section>

	<?php if ( $pl_explore_show ) : ?>
	<!-- ========== EXPLORE BY CATEGORY ========== -->
	<section class="pl-container">
		<div class="pl-explore">
			<h2 class="pl-explore-heading"><?php echo esc_html( $pl_explore_heading ); ?></h2>
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
	<?php endif; ?>

	<?php if ( $pl_newsletter_show ) : ?>
	<!-- ========== NEWSLETTER ========== -->
	<section class="pl-newsletter" style="background:linear-gradient(135deg,<?php echo esc_attr( $pl_newsletter_bg ); ?> 0%,<?php echo esc_attr( $pl_newsletter_bg ); ?>ee 50%,<?php echo esc_attr( $pl_newsletter_bg ); ?> 100%)">
		<div class="pl-newsletter-inner">
			<div class="pl-newsletter-icon">&#x2709;&#xFE0F;</div>
			<h2 class="pl-newsletter-title"><?php echo esc_html( $pl_newsletter_heading ); ?></h2>
			<p class="pl-newsletter-desc"><?php echo esc_html( $pl_newsletter_desc ); ?></p>
			<form class="pl-newsletter-form" id="plNewsletterForm">
				<input type="email" class="pl-newsletter-input" placeholder="<?php echo esc_attr( $pl_newsletter_placeholder ); ?>" required />
				<button type="submit" class="pl-newsletter-btn" style="background:linear-gradient(135deg,<?php echo esc_attr( $pl_accent ); ?>,<?php echo esc_attr( $pl_accent2 ); ?>)"><?php echo esc_html( $pl_newsletter_btn ); ?></button>
			</form>
			<p class="pl-newsletter-note"><?php echo esc_html( $pl_newsletter_note ); ?></p>
		</div>
	</section>
	<?php endif; ?>

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
		var loadText=<?php echo wp_json_encode( $pl_grid_loadmore_text ); ?>;
		btn.addEventListener('click',function(){
			var exclude=this.dataset.exclude;
			this.textContent='Loading...';
			this.disabled=true;
			var xhr=new XMLHttpRequest();
			xhr.open('GET',<?php echo wp_json_encode( esc_url_raw( rest_url( 'pl/v1/home-posts' ) ) ); ?>+'?exclude='+exclude);
			xhr.onload=function(){
				if(xhr.status===200){
					var data=JSON.parse(xhr.responseText);
					if(data.html){
						document.getElementById('plPostGrid').insertAdjacentHTML('beforeend',data.html);
						cards=document.querySelectorAll('.pl-card');
						if(data.exclude){btn.dataset.exclude=data.exclude}
						btn.textContent=loadText;
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
			this.innerHTML='<div style="background:rgba(0,184,148,.15);border:1px solid rgba(0,184,148,.3);border-radius:14px;padding:14px 24px;font-size:14px;color:#00b894;font-weight:500">'+<?php echo wp_json_encode( $pl_newsletter_success ); ?>+'</div>';
		});
	}
})();
</script>

<?php get_footer(); ?>
