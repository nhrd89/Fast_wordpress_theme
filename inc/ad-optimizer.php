<?php
/**
 * PinLightning Ad Optimizer v2 — 12-Rule Self-Improving Daily Cron
 *
 * Uses aggregated stats from ad-analytics-aggregator.php (pl_ad_get_stats_range)
 * instead of reading raw session files. Applies 12 optimization rules covering
 * fill rate, passback, viewability, clicks, overlays, and more.
 *
 * Safety Rails:
 * - Max 3 setting changes per cron run
 * - All settings bounded with min/max/step
 * - Minimum 50 sessions required before any optimization
 * - All changes logged with full audit trail
 * - Never disables all ad formats
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

function pl_ad_optimizer_schedule() {
	if ( ! wp_next_scheduled( 'pl_ad_optimizer_daily' ) ) {
		$next_3am = strtotime( 'tomorrow 03:00:00' );
		wp_schedule_event( $next_3am, 'daily', 'pl_ad_optimizer_daily' );
	}
}
add_action( 'after_setup_theme', 'pl_ad_optimizer_schedule' );

function pl_ad_optimizer_unschedule() {
	wp_clear_scheduled_hook( 'pl_ad_optimizer_daily' );
}
add_action( 'switch_theme', 'pl_ad_optimizer_unschedule' );

add_action( 'pl_ad_optimizer_daily', 'pl_ad_run_optimizer' );

/* ================================================================
 * 2. ADJUSTABLE SETTINGS — BOUNDS & STEPS
 * ================================================================ */

/**
 * Settings the optimizer is allowed to change, with safety bounds.
 *
 * @return array setting_key => [min, max, step]
 */
function pl_optimizer_adjustable_settings() {
	return array(
		'gate_scroll_pct'  => array( 'min' => 5,   'max' => 40,   'step' => 5 ),
		'gate_time_sec'    => array( 'min' => 2,   'max' => 15,   'step' => 1 ),
		'max_display_ads'  => array( 'min' => 2,   'max' => 7,    'step' => 1 ),
		'min_spacing_px'   => array( 'min' => 600, 'max' => 2500, 'step' => 100 ),
		'pause_min_ads'    => array( 'min' => 1,   'max' => 4,    'step' => 1 ),
		'backfill_check_delay' => array( 'min' => 500, 'max' => 3000, 'step' => 250 ),
	);
}

/**
 * Safely adjust a setting by a given number of steps within bounds.
 *
 * @param  array  $current  Current settings array (by reference).
 * @param  string $key      Setting key.
 * @param  int    $steps    Positive = increase, negative = decrease.
 * @return array|false      ['from' => old, 'to' => new] or false if no change.
 */
function pl_optimizer_adjust( &$current, $key, $steps ) {
	$bounds = pl_optimizer_adjustable_settings();
	if ( ! isset( $bounds[ $key ] ) ) {
		return false;
	}
	$b   = $bounds[ $key ];
	$old = $current[ $key ] ?? $b['min'];
	$new = $old + ( $steps * $b['step'] );
	$new = max( $b['min'], min( $b['max'], $new ) );

	if ( $new === $old ) {
		return false;
	}

	$current[ $key ] = $new;
	return array( 'from' => $old, 'to' => $new );
}

/* ================================================================
 * 3. THE 12 OPTIMIZATION RULES
 * ================================================================ */

/**
 * Return the complete rule set. Each rule is an array with:
 *   id       — short identifier (R1-R12)
 *   name     — human-readable name
 *   trigger  — description of trigger condition
 *   action   — description of what changes
 *   evaluate — closure( $stats, $current ) => array|false
 *              Returns ['setting'=>key, 'steps'=>int, 'detail'=>string] or false.
 *
 * @return array
 */
