<?php
/**
 * Plugin Name: PinLightning Visitor Tracker
 * Description: Ultimate visitor intelligence — ads, clicks, funnels, exit intent, touch, engagement. Zero performance impact.
 * Version: 3.2
 * Author: PinLightning
 *
 * Captures EVERYTHING needed to:
 * - Maximize ad viewability and clicks
 * - Build high-converting exit intent overlays
 * - Optimize ebook/affiliate funnels
 * - Understand exactly how every visitor behaves
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

add_action('admin_notices', function() {
    if (!current_user_can('manage_options')) return;
    $screen = get_current_screen();
    if ($screen && $screen->id === 'plugins') {
        $key = plt_get_key();
        $upload_dir = wp_upload_dir();
        $data_dir = $upload_dir['basedir'] . '/pl-tracker-data';
        $session_count = 0;
        if (is_dir($data_dir)) {
            $files = glob($data_dir . '/*/*.json');
            $session_count = count($files);
        }
        echo '<div class="notice notice-info"><p>';
        echo '<strong>📊 PinLightning Tracker v3.2 Active</strong> — ';
        echo $session_count . ' sessions. ';
        echo 'Report: <code>' . esc_html(rest_url('pl-tracker/v1/report') . '?key=' . $key) . '</code>';
        echo '</p></div>';
    }
});

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
});

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

    file_put_contents(
        $data_dir . '/' . uniqid('v3_') . '.json',
        json_encode($session, JSON_PRETTY_PRINT)
    );
    return new WP_REST_Response(['ok' => true], 200);
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

// Return visitor
var ret=0;
try{if(localStorage.getItem('plt_v')){ret=1;}localStorage.setItem('plt_v','1');}catch(e){}

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

    return {t:timeOnPage,url:location.pathname,pid:PID,ref:document.referrer,
        dev:dev,vw:vw,vh:vh,ret:ret,
        d:Math.round(maxDepth*10)/10,ccp:Math.round(ccp*10)/10,
        ss:Math.round(avgSpd),ms:Math.round(maxSpd),sp:pattern,
        ch:document.documentElement.scrollHeight,se:scrollEvts,dc:dirChanges,
        te:touchEvts,ip:idlePeriods,
        af:adsData.length,ac:totalClk,ads:adsData,
        rs:readSegs.map(Math.round),sbz:sbz,
        sec:secData,td:TD,ei:EI,eng:ENG,
        lnk:LNK.slice(-50),img:imgData,cta:ctaData,
        az:AZ.slice(-50),pe:PE,qs:Math.round(qs*10)/10,
        ci:window.__plt||null};
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

register_deactivation_hook(__FILE__, function() { delete_option('plt_data_key'); });
