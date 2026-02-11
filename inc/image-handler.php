<?php
/**
 * PinLightning image handler.
 *
 * 1. Lazy loading with LCP-aware eager first image
 * 2. Responsive image sizes for fashion content
 * 3. Pinterest data attributes
 * 3a. Featured image local resizer (post_thumbnail_html → img-resize.php)
 * 3b. CDN content image rewriting (the_content → myquickurl.com resizer)
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

/*--------------------------------------------------------------
 * 3a. FEATURED IMAGE LOCAL RESIZER
 *
 * Rewrites featured image src to go through the theme's local
 * img-resize.php (no cross-server dependency on myquickurl.com).
 *--------------------------------------------------------------*/

/**
 * Rewrite featured image to use the local resizer with srcset.
 *
 * Runs at priority 20 on post_thumbnail_html (after Pinterest attrs at 10).
 *
 * @param string       $html              The post thumbnail HTML.
 * @param int          $post_id           The post ID.
 * @param int          $post_thumbnail_id The post thumbnail attachment ID.
 * @param string|int[] $size              Requested image size.
 * @param string       $attr              Additional attributes.
 * @return string Modified HTML.
 */
function pinlightning_rewrite_featured_image_cdn( $html, $post_id, $post_thumbnail_id, $size, $attr ) {
	if ( empty( $html ) || is_admin() ) {
		return $html;
	}

	// Extract just the <img> tag (strip <picture>/<source> wrapper if present).
	// img-resize.php serves WebP automatically via Accept header, so <picture> is redundant.
	if ( ! preg_match( '/<img\b[^>]*>/i', $html, $img_match ) ) {
		return $html;
	}
	$img = $img_match[0];

	// Extract src.
	if ( ! preg_match( '/\bsrc=["\']([^"\']+)["\']/i', $img, $src_match ) ) {
		return $html;
	}

	$original_src = $src_match[1];

	// Only process images from wp-content/uploads/.
	if ( strpos( $original_src, '/wp-content/uploads/' ) === false ) {
		return $html;
	}

	// Skip if already rewritten.
	if ( strpos( $original_src, 'img-resize.php' ) !== false ) {
		return $html;
	}

	// Extract the uploads-relative path (e.g. 2024/10/image-1024x536.webp).
	if ( ! preg_match( '#/wp-content/uploads/(.+?)(?:\?|$)#', $original_src, $path_match ) ) {
		return $html;
	}

	$uploads_path = $path_match[1];
	$base_url     = PINLIGHTNING_URI . '/img-resize.php?src=' . rawurlencode( $uploads_path );

	// Build resized src (720px default).
	$new_src = $base_url . '&w=720&q=80';

	// Build srcset — 400w and 720w only (no 1024w to prevent high-DPR overfetch).
	$srcset = implode( ', ', array(
		$base_url . '&w=400&q=80 400w',
		$base_url . '&w=720&q=80 720w',
	) );

	// Calculate display dimensions (720px max content width).
	$display_w = 720;
	$display_h = 377; // fallback
	if ( preg_match( '/\bwidth=["\'](\d+)["\']/i', $img, $wm ) && preg_match( '/\bheight=["\'](\d+)["\']/i', $img, $hm ) ) {
		$orig_w = (int) $wm[1];
		$orig_h = (int) $hm[1];
		if ( $orig_w > 0 ) {
			$display_h = (int) round( $orig_h * $display_w / $orig_w );
		}
	}

	// Replace src.
	$img = preg_replace( '/\bsrc=["\'][^"\']+["\']/i', 'src="' . esc_url( $new_src ) . '"', $img );

	// Replace width/height with display dimensions.
	$img = preg_replace( '/\bwidth=["\']\d+["\']/i', 'width="' . $display_w . '"', $img );
	$img = preg_replace( '/\bheight=["\']\d+["\']/i', 'height="' . $display_h . '"', $img );

	// Strip WP-generated srcset/sizes (pointing to origin).
	$img = preg_replace( '/\bsrcset="[^"]*"/i', '', $img );
	$img = preg_replace( '/\bsizes="[^"]*"/i', '', $img );

	// Add resizer srcset and sizes.
	$img = str_replace( '<img', '<img srcset="' . esc_attr( $srcset ) . '" sizes="(max-width: 720px) 100vw, 720px"', $img );

	// Add inline aspect-ratio on the img element for CLS prevention.
	// This reserves vertical space before the image loads, avoiding layout shift.
	// Placed on the img (not the container) to avoid box-sizing/padding conflicts.
	$img = str_replace( '<img', '<img style="aspect-ratio:' . $display_w . '/' . $display_h . '"', $img );

	// Keep original full-size URL as data-pin-media.
	if ( strpos( $img, 'data-pin-media' ) === false ) {
		$img = str_replace( '<img', '<img data-pin-media="' . esc_url( $original_src ) . '"', $img );
	}

	// Return clean <img> without <picture> wrapper.
	return $img;
}
add_filter( 'post_thumbnail_html', 'pinlightning_rewrite_featured_image_cdn', 20, 5 );

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
 * 3b. CDN IMAGE REWRITING (myquickurl.com resizer)
 *
 * All external CDN images from myquickurl.com are 1080x1920.
 * Dimensions are hardcoded — no per-post caching or remote
 * lookups needed. Display width is 720px (article max-width),
 * so display height = round(1920 * 720 / 1080) = 1280.
 *--------------------------------------------------------------*/

