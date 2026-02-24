<?php
/**
 * PinLightning Injection Lab — Real-Time Predictive Injection Analytics
 *
 * Auto-refreshes every 30 seconds via AJAX.
 * Shows injection type comparison, speed bracket analysis,
 * spacing effectiveness, visitor breakdown, and live injection feed.
 *
 * @package PinLightning
 * @since   5.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ================================================================
 * ADMIN MENU
 * ================================================================ */

function pl_injection_lab_menu() {
	add_submenu_page(
		'pl-ad-engine',
		'Injection Lab',
		'Injection Lab',
		'manage_options',
		'pl-injection-lab',
		'pl_injection_lab_render'
	);
}
add_action( 'admin_menu', 'pl_injection_lab_menu' );

/* ================================================================
 * AJAX: Fetch injection lab data (auto-refresh every 30s)
 * ================================================================ */

function pl_injection_lab_ajax() {
	check_ajax_referer( 'pl_injection_lab_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized' );
	}

	$range = sanitize_text_field( $_POST['range'] ?? '1h' );

	// Map range to SQL interval.
	$intervals = array(
		'1h'  => '1 HOUR',
		'6h'  => '6 HOUR',
		'24h' => '24 HOUR',
		'7d'  => '7 DAY',
	);
	$interval = $intervals[ $range ] ?? '1 HOUR';

	global $wpdb;
	$te = $wpdb->prefix . 'pl_ad_events';

	// Check table exists.
	$exists = $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
		DB_NAME, $te
	) );
	if ( ! $exists ) {
		wp_send_json_success( array( 'empty' => true, 'msg' => 'Events table does not exist yet.' ) );
	}

	$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( 'HOUR' === substr( $interval, -4 ) ? intval( $interval ) * 3600 : intval( $interval ) * 86400 ) );

	// 1. Injection type comparison.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$type_comparison = $wpdb->get_results( $wpdb->prepare(
		"SELECT
			injection_type,
			COUNT(CASE WHEN event_type = 'dynamic_inject' THEN 1 END) AS injections,
			COUNT(CASE WHEN event_type = 'impression' THEN 1 END) AS filled,
			COUNT(CASE WHEN event_type = 'viewable' THEN 1 END) AS viewable,
			AVG(CASE WHEN event_type = 'dynamic_inject' THEN scroll_speed END) AS avg_speed,
			AVG(CASE WHEN event_type = 'dynamic_inject' THEN ad_spacing END) AS avg_spacing,
			AVG(CASE WHEN event_type = 'viewable' AND time_to_viewable > 0 THEN time_to_viewable END) AS avg_ttv
		FROM {$te}
		WHERE created_at >= %s
			AND slot_type = 'dynamic'
			AND injection_type != ''
		GROUP BY injection_type",
		$cutoff
	), ARRAY_A );

	// 2. Speed bracket analysis.
	$speed_brackets = $wpdb->get_results( $wpdb->prepare(
		"SELECT
			CASE
				WHEN scroll_speed < 50 THEN '0-50'
				WHEN scroll_speed < 100 THEN '50-100'
				WHEN scroll_speed < 200 THEN '100-200'
				WHEN scroll_speed < 300 THEN '200-300'
				WHEN scroll_speed < 500 THEN '300-500'
				ELSE '500+'
			END AS speed_bracket,
			COUNT(CASE WHEN event_type = 'dynamic_inject' THEN 1 END) AS injections,
			COUNT(CASE WHEN event_type = 'viewable' THEN 1 END) AS viewable,
			AVG(CASE WHEN event_type = 'dynamic_inject' THEN ad_spacing END) AS avg_spacing,
			AVG(CASE WHEN event_type = 'viewable' AND time_to_viewable > 0 THEN time_to_viewable END) AS avg_ttv
		FROM {$te}
		WHERE created_at >= %s
			AND slot_type = 'dynamic'
			AND scroll_speed > 0
		GROUP BY speed_bracket
		ORDER BY FIELD(speed_bracket, '0-50', '50-100', '100-200', '200-300', '300-500', '500+')",
		$cutoff
	), ARRAY_A );

	// 3. Spacing effectiveness.
	$spacing_ranges = $wpdb->get_results( $wpdb->prepare(
		"SELECT
			CASE
				WHEN ad_spacing < 400 THEN '200-400'
				WHEN ad_spacing < 600 THEN '400-600'
				WHEN ad_spacing < 800 THEN '600-800'
				WHEN ad_spacing < 1000 THEN '800-1000'
				ELSE '1000+'
			END AS spacing_range,
			COUNT(CASE WHEN event_type = 'dynamic_inject' THEN 1 END) AS injections,
			COUNT(CASE WHEN event_type = 'viewable' THEN 1 END) AS viewable,
			AVG(CASE WHEN event_type = 'viewable' AND time_to_viewable > 0 THEN time_to_viewable END) AS avg_ttv
		FROM {$te}
		WHERE created_at >= %s
			AND slot_type = 'dynamic'
			AND ad_spacing > 0
		GROUP BY spacing_range
		ORDER BY FIELD(spacing_range, '200-400', '400-600', '600-800', '800-1000', '1000+')",
		$cutoff
	), ARRAY_A );

	// 4. Visitor type breakdown.
	$visitor_types = $wpdb->get_results( $wpdb->prepare(
		"SELECT
			visitor_type,
			COUNT(DISTINCT session_id) AS sessions,
			COUNT(CASE WHEN event_type = 'dynamic_inject' THEN 1 END) AS injections,
			COUNT(CASE WHEN event_type = 'viewable' THEN 1 END) AS viewable,
			SUM(CASE WHEN event_type = 'dynamic_inject' AND injection_type = 'pause' THEN 1 ELSE 0 END) AS pause_count,
			SUM(CASE WHEN event_type = 'dynamic_inject' AND injection_type = 'predictive' THEN 1 ELSE 0 END) AS predictive_count
		FROM {$te}
		WHERE created_at >= %s
			AND slot_type = 'dynamic'
			AND visitor_type != ''
		GROUP BY visitor_type",
		$cutoff
	), ARRAY_A );

	// 5. Last 20 injections (live feed).
	$recent = $wpdb->get_results( $wpdb->prepare(
		"SELECT
			created_at,
			session_id,
			slot_id,
			injection_type,
			scroll_speed,
			ad_spacing,
			event_type,
			time_to_viewable,
			visitor_type
		FROM {$te}
		WHERE created_at >= %s
			AND slot_type = 'dynamic'
			AND event_type IN ('dynamic_inject', 'impression', 'viewable', 'empty')
		ORDER BY created_at DESC
		LIMIT 60",
		$cutoff
	), ARRAY_A );
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	// Group recent by slot_id to merge inject+impression+viewable rows.
	$grouped = array();
	foreach ( $recent as $row ) {
		$key = $row['slot_id'];
		if ( ! isset( $grouped[ $key ] ) ) {
			$grouped[ $key ] = array(
				'time'           => $row['created_at'],
				'session'        => substr( $row['session_id'], 0, 8 ),
				'slot'           => $row['slot_id'],
				'type'           => $row['injection_type'] ?: '-',
				'speed'          => 0,
				'spacing'        => 0,
				'filled'         => false,
				'viewable'       => false,
				'ttv'            => 0,
				'visitor'        => $row['visitor_type'],
			);
		}
		$g = &$grouped[ $key ];
		if ( $row['event_type'] === 'dynamic_inject' ) {
			$g['time']    = $row['created_at'];
			$g['speed']   = (int) $row['scroll_speed'];
			$g['spacing'] = (int) $row['ad_spacing'];
			$g['type']    = $row['injection_type'] ?: '-';
			$g['visitor'] = $row['visitor_type'];
		}
		if ( $row['event_type'] === 'impression' ) {
			$g['filled'] = true;
		}
		if ( $row['event_type'] === 'viewable' ) {
			$g['viewable'] = true;
			$g['ttv']      = (int) $row['time_to_viewable'];
		}
	}
	$live_feed = array_values( array_slice( $grouped, 0, 20 ) );

	// 6. Generate recommendations.
	$recommendations = pl_injection_lab_recommendations( $type_comparison, $speed_brackets, $spacing_ranges, $visitor_types );

	// 7. Overview totals.
	$totals = array(
		'total_injections' => 0,
		'pause_count'      => 0,
		'predictive_count' => 0,
		'total_filled'     => 0,
		'total_viewable'   => 0,
		'total_refreshes'  => 0,
		'avg_ttv_pause'    => 0,
		'avg_ttv_predict'  => 0,
	);
	foreach ( $type_comparison as $row ) {
		$totals['total_injections'] += (int) $row['injections'];
		$totals['total_filled']     += (int) $row['filled'];
		$totals['total_viewable']   += (int) $row['viewable'];
		if ( $row['injection_type'] === 'pause' ) {
			$totals['pause_count']   = (int) $row['injections'];
			$totals['avg_ttv_pause'] = round( (float) $row['avg_ttv'] );
		}
		if ( $row['injection_type'] === 'predictive' ) {
			$totals['predictive_count']   = (int) $row['injections'];
			$totals['avg_ttv_predict'] = round( (float) $row['avg_ttv'] );
		}
		if ( $row['injection_type'] === 'viewport_refresh' ) {
			$totals['total_refreshes'] = (int) $row['injections'];
		}
	}

	wp_send_json_success( array(
		'totals'          => $totals,
		'typeComparison'  => $type_comparison,
		'speedBrackets'   => $speed_brackets,
		'spacingRanges'   => $spacing_ranges,
		'visitorTypes'    => $visitor_types,
		'liveFeed'        => $live_feed,
		'recommendations' => $recommendations,
		'range'           => $range,
	) );
}
add_action( 'wp_ajax_pl_injection_lab', 'pl_injection_lab_ajax' );

