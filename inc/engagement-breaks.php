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

/* ================================================================
 * AD ZONE RENDERERS — v4 Full Monetization
 *
 * Three zone types: display (GPT slot), pause (GPT contentPause),
 * and video (playerPro script, NOT a GPT slot).
 * ================================================================ */

/**
 * Render a regular display ad zone.
 *
 * @param string $size_str Size like "300x250".
 * @param string $zone_id  Unique zone identifier.
 * @return string HTML div.
 */
function pl_render_display_zone( $size_str, $zone_id ) {
	$s = pl_ad_settings();
	if ( ! $s['enabled'] ) {
		return '';
	}
	$slot = 'Ad.Plus-' . $size_str;
	$dims = str_replace( 'x', ',', $size_str );
	return sprintf(
		'<div class="ad-zone" id="%s" data-zone="%s" data-slot="%s" data-size="%s"></div>',
		esc_attr( $zone_id ),
		esc_attr( $zone_id ),
		esc_attr( $slot ),
		esc_attr( $dims )
	);
}

/**
 * Render a pause banner zone (GPT contentPause).
 *
 * @param string $zone_id Unique zone identifier.
 * @return string HTML div.
 */
function pl_render_pause_zone( $zone_id ) {
	$s = pl_ad_settings();
	if ( ! $s['enabled'] ) {
		return '';
	}
	return sprintf(
		'<div class="ad-zone ad-pause" id="%s" data-zone="%s" data-slot="Ad.Plus-Pause-300x250" data-size="300,250" data-pause="true"></div>',
		esc_attr( $zone_id ),
		esc_attr( $zone_id )
	);
}

/**
 * Render an InPage Video zone (playerPro script, NOT a GPT slot).
 *
 * @return string HTML with script tags.
 */
function pl_render_video_zone() {
	$s = pl_ad_settings();
	if ( ! $s['enabled'] ) {
		return '';
	}
	return '<div class="ad-zone ad-video" data-zone="video-1">'
		. '<script async src="https://cdn.ad.plus/player/adplus.js"></script>'
		. '<script data-playerPro="current">(function(){'
		. 'var s=document.querySelector(\'script[data-playerPro="current"]\');'
		. 's.removeAttribute("data-playerPro");'
		. '(playerPro=window.playerPro||[]).push({id:"z2I717k6zq5b",after:s,'
		. 'appParams:{"C_NETWORK_CODE":"22953639975","C_WEBSITE":"cheerlives.com"}});'
		. '})();</script></div>';
}

/**
 * Get the v4 per-item ad config map.
 *
 * Keys: item number. Values: array of position => size or 'pause'.
 * Positions: 'after_img', 'after_p1', 'after_p2'.
 *
 * @return array
 */
function pl_get_item_ad_config() {
	return array(
		1  => array( 'after_img' => '300x250', 'after_p1' => '336x280' ),
		2  => array( 'after_img' => '300x600', 'after_p2' => '300x250' ),
		3  => array( 'after_img' => '300x250', 'after_p1' => 'pause' ),
		4  => array( 'after_img' => '336x280' ),
		5  => array( 'after_img' => '300x250' ),
		6  => array( 'after_img' => '300x600', 'after_p2' => 'pause' ),
		7  => array( 'after_img' => '300x250' ),
		8  => array( 'after_img' => '250x250' ),
		9  => array( 'after_img' => '336x280', 'after_p2' => 'pause' ),
		10 => array( 'after_img' => '300x250' ),
		11 => array( 'after_img' => '300x600' ),
		12 => array( 'after_img' => '300x250', 'after_p2' => 'pause' ),
		13 => array( 'after_img' => '300x100' ),
		14 => array( 'after_img' => '336x280' ),
		15 => array( 'after_img' => '300x250' ),
	);
}

/**
 * Inject ad zones into a single listicle item's HTML.
 *
 * Parses the item HTML to find images and paragraphs, then inserts
 * ad zones after the specified elements according to the ad config.
 *
 * @param string $html       Item HTML.
 * @param int    $item_index Item number (1-based).
 * @return string Modified HTML with ad zones.
 */
