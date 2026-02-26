<?php
/**
 * PinLightning Page Ad Recorder
 *
 * Separate REST endpoints for page ad analytics.
 * Completely isolated from post ad recorder (ad-data-recorder.php).
 *
 * Storage: wp_option 'pl_page_ad_stats' (JSON, keyed by date/domain/page-type).
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
 * Record a page ad event (impression, viewable, click).
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
	$date_key  = gmdate( 'Y-m-d' );

	// Allowed events.
	$valid_events = array( 'impression', 'viewable', 'click', 'empty', 'filled' );
	if ( ! in_array( $event, $valid_events, true ) ) {
		return new WP_REST_Response( array( 'ok' => false, 'msg' => 'invalid event' ), 400 );
	}

	// Read current stats.
	$stats = get_option( 'pl_page_ad_stats', array() );

	// Initialize structure: date > domain > page_type > counters.
	if ( ! isset( $stats[ $date_key ] ) ) {
		$stats[ $date_key ] = array();
	}
	if ( ! isset( $stats[ $date_key ][ $domain ] ) ) {
		$stats[ $date_key ][ $domain ] = array();
	}
	if ( ! isset( $stats[ $date_key ][ $domain ][ $page_type ] ) ) {
		$stats[ $date_key ][ $domain ][ $page_type ] = array(
			'impressions' => 0,
			'viewable'    => 0,
			'clicks'      => 0,
			'empty'       => 0,
			'filled'      => 0,
		);
	}

	// Increment counter.
	$counter_key = $event === 'impression' ? 'impressions' : $event;
	if ( isset( $stats[ $date_key ][ $domain ][ $page_type ][ $counter_key ] ) ) {
		$stats[ $date_key ][ $domain ][ $page_type ][ $counter_key ]++;
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
 * Get page ad stats for admin dashboard.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response
 */
function pl_page_ad_get_stats( $request ) {
	$stats = get_option( 'pl_page_ad_stats', array() );
	$days  = absint( $request->get_param( 'days' ) ) ?: 7;

	$cutoff  = gmdate( 'Y-m-d', strtotime( '-' . $days . ' days' ) );
	$filtered = array();
	foreach ( $stats as $date => $data ) {
		if ( $date >= $cutoff ) {
			$filtered[ $date ] = $data;
		}
	}

	return new WP_REST_Response( $filtered, 200 );
}
