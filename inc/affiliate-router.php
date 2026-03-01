<?php
/**
 * PL Affiliate Router
 *
 * Lightweight affiliate redirect with analytics + comprehensive
 * tracking dashboard with winner analysis, export, and clear.
 *
 * @package PinLightning
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
	// Only handle /go/ redirects on cheerlives.com.
	$host = parse_url( home_url(), PHP_URL_HOST );
	if ( $host !== 'cheerlives.com' && $host !== 'www.cheerlives.com' ) {
		return;
	}

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
 * ADMIN PAGE: Affiliate Analytics Dashboard
 * Priority 20 ensures parent menu (pl-ad-engine) exists first.
 * ================================================================ */

add_action( 'admin_menu', 'pl_aff_router_admin_menu', 20 );

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

/* ----------------------------------------------------------------
 * Helper: variant → psychology hook label
 * ---------------------------------------------------------------- */
function pl_aff_variant_hook( $variant ) {
	$hooks = array(
		'A1' => 'Income',
		'A2' => 'FOMO',
		'A3' => 'Identity',
		'B1' => 'Emotional',
		'B2' => 'Curiosity',
		'B3' => 'Humor',
		'C1' => 'Income Banner',
		'C2' => 'FOMO Banner',
		'C3' => 'Action Banner',
	);
	$key = strtoupper( str_replace( 'aff-', '', $variant ) );
	return isset( $hooks[ $key ] ) ? $hooks[ $key ] : $key;
}

/* ----------------------------------------------------------------
 * Helper: variant type (Dark / Light / Banner)
 * ---------------------------------------------------------------- */
function pl_aff_variant_type( $variant ) {
	$key = strtoupper( str_replace( 'aff-', '', $variant ) );
	if ( strpos( $key, 'A' ) === 0 ) return 'Dark';
	if ( strpos( $key, 'B' ) === 0 ) return 'Light';
	if ( strpos( $key, 'C' ) === 0 ) return 'Banner';
	return 'Unknown';
}

/* ----------------------------------------------------------------
 * Helper: safe table exists check
 * ---------------------------------------------------------------- */
function pl_aff_table_exists( $table ) {
	global $wpdb;
	return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
}

/* ----------------------------------------------------------------
 * Helper: build SQL time filter
 * ---------------------------------------------------------------- */
function pl_aff_time_sql( $range ) {
	$ranges = array(
		'1h'  => '1 HOUR',
		'6h'  => '6 HOUR',
		'24h' => '24 HOUR',
		'7d'  => '7 DAY',
		'30d' => '30 DAY',
		'all' => '365 DAY',
	);
	return isset( $ranges[ $range ] ) ? $ranges[ $range ] : '7 DAY';
}

/* ----------------------------------------------------------------
 * Helper: gather all dashboard data for a given range
 * ---------------------------------------------------------------- */
