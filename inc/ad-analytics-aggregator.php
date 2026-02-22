<?php
/**
 * PinLightning Ad Analytics — Data Aggregator
 *
 * Aggregates individual ad session data into daily summaries stored
 * in wp_options. Called whenever a session ends (from ad-data-recorder.php)
 * and via a daily cron fallback for cleanup.
 *
 * Data stored as: pl_ad_stats_YYYY-MM-DD (one option per day).
 *
 * @package PinLightning
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ================================================================
 * 1. EMPTY DAY STATS TEMPLATE
 * ================================================================ */

/**
 * Return the zero-initialized template for a day's aggregate stats.
 *
 * @return array
 */
function pl_ad_empty_day_stats() {
	return array(
		'total_sessions' => 0,

		// Breakdowns.
		'by_device'   => array(),
		'by_referrer' => array(),
		'by_language' => array(),
		'by_pattern'  => array(),
		'by_hour'     => array(),
		'by_zone'     => array(),
		'by_post'     => array(),

		// Engagement.
		'total_time_s'     => 0,
		'total_scroll_pct' => 0,
		'gate_opens'       => 0,
		'gate_checks'      => 0,

		// Ad performance.
		'total_zones_activated' => 0,
		'total_viewable_ads'    => 0,
		'total_ad_requests'     => 0,
		'total_ad_fills'        => 0,
		'total_ad_empty'        => 0,

		// Clicks.
		'total_display_clicks'      => 0,
		'total_anchor_clicks'       => 0,
		'total_interstitial_clicks' => 0,
		'total_pause_clicks'        => 0,

		// Overlays.
		'anchor_fired'               => 0,
		'anchor_filled'              => 0,
		'anchor_total_impressions'   => 0,
		'anchor_total_viewable'      => 0,
		'anchor_total_visible_ms'    => 0,
		'top_anchor_fired'           => 0,
		'top_anchor_filled'          => 0,
		'interstitial_fired'         => 0,
		'interstitial_viewable'      => 0,
		'interstitial_filled'        => 0,
		'interstitial_total_duration_ms' => 0,
		'pause_fired'                => 0,
		'pause_viewable'             => 0,
		'pause_filled'               => 0,
		'pause_total_visible_ms'     => 0,

		// V4: pause banners (contentPause) + refresh.
		'total_pause_banners_shown'     => 0,
		'total_pause_banners_continued' => 0,
		'total_refresh_count'           => 0,
		'total_refresh_impressions'     => 0,

		// Passback.
		'waldo_total_requested' => 0,
		'waldo_total_filled'    => 0,

		// Retries.
		'total_retries'      => 0,
		'retries_successful' => 0,
	);
}

/* ================================================================
 * 2. AGGREGATE A SINGLE SESSION
 * ================================================================ */

/**
 * Aggregate one session's data into the daily stats option.
 *
 * Called from ad-data-recorder.php whenever a session ends (both beacon
 * and heartbeat archive paths).
 *
 * @param array $session Session data (same shape as live sessions entries).
 */
