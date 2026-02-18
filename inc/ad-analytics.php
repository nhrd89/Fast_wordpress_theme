<?php
/**
 * PinLightning Ad Analytics Dashboard
 *
 * Admin page that visualizes viewability data collected by ad-data-recorder.php.
 * Reads JSON session files from wp-content/uploads/pl-ad-data/ and displays
 * aggregate metrics, zone performance, device breakdown, and revenue estimates.
 *
 * @package PinLightning
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ================================================================
 * AJAX: Clear All Ad Data
 * ================================================================ */

function pl_ad_clear_all_data() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized', 403 );
	}
	check_ajax_referer( 'pl_ad_clear_nonce', 'nonce' );

	// 1. Delete stored options.
	delete_option( 'pl_ad_analytics' );
	delete_option( 'pl_ad_snapshots' );
	delete_option( 'pl_ad_daily_snapshots' );
	delete_option( 'pl_ad_optimizer_log' );
	delete_option( 'pl_ad_optimizer_state' );

	// 2. Delete transients matching pl_ad_sessions_* and pl_ad_rate_*.
	global $wpdb;
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE '_transient_pl_ad_sessions_%'
		    OR option_name LIKE '_transient_timeout_pl_ad_sessions_%'
		    OR option_name LIKE '_transient_pl_ad_rate_%'
		    OR option_name LIKE '_transient_timeout_pl_ad_rate_%'"
	);

	// 3. Delete session JSON files from wp-content/uploads/pl-ad-data/.
	$upload_dir = wp_upload_dir();
	$data_dir   = $upload_dir['basedir'] . '/pl-ad-data';
	if ( is_dir( $data_dir ) ) {
		$date_dirs = glob( $data_dir . '/*', GLOB_ONLYDIR );
		if ( $date_dirs ) {
			foreach ( $date_dirs as $ddir ) {
				$files = glob( $ddir . '/*.json' );
				if ( $files ) {
					array_map( 'unlink', $files );
				}
				// Remove index.php too.
				$idx = $ddir . '/index.php';
				if ( file_exists( $idx ) ) {
					unlink( $idx );
				}
				rmdir( $ddir );
			}
		}
		// Remove .htaccess and base directory.
		$htaccess = $data_dir . '/.htaccess';
		if ( file_exists( $htaccess ) ) {
			unlink( $htaccess );
		}
		rmdir( $data_dir );
	}

	wp_send_json_success( 'All ad data cleared.' );
}
add_action( 'wp_ajax_pl_ad_clear_all', 'pl_ad_clear_all_data' );

/* ================================================================
 * AJAX: Clear Optimizer Only
 * ================================================================ */

function pl_ad_clear_optimizer() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized', 403 );
	}
	check_ajax_referer( 'pl_ad_clear_nonce', 'nonce' );

	// Reset optimizer log and snapshots only — leave analytics/session data intact.
	delete_option( 'pl_ad_optimizer_log' );
	delete_option( 'pl_ad_optimizer_state' );
	delete_option( 'pl_ad_snapshots' );
	delete_option( 'pl_ad_daily_snapshots' );

	// Reset ad settings back to defaults (undo any optimizer-applied changes).
	if ( function_exists( 'pl_ad_defaults' ) ) {
		update_option( 'pl_ad_settings', pl_ad_defaults() );
	}

	wp_send_json_success( 'Optimizer data cleared, settings reset to defaults.' );
}
add_action( 'wp_ajax_pl_ad_clear_optimizer', 'pl_ad_clear_optimizer' );

/**
 * Register the analytics submenu under the Ad Engine menu.
 */
function pl_ad_analytics_menu() {
	add_submenu_page(
		'pl-ad-engine',
		'Ad Analytics',
		'Analytics',
		'manage_options',
		'pl-ad-analytics',
		'pl_ad_analytics_page'
	);
}
add_action( 'admin_menu', 'pl_ad_analytics_menu' );

/**
 * Load ad session data from disk for a given date range.
 *
 * @param  int $days Number of days to look back.
 * @return array     Array of session arrays.
 */
