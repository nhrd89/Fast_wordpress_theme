<?php
/**
 * PinLightning image handler.
 *
 * 1. Lazy loading with LCP-aware eager first image
 * 2. Responsive image sizes for fashion content
 * 3. Pinterest data attributes
 * 3b. Content image dimension caching (WP + external CDN)
 * 3c. CDN image rewriting (myquickurl.com resizer integration)
 * 4. Dominant color placeholder extraction
 * 5. WebP <picture> wrapper
 *
 * @package PinLightning
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*--------------------------------------------------------------
 * 1. LAZY LOADING
 *--------------------------------------------------------------*/

/**
 * Track how many images have been rendered on the current page.
 *
 * @param bool $increment Whether to increment the counter.
 * @return int Current image count (before any increment).
 */
function pinlightning_image_counter( $increment = true ) {
	static $count = 0;
	$current = $count;
	if ( $increment ) {
		$count++;
	}
	return $current;
}

/**
 * Add loading and decoding attributes to attachment images.
 *
 * The first image on the page gets loading="eager" and fetchpriority="high"
 * (it is likely the LCP element). All subsequent images get loading="lazy"
 * and decoding="async".
 *
 * @param array $attr       Existing image attributes.
 * @param WP_Post $attachment The attachment post object.
 * @param string|int[] $size Requested image size.
 * @return array Modified attributes.
 */
function pinlightning_lazy_load_attributes( $attr, $attachment, $size ) {
	if ( is_admin() ) {
		return $attr;
	}

	$index = pinlightning_image_counter();

	if ( 0 === $index ) {
		// First image: LCP candidate.
		$attr['loading']       = 'eager';
		$attr['fetchpriority'] = 'high';
		$attr['decoding']      = 'async';
	} else {
		$attr['loading']  = 'lazy';
		$attr['decoding'] = 'async';
	}

	return $attr;
}
add_filter( 'wp_get_attachment_image_attributes', 'pinlightning_lazy_load_attributes', 10, 3 );

/*--------------------------------------------------------------
 * 2. RESPONSIVE IMAGES
 *--------------------------------------------------------------*/

/**
 * Register custom image sizes for fashion content.
 */
function pinlightning_register_image_sizes() {
	// 2:3 portrait — archive grid cards.
	add_image_size( 'card-thumb', 400, 600, true );

	// 2:3 portrait — featured / large cards.
	add_image_size( 'card-thumb-lg', 600, 900, true );

	// 2:3 portrait — Pinterest sharing.
	add_image_size( 'pinterest-pin', 1000, 1500, true );

	// 3:2 landscape — single post hero banner.
	add_image_size( 'post-hero', 1200, 800, true );
}
add_action( 'after_setup_theme', 'pinlightning_register_image_sizes' );

/**
 * Make custom sizes selectable in the media uploader.
 *
 * @param array $sizes Existing size names.
 * @return array
 */
function pinlightning_custom_image_size_names( $sizes ) {
	return array_merge( $sizes, array(
		'card-thumb'    => __( 'Card Thumbnail (400x600)', 'pinlightning' ),
		'card-thumb-lg' => __( 'Card Large (600x900)', 'pinlightning' ),
		'pinterest-pin' => __( 'Pinterest Pin (1000x1500)', 'pinlightning' ),
		'post-hero'     => __( 'Post Hero (1200x800)', 'pinlightning' ),
	) );
}
add_filter( 'image_size_names_choose', 'pinlightning_custom_image_size_names' );

/**
 * Set default srcset sizes attribute for theme image sizes.
 *
 * @param string $sizes         A source size value for use in a 'sizes' attribute.
 * @param array  $size          Requested width and height of the image.
 * @param string|null $image_src The image source URL.
 * @param array|null  $image_meta The image meta data.
 * @param int    $attachment_id The image attachment ID.
 * @return string Modified sizes attribute.
 */
