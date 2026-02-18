<?php
/**
 * PinLightning Ad Optimizer — Self-Improving Daily Cron
 *
 * Reads viewability data collected by ad-data-recorder.php, applies
 * 7 optimization rules with safety rails, and adjusts ad-engine settings
 * to maximize viewability and revenue.
 *
 * Safety Rails:
 * - Max 3 setting changes per cron run
 * - Never skip all ad positions (min 1 active format)
 * - min_spacing_px bounded to 800-2500
 * - gate_scroll_pct bounded to 5-40
 * - gate_time_sec bounded to 2-15
 * - All changes logged to wp_options for audit trail
 *
 * Runs daily at 3 AM site time via wp_cron.
 *
 * @package PinLightning
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ================================================================
 * 1. CRON SCHEDULE
 * ================================================================ */

/**
 * Register the daily optimizer cron event on theme activation.
 */
function pl_ad_optimizer_schedule() {
	if ( ! wp_next_scheduled( 'pl_ad_optimizer_daily' ) ) {
		// Schedule for 3 AM local time.
		$next_3am = strtotime( 'tomorrow 03:00:00' );
		wp_schedule_event( $next_3am, 'daily', 'pl_ad_optimizer_daily' );
	}
}
add_action( 'after_setup_theme', 'pl_ad_optimizer_schedule' );

/**
 * Unschedule on theme deactivation.
 */
function pl_ad_optimizer_unschedule() {
	wp_clear_scheduled_hook( 'pl_ad_optimizer_daily' );
}
add_action( 'switch_theme', 'pl_ad_optimizer_unschedule' );

/**
 * Hook the optimizer to the cron event.
 */
add_action( 'pl_ad_optimizer_daily', 'pl_ad_optimizer_run' );

/* ================================================================
 * 2. SAFETY RAILS — CONSTANTS
 * ================================================================ */

define( 'PL_OPT_MAX_CHANGES',      3 );
define( 'PL_OPT_MIN_SPACING',      800 );
define( 'PL_OPT_MAX_SPACING',      2500 );
define( 'PL_OPT_MIN_GATE_SCROLL',  5 );
define( 'PL_OPT_MAX_GATE_SCROLL',  40 );
define( 'PL_OPT_MIN_GATE_TIME',    2 );
define( 'PL_OPT_MAX_GATE_TIME',    15 );
define( 'PL_OPT_MIN_VIEWABILITY',  40 ); // % — below this triggers spacing increase
define( 'PL_OPT_TARGET_VIEWABILITY', 65 ); // % — target viewability rate
define( 'PL_OPT_MIN_SESSIONS',     20 ); // Need at least this many sessions to optimize

/* ================================================================
 * 3. DAILY SNAPSHOT AGGREGATOR
 * ================================================================ */

/**
 * Aggregate yesterday's session data into a snapshot.
 *
 * @return array|false Snapshot data or false if insufficient data.
 */
