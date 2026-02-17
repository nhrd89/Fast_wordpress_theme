<?php
/**
 * PinLightning Pinterest SEO.
 *
 * 1. Open Graph tags
 * 2. Pinterest-specific meta + Schema.org JSON-LD
 * 3. Twitter Card meta
 * 4. Canonical URL
 *
 * @package PinLightning
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*--------------------------------------------------------------
 * Shared helper
 *--------------------------------------------------------------*/

/**
 * Get a trimmed plain-text description for the current post.
 *
 * Returns the manual excerpt if set, otherwise the first 160 characters
 * of the post content with all tags stripped.
 *
 * @param WP_Post|null $post Post object. Defaults to global $post.
 * @return string
 */
function pinlightning_seo_description( $post = null ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return '';
	}

	$text = $post->post_excerpt;
	if ( empty( $text ) ) {
		$text = $post->post_content;
	}

	$text = wp_strip_all_tags( strip_shortcodes( $text ) );
	$text = str_replace( array( "\r\n", "\r", "\n" ), ' ', $text );
	$text = preg_replace( '/\s+/', ' ', trim( $text ) );

	if ( mb_strlen( $text ) > 160 ) {
		$text = mb_substr( $text, 0, 157 ) . '...';
	}

	return $text;
}

/*--------------------------------------------------------------
 * 1. OPEN GRAPH TAGS
 *--------------------------------------------------------------*/

/**
 * Output Open Graph meta tags in wp_head.
 *
 * Singular posts get the full set. Archives get a minimal set.
 */
function pinlightning_open_graph_tags() {
	$tags = array();

	if ( is_singular() ) {
		$post        = get_queried_object();
		$description = pinlightning_seo_description( $post );

		$tags['og:type']      = 'article';
		$tags['og:title']     = get_the_title( $post );
		$tags['og:url']       = get_permalink( $post );
		$tags['og:site_name'] = get_bloginfo( 'name' );
		$tags['og:locale']    = get_locale();

		if ( $description ) {
			$tags['og:description'] = $description;
		}

		// Featured image at pinterest-pin size (1000x1500).
		$thumbnail_id = get_post_thumbnail_id( $post );
		if ( $thumbnail_id ) {
			$image_url = wp_get_attachment_image_url( $thumbnail_id, 'pinterest-pin' );
			if ( $image_url ) {
				$tags['og:image']        = $image_url;
				$tags['og:image:width']  = '1000';
				$tags['og:image:height'] = '1500';
			}
		}
	} elseif ( is_archive() || is_home() ) {
		$tags['og:type']      = 'website';
		$tags['og:site_name'] = get_bloginfo( 'name' );
		$tags['og:url']       = pinlightning_current_url();

		if ( is_category() || is_tag() || is_tax() ) {
			$tags['og:title'] = single_term_title( '', false );
		} elseif ( is_post_type_archive() ) {
			$tags['og:title'] = post_type_archive_title( '', false );
		} elseif ( is_home() ) {
			$tags['og:title'] = is_front_page()
				? get_bloginfo( 'name' )
				: single_post_title( '', false );
		} else {
			$tags['og:title'] = get_the_archive_title();
		}
	} else {
		return;
	}

	echo "\n<!-- PinLightning Open Graph -->\n";
	foreach ( $tags as $property => $content ) {
		printf(
			'<meta property="%s" content="%s">' . "\n",
			esc_attr( $property ),
			esc_attr( $content )
		);
	}
}
add_action( 'wp_head', 'pinlightning_open_graph_tags', 5 );

/**
 * Get the current page URL for archive og:url.
 *
 * @return string
 */
function pinlightning_current_url() {
	global $wp;
	return home_url( add_query_arg( array(), $wp->request ) );
}

/*--------------------------------------------------------------
 * 2. PINTEREST-SPECIFIC META + SCHEMA.ORG JSON-LD
 *--------------------------------------------------------------*/

/**
 * Output Pinterest rich-pin meta and Article JSON-LD on singular posts.
 */
