<?php
/**
 * PinLightning Visitor Tracker v3.2
 * Embedded in theme — captures scroll, ads, clicks, engagement, character + image interactions.
 * Originally a standalone plugin, now integrated into PinLightning theme.
 */

if (!defined('ABSPATH')) exit;

define('PLT_VERSION', '3.2');
define('PLT_ACTIVE', true);

function plt_get_key() {
    $key = get_option('plt_data_key');
    if (!$key) {
        $key = 'plt_' . substr(md5(wp_salt('auth') . 'tracker'), 0, 16);
        update_option('plt_data_key', $key);
    }
    return $key;
}

add_action('rest_api_init', function() {
    register_rest_route('pl-tracker/v1', '/collect', [
        'methods' => 'POST', 'callback' => 'plt_collect_data',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('pl-tracker/v1', '/report', [
        'methods' => 'GET', 'callback' => 'plt_get_report',
        'permission_callback' => function() {
            return isset($_GET['key']) && $_GET['key'] === plt_get_key();
        },
    ]);
    register_rest_route('pl-tracker/v1', '/raw', [
        'methods' => 'GET', 'callback' => 'plt_get_raw',
        'permission_callback' => function() {
            return isset($_GET['key']) && $_GET['key'] === plt_get_key();
        },
    ]);
    register_rest_route('plt/v1', '/realtime', [
        'methods' => 'GET',
        'callback' => 'plt_realtime_data',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        },
    ]);
});

function plt_realtime_data() {
    $now = current_time('timestamp');
    $cutoff_5min = $now - 300;
    $cutoff_1min = $now - 60;

    $today_start = strtotime('today', $now);
    $sessions = plt_get_sessions_in_range($today_start, $now);

    $active_5min = [];
    $active_1min = 0;

    foreach ($sessions as $s) {
        $ts = $s['unix'] ?? strtotime($s['ts'] ?? '');
        if (!$ts) continue;

        if ($ts >= $cutoff_5min) {
            $page_url = $s['url'] ?? '';
            $short = preg_replace('#https?://[^/]+#', '', $page_url);

            $active_5min[] = [
                'country' => $s['country'] ?? 'Unknown',
                'device' => $s['device'] ?? 'desktop',
                'page' => strlen($short) > 45 ? substr($short, 0, 45) . '...' : $short,
                'time' => date('H:i:s', $ts),
            ];

            if ($ts >= $cutoff_1min) $active_1min++;
        }
    }

    return [
        'count' => count($active_5min),
        'count_1min' => $active_1min,
        'visitors' => array_reverse($active_5min),
        'timestamp' => date('H:i:s', $now),
    ];
}

function plt_collect_data($request) {
    $body = $request->get_json_params();
    if (empty($body) || !isset($body['t'])) return new WP_REST_Response(['ok'=>false], 400);

    $ip_hash = md5($_SERVER['REMOTE_ADDR'] . date('YmdHi'));
    if (get_transient('plt_r_' . $ip_hash)) return new WP_REST_Response(['ok'=>true], 200);
    set_transient('plt_r_' . $ip_hash, 1, 5);

    $upload_dir = wp_upload_dir();
    $data_dir = $upload_dir['basedir'] . '/pl-tracker-data/' . date('Y-m-d');
    if (!file_exists($data_dir)) {
        wp_mkdir_p($data_dir);
        file_put_contents(dirname($data_dir) . '/.htaccess', "Deny from all\n");
    }

    // Sanitize arrays helper
    $san = function($v) { return is_string($v) ? sanitize_text_field($v) : $v; };

    $session = [
        'v' => 3,
        'ts' => current_time('mysql'),
        'unix' => time(),
        'url' => sanitize_text_field($body['url'] ?? ''),
        'post_id' => intval($body['pid'] ?? 0),
        'referrer' => sanitize_text_field($body['ref'] ?? ''),
        'device' => sanitize_text_field($body['dev'] ?? ''),
        'vw' => intval($body['vw'] ?? 0),
        'vh' => intval($body['vh'] ?? 0),
        'returning' => intval($body['ret'] ?? 0),
        'visitor_id' => sanitize_text_field($body['vid'] ?? ''),
        'ga4cid' => sanitize_text_field($body['ga4cid'] ?? ''),
        'timezone' => sanitize_text_field($body['tz'] ?? ''),
        'language' => sanitize_text_field($body['lang'] ?? ''),

        // === CORE SCROLL ===
        'time_on_page_ms' => intval($body['t'] ?? 0),
        'max_depth_pct' => round(floatval($body['d'] ?? 0), 1),
        'avg_scroll_speed' => round(floatval($body['ss'] ?? 0)),
        'max_scroll_speed' => round(floatval($body['ms'] ?? 0)),
        'scroll_pattern' => sanitize_text_field($body['sp'] ?? ''),
        'content_height' => intval($body['ch'] ?? 0),
        'scroll_events' => intval($body['se'] ?? 0),
        'scroll_direction_changes' => intval($body['dc'] ?? 0),
        'idle_periods' => intval($body['ip'] ?? 0),
        'content_completion_pct' => round(floatval($body['ccp'] ?? 0), 1),

        // === SCROLL SPEED HEATMAP (per 10% zone) ===
        'speed_by_zone' => [],

        // === READ SEGMENTS ===
        'read_segments' => [],

        // === SECTION TIMING (H2 to H2) ===
        'sections' => [],

        // === TOUCH ===
        'touch_events' => intval($body['te'] ?? 0),
        'touch_data' => [
            'taps' => intval($body['td']['taps'] ?? 0),
            'swipes_up' => intval($body['td']['su'] ?? 0),
            'swipes_down' => intval($body['td']['sd'] ?? 0),
            'swipes_left' => intval($body['td']['sl'] ?? 0),
            'swipes_right' => intval($body['td']['sr'] ?? 0),
            'avg_swipe_velocity' => round(floatval($body['td']['asv'] ?? 0)),
            'max_swipe_velocity' => round(floatval($body['td']['msv'] ?? 0)),
            'pinch_zooms' => intval($body['td']['pz'] ?? 0),
            'double_taps' => intval($body['td']['dt'] ?? 0),
        ],

        // === EXIT INTENT ===
        'exit_intent' => [
            'triggered' => intval($body['ei']['tr'] ?? 0),
            'signals' => [
                'mouse_top_edge' => intval($body['ei']['mte'] ?? 0),
                'mouse_leave_window' => intval($body['ei']['mlw'] ?? 0),
                'scroll_reversal_from_deep' => intval($body['ei']['srd'] ?? 0),
                'tab_switch' => intval($body['ei']['ts'] ?? 0),
                'idle_timeout' => intval($body['ei']['it'] ?? 0),
                'rapid_scroll_up' => intval($body['ei']['rsu'] ?? 0),
            ],
            'first_signal_type' => sanitize_text_field($body['ei']['fst'] ?? ''),
            'first_signal_ms' => intval($body['ei']['fsm'] ?? 0),
            'depth_at_exit' => round(floatval($body['ei']['dae'] ?? 0), 1),
            'exit_velocity' => round(floatval($body['ei']['ev'] ?? 0)),
            'last_section_before_exit' => sanitize_text_field($body['ei']['lsbe'] ?? ''),
            'last_interaction_type' => sanitize_text_field($body['ei']['lit'] ?? ''),
            'time_since_last_interaction_ms' => intval($body['ei']['tsli'] ?? 0),
            'was_engaged' => intval($body['ei']['we'] ?? 0),
        ],

        // === ADS ===
        'ads_found' => intval($body['af'] ?? 0),
        'ads_clicked' => intval($body['ac'] ?? 0),
        'ads_data' => [],

        // === LINK CLICKS (the money data) ===
        'link_clicks' => [],

        // === IMAGE ENGAGEMENT ===
        'image_engagement' => [],

        // === CTA TRACKING ===
        'ctas' => [],

        // === ENGAGEMENT ===
        'engagement' => [
            'total_clicks' => intval($body['eng']['tc'] ?? 0),
            'link_clicks_count' => intval($body['eng']['lc'] ?? 0),
            'image_clicks' => intval($body['eng']['ic'] ?? 0),
            'text_selections' => intval($body['eng']['ts'] ?? 0),
            'copy_events' => intval($body['eng']['ce'] ?? 0),
            'active_time_ms' => intval($body['eng']['at'] ?? 0),
            'rage_clicks' => intval($body['eng']['rc'] ?? 0),
            'scroll_to_top_events' => intval($body['eng']['stt'] ?? 0),
        ],

        // === ATTENTION ZONES ===
        'attention_zones' => [],

        // === PEAK ENGAGEMENT (best moments for popup/overlay) ===
        'peak_engagement' => [
            'best_depth_pct' => round(floatval($body['pe']['bd'] ?? 0), 1),
            'best_time_ms' => intval($body['pe']['bt'] ?? 0),
            'engagement_score_at_peak' => round(floatval($body['pe']['es'] ?? 0), 2),
            'slowest_scroll_zone_pct' => round(floatval($body['pe']['ssz'] ?? 0), 1),
        ],

        // === SESSION QUALITY SCORE ===
        'quality_score' => round(floatval($body['qs'] ?? 0), 1),

        // === CHARACTER INTERACTION ===
        'char_interaction' => [
            'char_taps' => 0,
            'heart_taps' => 0,
            'first_char_tap_ms' => 0,
            'first_heart_tap_ms' => 0,
            'max_rapid_taps' => 0,
            'tap_log' => [],
        ],

        // === IMAGE INTERACTION ===
        'image_interaction' => [
            'image_taps' => 0,
            'first_image_tap_ms' => 0,
            'tap_log' => [],
        ],
    ];

    // Populate character interaction if present (from scroll-engage.js via window.__plt)
    if (!empty($body['ci']) && is_array($body['ci'])) {
        $ci = $body['ci'];
        $session['char_interaction'] = [
            'char_taps' => absint($ci['ct'] ?? 0),
            'heart_taps' => absint($ci['ht'] ?? 0),
            'first_char_tap_ms' => absint($ci['cft'] ?? 0),
            'first_heart_tap_ms' => absint($ci['hft'] ?? 0),
            'max_rapid_taps' => absint($ci['rm'] ?? 0),
            'tap_log' => [],
        ];
        if (!empty($ci['tl']) && is_array($ci['tl'])) {
            foreach (array_slice($ci['tl'], 0, 50) as $tap) {
                $session['char_interaction']['tap_log'][] = [
                    'time_ms' => absint($tap['t'] ?? 0),
                    'depth_pct' => absint($tap['d'] ?? 0),
                    'animation' => sanitize_text_field($tap['a'] ?? ''),
                    'speech_showing' => absint($tap['sb'] ?? 0),
                    'target' => sanitize_text_field($tap['tgt'] ?? ''),
                ];
            }
        }
    }

    // Image interaction data (from scroll-engage.js via window.__plt)
    if (!empty($body['ci']) && is_array($body['ci'])) {
        $ci_img = $body['ci'];
        if (!empty($ci_img['it'])) {
            $session['image_interaction'] = [
                'image_taps' => absint($ci_img['it'] ?? 0),
                'first_image_tap_ms' => absint($ci_img['ift'] ?? 0),
                'tap_log' => [],
            ];
            if (!empty($ci_img['itl']) && is_array($ci_img['itl'])) {
                foreach (array_slice($ci_img['itl'], 0, 30) as $itap) {
                    $session['image_interaction']['tap_log'][] = [
                        'time_ms' => absint($itap['time'] ?? 0),
                        'index' => absint($itap['index'] ?? 0),
                        'total' => absint($itap['total'] ?? 0),
                        'depth_pct' => round(floatval($itap['depth'] ?? 0), 1),
                        'alt' => sanitize_text_field($itap['alt'] ?? ''),
                        'section' => sanitize_text_field($itap['section'] ?? ''),
                        'caption' => sanitize_text_field($itap['caption'] ?? ''),
                        'opened_chat' => !empty($itap['openedChat']),
                    ];
                }
            }
        }
    }

    // Speed by zone
    if (!empty($body['sbz']) && is_array($body['sbz'])) {
        $session['speed_by_zone'] = array_map('round', array_map('floatval', $body['sbz']));
    }

    // Read segments
    if (!empty($body['rs']) && is_array($body['rs'])) {
        $session['read_segments'] = array_map('intval', $body['rs']);
    }

    // Sections (H2 timing)
    if (!empty($body['sec']) && is_array($body['sec'])) {
        foreach (array_slice($body['sec'], 0, 30) as $s) {
            $session['sections'][] = [
                'heading' => sanitize_text_field(substr($s['h'] ?? '', 0, 100)),
                'depth_pct' => round(floatval($s['d'] ?? 0), 1),
                'visible_ms' => intval($s['v'] ?? 0),
                'seen' => intval($s['s'] ?? 0),
                'scroll_speed_through' => round(floatval($s['sp'] ?? 0)),
            ];
        }
    }

    // Per-ad data
    if (!empty($body['ads']) && is_array($body['ads'])) {
        foreach (array_slice($body['ads'], 0, 40) as $ad) {
            $session['ads_data'][] = [
                'element' => sanitize_text_field($ad['el'] ?? ''),
                'size_w' => intval($ad['w'] ?? 0),
                'size_h' => intval($ad['h'] ?? 0),
                'position_pct' => round(floatval($ad['pos'] ?? 0), 1),
                'total_visible_ms' => intval($ad['vis'] ?? 0),
                'max_visible_ratio' => round(floatval($ad['mr'] ?? 0), 2),
                'avg_visible_ratio' => round(floatval($ad['ar'] ?? 0), 2),
                'time_to_first_view_ms' => intval($ad['ftv'] ?? 0),
                'viewable_1s' => intval($ad['v1'] ?? 0),
                'scroll_speed_at_position' => round(floatval($ad['sap'] ?? 0)),
                'scroll_speed_through' => round(floatval($ad['spt'] ?? 0)),
                'times_entered_viewport' => intval($ad['tev'] ?? 0),
                'scrolled_back_to' => intval($ad['sbt'] ?? 0),
                'clicked' => intval($ad['clk'] ?? 0),
                'click_time_ms' => intval($ad['ct'] ?? 0),
                'hover_time_ms' => intval($ad['ht'] ?? 0),
                'near_miss_clicks' => intval($ad['nmc'] ?? 0),
                'nearby_content' => sanitize_text_field(substr($ad['nc'] ?? '', 0, 100)),
            ];
        }
    }

    // Link clicks
    if (!empty($body['lnk']) && is_array($body['lnk'])) {
        foreach (array_slice($body['lnk'], 0, 50) as $lnk) {
            $session['link_clicks'][] = [
                'url' => esc_url_raw(substr($lnk['u'] ?? '', 0, 500)),
                'text' => sanitize_text_field(substr($lnk['t'] ?? '', 0, 100)),
                'type' => sanitize_text_field($lnk['tp'] ?? ''),  // internal, external, affiliate, social
                'affiliate_network' => sanitize_text_field($lnk['an'] ?? ''),
                'position_pct' => round(floatval($lnk['p'] ?? 0), 1),
                'click_time_ms' => intval($lnk['ct'] ?? 0),
                'nearby_section' => sanitize_text_field(substr($lnk['ns'] ?? '', 0, 100)),
            ];
        }
    }

    // Image engagement
    if (!empty($body['img']) && is_array($body['img'])) {
        foreach (array_slice($body['img'], 0, 30) as $im) {
            $session['image_engagement'][] = [
                'src' => sanitize_text_field(substr($im['s'] ?? '', 0, 200)),
                'alt' => sanitize_text_field(substr($im['a'] ?? '', 0, 100)),
                'position_pct' => round(floatval($im['p'] ?? 0), 1),
                'visible_ms' => intval($im['v'] ?? 0),
                'tapped' => intval($im['t'] ?? 0),
                'zoomed' => intval($im['z'] ?? 0),
            ];
        }
    }

    // CTA tracking
    if (!empty($body['cta']) && is_array($body['cta'])) {
        foreach (array_slice($body['cta'], 0, 20) as $c) {
            $session['ctas'][] = [
                'text' => sanitize_text_field(substr($c['t'] ?? '', 0, 100)),
                'url' => esc_url_raw(substr($c['u'] ?? '', 0, 500)),
                'position_pct' => round(floatval($c['p'] ?? 0), 1),
                'seen' => intval($c['s'] ?? 0),
                'visible_ms' => intval($c['v'] ?? 0),
                'clicked' => intval($c['c'] ?? 0),
                'scrolled_past' => intval($c['sp'] ?? 0),
            ];
        }
    }

    // Attention zones
    if (!empty($body['az']) && is_array($body['az'])) {
        foreach (array_slice($body['az'], 0, 50) as $az) {
            $session['attention_zones'][] = [
                'depth_pct' => round(floatval($az['d'] ?? 0), 1),
                'pause_ms' => intval($az['p'] ?? 0),
            ];
        }
    }

    // Pinterest pin saves
    if (!empty($body['pin']) && is_array($body['pin'])) {
        $session['pin_saves'] = [
            'saves' => intval($body['pin']['saves'] ?? 0),
            'images' => [],
        ];
        foreach (array_slice($body['pin']['images'] ?? [], 0, 20) as $img) {
            $session['pin_saves']['images'][] = sanitize_text_field(substr($img, 0, 300));
        }
    }

    // Geo lookup via IP (cached per IP for 24h)
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip && $ip !== '127.0.0.1' && $ip !== '::1') {
        $geo_cache_key = 'plt_geo_' . md5($ip);
        $geo = get_transient($geo_cache_key);
        if ($geo === false) {
            $response = wp_remote_get("http://ip-api.com/json/{$ip}?fields=country,countryCode,city,regionName", [
                'timeout' => 2,
            ]);
            if (!is_wp_error($response)) {
                $geo = json_decode(wp_remote_retrieve_body($response), true);
                if (!empty($geo['country'])) {
                    set_transient($geo_cache_key, $geo, DAY_IN_SECONDS);
                }
            }
        }
        if (!empty($geo['country'])) {
            $session['country'] = sanitize_text_field($geo['country']);
            $session['country_code'] = sanitize_text_field($geo['countryCode'] ?? '');
            $session['city'] = sanitize_text_field($geo['city'] ?? '');
            $session['region'] = sanitize_text_field($geo['regionName'] ?? '');
        }
    }

    file_put_contents(
        $data_dir . '/' . uniqid('v3_') . '.json',
        json_encode($session, JSON_PRETTY_PRINT)
    );

    // Invalidate homepage engagement scores cache so smart grid picks up new data.
    delete_transient( 'pl_post_engagement_scores' );

    // Send to GA4 (non-blocking, zero performance impact)
    pl_send_to_ga4($session);

    return new WP_REST_Response(['ok' => true], 200);
}

