<?php
/**
 * PinLightning CDN — Cache purge utility.
 *
 * Deploy to: myquickurl.com/public_html/purge.php
 *
 * Usage:
 *   purge.php?token=SECRET&domain=cheerfultalks.com          Purge all cache for a domain
 *   purge.php?token=SECRET&domain=cheerfultalks.com&path=folder/image.webp  Purge specific image
 *   purge.php?token=SECRET&all=1                              Purge everything
 *
 * @package PinLightning CDN
 * @since 1.0.0
 */

// ── Configuration ──────────────────────────────────────────────
// Change this secret before deploying!
$SECRET = 'pl_cdn_purge_2024_s3cur3';

define( 'IMG_CACHE_DIR', __DIR__ . '/img-cache/' );

// ── Auth ───────────────────────────────────────────────────────

header( 'Content-Type: application/json' );

$token = isset( $_GET['token'] ) ? $_GET['token'] : '';
if ( $token !== $SECRET ) {
	http_response_code( 403 );
	echo json_encode( array( 'error' => 'Invalid token' ) );
	exit;
}

// ── Helpers ────────────────────────────────────────────────────

/**
 * Recursively delete a directory and count files/bytes removed.
 *
 * @param string $dir Path to directory.
 * @return array [ 'files' => int, 'bytes' => int ]
 */
function purge_directory( $dir ) {
	$stats = array( 'files' => 0, 'bytes' => 0 );

	if ( ! is_dir( $dir ) ) {
		return $stats;
	}

	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $items as $item ) {
		if ( $item->isFile() ) {
			$stats['bytes'] += $item->getSize();
			$stats['files']++;
			unlink( $item->getPathname() );
		} elseif ( $item->isDir() ) {
			rmdir( $item->getPathname() );
		}
	}

	// Remove the top-level directory itself.
	if ( is_dir( $dir ) ) {
		rmdir( $dir );
	}

	return $stats;
}

/**
 * Purge cache for a specific image across all size variants.
 *
 * @param string $domain Domain subfolder (e.g. cheerfultalks.com).
 * @param string $path   Relative path within domain (e.g. folder/image.webp).
 * @return array [ 'files' => int, 'bytes' => int ]
 */
function purge_image( $domain, $path ) {
	$stats    = array( 'files' => 0, 'bytes' => 0 );
	$filename = pathinfo( $path, PATHINFO_FILENAME );
	$subdir   = dirname( $path );
	$base_dir = IMG_CACHE_DIR . $domain . '/' . ( $subdir !== '.' ? $subdir . '/' : '' );

	if ( ! is_dir( $base_dir ) ) {
		return $stats;
	}

	// Iterate over all size variant directories.
	$dirs = new DirectoryIterator( $base_dir );
	foreach ( $dirs as $dir ) {
		if ( ! $dir->isDir() || $dir->isDot() ) {
			continue;
		}

		// Look for files matching the image filename in any format.
		$pattern = $dir->getPathname() . '/' . $filename . '.*';
		$files   = glob( $pattern );
		if ( $files ) {
			foreach ( $files as $file ) {
				$stats['bytes'] += filesize( $file );
				$stats['files']++;
				unlink( $file );
			}
		}

		// Clean up empty directory.
		$remaining = glob( $dir->getPathname() . '/*' );
		if ( empty( $remaining ) ) {
			rmdir( $dir->getPathname() );
		}
	}

	return $stats;
}

// ── Route ──────────────────────────────────────────────────────

$purge_all = isset( $_GET['all'] ) && $_GET['all'] === '1';
$domain    = isset( $_GET['domain'] ) ? trim( $_GET['domain'] ) : '';
$path      = isset( $_GET['path'] ) ? trim( $_GET['path'] ) : '';

// Security: block path traversal.
if ( strpos( $domain, '..' ) !== false || strpos( $path, '..' ) !== false ) {
	http_response_code( 403 );
	echo json_encode( array( 'error' => 'Path traversal blocked' ) );
	exit;
}

$result = array(
	'action'    => '',
	'files'     => 0,
	'bytes'     => 0,
	'human_size' => '',
);

if ( $purge_all ) {
	// Purge everything.
	$result['action'] = 'purge_all';
	$stats = purge_directory( IMG_CACHE_DIR );
	$result['files'] = $stats['files'];
	$result['bytes'] = $stats['bytes'];
} elseif ( $domain && $path ) {
	// Purge specific image.
	$result['action'] = 'purge_image';
	$result['target'] = $domain . '/' . $path;
	$stats = purge_image( $domain, $path );
	$result['files'] = $stats['files'];
	$result['bytes'] = $stats['bytes'];
} elseif ( $domain ) {
	// Purge all cache for a domain.
	$result['action'] = 'purge_domain';
	$result['target'] = $domain;
	$dir = IMG_CACHE_DIR . $domain;
	$stats = purge_directory( $dir );
	$result['files'] = $stats['files'];
	$result['bytes'] = $stats['bytes'];
} else {
	http_response_code( 400 );
	echo json_encode( array( 'error' => 'Specify domain, domain+path, or all=1' ) );
	exit;
}

// Human-readable size.
$bytes = $result['bytes'];
if ( $bytes >= 1048576 ) {
	$result['human_size'] = round( $bytes / 1048576, 2 ) . ' MB';
} elseif ( $bytes >= 1024 ) {
	$result['human_size'] = round( $bytes / 1024, 2 ) . ' KB';
} else {
	$result['human_size'] = $bytes . ' bytes';
}

echo json_encode( $result, JSON_PRETTY_PRINT );