function pl_optimizer_get_rules() {
	return array(

		// R1: Gate pass rate too low → relax gate scroll threshold.
		array(
			'id'      => 'R1',
			'name'    => 'Gate Pass Rate',
			'trigger' => 'Gate pass rate < 50%',
			'action'  => 'Decrease gate_scroll_pct -5',
			'evaluate' => function ( $s, $current ) {
				$rate = $s['gate_checks'] > 0 ? ( $s['gate_opens'] / $s['gate_checks'] ) * 100 : 100;
				if ( $rate < 50 ) {
					return array( 'setting' => 'gate_scroll_pct', 'steps' => -1, 'detail' => 'Gate pass rate ' . round( $rate ) . '% — relaxing scroll threshold' );
				}
				return false;
			},
		),

		// R2: Display fill rate low → reduce max ads to concentrate impressions.
		array(
			'id'      => 'R2',
			'name'    => 'Display Fill Rate',
			'trigger' => 'Ad.Plus fill rate < 40%',
			'action'  => 'Decrease max_display_ads -1',
			'evaluate' => function ( $s, $current ) {
				$rate = $s['total_ad_requests'] > 0 ? ( $s['total_ad_fills'] / $s['total_ad_requests'] ) * 100 : 100;
				if ( $rate < 40 ) {
					return array( 'setting' => 'max_display_ads', 'steps' => -1, 'detail' => 'Fill rate ' . round( $rate ) . '% — reducing max ads to concentrate demand' );
				}
				return false;
			},
		),

		// R3: Passback timing — if Newor fill rate < 30%, slow down backfill check.
		array(
			'id'      => 'R3',
			'name'    => 'Passback Timing',
			'trigger' => 'Newor fill rate < 30%',
			'action'  => 'Increase backfill_check_delay +250ms',
			'evaluate' => function ( $s, $current ) {
				if ( $s['waldo_total_requested'] < 10 ) {
					return false;
				}
				$rate = ( $s['waldo_total_filled'] / $s['waldo_total_requested'] ) * 100;
				if ( $rate < 30 ) {
					return array( 'setting' => 'backfill_check_delay', 'steps' => 1, 'detail' => 'Newor fill rate ' . round( $rate ) . '% — adding delay for ad to render' );
				}
				return false;
			},
		),

		// R4: Viewability low → increase spacing to place ads in better positions.
		array(
			'id'      => 'R4',
			'name'    => 'Viewability Spacing',
			'trigger' => 'Viewability < 40%',
			'action'  => 'Increase min_spacing_px +100',
			'evaluate' => function ( $s, $current ) {
				$v = $s['total_zones_activated'] > 0 ? ( $s['total_viewable_ads'] / $s['total_zones_activated'] ) * 100 : 100;
				if ( $v < 40 ) {
					return array( 'setting' => 'min_spacing_px', 'steps' => 1, 'detail' => 'Viewability ' . round( $v ) . '% — increasing spacing for better positions' );
				}
				return false;
			},
		),

		// R5: Viewability high → decrease spacing for more impressions.
		array(
			'id'      => 'R5',
			'name'    => 'Zone Viewability Check',
			'trigger' => 'Viewability > 70%',
			'action'  => 'Decrease min_spacing_px -100',
			'evaluate' => function ( $s, $current ) {
				$v = $s['total_zones_activated'] > 0 ? ( $s['total_viewable_ads'] / $s['total_zones_activated'] ) * 100 : 0;
				if ( $v > 70 ) {
					return array( 'setting' => 'min_spacing_px', 'steps' => -1, 'detail' => 'Viewability ' . round( $v ) . '% (strong) — tightening spacing for more impressions' );
				}
				return false;
			},
		),

		// R6: Click rate monitoring — high CTR (>5%) may indicate accidental clicks.
		array(
			'id'      => 'R6',
			'name'    => 'Click Rate Monitoring',
			'trigger' => 'Display CTR > 5%',
			'action'  => 'Increase min_spacing_px +100',
			'evaluate' => function ( $s, $current ) {
				$total_filled = $s['total_ad_fills'] + $s['waldo_total_filled'];
				if ( $total_filled < 20 ) {
					return false;
				}
				$ctr = ( $s['total_display_clicks'] / $total_filled ) * 100;
				if ( $ctr > 5 ) {
					return array( 'setting' => 'min_spacing_px', 'steps' => 1, 'detail' => 'Display CTR ' . round( $ctr, 1 ) . '% (suspiciously high) — increasing spacing to reduce accidental clicks' );
				}
				return false;
			},
		),

		// R7: Anchor performance — if anchor viewability < 30%, nothing to change
		// directly but log a warning. If anchor fill rate < 50%, log info.
		array(
			'id'      => 'R7',
			'name'    => 'Anchor Performance',
			'trigger' => 'Anchor viewability < 30%',
			'action'  => 'Warning logged (anchor is network-controlled)',
			'evaluate' => function ( $s, $current ) {
				if ( $s['anchor_fired'] < 5 ) {
					return false;
				}
				$v = $s['anchor_total_impressions'] > 0 ? ( $s['anchor_total_viewable'] / $s['anchor_total_impressions'] ) * 100 : 0;
				if ( $v < 30 ) {
					return array( 'setting' => '_warning', 'steps' => 0, 'detail' => 'Anchor viewability only ' . round( $v ) . '% — consider reviewing anchor placement with ad network' );
				}
				return false;
			},
		),

		// R8: Interstitial trigger rate too low — if < 20% of gate-opened sessions
		// trigger interstitial, it may mean the gate is too strict.
		array(
			'id'      => 'R8',
			'name'    => 'Interstitial Trigger Rate',
			'trigger' => 'Interstitial fires < 20% of gate opens',
			'action'  => 'Decrease gate_time_sec -1',
			'evaluate' => function ( $s, $current ) {
				if ( $s['gate_opens'] < 20 ) {
					return false;
				}
				$rate = ( $s['interstitial_fired'] / $s['gate_opens'] ) * 100;
				if ( $rate < 20 ) {
					return array( 'setting' => 'gate_time_sec', 'steps' => -1, 'detail' => 'Interstitial trigger rate ' . round( $rate ) . '% of gate opens — relaxing gate time' );
				}
				return false;
			},
		),

		// R9: Pause ad trigger threshold — if pause fires < 10% of sessions, lower required ads.
		array(
			'id'      => 'R9',
			'name'    => 'Pause Trigger Threshold',
			'trigger' => 'Pause fires < 10% of sessions',
			'action'  => 'Decrease pause_min_ads -1',
			'evaluate' => function ( $s, $current ) {
				if ( $s['total_sessions'] < 30 ) {
					return false;
				}
				$rate = ( $s['pause_fired'] / $s['total_sessions'] ) * 100;
				if ( $rate < 10 && $current['pause_min_ads'] > 1 ) {
					return array( 'setting' => 'pause_min_ads', 'steps' => -1, 'detail' => 'Pause trigger rate ' . round( $rate ) . '% — lowering min ads threshold' );
				}
				return false;
			},
		),

		// R10: Bounce rate gate — if bouncer pattern > 40%, tighten gate.
		array(
			'id'      => 'R10',
			'name'    => 'Bounce Rate Gate',
			'trigger' => 'Bouncer pattern > 40%',
			'action'  => 'Increase gate_time_sec +1',
			'evaluate' => function ( $s, $current ) {
				$bouncers = $s['by_pattern']['bouncer'] ?? 0;
				$rate     = $s['total_sessions'] > 0 ? ( $bouncers / $s['total_sessions'] ) * 100 : 0;
				if ( $rate > 40 ) {
					return array( 'setting' => 'gate_time_sec', 'steps' => 1, 'detail' => 'Bouncer rate ' . round( $rate ) . '% — tightening gate to filter low-quality visits' );
				}
				return false;
			},
		),

		// R11: Retry effectiveness — if retries succeed < 20% of the time, reduce max ads.
		array(
			'id'      => 'R11',
			'name'    => 'Retry Effectiveness',
			'trigger' => 'Retry success rate < 20%',
			'action'  => 'Decrease max_display_ads -1',
			'evaluate' => function ( $s, $current ) {
				if ( $s['total_retries'] < 10 ) {
					return false;
				}
				$rate = ( $s['retries_successful'] / $s['total_retries'] ) * 100;
				if ( $rate < 20 ) {
					return array( 'setting' => 'max_display_ads', 'steps' => -1, 'detail' => 'Retry success rate ' . round( $rate ) . '% — reducing max ads (retries waste resources)' );
				}
				return false;
			},
		),

		// R12: Combined fill optimization — if effective fill > 80%, try adding more ads.
		array(
			'id'      => 'R12',
			'name'    => 'Combined Fill Optimization',
			'trigger' => 'Effective fill > 80% + viewability > 60%',
			'action'  => 'Increase max_display_ads +1',
			'evaluate' => function ( $s, $current ) {
				$combined_showing = 0;
				foreach ( $s['by_zone'] as $z ) {
					$combined_showing += ( $z['filled'] ?? 0 ) + ( $z['passback_filled'] ?? 0 );
				}
				$effective = $s['total_zones_activated'] > 0 ? ( $combined_showing / $s['total_zones_activated'] ) * 100 : 0;
				$viewability = $s['total_zones_activated'] > 0 ? ( $s['total_viewable_ads'] / $s['total_zones_activated'] ) * 100 : 0;

				if ( $effective > 80 && $viewability > 60 ) {
					return array( 'setting' => 'max_display_ads', 'steps' => 1, 'detail' => 'Effective fill ' . round( $effective ) . '% + viewability ' . round( $viewability ) . '% — room for more ads' );
				}
				return false;
			},
		),
	);
}

