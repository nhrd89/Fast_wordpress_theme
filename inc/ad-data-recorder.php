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

    // v5: timeOnPage is in seconds; convert to ms for storage compatibility.
    $time_val = floatval( $body['timeOnPage'] ?? 0 );
    $time_ms  = $time_val < 500 ? intval( $time_val * 1000 ) : intval( $time_val ); // auto-detect s vs ms

    // Sanitize and structure the data (v5 dynamic injection fields).
    $session = array(
        'timestamp' => current_time('mysql'),
        'unix' => time(),
        'session_id' => sanitize_text_field($body['sid'] ?? ''),
        'post_id' => intval($body['postId'] ?? 0),
        'post_slug' => sanitize_text_field($body['postSlug'] ?? ''),
        'device' => sanitize_text_field($body['device'] ?? 'unknown'),
        'viewport_w' => intval($body['viewportW'] ?? 0),
        'viewport_h' => intval($body['viewportH'] ?? 0),
        'time_on_page_ms' => $time_ms,
        'max_scroll_depth_pct' => floatval($body['maxDepth'] ?? 0),
        'avg_scroll_speed' => floatval($body['scrollSpeed'] ?? $body['avgScrollSpeed'] ?? 0),
        'scroll_pattern' => sanitize_text_field($body['scrollPattern'] ?? ''),
        'items_seen' => intval($body['itemsSeen'] ?? 0),
        'total_items' => intval($body['totalItems'] ?? 0),
        'dir_changes' => intval($body['dirChanges'] ?? 0),
        // v5: dynamic injection stats.
        'viewport_ads_injected' => intval($body['viewportAdsInjected'] ?? 0),
        'total_ads_injected' => intval($body['totalInjected'] ?? $body['totalAdsInjected'] ?? 0),
        'total_viewable' => intval($body['totalViewable'] ?? 0),
        'viewability_rate' => floatval($body['viewabilityRate'] ?? 0),
        // Out-of-page format status.
        'anchor_status' => sanitize_text_field($body['anchorStatus'] ?? 'off'),
        'interstitial_status' => sanitize_text_field($body['interstitialStatus'] ?? 'off'),
        'pause_status' => sanitize_text_field($body['pauseStatus'] ?? 'off'),
        'top_anchor_status' => sanitize_text_field($body['topAnchorStatus'] ?? 'off'),
        // Fill tracking.
        'total_requested' => intval($body['totalRequested'] ?? 0),
        'total_filled' => intval($body['totalFilled'] ?? 0),
        'total_empty' => intval($body['totalEmpty'] ?? 0),
        'fill_rate' => intval($body['fillRate'] ?? 0),
        'anchor_filled' => !empty($body['anchorFilled']),
        'interstitial_filled' => !empty($body['interstitialFilled']),
        'pause_filled' => !empty($body['pauseFilled']),
        'top_anchor_filled' => !empty($body['topAnchorFilled']),
        'zones_activated' => intval($body['totalInjected'] ?? $body['zonesActivated'] ?? 0),
        // v5: pause banners + refresh + video.
        'pause_banners_shown' => intval($body['pauseBannersInjected'] ?? $body['pauseBannersShown'] ?? 0),
        'pause_banners_continued' => intval($body['pauseBannersContinued'] ?? 0),
        'refresh_count' => intval($body['totalRefreshes'] ?? $body['refreshCount'] ?? 0),
        'refresh_impressions' => intval($body['refreshImpressions'] ?? 0),
        'video_injected' => !empty($body['videoInjected']),
        // Waldo passback tracking.
        'waldo_passbacks' => intval($body['waldoPassbacks'] ?? 0),
        'waldo_tags_used' => intval($body['waldoTagsUsed'] ?? 0),
        // Side rail fill tracking.
        'left_side_rail_filled' => !empty($body['leftSideRailFilled']),
        'right_side_rail_filled' => !empty($body['rightSideRailFilled']),
        // Overlay detail.
        'anchor_impressions' => intval($body['anchorImpressions'] ?? 0),
        'anchor_viewable' => intval($body['anchorViewable'] ?? 0),
        'interstitial_viewable' => intval($body['interstitialViewable'] ?? 0),
        'interstitial_duration_ms' => intval($body['interstitialDurationMs'] ?? 0),
        'referrer' => sanitize_text_field($body['referrer'] ?? ''),
        'language' => sanitize_text_field($body['language'] ?? ''),
        'zones' => array(),
    );

    // Per-zone data (v5: per-ad injection details).
    if (!empty($body['zones']) && is_array($body['zones'])) {
        foreach ($body['zones'] as $zone) {
            $session['zones'][] = array(
                'zone_id' => sanitize_text_field($zone['zoneId'] ?? ''),
                'slot' => sanitize_text_field($zone['slot'] ?? ''),
                'ad_size' => sanitize_text_field($zone['size'] ?? $zone['adSize'] ?? ''),
                'position' => sanitize_text_field($zone['position'] ?? ''),
                'speed_at_injection' => floatval($zone['speedAtInjection'] ?? $zone['scrollSpeedAtInjection'] ?? 0),
                'pattern_at_injection' => sanitize_text_field($zone['patternAtInjection'] ?? ''),
                'total_visible_ms' => intval($zone['visibleMs'] ?? $zone['totalVisibleMs'] ?? 0),
                'viewable_impressions' => !empty($zone['viewable']) ? 1 : 0,
                'max_ratio' => floatval($zone['maxRatio'] ?? 0),
                'filled' => !empty($zone['filled']),
                'is_pause' => !empty($zone['isPause']),
                'is_video' => !empty($zone['isVideo']),
                'passback' => !empty($zone['passback']),
                'passback_network' => sanitize_text_field($zone['passbackNetwork'] ?? ''),
                'refresh_count' => intval($zone['refreshCount'] ?? 0),
            );
        }
    }

    // Write file
    $filename = $data_dir . '/' . uniqid('s_') . '.json';
    file_put_contents($filename, json_encode($session, JSON_PRETTY_PRINT));

    // Also archive to Live Sessions Recent store so every completed ad session
    // appears on the admin dashboard (not just heartbeat-tracked ones).
    pinlightning_archive_ad_session_to_live( $session );

    // Aggregate into daily stats for the analytics dashboard.
    if ( function_exists( 'pl_ad_aggregate_session' ) ) {
        pl_ad_aggregate_session( $session );
    }

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
        $existing['active_ads']        = $session['total_ads_injected'];
        $existing['viewable_ads']      = $session['total_viewable'];
        $existing['viewability_rate']  = $session['viewability_rate'];
        $existing['zones_active']      = implode( ',', array_column( $session['zones'], 'zone_id' ) );
        $existing['events']            = $session['zones'];
        $existing['scroll_pattern']    = $session['scroll_pattern'];
        $existing['scroll_pct']        = max( $existing['scroll_pct'] ?? 0, $session['max_scroll_depth_pct'] );
        $existing['time_on_page_s']    = max( $existing['time_on_page_s'] ?? 0, round( $session['time_on_page_ms'] / 1000, 1 ) );
        $existing['dir_changes']       = max( $existing['dir_changes'] ?? 0, $session['dir_changes'] ?? 0 );
        // Overlay status (prefer final state from ad-track).
        $existing['anchor_status']       = ! empty( $session['anchor_status'] ) && $session['anchor_status'] !== 'off' ? $session['anchor_status'] : ( $existing['anchor_status'] ?? 'off' );
        $existing['interstitial_status'] = ! empty( $session['interstitial_status'] ) && $session['interstitial_status'] !== 'off' ? $session['interstitial_status'] : ( $existing['interstitial_status'] ?? 'off' );
        $existing['top_anchor_status']   = ! empty( $session['top_anchor_status'] ) && $session['top_anchor_status'] !== 'off' ? $session['top_anchor_status'] : ( $existing['top_anchor_status'] ?? 'off' );
        // Fill tracking (ad-track has final values).
        $existing['total_requested']     = max( $existing['total_requested'] ?? 0, $session['total_requested'] ?? 0 );
        $existing['total_filled']        = max( $existing['total_filled'] ?? 0, $session['total_filled'] ?? 0 );
        $existing['total_empty']         = max( $existing['total_empty'] ?? 0, $session['total_empty'] ?? 0 );
        $existing['fill_rate']           = $session['fill_rate'] ?? ( $existing['fill_rate'] ?? 0 );
        $existing['anchor_filled']       = ! empty( $session['anchor_filled'] ) || ! empty( $existing['anchor_filled'] );
        $existing['interstitial_filled'] = ! empty( $session['interstitial_filled'] ) || ! empty( $existing['interstitial_filled'] );
        $existing['top_anchor_filled']   = ! empty( $session['top_anchor_filled'] ) || ! empty( $existing['top_anchor_filled'] );
        $existing['zones_activated']     = max( $existing['zones_activated'] ?? 0, $session['zones_activated'] ?? 0 );
        // v5: pause banners + refresh + video.
        $existing['pause_banners_shown']     = max( $existing['pause_banners_shown'] ?? 0, $session['pause_banners_shown'] ?? 0 );
        $existing['refresh_count']           = max( $existing['refresh_count'] ?? 0, $session['refresh_count'] ?? 0 );
        $existing['video_injected']          = ! empty( $session['video_injected'] ) || ! empty( $existing['video_injected'] );
        // Overlay detail.
        $existing['anchor_impressions']      = max( $existing['anchor_impressions'] ?? 0, $session['anchor_impressions'] ?? 0 );
        $existing['interstitial_viewable']   = max( $existing['interstitial_viewable'] ?? 0, $session['interstitial_viewable'] ?? 0 );
        // Waldo + side rail (ad-track has final values).
        $existing['waldo_passbacks']         = max( $existing['waldo_passbacks'] ?? 0, $session['waldo_passbacks'] ?? 0 );
        $existing['waldo_tags_used']         = max( $existing['waldo_tags_used'] ?? 0, $session['waldo_tags_used'] ?? 0 );
        $existing['left_side_rail_filled']   = ! empty( $session['left_side_rail_filled'] ) || ! empty( $existing['left_side_rail_filled'] );
        $existing['right_side_rail_filled']  = ! empty( $session['right_side_rail_filled'] ) || ! empty( $existing['right_side_rail_filled'] );
        // Identity fields.
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
        'sid'              => $sid,
        'ts'               => $session['unix'],
        'post_id'          => $session['post_id'],
        'post_slug'        => $session['post_slug'],
        'post_title'       => $session['post_id'] ? get_the_title( $session['post_id'] ) : $session['post_slug'],
        'device'           => $session['device'],
        'viewport_w'       => $session['viewport_w'],
        'viewport_h'       => $session['viewport_h'],
        'time_on_page_s'   => round( $session['time_on_page_ms'] / 1000, 1 ),
        'scroll_pct'       => $session['max_scroll_depth_pct'],
        'scroll_speed'     => intval( $session['avg_scroll_speed'] ),
        'scroll_pattern'   => $session['scroll_pattern'],
        'dir_changes'      => $session['dir_changes'] ?? 0,
        'active_ads'       => $session['total_ads_injected'],
        'viewable_ads'     => $session['total_viewable'],
        'viewability_rate' => $session['viewability_rate'],
        'zones_active'     => implode( ',', array_column( $session['zones'], 'zone_id' ) ),
        'referrer'         => $session['referrer'] ?? '',
        'language'         => $session['language'] ?? '',
        'events'           => $session['zones'],
        // Overlay status.
        'anchor_status'       => $session['anchor_status'] ?? 'off',
        'interstitial_status' => $session['interstitial_status'] ?? 'off',
        'top_anchor_status'   => $session['top_anchor_status'] ?? 'off',
        // Fill tracking.
        'total_requested'      => $session['total_requested'] ?? 0,
        'total_filled'         => $session['total_filled'] ?? 0,
        'total_empty'          => $session['total_empty'] ?? 0,
        'fill_rate'            => $session['fill_rate'] ?? 0,
        'anchor_filled'        => $session['anchor_filled'] ?? false,
        'interstitial_filled'  => $session['interstitial_filled'] ?? false,
        'top_anchor_filled'    => $session['top_anchor_filled'] ?? false,
        'zones_activated'      => $session['zones_activated'] ?? 0,
        // v5: dynamic injection stats.
        'pause_banners_shown'    => $session['pause_banners_shown'] ?? 0,
        'refresh_count'          => $session['refresh_count'] ?? 0,
        'video_injected'         => $session['video_injected'] ?? false,
        'anchor_impressions'     => $session['anchor_impressions'] ?? 0,
        'interstitial_viewable'  => $session['interstitial_viewable'] ?? 0,
        // Waldo + side rail.
        'waldo_passbacks'          => $session['waldo_passbacks'] ?? 0,
        'waldo_tags_used'          => $session['waldo_tags_used'] ?? 0,
        'left_side_rail_filled'    => $session['left_side_rail_filled'] ?? false,
        'right_side_rail_filled'   => $session['right_side_rail_filled'] ?? false,
        'status'         => 'ended',
        'ended_at'       => time(),
        'source'         => 'ad-track',
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

