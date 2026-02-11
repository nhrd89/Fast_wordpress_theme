<?php
/**
 * PinLightning — Local featured image resizer.
 *
 * Standalone PHP file (no WordPress loaded) for fast image resizing.
 *
 * URL format:
 *   img-resize.php?src=2024/10/image-1024x536.webp&w=720&q=80
 *
 * src = path relative to wp-content/uploads/
 * w   = target width (preserves aspect ratio)
 * h   = target height (optional; if both w+h, center-crops)
 * q   = quality 1-100 (default 80)
 *
 * @package PinLightning
 * @since 1.1.0
 */

// ── Configuration ──────────────────────────────────────────────
define( 'UPLOADS_DIR', dirname( dirname( __DIR__ ) ) . '/uploads/' );
define( 'CACHE_DIR', __DIR__ . '/img-cache/' );
define( 'MAX_WIDTH', 2000 );
define( 'MAX_HEIGHT', 2000 );
define( 'DEFAULT_QUALITY', 80 );
define( 'ALLOWED_EXTENSIONS', array( 'jpg', 'jpeg', 'png', 'webp', 'gif' ) );

// ── Helpers ────────────────────────────────────────────────────

/**
 * Serve a 1x1 transparent PNG on error.
 */
function serve_error( $code, $message ) {
	http_response_code( $code );
	header( 'X-Img-Error: ' . $message );
	header( 'Content-Type: image/png' );
	header( 'Cache-Control: no-cache' );
	echo base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVQI12NgAAIABQABNjN9GQAAAAlwSFlzAAAWJQAAFiUBSVIk8AAAAA0lEQVQI12P4z8BQDwAEgAF/QualzQAAAABJRU5ErkJggg==' );
	exit;
}

/**
 * Serve a file with caching headers + ETag / 304 support.
 */
function serve_file( $path, $mime_type ) {
	$mtime = filemtime( $path );
	$etag  = '"' . md5( $path . $mtime ) . '"';
	$size  = filesize( $path );

	if ( isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) && trim( $_SERVER['HTTP_IF_NONE_MATCH'] ) === $etag ) {
		http_response_code( 304 );
		exit;
	}

	if ( isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) {
		$since = strtotime( $_SERVER['HTTP_IF_MODIFIED_SINCE'] );
		if ( $since !== false && $since >= $mtime ) {
			http_response_code( 304 );
			exit;
		}
	}

	header( 'Content-Type: ' . $mime_type );
	header( 'Content-Length: ' . $size );
	header( 'Cache-Control: public, max-age=31536000, immutable' );
	header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT' );
	header( 'ETag: ' . $etag );
	header( 'Vary: Accept' );

	readfile( $path );

	if ( function_exists( 'fastcgi_finish_request' ) ) {
		fastcgi_finish_request();
	}
	exit;
}

/**
 * Map file extension to MIME type.
 */
function ext_to_mime( $ext ) {
	$map = array(
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'webp' => 'image/webp',
		'gif'  => 'image/gif',
	);
	return isset( $map[ $ext ] ) ? $map[ $ext ] : 'application/octet-stream';
}

/**
 * Check if the browser supports WebP.
 */
function browser_accepts_webp() {
	return isset( $_SERVER['HTTP_ACCEPT'] ) && strpos( $_SERVER['HTTP_ACCEPT'], 'image/webp' ) !== false;
}

// ── Validate input ─────────────────────────────────────────────

$src = isset( $_GET['src'] ) ? trim( $_GET['src'] ) : '';

if ( empty( $src ) ) {
	serve_error( 400, 'Missing src parameter' );
}

// Security: block path traversal.
if ( strpos( $src, '..' ) !== false || $src[0] === '/' ) {
	serve_error( 403, 'Path traversal blocked' );
}

// Validate extension.
$ext = strtolower( pathinfo( $src, PATHINFO_EXTENSION ) );
if ( ! in_array( $ext, ALLOWED_EXTENSIONS, true ) ) {
	serve_error( 400, 'Invalid file extension: ' . $ext );
}

// ── Resolve source file ────────────────────────────────────────

$source_path = UPLOADS_DIR . $src;
if ( ! file_exists( $source_path ) ) {
	serve_error( 404, 'Source not found: ' . $src );
}

// Parse dimensions and quality.
$w = isset( $_GET['w'] ) ? min( (int) $_GET['w'], MAX_WIDTH ) : 0;
$h = isset( $_GET['h'] ) ? min( (int) $_GET['h'], MAX_HEIGHT ) : 0;
$q = isset( $_GET['q'] ) ? max( 1, min( 100, (int) $_GET['q'] ) ) : DEFAULT_QUALITY;

// No resize requested — serve original.
if ( $w <= 0 && $h <= 0 ) {
	serve_file( $source_path, ext_to_mime( $ext ) );
}

// ── Determine output format ────────────────────────────────────

$output_ext = $ext;
if ( $ext !== 'gif' && browser_accepts_webp() ) {
	$output_ext = 'webp';
}
$output_mime = ext_to_mime( $output_ext );

// ── Cache path ─────────────────────────────────────────────────