/* ================================================================
 * AUTO-GENERATED RECOMMENDATIONS
 * ================================================================ */

function pl_injection_lab_recommendations( $type_cmp, $speed, $spacing, $visitors ) {
	$recs = array();

	// Compare pause vs predictive viewability.
	$pause_view   = 0;
	$predict_view = 0;
	$pause_inj    = 0;
	$predict_inj  = 0;
	foreach ( $type_cmp as $row ) {
		if ( $row['injection_type'] === 'pause' ) {
			$pause_inj  = (int) $row['injections'];
			$pause_view = $pause_inj > 0 ? round( (int) $row['viewable'] / $pause_inj * 100 ) : 0;
		}
		if ( $row['injection_type'] === 'predictive' ) {
			$predict_inj  = (int) $row['injections'];
			$predict_view = $predict_inj > 0 ? round( (int) $row['viewable'] / $predict_inj * 100 ) : 0;
		}
	}
	if ( $pause_inj >= 5 && $predict_inj >= 5 ) {
		$diff = $pause_view - $predict_view;
		if ( $diff > 10 ) {
			$recs[] = "Pause injections have {$pause_view}% viewability vs {$predict_view}% for predictive ({$diff}pp higher) — consider increasing pause threshold to favor pause strategy.";
		} elseif ( $diff < -10 ) {
			$recs[] = "Predictive injections have {$predict_view}% viewability vs {$pause_view}% for pause (" . abs( $diff ) . "pp higher) — predictive strategy is outperforming.";
		}
	}

	// Spacing effectiveness.
	foreach ( $spacing as $row ) {
		$inj = (int) $row['injections'];
		$vw  = (int) $row['viewable'];
		if ( $inj < 5 ) continue;
		$rate = round( $vw / $inj * 100 );
		$range = $row['spacing_range'];
		if ( $range === '200-400' && $rate > 50 ) {
			$recs[] = "Spacing below 400px has {$rate}% viewability — safe to reduce minimum spacing for readers.";
		}
		if ( $range === '200-400' && $rate < 30 ) {
			$recs[] = "Spacing below 400px has only {$rate}% viewability — consider increasing reader spacing.";
		}
	}

	// Speed bracket insights.
	foreach ( $speed as $row ) {
		$inj  = (int) $row['injections'];
		$vw   = (int) $row['viewable'];
		if ( $inj < 5 ) continue;
		$rate  = round( $vw / $inj * 100 );
		$label = $row['speed_bracket'];
		if ( $label === '200-300' && $rate < 30 ) {
			$recs[] = "Speed bracket 200-300 px/s has low {$rate}% viewability — increase prediction distance for scanners.";
		}
	}

	// Fast-scanner pause effectiveness.
	foreach ( $visitors as $row ) {
		if ( $row['visitor_type'] !== 'fast-scanner' ) continue;
		$pc = (int) $row['pause_count'];
		$vw = (int) $row['viewable'];
		$inj = (int) $row['injections'];
		if ( $inj < 5 ) continue;
		$rate = round( $vw / $inj * 100 );
		if ( $rate > 30 && $pc > 0 ) {
			$recs[] = "Fast-scanners generate {$rate}% viewable impressions (pause={$pc}, predictive=" . (int) $row['predictive_count'] . ") — worth targeting.";
		}
	}

	if ( empty( $recs ) ) {
		$recs[] = 'Not enough data for recommendations yet. Check back after more traffic.';
	}

	return $recs;
}