function pl_ad_analytics_load_sessions( $days = 7 ) {
	$upload_dir = wp_upload_dir();
	$base_dir   = $upload_dir['basedir'] . '/pl-ad-data';
	$sessions   = array();

	for ( $i = 0; $i < $days; $i++ ) {
		$date = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
		$dir  = $base_dir . '/' . $date;
		if ( ! is_dir( $dir ) ) {
			continue;
		}

		$files = glob( $dir . '/s_*.json' );
		if ( ! $files ) {
			continue;
		}

		foreach ( $files as $file ) {
			$data = json_decode( file_get_contents( $file ), true );
			if ( $data ) {
				$sessions[] = $data;
			}
		}
	}

	return $sessions;
}

/**
 * Compute aggregate analytics from session data.
 *
 * @param  array $sessions Raw session data.
 * @return array           Computed metrics.
 */
function pl_ad_analytics_compute( $sessions ) {
	$total = count( $sessions );
	if ( 0 === $total ) {
		return array(
			'total_sessions'      => 0,
			'avg_time_on_page_s'  => 0,
			'avg_scroll_depth'    => 0,
			'avg_ads_per_session' => 0,
			'viewability_pct'     => 0,
			'devices'             => array(),
			'scroll_patterns'     => array(),
			'zones'               => array(),
			'top_posts'           => array(),
			'daily'               => array(),
		);
	}

	$total_time      = 0;
	$total_depth     = 0;
	$total_ads       = 0;
	$total_viewable  = 0;
	$devices         = array();
	$patterns        = array();
	$zone_stats      = array();
	$post_stats      = array();
	$daily_stats     = array();

	foreach ( $sessions as $s ) {
		$total_time     += $s['time_on_page_ms'] ?? 0;
		$total_depth    += $s['max_scroll_depth_pct'] ?? 0;
		$total_ads      += $s['total_ads_injected'] ?? 0;
		$total_viewable += $s['total_viewable'] ?? 0;

		// Device breakdown.
		$dev = $s['device'] ?? 'unknown';
		$devices[ $dev ] = ( $devices[ $dev ] ?? 0 ) + 1;

		// Scroll pattern breakdown.
		$pat = $s['scroll_pattern'] ?? 'unknown';
		$patterns[ $pat ] = ( $patterns[ $pat ] ?? 0 ) + 1;

		// Per-post aggregation.
		$slug = $s['post_slug'] ?? 'unknown';
		if ( ! isset( $post_stats[ $slug ] ) ) {
			$post_stats[ $slug ] = array(
				'sessions'  => 0,
				'ads'       => 0,
				'viewable'  => 0,
				'time_ms'   => 0,
				'post_id'   => $s['post_id'] ?? 0,
			);
		}
		$post_stats[ $slug ]['sessions']++;
		$post_stats[ $slug ]['ads']      += $s['total_ads_injected'] ?? 0;
		$post_stats[ $slug ]['viewable'] += $s['total_viewable'] ?? 0;
		$post_stats[ $slug ]['time_ms']  += $s['time_on_page_ms'] ?? 0;

		// Daily aggregation.
		$day = substr( $s['timestamp'] ?? '', 0, 10 );
		if ( $day ) {
			if ( ! isset( $daily_stats[ $day ] ) ) {
				$daily_stats[ $day ] = array( 'sessions' => 0, 'ads' => 0, 'viewable' => 0 );
			}
			$daily_stats[ $day ]['sessions']++;
			$daily_stats[ $day ]['ads']      += $s['total_ads_injected'] ?? 0;
			$daily_stats[ $day ]['viewable'] += $s['total_viewable'] ?? 0;
		}

		// Per-zone aggregation.
		foreach ( $s['zones'] ?? array() as $z ) {
			$zid = $z['zone_id'] ?? 'unknown';
			if ( ! isset( $zone_stats[ $zid ] ) ) {
				$zone_stats[ $zid ] = array(
					'impressions'        => 0,
					'viewable'           => 0,
					'total_visible_ms'   => 0,
					'total_first_view'   => 0,
					'first_view_count'   => 0,
					'sizes'              => array(),
				);
			}
			$zone_stats[ $zid ]['impressions']++;
			$zone_stats[ $zid ]['viewable']          += $z['viewable_impressions'] ?? 0;
			$zone_stats[ $zid ]['total_visible_ms']  += $z['total_visible_ms'] ?? 0;

			$ftv = $z['time_to_first_view_ms'] ?? 0;
			if ( $ftv > 0 ) {
				$zone_stats[ $zid ]['total_first_view'] += $ftv;
				$zone_stats[ $zid ]['first_view_count']++;
			}

			$size = $z['ad_size'] ?? 'unknown';
			$zone_stats[ $zid ]['sizes'][ $size ] = ( $zone_stats[ $zid ]['sizes'][ $size ] ?? 0 ) + 1;
		}
	}

	// Sort posts by sessions descending.
	uasort( $post_stats, function( $a, $b ) {
		return $b['sessions'] - $a['sessions'];
	} );

	// Sort daily by date.
	ksort( $daily_stats );

	return array(
		'total_sessions'      => $total,
		'avg_time_on_page_s'  => round( $total_time / $total / 1000, 1 ),
		'avg_scroll_depth'    => round( $total_depth / $total, 1 ),
		'avg_ads_per_session' => round( $total_ads / $total, 1 ),
		'viewability_pct'     => $total_ads > 0 ? round( ( $total_viewable / $total_ads ) * 100, 1 ) : 0,
		'devices'             => $devices,
		'scroll_patterns'     => $patterns,
		'zones'               => $zone_stats,
		'top_posts'           => array_slice( $post_stats, 0, 10, true ),
		'daily'               => $daily_stats,
	);
}

