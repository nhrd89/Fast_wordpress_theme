<?php
/**
 * PinLightning CDN — On-demand image resizer with proxy mode.
 *
 * Deploy to: myquickurl.com/public_html/img.php
 *
 * URL format:
 *   img.php?src=cheerfultalks.com/path/image.webp&w=720&q=80
 *
 * Clean URL (via .htaccess):
 *   /img/cheerfultalks.com/path/image.webp?w=720&q=80
 *
 * Source resolution order:
 *   1. Local file: public_html/{src}
 *   2. Mirror cache: img-mirror/{src} (if < 7 days old)
 *   3. Remote fetch: https://{src} (whitelisted domains only)
 *
 * X-Source response header: local | mirror | cache
 *
 * @package PinLightning CDN
 * @since 1.1.0
 */

// ── Configuration ──────────────────────────────────────────────
define( 'IMG_CACHE_DIR', __DIR__ . '/img-cache/' );
define( 'IMG_MIRROR_DIR', __DIR__ . '/img-mirror/' );
define( 'IMG_MAX_WIDTH', 2000 );
define( 'IMG_MAX_HEIGHT', 2000 );
define( 'IMG_DEFAULT_QUALITY', 80 );
define( 'IMG_ALLOWED_EXTENSIONS', array( 'jpg', 'jpeg', 'png', 'webp', 'gif' ) );
define( 'IMG_MIRROR_TTL', 7 * 24 * 3600 ); // 7 days before re-fetch.

// Domains allowed for remote proxy fetching.
$PROXY_DOMAINS = array( 'cheerfultalks.com' );

// ── Helpers ────────────────────────────────────────────────────

/**
 * Serve a 1x1 transparent PNG on error (don't break page layout).
 *
 * @param int    $code    HTTP status code.
 * @param string $message Error description for headers.
 */
function serve_error( $code, $message ) {
	http_response_code( $code );
	header( 'X-Img-Error: ' . $message );
	header( 'Content-Type: image/png' );
	header( 'Cache-Control: no-cache' );
	// 1x1 transparent PNG.
	echo base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVQI12NgAAIABQABNjN9GQAAAAlwSFlzAAAWJQAAFiUBSVIk8AAAAA0lEQVQI12P4z8BQDwAEgAF/QualzQAAAABJRU5ErkJggg==' );
	exit;
}

/**
 * Serve a file with proper caching headers.
 *
 * @param string $path      Path to the file.
 * @param string $mime_type MIME type.
 */
function serve_file( $path, $mime_type ) {
	$mtime = filemtime( $path );
	$etag  = '"' . md5( $path . $mtime ) . '"';
	$size  = filesize( $path );

	// 304 Not Modified — If-None-Match.
	if ( isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) && trim( $_SERVER['HTTP_IF_NONE_MATCH'] ) === $etag ) {
		http_response_code( 304 );
		exit;
	}

	// 304 Not Modified — If-Modified-Since.
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

	// Flush and close.
	if ( function_exists( 'fastcgi_finish_request' ) ) {
		fastcgi_finish_request();
	}
	exit;
}

