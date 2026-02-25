<?php
/**
 * PinLightning Ad Optimizer — Control Center
 *
 * Two responsibilities:
 * 1. Daily cron with 12 optimization rules (sections 1-5)
 * 2. Admin control center for all ad settings (sections 6-9)
 *
 * Settings stored in 7 separate wp_options:
 *   pl_ad_format_settings    — per-format enable/disable
 *   pl_ad_refresh_settings   — per-format refresh config
 *   pl_ad_layer2_settings    — dynamic injection tuning
 *   pl_ad_video_settings     — video outstream config
 *   pl_ad_viewability_settings — viewability optimization
 *   pl_ad_ecpm_settings      — eCPM values for revenue estimation
 *   pl_ad_auto_optimize      — auto-optimization smart rules
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

function pl_optimizer_adjustable_settings() {
	return array(
		'max_display_ads'      => array( 'min' => 2,   'max' => 7,    'step' => 1 ),
		'min_spacing_px'       => array( 'min' => 600, 'max' => 2500, 'step' => 100 ),
		'pause_min_ads'        => array( 'min' => 1,   'max' => 4,    'step' => 1 ),
		'backfill_check_delay' => array( 'min' => 500, 'max' => 3000, 'step' => 250 ),
	);
}

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

function pl_optimizer_get_rules() {
	return array(
		array(
			'id' => 'R2', 'name' => 'Display Fill Rate',
			'trigger' => 'Ad.Plus fill rate < 40%', 'action' => 'Decrease max_display_ads -1',
			'evaluate' => function ( $s, $current ) {
				$rate = $s['total_ad_requests'] > 0 ? ( $s['total_ad_fills'] / $s['total_ad_requests'] ) * 100 : 100;
				if ( $rate < 40 ) return array( 'setting' => 'max_display_ads', 'steps' => -1, 'detail' => 'Fill rate ' . round( $rate ) . '% — reducing max ads' );
				return false;
			},
		),
		array(
			'id' => 'R3', 'name' => 'Passback Timing',
			'trigger' => 'Newor fill rate < 30%', 'action' => 'Increase backfill_check_delay +250ms',
			'evaluate' => function ( $s, $current ) {
				if ( $s['waldo_total_requested'] < 10 ) return false;
				$rate = ( $s['waldo_total_filled'] / $s['waldo_total_requested'] ) * 100;
				if ( $rate < 30 ) return array( 'setting' => 'backfill_check_delay', 'steps' => 1, 'detail' => 'Newor fill rate ' . round( $rate ) . '% — adding delay' );
				return false;
			},
		),
		array(
			'id' => 'R4', 'name' => 'Viewability Spacing',
			'trigger' => 'Viewability < 40%', 'action' => 'Increase min_spacing_px +100',
			'evaluate' => function ( $s, $current ) {
				$v = $s['total_zones_activated'] > 0 ? ( $s['total_viewable_ads'] / $s['total_zones_activated'] ) * 100 : 100;
				if ( $v < 40 ) return array( 'setting' => 'min_spacing_px', 'steps' => 1, 'detail' => 'Viewability ' . round( $v ) . '% — increasing spacing' );
				return false;
			},
		),
		array(
			'id' => 'R5', 'name' => 'Zone Viewability Check',
			'trigger' => 'Viewability > 70%', 'action' => 'Decrease min_spacing_px -100',
			'evaluate' => function ( $s, $current ) {
				$v = $s['total_zones_activated'] > 0 ? ( $s['total_viewable_ads'] / $s['total_zones_activated'] ) * 100 : 0;
				if ( $v > 70 ) return array( 'setting' => 'min_spacing_px', 'steps' => -1, 'detail' => 'Viewability ' . round( $v ) . '% — tightening spacing' );
				return false;
			},
		),
		array(
			'id' => 'R6', 'name' => 'Click Rate Monitoring',
			'trigger' => 'Display CTR > 5%', 'action' => 'Increase min_spacing_px +100',
			'evaluate' => function ( $s, $current ) {
				$total_filled = $s['total_ad_fills'] + $s['waldo_total_filled'];
				if ( $total_filled < 20 ) return false;
				$ctr = ( $s['total_display_clicks'] / $total_filled ) * 100;
				if ( $ctr > 5 ) return array( 'setting' => 'min_spacing_px', 'steps' => 1, 'detail' => 'CTR ' . round( $ctr, 1 ) . '% — increasing spacing' );
				return false;
			},
		),
		array(
			'id' => 'R7', 'name' => 'Anchor Performance',
			'trigger' => 'Anchor viewability < 30%', 'action' => 'Warning logged',
			'evaluate' => function ( $s, $current ) {
				if ( $s['anchor_fired'] < 5 ) return false;
				$v = $s['anchor_total_impressions'] > 0 ? ( $s['anchor_total_viewable'] / $s['anchor_total_impressions'] ) * 100 : 0;
				if ( $v < 30 ) return array( 'setting' => '_warning', 'steps' => 0, 'detail' => 'Anchor viewability ' . round( $v ) . '%' );
				return false;
			},
		),
		array(
			'id' => 'R8', 'name' => 'Interstitial Trigger Rate',
			'trigger' => 'Interstitial fires < 20% of sessions', 'action' => 'Warning logged',
			'evaluate' => function ( $s, $current ) {
				if ( $s['total_sessions'] < 50 ) return false;
				$rate = ( $s['interstitial_fired'] / $s['total_sessions'] ) * 100;
				if ( $rate < 20 ) return array( 'setting' => '_warning', 'steps' => 0, 'detail' => 'Interstitial trigger rate ' . round( $rate ) . '% of sessions' );
				return false;
			},
		),
		array(
			'id' => 'R9', 'name' => 'Pause Trigger Threshold',
			'trigger' => 'Pause fires < 10% of sessions', 'action' => 'Decrease pause_min_ads -1',
			'evaluate' => function ( $s, $current ) {
				if ( $s['total_sessions'] < 30 ) return false;
				$rate = ( $s['pause_fired'] / $s['total_sessions'] ) * 100;
				if ( $rate < 10 && $current['pause_min_ads'] > 1 ) return array( 'setting' => 'pause_min_ads', 'steps' => -1, 'detail' => 'Pause rate ' . round( $rate ) . '%' );
				return false;
			},
		),
		array(
			'id' => 'R10', 'name' => 'Bounce Rate Monitor',
			'trigger' => 'Bouncer pattern > 40%', 'action' => 'Warning logged',
			'evaluate' => function ( $s, $current ) {
				$bouncers = $s['by_pattern']['bouncer'] ?? 0;
				$rate = $s['total_sessions'] > 0 ? ( $bouncers / $s['total_sessions'] ) * 100 : 0;
				if ( $rate > 40 ) return array( 'setting' => '_warning', 'steps' => 0, 'detail' => 'Bouncer rate ' . round( $rate ) . '% — consider switching to Light density' );
				return false;
			},
		),
		array(
			'id' => 'R11', 'name' => 'Retry Effectiveness',
			'trigger' => 'Retry success rate < 20%', 'action' => 'Decrease max_display_ads -1',
			'evaluate' => function ( $s, $current ) {
				if ( $s['total_retries'] < 10 ) return false;
				$rate = ( $s['retries_successful'] / $s['total_retries'] ) * 100;
				if ( $rate < 20 ) return array( 'setting' => 'max_display_ads', 'steps' => -1, 'detail' => 'Retry success ' . round( $rate ) . '%' );
				return false;
			},
		),
		array(
			'id' => 'R12', 'name' => 'Combined Fill Optimization',
			'trigger' => 'Effective fill > 80% + viewability > 60%', 'action' => 'Increase max_display_ads +1',
			'evaluate' => function ( $s, $current ) {
				$combined = 0;
				foreach ( $s['by_zone'] as $z ) { $combined += ( $z['filled'] ?? 0 ) + ( $z['passback_filled'] ?? 0 ); }
				$eff = $s['total_zones_activated'] > 0 ? ( $combined / $s['total_zones_activated'] ) * 100 : 0;
				$vw  = $s['total_zones_activated'] > 0 ? ( $s['total_viewable_ads'] / $s['total_zones_activated'] ) * 100 : 0;
				if ( $eff > 80 && $vw > 60 ) return array( 'setting' => 'max_display_ads', 'steps' => 1, 'detail' => 'Fill ' . round( $eff ) . '% + viewability ' . round( $vw ) . '%' );
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
		pl_optimizer_save_log( 'skip', 'Insufficient data: ' . $stats['total_sessions'] . ' sessions' );
		return;
	}

	$settings = get_option( 'pl_ad_settings', array() );
	$defaults = function_exists( 'pl_ad_defaults' ) ? pl_ad_defaults() : array();
	$current  = wp_parse_args( $settings, $defaults );

	if ( empty( $current['enabled'] ) ) {
		pl_optimizer_save_log( 'skip', 'Ads disabled' );
		return;
	}

	$rules    = pl_optimizer_get_rules();
	$changes  = array();
	$warnings = array();
	$info     = array();
	$cc       = 0;

	foreach ( $rules as $rule ) {
		if ( $cc >= PL_OPT_MAX_CHANGES ) break;
		$result = call_user_func( $rule['evaluate'], $stats, $current );
		if ( ! $result ) continue;
		if ( $result['setting'] === '_warning' ) {
			$warnings[] = array( 'rule' => $rule['id'], 'name' => $rule['name'], 'detail' => $result['detail'] );
			continue;
		}
		if ( $result['steps'] === 0 ) {
			$info[] = array( 'rule' => $rule['id'], 'name' => $rule['name'], 'detail' => $result['detail'] );
			continue;
		}
		$adj = pl_optimizer_adjust( $current, $result['setting'], $result['steps'] );
		if ( $adj ) {
			$changes[] = array( 'rule' => $rule['id'], 'name' => $rule['name'], 'setting' => $result['setting'], 'from' => $adj['from'], 'to' => $adj['to'], 'detail' => $result['detail'] );
			$cc++;
		}
	}

	if ( ! empty( $changes ) ) update_option( 'pl_ad_settings', $current );

	$summary = array(
		'sessions'    => $stats['total_sessions'],
		'fill_rate'   => $stats['total_ad_requests'] > 0 ? round( ( $stats['total_ad_fills'] / $stats['total_ad_requests'] ) * 100 ) : 0,
		'viewability' => $stats['total_zones_activated'] > 0 ? round( ( $stats['total_viewable_ads'] / $stats['total_zones_activated'] ) * 100 ) : 0,
	);
	pl_optimizer_save_log( ! empty( $changes ) ? 'optimized' : 'no-change', '', $summary, $changes, $warnings, $info );
}

/* ================================================================
 * 5. LOG / HISTORY MANAGEMENT
 * ================================================================ */

