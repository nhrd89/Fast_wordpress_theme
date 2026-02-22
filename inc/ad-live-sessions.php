<?php
/**
 * PinLightning Ad Live Sessions — Real-Time Debugging Dashboard
 *
 * Admin page showing active visitor sessions with live-updating gate status,
 * scroll depth, ad viewability, and zone activation data.
 *
 * Architecture:
 * - Browser: smart-ads.js heartbeat module POSTs to /pl-ads/v1/heartbeat every 3s
 * - Server: stores each heartbeat's full payload in the session index transient
 *   (keyed by sid). This avoids the race condition where a short-TTL per-session
 *   transient expires before stale detection can archive it.
 * - When a session goes stale (>60s no heartbeat), its final state is moved to
 *   pl_live_recent_sessions (2-hour TTL) for post-session debugging.
 * - Admin: JS polls GET /pl-ads/v1/live-sessions every 5s to refresh both tables
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

function pl_live_sessions_menu() {
	add_submenu_page(
		'pl-ad-engine',
		'Live Sessions',
		'Live Sessions',
		'manage_options',
		'pl-ad-live-sessions',
		'pl_live_sessions_page'
	);
}
add_action( 'admin_menu', 'pl_live_sessions_menu' );

/**
 * AJAX: Clear all live session data.
 */
function pl_live_sessions_clear_all() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized' );
	}
	check_ajax_referer( 'pl_live_clear_nonce', 'nonce' );

	delete_transient( 'pl_live_sess_index' );
	delete_transient( 'pl_live_recent_sessions' );

	wp_send_json_success( 'All session data cleared.' );
}
add_action( 'wp_ajax_pl_live_clear_all', 'pl_live_sessions_clear_all' );

/* ================================================================
 * 2. REST ENDPOINTS
 * ================================================================ */

function pl_live_sessions_rest_routes() {
	// Heartbeat from browser (public — visitors send data).
	register_rest_route( 'pl-ads/v1', '/heartbeat', array(
		'methods'             => 'POST',
		'callback'            => 'pl_live_sessions_heartbeat',
		'permission_callback' => '__return_true',
	) );

	// Admin reads active + recent sessions.
	register_rest_route( 'pl-ads/v1', '/live-sessions', array(
		'methods'             => 'GET',
		'callback'            => 'pl_live_sessions_get',
		'permission_callback' => function() {
			return current_user_can( 'manage_options' );
		},
	) );
}
add_action( 'rest_api_init', 'pl_live_sessions_rest_routes' );

/**
 * Receive heartbeat from browser and store in transient.
 */