/**
 * Get report data as array (for internal dashboard use)
 */
function plt_get_report_data($days = 7) {
    $sessions = plt_load_sessions($days);
    if (empty($sessions)) return null;
    // Reuse the report logic — call the REST handler with a mock request
    $request = new WP_REST_Request('GET', '/pl-tracker/v1/report');
    $request->set_param('days', $days);
    $response = plt_get_report($request);
    $data = $response->get_data();
    return $data;
}

// ============================================
// REPORT v3 — comprehensive
// ============================================
function plt_get_report($request) {
    $days = intval($request->get_param('days') ?: 7);
    $sessions = plt_load_sessions($days);
    if (empty($sessions)) return new WP_REST_Response(['status'=>'no_data','key'=>plt_get_key()], 200);

    $total = count($sessions);
    $avg = function($a) { return count($a) ? array_sum($a)/count($a) : 0; };
    $med = function($a) {
        if (!count($a)) return 0; sort($a); $m=floor(count($a)/2);
        return count($a)%2 ? $a[$m] : ($a[$m-1]+$a[$m])/2;
    };

    // Accumulators
    $devices = ['mobile'=>0,'desktop'=>0,'tablet'=>0];
    $patterns = ['reader'=>0,'scanner'=>0,'bouncer'=>0];
    $times=[]; $depths=[]; $speeds=[]; $max_speeds=[]; $ads_per=[]; $referrers=[]; $urls=[];
    $dir_changes=[]; $idle_per=[]; $completions=[]; $quality_scores=[];
    $read_segs = array_fill(0,10,[]);
    $speed_zones = array_fill(0,10,[]);
    $returning = 0;

    // Exit
    $exit_sigs = ['mouse_top_edge'=>0,'mouse_leave_window'=>0,'scroll_reversal_from_deep'=>0,
        'tab_switch'=>0,'idle_timeout'=>0,'rapid_scroll_up'=>0];
    $exit_trig=0; $exit_depths=[]; $exit_times=[]; $first_sigs=[]; $exit_engaged=0;
    $last_sections_exit=[]; $last_interact_types=[];

    // Touch
    $touch_sess=0; $taps=0; $sw_u=0; $sw_d=0; $sw_vel=[]; $pinches=0; $dtaps=0;

    // Engagement
    $link_clk=0; $ad_clk=0; $rage_clk=0; $txt_sel=0; $active_t=[];
    $ad_clk_pos=[]; $ad_clk_size=[];

    // Ads
    $all_ads=[]; $ad_rengaged=0;

    // Links
    $all_links=[]; $aff_clicks=0; $ext_clicks=0; $int_clicks=0;
    $aff_networks=[];

    // Images
    $all_images=[];

    // CTAs
    $all_ctas=[];

    // Sections
    $all_sections=[];

    // Attention
    $attention = array_fill(0,10,['pauses'=>0,'ms'=>0]);

    // Peak engagement
    $peak_depths=[]; $peak_times=[]; $peak_scores=[]; $slowest_zones=[];

    // Character interaction
    $ci_sessions = 0; $ci_char_taps = 0; $ci_heart_taps = 0;
    $ci_first_tap_times = []; $ci_rapid_max = [];
    $ci_taps_by_depth = array_fill(0, 10, 0);
    $ci_taps_by_anim = [];
    $ci_taps_by_target = ['char' => 0, 'heart' => 0];
    $ci_taps_with_speech = 0;
    $ci_tappers_depths = []; $ci_nontappers_depths = [];
    $ci_tappers_return = 0; $ci_nontappers_return = 0;
    $ci_tappers_total = 0; $ci_nontappers_total = 0;

    // Image interaction
    $img_sessions = 0; $img_total_taps = 0;
    $img_taps_by_depth = array_fill(0, 10, 0);
    $img_taps_by_section = [];
    $img_to_chat = 0;

    // Pinterest
    $pin_sessions = 0; $pin_total_saves = 0; $pin_images = [];

    foreach ($sessions as $s) {
        $dev=$s['device']??'unknown'; if(isset($devices[$dev])) $devices[$dev]++;
        $pat=$s['scroll_pattern']??''; if(isset($patterns[$pat])) $patterns[$pat]++;
        $times[]=$s['time_on_page_ms']??0;
        $depths[]=$s['max_depth_pct']??0;
        $speeds[]=$s['avg_scroll_speed']??0;
        $max_speeds[]=$s['max_scroll_speed']??0;
        $ads_per[]=$s['ads_found']??0;
        $dir_changes[]=$s['scroll_direction_changes']??0;
        $idle_per[]=$s['idle_periods']??0;
        if(isset($s['content_completion_pct'])) $completions[]=$s['content_completion_pct'];
        if(isset($s['quality_score'])) $quality_scores[]=$s['quality_score'];
        if(!empty($s['returning'])) $returning++;

        $ref=$s['referrer']??'direct';
        if(strpos($ref,'pinterest')!==false)$ref='pinterest';
        elseif(strpos($ref,'google')!==false)$ref='google';
        elseif(strpos($ref,'facebook')!==false||strpos($ref,'fb.')!==false)$ref='facebook';
        elseif($ref==='')$ref='direct';
        $referrers[$ref]=($referrers[$ref]??0)+1;
        $url=$s['url']??''; $urls[$url]=($urls[$url]??0)+1;

        // Read segments
        foreach($s['read_segments']??[] as $i=>$ms){ if($i<10) $read_segs[$i][]=$ms; }
        // Speed zones
        foreach($s['speed_by_zone']??[] as $i=>$spd){ if($i<10) $speed_zones[$i][]=$spd; }

        // Exit
        $ei=$s['exit_intent']??[];
        if(!empty($ei['triggered'])) $exit_trig++;
        foreach($exit_sigs as $k=>&$v){ $v+=intval($ei['signals'][$k]??0); }
        if(!empty($ei['depth_at_exit'])) $exit_depths[]=$ei['depth_at_exit'];
        if(!empty($ei['first_signal_ms'])) $exit_times[]=$ei['first_signal_ms'];
        if(!empty($ei['first_signal_type'])){ $f=$ei['first_signal_type']; $first_sigs[$f]=($first_sigs[$f]??0)+1; }
        if(!empty($ei['was_engaged'])) $exit_engaged++;
        if(!empty($ei['last_section_before_exit'])){
            $ls=$ei['last_section_before_exit']; $last_sections_exit[$ls]=($last_sections_exit[$ls]??0)+1;
        }
        if(!empty($ei['last_interaction_type'])){
            $li=$ei['last_interaction_type']; $last_interact_types[$li]=($last_interact_types[$li]??0)+1;
        }

        // Touch
        $td=$s['touch_data']??[];
        if(!empty($td['taps'])||($s['touch_events']??0)>0){
            $touch_sess++; $taps+=$td['taps']??0;
            $sw_u+=$td['swipes_up']??0; $sw_d+=$td['swipes_down']??0;
            if(!empty($td['avg_swipe_velocity'])) $sw_vel[]=$td['avg_swipe_velocity'];
            $pinches+=$td['pinch_zooms']??0; $dtaps+=$td['double_taps']??0;
        }

        // Engagement
        $eng=$s['engagement']??[];
        $link_clk+=$eng['link_clicks_count']??0;
        $rage_clk+=$eng['rage_clicks']??0;
        $txt_sel+=$eng['text_selections']??0;
        if(!empty($eng['active_time_ms'])) $active_t[]=$eng['active_time_ms'];
        $ad_clk+=$s['ads_clicked']??0;

        // Ads
        foreach($s['ads_data']??[] as $ad){
            $all_ads[]=array_merge($ad,['device'=>$dev,'pattern'=>$pat]);
            if(!empty($ad['scrolled_back_to'])) $ad_rengaged+=$ad['scrolled_back_to'];
            if(!empty($ad['clicked'])){
                $pb=floor(($ad['position_pct']??0)/10)*10;
                $pk=$pb.'-'.($pb+10).'%';
                $ad_clk_pos[$pk]=($ad_clk_pos[$pk]??0)+$ad['clicked'];
                $sk=($ad['size_w']??0).'x'.($ad['size_h']??0);
                $ad_clk_size[$sk]=($ad_clk_size[$sk]??0)+$ad['clicked'];
            }
        }

        // Links
        foreach($s['link_clicks']??[] as $lnk){
            $all_links[]=$lnk;
            $tp=$lnk['type']??'unknown';
            if($tp==='affiliate'){$aff_clicks++;
                $an=$lnk['affiliate_network']??'unknown';
                $aff_networks[$an]=($aff_networks[$an]??0)+1;
            }
            elseif($tp==='external') $ext_clicks++;
            elseif($tp==='internal') $int_clicks++;
        }

        // Images
        foreach($s['image_engagement']??[] as $im) $all_images[]=$im;

        // CTAs
        foreach($s['ctas']??[] as $c) $all_ctas[]=$c;

        // Sections
        foreach($s['sections']??[] as $sec) $all_sections[]=$sec;

        // Attention
        foreach($s['attention_zones']??[] as $az){
            $idx=min(9,max(0,floor(($az['depth_pct']??0)/10)));
            $attention[$idx]['pauses']++;
            $attention[$idx]['ms']+=$az['pause_ms']??0;
        }

        // Peak
        $pe=$s['peak_engagement']??[];
        if(!empty($pe['best_depth_pct'])) $peak_depths[]=$pe['best_depth_pct'];
        if(!empty($pe['best_time_ms'])) $peak_times[]=$pe['best_time_ms'];
        if(!empty($pe['engagement_score_at_peak'])) $peak_scores[]=$pe['engagement_score_at_peak'];
        if(!empty($pe['slowest_scroll_zone_pct'])) $slowest_zones[]=$pe['slowest_scroll_zone_pct'];

        // Character interaction
        $cci = $s['char_interaction'] ?? [];
        $ct = intval($cci['char_taps'] ?? 0);
        $hrt = intval($cci['heart_taps'] ?? 0);
        $has_taps = ($ct + $hrt) > 0;
        if ($has_taps) {
            $ci_sessions++;
            $ci_char_taps += $ct;
            $ci_heart_taps += $hrt;
            $ci_taps_by_target['char'] += $ct;
            $ci_taps_by_target['heart'] += $hrt;
            if (!empty($cci['first_char_tap_ms'])) $ci_first_tap_times[] = $cci['first_char_tap_ms'];
            if (!empty($cci['max_rapid_taps'])) $ci_rapid_max[] = $cci['max_rapid_taps'];
            foreach ($cci['tap_log'] ?? [] as $tap) {
                $di = min(9, max(0, floor(($tap['depth_pct'] ?? 0) / 10)));
                $ci_taps_by_depth[$di]++;
                $anim = $tap['animation'] ?? 'unknown';
                $ci_taps_by_anim[$anim] = ($ci_taps_by_anim[$anim] ?? 0) + 1;
                if (!empty($tap['speech_showing'])) $ci_taps_with_speech++;
            }
            $ci_tappers_depths[] = $s['max_depth_pct'] ?? 0;
            $ci_tappers_total++;
            if (!empty($s['returning'])) $ci_tappers_return++;
        } else {
            $ci_nontappers_depths[] = $s['max_depth_pct'] ?? 0;
            $ci_nontappers_total++;
            if (!empty($s['returning'])) $ci_nontappers_return++;
        }

        // Pinterest saves
        $ps = $s['pin_saves'] ?? [];
        if (!empty($ps['saves'])) {
            $pin_sessions++;
            $pin_total_saves += intval($ps['saves']);
            foreach ($ps['images'] ?? [] as $pi) {
                $pin_images[$pi] = ($pin_images[$pi] ?? 0) + 1;
            }
        }

        // Image interaction
        $iii = $s['image_interaction'] ?? [];
        $iit = intval($iii['image_taps'] ?? 0);
        if ($iit > 0) {
            $img_sessions++;
            $img_total_taps += $iit;
            foreach ($iii['tap_log'] ?? [] as $itap) {
                $di = min(9, max(0, floor(($itap['depth_pct'] ?? 0) / 10)));
                $img_taps_by_depth[$di]++;
                $sec = $itap['section'] ?? '';
                if ($sec) $img_taps_by_section[$sec] = ($img_taps_by_section[$sec] ?? 0) + 1;
                if (!empty($itap['opened_chat'])) $img_to_chat++;
            }
        }
    }

    // Build heatmaps
    $read_hm=[]; $speed_hm=[]; $att_hm=[];
    for($i=0;$i<10;$i++){
        $p=($i*10).'-'.(($i+1)*10).'%';
        $read_hm[$p]=['avg_time_ms'=>round($avg($read_segs[$i])),'samples'=>count($read_segs[$i])];
        $speed_hm[$p]=['avg_speed'=>round($avg($speed_zones[$i])),'samples'=>count($speed_zones[$i])];
        $att_hm[$p]=['pauses'=>$attention[$i]['pauses'],
            'avg_pause_ms'=>$attention[$i]['pauses']>0?round($attention[$i]['ms']/$attention[$i]['pauses']):0];
    }

    // Ad analysis
    $abp=[]; $abd=['mobile'=>['t'=>0,'v'=>0,'c'=>0],'desktop'=>['t'=>0,'v'=>0,'c'=>0]];
    $abpat=['reader'=>['t'=>0,'v'=>0],'scanner'=>['t'=>0,'v'=>0],'bouncer'=>['t'=>0,'v'=>0]];
    foreach($all_ads as $ad){
        $pb=floor(($ad['position_pct']??0)/10)*10;
        $k=$pb.'-'.($pb+10).'%';
        if(!isset($abp[$k]))$abp[$k]=['total'=>0,'v1'=>0,'vis_ms'=>0,'rsum'=>0,'clicks'=>0,'hover'=>0,'sbt'=>0,'spt'=>0];
        $abp[$k]['total']++; $abp[$k]['v1']+=$ad['viewable_1s']??0;
        $abp[$k]['vis_ms']+=$ad['total_visible_ms']??0; $abp[$k]['rsum']+=$ad['avg_visible_ratio']??0;
        $abp[$k]['clicks']+=$ad['clicked']??0; $abp[$k]['hover']+=$ad['hover_time_ms']??0;
        $abp[$k]['sbt']+=$ad['scrolled_back_to']??0; $abp[$k]['spt']+=$ad['scroll_speed_through']??0;

        $dv=$ad['device']; if(isset($abd[$dv])){$abd[$dv]['t']++;$abd[$dv]['v']+=$ad['viewable_1s']??0;$abd[$dv]['c']+=$ad['clicked']??0;}
        $pt=$ad['pattern']; if(isset($abpat[$pt])){$abpat[$pt]['t']++;$abpat[$pt]['v']+=$ad['viewable_1s']??0;}
    }
    ksort($abp);
    foreach($abp as &$p){
        $p['viewability_pct']=$p['total']>0?round(($p['v1']/$p['total'])*100,1):0;
        $p['avg_visible_ms']=$p['total']>0?round($p['vis_ms']/$p['total']):0;
        $p['avg_ratio']=$p['total']>0?round($p['rsum']/$p['total'],2):0;
        $p['ctr_pct']=$p['total']>0?round(($p['clicks']/$p['total'])*100,2):0;
        $p['avg_hover_ms']=$p['total']>0?round($p['hover']/$p['total']):0;
        $p['re_engagements']=$p['sbt'];
        $p['avg_scroll_speed_through']=$p['total']>0?round($p['spt']/$p['total']):0;
        unset($p['rsum'],$p['hover'],$p['vis_ms'],$p['v1'],$p['sbt'],$p['spt']);
    }

    // Section analysis
    $section_stats=[];
    foreach($all_sections as $sec){
        $h=$sec['heading']??'unknown';
        if(!isset($section_stats[$h]))$section_stats[$h]=['seen'=>0,'total_visible_ms'=>0,'count'=>0,'avg_speed'=>0];
        $section_stats[$h]['count']++;
        $section_stats[$h]['seen']+=$sec['seen']??0;
        $section_stats[$h]['total_visible_ms']+=$sec['visible_ms']??0;
        $section_stats[$h]['avg_speed']+=$sec['scroll_speed_through']??0;
    }
    foreach($section_stats as &$ss){
        $ss['avg_visible_ms']=$ss['count']>0?round($ss['total_visible_ms']/$ss['count']):0;
        $ss['view_rate_pct']=$ss['count']>0?round(($ss['seen']/$ss['count'])*100,1):0;
        $ss['avg_scroll_speed']=round($ss['avg_speed']/max($ss['count'],1));
        unset($ss['total_visible_ms'],$ss['avg_speed']);
    }

    // CTA analysis
    $cta_stats=[];
    foreach($all_ctas as $c){
        $t=$c['text']??'unknown';
        if(!isset($cta_stats[$t]))$cta_stats[$t]=['seen'=>0,'clicked'=>0,'scrolled_past'=>0,'total_visible_ms'=>0,'count'=>0];
        $cta_stats[$t]['count']++;
        $cta_stats[$t]['seen']+=$c['seen']??0;
        $cta_stats[$t]['clicked']+=$c['clicked']??0;
        $cta_stats[$t]['scrolled_past']+=$c['scrolled_past']??0;
        $cta_stats[$t]['total_visible_ms']+=$c['visible_ms']??0;
    }
    foreach($cta_stats as &$cs){
        $cs['view_rate_pct']=$cs['count']>0?round(($cs['seen']/$cs['count'])*100,1):0;
        $cs['ctr_pct']=$cs['seen']>0?round(($cs['clicked']/$cs['seen'])*100,2):0;
        $cs['pass_rate_pct']=$cs['seen']>0?round(($cs['scrolled_past']/$cs['seen'])*100,1):0;
        $cs['avg_visible_ms']=$cs['count']>0?round($cs['total_visible_ms']/$cs['count']):0;
        unset($cs['total_visible_ms']);
    }

    // Link analysis
    $link_by_type=['internal'=>$int_clicks,'external'=>$ext_clicks,'affiliate'=>$aff_clicks];
    $top_links=[];
    foreach($all_links as $l){
        $u=$l['url']??'';
        if(!isset($top_links[$u]))$top_links[$u]=['clicks'=>0,'text'=>$l['text']??'','type'=>$l['type']??'','avg_pos'=>0,'pos_sum'=>0];
        $top_links[$u]['clicks']++;
        $top_links[$u]['pos_sum']+=$l['position_pct']??0;
    }
    foreach($top_links as &$tl){ $tl['avg_position_pct']=round($tl['pos_sum']/max($tl['clicks'],1),1); unset($tl['pos_sum']); }
    arsort(array_column($top_links,'clicks'));
    $top_links_sorted=[];
    uasort($top_links, function($a,$b){return $b['clicks']-$a['clicks'];});
    $top_links=array_slice($top_links,0,30,true);

    // Image analysis
    $img_tapped=0; $img_zoomed=0; $img_vis_total=0;
    foreach($all_images as $im){
        $img_tapped+=$im['tapped']??0;
        $img_zoomed+=$im['zoomed']??0;
        $img_vis_total+=$im['visible_ms']??0;
    }

    // Best/worst ad positions
    $bp='';$bv=0;$wp='';$wv=100;
    foreach($abp as $pos=>$d){
        if($d['total']<3)continue;
        if($d['viewability_pct']>$bv){$bv=$d['viewability_pct'];$bp=$pos;}
        if($d['viewability_pct']<$wv){$wv=$d['viewability_pct'];$wp=$pos;}
    }

    arsort($first_sigs); arsort($last_sections_exit); arsort($last_interact_types);
    arsort($aff_networks); arsort($ad_clk_pos); arsort($ad_clk_size); arsort($urls);

    return new WP_REST_Response([
        'generated' => current_time('mysql'),
        'tracker_version' => 3,
        'data_key' => plt_get_key(),
        'period_days' => $days,

        'overview' => [
            'total_sessions' => $total,
            'returning_visitors' => $returning,
            'returning_pct' => $total>0?round(($returning/$total)*100,1):0,
            'avg_time_on_page_s' => round($avg($times)/1000,1),
            'median_time_on_page_s' => round($med($times)/1000,1),
            'avg_scroll_depth_pct' => round($avg($depths),1),
            'median_scroll_depth_pct' => round($med($depths),1),
            'avg_content_completion_pct' => count($completions)?round($avg($completions),1):null,
            'avg_scroll_speed_px_s' => round($avg($speeds)),
            'max_scroll_speed_px_s' => !empty($max_speeds)?round(max($max_speeds)):0,
            'avg_direction_changes' => round($avg($dir_changes),1),
            'avg_quality_score' => count($quality_scores)?round($avg($quality_scores),1):null,
            'avg_active_time_s' => count($active_t)?round($avg($active_t)/1000,1):null,
        ],

        'devices' => $devices,
        'scroll_patterns' => $patterns,
        'referrers' => $referrers,
        'top_pages' => array_slice($urls,0,20,true),

        'heatmaps' => [
            'read_depth' => $read_hm,
            'scroll_speed_by_zone' => $speed_hm,
            'attention_pauses' => $att_hm,
        ],

        'sections' => $section_stats,

        'exit_intent' => [
            'sessions_with_exit_signals' => $exit_trig,
            'exit_rate_pct' => $total>0?round(($exit_trig/$total)*100,1):0,
            'engaged_exits_pct' => $exit_trig>0?round(($exit_engaged/$exit_trig)*100,1):0,
            'signal_counts' => $exit_sigs,
            'most_common_first_signal' => $first_sigs ?: null,
            'avg_depth_at_exit_pct' => count($exit_depths)?round($avg($exit_depths),1):null,
            'avg_time_to_first_signal_s' => count($exit_times)?round($avg($exit_times)/1000,1):null,
            'last_sections_before_exit' => array_slice($last_sections_exit,0,10,true),
            'last_interaction_before_exit' => array_slice($last_interact_types,0,10,true),
        ],

        'touch_behavior' => [
            'touch_sessions' => $touch_sess,
            'avg_taps' => $touch_sess>0?round($taps/$touch_sess,1):0,
            'avg_swipes_up' => $touch_sess>0?round($sw_u/$touch_sess,1):0,
            'avg_swipes_down' => $touch_sess>0?round($sw_d/$touch_sess,1):0,
            'avg_swipe_velocity' => count($sw_vel)?round($avg($sw_vel)):0,
            'pinch_zooms' => $pinches,
            'double_taps' => $dtaps,
        ],

        'peak_engagement' => [
            'avg_best_depth_pct' => count($peak_depths)?round($avg($peak_depths),1):null,
            'avg_best_time_s' => count($peak_times)?round($avg($peak_times)/1000,1):null,
            'avg_engagement_score' => count($peak_scores)?round($avg($peak_scores),2):null,
            'most_common_slowest_zone' => count($slowest_zones)?round($avg($slowest_zones),1):null,
        ],

        'ad_overview' => [
            'total_impressions' => count($all_ads),
            'avg_per_session' => round($avg($ads_per),1),
            'overall_viewability_pct' => count($all_ads)>0
                ?round((array_sum(array_column($all_ads,'viewable_1s'))/count($all_ads))*100,1):0,
            'total_clicks' => $ad_clk,
            'overall_ctr_pct' => count($all_ads)>0?round(($ad_clk/count($all_ads))*100,2):0,
            'ad_re_engagements' => $ad_rengaged,
        ],
        'ad_by_position' => $abp,
        'ad_by_device' => [
            'mobile' => ['impressions'=>$abd['mobile']['t'],
                'viewability_pct'=>$abd['mobile']['t']>0?round(($abd['mobile']['v']/$abd['mobile']['t'])*100,1):0,
                'clicks'=>$abd['mobile']['c']],
            'desktop' => ['impressions'=>$abd['desktop']['t'],
                'viewability_pct'=>$abd['desktop']['t']>0?round(($abd['desktop']['v']/$abd['desktop']['t'])*100,1):0,
                'clicks'=>$abd['desktop']['c']],
        ],
        'ad_by_pattern' => [
            'reader'=>$abpat['reader']['t']>0?round(($abpat['reader']['v']/$abpat['reader']['t'])*100,1):0,
            'scanner'=>$abpat['scanner']['t']>0?round(($abpat['scanner']['v']/$abpat['scanner']['t'])*100,1):0,
            'bouncer'=>$abpat['bouncer']['t']>0?round(($abpat['bouncer']['v']/$abpat['bouncer']['t'])*100,1):0,
        ],
        'ad_clicks_by_position' => $ad_clk_pos,
        'ad_clicks_by_size' => $ad_clk_size,

        'link_clicks' => [
            'total' => count($all_links),
            'by_type' => $link_by_type,
            'affiliate_networks' => $aff_networks,
            'top_clicked_links' => $top_links,
        ],

        'cta_performance' => $cta_stats,

        'image_engagement' => [
            'total_images_tracked' => count($all_images),
            'total_taps' => $img_tapped,
            'total_zooms' => $img_zoomed,
            'avg_visible_ms' => count($all_images)>0?round($img_vis_total/count($all_images)):0,
        ],

        'optimization_hints' => [
            'best_ad_position' => $bp.' ('.$bv.'% viewable)',
            'worst_ad_position' => $wp.' ('.$wv.'% viewable)',
            'bouncer_rate' => $total>0?round(($patterns['bouncer']/$total)*100,1).'%':'0%',
            'reader_rate' => $total>0?round(($patterns['reader']/$total)*100,1).'%':'0%',
            'returning_rate' => $total>0?round(($returning/$total)*100,1).'%':'0%',
        ],

        'character_interaction' => [
            'sessions_with_taps' => $ci_sessions,
            'tap_rate_pct' => $total > 0 ? round(($ci_sessions / $total) * 100, 1) : 0,
            'total_char_taps' => $ci_char_taps,
            'total_heart_taps' => $ci_heart_taps,
            'avg_taps_per_tapper' => $ci_sessions > 0 ? round(($ci_char_taps + $ci_heart_taps) / $ci_sessions, 1) : 0,
            'avg_first_tap_s' => count($ci_first_tap_times) > 0 ? round($avg($ci_first_tap_times) / 1000, 1) : null,
            'avg_max_rapid_taps' => count($ci_rapid_max) > 0 ? round($avg($ci_rapid_max), 1) : 0,
            'taps_by_depth_zone' => (function() use ($ci_taps_by_depth) {
                $z = [];
                for ($i = 0; $i < 10; $i++) {
                    $z[($i*10).'-'.(($i+1)*10).'%'] = $ci_taps_by_depth[$i];
                }
                return $z;
            })(),
            'taps_by_animation' => $ci_taps_by_anim ?: new \stdClass(),
            'taps_by_target' => $ci_taps_by_target,
            'taps_during_speech_pct' => ($ci_char_taps + $ci_heart_taps) > 0
                ? round(($ci_taps_with_speech / ($ci_char_taps + $ci_heart_taps)) * 100, 1) : 0,
            'tappers_vs_nontappers' => [
                'tappers_avg_depth' => count($ci_tappers_depths) > 0 ? round($avg($ci_tappers_depths), 1) : null,
                'nontappers_avg_depth' => count($ci_nontappers_depths) > 0 ? round($avg($ci_nontappers_depths), 1) : null,
                'tappers_return_rate' => $ci_tappers_total > 0
                    ? round(($ci_tappers_return / $ci_tappers_total) * 100, 1) : 0,
                'nontappers_return_rate' => $ci_nontappers_total > 0
                    ? round(($ci_nontappers_return / $ci_nontappers_total) * 100, 1) : 0,
            ],
        ],

        'image_interaction' => [
            'sessions_with_taps' => $img_sessions,
            'tap_rate_pct' => $total > 0 ? round(($img_sessions / $total) * 100, 1) : 0,
            'total_taps' => $img_total_taps,
            'avg_taps_per_tapper' => $img_sessions > 0 ? round($img_total_taps / $img_sessions, 1) : 0,
            'taps_to_chat' => $img_to_chat,
            'taps_to_chat_rate' => $img_total_taps > 0 ? round(($img_to_chat / $img_total_taps) * 100, 1) : 0,
            'taps_by_depth_zone' => (function() use ($img_taps_by_depth) {
                $z = [];
                for ($i = 0; $i < 10; $i++) {
                    $z[($i*10).'-'.(($i+1)*10).'%'] = $img_taps_by_depth[$i];
                }
                return $z;
            })(),
            'taps_by_section' => !empty($img_taps_by_section) ? $img_taps_by_section : new \stdClass(),
        ],

        'pinterest' => [
            'sessions_with_saves' => $pin_sessions,
            'save_rate_pct' => $total > 0 ? round(($pin_sessions / $total) * 100, 1) : 0,
            'total_saves' => $pin_total_saves,
            'avg_saves_per_saver' => $pin_sessions > 0 ? round($pin_total_saves / $pin_sessions, 1) : 0,
            'top_pinned_images' => (function() use ($pin_images) {
                arsort($pin_images);
                return array_slice($pin_images, 0, 10, true);
            })(),
        ],
    ], 200);
}