function pl_ad_optimizer_snapshot() {
	$upload_dir = wp_upload_dir();
	$yesterday  = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
	$dir        = $upload_dir['basedir'] . '/pl-ad-data/' . $yesterday;

	if ( ! is_dir( $dir ) ) {
		return false;
	}

	$files    = glob( $dir . '/s_*.json' );
	$sessions = array();

	if ( ! $files ) {
		return false;
	}

	foreach ( $files as $file ) {
		$data = json_decode( file_get_contents( $file ), true );
		if ( $data ) {
			$sessions[] = $data;
		}
	}

	$total = count( $sessions );
	if ( $total < PL_OPT_MIN_SESSIONS ) {
		return false;
	}

	// Aggregate metrics.
	$total_ads      = 0;
	$total_viewable = 0;
	$total_time     = 0;
	$total_depth    = 0;
	$devices        = array( 'mobile' => 0, 'desktop' => 0 );
	$patterns       = array( 'reader' => 0, 'scanner' => 0, 'bouncer' => 0 );
	$zone_data      = array();

	foreach ( $sessions as $s ) {
		$total_ads      += $s['total_ads_injected'] ?? 0;
		$total_viewable += $s['total_viewable'] ?? 0;
		$total_time     += $s['time_on_page_ms'] ?? 0;
		$total_depth    += $s['max_scroll_depth_pct'] ?? 0;

		$dev = $s['device'] ?? 'unknown';
		if ( isset( $devices[ $dev ] ) ) {
			$devices[ $dev ]++;
		}

		$pat = $s['scroll_pattern'] ?? '';
		if ( isset( $patterns[ $pat ] ) ) {
			$patterns[ $pat ]++;
		}

		foreach ( $s['zones'] ?? array() as $z ) {
			$zid = $z['zone_id'] ?? '';
			if ( ! $zid ) {
				continue;
			}
			if ( ! isset( $zone_data[ $zid ] ) ) {
				$zone_data[ $zid ] = array(
					'impressions' => 0,
					'viewable'    => 0,
					'visible_ms'  => 0,
				);
			}
			$zone_data[ $zid ]['impressions']++;
			$zone_data[ $zid ]['viewable']   += $z['viewable_impressions'] ?? 0;
			$zone_data[ $zid ]['visible_ms'] += $z['total_visible_ms'] ?? 0;
		}
	}

	$snapshot = array(
		'date'              => $yesterday,
		'sessions'          => $total,
		'total_ads'         => $total_ads,
		'total_viewable'    => $total_viewable,
		'viewability_pct'   => $total_ads > 0 ? round( ( $total_viewable / $total_ads ) * 100, 1 ) : 0,
		'avg_time_s'        => round( $total_time / $total / 1000, 1 ),
		'avg_depth_pct'     => round( $total_depth / $total, 1 ),
		'avg_ads_per_session' => round( $total_ads / $total, 1 ),
		'devices'           => $devices,
		'patterns'          => $patterns,
		'bouncer_pct'       => $total > 0 ? round( ( $patterns['bouncer'] / $total ) * 100, 1 ) : 0,
		'reader_pct'        => $total > 0 ? round( ( $patterns['reader'] / $total ) * 100, 1 ) : 0,
		'zones'             => $zone_data,
	);

	// Store snapshot.
	$snapshots = get_option( 'pl_ad_snapshots', array() );
	$snapshots[ $yesterday ] = $snapshot;

	// Keep only last 30 days of snapshots.
	$cutoff = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
	$snapshots = array_filter( $snapshots, function( $v, $k ) use ( $cutoff ) {
		return $k >= $cutoff;
	}, ARRAY_FILTER_USE_BOTH );

	update_option( 'pl_ad_snapshots', $snapshots, false );

	return $snapshot;
}

/* ================================================================
 * 4. THE 7 OPTIMIZATION RULES
 * ================================================================ */

/**
 * Run the optimizer: aggregate snapshot, apply rules, save changes.
 */