/* ================================================================
 * 4. MAIN OPTIMIZER FUNCTION
 * ================================================================ */

define( 'PL_OPT_MAX_CHANGES',  3 );
define( 'PL_OPT_MIN_SESSIONS', 50 );

/**
 * Run the optimizer using aggregated stats from the last 3 days.
 */
function pl_ad_run_optimizer() {
	if ( ! function_exists( 'pl_ad_get_stats_range' ) ) {
		pl_optimizer_save_log( 'skip', 'Aggregator not loaded' );
		return;
	}

	$end   = current_time( 'Y-m-d' );
	$start = gmdate( 'Y-m-d', strtotime( '-2 days' ) );
	$data  = pl_ad_get_stats_range( $start, $end );
	$stats = $data['combined'];

	if ( $stats['total_sessions'] < PL_OPT_MIN_SESSIONS ) {
		pl_optimizer_save_log( 'skip', 'Insufficient data: ' . $stats['total_sessions'] . ' sessions (need ' . PL_OPT_MIN_SESSIONS . '+)' );
		return;
	}

	$settings = get_option( 'pl_ad_settings', array() );
	$defaults = function_exists( 'pl_ad_defaults' ) ? pl_ad_defaults() : array();
	$current  = wp_parse_args( $settings, $defaults );

	if ( empty( $current['enabled'] ) ) {
		pl_optimizer_save_log( 'skip', 'Ads disabled' );
		return;
	}

	$rules        = pl_optimizer_get_rules();
	$changes      = array();
	$warnings     = array();
	$info         = array();
	$change_count = 0;

	foreach ( $rules as $rule ) {
		if ( $change_count >= PL_OPT_MAX_CHANGES ) {
			break;
		}

		$result = call_user_func( $rule['evaluate'], $stats, $current );
		if ( ! $result ) {
			continue;
		}

		// Warning-only rules (no setting change).
		if ( $result['setting'] === '_warning' ) {
			$warnings[] = array(
				'rule'   => $rule['id'],
				'name'   => $rule['name'],
				'detail' => $result['detail'],
			);
			continue;
		}

		// Info-only (steps = 0).
		if ( $result['steps'] === 0 ) {
			$info[] = array(
				'rule'   => $rule['id'],
				'name'   => $rule['name'],
				'detail' => $result['detail'],
			);
			continue;
		}

		$adj = pl_optimizer_adjust( $current, $result['setting'], $result['steps'] );
		if ( $adj ) {
			$changes[] = array(
				'rule'    => $rule['id'],
				'name'    => $rule['name'],
				'setting' => $result['setting'],
				'from'    => $adj['from'],
				'to'      => $adj['to'],
				'detail'  => $result['detail'],
			);
			$change_count++;
		}
	}

	// Safety rail: never disable all display formats.
	$any_display = ! empty( $current['fmt_300x250'] ) || ! empty( $current['fmt_970x250'] ) || ! empty( $current['fmt_728x90'] );
	if ( ! $any_display ) {
		$current['fmt_300x250'] = true;
		$warnings[] = array(
			'rule'   => 'SAFETY',
			'name'   => 'Format Guard',
			'detail' => 'Re-enabled 300x250 — cannot skip all display formats',
		);
	}

	// Save changes.
	if ( ! empty( $changes ) ) {
		update_option( 'pl_ad_settings', $current );
	}

	// Build summary for log.
	$summary = array(
		'sessions'    => $stats['total_sessions'],
		'gate_rate'   => $stats['gate_checks'] > 0 ? round( ( $stats['gate_opens'] / $stats['gate_checks'] ) * 100 ) : 0,
		'fill_rate'   => $stats['total_ad_requests'] > 0 ? round( ( $stats['total_ad_fills'] / $stats['total_ad_requests'] ) * 100 ) : 0,
		'viewability' => $stats['total_zones_activated'] > 0 ? round( ( $stats['total_viewable_ads'] / $stats['total_zones_activated'] ) * 100 ) : 0,
		'bouncer_pct' => $stats['total_sessions'] > 0 ? round( ( ( $stats['by_pattern']['bouncer'] ?? 0 ) / $stats['total_sessions'] ) * 100 ) : 0,
	);

	$status = ! empty( $changes ) ? 'optimized' : 'no-change';
	pl_optimizer_save_log( $status, '', $summary, $changes, $warnings, $info );
}