function pl_live_sessions_heartbeat( $request ) {
	$body = $request->get_json_params();

	if ( empty( $body['sid'] ) ) {
		return new WP_REST_Response( array( 'ok' => false ), 400 );
	}

	$sid = sanitize_key( substr( $body['sid'], 0, 16 ) );

	// v5: buildSessionReport() sends timeOnPage in seconds.
	$session = array(
		'sid'              => $sid,
		'ts'               => time(),
		'post_id'          => intval( $body['postId'] ?? 0 ),
		'post_slug'        => sanitize_text_field( $body['postSlug'] ?? '' ),
		'post_title'       => sanitize_text_field( $body['postTitle'] ?? '' ),
		'device'           => sanitize_text_field( $body['device'] ?? 'unknown' ),
		'viewport_w'       => intval( $body['viewportW'] ?? 0 ),
		'viewport_h'       => intval( $body['viewportH'] ?? 0 ),
		'time_on_page_s'   => floatval( $body['timeOnPage'] ?? 0 ),
		'scroll_pct'       => floatval( $body['maxDepth'] ?? $body['scrollPct'] ?? 0 ),
		'scroll_speed'     => intval( $body['scrollSpeed'] ?? 0 ),
		'scroll_pattern'   => sanitize_text_field( $body['scrollPattern'] ?? '' ),
		'dir_changes'      => intval( $body['dirChanges'] ?? 0 ),
		'gate_open'        => ! empty( $body['gateOpen'] ),
		// v5: dynamic injection stats.
		'active_ads'       => intval( $body['totalInjected'] ?? $body['activeAds'] ?? 0 ),
		'viewable_ads'     => intval( $body['totalViewable'] ?? $body['viewableAds'] ?? 0 ),
		'viewability_rate' => intval( $body['viewabilityRate'] ?? 0 ),
		'zones_active'     => '',
		'referrer'         => sanitize_text_field( $body['referrer'] ?? '' ),
		'language'         => sanitize_text_field( $body['language'] ?? '' ),
		'events'           => array(),
		// Overlay status.
		'anchor_status'       => sanitize_text_field( $body['anchorStatus'] ?? 'off' ),
		'interstitial_status' => sanitize_text_field( $body['interstitialStatus'] ?? 'off' ),
		'top_anchor_status'   => sanitize_text_field( $body['topAnchorStatus'] ?? 'off' ),
		// Overlay viewability.
		'anchor_impressions'       => intval( $body['anchorImpressions'] ?? 0 ),
		'anchor_viewable'          => intval( $body['anchorViewable'] ?? 0 ),
		'anchor_visible_ms'        => intval( $body['anchorVisibleMs'] ?? 0 ),
		'interstitial_viewable'    => intval( $body['interstitialViewable'] ?? 0 ),
		'interstitial_duration_ms' => intval( $body['interstitialDurationMs'] ?? 0 ),
		// Fill tracking.
		'total_requested'      => intval( $body['totalRequested'] ?? 0 ),
		'total_filled'         => intval( $body['totalFilled'] ?? 0 ),
		'total_empty'          => intval( $body['totalEmpty'] ?? 0 ),
		'fill_rate'            => intval( $body['fillRate'] ?? 0 ),
		'anchor_filled'        => ! empty( $body['anchorFilled'] ),
		'interstitial_filled'  => ! empty( $body['interstitialFilled'] ),
		'top_anchor_filled'    => ! empty( $body['topAnchorFilled'] ),
		'zones_activated'      => intval( $body['totalInjected'] ?? $body['zonesActivated'] ?? 0 ),
		// v5: pause banners + refresh + video.
		'pause_banners_shown'  => intval( $body['pauseBannersInjected'] ?? $body['pauseBannersShown'] ?? 0 ),
		'refresh_count'        => intval( $body['totalRefreshes'] ?? $body['refreshCount'] ?? 0 ),
		'video_injected'       => ! empty( $body['videoInjected'] ),
	);

	// v5: Per-ad injection details from heartbeat.
	if ( ! empty( $body['zones'] ) && is_array( $body['zones'] ) ) {
		foreach ( array_slice( $body['zones'], 0, 20 ) as $z ) {
			$session['events'][] = array(
				'zone_id'       => sanitize_text_field( $z['zoneId'] ?? '' ),
				'slot'          => sanitize_text_field( $z['slot'] ?? '' ),
				'ad_size'       => sanitize_text_field( $z['size'] ?? $z['adSize'] ?? '' ),
				'position'      => sanitize_text_field( $z['position'] ?? '' ),
				'speed_at_inj'  => intval( $z['speedAtInjection'] ?? 0 ),
				'pattern'       => sanitize_text_field( $z['patternAtInjection'] ?? '' ),
				'visible_ms'    => intval( $z['visibleMs'] ?? 0 ),
				'viewable'      => ! empty( $z['viewable'] ) ? 1 : 0,
				'max_ratio'     => floatval( $z['maxRatio'] ?? 0 ),
				'filled'        => isset( $z['filled'] ) ? (bool) $z['filled'] : null,
				'is_pause'      => ! empty( $z['isPause'] ),
				'refresh_count' => intval( $z['refreshCount'] ?? 0 ),
			);
		}
		$session['zones_active'] = implode( ',', array_column( $session['events'], 'zone_id' ) );
	}

	// Store full payload in the index (not a separate transient — avoids TTL race).
	$index = get_transient( 'pl_live_sess_index' );
	if ( ! is_array( $index ) ) {
		$index = array();
	}
	$index[ $sid ] = $session;

	// Prune stale entries from index (no heartbeat for >60s) — move to recent.
	$cutoff = time() - 60;
	$stale  = array();
	foreach ( $index as $s_id => $s_data ) {
		if ( ! is_array( $s_data ) ) {
			// Legacy format (just timestamp) — remove.
			unset( $index[ $s_id ] );
			continue;
		}
		if ( ( $s_data['ts'] ?? 0 ) < $cutoff && $s_id !== $sid ) {
			$stale[ $s_id ] = $s_data;
		}
	}

	if ( ! empty( $stale ) ) {
		pl_live_sessions_archive_stale( $stale );
		$index = array_diff_key( $index, $stale );
	}

	set_transient( 'pl_live_sess_index', $index, 300 );

	return new WP_REST_Response( array( 'ok' => true ), 200 );
}