/* ================================================================
 * EVENT-LEVEL TRACKING — AJAX ENDPOINT + DB TABLES
 * ================================================================ */

/**
 * Ensure event tracking DB tables exist.
 * Called on admin_init — uses dbDelta for safe idempotent creation.
 */
function pl_ad_ensure_tables() {
    $installed_ver = get_option( 'pl_ad_tables_ver', '0' );
    if ( '4' === $installed_ver ) {
        return;
    }

    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();

    // Granular event log (v2: added injection analytics columns).
    $sql1 = "CREATE TABLE {$wpdb->prefix}pl_ad_events (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        session_id varchar(32) NOT NULL DEFAULT '',
        event_type varchar(20) NOT NULL DEFAULT '',
        slot_id varchar(30) NOT NULL DEFAULT '',
        unit_name varchar(50) NOT NULL DEFAULT '',
        slot_type varchar(20) NOT NULL DEFAULT '',
        creative_size varchar(15) NOT NULL DEFAULT '',
        refresh_count tinyint(3) unsigned NOT NULL DEFAULT 0,
        visitor_type varchar(15) NOT NULL DEFAULT '',
        scroll_percent tinyint(3) unsigned NOT NULL DEFAULT 0,
        time_on_page int(10) unsigned NOT NULL DEFAULT 0,
        device varchar(10) NOT NULL DEFAULT '',
        page_type varchar(10) NOT NULL DEFAULT '',
        post_id int(10) unsigned NOT NULL DEFAULT 0,
        injection_type varchar(20) NOT NULL DEFAULT '',
        scroll_speed int(10) unsigned NOT NULL DEFAULT 0,
        predicted_distance int(11) NOT NULL DEFAULT 0,
        ad_spacing int(10) unsigned NOT NULL DEFAULT 0,
        time_to_viewable int(10) unsigned NOT NULL DEFAULT 0,
        scroll_direction varchar(4) NOT NULL DEFAULT '',
        near_image tinyint(1) unsigned NOT NULL DEFAULT 0,
        ads_in_viewport tinyint(3) unsigned NOT NULL DEFAULT 0,
        ad_density_percent tinyint(3) unsigned NOT NULL DEFAULT 0,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY idx_created (created_at),
        KEY idx_slot_created (slot_type, created_at),
        KEY idx_event_created (event_type, created_at),
        KEY idx_injection (injection_type, created_at)
    ) $charset;";

    // Hourly rollup for dashboard queries.
    $sql2 = "CREATE TABLE {$wpdb->prefix}pl_ad_hourly_stats (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        hour datetime NOT NULL,
        slot_type varchar(20) NOT NULL DEFAULT '',
        slot_id varchar(30) NOT NULL DEFAULT '',
        device varchar(10) NOT NULL DEFAULT '',
        impressions int(10) unsigned NOT NULL DEFAULT 0,
        empties int(10) unsigned NOT NULL DEFAULT 0,
        viewables int(10) unsigned NOT NULL DEFAULT 0,
        refreshes int(10) unsigned NOT NULL DEFAULT 0,
        refresh_skips int(10) unsigned NOT NULL DEFAULT 0,
        clicks int(10) unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        UNIQUE KEY idx_hourly (hour, slot_type, slot_id, device)
    ) $charset;";

    dbDelta( $sql1 );
    dbDelta( $sql2 );

    // v1→v2 migration: add injection analytics columns to existing table.
    if ( $installed_ver === '1' ) {
        $te = $wpdb->prefix . 'pl_ad_events';
        $cols = $wpdb->get_col( "SHOW COLUMNS FROM {$te}", 0 );
        if ( ! in_array( 'injection_type', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$te}
                ADD COLUMN injection_type varchar(20) NOT NULL DEFAULT '' AFTER post_id,
                ADD COLUMN scroll_speed int(10) unsigned NOT NULL DEFAULT 0 AFTER injection_type,
                ADD COLUMN predicted_distance int(11) NOT NULL DEFAULT 0 AFTER scroll_speed,
                ADD COLUMN ad_spacing int(10) unsigned NOT NULL DEFAULT 0 AFTER predicted_distance,
                ADD COLUMN time_to_viewable int(10) unsigned NOT NULL DEFAULT 0 AFTER ad_spacing,
                ADD KEY idx_injection (injection_type, created_at)" );
        }
    }

    // v2→v3 migration: add scroll_direction column.
    if ( $installed_ver === '1' || $installed_ver === '2' ) {
        $te = $wpdb->prefix . 'pl_ad_events';
        $cols = $wpdb->get_col( "SHOW COLUMNS FROM {$te}", 0 );
        if ( ! in_array( 'scroll_direction', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$te}
                ADD COLUMN scroll_direction varchar(4) NOT NULL DEFAULT '' AFTER time_to_viewable" );
        }
    }

    // v3→v4 migration: add near_image, ads_in_viewport, ad_density_percent columns.
    if ( in_array( $installed_ver, array( '1', '2', '3' ), true ) ) {
        $te = $wpdb->prefix . 'pl_ad_events';
        $cols = $wpdb->get_col( "SHOW COLUMNS FROM {$te}", 0 );
        if ( ! in_array( 'near_image', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$te}
                ADD COLUMN near_image tinyint(1) unsigned NOT NULL DEFAULT 0 AFTER scroll_direction,
                ADD COLUMN ads_in_viewport tinyint(3) unsigned NOT NULL DEFAULT 0 AFTER near_image,
                ADD COLUMN ad_density_percent tinyint(3) unsigned NOT NULL DEFAULT 0 AFTER ads_in_viewport" );
        }
    }

    update_option( 'pl_ad_tables_ver', '4' );
}
add_action( 'admin_init', 'pl_ad_ensure_tables' );

