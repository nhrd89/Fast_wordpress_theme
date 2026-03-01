<?php
/**
 * PL Affiliate Router
 *
 * Lightweight affiliate redirect with analytics.
 * Only activates on cheerlives.com — handles /go/ routes and
 * tracks every redirect with full analytics.
 *
 * @package PinLightning
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Only run on cheerlives.com.
$pl_aff_host = parse_url( home_url(), PHP_URL_HOST );
if ( $pl_aff_host !== 'cheerlives.com' && $pl_aff_host !== 'www.cheerlives.com' ) {
	return;
}

/* ================================================================
 * DATABASE TABLE
 * ================================================================ */

add_action( 'init', 'pl_aff_router_maybe_create_table' );

function pl_aff_router_maybe_create_table() {
	if ( get_option( 'pl_aff_router_db_version' ) === '1.0' ) {
		return;
	}

	global $wpdb;
	$table   = $wpdb->prefix . 'pl_affiliate_redirects';
	$charset = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE IF NOT EXISTS $table (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		slug varchar(50) NOT NULL,
		source_site varchar(100) NOT NULL DEFAULT 'direct',
		variant varchar(10) DEFAULT '',
		device varchar(20) DEFAULT '',
		ip_hash varchar(64) DEFAULT '',
		user_agent varchar(255) DEFAULT '',
		referer varchar(500) DEFAULT '',
		country varchar(5) DEFAULT '',
		created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		KEY idx_slug_date (slug, created_at),
		KEY idx_source_date (source_site, created_at)
	) $charset;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
	update_option( 'pl_aff_router_db_version', '1.0' );
}

/* ================================================================
 * REDIRECT HANDLER
 * ================================================================ */

add_action( 'init', 'pl_affiliate_router_early', 1 );