function pl_aff_gather_data( $range ) {
	global $wpdb;
	$rdr     = $wpdb->prefix . 'pl_affiliate_redirects';
	$evt     = $wpdb->prefix . 'pl_ad_events';
	$posts_t = $wpdb->posts;

	$interval = pl_aff_time_sql( $range );
	$rdr_exists = pl_aff_table_exists( $rdr );
	$evt_exists = pl_aff_table_exists( $evt );

	$data = array(
		'range' => $range,
	);

	/* ---- Overview ---- */
	$total_redirects  = 0;
	$unique_visitors  = 0;
	$total_clicks     = 0;
	$total_impressions = 0;

	if ( $rdr_exists ) {
		$row = $wpdb->get_row(
			"SELECT COUNT(*) AS cnt, COUNT(DISTINCT ip_hash) AS uv
			 FROM $rdr WHERE created_at >= DATE_SUB(NOW(), INTERVAL $interval)"
		);
		if ( $row ) {
			$total_redirects = (int) $row->cnt;
			$unique_visitors = (int) $row->uv;
		}
	}

	if ( $evt_exists ) {
		$clicks_row = $wpdb->get_var(
			"SELECT COUNT(*) FROM $evt
			 WHERE event_type = 'affiliate_click'
			   AND created_at >= DATE_SUB(NOW(), INTERVAL $interval)"
		);
		$total_clicks = (int) $clicks_row;

		$impr_row = $wpdb->get_var(
			"SELECT COUNT(*) FROM $evt
			 WHERE event_type = 'affiliate_impression'
			   AND created_at >= DATE_SUB(NOW(), INTERVAL $interval)"
		);
		$total_impressions = (int) $impr_row;
	}

	$overall_ctr = $total_impressions > 0
		? round( $total_clicks / $total_impressions * 100, 1 )
		: 0;

	$data['overview'] = array(
		'total_redirects'  => $total_redirects,
		'unique_visitors'  => $unique_visitors,
		'total_clicks'     => $total_clicks,
		'total_impressions' => $total_impressions,
		'overall_ctr'      => $overall_ctr,
	);

	/* ---- Section 1: Router Traffic by Source ---- */
	$data['by_source'] = array();
	if ( $rdr_exists ) {
		$data['by_source'] = $wpdb->get_results(
			"SELECT source_site,
			        COUNT(*) AS redirects,
			        COUNT(DISTINCT ip_hash) AS unique_visitors,
			        COUNT(DISTINCT DATE(created_at)) AS active_days
			 FROM $rdr
			 WHERE created_at >= DATE_SUB(NOW(), INTERVAL $interval)
			 GROUP BY source_site
			 ORDER BY redirects DESC"
		);
	}

	/* ---- Section 2: Router Traffic by Variant ---- */
	$data['by_variant_router'] = array();
	if ( $rdr_exists ) {
		$data['by_variant_router'] = $wpdb->get_results(
			"SELECT variant, source_site, COUNT(*) AS redirects
			 FROM $rdr
			 WHERE created_at >= DATE_SUB(NOW(), INTERVAL $interval)
			   AND variant != ''
			 GROUP BY variant, source_site
			 ORDER BY redirects DESC"
		);
	}

	/* ---- Section 3: Affiliate Impressions & Clicks (from ad events) ---- */
	$data['by_variant_events'] = array();
	if ( $evt_exists ) {
		$data['by_variant_events'] = $wpdb->get_results(
			"SELECT creative_size AS variant,
			        SUM(event_type = 'affiliate_impression') AS impressions,
			        SUM(event_type = 'affiliate_viewable') AS viewable,
			        SUM(event_type = 'affiliate_click') AS clicks
			 FROM $evt
			 WHERE event_type IN ('affiliate_impression','affiliate_viewable','affiliate_click')
			   AND created_at >= DATE_SUB(NOW(), INTERVAL $interval)
			 GROUP BY creative_size
			 ORDER BY clicks DESC"
		);
	}

	/* ---- Section 4: By Placement Reason ---- */
	$data['by_reason'] = array();
	if ( $evt_exists ) {
		$data['by_reason'] = $wpdb->get_results(
			"SELECT injection_type AS reason,
			        SUM(event_type = 'affiliate_impression') AS impressions,
			        SUM(event_type = 'affiliate_click') AS clicks
			 FROM $evt
			 WHERE event_type IN ('affiliate_impression','affiliate_click')
			   AND created_at >= DATE_SUB(NOW(), INTERVAL $interval)
			 GROUP BY injection_type
			 ORDER BY clicks DESC"
		);
	}

	/* ---- Section 5: By Device ---- */
	$data['by_device'] = array();
	if ( $rdr_exists || $evt_exists ) {
		$device_rdr = array();
		if ( $rdr_exists ) {
			$rows = $wpdb->get_results(
				"SELECT device, COUNT(*) AS redirects
				 FROM $rdr
				 WHERE created_at >= DATE_SUB(NOW(), INTERVAL $interval)
				 GROUP BY device"
			);
			foreach ( $rows as $r ) {
				$device_rdr[ $r->device ] = (int) $r->redirects;
			}
		}

		$device_impr = array();
		$device_click = array();
		if ( $evt_exists ) {
			$rows = $wpdb->get_results(
				"SELECT device,
				        SUM(event_type = 'affiliate_impression') AS impressions,
				        SUM(event_type = 'affiliate_click') AS clicks
				 FROM $evt
				 WHERE event_type IN ('affiliate_impression','affiliate_click')
				   AND created_at >= DATE_SUB(NOW(), INTERVAL $interval)
				 GROUP BY device"
			);
			foreach ( $rows as $r ) {
				$device_impr[ $r->device ]  = (int) $r->impressions;
				$device_click[ $r->device ] = (int) $r->clicks;
			}
		}

		$all_devices = array_unique( array_merge( array_keys( $device_rdr ), array_keys( $device_impr ) ) );
		foreach ( $all_devices as $dev ) {
			$impr = isset( $device_impr[ $dev ] ) ? $device_impr[ $dev ] : 0;
			$clk  = isset( $device_click[ $dev ] ) ? $device_click[ $dev ] : 0;
			$data['by_device'][] = array(
				'device'      => $dev,
				'redirects'   => isset( $device_rdr[ $dev ] ) ? $device_rdr[ $dev ] : 0,
				'impressions' => $impr,
				'clicks'      => $clk,
				'ctr'         => $impr > 0 ? round( $clk / $impr * 100, 1 ) : 0,
			);
		}
	}

	/* ---- Section 6: Daily Trend ---- */
	$data['daily'] = array();
	if ( $rdr_exists ) {
		$data['daily'] = $wpdb->get_results(
			"SELECT DATE(created_at) AS date,
			        source_site,
			        COUNT(*) AS redirects,
			        COUNT(DISTINCT ip_hash) AS unique_visitors
			 FROM $rdr
			 WHERE created_at >= DATE_SUB(NOW(), INTERVAL $interval)
			 GROUP BY date, source_site
			 ORDER BY date DESC
			 LIMIT 200"
		);
	}

	$daily_evt = array();
	if ( $evt_exists ) {
		$rows = $wpdb->get_results(
			"SELECT DATE(created_at) AS date,
			        SUM(event_type = 'affiliate_impression') AS impressions,
			        SUM(event_type = 'affiliate_click') AS clicks
			 FROM $evt
			 WHERE event_type IN ('affiliate_impression','affiliate_click')
			   AND created_at >= DATE_SUB(NOW(), INTERVAL $interval)
			 GROUP BY date
			 ORDER BY date DESC
			 LIMIT 30"
		);
		foreach ( $rows as $r ) {
			$daily_evt[ $r->date ] = array(
				'impressions' => (int) $r->impressions,
				'clicks'      => (int) $r->clicks,
			);
		}
	}
	$data['daily_events'] = $daily_evt;

	/* ---- Section 7: Top Banner Stats ---- */
	$data['top_banner'] = array(
		'impressions' => 0,
		'clicks'      => 0,
		'dismissals'  => 0,
		'dismiss_rate' => 0,
		'avg_visible_time' => 0,
		'by_variant'  => array(),
	);
	if ( $evt_exists ) {
		$banner_impr = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM $evt
			 WHERE event_type = 'affiliate_impression'
			   AND injection_type = 'top_banner'
			   AND created_at >= DATE_SUB(NOW(), INTERVAL $interval)"
		);
		$banner_clicks = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM $evt
			 WHERE event_type = 'affiliate_click'
			   AND injection_type = 'top_banner'
			   AND created_at >= DATE_SUB(NOW(), INTERVAL $interval)"
		);
		$banner_dismiss = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM $evt
			 WHERE event_type = 'affiliate_dismiss'
			   AND created_at >= DATE_SUB(NOW(), INTERVAL $interval)"
		);
		$avg_visible = (float) $wpdb->get_var(
			"SELECT AVG(predicted_distance) FROM $evt
			 WHERE event_type = 'affiliate_dismiss'
			   AND predicted_distance > 0
			   AND created_at >= DATE_SUB(NOW(), INTERVAL $interval)"
		);

		$data['top_banner']['impressions']      = $banner_impr;
		$data['top_banner']['clicks']            = $banner_clicks;
		$data['top_banner']['dismissals']        = $banner_dismiss;
		$data['top_banner']['dismiss_rate']      = $banner_impr > 0 ? round( $banner_dismiss / $banner_impr * 100, 1 ) : 0;
		$data['top_banner']['avg_visible_time']  = round( $avg_visible / 1000, 1 );

		$data['top_banner']['by_variant'] = $wpdb->get_results(
			"SELECT creative_size AS variant,
			        SUM(event_type = 'affiliate_impression') AS impressions,
			        SUM(event_type = 'affiliate_click') AS clicks,
			        SUM(event_type = 'affiliate_dismiss') AS dismissals
			 FROM $evt
			 WHERE event_type IN ('affiliate_impression','affiliate_click','affiliate_dismiss')
			   AND (injection_type = 'top_banner' OR event_type = 'affiliate_dismiss')
			   AND creative_size LIKE 'aff-C%'
			   AND created_at >= DATE_SUB(NOW(), INTERVAL $interval)
			 GROUP BY creative_size
			 ORDER BY impressions DESC"
		);
	}

	/* ---- Section 8: Top Performing Posts ---- */
	$data['top_posts'] = array();
	if ( $evt_exists ) {
		$data['top_posts'] = $wpdb->get_results(
			"SELECT e.post_id,
			        COALESCE(p.post_title, CONCAT('Post #', e.post_id)) AS title,
			        SUM(e.event_type = 'affiliate_impression') AS impressions,
			        SUM(e.event_type = 'affiliate_click') AS clicks
			 FROM $evt e
			 LEFT JOIN $posts_t p ON e.post_id = p.ID
			 WHERE e.event_type IN ('affiliate_impression','affiliate_click')
			   AND e.post_id > 0
			   AND e.created_at >= DATE_SUB(NOW(), INTERVAL $interval)
			 GROUP BY e.post_id
			 HAVING clicks > 0
			 ORDER BY clicks DESC
			 LIMIT 20"
		);
	}

	/* ---- Section 9: Hourly Heatmap (Last 24h) ---- */
	$data['hourly'] = array();
	if ( $rdr_exists ) {
		$data['hourly'] = $wpdb->get_results(
			"SELECT HOUR(created_at) AS hr, COUNT(*) AS cnt
			 FROM $rdr
			 WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
			 GROUP BY hr
			 ORDER BY hr ASC"
		);
	}

	/* ---- Section 10: Live Feed ---- */
	$data['live_feed'] = array();
	if ( $rdr_exists ) {
		$data['live_feed'] = $wpdb->get_results(
			"SELECT created_at, source_site, variant, device, referer
			 FROM $rdr
			 ORDER BY created_at DESC
			 LIMIT 20"
		);
	}

	/* ---- Section 11: Winner Analysis ---- */
	$data['winner_analysis'] = pl_aff_compute_winners( $data['by_variant_events'] );

	return $data;
}

/* ----------------------------------------------------------------
 * Helper: compute winner analysis from variant event data
 * ---------------------------------------------------------------- */
