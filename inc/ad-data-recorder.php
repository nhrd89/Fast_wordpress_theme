<?php
/**
 * PinLightning Ad Data Recorder
 *
 * REST endpoint that receives viewability data from the browser
 * and stores it as JSON files for analysis.
 *
 * Data is stored in: wp-content/uploads/pl-ad-data/YYYY-MM-DD/
 * Each session creates one JSON file.
 *
 * Claude Code can analyze this data via:
 *   curl https://cheerlives.com/wp-json/pinlightning/v1/ad-data?days=7
 */

add_action('rest_api_init', function() {
    // POST: receive data from browser
    register_rest_route('pinlightning/v1', '/ad-data', array(
        'methods' => 'POST',
        'callback' => 'pinlightning_record_ad_data',
        'permission_callback' => '__return_true', // Public — visitors send data
    ));

    // GET: retrieve data for analysis
    register_rest_route('pinlightning/v1', '/ad-data', array(
        'methods' => 'GET',
        'callback' => 'pinlightning_get_ad_data',
        'permission_callback' => function() {
            // Allow with secret key OR authenticated admin (app password)
            if (isset($_GET['key']) && $_GET['key'] === PL_ADS_DATA_KEY) return true;
            if (is_user_logged_in() && current_user_can('manage_options')) return true;
            return false;
        },
    ));

});

// Secret key for reading data — change this to something unique
if (!defined('PL_ADS_DATA_KEY')) {
    define('PL_ADS_DATA_KEY', 'pl_' . substr(md5(AUTH_KEY . 'ad-data'), 0, 16));
}

/**
 * Store viewability session data
 */