function pinlightning_pinterest_meta() {
	echo '<meta name="pinterest-rich-pin" content="true">' . "\n";

	if ( ! is_singular() ) {
		return;
	}

	$post        = get_queried_object();
	$description = pinlightning_seo_description( $post );
	$author      = get_the_author_meta( 'display_name', $post->post_author );
	$permalink   = get_permalink( $post );

	// Schema.org Article JSON-LD.
	$schema = array(
		'@context'         => 'https://schema.org',
		'@type'            => 'Article',
		'headline'         => get_the_title( $post ),
		'datePublished'    => get_the_date( 'c', $post ),
		'dateModified'     => get_the_modified_date( 'c', $post ),
		'mainEntityOfPage' => $permalink,
		'author'           => array(
			'@type' => 'Person',
			'name'  => $author,
		),
		'publisher'        => array(
			'@type' => 'Organization',
			'name'  => get_bloginfo( 'name' ),
		),
	);

	if ( $description ) {
		$schema['description'] = $description;
	}

	// Publisher logo.
	$custom_logo_id = get_theme_mod( 'custom_logo' );
	if ( $custom_logo_id ) {
		$logo_url = wp_get_attachment_image_url( $custom_logo_id, 'full' );
		if ( $logo_url ) {
			$schema['publisher']['logo'] = array(
				'@type' => 'ImageObject',
				'url'   => $logo_url,
			);
		}
	}

	// Featured image at pinterest-pin size.
	$thumbnail_id = get_post_thumbnail_id( $post );
	if ( $thumbnail_id ) {
		$image_url = wp_get_attachment_image_url( $thumbnail_id, 'pinterest-pin' );
		if ( $image_url ) {
			$schema['image'] = array(
				'@type'  => 'ImageObject',
				'url'    => $image_url,
				'width'  => 1000,
				'height' => 1500,
			);
		}
	}

	echo '<script type="application/ld+json">' . "\n";
	echo wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
	echo "\n</script>\n";
}
add_action( 'wp_head', 'pinlightning_pinterest_meta', 5 );

/*--------------------------------------------------------------
 * 3. TWITTER CARD META
 *--------------------------------------------------------------*/

/**
 * Output Twitter Card meta tags on singular posts.
 */
function pinlightning_twitter_card_tags() {
	if ( ! is_singular() ) {
		return;
	}

	$post        = get_queried_object();
	$description = pinlightning_seo_description( $post );

	$tags = array(
		'twitter:card'  => 'summary_large_image',
		'twitter:title' => get_the_title( $post ),
	);

	if ( $description ) {
		$tags['twitter:description'] = $description;
	}

	$thumbnail_id = get_post_thumbnail_id( $post );
	if ( $thumbnail_id ) {
		$image_url = wp_get_attachment_image_url( $thumbnail_id, 'pinterest-pin' );
		if ( $image_url ) {
			$tags['twitter:image'] = $image_url;
		}
	}

	echo "\n<!-- PinLightning Twitter Card -->\n";
	foreach ( $tags as $name => $content ) {
		printf(
			'<meta name="%s" content="%s">' . "\n",
			esc_attr( $name ),
			esc_attr( $content )
		);
	}
}
add_action( 'wp_head', 'pinlightning_twitter_card_tags', 5 );

/*--------------------------------------------------------------
 * 4. CANONICAL URL
 *--------------------------------------------------------------*/

/**
 * Output a canonical URL on singular posts.
 *
 * Skips output when Yoast SEO, RankMath, or All in One SEO is active
 * to avoid duplicate canonical tags.
 */
function pinlightning_canonical_url() {
	if ( ! is_singular() ) {
		return;
	}

	// Bail if a major SEO plugin is handling canonicals.
	if ( class_exists( 'WPSEO_Frontend' ) || class_exists( 'WPSEO_Options' ) ) {
		return; // Yoast SEO.
	}
	if ( class_exists( 'RankMath' ) ) {
		return; // RankMath.
	}
	if ( class_exists( 'AIOSEO\\Plugin\\AIOSEO' ) ) {
		return; // All in One SEO.
	}

	$url = get_permalink();
	if ( $url ) {
		echo '<link rel="canonical" href="' . esc_url( $url ) . '">' . "\n";
	}
}
add_action( 'wp_head', 'pinlightning_canonical_url', 5 );
