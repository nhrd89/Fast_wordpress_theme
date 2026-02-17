<?php
/**
 * PinLightning Engagement Breaks — Content Filter
 *
 * Hooks into the_content to auto-inject engagement architecture
 * into listicle posts. Only activates on single posts with
 * numbered H2 headings.
 *
 * @package PinLightning
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main content filter.
 *
 * Runs AFTER the Pinterest save button filter (priority 90) so that
 * .pl-pin-wrap containers already exist for overlay injection.
 */
function pl_engagement_filter( $content ) {
	// Only on single posts, not admin, not REST API, not feed.
	if ( ! is_single() || is_admin() || wp_doing_ajax() || is_feed() ) {
		return $content;
	}

	// Check if post has listicle structure (numbered H2s).
	if ( ! preg_match( '/<h2[^>]*>.*?#\d+/i', $content ) ) {
		return $content;
	}

	// Get category.
	$categories = get_the_category();
	$cat_slug   = ! empty( $categories ) ? $categories[0]->slug : 'hairstyle';

	// Load config.
	$config = pl_get_engagement_config( $cat_slug );

	// Split content at H2 boundaries.
	$parts = preg_split( '/(?=<h2[^>]*>.*?#\d+)/i', $content, -1, PREG_SPLIT_NO_EMPTY );

	if ( count( $parts ) < 3 ) {
		return $content;
	}

	// Count total items.
	preg_match_all( '/<h2[^>]*>.*?#(\d+)/i', $content, $h2_matches );
	$total_items = count( $h2_matches[0] );

	if ( $total_items < 5 ) {
		return $content;
	}

	// Generate seeded random indices for trending + collectibles.
	$post_id   = get_the_ID();
	$date_seed = gmdate( 'Ymd' );
	$seed      = crc32( $post_id . $date_seed );
	mt_srand( $seed );

	// Trending: pick N random items.
	$trending_count   = $config['trending_count'] ?? 3;
	$available        = range( 1, $total_items );
	shuffle( $available );
	$trending_indices = array_slice( $available, 0, $trending_count );

	// Collectibles: pick N random items (different from trending).
	$collectible_count  = $config['collectible_count'] ?? 5;
	$remaining          = array_diff( range( 1, $total_items ), $trending_indices );
	shuffle( $remaining );
	$collectible_indices = array_slice( array_values( $remaining ), 0, $collectible_count );
	$collectible_emojis  = $config['collectible_emojis'] ?? array( "\xF0\x9F\x92\x8E", "\u2728", "\xF0\x9F\xA6\x8B", "\xF0\x9F\x92\xAB", "\xF0\x9F\x8C\xB8" );

	// Blur gate item.
	$blur_item = $config['blur_item'] ?? 0;

	// Save count generation (seeded, weighted: earlier items get higher counts).
	$save_counts = array();
	mt_srand( $seed + 1 );
	for ( $i = 1; $i <= $total_items; $i++ ) {
		$weight        = 1 - ( ( $i - 1 ) / $total_items ) * 0.6;
		$save_counts[ $i ] = (int) round( mt_rand( 800, 4800 ) * $weight );
	}

	// Build break map: position => HTML.
	$break_map = array();
	foreach ( $config['breaks'] as $break_conf ) {
		$pos = $break_conf['position'];
		if ( $pos <= $total_items ) {
			$break_map[ $pos ] = pl_eb_render_break( $break_conf, $config, $post_id, $total_items );
		}
	}

	// Process each part (item).
	$item_index   = 0;
	$output_parts = array();

	// TOC teaser images.
	$toc_images = array();
	$toc_count  = $config['toc_preview_count'] ?? 5;

	foreach ( $parts as $part ) {
		// Check if this part starts with an H2 (is a listicle item).
		if ( preg_match( '/<h2[^>]*>.*?#(\d+)/i', $part ) ) {
			$item_index++;

			// Collect TOC images from early items.
			if ( count( $toc_images ) < $toc_count ) {
				if ( preg_match( '/src=["\']([^"\']+)["\']/', $part, $src_match ) ) {
					$toc_images[] = $src_match[1];
				}
			}

			// Wrap item with engagement overlays.
			$part = pl_eb_wrap_item( $part, $item_index, array(
				'trending'    => in_array( $item_index, $trending_indices, true ),
				'collectible' => in_array( $item_index, $collectible_indices, true )
					? $collectible_emojis[ array_search( $item_index, $collectible_indices ) % count( $collectible_emojis ) ]
					: false,
				'save_count'  => $save_counts[ $item_index ] ?? 0,
				'blur'        => ( $item_index === $blur_item ),
			) );

			$output_parts[] = $part;

			// Inject engagement break AFTER this item if configured.
			if ( isset( $break_map[ $item_index ] ) ) {
				$output_parts[] = $break_map[ $item_index ];
			}
		} else {
			// Intro or non-item content — pass through.
			$output_parts[] = $part;
		}
	}

	$content = implode( "\n", $output_parts );

	// Prepend: TOC teaser.
	$toc_html = pl_eb_render_toc_teaser( $toc_images );
	if ( $toc_html ) {
		$content = preg_replace(
			'/(?=<div class="eb-item"[^>]*data-item="1")/i',
			$toc_html,
			$content,
			1
		);
	}

	// Append: AI tip placeholder + favorites summary.
	$content .= pl_eb_render_ai_tip();
	$content .= pl_eb_render_fav_summary();

	return $content;
}
add_filter( 'the_content', 'pl_engagement_filter', 95 );