/* ================================================================
 * 5. LOG / HISTORY MANAGEMENT
 * ================================================================ */

/**
 * Save an optimizer run entry.
 */
function pl_optimizer_save_log( $status, $reason = '', $summary = null, $changes = array(), $warnings = array(), $info = array() ) {
	$log = get_option( 'pl_ad_optimizer_log', array() );

	$entry = array(
		'time'   => current_time( 'mysql' ),
		'status' => $status,
	);

	if ( $reason ) {
		$entry['reason'] = $reason;
	}
	if ( $summary ) {
		$entry['summary'] = $summary;
	}
	if ( ! empty( $changes ) ) {
		$entry['changes'] = $changes;
	}
	if ( ! empty( $warnings ) ) {
		$entry['warnings'] = $warnings;
	}
	if ( ! empty( $info ) ) {
		$entry['info'] = $info;
	}

	$log[] = $entry;

	// Keep last 30 runs.
	if ( count( $log ) > 30 ) {
		$log = array_slice( $log, -30 );
	}

	update_option( 'pl_ad_optimizer_log', $log, false );
}

/**
 * Get the optimizer run history.
 *
 * @return array
 */
function pl_optimizer_get_history() {
	return get_option( 'pl_ad_optimizer_log', array() );
}

/**
 * Clear all optimizer history.
 */
