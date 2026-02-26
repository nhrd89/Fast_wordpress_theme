<?php
/**
 * PinLightning Page Ad Recorder
 *
 * Separate REST endpoints for page ad analytics.
 * Completely isolated from post ad recorder (ad-data-recorder.php).
 *
 * Storage: wp_option 'pl_page_ad_stats' (JSON).
 * Structure: date > domain > page_type > format > counters.
 *
 * @package PinLightning
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', function() {
	// POST: receive page ad events from browser.
	register_rest_route( 'pl/v1', '/page-ad-event', array(
		'methods'             => 'POST',
		'callback'            => 'pl_page_ad_record_event',
		'permission_callback' => '__return_true',
	) );

	// GET: retrieve page ad stats for admin.
	register_rest_route( 'pl/v1', '/page-ad-stats', array(
		'methods'             => 'GET',
		'callback'            => 'pl_page_ad_get_stats',
		'permission_callback' => function() {
			return is_user_logged_in() && current_user_can( 'manage_options' );
		},
	) );
} );

/**
 * Record a page ad event (impression, viewable, click, empty, filled).
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response
 */
function pl_page_ad_record_event( $request ) {
	$body = $request->get_json_params();

	if ( empty( $body ) || empty( $body['event'] ) ) {
		return new WP_REST_Response( array( 'ok' => false ), 400 );
	}

	// Bot detection.
	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '';
	if ( preg_match( '/bot|crawl|spider|slurp|googlebot|bingbot|lighthouse|pagespeed|headlesschrome|phantomjs/i', $ua ) ) {
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	// Rate limit: 1 write per 2 seconds per IP.
	$ip_hash   = md5( ( isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '' ) . gmdate( 'YmdHi' ) );
	$transient = 'pl_pga_rate_' . substr( $ip_hash, 0, 12 );
	if ( get_transient( $transient ) ) {
		return new WP_REST_Response( array( 'ok' => true, 'msg' => 'rate-limited' ), 200 );
	}
	set_transient( $transient, 1, 2 );

	$event     = sanitize_text_field( $body['event'] );
	$page_type = sanitize_text_field( $body['pageType'] ?? 'unknown' );
	$slot_id   = sanitize_text_field( $body['slotId'] ?? '' );
	$domain    = sanitize_text_field( $body['domain'] ?? '' );
	$ad_format = sanitize_text_field( $body['adFormat'] ?? 'unknown' );
	$date_key  = gmdate( 'Y-m-d' );

	// Allowed events.
	$valid_events = array( 'impression', 'viewable', 'click', 'empty', 'filled' );
	if ( ! in_array( $event, $valid_events, true ) ) {
		return new WP_REST_Response( array( 'ok' => false, 'msg' => 'invalid event' ), 400 );
	}

	// Read current stats.
	$stats = get_option( 'pl_page_ad_stats', array() );

	// Initialize structure: date > domain > page_type > format > counters.
	if ( ! isset( $stats[ $date_key ] ) ) {
		$stats[ $date_key ] = array();
	}
	if ( ! isset( $stats[ $date_key ][ $domain ] ) ) {
		$stats[ $date_key ][ $domain ] = array();
	}
	if ( ! isset( $stats[ $date_key ][ $domain ][ $page_type ] ) ) {
		$stats[ $date_key ][ $domain ][ $page_type ] = array();
	}
	if ( ! isset( $stats[ $date_key ][ $domain ][ $page_type ][ $ad_format ] ) ) {
		$stats[ $date_key ][ $domain ][ $page_type ][ $ad_format ] = array(
			'impressions' => 0,
			'viewable'    => 0,
			'clicks'      => 0,
			'empty'       => 0,
			'filled'      => 0,
		);
	}

	// Increment counter.
	$counter_key = $event === 'impression' ? 'impressions' : $event;
	if ( isset( $stats[ $date_key ][ $domain ][ $page_type ][ $ad_format ][ $counter_key ] ) ) {
		$stats[ $date_key ][ $domain ][ $page_type ][ $ad_format ][ $counter_key ]++;
	}

	// Keep only last 30 days.
	$cutoff = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
	foreach ( array_keys( $stats ) as $k ) {
		if ( $k < $cutoff ) {
			unset( $stats[ $k ] );
		}
	}

	update_option( 'pl_page_ad_stats', $stats, false );

	return new WP_REST_Response( array( 'ok' => true ), 200 );
}

/**
 * AJAX handler: clear all page ad stats data.
 */
function pl_clear_page_ad_stats_handler() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
	}
	check_ajax_referer( 'pl_clear_page_ad_stats' );
	delete_option( 'pl_page_ad_stats' );
	wp_send_json_success( array( 'message' => 'All page ad data cleared' ) );
}
add_action( 'wp_ajax_pl_clear_page_ad_stats', 'pl_clear_page_ad_stats_handler' );