function pinlightning_responsive_sizes( $sizes, $size, $image_src, $image_meta, $attachment_id ) {
	if ( ! is_array( $size ) || empty( $size[0] ) ) {
		return $sizes;
	}

	$width = (int) $size[0];

	// Card thumbnails: 1 column on mobile, 2 on tablet, 3–4 on desktop.
	if ( $width <= 400 ) {
		return '(max-width: 480px) 100vw, (max-width: 768px) 50vw, 400px';
	}

	if ( $width <= 600 ) {
		return '(max-width: 480px) 100vw, (max-width: 768px) 50vw, 600px';
	}

	// Post hero: full width up to max-width.
	if ( $width >= 1200 ) {
		return '(max-width: 1200px) 100vw, 1200px';
	}

	return $sizes;
}
add_filter( 'wp_calculate_image_sizes', 'pinlightning_responsive_sizes', 10, 5 );

/*--------------------------------------------------------------
 * 3. PINTEREST IMAGE ATTRIBUTES
 *--------------------------------------------------------------*/

/**
 * Add Pinterest data attributes to post thumbnail HTML.
 *
 * @param string       $html              The post thumbnail HTML.
 * @param int          $post_id           The post ID.
 * @param int          $post_thumbnail_id The post thumbnail attachment ID.
 * @param string|int[] $size              Requested image size.
 * @param string       $attr              Additional attributes string (query string format).
 * @return string Modified HTML.
 */
function pinlightning_pinterest_thumbnail_attrs( $html, $post_id, $post_thumbnail_id, $size, $attr ) {
	if ( empty( $html ) || is_admin() ) {
		return $html;
	}

	$pin_attrs = pinlightning_build_pinterest_attrs( $post_id, $post_thumbnail_id );
	if ( empty( $pin_attrs ) ) {
		return $html;
	}

	// Insert attributes before the closing /> or > of the <img> tag.
	return preg_replace( '/<img\b/i', '<img ' . $pin_attrs, $html, 1 );
}
add_filter( 'post_thumbnail_html', 'pinlightning_pinterest_thumbnail_attrs', 10, 5 );

/**
 * Add Pinterest data attributes to images inside post content.
 *
 * Matches <img> tags that have a wp-image-{id} class (WordPress core adds this)
 * and decorates them with Pinterest sharing data from the parent post.
 *
 * @param string $content The post content.
 * @return string Modified content.
 */
function pinlightning_pinterest_content_images( $content ) {
	if ( empty( $content ) || is_admin() || ! is_singular() ) {
		return $content;
	}

	$post_id = get_the_ID();
	if ( ! $post_id ) {
		return $content;
	}

	return preg_replace_callback(
		'/<img\b([^>]*)\bclass="([^"]*\bwp-image-(\d+)\b[^"]*)"([^>]*)>/i',
		function( $matches ) use ( $post_id ) {
			$attachment_id = (int) $matches[3];
			$pin_attrs     = pinlightning_build_pinterest_attrs( $post_id, $attachment_id );
			if ( empty( $pin_attrs ) ) {
				return $matches[0];
			}
			return '<img ' . $pin_attrs . $matches[1] . 'class="' . $matches[2] . '"' . $matches[4] . '>';
		},
		$content
	);
}
add_filter( 'the_content', 'pinlightning_pinterest_content_images', 20 );

/**
 * Build the Pinterest data-attribute string for a given post + image.
 *
 * @param int $post_id       The post ID.
 * @param int $attachment_id The image attachment ID.
 * @return string Space-prefixed attribute string, or empty if nothing to add.
 */