function pl_ad_optimizer_run() {
	$snapshot = pl_ad_optimizer_snapshot();
	if ( ! $snapshot ) {
		pl_ad_optimizer_log( 'skip', 'Insufficient data for optimization (need ' . PL_OPT_MIN_SESSIONS . '+ sessions)' );
		return;
	}

	$settings = get_option( 'pl_ad_settings', array() );
	$defaults = pl_ad_defaults();
	$current  = wp_parse_args( $settings, $defaults );

	// Don't optimize if ads are disabled.
	if ( ! $current['enabled'] ) {
		pl_ad_optimizer_log( 'skip', 'Ads disabled — skipping optimization' );
		return;
	}

	$changes      = array();
	$change_count = 0;

	// --- RULE 1: Low viewability → increase spacing ---
	// If viewability < 40%, users aren't seeing ads long enough.
	// Increase spacing to place ads in better positions.
	if ( $snapshot['viewability_pct'] < PL_OPT_MIN_VIEWABILITY && $change_count < PL_OPT_MAX_CHANGES ) {
		$new_spacing = min( PL_OPT_MAX_SPACING, $current['min_spacing_px'] + 100 );
		if ( $new_spacing !== $current['min_spacing_px'] ) {
			$changes['min_spacing_px'] = array(
				'from' => $current['min_spacing_px'],
				'to'   => $new_spacing,
				'rule' => 'R1: Low viewability (' . $snapshot['viewability_pct'] . '%) — increase spacing',
			);
			$current['min_spacing_px'] = $new_spacing;
			$change_count++;
		}
	}

	// --- RULE 2: High viewability → try reducing spacing for more impressions ---
	// If viewability > target and spacing is above minimum, try tightening slightly.
	if ( $snapshot['viewability_pct'] > PL_OPT_TARGET_VIEWABILITY && $change_count < PL_OPT_MAX_CHANGES ) {
		$new_spacing = max( PL_OPT_MIN_SPACING, $current['min_spacing_px'] - 50 );
		if ( $new_spacing !== $current['min_spacing_px'] ) {
			$changes['min_spacing_px'] = array(
				'from' => $current['min_spacing_px'],
				'to'   => $new_spacing,
				'rule' => 'R2: High viewability (' . $snapshot['viewability_pct'] . '%) — decrease spacing for more impressions',
			);
			$current['min_spacing_px'] = $new_spacing;
			$change_count++;
		}
	}

	// --- RULE 3: High bouncer rate → increase gate thresholds ---
	// If >40% of visitors bounce, ads are loading for disengaged users.
	if ( $snapshot['bouncer_pct'] > 40 && $change_count < PL_OPT_MAX_CHANGES ) {
		$new_gate_time = min( PL_OPT_MAX_GATE_TIME, $current['gate_time_sec'] + 1 );
		if ( $new_gate_time !== $current['gate_time_sec'] ) {
			$changes['gate_time_sec'] = array(
				'from' => $current['gate_time_sec'],
				'to'   => $new_gate_time,
				'rule' => 'R3: High bouncer rate (' . $snapshot['bouncer_pct'] . '%) — increase gate time',
			);
			$current['gate_time_sec'] = $new_gate_time;
			$change_count++;
		}
	}

	// --- RULE 4: Low bouncer rate + low gate → relax gate for more impressions ---
	// If <15% bouncers and gate is strict, relax to show ads sooner.
	if ( $snapshot['bouncer_pct'] < 15 && $change_count < PL_OPT_MAX_CHANGES ) {
		$new_gate_time = max( PL_OPT_MIN_GATE_TIME, $current['gate_time_sec'] - 1 );
		if ( $new_gate_time !== $current['gate_time_sec'] ) {
			$changes['gate_time_sec'] = array(
				'from' => $current['gate_time_sec'],
				'to'   => $new_gate_time,
				'rule' => 'R4: Low bouncer rate (' . $snapshot['bouncer_pct'] . '%) — relax gate time',
			);
			$current['gate_time_sec'] = $new_gate_time;
			$change_count++;
		}
	}

	// --- RULE 5: Too few ads per session → increase max ads or reduce spacing ---
	// If avg ads < 2 and we have room, allow more.
	if ( $snapshot['avg_ads_per_session'] < 2 && $current['max_display_ads'] < 6 && $change_count < PL_OPT_MAX_CHANGES ) {
		$new_max = $current['max_display_ads'] + 1;
		$changes['max_display_ads'] = array(
			'from' => $current['max_display_ads'],
			'to'   => $new_max,
			'rule' => 'R5: Low ads/session (' . $snapshot['avg_ads_per_session'] . ') — increase max ads',
		);
		$current['max_display_ads'] = $new_max;
		$change_count++;
	}

	// --- RULE 6: Too many ads per session with low viewability → reduce max ---
	// Overloading hurts viewability. Pull back.
	if ( $snapshot['avg_ads_per_session'] > 4 && $snapshot['viewability_pct'] < 50 && $change_count < PL_OPT_MAX_CHANGES ) {
		$new_max = max( 2, $current['max_display_ads'] - 1 );
		if ( $new_max !== $current['max_display_ads'] ) {
			$changes['max_display_ads'] = array(
				'from' => $current['max_display_ads'],
				'to'   => $new_max,
				'rule' => 'R6: High ads (' . $snapshot['avg_ads_per_session'] . '/session) + low viewability (' . $snapshot['viewability_pct'] . '%) — reduce max',
			);
			$current['max_display_ads'] = $new_max;
			$change_count++;
		}
	}

	// --- RULE 7: Mobile vs Desktop format toggle ---
	// If mobile has <50 sessions but desktop has >50, consider
	// disabling underperforming desktop-only formats (970x250 on mobile never serves).
	// Also: if 970x250 zones have <30% viewability, disable the format.
	if ( $change_count < PL_OPT_MAX_CHANGES ) {
		$low_viewability_formats = array();
		foreach ( $snapshot['zones'] as $zid => $zdata ) {
			if ( $zdata['impressions'] < 5 ) {
				continue;
			}
			$zone_vr = round( ( $zdata['viewable'] / $zdata['impressions'] ) * 100, 1 );
			if ( $zone_vr < 30 ) {
				$low_viewability_formats[ $zid ] = $zone_vr;
			}
		}

		// Check if any 970x250 zones are consistently underperforming.
		$billboard_low = false;
		foreach ( $low_viewability_formats as $zid => $vr ) {
			if ( strpos( $zid, 'auto-' ) !== false || strpos( $zid, 'eb-mid-' ) !== false ) {
				$billboard_low = true;
				break;
			}
		}

		// Safety rail: never disable ALL display formats.
		if ( $billboard_low && $current['fmt_970x250'] && $current['fmt_300x250'] ) {
			$changes['fmt_970x250'] = array(
				'from' => true,
				'to'   => false,
				'rule' => 'R7: 970x250 zones underperforming (<30% viewability) — disabled, keeping 300x250',
			);
			$current['fmt_970x250'] = false;
			$change_count++;
		}
	}

	// --- SAFETY RAIL: Never skip all positions ---
	// Ensure at least one display format remains enabled.
	$any_display = $current['fmt_300x250'] || $current['fmt_970x250'] || $current['fmt_728x90'];
	if ( ! $any_display ) {
		$current['fmt_300x250'] = true;
		$changes['fmt_300x250'] = array(
			'from' => false,
			'to'   => true,
			'rule' => 'SAFETY: Re-enabled 300x250 — cannot skip all display formats',
		);
	}

	// Apply changes.
	if ( ! empty( $changes ) ) {
		update_option( 'pl_ad_settings', $current );
		// Clear static cache.
		if ( function_exists( 'pl_ad_settings' ) ) {
			// Static cache reset requires page reload; cron runs in isolation.
		}
	}

	// Log the run.
	pl_ad_optimizer_log( empty( $changes ) ? 'no-change' : 'optimized', '', $snapshot, $changes );
}

