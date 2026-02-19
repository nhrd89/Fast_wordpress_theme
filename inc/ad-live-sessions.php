<?php
/**
 * PinLightning Ad Live Sessions — Real-Time Debugging Dashboard
 *
 * Admin page showing active visitor sessions with live-updating gate status,
 * scroll depth, ad viewability, and zone activation data.
 *
 * Architecture:
 * - Browser: smart-ads.js heartbeat module POSTs to /pl-ads/v1/heartbeat every 3s
 * - Server: stores each heartbeat as a transient with 30s TTL (auto-cleanup)
 * - Admin: JS polls GET /pl-ads/v1/live-sessions every 5s to refresh the table
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

	// Admin reads active sessions.
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

	$session = array(
		'sid'            => $sid,
		'ts'             => time(),
		'post_id'        => intval( $body['postId'] ?? 0 ),
		'post_slug'      => sanitize_text_field( $body['postSlug'] ?? '' ),
		'post_title'     => sanitize_text_field( $body['postTitle'] ?? '' ),
		'device'         => sanitize_text_field( $body['device'] ?? 'unknown' ),
		'viewport_w'     => intval( $body['viewportW'] ?? 0 ),
		'viewport_h'     => intval( $body['viewportH'] ?? 0 ),
		'time_on_page_s' => floatval( $body['timeOnPage'] ?? 0 ),
		'scroll_pct'     => floatval( $body['scrollPct'] ?? 0 ),
		'scroll_speed'   => intval( $body['scrollSpeed'] ?? 0 ),
		'scroll_pattern' => sanitize_text_field( $body['scrollPattern'] ?? '' ),
		'gate_scroll'    => ! empty( $body['gateScroll'] ),
		'gate_time'      => ! empty( $body['gateTime'] ),
		'gate_direction' => ! empty( $body['gateDirection'] ),
		'gate_open'      => ! empty( $body['gateOpen'] ),
		'active_ads'     => intval( $body['activeAds'] ?? 0 ),
		'viewable_ads'   => intval( $body['viewableAds'] ?? 0 ),
		'zones_active'   => sanitize_text_field( $body['zonesActive'] ?? '' ),
		'referrer'       => sanitize_text_field( $body['referrer'] ?? '' ),
		'language'       => sanitize_text_field( $body['language'] ?? '' ),
		'events'         => array(),
	);

	// Capture zone detail from heartbeat.
	if ( ! empty( $body['zoneDetail'] ) && is_array( $body['zoneDetail'] ) ) {
		foreach ( array_slice( $body['zoneDetail'], 0, 20 ) as $z ) {
			$session['events'][] = array(
				'zone_id'       => sanitize_text_field( $z['zoneId'] ?? '' ),
				'ad_size'       => sanitize_text_field( $z['adSize'] ?? '' ),
				'injected_at'   => floatval( $z['injectedAtDepth'] ?? 0 ),
				'speed_at_inj'  => intval( $z['scrollSpeedAtInjection'] ?? 0 ),
				'visible_ms'    => intval( $z['totalVisibleMs'] ?? 0 ),
				'viewable'      => intval( $z['viewableImpressions'] ?? 0 ),
				'max_ratio'     => floatval( $z['maxRatio'] ?? 0 ),
				'first_view_ms' => intval( $z['timeToFirstView'] ?? 0 ),
			);
		}
	}

	// Store with 30s TTL. Also add to the session index.
	set_transient( 'pl_live_sess_' . $sid, $session, 30 );

	// Maintain an index of active session IDs (120s TTL for the index).
	$index = get_transient( 'pl_live_sess_index' );
	if ( ! is_array( $index ) ) {
		$index = array();
	}
	$index[ $sid ] = time();

	// Prune stale entries from index (older than 60s).
	$cutoff = time() - 60;
	$index  = array_filter( $index, function( $ts ) use ( $cutoff ) {
		return $ts >= $cutoff;
	} );

	set_transient( 'pl_live_sess_index', $index, 120 );

	return new WP_REST_Response( array( 'ok' => true ), 200 );
}

/**
 * Return all active sessions for admin polling.
 */