function pinlightning_build_pinterest_attrs( $post_id, $attachment_id ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return '';
	}

	$attrs = array();

	// data-pin-description: title + trimmed excerpt.
	$description = get_the_title( $post_id );
	$excerpt     = get_the_excerpt( $post );
	if ( $excerpt ) {
		$description .= ' — ' . $excerpt;
	}
	$attrs[] = 'data-pin-description="' . esc_attr( $description ) . '"';

	// data-pin-media: pinterest-pin size URL.
	$pin_image_url = wp_get_attachment_image_url( $attachment_id, 'pinterest-pin' );
	if ( $pin_image_url ) {
		$attrs[] = 'data-pin-media="' . esc_url( $pin_image_url ) . '"';
	}

	// data-pin-url: permalink.
	$attrs[] = 'data-pin-url="' . esc_url( get_permalink( $post_id ) ) . '"';

	return implode( ' ', $attrs ) . ' ';
}

/*--------------------------------------------------------------
 * 3b. CONTENT IMAGE DIMENSION CACHING
 *
 * Two-phase approach for external CDN images (myquickurl.com etc.):
 *   Phase 1 (save_post): Pre-fetch dimensions via getimagesize()
 *            and cache in post meta '_pinlightning_image_dims'.
 *   Phase 2 (the_content): Read cached dims and inject width/height
 *            attributes. NEVER calls getimagesize() during render.
 *--------------------------------------------------------------*/

/**
 * Phase 1: Cache image dimensions when a post is saved.
 *
 * Scans post_content for <img> tags, fetches dimensions for any
 * that don't already have width/height, and stores results as
 * post meta keyed by image src URL.
 *
 * @param int     $post_id The post ID.
 * @param WP_Post $post    The post object.
 */
function pinlightning_cache_image_dims_on_save( $post_id, $post ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	if ( empty( $post->post_content ) ) {
		return;
	}

	// Find all <img> tags in the raw post content.
	if ( ! preg_match_all( '/<img\b[^>]+>/i', $post->post_content, $img_matches ) ) {
		return;
	}

	// Load existing cached dimensions.
	$cached = get_post_meta( $post_id, '_pinlightning_image_dims', true );
	if ( ! is_array( $cached ) ) {
		$cached = array();
	}

	$updated = false;

	foreach ( $img_matches[0] as $img_tag ) {
		// Extract src.
		if ( ! preg_match( '/\bsrc=["\']([^"\']+)["\']/i', $img_tag, $src_match ) ) {
			continue;
		}

		$src = $src_match[1];

		// Skip if already has both width and height in the markup.
		if ( preg_match( '/\bwidth\s*=/', $img_tag ) && preg_match( '/\bheight\s*=/', $img_tag ) ) {
			continue;
		}

		// Skip if already cached for this src.
		$cache_key = md5( $src );
		if ( isset( $cached[ $cache_key ] ) ) {
			continue;
		}

		// Try WordPress attachment first (wp-image-{id} class).
		$dims = pinlightning_get_wp_image_dims( $img_tag );

		// Fall back to getimagesize() for external images.
		if ( ! $dims ) {
			$dims = pinlightning_fetch_remote_image_dims( $src );
		}

		if ( $dims ) {
			$cached[ $cache_key ] = array(
				'src'    => $src,
				'width'  => $dims[0],
				'height' => $dims[1],
			);
			$updated = true;
		}
	}

	if ( $updated ) {
		update_post_meta( $post_id, '_pinlightning_image_dims', $cached );
	}
}
add_action( 'save_post', 'pinlightning_cache_image_dims_on_save', 15, 2 );

/**
 * Try to get dimensions for a WordPress library image from its wp-image-{id} class.
 *
 * @param string $img_tag The full <img> tag HTML.
 * @return array|false [width, height] or false.
 */
function pinlightning_get_wp_image_dims( $img_tag ) {
	if ( ! preg_match( '/\bwp-image-(\d+)\b/', $img_tag, $id_match ) ) {
		return false;
	}

	$attachment_id = (int) $id_match[1];

	// Determine size from class.
	$size = 'full';
	if ( preg_match( '/\bsize-(\S+)/', $img_tag, $size_match ) ) {
		$size = $size_match[1];
	}

	$image_src = wp_get_attachment_image_src( $attachment_id, $size );
	if ( $image_src && $image_src[1] && $image_src[2] ) {
		return array( (int) $image_src[1], (int) $image_src[2] );
	}

	return false;
}