function plt_get_raw($request) {
    $days = intval($request->get_param('days') ?: 1);
    $s = plt_load_sessions($days);
    return new WP_REST_Response(['total'=>count($s),'sessions'=>$s], 200);
}

function plt_load_sessions($days) {
    $upload_dir = wp_upload_dir();
    $base = $upload_dir['basedir'] . '/pl-tracker-data';
    $sessions = [];
    for ($i = 0; $i < $days; $i++) {
        $d = $base.'/'.date('Y-m-d', strtotime("-{$i} days"));
        if (!is_dir($d)) continue;
        foreach (glob($d.'/*.json') as $f) {
            $data = json_decode(file_get_contents($f), true);
            if ($data) $sessions[] = $data;
        }
    }
    return $sessions;
}

/**
 * Load visitor sessions within a specific timestamp range.
 */
function plt_get_sessions_in_range($start_ts, $end_ts) {
    $upload_dir = wp_upload_dir();
    $base = $upload_dir['basedir'] . '/pl-tracker-data';
    if (!is_dir($base)) return [];

    $sessions = [];
    $current = strtotime(date('Y-m-d', $start_ts));
    $end_day = strtotime(date('Y-m-d', $end_ts));

    while ($current <= $end_day) {
        $d = $base . '/' . date('Y-m-d', $current);
        if (is_dir($d)) {
            foreach (glob($d . '/*.json') as $f) {
                $data = json_decode(file_get_contents($f), true);
                if ($data) {
                    $ts = $data['unix'] ?? strtotime($data['ts'] ?? '');
                    if ($ts >= $start_ts && $ts <= $end_ts) {
                        $sessions[] = $data;
                    }
                }
            }
        }
        $current += DAY_IN_SECONDS;
    }
    return $sessions;
}

// ============================================
// ADMIN DASHBOARD
// ============================================
add_action('admin_menu', function() {
    add_menu_page(
        'Visitor Analytics',
        'Analytics',
        'manage_options',
        'pl-analytics',
        'plt_admin_dashboard',
        'dashicons-chart-area',
        27
    );
    add_submenu_page('pl-analytics', 'Dashboard', 'Dashboard', 'manage_options', 'pl-analytics', 'plt_admin_dashboard');
    add_submenu_page('pl-analytics', 'Sessions', 'Sessions', 'manage_options', 'pl-analytics-sessions', 'plt_admin_sessions');
    add_submenu_page('pl-analytics', 'Settings', 'Settings', 'manage_options', 'pl-analytics-settings', 'plt_admin_settings');
    add_submenu_page('pl-analytics', 'Full Analytics', "\xF0\x9F\x93\x8A Full Analytics", 'manage_options', 'pl-analytics-full', 'plt_full_analytics_page');
});

add_action('admin_enqueue_scripts', function($hook) {
    if (strpos($hook, 'pl-analytics-full') === false) return;
    wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js', [], '4.4', true);
});