// CDN image originals: 1080x1920 (9:16 portrait).
define( 'PINLIGHTNING_CDN_ORIG_W', 1080 );
define( 'PINLIGHTNING_CDN_ORIG_H', 1920 );
define( 'PINLIGHTNING_CDN_DISPLAY_W', 720 );
define( 'PINLIGHTNING_CDN_DISPLAY_H', (int) round( 1920 * 720 / 1080 ) ); // 1280

/**
 * Rewrite myquickurl.com images to use the on-demand resizer with srcset.
 *
 * Runs at priority 25 (after Pinterest attrs at 20).
 * Generates resized src + 3-width srcset through the img.php endpoint.
 * Adds hardcoded width/height based on known 1080x1920 originals.
 *
 * @param string $content The post content.
 * @return string Modified content.
 */
function pinlightning_rewrite_cdn_images( $content ) {
	if ( empty( $content ) || is_admin() || ! is_singular() ) {
		return $content;
	}

	return preg_replace_callback(
		'/<img\b([^>]*)>/i',
		'pinlightning_rewrite_cdn_img',
		$content
	);
}
add_filter( 'the_content', 'pinlightning_rewrite_cdn_images', 25 );

/**
 * Rewrite a single CDN image tag.
 *
 * @param array $matches Regex matches.
 * @return string Modified <img> tag.
 */
function pinlightning_rewrite_cdn_img( $matches ) {
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
		$cdn_path = urldecode( $m[1] );
	} elseif ( preg_match( '/myquickurl\.com\/img\/(.+?)(?:\?|$)/', $original_src, $m ) ) {
		$cdn_path = $m[1];
	} elseif ( preg_match( '/myquickurl\.com\/(.+?)(?:\?|$)/', $original_src, $m ) ) {
		$cdn_path = $m[1];
	}

	if ( empty( $cdn_path ) ) {
		return $img;
	}

	$cdn_path_encoded = rawurlencode( $cdn_path );
	$cdn_path_encoded = str_replace( '%2F', '/', $cdn_path_encoded );

	$base_url = 'https://myquickurl.com/img.php?src=' . $cdn_path_encoded;

	// Build resized src (720px for article width).
	$new_src = $base_url . '&w=720&q=80';

	// Build srcset with 3 widths (capped at 720 to avoid full-size on high-DPR mobile).
	$srcset = implode( ', ', array(
		$base_url . '&w=360&q=80 360w',
		$base_url . '&w=480&q=80 480w',
		$base_url . '&w=720&q=80 720w',
	) );

	// Replace src.
	$img = preg_replace(
		'/\bsrc=["\'][^"\']+["\']/i',
		'src="' . esc_url( $new_src ) . '"',
		$img
	);

	// Add srcset and sizes.
	$img = str_replace( '<img', '<img srcset="' . esc_attr( $srcset ) . '" sizes="(max-width: 480px) 100vw, (max-width: 720px) 100vw, 720px"', $img );

	// Preserve original full-size URL as data-pin-media for Pinterest.
	$original_full = 'https://myquickurl.com/' . $cdn_path;
	if ( strpos( $img, 'data-pin-media' ) === false ) {
		$img = str_replace( '<img', '<img data-pin-media="' . esc_url( $original_full ) . '"', $img );
	}

	// Hardcoded dimensions: all CDN images are 1080x1920, displayed at 720px width.
	if ( ! preg_match( '/\bwidth\s*=/', $img ) ) {
		$img = str_replace( '<img', '<img width="' . PINLIGHTNING_CDN_DISPLAY_W . '" height="' . PINLIGHTNING_CDN_DISPLAY_H . '"', $img );
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

	// Extract width/height from the <img> tag for the <source> element (CLS prevention).
	$dim_attrs = '';
	$img_meta  = wp_get_attachment_image_src( $attachment_id, $size );
	if ( $img_meta && ! empty( $img_meta[1] ) && ! empty( $img_meta[2] ) ) {
		$dim_attrs = ' width="' . (int) $img_meta[1] . '" height="' . (int) $img_meta[2] . '"';
	}

	return '<picture>'
		. '<source type="image/webp"' . $webp_srcset . $sizes_attr . $dim_attrs . '>'
		. $html
		. '</picture>';
}
add_filter( 'wp_get_attachment_image', 'pinlightning_webp_picture_wrap', 10, 5 );