/**
 * Wrap a listicle item with engagement overlays.
 */
function pl_eb_wrap_item( $html, $index, $options ) {
	$trending    = $options['trending'];
	$collectible = $options['collectible'];
	$save_count  = $options['save_count'];
	$blur        = $options['blur'];

	$formatted_count = $save_count >= 1000
		? round( $save_count / 1000, 1 ) . 'K'
		: (string) $save_count;

	// Build overlay HTML to inject inside .pl-pin-wrap.
	$overlays = '';

	if ( $trending && get_theme_mod( 'eb_trending', true ) ) {
		$overlays .= '<span class="eb-trending-badge">' . "\xF0\x9F\x94\xA5" . ' Trending</span>';
	}

	if ( get_theme_mod( 'eb_save_counts', true ) ) {
		$overlays .= '<span class="eb-save-count">' . "\xF0\x9F\x93\x8C" . ' ' . esc_html( $formatted_count ) . ' saves</span>';
	}

	if ( get_theme_mod( 'eb_favorites', true ) ) {
		$overlays .= '<button class="eb-fav-heart" data-eb-action="fav" data-idx="' . ( $index - 1 ) . '" aria-label="Save to favorites">' . "\xE2\x99\xA1" . '</button>';
	}

	if ( $collectible && get_theme_mod( 'eb_collectibles', true ) ) {
		$overlays .= '<span class="eb-collectible" data-eb-action="collect" data-emoji="' . esc_attr( $collectible ) . '">' . $collectible . '</span>';
	}

	if ( $blur && get_theme_mod( 'eb_blur_reveal', true ) ) {
		$overlays .= '<div class="eb-reveal-overlay" data-eb-action="reveal"><span>' . "\u2728" . ' Tap to reveal this look</span></div>';
	}

	// Inject overlays inside .pl-pin-wrap (before the <img>).
	if ( $overlays && strpos( $html, 'pl-pin-wrap' ) !== false ) {
		$html = preg_replace(
			'/(<div class="pl-pin-wrap[^"]*">)/',
			'$1' . $overlays,
			$html,
			1
		);
	}

	// Add blur class if needed.
	if ( $blur && get_theme_mod( 'eb_blur_reveal', true ) ) {
		$html = preg_replace(
			'/class="pl-pin-wrap([^"]*)"/i',
			'class="pl-pin-wrap$1 eb-reveal-blur"',
			$html,
			1
		);
	}

	// Add .eb-img-wrap to pin-wrap.
	$html = preg_replace(
		'/class="pl-pin-wrap([^"]*)"/i',
		'class="pl-pin-wrap$1 eb-img-wrap"',
		$html,
		1
	);

	// Add .eb-heading to the H2.
	if ( preg_match( '/<h2([^>]*)class="([^"]*)"/i', $html ) ) {
		$html = preg_replace(
			'/<h2([^>]*)class="([^"]*)"/i',
			'<h2$1class="$2 eb-heading"',
			$html,
			1
		);
	} else {
		$html = preg_replace(
			'/<h2(?![^>]*class=)([^>]*)>/i',
			'<h2 class="eb-heading"$1>',
			$html,
			1
		);
	}

	// Wrap in .eb-item div.
	$html = '<div class="eb-item" data-item="' . $index . '" id="item-' . $index . '">'
		. $html
		. '</div>';

	return $html;
}