/**
 * Fetch dimensions of a remote image via getimagesize() with timeout.
 *
 * Only called during save_post, never during page render.
 * For myquickurl.com images, always fetches the original (non-resized) URL.
 *
 * @param string $url The image URL.
 * @return array|false [width, height] or false on failure.
 */
function pinlightning_fetch_remote_image_dims( $url ) {
	if ( empty( $url ) ) {
		return false;
	}

	// For myquickurl.com CDN URLs, normalize to the original image path.
	if ( strpos( $url, 'myquickurl.com' ) !== false ) {
		if ( preg_match( '/myquickurl\.com\/img\.php\?.*?src=([^&]+)/', $url, $m ) ) {
			$url = 'https://myquickurl.com/' . urldecode( $m[1] );
		} elseif ( preg_match( '/myquickurl\.com\/img\/(.+?)(?:\?|$)/', $url, $m ) ) {
			$url = 'https://myquickurl.com/' . $m[1];
		}
	}

	// Stream context with 3-second timeout.
	$context = stream_context_create( array(
		'http' => array(
			'timeout' => 3,
			'header'  => "User-Agent: PinLightning/1.0\r\n",
		),
		'ssl' => array(
			'verify_peer' => false,
		),
	) );

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$size = @getimagesize( $url, $info = array() );

	if ( ! $size ) {
		// Retry with stream context for URLs that need custom headers.
		$tmp = @file_get_contents( $url, false, $context, 0, 32768 ); // phpcs:ignore
		if ( $tmp ) {
			$tmp_file = wp_tempnam( 'pl_img_' );
			file_put_contents( $tmp_file, $tmp ); // phpcs:ignore
			$size = @getimagesize( $tmp_file );
			@unlink( $tmp_file );
		}
	}

	if ( $size && $size[0] > 0 && $size[1] > 0 ) {
		return array( (int) $size[0], (int) $size[1] );
	}

	return false;
}

/**
 * Phase 2: Add width/height and sizes to content images at render time.
 *
 * Uses only cached dimensions from post meta. Never makes remote requests.
 *
 * @param string $content The post content.
 * @return string Modified content.
 */
function pinlightning_content_image_dimensions( $content ) {
	if ( empty( $content ) || is_admin() || ! is_singular() ) {
		return $content;
	}

	$post_id = get_the_ID();
	if ( ! $post_id ) {
		return $content;
	}

	// Load cached dimensions.
	$cached = get_post_meta( $post_id, '_pinlightning_image_dims', true );
	if ( ! is_array( $cached ) ) {
		$cached = array();
	}

	return preg_replace_callback(
		'/<img\b([^>]*)>/i',
		function ( $matches ) use ( $cached ) {
			return pinlightning_fix_img_tag( $matches, $cached );
		},
		$content
	);
}
add_filter( 'the_content', 'pinlightning_content_image_dimensions', 15 );

/**
 * Fix a single <img> tag: add width/height and sizes from cache.
 *
 * Works for both WP library images (wp-image-{id}) and external CDN images.
 *
 * @param array $matches Regex matches from preg_replace_callback.
 * @param array $cached  Cached dimensions map from post meta.
 * @return string The fixed <img> tag.
 */