/**
 * Get page ad stats for admin dashboard.
 *
 * Returns structured breakdowns: today, last_7_days, last_30_days, by_domain, by_format.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response
 */
function pl_page_ad_get_stats( $request ) {
	$stats   = get_option( 'pl_page_ad_stats', array() );
	$today   = gmdate( 'Y-m-d' );
	$cutoff7 = gmdate( 'Y-m-d', strtotime( '-7 days' ) );
	$cutoff30 = gmdate( 'Y-m-d', strtotime( '-30 days' ) );

	$empty_counters = array(
		'impressions' => 0,
		'viewable'    => 0,
		'clicks'      => 0,
		'empty'       => 0,
		'filled'      => 0,
	);

	// Aggregate helpers.
	$by_period    = array( 'today' => array(), 'last_7_days' => array(), 'last_30_days' => array() );
	$by_domain    = array();
	$by_format    = array();

	foreach ( $stats as $date => $domains ) {
		if ( $date < $cutoff30 ) {
			continue;
		}

		// Determine which periods this date falls into.
		$periods = array();
		if ( $date === $today ) {
			$periods[] = 'today';
		}
		if ( $date >= $cutoff7 ) {
			$periods[] = 'last_7_days';
		}
		$periods[] = 'last_30_days';

		foreach ( $domains as $domain => $page_types ) {
			foreach ( $page_types as $page_type => $formats ) {
				// Handle legacy flat structure (format counters directly under page_type).
				if ( isset( $formats['impressions'] ) || isset( $formats['viewable'] ) ) {
					$formats = array( 'unknown' => $formats );
				}

				foreach ( $formats as $format => $counters ) {
					if ( ! is_array( $counters ) ) {
						continue;
					}

					// Aggregate by period + page_type.
					foreach ( $periods as $period ) {
						if ( ! isset( $by_period[ $period ][ $page_type ] ) ) {
							$by_period[ $period ][ $page_type ] = $empty_counters;
						}
						foreach ( $empty_counters as $key => $_ ) {
							$by_period[ $period ][ $page_type ][ $key ] += ( $counters[ $key ] ?? 0 );
						}
					}

					// Aggregate by domain.
					if ( ! isset( $by_domain[ $domain ] ) ) {
						$by_domain[ $domain ] = $empty_counters;
					}
					foreach ( $empty_counters as $key => $_ ) {
						$by_domain[ $domain ][ $key ] += ( $counters[ $key ] ?? 0 );
					}

					// Aggregate by format.
					if ( ! isset( $by_format[ $format ] ) ) {
						$by_format[ $format ] = $empty_counters;
					}
					foreach ( $empty_counters as $key => $_ ) {
						$by_format[ $format ][ $key ] += ( $counters[ $key ] ?? 0 );
					}
				}
			}
		}
	}

	// Calculate fill_rate for each aggregation.
	$add_fill_rate = function( &$data ) {
		foreach ( $data as &$counters ) {
			$total = ( $counters['filled'] ?? 0 ) + ( $counters['empty'] ?? 0 );
			$counters['fill_rate'] = $total > 0 ? round( ( $counters['filled'] / $total ) * 100, 1 ) : 0;
		}
	};

	foreach ( $by_period as &$period_data ) {
		$add_fill_rate( $period_data );
	}
	$add_fill_rate( $by_domain );
	$add_fill_rate( $by_format );

	return new WP_REST_Response( array(
		'today'        => $by_period['today'],
		'last_7_days'  => $by_period['last_7_days'],
		'last_30_days' => $by_period['last_30_days'],
		'by_domain'    => $by_domain,
		'by_format'    => $by_format,
	), 200 );
}