function pl_affiliate_router_early() {
	$path = trim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );
	if ( strpos( $path, 'go/' ) !== 0 ) {
		return;
	}

	$slug = sanitize_text_field( str_replace( 'go/', '', $path ) );

	$routes = array(
		'skillshare' => 'https://fxo.co/1522574/social',
	);

	if ( ! isset( $routes[ $slug ] ) ) {
		return;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'pl_affiliate_redirects';

	// Collect analytics data.
	$source  = isset( $_GET['src'] ) ? sanitize_text_field( $_GET['src'] ) : 'cheerlives';
	$variant = isset( $_GET['v'] ) ? sanitize_text_field( $_GET['v'] ) : '';
	$ua      = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '';
	$referer = isset( $_SERVER['HTTP_REFERER'] ) ? $_SERVER['HTTP_REFERER'] : '';

	// Detect device from UA.
	$device = 'desktop';
	if ( preg_match( '/Mobile|Android|iPhone/i', $ua ) ) {
		$device = 'mobile';
	} elseif ( preg_match( '/Tablet|iPad/i', $ua ) ) {
		$device = 'tablet';
	}

	// Hash IP for privacy (don't store raw IP).
	$ip_hash = hash( 'sha256', $_SERVER['REMOTE_ADDR'] . 'pl_salt_2026' );

	// Insert redirect log.
	$wpdb->insert( $table, array(
		'slug'        => $slug,
		'source_site' => $source,
		'variant'     => $variant,
		'device'      => $device,
		'ip_hash'     => substr( $ip_hash, 0, 16 ),
		'user_agent'  => substr( $ua, 0, 255 ),
		'referer'     => substr( $referer, 0, 500 ),
		'created_at'  => current_time( 'mysql' ),
	) );

	// 302 redirect with no-referrer so FlexOffers doesn't see source.
	header( 'HTTP/1.1 302 Found' );
	header( 'Location: ' . $routes[ $slug ] );
	header( 'Cache-Control: no-cache, no-store, must-revalidate' );
	header( 'Referrer-Policy: no-referrer' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	exit;
}

/* ================================================================
 * ADMIN PAGE: Router Analytics
 * ================================================================ */

add_action( 'admin_menu', 'pl_aff_router_admin_menu' );

function pl_aff_router_admin_menu() {
	add_submenu_page(
		'pl-ad-engine',
		'Affiliate Router',
		'Affiliate Router',
		'manage_options',
		'pl-affiliate-router',
		'pl_aff_router_admin_page'
	);
}

function pl_aff_router_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'pl_affiliate_redirects';

	// Handle clear data.
	if ( isset( $_POST['pl_aff_clear_data'] ) && check_admin_referer( 'pl_aff_clear_nonce' ) ) {
		$wpdb->query( "TRUNCATE TABLE $table" );
		echo '<div class="notice notice-success is-dismissible"><p>Affiliate router data cleared.</p></div>';
	}

	// Date range filter.
	$range = isset( $_GET['range'] ) ? sanitize_text_field( $_GET['range'] ) : '7d';
	$ranges = array(
		'1h'  => '1 HOUR',
		'6h'  => '6 HOUR',
		'24h' => '24 HOUR',
		'7d'  => '7 DAY',
		'30d' => '30 DAY',
		'all' => '365 DAY',
	);
	$interval = isset( $ranges[ $range ] ) ? $ranges[ $range ] : '7 DAY';
	$since    = "DATE_SUB(NOW(), INTERVAL $interval)";

	// Overview stats.
	$overview = $wpdb->get_row(
		"SELECT COUNT(*) as total_redirects, COUNT(DISTINCT ip_hash) as unique_visitors, COUNT(DISTINCT source_site) as sources FROM $table WHERE created_at >= $since"
	);

	// By source site.
	$by_source = $wpdb->get_results(
		"SELECT source_site, COUNT(*) as redirects, COUNT(DISTINCT ip_hash) as unique_visitors, COUNT(DISTINCT DATE(created_at)) as active_days FROM $table WHERE created_at >= $since GROUP BY source_site ORDER BY redirects DESC"
	);

	// By variant.
	$by_variant = $wpdb->get_results(
		"SELECT variant, source_site, COUNT(*) as redirects FROM $table WHERE created_at >= $since AND variant != '' GROUP BY variant, source_site ORDER BY redirects DESC"
	);

	// By device.
	$by_device = $wpdb->get_results(
		"SELECT device, COUNT(*) as redirects, COUNT(DISTINCT ip_hash) as unique_visitors FROM $table WHERE created_at >= $since GROUP BY device ORDER BY redirects DESC"
	);

	// By hour (for trend).
	$by_hour = $wpdb->get_results(
		"SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:00') as hour, COUNT(*) as redirects FROM $table WHERE created_at >= $since GROUP BY hour ORDER BY hour ASC"
	);

	// Daily breakdown.
	$by_day = $wpdb->get_results(
		"SELECT DATE(created_at) as date, source_site, COUNT(*) as redirects, COUNT(DISTINCT ip_hash) as unique_visitors FROM $table WHERE created_at >= $since GROUP BY date, source_site ORDER BY date DESC"
	);

	// By slug.
	$by_slug = $wpdb->get_results(
		"SELECT slug, COUNT(*) as redirects FROM $table WHERE created_at >= $since GROUP BY slug ORDER BY redirects DESC"
	);

	$total_for_pct = max( 1, $overview->total_redirects );
	$max_hourly    = 1;
	foreach ( $by_hour as $h ) {
		if ( $h->redirects > $max_hourly ) {
			$max_hourly = $h->redirects;
		}
	}

	?>
	<div class="wrap">
		<h1>Affiliate Router Analytics</h1>

		<div style="display:flex;gap:20px;margin:20px 0">
			<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;text-align:center;flex:1">
				<div style="font-size:32px;font-weight:700;color:#FF6B35"><?php echo esc_html( number_format( $overview->total_redirects ) ); ?></div>
				<div style="color:#666;font-size:13px">Total Redirects</div>
			</div>
			<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;text-align:center;flex:1">
				<div style="font-size:32px;font-weight:700;color:#00D4AA"><?php echo esc_html( number_format( $overview->unique_visitors ) ); ?></div>
				<div style="color:#666;font-size:13px">Unique Visitors</div>
			</div>
			<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;text-align:center;flex:1">
				<div style="font-size:32px;font-weight:700;color:#1a1a3e"><?php echo esc_html( $overview->sources ); ?></div>
				<div style="color:#666;font-size:13px">Source Sites</div>
			</div>
		</div>

		<div style="margin-bottom:20px">
			<?php
			$range_labels = array( '1h' => '1 Hour', '6h' => '6 Hours', '24h' => '24 Hours', '7d' => '7 Days', '30d' => '30 Days', 'all' => 'All Time' );
			foreach ( $range_labels as $key => $label ) :
				$active = ( $key === $range ) ? 'background:#0073aa;color:#fff;' : '';
				?>
				<a href="?page=pl-affiliate-router&range=<?php echo esc_attr( $key ); ?>" class="button" style="<?php echo $active; ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</div>

		<!-- By Source Site -->
		<h2>By Source Site</h2>
		<table class="widefat striped" style="max-width:700px">
			<thead><tr><th>Source</th><th>Redirects</th><th>Unique Visitors</th><th>Active Days</th><th>Avg/Day</th></tr></thead>
			<tbody>
			<?php foreach ( $by_source as $row ) :
				$avg_day = $row->active_days > 0 ? round( $row->redirects / $row->active_days, 1 ) : 0;
				?>
				<tr>
					<td><strong><?php echo esc_html( $row->source_site ); ?></strong></td>
					<td><?php echo esc_html( number_format( $row->redirects ) ); ?></td>
					<td><?php echo esc_html( number_format( $row->unique_visitors ) ); ?></td>
					<td><?php echo esc_html( $row->active_days ); ?></td>
					<td><?php echo esc_html( $avg_day ); ?></td>
				</tr>
			<?php endforeach; ?>
			<?php if ( empty( $by_source ) ) : ?>
				<tr><td colspan="5" style="color:#999;text-align:center">No data yet</td></tr>
			<?php endif; ?>
			</tbody>
		</table>

		<!-- By Variant -->
		<h2 style="margin-top:30px">By Variant</h2>
		<table class="widefat striped" style="max-width:500px">
			<thead><tr><th>Variant</th><th>Source</th><th>Redirects</th></tr></thead>
			<tbody>
			<?php foreach ( $by_variant as $row ) : ?>
				<tr>
					<td><code><?php echo esc_html( $row->variant ); ?></code></td>
					<td><?php echo esc_html( $row->source_site ); ?></td>
					<td><?php echo esc_html( number_format( $row->redirects ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			<?php if ( empty( $by_variant ) ) : ?>
				<tr><td colspan="3" style="color:#999;text-align:center">No variant data yet</td></tr>
			<?php endif; ?>
			</tbody>
		</table>

		<!-- By Device -->
		<h2 style="margin-top:30px">By Device</h2>
		<table class="widefat striped" style="max-width:500px">
			<thead><tr><th>Device</th><th>Redirects</th><th>Unique Visitors</th><th>% of Total</th></tr></thead>
			<tbody>
			<?php foreach ( $by_device as $row ) :
				$pct = round( $row->redirects / $total_for_pct * 100, 1 );
				?>
				<tr>
					<td><?php echo esc_html( $row->device ); ?></td>
					<td><?php echo esc_html( number_format( $row->redirects ) ); ?></td>
					<td><?php echo esc_html( number_format( $row->unique_visitors ) ); ?></td>
					<td><?php echo esc_html( $pct ); ?>%</td>
				</tr>
			<?php endforeach; ?>
			<?php if ( empty( $by_device ) ) : ?>
				<tr><td colspan="4" style="color:#999;text-align:center">No data yet</td></tr>
			<?php endif; ?>
			</tbody>
		</table>

		<!-- Hourly Trend -->
		<h2 style="margin-top:30px">Hourly Trend</h2>
		<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;max-width:700px">
		<?php if ( ! empty( $by_hour ) ) : ?>
			<?php foreach ( $by_hour as $h ) :
				$bar_w = round( $h->redirects / $max_hourly * 100 );
				?>
				<div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
					<div style="width:130px;font-size:11px;color:#666;text-align:right"><?php echo esc_html( $h->hour ); ?></div>
					<div style="flex:1;height:18px;background:#f0f0f0;border-radius:3px;overflow:hidden">
						<div style="width:<?php echo $bar_w; ?>%;height:100%;background:linear-gradient(90deg,#FF6B35,#ff8f65);border-radius:3px"></div>
					</div>
					<div style="width:40px;font-size:12px;font-weight:600"><?php echo esc_html( $h->redirects ); ?></div>
				</div>
			<?php endforeach; ?>
		<?php else : ?>
			<p style="color:#999;text-align:center">No hourly data yet</p>
		<?php endif; ?>
		</div>

		<!-- Daily Breakdown -->
		<h2 style="margin-top:30px">Daily Breakdown</h2>
		<table class="widefat striped" style="max-width:600px">
			<thead><tr><th>Date</th><th>Source</th><th>Redirects</th><th>Unique Visitors</th></tr></thead>
			<tbody>
			<?php foreach ( $by_day as $row ) : ?>
				<tr>
					<td><?php echo esc_html( $row->date ); ?></td>
					<td><?php echo esc_html( $row->source_site ); ?></td>
					<td><?php echo esc_html( number_format( $row->redirects ) ); ?></td>
					<td><?php echo esc_html( number_format( $row->unique_visitors ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			<?php if ( empty( $by_day ) ) : ?>
				<tr><td colspan="4" style="color:#999;text-align:center">No data yet</td></tr>
			<?php endif; ?>
			</tbody>
		</table>

		<!-- Actions -->
		<div style="margin-top:30px;display:flex;gap:10px">
			<a href="<?php echo esc_url( rest_url( 'pl-aff/v1/router-stats?range=' . $range ) ); ?>" class="button" target="_blank">Export JSON</a>
			<form method="post" style="display:inline" onsubmit="return confirm('Delete all affiliate redirect data?')">
				<?php wp_nonce_field( 'pl_aff_clear_nonce' ); ?>
				<button type="submit" name="pl_aff_clear_data" class="button" style="color:#dc3232">Clear Data</button>
			</form>
		</div>
	</div>
	<?php
}

/* ================================================================
 * CLEANUP: 90-day retention
 * ================================================================ */

add_action( 'pl_ad_daily_cleanup', 'pl_aff_router_cleanup' );

function pl_aff_router_cleanup() {
	global $wpdb;
	$table = $wpdb->prefix . 'pl_affiliate_redirects';
	$wpdb->query( "DELETE FROM $table WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)" );
}

/* ================================================================
 * REST API for live stats
 * ================================================================ */

add_action( 'rest_api_init', function () {
	register_rest_route( 'pl-aff/v1', '/router-stats', array(
		'methods'             => 'GET',
		'callback'            => 'pl_aff_router_api_stats',
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
	) );
} );

function pl_aff_router_api_stats( $request ) {
	global $wpdb;
	$table = $wpdb->prefix . 'pl_affiliate_redirects';

	$range   = sanitize_text_field( $request->get_param( 'range' ) ?: '24h' );
	$ranges  = array( '1h' => '1 HOUR', '6h' => '6 HOUR', '24h' => '24 HOUR', '7d' => '7 DAY', '30d' => '30 DAY', 'all' => '365 DAY' );
	$interval = isset( $ranges[ $range ] ) ? $ranges[ $range ] : '24 HOUR';
	$since   = "DATE_SUB(NOW(), INTERVAL $interval)";

	$overview = $wpdb->get_row(
		"SELECT COUNT(*) as total_redirects, COUNT(DISTINCT ip_hash) as unique_visitors, COUNT(DISTINCT source_site) as sources FROM $table WHERE created_at >= $since"
	);

	$by_source = $wpdb->get_results(
		"SELECT source_site, COUNT(*) as redirects, COUNT(DISTINCT ip_hash) as unique_visitors FROM $table WHERE created_at >= $since GROUP BY source_site ORDER BY redirects DESC"
	);

	$by_variant = $wpdb->get_results(
		"SELECT variant, source_site, COUNT(*) as redirects FROM $table WHERE created_at >= $since AND variant != '' GROUP BY variant, source_site ORDER BY redirects DESC"
	);

	$by_device = $wpdb->get_results(
		"SELECT device, COUNT(*) as redirects FROM $table WHERE created_at >= $since GROUP BY device ORDER BY redirects DESC"
	);

	return rest_ensure_response( array(
		'range'      => $range,
		'overview'   => $overview,
		'by_source'  => $by_source,
		'by_variant' => $by_variant,
		'by_device'  => $by_device,
	) );
}