/**
 * Render the analytics dashboard page.
 */
function pl_ad_analytics_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$days     = isset( $_GET['days'] ) ? max( 1, min( 90, (int) $_GET['days'] ) ) : 7;
	$sessions = pl_ad_analytics_load_sessions( $days );
	$data     = pl_ad_analytics_compute( $sessions );

	?>
	<div class="wrap">
		<h1>Ad Analytics</h1>

		<div style="display:flex;gap:10px;margin-bottom:16px">
			<button type="button" id="plClearAllData" class="button" style="background:#d63638;border-color:#d63638;color:#fff">Clear All Ad Data</button>
			<button type="button" id="plClearOptimizer" class="button">Clear Optimizer Only</button>
		</div>
		<div id="plClearNotice" style="display:none"></div>

		<script>
		(function(){
			var nonce = <?php echo wp_json_encode( wp_create_nonce( 'pl_ad_clear_nonce' ) ); ?>;
			var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

			document.getElementById('plClearAllData').addEventListener('click', function(){
				if (!confirm('Are you sure? This will delete ALL ad analytics data including daily snapshots, optimizer state, and session data. This cannot be undone.')) return;
				doAjax('pl_ad_clear_all');
			});

			document.getElementById('plClearOptimizer').addEventListener('click', function(){
				if (!confirm('This will reset the optimizer log, snapshots, and ad settings back to defaults. Analytics and session data will be preserved. Continue?')) return;
				doAjax('pl_ad_clear_optimizer');
			});

			function doAjax(action) {
				var fd = new FormData();
				fd.append('action', action);
				fd.append('nonce', nonce);
				fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
					.then(function(r){ return r.json(); })
					.then(function(res){
						var el = document.getElementById('plClearNotice');
						if (res.success) {
							el.className = 'notice notice-success';
							el.innerHTML = '<p>' + res.data + '</p>';
						} else {
							el.className = 'notice notice-error';
							el.innerHTML = '<p>Error: ' + (res.data || 'Unknown error') + '</p>';
						}
						el.style.display = '';
						setTimeout(function(){ location.reload(); }, 1200);
					})
					.catch(function(e){
						alert('Request failed: ' + e.message);
					});
			}
		})();
		</script>

		<form method="get" style="margin-bottom:20px">
			<input type="hidden" name="page" value="pl-ad-analytics">
			<label>Date range:
				<select name="days" onchange="this.form.submit()">
					<?php foreach ( array( 1, 3, 7, 14, 30 ) as $d ) : ?>
						<option value="<?php echo $d; ?>" <?php selected( $days, $d ); ?>><?php echo $d; ?> day<?php echo $d > 1 ? 's' : ''; ?></option>
					<?php endforeach; ?>
				</select>
			</label>
		</form>

		<?php if ( 0 === $data['total_sessions'] ) : ?>
			<div class="notice notice-warning">
				<p>No ad data collected in the last <?php echo $days; ?> day(s). Data is recorded when ads are enabled and visitors browse your posts.</p>
			</div>
			<?php return; ?>
		<?php endif; ?>

		<!-- Overview Cards -->
		<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:24px">
			<?php
			$cards = array(
				array( 'Sessions',        number_format( $data['total_sessions'] ),             '#2271b1' ),
				array( 'Avg Time',        $data['avg_time_on_page_s'] . 's',                   '#00a32a' ),
				array( 'Avg Scroll',      $data['avg_scroll_depth'] . '%',                     '#dba617' ),
				array( 'Ads/Session',     $data['avg_ads_per_session'],                         '#d63638' ),
				array( 'Viewability',     $data['viewability_pct'] . '%',                       '#2271b1' ),
			);
			foreach ( $cards as $c ) :
			?>
				<div style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid <?php echo $c[2]; ?>;border-radius:4px;padding:16px 20px;min-width:140px;flex:1">
					<div style="font-size:13px;color:#646970;margin-bottom:4px"><?php echo esc_html( $c[0] ); ?></div>
					<div style="font-size:28px;font-weight:600;color:#1d2327"><?php echo esc_html( $c[1] ); ?></div>
				</div>
			<?php endforeach; ?>
		</div>

		<div style="display:flex;gap:24px;flex-wrap:wrap">
			<!-- Left Column -->
			<div style="flex:2;min-width:400px">

				<!-- Daily Trend -->
				<?php if ( count( $data['daily'] ) > 1 ) : ?>
				<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:20px;margin-bottom:24px">
					<h2 style="margin-top:0">Daily Trend</h2>
					<table class="widefat striped">
						<thead>
							<tr>
								<th>Date</th>
								<th>Sessions</th>
								<th>Ads Served</th>
								<th>Viewable</th>
								<th>Viewability %</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $data['daily'] as $date => $d ) :
								$vr = $d['ads'] > 0 ? round( ( $d['viewable'] / $d['ads'] ) * 100, 1 ) : 0;
							?>
								<tr>
									<td><?php echo esc_html( $date ); ?></td>
									<td><?php echo number_format( $d['sessions'] ); ?></td>
									<td><?php echo number_format( $d['ads'] ); ?></td>
									<td><?php echo number_format( $d['viewable'] ); ?></td>
									<td>
										<span style="display:inline-block;width:50px;background:#e8f5e9;border-radius:3px;text-align:center;padding:2px 6px;font-weight:600;color:<?php echo $vr >= 70 ? '#2e7d32' : ( $vr >= 50 ? '#f9a825' : '#c62828' ); ?>">
											<?php echo $vr; ?>%
										</span>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php endif; ?>

				<!-- Zone Performance -->
				<?php if ( ! empty( $data['zones'] ) ) : ?>
				<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:20px;margin-bottom:24px">
					<h2 style="margin-top:0">Zone Performance</h2>
					<table class="widefat striped">
						<thead>
							<tr>
								<th>Zone ID</th>
								<th>Impressions</th>
								<th>Viewable</th>
								<th>Viewability %</th>
								<th>Avg Visible (s)</th>
								<th>Avg First View (s)</th>
								<th>Top Size</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $data['zones'] as $zid => $z ) :
								$vr   = $z['impressions'] > 0 ? round( ( $z['viewable'] / $z['impressions'] ) * 100, 1 ) : 0;
								$avgv = $z['impressions'] > 0 ? round( $z['total_visible_ms'] / $z['impressions'] / 1000, 1 ) : 0;
								$avgf = $z['first_view_count'] > 0 ? round( $z['total_first_view'] / $z['first_view_count'] / 1000, 1 ) : 0;
								arsort( $z['sizes'] );
								$top_size = ! empty( $z['sizes'] ) ? key( $z['sizes'] ) : '-';
							?>
								<tr>
									<td><code><?php echo esc_html( $zid ); ?></code></td>
									<td><?php echo number_format( $z['impressions'] ); ?></td>
									<td><?php echo number_format( $z['viewable'] ); ?></td>
									<td>
										<span style="display:inline-block;width:50px;background:#e8f5e9;border-radius:3px;text-align:center;padding:2px 6px;font-weight:600;color:<?php echo $vr >= 70 ? '#2e7d32' : ( $vr >= 50 ? '#f9a825' : '#c62828' ); ?>">
											<?php echo $vr; ?>%
										</span>
									</td>
									<td><?php echo $avgv; ?>s</td>
									<td><?php echo $avgf; ?>s</td>
									<td><code><?php echo esc_html( $top_size ); ?></code></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php endif; ?>

				<!-- Top Posts -->
				<?php if ( ! empty( $data['top_posts'] ) ) : ?>
				<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:20px;margin-bottom:24px">
					<h2 style="margin-top:0">Top Posts by Ad Sessions</h2>
					<table class="widefat striped">
						<thead>
							<tr>
								<th>Post</th>
								<th>Sessions</th>
								<th>Ads Served</th>
								<th>Viewable</th>
								<th>Avg Time</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $data['top_posts'] as $slug => $p ) :
								$avg_time = $p['sessions'] > 0 ? round( $p['time_ms'] / $p['sessions'] / 1000, 1 ) : 0;
								$title    = $p['post_id'] ? get_the_title( $p['post_id'] ) : $slug;
								$url      = $p['post_id'] ? get_permalink( $p['post_id'] ) : '#';
							?>
								<tr>
									<td><a href="<?php echo esc_url( $url ); ?>" target="_blank"><?php echo esc_html( $title ?: $slug ); ?></a></td>
									<td><?php echo number_format( $p['sessions'] ); ?></td>
									<td><?php echo number_format( $p['ads'] ); ?></td>
									<td><?php echo number_format( $p['viewable'] ); ?></td>
									<td><?php echo $avg_time; ?>s</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php endif; ?>
			</div>

			<!-- Right Column -->
			<div style="flex:1;min-width:250px">

				<!-- Device Breakdown -->
				<?php if ( ! empty( $data['devices'] ) ) : ?>
				<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:20px;margin-bottom:24px">
					<h2 style="margin-top:0">Devices</h2>
					<?php
					$device_colors = array( 'mobile' => '#2271b1', 'desktop' => '#00a32a', 'tablet' => '#dba617', 'unknown' => '#c3c4c7' );
					foreach ( $data['devices'] as $dev => $count ) :
						$pct = round( ( $count / $data['total_sessions'] ) * 100, 1 );
						$color = $device_colors[ $dev ] ?? '#c3c4c7';
					?>
						<div style="margin-bottom:12px">
							<div style="display:flex;justify-content:space-between;margin-bottom:4px">
								<span style="font-weight:600;text-transform:capitalize"><?php echo esc_html( $dev ); ?></span>
								<span><?php echo $pct; ?>% (<?php echo number_format( $count ); ?>)</span>
							</div>
							<div style="background:#f0f0f1;border-radius:3px;height:8px;overflow:hidden">
								<div style="background:<?php echo $color; ?>;width:<?php echo $pct; ?>%;height:100%;border-radius:3px"></div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>

				<!-- Scroll Patterns -->
				<?php if ( ! empty( $data['scroll_patterns'] ) ) : ?>
				<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:20px;margin-bottom:24px">
					<h2 style="margin-top:0">Scroll Behavior</h2>
					<?php
					$pattern_labels = array( 'reader' => 'Readers', 'scanner' => 'Scanners', 'bouncer' => 'Bouncers', 'unknown' => 'Unknown' );
					$pattern_colors = array( 'reader' => '#00a32a', 'scanner' => '#dba617', 'bouncer' => '#d63638', 'unknown' => '#c3c4c7' );
					foreach ( $data['scroll_patterns'] as $pat => $count ) :
						$pct   = round( ( $count / $data['total_sessions'] ) * 100, 1 );
						$label = $pattern_labels[ $pat ] ?? ucfirst( $pat );
						$color = $pattern_colors[ $pat ] ?? '#c3c4c7';
					?>
						<div style="margin-bottom:12px">
							<div style="display:flex;justify-content:space-between;margin-bottom:4px">
								<span style="font-weight:600"><?php echo esc_html( $label ); ?></span>
								<span><?php echo $pct; ?>% (<?php echo number_format( $count ); ?>)</span>
							</div>
							<div style="background:#f0f0f1;border-radius:3px;height:8px;overflow:hidden">
								<div style="background:<?php echo $color; ?>;width:<?php echo $pct; ?>%;height:100%;border-radius:3px"></div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>

				<!-- Revenue Estimates -->
				<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:20px;margin-bottom:24px">
					<h2 style="margin-top:0">Revenue Estimates</h2>
					<p style="color:#646970;font-size:12px;margin-top:0">Based on avg eCPMs from Ad.Plus reporting.</p>
					<?php
					// eCPM estimates from historical data.
					$ecpms = array(
						'300x250' => 0.49,
						'970x250' => 0.80,
						'728x90'  => 0.35,
						'anchor'  => 1.20,
						'interstitial' => 2.50,
					);

					$total_rev = 0;
					foreach ( $data['zones'] as $zid => $z ) {
						arsort( $z['sizes'] );
						$size = ! empty( $z['sizes'] ) ? key( $z['sizes'] ) : '300x250';
						$ecpm = $ecpms[ $size ] ?? 0.49;
						$rev  = ( $z['viewable'] / 1000 ) * $ecpm;
						$total_rev += $rev;
					}
					?>
					<div style="font-size:32px;font-weight:700;color:#2e7d32;margin:12px 0">
						$<?php echo number_format( $total_rev, 2 ); ?>
					</div>
					<div style="font-size:13px;color:#646970">
						Est. revenue for <?php echo $days; ?>-day period<br>
						<?php echo $days > 0 ? '$' . number_format( $total_rev / $days * 7, 2 ) . '/week projected' : ''; ?>
					</div>

					<div style="margin-top:16px;font-size:12px;color:#646970">
						<strong>eCPM rates used:</strong><br>
						<?php foreach ( $ecpms as $format => $rate ) : ?>
							<?php echo esc_html( $format ); ?>: $<?php echo number_format( $rate, 2 ); ?><br>
						<?php endforeach; ?>
					</div>
				</div>

				<!-- Data Info -->
				<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:20px">
					<h2 style="margin-top:0">Data Info</h2>
					<p style="margin:0;font-size:13px;color:#646970">
						Sessions: <?php echo number_format( $data['total_sessions'] ); ?><br>
						Zones tracked: <?php echo count( $data['zones'] ); ?><br>
						Posts tracked: <?php echo count( $data['top_posts'] ); ?><br>
						Date range: <?php echo $days; ?> day(s)<br>
						Storage: <code>wp-content/uploads/pl-ad-data/</code>
					</p>
					<p style="margin-top:12px;font-size:12px">
						<a href="<?php echo esc_url( rest_url( 'pinlightning/v1/ad-data?key=' . PL_ADS_DATA_KEY . '&days=' . $days . '&summary=true' ) ); ?>" target="_blank">View raw API summary &rarr;</a>
					</p>
				</div>
			</div>
		</div>
	</div>
	<?php
}