function pl_aff_compute_winners( $by_variant ) {
	$result = array(
		'total_impressions' => 0,
		'best_dark'         => '',
		'best_light'        => '',
		'best_banner'       => '',
		'ranked'            => array(),
	);

	if ( empty( $by_variant ) ) {
		return $result;
	}

	$variants = array();
	$total_impr = 0;
	foreach ( $by_variant as $row ) {
		$impr = (int) $row->impressions;
		$clk  = (int) $row->clicks;
		$ctr  = $impr > 0 ? round( $clk / $impr * 100, 1 ) : 0;
		$key  = strtoupper( str_replace( 'aff-', '', $row->variant ) );
		$variants[ $key ] = array(
			'variant'     => $row->variant,
			'key'         => $key,
			'type'        => pl_aff_variant_type( $row->variant ),
			'hook'        => pl_aff_variant_hook( $row->variant ),
			'impressions' => $impr,
			'clicks'      => $clk,
			'ctr'         => $ctr,
		);
		$total_impr += $impr;
	}

	$result['total_impressions'] = $total_impr;

	// Find winners per category.
	$groups = array(
		'Dark'   => array( 'A1', 'A2', 'A3' ),
		'Light'  => array( 'B1', 'B2', 'B3' ),
		'Banner' => array( 'C1', 'C2', 'C3' ),
	);

	foreach ( $groups as $group => $keys ) {
		$best     = '';
		$best_ctr = -1;
		foreach ( $keys as $k ) {
			if ( isset( $variants[ $k ] ) && $variants[ $k ]['impressions'] >= 10 && $variants[ $k ]['ctr'] > $best_ctr ) {
				$best     = $k;
				$best_ctr = $variants[ $k ]['ctr'];
			}
		}
		if ( $group === 'Dark' )   $result['best_dark']   = $best;
		if ( $group === 'Light' )  $result['best_light']  = $best;
		if ( $group === 'Banner' ) $result['best_banner'] = $best;
	}

	// Ranked table: all variants sorted by CTR.
	$avg_ctr = 0;
	$counted = 0;
	foreach ( $variants as $v ) {
		if ( $v['impressions'] >= 10 ) {
			$avg_ctr += $v['ctr'];
			$counted++;
		}
	}
	$avg_ctr = $counted > 0 ? $avg_ctr / $counted : 0;

	// Find global winner (highest CTR with 50+ impressions).
	$global_winner     = '';
	$global_winner_ctr = -1;
	foreach ( $variants as $k => $v ) {
		if ( $v['impressions'] >= 50 && $v['ctr'] > $global_winner_ctr ) {
			$global_winner     = $k;
			$global_winner_ctr = $v['ctr'];
		}
	}

	foreach ( $variants as $k => $v ) {
		$status = '';
		if ( $v['impressions'] < 10 ) {
			$status = 'collecting';
		} elseif ( $k === $global_winner ) {
			$status = 'winner';
		} elseif ( $v['ctr'] > $avg_ctr ) {
			$status = 'rising';
		} elseif ( $v['ctr'] >= $avg_ctr * 0.8 ) {
			$status = 'good';
		} else {
			$status = 'low';
		}
		$v['status'] = $status;
		$result['ranked'][] = $v;
	}

	// Sort by CTR desc.
	usort( $result['ranked'], function( $a, $b ) {
		return $b['ctr'] <=> $a['ctr'];
	} );

	return $result;
}

/* ----------------------------------------------------------------
 * Helper: human time ago
 * ---------------------------------------------------------------- */
function pl_aff_time_ago( $datetime ) {
	$diff = time() - strtotime( $datetime );
	if ( $diff < 60 )   return $diff . 's ago';
	if ( $diff < 3600 ) return floor( $diff / 60 ) . 'm ago';
	if ( $diff < 86400 ) return floor( $diff / 3600 ) . 'h ago';
	return floor( $diff / 86400 ) . 'd ago';
}

/* ================================================================
 * ADMIN PAGE RENDER
 * ================================================================ */