function pinlightning_record_ad_data($request) {
    $body = $request->get_json_params();

    if (empty($body) || !isset($body['session'])) {
        return new WP_REST_Response(array('ok' => false), 400);
    }

    // Server-side bot detection — reject bot user agents.
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if ( preg_match( '/bot|crawl|spider|slurp|googlebot|bingbot|baiduspider|yandexbot|duckduckbot|sogou|exabot|ia_archiver|facebot|facebookexternalhit|ahrefsbot|semrushbot|mj12bot|dotbot|petalbot|applebot|dataforseobot|bytespider|gptbot|claudebot|ccbot|amazonbot|anthropic|headlesschrome|phantomjs|slimerjs|lighthouse|pagespeed|pingdom|uptimerobot|wget|curl|python-requests|go-http-client|java\/|libwww/i', $ua ) ) {
        return new WP_REST_Response( array( 'ok' => true ), 200 );
    }

    // Rate limit: max 1 write per 5 seconds per IP
    $ip_hash = md5($_SERVER['REMOTE_ADDR'] . date('YmdHi'));
    $transient = 'pl_ad_rate_' . $ip_hash;
    if (get_transient($transient)) {
        return new WP_REST_Response(array('ok' => true, 'msg' => 'rate-limited'), 200);
    }
    set_transient($transient, 1, 5);

    // Build storage path
    $upload_dir = wp_upload_dir();
    $data_dir = $upload_dir['basedir'] . '/pl-ad-data/' . date('Y-m-d');

    if (!file_exists($data_dir)) {
        wp_mkdir_p($data_dir);
        // Protect directory
        file_put_contents(dirname($data_dir) . '/.htaccess', 'Deny from all');
        file_put_contents($data_dir . '/index.php', '<?php // Silence');
    }

    // Sanitize and structure the data
    $session = array(
        'timestamp' => current_time('mysql'),
        'unix' => time(),
        'session_id' => sanitize_text_field($body['sid'] ?? ''),
        'post_id' => intval($body['postId'] ?? 0),
        'post_slug' => sanitize_text_field($body['postSlug'] ?? ''),
        'device' => sanitize_text_field($body['device'] ?? 'unknown'),
        'viewport_w' => intval($body['viewportW'] ?? 0),
        'viewport_h' => intval($body['viewportH'] ?? 0),
        'time_on_page_ms' => intval($body['timeOnPage'] ?? 0),
        'max_scroll_depth_pct' => floatval($body['maxDepth'] ?? 0),
        'avg_scroll_speed' => floatval($body['avgScrollSpeed'] ?? 0),
        'scroll_pattern' => sanitize_text_field($body['scrollPattern'] ?? ''), // 'reader', 'scanner', 'bouncer'
        'items_seen' => intval($body['itemsSeen'] ?? 0),
        'total_items' => intval($body['totalItems'] ?? 0),
        'gate_open' => !empty($body['gateOpen']),
        'gate_scroll' => !empty($body['gateScroll']),
        'gate_time' => !empty($body['gateTime']),
        'gate_direction' => !empty($body['gateDirection']),
        'total_ads_injected' => intval($body['totalAdsInjected'] ?? 0),
        'total_viewable' => intval($body['totalViewable'] ?? 0),
        'viewability_rate' => floatval($body['viewabilityRate'] ?? 0),
        // Out-of-page format status.
        'anchor_status' => sanitize_text_field($body['anchorStatus'] ?? 'off'),
        'interstitial_status' => sanitize_text_field($body['interstitialStatus'] ?? 'off'),
        'pause_status' => sanitize_text_field($body['pauseStatus'] ?? 'off'),
        // Retry stats.
        'retries_used' => intval($body['retriesUsed'] ?? 0),
        'retries_successful' => intval($body['retriesSuccessful'] ?? 0),
        // Fill tracking.
        'total_requested' => intval($body['totalRequested'] ?? 0),
        'total_filled' => intval($body['totalFilled'] ?? 0),
        'total_empty' => intval($body['totalEmpty'] ?? 0),
        'fill_rate' => intval($body['fillRate'] ?? 0),
        'anchor_filled' => !empty($body['anchorFilled']),
        'interstitial_filled' => !empty($body['interstitialFilled']),
        'pause_filled' => !empty($body['pauseFilled']),
        'zones_activated' => intval($body['zonesActivated'] ?? 0),
        'referrer' => sanitize_text_field($body['referrer'] ?? ''),
        'language' => sanitize_text_field($body['language'] ?? ''),
        'zones' => array(),
    );

    // Per-zone data
    if (!empty($body['zones']) && is_array($body['zones'])) {
        foreach ($body['zones'] as $zone) {
            $session['zones'][] = array(
                'zone_id' => sanitize_text_field($zone['zoneId'] ?? ''),
                'ad_size' => sanitize_text_field($zone['adSize'] ?? ''),
                'total_visible_ms' => intval($zone['totalVisibleMs'] ?? 0),
                'viewable_impressions' => intval($zone['viewableImpressions'] ?? 0),
                'max_ratio' => floatval($zone['maxRatio'] ?? 0),
                'avg_ratio' => floatval($zone['avgRatio'] ?? 0),
                'time_to_first_view_ms' => intval($zone['timeToFirstView'] ?? 0),
                'injected_at_depth_pct' => floatval($zone['injectedAtDepth'] ?? 0),
                'scroll_speed_at_injection' => floatval($zone['scrollSpeedAtInjection'] ?? 0),
                'filled' => !empty($zone['filled']),
                'fill_size' => sanitize_text_field($zone['fillSize'] ?? ''),
                'advertiser_id' => intval($zone['advertiserId'] ?? 0),
            );
        }
    }

    // Write file
    $filename = $data_dir . '/' . uniqid('s_') . '.json';
    file_put_contents($filename, json_encode($session, JSON_PRETTY_PRINT));

    // Also archive to Live Sessions Recent store so every completed ad session
    // appears on the admin dashboard (not just heartbeat-tracked ones).
    pinlightning_archive_ad_session_to_live( $session );

    return new WP_REST_Response(array('ok' => true), 200);
}