function pl_optimizer_save_log( $status, $reason = '', $summary = null, $changes = array(), $warnings = array(), $info = array() ) {
	$log   = get_option( 'pl_ad_optimizer_log', array() );
	$entry = array( 'time' => current_time( 'mysql' ), 'status' => $status );
	if ( $reason )            $entry['reason']   = $reason;
	if ( $summary )           $entry['summary']  = $summary;
	if ( ! empty( $changes ) )  $entry['changes']  = $changes;
	if ( ! empty( $warnings ) ) $entry['warnings'] = $warnings;
	if ( ! empty( $info ) )     $entry['info']     = $info;
	$log[] = $entry;
	if ( count( $log ) > 30 ) $log = array_slice( $log, -30 );
	update_option( 'pl_ad_optimizer_log', $log, false );
}

function pl_optimizer_get_history() {
	return get_option( 'pl_ad_optimizer_log', array() );
}

function pl_optimizer_clear_history() {
	delete_option( 'pl_ad_optimizer_log' );
}

/* ================================================================
 * 6. OPTION DEFAULTS
 * ================================================================ */

function pl_opt_format_defaults() {
	return array(
		'nav'          => false,
		'initial1'     => true,
		'initial2'     => true,
		'sidebar1'     => true,
		'sidebar2'     => true,
		'pause'        => false,
		'dynamic'      => true,
		'video'        => true,
		'topAnchor'    => false,
		'anchor'       => true,
		'sideRails'    => true,
		'interstitial' => true,
	);
}

function pl_opt_refresh_defaults() {
	return array(
		'nav'       => array( 'enabled' => true,  'delay' => 30, 'max' => -1 ),
		'initial'   => array( 'enabled' => true,  'delay' => 45, 'max' => 3 ),
		'sidebar'   => array( 'enabled' => true,  'delay' => 45, 'max' => 3 ),
		'pause'     => array( 'enabled' => true,  'delay' => 30, 'max' => 2 ),
		'dynamic'   => array( 'enabled' => true,  'delay' => 30, 'max' => 2 ),
		'anchor'    => array( 'enabled' => true,  'delay' => 30, 'max' => -1 ),
		'sideRails' => array( 'enabled' => true,  'delay' => 30, 'max' => -1 ),
	);
}

function pl_opt_layer2_defaults() {
	return array(
		'density'              => 'normal',
		'maxSlots'             => 20,
		'pauseThreshold'       => 120,
		'predictiveWindow'     => 1.0,
		'viewportRefreshDelay' => 3000,
		'minPixelSpacing'      => 400,
		'maxPixelSpacing'      => 1000,
		'desktopMaxInView'     => 2,
		'mobileMaxInView'      => 1,
		'maxAdDensityPercent'  => 30,
		'predictiveSpeedCap'   => 300,
		'readerSpeed'          => 100,
		'fastScannerSpeed'     => 400,
	);
}

function pl_opt_video_defaults() {
	return array(
		'enabled'        => false,
		'minScroll'      => 40,
		'minFilledAds'   => 2,
		'allowedVisitor' => array( 'reader', 'scanner' ),
	);
}

function pl_opt_viewability_defaults() {
	return array(
		'viewportCheck'   => true,
		'minVisibility'   => 10,
		'skipTabHidden'   => true,
		'lazyThreshold'   => 200,
	);
}

