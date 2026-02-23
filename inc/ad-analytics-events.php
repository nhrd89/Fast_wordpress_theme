<?php
/**
 * PinLightning Ad Analytics — Event-Level Sections
 *
 * Reads from pl_ad_events and pl_ad_hourly_stats tables (ad-data-recorder.php)
 * and renders 8 analytics sections included via pl_ad_events_render_all().
 *
 * @package PinLightning
 * @since   1.0.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* --- 1. MASTER QUERY -------------------------------------------------- */

/**
 * Query both event tables for a date range.
 *
 * @param  string $start Y-m-d.
 * @param  string $end   Y-m-d.
 * @return array
 */
function pl_ad_events_query_range( $start, $end ) {
	global $wpdb;
	$te     = $wpdb->prefix . 'pl_ad_events';
	$th     = $wpdb->prefix . 'pl_ad_hourly_stats';
	$end_ts = $end . ' 23:59:59';

	$totals = $wpdb->get_results( $wpdb->prepare(
		"SELECT event_type, COUNT(*) AS cnt FROM {$te}
		 WHERE created_at BETWEEN %s AND %s GROUP BY event_type",
		$start, $end_ts
	), OBJECT_K );

	$by_slot = $wpdb->get_results( $wpdb->prepare(
		"SELECT slot_type,
			SUM(CASE WHEN event_type='impression' THEN 1 ELSE 0 END) AS impressions,
			SUM(CASE WHEN event_type='empty' THEN 1 ELSE 0 END) AS empties,
			SUM(CASE WHEN event_type='viewable' THEN 1 ELSE 0 END) AS viewables,
			SUM(CASE WHEN event_type='refresh' THEN 1 ELSE 0 END) AS refreshes,
			SUM(CASE WHEN event_type='refresh_skip' THEN 1 ELSE 0 END) AS refresh_skips
		 FROM {$te} WHERE created_at BETWEEN %s AND %s
		 GROUP BY slot_type ORDER BY impressions DESC",
		$start, $end_ts
	), ARRAY_A );

	$hourly = $wpdb->get_results( $wpdb->prepare(
		"SELECT DATE_FORMAT(hour, '%%Y-%%m-%%d %%H:00') AS h,
			SUM(impressions) AS impressions, SUM(viewables) AS viewables
		 FROM {$th} WHERE hour BETWEEN %s AND %s GROUP BY h ORDER BY h ASC",
		$start, $end_ts
	), ARRAY_A );

	$by_device = $wpdb->get_results( $wpdb->prepare(
		"SELECT device,
			SUM(CASE WHEN event_type='impression' THEN 1 ELSE 0 END) AS impressions,
			SUM(CASE WHEN event_type='empty' THEN 1 ELSE 0 END) AS empties,
			SUM(CASE WHEN event_type='viewable' THEN 1 ELSE 0 END) AS viewables
		 FROM {$te} WHERE created_at BETWEEN %s AND %s GROUP BY device",
		$start, $end_ts
	), ARRAY_A );

	$by_visitor = $wpdb->get_results( $wpdb->prepare(
		"SELECT visitor_type,
			SUM(CASE WHEN event_type='impression' THEN 1 ELSE 0 END) AS impressions,
			SUM(CASE WHEN event_type='viewable' THEN 1 ELSE 0 END) AS viewables,
			AVG(scroll_percent) AS avg_scroll, AVG(time_on_page) AS avg_time
		 FROM {$te} WHERE created_at BETWEEN %s AND %s GROUP BY visitor_type",
		$start, $end_ts
	), ARRAY_A );

	$by_size = $wpdb->get_results( $wpdb->prepare(
		"SELECT creative_size, COUNT(*) AS cnt,
			SUM(CASE WHEN event_type='viewable' THEN 1 ELSE 0 END) AS viewables
		 FROM {$te} WHERE created_at BETWEEN %s AND %s AND creative_size != ''
		 GROUP BY creative_size ORDER BY cnt DESC",
		$start, $end_ts
	), ARRAY_A );

	$refresh_by_slot = $wpdb->get_results( $wpdb->prepare(
		"SELECT slot_type, COUNT(*) AS cnt FROM {$te}
		 WHERE created_at BETWEEN %s AND %s AND event_type IN ('refresh','refresh_skip')
		 GROUP BY slot_type ORDER BY cnt DESC",
		$start, $end_ts
	), ARRAY_A );

	$by_post = $wpdb->get_results( $wpdb->prepare(
		"SELECT post_id,
			SUM(CASE WHEN event_type='impression' THEN 1 ELSE 0 END) AS impressions,
			SUM(CASE WHEN event_type='empty' THEN 1 ELSE 0 END) AS empties,
			SUM(CASE WHEN event_type='viewable' THEN 1 ELSE 0 END) AS viewables,
			SUM(CASE WHEN event_type='refresh' THEN 1 ELSE 0 END) AS refreshes
		 FROM {$te} WHERE created_at BETWEEN %s AND %s AND post_id > 0
		 GROUP BY post_id ORDER BY impressions DESC LIMIT 20",
		$start, $end_ts
	), ARRAY_A );

	$cnt = function ( $type ) use ( $totals ) {
		return isset( $totals[ $type ] ) ? (int) $totals[ $type ]->cnt : 0;
	};

	return array(
		'impressions'     => $cnt( 'impression' ),
		'empties'         => $cnt( 'empty' ),
		'viewables'       => $cnt( 'viewable' ),
		'refreshes'       => $cnt( 'refresh' ),
		'refresh_skips'   => $cnt( 'refresh_skip' ),
		'dynamic_injects' => $cnt( 'dynamic_inject' ),
		'video_injects'   => $cnt( 'video_inject' ),
		'by_slot'         => $by_slot,
		'hourly'          => $hourly,
		'by_device'       => $by_device,
		'by_visitor'      => $by_visitor,
		'by_size'         => $by_size,
		'refresh_by_slot' => $refresh_by_slot,
		'by_post'         => $by_post,
	);
}