/**
 * Archive a completed ad session to the Live Sessions Recent store.
 *
 * This ensures every ad session appears in Recent even if the heartbeat
 * module wasn't active (admin didn't have Live Sessions open).
 */
function pinlightning_archive_ad_session_to_live( $session ) {
    $recent = get_transient( 'pl_live_recent_sessions' );
    if ( ! is_array( $recent ) ) {
        $recent = array();
    }

    // Use the same session ID as the heartbeat module to avoid duplicates.
    // If the heartbeat already archived this session, we UPDATE it with
    // richer ad-track data instead of creating a second entry.
    $js_sid = ! empty( $session['session_id'] ) ? $session['session_id'] : '';

    if ( $js_sid && isset( $recent[ $js_sid ] ) ) {
        // Heartbeat entry exists — merge richer ad-track data into it.
        $existing = $recent[ $js_sid ];
        $existing['active_ads']     = $session['total_ads_injected'];
        $existing['viewable_ads']   = $session['total_viewable'];
        $existing['zones_active']   = implode( ',', array_column( $session['zones'], 'zone_id' ) );
        $existing['events']         = $session['zones'];
        $existing['scroll_pattern'] = $session['scroll_pattern'];
        $existing['scroll_pct']     = max( $existing['scroll_pct'] ?? 0, $session['max_scroll_depth_pct'] );
        $existing['time_on_page_s'] = max( $existing['time_on_page_s'] ?? 0, round( $session['time_on_page_ms'] / 1000, 1 ) );
        // Preserve out-of-page status from heartbeat if ad-track has it; prefer ad-track's final state.
        $existing['anchor_status']       = ! empty( $session['anchor_status'] ) && $session['anchor_status'] !== 'off' ? $session['anchor_status'] : ( $existing['anchor_status'] ?? 'off' );
        $existing['interstitial_status'] = ! empty( $session['interstitial_status'] ) && $session['interstitial_status'] !== 'off' ? $session['interstitial_status'] : ( $existing['interstitial_status'] ?? 'off' );
        $existing['pause_status']        = ! empty( $session['pause_status'] ) && $session['pause_status'] !== 'off' ? $session['pause_status'] : ( $existing['pause_status'] ?? 'off' );
        // Merge retry stats (ad-track has final values).
        $existing['total_retries']      = max( $existing['total_retries'] ?? 0, $session['retries_used'] ?? 0 );
        $existing['retries_successful'] = max( $existing['retries_successful'] ?? 0, $session['retries_successful'] ?? 0 );
        // Fill tracking (ad-track has final values).
        $existing['total_requested']     = max( $existing['total_requested'] ?? 0, $session['total_requested'] ?? 0 );
        $existing['total_filled']        = max( $existing['total_filled'] ?? 0, $session['total_filled'] ?? 0 );
        $existing['total_empty']         = max( $existing['total_empty'] ?? 0, $session['total_empty'] ?? 0 );
        $existing['fill_rate']           = $session['fill_rate'] ?? ( $existing['fill_rate'] ?? 0 );
        $existing['anchor_filled']       = ! empty( $session['anchor_filled'] ) || ! empty( $existing['anchor_filled'] );
        $existing['interstitial_filled'] = ! empty( $session['interstitial_filled'] ) || ! empty( $existing['interstitial_filled'] );
        $existing['pause_filled']        = ! empty( $session['pause_filled'] ) || ! empty( $existing['pause_filled'] );
        $existing['zones_activated']     = max( $existing['zones_activated'] ?? 0, $session['zones_activated'] ?? 0 );
        // Preserve identity fields — only overwrite if beacon has non-empty values.
        if ( ! empty( $session['referrer'] ) ) {
            $existing['referrer'] = $session['referrer'];
        }
        if ( ! empty( $session['language'] ) ) {
            $existing['language'] = $session['language'];
        }
        $existing['source']         = 'heartbeat+ad-track';
        $recent[ $js_sid ]          = $existing;
        goto prune_and_save;
    }

    // No heartbeat entry — create a new one using the JS session ID when available.
    $sid = $js_sid ?: 'ad_' . substr( md5( $session['unix'] . $session['post_slug'] . wp_rand() ), 0, 12 );

    $entry = array(
        'sid'            => $sid,
        'ts'             => $session['unix'],
        'post_id'        => $session['post_id'],
        'post_slug'      => $session['post_slug'],
        'post_title'     => $session['post_slug'], // ad recorder doesn't have title
        'device'         => $session['device'],
        'viewport_w'     => $session['viewport_w'],
        'viewport_h'     => $session['viewport_h'],
        'time_on_page_s' => round( $session['time_on_page_ms'] / 1000, 1 ),
        'scroll_pct'     => $session['max_scroll_depth_pct'],
        'scroll_speed'   => intval( $session['avg_scroll_speed'] ),
        'scroll_pattern' => $session['scroll_pattern'],
        'gate_scroll'    => $session['gate_scroll'],
        'gate_time'      => $session['gate_time'],
        'gate_direction' => $session['gate_direction'],
        'gate_open'      => $session['gate_open'],
        'active_ads'     => $session['total_ads_injected'],
        'viewable_ads'   => $session['total_viewable'],
        'zones_active'   => implode( ',', array_column( $session['zones'], 'zone_id' ) ),
        'referrer'       => $session['referrer'] ?? '',
        'language'       => $session['language'] ?? '',
        'events'         => $session['zones'],
        // Out-of-page format status.
        'anchor_status'       => $session['anchor_status'] ?? 'off',
        'interstitial_status' => $session['interstitial_status'] ?? 'off',
        'pause_status'        => $session['pause_status'] ?? 'off',
        // Retry stats.
        'total_retries'      => $session['retries_used'] ?? 0,
        'retries_successful' => $session['retries_successful'] ?? 0,
        // Fill tracking.
        'total_requested'      => $session['total_requested'] ?? 0,
        'total_filled'         => $session['total_filled'] ?? 0,
        'total_empty'          => $session['total_empty'] ?? 0,
        'fill_rate'            => $session['fill_rate'] ?? 0,
        'anchor_filled'        => $session['anchor_filled'] ?? false,
        'interstitial_filled'  => $session['interstitial_filled'] ?? false,
        'pause_filled'         => $session['pause_filled'] ?? false,
        'zones_activated'      => $session['zones_activated'] ?? 0,
        'status'         => 'ended',
        'ended_at'       => time(),
        'source'         => 'ad-track', // Distinguish from heartbeat-sourced entries.
    );

    $recent[ $sid ] = $entry;

    prune_and_save:
    // Prune older than 2 hours, cap at 300.
    $cutoff = time() - 7200;
    $recent = array_filter( $recent, function( $s ) use ( $cutoff ) {
        return ( $s['ended_at'] ?? $s['ts'] ?? 0 ) >= $cutoff;
    } );
    if ( count( $recent ) > 300 ) {
        uasort( $recent, function( $a, $b ) {
            return ( $b['ended_at'] ?? $b['ts'] ) - ( $a['ended_at'] ?? $a['ts'] );
        } );
        $recent = array_slice( $recent, 0, 300, true );
    }

    set_transient( 'pl_live_recent_sessions', $recent, 7200 );
}