$filename   = pathinfo( $src, PATHINFO_FILENAME );
$cache_sub  = dirname( $src ); // e.g. 2024/10
$size_key   = $w . 'x' . $h . '_q' . $q;
$cache_name = $filename . '.' . $output_ext;
$cache_path = CACHE_DIR . $size_key . '/' . $cache_sub . '/' . $cache_name;

// Check cache: exists AND newer than source.
if ( file_exists( $cache_path ) && filemtime( $cache_path ) >= filemtime( $source_path ) ) {
	serve_file( $cache_path, $output_mime );
}

// ── Resize ─────────────────────────────────────────────────────

$old_limit = ini_get( 'memory_limit' );
ini_set( 'memory_limit', '256M' );

switch ( $ext ) {
	case 'jpg':
	case 'jpeg':
		$src_img = @imagecreatefromjpeg( $source_path );
		break;
	case 'png':
		$src_img = @imagecreatefrompng( $source_path );
		break;
	case 'webp':
		$src_img = @imagecreatefromwebp( $source_path );
		break;
	case 'gif':
		$src_img = @imagecreatefromgif( $source_path );
		break;
	default:
		$src_img = false;
}

if ( ! $src_img ) {
	ini_set( 'memory_limit', $old_limit );
	serve_error( 500, 'Failed to load source image' );
}

$orig_w = imagesx( $src_img );
$orig_h = imagesy( $src_img );

// Calculate target dimensions.
if ( $w > 0 && $h > 0 ) {
	// Both specified — center crop to fit.
	$target_w = $w;
	$target_h = $h;

	$ratio_w = $orig_w / $target_w;
	$ratio_h = $orig_h / $target_h;

	if ( $ratio_w < $ratio_h ) {
		$crop_w = $orig_w;
		$crop_h = (int) round( $target_h * $ratio_w );
	} else {
		$crop_w = (int) round( $target_w * $ratio_h );
		$crop_h = $orig_h;
	}

	$crop_x = (int) round( ( $orig_w - $crop_w ) / 2 );
	$crop_y = (int) round( ( $orig_h - $crop_h ) / 2 );
} elseif ( $w > 0 ) {
	// Width only — preserve aspect ratio.
	if ( $w >= $orig_w ) {
		// Don't upscale — serve original.
		imagedestroy( $src_img );
		ini_set( 'memory_limit', $old_limit );
		serve_file( $source_path, ext_to_mime( $ext ) );
	}
	$target_w = $w;
	$target_h = (int) round( $orig_h * ( $w / $orig_w ) );
	$crop_x   = 0;
	$crop_y   = 0;
	$crop_w   = $orig_w;
	$crop_h   = $orig_h;
} else {
	// Height only — preserve aspect ratio.
	if ( $h >= $orig_h ) {
		imagedestroy( $src_img );
		ini_set( 'memory_limit', $old_limit );
		serve_file( $source_path, ext_to_mime( $ext ) );
	}
	$target_h = $h;
	$target_w = (int) round( $orig_w * ( $h / $orig_h ) );
	$crop_x   = 0;
	$crop_y   = 0;
	$crop_w   = $orig_w;
	$crop_h   = $orig_h;
}

// Create destination image.
$dst_img = imagecreatetruecolor( $target_w, $target_h );

// Preserve transparency for PNG and GIF.
if ( in_array( $output_ext, array( 'png', 'gif' ), true ) ) {
	imagealphablending( $dst_img, false );
	imagesavealpha( $dst_img, true );
	$transparent = imagecolorallocatealpha( $dst_img, 0, 0, 0, 127 );
	imagefill( $dst_img, 0, 0, $transparent );
}

// Resample.
imagecopyresampled( $dst_img, $src_img, 0, 0, $crop_x, $crop_y, $target_w, $target_h, $crop_w, $crop_h );
imagedestroy( $src_img );

// Subtle sharpen after resize.
$sharpen_matrix = array(
	array( 0, -1, 0 ),
	array( -1, 5, -1 ),
	array( 0, -1, 0 ),
);
imageconvolution( $dst_img, $sharpen_matrix, 1, 0 );

// ── Save to cache ──────────────────────────────────────────────

$cache_dir = dirname( $cache_path );
if ( ! is_dir( $cache_dir ) ) {
	mkdir( $cache_dir, 0755, true );
}

switch ( $output_ext ) {
	case 'jpg':
	case 'jpeg':
		imagejpeg( $dst_img, $cache_path, $q );
		break;
	case 'png':
		$png_quality = (int) round( ( 100 - $q ) * 9 / 100 );
		imagepng( $dst_img, $cache_path, $png_quality );
		break;
	case 'webp':
		imagewebp( $dst_img, $cache_path, $q );
		break;
	case 'gif':
		imagegif( $dst_img, $cache_path );
		break;
}

imagedestroy( $dst_img );
ini_set( 'memory_limit', $old_limit );

// ── Serve the cached file ──────────────────────────────────────

if ( file_exists( $cache_path ) ) {
	serve_file( $cache_path, $output_mime );
} else {
	serve_error( 500, 'Failed to write cache file' );
}
