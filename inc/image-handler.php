<?php
/**
 * PinLightning image handler.
 *
 * 1. Lazy loading with LCP-aware eager first image
 * 2. Responsive image sizes for fashion content
 * 3. Pinterest data attributes
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