function pl_opt_ecpm_defaults() {
	return array(
		'interstitial' => 3.27,
		'anchor'       => 1.10,
		'sideRails'    => 0.65,
		'video'        => 2.50,
		'336x280'      => 0.74,
		'300x600'      => 0.65,
		'300x250'      => 0.63,
		'970x250'      => 0.67,
		'970x90'       => 0.10,
		'250x250'      => 0.42,
		'pause'        => 0.63,
		'320x100'      => 0.32,
		'other'        => 0.30,
	);
}

function pl_opt_auto_defaults() {
	return array(
		'enabled'             => false,
		'autoDisableLowFill'  => false,
		'autoIncreaseDelay'   => false,
		'autoDecreaseSpacing' => false,
		'autoLightDensity'    => false,
	);
}

/** Get merged option with defaults. */
function pl_opt_get( $key ) {
	$defaults_map = array(
		'pl_ad_format_settings'      => 'pl_opt_format_defaults',
		'pl_ad_refresh_settings'     => 'pl_opt_refresh_defaults',
		'pl_ad_layer2_settings'      => 'pl_opt_layer2_defaults',
		'pl_ad_video_settings'       => 'pl_opt_video_defaults',
		'pl_ad_viewability_settings' => 'pl_opt_viewability_defaults',
		'pl_ad_ecpm_settings'        => 'pl_opt_ecpm_defaults',
		'pl_ad_auto_optimize'        => 'pl_opt_auto_defaults',
	);
	if ( ! isset( $defaults_map[ $key ] ) ) return array();
	$saved = get_option( $key, array() );
	if ( ! is_array( $saved ) ) $saved = array();
	return wp_parse_args( $saved, call_user_func( $defaults_map[ $key ] ) );
}

/* ================================================================
 * 7. ADMIN MENU & AJAX HANDLERS
 * ================================================================ */

function pl_ad_optimizer_page() {
	add_submenu_page(
		'pl-ad-engine', 'Ad Optimizer', 'Optimizer',
		'manage_options', 'pl-ad-optimizer', 'pl_ad_render_optimizer'
	);
}
add_action( 'admin_menu', 'pl_ad_optimizer_page' );

/** Handle form POST actions. */
function pl_ad_optimizer_handle_actions() {
	if ( ! current_user_can( 'manage_options' ) ) return;

	if ( isset( $_POST['pl_optimizer_run_now'] ) && check_admin_referer( 'pl_optimizer_nonce' ) ) {
		pl_ad_run_optimizer();
		wp_safe_redirect( admin_url( 'admin.php?page=pl-ad-optimizer&ran=1' ) );
		exit;
	}
	if ( isset( $_POST['pl_optimizer_export'] ) && check_admin_referer( 'pl_optimizer_nonce' ) ) {
		header( 'Content-Type: application/json' );
		header( 'Content-Disposition: attachment; filename="pl-optimizer-history.json"' );
		echo wp_json_encode( pl_optimizer_get_history(), JSON_PRETTY_PRINT );
		exit;
	}
	if ( isset( $_POST['pl_optimizer_clear'] ) && check_admin_referer( 'pl_optimizer_nonce' ) ) {
		pl_optimizer_clear_history();
		wp_safe_redirect( admin_url( 'admin.php?page=pl-ad-optimizer&cleared=1' ) );
		exit;
	}
}
add_action( 'admin_init', 'pl_ad_optimizer_handle_actions' );