function pl_optimizer_clear_history() {
	delete_option( 'pl_ad_optimizer_log' );
}

/* ================================================================
 * 6. ADMIN PAGE
 * ================================================================ */

/**
 * Register the optimizer submenu under Ad Engine.
 */
function pl_ad_optimizer_page() {
	add_submenu_page(
		'pl-ad-engine',
		'Ad Optimizer',
		'Optimizer',
		'manage_options',
		'pl-ad-optimizer',
		'pl_ad_render_optimizer'
	);
}
add_action( 'admin_menu', 'pl_ad_optimizer_page' );

/**
 * Handle POST actions on admin_init (before page output).
 */
function pl_ad_optimizer_handle_actions() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Manual run.
	if ( isset( $_POST['pl_optimizer_run_now'] ) && check_admin_referer( 'pl_optimizer_nonce' ) ) {
		pl_ad_run_optimizer();
		wp_safe_redirect( admin_url( 'admin.php?page=pl-ad-optimizer&ran=1' ) );
		exit;
	}

	// Export history.
	if ( isset( $_POST['pl_optimizer_export'] ) && check_admin_referer( 'pl_optimizer_nonce' ) ) {
		$log = pl_optimizer_get_history();
		header( 'Content-Type: application/json' );
		header( 'Content-Disposition: attachment; filename="pl-optimizer-history.json"' );
		header( 'Cache-Control: no-store' );
		echo wp_json_encode( $log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	// Clear history.
	if ( isset( $_POST['pl_optimizer_clear'] ) && check_admin_referer( 'pl_optimizer_nonce' ) ) {
		pl_optimizer_clear_history();
		wp_safe_redirect( admin_url( 'admin.php?page=pl-ad-optimizer&cleared=1' ) );
		exit;
	}
}
add_action( 'admin_init', 'pl_ad_optimizer_handle_actions' );

/**
 * Render the optimizer admin page.
 */
function pl_ad_render_optimizer() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$log      = pl_optimizer_get_history();
	$next_run = wp_next_scheduled( 'pl_ad_optimizer_daily' );
	$settings = get_option( 'pl_ad_settings', array() );
	$defaults = function_exists( 'pl_ad_defaults' ) ? pl_ad_defaults() : array();
	$current  = wp_parse_args( $settings, $defaults );
	$bounds   = pl_optimizer_adjustable_settings();
	$rules    = pl_optimizer_get_rules();

	?>
	<div class="wrap">
		<h1>Ad Optimizer v2</h1>

		<?php if ( ! empty( $_GET['ran'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>Optimizer run completed. Check results below.</p></div>
		<?php endif; ?>
		<?php if ( ! empty( $_GET['cleared'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>Optimizer history cleared.</p></div>
		<?php endif; ?>

		<!-- Navigation -->
		<div style="margin-bottom:20px;display:flex;gap:8px;">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=pl-ad-analytics-dashboard' ) ); ?>" class="button button-primary">Analytics Dashboard</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=pl-ad-live-sessions' ) ); ?>" class="button">Live Sessions</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=pl-ad-engine' ) ); ?>" class="button">Ad Engine Settings</a>
		</div>

		<!-- Top Cards -->
		<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:24px;">
			<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 20px;flex:1;min-width:180px;">
				<div style="font-size:13px;color:#646970;margin-bottom:4px;">Next Auto-Run</div>
				<div style="font-size:16px;font-weight:600;"><?php echo $next_run ? date_i18n( 'M j, Y g:i A', $next_run ) : 'Not scheduled'; ?></div>
			</div>
			<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 20px;flex:1;min-width:180px;">
				<div style="font-size:13px;color:#646970;margin-bottom:4px;">Total Runs</div>
				<div style="font-size:16px;font-weight:600;"><?php echo count( $log ); ?></div>
			</div>
			<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 20px;flex:1;min-width:180px;">
				<div style="font-size:13px;color:#646970;margin-bottom:4px;">Min Sessions</div>
				<div style="font-size:16px;font-weight:600;"><?php echo PL_OPT_MIN_SESSIONS; ?> (3-day window)</div>
			</div>
			<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 20px;flex:1;min-width:180px;">
				<div style="font-size:13px;color:#646970;margin-bottom:4px;">Max Changes/Run</div>
				<div style="font-size:16px;font-weight:600;"><?php echo PL_OPT_MAX_CHANGES; ?></div>
			</div>
		</div>

		<!-- Actions -->
		<div style="margin-bottom:24px;display:flex;gap:8px;align-items:center;">
			<form method="post" style="display:inline;margin:0;">
				<?php wp_nonce_field( 'pl_optimizer_nonce' ); ?>
				<button type="submit" name="pl_optimizer_run_now" class="button button-primary">Run Optimizer Now</button>
			</form>
			<form method="post" style="display:inline;margin:0;">
				<?php wp_nonce_field( 'pl_optimizer_nonce' ); ?>
				<button type="submit" name="pl_optimizer_export" class="button" style="background:#2271b1;border-color:#2271b1;color:#fff;">Export History (JSON)</button>
			</form>
			<form method="post" style="display:inline;margin:0;" onsubmit="return confirm('Clear all optimizer run history?');">
				<?php wp_nonce_field( 'pl_optimizer_nonce' ); ?>
				<button type="submit" name="pl_optimizer_clear" class="button" style="background:#d63638;border-color:#d63638;color:#fff;">Clear History</button>
			</form>
		</div>

		<!-- Current Settings -->
		<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 20px;margin-bottom:24px;">
			<h2 style="margin-top:0;">Current Adjustable Settings</h2>
			<table class="widefat striped" style="font-size:13px;">
				<thead><tr><th>Setting</th><th>Current Value</th><th>Min</th><th>Max</th><th>Step</th></tr></thead>
				<tbody>
					<?php foreach ( $bounds as $key => $b ) : ?>
						<tr>
							<td><code><?php echo esc_html( $key ); ?></code></td>
							<td style="font-weight:600;"><?php echo esc_html( $current[ $key ] ?? $b['min'] ); ?></td>
							<td><?php echo $b['min']; ?></td>
							<td><?php echo $b['max']; ?></td>
							<td><?php echo $b['step']; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<!-- 12 Rules Reference -->
		<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 20px;margin-bottom:24px;">
			<h2 style="margin-top:0;">12 Optimization Rules</h2>
			<table class="widefat striped" style="font-size:13px;">
				<thead><tr><th>#</th><th>Name</th><th>Trigger</th><th>Action</th></tr></thead>
				<tbody>
					<?php foreach ( $rules as $rule ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $rule['id'] ); ?></strong></td>
							<td><?php echo esc_html( $rule['name'] ); ?></td>
							<td><?php echo esc_html( $rule['trigger'] ); ?></td>
							<td><?php echo esc_html( $rule['action'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<!-- Run History -->
		<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 20px;">
			<h2 style="margin-top:0;">Run History (last 30)</h2>
			<?php if ( empty( $log ) ) : ?>
				<p style="color:#646970;">No optimizer runs yet. The optimizer will run automatically at 3 AM or you can trigger a manual run above.</p>
			<?php else : ?>
				<?php foreach ( array_reverse( $log ) as $entry ) :
					$status_colors = array( 'optimized' => '#00a32a', 'no-change' => '#646970', 'skip' => '#dba617' );
					$color = $status_colors[ $entry['status'] ] ?? '#646970';
				?>
					<div style="border:1px solid #eee;border-radius:4px;padding:12px 16px;margin-bottom:12px;">
						<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
							<div>
								<span style="color:<?php echo $color; ?>;font-weight:700;text-transform:uppercase;font-size:12px;"><?php echo esc_html( $entry['status'] ); ?></span>
								<span style="color:#888;font-size:12px;margin-left:8px;"><?php echo esc_html( $entry['time'] ); ?></span>
							</div>
							<?php if ( ! empty( $entry['summary'] ) ) : ?>
								<div style="font-size:12px;color:#666;">
									<?php echo $entry['summary']['sessions']; ?> sessions |
									Gate <?php echo $entry['summary']['gate_rate']; ?>% |
									Fill <?php echo $entry['summary']['fill_rate']; ?>% |
									View <?php echo $entry['summary']['viewability']; ?>%
								</div>
							<?php endif; ?>
						</div>

						<?php if ( ! empty( $entry['reason'] ) ) : ?>
							<div style="color:#888;font-size:13px;"><?php echo esc_html( $entry['reason'] ); ?></div>
						<?php endif; ?>

						<?php if ( ! empty( $entry['changes'] ) ) : ?>
							<?php foreach ( $entry['changes'] as $c ) : ?>
								<div style="background:#d4edda;border-radius:3px;padding:6px 10px;margin-top:6px;font-size:13px;">
									<strong><?php echo esc_html( $c['rule'] ); ?>: <?php echo esc_html( $c['name'] ); ?></strong> &mdash;
									<code><?php echo esc_html( $c['setting'] ); ?></code>:
									<?php echo esc_html( $c['from'] ); ?> &rarr; <?php echo esc_html( $c['to'] ); ?>
									<br><small style="color:#555;"><?php echo esc_html( $c['detail'] ); ?></small>
								</div>
							<?php endforeach; ?>
						<?php endif; ?>

						<?php if ( ! empty( $entry['warnings'] ) ) : ?>
							<?php foreach ( $entry['warnings'] as $w ) : ?>
								<div style="background:#fff3cd;border-radius:3px;padding:6px 10px;margin-top:6px;font-size:13px;">
									<strong><?php echo esc_html( $w['rule'] ); ?>: <?php echo esc_html( $w['name'] ); ?></strong> &mdash;
									<?php echo esc_html( $w['detail'] ); ?>
								</div>
							<?php endforeach; ?>
						<?php endif; ?>

						<?php if ( ! empty( $entry['info'] ) ) : ?>
							<?php foreach ( $entry['info'] as $i ) : ?>
								<div style="background:#cce5ff;border-radius:3px;padding:6px 10px;margin-top:6px;font-size:13px;">
									<strong><?php echo esc_html( $i['rule'] ); ?>: <?php echo esc_html( $i['name'] ); ?></strong> &mdash;
									<?php echo esc_html( $i['detail'] ); ?>
								</div>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
	</div>
	<?php
}