/* ================================================================
 * 5. CHANGES LOG
 * ================================================================ */

/**
 * Log an optimizer run to wp_options.
 *
 * @param string $status   'optimized', 'no-change', or 'skip'.
 * @param string $reason   Optional reason for skip.
 * @param array  $snapshot Optional snapshot data.
 * @param array  $changes  Optional changes made.
 */
function pl_ad_optimizer_log( $status, $reason = '', $snapshot = null, $changes = array() ) {
	$log = get_option( 'pl_ad_optimizer_log', array() );

	$entry = array(
		'time'    => current_time( 'mysql' ),
		'status'  => $status,
	);

	if ( $reason ) {
		$entry['reason'] = $reason;
	}

	if ( $snapshot ) {
		$entry['snapshot'] = array(
			'sessions'        => $snapshot['sessions'],
			'viewability_pct' => $snapshot['viewability_pct'],
			'avg_ads'         => $snapshot['avg_ads_per_session'],
			'bouncer_pct'     => $snapshot['bouncer_pct'],
			'avg_time_s'      => $snapshot['avg_time_s'],
		);
	}

	if ( ! empty( $changes ) ) {
		$entry['changes'] = array();
		foreach ( $changes as $key => $change ) {
			$entry['changes'][] = array(
				'setting' => $key,
				'from'    => $change['from'],
				'to'      => $change['to'],
				'rule'    => $change['rule'],
			);
		}
	}

	$log[] = $entry;

	// Keep only last 90 entries.
	if ( count( $log ) > 90 ) {
		$log = array_slice( $log, -90 );
	}

	update_option( 'pl_ad_optimizer_log', $log, false );
}