function pinlightning_fix_img_tag( $matches, $cached ) {
	$img = $matches[0];
	$atts = $matches[1];

	$has_width  = (bool) preg_match( '/\bwidth\s*=/', $atts );
	$has_height = (bool) preg_match( '/\bheight\s*=/', $atts );

	// Already has both — nothing to do except maybe add sizes.
	if ( $has_width && $has_height ) {
		return pinlightning_maybe_add_sizes( $img, $atts );
	}

	$width  = 0;
	$height = 0;

	// Strategy 1: WP library image — use attachment API.
	if ( preg_match( '/\bwp-image-(\d+)\b/', $atts, $id_match ) ) {
		$attachment_id = (int) $id_match[1];
		$size = 'full';
		if ( preg_match( '/\bsize-(\S+)/', $atts, $size_match ) ) {
			$size = $size_match[1];
		}

		$image_src = wp_get_attachment_image_src( $attachment_id, $size );
		if ( $image_src ) {
			$width  = (int) $image_src[1];
			$height = (int) $image_src[2];
		}

		// Also add srcset for WP images.
		if ( $width && ! preg_match( '/\bsrcset\s*=/', $img ) ) {
			$srcset = wp_get_attachment_image_srcset( $attachment_id, $size );
			if ( $srcset ) {
				$img = str_replace( '<img', '<img srcset="' . esc_attr( $srcset ) . '"', $img );
			}
		}
	}

	// Strategy 2: Cached dimensions (external CDN images).
	if ( ! $width && preg_match( '/\bsrc=["\']([^"\']+)["\']/i', $atts, $src_match ) ) {
		$cache_key = md5( $src_match[1] );
		if ( isset( $cached[ $cache_key ] ) ) {
			$width  = (int) $cached[ $cache_key ]['width'];
			$height = (int) $cached[ $cache_key ]['height'];
		}
	}

	// Apply dimensions.
	if ( $width && ! $has_width ) {
		$img = str_replace( '<img', '<img width="' . $width . '"', $img );
	}
	if ( $height && ! $has_height ) {
		$img = str_replace( '<img', '<img height="' . $height . '"', $img );
	}

	return pinlightning_maybe_add_sizes( $img, $atts );
}

/**
 * Add sizes attribute if srcset is present but sizes is missing.
 *
 * Uses article max-width of 720px as the layout constraint.
 *
 * @param string $img The <img> tag HTML.
 * @param string $atts The img attributes string.
 * @return string Modified <img> tag.
 */
function pinlightning_maybe_add_sizes( $img, $atts ) {
	if ( ! preg_match( '/\bsizes\s*=/', $img ) && preg_match( '/\bsrcset\s*=/', $img ) ) {
		$img = str_replace( '<img', '<img sizes="(max-width: 720px) 100vw, 720px"', $img );
	}
	return $img;
}

/*--------------------------------------------------------------
 * 3c. CDN IMAGE REWRITING (myquickurl.com resizer)
 *--------------------------------------------------------------*/

/**
 * Rewrite myquickurl.com images to use the on-demand resizer with srcset.
 *
 * Runs at priority 25 (after Pinterest attrs at 20 and dimensions at 15).
 * Generates resized src + 3-width srcset through the img.php endpoint.
 *
 * @param string $content The post content.
 * @return string Modified content.
 */
function pinlightning_rewrite_cdn_images( $content ) {
	if ( empty( $content ) || is_admin() || ! is_singular() ) {
		return $content;
	}

	$post_id = get_the_ID();
	$cached  = array();
	if ( $post_id ) {
		$cached = get_post_meta( $post_id, '_pinlightning_image_dims', true );
		if ( ! is_array( $cached ) ) {
			$cached = array();
		}
	}

	return preg_replace_callback(
		'/<img\b([^>]*)>/i',
		function ( $matches ) use ( $cached ) {
			return pinlightning_rewrite_cdn_img( $matches, $cached );
		},
		$content
	);
}
add_filter( 'the_content', 'pinlightning_rewrite_cdn_images', 25 );

/**
 * Rewrite a single CDN image tag.
 *
 * @param array $matches Regex matches.
 * @param array $cached  Dimension cache from post meta.
 * @return string Modified <img> tag.
 */