/**
 * Get MIME type for a file extension.
 *
 * @param string $ext File extension.
 * @return string MIME type.
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
 *
 * @return bool
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
if ( ! in_array( $ext, IMG_ALLOWED_EXTENSIONS, true ) ) {
	serve_error( 400, 'Invalid file extension: ' . $ext );
}

// ── Resolve source file ────────────────────────────────────────

$source_path = dirname( __DIR__ ) . '/' . $src;
$img_source  = 'local';

if ( ! file_exists( $source_path ) ) {
	// Local file not found — try proxy mode.

	// Extract the domain from the src path (first path segment).
	$first_slash = strpos( $src, '/' );
	$src_domain  = ( $first_slash !== false ) ? substr( $src, 0, $first_slash ) : $src;

	if ( ! in_array( $src_domain, $PROXY_DOMAINS, true ) ) {
		serve_error( 404, 'Source not found: ' . $src );
	}

	// Check mirror directory for a previously fetched copy.
	$mirror_path = IMG_MIRROR_DIR . $src;
	$need_fetch  = true;

	if ( file_exists( $mirror_path ) ) {
		$mirror_age = time() - filemtime( $mirror_path );
		if ( $mirror_age < IMG_MIRROR_TTL ) {
			// Mirror is fresh enough — use it.
			$need_fetch = false;
		}
	}

	if ( $need_fetch ) {
		// Fetch from remote origin.
		$remote_url = 'https://' . $src;
		$ctx        = stream_context_create( array(
			'http' => array(
				'timeout'         => 10,
				'follow_location' => 1,
				'max_redirects'   => 3,
				'user_agent'      => 'PinLightning-CDN/1.0',
			),
			'ssl' => array(
				'verify_peer' => false,
			),
		) );

		$remote_data = @file_get_contents( $remote_url, false, $ctx );
		if ( $remote_data === false || empty( $remote_data ) ) {
			serve_error( 404, 'Remote fetch failed: ' . $remote_url );
		}

		// Save to mirror directory.
		$mirror_dir = dirname( $mirror_path );
		if ( ! is_dir( $mirror_dir ) ) {
			mkdir( $mirror_dir, 0755, true );
		}
		file_put_contents( $mirror_path, $remote_data );
	}

	$source_path = $mirror_path;
	$img_source  = 'mirror';
}

// Parse dimensions and quality.
$w = isset( $_GET['w'] ) ? min( (int) $_GET['w'], IMG_MAX_WIDTH ) : 0;
$h = isset( $_GET['h'] ) ? min( (int) $_GET['h'], IMG_MAX_HEIGHT ) : 0;
$q = isset( $_GET['q'] ) ? max( 1, min( 100, (int) $_GET['q'] ) ) : IMG_DEFAULT_QUALITY;

// No resize needed — redirect to original.
if ( $w <= 0 && $h <= 0 ) {
	header( 'X-Source: ' . $img_source );
	header( 'Location: /' . $src, true, 302 );
	exit;
}

// ── Determine output format ────────────────────────────────────

$output_ext = $ext;
if ( $ext !== 'gif' && browser_accepts_webp() ) {
	$output_ext = 'webp';
}
$output_mime = ext_to_mime( $output_ext );

// ── Cache path ─────────────────────────────────────────────────

$filename    = pathinfo( $src, PATHINFO_FILENAME );
$cache_subdir = dirname( $src ); // e.g. cheerfultalks.com/folder
$size_key    = $w . 'x' . $h;
$cache_name  = $filename . '.' . $output_ext;
$cache_path  = IMG_CACHE_DIR . $cache_subdir . '/' . $size_key . '/' . $cache_name;

// Check cache: exists AND newer than source.
if ( file_exists( $cache_path ) && filemtime( $cache_path ) >= filemtime( $source_path ) ) {
	header( 'X-Source: cache' );
	serve_file( $cache_path, $output_mime );
}

// ── Resize ─────────────────────────────────────────────────────

// Temporarily increase memory for large images.
$old_limit = ini_get( 'memory_limit' );
ini_set( 'memory_limit', '256M' );

// Load source image.
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
		// Crop vertically.
		$crop_w = $orig_w;
		$crop_h = (int) round( $target_h * $ratio_w );
	} else {
		// Crop horizontally.
		$crop_w = (int) round( $target_w * $ratio_h );
		$crop_h = $orig_h;
	}

	$crop_x = (int) round( ( $orig_w - $crop_w ) / 2 );
	$crop_y = (int) round( ( $orig_h - $crop_h ) / 2 );
} elseif ( $w > 0 ) {
	// Width only — maintain aspect ratio.
	if ( $w >= $orig_w ) {
		// Don't upscale.
		imagedestroy( $src_img );
		ini_set( 'memory_limit', $old_limit );
		// Serve original at source format.
		serve_file( $source_path, ext_to_mime( $ext ) );
	}
	$target_w = $w;
	$target_h = (int) round( $orig_h * ( $w / $orig_w ) );
	$crop_x   = 0;
	$crop_y   = 0;
	$crop_w   = $orig_w;
	$crop_h   = $orig_h;
} else {
	// Height only — maintain aspect ratio.
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
		// PNG quality is 0-9 (inverted from JPEG).
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
	header( 'X-Source: ' . $img_source );
	serve_file( $cache_path, $output_mime );
} else {
	serve_error( 500, 'Failed to write cache file' );
}