/* ================================================================
 * 6. ADMIN: OPTIMIZER LOG SUBMENU
 * ================================================================ */

/**
 * Register the optimizer log submenu under Ad Engine.
 */
function pl_ad_optimizer_menu() {
	add_submenu_page(
		'pl-ad-engine',
		'Optimizer Log',
		'Optimizer',
		'manage_options',
		'pl-ad-optimizer',
		'pl_ad_optimizer_page'
	);
}
add_action( 'admin_menu', 'pl_ad_optimizer_menu' );

/**
 * Render the optimizer log page.
 */
function pl_ad_optimizer_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Handle manual run trigger.
	if ( isset( $_POST['pl_optimizer_run_now'] ) && check_admin_referer( 'pl_optimizer_run_nonce' ) ) {
		pl_ad_optimizer_run();
		echo '<div class="notice notice-success is-dismissible"><p>Optimizer run completed. Check log below.</p></div>';
	}

	$log       = get_option( 'pl_ad_optimizer_log', array() );
	$snapshots = get_option( 'pl_ad_snapshots', array() );
	$next_run  = wp_next_scheduled( 'pl_ad_optimizer_daily' );

	?>
	<div class="wrap">
		<h1>Ad Optimizer</h1>

		<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:24px">
			<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 20px;flex:1;min-width:200px">
				<div style="font-size:13px;color:#646970;margin-bottom:4px">Next Run</div>
				<div style="font-size:16px;font-weight:600"><?php echo $next_run ? date_i18n( 'M j, Y g:i A', $next_run ) : 'Not scheduled'; ?></div>
			</div>
			<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 20px;flex:1;min-width:200px">
				<div style="font-size:13px;color:#646970;margin-bottom:4px">Total Runs</div>
				<div style="font-size:16px;font-weight:600"><?php echo count( $log ); ?></div>
			</div>
			<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 20px;flex:1;min-width:200px">
				<div style="font-size:13px;color:#646970;margin-bottom:4px">Snapshots Stored</div>
				<div style="font-size:16px;font-weight:600"><?php echo count( $snapshots ); ?> days</div>
			</div>
			<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 20px;flex:1;min-width:200px">
				<form method="post" style="margin:0">
					<?php wp_nonce_field( 'pl_optimizer_run_nonce' ); ?>
					<div style="font-size:13px;color:#646970;margin-bottom:4px">Manual Run</div>
					<button type="submit" name="pl_optimizer_run_now" class="button button-primary" style="margin-top:2px">Run Now</button>
				</form>
			</div>
		</div>

		<!-- Safety Rails Info -->
		<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 20px;margin-bottom:24px">
			<h2 style="margin-top:0">Safety Rails</h2>
			<div style="display:flex;gap:24px;flex-wrap:wrap;font-size:13px;color:#646970">
				<div>Max <?php echo PL_OPT_MAX_CHANGES; ?> changes/run</div>
				<div>Spacing: <?php echo PL_OPT_MIN_SPACING; ?>-<?php echo PL_OPT_MAX_SPACING; ?>px</div>
				<div>Gate scroll: <?php echo PL_OPT_MIN_GATE_SCROLL; ?>-<?php echo PL_OPT_MAX_GATE_SCROLL; ?>%</div>
				<div>Gate time: <?php echo PL_OPT_MIN_GATE_TIME; ?>-<?php echo PL_OPT_MAX_GATE_TIME; ?>s</div>
				<div>Min sessions: <?php echo PL_OPT_MIN_SESSIONS; ?></div>
				<div>Never skip all formats</div>
			</div>
		</div>

		<!-- 7 Rules Reference -->
		<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 20px;margin-bottom:24px">
			<h2 style="margin-top:0">Optimization Rules</h2>
			<table class="widefat striped" style="font-size:13px">
				<thead><tr><th>#</th><th>Trigger</th><th>Action</th></tr></thead>
				<tbody>
					<tr><td>R1</td><td>Viewability &lt; <?php echo PL_OPT_MIN_VIEWABILITY; ?>%</td><td>Increase spacing +100px</td></tr>
					<tr><td>R2</td><td>Viewability &gt; <?php echo PL_OPT_TARGET_VIEWABILITY; ?>%</td><td>Decrease spacing -50px (more impressions)</td></tr>
					<tr><td>R3</td><td>Bouncer rate &gt; 40%</td><td>Increase gate time +1s</td></tr>
					<tr><td>R4</td><td>Bouncer rate &lt; 15%</td><td>Decrease gate time -1s</td></tr>
					<tr><td>R5</td><td>Avg ads/session &lt; 2</td><td>Increase max ads +1</td></tr>
					<tr><td>R6</td><td>Avg ads/session &gt; 4 + viewability &lt; 50%</td><td>Decrease max ads -1</td></tr>
					<tr><td>R7</td><td>970x250 zones &lt; 30% viewability</td><td>Disable 970x250 format</td></tr>
				</tbody>
			</table>
		</div>

		<!-- Run Log -->
		<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 20px">
			<h2 style="margin-top:0">Run Log</h2>
			<?php if ( empty( $log ) ) : ?>
				<p style="color:#646970">No optimizer runs yet. Data collection begins when ads are enabled.</p>
			<?php else : ?>
				<table class="widefat striped" style="font-size:13px">
					<thead>
						<tr>
							<th>Time</th>
							<th>Status</th>
							<th>Sessions</th>
							<th>Viewability</th>
							<th>Ads/Session</th>
							<th>Changes</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( array_reverse( $log ) as $entry ) :
							$status_color = array( 'optimized' => '#00a32a', 'no-change' => '#646970', 'skip' => '#dba617' );
							$color = $status_color[ $entry['status'] ] ?? '#646970';
						?>
							<tr>
								<td><?php echo esc_html( $entry['time'] ); ?></td>
								<td><span style="color:<?php echo $color; ?>;font-weight:600"><?php echo esc_html( $entry['status'] ); ?></span></td>
								<td><?php echo isset( $entry['snapshot'] ) ? $entry['snapshot']['sessions'] : ( $entry['reason'] ?? '-' ); ?></td>
								<td><?php echo isset( $entry['snapshot'] ) ? $entry['snapshot']['viewability_pct'] . '%' : '-'; ?></td>
								<td><?php echo isset( $entry['snapshot'] ) ? $entry['snapshot']['avg_ads'] : '-'; ?></td>
								<td>
									<?php if ( ! empty( $entry['changes'] ) ) : ?>
										<?php foreach ( $entry['changes'] as $c ) : ?>
											<div style="margin-bottom:4px">
												<code><?php echo esc_html( $c['setting'] ); ?></code>:
												<?php echo esc_html( $c['from'] ); ?> &rarr; <?php echo esc_html( $c['to'] ); ?>
												<br><small style="color:#646970"><?php echo esc_html( $c['rule'] ); ?></small>
											</div>
										<?php endforeach; ?>
									<?php else : ?>
										-
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</div>
	<?php
}