function pl_aff_router_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$range = isset( $_GET['range'] ) ? sanitize_text_field( $_GET['range'] ) : '7d';
	$data  = pl_aff_gather_data( $range );
	$ov    = $data['overview'];

	$range_labels = array(
		'1h'  => '1h',
		'6h'  => '6h',
		'24h' => '24h',
		'7d'  => '7d',
		'30d' => '30d',
		'all' => 'All',
	);

	?>
	<style>
		#pl-aff-dash { max-width:1200px; }
		.aff-cards { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin:16px 0 20px; }
		.aff-card { background:#fff; border:1px solid #c3c4c7; border-radius:6px; padding:16px; text-align:center; }
		.aff-card-value { font-size:28px; font-weight:700; color:#1d2327; }
		.aff-card-label { font-size:11px; color:#888; text-transform:uppercase; letter-spacing:.5px; margin-top:4px; }
		.aff-card-sub { font-size:11px; color:#666; margin-top:2px; }
		.aff-section { background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:16px; margin-bottom:16px; }
		.aff-section h3 { margin:0 0 12px; font-size:14px; font-weight:600; }
		.aff-controls { display:flex; gap:8px; align-items:center; margin-bottom:20px; flex-wrap:wrap; }
		.aff-range-btn { display:inline-block; padding:4px 12px; border:1px solid #c3c4c7; background:#fff; color:#1d2327; text-decoration:none; font-size:13px; cursor:pointer; border-radius:3px; }
		.aff-range-btn:hover { background:#f0f0f1; color:#1d2327; }
		.aff-range-btn.active { background:#2271b1; color:#fff; border-color:#2271b1; }
		.aff-btn-export { background:#00a32a !important; border-color:#00a32a !important; color:#fff !important; }
		.aff-btn-clear-router { background:#dba617 !important; border-color:#dba617 !important; color:#fff !important; }
		.aff-btn-clear-all { background:#d63638 !important; border-color:#d63638 !important; color:#fff !important; }
		.aff-table { border-collapse:collapse; width:100%; font-size:13px; }
		.aff-table th { background:#f6f7f7; text-align:left; padding:8px 10px; border-bottom:1px solid #c3c4c7; font-size:12px; font-weight:600; white-space:nowrap; }
		.aff-table td { padding:6px 10px; border-bottom:1px solid #e0e0e0; vertical-align:top; }
		.aff-table tr:hover td { background:#f6f7f7; }
		.aff-table .num { text-align:right; }
		.aff-empty { text-align:center; color:#646970; padding:20px; font-size:13px; }
		.aff-badge { display:inline-block; padding:2px 8px; border-radius:3px; font-size:11px; font-weight:600; }
		.aff-badge-winner { background:#d4edda; color:#155724; }
		.aff-badge-rising { background:#cce5ff; color:#004085; }
		.aff-badge-good { background:#f0f0f1; color:#1d2327; }
		.aff-badge-low { background:#fce4ec; color:#c62828; }
		.aff-badge-collecting { background:#fff3cd; color:#856404; }
		.aff-winner-cards { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin-bottom:16px; }
		.aff-winner-card { border:2px solid #c3c4c7; border-radius:6px; padding:14px; background:#fff; }
		.aff-winner-card.has-winner { border-color:#00a32a; }
		.aff-winner-card h4 { margin:0 0 10px; font-size:13px; font-weight:600; }
		.aff-winner-card .winner-row { display:flex; justify-content:space-between; align-items:center; padding:3px 0; font-size:12px; }
		.aff-winner-card .winner-row.is-winner { background:#edfcf2; padding:3px 6px; border-radius:3px; font-weight:600; }
		.aff-winner-card .winner-lift { font-size:11px; color:#00a32a; margin-top:6px; }
		.aff-heatmap-bar { display:flex; align-items:center; gap:8px; margin-bottom:3px; }
		.aff-heatmap-label { width:50px; font-size:11px; color:#666; text-align:right; }
		.aff-heatmap-track { flex:1; height:20px; background:#f0f0f0; border-radius:3px; overflow:hidden; }
		.aff-heatmap-fill { height:100%; border-radius:3px; transition:width .3s; }
		.aff-heatmap-count { width:40px; font-size:12px; font-weight:600; }
		.aff-row-winner td { background:#edfcf2 !important; }
		.aff-row-rising td { background:#f0f7ff !important; }
		.aff-row-low td { background:#fff5f5 !important; }
		.aff-row-collecting td { background:#f9f9f9 !important; color:#999; }
		.aff-live-indicator { display:inline-flex; align-items:center; gap:4px; font-size:11px; color:#646970; }
		.aff-live-dot { width:6px; height:6px; border-radius:50%; background:#00a32a; animation:plAffPulse 2s infinite; }
		@keyframes plAffPulse { 0%,100%{opacity:1} 50%{opacity:.3} }
		.aff-modal-overlay { display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,.5); z-index:100000; justify-content:center; align-items:center; }
		.aff-modal-overlay.visible { display:flex; }
		.aff-modal { background:#fff; border-radius:8px; padding:24px; max-width:450px; width:90%; box-shadow:0 4px 20px rgba(0,0,0,.2); }
		.aff-modal h3 { margin:0 0 12px; font-size:16px; }
		.aff-modal p { font-size:13px; color:#646970; margin:0 0 16px; }
		.aff-modal-actions { display:flex; gap:8px; justify-content:flex-end; }
		@media (max-width:782px) {
			.aff-cards { grid-template-columns:repeat(2,1fr); }
			.aff-winner-cards { grid-template-columns:1fr; }
		}
	</style>

	<div class="wrap" id="pl-aff-dash">
		<h1 style="display:flex;align-items:center;gap:10px;">Affiliate Router Analytics
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=pl-ad-engine' ) ); ?>" class="button" style="font-size:12px;">Back to Ad Engine</a>
		</h1>

		<!-- OVERVIEW CARDS -->
		<div class="aff-cards">
			<div class="aff-card">
				<div class="aff-card-value" style="color:#FF6B35"><?php echo esc_html( number_format( $ov['total_redirects'] ) ); ?></div>
				<div class="aff-card-label">Total Redirects</div>
			</div>
			<div class="aff-card">
				<div class="aff-card-value" style="color:#00D4AA"><?php echo esc_html( number_format( $ov['unique_visitors'] ) ); ?></div>
				<div class="aff-card-label">Unique Visitors</div>
			</div>
			<div class="aff-card">
				<div class="aff-card-value" style="color:#2271b1"><?php echo esc_html( number_format( $ov['total_clicks'] ) ); ?></div>
				<div class="aff-card-label">Total Ad Clicks</div>
			</div>
			<div class="aff-card">
				<div class="aff-card-value" style="color:#1d2327"><?php echo esc_html( $ov['overall_ctr'] ); ?>%</div>
				<div class="aff-card-label">Overall CTR</div>
				<div class="aff-card-sub"><?php echo esc_html( number_format( $ov['total_impressions'] ) ); ?> impressions</div>
			</div>
		</div>

		<!-- CONTROLS BAR -->
		<div class="aff-controls">
			<?php foreach ( $range_labels as $key => $label ) : ?>
				<a href="?page=pl-affiliate-router&range=<?php echo esc_attr( $key ); ?>"
				   class="aff-range-btn <?php echo $key === $range ? 'active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
			<span style="flex:1"></span>
			<button type="button" id="plAffExport" class="button aff-btn-export">Export JSON</button>
			<button type="button" id="plAffClearRouterBtn" class="button aff-btn-clear-router">Clear Router Data</button>
			<button type="button" id="plAffClearAllBtn" class="button aff-btn-clear-all">Clear ALL Affiliate Data</button>
		</div>

		<!-- SECTION 1: Router Traffic by Source -->
		<div class="aff-section">
			<h3>Router Traffic by Source</h3>
			<?php if ( ! empty( $data['by_source'] ) ) : ?>
				<?php $max_rdr = max( array_column( $data['by_source'], 'redirects' ) ); ?>
				<table class="aff-table">
					<thead><tr><th>Source Site</th><th class="num">Redirects</th><th class="num">Unique Visitors</th><th class="num">Active Days</th><th class="num">Avg/Day</th></tr></thead>
					<tbody>
					<?php foreach ( $data['by_source'] as $row ) :
						$avg = $row->active_days > 0 ? round( $row->redirects / $row->active_days, 1 ) : 0;
						$is_top = ( (int) $row->redirects === (int) $max_rdr );
						?>
						<tr>
							<td><?php if ( $is_top ) echo '<strong>'; ?><?php echo esc_html( $row->source_site ); ?><?php if ( $is_top ) echo '</strong>'; ?></td>
							<td class="num"><?php echo esc_html( number_format( $row->redirects ) ); ?></td>
							<td class="num"><?php echo esc_html( number_format( $row->unique_visitors ) ); ?></td>
							<td class="num"><?php echo esc_html( $row->active_days ); ?></td>
							<td class="num"><?php echo esc_html( $avg ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<div class="aff-empty">No data yet</div>
			<?php endif; ?>
		</div>

		<!-- SECTION 2: Router Traffic by Variant -->
		<div class="aff-section">
			<h3>Router Traffic by Variant</h3>
			<?php if ( ! empty( $data['by_variant_router'] ) ) : ?>
				<?php $total_var = max( 1, array_sum( array_column( $data['by_variant_router'], 'redirects' ) ) ); ?>
				<table class="aff-table">
					<thead><tr><th>Variant</th><th>Source</th><th class="num">Redirects</th><th class="num">% of Total</th></tr></thead>
					<tbody>
					<?php foreach ( $data['by_variant_router'] as $row ) :
						$pct = round( $row->redirects / $total_var * 100, 1 );
						?>
						<tr>
							<td><code><?php echo esc_html( $row->variant ); ?></code></td>
							<td><?php echo esc_html( $row->source_site ); ?></td>
							<td class="num"><?php echo esc_html( number_format( $row->redirects ) ); ?></td>
							<td class="num"><?php echo esc_html( $pct ); ?>%</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<div class="aff-empty">No variant data yet</div>
			<?php endif; ?>
		</div>

		<!-- SECTION 3: Affiliate Impressions & Clicks -->
		<div class="aff-section" style="border-left:4px solid #FF6B35;">
			<h3 style="color:#FF6B35;">Affiliate Impressions &amp; Clicks</h3>
			<?php if ( ! empty( $data['by_variant_events'] ) ) :
				// Find highest CTR.
				$best_ctr_variant = '';
				$best_ctr_val = -1;
				foreach ( $data['by_variant_events'] as $row ) {
					$impr = (int) $row->impressions;
					$clk  = (int) $row->clicks;
					$ctr  = $impr > 0 ? round( $clk / $impr * 100, 1 ) : 0;
					if ( $ctr > $best_ctr_val && $impr >= 10 ) {
						$best_ctr_val     = $ctr;
						$best_ctr_variant = $row->variant;
					}
				}
				?>
				<table class="aff-table">
					<thead><tr><th>Variant</th><th class="num">Impressions</th><th class="num">Viewable</th><th class="num">Clicks</th><th class="num">CTR</th><th>Best?</th></tr></thead>
					<tbody>
					<?php foreach ( $data['by_variant_events'] as $row ) :
						$impr = (int) $row->impressions;
						$clk  = (int) $row->clicks;
						$ctr  = $impr > 0 ? round( $clk / $impr * 100, 1 ) : 0;
						$is_best = ( $row->variant === $best_ctr_variant && $impr >= 10 );
						?>
						<tr>
							<td><code><?php echo esc_html( $row->variant ); ?></code> <span style="color:#888;font-size:11px"><?php echo esc_html( pl_aff_variant_hook( $row->variant ) ); ?></span></td>
							<td class="num"><?php echo esc_html( number_format( $impr ) ); ?></td>
							<td class="num"><?php echo esc_html( number_format( (int) $row->viewable ) ); ?></td>
							<td class="num"><?php echo esc_html( number_format( $clk ) ); ?></td>
							<td class="num"><?php echo esc_html( $ctr ); ?>%</td>
							<td><?php if ( $is_best ) echo '&#11088;'; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<div class="aff-empty">No affiliate ad event data yet</div>
			<?php endif; ?>
		</div>

		<!-- SECTION 4: By Placement Reason -->
		<div class="aff-section">
			<h3>By Placement Reason</h3>
			<?php if ( ! empty( $data['by_reason'] ) ) : ?>
				<table class="aff-table">
					<thead><tr><th>Reason</th><th class="num">Impressions</th><th class="num">Clicks</th><th class="num">CTR</th></tr></thead>
					<tbody>
					<?php foreach ( $data['by_reason'] as $row ) :
						$impr = (int) $row->impressions;
						$clk  = (int) $row->clicks;
						$ctr  = $impr > 0 ? round( $clk / $impr * 100, 1 ) : 0;
						?>
						<tr>
							<td><span class="aff-badge" style="background:#e0f2f1;color:#00695c"><?php echo esc_html( $row->reason ?: 'unknown' ); ?></span></td>
							<td class="num"><?php echo esc_html( number_format( $impr ) ); ?></td>
							<td class="num"><?php echo esc_html( number_format( $clk ) ); ?></td>
							<td class="num"><?php echo esc_html( $ctr ); ?>%</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<div class="aff-empty">No placement data yet</div>
			<?php endif; ?>
		</div>

		<!-- SECTION 5: By Device -->
		<div class="aff-section">
			<h3>By Device</h3>
			<?php if ( ! empty( $data['by_device'] ) ) : ?>
				<table class="aff-table">
					<thead><tr><th>Device</th><th class="num">Router Redirects</th><th class="num">Ad Impressions</th><th class="num">Ad Clicks</th><th class="num">CTR</th></tr></thead>
					<tbody>
					<?php foreach ( $data['by_device'] as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['device'] ); ?></td>
							<td class="num"><?php echo esc_html( number_format( $row['redirects'] ) ); ?></td>
							<td class="num"><?php echo esc_html( number_format( $row['impressions'] ) ); ?></td>
							<td class="num"><?php echo esc_html( number_format( $row['clicks'] ) ); ?></td>
							<td class="num"><?php echo esc_html( $row['ctr'] ); ?>%</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<div class="aff-empty">No device data yet</div>
			<?php endif; ?>
		</div>

		<!-- SECTION 6: Daily Trend -->
		<div class="aff-section">
			<h3>Daily Trend</h3>
			<?php if ( ! empty( $data['daily'] ) ) :
				// Merge daily router + event data by date.
				$dates = array();
				foreach ( $data['daily'] as $row ) {
					$d = $row->date;
					if ( ! isset( $dates[ $d ] ) ) {
						$dates[ $d ] = array( 'sources' => array(), 'redirects' => 0, 'unique_visitors' => 0, 'impressions' => 0, 'clicks' => 0 );
					}
					$dates[ $d ]['redirects']       += (int) $row->redirects;
					$dates[ $d ]['unique_visitors']  += (int) $row->unique_visitors;
					$dates[ $d ]['sources'][]         = $row->source_site;
				}
				foreach ( $data['daily_events'] as $d => $ev ) {
					if ( ! isset( $dates[ $d ] ) ) {
						$dates[ $d ] = array( 'sources' => array(), 'redirects' => 0, 'unique_visitors' => 0, 'impressions' => 0, 'clicks' => 0 );
					}
					$dates[ $d ]['impressions'] = $ev['impressions'];
					$dates[ $d ]['clicks']      = $ev['clicks'];
				}
				krsort( $dates );
				$dates = array_slice( $dates, 0, 30, true );
				?>
				<table class="aff-table">
					<thead><tr><th>Date</th><th>Sources</th><th class="num">Router Redirects</th><th class="num">Unique Visitors</th><th class="num">Ad Impressions</th><th class="num">Ad Clicks</th></tr></thead>
					<tbody>
					<?php foreach ( $dates as $d => $row ) : ?>
						<tr>
							<td><?php echo esc_html( $d ); ?></td>
							<td><?php echo esc_html( implode( ', ', array_unique( $row['sources'] ) ) ); ?></td>
							<td class="num"><?php echo esc_html( number_format( $row['redirects'] ) ); ?></td>
							<td class="num"><?php echo esc_html( number_format( $row['unique_visitors'] ) ); ?></td>
							<td class="num"><?php echo esc_html( number_format( $row['impressions'] ) ); ?></td>
							<td class="num"><?php echo esc_html( number_format( $row['clicks'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<div class="aff-empty">No daily data yet</div>
			<?php endif; ?>
		</div>

		<!-- SECTION 7: Top Banner Stats -->
		<div class="aff-section" style="border-left:4px solid #dba617;">
			<h3>Top Banner Stats</h3>
			<?php $tb = $data['top_banner']; ?>
			<div style="display:flex;gap:20px;flex-wrap:wrap;font-size:13px;margin-bottom:14px;">
				<div><strong style="color:#2271b1"><?php echo esc_html( number_format( $tb['impressions'] ) ); ?></strong> impressions</div>
				<div><strong style="color:#00a32a"><?php echo esc_html( number_format( $tb['clicks'] ) ); ?></strong> clicks</div>
				<div><strong style="color:#d63638"><?php echo esc_html( number_format( $tb['dismissals'] ) ); ?></strong> dismissals</div>
				<div><strong><?php echo esc_html( $tb['dismiss_rate'] ); ?>%</strong> dismiss rate</div>
				<div><strong><?php echo esc_html( $tb['avg_visible_time'] ); ?>s</strong> avg visible</div>
			</div>
			<?php if ( ! empty( $tb['by_variant'] ) ) : ?>
				<table class="aff-table">
					<thead><tr><th>Variant</th><th class="num">Impressions</th><th class="num">Clicks</th><th class="num">CTR</th><th class="num">Dismissals</th><th class="num">Dismiss Rate</th></tr></thead>
					<tbody>
					<?php foreach ( $tb['by_variant'] as $row ) :
						$impr = (int) $row->impressions;
						$clk  = (int) $row->clicks;
						$dis  = (int) $row->dismissals;
						$ctr  = $impr > 0 ? round( $clk / $impr * 100, 1 ) : 0;
						$dr   = $impr > 0 ? round( $dis / $impr * 100, 1 ) : 0;
						?>
						<tr>
							<td><code><?php echo esc_html( $row->variant ); ?></code> <span style="color:#888;font-size:11px"><?php echo esc_html( pl_aff_variant_hook( $row->variant ) ); ?></span></td>
							<td class="num"><?php echo esc_html( number_format( $impr ) ); ?></td>
							<td class="num"><?php echo esc_html( number_format( $clk ) ); ?></td>
							<td class="num"><?php echo esc_html( $ctr ); ?>%</td>
							<td class="num"><?php echo esc_html( number_format( $dis ) ); ?></td>
							<td class="num"><?php echo esc_html( $dr ); ?>%</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<div class="aff-empty">No banner data yet</div>
			<?php endif; ?>
		</div>

		<!-- SECTION 8: Top Performing Posts -->
		<div class="aff-section">
			<h3>Top Performing Posts</h3>
			<?php if ( ! empty( $data['top_posts'] ) ) : ?>
				<table class="aff-table">
					<thead><tr><th>#</th><th>Post Title</th><th class="num">Impressions</th><th class="num">Clicks</th><th class="num">CTR</th></tr></thead>
					<tbody>
					<?php $rank = 0; foreach ( $data['top_posts'] as $row ) :
						$rank++;
						$impr  = (int) $row->impressions;
						$clk   = (int) $row->clicks;
						$ctr   = $impr > 0 ? round( $clk / $impr * 100, 1 ) : 0;
						$title = strlen( $row->title ) > 60 ? substr( $row->title, 0, 60 ) . '...' : $row->title;
						$link  = get_permalink( (int) $row->post_id );
						?>
						<tr>
							<td><?php echo esc_html( $rank ); ?></td>
							<td><a href="<?php echo esc_url( $link ); ?>" target="_blank"><?php echo esc_html( $title ); ?></a></td>
							<td class="num"><?php echo esc_html( number_format( $impr ) ); ?></td>
							<td class="num"><?php echo esc_html( number_format( $clk ) ); ?></td>
							<td class="num"><?php echo esc_html( $ctr ); ?>%</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<div class="aff-empty">No post-level data yet</div>
			<?php endif; ?>
		</div>

		<!-- SECTION 9: Hourly Heatmap (Last 24h) -->
		<div class="aff-section">
			<h3>Hourly Heatmap (Last 24h)</h3>
			<?php if ( ! empty( $data['hourly'] ) ) :
				$hourly_map = array();
				$max_h = 1;
				foreach ( $data['hourly'] as $h ) {
					$hourly_map[ (int) $h->hr ] = (int) $h->cnt;
					if ( (int) $h->cnt > $max_h ) $max_h = (int) $h->cnt;
				}
				?>
				<?php for ( $i = 0; $i < 24; $i++ ) :
					$cnt = isset( $hourly_map[ $i ] ) ? $hourly_map[ $i ] : 0;
					$pct = $max_h > 0 ? round( $cnt / $max_h * 100 ) : 0;
					$intensity = $max_h > 0 ? $cnt / $max_h : 0;
					// Gradient from light green (#e0f7e0) to dark teal (#00D4AA).
					$r = (int) round( 224 - ( 224 - 0 ) * $intensity );
					$g = (int) round( 247 - ( 247 - 212 ) * $intensity );
					$b = (int) round( 224 - ( 224 - 170 ) * $intensity );
					$color = sprintf( 'rgb(%d,%d,%d)', $r, $g, $b );
					?>
					<div class="aff-heatmap-bar">
						<div class="aff-heatmap-label"><?php echo str_pad( $i, 2, '0', STR_PAD_LEFT ); ?>:00</div>
						<div class="aff-heatmap-track">
							<div class="aff-heatmap-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $color; ?>"></div>
						</div>
						<div class="aff-heatmap-count"><?php echo esc_html( $cnt ); ?></div>
					</div>
				<?php endfor; ?>
			<?php else : ?>
				<div class="aff-empty">No hourly data yet</div>
			<?php endif; ?>
		</div>

		<!-- SECTION 10: Live Feed -->
		<div class="aff-section">
			<h3 style="display:flex;align-items:center;gap:10px;">
				Live Feed
				<span class="aff-live-indicator" id="plAffLiveIndicator"><span class="aff-live-dot"></span> Auto-refresh: <strong id="plAffLiveStatus">ON</strong></span>
				<button type="button" id="plAffLiveToggle" class="button" style="font-size:11px;padding:0 8px;height:24px;">Toggle</button>
			</h3>
			<table class="aff-table">
				<thead><tr><th>Time</th><th>Source</th><th>Variant</th><th>Device</th><th>Referer</th></tr></thead>
				<tbody id="plAffLiveFeed">
				<?php if ( ! empty( $data['live_feed'] ) ) : ?>
					<?php foreach ( $data['live_feed'] as $row ) :
						$ref = strlen( $row->referer ) > 50 ? substr( $row->referer, 0, 50 ) . '...' : $row->referer;
						?>
						<tr>
							<td title="<?php echo esc_attr( $row->created_at ); ?>"><?php echo esc_html( pl_aff_time_ago( $row->created_at ) ); ?></td>
							<td><?php echo esc_html( $row->source_site ); ?></td>
							<td><code><?php echo esc_html( $row->variant ?: '-' ); ?></code></td>
							<td><?php echo esc_html( $row->device ); ?></td>
							<td style="font-size:11px;color:#666"><?php echo esc_html( $ref ?: '-' ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr><td colspan="5" class="aff-empty">No redirects yet</td></tr>
				<?php endif; ?>
				</tbody>
			</table>
		</div>

		<!-- SECTION 11: Winner Analysis -->
		<div class="aff-section" style="border-left:4px solid #FF6B35;">
			<?php $wa = $data['winner_analysis']; ?>
			<h3 style="color:#FF6B35;">&#127942; Winner Analysis</h3>

			<?php if ( $wa['total_impressions'] < 50 ) : ?>
				<div class="aff-empty">Need 50+ impressions for winner analysis. Currently at <?php echo esc_html( number_format( $wa['total_impressions'] ) ); ?> impressions.</div>
			<?php else : ?>

				<!-- Winner Cards -->
				<div class="aff-winner-cards">
					<?php
					$groups = array(
						array( 'title' => 'Best Dark Card', 'winner' => $wa['best_dark'], 'keys' => array( 'A1', 'A2', 'A3' ) ),
						array( 'title' => 'Best Light Card', 'winner' => $wa['best_light'], 'keys' => array( 'B1', 'B2', 'B3' ) ),
						array( 'title' => 'Best Banner', 'winner' => $wa['best_banner'], 'keys' => array( 'C1', 'C2', 'C3' ) ),
					);
					// Build lookup from ranked data.
					$ranked_lookup = array();
					foreach ( $wa['ranked'] as $r ) {
						$ranked_lookup[ $r['key'] ] = $r;
					}

					foreach ( $groups as $g ) :
						$has_winner = ! empty( $g['winner'] );
						$group_data = array();
						$all_have_data = true;
						foreach ( $g['keys'] as $k ) {
							if ( isset( $ranked_lookup[ $k ] ) ) {
								$group_data[ $k ] = $ranked_lookup[ $k ];
								if ( $ranked_lookup[ $k ]['impressions'] < 10 ) $all_have_data = false;
							} else {
								$group_data[ $k ] = array( 'impressions' => 0, 'clicks' => 0, 'ctr' => 0 );
								$all_have_data = false;
							}
						}
						?>
						<div class="aff-winner-card <?php echo $has_winner ? 'has-winner' : ''; ?>">
							<h4><?php echo esc_html( $g['title'] ); ?></h4>
							<?php foreach ( $g['keys'] as $k ) :
								$v = $group_data[ $k ];
								$is_w = ( $k === $g['winner'] );
								?>
								<div class="winner-row <?php echo $is_w ? 'is-winner' : ''; ?>">
									<span><?php if ( $is_w ) echo '&#127942; '; ?><?php echo esc_html( $k ); ?> <span style="color:#888;font-size:10px"><?php echo esc_html( pl_aff_variant_hook( 'aff-' . $k ) ); ?></span></span>
									<span>
										<?php if ( isset( $v['impressions'] ) && $v['impressions'] >= 10 ) : ?>
											<?php echo esc_html( $v['ctr'] ); ?>% <span style="color:#888;font-size:10px">(<?php echo esc_html( $v['impressions'] ); ?> imp)</span>
										<?php else : ?>
											<span style="color:#999;font-size:11px">Not enough data</span>
										<?php endif; ?>
									</span>
								</div>
							<?php endforeach; ?>
							<?php
							// CTR lift calculation.
							if ( $has_winner && $all_have_data ) :
								$winner_ctr = $group_data[ $g['winner'] ]['ctr'];
								$worst_ctr  = PHP_FLOAT_MAX;
								$worst_key  = '';
								foreach ( $g['keys'] as $k ) {
									if ( $k !== $g['winner'] && $group_data[ $k ]['ctr'] < $worst_ctr ) {
										$worst_ctr = $group_data[ $k ]['ctr'];
										$worst_key = $k;
									}
								}
								if ( $worst_ctr > 0 ) :
									$lift = round( ( $winner_ctr - $worst_ctr ) / $worst_ctr * 100 );
									?>
									<div class="winner-lift"><?php echo esc_html( $g['winner'] ); ?> converts <?php echo esc_html( $lift ); ?>% better than <?php echo esc_html( $worst_key ); ?></div>
								<?php endif; ?>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>

				<!-- Master Ranked Table -->
				<table class="aff-table" style="margin-top:16px;">
					<thead><tr><th>Rank</th><th>Variant</th><th>Type</th><th>Hook</th><th class="num">Impressions</th><th class="num">Clicks</th><th class="num">CTR</th><th>Status</th></tr></thead>
					<tbody>
					<?php $rank = 0; foreach ( $wa['ranked'] as $v ) :
						$rank++;
						$row_class = '';
						$status_label = '';
						$status_badge = '';
						switch ( $v['status'] ) {
							case 'winner':
								$row_class    = 'aff-row-winner';
								$status_badge = 'aff-badge-winner';
								$status_label = '&#127942; WINNER';
								break;
							case 'rising':
								$row_class    = 'aff-row-rising';
								$status_badge = 'aff-badge-rising';
								$status_label = '&#11014; Rising';
								break;
							case 'good':
								$status_badge = 'aff-badge-good';
								$status_label = '&#9989; Good';
								break;
							case 'low':
								$row_class    = 'aff-row-low';
								$status_badge = 'aff-badge-low';
								$status_label = '&#128315; Low';
								break;
							case 'collecting':
								$row_class    = 'aff-row-collecting';
								$status_badge = 'aff-badge-collecting';
								$status_label = '&#9203; Collecting';
								break;
						}
						?>
						<tr class="<?php echo $row_class; ?>">
							<td><?php echo esc_html( $rank ); ?></td>
							<td><code><?php echo esc_html( $v['variant'] ); ?></code></td>
							<td><?php echo esc_html( $v['type'] ); ?></td>
							<td><?php echo esc_html( $v['hook'] ); ?></td>
							<td class="num"><?php echo esc_html( number_format( $v['impressions'] ) ); ?></td>
							<td class="num"><?php echo esc_html( number_format( $v['clicks'] ) ); ?></td>
							<td class="num"><?php echo esc_html( $v['ctr'] ); ?>%</td>
							<td><span class="aff-badge <?php echo $status_badge; ?>"><?php echo $status_label; ?></span></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( empty( $wa['ranked'] ) ) : ?>
						<tr><td colspan="8" class="aff-empty">No variant data yet</td></tr>
					<?php endif; ?>
					</tbody>
				</table>

				<!-- Bandit Status -->
				<?php
				$all_have_10 = true;
				$total_impr_sum = 0;
				$winner_key = '';
				$winner_ctr = 0;
				$winner_share = 0;
				foreach ( $wa['ranked'] as $v ) {
					$total_impr_sum += $v['impressions'];
					if ( $v['impressions'] < 10 ) $all_have_10 = false;
					if ( $v['status'] === 'winner' ) {
						$winner_key = $v['key'];
						$winner_ctr = $v['ctr'];
					}
				}
				$variant_count = count( $wa['ranked'] );
				$equal_share   = $variant_count > 0 ? round( 100 / $variant_count, 1 ) : 0;
				if ( $winner_key && $total_impr_sum > 0 ) {
					foreach ( $wa['ranked'] as $v ) {
						if ( $v['key'] === $winner_key ) {
							$winner_share = round( $v['impressions'] / $total_impr_sum * 100, 1 );
						}
					}
				}
				?>
				<div style="margin-top:12px;padding:10px 14px;background:#f6f7f7;border-radius:4px;font-size:12px;color:#646970;">
					<?php if ( ! $all_have_10 ) : ?>
						<strong>Bandit Status: Exploring</strong> &mdash; all variants getting equal weight (need 10+ impressions each)
					<?php else : ?>
						<strong>Bandit Status: Optimizing</strong> &mdash;
						<?php if ( $winner_key ) : ?>
							<?php echo esc_html( $winner_key ); ?> getting <?php echo esc_html( $winner_share ); ?>% of impressions vs <?php echo esc_html( $equal_share ); ?>% equal distribution. Top performer: <?php echo esc_html( $winner_key ); ?> at <?php echo esc_html( $winner_ctr ); ?>% CTR
						<?php else : ?>
							No clear winner yet (need 50+ impressions per variant)
						<?php endif; ?>
					<?php endif; ?>
				</div>

			<?php endif; ?>
		</div>

	</div><!-- #pl-aff-dash -->

	<!-- Clear Router Data Modal -->
	<div class="aff-modal-overlay" id="plAffClearRouterModal">
		<div class="aff-modal">
			<h3>Clear Router Data?</h3>
			<p>Delete all router redirect logs? Ad event tracking will NOT be affected.</p>
			<div class="aff-modal-actions">
				<button type="button" class="button" id="plAffClearRouterCancel">Cancel</button>
				<button type="button" class="button aff-btn-clear-router" id="plAffClearRouterConfirm">Delete Router Data</button>
			</div>
		</div>
	</div>

	<!-- Clear ALL Affiliate Data Modal -->
	<div class="aff-modal-overlay" id="plAffClearAllModal">
		<div class="aff-modal">
			<h3>&#9888;&#65039; Clear ALL Affiliate Data?</h3>
			<p>Delete ALL affiliate data including impressions, clicks, viewable events, and router logs? This cannot be undone.</p>
			<div class="aff-modal-actions">
				<button type="button" class="button" id="plAffClearAllCancel">Cancel</button>
				<button type="button" class="button aff-btn-clear-all" id="plAffClearAllConfirm">Delete ALL Affiliate Data</button>
			</div>
		</div>
	</div>

	<script>
	(function(){
		var range = <?php echo wp_json_encode( $range ); ?>;
		var restBase = <?php echo wp_json_encode( esc_url_raw( rest_url( 'pl-aff/v1/' ) ) ); ?>;
		var nonce = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
		var autoRefresh = true;
		var refreshTimer = null;

		/* Export JSON */
		var exportBtn = document.getElementById('plAffExport');
		if (exportBtn) {
			exportBtn.addEventListener('click', function(){
				fetch(restBase + 'router-stats?range=' + encodeURIComponent(range), {
					headers: { 'X-WP-Nonce': nonce }
				})
				.then(function(r){ return r.json(); })
				.then(function(data){
					var blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
					var url = URL.createObjectURL(blob);
					var a = document.createElement('a');
					a.href = url;
					var today = new Date().toISOString().slice(0,10);
					a.download = 'pl-affiliate-analytics-' + range + '-' + today + '.json';
					document.body.appendChild(a);
					a.click();
					document.body.removeChild(a);
					URL.revokeObjectURL(url);
				})
				.catch(function(e){ alert('Export failed: ' + e.message); });
			});
		}

		/* Clear Router Data Modal */
		var crBtn     = document.getElementById('plAffClearRouterBtn');
		var crModal   = document.getElementById('plAffClearRouterModal');
		var crCancel  = document.getElementById('plAffClearRouterCancel');
		var crConfirm = document.getElementById('plAffClearRouterConfirm');

		if (crBtn && crModal) {
			crBtn.addEventListener('click', function(){ crModal.classList.add('visible'); });
			crCancel.addEventListener('click', function(){ crModal.classList.remove('visible'); });
			crModal.addEventListener('click', function(e){ if (e.target === crModal) crModal.classList.remove('visible'); });
			crConfirm.addEventListener('click', function(){
				crConfirm.disabled = true;
				crConfirm.textContent = 'Deleting...';
				fetch(restBase + 'clear-router-data', {
					method: 'POST',
					headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' }
				})
				.then(function(r){ return r.json(); })
				.then(function(data){
					if (data.success) {
						crModal.classList.remove('visible');
						window.location.reload();
					} else {
						alert('Error: ' + (data.message || 'Unknown error'));
						crConfirm.disabled = false;
						crConfirm.textContent = 'Delete Router Data';
					}
				})
				.catch(function(e){
					alert('Error: ' + e.message);
					crConfirm.disabled = false;
					crConfirm.textContent = 'Delete Router Data';
				});
			});
		}

		/* Clear ALL Affiliate Data Modal */
		var caBtn     = document.getElementById('plAffClearAllBtn');
		var caModal   = document.getElementById('plAffClearAllModal');
		var caCancel  = document.getElementById('plAffClearAllCancel');
		var caConfirm = document.getElementById('plAffClearAllConfirm');

		if (caBtn && caModal) {
			caBtn.addEventListener('click', function(){ caModal.classList.add('visible'); });
			caCancel.addEventListener('click', function(){ caModal.classList.remove('visible'); });
			caModal.addEventListener('click', function(e){ if (e.target === caModal) caModal.classList.remove('visible'); });
			caConfirm.addEventListener('click', function(){
				caConfirm.disabled = true;
				caConfirm.textContent = 'Deleting...';
				fetch(restBase + 'clear-all-data', {
					method: 'POST',
					headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' }
				})
				.then(function(r){ return r.json(); })
				.then(function(data){
					if (data.success) {
						caModal.classList.remove('visible');
						window.location.reload();
					} else {
						alert('Error: ' + (data.message || 'Unknown error'));
						caConfirm.disabled = false;
						caConfirm.textContent = 'Delete ALL Affiliate Data';
					}
				})
				.catch(function(e){
					alert('Error: ' + e.message);
					caConfirm.disabled = false;
					caConfirm.textContent = 'Delete ALL Affiliate Data';
				});
			});
		}

		/* Live Feed Auto-Refresh */
		function refreshLiveFeed(){
			fetch(restBase + 'live-feed?limit=20', {
				headers: { 'X-WP-Nonce': nonce }
			})
			.then(function(r){ return r.json(); })
			.then(function(rows){
				var tbody = document.getElementById('plAffLiveFeed');
				if (!tbody || !Array.isArray(rows)) return;
				if (rows.length === 0) {
					tbody.innerHTML = '<tr><td colspan="5" class="aff-empty">No redirects yet</td></tr>';
					return;
				}
				var html = '';
				rows.forEach(function(r){
					var ref = r.referer || '-';
					if (ref.length > 50) ref = ref.substring(0, 50) + '...';
					html += '<tr>';
					html += '<td title="' + (r.created_at||'') + '">' + (r.time_ago||'') + '</td>';
					html += '<td>' + (r.source_site||'') + '</td>';
					html += '<td><code>' + (r.variant||'-') + '</code></td>';
					html += '<td>' + (r.device||'') + '</td>';
					html += '<td style="font-size:11px;color:#666">' + ref + '</td>';
					html += '</tr>';
				});
				tbody.innerHTML = html;
			})
			.catch(function(){});
		}

		function startAutoRefresh(){
			if (refreshTimer) clearInterval(refreshTimer);
			refreshTimer = setInterval(refreshLiveFeed, 30000);
		}

		var liveToggle = document.getElementById('plAffLiveToggle');
		var liveStatus = document.getElementById('plAffLiveStatus');
		var liveDot    = document.querySelector('.aff-live-dot');
		if (liveToggle) {
			liveToggle.addEventListener('click', function(){
				autoRefresh = !autoRefresh;
				liveStatus.textContent = autoRefresh ? 'ON' : 'OFF';
				if (liveDot) liveDot.style.background = autoRefresh ? '#00a32a' : '#c3c4c7';
				if (autoRefresh) { startAutoRefresh(); } else { clearInterval(refreshTimer); }
			});
		}

		startAutoRefresh();
	})();
	</script>
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
 * REST API ENDPOINTS
 * ================================================================ */

add_action( 'rest_api_init', 'pl_aff_register_rest_routes' );

function pl_aff_register_rest_routes() {
	// GET — full stats for export.
	register_rest_route( 'pl-aff/v1', '/router-stats', array(
		'methods'             => 'GET',
		'callback'            => 'pl_aff_router_api_stats',
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
	) );

	// POST — clear router data only.
	register_rest_route( 'pl-aff/v1', '/clear-router-data', array(
		'methods'             => 'POST',
		'callback'            => 'pl_aff_router_api_clear',
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
	) );

	// POST — clear ALL affiliate data (router + ad events).
	register_rest_route( 'pl-aff/v1', '/clear-all-data', array(
		'methods'             => 'POST',
		'callback'            => 'pl_aff_router_api_clear_all',
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
	) );

	// GET — live feed for auto-refresh.
	register_rest_route( 'pl-aff/v1', '/live-feed', array(
		'methods'             => 'GET',
		'callback'            => 'pl_aff_router_api_live_feed',
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
	) );
}

/* ----------------------------------------------------------------
 * GET /pl-aff/v1/router-stats?range=7d
 * Returns complete dashboard data as JSON (for Export).
 * ---------------------------------------------------------------- */
function pl_aff_router_api_stats( $request ) {
	$range = sanitize_text_field( $request->get_param( 'range' ) ?: '7d' );
	$data  = pl_aff_gather_data( $range );

	$data['exported_at'] = current_time( 'mysql' );

	return rest_ensure_response( $data );
}

/* ----------------------------------------------------------------
 * POST /pl-aff/v1/clear-router-data
 * Truncates pl_affiliate_redirects table.
 * ---------------------------------------------------------------- */
function pl_aff_router_api_clear() {
	global $wpdb;
	$table = $wpdb->prefix . 'pl_affiliate_redirects';

	if ( pl_aff_table_exists( $table ) ) {
		$wpdb->query( "TRUNCATE TABLE $table" );
	}

	return rest_ensure_response( array(
		'success' => true,
		'message' => 'Router data cleared.',
	) );
}

/* ----------------------------------------------------------------
 * POST /pl-aff/v1/clear-all-data
 * Truncates pl_affiliate_redirects AND deletes affiliate events
 * from pl_ad_events.
 * ---------------------------------------------------------------- */
function pl_aff_router_api_clear_all() {
	global $wpdb;
	$rdr = $wpdb->prefix . 'pl_affiliate_redirects';
	$evt = $wpdb->prefix . 'pl_ad_events';

	if ( pl_aff_table_exists( $rdr ) ) {
		$wpdb->query( "TRUNCATE TABLE $rdr" );
	}

	$deleted_events = 0;
	if ( pl_aff_table_exists( $evt ) ) {
		$deleted_events = (int) $wpdb->query(
			"DELETE FROM $evt WHERE event_type LIKE 'affiliate_%'"
		);
	}

	return rest_ensure_response( array(
		'success'        => true,
		'deleted_events' => $deleted_events,
		'message'        => 'All affiliate data cleared. ' . $deleted_events . ' ad events deleted.',
	) );
}

/* ----------------------------------------------------------------
 * GET /pl-aff/v1/live-feed?limit=20
 * Returns last N redirects for live feed auto-refresh.
 * ---------------------------------------------------------------- */
function pl_aff_router_api_live_feed( $request ) {
	global $wpdb;
	$table = $wpdb->prefix . 'pl_affiliate_redirects';

	if ( ! pl_aff_table_exists( $table ) ) {
		return rest_ensure_response( array() );
	}

	$limit = absint( $request->get_param( 'limit' ) ?: 20 );
	if ( $limit < 1 || $limit > 100 ) {
		$limit = 20;
	}

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT created_at, source_site, variant, device, referer
		 FROM $table ORDER BY created_at DESC LIMIT %d",
		$limit
	) );

	$result = array();
	foreach ( $rows as $row ) {
		$result[] = array(
			'created_at'  => $row->created_at,
			'time_ago'    => pl_aff_time_ago( $row->created_at ),
			'source_site' => $row->source_site,
			'variant'     => $row->variant ?: '-',
			'device'      => $row->device,
			'referer'     => $row->referer ?: '-',
		);
	}

	return rest_ensure_response( $result );
}