/**
 * Retrieve aggregated data for analysis
 *
 * Usage:
 *   GET /wp-json/pinlightning/v1/ad-data?key=YOUR_KEY&days=7
 *   GET /wp-json/pinlightning/v1/ad-data?key=YOUR_KEY&date=2026-02-12
 *   GET /wp-json/pinlightning/v1/ad-data?key=YOUR_KEY&days=7&summary=true
 */
function pinlightning_get_ad_data($request) {
    $upload_dir = wp_upload_dir();
    $base_dir = $upload_dir['basedir'] . '/pl-ad-data';

    $days = intval($request->get_param('days') ?: 7);
    $specific_date = sanitize_text_field($request->get_param('date') ?: '');
    $summary = $request->get_param('summary') === 'true';

    $sessions = array();

    if ($specific_date) {
        $dates = array($specific_date);
    } else {
        $dates = array();
        for ($i = 0; $i < $days; $i++) {
            $dates[] = date('Y-m-d', strtotime("-{$i} days"));
        }
    }

    foreach ($dates as $date) {
        $dir = $base_dir . '/' . $date;
        if (!is_dir($dir)) continue;

        $files = glob($dir . '/s_*.json');
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data) $sessions[] = $data;
        }
    }

    if ($summary) {
        // Return aggregate stats instead of raw sessions
        return new WP_REST_Response(pinlightning_summarize_ad_data($sessions), 200);
    }

    return new WP_REST_Response(array(
        'total_sessions' => count($sessions),
        'date_range' => $dates,
        'sessions' => $sessions,
        'data_key_info' => 'This key is: ' . PL_ADS_DATA_KEY
    ), 200);
}

