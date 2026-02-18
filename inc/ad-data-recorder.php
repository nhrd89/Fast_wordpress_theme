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
 *   curl https://cheerfultalks.com/wp-json/pinlightning/v1/ad-data?days=7
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
        'total_ads_injected' => intval($body['totalAdsInjected'] ?? 0),
        'total_viewable' => intval($body['totalViewable'] ?? 0),
        'viewability_rate' => floatval($body['viewabilityRate'] ?? 0),
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
            );
        }
    }

    // Write file
    $filename = $data_dir . '/' . uniqid('s_') . '.json';
    file_put_contents($filename, json_encode($session, JSON_PRETTY_PRINT));

    return new WP_REST_Response(array('ok' => true), 200);
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

    foreach ($sessions as $s) {
        $total_time += $s['time_on_page_ms'] ?? 0;
        $total_depth += $s['max_scroll_depth_pct'] ?? 0;
        $total_ads += $s['total_ads_injected'] ?? 0;
        $total_viewable += $s['total_viewable'] ?? 0;

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
        'devices' => $devices,
        'scroll_patterns' => $patterns,
        'zone_performance' => $zone_stats,
    );
}