function pinlightning_rewrite_cdn_img( $matches, $cached ) {
	$img = $matches[0];
	$atts = $matches[1];

	// Only process myquickurl.com images.
	if ( strpos( $atts, 'myquickurl.com' ) === false ) {
		return $img;
	}

	// Skip if already has srcset (already processed or manually set).
	if ( preg_match( '/\bsrcset\s*=/', $atts ) ) {
		return $img;
	}

	// Extract the current src.
	if ( ! preg_match( '/\bsrc=["\']([^"\']+)["\']/i', $atts, $src_match ) ) {
		return $img;
	}

	$original_src = $src_match[1];

	// Extract the path after myquickurl.com/ (handles various URL formats).
	$cdn_path = '';
	if ( preg_match( '/myquickurl\.com\/img\.php\?.*?src=([^&"\']+)/', $original_src, $m ) ) {
		// Already a resizer URL — extract the original path.
		$cdn_path = urldecode( $m[1] );
	} elseif ( preg_match( '/myquickurl\.com\/img\/(.+?)(?:\?|$)/', $original_src, $m ) ) {
		// Clean URL format.
		$cdn_path = $m[1];
	} elseif ( preg_match( '/myquickurl\.com\/(.+?)(?:\?|$)/', $original_src, $m ) ) {
		// Direct file URL.
		$cdn_path = $m[1];
	}

	if ( empty( $cdn_path ) ) {
		return $img;
	}

	$cdn_path_encoded = rawurlencode( $cdn_path );
	// Restore forward slashes (they're safe in the query string).
	$cdn_path_encoded = str_replace( '%2F', '/', $cdn_path_encoded );

	$base_url = 'https://myquickurl.com/img.php?src=' . $cdn_path_encoded;

	// Build resized src (720px for article width).
	$new_src = $base_url . '&w=720&q=80';

	// Build srcset with 3 widths.
	$srcset = implode( ', ', array(
		$base_url . '&w=480&q=80 480w',
		$base_url . '&w=720&q=80 720w',
		$base_url . '&w=1080&q=80 1080w',
	) );

	// Replace src.
	$img = preg_replace(
		'/\bsrc=["\'][^"\']+["\']/i',
		'src="' . esc_url( $new_src ) . '"',
		$img
	);

	// Add srcset and sizes.
	$img = str_replace( '<img', '<img srcset="' . esc_attr( $srcset ) . '" sizes="(max-width: 720px) 100vw, 720px"', $img );

	// Preserve original full-size URL as data-pin-media for Pinterest.
	$original_full = 'https://myquickurl.com/' . $cdn_path;
	if ( strpos( $img, 'data-pin-media' ) === false ) {
		$img = str_replace( '<img', '<img data-pin-media="' . esc_url( $original_full ) . '"', $img );
	}

	// Add width/height from dimension cache if missing.
	if ( ! preg_match( '/\bwidth\s*=/', $img ) ) {
		// Try cache with original src key first, then the CDN path key.
		$dims = null;
		foreach ( array( $original_src, $original_full ) as $try_src ) {
			$key = md5( $try_src );
			if ( isset( $cached[ $key ] ) ) {
				$dims = $cached[ $key ];
				break;
			}
		}

		if ( $dims ) {
			$orig_w = (int) $dims['width'];
			$orig_h = (int) $dims['height'];
			// Calculate dimensions at 720px width.
			if ( $orig_w > 720 ) {
				$display_w = 720;
				$display_h = (int) round( $orig_h * ( 720 / $orig_w ) );
			} else {
				$display_w = $orig_w;
				$display_h = $orig_h;
			}
			$img = str_replace( '<img', '<img width="' . $display_w . '" height="' . $display_h . '"', $img );
		}
	}

	return $img;
}

/*--------------------------------------------------------------
 * 4. DOMINANT COLOR PLACEHOLDER
 *--------------------------------------------------------------*/