/** AJAX: Save all optimizer settings at once. */
function pl_opt_ajax_save() {
	check_ajax_referer( 'pl_optimizer_save_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

	$raw = json_decode( wp_unslash( $_POST['settings'] ?? '{}' ), true );
	if ( ! is_array( $raw ) ) wp_send_json_error( 'Invalid data' );

	// Global — update master toggle + density in pl_ad_settings.
	$ad_settings = get_option( 'pl_ad_settings', array() );
	$ad_settings['enabled'] = ! empty( $raw['globalEnabled'] );
	update_option( 'pl_ad_settings', $ad_settings );

	// Formats.
	if ( isset( $raw['formats'] ) && is_array( $raw['formats'] ) ) {
		$fmts = array();
		foreach ( pl_opt_format_defaults() as $k => $v ) {
			$fmts[ $k ] = ! empty( $raw['formats'][ $k ] );
		}
		update_option( 'pl_ad_format_settings', $fmts );
	}

	// Refresh.
	if ( isset( $raw['refresh'] ) && is_array( $raw['refresh'] ) ) {
		$ref = array();
		foreach ( pl_opt_refresh_defaults() as $k => $def ) {
			$r = $raw['refresh'][ $k ] ?? array();
			$ref[ $k ] = array(
				'enabled' => ! empty( $r['enabled'] ),
				'delay'   => max( 15, min( 120, (int) ( $r['delay'] ?? $def['delay'] ) ) ),
				'max'     => (int) ( $r['max'] ?? $def['max'] ),
			);
			if ( $ref[ $k ]['max'] < -1 ) $ref[ $k ]['max'] = -1;
			if ( $ref[ $k ]['max'] > 10 ) $ref[ $k ]['max'] = 10;
		}
		update_option( 'pl_ad_refresh_settings', $ref );
	}

	// Layer 2.
	if ( isset( $raw['layer2'] ) && is_array( $raw['layer2'] ) ) {
		$l = $raw['layer2'];
		$d = pl_opt_layer2_defaults();
		update_option( 'pl_ad_layer2_settings', array(
			'density'              => in_array( $l['density'] ?? '', array( 'light', 'normal', 'aggressive' ), true ) ? $l['density'] : 'normal',
			'maxSlots'             => max( 1, min( 30, (int) ( $l['maxSlots'] ?? $d['maxSlots'] ) ) ),
			'pauseThreshold'       => max( 100, min( 3000, (int) ( $l['pauseThreshold'] ?? $d['pauseThreshold'] ) ) ),
			'predictiveWindow'     => max( 0.1, min( 5.0, round( (float) ( $l['predictiveWindow'] ?? $d['predictiveWindow'] ), 1 ) ) ),
			'viewportRefreshDelay' => max( 1000, min( 30000, (int) ( $l['viewportRefreshDelay'] ?? $d['viewportRefreshDelay'] ) ) ),
			'minPixelSpacing'      => max( 400, min( 1000, (int) ( $l['minPixelSpacing'] ?? $d['minPixelSpacing'] ) ) ),
			'maxPixelSpacing'      => max( 200, min( 3000, (int) ( $l['maxPixelSpacing'] ?? $d['maxPixelSpacing'] ) ) ),
			'desktopMaxInView'     => max( 1, min( 5, (int) ( $l['desktopMaxInView'] ?? $d['desktopMaxInView'] ) ) ),
			'mobileMaxInView'      => max( 1, min( 3, (int) ( $l['mobileMaxInView'] ?? $d['mobileMaxInView'] ) ) ),
			'maxAdDensityPercent'  => max( 10, min( 60, (int) ( $l['maxAdDensityPercent'] ?? $d['maxAdDensityPercent'] ) ) ),
			'predictiveSpeedCap'   => max( 100, min( 1000, (int) ( $l['predictiveSpeedCap'] ?? $d['predictiveSpeedCap'] ) ) ),
			'readerSpeed'          => max( 10, min( 500, (int) ( $l['readerSpeed'] ?? $d['readerSpeed'] ) ) ),
			'fastScannerSpeed'     => max( 100, min( 2000, (int) ( $l['fastScannerSpeed'] ?? $d['fastScannerSpeed'] ) ) ),
		) );
	}

	// Video.
	if ( isset( $raw['video'] ) && is_array( $raw['video'] ) ) {
		$v = $raw['video'];
		$allowed = array();
		if ( ! empty( $v['allowReader'] ) )  $allowed[] = 'reader';
		if ( ! empty( $v['allowScanner'] ) ) $allowed[] = 'scanner';
		update_option( 'pl_ad_video_settings', array(
			'enabled'        => ! empty( $v['enabled'] ),
			'minScroll'      => max( 0, min( 100, (int) ( $v['minScroll'] ?? 40 ) ) ),
			'minFilledAds'   => max( 0, min( 10, (int) ( $v['minFilledAds'] ?? 2 ) ) ),
			'allowedVisitor' => $allowed,
		) );
	}

	// Viewability.
	if ( isset( $raw['viewability'] ) && is_array( $raw['viewability'] ) ) {
		$vw = $raw['viewability'];
		update_option( 'pl_ad_viewability_settings', array(
			'viewportCheck'  => ! empty( $vw['viewportCheck'] ),
			'minVisibility'  => max( 10, min( 100, (int) ( $vw['minVisibility'] ?? 50 ) ) ),
			'skipTabHidden'  => ! empty( $vw['skipTabHidden'] ),
			'lazyThreshold'  => max( 0, min( 1000, (int) ( $vw['lazyThreshold'] ?? 200 ) ) ),
		) );
	}

	// eCPM.
	if ( isset( $raw['ecpm'] ) && is_array( $raw['ecpm'] ) ) {
		$ecpm = array();
		foreach ( pl_opt_ecpm_defaults() as $k => $def ) {
			$ecpm[ $k ] = round( max( 0, min( 50, (float) ( $raw['ecpm'][ $k ] ?? $def ) ) ), 2 );
		}
		update_option( 'pl_ad_ecpm_settings', $ecpm );
	}

	// Auto-optimize.
	if ( isset( $raw['autoOptimize'] ) && is_array( $raw['autoOptimize'] ) ) {
		$ao = $raw['autoOptimize'];
		update_option( 'pl_ad_auto_optimize', array(
			'enabled'             => ! empty( $ao['enabled'] ),
			'autoDisableLowFill'  => ! empty( $ao['autoDisableLowFill'] ),
			'autoIncreaseDelay'   => ! empty( $ao['autoIncreaseDelay'] ),
			'autoDecreaseSpacing' => ! empty( $ao['autoDecreaseSpacing'] ),
			'autoLightDensity'    => ! empty( $ao['autoLightDensity'] ),
		) );
	}

	wp_send_json_success( 'All settings saved.' );
}
add_action( 'wp_ajax_pl_opt_save', 'pl_opt_ajax_save' );

/** AJAX: Reset all optimizer settings to defaults. */
function pl_opt_ajax_reset() {
	check_ajax_referer( 'pl_optimizer_save_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
	delete_option( 'pl_ad_format_settings' );
	delete_option( 'pl_ad_refresh_settings' );
	delete_option( 'pl_ad_layer2_settings' );
	delete_option( 'pl_ad_video_settings' );
	delete_option( 'pl_ad_viewability_settings' );
	delete_option( 'pl_ad_ecpm_settings' );
	delete_option( 'pl_ad_auto_optimize' );
	wp_send_json_success( 'All settings reset to defaults.' );
}
add_action( 'wp_ajax_pl_opt_reset', 'pl_opt_ajax_reset' );

/* ================================================================
 * 8. LIVE STATS QUERY (last 24h by slot_type)
 * ================================================================ */

function pl_opt_get_24h_stats() {
	global $wpdb;
	$te = $wpdb->prefix . 'pl_ad_events';
	$exists = $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
		DB_NAME, $te
	) );
	if ( ! $exists ) return array();
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - 86400 );
	return $wpdb->get_results( $wpdb->prepare(
		"SELECT slot_type,
			SUM(CASE WHEN event_type='impression' THEN 1 ELSE 0 END) AS impressions,
			SUM(CASE WHEN event_type='empty' THEN 1 ELSE 0 END) AS empties,
			SUM(CASE WHEN event_type='viewable' THEN 1 ELSE 0 END) AS viewables
		 FROM {$te} WHERE created_at >= %s GROUP BY slot_type",
		$cutoff
	), OBJECT_K );
}

/* ================================================================
 * 9. ADMIN PAGE RENDER
 * ================================================================ */

