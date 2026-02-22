<?php
/**
 * PinLightning Ad Analytics Dashboard
 *
 * Comprehensive analytics page that reads from aggregated daily stats
 * (stored by ad-analytics-aggregator.php) and renders 10 report sections:
 * overview cards, trends, fill analysis, viewability, overlay performance,
 * passback report, traffic breakdown, zone performance, top posts, and clicks.
 *
 * @package PinLightning
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ================================================================
 * 1. ADMIN MENU
 * ================================================================ */

/**
 * Register the analytics dashboard as a submenu under Ad Engine.
 */
function pl_ad_dashboard_menu() {
	add_submenu_page(
		'pl-ad-engine',
		'Analytics Dashboard',
		'Analytics',
		'manage_options',
		'pl-ad-analytics-dashboard',
		'pl_ad_render_analytics'
	);
}
add_action( 'admin_menu', 'pl_ad_dashboard_menu' );

/* ================================================================
 * 2. EXPORT & CLEAR HANDLERS (admin_init — runs before page output)
 * ================================================================ */

/**
 * Handle analytics JSON export.
 */
function pl_ad_handle_analytics_export() {
	if ( ! isset( $_POST['pl_ad_export_analytics'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'pl_ad_export_analytics_nonce' ) ) {
		return;
	}

	$range = sanitize_key( $_POST['export_range'] ?? 'today' );
	$today = current_time( 'Y-m-d' );

	switch ( $range ) {
		case 'yesterday':
			$start = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
			$end   = $start;
			break;
		case '7days':
			$start = gmdate( 'Y-m-d', strtotime( '-6 days' ) );
			$end   = $today;
			break;
		case '30days':
			$start = gmdate( 'Y-m-d', strtotime( '-29 days' ) );
			$end   = $today;
			break;
		case 'custom':
			$start = sanitize_text_field( $_POST['export_start'] ?? $today );
			$end   = sanitize_text_field( $_POST['export_end'] ?? $today );
			break;
		default:
			$start = $today;
			$end   = $today;
	}

	$data    = pl_ad_get_stats_range( $start, $end );
	$summary = pl_ad_compute_export_summary( $data['combined'] );

	$export = array(
		'exported_at' => current_time( 'c' ),
		'range'       => $range,
		'start_date'  => $start,
		'end_date'    => $end,
		'summary'     => $summary,
		'combined'    => $data['combined'],
		'daily'       => $data['daily'],
	);

	$filename = 'pl-ad-analytics-' . $start . '-to-' . $end . '.json';

	header( 'Content-Type: application/json' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	header( 'Cache-Control: no-store' );
	echo wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	exit;
}
add_action( 'admin_init', 'pl_ad_handle_analytics_export' );

/**
 * Handle clearing all aggregated analytics data.
 */
function pl_ad_handle_analytics_clear() {
	if ( ! isset( $_POST['pl_ad_clear_analytics'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'pl_ad_clear_analytics_nonce' ) ) {
		return;
	}

	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'pl\_ad\_stats\_%'" );

	wp_safe_redirect( admin_url( 'admin.php?page=pl-ad-analytics-dashboard&cleared=1' ) );
	exit;
}
add_action( 'admin_init', 'pl_ad_handle_analytics_clear' );

/**
 * Compute pre-calculated summary metrics for export.
 *
 * @param  array $s Combined stats array.
 * @return array    Key metrics summary.
 */
function pl_ad_compute_export_summary( $s ) {
	$sessions = $s['total_sessions'];

	$gate_pass_rate = $s['gate_checks'] > 0
		? round( ( $s['gate_opens'] / $s['gate_checks'] ) * 100, 1 )
		: 0;

	$adplus_fill_rate = $s['total_ad_requests'] > 0
		? round( ( $s['total_ad_fills'] / $s['total_ad_requests'] ) * 100, 1 )
		: 0;

	$newor_fill_rate = $s['waldo_total_requested'] > 0
		? round( ( $s['waldo_total_filled'] / $s['waldo_total_requested'] ) * 100, 1 )
		: 0;

	$combined_showing = 0;
	foreach ( $s['by_zone'] as $z ) {
		$combined_showing += ( $z['filled'] ?? 0 ) + ( $z['passback_filled'] ?? 0 );
	}
	$effective_fill = $s['total_zones_activated'] > 0
		? round( ( $combined_showing / $s['total_zones_activated'] ) * 100, 1 )
		: 0;

	$viewability = $s['total_zones_activated'] > 0
		? round( ( $s['total_viewable_ads'] / $s['total_zones_activated'] ) * 100, 1 )
		: 0;

	$total_clicks = $s['total_display_clicks'] + $s['total_anchor_clicks']
		+ $s['total_interstitial_clicks'] + $s['total_pause_clicks'];
	$total_imps   = $s['total_ad_fills'] + $s['waldo_total_filled'] + $s['anchor_total_impressions'];
	$ctr          = $total_imps > 0 ? round( ( $total_clicks / $total_imps ) * 100, 2 ) : 0;

	$avg_time   = $sessions > 0 ? round( $s['total_time_s'] / $sessions, 1 ) : 0;
	$avg_scroll = $sessions > 0 ? round( $s['total_scroll_pct'] / $sessions, 1 ) : 0;
	$avg_zones  = $sessions > 0 ? round( $s['total_zones_activated'] / $sessions, 1 ) : 0;

	// Top referrer.
	$top_referrer = '-';
	if ( ! empty( $s['by_referrer'] ) ) {
		arsort( $s['by_referrer'] );
		$top_referrer = array_key_first( $s['by_referrer'] );
	}

	// Top device.
	$top_device = '-';
	if ( ! empty( $s['by_device'] ) ) {
		arsort( $s['by_device'] );
		$top_device = array_key_first( $s['by_device'] );
	}

	$retry_rate = $s['total_retries'] > 0
		? round( ( $s['retries_successful'] / $s['total_retries'] ) * 100, 1 )
		: 0;

	return array(
		'total_sessions'    => $sessions,
		'gate_pass_rate'    => $gate_pass_rate . '%',
		'adplus_fill_rate'  => $adplus_fill_rate . '%',
		'newor_fill_rate'   => $newor_fill_rate . '%',
		'effective_fill'    => $effective_fill . '%',
		'viewability'       => $viewability . '%',
		'total_clicks'      => $total_clicks,
		'ctr'               => $ctr . '%',
		'avg_time_s'        => $avg_time,
		'avg_scroll_pct'    => $avg_scroll,
		'avg_zones_session' => $avg_zones,
		'top_referrer'      => $top_referrer,
		'top_device'        => $top_device,
		'retry_success_rate' => $retry_rate . '%',
		'anchor_impressions' => $s['anchor_total_impressions'],
	);
}

/* ================================================================
 * 3. MAIN RENDER FUNCTION
 * ================================================================ */

/**
 * Render the full analytics dashboard page.
 */
function pl_ad_render_analytics() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Parse date range from query params.
	$range = sanitize_key( $_GET['range'] ?? 'today' );
	$today = current_time( 'Y-m-d' );

	switch ( $range ) {
		case 'yesterday':
			$start = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
			$end   = $start;
			break;
		case '7days':
			$start = gmdate( 'Y-m-d', strtotime( '-6 days' ) );
			$end   = $today;
			break;
		case '30days':
			$start = gmdate( 'Y-m-d', strtotime( '-29 days' ) );
			$end   = $today;
			break;
		case 'custom':
			$start = sanitize_text_field( $_GET['start'] ?? $today );
			$end   = sanitize_text_field( $_GET['end'] ?? $today );
			break;
		default: // today
			$start = $today;
			$end   = $today;
	}

	$data  = pl_ad_get_stats_range( $start, $end );
	$stats = $data['combined'];
	$daily = $data['daily'];

	?>
	<div class="wrap" id="pl-analytics">
		<h1>Ad Engine Analytics</h1>

		<?php if ( ! empty( $_GET['cleared'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>All analytics data has been cleared.</p></div>
		<?php endif; ?>

		<!-- Date Range Selector -->
		<div class="pl-date-range" style="margin:15px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
			<?php
			$ranges = array(
				'today'     => 'Today',
				'yesterday' => 'Yesterday',
				'7days'     => 'Last 7 Days',
				'30days'    => 'Last 30 Days',
			);
			foreach ( $ranges as $k => $label ) {
				$active = ( $range === $k ) ? 'button-primary' : '';
				$url    = admin_url( 'admin.php?page=pl-ad-analytics-dashboard&range=' . $k );
				echo '<a href="' . esc_url( $url ) . '" class="button ' . $active . '">' . esc_html( $label ) . '</a>';
			}
			?>
			<form method="get" style="display:inline-flex;gap:5px;align-items:center;margin-left:10px;">
				<input type="hidden" name="page" value="pl-ad-analytics-dashboard">
				<input type="hidden" name="range" value="custom">
				<input type="date" name="start" value="<?php echo esc_attr( $start ); ?>" style="padding:3px 6px;">
				<span>to</span>
				<input type="date" name="end" value="<?php echo esc_attr( $end ); ?>" style="padding:3px 6px;">
				<button type="submit" class="button">Apply</button>
			</form>
		</div>

		<!-- Navigation links -->
		<div style="margin-bottom:20px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=pl-ad-live-sessions' ) ); ?>" class="button">Live Sessions</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=pl-ad-engine' ) ); ?>" class="button">Ad Engine Settings</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=pl-ad-optimizer' ) ); ?>" class="button">Optimizer</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=pl-ad-analytics' ) ); ?>" class="button">Legacy Analytics</a>

			<span style="border-left:1px solid #c3c4c7;height:24px;margin:0 4px;"></span>

			<!-- Export JSON -->
			<form method="post" style="display:inline;margin:0;">
				<?php wp_nonce_field( 'pl_ad_export_analytics_nonce' ); ?>
				<input type="hidden" name="export_range" value="<?php echo esc_attr( $range ); ?>">
				<input type="hidden" name="export_start" value="<?php echo esc_attr( $start ); ?>">
				<input type="hidden" name="export_end" value="<?php echo esc_attr( $end ); ?>">
				<button type="submit" name="pl_ad_export_analytics" class="button" style="background:#2271b1;border-color:#2271b1;color:#fff;">Export JSON</button>
			</form>

			<!-- Clear All Data -->
			<form method="post" style="display:inline;margin:0;" onsubmit="return confirm('Delete ALL aggregated analytics data? This cannot be undone.');">
				<?php wp_nonce_field( 'pl_ad_clear_analytics_nonce' ); ?>
				<button type="submit" name="pl_ad_clear_analytics" class="button" style="background:#d63638;border-color:#d63638;color:#fff;">Clear All Data</button>
			</form>
		</div>

		<?php if ( $stats['total_sessions'] === 0 ) : ?>
			<div class="notice notice-warning"><p>No data for this date range. Sessions are aggregated when they end &mdash; check back after some traffic.</p></div>
		<?php else : ?>
			<?php pl_ad_render_overview_cards( $stats ); ?>
			<?php pl_ad_render_trend_charts( $daily, $start, $end ); ?>
			<?php pl_ad_render_fill_analysis( $stats ); ?>
			<?php pl_ad_render_viewability_report( $stats ); ?>
			<?php pl_ad_render_overlay_performance( $stats ); ?>
			<?php pl_ad_render_passback_report( $stats ); ?>
			<?php pl_ad_render_traffic_breakdown( $stats ); ?>
			<?php pl_ad_render_zone_performance( $stats ); ?>
			<?php pl_ad_render_top_posts( $stats ); ?>
			<?php pl_ad_render_click_report( $stats ); ?>
		<?php endif; ?>
	</div>

	<style>
	/* Dashboard styles */
	#pl-analytics { max-width: 1400px; }

	.pl-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px; margin: 20px 0; }
	.pl-card { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 20px; text-align: center; }
	.pl-card .pl-card-value { font-size: 28px; font-weight: 700; color: #1d2327; margin: 5px 0; }
	.pl-card .pl-card-label { font-size: 13px; color: #646970; text-transform: uppercase; letter-spacing: 0.5px; }
	.pl-card .pl-card-sub { font-size: 12px; color: #888; margin-top: 4px; }
	.pl-card.green .pl-card-value { color: #00a32a; }
	.pl-card.red .pl-card-value { color: #d63638; }
	.pl-card.blue .pl-card-value { color: #2271b1; }
	.pl-card.purple .pl-card-value { color: #8c5fc7; }

	.pl-section { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 20px; margin: 20px 0; }
	.pl-section h2 { margin: 0 0 15px; font-size: 16px; border-bottom: 1px solid #eee; padding-bottom: 10px; }

	.pl-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
	.pl-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; }
	@media (max-width: 1200px) { .pl-grid-2, .pl-grid-3 { grid-template-columns: 1fr; } }

	.pl-bar { background: #f0f0f1; border-radius: 4px; height: 24px; position: relative; margin: 4px 0; }
	.pl-bar-fill { height: 100%; border-radius: 4px; min-width: 2px; transition: width 0.3s; }
	.pl-bar-label { position: absolute; left: 8px; top: 3px; font-size: 12px; font-weight: 500; color: #1d2327; }
	.pl-bar-value { position: absolute; right: 8px; top: 3px; font-size: 12px; color: #646970; }

	table.pl-table { width: 100%; border-collapse: collapse; font-size: 13px; }
	table.pl-table th { text-align: left; padding: 8px 10px; border-bottom: 2px solid #ddd; font-weight: 600; color: #1d2327; }
	table.pl-table td { padding: 8px 10px; border-bottom: 1px solid #f0f0f1; }
	table.pl-table tr:hover td { background: #f9f9f9; }
	table.pl-table .num { text-align: right; font-variant-numeric: tabular-nums; }

	.pl-mini-chart { display: flex; align-items: flex-end; gap: 2px; height: 100px; }
	.pl-mini-bar { background: #2271b1; border-radius: 2px 2px 0 0; min-width: 8px; flex: 1; transition: height 0.3s; }
	.pl-mini-bar:hover { opacity: 0.8; }

	.pl-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
	.pl-badge-green { background: #d4edda; color: #155724; }
	.pl-badge-red { background: #f8d7da; color: #721c24; }
	.pl-badge-blue { background: #cce5ff; color: #004085; }
	.pl-badge-purple { background: #e8d5f5; color: #5a2d82; }
	.pl-badge-yellow { background: #fff3cd; color: #856404; }
	</style>
	<?php
}

/* ================================================================
 * 3. SECTION 1 — OVERVIEW CARDS
 * ================================================================ */

function pl_ad_render_overview_cards( $s ) {
	$sessions   = $s['total_sessions'];
	$gate_rate  = $s['gate_checks'] > 0 ? round( ( $s['gate_opens'] / $s['gate_checks'] ) * 100 ) : 0;
	$avg_time   = $sessions > 0 ? round( $s['total_time_s'] / $sessions ) : 0;
	$avg_scroll = $sessions > 0 ? round( $s['total_scroll_pct'] / $sessions ) : 0;

	$fill_rate   = $s['total_ad_requests'] > 0 ? round( ( $s['total_ad_fills'] / $s['total_ad_requests'] ) * 100 ) : 0;
	$viewability = $s['total_zones_activated'] > 0 ? round( ( $s['total_viewable_ads'] / $s['total_zones_activated'] ) * 100 ) : 0;

	$waldo_fills = $s['waldo_total_filled'];

	$total_clicks      = $s['total_display_clicks'] + $s['total_anchor_clicks'] + $s['total_interstitial_clicks'] + $s['total_pause_clicks'];
	$total_impressions = $s['total_ad_fills'] + $s['waldo_total_filled'] + $s['anchor_total_impressions'];
	$ctr               = $total_impressions > 0 ? round( ( $total_clicks / $total_impressions ) * 100, 2 ) : 0;

	?>
	<div class="pl-cards">
		<div class="pl-card">
			<div class="pl-card-label">Sessions</div>
			<div class="pl-card-value"><?php echo number_format( $sessions ); ?></div>
			<div class="pl-card-sub"><?php echo number_format( $s['gate_opens'] ); ?> gate opens (<?php echo $gate_rate; ?>%)</div>
		</div>
		<div class="pl-card">
			<div class="pl-card-label">Avg Time on Page</div>
			<div class="pl-card-value"><?php echo $avg_time; ?>s</div>
			<div class="pl-card-sub">Avg scroll <?php echo $avg_scroll; ?>%</div>
		</div>
		<div class="pl-card blue">
			<div class="pl-card-label">Ad.Plus Fill Rate</div>
			<div class="pl-card-value"><?php echo $fill_rate; ?>%</div>
			<div class="pl-card-sub"><?php echo $s['total_ad_fills']; ?>/<?php echo $s['total_ad_requests']; ?> filled</div>
		</div>
		<div class="pl-card purple">
			<div class="pl-card-label">Newor Passback Fills</div>
			<div class="pl-card-value"><?php echo number_format( $waldo_fills ); ?></div>
			<div class="pl-card-sub"><?php echo $s['waldo_total_requested']; ?> attempted, <?php echo ( $s['waldo_total_requested'] > 0 ? round( ( $waldo_fills / $s['waldo_total_requested'] ) * 100 ) : 0 ); ?>% rate</div>
		</div>
		<div class="pl-card green">
			<div class="pl-card-label">Viewability</div>
			<div class="pl-card-value"><?php echo $viewability; ?>%</div>
			<div class="pl-card-sub"><?php echo $s['total_viewable_ads']; ?> viewable impressions</div>
		</div>
		<div class="pl-card">
			<div class="pl-card-label">Total Clicks</div>
			<div class="pl-card-value"><?php echo number_format( $total_clicks ); ?></div>
			<div class="pl-card-sub">CTR: <?php echo $ctr; ?>%</div>
		</div>
		<div class="pl-card">
			<div class="pl-card-label">Zones Activated</div>
			<div class="pl-card-value"><?php echo number_format( $s['total_zones_activated'] ); ?></div>
			<div class="pl-card-sub">Avg <?php echo ( $sessions > 0 ? round( $s['total_zones_activated'] / $sessions, 1 ) : 0 ); ?> per session</div>
		</div>
		<div class="pl-card">
			<div class="pl-card-label">Anchor Impressions</div>
			<div class="pl-card-value"><?php echo number_format( $s['anchor_total_impressions'] ); ?></div>
			<div class="pl-card-sub"><?php echo $s['anchor_total_viewable']; ?> viewable (<?php echo ( $s['anchor_total_impressions'] > 0 ? round( ( $s['anchor_total_viewable'] / $s['anchor_total_impressions'] ) * 100 ) : 0 ); ?>%)</div>
		</div>
	</div>
	<?php
}

/* ================================================================
 * 4. SECTION 2 — TREND CHARTS
 * ================================================================ */

function pl_ad_render_trend_charts( $daily, $start, $end ) {
	if ( count( $daily ) <= 1 ) {
		return;
	}

	?>
	<div class="pl-section">
		<h2>Daily Trends</h2>
		<div class="pl-grid-2">
			<!-- Sessions + Gate Opens trend -->
			<div>
				<h4 style="margin:0 0 10px;">Sessions &amp; Gate Opens</h4>
				<?php pl_ad_render_bar_chart( $daily, array( 'total_sessions' => '#2271b1', 'gate_opens' => '#00a32a' ), 'Sessions / Gate Opens' ); ?>
			</div>

			<!-- Fill Rate trend -->
			<div>
				<h4 style="margin:0 0 10px;">Fill Rate %</h4>
				<?php
				$fill_rates = array();
				foreach ( $daily as $date => $d ) {
					$fill_rates[ $date ] = array(
						'fill_rate' => $d['total_ad_requests'] > 0 ? round( ( $d['total_ad_fills'] / $d['total_ad_requests'] ) * 100 ) : 0,
					);
				}
				pl_ad_render_bar_chart( $fill_rates, array( 'fill_rate' => '#2271b1' ), 'Fill Rate %', true );
				?>
			</div>
		</div>
	</div>
	<?php
}

/**
 * Render a simple CSS bar chart from daily data.
 *
 * @param array  $daily   Associative array date => stats.
 * @param array  $series  key => color mapping.
 * @param string $label   Chart label.
 * @param bool   $is_pct  Whether values are percentages (max 100).
 */
function pl_ad_render_bar_chart( $daily, $series, $label, $is_pct = false ) {
	$max_val = 1;
	foreach ( $daily as $d ) {
		foreach ( $series as $key => $color ) {
			$val = $d[ $key ] ?? 0;
			if ( $val > $max_val ) {
				$max_val = $val;
			}
		}
	}
	if ( $is_pct ) {
		$max_val = 100;
	}

	echo '<div style="display:flex;align-items:flex-end;gap:3px;height:120px;padding:0 0 20px;">';
	foreach ( $daily as $date => $d ) {
		$short_date = gmdate( 'M j', strtotime( $date ) );
		echo '<div style="flex:1;text-align:center;position:relative;">';
		foreach ( $series as $key => $color ) {
			$val = $d[ $key ] ?? 0;
			$h   = $max_val > 0 ? round( ( $val / $max_val ) * 100 ) : 0;
			echo '<div style="height:' . $h . 'px;background:' . esc_attr( $color ) . ';border-radius:2px 2px 0 0;margin:0 1px;opacity:0.85;" title="' . esc_attr( $key . ': ' . $val ) . '"></div>';
		}
		echo '<div style="position:absolute;bottom:-18px;left:0;right:0;font-size:10px;color:#888;">' . esc_html( $short_date ) . '</div>';
		echo '</div>';
	}
	echo '</div>';

	// Legend.
	echo '<div style="display:flex;gap:15px;margin-top:5px;font-size:11px;">';
	foreach ( $series as $key => $color ) {
		$readable = ucwords( str_replace( '_', ' ', $key ) );
		echo '<span><span style="display:inline-block;width:10px;height:10px;background:' . esc_attr( $color ) . ';border-radius:2px;margin-right:3px;"></span>' . esc_html( $readable ) . '</span>';
	}
	echo '</div>';
}

/* ================================================================
 * 5. SECTION 3 — FILL RATE ANALYSIS
 * ================================================================ */

function pl_ad_render_fill_analysis( $s ) {
	$fill_rate  = $s['total_ad_requests'] > 0 ? round( ( $s['total_ad_fills'] / $s['total_ad_requests'] ) * 100 ) : 0;
	$waldo_rate = $s['waldo_total_requested'] > 0 ? round( ( $s['waldo_total_filled'] / $s['waldo_total_requested'] ) * 100 ) : 0;

	// Effective display fill (what % of zone activations ended with an ad showing).
	$display_showing = 0;
	foreach ( $s['by_zone'] as $z ) {
		$display_showing += ( $z['filled'] ?? 0 ) + ( $z['passback_filled'] ?? 0 );
	}
	$effective = $s['total_zones_activated'] > 0 ? round( ( $display_showing / $s['total_zones_activated'] ) * 100 ) : 0;

	?>
	<div class="pl-section">
		<h2>Fill Rate Analysis</h2>
		<div class="pl-grid-3">
			<div>
				<h4>Ad.Plus (Primary)</h4>
				<div style="font-size:36px;font-weight:700;color:#2271b1;"><?php echo $fill_rate; ?>%</div>
				<p style="color:#666;font-size:13px;"><?php echo $s['total_ad_fills']; ?> filled / <?php echo $s['total_ad_requests']; ?> requested</p>
				<div class="pl-bar"><div class="pl-bar-fill" style="width:<?php echo $fill_rate; ?>%;background:#2271b1;"></div></div>
			</div>
			<div>
				<h4>Newor Media (Backfill)</h4>
				<div style="font-size:36px;font-weight:700;color:#8c5fc7;"><?php echo $waldo_rate; ?>%</div>
				<p style="color:#666;font-size:13px;"><?php echo $s['waldo_total_filled']; ?> filled / <?php echo $s['waldo_total_requested']; ?> attempted</p>
				<div class="pl-bar"><div class="pl-bar-fill" style="width:<?php echo $waldo_rate; ?>%;background:#8c5fc7;"></div></div>
			</div>
			<div>
				<h4>Effective Fill (Combined)</h4>
				<div style="font-size:36px;font-weight:700;color:#00a32a;"><?php echo $effective; ?>%</div>
				<p style="color:#666;font-size:13px;"><?php echo $display_showing; ?> ads shown / <?php echo $s['total_zones_activated']; ?> zones</p>
				<div class="pl-bar"><div class="pl-bar-fill" style="width:<?php echo $effective; ?>%;background:#00a32a;"></div></div>
			</div>
		</div>

		<!-- Fill funnel -->
		<h4 style="margin-top:20px;">Fill Funnel</h4>
		<table class="pl-table" style="max-width:600px;">
			<tr><td>Zones Activated</td><td class="num"><strong><?php echo $s['total_zones_activated']; ?></strong></td><td class="num">100%</td></tr>
			<tr><td>&rarr; Ad.Plus Filled</td><td class="num" style="color:#2271b1;"><?php echo $s['total_ad_fills']; ?></td><td class="num"><?php echo $s['total_zones_activated'] > 0 ? round( ( $s['total_ad_fills'] / $s['total_zones_activated'] ) * 100 ) : 0; ?>%</td></tr>
			<tr><td>&rarr; Ad.Plus Empty &rarr; Passed to Newor</td><td class="num" style="color:#8c5fc7;"><?php echo $s['waldo_total_requested']; ?></td><td class="num"><?php echo $s['total_zones_activated'] > 0 ? round( ( $s['waldo_total_requested'] / $s['total_zones_activated'] ) * 100 ) : 0; ?>%</td></tr>
			<tr><td>&nbsp;&nbsp;&rarr; Newor Filled</td><td class="num" style="color:#8c5fc7;"><?php echo $s['waldo_total_filled']; ?></td><td class="num"><?php echo $s['waldo_total_requested'] > 0 ? round( ( $s['waldo_total_filled'] / $s['waldo_total_requested'] ) * 100 ) : 0; ?>%</td></tr>
			<tr><td>&nbsp;&nbsp;&rarr; Both Empty (Collapsed)</td><td class="num" style="color:#d63638;"><?php echo max( 0, $s['total_ad_empty'] - $s['waldo_total_filled'] ); ?></td><td class="num"></td></tr>
		</table>
	</div>
	<?php
}

/* ================================================================
 * 6. SECTION 4 — VIEWABILITY REPORT
 * ================================================================ */

function pl_ad_render_viewability_report( $s ) {
	$viewability = $s['total_zones_activated'] > 0 ? round( ( $s['total_viewable_ads'] / $s['total_zones_activated'] ) * 100 ) : 0;

	?>
	<div class="pl-section">
		<h2>Viewability Report</h2>
		<div class="pl-grid-2">
			<div>
				<h4>Display Ad Viewability</h4>
				<div style="font-size:36px;font-weight:700;color:<?php echo $viewability >= 70 ? '#00a32a' : ( $viewability >= 50 ? '#dba617' : '#d63638' ); ?>;"><?php echo $viewability; ?>%</div>
				<p style="color:#666;font-size:13px;"><?php echo $s['total_viewable_ads']; ?> viewable out of <?php echo $s['total_zones_activated']; ?> activated</p>
				<p style="font-size:12px;color:#888;">Industry target: 70%+ | Premium: 80%+</p>
			</div>
			<div>
				<h4>By Zone</h4>
				<?php
				// Sort zones by viewability rate.
				$zone_view = array();
				foreach ( $s['by_zone'] as $zid => $z ) {
					$total = ( $z['filled'] ?? 0 ) + ( $z['passback_filled'] ?? 0 );
					$rate  = $total > 0 ? round( ( ( $z['viewable'] ?? 0 ) / $total ) * 100 ) : 0;
					$zone_view[ $zid ] = array(
						'rate'     => $rate,
						'viewable' => $z['viewable'] ?? 0,
						'total'    => $total,
					);
				}
				arsort( $zone_view );

				foreach ( $zone_view as $zid => $zv ) {
					$color = $zv['rate'] >= 70 ? '#00a32a' : ( $zv['rate'] >= 50 ? '#dba617' : '#d63638' );
					echo '<div class="pl-bar">';
					echo '<div class="pl-bar-fill" style="width:' . $zv['rate'] . '%;background:' . esc_attr( $color ) . ';"></div>';
					echo '<span class="pl-bar-label">' . esc_html( $zid ) . '</span>';
					echo '<span class="pl-bar-value">' . $zv['rate'] . '% (' . $zv['viewable'] . '/' . $zv['total'] . ')</span>';
					echo '</div>';
				}
				?>
			</div>
		</div>
	</div>
	<?php
}

/* ================================================================
 * 7. SECTION 5 — OVERLAY PERFORMANCE
 * ================================================================ */

function pl_ad_render_overlay_performance( $s ) {
	?>
	<div class="pl-section">
		<h2>Overlay Performance</h2>
		<table class="pl-table">
			<thead>
				<tr>
					<th>Format</th>
					<th class="num">Triggered</th>
					<th class="num">Filled</th>
					<th class="num">Fill Rate</th>
					<th class="num">Viewable</th>
					<th class="num">Viewability</th>
					<th class="num">Impressions</th>
					<th class="num">Avg Visible Time</th>
					<th class="num">Clicks</th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><strong>Anchor (Sticky)</strong></td>
					<td class="num"><?php echo $s['anchor_fired']; ?></td>
					<td class="num"><?php echo $s['anchor_filled']; ?></td>
					<td class="num"><?php echo $s['anchor_fired'] > 0 ? round( ( $s['anchor_filled'] / $s['anchor_fired'] ) * 100 ) : 0; ?>%</td>
					<td class="num"><?php echo $s['anchor_total_viewable']; ?></td>
					<td class="num"><?php echo $s['anchor_total_impressions'] > 0 ? round( ( $s['anchor_total_viewable'] / $s['anchor_total_impressions'] ) * 100 ) : 0; ?>%</td>
					<td class="num"><?php echo $s['anchor_total_impressions']; ?></td>
					<td class="num"><?php echo $s['anchor_fired'] > 0 ? round( $s['anchor_total_visible_ms'] / $s['anchor_fired'] / 1000, 1 ) : 0; ?>s</td>
					<td class="num"><?php echo $s['total_anchor_clicks']; ?></td>
				</tr>
				<tr>
					<td><strong>Interstitial</strong></td>
					<td class="num"><?php echo $s['interstitial_fired']; ?></td>
					<td class="num"><?php echo $s['interstitial_filled']; ?></td>
					<td class="num"><?php echo $s['interstitial_fired'] > 0 ? round( ( $s['interstitial_filled'] / $s['interstitial_fired'] ) * 100 ) : 0; ?>%</td>
					<td class="num"><?php echo $s['interstitial_viewable']; ?></td>
					<td class="num"><?php echo $s['interstitial_fired'] > 0 ? round( ( $s['interstitial_viewable'] / $s['interstitial_fired'] ) * 100 ) : 0; ?>%</td>
					<td class="num"><?php echo $s['interstitial_fired']; ?></td>
					<td class="num"><?php echo $s['interstitial_fired'] > 0 ? round( $s['interstitial_total_duration_ms'] / $s['interstitial_fired'] / 1000, 1 ) : 0; ?>s</td>
					<td class="num"><?php echo $s['total_interstitial_clicks']; ?></td>
				</tr>
				<tr>
					<td><strong>Pause Banner</strong></td>
					<td class="num"><?php echo $s['pause_fired']; ?></td>
					<td class="num"><?php echo $s['pause_filled']; ?></td>
					<td class="num"><?php echo $s['pause_fired'] > 0 ? round( ( $s['pause_filled'] / $s['pause_fired'] ) * 100 ) : 0; ?>%</td>
					<td class="num"><?php echo $s['pause_viewable']; ?></td>
					<td class="num"><?php echo $s['pause_fired'] > 0 ? round( ( $s['pause_viewable'] / $s['pause_fired'] ) * 100 ) : 0; ?>%</td>
					<td class="num"><?php echo $s['pause_fired']; ?></td>
					<td class="num"><?php echo $s['pause_fired'] > 0 ? round( $s['pause_total_visible_ms'] / $s['pause_fired'] / 1000, 1 ) : 0; ?>s</td>
					<td class="num"><?php echo $s['total_pause_clicks']; ?></td>
				</tr>
			</tbody>
		</table>
	</div>
	<?php
}

/* ================================================================
 * 8. SECTION 6 — PASSBACK REPORT
 * ================================================================ */

function pl_ad_render_passback_report( $s ) {
	if ( $s['waldo_total_requested'] === 0 && $s['total_ad_empty'] === 0 ) {
		return;
	}

	?>
	<div class="pl-section">
		<h2>Passback Report (Ad.Plus &rarr; Newor Media)</h2>
		<div class="pl-grid-2">
			<div>
				<h4>Passback Waterfall</h4>
				<table class="pl-table">
					<tr><td>Ad.Plus no-fills (display)</td><td class="num"><strong><?php echo $s['total_ad_empty']; ?></strong></td></tr>
					<tr><td>&rarr; Passed to Newor Media</td><td class="num" style="color:#8c5fc7;"><?php echo $s['waldo_total_requested']; ?></td></tr>
					<tr><td>&nbsp;&nbsp;&rarr; Newor filled</td><td class="num" style="color:#00a32a;"><?php echo $s['waldo_total_filled']; ?></td></tr>
					<tr><td>&nbsp;&nbsp;&rarr; Newor also empty</td><td class="num" style="color:#d63638;"><?php echo max( 0, $s['waldo_total_requested'] - $s['waldo_total_filled'] ); ?></td></tr>
					<tr><td>Zones rescued by Newor</td><td class="num" style="color:#8c5fc7;font-weight:700;"><?php echo $s['waldo_total_filled']; ?> saved</td></tr>
				</table>
			</div>
			<div>
				<h4>Per-Zone Passback</h4>
				<?php
				foreach ( $s['by_zone'] as $zid => $z ) {
					if ( ( $z['passback_tried'] ?? 0 ) === 0 ) {
						continue;
					}
					$pb_rate = round( ( $z['passback_filled'] / $z['passback_tried'] ) * 100 );
					$color   = $z['passback_filled'] > 0 ? '#8c5fc7' : '#d63638';
					echo '<div class="pl-bar">';
					echo '<div class="pl-bar-fill" style="width:' . $pb_rate . '%;background:' . esc_attr( $color ) . ';"></div>';
					echo '<span class="pl-bar-label">' . esc_html( $zid ) . '</span>';
					echo '<span class="pl-bar-value">' . $z['passback_filled'] . '/' . $z['passback_tried'] . ' (' . $pb_rate . '%)</span>';
					echo '</div>';
				}
				?>
			</div>
		</div>
	</div>
	<?php
}

/* ================================================================
 * 9. SECTION 7 — TRAFFIC BREAKDOWN
 * ================================================================ */

function pl_ad_render_traffic_breakdown( $s ) {
	?>
	<div class="pl-section">
		<h2>Traffic Breakdown</h2>
		<div class="pl-grid-3">
			<!-- By Device -->
			<div>
				<h4>By Device</h4>
				<?php pl_ad_render_distribution_bars( $s['by_device'], $s['total_sessions'], array( 'mobile' => '#2271b1', 'desktop' => '#00a32a', 'tablet' => '#dba617' ) ); ?>
			</div>

			<!-- By Referrer -->
			<div>
				<h4>By Referrer</h4>
				<?php pl_ad_render_distribution_bars( $s['by_referrer'], $s['total_sessions'], array( 'pinterest' => '#E60023', 'google' => '#4285F4', 'facebook' => '#1877F2', 'direct' => '#666', 'bing' => '#008373', 'other' => '#999' ) ); ?>
			</div>

			<!-- By Language -->
			<div>
				<h4>By Language</h4>
				<?php
				$lang = $s['by_language'];
				arsort( $lang );
				pl_ad_render_distribution_bars( array_slice( $lang, 0, 8, true ), $s['total_sessions'] );
				?>
			</div>
		</div>

		<div class="pl-grid-2" style="margin-top:20px;">
			<!-- By Scroll Pattern -->
			<div>
				<h4>By Visitor Type</h4>
				<?php pl_ad_render_distribution_bars( $s['by_pattern'], $s['total_sessions'], array( 'reader' => '#00a32a', 'scanner' => '#2271b1', 'bouncer' => '#d63638' ) ); ?>
			</div>

			<!-- By Hour -->
			<div>
				<h4>Sessions by Hour (UTC)</h4>
				<div class="pl-mini-chart">
					<?php
					$hour_data = $s['by_hour'] ?: array();
					$max_hour  = ! empty( $hour_data ) ? max( array_values( $hour_data ) ) : 1;
					for ( $h = 0; $h < 24; $h++ ) {
						$val    = $hour_data[ $h ] ?? 0;
						$height = $max_hour > 0 ? round( ( $val / $max_hour ) * 100 ) : 0;
						$lbl    = str_pad( $h, 2, '0', STR_PAD_LEFT ) . ':00';
						echo '<div class="pl-mini-bar" style="height:' . $height . 'px;" title="' . esc_attr( $lbl . ': ' . $val . ' sessions' ) . '"></div>';
					}
					?>
				</div>
				<div style="display:flex;justify-content:space-between;font-size:10px;color:#888;margin-top:4px;">
					<span>00:00</span><span>06:00</span><span>12:00</span><span>18:00</span><span>23:00</span>
				</div>
			</div>
		</div>
	</div>
	<?php
}

/**
 * Render horizontal distribution bars for a breakdown.
 *
 * @param array $data   Associative array of key => count.
 * @param int   $total  Total for percentage calculation.
 * @param array $colors Optional key => color mapping.
 */
function pl_ad_render_distribution_bars( $data, $total, $colors = array() ) {
	arsort( $data );
	$default_colors = array( '#2271b1', '#00a32a', '#dba617', '#d63638', '#8c5fc7', '#3582c4', '#a0a5aa', '#ccc' );
	$i = 0;

	foreach ( $data as $key => $val ) {
		$pct   = $total > 0 ? round( ( $val / $total ) * 100 ) : 0;
		$color = $colors[ $key ] ?? ( $default_colors[ $i % count( $default_colors ) ] );
		echo '<div class="pl-bar">';
		echo '<div class="pl-bar-fill" style="width:' . $pct . '%;background:' . esc_attr( $color ) . ';"></div>';
		echo '<span class="pl-bar-label">' . esc_html( $key ) . '</span>';
		echo '<span class="pl-bar-value">' . $val . ' (' . $pct . '%)</span>';
		echo '</div>';
		$i++;
	}
}

/* ================================================================
 * 10. SECTION 8 — ZONE PERFORMANCE
 * ================================================================ */

function pl_ad_render_zone_performance( $s ) {
	if ( empty( $s['by_zone'] ) ) {
		return;
	}

	?>
	<div class="pl-section">
		<h2>Zone Performance</h2>
		<table class="pl-table">
			<thead>
				<tr>
					<th>Zone</th>
					<th class="num">Activations</th>
					<th class="num">Ad.Plus Fill</th>
					<th class="num">Passback Fill</th>
					<th class="num">Combined Fill</th>
					<th class="num">Viewable</th>
					<th class="num">Viewability</th>
					<th class="num">Avg Visible</th>
					<th class="num">Clicks</th>
					<th class="num">CTR</th>
				</tr>
			</thead>
			<tbody>
				<?php
				// Sort by activations descending.
				$zones = $s['by_zone'];
				uasort( $zones, function( $a, $b ) {
					return ( $b['activations'] ?? 0 ) - ( $a['activations'] ?? 0 );
				} );

				foreach ( $zones as $zid => $z ) {
					$combined      = ( $z['filled'] ?? 0 ) + ( $z['passback_filled'] ?? 0 );
					$activations   = $z['activations'] ?? 0;
					$combined_rate = $activations > 0 ? round( ( $combined / $activations ) * 100 ) : 0;
					$adplus_rate   = $activations > 0 ? round( ( ( $z['filled'] ?? 0 ) / $activations ) * 100 ) : 0;
					$view_rate     = $combined > 0 ? round( ( ( $z['viewable'] ?? 0 ) / $combined ) * 100 ) : 0;
					$avg_vis       = ( $z['viewable'] ?? 0 ) > 0 ? round( ( $z['total_visible_ms'] ?? 0 ) / $z['viewable'] / 1000, 1 ) : 0;
					$ctr           = $combined > 0 ? round( ( ( $z['clicks'] ?? 0 ) / $combined ) * 100, 2 ) : 0;

					$fill_color = $combined_rate >= 70 ? '#00a32a' : ( $combined_rate >= 40 ? '#dba617' : '#d63638' );
					$view_color = $view_rate >= 70 ? '#00a32a' : ( $view_rate >= 50 ? '#dba617' : '#d63638' );
					?>
					<tr>
						<td><strong><?php echo esc_html( $zid ); ?></strong></td>
						<td class="num"><?php echo $activations; ?></td>
						<td class="num"><?php echo $z['filled'] ?? 0; ?> <span style="color:#888;">(<?php echo $adplus_rate; ?>%)</span></td>
						<td class="num" style="color:#8c5fc7;"><?php echo $z['passback_filled'] ?? 0; ?>/<?php echo $z['passback_tried'] ?? 0; ?></td>
						<td class="num"><span style="color:<?php echo $fill_color; ?>;font-weight:600;"><?php echo $combined_rate; ?>%</span></td>
						<td class="num"><?php echo $z['viewable'] ?? 0; ?></td>
						<td class="num"><span style="color:<?php echo $view_color; ?>;"><?php echo $view_rate; ?>%</span></td>
						<td class="num"><?php echo $avg_vis; ?>s</td>
						<td class="num"><?php echo $z['clicks'] ?? 0; ?></td>
						<td class="num"><?php echo $ctr; ?>%</td>
					</tr>
					<?php
				}
				?>
			</tbody>
		</table>
	</div>
	<?php
}

/* ================================================================
 * 11. SECTION 9 — TOP POSTS
 * ================================================================ */

function pl_ad_render_top_posts( $s ) {
	if ( empty( $s['by_post'] ) ) {
		return;
	}

	// Sort by sessions descending.
	$posts = $s['by_post'];
	uasort( $posts, function( $a, $b ) {
		return ( $b['sessions'] ?? 0 ) - ( $a['sessions'] ?? 0 );
	} );
	$posts = array_slice( $posts, 0, 20, true ); // Top 20.

	?>
	<div class="pl-section">
		<h2>Top Posts by Sessions</h2>
		<table class="pl-table">
			<thead>
				<tr>
					<th>Post</th>
					<th class="num">Sessions</th>
					<th class="num">Gate Open</th>
					<th class="num">Gate Rate</th>
					<th class="num">Ads Filled</th>
					<th class="num">Viewable</th>
					<th class="num">Clicks</th>
					<th class="num">Avg Time</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $posts as $slug => $p ) :
					$gate_rate = ( $p['sessions'] ?? 0 ) > 0 ? round( ( ( $p['gate_opens'] ?? 0 ) / $p['sessions'] ) * 100 ) : 0;
					$avg_time  = ( $p['sessions'] ?? 0 ) > 0 ? round( ( $p['total_time_s'] ?? 0 ) / $p['sessions'] ) : 0;
					$display   = strlen( $slug ) > 50 ? substr( $slug, 0, 47 ) . '...' : $slug;
				?>
				<tr>
					<td title="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $display ); ?></td>
					<td class="num"><?php echo $p['sessions'] ?? 0; ?></td>
					<td class="num"><?php echo $p['gate_opens'] ?? 0; ?></td>
					<td class="num"><?php echo $gate_rate; ?>%</td>
					<td class="num"><?php echo $p['ads_filled'] ?? 0; ?></td>
					<td class="num"><?php echo $p['ads_viewable'] ?? 0; ?></td>
					<td class="num"><?php echo $p['clicks'] ?? 0; ?></td>
					<td class="num"><?php echo $avg_time; ?>s</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}

/* ================================================================
 * 12. SECTION 10 — CLICK REPORT
 * ================================================================ */

function pl_ad_render_click_report( $s ) {
	$total_clicks = $s['total_display_clicks'] + $s['total_anchor_clicks'] + $s['total_interstitial_clicks'] + $s['total_pause_clicks'];

	?>
	<div class="pl-section">
		<h2>Click Report</h2>
		<?php if ( $total_clicks === 0 ) : ?>
			<p style="color:#888;">No clicks recorded yet. Clicks are detected via iframe focus changes and typically represent 1-3% of impressions.</p>
		<?php else : ?>
		<div class="pl-grid-2">
			<div>
				<h4>Clicks by Format</h4>
				<table class="pl-table">
					<tr><td>Display Ads</td><td class="num"><strong><?php echo $s['total_display_clicks']; ?></strong></td></tr>
					<tr><td>Anchor (Sticky)</td><td class="num"><strong><?php echo $s['total_anchor_clicks']; ?></strong></td></tr>
					<tr><td>Interstitial</td><td class="num"><strong><?php echo $s['total_interstitial_clicks']; ?></strong></td></tr>
					<tr><td>Pause Banner</td><td class="num"><strong><?php echo $s['total_pause_clicks']; ?></strong></td></tr>
					<tr style="border-top:2px solid #ddd;"><td><strong>Total</strong></td><td class="num"><strong><?php echo $total_clicks; ?></strong></td></tr>
				</table>
			</div>
			<div>
				<h4>Clicks by Zone</h4>
				<?php
				foreach ( $s['by_zone'] as $zid => $z ) {
					if ( ( $z['clicks'] ?? 0 ) === 0 ) {
						continue;
					}
					$filled = ( $z['filled'] ?? 0 ) + ( $z['passback_filled'] ?? 0 );
					$ctr    = $filled > 0 ? round( ( $z['clicks'] / $filled ) * 100, 1 ) : 0;
					echo '<div class="pl-bar">';
					echo '<div class="pl-bar-fill" style="width:' . min( $ctr * 10, 100 ) . '%;background:#2271b1;"></div>';
					echo '<span class="pl-bar-label">' . esc_html( $zid ) . '</span>';
					echo '<span class="pl-bar-value">' . $z['clicks'] . ' clicks (' . $ctr . '% CTR)</span>';
					echo '</div>';
				}
				?>
			</div>
		</div>
		<?php endif; ?>
	</div>
	<?php
}