/* ================================================================
 * ADMIN PAGE RENDER
 * ================================================================ */

function pl_injection_lab_render() {
	if ( ! current_user_can( 'manage_options' ) ) return;

	$nonce = wp_create_nonce( 'pl_injection_lab_nonce' );
	?>
	<div class="wrap" id="pl-injection-lab">
		<h1>Injection Lab &mdash; Predictive Injection Analytics</h1>

		<!-- Navigation -->
		<div style="margin-bottom:16px;display:flex;gap:8px;flex-wrap:wrap;">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=pl-ad-analytics-dashboard' ) ); ?>" class="button">Analytics Dashboard</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=pl-ad-live-sessions' ) ); ?>" class="button">Live Sessions</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=pl-ad-optimizer' ) ); ?>" class="button">Optimizer</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=pl-ad-recommendations' ) ); ?>" class="button">Recommendations</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=pl-ad-engine' ) ); ?>" class="button">Ad Engine Settings</a>
		</div>

		<!-- Range selector + auto-refresh indicator -->
		<div style="margin-bottom:16px;display:flex;gap:12px;align-items:center;">
			<select id="labRange">
				<option value="1h" selected>Last 1 Hour</option>
				<option value="6h">Last 6 Hours</option>
				<option value="24h">Last 24 Hours</option>
				<option value="7d">Last 7 Days</option>
			</select>
			<button type="button" id="labRefresh" class="button button-primary">Refresh Now</button>
			<span id="labStatus" style="font-size:12px;color:#888;"></span>
			<label style="margin-left:auto;font-size:12px;color:#666;">
				<input type="checkbox" id="labAutoRefresh" checked> Auto-refresh (30s)
			</label>
		</div>

		<!-- Overview Cards -->
		<div id="labCards" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin-bottom:20px;"></div>

		<!-- Type Comparison Table -->
		<div class="lab-section">
			<h3>Injection Type Comparison</h3>
			<table class="widefat striped" style="font-size:13px;">
				<thead><tr><th>Type</th><th class="num">Injections</th><th class="num">Filled</th><th class="num">Fill%</th><th class="num">Viewable</th><th class="num">View%</th><th class="num">Avg Speed</th><th class="num">Avg Spacing</th><th class="num">Avg TTV</th></tr></thead>
				<tbody id="labTypeTable"><tr><td colspan="9" style="text-align:center;color:#888;">Loading...</td></tr></tbody>
			</table>
		</div>

		<!-- Speed Bracket Table -->
		<div class="lab-section">
			<h3>Viewability by Scroll Speed</h3>
			<table class="widefat striped" style="font-size:13px;">
				<thead><tr><th>Speed Range</th><th class="num">Injections</th><th class="num">Viewable</th><th class="num">View%</th><th class="num">Avg TTV</th><th class="num">Avg Spacing</th></tr></thead>
				<tbody id="labSpeedTable"><tr><td colspan="6" style="text-align:center;color:#888;">Loading...</td></tr></tbody>
			</table>
		</div>

		<!-- Spacing Table -->
		<div class="lab-section">
			<h3>Spacing Effectiveness</h3>
			<table class="widefat striped" style="font-size:13px;">
				<thead><tr><th>Spacing Range</th><th class="num">Injections</th><th class="num">Viewable</th><th class="num">View%</th><th class="num">Avg TTV</th></tr></thead>
				<tbody id="labSpacingTable"><tr><td colspan="5" style="text-align:center;color:#888;">Loading...</td></tr></tbody>
			</table>
		</div>

		<!-- Visitor Type Table -->
		<div class="lab-section">
			<h3>Visitor Type Breakdown</h3>
			<table class="widefat striped" style="font-size:13px;">
				<thead><tr><th>Visitor Type</th><th class="num">Sessions</th><th class="num">Injections</th><th class="num">Inj/Session</th><th class="num">Viewable</th><th class="num">View%</th><th class="num">Pause%</th><th class="num">Predictive%</th></tr></thead>
				<tbody id="labVisitorTable"><tr><td colspan="8" style="text-align:center;color:#888;">Loading...</td></tr></tbody>
			</table>
		</div>

		<!-- Live Feed -->
		<div class="lab-section">
			<h3>Last 20 Injections (Live Feed)</h3>
			<table class="widefat striped" style="font-size:12px;">
				<thead><tr><th>Time</th><th>Session</th><th>Slot</th><th>Type</th><th>Visitor</th><th class="num">Speed</th><th class="num">Spacing</th><th>Filled</th><th>Viewable</th><th class="num">TTV</th></tr></thead>
				<tbody id="labFeedTable"><tr><td colspan="10" style="text-align:center;color:#888;">Loading...</td></tr></tbody>
			</table>
		</div>

		<!-- Recommendations -->
		<div class="lab-section">
			<h3>Recommendations</h3>
			<div id="labRecs" style="font-size:13px;color:#666;">Loading...</div>
		</div>
	</div>

	<style>
	#pl-injection-lab { max-width: 1200px; }
	.lab-section { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 16px; margin-bottom: 16px; }
	.lab-section h3 { margin: 0 0 12px; font-size: 14px; font-weight: 600; }
	.lab-card { background: #fff; border: 1px solid #c3c4c7; border-radius: 6px; padding: 16px; }
	.lab-card-label { font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
	.lab-card-value { font-size: 24px; font-weight: 700; color: #1d2327; }
	.lab-card-sub { font-size: 11px; color: #666; margin-top: 2px; }
	.num { text-align: right; }
	.lab-badge { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; }
	.badge-pause { background: #d4edda; color: #155724; }
	.badge-predictive { background: #cce5ff; color: #004085; }
	.badge-refresh { background: #fff3cd; color: #856404; }
	.badge-yes { color: #00a32a; }
	.badge-no { color: #d63638; }
	</style>

	<script>
	(function() {
		var AJAX_URL = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
		var NONCE    = <?php echo wp_json_encode( $nonce ); ?>;
		var timer    = null;

		function pct(a, b) { return b > 0 ? Math.round(a / b * 100) : 0; }
		function fmt(n) { return n !== null && n !== undefined ? Math.round(n).toLocaleString() : '-'; }
		function fmtMs(n) { return n > 0 ? (n / 1000).toFixed(1) + 's' : '-'; }
		function badgeType(t) {
			if (t === 'pause') return '<span class="lab-badge badge-pause">pause</span>';
			if (t === 'predictive') return '<span class="lab-badge badge-predictive">predictive</span>';
			if (t === 'viewport_refresh') return '<span class="lab-badge badge-refresh">refresh</span>';
			return t || '-';
		}
		function yn(v) { return v ? '<span class="badge-yes">&#10003;</span>' : '<span class="badge-no">&#10007;</span>'; }

		function load() {
			var range = document.getElementById('labRange').value;
			document.getElementById('labStatus').textContent = 'Loading...';

			var fd = new FormData();
			fd.append('action', 'pl_injection_lab');
			fd.append('nonce', NONCE);
			fd.append('range', range);

			fetch(AJAX_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function(r) { return r.json(); })
				.then(function(res) {
					if (!res.success) {
						document.getElementById('labStatus').textContent = 'Error: ' + (res.data || 'Unknown');
						return;
					}
					var d = res.data;
					if (d.empty) {
						document.getElementById('labStatus').textContent = d.msg || 'No data yet.';
						return;
					}
					renderCards(d.totals);
					renderTypeTable(d.typeComparison);
					renderSpeedTable(d.speedBrackets);
					renderSpacingTable(d.spacingRanges);
					renderVisitorTable(d.visitorTypes);
					renderFeed(d.liveFeed);
					renderRecs(d.recommendations);
					document.getElementById('labStatus').textContent = 'Updated ' + new Date().toLocaleTimeString();
				})
				.catch(function() {
					document.getElementById('labStatus').textContent = 'Network error';
				});
		}

		function renderCards(t) {
			var fillRate = pct(t.total_filled, t.total_injections);
			var viewRate = pct(t.total_viewable, t.total_filled);
			var pausePct = pct(t.pause_count, t.total_injections);
			var predictPct = pct(t.predictive_count, t.total_injections);
			var html = '';
			html += card('Total Injections', t.total_injections, 'Pause: ' + t.pause_count + ' (' + pausePct + '%) | Predictive: ' + t.predictive_count + ' (' + predictPct + '%)');
			html += card('Fill Rate', fillRate + '%', t.total_filled + ' / ' + t.total_injections + ' filled');
			html += card('Viewability', viewRate + '%', t.total_viewable + ' / ' + t.total_filled + ' viewable');
			html += card('Avg TTV (Pause)', fmtMs(t.avg_ttv_pause), 'Time to viewable');
			html += card('Avg TTV (Predictive)', fmtMs(t.avg_ttv_predict), 'Time to viewable');
			html += card('Viewport Refreshes', t.total_refreshes, 'In-view ad refreshes');
			document.getElementById('labCards').innerHTML = html;
		}

		function card(label, value, sub) {
			return '<div class="lab-card"><div class="lab-card-label">' + label + '</div><div class="lab-card-value">' + value + '</div><div class="lab-card-sub">' + sub + '</div></div>';
		}

		function renderTypeTable(rows) {
			if (!rows || !rows.length) { document.getElementById('labTypeTable').innerHTML = '<tr><td colspan="9" style="text-align:center;color:#888;">No data</td></tr>'; return; }
			var totalInj = 0, totalFilled = 0, totalView = 0;
			var html = '';
			rows.forEach(function(r) {
				var inj = parseInt(r.injections) || 0;
				var filled = parseInt(r.filled) || 0;
				var viewable = parseInt(r.viewable) || 0;
				totalInj += inj; totalFilled += filled; totalView += viewable;
				html += '<tr><td>' + badgeType(r.injection_type) + '</td><td class="num">' + fmt(inj) + '</td><td class="num">' + fmt(filled) + '</td><td class="num">' + pct(filled, inj) + '%</td><td class="num">' + fmt(viewable) + '</td><td class="num">' + pct(viewable, inj) + '%</td><td class="num">' + fmt(r.avg_speed) + '</td><td class="num">' + fmt(r.avg_spacing) + '</td><td class="num">' + fmtMs(r.avg_ttv) + '</td></tr>';
			});
			html += '<tr style="font-weight:700;border-top:2px solid #c3c4c7;"><td>Total</td><td class="num">' + fmt(totalInj) + '</td><td class="num">' + fmt(totalFilled) + '</td><td class="num">' + pct(totalFilled, totalInj) + '%</td><td class="num">' + fmt(totalView) + '</td><td class="num">' + pct(totalView, totalInj) + '%</td><td class="num">-</td><td class="num">-</td><td class="num">-</td></tr>';
			document.getElementById('labTypeTable').innerHTML = html;
		}

		function renderSpeedTable(rows) {
			if (!rows || !rows.length) { document.getElementById('labSpeedTable').innerHTML = '<tr><td colspan="6" style="text-align:center;color:#888;">No data</td></tr>'; return; }
			var labels = {'0-50': 'paused', '50-100': 'slow reader', '100-200': 'reader', '200-300': 'scanner', '300-500': 'fast', '500+': 'very fast'};
			var html = '';
			rows.forEach(function(r) {
				var inj = parseInt(r.injections) || 0;
				var vw = parseInt(r.viewable) || 0;
				html += '<tr><td>' + r.speed_bracket + ' px/s <span style="color:#888;font-size:11px;">(' + (labels[r.speed_bracket] || '') + ')</span></td><td class="num">' + fmt(inj) + '</td><td class="num">' + fmt(vw) + '</td><td class="num">' + pct(vw, inj) + '%</td><td class="num">' + fmtMs(r.avg_ttv) + '</td><td class="num">' + fmt(r.avg_spacing) + 'px</td></tr>';
			});
			document.getElementById('labSpeedTable').innerHTML = html;
		}

		function renderSpacingTable(rows) {
			if (!rows || !rows.length) { document.getElementById('labSpacingTable').innerHTML = '<tr><td colspan="5" style="text-align:center;color:#888;">No data</td></tr>'; return; }
			var html = '';
			rows.forEach(function(r) {
				var inj = parseInt(r.injections) || 0;
				var vw = parseInt(r.viewable) || 0;
				html += '<tr><td>' + r.spacing_range + 'px</td><td class="num">' + fmt(inj) + '</td><td class="num">' + fmt(vw) + '</td><td class="num">' + pct(vw, inj) + '%</td><td class="num">' + fmtMs(r.avg_ttv) + '</td></tr>';
			});
			document.getElementById('labSpacingTable').innerHTML = html;
		}

		function renderVisitorTable(rows) {
			if (!rows || !rows.length) { document.getElementById('labVisitorTable').innerHTML = '<tr><td colspan="8" style="text-align:center;color:#888;">No data</td></tr>'; return; }
			var html = '';
			rows.forEach(function(r) {
				var sess = parseInt(r.sessions) || 0;
				var inj = parseInt(r.injections) || 0;
				var vw = parseInt(r.viewable) || 0;
				var pc = parseInt(r.pause_count) || 0;
				var pr = parseInt(r.predictive_count) || 0;
				html += '<tr><td><strong>' + (r.visitor_type || '-') + '</strong></td><td class="num">' + fmt(sess) + '</td><td class="num">' + fmt(inj) + '</td><td class="num">' + (sess > 0 ? (inj / sess).toFixed(1) : '-') + '</td><td class="num">' + fmt(vw) + '</td><td class="num">' + pct(vw, inj) + '%</td><td class="num">' + pct(pc, inj) + '%</td><td class="num">' + pct(pr, inj) + '%</td></tr>';
			});
			document.getElementById('labVisitorTable').innerHTML = html;
		}

		function renderFeed(rows) {
			if (!rows || !rows.length) { document.getElementById('labFeedTable').innerHTML = '<tr><td colspan="10" style="text-align:center;color:#888;">No recent injections</td></tr>'; return; }
			var html = '';
			rows.forEach(function(r) {
				var timeStr = r.time ? r.time.split(' ')[1] || r.time : '-';
				html += '<tr><td style="white-space:nowrap">' + timeStr + '</td><td><code>' + (r.session || '-') + '</code></td><td><code>' + (r.slot || '-') + '</code></td><td>' + badgeType(r.type) + '</td><td>' + (r.visitor || '-') + '</td><td class="num">' + (r.speed || '-') + '</td><td class="num">' + (r.spacing || '-') + '</td><td>' + yn(r.filled) + '</td><td>' + yn(r.viewable) + '</td><td class="num">' + fmtMs(r.ttv) + '</td></tr>';
			});
			document.getElementById('labFeedTable').innerHTML = html;
		}

		function renderRecs(recs) {
			if (!recs || !recs.length) { document.getElementById('labRecs').innerHTML = '<p style="color:#888;">No recommendations yet.</p>'; return; }
			var html = '<ul style="margin:0;padding-left:20px;">';
			recs.forEach(function(r) {
				html += '<li style="margin-bottom:6px;">' + r + '</li>';
			});
			html += '</ul>';
			document.getElementById('labRecs').innerHTML = html;
		}

		function startTimer() {
			if (timer) clearInterval(timer);
			if (document.getElementById('labAutoRefresh').checked) {
				timer = setInterval(load, 30000);
			}
		}

		document.getElementById('labRefresh').addEventListener('click', load);
		document.getElementById('labRange').addEventListener('change', load);
		document.getElementById('labAutoRefresh').addEventListener('change', startTimer);

		// Initial load.
		load();
		startTimer();
	})();
	</script>
	<?php
}