function pl_ad_render_optimizer() {
	if ( ! current_user_can( 'manage_options' ) ) return;

	$log      = pl_optimizer_get_history();
	$next_run = wp_next_scheduled( 'pl_ad_optimizer_daily' );
	$ad_s     = function_exists( 'pl_ad_settings' ) ? pl_ad_settings() : array( 'enabled' => false );
	$fmt      = pl_opt_get( 'pl_ad_format_settings' );
	$ref      = pl_opt_get( 'pl_ad_refresh_settings' );
	$l2       = pl_opt_get( 'pl_ad_layer2_settings' );
	$vid      = pl_opt_get( 'pl_ad_video_settings' );
	$vw       = pl_opt_get( 'pl_ad_viewability_settings' );
	$ecpm     = pl_opt_get( 'pl_ad_ecpm_settings' );
	$ao       = pl_opt_get( 'pl_ad_auto_optimize' );
	$stats24  = pl_opt_get_24h_stats();
	$nonce    = wp_create_nonce( 'pl_optimizer_save_nonce' );

	// Helper: get 24h stat for a slot type.
	$stat = function( $type, $field ) use ( $stats24 ) {
		return isset( $stats24[ $type ] ) ? (int) $stats24[ $type ]->$field : 0;
	};
	$fill_pct = function( $type ) use ( $stat ) {
		$i = $stat( $type, 'impressions' ); $e = $stat( $type, 'empties' );
		return ( $i + $e ) > 0 ? round( $i / ( $i + $e ) * 100, 1 ) : '-';
	};
	$vw_pct = function( $type ) use ( $stat ) {
		$i = $stat( $type, 'impressions' ); $v = $stat( $type, 'viewables' );
		return $i > 0 ? round( $v / $i * 100, 1 ) : '-';
	};

	?>
	<div class="wrap" id="pl-optimizer">
		<h1>Ad Optimizer &mdash; Control Center</h1>

		<?php if ( ! empty( $_GET['ran'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>Optimizer run completed.</p></div>
		<?php endif; ?>
		<?php if ( ! empty( $_GET['cleared'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>History cleared.</p></div>
		<?php endif; ?>
		<div id="plOptNotice" style="display:none"></div>

		<!-- Navigation -->
		<div style="margin-bottom:16px;display:flex;gap:8px;flex-wrap:wrap;">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=pl-ad-analytics-dashboard' ) ); ?>" class="button button-primary">Analytics Dashboard</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=pl-ad-live-sessions' ) ); ?>" class="button">Live Sessions</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=pl-ad-engine' ) ); ?>" class="button">Ad Engine Settings</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=pl-ad-recommendations' ) ); ?>" class="button">Recommendations</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=pl-injection-lab' ) ); ?>" class="button">Injection Lab</a>
		</div>

		<!-- Save / Reset bar -->
		<div style="position:sticky;top:32px;z-index:10;background:#f0f0f1;padding:10px 0;margin-bottom:16px;display:flex;gap:8px;align-items:center;">
			<button type="button" id="plOptSave" class="button button-primary button-hero" style="padding:6px 30px;font-size:14px;">Save All Settings</button>
			<button type="button" id="plOptReset" class="button" style="background:#d63638;border-color:#d63638;color:#fff;" onclick="if(!confirm('Reset ALL optimizer settings to factory defaults?'))return false;">Reset to Defaults</button>
			<span style="border-left:1px solid #c3c4c7;height:24px;margin:0 4px;"></span>
			<form method="post" style="display:inline;margin:0;">
				<?php wp_nonce_field( 'pl_optimizer_nonce' ); ?>
				<button type="submit" name="pl_optimizer_run_now" class="button">Run Cron Now</button>
			</form>
			<form method="post" style="display:inline;margin:0;">
				<?php wp_nonce_field( 'pl_optimizer_nonce' ); ?>
				<button type="submit" name="pl_optimizer_export" class="button">Export History</button>
			</form>
			<span style="font-size:12px;color:#888;margin-left:auto;">Next auto-run: <?php echo $next_run ? date_i18n( 'M j g:i A', $next_run ) : 'Not scheduled'; ?></span>
		</div>

		<!-- ============ SECTION 1: GLOBAL CONTROLS ============ -->
		<details class="pl-opt-card" open>
			<summary>1. Global Controls</summary>
			<div class="pl-opt-body">
				<table class="form-table">
					<tr>
						<th>Master Ad Enable</th>
						<td><label><input type="checkbox" id="opt_globalEnabled" <?php checked( $ad_s['enabled'] ); ?>> Enable all ad serving</label></td>
					</tr>
					<tr>
						<th>Ad Density Level</th>
						<td>
							<select id="opt_density">
								<option value="light" <?php selected( $l2['density'], 'light' ); ?>>Light (wider spacing, max 1 in view, max 10 slots)</option>
								<option value="normal" <?php selected( $l2['density'], 'normal' ); ?>>Normal (balanced spacing, max 2 desktop / 1 mobile, max 20)</option>
								<option value="aggressive" <?php selected( $l2['density'], 'aggressive' ); ?>>Aggressive (tighter spacing, max 3 desktop / 2 mobile, max 20)</option>
							</select>
							<p class="description">Presets for spacing bounds, density limits, and max dynamic slots.</p>
						</td>
					</tr>
				</table>
			</div>
		</details>

		<!-- ============ SECTION 2: FORMAT TOGGLES ============ -->
		<details class="pl-opt-card" open>
			<summary>2. Format Toggles</summary>
			<div class="pl-opt-body">
				<table class="widefat striped" style="font-size:13px;">
					<thead><tr><th>Format</th><th style="width:70px">Enabled</th><th class="num">24h Imps</th><th class="num">Fill Rate</th><th class="num">Viewability</th></tr></thead>
					<tbody>
					<?php
					$format_list = array(
						'nav'          => array( 'Nav Ad', 'nav' ),
						'initial1'     => array( 'Initial Ad 1', 'initial' ),
						'initial2'     => array( 'Initial Ad 2', 'initial' ),
						'sidebar1'     => array( 'Sidebar Ad 1', 'sidebar' ),
						'sidebar2'     => array( 'Sidebar Ad 2', 'sidebar' ),
						'pause'        => array( 'Pause Banner', 'pause' ),
						'dynamic'      => array( 'Dynamic Ads (Layer 2)', 'dynamic' ),
						'video'        => array( 'Video Outstream', 'video' ),
						'anchor'       => array( 'Bottom Anchor', 'anchor' ),
						'sideRails'    => array( 'Side Rails', 'sideRail' ),
						'interstitial' => array( 'Interstitial', 'interstitial' ),
					);
					foreach ( $format_list as $key => $meta ) :
						$label = $meta[0];
						$stype = $meta[1];
						$imps  = $stat( $stype, 'impressions' );
						$fp    = $fill_pct( $stype );
						$vp    = $vw_pct( $stype );
					?>
						<tr>
							<td><strong><?php echo esc_html( $label ); ?></strong></td>
							<td><input type="checkbox" class="opt-fmt" data-key="<?php echo esc_attr( $key ); ?>" <?php checked( $fmt[ $key ] ?? true ); ?>></td>
							<td class="num"><?php echo number_format( $imps ); ?></td>
							<td class="num"><?php echo $fp; ?><?php echo $fp !== '-' ? '%' : ''; ?></td>
							<td class="num"><?php echo $vp; ?><?php echo $vp !== '-' ? '%' : ''; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</details>

		<!-- ============ SECTION 3: REFRESH SETTINGS ============ -->
		<details class="pl-opt-card">
			<summary>3. Refresh Settings</summary>
			<div class="pl-opt-body">
				<table class="widefat striped" style="font-size:13px;">
					<thead><tr><th>Format</th><th style="width:70px">Enabled</th><th>Delay (s)</th><th>Max Refreshes</th></tr></thead>
					<tbody>
					<?php
					$ref_labels = array(
						'nav'       => 'Nav Ad',
						'initial'   => 'Initial Ads',
						'sidebar'   => 'Sidebar Ads',
						'pause'     => 'Pause Banner',
						'dynamic'   => 'Dynamic Ads',
						'anchor'    => 'Bottom Anchor',
						'sideRails' => 'Side Rails',
					);
					foreach ( $ref_labels as $rk => $rl ) :
						$rc = $ref[ $rk ] ?? pl_opt_refresh_defaults()[ $rk ];
					?>
						<tr>
							<td><strong><?php echo esc_html( $rl ); ?></strong></td>
							<td><input type="checkbox" class="opt-ref-en" data-key="<?php echo esc_attr( $rk ); ?>" <?php checked( $rc['enabled'] ); ?>></td>
							<td><input type="number" class="opt-ref-delay small-text" data-key="<?php echo esc_attr( $rk ); ?>" value="<?php echo (int) $rc['delay']; ?>" min="15" max="120" style="width:70px"></td>
							<td><input type="number" class="opt-ref-max small-text" data-key="<?php echo esc_attr( $rk ); ?>" value="<?php echo (int) $rc['max']; ?>" min="-1" max="10" style="width:70px"> <span class="description">(-1 = unlimited)</span></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</details>

		<!-- ============ SECTION 4: LAYER 2 TUNING (Predictive Engine) ============ -->
		<details class="pl-opt-card">
			<summary>4. Layer 2 Tuning (Predictive Engine)</summary>
			<div class="pl-opt-body">
				<p class="description" style="margin-bottom:12px;">Predictive injection uses real-time velocity tracking to inject ads at pause points and predicted scroll stops. Spacing is speed-based: <code>speed &times; timeBetween</code>, clamped between min/max.</p>
				<table class="form-table">
					<tr><th>Max active dynamic slots</th><td><input type="number" id="opt_l2_maxSlots" value="<?php echo (int) $l2['maxSlots']; ?>" min="1" max="30" class="small-text"> <p class="description">Slots are recycled when 1000px+ above viewport</p></td></tr>
					<tr><th>Pause detection threshold</th><td><input type="number" id="opt_l2_pauseThreshold" value="<?php echo (int) $l2['pauseThreshold']; ?>" min="100" max="3000" class="small-text"> ms <p class="description">Time scrolling must stop before injecting an ad</p></td></tr>
					<tr><th>Predictive window</th><td><input type="number" id="opt_l2_predictiveWindow" value="<?php echo esc_attr( $l2['predictiveWindow'] ); ?>" min="0.1" max="5.0" step="0.1" class="small-text"> s <p class="description">How far ahead to predict scroll stop position</p></td></tr>
					<tr><th>Viewport refresh delay</th><td><input type="number" id="opt_l2_viewportRefreshDelay" value="<?php echo (int) $l2['viewportRefreshDelay']; ?>" min="1000" max="30000" step="1000" class="small-text"> ms <p class="description">Pause duration before refreshing in-view ads</p></td></tr>
					<tr><th>Max speed for predictive injection</th><td><input type="number" id="opt_l2_predictiveSpeedCap" value="<?php echo (int) $l2['predictiveSpeedCap']; ?>" min="100" max="1000" class="small-text"> px/s <p class="description">Predictive injection only fires when speed is below this cap (prevents injecting at speeds too fast for viewability)</p></td></tr>
					<tr>
						<th>Spacing bounds (speed-based)</th>
						<td>
							<label>Min spacing: <input type="number" id="opt_l2_minPixelSpacing" value="<?php echo (int) $l2['minPixelSpacing']; ?>" min="400" max="1000" class="small-text"> px</label><br>
							<label>Max spacing: <input type="number" id="opt_l2_maxPixelSpacing" value="<?php echo (int) $l2['maxPixelSpacing']; ?>" min="200" max="3000" class="small-text"> px</label>
							<p class="description">Spacing = scroll speed &times; time-between (reader 2.5s, scanner 3.0s, fast 3.5s), clamped to these bounds</p>
						</td>
					</tr>
					<tr>
						<th>Viewport density limits</th>
						<td>
							<label>Desktop max ads in view: <input type="number" id="opt_l2_desktopMaxInView" value="<?php echo (int) $l2['desktopMaxInView']; ?>" min="1" max="5" class="small-text"></label><br>
							<label>Mobile max ads in view: <input type="number" id="opt_l2_mobileMaxInView" value="<?php echo (int) $l2['mobileMaxInView']; ?>" min="1" max="3" class="small-text"></label><br>
							<label>Max ad density: <input type="number" id="opt_l2_maxAdDensityPercent" value="<?php echo (int) $l2['maxAdDensityPercent']; ?>" min="10" max="60" class="small-text">% of viewport</label>
							<p class="description">Skip injection when viewport is already at capacity</p>
						</td>
					</tr>
					<tr>
						<th>Visitor classification</th>
						<td>
							<label>Reader &lt; <input type="number" id="opt_l2_readerSpeed" value="<?php echo (int) $l2['readerSpeed']; ?>" min="10" max="500" class="small-text"> px/s</label><br>
							<label>Fast-scanner &gt; <input type="number" id="opt_l2_fastScannerSpeed" value="<?php echo (int) $l2['fastScannerSpeed']; ?>" min="100" max="2000" class="small-text"> px/s</label>
							<p class="description">Scanner fills the gap between reader and fast-scanner thresholds</p>
						</td>
					</tr>
				</table>
			</div>
		</details>

		<!-- ============ SECTION 5: VIDEO SETTINGS ============ -->
		<details class="pl-opt-card">
			<summary>5. Video Settings</summary>
			<div class="pl-opt-body">
				<table class="form-table">
					<tr><th>Video enabled</th><td><label><input type="checkbox" id="opt_vid_enabled" <?php checked( $vid['enabled'] ); ?>> Enable video outstream ad</label></td></tr>
					<tr><th>Min scroll % before injection</th><td><input type="number" id="opt_vid_minScroll" value="<?php echo (int) $vid['minScroll']; ?>" min="0" max="100" class="small-text">%</td></tr>
					<tr><th>Min dynamic ads filled</th><td><input type="number" id="opt_vid_minFilled" value="<?php echo (int) $vid['minFilledAds']; ?>" min="0" max="10" class="small-text"></td></tr>
					<tr>
						<th>Visitor types allowed</th>
						<td>
							<label><input type="checkbox" id="opt_vid_reader" <?php checked( in_array( 'reader', $vid['allowedVisitor'] ?? array() ) ); ?>> Reader</label>
							<label style="margin-left:12px"><input type="checkbox" id="opt_vid_scanner" <?php checked( in_array( 'scanner', $vid['allowedVisitor'] ?? array() ) ); ?>> Scanner</label>
						</td>
					</tr>
				</table>
			</div>
		</details>

		<!-- ============ SECTION 6: VIEWABILITY OPTIMIZATION ============ -->
		<details class="pl-opt-card">
			<summary>6. Viewability Optimization</summary>
			<div class="pl-opt-body">
				<table class="form-table">
					<tr><th>Refresh only when in viewport</th><td><label><input type="checkbox" id="opt_vw_viewportCheck" <?php checked( $vw['viewportCheck'] ); ?>> Enabled</label></td></tr>
					<tr><th>Min viewport visibility for refresh</th><td><input type="number" id="opt_vw_minVis" value="<?php echo (int) $vw['minVisibility']; ?>" min="10" max="100" class="small-text">%</td></tr>
					<tr><th>Skip refresh when tab hidden</th><td><label><input type="checkbox" id="opt_vw_skipTab" <?php checked( $vw['skipTabHidden'] ); ?>> Enabled</label></td></tr>
					<tr><th>Lazy load threshold</th><td><input type="number" id="opt_vw_lazy" value="<?php echo (int) $vw['lazyThreshold']; ?>" min="0" max="1000" class="small-text"> px below viewport</td></tr>
				</table>
			</div>
		</details>

		<!-- ============ SECTION 7: eCPM CONFIGURATION ============ -->
		<details class="pl-opt-card">
			<summary>7. eCPM Configuration</summary>
			<div class="pl-opt-body">
				<p class="description">eCPM values (USD per 1000 impressions) used for revenue estimation in dashboards.</p>
				<table class="widefat striped" style="font-size:13px;max-width:500px;">
					<thead><tr><th>Format</th><th>eCPM ($)</th></tr></thead>
					<tbody>
					<?php
					$ecpm_labels = array(
						'interstitial' => 'Interstitial', 'anchor' => 'Bottom Anchor',
						'sideRails' => 'Side Rails', 'video' => 'Video Outstream',
						'336x280' => '336x280', '300x600' => '300x600', '300x250' => '300x250',
						'970x250' => '970x250', '970x90' => '970x90', '250x250' => '250x250',
						'pause' => 'Pause Banner', '320x100' => '320x100', 'other' => 'Other',
					);
					foreach ( $ecpm_labels as $ek => $el ) : ?>
						<tr>
							<td><?php echo esc_html( $el ); ?></td>
							<td><input type="number" class="opt-ecpm" data-key="<?php echo esc_attr( $ek ); ?>" value="<?php echo esc_attr( $ecpm[ $ek ] ?? 0 ); ?>" min="0" max="50" step="0.01" style="width:80px"></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</details>

		<!-- ============ SECTION 8: AUTO-OPTIMIZATION ============ -->
		<details class="pl-opt-card">
			<summary>8. Auto-Optimization (Smart Rules)</summary>
			<div class="pl-opt-body">
				<table class="form-table">
					<tr>
						<th>Master toggle</th>
						<td><label><input type="checkbox" id="opt_ao_enabled" <?php checked( $ao['enabled'] ); ?>> Enable auto-optimization</label>
						<p class="description">When enabled, the daily cron applies smart rules below in addition to the 12 base rules.</p></td>
					</tr>
					<tr><th>Auto-disable low fill formats</th><td><label><input type="checkbox" id="opt_ao_lowFill" <?php checked( $ao['autoDisableLowFill'] ); ?>> Disable formats with &lt;5% fill rate after 7 days of data</label></td></tr>
					<tr><th>Auto-increase refresh delay</th><td><label><input type="checkbox" id="opt_ao_delay" <?php checked( $ao['autoIncreaseDelay'] ); ?>> Increase delay if viewability drops below 30%</label></td></tr>
					<tr><th>Auto-decrease injection spacing</th><td><label><input type="checkbox" id="opt_ao_spacing" <?php checked( $ao['autoDecreaseSpacing'] ); ?>> Decrease spacing if viewability above 70%</label></td></tr>
					<tr><th>Auto light density</th><td><label><input type="checkbox" id="opt_ao_light" <?php checked( $ao['autoLightDensity'] ); ?>> Switch to Light density for fast-scanner heavy pages</label></td></tr>
				</table>
			</div>
		</details>

		<!-- ============ RUN HISTORY ============ -->
		<details class="pl-opt-card">
			<summary>Cron Run History (last 30)</summary>
			<div class="pl-opt-body">
				<div style="margin-bottom:12px;">
					<form method="post" style="display:inline;margin:0;" onsubmit="return confirm('Clear all optimizer run history?');">
						<?php wp_nonce_field( 'pl_optimizer_nonce' ); ?>
						<button type="submit" name="pl_optimizer_clear" class="button" style="background:#d63638;border-color:#d63638;color:#fff;">Clear History</button>
					</form>
				</div>
				<?php if ( empty( $log ) ) : ?>
					<p style="color:#646970;">No optimizer runs yet.</p>
				<?php else : ?>
					<?php foreach ( array_reverse( $log ) as $entry ) :
						$sc = array( 'optimized' => '#00a32a', 'no-change' => '#646970', 'skip' => '#dba617' );
						$color = $sc[ $entry['status'] ] ?? '#646970';
					?>
						<div style="border:1px solid #eee;border-radius:4px;padding:10px 14px;margin-bottom:8px;">
							<div style="display:flex;justify-content:space-between;align-items:center;">
								<div>
									<span style="color:<?php echo $color; ?>;font-weight:700;text-transform:uppercase;font-size:11px;"><?php echo esc_html( $entry['status'] ); ?></span>
									<span style="color:#888;font-size:11px;margin-left:6px;"><?php echo esc_html( $entry['time'] ); ?></span>
								</div>
								<?php if ( ! empty( $entry['summary'] ) ) : ?>
									<span style="font-size:11px;color:#666;">
										<?php echo $entry['summary']['sessions']; ?>s | F<?php echo $entry['summary']['fill_rate']; ?>% | V<?php echo $entry['summary']['viewability']; ?>%
									</span>
								<?php endif; ?>
							</div>
							<?php if ( ! empty( $entry['reason'] ) ) : ?>
								<div style="color:#888;font-size:12px;margin-top:4px;"><?php echo esc_html( $entry['reason'] ); ?></div>
							<?php endif; ?>
							<?php if ( ! empty( $entry['changes'] ) ) : foreach ( $entry['changes'] as $c ) : ?>
								<div style="background:#d4edda;border-radius:3px;padding:4px 8px;margin-top:4px;font-size:12px;">
									<strong><?php echo esc_html( $c['rule'] ); ?></strong>: <code><?php echo esc_html( $c['setting'] ); ?></code> <?php echo esc_html( $c['from'] ); ?> &rarr; <?php echo esc_html( $c['to'] ); ?>
								</div>
							<?php endforeach; endif; ?>
							<?php if ( ! empty( $entry['warnings'] ) ) : foreach ( $entry['warnings'] as $w ) : ?>
								<div style="background:#fff3cd;border-radius:3px;padding:4px 8px;margin-top:4px;font-size:12px;">
									<strong><?php echo esc_html( $w['rule'] ); ?></strong>: <?php echo esc_html( $w['detail'] ); ?>
								</div>
							<?php endforeach; endif; ?>
						</div>
					<?php endforeach; endif; ?>
			</div>
		</details>
	</div>

	<style>
	#pl-optimizer { max-width: 1200px; }
	.pl-opt-card { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; margin-bottom: 12px; }
	.pl-opt-card > summary {
		padding: 12px 16px; cursor: pointer; font-weight: 600; font-size: 14px;
		list-style: none; display: flex; align-items: center; gap: 8px;
	}
	.pl-opt-card > summary::-webkit-details-marker { display: none; }
	.pl-opt-card > summary::before { content: '\25B6'; font-size: 10px; transition: transform .2s; }
	.pl-opt-card[open] > summary::before { transform: rotate(90deg); }
	.pl-opt-card > summary:hover { background: #f6f7f7; }
	.pl-opt-body { padding: 0 16px 16px; }
	.num { text-align: right; }
	.form-table th { font-weight: 500; font-size: 13px; padding: 10px 12px 10px 0; width: 240px; }
	.form-table td { padding: 8px 0; }
	</style>

	<script>
	(function(){
		var AJAX_URL = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
		var NONCE    = <?php echo wp_json_encode( $nonce ); ?>;

		function notice(msg, type) {
			var el = document.getElementById('plOptNotice');
			el.className = 'notice notice-' + type + ' is-dismissible';
			el.innerHTML = '<p>' + msg + '</p>';
			el.style.display = '';
			setTimeout(function() { el.style.display = 'none'; }, 4000);
		}

		function collectAll() {
			// Formats
			var formats = {};
			document.querySelectorAll('.opt-fmt').forEach(function(cb) {
				formats[cb.dataset.key] = cb.checked;
			});

			// Refresh
			var refresh = {};
			document.querySelectorAll('.opt-ref-en').forEach(function(cb) {
				var k = cb.dataset.key;
				refresh[k] = refresh[k] || {};
				refresh[k].enabled = cb.checked;
			});
			document.querySelectorAll('.opt-ref-delay').forEach(function(inp) {
				var k = inp.dataset.key;
				refresh[k] = refresh[k] || {};
				refresh[k].delay = parseInt(inp.value, 10);
			});
			document.querySelectorAll('.opt-ref-max').forEach(function(inp) {
				var k = inp.dataset.key;
				refresh[k] = refresh[k] || {};
				refresh[k].max = parseInt(inp.value, 10);
			});

			// Layer 2
			var density = document.getElementById('opt_density').value;
			var layer2 = {
				density:              density,
				maxSlots:             parseInt(document.getElementById('opt_l2_maxSlots').value, 10),
				pauseThreshold:       parseInt(document.getElementById('opt_l2_pauseThreshold').value, 10),
				predictiveWindow:     parseFloat(document.getElementById('opt_l2_predictiveWindow').value),
				viewportRefreshDelay: parseInt(document.getElementById('opt_l2_viewportRefreshDelay').value, 10),
				predictiveSpeedCap:   parseInt(document.getElementById('opt_l2_predictiveSpeedCap').value, 10),
				minPixelSpacing:      parseInt(document.getElementById('opt_l2_minPixelSpacing').value, 10),
				maxPixelSpacing:      parseInt(document.getElementById('opt_l2_maxPixelSpacing').value, 10),
				desktopMaxInView:     parseInt(document.getElementById('opt_l2_desktopMaxInView').value, 10),
				mobileMaxInView:      parseInt(document.getElementById('opt_l2_mobileMaxInView').value, 10),
				maxAdDensityPercent:  parseInt(document.getElementById('opt_l2_maxAdDensityPercent').value, 10),
				readerSpeed:          parseInt(document.getElementById('opt_l2_readerSpeed').value, 10),
				fastScannerSpeed:     parseInt(document.getElementById('opt_l2_fastScannerSpeed').value, 10),
			};

			// Video
			var video = {
				enabled:      document.getElementById('opt_vid_enabled').checked,
				minScroll:    parseInt(document.getElementById('opt_vid_minScroll').value, 10),
				minFilledAds: parseInt(document.getElementById('opt_vid_minFilled').value, 10),
				allowReader:  document.getElementById('opt_vid_reader').checked,
				allowScanner: document.getElementById('opt_vid_scanner').checked,
			};

			// Viewability
			var viewability = {
				viewportCheck: document.getElementById('opt_vw_viewportCheck').checked,
				minVisibility: parseInt(document.getElementById('opt_vw_minVis').value, 10),
				skipTabHidden: document.getElementById('opt_vw_skipTab').checked,
				lazyThreshold: parseInt(document.getElementById('opt_vw_lazy').value, 10),
			};

			// eCPM
			var ecpm = {};
			document.querySelectorAll('.opt-ecpm').forEach(function(inp) {
				ecpm[inp.dataset.key] = parseFloat(inp.value) || 0;
			});

			// Auto-optimize
			var autoOptimize = {
				enabled:             document.getElementById('opt_ao_enabled').checked,
				autoDisableLowFill:  document.getElementById('opt_ao_lowFill').checked,
				autoIncreaseDelay:   document.getElementById('opt_ao_delay').checked,
				autoDecreaseSpacing: document.getElementById('opt_ao_spacing').checked,
				autoLightDensity:    document.getElementById('opt_ao_light').checked,
			};

			return {
				globalEnabled: document.getElementById('opt_globalEnabled').checked,
				formats: formats,
				refresh: refresh,
				layer2: layer2,
				video: video,
				viewability: viewability,
				ecpm: ecpm,
				autoOptimize: autoOptimize,
			};
		}

		// Density preset auto-fill
		document.getElementById('opt_density').addEventListener('change', function() {
			var presets = {
				light:      { minPixelSpacing: 600, maxPixelSpacing: 1200, desktopMaxInView: 1, mobileMaxInView: 1, maxAdDensityPercent: 20, maxSlots: 10, predictiveSpeedCap: 250 },
				normal:     { minPixelSpacing: 400, maxPixelSpacing: 1000, desktopMaxInView: 2, mobileMaxInView: 1, maxAdDensityPercent: 30, maxSlots: 20, predictiveSpeedCap: 300 },
				aggressive: { minPixelSpacing: 400, maxPixelSpacing: 800,  desktopMaxInView: 3, mobileMaxInView: 2, maxAdDensityPercent: 40, maxSlots: 20, predictiveSpeedCap: 400 },
			};
			var p = presets[this.value];
			if (p) {
				document.getElementById('opt_l2_minPixelSpacing').value      = p.minPixelSpacing;
				document.getElementById('opt_l2_maxPixelSpacing').value      = p.maxPixelSpacing;
				document.getElementById('opt_l2_desktopMaxInView').value     = p.desktopMaxInView;
				document.getElementById('opt_l2_mobileMaxInView').value      = p.mobileMaxInView;
				document.getElementById('opt_l2_maxAdDensityPercent').value  = p.maxAdDensityPercent;
				document.getElementById('opt_l2_maxSlots').value             = p.maxSlots;
				document.getElementById('opt_l2_predictiveSpeedCap').value   = p.predictiveSpeedCap;
			}
		});

		// Save
		document.getElementById('plOptSave').addEventListener('click', function() {
			var btn = this;
			btn.disabled = true;
			btn.textContent = 'Saving...';
			var fd = new FormData();
			fd.append('action', 'pl_opt_save');
			fd.append('nonce', NONCE);
			fd.append('settings', JSON.stringify(collectAll()));
			fetch(AJAX_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function(r) { return r.json(); })
				.then(function(res) {
					notice(res.success ? res.data : ('Error: ' + (res.data || 'Unknown')), res.success ? 'success' : 'error');
				})
				.catch(function() { notice('Network error', 'error'); })
				.finally(function() { btn.disabled = false; btn.textContent = 'Save All Settings'; });
		});

		// Reset
		document.getElementById('plOptReset').addEventListener('click', function() {
			var fd = new FormData();
			fd.append('action', 'pl_opt_reset');
			fd.append('nonce', NONCE);
			fetch(AJAX_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function(r) { return r.json(); })
				.then(function(res) {
					if (res.success) {
						notice('Settings reset. Reloading...', 'success');
						setTimeout(function() { location.reload(); }, 1000);
					} else {
						notice('Error: ' + (res.data || 'Unknown'), 'error');
					}
				})
				.catch(function() { notice('Network error', 'error'); });
		});
	})();
	</script>
	<?php
}
