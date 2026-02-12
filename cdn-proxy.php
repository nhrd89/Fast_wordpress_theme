<?php
/**
 * PinLightning — Same-origin CDN proxy for hero images.
 *
 * Proxies image requests through Hostinger (same-origin) while fetching
 * from myquickurl.com behind the scenes. This eliminates the cross-origin
 * DNS+TCP+TLS penalty (~500ms on Slow 4G) for the LCP hero image.
 *
 * URL format:
 *   cdn-proxy.php?src=cheerfultalks.com/wp-content/uploads/2024/10/img.webp&w=480&q=65
 *
 * The proxy fetches from:
 *   https://myquickurl.com/img.php?src=...&w=480&q=65
 *
 * Cached locally in cdn-cache/ with 1-year immutable headers.
 *
 * @package PinLightning
 * @since 1.2.0
 */

// ── Configuration ──────────────────────────────────────────────
define( 'CDN_CACHE_DIR', __DIR__ . '/cdn-cache/' );
define( 'CDN_BASE_URL', 'https://myquickurl.com/img.php' );
define( 'CDN_FETCH_TIMEOUT', 10 );

// ── Helpers ────────────────────────────────────────────────────

/**
 * Serve a 1x1 transparent PNG on error.
 */
function cdn_serve_error( $code, $message ) {
	http_response_code( $code );
	header( 'X-Error: ' . $message );
	header( 'Content-Type: image/png' );
	header( 'Cache-Control: no-cache' );
	echo base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVQI12NgAAIABQABNjN9GQAAAAlwSFlzAAAWJQAAFiUBSVIk8AAAAA0lEQVQI12P4z8BQDwAEgAF/QualzQAAAABJRU5ErkJggg==' );
	exit;
}

/**
 * Map extension to MIME type.
 */
function cdn_ext_to_mime( $ext ) {
	$map = array(
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'webp' => 'image/webp',
		'gif'  => 'image/gif',
	);
	return isset( $map[ $ext ] ) ? $map[ $ext ] : 'image/webp';
}

// ── Validate input ─────────────────────────────────────────────

$src = isset( $_GET['src'] ) ? trim( $_GET['src'] ) : '';

if ( empty( $src ) ) {
	cdn_serve_error( 400, 'Missing src parameter' );
}

// Security: block path traversal and absolute paths.
if ( strpos( $src, '..' ) !== false || $src[0] === '/' ) {
	cdn_serve_error( 403, 'Path traversal blocked' );
}

// Validate extension.
$ext = strtolower( pathinfo( $src, PATHINFO_EXTENSION ) );
$allowed = array( 'jpg', 'jpeg', 'png', 'webp', 'gif' );
if ( ! in_array( $ext, $allowed, true ) ) {
	cdn_serve_error( 400, 'Invalid file extension' );
}

// Parse width and quality.
$w = isset( $_GET['w'] ) ? max( 0, min( 2000, (int) $_GET['w'] ) ) : 0;
$q = isset( $_GET['q'] ) ? max( 1, min( 100, (int) $_GET['q'] ) ) : 80;

// ── Build cache key ────────────────────────────────────────────

$cache_key  = $w . 'x0_q' . $q . '/' . $src;
$cache_path = CDN_CACHE_DIR . $cache_key;

// ── Serve from cache if available ──────────────────────────────

if ( file_exists( $cache_path ) ) {
	$mime  = cdn_ext_to_mime( $ext );
	$mtime = filemtime( $cache_path );
	$etag  = '"' . md5( $cache_path . $mtime ) . '"';
	$size  = filesize( $cache_path );

	if ( isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) && trim( $_SERVER['HTTP_IF_NONE_MATCH'] ) === $etag ) {
		http_response_code( 304 );
		exit;
	}

	header( 'Content-Type: ' . $mime );
	header( 'Content-Length: ' . $size );
	header( 'Cache-Control: public, max-age=31536000, immutable' );
	header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT' );
	header( 'ETag: ' . $etag );
	header( 'X-Source: cdn-proxy-cache' );

	readfile( $cache_path );

	if ( function_exists( 'fastcgi_finish_request' ) ) {
		fastcgi_finish_request();
	}
	exit;
}

// ── Fetch from CDN ─────────────────────────────────────────────

$query = array( 'src' => $src );
if ( $w > 0 ) {
	$query['w'] = $w;
}
$query['q'] = $q;

$cdn_url = CDN_BASE_URL . '?' . http_build_query( $query );

$ch = curl_init( $cdn_url );
curl_setopt_array( $ch, array(
	CURLOPT_RETURNTRANSFER => true,
	CURLOPT_FOLLOWLOCATION => true,
	CURLOPT_TIMEOUT        => CDN_FETCH_TIMEOUT,
	CURLOPT_CONNECTTIMEOUT => 5,
	CURLOPT_HTTPHEADER     => array( 'Accept: image/webp,image/*,*/*;q=0.8' ),
	CURLOPT_USERAGENT      => 'PinLightning-CDN-Proxy/1.0',
) );

$body = curl_exec( $ch );
$http_code    = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
$content_type = curl_getinfo( $ch, CURLINFO_CONTENT_TYPE );
$curl_error   = curl_error( $ch );
curl_close( $ch );

if ( $body === false || $http_code !== 200 ) {
	cdn_serve_error( 502, 'CDN fetch failed: HTTP ' . $http_code . ' ' . $curl_error );
}

// Validate we got an image.
if ( strlen( $body ) < 100 || strpos( $content_type, 'image/' ) !== 0 ) {
	cdn_serve_error( 502, 'CDN returned non-image: ' . $content_type );
}

// ── Save to cache ──────────────────────────────────────────────

$cache_dir = dirname( $cache_path );
if ( ! is_dir( $cache_dir ) ) {
	mkdir( $cache_dir, 0755, true );
}

file_put_contents( $cache_path, $body );

// ── Serve the response ─────────────────────────────────────────

$mime  = $content_type ?: cdn_ext_to_mime( $ext );
$mtime = time();
$etag  = '"' . md5( $cache_path . $mtime ) . '"';

header( 'Content-Type: ' . $mime );
header( 'Content-Length: ' . strlen( $body ) );
header( 'Cache-Control: public, max-age=31536000, immutable' );
header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT' );
header( 'ETag: ' . $etag );
header( 'X-Source: cdn-proxy' );

echo $body;

if ( function_exists( 'fastcgi_finish_request' ) ) {
	fastcgi_finish_request();
}