function pl_ad_aggregate_session( $session ) {
	$today = current_time( 'Y-m-d' );
	$hour  = (int) current_time( 'G' );
	$key   = 'pl_ad_stats_' . $today;

	$stats = get_option( $key, pl_ad_empty_day_stats() );

	// --- Session counts ---
	$stats['total_sessions']++;

	// Device.
	$device = $session['device'] ?? 'unknown';
	$stats['by_device'][ $device ] = ( $stats['by_device'][ $device ] ?? 0 ) + 1;

	// Referrer (normalize).
	$ref           = $session['referrer'] ?? '';
	$referrer_type = 'direct';
	if ( strpos( $ref, 'pinterest' ) !== false ) {
		$referrer_type = 'pinterest';
	} elseif ( strpos( $ref, 'google' ) !== false ) {
		$referrer_type = 'google';
	} elseif ( strpos( $ref, 'facebook' ) !== false || strpos( $ref, 'fb.com' ) !== false ) {
		$referrer_type = 'facebook';
	} elseif ( strpos( $ref, 'bing' ) !== false ) {
		$referrer_type = 'bing';
	} elseif ( ! empty( $ref ) ) {
		$referrer_type = 'other';
	}
	$stats['by_referrer'][ $referrer_type ] = ( $stats['by_referrer'][ $referrer_type ] ?? 0 ) + 1;

	// Language (first 2 chars).
	$lang = strtolower( substr( $session['language'] ?? 'unknown', 0, 2 ) );
	if ( empty( $lang ) ) {
		$lang = 'unknown';
	}
	$stats['by_language'][ $lang ] = ( $stats['by_language'][ $lang ] ?? 0 ) + 1;

	// Scroll pattern.
	$pattern = $session['scroll_pattern'] ?? 'unknown';
	$stats['by_pattern'][ $pattern ] = ( $stats['by_pattern'][ $pattern ] ?? 0 ) + 1;

	// Hourly distribution.
	$stats['by_hour'][ $hour ] = ( $stats['by_hour'][ $hour ] ?? 0 ) + 1;

	// --- Engagement ---
	$stats['total_time_s']     += floatval( $session['time_on_page_s'] ?? 0 );
	$stats['total_scroll_pct'] += floatval( $session['scroll_pct'] ?? 0 );

	// Gate.
	if ( ! empty( $session['gate_open'] ) ) {
		$stats['gate_opens']++;
	}
	$stats['gate_checks']++;

	// --- Ad Performance ---
	$zones_activated  = intval( $session['zones_activated'] ?? $session['active_ads'] ?? 0 );
	$viewable         = intval( $session['viewable_ads'] ?? 0 );
	$total_requested  = intval( $session['total_requested'] ?? 0 );
	$total_filled     = intval( $session['total_filled'] ?? 0 );
	$total_empty      = intval( $session['total_empty'] ?? 0 );

	$stats['total_zones_activated'] += $zones_activated;
	$stats['total_viewable_ads']    += $viewable;
	$stats['total_ad_requests']     += $total_requested;
	$stats['total_ad_fills']        += $total_filled;
	$stats['total_ad_empty']        += $total_empty;

	// Clicks.
	$stats['total_display_clicks']      += intval( $session['total_display_clicks'] ?? 0 );
	$stats['total_anchor_clicks']       += intval( $session['anchor_clicks'] ?? 0 );
	$stats['total_interstitial_clicks'] += intval( $session['interstitial_clicks'] ?? 0 );
	$stats['total_pause_clicks']        += intval( $session['pause_clicks'] ?? 0 );

	// --- Overlay Performance ---
	if ( ( $session['anchor_status'] ?? '' ) !== 'off' ) {
		$stats['anchor_fired']++;
		if ( ! empty( $session['anchor_filled'] ) ) {
			$stats['anchor_filled']++;
		}
		$stats['anchor_total_impressions'] += intval( $session['anchor_impressions'] ?? 0 );
		$stats['anchor_total_viewable']    += intval( $session['anchor_viewable'] ?? 0 );
		$stats['anchor_total_visible_ms']  += intval( $session['anchor_visible_ms'] ?? 0 );
	}

	if ( ( $session['interstitial_status'] ?? '' ) === 'fired' ) {
		$stats['interstitial_fired']++;
		if ( ! empty( $session['interstitial_viewable'] ) ) {
			$stats['interstitial_viewable']++;
		}
		$stats['interstitial_total_duration_ms'] += intval( $session['interstitial_duration_ms'] ?? 0 );
		if ( ! empty( $session['interstitial_filled'] ) ) {
			$stats['interstitial_filled']++;
		}
	}

	if ( ( $session['pause_status'] ?? '' ) === 'fired' ) {
		$stats['pause_fired']++;
		if ( ! empty( $session['pause_viewable'] ) ) {
			$stats['pause_viewable']++;
		}
		$stats['pause_total_visible_ms'] += intval( $session['pause_visible_ms'] ?? 0 );
		if ( ! empty( $session['pause_filled'] ) ) {
			$stats['pause_filled']++;
		}
	}

	// V4: Top Anchor.
	if ( ( $session['top_anchor_status'] ?? '' ) !== 'off' ) {
		$stats['top_anchor_fired']++;
		if ( ! empty( $session['top_anchor_filled'] ) ) {
			$stats['top_anchor_filled']++;
		}
	}

	// V4: Pause banners (contentPause) + refresh.
	$stats['total_pause_banners_shown']     += intval( $session['pause_banners_shown'] ?? 0 );
	$stats['total_pause_banners_continued'] += intval( $session['pause_banners_continued'] ?? 0 );
	$stats['total_refresh_count']           += intval( $session['refresh_count'] ?? 0 );
	$stats['total_refresh_impressions']     += intval( $session['refresh_impressions'] ?? 0 );

	// --- Passback (Newor Media) ---
	$stats['waldo_total_requested'] += intval( $session['waldo_requested'] ?? 0 );
	$stats['waldo_total_filled']    += intval( $session['waldo_filled'] ?? 0 );

	// --- Per-Zone Stats ---
	// Beacon path stores zone data as 'zones'; heartbeat archive path stores as 'events'.
	$events = $session['events'] ?? $session['zones'] ?? array();
	if ( is_string( $events ) ) {
		$events = json_decode( $events, true ) ?: array();
	}

	foreach ( $events as $ev ) {
		$zid = $ev['zone_id'] ?? 'unknown';
		if ( ! isset( $stats['by_zone'][ $zid ] ) ) {
			$stats['by_zone'][ $zid ] = array(
				'activations'     => 0,
				'filled'          => 0,
				'empty'           => 0,
				'viewable'        => 0,
				'total_visible_ms' => 0,
				'clicks'          => 0,
				'passback_filled' => 0,
				'passback_tried'  => 0,
			);
		}
		$z = &$stats['by_zone'][ $zid ];
		$z['activations']++;
		if ( ! empty( $ev['filled'] ) ) {
			$z['filled']++;
		} elseif ( isset( $ev['filled'] ) && ! $ev['filled'] ) {
			$z['empty']++;
		}
		if ( intval( $ev['viewable_impressions'] ?? $ev['viewable'] ?? 0 ) > 0 ) {
			$z['viewable']++;
		}
		$z['total_visible_ms'] += intval( $ev['total_visible_ms'] ?? $ev['visible_ms'] ?? 0 );
		$z['clicks']           += intval( $ev['clicks'] ?? 0 );
		if ( ! empty( $ev['passback'] ) ) {
			$z['passback_tried']++;
			if ( ! empty( $ev['passback']['filled'] ) ) {
				$z['passback_filled']++;
			}
		}
	}

	// --- Per-Post Stats ---
	$post_slug = $session['post_slug'] ?? 'unknown';
	if ( ! isset( $stats['by_post'][ $post_slug ] ) ) {
		$stats['by_post'][ $post_slug ] = array(
			'sessions'     => 0,
			'gate_opens'   => 0,
			'ads_filled'   => 0,
			'ads_viewable' => 0,
			'clicks'       => 0,
			'total_time_s' => 0,
		);
	}
	$p = &$stats['by_post'][ $post_slug ];
	$p['sessions']++;
	if ( ! empty( $session['gate_open'] ) ) {
		$p['gate_opens']++;
	}
	$p['ads_filled']   += $total_filled;
	$p['ads_viewable'] += $viewable;
	$p['clicks']       += intval( $session['total_display_clicks'] ?? 0 );
	$p['total_time_s'] += floatval( $session['time_on_page_s'] ?? 0 );

	// --- Retries ---
	$stats['total_retries']      += intval( $session['total_retries'] ?? 0 );
	$stats['retries_successful'] += intval( $session['retries_successful'] ?? 0 );

	update_option( $key, $stats, false ); // autoload = false
}