/**
 * Extract and store the dominant color of the featured image on save.
 *
 * Samples five points (four corners + center) of the image, averages
 * the RGB values, and stores the result as post meta.
 *
 * @param int     $post_id The post ID.
 * @param WP_Post $post    The post object.
 */
function pinlightning_extract_dominant_color( $post_id, $post ) {
	// Skip revisions, autosaves, and non-public post types.
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	$thumbnail_id = get_post_thumbnail_id( $post_id );
	if ( ! $thumbnail_id ) {
		delete_post_meta( $post_id, '_pinlightning_dominant_color' );
		return;
	}

	$file = get_attached_file( $thumbnail_id );
	if ( ! $file || ! file_exists( $file ) ) {
		return;
	}

	$color = pinlightning_calculate_dominant_color( $file );
	if ( $color ) {
		update_post_meta( $post_id, '_pinlightning_dominant_color', sanitize_hex_color( $color ) );
	}
}
add_action( 'save_post', 'pinlightning_extract_dominant_color', 10, 2 );

/**
 * Calculate the dominant color of an image file using GD.
 *
 * Samples five points — four corners and the center — and averages
 * the RGB values to produce a single representative hex color.
 *
 * @param string $file Absolute path to the image file.
 * @return string|false Hex color string (e.g. '#a83251') or false on failure.
 */
function pinlightning_calculate_dominant_color( $file ) {
	if ( ! function_exists( 'imagecreatefromstring' ) ) {
		return false;
	}

	$image_data = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( ! $image_data ) {
		return false;
	}

	$image = @imagecreatefromstring( $image_data );
	if ( ! $image ) {
		return false;
	}

	$width  = imagesx( $image );
	$height = imagesy( $image );

	if ( $width < 1 || $height < 1 ) {
		imagedestroy( $image );
		return false;
	}

	// Sample points: four corners + center.
	$points = array(
		array( 0, 0 ),                              // Top-left.
		array( $width - 1, 0 ),                      // Top-right.
		array( 0, $height - 1 ),                     // Bottom-left.
		array( $width - 1, $height - 1 ),            // Bottom-right.
		array( (int) ( $width / 2 ), (int) ( $height / 2 ) ), // Center.
	);

	$r_total = 0;
	$g_total = 0;
	$b_total = 0;

	foreach ( $points as $point ) {
		$rgb      = imagecolorat( $image, $point[0], $point[1] );
		$r_total += ( $rgb >> 16 ) & 0xFF;
		$g_total += ( $rgb >> 8 ) & 0xFF;
		$b_total += $rgb & 0xFF;
	}

	imagedestroy( $image );

	$count = count( $points );
	$r     = (int) round( $r_total / $count );
	$g     = (int) round( $g_total / $count );
	$b     = (int) round( $b_total / $count );

	return sprintf( '#%02x%02x%02x', $r, $g, $b );
}

/**
 * Get the stored dominant color for a post's featured image.
 *
 * @param int $post_id The post ID. Defaults to the current post.
 * @return string Hex color (e.g. '#a83251') or empty string if not set.
 */
function pinlightning_get_dominant_color( $post_id = 0 ) {
	if ( ! $post_id ) {
		$post_id = get_the_ID();
	}

	$color = get_post_meta( $post_id, '_pinlightning_dominant_color', true );

	return $color ? $color : '';
}

/*--------------------------------------------------------------
 * 5. WEBP SUPPORT
 *--------------------------------------------------------------*/

/**
 * Wrap attachment images in a <picture> element with a WebP <source>
 * when a .webp version of the file exists in the same directory.
 *
 * @param string       $html          The image HTML.
 * @param int          $attachment_id The attachment ID.
 * @param string|int[] $size          Requested image size.
 * @param bool         $icon          Whether it's an icon.
 * @param array        $attr          Image attributes.
 * @return string Modified HTML with <picture> wrapper, or original HTML.
 */