/**
 * Aggregate summary of ad data
 */
function pinlightning_summarize_ad_data($sessions) {
    if (empty($sessions)) return array('msg' => 'No data');

    $total = count($sessions);
    $total_time = 0;
    $total_depth = 0;
    $total_ads = 0;
    $total_viewable = 0;
    $devices = array('mobile' => 0, 'desktop' => 0, 'tablet' => 0);
    $patterns = array('reader' => 0, 'scanner' => 0, 'bouncer' => 0);
    $zone_stats = array();
    $gate_funnel = array('loaded' => $total, 'scroll' => 0, 'time' => 0, 'direction' => 0, 'open' => 0, 'ads_shown' => 0);

    foreach ($sessions as $s) {
        $total_time += $s['time_on_page_ms'] ?? 0;
        $total_depth += $s['max_scroll_depth_pct'] ?? 0;
        $total_ads += $s['total_ads_injected'] ?? 0;
        $total_viewable += $s['total_viewable'] ?? 0;

        // Gate funnel tracking.
        if (!empty($s['gate_scroll'])) $gate_funnel['scroll']++;
        if (!empty($s['gate_time'])) $gate_funnel['time']++;
        if (!empty($s['gate_direction'])) $gate_funnel['direction']++;
        if (!empty($s['gate_open'])) $gate_funnel['open']++;
        if (($s['total_ads_injected'] ?? 0) > 0) $gate_funnel['ads_shown']++;

        $dev = $s['device'] ?? 'unknown';
        if (isset($devices[$dev])) $devices[$dev]++;

        $pat = $s['scroll_pattern'] ?? '';
        if (isset($patterns[$pat])) $patterns[$pat]++;

        foreach ($s['zones'] ?? array() as $z) {
            $zid = $z['zone_id'];
            if (!isset($zone_stats[$zid])) {
                $zone_stats[$zid] = array(
                    'count' => 0, 'total_visible_ms' => 0,
                    'viewable_count' => 0, 'sizes' => array()
                );
            }
            $zone_stats[$zid]['count']++;
            $zone_stats[$zid]['total_visible_ms'] += $z['total_visible_ms'];
            $zone_stats[$zid]['viewable_count'] += $z['viewable_impressions'];
            $zone_stats[$zid]['sizes'][$z['ad_size']] = ($zone_stats[$zid]['sizes'][$z['ad_size']] ?? 0) + 1;
        }
    }

    return array(
        'sessions' => $total,
        'avg_time_on_page_s' => round($total_time / $total / 1000, 1),
        'avg_scroll_depth_pct' => round($total_depth / $total, 1),
        'avg_ads_per_session' => round($total_ads / $total, 1),
        'overall_viewability_pct' => $total_ads > 0 ? round(($total_viewable / $total_ads) * 100, 1) : 0,
        'gate_funnel' => $gate_funnel,
        'devices' => $devices,
        'scroll_patterns' => $patterns,
        'zone_performance' => $zone_stats,
    );
}