/* ================================================================
 * 3. LOAD MULTI-DAY STATS
 * ================================================================ */

/**
 * Load and merge stats for a date range.
 *
 * @param  string $start_date Y-m-d start date.
 * @param  string $end_date   Y-m-d end date.
 * @return array  ['combined' => merged stats, 'daily' => date => stats]
 */
function pl_ad_get_stats_range( $start_date, $end_date ) {
	$combined = pl_ad_empty_day_stats();
	$daily    = array();

	$current = new DateTime( $start_date );
	$end     = new DateTime( $end_date );
	$end->modify( '+1 day' );

	while ( $current < $end ) {
		$date_str = $current->format( 'Y-m-d' );
		$key      = 'pl_ad_stats_' . $date_str;
		$day      = get_option( $key, null );

		if ( $day ) {
			$daily[ $date_str ] = $day;

			// Merge into combined.
			foreach ( $day as $k => $v ) {
				if ( is_int( $v ) || is_float( $v ) ) {
					$combined[ $k ] = ( $combined[ $k ] ?? 0 ) + $v;
				} elseif ( is_array( $v ) && ! empty( $v ) ) {
					if ( ! isset( $combined[ $k ] ) ) {
						$combined[ $k ] = array();
					}
					foreach ( $v as $sub_k => $sub_v ) {
						if ( is_int( $sub_v ) || is_float( $sub_v ) ) {
							$combined[ $k ][ $sub_k ] = ( $combined[ $k ][ $sub_k ] ?? 0 ) + $sub_v;
						} elseif ( is_array( $sub_v ) ) {
							// Zone/post stats — merge sub-arrays.
							if ( ! isset( $combined[ $k ][ $sub_k ] ) ) {
								$combined[ $k ][ $sub_k ] = array();
							}
							foreach ( $sub_v as $field => $val ) {
								$combined[ $k ][ $sub_k ][ $field ] = ( $combined[ $k ][ $sub_k ][ $field ] ?? 0 ) + $val;
							}
						}
					}
				}
			}
		}

		$current->modify( '+1 day' );
	}

	return array( 'combined' => $combined, 'daily' => $daily );
}

/* ================================================================
 * 4. CLEANUP OLD STATS (90 DAYS)
 * ================================================================ */

/**
 * Delete aggregated stats older than 90 days.
 */
function pl_ad_cleanup_old_stats() {
	global $wpdb;
	$cutoff = gmdate( 'Y-m-d', strtotime( '-90 days' ) );
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name < %s",
		'pl\_ad\_stats\_%',
		'pl_ad_stats_' . $cutoff
	) );
}
add_action( 'pl_ad_daily_cleanup', 'pl_ad_cleanup_old_stats' );

// Schedule cleanup cron on theme load.
if ( ! wp_next_scheduled( 'pl_ad_daily_cleanup' ) ) {
	wp_schedule_event( time(), 'daily', 'pl_ad_daily_cleanup' );
}