function pl_inject_item_ads( $html, $item_index ) {
	$config = pl_get_item_ad_config();
	if ( ! isset( $config[ $item_index ] ) ) {
		return $html;
	}

	$ads = $config[ $item_index ];

	// Insert after_img: find first </figure> or first </div> containing an image.
	if ( isset( $ads['after_img'] ) ) {
		$size    = $ads['after_img'];
		$zone_id = 'item' . $item_index . '-img-' . $size;
		$zone_html = pl_render_display_zone( $size, $zone_id );

		// Try after </figure> first (WordPress block images).
		if ( strpos( $html, '</figure>' ) !== false ) {
			$pos = strpos( $html, '</figure>' );
			$html = substr( $html, 0, $pos + 9 ) . $zone_html . substr( $html, $pos + 9 );
		} elseif ( preg_match( '/<\/div>\s*(?=<p)/i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			// After the image wrapper div, before first paragraph.
			$pos = $m[0][1] + strlen( $m[0][0] );
			$html = substr( $html, 0, $pos ) . $zone_html . substr( $html, $pos );
		}
	}

	// Insert after_p1 or after_p2: find Nth </p>.
	foreach ( array( 'after_p1' => 1, 'after_p2' => 2 ) as $key => $nth ) {
		if ( ! isset( $ads[ $key ] ) ) {
			continue;
		}

		$size = $ads[ $key ];
		$p_count = 0;
		$offset = 0;

		while ( ( $pos = strpos( $html, '</p>', $offset ) ) !== false ) {
			$p_count++;
			if ( $p_count === $nth ) {
				$insert_pos = $pos + 4;

				if ( $size === 'pause' ) {
					$zone_id = 'item' . $item_index . '-p' . $nth . '-pause';
					$zone_html = pl_render_pause_zone( $zone_id );
				} else {
					$zone_id = 'item' . $item_index . '-p' . $nth . '-' . $size;
					$zone_html = pl_render_display_zone( $size, $zone_id );
				}

				$html = substr( $html, 0, $insert_pos ) . $zone_html . substr( $html, $insert_pos );
				break;
			}
			$offset = $pos + 4;
		}
	}

	return $html;
}

/**
 * Inject intro ad zones into pre-item content.
 *
 * Intro structure: P1 → P2 → P3 → P4 → P5 (5 paragraphs before items).
 * Ad map: After P2 = pause, After P3 = video, After P5 = 300x250.
 *
 * @param string $intro_html Intro HTML (content before first H2).
 * @return string Modified HTML with ad zones.
 */
function pl_inject_intro_ads( $intro_html ) {
	$intro_ads = array(
		2 => array( 'type' => 'pause',   'zone_id' => 'intro-p2-pause' ),
		3 => array( 'type' => 'video' ),
		5 => array( 'type' => 'display', 'zone_id' => 'intro-p5-300x250', 'size' => '300x250' ),
	);

	$p_count = 0;
	$offset  = 0;
	$inserts = array(); // position => html (reverse order later).

	while ( ( $pos = strpos( $intro_html, '</p>', $offset ) ) !== false ) {
		$p_count++;
		$insert_pos = $pos + 4;

		if ( isset( $intro_ads[ $p_count ] ) ) {
			$ad = $intro_ads[ $p_count ];
			$zone_html = '';

			switch ( $ad['type'] ) {
				case 'pause':
					$zone_html = pl_render_pause_zone( $ad['zone_id'] );
					break;
				case 'video':
					$zone_html = pl_render_video_zone();
					break;
				case 'display':
					$zone_html = pl_render_display_zone( $ad['size'], $ad['zone_id'] );
					break;
			}

			if ( $zone_html ) {
				$inserts[ $insert_pos ] = $zone_html;
			}
		}

		$offset = $pos + 4;
	}

	// Insert in reverse order so positions don't shift.
	krsort( $inserts );
	foreach ( $inserts as $pos => $html ) {
		$intro_html = substr( $intro_html, 0, $pos ) . $html . substr( $intro_html, $pos );
	}

	return $intro_html;
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
	$post_id      = get_the_ID();
	$raw_content  = $content;
	$date_seed    = gmdate( 'Ymd' );
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
	$collectible_emojis  = $config['collectible_emojis'] ?? array( "\xF0\x9F\x92\x8E", "✨", "\xF0\x9F\xA6\x8B", "\xF0\x9F\x92\xAB", "\xF0\x9F\x8C\xB8" );

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

	foreach ( $parts as $part ) {
		// Check if this part starts with an H2 (is a listicle item).
		if ( preg_match( '/<h2[^>]*>.*?#(\d+)/i', $part ) ) {
			$item_index++;

			// Wrap item with engagement overlays.
			$part = pl_eb_wrap_item( $part, $item_index, array(
				'trending'    => in_array( $item_index, $trending_indices, true ),
				'collectible' => in_array( $item_index, $collectible_indices, true )
					? $collectible_emojis[ array_search( $item_index, $collectible_indices ) % count( $collectible_emojis ) ]
					: false,
				'save_count'  => $save_counts[ $item_index ] ?? 0,
				'blur'        => ( $item_index === $blur_item ),
			) );

			// v4: Inject ad zones INSIDE each item (after img, after p1/p2).
			$part = pl_inject_item_ads( $part, $item_index );

			$output_parts[] = $part;

			// Inject engagement break AFTER this item if configured.
			if ( isset( $break_map[ $item_index ] ) ) {
				$output_parts[] = $break_map[ $item_index ];
			}
		} else {
			// Intro or non-item content — inject intro ads.
			if ( $item_index === 0 ) {
				$part = pl_inject_intro_ads( $part );
			}
			$output_parts[] = $part;
		}
	}

	$content = implode( "\n", $output_parts );

	// Hero mosaic — insert after first paragraph, or before first H2 as fallback.
	$mosaic_html = pl_render_hero_mosaic( $raw_content, $total_items, $post_id );

	// Ad zone after hero mosaic — premium above-fold position.
	$after_mosaic_ad = '';
	if ( $mosaic_html ) {
		$after_mosaic_ad = pl_render_display_zone( '300x250', 'eb-after-mosaic' );
	}

	if ( $mosaic_html ) {
		$mosaic_block = $mosaic_html . $after_mosaic_ad;
		$first_p_end  = strpos( $content, '</p>' );
		if ( $first_p_end !== false ) {
			$insert_pos = $first_p_end + 4; // after </p>
			$content    = substr( $content, 0, $insert_pos ) . $mosaic_block . substr( $content, $insert_pos );
		} else {
			// Fallback: prepend before first H2 item.
			$content = preg_replace(
				'/(?=<div class="eb-item"[^>]*data-item="1")/i',
				$mosaic_block,
				$content,
				1
			);
		}
	}

	// Footer ad zone — end-of-content position.
	$content .= pl_render_display_zone( '300x250', 'eb-footer' );

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
		$overlays .= '<button class="eb-fav-heart" data-eb-action="fav" data-idx="' . ( $index - 1 ) . '" aria-label="Save to favorites"></button>';
	}

	if ( $collectible && get_theme_mod( 'eb_collectibles', true ) ) {
		$overlays .= '<span class="eb-collectible" data-eb-action="collect" data-emoji="' . esc_attr( $collectible ) . '">' . $collectible . '</span>';
	}

	if ( $blur && get_theme_mod( 'eb_blur_reveal', true ) ) {
		$overlays .= '<div class="eb-reveal-overlay" data-eb-action="reveal"><span>' . "✨" . ' Tap to reveal this look</span></div>';
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
				. '<div class="eb-break-badge">' . "✨" . ' Style Quiz</div>'
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
 * Resize a mosaic image URL via the CDN resizer.
 *
 * @param string $url   Original image URL.
 * @param int    $width Target width.
 * @return string Resized URL.
 */
function pl_mosaic_resize_url( $url, $width ) {
	if ( strpos( $url, 'myquickurl.com' ) === false ) {
		return $url;
	}

	// Already a resizer URL — replace &w= parameter.
	if ( strpos( $url, 'img.php?' ) !== false ) {
		if ( preg_match( '/[&?]w=\d+/', $url ) ) {
			return preg_replace( '/([&?])w=\d+/', '${1}w=' . $width, $url );
		}
		return $url . '&w=' . $width . '&q=75';
	}

	// Full CDN URL — extract path and build resizer URL.
	if ( preg_match( '/myquickurl\.com\/(.+?)(?:\?|$)/', $url, $m ) ) {
		$path = rawurlencode( $m[1] );
		$path = str_replace( '%2F', '/', $path );
		return 'https://myquickurl.com/img.php?src=' . $path . '&w=' . $width . '&q=75';
	}

	return $url;
}

/**
 * Hero Mosaic — curiosity-gap grid replacing featured image + TOC.
 *
 * Extracts images/titles from post content, shows a 4-cell mosaic
 * with items #1, #5, #9 (or adapted) plus a "+N more" overlay.
 */
function pl_render_hero_mosaic( $content, $total_items, $post_id ) {
	if ( $total_items < 4 ) {
		return '';
	}

	// Split content at H2 boundaries to extract per-item data.
	$parts = preg_split( '/(?=<h2[^>]*>.*?#\d+)/i', $content, -1, PREG_SPLIT_NO_EMPTY );

	$items    = array();
	$item_num = 0;

	foreach ( $parts as $part ) {
		if ( ! preg_match( '/<h2[^>]*>(.*?)<\/h2>/is', $part, $h2m ) ) {
			continue;
		}

		$item_num++;
		$h2_inner = $h2m[1];

		// Extract title: strip tags and remove "#N" prefix.
		$title = wp_strip_all_tags( $h2_inner );
		$title = preg_replace( '/^\s*#\d+\s*[\.:\-\x{2013}\x{2014}]?\s*/u', '', $title );
		$title = trim( $title );

		// Extract image: prefer data-pin-media, fallback to img src.
		$img = '';
		if ( preg_match( '/data-pin-media=["\']([^"\']+)["\']/', $part, $pm ) ) {
			$img = $pm[1];
		} elseif ( preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/', $part, $sm ) ) {
			$img = $sm[1];
		}

		if ( $img && $title ) {
			$items[ $item_num ] = array( 'img' => $img, 'title' => $title );
		}
	}

	if ( count( $items ) < 4 ) {
		return '';
	}

	// Pick visible cell positions: 1, 5, 9 or adapted for fewer items.
	$keys = array_keys( $items );
	if ( $total_items >= 9 ) {
		$vis_pos = array( 1, 5, 9 );
	} else {
		$vis_pos = array_unique( array(
			1,
			(int) ceil( $total_items / 3 ),
			(int) ceil( 2 * $total_items / 3 ),
		) );
	}

	$visible = array();
	foreach ( $vis_pos as $p ) {
		if ( isset( $items[ $p ] ) ) {
			$visible[] = $p;
		}
	}
	if ( count( $visible ) < 3 ) {
		$visible = array_slice( $keys, 0, 3 );
	}

	// "+N more" cell image: first item not in visible set.
	$more_key = null;
	foreach ( $keys as $k ) {
		if ( ! in_array( $k, $visible, true ) ) {
			$more_key = $k;
			break;
		}
	}
	if ( $more_key === null ) {
		$more_key = end( $keys );
	}

	// Curiosity number: random item NOT in visible set, seeded consistently.
	$seed = crc32( $post_id . gmdate( 'Ymd' ) );
	mt_srand( $seed + 42 );
	$non_visible   = array_values( array_diff( $keys, $visible ) );
	$curiosity_num = ! empty( $non_visible )
		? $non_visible[ mt_rand( 0, count( $non_visible ) - 1 ) ]
		: mt_rand( 1, $total_items );

	// Social proof count (seeded per post per day).
	mt_srand( $seed + 99 );
	$social_count = mt_rand( 2800, 8400 );
	$formatted    = number_format( $social_count / 1000, 1 ) . 'K';

	$remaining = $total_items - 3;

	// Build HTML.
	$html  = '<div class="eb-hero-mosaic">';

	// Hook text.
	$html .= '<div class="eb-hero-hook">';
	$html .= '<span class="eb-hero-hook-text">' . "\xE2\x9C\xA8" . ' Upcoming preview</span>';
	$html .= '<span class="eb-hero-hook-sub">Tap any look to jump</span>';
	$html .= '</div>';

	// Grid.
	$html .= '<div class="eb-hero-grid">';

	// Cell 1 (main, spans 2 rows — ~50% of 720px container).
	$v     = $visible[0];
	$html .= '<a class="eb-hero-cell eb-hero-cell-main" href="#item-' . $v . '">'
		. '<img src="' . esc_url( pl_mosaic_resize_url( $items[ $v ]['img'], 360 ) ) . '" alt="' . esc_attr( $items[ $v ]['title'] ) . '" loading="eager" fetchpriority="high" width="360" height="640">'
		. '<div class="eb-hero-cell-info"><span class="eb-hero-cell-num">#' . $v . '</span>'
		. '<span class="eb-hero-cell-name">' . esc_html( $items[ $v ]['title'] ) . '</span></div></a>';

	// Cell 2 (~25% of container).
	$v     = $visible[1];
	$html .= '<a class="eb-hero-cell" href="#item-' . $v . '">'
		. '<img src="' . esc_url( pl_mosaic_resize_url( $items[ $v ]['img'], 240 ) ) . '" alt="' . esc_attr( $items[ $v ]['title'] ) . '" loading="eager" width="240" height="427">'
		. '<div class="eb-hero-cell-info"><span class="eb-hero-cell-num">#' . $v . '</span>'
		. '<span class="eb-hero-cell-name">' . esc_html( $items[ $v ]['title'] ) . '</span></div></a>';

	// Cell 3 (~25% of container).
	$v     = $visible[2];
	$html .= '<a class="eb-hero-cell" href="#item-' . $v . '">'
		. '<img src="' . esc_url( pl_mosaic_resize_url( $items[ $v ]['img'], 240 ) ) . '" alt="' . esc_attr( $items[ $v ]['title'] ) . '" loading="eager" width="240" height="427">'
		. '<div class="eb-hero-cell-info"><span class="eb-hero-cell-num">#' . $v . '</span>'
		. '<span class="eb-hero-cell-name">' . esc_html( $items[ $v ]['title'] ) . '</span></div></a>';

	// Cell 4: "+N more" overlay (~25% of container).
	$html .= '<a class="eb-hero-cell" href="#item-1">'
		. '<img src="' . esc_url( pl_mosaic_resize_url( $items[ $more_key ]['img'], 240 ) ) . '" alt="" loading="eager" width="240" height="427">'
		. '<div class="eb-hero-more"><span class="eb-hero-more-num">+' . $remaining . '</span>'
		. '<span class="eb-hero-more-text">more looks</span></div></a>';

	$html .= '</div>'; // .eb-hero-grid

	// Social proof bar.
	$html .= '<div class="eb-hero-social">'
		. '<span class="eb-hero-social-count">' . "\xF0\x9F\x93\x8C" . ' ' . $formatted . ' people saved this collection</span>'
		. '<span class="eb-hero-social-total">' . $total_items . ' looks inside</span>'
		. '</div>';

	$html .= '</div>'; // .eb-hero-mosaic

	return $html;
}


/**
 * TOC Teaser (legacy, kept for non-mosaic fallback).
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