/**
 * Render an engagement break.
 */
function pl_eb_render_break( $break, $config, $post_id, $total_items ) {
	$type   = $break['type'];
	$output = '';

	switch ( $type ) {
		case 'poll':
			if ( ! get_theme_mod( 'eb_polls', true ) ) {
				break;
			}
			$options_html = '';
			foreach ( $config['poll_options'] as $opt ) {
				$options_html .= sprintf(
					'<button class="eb-poll-opt" data-eb-action="vote" data-vote="%s">'
					. '<span class="eb-poll-emoji">%s</span>%s'
					. '<span class="eb-poll-bar"><span class="eb-poll-fill" data-pct="%d"></span></span>'
					. '</button>',
					esc_attr( sanitize_title( $opt['label'] ) ),
					$opt['emoji'],
					esc_html( $opt['label'] ),
					$opt['pct']
				);
			}
			$question = $config['poll_question'] ?? 'Which style is calling your name?';
			$output   = '<div class="eb-break eb-poll" data-eb="poll">'
				. '<div class="eb-break-badge">Quick Poll</div>'
				. '<h3 class="eb-break-title">' . esc_html( $question ) . '</h3>'
				. '<div class="eb-poll-options">' . $options_html . '</div>'
				. '<div class="eb-poll-result" style="display:none"><span class="eb-poll-total">2,847 people voted</span></div>'
				. '</div>';
			break;

		case 'quiz':
			if ( ! get_theme_mod( 'eb_quizzes', true ) ) {
				break;
			}
			$quiz_html = '';
			foreach ( $config['quiz_styles'] ?? array() as $style ) {
				$quiz_html .= sprintf(
					'<button class="eb-quiz-opt" data-eb-action="quiz" data-style="%s" data-result="%s">'
					. '<span>%s</span></button>',
					esc_attr( $style['slug'] ),
					esc_attr( $style['result'] ),
					esc_html( $style['label'] )
				);
			}
			$quiz_question = $config['quiz_question'] ?? 'What\'s Your Style Personality?';
			$output        = '<div class="eb-break eb-quiz" data-eb="quiz">'
				. '<div class="eb-break-badge">' . "\u2728" . ' Style Quiz</div>'
				. '<h3 class="eb-break-title">' . esc_html( $quiz_question ) . '</h3>'
				. '<p class="eb-quiz-sub">Tap the look that speaks to you most:</p>'
				. '<div class="eb-quiz-grid">' . $quiz_html . '</div>'
				. '<div class="eb-quiz-result" style="display:none"></div>'
				. '</div>';
			break;

		case 'fact':
			$output = '<div class="eb-break eb-fact" data-eb="fact">'
				. '<div class="eb-fact-icon">' . "\xF0\x9F\x92\xA1" . '</div>'
				. '<p class="eb-fact-text">' . esc_html( $break['text'] ) . '</p>'
				. '</div>';
			break;

		case 'curiosity':
			if ( ! get_theme_mod( 'eb_curiosity', true ) ) {
				break;
			}
			$text = $break['text'];
			$next = $break['position'] + 1;
			$text = str_replace( '{next}', (string) $next, $text );
			mt_srand( crc32( $post_id . $break['position'] ) );
			$text   = str_replace( '{saves}', number_format( mt_rand( 3000, 5000 ) / 1000, 1 ) . 'K', $text );
			$output = '<div class="eb-curiosity"><span class="eb-curiosity-icon">' . "\xF0\x9F\x91\x80" . '</span> '
				. esc_html( $text ) . '</div>';
			break;

		case 'email':
			if ( ! get_theme_mod( 'eb_email_capture', true ) ) {
				break;
			}
			$hook   = $config['email_hook'] ?? array();
			$output = '<div class="eb-break eb-email" data-eb="email">'
				. '<div class="eb-email-inner">'
				. '<div class="eb-email-icon">' . "\xF0\x9F\x92\x8C" . '</div>'
				. '<h3 class="eb-email-title">' . esc_html( $hook['title'] ?? 'Get Exclusive Bonus Content' ) . '</h3>'
				. '<p class="eb-email-desc">' . esc_html( $hook['desc'] ?? '' ) . '</p>'
				. '<div class="eb-email-form">'
				. '<input type="email" class="eb-email-input" placeholder="Your best email..." aria-label="Email">'
				. '<button class="eb-email-btn" data-eb-action="email">Send Me the Looks ' . "\xE2\x86\x92" . '</button>'
				. '</div>'
				. '<span class="eb-email-note">' . esc_html( $hook['note'] ?? '' ) . '</span>'
				. '</div></div>';
			break;

		case 'related':
			$related = pl_eb_get_related_post( $post_id );
			if ( $related ) {
				$output = '<div class="eb-break eb-teaser" data-eb="teaser">'
					. '<div class="eb-teaser-badge">You\'ll Also Love</div>'
					. '<a class="eb-teaser-card" href="' . esc_url( $related['url'] ) . '">'
					. ( $related['img'] ? '<img class="eb-teaser-img" src="' . esc_url( $related['img'] ) . '" alt="" loading="lazy" width="80" height="80">' : '' )
					. '<div class="eb-teaser-body">'
					. '<span class="eb-teaser-title">' . esc_html( $related['title'] ) . '</span>'
					. '<span class="eb-teaser-meta">67% of readers who liked this also read this ' . "\xE2\x86\x92" . '</span>'
					. '</div></a></div>';
			}
			break;

		case 'stylist_tip':
			$output = '<div class="eb-break eb-stylist" data-eb="stylist-tip">'
				. '<div class="eb-stylist-icon">' . "\xF0\x9F\x92\x87\xE2\x80\x8D\xE2\x99\x80\xEF\xB8\x8F" . '</div>'
				. '<div class="eb-stylist-body">'
				. '<span class="eb-stylist-badge">Stylist Tip</span>'
				. '<p class="eb-stylist-text">' . esc_html( $break['text'] ) . '</p>'
				. '</div></div>';
			break;
	}

	return $output;
}