/**
 * AJAX handler: receive batched ad events from the browser.
 * Fires on wp_ajax_pl_ad_event and wp_ajax_nopriv_pl_ad_event.
 */
function pl_ad_handle_event_batch() {
    // Read JSON body (sendBeacon sends as blob).
    $raw  = file_get_contents( 'php://input' );
    $data = json_decode( $raw, true );

    if ( empty( $data ) || empty( $data['events'] ) || ! is_array( $data['events'] ) ) {
        wp_send_json( array( 'ok' => false ), 400 );
    }

    // Bot detection.
    $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '';
    if ( preg_match( '/bot|crawl|spider|lighthouse|pagespeed|headlesschrome|phantomjs/i', $ua ) ) {
        wp_send_json( array( 'ok' => true ) );
    }

    // Rate limit: max 1 batch per 5s per IP.
    $ip      = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    $ip_hash = md5( $ip . floor( time() / 5 ) );
    $rate_key = 'pl_evt_' . substr( $ip_hash, 0, 12 );
    if ( get_transient( $rate_key ) ) {
        wp_send_json( array( 'ok' => true, 'msg' => 'rate-limited' ) );
    }
    set_transient( $rate_key, 1, 5 );

    global $wpdb;
    $table_events = $wpdb->prefix . 'pl_ad_events';
    $table_hourly = $wpdb->prefix . 'pl_ad_hourly_stats';

    // Shared fields from envelope.
    $sid         = sanitize_text_field( substr( $data['sid'] ?? '', 0, 32 ) );
    $device      = sanitize_text_field( substr( $data['device'] ?? '', 0, 10 ) );
    $pageType    = sanitize_text_field( substr( $data['pageType'] ?? '', 0, 10 ) );
    $postId      = absint( $data['postId'] ?? 0 );
    $scrollPct   = min( 100, absint( $data['scrollPct'] ?? 0 ) );
    $timeOnPage  = absint( $data['timeOnPage'] ?? 0 );
    $visitorType = sanitize_text_field( substr( $data['visitorType'] ?? '', 0, 15 ) );
    $hour        = current_time( 'Y-m-d H:00:00' );

    // Cap events per batch to prevent abuse.
    $events = array_slice( $data['events'], 0, 50 );

    foreach ( $events as $evt ) {
        $eventType    = sanitize_text_field( substr( $evt['e'] ?? '', 0, 20 ) );
        $slotId       = sanitize_text_field( substr( $evt['s'] ?? '', 0, 30 ) );
        $unitName     = sanitize_text_field( substr( $evt['u'] ?? '', 0, 50 ) );
        $slotType     = sanitize_text_field( substr( $evt['t'] ?? '', 0, 20 ) );
        $creativeSize = sanitize_text_field( substr( $evt['cs'] ?? '', 0, 15 ) );
        $refreshCount = min( 255, absint( $evt['rc'] ?? 0 ) );

        // Injection analytics fields (v2).
        $injectionType    = sanitize_text_field( substr( $evt['it'] ?? '', 0, 20 ) );
        $scrollSpeed      = absint( $evt['ss'] ?? 0 );
        $predictedDist    = intval( $evt['pd'] ?? 0 );
        $adSpacing        = absint( $evt['sp'] ?? 0 );
        $timeToViewable   = absint( $evt['ttv'] ?? 0 );
        // Direction tracking (v3).
        $scrollDirection  = sanitize_text_field( substr( $evt['sd'] ?? '', 0, 4 ) );
        // Image proximity + density tracking (v4).
        $nearImage        = ! empty( $evt['ni'] ) ? 1 : 0;
        $adsInViewport    = min( 255, absint( $evt['aiv'] ?? 0 ) );
        $adDensityPercent = min( 100, absint( $evt['adp'] ?? 0 ) );

        // Insert event row.
        $wpdb->insert( $table_events, array(
            'session_id'         => $sid,
            'event_type'         => $eventType,
            'slot_id'            => $slotId,
            'unit_name'          => $unitName,
            'slot_type'          => $slotType,
            'creative_size'      => $creativeSize,
            'refresh_count'      => $refreshCount,
            'visitor_type'       => $visitorType,
            'scroll_percent'     => $scrollPct,
            'time_on_page'       => $timeOnPage,
            'device'             => $device,
            'page_type'          => $pageType,
            'post_id'            => $postId,
            'injection_type'     => $injectionType,
            'scroll_speed'       => $scrollSpeed,
            'predicted_distance' => $predictedDist,
            'ad_spacing'         => $adSpacing,
            'time_to_viewable'   => $timeToViewable,
            'scroll_direction'   => $scrollDirection,
            'near_image'         => $nearImage,
            'ads_in_viewport'    => $adsInViewport,
            'ad_density_percent' => $adDensityPercent,
            'created_at'         => current_time( 'mysql' ),
        ) );

        // Hourly aggregation via INSERT ... ON DUPLICATE KEY UPDATE.
        $col = '';
        switch ( $eventType ) {
            case 'impression':   $col = 'impressions'; break;
            case 'empty':        $col = 'empties'; break;
            case 'viewable':     $col = 'viewables'; break;
            case 'refresh':      $col = 'refreshes'; break;
            case 'refresh_skip': $col = 'refresh_skips'; break;
            case 'click':        $col = 'clicks'; break;
        }

        if ( $col ) {
            $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$table_hourly} (hour, slot_type, slot_id, device, {$col})
                 VALUES (%s, %s, %s, %s, 1)
                 ON DUPLICATE KEY UPDATE {$col} = {$col} + 1",
                $hour, $slotType, $slotId, $device
            ) );
        }
    }

    wp_send_json( array( 'ok' => true, 'processed' => count( $events ) ) );
}
add_action( 'wp_ajax_pl_ad_event', 'pl_ad_handle_event_batch' );
add_action( 'wp_ajax_nopriv_pl_ad_event', 'pl_ad_handle_event_batch' );

/**
 * Cleanup: purge event rows older than 90 days.
 * Runs once daily on admin_init via transient guard.
 */
function pl_ad_cleanup_old_events() {
    if ( get_transient( 'pl_ad_events_cleaned' ) ) {
        return;
    }
    global $wpdb;
    $wpdb->query( "DELETE FROM {$wpdb->prefix}pl_ad_events WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY) LIMIT 10000" );
    $wpdb->query( "DELETE FROM {$wpdb->prefix}pl_ad_hourly_stats WHERE hour < DATE_SUB(NOW(), INTERVAL 90 DAY) LIMIT 10000" );
    set_transient( 'pl_ad_events_cleaned', 1, DAY_IN_SECONDS );
}
add_action( 'admin_init', 'pl_ad_cleanup_old_events' );