/**
 * Move stale sessions (final heartbeat data) to the recent sessions store.
 *
 * @param array $stale_sessions Associative array: sid => full session payload.
 */
function pl_live_sessions_archive_stale( $stale_sessions ) {
	$recent = get_transient( 'pl_live_recent_sessions' );
	if ( ! is_array( $recent ) ) {
		$recent = array();
	}

	foreach ( $stale_sessions as $sid => $data ) {
		if ( ! is_array( $data ) ) {
			continue;
		}
		// If ad-track already archived this session with richer data, don't overwrite.
		if ( isset( $recent[ $sid ] ) && ! empty( $recent[ $sid ]['source'] ) && strpos( $recent[ $sid ]['source'], 'ad-track' ) !== false ) {
			continue;
		}
		$data['status']   = 'ended';
		$data['ended_at'] = time();
		$data['source']   = 'heartbeat';
		$recent[ $sid ]   = $data;

		// Aggregate heartbeat-only sessions into daily stats (beacon sessions
		// are already aggregated in ad-data-recorder.php).
		if ( function_exists( 'pl_ad_aggregate_session' ) ) {
			pl_ad_aggregate_session( $data );
		}
	}

	// Prune recent sessions older than 2 hours, cap at 300 entries.
	$two_hours_ago = time() - 7200;
	$recent = array_filter( $recent, function( $s ) use ( $two_hours_ago ) {
		return ( $s['ended_at'] ?? $s['ts'] ?? 0 ) >= $two_hours_ago;
	} );

	// Keep most recent 300.
	if ( count( $recent ) > 300 ) {
		uasort( $recent, function( $a, $b ) {
			return ( $b['ended_at'] ?? $b['ts'] ) - ( $a['ended_at'] ?? $a['ts'] );
		} );
		$recent = array_slice( $recent, 0, 300, true );
	}

	set_transient( 'pl_live_recent_sessions', $recent, 7200 );
}

/**
 * Return all active + recent sessions for admin polling.
 */
function pl_live_sessions_get( $request ) {
	// Keep the liveMonitor flag alive while admin is polling.
	set_transient( 'pl_live_monitor_active', 1, 60 );

	$now   = time();
	$index = get_transient( 'pl_live_sess_index' );

	// -- Active sessions --
	$active = array();
	if ( is_array( $index ) ) {
		// Check for stale entries and archive them now (in case heartbeat hasn't run recently).
		$cutoff      = $now - 60;
		$stale       = array();
		$clean_index = array();

		foreach ( $index as $sid => $s_data ) {
			if ( ! is_array( $s_data ) ) {
				// Legacy format (just timestamp) — discard.
				continue;
			}
			if ( ( $s_data['ts'] ?? 0 ) < $cutoff ) {
				$stale[ $sid ] = $s_data;
			} else {
				$clean_index[ $sid ] = $s_data;
			}
		}

		if ( ! empty( $stale ) ) {
			pl_live_sessions_archive_stale( $stale );
			set_transient( 'pl_live_sess_index', $clean_index, 300 );
		}

		// Read active sessions directly from index (full payloads stored there).
		foreach ( $clean_index as $sid => $data ) {
			$data['age_s']  = $now - $data['ts'];
			$data['status'] = 'active';
			$active[]       = $data;
		}

		// Sort active by most recent heartbeat.
		usort( $active, function( $a, $b ) {
			return $b['ts'] - $a['ts'];
		} );
	}

	// -- Recent sessions (ended) --
	$recent_raw = get_transient( 'pl_live_recent_sessions' );
	$recent     = array();
	if ( is_array( $recent_raw ) ) {
		foreach ( $recent_raw as $s ) {
			$s['age_s'] = $now - ( $s['ended_at'] ?? $s['ts'] );
			$recent[]   = $s;
		}
		// Sort recent by ended_at desc.
		usort( $recent, function( $a, $b ) {
			return ( $b['ended_at'] ?? $b['ts'] ) - ( $a['ended_at'] ?? $a['ts'] );
		} );
	}

	return new WP_REST_Response( array(
		'active'       => $active,
		'active_count' => count( $active ),
		'recent'       => $recent,
		'recent_count' => count( $recent ),
		'ts'           => $now,
	), 200 );
}