/**
 * TOC Teaser.
 */
function pl_eb_render_toc_teaser( $images ) {
	if ( empty( $images ) ) {
		return '';
	}
	$html = '<div class="eb-toc"><span class="eb-toc-label">Preview what\'s ahead ' . "\xE2\x86\x92" . '</span>';
	foreach ( $images as $i => $url ) {
		$html .= '<img src="' . esc_url( $url ) . '" alt="Preview ' . ( $i + 1 ) . '" loading="lazy" class="eb-toc-img" data-eb-action="jump" data-jump="' . ( $i + 1 ) . '" width="48" height="48">';
	}
	$html .= '</div>';
	return $html;
}


/**
 * AI Tip placeholder (unlocked by JS based on engagement signals).
 */
function pl_eb_render_ai_tip() {
	if ( ! get_theme_mod( 'eb_ai_tip', true ) ) {
		return '';
	}
	return '<div class="eb-ai-tip" id="ebAiTip">'
		. '<div class="eb-ai-tip-badge">' . "\xF0\x9F\xA4\x96" . ' Personalized Tip — Unlocked!</div>'
		. '<p class="eb-ai-tip-text" id="ebAiTipText"></p>'
		. '</div>';
}


/**
 * Favorites summary grid.
 */
function pl_eb_render_fav_summary() {
	if ( ! get_theme_mod( 'eb_favorites', true ) ) {
		return '';
	}
	return '<div class="eb-fav-summary" id="ebFavSummary">'
		. '<h3>' . "\xF0\x9F\x92\x95" . ' Your Favorites Collection</h3>'
		. '<div class="eb-fav-grid" id="ebFavGrid"></div>'
		. '<button class="eb-fav-pin-all" data-eb-action="pin-all">' . "\xF0\x9F\x93\x8C" . ' Save All to Pinterest</button>'
		. '</div>';
}


/**
 * Get a related post from same category.
 */
function pl_eb_get_related_post( $post_id ) {
	$cats = wp_get_post_categories( $post_id );
	if ( empty( $cats ) ) {
		return null;
	}

	$related = get_posts( array(
		'category__in'   => $cats,
		'post__not_in'   => array( $post_id ),
		'posts_per_page' => 1,
		'orderby'        => 'rand',
		'fields'         => 'ids',
	) );

	if ( empty( $related ) ) {
		return null;
	}

	$rid   = $related[0];
	$thumb = get_the_post_thumbnail_url( $rid, 'medium' );

	return array(
		'title' => get_the_title( $rid ),
		'url'   => get_permalink( $rid ),
		'img'   => $thumb ?: '',
	);
}