/* --- 2. SECTION A — OVERVIEW CARDS ------------------------------------ */

function pl_ad_events_render_overview( $d ) {
	$imps      = $d['impressions'];
	$empties   = $d['empties'];
	$viewables = $d['viewables'];
	$refreshes = $d['refreshes'];
	$skip_rate = ( $refreshes + $d['refresh_skips'] ) > 0
		? round( $d['refresh_skips'] / ( $refreshes + $d['refresh_skips'] ) * 100, 1 ) : 0;
	$fill_rate = ( $imps + $empties ) > 0
		? round( $imps / ( $imps + $empties ) * 100, 1 ) : 0;
	$view_rate = $imps > 0 ? round( $viewables / $imps * 100, 1 ) : 0;
	$est_rev   = pl_ad_events_estimate_revenue( $d['by_slot'] );
	?>
	<div class="pl-cards">
		<div class="pl-card blue"><div class="pl-card-label">Total Impressions</div><div class="pl-card-value"><?php echo number_format( $imps ); ?></div></div>
		<div class="pl-card red"><div class="pl-card-label">Total Empties</div><div class="pl-card-value"><?php echo number_format( $empties ); ?></div></div>
		<div class="pl-card <?php echo $fill_rate >= 70 ? 'green' : 'red'; ?>">
			<div class="pl-card-label">Fill Rate</div>
			<div class="pl-card-value"><?php echo $fill_rate; ?>%</div>
			<div class="pl-card-sub"><?php echo number_format( $imps ); ?> / <?php echo number_format( $imps + $empties ); ?></div>
		</div>
		<div class="pl-card blue"><div class="pl-card-label">Viewable Impressions</div><div class="pl-card-value"><?php echo number_format( $viewables ); ?></div></div>
		<div class="pl-card <?php echo $view_rate >= 60 ? 'green' : 'red'; ?>"><div class="pl-card-label">Viewability</div><div class="pl-card-value"><?php echo $view_rate; ?>%</div></div>
		<div class="pl-card blue"><div class="pl-card-label">Total Refreshes</div><div class="pl-card-value"><?php echo number_format( $refreshes ); ?></div></div>
		<div class="pl-card <?php echo $skip_rate <= 30 ? 'green' : 'red'; ?>"><div class="pl-card-label">Refresh Skip Rate</div><div class="pl-card-value"><?php echo $skip_rate; ?>%</div></div>
		<div class="pl-card green"><div class="pl-card-label">Est. Revenue</div><div class="pl-card-value">$<?php echo number_format( $est_rev, 2 ); ?></div></div>
	</div>
	<?php
}