function plt_admin_dashboard() {
    $days = intval($_GET['days'] ?? 7);
    if ($days < 1 || $days > 90) $days = 7;

    // Handle Pinterest data clear
    if (isset($_POST['pl_clear_pin_data']) && wp_verify_nonce($_POST['pl_pin_nonce'] ?? '', 'pl_clear_pin_data')) {
        $upload_dir = wp_upload_dir();
        $base = $upload_dir['basedir'] . '/pl-tracker-data';
        if (is_dir($base)) {
            foreach (glob($base . '/*/v3_*.json') as $file) {
                $session = json_decode(file_get_contents($file), true);
                if ($session && isset($session['pin_saves'])) {
                    unset($session['pin_saves']);
                    file_put_contents($file, json_encode($session, JSON_PRETTY_PRINT));
                }
            }
        }
        delete_transient('pl_post_engagement_scores');
        echo '<div class="notice notice-success is-dismissible"><p>Pinterest save data cleared.</p></div>';
    }

    $data = plt_get_report_data($days);

    // Count raw sessions for today
    $upload_dir = wp_upload_dir();
    $today_dir = $upload_dir['basedir'] . '/pl-tracker-data/' . date('Y-m-d');
    $today_count = is_dir($today_dir) ? count(glob($today_dir . '/*.json')) : 0;

    ?>
    <div class="wrap">
    <h1>Visitor Analytics</h1>

    <style>
    .plt-dash{font-family:-apple-system,BlinkMacSystemFont,sans-serif}
    .plt-nav{display:flex;gap:8px;margin:16px 0}
    .plt-nav a{padding:6px 14px;border-radius:6px;text-decoration:none;font-size:13px;font-weight:500;background:#f0f0f0;color:#333}
    .plt-nav a.active{background:#ec4899;color:#fff}
    .plt-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;margin:16px 0}
    .plt-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px;text-align:center}
    .plt-card h2{font-size:28px;margin:0 0 4px;color:#111}
    .plt-card p{font-size:12px;color:#888;margin:0}
    .plt-card.pink{border-left:4px solid #ec4899}
    .plt-card.green{border-left:4px solid #10b981}
    .plt-card.blue{border-left:4px solid #3b82f6}
    .plt-card.orange{border-left:4px solid #f59e0b}
    .plt-card.red{border-left:4px solid #ef4444}
    .plt-section{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:20px;margin:20px 0}
    .plt-section h3{margin:0 0 14px;font-size:16px;color:#111}
    .plt-2col{display:grid;grid-template-columns:1fr 1fr;gap:20px}
    .plt-3col{display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px}
    @media(max-width:782px){.plt-2col,.plt-3col{grid-template-columns:1fr}}
    .plt-bar-row{display:flex;align-items:center;margin:4px 0;font-size:13px}
    .plt-bar-label{width:80px;flex-shrink:0;color:#666}
    .plt-bar-track{flex:1;height:18px;background:#f3f4f6;border-radius:4px;overflow:hidden;margin:0 8px}
    .plt-bar-fill{height:100%;border-radius:4px;min-width:2px}
    .plt-bar-val{width:40px;text-align:right;color:#666;flex-shrink:0}
    .plt-tbl{width:100%;border-collapse:collapse;font-size:13px}
    .plt-tbl th{text-align:left;padding:8px;border-bottom:2px solid #e5e7eb;font-weight:600;color:#666;font-size:11px;text-transform:uppercase}
    .plt-tbl td{padding:8px;border-bottom:1px solid #f3f4f6}
    .plt-tbl tr:hover td{background:#fdf2f8}
    .plt-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600}
    .plt-empty{text-align:center;padding:40px;color:#999}
    </style>

    <div class="plt-dash">

    <div class="plt-nav">
    <?php foreach ([1=>'Today', 3=>'3 Days', 7=>'7 Days', 14=>'14 Days', 30=>'30 Days'] as $d => $label): ?>
        <a href="<?php echo admin_url('admin.php?page=pl-analytics&days='.$d); ?>" class="<?php echo $d === $days ? 'active' : ''; ?>"><?php echo $label; ?></a>
    <?php endforeach; ?>
    </div>

    <?php if (!$data): ?>
        <div class="plt-empty"><h2>No data yet</h2><p>Sessions will appear as visitors browse your posts.</p></div>
    <?php return; endif; ?>

    <!-- OVERVIEW -->
    <div class="plt-grid">
        <div class="plt-card pink"><h2><?php echo number_format($data['overview']['total_sessions']); ?></h2><p>Total Sessions</p></div>
        <div class="plt-card blue"><h2><?php echo $today_count; ?></h2><p>Today</p></div>
        <div class="plt-card green"><h2><?php echo $data['overview']['avg_scroll_depth_pct']; ?>%</h2><p>Avg Scroll Depth</p></div>
        <div class="plt-card"><h2><?php echo round($data['overview']['avg_time_on_page_s']); ?>s</h2><p>Avg Time on Page</p></div>
        <div class="plt-card orange"><h2><?php echo $data['optimization_hints']['returning_rate']; ?></h2><p>Returning Visitors</p></div>
        <div class="plt-card red"><h2><?php echo $data['optimization_hints']['bouncer_rate']; ?></h2><p>Bounce Rate</p></div>
        <div class="plt-card"><h2><?php echo $data['overview']['avg_quality_score']; ?></h2><p>Avg Quality Score</p></div>
        <div class="plt-card green"><h2><?php echo $data['overview']['avg_content_completion_pct']; ?>%</h2><p>Content Completion</p></div>
    </div>

    <!-- DEVICES & PATTERNS -->
    <div class="plt-2col">
        <div class="plt-section">
            <h3>Device Breakdown</h3>
            <?php $total_s = max(1, $data['overview']['total_sessions']);
            foreach ($data['devices'] as $dev => $count):
                $pct = round(($count / $total_s) * 100);
                $color = $dev === 'mobile' ? '#ec4899' : ($dev === 'desktop' ? '#3b82f6' : '#f59e0b');
            ?>
            <div class="plt-bar-row">
                <span class="plt-bar-label"><?php echo ucfirst($dev); ?></span>
                <div class="plt-bar-track"><div class="plt-bar-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $color; ?>"></div></div>
                <span class="plt-bar-val"><?php echo $count; ?> (<?php echo $pct; ?>%)</span>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="plt-section">
            <h3>Scroll Patterns</h3>
            <?php foreach ($data['scroll_patterns'] as $pat => $count):
                $pct = round(($count / $total_s) * 100);
                $color = $pat === 'reader' ? '#10b981' : ($pat === 'scanner' ? '#f59e0b' : '#ef4444');
            ?>
            <div class="plt-bar-row">
                <span class="plt-bar-label"><?php echo ucfirst($pat); ?></span>
                <div class="plt-bar-track"><div class="plt-bar-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $color; ?>"></div></div>
                <span class="plt-bar-val"><?php echo $count; ?> (<?php echo $pct; ?>%)</span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- SCROLL DEPTH HEATMAP -->
    <div class="plt-section">
        <h3>Scroll Depth Heatmap (Read Time by Zone)</h3>
        <?php
        $hm = $data['heatmaps']['read_depth'] ?? [];
        $max_ms = 1;
        foreach ($hm as $zone => $info) {
            if (($info['avg_time_ms'] ?? 0) > $max_ms) $max_ms = $info['avg_time_ms'];
        }
        foreach ($hm as $zone => $info):
            $ms = $info['avg_time_ms'] ?? 0;
            $pct = round(($ms / $max_ms) * 100);
        ?>
        <div class="plt-bar-row">
            <span class="plt-bar-label"><?php echo $zone; ?></span>
            <div class="plt-bar-track"><div class="plt-bar-fill" style="width:<?php echo max(3, $pct); ?>%;background:rgb(236,72,153,<?php echo max(0.15, $pct/100); ?>)"></div></div>
            <span class="plt-bar-val"><?php echo $ms > 1000 ? round($ms/1000,1).'s' : $ms.'ms'; ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- TOP PAGES -->
    <div class="plt-section">
        <h3>Top Pages</h3>
        <table class="plt-tbl">
        <tr><th>URL</th><th>Sessions</th></tr>
        <?php
        $pages = $data['top_pages'] ?? [];
        foreach (array_slice($pages, 0, 15, true) as $url => $count):
        ?>
        <tr>
            <td><a href="<?php echo esc_url(home_url($url)); ?>" target="_blank"><?php echo esc_html($url); ?></a></td>
            <td><strong><?php echo $count; ?></strong></td>
        </tr>
        <?php endforeach; ?>
        </table>
    </div>

    <!-- REFERRERS -->
    <div class="plt-section">
        <h3>Top Referrers</h3>
        <table class="plt-tbl">
        <tr><th>Source</th><th>Sessions</th></tr>
        <?php
        $refs = $data['referrers'] ?? [];
        arsort($refs);
        foreach (array_slice($refs, 0, 10, true) as $ref => $count):
        ?>
        <tr>
            <td><?php echo esc_html($ref ?: '(direct)'); ?></td>
            <td><strong><?php echo $count; ?></strong></td>
        </tr>
        <?php endforeach; ?>
        </table>
    </div>

    <!-- AD PERFORMANCE -->
    <div class="plt-2col">
        <div class="plt-section">
            <h3>Ad Overview</h3>
            <?php $ad = $data['ad_overview'] ?? []; ?>
            <div class="plt-grid" style="grid-template-columns:1fr 1fr 1fr">
                <div class="plt-card"><h2><?php echo $ad['total_impressions'] ?? 0; ?></h2><p>Impressions</p></div>
                <div class="plt-card green"><h2><?php echo $ad['overall_viewability_pct'] ?? 0; ?>%</h2><p>Viewability</p></div>
                <div class="plt-card pink"><h2><?php echo $ad['total_clicks'] ?? 0; ?></h2><p>Clicks</p></div>
            </div>
            <div style="margin-top:12px">
                <p style="font-size:13px;color:#666">Best position: <strong><?php echo $data['optimization_hints']['best_ad_position'] ?? 'N/A'; ?></strong></p>
                <p style="font-size:13px;color:#666">Worst position: <strong><?php echo $data['optimization_hints']['worst_ad_position'] ?? 'N/A'; ?></strong></p>
            </div>
        </div>

        <div class="plt-section">
            <h3>Ad Viewability by Position</h3>
            <?php $abp = $data['ad_by_position'] ?? [];
            foreach ($abp as $pos => $info):
                $v = $info['viewability_pct'] ?? 0;
                $color = $v >= 70 ? '#10b981' : ($v >= 40 ? '#f59e0b' : '#ef4444');
            ?>
            <div class="plt-bar-row">
                <span class="plt-bar-label"><?php echo $pos; ?></span>
                <div class="plt-bar-track"><div class="plt-bar-fill" style="width:<?php echo $v; ?>%;background:<?php echo $color; ?>"></div></div>
                <span class="plt-bar-val"><?php echo $v; ?>%</span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- LINK CLICKS -->
    <div class="plt-section">
        <h3>Link Clicks</h3>
        <?php $links = $data['link_clicks'] ?? []; ?>
        <div class="plt-grid" style="grid-template-columns:repeat(4,1fr)">
            <div class="plt-card"><h2><?php echo $links['total'] ?? 0; ?></h2><p>Total Clicks</p></div>
            <div class="plt-card blue"><h2><?php echo $links['by_type']['internal'] ?? 0; ?></h2><p>Internal</p></div>
            <div class="plt-card green"><h2><?php echo $links['by_type']['affiliate'] ?? 0; ?></h2><p>Affiliate</p></div>
            <div class="plt-card orange"><h2><?php echo $links['by_type']['external'] ?? 0; ?></h2><p>External</p></div>
        </div>
        <?php if (!empty($links['top_clicked_links'])): ?>
        <table class="plt-tbl" style="margin-top:12px">
        <tr><th>Link</th><th>Text</th><th>Clicks</th><th>Type</th></tr>
        <?php foreach (array_slice($links['top_clicked_links'], 0, 10, true) as $url => $lnk): ?>
        <tr>
            <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><a href="<?php echo esc_url($url); ?>" target="_blank"><?php echo esc_html(substr($url, 0, 50)); ?></a></td>
            <td><?php echo esc_html($lnk['text'] ?? ''); ?></td>
            <td><strong><?php echo $lnk['clicks']; ?></strong></td>
            <td><span class="plt-badge" style="background:<?php echo ($lnk['type']??'')==='affiliate'?'#d1fae5':'#e0e7ff'; ?>"><?php echo esc_html($lnk['type'] ?? ''); ?></span></td>
        </tr>
        <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </div>

    <!-- EXIT INTENT -->
    <div class="plt-section">
        <h3>Exit Intent</h3>
        <?php $exit = $data['exit_intent'] ?? []; ?>
        <div class="plt-grid" style="grid-template-columns:repeat(3,1fr)">
            <div class="plt-card"><h2><?php echo $exit['sessions_with_exit_signals'] ?? 0; ?></h2><p>Exit Signals Triggered</p></div>
            <div class="plt-card"><h2><?php echo $exit['avg_depth_at_exit_pct'] ?? 0; ?>%</h2><p>Avg Exit Depth</p></div>
            <div class="plt-card green"><h2><?php echo $exit['engaged_exits_pct'] ?? 0; ?>%</h2><p>Were Engaged</p></div>
        </div>
        <?php if (!empty($exit['signal_counts'])): ?>
        <div style="margin-top:12px">
            <h4 style="font-size:13px;color:#666">Exit Signals</h4>
            <?php $max_sig = max(1, max(array_values($exit['signal_counts'])));
            foreach ($exit['signal_counts'] as $sig => $count):
                $pct = round(($count / $max_sig) * 100);
            ?>
            <div class="plt-bar-row">
                <span class="plt-bar-label" style="width:200px"><?php echo str_replace('_', ' ', ucfirst($sig)); ?></span>
                <div class="plt-bar-track"><div class="plt-bar-fill" style="width:<?php echo $pct; ?>%;background:#ef4444"></div></div>
                <span class="plt-bar-val"><?php echo $count; ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- CHARACTER & IMAGE INTERACTION -->
    <?php $ci = $data['character_interaction'] ?? []; ?>
    <?php if (($ci['sessions_with_taps'] ?? 0) > 0 || ($data['image_interaction']['sessions_with_taps'] ?? 0) > 0): ?>
    <div class="plt-2col">
        <div class="plt-section">
            <h3>Character &amp; Heart Interactions</h3>
            <div class="plt-grid" style="grid-template-columns:1fr 1fr">
                <div class="plt-card pink"><h2><?php echo $ci['sessions_with_taps'] ?? 0; ?></h2><p>Sessions with Taps</p></div>
                <div class="plt-card"><h2><?php echo $ci['tap_rate_pct'] ?? 0; ?>%</h2><p>Tap Rate</p></div>
                <div class="plt-card"><h2><?php echo $ci['total_char_taps'] ?? 0; ?></h2><p>Character Taps</p></div>
                <div class="plt-card"><h2><?php echo $ci['total_heart_taps'] ?? 0; ?></h2><p>Heart Taps</p></div>
            </div>
            <?php $tvn = $ci['tappers_vs_nontappers'] ?? [];
            if (!empty($tvn['tappers_avg_depth'])): ?>
            <div style="margin-top:12px;font-size:13px;color:#666">
                <p>Tappers avg depth: <strong><?php echo $tvn['tappers_avg_depth']; ?>%</strong> vs Non-tappers: <strong><?php echo $tvn['nontappers_avg_depth']; ?>%</strong></p>
                <p>Tappers return rate: <strong><?php echo $tvn['tappers_return_rate']; ?>%</strong> vs Non-tappers: <strong><?php echo $tvn['nontappers_return_rate']; ?>%</strong></p>
            </div>
            <?php endif; ?>
        </div>

        <!-- IMAGE INTERACTION -->
        <div class="plt-section">
            <h3>Post Image Interactions</h3>
            <?php $ii = $data['image_interaction'] ?? []; ?>
            <div class="plt-grid" style="grid-template-columns:1fr 1fr">
                <div class="plt-card pink"><h2><?php echo $ii['sessions_with_taps'] ?? 0; ?></h2><p>Sessions with Image Taps</p></div>
                <div class="plt-card"><h2><?php echo $ii['tap_rate_pct'] ?? 0; ?>%</h2><p>Image Tap Rate</p></div>
                <div class="plt-card"><h2><?php echo $ii['total_taps'] ?? 0; ?></h2><p>Total Image Taps</p></div>
                <div class="plt-card green"><h2><?php echo $ii['taps_to_chat'] ?? 0; ?></h2><p>Image to Chat</p></div>
            </div>
            <?php $by_sec = (array)($ii['taps_by_section'] ?? []);
            if (!empty($by_sec)): arsort($by_sec); ?>
            <div style="margin-top:12px">
                <h4 style="font-size:13px;color:#666">Most Tapped Sections</h4>
                <?php foreach (array_slice($by_sec, 0, 5, true) as $sec => $count): ?>
                <div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid #f3f4f6;font-size:13px">
                    <span><?php echo esc_html(mb_substr($sec, 0, 50)); ?></span>
                    <strong style="color:#ec4899"><?php echo $count; ?></strong>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- SECTION TIMING -->
    <?php if (!empty($data['sections'])): ?>
    <div class="plt-section">
        <h3>Section Timing (H2/H3 Headings)</h3>
        <div style="max-height:400px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:8px">
        <table class="plt-tbl" style="margin:0">
        <thead style="position:sticky;top:0;background:#f9fafb;z-index:1">
        <tr><th>Section</th><th>View Rate</th><th>Avg Read Time</th><th>Scroll Speed</th></tr>
        </thead>
        <tbody>
        <?php foreach ($data['sections'] as $heading => $sec): ?>
        <tr>
            <td><?php echo esc_html($heading); ?></td>
            <td><?php echo $sec['view_rate_pct'] ?? 0; ?>%</td>
            <td><?php echo round(($sec['avg_visible_ms'] ?? 0) / 1000, 1); ?>s</td>
            <td><?php echo $sec['avg_scroll_speed'] ?? 0; ?> px/s</td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- TOUCH BEHAVIOR -->
    <?php $touch = $data['touch_behavior'] ?? []; ?>
    <?php if (($touch['touch_sessions'] ?? 0) > 0): ?>
    <div class="plt-section">
        <h3>Touch Behavior</h3>
        <div class="plt-grid" style="grid-template-columns:repeat(4,1fr)">
            <div class="plt-card"><h2><?php echo $touch['touch_sessions']; ?></h2><p>Touch Sessions</p></div>
            <div class="plt-card"><h2><?php echo $touch['avg_taps']; ?></h2><p>Avg Taps</p></div>
            <div class="plt-card"><h2><?php echo $touch['pinch_zooms']; ?></h2><p>Pinch Zooms</p></div>
            <div class="plt-card"><h2><?php echo $touch['avg_swipe_velocity']; ?></h2><p>Avg Swipe Speed</p></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- PEAK ENGAGEMENT -->
    <div class="plt-section">
        <h3>Peak Engagement</h3>
        <?php $pe = $data['peak_engagement'] ?? []; ?>
        <div class="plt-grid" style="grid-template-columns:repeat(4,1fr)">
            <div class="plt-card green"><h2><?php echo $pe['avg_best_depth_pct'] ?? 'N/A'; ?>%</h2><p>Best Depth</p></div>
            <div class="plt-card"><h2><?php echo $pe['avg_best_time_s'] ?? 'N/A'; ?>s</h2><p>Best Time</p></div>
            <div class="plt-card"><h2><?php echo $pe['avg_engagement_score'] ?? 'N/A'; ?></h2><p>Engagement Score</p></div>
            <div class="plt-card orange"><h2><?php echo $pe['most_common_slowest_zone'] ?? 'N/A'; ?>%</h2><p>Slowest Zone</p></div>
        </div>
    </div>

    <!-- PINTEREST -->
    <?php $pin = $data['pinterest'] ?? []; ?>
    <div class="plt-section">
        <h3>Pinterest Performance</h3>
        <div class="plt-grid" style="grid-template-columns:repeat(4,1fr)">
            <div class="plt-card"><h2><?php echo intval($pin['sessions_with_saves'] ?? 0); ?></h2><p>Sessions with Saves</p></div>
            <div class="plt-card"><h2><?php echo floatval($pin['save_rate_pct'] ?? 0); ?>%</h2><p>Save Rate</p></div>
            <div class="plt-card green"><h2><?php echo intval($pin['total_saves'] ?? 0); ?></h2><p>Total Saves</p></div>
            <div class="plt-card"><h2><?php echo floatval($pin['avg_saves_per_saver'] ?? 0); ?></h2><p>Avg Saves/Saver</p></div>
        </div>
        <?php $top_pins = $pin['top_pinned_images'] ?? []; if (!empty($top_pins)) : ?>
        <h4 style="margin-top:16px">Top Pinned Images</h4>
        <table class="plt-table"><tr><th>Image</th><th>Saves</th></tr>
        <?php foreach (array_slice($top_pins, 0, 5, true) as $img_url => $count) : ?>
            <tr><td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><a href="<?php echo esc_url($img_url); ?>" target="_blank"><?php echo esc_html(basename($img_url)); ?></a></td><td><?php echo intval($count); ?></td></tr>
        <?php endforeach; ?>
        </table>
        <?php endif; ?>
        <form method="post" style="margin-top:12px">
            <?php wp_nonce_field('pl_clear_pin_data', 'pl_pin_nonce'); ?>
            <button type="submit" name="pl_clear_pin_data" class="button" onclick="return confirm('Clear all Pinterest save data? This cannot be undone.')">&#x1F5D1;&#xFE0F; Clear Pinterest Data</button>
        </form>
    </div>

    <!-- API ENDPOINT -->
    <div class="plt-section" style="background:#f9fafb">
        <h3>API Access</h3>
        <p style="font-size:13px;color:#666">Report endpoint: <code><?php echo esc_html(rest_url('pl-tracker/v1/report') . '?key=' . plt_get_key() . '&days=' . $days); ?></code></p>
        <p style="font-size:13px;color:#666">Raw data: <code><?php echo esc_html(rest_url('pl-tracker/v1/raw') . '?key=' . plt_get_key() . '&days=1'); ?></code></p>
    </div>

    </div><!-- .plt-dash -->
    </div><!-- .wrap -->
    <?php
}

function plt_admin_sessions() {
    $days = intval($_GET['days'] ?? 1);
    $sessions = plt_load_sessions($days);
    $sessions = array_reverse($sessions); // newest first
    ?>
    <div class="wrap">
    <h1>Recent Sessions</h1>

    <style>
    .plt-tbl{width:100%;border-collapse:collapse;font-size:13px;background:#fff}
    .plt-tbl th{text-align:left;padding:10px;border-bottom:2px solid #e5e7eb;font-weight:600;color:#666;font-size:11px;text-transform:uppercase}
    .plt-tbl td{padding:8px 10px;border-bottom:1px solid #f3f4f6}
    .plt-tbl tr:hover td{background:#fdf2f8}
    .plt-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600}
    .plt-nav{display:flex;gap:8px;margin:16px 0}
    .plt-nav a{padding:6px 14px;border-radius:6px;text-decoration:none;font-size:13px;font-weight:500;background:#f0f0f0;color:#333}
    .plt-nav a.active{background:#ec4899;color:#fff}
    </style>

    <div class="plt-nav">
    <?php foreach ([1=>'Today', 3=>'3 Days', 7=>'7 Days'] as $d => $label): ?>
        <a href="<?php echo admin_url('admin.php?page=pl-analytics-sessions&days='.$d); ?>" class="<?php echo $d === $days ? 'active' : ''; ?>"><?php echo $label; ?></a>
    <?php endforeach; ?>
    </div>

    <p><?php echo count($sessions); ?> sessions</p>

    <table class="plt-tbl">
    <tr><th>Time</th><th>URL</th><th>Device</th><th>Pattern</th><th>Depth</th><th>Time</th><th>Quality</th><th>Referrer</th><th>Char Taps</th><th>Img Taps</th></tr>
    <?php foreach (array_slice($sessions, 0, 200) as $s):
        $ci_t = ($s['char_interaction']['char_taps'] ?? 0) + ($s['char_interaction']['heart_taps'] ?? 0);
        $ii_t = $s['image_interaction']['image_taps'] ?? 0;
    ?>
    <tr>
        <td><?php echo esc_html($s['ts'] ?? ''); ?></td>
        <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo esc_html($s['url'] ?? ''); ?></td>
        <td><span class="plt-badge" style="background:<?php echo ($s['device']??'')==='mobile'?'#fce7f3':'#e0e7ff'; ?>"><?php echo esc_html($s['device'] ?? ''); ?></span></td>
        <td><span class="plt-badge" style="background:<?php echo ($s['scroll_pattern']??'')==='reader'?'#d1fae5':(($s['scroll_pattern']??'')==='bouncer'?'#fee2e2':'#fef3c7'); ?>"><?php echo esc_html($s['scroll_pattern'] ?? ''); ?></span></td>
        <td><strong><?php echo $s['max_depth_pct'] ?? 0; ?>%</strong></td>
        <td><?php echo round(($s['time_on_page_ms'] ?? 0) / 1000); ?>s</td>
        <td><?php echo $s['quality_score'] ?? 0; ?></td>
        <td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo esc_html($s['referrer'] ?? '(direct)'); ?></td>
        <td><?php echo $ci_t > 0 ? '<strong style="color:#ec4899">'.$ci_t.'</strong>' : '-'; ?></td>
        <td><?php echo $ii_t > 0 ? '<strong style="color:#ec4899">'.$ii_t.'</strong>' : '-'; ?></td>
    </tr>
    <?php endforeach; ?>
    </table>
    </div>
    <?php
}

function plt_admin_settings() {
    // Handle GA4 test event
    if (isset($_POST['pl_test_ga4']) && wp_verify_nonce($_POST['pl_ga4_nonce'] ?? '', 'pl_test_ga4')) {
        $measurement_id = get_theme_mod('pl_ga4_measurement_id', '');
        $api_secret = get_theme_mod('pl_ga4_api_secret', '');

        if (empty($measurement_id) || empty($api_secret)) {
            echo '<div class="notice notice-error"><p>GA4 Measurement ID and API Secret must be set in Appearance &rarr; Customize &rarr; Integrations</p></div>';
        } else {
            $debug_url = 'https://www.google-analytics.com/debug/mp/collect'
                . '?measurement_id=' . urlencode($measurement_id)
                . '&api_secret=' . urlencode($api_secret);

            $response = wp_remote_post($debug_url, [
                'body' => json_encode([
                    'client_id' => 'test_' . time(),
                    'events' => [['name' => 'pl_test', 'params' => ['test' => true]]],
                ]),
                'headers' => ['Content-Type' => 'application/json'],
                'timeout' => 5,
            ]);

            if (is_wp_error($response)) {
                echo '<div class="notice notice-error"><p>Network error: ' . esc_html($response->get_error_message()) . '</p></div>';
            } else {
                $body = wp_remote_retrieve_body($response);
                $data = json_decode($body, true);
                $has_errors = !empty($data['validationMessages']);

                if (!$has_errors) {
                    pl_send_to_ga4([
                        'visitor_id' => 'test_' . time(),
                        'url' => home_url('/'),
                        'referrer' => 'direct',
                        'device' => 'desktop',
                        'max_depth_pct' => 75,
                        'engagement' => ['active_time_ms' => 60000],
                        'time_on_page_ms' => 90000,
                        'quality_score' => 80,
                        'scroll_pattern' => 'reader',
                        'country' => 'Test Country',
                        'city' => 'Test City',
                    ]);
                    echo '<div class="notice notice-success"><p>GA4 connection verified! Test event sent. Check GA4 Realtime report in 1-2 minutes.</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>GA4 validation failed: ' . esc_html($body) . '</p></div>';
                }
            }
        }
    }

    $key = plt_get_key();
    $upload_dir = wp_upload_dir();
    $data_dir = $upload_dir['basedir'] . '/pl-tracker-data';
    $total_sessions = 0;
    $total_size = 0;
    $days_with_data = 0;

    if (is_dir($data_dir)) {
        $day_dirs = glob($data_dir . '/????-??-??');
        $days_with_data = count($day_dirs);
        foreach ($day_dirs as $dd) {
            $files = glob($dd . '/*.json');
            $total_sessions += count($files);
            foreach ($files as $f) $total_size += filesize($f);
        }
    }

    // Handle purge
    if (isset($_POST['plt_purge']) && wp_verify_nonce($_POST['_wpnonce'], 'plt_purge')) {
        $purge_days = intval($_POST['purge_days']);
        $delete_all = ($purge_days === 0);
        $cutoff = $delete_all ? '9999-99-99' : date('Y-m-d', strtotime("-{$purge_days} days"));
        $deleted = 0;
        if (is_dir($data_dir)) {
            foreach (glob($data_dir . '/????-??-??') as $dd) {
                $dir_date = basename($dd);
                if ($delete_all || $dir_date < $cutoff) {
                    $files = glob($dd . '/*.json');
                    foreach ($files as $f) { unlink($f); $deleted++; }
                    rmdir($dd);
                }
            }
        }
        $msg = $delete_all ? 'Deleted ALL ' . $deleted . ' sessions.' : 'Purged ' . $deleted . ' sessions older than ' . $purge_days . ' days.';
        echo '<div class="notice notice-success"><p>' . $msg . '</p></div>';
        $total_sessions -= $deleted;
    }
    ?>
    <div class="wrap">
    <h1>Tracker Settings</h1>

    <style>
    .plt-settings-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:20px;margin:16px 0}
    .plt-settings-card h3{margin:0 0 12px}
    .plt-settings-card p{font-size:13px;color:#666;margin:4px 0}
    .plt-settings-card code{background:#f3f4f6;padding:2px 6px;border-radius:4px;font-size:12px}
    </style>

    <div class="plt-settings-card">
        <h3>Data Storage</h3>
        <p>Total sessions: <strong><?php echo number_format($total_sessions); ?></strong></p>
        <p>Days with data: <strong><?php echo $days_with_data; ?></strong></p>
        <p>Storage used: <strong><?php echo round($total_size / 1024 / 1024, 2); ?> MB</strong></p>
        <p>Data directory: <code><?php echo esc_html($data_dir); ?></code></p>
    </div>

    <div class="plt-settings-card">
        <h3>API Endpoints</h3>
        <p>Report: <code><?php echo esc_html(rest_url('pl-tracker/v1/report') . '?key=' . $key); ?></code></p>
        <p>Raw: <code><?php echo esc_html(rest_url('pl-tracker/v1/raw') . '?key=' . $key . '&days=1'); ?></code></p>
        <p>Collect: <code><?php echo esc_html(rest_url('pl-tracker/v1/collect')); ?></code> (POST)</p>
        <p>API Key: <code><?php echo esc_html($key); ?></code></p>
    </div>

    <div class="plt-settings-card">
        <h3>Purge Old Data</h3>
        <form method="post">
            <?php wp_nonce_field('plt_purge'); ?>
            <p>Delete sessions older than
            <select name="purge_days" style="padding:4px 8px">
                <option value="30">30 days</option>
                <option value="60">60 days</option>
                <option value="90">90 days</option>
                <option value="7">7 days</option>
                <option value="0" style="color:red">ALL data</option>
            </select>
            <button type="submit" name="plt_purge" class="button button-secondary" onclick="return confirm(this.form.purge_days.value==='0' ? 'DELETE ALL SESSION DATA? This cannot be undone!' : 'Delete old session data?')">Purge</button>
            </p>
        </form>
    </div>

    <div class="plt-settings-card" style="border-left:4px solid #f59e0b">
        <h3>Migration Note</h3>
        <p>This tracker is now embedded in the PinLightning theme. If you still have the <strong>PinLightning Visitor Tracker</strong> plugin active, you should <strong>deactivate it</strong> to avoid duplicate tracking.</p>
    </div>

    <div class="plt-settings-card">
        <h3>GA4 Integration</h3>
        <?php
        $ga4_id = get_theme_mod('pl_ga4_measurement_id', '');
        $ga4_secret = get_theme_mod('pl_ga4_api_secret', '');
        $ga4_on = get_theme_mod('pl_ga4_enabled', false);
        ?>
        <p>Status: <?php echo $ga4_on && $ga4_id && $ga4_secret
            ? '<strong style="color:#059669">Active</strong> &mdash; ' . esc_html($ga4_id)
            : '<strong style="color:#888">Not configured</strong> &mdash; Set up in Appearance &rarr; Customize &rarr; Integrations'; ?></p>
        <form method="post" style="margin-top:8px">
            <?php wp_nonce_field('pl_test_ga4', 'pl_ga4_nonce'); ?>
            <button type="submit" name="pl_test_ga4" class="button" <?php echo (!$ga4_id || !$ga4_secret) ? 'disabled' : ''; ?>>&#x1F9EA; Send Test Event to GA4</button>
        </form>
    </div>

    </div>
    <?php
}

// ============================================
// FULL ANALYTICS DASHBOARD
// ============================================

function plt_full_analytics_page() {
    // ─── PERIOD HANDLING ───
    $period = sanitize_text_field($_GET['period'] ?? '7d');
    $custom_from = sanitize_text_field($_GET['from'] ?? '');
    $custom_to = sanitize_text_field($_GET['to'] ?? '');

    $now = current_time('timestamp');
    switch ($period) {
        case 'today':   $start = strtotime('today', $now); break;
        case '7d':      $start = $now - 7 * DAY_IN_SECONDS; break;
        case '14d':     $start = $now - 14 * DAY_IN_SECONDS; break;
        case '30d':     $start = $now - 30 * DAY_IN_SECONDS; break;
        case '90d':     $start = $now - 90 * DAY_IN_SECONDS; break;
        case 'custom':
            $start = $custom_from ? strtotime($custom_from) : $now - 7 * DAY_IN_SECONDS;
            $now = $custom_to ? strtotime($custom_to . ' 23:59:59') : $now;
            break;
        default:        $start = $now - 7 * DAY_IN_SECONDS;
    }

    // ─── LOAD SESSION DATA ───
    $sessions = plt_get_sessions_in_range($start, $now);
    $total = count($sessions);

    // ─── AGGREGATE ALL METRICS ───
    $daily_sessions = [];
    $hourly_sessions = array_fill(0, 24, 0);
    $dow_sessions = array_fill(0, 7, 0);
    $devices = ['desktop' => 0, 'mobile' => 0, 'tablet' => 0];
    $referrers = [];
    $referrer_types = ['pinterest' => 0, 'direct' => 0, 'search' => 0, 'social' => 0, 'other' => 0];
    $page_views = [];
    $scroll_depths = [];
    $quality_scores = [];
    $active_times = [];
    $time_on_page = [];
    $patterns = ['reader' => 0, 'scanner' => 0, 'bouncer' => 0];
    $visitor_session_count = [];
    $daily_unique = [];
    $countries = [];
    $cities = [];
    $depth_buckets = array_fill(0, 10, 0);
    $realtime_cutoff = time() - 300;
    $realtime_count = 0;
    $total_pin_saves = 0;
    $pin_sessions = 0;
    $exit_depths = [];
    $viewport_widths = [];
    $completions = [];

    // ─── PROCESS EACH SESSION ───
    foreach ($sessions as $s) {
        $ts = $s['unix'] ?? strtotime($s['ts'] ?? '');
        if (!$ts) continue;

        $date = date('Y-m-d', $ts);
        $hour = (int)date('G', $ts);
        $dow = (int)date('w', $ts);

        $daily_sessions[$date] = ($daily_sessions[$date] ?? 0) + 1;
        $hourly_sessions[$hour]++;
        $dow_sessions[$dow]++;

        // Visitor tracking
        $vid = $s['visitor_id'] ?? '';
        if (!$vid) {
            // Fallback for old sessions without visitor_id: generate pseudo-fingerprint
            $vid = 'pf_' . md5(($s['device'] ?? '') . ($s['vw'] ?? '') . ($s['timezone'] ?? '') . ($s['language'] ?? '') . ($s['country'] ?? ''));
        }
        $visitor_session_count[$vid] = ($visitor_session_count[$vid] ?? 0) + 1;
        $daily_unique[$date][$vid] = true;

        // Country / City
        $country = $s['country'] ?? '';
        if ($country) $countries[$country] = ($countries[$country] ?? 0) + 1;
        $city = $s['city'] ?? '';
        if ($city) $cities[$city] = ($cities[$city] ?? 0) + 1;

        $device = strtolower($s['device'] ?? 'desktop');
        if (isset($devices[$device])) $devices[$device]++;

        $ref = $s['referrer'] ?? '';
        $ref_display = $ref ?: '(direct)';
        $referrers[$ref_display] = ($referrers[$ref_display] ?? 0) + 1;
        $ref_lower = strtolower($ref);
        if (strpos($ref_lower, 'pinterest') !== false) $referrer_types['pinterest']++;
        elseif (empty($ref)) $referrer_types['direct']++;
        elseif (preg_match('/google|bing|yahoo|duckduckgo|ecosia|yandex/', $ref_lower)) $referrer_types['search']++;
        elseif (preg_match('/facebook|twitter|instagram|tiktok|reddit|linkedin/', $ref_lower)) $referrer_types['social']++;
        else $referrer_types['other']++;

        $page_url = $s['url'] ?? '';
        if ($page_url) $page_views[$page_url] = ($page_views[$page_url] ?? 0) + 1;

        $depth = floatval($s['max_depth_pct'] ?? 0);
        $scroll_depths[] = $depth;
        $bucket = min(9, (int)floor($depth / 10));
        $depth_buckets[$bucket]++;

        $quality_scores[] = floatval($s['quality_score'] ?? 0);
        $active_times[] = floatval(($s['engagement']['active_time_ms'] ?? 0)) / 1000;
        $time_on_page[] = floatval(($s['time_on_page_ms'] ?? 0)) / 1000;
        $completions[] = floatval($s['content_completion_pct'] ?? 0);

        $pattern = strtolower($s['scroll_pattern'] ?? 'scanner');
        if (isset($patterns[$pattern])) $patterns[$pattern]++;

        if ($ts >= $realtime_cutoff) $realtime_count++;

        $pin_s = $s['pin_saves']['saves'] ?? 0;
        if ($pin_s > 0) { $total_pin_saves += intval($pin_s); $pin_sessions++; }

        $exit_depths[] = floatval($s['exit_intent']['depth_at_exit'] ?? $depth);

        $vw = intval($s['vw'] ?? 0);
        if ($vw > 0) {
            if ($vw < 480) $vw_label = '<480';
            elseif ($vw < 768) $vw_label = '480-767';
            elseif ($vw < 1024) $vw_label = '768-1023';
            elseif ($vw < 1440) $vw_label = '1024-1439';
            else $vw_label = '1440+';
            $viewport_widths[$vw_label] = ($viewport_widths[$vw_label] ?? 0) + 1;
        }
    }

    // ─── COMPUTE SUMMARY METRICS ───
    $avg = function($a) { return count($a) ? array_sum($a) / count($a) : 0; };
    $med = function($a) { if (!count($a)) return 0; sort($a); $m = (int)floor(count($a)/2); return count($a) % 2 ? round($a[$m],1) : round(($a[$m-1]+$a[$m])/2,1); };

    $avg_depth = $total > 0 ? round($avg($scroll_depths), 1) : 0;
    $median_depth = $med($scroll_depths);
    $avg_quality = $total > 0 ? round($avg($quality_scores), 1) : 0;
    $avg_active = $total > 0 ? round($avg($active_times), 1) : 0;
    $avg_time = $total > 0 ? round($avg($time_on_page), 1) : 0;
    $bounce_rate = $total > 0 ? round($patterns['bouncer'] / $total * 100, 1) : 0;
    $pin_rate = $total > 0 ? round($pin_sessions / $total * 100, 1) : 0;

    // Unique visitor metrics
    $unique_visitors = count($visitor_session_count);
    $new_visitors = 0;
    $returning_visitors = 0;
    foreach ($visitor_session_count as $vid => $count) {
        if ($count > 1) $returning_visitors++;
        else $new_visitors++;
    }
    $returning_pct = $unique_visitors > 0 ? round($returning_visitors / $unique_visitors * 100, 1) : 0;
    $sessions_per_visitor = $unique_visitors > 0 ? round($total / $unique_visitors, 1) : 0;

    // Chart data for unique visitors per day
    $chart_unique = [];

    arsort($page_views);
    arsort($referrers);
    arsort($countries);
    arsort($cities);
    ksort($daily_sessions);
    $chart_dates = array_keys($daily_sessions);
    $chart_counts = array_values($daily_sessions);
    foreach ($chart_dates as $d) {
        $chart_unique[] = count($daily_unique[$d] ?? []);
    }

    // ─── RENDER PAGE ───
    ?>
    <div class="wrap" style="max-width:1400px">
        <style>
            .pla-grid{display:grid;gap:16px;margin-bottom:24px}
            .pla-grid-4{grid-template-columns:repeat(4,1fr)}
            .pla-grid-3{grid-template-columns:repeat(3,1fr)}
            .pla-grid-2{grid-template-columns:repeat(2,1fr)}
            @media(max-width:1200px){.pla-grid-4{grid-template-columns:repeat(2,1fr)}}
            @media(max-width:768px){.pla-grid-4,.pla-grid-3,.pla-grid-2{grid-template-columns:1fr}}

            .pla-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,.04)}
            .pla-card h3{margin:0 0 14px;font-size:15px;font-weight:600;color:#111;display:flex;align-items:center;gap:8px}

            .pla-stat{text-align:center;padding:16px}
            .pla-stat-value{font-size:28px;font-weight:700;color:#111;line-height:1.2}
            .pla-stat-label{font-size:12px;color:#888;margin-top:4px;text-transform:uppercase;letter-spacing:.5px}
            .pla-stat-sub{font-size:11px;color:#aaa;margin-top:2px}

            .pla-realtime{background:linear-gradient(135deg,#059669,#10b981);color:#fff;border:none}
            .pla-realtime .pla-stat-value{color:#fff;font-size:42px}
            .pla-realtime .pla-stat-label{color:rgba(255,255,255,.8)}
            .pla-pulse{display:inline-block;width:10px;height:10px;background:#fff;border-radius:50%;animation:plaPulse 1.5s infinite}
            @keyframes plaPulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(1.3)}}

            .pla-period{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:20px;align-items:center}
            .pla-period a,.pla-period button{padding:7px 16px;border-radius:8px;font-size:13px;font-weight:500;text-decoration:none;border:1px solid #ddd;background:#fff;color:#555;cursor:pointer;transition:all .15s}
            .pla-period a:hover,.pla-period button:hover{border-color:#999}
            .pla-period a.active,.pla-period button.active{background:#111;color:#fff;border-color:#111}

            .pla-table{width:100%;border-collapse:collapse;font-size:13px}
            .pla-table th{text-align:left;padding:8px 10px;border-bottom:2px solid #e5e7eb;font-weight:600;color:#555;font-size:11px;text-transform:uppercase;letter-spacing:.5px;position:sticky;top:0;background:#fff;z-index:1}
            .pla-table td{padding:8px 10px;border-bottom:1px solid #f3f4f6;color:#333}
            .pla-table tr:hover td{background:#f9fafb}

            .pla-scroll{max-height:350px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:8px}
            .pla-scroll .pla-table th{background:#f9fafb}

            .pla-chart-wrap{position:relative;height:250px;width:100%}
            .pla-chart-tall{height:300px}

            .pla-badge{display:inline-block;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600}
        </style>

        <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:6px">
            &#x1F4CA; Full Analytics
            <span style="font-size:13px;color:#888;font-weight:400">
                <?php echo number_format($total); ?> sessions
                <?php if ($period !== 'custom') : ?>
                    &middot; <?php echo $period === 'today' ? 'Today' : 'Last ' . esc_html($period); ?>
                <?php else : ?>
                    &middot; <?php echo esc_html($custom_from); ?> to <?php echo esc_html($custom_to); ?>
                <?php endif; ?>
            </span>
        </h1>

        <!-- PERIOD SELECTOR -->
        <div class="pla-period">
            <?php
            $base_url = admin_url('admin.php?page=pl-analytics-full');
            $periods = ['today' => 'Today', '7d' => '7 Days', '14d' => '14 Days', '30d' => '30 Days', '90d' => '90 Days'];
            foreach ($periods as $key => $label) :
            ?>
            <a href="<?php echo esc_url($base_url . '&period=' . $key); ?>"
                class="<?php echo $period === $key ? 'active' : ''; ?>">
                <?php echo esc_html($label); ?>
            </a>
            <?php endforeach; ?>

            <span style="color:#ccc;margin:0 4px">|</span>

            <form method="get" style="display:flex;gap:6px;align-items:center">
                <input type="hidden" name="page" value="pl-analytics-full" />
                <input type="hidden" name="period" value="custom" />
                <input type="date" name="from" value="<?php echo esc_attr($custom_from ?: date('Y-m-d', $start)); ?>"
                        style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;font-size:13px" />
                <span style="color:#888">&rarr;</span>
                <input type="date" name="to" value="<?php echo esc_attr($custom_to ?: date('Y-m-d')); ?>"
                        style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;font-size:13px" />
                <button type="submit" class="<?php echo $period === 'custom' ? 'active' : ''; ?>">Apply</button>
            </form>
        </div>

        <!-- ROW 1: REAL-TIME + KEY METRICS -->
        <div class="pla-grid pla-grid-4">
            <div class="pla-card pla-realtime pla-stat" id="plaRealtimeCard">
                <div><span class="pla-pulse"></span></div>
                <div class="pla-stat-value" id="plaRealtimeCount"><?php echo $realtime_count; ?></div>
                <div class="pla-stat-label">Active Now (5 min)</div>
                <div class="pla-stat-sub" id="plaRealtimeSub" style="color:rgba(255,255,255,.6)">Last updated: <?php echo date('H:i:s'); ?></div>
            </div>
            <div class="pla-card pla-stat">
                <div class="pla-stat-value"><?php echo number_format($total); ?></div>
                <div class="pla-stat-label">Total Sessions</div>
                <div class="pla-stat-sub"><?php echo number_format($unique_visitors); ?> unique &middot; <?php echo $sessions_per_visitor; ?> sessions/visitor</div>
            </div>
            <div class="pla-card pla-stat">
                <div class="pla-stat-value"><?php echo $avg_depth; ?>%</div>
                <div class="pla-stat-label">Avg Scroll Depth</div>
                <div class="pla-stat-sub">Median: <?php echo $median_depth; ?>%</div>
            </div>
            <div class="pla-card pla-stat">
                <div class="pla-stat-value"><?php echo $bounce_rate; ?>%</div>
                <div class="pla-stat-label">Bounce Rate</div>
                <div class="pla-stat-sub"><?php echo $patterns['bouncer']; ?> bouncers</div>
            </div>
        </div>

        <!-- ROW 2: MORE KEY METRICS -->
        <div class="pla-grid pla-grid-4">
            <div class="pla-card pla-stat">
                <div class="pla-stat-value"><?php echo $returning_pct; ?>%</div>
                <div class="pla-stat-label">Returning Visitors</div>
                <div class="pla-stat-sub"><?php echo $returning_visitors; ?> / <?php echo $total; ?></div>
            </div>
            <div class="pla-card pla-stat">
                <div class="pla-stat-value"><?php echo $avg_active; ?>s</div>
                <div class="pla-stat-label">Avg Active Time</div>
                <div class="pla-stat-sub"><?php echo round($avg_time); ?>s total avg</div>
            </div>
            <div class="pla-card pla-stat">
                <div class="pla-stat-value"><?php echo $avg_quality; ?></div>
                <div class="pla-stat-label">Avg Quality Score</div>
                <div class="pla-stat-sub">out of 100</div>
            </div>
            <div class="pla-card pla-stat">
                <div class="pla-stat-value"><?php echo $total_pin_saves; ?></div>
                <div class="pla-stat-label">&#x1F4CC; Pinterest Saves</div>
                <div class="pla-stat-sub"><?php echo $pin_rate; ?>% save rate</div>
            </div>
        </div>

        <!-- REAL-TIME VISITORS TABLE -->
        <?php if ($realtime_count > 0): ?>
        <div class="pla-card" id="plaRealtimeTable" style="margin-bottom:24px">
            <h3>Active Visitors (Last 5 Minutes)</h3>
            <div style="overflow-x:auto">
                <table class="pla-table" style="width:100%;border-collapse:collapse;font-size:13px">
                    <thead>
                        <tr style="border-bottom:2px solid #f3f4f6">
                            <th style="text-align:left;padding:8px 12px;color:#888;font-weight:600">Country</th>
                            <th style="text-align:left;padding:8px 12px;color:#888;font-weight:600">Device</th>
                            <th style="text-align:left;padding:8px 12px;color:#888;font-weight:600">Page</th>
                            <th style="text-align:left;padding:8px 12px;color:#888;font-weight:600">Time</th>
                        </tr>
                    </thead>
                    <tbody id="plaRealtimeBody">
                        <?php
                        $rt_cutoff = time() - 300;
                        $rt_visitors = [];
                        foreach ($sessions as $s) {
                            $ts = $s['unix'] ?? strtotime($s['ts'] ?? '');
                            if ($ts && $ts >= $rt_cutoff) {
                                $page_url = $s['url'] ?? '';
                                $short = preg_replace('#https?://[^/]+#', '', $page_url);
                                $rt_visitors[] = [
                                    'country' => $s['country'] ?? 'Unknown',
                                    'device' => $s['device'] ?? 'desktop',
                                    'page' => strlen($short) > 45 ? substr($short, 0, 45) . '...' : $short,
                                    'time' => date('H:i:s', $ts),
                                ];
                            }
                        }
                        $rt_visitors = array_reverse($rt_visitors);
                        foreach ($rt_visitors as $rv): ?>
                        <tr style="border-bottom:1px solid #f3f4f6">
                            <td style="padding:8px 12px"><?php echo esc_html($rv['country']); ?></td>
                            <td style="padding:8px 12px"><span class="pla-badge" style="background:#e0f2fe;color:#0369a1;padding:2px 8px;border-radius:4px;font-size:11px"><?php echo esc_html($rv['device']); ?></span></td>
                            <td style="padding:8px 12px"><?php echo esc_html($rv['page']); ?></td>
                            <td style="padding:8px 12px"><?php echo esc_html($rv['time']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- ROW 3: SESSIONS OVER TIME + HOURLY -->
        <div class="pla-grid pla-grid-2">
            <div class="pla-card">
                <h3>&#x1F4C8; Sessions Over Time</h3>
                <div class="pla-chart-wrap pla-chart-tall"><canvas id="plaSessionsChart"></canvas></div>
            </div>
            <div class="pla-card">
                <h3>&#x1F550; Sessions by Hour</h3>
                <div class="pla-chart-wrap pla-chart-tall"><canvas id="plaHourlyChart"></canvas></div>
            </div>
        </div>

        <!-- ROW 4: DEVICE + PATTERN + VISITOR PIE CHARTS -->
        <div class="pla-grid pla-grid-3">
            <div class="pla-card">
                <h3>&#x1F4F1; Devices</h3>
                <div class="pla-chart-wrap"><canvas id="plaDeviceChart"></canvas></div>
            </div>
            <div class="pla-card">
                <h3>&#x1F441; Scroll Patterns</h3>
                <div class="pla-chart-wrap"><canvas id="plaPatternChart"></canvas></div>
            </div>
            <div class="pla-card">
                <h3>&#x1F504; New vs Returning</h3>
                <div class="pla-chart-wrap"><canvas id="plaVisitorChart"></canvas></div>
            </div>
        </div>

        <!-- ROW 5: SCROLL DEPTH + QUALITY SCORE -->
        <div class="pla-grid pla-grid-2">
            <div class="pla-card">
                <h3>&#x1F4CF; Scroll Depth Distribution</h3>
                <div class="pla-chart-wrap pla-chart-tall"><canvas id="plaDepthChart"></canvas></div>
            </div>
            <div class="pla-card">
                <h3>&#x2B50; Quality Score Distribution</h3>
                <div class="pla-chart-wrap pla-chart-tall"><canvas id="plaQualityChart"></canvas></div>
            </div>
        </div>

        <!-- ROW 6: TRAFFIC SOURCES + REFERRER TABLE -->
        <div class="pla-grid pla-grid-2">
            <div class="pla-card">
                <h3>&#x1F517; Traffic Sources</h3>
                <div class="pla-chart-wrap pla-chart-tall"><canvas id="plaReferrerChart"></canvas></div>
            </div>
            <div class="pla-card">
                <h3>&#x1F4CA; Referrer Breakdown</h3>
                <div class="pla-scroll">
                    <table class="pla-table">
                        <thead><tr><th>Source</th><th>Sessions</th><th>%</th></tr></thead>
                        <tbody>
                        <?php foreach (array_slice($referrers, 0, 20, true) as $ref => $cnt) :
                            $pct = $total > 0 ? round($cnt / $total * 100, 1) : 0;
                        ?>
                        <tr>
                            <td><?php echo esc_html(strlen($ref) > 50 ? substr($ref, 0, 50) . '...' : $ref); ?></td>
                            <td><strong><?php echo number_format($cnt); ?></strong></td>
                            <td><?php echo $pct; ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ROW 7: TOP PAGES + VIEWPORT WIDTHS -->
        <div class="pla-grid pla-grid-2">
            <div class="pla-card">
                <h3>&#x1F4C4; Top Pages</h3>
                <div class="pla-scroll">
                    <table class="pla-table">
                        <thead><tr><th>#</th><th>Page</th><th>Views</th><th>%</th></tr></thead>
                        <tbody>
                        <?php $rank = 1; foreach (array_slice($page_views, 0, 25, true) as $url => $cnt) :
                            $pct = $total > 0 ? round($cnt / $total * 100, 1) : 0;
                            $short = strlen($url) > 55 ? substr($url, 0, 55) . '...' : $url;
                        ?>
                        <tr>
                            <td><?php echo $rank++; ?></td>
                            <td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo esc_html($short); ?></td>
                            <td><strong><?php echo number_format($cnt); ?></strong></td>
                            <td><?php echo $pct; ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="pla-card">
                <h3>&#x1F4BB; Viewport Widths</h3>
                <div class="pla-chart-wrap"><canvas id="plaViewportChart"></canvas></div>
            </div>
        </div>

        <!-- ROW 8: COUNTRIES + CITIES -->
        <?php if (!empty($countries)) : ?>
        <div class="pla-grid pla-grid-2">
            <div class="pla-card">
                <h3>&#x1F30D; Top Countries</h3>
                <div class="pla-chart-wrap pla-chart-tall"><canvas id="plaCountryChart"></canvas></div>
            </div>
            <div class="pla-card">
                <h3>&#x1F3D9; Top Cities</h3>
                <div class="pla-scroll">
                    <table class="pla-table">
                        <thead><tr><th>City</th><th>Sessions</th><th>%</th></tr></thead>
                        <tbody>
                        <?php foreach (array_slice($cities, 0, 20, true) as $city_name => $cnt) :
                            $pct = $total > 0 ? round($cnt / $total * 100, 1) : 0;
                        ?>
                        <tr>
                            <td><?php echo esc_html($city_name); ?></td>
                            <td><strong><?php echo number_format($cnt); ?></strong></td>
                            <td><?php echo $pct; ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ROW 9: DAY OF WEEK + ACTIVE TIME -->
        <div class="pla-grid pla-grid-2">
            <div class="pla-card">
                <h3>&#x1F4C5; Sessions by Day of Week</h3>
                <div class="pla-chart-wrap"><canvas id="plaDowChart"></canvas></div>
            </div>
            <div class="pla-card">
                <h3>&#x23F1; Active Time Distribution</h3>
                <div class="pla-chart-wrap"><canvas id="plaActiveTimeChart"></canvas></div>
            </div>
        </div>

        <!-- ROW 9: ENGAGEMENT DEPTH + EXIT DEPTH -->
        <div class="pla-grid pla-grid-2">
            <div class="pla-card">
                <h3>&#x1F525; Engagement by Depth Zone</h3>
                <div class="pla-chart-wrap pla-chart-tall"><canvas id="plaZoneChart"></canvas></div>
            </div>
            <div class="pla-card">
                <h3>&#x1F6AA; Exit Depth Distribution</h3>
                <div class="pla-chart-wrap pla-chart-tall"><canvas id="plaExitChart"></canvas></div>
            </div>
        </div>

        <!-- ROW 10: CONTENT COMPLETION + PINTEREST -->
        <div class="pla-grid pla-grid-2">
            <div class="pla-card">
                <h3>&#x2705; Content Completion Distribution</h3>
                <div class="pla-chart-wrap pla-chart-tall"><canvas id="plaCompletionChart"></canvas></div>
            </div>
            <div class="pla-card">
                <h3>&#x1F4CC; Pinterest Saves Over Time</h3>
                <div class="pla-chart-wrap pla-chart-tall"><canvas id="plaPinChart"></canvas></div>
            </div>
        </div>

        <!-- CHART.JS INITIALIZATION -->
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof Chart === 'undefined') return;
            var defaults = Chart.defaults;
            defaults.font.family = '-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif';
            defaults.font.size = 12;
            defaults.plugins.legend.labels.usePointStyle = true;
            defaults.plugins.legend.labels.padding = 16;
            defaults.animation.duration = 600;

            var accent = '#e84393';
            var accent2 = '#6c5ce7';
            var colors = ['#e84393','#6c5ce7','#0984e3','#00b894','#e17055','#fdcb6e','#d63031','#00cec9','#e056a0','#636e72'];
            var barOpts = {responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},
                scales:{x:{grid:{display:false}},y:{beginAtZero:true,grid:{color:'#f3f4f6'}}}};
            var hBarOpts = {indexAxis:'y',responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},
                scales:{x:{beginAtZero:true,grid:{color:'#f3f4f6'}},y:{grid:{display:false}}}};
            var donutOpts = {responsive:true,maintainAspectRatio:false,cutout:'55%',plugins:{legend:{position:'bottom'}}};
            var zoneLabels = ['0-10%','10-20%','20-30%','30-40%','40-50%','50-60%','60-70%','70-80%','80-90%','90-100%'];

            // 1. Sessions Over Time + Unique Visitors
            new Chart(document.getElementById('plaSessionsChart'),{type:'line',
                data:{labels:<?php echo json_encode($chart_dates); ?>,datasets:[{label:'Sessions',data:<?php echo json_encode($chart_counts); ?>,
                    borderColor:accent,backgroundColor:accent+'15',fill:true,tension:.4,
                    pointRadius:<?php echo count($chart_dates) > 30 ? 0 : 3; ?>,pointHoverRadius:5,borderWidth:2},
                {label:'Unique Visitors',data:<?php echo json_encode($chart_unique); ?>,
                    borderColor:accent2,backgroundColor:accent2+'15',fill:true,tension:.4,
                    pointRadius:<?php echo count($chart_dates) > 30 ? 0 : 3; ?>,pointHoverRadius:5,borderWidth:2,borderDash:[5,3]}]},
                options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:true,position:'top'}},
                    scales:{x:{grid:{display:false}},y:{beginAtZero:true,grid:{color:'#f3f4f6'}}}}
            });

            // 2. Hourly
            new Chart(document.getElementById('plaHourlyChart'),{type:'bar',
                data:{labels:<?php echo json_encode(array_map(function($h){return sprintf('%02d:00',$h);},range(0,23))); ?>,
                    datasets:[{label:'Sessions',data:<?php echo json_encode(array_values($hourly_sessions)); ?>,backgroundColor:accent+'80',borderRadius:4,borderSkipped:false}]},
                options:Object.assign({},barOpts,{scales:{x:{grid:{display:false},ticks:{maxRotation:0,autoSkip:true,maxTicksLimit:12}},y:{beginAtZero:true,grid:{color:'#f3f4f6'}}}})
            });

            // 3. Devices
            new Chart(document.getElementById('plaDeviceChart'),{type:'doughnut',
                data:{labels:<?php echo json_encode(array_keys($devices)); ?>,
                    datasets:[{data:<?php echo json_encode(array_values($devices)); ?>,backgroundColor:[accent,accent2,'#0984e3'],borderWidth:0}]},
                options:donutOpts});

            // 4. Scroll Patterns
            new Chart(document.getElementById('plaPatternChart'),{type:'doughnut',
                data:{labels:<?php echo json_encode(array_keys($patterns)); ?>,
                    datasets:[{data:<?php echo json_encode(array_values($patterns)); ?>,backgroundColor:['#00b894','#fdcb6e','#d63031'],borderWidth:0}]},
                options:donutOpts});

            // 5. New vs Returning
            new Chart(document.getElementById('plaVisitorChart'),{type:'doughnut',
                data:{labels:['New','Returning'],datasets:[{data:[<?php echo $new_visitors; ?>,<?php echo $returning_visitors; ?>],backgroundColor:[accent,accent2],borderWidth:0}]},
                options:donutOpts});

            // 6. Scroll Depth Distribution
            new Chart(document.getElementById('plaDepthChart'),{type:'bar',
                data:{labels:zoneLabels,datasets:[{label:'Sessions',data:<?php echo json_encode(array_values($depth_buckets)); ?>,
                    backgroundColor:colors.map(function(c){return c+'80';}),borderRadius:4,borderSkipped:false}]},
                options:barOpts});

            // 7. Quality Score Distribution
            <?php
            $qs_buckets = array_fill(0, 10, 0);
            foreach ($quality_scores as $qs) { $b = min(9, (int)floor($qs / 10)); $qs_buckets[$b]++; }
            ?>
            new Chart(document.getElementById('plaQualityChart'),{type:'bar',
                data:{labels:['0-10','10-20','20-30','30-40','40-50','50-60','60-70','70-80','80-90','90-100'],
                    datasets:[{label:'Sessions',data:<?php echo json_encode(array_values($qs_buckets)); ?>,backgroundColor:accent2+'80',borderRadius:4,borderSkipped:false}]},
                options:barOpts});

            // 8. Traffic Sources
            new Chart(document.getElementById('plaReferrerChart'),{type:'bar',
                data:{labels:<?php echo json_encode(array_keys($referrer_types)); ?>,
                    datasets:[{label:'Sessions',data:<?php echo json_encode(array_values($referrer_types)); ?>,
                        backgroundColor:[accent,'#aaa','#0984e3',accent2,'#e17055'],borderRadius:4,borderSkipped:false}]},
                options:hBarOpts});

            // 9. Viewport Widths
            <?php
            $vw_order = ['<480' => 0, '480-767' => 0, '768-1023' => 0, '1024-1439' => 0, '1440+' => 0];
            foreach ($viewport_widths as $k => $v) { if (isset($vw_order[$k])) $vw_order[$k] = $v; }
            ?>
            new Chart(document.getElementById('plaViewportChart'),{type:'doughnut',
                data:{labels:<?php echo json_encode(array_keys($vw_order)); ?>,
                    datasets:[{data:<?php echo json_encode(array_values($vw_order)); ?>,backgroundColor:colors.slice(0,5),borderWidth:0}]},
                options:donutOpts});

            // 10. Countries
            <?php $top_countries = array_slice($countries, 0, 10, true); if (!empty($top_countries)) : ?>
            new Chart(document.getElementById('plaCountryChart'),{type:'bar',
                data:{labels:<?php echo json_encode(array_keys($top_countries)); ?>,
                    datasets:[{label:'Sessions',data:<?php echo json_encode(array_values($top_countries)); ?>,
                        backgroundColor:accent+'80',borderRadius:4,borderSkipped:false}]},
                options:hBarOpts});
            <?php endif; ?>

            // 11. Day of Week
            new Chart(document.getElementById('plaDowChart'),{type:'bar',
                data:{labels:['Sun','Mon','Tue','Wed','Thu','Fri','Sat'],
                    datasets:[{label:'Sessions',data:<?php echo json_encode(array_values($dow_sessions)); ?>,backgroundColor:accent2+'80',borderRadius:4,borderSkipped:false}]},
                options:barOpts});

            // 11. Active Time Distribution
            <?php
            $time_labels = ['0-15s','15-30s','30-60s','1-2m','2-5m','5-10m','10m+'];
            $time_buckets = array_fill(0, 7, 0);
            foreach ($active_times as $t) {
                if ($t < 15) $time_buckets[0]++;
                elseif ($t < 30) $time_buckets[1]++;
                elseif ($t < 60) $time_buckets[2]++;
                elseif ($t < 120) $time_buckets[3]++;
                elseif ($t < 300) $time_buckets[4]++;
                elseif ($t < 600) $time_buckets[5]++;
                else $time_buckets[6]++;
            }
            ?>
            new Chart(document.getElementById('plaActiveTimeChart'),{type:'bar',
                data:{labels:<?php echo json_encode($time_labels); ?>,
                    datasets:[{label:'Sessions',data:<?php echo json_encode($time_buckets); ?>,backgroundColor:'#00b89480',borderRadius:4,borderSkipped:false}]},
                options:barOpts});

            // 12. Engagement by Depth Zone
            new Chart(document.getElementById('plaZoneChart'),{type:'bar',
                data:{labels:zoneLabels,datasets:[{label:'Visitors Reaching Zone',data:<?php echo json_encode(array_values($depth_buckets)); ?>,
                    backgroundColor:'rgba(232,67,147,0.6)',borderRadius:4,borderSkipped:false}]},
                options:hBarOpts});

            // 13. Exit Depth Distribution
            <?php
            $exit_buckets = array_fill(0, 10, 0);
            foreach ($exit_depths as $ed) { $b = min(9, (int)floor($ed / 10)); $exit_buckets[$b]++; }
            ?>
            new Chart(document.getElementById('plaExitChart'),{type:'bar',
                data:{labels:zoneLabels,datasets:[{label:'Exits',data:<?php echo json_encode(array_values($exit_buckets)); ?>,
                    backgroundColor:'#d6303180',borderRadius:4,borderSkipped:false}]},
                options:barOpts});

            // 14. Content Completion Distribution
            <?php
            $cc_buckets = array_fill(0, 10, 0);
            foreach ($completions as $cc) { $b = min(9, (int)floor($cc / 10)); $cc_buckets[$b]++; }
            ?>
            new Chart(document.getElementById('plaCompletionChart'),{type:'bar',
                data:{labels:zoneLabels,datasets:[{label:'Sessions',data:<?php echo json_encode(array_values($cc_buckets)); ?>,
                    backgroundColor:'#0984e380',borderRadius:4,borderSkipped:false}]},
                options:barOpts});

            // 15. Pinterest Saves Over Time
            <?php
            $daily_pins = [];
            foreach ($sessions as $s) {
                $ps = $s['pin_saves']['saves'] ?? 0;
                if ($ps > 0) {
                    $d = date('Y-m-d', $s['unix'] ?? strtotime($s['ts'] ?? ''));
                    $daily_pins[$d] = ($daily_pins[$d] ?? 0) + intval($ps);
                }
            }
            // Fill missing dates with 0
            foreach ($chart_dates as $d) { if (!isset($daily_pins[$d])) $daily_pins[$d] = 0; }
            ksort($daily_pins);
            ?>
            new Chart(document.getElementById('plaPinChart'),{type:'bar',
                data:{labels:<?php echo json_encode(array_keys($daily_pins)); ?>,
                    datasets:[{label:'Saves',data:<?php echo json_encode(array_values($daily_pins)); ?>,
                        backgroundColor:accent+'80',borderRadius:4,borderSkipped:false}]},
                options:barOpts});
        });
        </script>
        <script>
        (function() {
            var countEl = document.getElementById('plaRealtimeCount');
            var subEl = document.getElementById('plaRealtimeSub');
            var bodyEl = document.getElementById('plaRealtimeBody');
            var tableWrap = document.getElementById('plaRealtimeTable');

            if (!countEl) return;

            countEl.style.transition = 'transform 0.2s ease';

            function refreshRealtime() {
                fetch('<?php echo esc_url(rest_url('plt/v1/realtime')); ?>', {
                    headers: { 'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>' }
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    countEl.textContent = data.count;
                    countEl.style.transform = 'scale(1.1)';
                    setTimeout(function() { countEl.style.transform = 'scale(1)'; }, 200);

                    if (subEl) {
                        subEl.textContent = 'Updated: ' + data.timestamp +
                            (data.count_1min > 0 ? ' \u00b7 ' + data.count_1min + ' in last min' : '');
                    }

                    if (bodyEl && data.visitors) {
                        var html = '';
                        data.visitors.forEach(function(v) {
                            html += '<tr style="border-bottom:1px solid #f3f4f6">' +
                                '<td style="padding:8px 12px">' + v.country + '</td>' +
                                '<td style="padding:8px 12px"><span class="pla-badge" style="background:#e0f2fe;color:#0369a1;padding:2px 8px;border-radius:4px;font-size:11px">' + v.device + '</span></td>' +
                                '<td style="padding:8px 12px">' + v.page + '</td>' +
                                '<td style="padding:8px 12px">' + v.time + '</td>' +
                                '</tr>';
                        });
                        bodyEl.innerHTML = html || '<tr><td colspan="4" style="text-align:center;color:#aaa;padding:16px">No active visitors</td></tr>';
                    }

                    if (tableWrap) {
                        tableWrap.style.display = data.count > 0 ? '' : 'none';
                    }
                })
                .catch(function() {});
            }

            setInterval(refreshRealtime, 30000);
        })();
        </script>
    </div>
    <?php
}

// ============================================
// TRACKING SCRIPT v3
// ============================================
add_action('wp_footer', function() {
    if (!PLT_ACTIVE || !is_singular()) return;
    $endpoint = rest_url('pl-tracker/v1/collect');
    $post_id = get_the_ID();
    ?>
<script>
(function(){
'use strict';
function init(){
var EP='<?php echo esc_js($endpoint); ?>',PID=<?php echo intval($post_id); ?>,T0=Date.now();
var vw=window.innerWidth,vh=window.innerHeight;
var dev=vw<768?'mobile':(vw<1024?'tablet':'desktop');

// Visitor ID (persistent across sessions)
var ret=0,vid='';
try{
vid=localStorage.getItem('plt_vid')||'';
if(!vid){vid='v_'+Math.random().toString(36).substr(2,9)+'_'+Date.now().toString(36);localStorage.setItem('plt_vid',vid);}
if(localStorage.getItem('plt_v')){ret=1;}localStorage.setItem('plt_v','1');
}catch(e){}

// STATE
var lastY=0,lastT=0,speeds=[],maxSpd=0,scrollEvts=0,dirChanges=0,lastDir='down';
var touchEvts=0,idlePeriods=0,lastActivity=Date.now(),lastInteractType='';
var readSegs=new Array(10).fill(0),lastSegT=Date.now(),lastSegIdx=0;
var speedZones=new Array(10).fill(0),speedZoneCounts=new Array(10).fill(0);
var sent=false,maxDepth=0;

// Touch
var TD={taps:0,su:0,sd:0,sl:0,sr:0,asv:0,msv:0,pz:0,dt:0};
var touchSpeeds=[],lastTapT=0;

// Exit
var EI={tr:0,mte:0,mlw:0,srd:0,ts:0,it:0,rsu:0,
        fst:'',fsm:0,dae:0,ev:0,lsbe:'',lit:'',tsli:0,we:0,sc:0};

// Engagement
var ENG={tc:0,lc:0,ic:0,ts:0,ce:0,at:0,rc:0,stt:0};
var clickTimes=[];

// Attention
var AZ=[],pauseStart=null,pauseDepth=0;

// Peak engagement tracking
var PE={bd:0,bt:0,es:0,ssz:0};
var engScores=[],bestEngScore=0;

// Link clicks
var LNK=[];

// Image engagement
var IMG=[];

// CTA tracking
var CTA=[];

// Section timing
var SEC=[];

// Content elements
var contentArea=document.querySelector('.entry-content,.post-content,article,.single-content,[class*="content"]');

// Affiliate patterns
var affPatterns=[/amzn\./,/amazon\./,/shrsl\./,/shareasale\./,/awin/,/flexoffers/,/commission/,/cj\.com/,
    /rakuten/,/impact\.com/,/partnerize/,/pepperjam/,/avantlink/,/linksynergy/,/jdoqocy/,/tkqlhce/,
    /dpbolvw/,/anrdoezrs/,/kqzyfj/,/click\./,/go\./,/ref=/,/affiliate/,/partner/];

function isAffiliate(url){
    for(var i=0;i<affPatterns.length;i++){if(affPatterns[i].test(url))return true;}
    return false;
}
function getAffNetwork(url){
    if(/amzn\.|amazon\./.test(url))return 'amazon';
    if(/shrsl\.|shareasale\./.test(url))return 'shareasale';
    if(/awin/.test(url))return 'awin';
    if(/flexoffers/.test(url))return 'flexoffers';
    if(/cj\.com|commission/.test(url))return 'cj';
    if(/rakuten/.test(url))return 'rakuten';
    if(/impact\.com/.test(url))return 'impact';
    return 'other';
}

function getDepth(){
    var dh=document.documentElement.scrollHeight-vh;
    return dh>0?Math.min(100,(window.scrollY/dh)*100):0;
}

function getCurrentSection(){
    var h2s=document.querySelectorAll('h2,h3');
    var scrollY=window.scrollY+vh/2;
    var current='';
    h2s.forEach(function(h){
        if(h.offsetTop<scrollY) current=h.textContent.substring(0,80);
    });
    return current;
}

function exSig(type,depth,vel){
    EI.sc++;lastInteractType='exit_signal';
    if(EI.sc===1){EI.fsm=Date.now()-T0;EI.fst=type;}
    EI.dae=depth||maxDepth;EI.ev=vel||0;
    EI.lsbe=getCurrentSection();
    EI.lit=lastInteractType;
    EI.tsli=Date.now()-lastActivity;
    if(ENG.tc>0||ENG.lc>0||scrollEvts>20)EI.we=1;
    if(EI.sc>=2)EI.tr=1;
}

// === SCROLL ===
var pauseTimer=null;
window.addEventListener('scroll',function(){
    var now=Date.now(),y=window.scrollY,dt=now-lastT;
    scrollEvts++;lastActivity=now;lastInteractType='scroll';
    if(dt>0&&dt<500){
        var spd=(Math.abs(y-lastY)/dt)*1000;
        speeds.push(spd);if(speeds.length>50)speeds.shift();
        if(spd>maxSpd)maxSpd=spd;
        var dir=y>lastY?'down':'up';
        if(dir!==lastDir){dirChanges++;lastDir=dir;}

        // Speed per zone
        var depth=getDepth();
        if(depth>maxDepth)maxDepth=depth;
        var zi=Math.min(9,Math.floor(depth/10));
        speedZones[zi]+=spd;speedZoneCounts[zi]++;

        // Exit signals
        if(dir==='up'&&spd>2000&&maxDepth>60){EI.rsu++;exSig('rapid_scroll_up',depth,spd);}
        if(dir==='up'&&maxDepth>80&&depth<maxDepth-20){EI.srd++;exSig('scroll_reversal_from_deep',depth,spd);}

        // Engagement score (lower speed + deeper = more engaged)
        var engScore=Math.max(0,(1-spd/2000))*Math.min(depth/50,1);
        engScores.push({s:engScore,d:depth,t:now-T0});
        if(engScore>bestEngScore){bestEngScore=engScore;PE.bd=depth;PE.bt=now-T0;PE.es=engScore;}
    }

    // Read segments
    var d2=getDepth();
    var si=Math.min(9,Math.floor(d2/10));
    var sn=Date.now();
    readSegs[lastSegIdx]+=sn-lastSegT;lastSegT=sn;lastSegIdx=si;

    if(y<50&&lastY>300)ENG.stt++;

    // Attention pause
    if(pauseTimer)clearTimeout(pauseTimer);
    if(pauseStart){
        var pd=Date.now()-pauseStart;
        if(pd>500)AZ.push({d:Math.round(pauseDepth*10)/10,p:pd});
        pauseStart=null;
    }
    pauseTimer=setTimeout(function(){pauseStart=Date.now();pauseDepth=d2;},800);

    lastY=y;lastT=now;
},{passive:true});

// === TOUCH ===
var tsX=0,tsY=0,tsT=0;
window.addEventListener('touchstart',function(e){
    touchEvts++;lastActivity=Date.now();lastInteractType='touch';
    if(e.touches.length===1){tsX=e.touches[0].clientX;tsY=e.touches[0].clientY;tsT=Date.now();}
    if(e.touches.length>=2)TD.pz++;
    var now=Date.now();if(now-lastTapT<300)TD.dt++;lastTapT=now;
},{passive:true});

window.addEventListener('touchend',function(e){
    lastActivity=Date.now();
    var dt=Date.now()-tsT;
    if(dt<200&&e.changedTouches.length===1){
        var dx=e.changedTouches[0].clientX-tsX,dy=e.changedTouches[0].clientY-tsY;
        if(Math.sqrt(dx*dx+dy*dy)<15){TD.taps++;return;}
    }
    if(dt>0&&e.changedTouches.length===1){
        var dx2=e.changedTouches[0].clientX-tsX,dy2=e.changedTouches[0].clientY-tsY;
        var dist=Math.sqrt(dx2*dx2+dy2*dy2);
        if(dist>50){
            var vel=(dist/dt)*1000;touchSpeeds.push(vel);
            if(vel>TD.msv)TD.msv=Math.round(vel);
            if(Math.abs(dy2)>Math.abs(dx2)){if(dy2<0)TD.su++;else TD.sd++;}
            else{if(dx2<0)TD.sl++;else TD.sr++;}
        }
    }
},{passive:true});

// === EXIT INTENT (desktop) ===
document.addEventListener('mousemove',function(e){
    lastActivity=Date.now();lastInteractType='mouse';
    if(e.clientY<10&&dev==='desktop'){EI.mte++;exSig('mouse_top_edge',null,0);}
},{passive:true});
document.addEventListener('mouseleave',function(e){
    if(e.clientY<=0&&dev==='desktop'){EI.mlw++;exSig('mouse_leave_window',null,0);}
},{passive:true});
document.addEventListener('visibilitychange',function(){
    if(document.visibilityState==='hidden'){EI.ts++;exSig('tab_switch',null,0);}
});
setInterval(function(){
    if(Date.now()-lastActivity>8000){idlePeriods++;EI.it++;exSig('idle_timeout',null,0);}
},8000);

// === LINK CLICK TRACKING ===
document.addEventListener('click',function(e){
    ENG.tc++;lastActivity=Date.now();lastInteractType='click';
    var now=Date.now();
    clickTimes.push(now);clickTimes=clickTimes.filter(function(t){return now-t<1500;});
    if(clickTimes.length>=3)ENG.rc++;

    var target=e.target;
    var link=target.closest?target.closest('a'):null;
    if(!link&&target.tagName==='A')link=target;
    if(!link&&target.parentElement&&target.parentElement.tagName==='A')link=target.parentElement;

    if(link&&link.href){
        ENG.lc++;lastInteractType='link_click';
        var url=link.href;
        var text=(link.textContent||link.innerText||'').substring(0,80).trim();
        var isExt=link.hostname!==location.hostname;
        var isAff=isAffiliate(url);
        var type=isAff?'affiliate':(isExt?'external':'internal');
        var depth=getDepth();
        var section=getCurrentSection();

        LNK.push({
            u:url.substring(0,400),t:text,tp:type,
            an:isAff?getAffNetwork(url):'',
            p:Math.round(depth*10)/10,
            ct:now-T0,ns:section
        });
    }

    if(target.tagName==='IMG'){
        ENG.ic++;lastInteractType='image_click';
    }
},{passive:true});

document.addEventListener('copy',function(){ENG.ce++;lastInteractType='copy';},{passive:true});
document.addEventListener('selectstart',function(){ENG.ts++;},{passive:true});

// Active time
var actStart=Date.now(),wasAct=true;
setInterval(function(){
    var now=Date.now();
    if(now-lastActivity<5000){
        if(!wasAct){actStart=now;wasAct=true;}
        ENG.at+=Math.min(now-actStart,5000);actStart=now;
    }else{wasAct=false;}
},5000);

// === IMAGE ENGAGEMENT ===
function trackImages(){
    var imgs=document.querySelectorAll('img');
    imgs.forEach(function(img,idx){
        if(img.dataset.pltTracked)return;
        img.dataset.pltTracked='1';
        var rect=img.getBoundingClientRect();
        var docH=document.documentElement.scrollHeight;
        var pos=docH>0?((rect.top+window.scrollY)/docH)*100:0;
        var tracker={s:img.src.substring(0,150),a:(img.alt||'').substring(0,80),p:pos,v:0,t:0,z:0,visSince:null};
        IMG.push(tracker);

        var obs=new IntersectionObserver(function(entries){
            if(entries[0].isIntersecting){
                if(!tracker.visSince)tracker.visSince=Date.now();
            } else {
                if(tracker.visSince){tracker.v+=Date.now()-tracker.visSince;tracker.visSince=null;}
            }
        },{threshold:0.5});
        obs.observe(img);

        img.addEventListener('click',function(){tracker.t++;},{passive:true});
    });
}

// === CTA/BUTTON TRACKING ===
function trackCTAs(){
    var btns=document.querySelectorAll('a.button,a.btn,.wp-block-button a,a[class*="cta"],a[class*="buy"],a[class*="shop"],a[class*="download"],button');
    btns.forEach(function(btn){
        if(btn.dataset.pltCta)return;
        btn.dataset.pltCta='1';
        var rect=btn.getBoundingClientRect();
        var docH=document.documentElement.scrollHeight;
        var pos=docH>0?((rect.top+window.scrollY)/docH)*100:0;
        var tracker={t:(btn.textContent||btn.innerText||'').substring(0,80).trim(),
            u:(btn.href||'').substring(0,400),p:pos,s:0,v:0,c:0,sp:0,visSince:null};
        CTA.push(tracker);

        var obs=new IntersectionObserver(function(entries){
            if(entries[0].isIntersecting&&entries[0].intersectionRatio>=0.5){
                tracker.s=1;
                if(!tracker.visSince)tracker.visSince=Date.now();
            } else {
                if(tracker.visSince){
                    tracker.v+=Date.now()-tracker.visSince;
                    tracker.visSince=null;
                    if(tracker.s&&!tracker.c)tracker.sp=1; // Scrolled past without clicking
                }
            }
        },{threshold:0.5});
        obs.observe(btn);

        btn.addEventListener('click',function(){tracker.c++;},{passive:true});
    });
}

// === SECTION TIMING ===
function trackSections(){
    var headings=document.querySelectorAll('h2,h3');
    headings.forEach(function(h){
        if(h.dataset.pltSec)return;
        h.dataset.pltSec='1';
        var rect=h.getBoundingClientRect();
        var docH=document.documentElement.scrollHeight;
        var pos=docH>0?((rect.top+window.scrollY)/docH)*100:0;
        var tracker={h:h.textContent.substring(0,80).trim(),d:pos,v:0,s:0,sp:0,visSince:null,speedSamples:[]};
        SEC.push(tracker);

        var obs=new IntersectionObserver(function(entries){
            if(entries[0].isIntersecting){
                tracker.s=1;
                if(!tracker.visSince)tracker.visSince=Date.now();
                // Capture scroll speed through this section
                if(speeds.length)tracker.speedSamples.push(speeds[speeds.length-1]);
            } else {
                if(tracker.visSince){tracker.v+=Date.now()-tracker.visSince;tracker.visSince=null;}
            }
        },{threshold:0.3});
        obs.observe(h);
    });
}

// === AD TRACKING ===
var adTrackers=[];
function findAds(){
    var ads=[],seen=new Set();
    var sel='div[id*="300x250"],div[id*="728x90"],div[id*="320x100"],div[id*="970x250"],'+
        'div[id*="336x280"],div[id*="300x600"],div[id*="250x250"],div[id*="300x100"],'+
        'div[id*="320x50"],div[id*="300x50"],div[id*="468x60"],div[id*="970x90"],'+
        'div[id*="120x600"],div[id*="160x600"]';
    document.querySelectorAll(sel).forEach(function(el){if(!seen.has(el)){seen.add(el);ads.push(el);}});
    document.querySelectorAll('iframe[id*="google_ads"],iframe[src*="doubleclick"],iframe[src*="googlesyndication"]').forEach(function(el){
        var p=el.parentElement||el;if(!seen.has(p)){seen.add(p);ads.push(p);}
    });
    document.querySelectorAll('[class*="adplus"],[id*="adplus"]').forEach(function(el){
        if(!seen.has(el)&&el.offsetHeight>30){seen.add(el);ads.push(el);}
    });
    document.querySelectorAll('.ad-container,.ad-wrapper,.ad-slot,.advertisement').forEach(function(el){
        if(!seen.has(el)&&el.offsetHeight>30){seen.add(el);ads.push(el);}
    });
    return ads;
}

function trackAd(el,idx){
    for(var i=0;i<adTrackers.length;i++){if(adTrackers[i].el===el)return;}
    var rect=el.getBoundingClientRect();
    var docH=document.documentElement.scrollHeight;
    var posY=rect.top+window.scrollY;
    var tr={el:el,id:el.id||('ad-'+idx),w:Math.round(rect.width),h:Math.round(rect.height),
        pos:docH>0?(posY/docH)*100:0,visMs:0,visSince:null,maxR:0,ratios:[],
        ftv:-1,v1:0,sap:0,spt:0,sptSamples:[],tev:0,inView:false,sbt:0,wasInView:false,
        clk:0,ct:0,ht:0,hs:null,nmc:0,nc:''};

    // Get nearby content
    var prev=el.previousElementSibling;
    if(prev)tr.nc=(prev.textContent||'').substring(0,80).trim();

    adTrackers.push(tr);

    var obs=new IntersectionObserver(function(entries){
        var e=entries[0],r=e.intersectionRatio;
        tr.ratios.push(r);if(tr.ratios.length>200)tr.ratios.shift();
        if(r>tr.maxR)tr.maxR=r;

        // Capture scroll speed through ad
        if(e.isIntersecting&&speeds.length)tr.sptSamples.push(speeds[speeds.length-1]);

        if(e.isIntersecting&&r>=0.5){
            if(!tr.inView){
                tr.inView=true;tr.visSince=Date.now();tr.tev++;
                if(tr.ftv===-1)tr.ftv=Date.now()-T0;
                tr.sap=speeds.length?speeds[speeds.length-1]:0;
                // Scrolled back to?
                if(tr.wasInView)tr.sbt++;
            }
        } else {
            if(tr.inView&&tr.visSince){
                var dur=Date.now()-tr.visSince;
                tr.visMs+=dur;if(dur>=1000)tr.v1++;
                tr.inView=false;tr.visSince=null;tr.wasInView=true;
            }
        }
    },{threshold:[0,0.1,0.25,0.5,0.75,1.0]});
    obs.observe(el);

    el.addEventListener('click',function(){tr.clk++;tr.ct=Date.now()-T0;lastInteractType='ad_click';},true);
    el.addEventListener('mouseenter',function(){tr.hs=Date.now();});
    el.addEventListener('mouseleave',function(){if(tr.hs)tr.ht+=Date.now()-tr.hs;tr.hs=null;});

    document.addEventListener('click',function(e){
        var ar=el.getBoundingClientRect();
        if(e.clientX>=ar.left-30&&e.clientX<=ar.right+30&&e.clientY>=ar.top-30&&e.clientY<=ar.bottom+30){
            if(e.clientX<ar.left||e.clientX>ar.right||e.clientY<ar.top||e.clientY>ar.bottom)tr.nmc++;
        }
    },{passive:true});
}

function initTracking(){
    var ads=findAds();ads.forEach(function(el,i){trackAd(el,adTrackers.length+i);});
    trackImages();trackCTAs();trackSections();
}
setTimeout(initTracking,2000);setTimeout(initTracking,6000);
setTimeout(initTracking,12000);setTimeout(initTracking,20000);

// === BUILD PAYLOAD ===
function buildPayload(){
    // Finalize timers
    adTrackers.forEach(function(tr){
        if(tr.inView&&tr.visSince){var d=Date.now()-tr.visSince;tr.visMs+=d;if(d>=1000)tr.v1++;tr.visSince=Date.now();}
        if(tr.hs){tr.ht+=Date.now()-tr.hs;tr.hs=Date.now();}
        // Avg scroll speed through
        if(tr.sptSamples.length){var s=0;for(var i=0;i<tr.sptSamples.length;i++)s+=tr.sptSamples[i];tr.spt=s/tr.sptSamples.length;}
    });
    readSegs[lastSegIdx]+=Date.now()-lastSegT;lastSegT=Date.now();
    if(pauseStart){var pd=Date.now()-pauseStart;if(pd>500)AZ.push({d:Math.round(pauseDepth*10)/10,p:pd});pauseStart=Date.now();}

    // Finalize images, CTAs, sections
    IMG.forEach(function(im){if(im.visSince){im.v+=Date.now()-im.visSince;im.visSince=Date.now();}});
    CTA.forEach(function(c){if(c.visSince){c.v+=Date.now()-c.visSince;c.visSince=Date.now();}});
    SEC.forEach(function(s){if(s.visSince){s.v+=Date.now()-s.visSince;s.visSince=Date.now();}});

    var avgSpd=0;if(speeds.length){var s=0;for(var i=0;i<speeds.length;i++)s+=speeds[i];avgSpd=s/speeds.length;}
    if(touchSpeeds.length){var ts=0;for(var j=0;j<touchSpeeds.length;j++)ts+=touchSpeeds[j];TD.asv=Math.round(ts/touchSpeeds.length);}

    var depth=getDepth();if(depth>maxDepth)maxDepth=depth;
    var timeOnPage=Date.now()-T0;
    var pattern='reader';
    if(timeOnPage<10000&&maxDepth<30)pattern='bouncer';
    else if(avgSpd>600)pattern='scanner';

    // Speed by zone averages
    var sbz=[];
    for(var i=0;i<10;i++){sbz.push(speedZoneCounts[i]>0?Math.round(speedZones[i]/speedZoneCounts[i]):0);}

    // Slowest scroll zone
    var slowestZ=0,slowestSpd=Infinity;
    for(var i=0;i<10;i++){if(speedZoneCounts[i]>2){var avg=speedZones[i]/speedZoneCounts[i];if(avg<slowestSpd){slowestSpd=avg;slowestZ=i*10+5;}}}
    PE.ssz=slowestZ;

    // Content completion (how much of actual content was seen)
    var contentEls=contentArea?contentArea.querySelectorAll('p,h2,h3,figure'):[];
    var seenEls=0;
    contentEls.forEach(function(el){
        var rect=el.getBoundingClientRect();
        if(rect.top+window.scrollY<window.scrollY+vh*1.1&&rect.top+window.scrollY>0)seenEls++;
    });
    var ccp=contentEls.length>0?(seenEls/contentEls.length)*100:maxDepth;

    // Quality score (0-100)
    var qs=0;
    qs+=Math.min(timeOnPage/120000,1)*30; // up to 30pts for 2+ min
    qs+=Math.min(maxDepth/100,1)*25;       // up to 25pts for full depth
    qs+=Math.min(dirChanges/5,1)*15;       // up to 15pts for re-reading
    qs+=(ENG.lc>0?10:0);                   // 10pts for clicking a link
    qs+=(avgSpd<500?20:(avgSpd<1000?10:0)); // up to 20pts for slow reading

    // Build ads data
    var adsData=[],seenAds=new Set(),totalClk=0;
    adTrackers.forEach(function(tr){
        var key=tr.id+'_'+tr.w+'_'+tr.h;if(seenAds.has(key))return;seenAds.add(key);
        if(tr.w<10||tr.h<10)return;
        var ar=0;if(tr.ratios.length){var rs=0;for(var i=0;i<tr.ratios.length;i++)rs+=tr.ratios[i];ar=rs/tr.ratios.length;}
        totalClk+=tr.clk;
        adsData.push({el:tr.id,w:tr.w,h:tr.h,pos:Math.round(tr.pos*10)/10,
            vis:tr.visMs,mr:Math.round(tr.maxR*100)/100,ar:Math.round(ar*100)/100,
            ftv:tr.ftv,v1:tr.v1,sap:Math.round(tr.sap),spt:Math.round(tr.spt),
            tev:tr.tev,sbt:tr.sbt,
            clk:tr.clk,ct:tr.ct,ht:Math.round(tr.ht),nmc:tr.nmc,
            nc:tr.nc});
    });

    // Build section data
    var secData=[];
    SEC.forEach(function(s){
        var avgSp=0;if(s.speedSamples.length){var ss=0;for(var i=0;i<s.speedSamples.length;i++)ss+=s.speedSamples[i];avgSp=ss/s.speedSamples.length;}
        secData.push({h:s.h,d:Math.round(s.d*10)/10,v:s.v,s:s.s,sp:Math.round(avgSp)});
    });

    // Build CTA data
    var ctaData=[];
    CTA.forEach(function(c){ctaData.push({t:c.t,u:c.u,p:Math.round(c.p*10)/10,s:c.s,v:c.v,c:c.c,sp:c.sp});});

    // Build image data (top 20 by visible time)
    var imgData=IMG.map(function(im){return{s:im.s,a:im.a,p:Math.round(im.p*10)/10,v:im.v,t:im.t,z:im.z};});
    imgData.sort(function(a,b){return b.v-a.v;});
    imgData=imgData.slice(0,20);

    var ga4cid='';try{ga4cid=localStorage.getItem('plt_ga4cid')||'';}catch(e){}

    return {t:timeOnPage,url:location.pathname,pid:PID,ref:document.referrer,
        dev:dev,vw:vw,vh:vh,ret:ret,vid:vid,ga4cid:ga4cid,
        tz:Intl.DateTimeFormat().resolvedOptions().timeZone||'',lang:navigator.language||'',
        d:Math.round(maxDepth*10)/10,ccp:Math.round(ccp*10)/10,
        ss:Math.round(avgSpd),ms:Math.round(maxSpd),sp:pattern,
        ch:document.documentElement.scrollHeight,se:scrollEvts,dc:dirChanges,
        te:touchEvts,ip:idlePeriods,
        af:adsData.length,ac:totalClk,ads:adsData,
        rs:readSegs.map(Math.round),sbz:sbz,
        sec:secData,td:TD,ei:EI,eng:ENG,
        lnk:LNK.slice(-50),img:imgData,cta:ctaData,
        az:AZ.slice(-50),pe:PE,qs:Math.round(qs*10)/10,
        ci:window.__plt||null,pin:window.__plPinData||null};
}

function send(){
    if(sent)return;if(Date.now()-T0<3000)return;sent=true;
    try{navigator.sendBeacon(EP,new Blob([JSON.stringify(buildPayload())],{type:'application/json'}));}catch(e){}
}

setInterval(function(){
    if(Date.now()-T0<3000)return;
    try{navigator.sendBeacon(EP,new Blob([JSON.stringify(buildPayload())],{type:'application/json'}));}catch(e){}
},30000);

document.addEventListener('visibilitychange',function(){if(document.visibilityState==='hidden')send();});
window.addEventListener('pagehide',send);
}

if('requestIdleCallback' in window)requestIdleCallback(init);
else setTimeout(init,300);
})();
</script>
<?php
}, 999);

// ============================================
// GA4 CLIENT-SIDE PAGE_VIEW BEACON (all pages)
// Sends page_view from visitor's browser so GA4 gets real IP for geo.
// ============================================
add_action('wp_footer', function() {
    if (!PLT_ACTIVE) return;
    $ga4_id = get_theme_mod('pl_ga4_measurement_id', '');
    $ga4_secret = get_theme_mod('pl_ga4_api_secret', '');
    $ga4_enabled = get_theme_mod('pl_ga4_enabled', false);
    if (!$ga4_enabled || !$ga4_id || !$ga4_secret) return;
    ?>
<script>
(function(){
var vid=localStorage.getItem('plt_vid')||'';
if(!vid){vid='v_'+Math.random().toString(36).substr(2,9)+'_'+Date.now().toString(36);try{localStorage.setItem('plt_vid',vid);}catch(e){}}
var cid=localStorage.getItem('plt_ga4cid')||'';
if(!cid){var h=0;for(var i=0;i<vid.length;i++){h=((h<<5)-h)+vid.charCodeAt(i);h|=0;}cid=Math.abs(h)+'.'+Math.floor(Date.now()/1000);try{localStorage.setItem('plt_ga4cid',cid);}catch(e){}}
var payload=JSON.stringify({client_id:cid,events:[{name:'page_view',params:{
page_location:location.href,page_title:document.title,page_referrer:document.referrer,engagement_time_msec:100}}]});
if(navigator.sendBeacon){navigator.sendBeacon('https://www.google-analytics.com/mp/collect?measurement_id=<?php echo esc_js($ga4_id); ?>&api_secret=<?php echo esc_js($ga4_secret); ?>',new Blob([payload],{type:'application/json'}));}
})();
</script>
<?php
}, 1000);

