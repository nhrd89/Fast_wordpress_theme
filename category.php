<?php
/**
 * Category Archive — Pinterest-style waterfall grid
 * Shows engagement-weighted popular posts from the current category.
 *
 * @package PinLightning
 * @since 1.0.0
 */

get_header();

$cat       = get_queried_object();
$cat_name  = $cat->name ?? 'Category';
$cat_slug  = $cat->slug ?? '';
$cat_desc  = category_description( $cat->term_id ) ?: "Browse our collection of {$cat_name} articles";
$cat_count = $cat->count ?? 0;
$cat_id    = $cat->term_id ?? 0;

// Reuse theme's existing icon/color helpers.
$icon   = pl_get_cat_icon( $cat_slug );
$accent = pl_get_cat_color( $cat_slug );

// Sort mode.
$sort = isset( $_GET['sort'] ) ? sanitize_text_field( $_GET['sort'] ) : 'popular';

// Get engagement-weighted posts.
$posts_data = pl_get_category_posts( $cat_id, $sort, 18, 0 );
$cat_posts  = $posts_data['posts'];
$has_more   = $posts_data['has_more'];
?>

<style>
	/* --- Category Page --- */
	.pl-cat-page{background:#fafaf8;min-height:100vh;padding-bottom:60px}
	.pl-cat-wrap{max-width:1100px;margin:0 auto;padding:0 20px}

	/* Header */
	.pl-cat-header{display:flex;align-items:center;justify-content:space-between;padding:24px 0 20px;flex-wrap:wrap;gap:16px}
	.pl-cat-info{display:flex;align-items:center;gap:14px}
	.pl-cat-icon{width:52px;height:52px;border-radius:16px;background:<?php echo esc_attr( $accent ); ?>12;display:flex;align-items:center;justify-content:center;font-size:26px;flex-shrink:0}
	.pl-cat-name{font-size:26px;font-weight:800;color:#111;margin:0 0 2px;line-height:1.2}
	.pl-cat-meta{font-size:13px;color:#999;margin:0}

	/* Sort Tabs */
	.pl-cat-tabs{display:flex;gap:6px}
	.pl-cat-tab{padding:7px 16px;border-radius:10px;border:1px solid #eee;background:#fff;color:#666;font-size:13px;font-weight:600;text-decoration:none;transition:all .15s;font-family:inherit;cursor:pointer}
	.pl-cat-tab:hover{border-color:#ccc;color:#333;text-decoration:none}
	.pl-cat-tab.active{background:#111;color:#fff;border-color:#111}

	/* Waterfall Grid */
	.pl-cat-grid{columns:3 300px;column-gap:18px}

	/* Card */
	.pl-cat-card{break-inside:avoid;margin-bottom:18px;border-radius:16px;overflow:hidden;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.04);transition:transform .25s ease,box-shadow .25s ease;position:relative}
	.pl-cat-card:hover{transform:translateY(-3px);box-shadow:0 8px 24px rgba(0,0,0,.1)}
	.pl-cat-card:hover .pl-cat-pin{opacity:1}

	/* Card Image */
	.pl-cat-card-img{width:100%;display:block;aspect-ratio:auto}
	.pl-cat-card-img-wrap{position:relative;overflow:hidden;background:#f0f0f0}

	/* Pinterest Save Button */
	.pl-cat-pin{position:absolute;top:10px;right:10px;background:#e60023;color:#fff;border-radius:20px;padding:5px 12px;font-size:11px;font-weight:600;display:flex;align-items:center;gap:4px;opacity:0;transition:opacity .2s;text-decoration:none;z-index:2}
	.pl-cat-pin:hover{background:#ad081b;color:#fff;text-decoration:none}
	.pl-cat-pin svg{width:12px;height:12px}

	/* Save count badge */
	.pl-cat-saves{position:absolute;bottom:10px;left:10px;background:rgba(0,0,0,.5);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);color:#fff;padding:3px 10px;border-radius:8px;font-size:11px;font-weight:500}

	/* Card Body */
	.pl-cat-card-body{padding:14px 16px 16px}
	.pl-cat-card-body h3{font-size:15px;font-weight:700;margin:0 0 6px;line-height:1.4;color:#111}
	.pl-cat-card-body h3 a{color:inherit;text-decoration:none}
	.pl-cat-card-body h3 a:hover{color:<?php echo esc_attr( $accent ); ?>;text-decoration:none}
	.pl-cat-card-meta{font-size:12px;color:#aaa;display:flex;align-items:center;gap:10px}

	/* Load More */
	.pl-cat-loadmore{text-align:center;padding:32px 0}
	.pl-cat-loadmore button{padding:12px 36px;border-radius:12px;border:2px solid #eee;background:#fff;color:#555;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .15s}
	.pl-cat-loadmore button:hover{border-color:#ccc;color:#111}
	.pl-cat-loadmore button:disabled{opacity:.5;cursor:not-allowed}

	/* Empty State */
	.pl-cat-empty{text-align:center;padding:64px 20px;color:#aaa}
	.pl-cat-empty .icon{font-size:48px;margin-bottom:12px}
	.pl-cat-empty h2{font-size:20px;color:#666;margin:0 0 8px}

	/* Responsive */
	@media(max-width:900px){.pl-cat-grid{columns:2}.pl-cat-header{flex-direction:column;align-items:flex-start}}
	@media(max-width:580px){.pl-cat-grid{columns:1}.pl-cat-name{font-size:22px}.pl-cat-icon{width:44px;height:44px;font-size:22px;border-radius:12px}}
</style>

<div class="pl-cat-page">
	<div class="pl-cat-wrap">

		<!-- Header -->
		<div class="pl-cat-header">
			<div class="pl-cat-info">
				<div class="pl-cat-icon"><?php echo $icon; ?></div>
				<div>
					<h1 class="pl-cat-name"><?php echo esc_html( $cat_name ); ?></h1>
					<p class="pl-cat-meta"><?php echo number_format( $cat_count ); ?> articles</p>
				</div>
			</div>
			<div class="pl-cat-tabs">
				<?php
				$sorts = array( 'popular' => 'Popular', 'latest' => 'Latest', 'saved' => 'Most Saved' );
				foreach ( $sorts as $key => $label ) :
					$url = add_query_arg( 'sort', $key, get_category_link( $cat_id ) );
				?>
				<a href="<?php echo esc_url( $url ); ?>"
				   class="pl-cat-tab<?php echo $sort === $key ? ' active' : ''; ?>">
					<?php echo esc_html( $label ); ?>
				</a>
				<?php endforeach; ?>
			</div>
		</div>

		<!-- Post Grid -->
		<?php if ( ! empty( $cat_posts ) ) : ?>
		<div class="pl-cat-grid" id="plCatGrid">
			<?php foreach ( $cat_posts as $post ) :
				setup_postdata( $post );
				$pid       = $post->ID;
				$thumb_id  = get_post_thumbnail_id( $pid );
				$permalink = get_permalink( $pid );
				$title     = get_the_title( $pid );

				// Get image with srcset for responsive loading.
				$img_full   = '';
				$img_srcset = '';
				if ( $thumb_id ) {
					$img_data   = wp_get_attachment_image_src( $thumb_id, 'medium_large' );
					$img_full   = $img_data[0] ?? '';
					$img_srcset = wp_get_attachment_image_srcset( $thumb_id, 'medium_large' );
				}

				// Read time estimate.
				$content   = get_the_content( null, false, $pid );
				$words     = str_word_count( strip_tags( $content ) );
				$read_time = max( 1, round( $words / 200 ) );

				// Pinterest URL.
				$pin_url = 'https://www.pinterest.com/pin/create/button/?url=' . urlencode( $permalink ) . '&media=' . urlencode( $img_full ) . '&description=' . urlencode( $title );

				// Pin saves count.
				$pin_saves  = 0;
				$saved_pins = get_post_meta( $pid, '_pl_pin_saves', true );
				if ( $saved_pins ) {
					$pin_saves = intval( $saved_pins );
				}
			?>
			<article class="pl-cat-card">
				<?php if ( $img_full ) : ?>
				<div class="pl-cat-card-img-wrap">
					<a href="<?php echo esc_url( $permalink ); ?>">
						<img class="pl-cat-card-img"
						     src="<?php echo esc_url( $img_full ); ?>"
						     <?php if ( $img_srcset ) : ?>
						     srcset="<?php echo esc_attr( $img_srcset ); ?>"
						     sizes="(max-width: 580px) 100vw, (max-width: 900px) 50vw, 33vw"
						     <?php endif; ?>
						     alt="<?php echo esc_attr( $title ); ?>"
						     loading="lazy"
						     decoding="async" />
					</a>
					<!-- Pinterest Save -->
					<a href="<?php echo esc_url( $pin_url ); ?>"
					   class="pl-cat-pin" target="_blank" rel="noopener">
						<svg viewBox="0 0 24 24" fill="#fff"><path d="M12 0C5.373 0 0 5.373 0 12c0 5.084 3.163 9.426 7.627 11.174-.105-.949-.2-2.405.042-3.441.218-.937 1.407-5.965 1.407-5.965s-.359-.719-.359-1.782c0-1.668.967-2.914 2.171-2.914 1.023 0 1.518.769 1.518 1.69 0 1.029-.655 2.568-.994 3.995-.283 1.194.599 2.169 1.777 2.169 2.133 0 3.772-2.249 3.772-5.495 0-2.873-2.064-4.882-5.012-4.882-3.414 0-5.418 2.561-5.418 5.207 0 1.031.397 2.138.893 2.738a.36.36 0 01.083.345l-.333 1.36c-.053.22-.174.267-.402.161-1.499-.698-2.436-2.889-2.436-4.649 0-3.785 2.75-7.262 7.929-7.262 4.163 0 7.398 2.967 7.398 6.931 0 4.136-2.607 7.464-6.227 7.464-1.216 0-2.359-.632-2.75-1.378l-.748 2.853c-.271 1.043-1.002 2.35-1.492 3.146C9.57 23.812 10.763 24 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0z"/></svg>
						Save
					</a>
					<?php if ( $pin_saves > 0 ) : ?>
					<span class="pl-cat-saves"><?php echo number_format( $pin_saves ); ?> saves</span>
					<?php endif; ?>
				</div>
				<?php endif; ?>
				<div class="pl-cat-card-body">
					<h2><a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $title ); ?></a></h2>
					<div class="pl-cat-card-meta">
						<span><?php echo $read_time; ?> min read</span>
						<span><?php echo get_the_date( 'M j' ); ?></span>
					</div>
				</div>
			</article>
			<?php endforeach; wp_reset_postdata(); ?>
		</div>

		<!-- Load More -->
		<?php if ( $has_more ) : ?>
		<div class="pl-cat-loadmore">
			<button id="plCatLoadMore" data-cat="<?php echo $cat_id; ?>" data-sort="<?php echo esc_attr( $sort ); ?>" data-offset="18">
				Show More Picks
			</button>
		</div>
		<?php endif; ?>

		<?php else : ?>
		<div class="pl-cat-empty">
			<div class="icon"><?php echo $icon; ?></div>
			<h2>No posts yet</h2>
			<p>We're working on adding content to <?php echo esc_html( $cat_name ); ?>.</p>
		</div>
		<?php endif; ?>

	</div>
</div>

<!-- Load More JS (lightweight inline) -->
<script>
(function(){
	var btn=document.getElementById('plCatLoadMore');
	if(!btn)return;
	var grid=document.getElementById('plCatGrid');

	btn.addEventListener('click',function(){
		var cat=btn.dataset.cat;
		var sort=btn.dataset.sort;
		var offset=parseInt(btn.dataset.offset);

		btn.disabled=true;
		btn.textContent='Loading...';

		fetch(<?php echo wp_json_encode( esc_url_raw( rest_url( 'pl/v1/category-posts' ) ) ); ?>+'?cat='+cat+'&sort='+sort+'&offset='+offset)
		.then(function(r){return r.json();})
		.then(function(data){
			if(data.html){
				var temp=document.createElement('div');
				temp.innerHTML=data.html;
				while(temp.firstChild){
					grid.appendChild(temp.firstChild);
				}
			}
			btn.dataset.offset=offset+18;
			btn.disabled=false;
			btn.textContent='Show More Picks';
			if(!data.has_more){
				btn.parentElement.style.display='none';
			}
		})
		.catch(function(){
			btn.disabled=false;
			btn.textContent='Show More Picks';
		});
	});
})();
</script>

<?php get_footer(); ?>