/* --- 3. SECTION B — FORMAT PERFORMANCE TABLE -------------------------- */

function pl_ad_events_render_format_table( $d ) {
	$ecpm_map = pl_ad_events_ecpm_map();
	$t_imps = $t_emp = $t_view = $t_ref = 0;
	$t_rev  = 0;
	?>
	<div class="pl-section">
		<h2>Format Performance</h2>
		<table class="pl-table">
			<thead><tr>
				<th>Slot Type</th><th class="num">Impressions</th><th class="num">Empties</th>
				<th class="num">Fill Rate</th><th class="num">Viewables</th><th class="num">Viewability</th>
				<th class="num">Refreshes</th><th class="num">eCPM</th><th class="num">Est. Revenue</th>
			</tr></thead>
			<tbody>
			<?php foreach ( $d['by_slot'] as $row ) :
				$imp  = (int) $row['impressions'];
				$emp  = (int) $row['empties'];
				$vw   = (int) $row['viewables'];
				$ref  = (int) $row['refreshes'];
				$fill = ( $imp + $emp ) > 0 ? round( $imp / ( $imp + $emp ) * 100, 1 ) : 0;
				$vr   = $imp > 0 ? round( $vw / $imp * 100, 1 ) : 0;
				$ecpm = $ecpm_map[ $row['slot_type'] ] ?? 0.50;
				$rev  = ( $imp / 1000 ) * $ecpm;
				$t_imps += $imp; $t_emp += $emp; $t_view += $vw; $t_ref += $ref; $t_rev += $rev;
			?>
				<tr>
					<td><strong><?php echo esc_html( $row['slot_type'] ); ?></strong></td>
					<td class="num"><?php echo number_format( $imp ); ?></td>
					<td class="num"><?php echo number_format( $emp ); ?></td>
					<td class="num" style="color:<?php echo $fill >= 70 ? '#00a32a' : '#d63638'; ?>;"><?php echo $fill; ?>%</td>
					<td class="num"><?php echo number_format( $vw ); ?></td>
					<td class="num" style="color:<?php echo $vr >= 60 ? '#00a32a' : '#d63638'; ?>;"><?php echo $vr; ?>%</td>
					<td class="num"><?php echo number_format( $ref ); ?></td>
					<td class="num">$<?php echo number_format( $ecpm, 2 ); ?></td>
					<td class="num" style="color:#00a32a;">$<?php echo number_format( $rev, 2 ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
			<tfoot><tr style="border-top:2px solid #ddd;font-weight:700;">
				<td>Total</td>
				<td class="num"><?php echo number_format( $t_imps ); ?></td>
				<td class="num"><?php echo number_format( $t_emp ); ?></td>
				<td class="num"><?php echo ( $t_imps + $t_emp ) > 0 ? round( $t_imps / ( $t_imps + $t_emp ) * 100, 1 ) : 0; ?>%</td>
				<td class="num"><?php echo number_format( $t_view ); ?></td>
				<td class="num"><?php echo $t_imps > 0 ? round( $t_view / $t_imps * 100, 1 ) : 0; ?>%</td>
				<td class="num"><?php echo number_format( $t_ref ); ?></td>
				<td class="num"></td>
				<td class="num" style="color:#00a32a;font-size:16px;">$<?php echo number_format( $t_rev, 2 ); ?></td>
			</tr></tfoot>
		</table>
	</div>
	<?php
}

/* --- 4. SECTION C — HOURLY TREND CHART (Chart.js) --------------------- */

function pl_ad_events_render_hourly_chart( $d ) {
	if ( empty( $d['hourly'] ) ) {
		return;
	}
	$labels = $imps = $views = array();
	foreach ( $d['hourly'] as $row ) {
		$labels[] = $row['h'];
		$imps[]   = (int) $row['impressions'];
		$views[]  = (int) $row['viewables'];
	}
	?>
	<div class="pl-section">
		<h2>Hourly Trend</h2>
		<canvas id="plEvtHourlyChart" height="260"></canvas>
		<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
		<script>
		(function(){
			var ctx = document.getElementById('plEvtHourlyChart').getContext('2d');
			new Chart(ctx, {
				type: 'line',
				data: {
					labels: <?php echo wp_json_encode( $labels ); ?>,
					datasets: [
						{ label:'Impressions', data:<?php echo wp_json_encode( $imps ); ?>,
						  borderColor:'#2271b1', backgroundColor:'rgba(34,113,177,0.1)',
						  fill:true, tension:0.3, pointRadius:2 },
						{ label:'Viewables', data:<?php echo wp_json_encode( $views ); ?>,
						  borderColor:'#00a32a', backgroundColor:'rgba(0,163,42,0.1)',
						  fill:true, tension:0.3, pointRadius:2 }
					]
				},
				options: {
					responsive:true,
					interaction:{mode:'index',intersect:false},
					scales:{ x:{ticks:{maxTicksLimit:24,font:{size:11}}}, y:{beginAtZero:true} },
					plugins:{legend:{position:'top'}}
				}
			});
		})();
		</script>
	</div>
	<?php
}

/* --- 5. SECTION D — DEVICE BREAKDOWN ---------------------------------- */

function pl_ad_events_render_device_breakdown( $d ) {
	if ( empty( $d['by_device'] ) ) {
		return;
	}
	$max_imp = 1;
	foreach ( $d['by_device'] as $row ) {
		if ( (int) $row['impressions'] > $max_imp ) {
			$max_imp = (int) $row['impressions'];
		}
	}
	$colors = array( 'mobile' => '#2271b1', 'desktop' => '#00a32a', 'tablet' => '#dba617' );
	?>
	<div class="pl-section">
		<h2>Device Breakdown</h2>
		<?php foreach ( $d['by_device'] as $row ) :
			$imp  = (int) $row['impressions'];
			$emp  = (int) $row['empties'];
			$vw   = (int) $row['viewables'];
			$fill = ( $imp + $emp ) > 0 ? round( $imp / ( $imp + $emp ) * 100, 1 ) : 0;
			$vr   = $imp > 0 ? round( $vw / $imp * 100, 1 ) : 0;
			$pct  = round( $imp / $max_imp * 100 );
			$clr  = $colors[ $row['device'] ] ?? '#888';
		?>
			<div style="margin-bottom:12px;">
				<div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:2px;">
					<strong><?php echo esc_html( ucfirst( $row['device'] ) ); ?></strong>
					<span><?php echo number_format( $imp ); ?> imps &middot; <?php echo $vr; ?>% viewable &middot; <?php echo $fill; ?>% fill</span>
				</div>
				<div class="pl-bar"><div class="pl-bar-fill" style="width:<?php echo $pct; ?>%;background:<?php echo esc_attr( $clr ); ?>;"></div></div>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
}

/* --- 6. SECTION E — VISITOR BEHAVIOR ---------------------------------- */

function pl_ad_events_render_visitor_behavior( $d ) {
	if ( empty( $d['by_visitor'] ) ) {
		return;
	}
	?>
	<div class="pl-section">
		<h2>Visitor Behavior</h2>
		<table class="pl-table">
			<thead><tr>
				<th>Visitor Type</th><th class="num">Impressions</th><th class="num">Viewability</th>
				<th class="num">Avg Scroll %</th><th class="num">Avg Time (s)</th>
			</tr></thead>
			<tbody>
			<?php foreach ( $d['by_visitor'] as $row ) :
				$imp = (int) $row['impressions'];
				$vw  = (int) $row['viewables'];
				$vr  = $imp > 0 ? round( $vw / $imp * 100, 1 ) : 0;
				$vt  = $row['visitor_type'] ?: 'unknown';
			?>
				<tr>
					<td><strong><?php echo esc_html( $vt ); ?></strong></td>
					<td class="num"><?php echo number_format( $imp ); ?></td>
					<td class="num" style="color:<?php echo $vr >= 60 ? '#00a32a' : '#d63638'; ?>;"><?php echo $vr; ?>%</td>
					<td class="num"><?php echo round( (float) $row['avg_scroll'], 1 ); ?>%</td>
					<td class="num"><?php echo round( (float) $row['avg_time'] ); ?>s</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}

/* --- 7. SECTION F — CREATIVE SIZE PERFORMANCE ------------------------- */

function pl_ad_events_render_creative_sizes( $d ) {
	if ( empty( $d['by_size'] ) ) {
		return;
	}
	?>
	<div class="pl-section">
		<h2>Creative Size Performance</h2>
		<table class="pl-table">
			<thead><tr>
				<th>Creative Size</th><th class="num">Count</th><th class="num">Viewable</th><th class="num">Viewability</th>
			</tr></thead>
			<tbody>
			<?php foreach ( $d['by_size'] as $row ) :
				$cnt = (int) $row['cnt'];
				$vw  = (int) $row['viewables'];
				$vr  = $cnt > 0 ? round( $vw / $cnt * 100, 1 ) : 0;
			?>
				<tr>
					<td><strong><?php echo esc_html( $row['creative_size'] ); ?></strong></td>
					<td class="num"><?php echo number_format( $cnt ); ?></td>
					<td class="num"><?php echo number_format( $vw ); ?></td>
					<td class="num" style="color:<?php echo $vr >= 60 ? '#00a32a' : '#d63638'; ?>;"><?php echo $vr; ?>%</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}

/* --- 8. SECTION G — REFRESH ANALYSIS ---------------------------------- */

function pl_ad_events_render_refresh_analysis( $d ) {
	$refreshes = $d['refreshes'];
	$skips     = $d['refresh_skips'];
	$skip_rate = ( $refreshes + $skips ) > 0
		? round( $skips / ( $refreshes + $skips ) * 100, 1 ) : 0;
	$ref_rev   = ( $refreshes / 1000 ) * 0.35;
	$max_ref   = 1;
	foreach ( $d['refresh_by_slot'] as $row ) {
		if ( (int) $row['cnt'] > $max_ref ) {
			$max_ref = (int) $row['cnt'];
		}
	}
	?>
	<div class="pl-section">
		<h2>Refresh Analysis</h2>
		<div class="pl-cards" style="grid-template-columns:repeat(4,1fr);">
			<div class="pl-card blue"><div class="pl-card-label">Total Refreshes</div><div class="pl-card-value"><?php echo number_format( $refreshes ); ?></div></div>
			<div class="pl-card"><div class="pl-card-label">Refresh Skips</div><div class="pl-card-value"><?php echo number_format( $skips ); ?></div></div>
			<div class="pl-card <?php echo $skip_rate <= 30 ? 'green' : 'red'; ?>"><div class="pl-card-label">Skip Rate</div><div class="pl-card-value"><?php echo $skip_rate; ?>%</div></div>
			<div class="pl-card green">
				<div class="pl-card-label">Refresh Revenue</div>
				<div class="pl-card-value">$<?php echo number_format( $ref_rev, 2 ); ?></div>
				<div class="pl-card-sub">@ $0.35 eCPM</div>
			</div>
		</div>
		<?php if ( ! empty( $d['refresh_by_slot'] ) ) : ?>
		<h4 style="margin-top:20px;">Refreshes by Slot Type</h4>
		<?php foreach ( $d['refresh_by_slot'] as $row ) :
			$cnt = (int) $row['cnt'];
			$pct = round( $cnt / $max_ref * 100 );
		?>
			<div class="pl-bar">
				<div class="pl-bar-fill" style="width:<?php echo $pct; ?>%;background:#2271b1;"></div>
				<span class="pl-bar-label"><?php echo esc_html( $row['slot_type'] ); ?></span>
				<span class="pl-bar-value"><?php echo number_format( $cnt ); ?></span>
			</div>
		<?php endforeach; endif; ?>
	</div>
	<?php
}

/* --- 9. SECTION H — TOP POSTS ----------------------------------------- */

function pl_ad_events_render_top_posts( $d ) {
	if ( empty( $d['by_post'] ) ) {
		return;
	}
	?>
	<div class="pl-section">
		<h2>Top Posts by Impressions</h2>
		<table class="pl-table">
			<thead><tr>
				<th>Post</th><th class="num">Impressions</th><th class="num">Empties</th>
				<th class="num">Fill Rate</th><th class="num">Viewables</th>
				<th class="num">Viewability</th><th class="num">Refreshes</th>
			</tr></thead>
			<tbody>
			<?php foreach ( $d['by_post'] as $row ) :
				$pid  = (int) $row['post_id'];
				$imp  = (int) $row['impressions'];
				$emp  = (int) $row['empties'];
				$vw   = (int) $row['viewables'];
				$ref  = (int) $row['refreshes'];
				$fill = ( $imp + $emp ) > 0 ? round( $imp / ( $imp + $emp ) * 100, 1 ) : 0;
				$vr   = $imp > 0 ? round( $vw / $imp * 100, 1 ) : 0;
				$title = get_the_title( $pid );
				if ( ! $title ) {
					$title = '#' . $pid;
				} elseif ( strlen( $title ) > 55 ) {
					$title = substr( $title, 0, 52 ) . '...';
				}
			?>
				<tr>
					<td title="Post ID: <?php echo $pid; ?>"><?php echo esc_html( $title ); ?></td>
					<td class="num"><?php echo number_format( $imp ); ?></td>
					<td class="num"><?php echo number_format( $emp ); ?></td>
					<td class="num" style="color:<?php echo $fill >= 70 ? '#00a32a' : '#d63638'; ?>;"><?php echo $fill; ?>%</td>
					<td class="num"><?php echo number_format( $vw ); ?></td>
					<td class="num" style="color:<?php echo $vr >= 60 ? '#00a32a' : '#d63638'; ?>;"><?php echo $vr; ?>%</td>
					<td class="num"><?php echo number_format( $ref ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}

/* --- 10. MAIN RENDER -------------------------------------------------- */

/**
 * Render all 8 event-level analytics sections.
 *
 * @param string $start Y-m-d.
 * @param string $end   Y-m-d.
 */
function pl_ad_events_render_all( $start, $end ) {
	$data = pl_ad_events_query_range( $start, $end );

	if ( $data['impressions'] === 0 && $data['empties'] === 0 ) {
		echo '<div class="pl-section"><h2>Event-Level Analytics</h2>';
		echo '<p style="color:#888;">No event data yet. Events are recorded as ads load on the frontend.</p></div>';
		return;
	}
	?>
	<div class="pl-section" style="background:transparent;border:none;padding:0;">
		<h2 style="font-size:18px;border-bottom:2px solid #2271b1;padding-bottom:8px;">Event-Level Analytics</h2>
		<?php
		pl_ad_events_render_overview( $data );
		pl_ad_events_render_format_table( $data );
		pl_ad_events_render_hourly_chart( $data );
		pl_ad_events_render_device_breakdown( $data );
		pl_ad_events_render_visitor_behavior( $data );
		pl_ad_events_render_creative_sizes( $data );
		pl_ad_events_render_refresh_analysis( $data );
		pl_ad_events_render_top_posts( $data );
		?>
	</div>
	<?php
}

/* --- HELPERS ---------------------------------------------------------- */

/** eCPM lookup by slot_type (USD per 1000 impressions). */
function pl_ad_events_ecpm_map() {
	return array(
		'interstitial' => 3.27,
		'anchor'       => 1.10,
		'sideRail'     => 0.65,
		'initial'      => 0.67,
		'sidebar'      => 0.42,
		'nav'          => 0.67,
		'dynamic'      => 0.63,
		'pause'        => 0.63,
		'video'        => 2.50,
	);
}

/** Estimate total revenue from per-slot impression data. */
function pl_ad_events_estimate_revenue( $by_slot ) {
	$ecpm_map = pl_ad_events_ecpm_map();
	$total    = 0.0;
	foreach ( $by_slot as $row ) {
		$imp  = (int) $row['impressions'];
		$ecpm = $ecpm_map[ $row['slot_type'] ] ?? 0.50;
		$total += ( $imp / 1000 ) * $ecpm;
	}
	return $total;
}