function pl_live_sessions_get( $request ) {
	// Keep the liveMonitor flag alive while admin is polling.
	set_transient( 'pl_live_monitor_active', 1, 60 );

	$include_recent = $request->get_param( 'recent' ) === '1';
	$index          = get_transient( 'pl_live_sess_index' );

	if ( ! is_array( $index ) || empty( $index ) ) {
		return new WP_REST_Response( array( 'sessions' => array(), 'count' => 0 ), 200 );
	}

	$sessions = array();
	$now      = time();
	$cutoff   = $include_recent ? $now - 1800 : $now - 60;

	foreach ( $index as $sid => $ts ) {
		if ( $ts < $cutoff ) {
			continue;
		}
		$data = get_transient( 'pl_live_sess_' . $sid );
		if ( $data ) {
			$data['age_s'] = $now - $data['ts'];
			$sessions[]    = $data;
		}
	}

	// Sort by most recent heartbeat.
	usort( $sessions, function( $a, $b ) {
		return $b['ts'] - $a['ts'];
	} );

	return new WP_REST_Response( array(
		'sessions' => $sessions,
		'count'    => count( $sessions ),
		'ts'       => $now,
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

	$nonce = wp_create_nonce( 'wp_rest' );
	$api   = esc_url_raw( rest_url( 'pl-ads/v1/live-sessions' ) );
	?>
	<div class="wrap">
		<h1>Live Sessions</h1>

		<div style="display:flex;gap:10px;align-items:center;margin-bottom:16px">
			<span id="plLiveStatus" style="display:inline-flex;align-items:center;gap:6px;font-size:13px;color:#646970">
				<span id="plLiveDot" style="width:8px;height:8px;border-radius:50%;background:#00a32a;display:inline-block;animation:plPulse 2s infinite"></span>
				Monitoring — <strong id="plLiveCount">0</strong> active sessions
			</span>
			<span style="flex:1"></span>
			<button type="button" id="plExportLive" class="button" style="background:#2271b1;border-color:#2271b1;color:#fff">Export Live Sessions (JSON)</button>
		</div>

		<style>
			@keyframes plPulse { 0%,100%{opacity:1}50%{opacity:.3} }
			#plLiveTable { border-collapse:collapse;width:100%;font-size:13px }
			#plLiveTable th { background:#f0f0f1;position:sticky;top:0;z-index:1;text-align:left;padding:8px 10px;border-bottom:2px solid #c3c4c7;white-space:nowrap }
			#plLiveTable td { padding:6px 10px;border-bottom:1px solid #e0e0e0;vertical-align:top }
			#plLiveTable tr:hover td { background:#f6f7f7 }
			.pl-row-expand { cursor:pointer }
			.pl-gate-ok { color:#00a32a }
			.pl-gate-fail { color:#d63638 }
			.pl-detail { display:none;background:#f9f9f9;border-left:3px solid #2271b1 }
			.pl-detail td { padding:12px 16px }
			.pl-detail-inner { display:grid;grid-template-columns:1fr 1fr;gap:16px }
			.pl-detail-inner h4 { margin:0 0 6px;font-size:13px }
			.pl-detail-inner table { font-size:12px;width:100%;border-collapse:collapse }
			.pl-detail-inner table th,.pl-detail-inner table td { padding:3px 6px;text-align:left;border-bottom:1px solid #e0e0e0 }
			.pl-empty { text-align:center;color:#646970;padding:40px 20px }
			.pl-stale td { opacity:.5 }
		</style>

		<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;overflow-x:auto">
			<table id="plLiveTable">
				<thead>
					<tr>
						<th>Session</th>
						<th>Post</th>
						<th>Device</th>
						<th>Time</th>
						<th>Scroll</th>
						<th>Pattern</th>
						<th>Gate Status</th>
						<th>Ads</th>
						<th>Viewable</th>
						<th>Rate</th>
						<th>Speed</th>
						<th>Zones</th>
						<th>Referrer</th>
						<th>Lang</th>
					</tr>
				</thead>
				<tbody id="plLiveBody">
					<tr><td colspan="14" class="pl-empty">Waiting for first heartbeat...</td></tr>
				</tbody>
			</table>
		</div>
	</div>

	<script>
	(function() {
		var API = <?php echo wp_json_encode( $api ); ?>;
		var NONCE = <?php echo wp_json_encode( $nonce ); ?>;
		var tbody = document.getElementById('plLiveBody');
		var countEl = document.getElementById('plLiveCount');
		var expandedSid = null;
		var lastData = null;

		function gateIcon(ok) {
			return ok ? '<span class="pl-gate-ok">&#10003;</span>' : '<span class="pl-gate-fail">&#10007;</span>';
		}

		function gateStatus(s) {
			if (s.gate_open) return '<span class="pl-gate-ok" style="font-weight:700">&#10003; OPEN</span>';
			return gateIcon(s.gate_scroll) + ' scrl ' +
			       gateIcon(s.gate_time) + ' time ' +
			       gateIcon(s.gate_direction) + ' dir';
		}

		function fmtTime(seconds) {
			var m = Math.floor(seconds / 60);
			var sec = Math.round(seconds % 60);
			return m > 0 ? m + 'm ' + sec + 's' : sec + 's';
		}

		function shortRef(ref) {
			if (!ref) return 'direct';
			if (ref.indexOf('pinterest') !== -1) return 'pinterest';
			if (ref.indexOf('google') !== -1) return 'google';
			if (ref.indexOf('facebook') !== -1 || ref.indexOf('fb.') !== -1) return 'facebook';
			try { return new URL(ref).hostname.replace('www.', ''); } catch(e) { return ref.substring(0, 20); }
		}

		function renderRow(s) {
			var stale = s.age_s > 15 ? ' class="pl-stale pl-row-expand"' : ' class="pl-row-expand"';
			var rate = s.active_ads > 0 ? Math.round((s.viewable_ads / s.active_ads) * 100) : 0;
			var title = s.post_title || s.post_slug || '(ID ' + s.post_id + ')';
			if (title.length > 35) title = title.substring(0, 32) + '...';

			var html = '<tr' + stale + ' data-sid="' + s.sid + '">' +
				'<td><code>' + s.sid + '</code></td>' +
				'<td title="' + (s.post_title || s.post_slug) + '">' + title + '</td>' +
				'<td>' + s.device + '</td>' +
				'<td>' + fmtTime(s.time_on_page_s) + '</td>' +
				'<td>' + Math.round(s.scroll_pct) + '%</td>' +
				'<td>' + (s.scroll_pattern || '-') + '</td>' +
				'<td style="white-space:nowrap">' + gateStatus(s) + '</td>' +
				'<td>' + s.active_ads + '</td>' +
				'<td>' + s.viewable_ads + '</td>' +
				'<td>' + rate + '%</td>' +
				'<td>' + s.scroll_speed + '</td>' +
				'<td><code style="font-size:11px">' + (s.zones_active || '-') + '</code></td>' +
				'<td>' + shortRef(s.referrer) + '</td>' +
				'<td>' + (s.language || '-') + '</td>' +
				'</tr>';

			// Detail row.
			var show = expandedSid === s.sid ? '' : ' style="display:none"';
			html += '<tr class="pl-detail"' + show + ' data-detail="' + s.sid + '"><td colspan="14">';
			html += renderDetail(s);
			html += '</td></tr>';

			return html;
		}

		function renderDetail(s) {
			var h = '<div class="pl-detail-inner">';

			// Gate funnel.
			h += '<div>';
			h += '<h4>Gate Funnel</h4>';
			h += '<table><tr><th>Signal</th><th>Status</th></tr>';
			h += '<tr><td>Scroll (' + Math.round(s.scroll_pct) + '%)</td><td>' + gateIcon(s.gate_scroll) + '</td></tr>';
			h += '<tr><td>Time (' + fmtTime(s.time_on_page_s) + ')</td><td>' + gateIcon(s.gate_time) + '</td></tr>';
			h += '<tr><td>Direction changes</td><td>' + gateIcon(s.gate_direction) + '</td></tr>';
			h += '<tr><td><strong>Gate</strong></td><td>' + (s.gate_open ? '<span class="pl-gate-ok" style="font-weight:700">OPEN</span>' : '<span class="pl-gate-fail">CLOSED</span>') + '</td></tr>';
			h += '</table>';
			h += '<p style="font-size:12px;color:#646970;margin-top:8px">Viewport: ' + s.viewport_w + 'x' + s.viewport_h + '<br>Device: ' + s.device + '<br>Referrer: ' + (s.referrer || 'direct') + '</p>';
			h += '</div>';

			// Zone detail.
			h += '<div>';
			h += '<h4>Zone Detail (' + (s.events ? s.events.length : 0) + ' zones)</h4>';
			if (s.events && s.events.length > 0) {
				h += '<table><tr><th>Zone</th><th>Size</th><th>Depth</th><th>Speed</th><th>Visible</th><th>Viewable</th><th>Max %</th></tr>';
				for (var i = 0; i < s.events.length; i++) {
					var e = s.events[i];
					h += '<tr>';
					h += '<td><code>' + e.zone_id + '</code></td>';
					h += '<td>' + e.ad_size + '</td>';
					h += '<td>' + Math.round(e.injected_at) + '%</td>';
					h += '<td>' + e.speed_at_inj + '</td>';
					h += '<td>' + (e.visible_ms / 1000).toFixed(1) + 's</td>';
					h += '<td>' + (e.viewable > 0 ? gateIcon(true) : gateIcon(false)) + '</td>';
					h += '<td>' + Math.round(e.max_ratio * 100) + '%</td>';
					h += '</tr>';
				}
				h += '</table>';
			} else {
				h += '<p style="color:#646970;font-size:12px">No zones activated yet (gate may be closed).</p>';
			}
			h += '</div>';

			h += '</div>';
			return h;
		}

		function refresh() {
			fetch(API + '?_wpnonce=' + NONCE, { credentials: 'same-origin' })
				.then(function(r) { return r.json(); })
				.then(function(res) {
					lastData = res;
					var sessions = res.sessions || [];
					countEl.textContent = sessions.length;

					if (sessions.length === 0) {
						tbody.innerHTML = '<tr><td colspan="14" class="pl-empty">No active sessions. Heartbeats appear when visitors browse posts with the ad engine active.</td></tr>';
						return;
					}

					var html = '';
					for (var i = 0; i < sessions.length; i++) {
						html += renderRow(sessions[i]);
					}
					tbody.innerHTML = html;

					// Re-bind expand clicks.
					var rows = tbody.querySelectorAll('.pl-row-expand');
					for (var j = 0; j < rows.length; j++) {
						rows[j].addEventListener('click', onRowClick);
					}
				})
				.catch(function() {});
		}

		function onRowClick() {
			var sid = this.getAttribute('data-sid');
			expandedSid = expandedSid === sid ? null : sid;
			var detail = tbody.querySelector('[data-detail="' + sid + '"]');
			if (detail) {
				detail.style.display = expandedSid === sid ? '' : 'none';
			}
		}

		// Export button.
		document.getElementById('plExportLive').addEventListener('click', function() {
			fetch(API + '?_wpnonce=' + NONCE + '&recent=1', { credentials: 'same-origin' })
				.then(function(r) { return r.json(); })
				.then(function(res) {
					var blob = new Blob([JSON.stringify(res, null, 2)], { type: 'application/json' });
					var url = URL.createObjectURL(blob);
					var a = document.createElement('a');
					a.href = url;
					var d = new Date().toISOString().slice(0, 10);
					a.download = 'pl-live-sessions-' + d + '.json';
					a.click();
					URL.revokeObjectURL(url);
				});
		});

		// Start polling.
		refresh();
		setInterval(refresh, 5000);
	})();
	</script>
	<?php
}