function pinlightning_webp_picture_wrap( $html, $attachment_id, $size, $icon, $attr ) {
	if ( is_admin() || empty( $html ) || $icon ) {
		return $html;
	}

	$image_src = wp_get_attachment_image_url( $attachment_id, $size );
	if ( ! $image_src ) {
		return $html;
	}

	// Build the expected WebP file path.
	$upload_dir = wp_get_upload_dir();
	$base_url   = $upload_dir['baseurl'];
	$base_dir   = $upload_dir['basedir'];

	// Only process images from the uploads directory.
	if ( ! str_starts_with( $image_src, $base_url ) ) {
		return $html;
	}

	$relative_path = substr( $image_src, strlen( $base_url ) );
	$webp_path     = $base_dir . preg_replace( '/\.(jpe?g|png|gif)$/i', '.webp', $relative_path );
	$webp_url      = $base_url . preg_replace( '/\.(jpe?g|png|gif)$/i', '.webp', $relative_path );

	if ( ! file_exists( $webp_path ) ) {
		return $html;
	}

	// Build srcset for the WebP source if the original has srcset.
	$webp_srcset = '';
	$srcset_data = wp_get_attachment_image_srcset( $attachment_id, $size );
	if ( $srcset_data ) {
		// Replace each image extension in the srcset with .webp.
		$webp_srcset_value = preg_replace( '/\.(jpe?g|png|gif)(\s)/i', '.webp$2', $srcset_data );
		$webp_srcset = ' srcset="' . esc_attr( $webp_srcset_value ) . '"';
	} else {
		$webp_srcset = ' srcset="' . esc_url( $webp_url ) . '"';
	}

	$sizes_attr = '';
	$sizes_data = wp_get_attachment_image_sizes( $attachment_id, $size );
	if ( $sizes_data ) {
		$sizes_attr = ' sizes="' . esc_attr( $sizes_data ) . '"';
	}

	return '<picture>'
		. '<source type="image/webp"' . $webp_srcset . $sizes_attr . '>'
		. $html
		. '</picture>';
}
add_filter( 'wp_get_attachment_image', 'pinlightning_webp_picture_wrap', 10, 5 );

/*--------------------------------------------------------------
 * 6. BULK IMAGE DIMENSION CACHE BUILDER
 *--------------------------------------------------------------*/

/**
 * Bulk-cache image dimensions for all published posts.
 *
 * Triggered via: /wp-admin/admin-post.php?action=pinlightning_cache_all_images&token=SECRET
 * Streams progress as plain text for real-time monitoring.
 */
function pinlightning_bulk_cache_images() {
	if ( ! isset( $_GET['token'] ) || ! defined( 'PINLIGHTNING_CACHE_SECRET' ) || $_GET['token'] !== PINLIGHTNING_CACHE_SECRET ) {
		wp_die( 'Unauthorized' );
	}

	set_time_limit( 0 );

	header( 'Content-Type: text/plain; charset=utf-8' );

	$posts = get_posts( array(
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	) );

	$total = count( $posts );
	echo "Starting bulk image dimension cache for $total posts...\n\n";
	if ( ob_get_level() ) {
		ob_flush();
	}
	flush();

	$total_cached = 0;

	foreach ( $posts as $i => $post_id ) {
		$title = get_the_title( $post_id );
		$post  = get_post( $post_id );

		if ( $post ) {
			pinlightning_cache_image_dims_on_save( $post_id, $post );
		}

		$dims  = get_post_meta( $post_id, '_pinlightning_image_dims', true );
		$count = is_array( $dims ) ? count( $dims ) : 0;
		$total_cached += $count;

		$num = $i + 1;
		echo "[$num/$total] $title — cached $count images\n";
		if ( ob_get_level() ) {
			ob_flush();
		}
		flush();
	}

	echo "\nDone! Cached dimensions for $total_cached images across $total posts.\n";
	exit;
}
add_action( 'admin_post_pinlightning_cache_all_images', 'pinlightning_bulk_cache_images' );