/* ================================================================
 * 3. ADMIN PAGE
 * ================================================================ */

function pl_live_sessions_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Signal to frontends that an admin is watching (60s TTL, refreshed by page load).
	set_transient( 'pl_live_monitor_active', 1, 60 );

	$nonce     = wp_create_nonce( 'wp_rest' );
	$api       = esc_url_raw( rest_url( 'pl-ads/v1/live-sessions' ) );
	$ajax_url  = admin_url( 'admin-ajax.php' );
	$clear_nonce = wp_create_nonce( 'pl_live_clear_nonce' );
	?>
	<div class="wrap">
		<h1>Live Sessions</h1>

		<p id="plLiveStatus" style="display:flex;align-items:center;gap:6px;font-size:13px;color:#646970;margin:0 0 10px">
			<span id="plLiveDot" style="width:8px;height:8px;border-radius:50%;background:#00a32a;display:inline-block;animation:plPulse 2s infinite"></span>
			Monitoring &mdash;
			<strong>Active: <span id="plActiveCount">0</span></strong>
			<span style="color:#c3c4c7">|</span>
			<strong>Recent: <span id="plRecentCount">0</span></strong>
		</p>
		<div style="display:flex;gap:10px;align-items:center;margin-bottom:16px">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=pl-ad-analytics-dashboard' ) ); ?>" class="button button-primary">Analytics Dashboard</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=pl-ad-engine' ) ); ?>" class="button">Ad Engine Settings</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=pl-ad-optimizer' ) ); ?>" class="button">Optimizer</a>
			<span style="border-left:1px solid #c3c4c7;height:24px"></span>
			<button type="button" id="plClearLiveSessions" class="button" style="background:#d63638;border-color:#d63638;color:#fff">Clear All Data</button>
			<button type="button" id="plExportLive" class="button" style="background:#2271b1;border-color:#2271b1;color:#fff">Export All Sessions (JSON)</button>
		</div>
		<div id="plClearNotice" style="display:none"></div>

		<style>
			@keyframes plPulse { 0%,100%{opacity:1}50%{opacity:.3} }
			.pl-section-header { padding:10px 14px;font-weight:600;font-size:13px;border-bottom:2px solid }
			.pl-section-active .pl-section-header { background:#edfcf2;color:#00a32a;border-color:#00a32a }
			.pl-section-recent .pl-section-header { background:#f0f0f1;color:#646970;border-color:#c3c4c7 }
			.pl-sess-table { border-collapse:collapse;width:100%;font-size:13px }
			.pl-sess-table th { background:#f6f7f7;position:sticky;top:0;z-index:1;text-align:left;padding:8px 10px;border-bottom:1px solid #c3c4c7;white-space:nowrap;font-size:12px }
			.pl-sess-table td { padding:6px 10px;border-bottom:1px solid #e0e0e0;vertical-align:top }
			.pl-sess-table tr:hover td { background:#f6f7f7 }
			.pl-row-expand { cursor:pointer }
			.pl-gate-ok { color:#00a32a }
			.pl-gate-fail { color:#d63638 }
			.pl-detail { display:none;background:#f9f9f9;border-left:3px solid #2271b1 }
			.pl-detail td { padding:12px 16px }
			.pl-detail-inner { display:grid;grid-template-columns:1fr 1fr;gap:16px }
			.pl-detail-inner h4 { margin:0 0 6px;font-size:13px }
			.pl-detail-inner table { font-size:12px;width:100%;border-collapse:collapse }
			.pl-detail-inner table th,.pl-detail-inner table td { padding:3px 6px;text-align:left;border-bottom:1px solid #e0e0e0 }
			.pl-empty { text-align:center;color:#646970;padding:24px 20px;font-size:13px }
			.pl-stale td { opacity:.5 }
			.pl-ended td { color:#646970 }
			.pl-status-badge { display:inline-block;padding:1px 6px;border-radius:3px;font-size:11px;font-weight:600 }
			.pl-status-active { background:#edfcf2;color:#00a32a }
			.pl-status-ended { background:#f0f0f1;color:#646970 }
		</style>

		<!-- ACTIVE SESSIONS -->
		<div class="pl-section-active" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;margin-bottom:20px;overflow-x:auto">
			<div class="pl-section-header">Active Sessions</div>
			<table class="pl-sess-table">
				<thead>
					<tr>
						<th>Session</th>
						<th>Post</th>
						<th>Device</th>
						<th>Time</th>
						<th>Scroll</th>
						<th>Speed</th>
						<th>Pattern</th>
						<th>Gate</th>
						<th>Injected</th>
						<th>Viewable</th>
						<th>View%</th>
						<th>Pause</th>
						<th>Refresh</th>
						<th>Video</th>
						<th>Overlays</th>
						<th>Ref</th>
					</tr>
				</thead>
				<tbody id="plActiveBody">
					<tr><td colspan="16" class="pl-empty">Waiting for first heartbeat...</td></tr>
				</tbody>
			</table>
		</div>

		<!-- RECENT SESSIONS -->
		<div class="pl-section-recent" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;overflow-x:auto">
			<div class="pl-section-header">Recent Sessions (Last 2 Hours)</div>
			<table class="pl-sess-table">
				<thead>
					<tr>
						<th>Session</th>
						<th>Post</th>
						<th>Device</th>
						<th>Duration</th>
						<th>Scroll</th>
						<th>Speed</th>
						<th>Pattern</th>
						<th>Gate</th>
						<th>Injected</th>
						<th>Viewable</th>
						<th>View%</th>
						<th>Pause</th>
						<th>Refresh</th>
						<th>Video</th>
						<th>Overlays</th>
						<th>Ended</th>
					</tr>
				</thead>
				<tbody id="plRecentBody">
					<tr><td colspan="16" class="pl-empty">No recent sessions yet.</td></tr>
				</tbody>
			</table>
		</div>
	</div>

	<script>
	(function() {
		var API = <?php echo wp_json_encode( $api ); ?>;
		var NONCE = <?php echo wp_json_encode( $nonce ); ?>;
		var activeBody = document.getElementById('plActiveBody');
		var recentBody = document.getElementById('plRecentBody');
		var activeCountEl = document.getElementById('plActiveCount');
		var recentCountEl = document.getElementById('plRecentCount');
		var expandedSids = {};
		var lastData = null;

		function gateIcon(ok) {
			return ok ? '<span class="pl-gate-ok">&#10003;</span>' : '<span class="pl-gate-fail">&#10007;</span>';
		}

		function gateStatus(s) {
			if (s.gate_open) return '<span class="pl-gate-ok" style="font-weight:700">&#10003; OPEN</span>';
			return '<span class="pl-gate-fail">&#10007; CLOSED</span>';
		}

		function fmtTime(seconds) {
			var m = Math.floor(seconds / 60);
			var sec = Math.round(seconds % 60);
			return m > 0 ? m + 'm ' + sec + 's' : sec + 's';
		}

		function fmtAgo(seconds) {
			if (seconds < 60) return seconds + 's ago';
			var m = Math.floor(seconds / 60);
			return m + 'm ago';
		}

		function shortRef(ref) {
			if (!ref) return 'direct';
			if (ref.indexOf('pinterest') !== -1) return 'pinterest';
			if (ref.indexOf('google') !== -1) return 'google';
			if (ref.indexOf('facebook') !== -1 || ref.indexOf('fb.') !== -1) return 'facebook';
			try { return new URL(ref).hostname.replace('www.', ''); } catch(e) { return ref.substring(0, 20); }
		}

		function fmtStatus(status) {
			if (status === 'firing' || status === 'fired') return '<span class="pl-gate-ok" title="' + status + '">&#10003;</span>';
			if (status === 'waiting') return '<span style="color:#dba617" title="waiting">&#9203;</span>';
			return '<span class="pl-gate-fail" title="off">&#10007;</span>';
		}

		function fmtOverlay(status, extra) {
			var icon = fmtStatus(status);
			if (extra && (status === 'firing' || status === 'fired')) {
				return icon + ' <span style="font-size:11px">' + extra + '</span>';
			}
			return icon;
		}

		function renderRow(s, isRecent) {
			var rowClass = 'pl-row-expand';
			if (isRecent) rowClass += ' pl-ended';
			else if (s.age_s > 15) rowClass += ' pl-stale';

			var rate = s.active_ads > 0 ? Math.round((s.viewable_ads / s.active_ads) * 100) : 0;
			var title = s.post_title || s.post_slug || '(ID ' + s.post_id + ')';
			if (title.length > 35) title = title.substring(0, 32) + '...';

			var lastCol = isRecent ? fmtAgo(s.age_s) : shortRef(s.referrer);

			// Overlays compact: A=anchor, I=interstitial, T=top-anchor.
			var overlays = fmtStatus(s.anchor_status) + ' ' + fmtStatus(s.interstitial_status) + ' ' + fmtStatus(s.top_anchor_status || 'off');

			var html = '<tr class="' + rowClass + '" data-sid="' + s.sid + '">' +
				'<td><code>' + s.sid + '</code></td>' +
				'<td title="' + (s.post_title || s.post_slug) + '">' + title + '</td>' +
				'<td>' + s.device + '</td>' +
				'<td>' + fmtTime(s.time_on_page_s) + '</td>' +
				'<td>' + Math.round(s.scroll_pct) + '%</td>' +
				'<td>' + s.scroll_speed + '</td>' +
				'<td>' + (s.scroll_pattern || '-') + '</td>' +
				'<td style="white-space:nowrap">' + gateStatus(s) + '</td>' +
				'<td>' + (s.active_ads || 0) + '</td>' +
				'<td>' + (s.viewable_ads || 0) + '</td>' +
				'<td>' + rate + '%</td>' +
				'<td>' + (s.pause_banners_shown || 0) + '</td>' +
				'<td>' + (s.refresh_count || 0) + '</td>' +
				'<td>' + (s.video_injected ? '&#10003;' : '-') + '</td>' +
				'<td style="white-space:nowrap">' + overlays + '</td>' +
				'<td>' + lastCol + '</td>' +
				'</tr>';

			// Detail row.
			var show = expandedSids[s.sid] ? '' : ' style="display:none"';
			html += '<tr class="pl-detail"' + show + ' data-detail="' + s.sid + '"><td colspan="16">';
			html += renderDetail(s, isRecent);
			html += '</td></tr>';

			return html;
		}

		function renderDetail(s, isRecent) {
			var h = '<div class="pl-detail-inner" style="grid-template-columns:1fr 1fr 1fr">';

			// Session overview.
			h += '<div>';
			h += '<h4>Session Overview' + (isRecent ? ' (Final State)' : '') + '</h4>';
			h += '<table><tr><th>Metric</th><th>Value</th></tr>';
			h += '<tr><td>Gate</td><td>' + (s.gate_open ? '<span class="pl-gate-ok" style="font-weight:700">OPEN</span>' : '<span class="pl-gate-fail">CLOSED</span>') + '</td></tr>';
			h += '<tr><td>Scroll</td><td>' + Math.round(s.scroll_pct) + '%</td></tr>';
			h += '<tr><td>Time on page</td><td>' + fmtTime(s.time_on_page_s) + '</td></tr>';
			h += '<tr><td>Scroll Speed</td><td>' + s.scroll_speed + ' px/s</td></tr>';
			h += '<tr><td>Direction Changes</td><td>' + (s.dir_changes || 0) + '</td></tr>';
			h += '<tr><td>Pattern</td><td>' + (s.scroll_pattern || '-') + '</td></tr>';
			h += '</table>';
			h += '<p style="font-size:12px;color:#646970;margin-top:8px">';
			h += 'Viewport: ' + s.viewport_w + 'x' + s.viewport_h + '<br>';
			h += 'Device: ' + s.device + '<br>';
			h += 'Referrer: ' + (s.referrer || 'direct') + '<br>';
			h += 'Language: ' + (s.language || '-');
			if (isRecent && s.ended_at) {
				var endDate = new Date(s.ended_at * 1000);
				h += '<br>Ended: ' + endDate.toLocaleTimeString();
			}
			h += '</p></div>';

			// Injection Intelligence + Overlays.
			h += '<div>';
			h += '<h4>Injection Intelligence</h4>';
			h += '<table><tr><th>Metric</th><th>Value</th></tr>';
			h += '<tr><td>Ads Injected</td><td><strong>' + (s.active_ads || 0) + '</strong></td></tr>';
			h += '<tr><td>Viewable</td><td>' + (s.viewable_ads || 0) + '</td></tr>';
			h += '<tr><td>Viewability Rate</td><td>' + (s.viewability_rate || 0) + '%</td></tr>';
			if (s.total_requested > 0) {
				h += '<tr><td>Fill</td><td>' + s.total_filled + '/' + s.total_requested + ' (' + s.fill_rate + '%)</td></tr>';
			}
			h += '<tr><td>Pause Banners</td><td>' + (s.pause_banners_shown || 0) + '</td></tr>';
			h += '<tr><td>Refreshes</td><td>' + (s.refresh_count || 0) + '</td></tr>';
			h += '<tr><td>Video</td><td>' + (s.video_injected ? '<span class="pl-gate-ok">&#10003; Injected</span>' : '&#10007; Not injected') + '</td></tr>';
			h += '</table>';
			// Overlays detail.
			h += '<h4 style="margin-top:12px">Overlays</h4>';
			h += '<table><tr><th>Format</th><th>Status</th><th>Detail</th></tr>';
			var anchorDetail = s.anchor_impressions ? s.anchor_impressions + ' imp, ' + (s.anchor_viewable || 0) + ' viewable' : '-';
			if (s.anchor_visible_ms) anchorDetail += ', ' + (s.anchor_visible_ms / 1000).toFixed(1) + 's visible';
			h += '<tr><td>Anchor</td><td>' + fmtStatus(s.anchor_status) + ' ' + (s.anchor_status || 'off') + '</td><td style="font-size:12px">' + anchorDetail + '</td></tr>';
			var intDur = s.interstitial_duration_ms ? (s.interstitial_duration_ms / 1000).toFixed(1) + 's' : '-';
			h += '<tr><td>Interstitial</td><td>' + fmtStatus(s.interstitial_status) + ' ' + (s.interstitial_status || 'off') + '</td><td style="font-size:12px">Duration: ' + intDur + '</td></tr>';
			h += '<tr><td>Top Anchor</td><td>' + fmtStatus(s.top_anchor_status || 'off') + ' ' + (s.top_anchor_status || 'off') + '</td><td style="font-size:12px">-</td></tr>';
			h += '</table>';
			h += '</div>';

			// Per-ad injection detail.
			h += '<div>';
			h += '<h4>Ad Detail (' + (s.events ? s.events.length : 0) + ' ads)' + (isRecent ? ' — Final' : '') + '</h4>';
			if (s.events && s.events.length > 0) {
				h += '<table><tr><th>Zone</th><th>Size</th><th>Position</th><th>Speed</th><th>Pattern</th><th>Visible</th><th>Viewable</th><th>Pause</th><th>Refresh</th></tr>';
				for (var i = 0; i < s.events.length; i++) {
					var e = s.events[i];
					h += '<tr>';
					h += '<td><code>' + (e.zone_id || '-') + '</code></td>';
					h += '<td>' + (e.ad_size || '-') + '</td>';
					h += '<td>' + (e.position || '-') + '</td>';
					h += '<td>' + (e.speed_at_inj || 0) + '</td>';
					h += '<td>' + (e.pattern || '-') + '</td>';
					h += '<td>' + ((e.visible_ms || 0) / 1000).toFixed(1) + 's</td>';
					h += '<td>' + (e.viewable > 0 ? gateIcon(true) : gateIcon(false)) + '</td>';
					h += '<td>' + (e.is_pause ? '&#10003;' : '-') + '</td>';
					h += '<td>' + (e.refresh_count || 0) + '</td>';
					h += '</tr>';
				}
				h += '</table>';
			} else {
				h += '<p style="color:#646970;font-size:12px">No ads injected' + (isRecent ? '.' : ' yet (gate may be closed).') + '</p>';
			}
			h += '</div>';

			h += '</div>';
			return h;
		}

		function renderTable(tbody, sessions, isRecent, emptyMsg) {
			if (sessions.length === 0) {
				tbody.innerHTML = '<tr><td colspan="16" class="pl-empty">' + emptyMsg + '</td></tr>';
				return;
			}
			var html = '';
			for (var i = 0; i < sessions.length; i++) {
				html += renderRow(sessions[i], isRecent);
			}
			tbody.innerHTML = html;

			// Bind expand clicks.
			var rows = tbody.querySelectorAll('.pl-row-expand');
			for (var j = 0; j < rows.length; j++) {
				rows[j].addEventListener('click', onRowClick);
			}
		}

		function refresh() {
			fetch(API + '?_wpnonce=' + NONCE, { credentials: 'same-origin' })
				.then(function(r) { return r.json(); })
				.then(function(res) {
					lastData = res;

					activeCountEl.textContent = res.active_count || 0;
					recentCountEl.textContent = res.recent_count || 0;

					renderTable(
						activeBody,
						res.active || [],
						false,
						'No active sessions. Heartbeats appear when visitors browse posts with the ad engine active.'
					);
					renderTable(
						recentBody,
						res.recent || [],
						true,
						'No recent sessions yet.'
					);
				})
				.catch(function() {});
		}

		function onRowClick() {
			var sid = this.getAttribute('data-sid');
			expandedSids[sid] = !expandedSids[sid];

			// Find detail row in both tables.
			var tables = [activeBody, recentBody];
			for (var t = 0; t < tables.length; t++) {
				var detail = tables[t].querySelector('[data-detail="' + sid + '"]');
				if (detail) {
					detail.style.display = expandedSids[sid] ? '' : 'none';
				}
			}
		}

		// Export button — includes both active and recent.
		document.getElementById('plExportLive').addEventListener('click', function() {
			if (!lastData) return;
			var exportData = {
				exported_at: new Date().toISOString(),
				active_count: lastData.active_count || 0,
				recent_count: lastData.recent_count || 0,
				active: lastData.active || [],
				recent: lastData.recent || []
			};
			var blob = new Blob([JSON.stringify(exportData, null, 2)], { type: 'application/json' });
			var url = URL.createObjectURL(blob);
			var a = document.createElement('a');
			a.href = url;
			var d = new Date().toISOString().slice(0, 10);
			a.download = 'pl-live-sessions-' + d + '.json';
			a.click();
			URL.revokeObjectURL(url);
		});

		// Clear All Data button (AJAX, matches Ad Analytics pattern).
		document.getElementById('plClearLiveSessions').addEventListener('click', function() {
			if (!confirm('Clear all active and recent session data?')) return;
			var fd = new FormData();
			fd.append('action', 'pl_live_clear_all');
			fd.append('nonce', <?php echo wp_json_encode( $clear_nonce ); ?>);
			fetch(<?php echo wp_json_encode( $ajax_url ); ?>, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function(r) { return r.json(); })
				.then(function(res) {
					var el = document.getElementById('plClearNotice');
					if (res.success) {
						el.className = 'notice notice-success';
						el.innerHTML = '<p>' + res.data + '</p>';
					} else {
						el.className = 'notice notice-error';
						el.innerHTML = '<p>Error: ' + (res.data || 'Unknown error') + '</p>';
					}
					el.style.display = '';
					setTimeout(function() { location.reload(); }, 1200);
				})
				.catch(function() {
					var el = document.getElementById('plClearNotice');
					el.className = 'notice notice-error';
					el.innerHTML = '<p>Network error — try again.</p>';
					el.style.display = '';
				});
		});

		// Start polling.
		refresh();
		setInterval(refresh, 5000);
	})();
	</script>
	<?php
}
