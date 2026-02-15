<?php
/**
 * PinLightning AI Chat — Theme Integration
 * Powered by Claude Haiku 4.5
 * Version: 1.0
 */

if (!defined('ABSPATH')) exit;

define('PLCHAT_VERSION', '1.0');
define('PLCHAT_MODEL', 'claude-haiku-4-5-20251001');
define('PLCHAT_MAX_MESSAGES', 20);      // max messages per session
define('PLCHAT_MAX_SESSIONS_DAY', 500); // rate limit per day

// ============================================
// ACTIVATION — Create DB tables on theme switch
// ============================================
add_action('after_switch_theme', 'plchat_create_tables');
add_action('admin_init', 'plchat_maybe_create_tables');

function plchat_maybe_create_tables() {
    if (get_option('plchat_db_version') !== PLCHAT_VERSION) {
        plchat_create_tables();
    }
}

function plchat_create_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}pl_chat_sessions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        visitor_id VARCHAR(64) NOT NULL,
        post_id BIGINT UNSIGNED DEFAULT 0,
        post_title VARCHAR(255) DEFAULT '',
        post_category VARCHAR(100) DEFAULT '',
        device VARCHAR(20) DEFAULT '',
        referrer VARCHAR(500) DEFAULT '',
        country VARCHAR(100) DEFAULT '',
        city VARCHAR(100) DEFAULT '',
        timezone VARCHAR(50) DEFAULT '',
        local_hour TINYINT DEFAULT -1,
        visit_number INT DEFAULT 1,
        scroll_depth FLOAT DEFAULT 0,
        scroll_speed FLOAT DEFAULT 0,
        scroll_pattern VARCHAR(20) DEFAULT '',
        paused_sections TEXT,
        tapped_images TEXT,
        messages_count INT DEFAULT 0,
        first_message_at DATETIME NULL,
        last_message_at DATETIME NULL,
        session_duration_s INT DEFAULT 0,
        char_taps_before INT DEFAULT 0,
        heart_taps_before INT DEFAULT 0,
        total_tokens_in INT DEFAULT 0,
        total_tokens_out INT DEFAULT 0,
        estimated_cost_usd DECIMAL(8,6) DEFAULT 0,
        converted TINYINT DEFAULT 0,
        conversion_type VARCHAR(50) DEFAULT '',
        email_captured VARCHAR(255) DEFAULT '',
        starred TINYINT DEFAULT 0,
        admin_notes TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_visitor (visitor_id),
        INDEX idx_post (post_id),
        INDEX idx_created (created_at),
        INDEX idx_converted (converted),
        INDEX idx_starred (starred)
    ) $charset");

    $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}pl_chat_messages (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        session_id BIGINT UNSIGNED NOT NULL,
        role ENUM('system','assistant','user') NOT NULL,
        content TEXT NOT NULL,
        tokens_in INT DEFAULT 0,
        tokens_out INT DEFAULT 0,
        response_ms INT DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_session (session_id),
        INDEX idx_created (created_at)
    ) $charset");

    update_option('plchat_db_version', PLCHAT_VERSION);
}

// ============================================
// SETTINGS PAGE — API key + configuration
// ============================================
add_action('admin_menu', function() {
    // Main menu
    add_menu_page(
        'AI Chat',
        'AI Chat',
        'manage_options',
        'pl-ai-chat',
        'plchat_admin_dashboard',
        'dashicons-format-chat',
        30
    );

    // Sub-pages
    add_submenu_page('pl-ai-chat', 'Dashboard', 'Dashboard', 'manage_options', 'pl-ai-chat', 'plchat_admin_dashboard');
    add_submenu_page('pl-ai-chat', 'Live Chats', 'Live Chats', 'manage_options', 'pl-ai-chat-live', 'plchat_admin_live');
    add_submenu_page('pl-ai-chat', 'Chat History', 'Chat History', 'manage_options', 'pl-ai-chat-history', 'plchat_admin_history');
    add_submenu_page('pl-ai-chat', 'Analytics', 'Analytics', 'manage_options', 'pl-ai-chat-analytics', 'plchat_admin_analytics');
    add_submenu_page('pl-ai-chat', 'Settings', 'Settings', 'manage_options', 'pl-ai-chat-settings', 'plchat_admin_settings');
});

// Settings registration
add_action('admin_init', function() {
    register_setting('plchat_settings', 'plchat_api_key');
    register_setting('plchat_settings', 'plchat_model', ['default' => PLCHAT_MODEL]);
    register_setting('plchat_settings', 'plchat_enabled', ['default' => '0']);
    register_setting('plchat_settings', 'plchat_max_messages', ['default' => PLCHAT_MAX_MESSAGES]);
    register_setting('plchat_settings', 'plchat_character_name', ['default' => 'Cheer']);
    register_setting('plchat_settings', 'plchat_system_prompt', ['default' => plchat_default_system_prompt()]);
    register_setting('plchat_settings', 'plchat_taps_to_prompt', ['default' => '3']);
    register_setting('plchat_settings', 'plchat_taps_to_open', ['default' => '4']);
    register_setting('plchat_settings', 'plchat_heart_taps_to_open', ['default' => '3']);
    register_setting('plchat_settings', 'plchat_proactive_delay', ['default' => '60']);
    register_setting('plchat_settings', 'plchat_show_on_mobile', ['default' => '1']);
});

function plchat_default_system_prompt() {
    return 'You are {name}, a friendly, fashion-obsessed character living on a style blog. You\'re warm, playful, use emojis naturally (1-2 per message), and feel like a best friend who knows everything about fashion, home decor, and lifestyle.

RULES:
- Keep responses SHORT: 2-3 sentences max. This is mobile chat.
- Never reveal you have data about the visitor. Weave observations naturally.
- Never say "I see from your data" or "your scroll behavior shows"
- Match energy to behavior: slow readers get thoughtful replies, scanners get quick/fun ones
- Use the visitor context to make relevant suggestions about the current post
- If they seem interested in something specific, guide them toward related content
- Be genuinely curious about them — ask ONE question per reply max
- You can suggest products or links when it feels natural, never pushy
- If asked who you are: you\'re {name}, the blog\'s resident style guide
- If asked if you\'re AI: "I\'m {name}! I live here on the blog and help people find amazing style inspiration"

PERSONALITY:
- Playful but not childish
- Knowledgeable but not lecturing
- Empathetic and emotionally intelligent
- Uses fashion/style metaphors naturally
- Remembers what they said earlier in the conversation';
}

// ============================================
// REST API ENDPOINTS
// ============================================
add_action('rest_api_init', function() {
    // Start chat session
    register_rest_route('pl-chat/v1', '/start', [
        'methods' => 'POST',
        'callback' => 'plchat_api_start',
        'permission_callback' => '__return_true',
    ]);

    // Send message
    register_rest_route('pl-chat/v1', '/message', [
        'methods' => 'POST',
        'callback' => 'plchat_api_message',
        'permission_callback' => '__return_true',
    ]);

    // End session
    register_rest_route('pl-chat/v1', '/end', [
        'methods' => 'POST',
        'callback' => 'plchat_api_end',
        'permission_callback' => '__return_true',
    ]);

    // Admin: get sessions list
    register_rest_route('pl-chat/v1', '/admin/sessions', [
        'methods' => 'GET',
        'callback' => 'plchat_api_admin_sessions',
        'permission_callback' => function() { return current_user_can('manage_options'); },
    ]);

    // Admin: get single session with messages
    register_rest_route('pl-chat/v1', '/admin/session/(?P<id>\d+)', [
        'methods' => 'GET',
        'callback' => 'plchat_api_admin_session',
        'permission_callback' => function() { return current_user_can('manage_options'); },
    ]);

    // Admin: star/note a session
    register_rest_route('pl-chat/v1', '/admin/session/(?P<id>\d+)/update', [
        'methods' => 'POST',
        'callback' => 'plchat_api_admin_update',
        'permission_callback' => function() { return current_user_can('manage_options'); },
    ]);

    // Admin: analytics data
    register_rest_route('pl-chat/v1', '/admin/analytics', [
        'methods' => 'GET',
        'callback' => 'plchat_api_admin_analytics',
        'permission_callback' => function() { return current_user_can('manage_options'); },
    ]);

    // Admin: delete a session
    register_rest_route('pl-chat/v1', '/admin/session/(?P<id>\d+)/delete', [
        'methods' => 'POST',
        'callback' => 'plchat_api_admin_delete',
        'permission_callback' => function() { return current_user_can('manage_options'); },
    ]);
});

// ============================================
// API: Start Session
// ============================================
function plchat_api_start($request) {
    if (get_option('plchat_enabled') !== '1') {
        return new WP_REST_Response(['error' => 'Chat disabled'], 503);
    }

    $api_key = get_option('plchat_api_key');
    if (empty($api_key)) {
        return new WP_REST_Response(['error' => 'Not configured'], 503);
    }

    // Rate limit check
    global $wpdb;
    $today_count = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}pl_chat_sessions WHERE created_at >= %s",
        gmdate('Y-m-d 00:00:00')
    ));
    if ($today_count >= PLCHAT_MAX_SESSIONS_DAY) {
        return new WP_REST_Response(['error' => 'Busy, try later'], 429);
    }

    $body = $request->get_json_params();

    // Generate or retrieve visitor ID
    $visitor_id = sanitize_text_field($body['vid'] ?? '');
    if (empty($visitor_id)) {
        $visitor_id = 'v_' . bin2hex(random_bytes(12));
    }

    // Visitor context from scroll-engage.js
    $context = [
        'post_id'        => absint($body['pid'] ?? 0),
        'post_title'     => sanitize_text_field($body['pt'] ?? ''),
        'post_category'  => sanitize_text_field($body['pc'] ?? ''),
        'device'         => sanitize_text_field($body['dev'] ?? ''),
        'referrer'       => sanitize_text_field($body['ref'] ?? ''),
        'country'        => sanitize_text_field($body['country'] ?? ''),
        'city'           => sanitize_text_field($body['city'] ?? ''),
        'timezone'       => sanitize_text_field($body['tz'] ?? ''),
        'local_hour'     => intval($body['lh'] ?? -1),
        'visit_number'   => intval($body['vn'] ?? 1),
        'scroll_depth'   => round(floatval($body['sd'] ?? 0), 1),
        'scroll_speed'   => round(floatval($body['ss'] ?? 0)),
        'scroll_pattern' => sanitize_text_field($body['sp'] ?? ''),
        'paused_sections'=> sanitize_text_field($body['ps'] ?? ''),
        'tapped_images'  => sanitize_text_field($body['ti'] ?? ''),
        'char_taps'      => intval($body['ct'] ?? 0),
        'heart_taps'     => intval($body['ht'] ?? 0),
    ];

    // Create session
    $wpdb->insert("{$wpdb->prefix}pl_chat_sessions", [
        'visitor_id'      => $visitor_id,
        'post_id'         => $context['post_id'],
        'post_title'      => $context['post_title'],
        'post_category'   => $context['post_category'],
        'device'          => $context['device'],
        'referrer'        => $context['referrer'],
        'country'         => $context['country'],
        'city'            => $context['city'],
        'timezone'        => $context['timezone'],
        'local_hour'      => $context['local_hour'],
        'visit_number'    => $context['visit_number'],
        'scroll_depth'    => $context['scroll_depth'],
        'scroll_speed'    => $context['scroll_speed'],
        'scroll_pattern'  => $context['scroll_pattern'],
        'paused_sections' => $context['paused_sections'],
        'tapped_images'   => $context['tapped_images'],
        'char_taps_before'=> $context['char_taps'],
        'heart_taps_before'=> $context['heart_taps'],
        'first_message_at'=> current_time('mysql'),
    ]);
    $session_id = $wpdb->insert_id;

    // Build system prompt with visitor context
    $char_name = get_option('plchat_character_name', 'Cheer');
    $sys_template = get_option('plchat_system_prompt', plchat_default_system_prompt());
    $sys_prompt = str_replace('{name}', $char_name, $sys_template);

    // Append visitor context block
    $ctx_block = "\n\nVISITOR CONTEXT (use naturally, never expose raw data):";
    $ctx_block .= "\n- Current post: \"{$context['post_title']}\"";
    if ($context['post_category']) $ctx_block .= " (category: {$context['post_category']})";
    $ctx_block .= "\n- Visit #: {$context['visit_number']}";
    $ctx_block .= "\n- Device: {$context['device']}";
    if ($context['referrer']) $ctx_block .= "\n- Came from: {$context['referrer']}";
    if ($context['country']) $ctx_block .= "\n- Location: {$context['city']}, {$context['country']}";
    if ($context['local_hour'] >= 0) {
        $hour = $context['local_hour'];
        $tod = $hour < 6 ? 'late night' : ($hour < 12 ? 'morning' : ($hour < 17 ? 'afternoon' : ($hour < 21 ? 'evening' : 'night')));
        $ctx_block .= "\n- Local time: {$tod} ({$hour}:00)";
    }
    if ($context['timezone']) $ctx_block .= " ({$context['timezone']})";
    $ctx_block .= "\n- Scroll behavior: {$context['scroll_pattern']} at {$context['scroll_speed']} px/s, reached {$context['scroll_depth']}% depth";
    if ($context['paused_sections']) $ctx_block .= "\n- Paused longest on: {$context['paused_sections']}";
    if ($context['tapped_images']) $ctx_block .= "\n- Tapped/zoomed images near: {$context['tapped_images']}";
    if ($context['char_taps'] > 0) $ctx_block .= "\n- Tapped the character {$context['char_taps']} times before opening chat (eager to interact!)";
    if ($context['heart_taps'] > 0) $ctx_block .= "\n- Tapped the heart {$context['heart_taps']} times";

    $full_system = $sys_prompt . $ctx_block;

    // Store system prompt as first message
    $wpdb->insert("{$wpdb->prefix}pl_chat_messages", [
        'session_id' => $session_id,
        'role'       => 'system',
        'content'    => $full_system,
    ]);

    // Generate opening message from Claude
    $opening = plchat_call_claude($api_key, $full_system, [], $session_id);

    if ($opening['error']) {
        return new WP_REST_Response(['error' => $opening['error']], 500);
    }

    return new WP_REST_Response([
        'sid'     => $session_id,
        'vid'     => $visitor_id,
        'msg'     => $opening['content'],
        'name'    => $char_name,
    ], 200);
}

// ============================================
// API: Send Message
// ============================================
function plchat_api_message($request) {
    global $wpdb;

    $body = $request->get_json_params();
    $session_id = absint($body['sid'] ?? 0);
    $user_msg = sanitize_text_field($body['msg'] ?? '');

    if (!$session_id || empty($user_msg)) {
        return new WP_REST_Response(['error' => 'Invalid'], 400);
    }

    // Check session exists and message limit
    $session = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}pl_chat_sessions WHERE id = %d", $session_id
    ));
    if (!$session) return new WP_REST_Response(['error' => 'Session not found'], 404);

    $max = intval(get_option('plchat_max_messages', PLCHAT_MAX_MESSAGES));
    if ($session->messages_count >= $max) {
        return new WP_REST_Response([
            'msg' => "I've loved chatting with you! But I need to take a little break. Come back anytime!",
            'ended' => true,
        ], 200);
    }

    // Truncate user message
    $user_msg = mb_substr($user_msg, 0, 500);

    // Store user message
    $wpdb->insert("{$wpdb->prefix}pl_chat_messages", [
        'session_id' => $session_id,
        'role'       => 'user',
        'content'    => $user_msg,
    ]);

    // Load conversation history
    $messages = $wpdb->get_results($wpdb->prepare(
        "SELECT role, content FROM {$wpdb->prefix}pl_chat_messages WHERE session_id = %d ORDER BY id ASC",
        $session_id
    ));

    $system_prompt = '';
    $conversation = [];
    foreach ($messages as $m) {
        if ($m->role === 'system') {
            $system_prompt = $m->content;
        } else {
            $conversation[] = ['role' => $m->role, 'content' => $m->content];
        }
    }

    // Call Claude
    $api_key = get_option('plchat_api_key');
    $response = plchat_call_claude($api_key, $system_prompt, $conversation, $session_id);

    if ($response['error']) {
        return new WP_REST_Response(['error' => $response['error']], 500);
    }

    // Update session stats
    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}pl_chat_sessions SET
            messages_count = messages_count + 2,
            last_message_at = %s,
            total_tokens_in = total_tokens_in + %d,
            total_tokens_out = total_tokens_out + %d,
            estimated_cost_usd = estimated_cost_usd + %f,
            scroll_depth = GREATEST(scroll_depth, %f)
        WHERE id = %d",
        current_time('mysql'),
        $response['tokens_in'],
        $response['tokens_out'],
        $response['cost'],
        floatval($body['sd'] ?? 0),
        $session_id
    ));

    // Check for email in user message (simple capture)
    if (preg_match('/[\w.+-]+@[\w-]+\.[\w.]+/', $user_msg, $email_match)) {
        $wpdb->update("{$wpdb->prefix}pl_chat_sessions",
            ['email_captured' => sanitize_email($email_match[0]), 'converted' => 1, 'conversion_type' => 'email'],
            ['id' => $session_id]
        );
    }

    return new WP_REST_Response([
        'msg'   => $response['content'],
        'count' => $session->messages_count + 2,
        'max'   => $max,
    ], 200);
}

// ============================================
// API: End Session
// ============================================
function plchat_api_end($request) {
    global $wpdb;
    $body = $request->get_json_params();
    $session_id = absint($body['sid'] ?? 0);
    if (!$session_id) return new WP_REST_Response(['ok' => true], 200);

    $duration = absint($body['dur'] ?? 0);
    $final_depth = round(floatval($body['sd'] ?? 0), 1);

    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}pl_chat_sessions SET
            session_duration_s = %d,
            scroll_depth = GREATEST(scroll_depth, %f)
        WHERE id = %d",
        $duration, $final_depth, $session_id
    ));

    return new WP_REST_Response(['ok' => true], 200);
}

// ============================================
// API: Admin Delete Session
// ============================================
function plchat_api_admin_delete($request) {
    global $wpdb;
    $id = absint($request['id']);
    $wpdb->delete("{$wpdb->prefix}pl_chat_messages", ['session_id' => $id]);
    $wpdb->delete("{$wpdb->prefix}pl_chat_sessions", ['id' => $id]);
    return new WP_REST_Response(['ok' => true, 'deleted' => $id], 200);
}

// ============================================
// CLAUDE API CALL
// ============================================
function plchat_call_claude($api_key, $system_prompt, $conversation, $session_id) {
    global $wpdb;
    $model = get_option('plchat_model', PLCHAT_MODEL);
    $start = microtime(true);

    $api_messages = [];
    foreach ($conversation as $msg) {
        $api_messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
    }

    // If no conversation yet, add a trigger for opening message
    if (empty($api_messages)) {
        $api_messages[] = [
            'role' => 'user',
            'content' => '[The visitor just opened the chat by tapping on you. Say hi with a personalized opening based on their context. Be warm and make them feel seen. Keep it to 1-2 sentences.]'
        ];
    }

    $payload = [
        'model'      => $model,
        'max_tokens' => 200,
        'system'     => $system_prompt,
        'messages'   => $api_messages,
    ];

    $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
        'timeout' => 15,
        'headers' => [
            'Content-Type'      => 'application/json',
            'x-api-key'         => $api_key,
            'anthropic-version'  => '2023-06-01',
        ],
        'body' => wp_json_encode($payload),
    ]);

    $elapsed = round((microtime(true) - $start) * 1000);

    if (is_wp_error($response)) {
        return ['error' => 'API connection failed', 'content' => '', 'tokens_in' => 0, 'tokens_out' => 0, 'cost' => 0];
    }

    $status = wp_remote_retrieve_response_code($response);
    $data = json_decode(wp_remote_retrieve_body($response), true);

    if ($status !== 200 || empty($data['content'][0]['text'])) {
        $err = $data['error']['message'] ?? 'API error ' . $status;
        return ['error' => $err, 'content' => '', 'tokens_in' => 0, 'tokens_out' => 0, 'cost' => 0];
    }

    $content = $data['content'][0]['text'];
    $tokens_in = $data['usage']['input_tokens'] ?? 0;
    $tokens_out = $data['usage']['output_tokens'] ?? 0;

    // Cost calculation (Haiku 4.5: $1/$5 per MTok)
    $cost = ($tokens_in * 1.0 / 1000000) + ($tokens_out * 5.0 / 1000000);

    // Store assistant response
    $wpdb->insert("{$wpdb->prefix}pl_chat_messages", [
        'session_id'  => $session_id,
        'role'        => 'assistant',
        'content'     => $content,
        'tokens_in'   => $tokens_in,
        'tokens_out'  => $tokens_out,
        'response_ms' => $elapsed,
    ]);

    return [
        'error'     => null,
        'content'   => $content,
        'tokens_in' => $tokens_in,
        'tokens_out'=> $tokens_out,
        'cost'      => $cost,
    ];
}

// ============================================
// ADMIN: Dashboard
// ============================================
function plchat_admin_dashboard() {
    global $wpdb;
    $t = $wpdb->prefix . 'pl_chat_sessions';

    $today = gmdate('Y-m-d');
    $week_ago = gmdate('Y-m-d', strtotime('-7 days'));

    $stats = [
        'today_sessions' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE DATE(created_at) = %s", $today)),
        'today_messages' => (int) $wpdb->get_var($wpdb->prepare("SELECT SUM(messages_count) FROM $t WHERE DATE(created_at) = %s", $today)),
        'week_sessions'  => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE created_at >= %s", $week_ago)),
        'week_messages'  => (int) $wpdb->get_var($wpdb->prepare("SELECT SUM(messages_count) FROM $t WHERE created_at >= %s", $week_ago)),
        'total_sessions' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $t"),
        'total_messages' => (int) $wpdb->get_var("SELECT SUM(messages_count) FROM $t"),
        'total_cost'     => (float) $wpdb->get_var("SELECT SUM(estimated_cost_usd) FROM $t"),
        'conversions'    => (int) $wpdb->get_var("SELECT COUNT(*) FROM $t WHERE converted = 1"),
        'emails'         => (int) $wpdb->get_var("SELECT COUNT(*) FROM $t WHERE email_captured != ''"),
        'avg_messages'   => (float) $wpdb->get_var("SELECT AVG(messages_count) FROM $t WHERE messages_count > 0"),
        'avg_duration'   => (int) $wpdb->get_var("SELECT AVG(session_duration_s) FROM $t WHERE session_duration_s > 0"),
    ];

    // Enabled status
    $enabled = get_option('plchat_enabled') === '1';
    $has_key = !empty(get_option('plchat_api_key'));

    ?>
    <div class="wrap">
        <h1>AI Chat Dashboard</h1>

        <?php if (!$has_key): ?>
            <div class="notice notice-error"><p><strong>API key not set.</strong> <a href="<?php echo esc_url(admin_url('admin.php?page=pl-ai-chat-settings')); ?>">Configure settings</a> to enable chat.</p></div>
        <?php elseif (!$enabled): ?>
            <div class="notice notice-warning"><p>Chat is currently <strong>disabled</strong>. <a href="<?php echo esc_url(admin_url('admin.php?page=pl-ai-chat-settings')); ?>">Enable it</a> when ready.</p></div>
        <?php else: ?>
            <div class="notice notice-success"><p>Chat is <strong>active</strong> using <?php echo esc_html(get_option('plchat_model', PLCHAT_MODEL)); ?></p></div>
        <?php endif; ?>

        <style>
            .plc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin:20px 0}
            .plc-card{background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;text-align:center}
            .plc-card h2{font-size:36px;margin:0;color:#7c3aed}
            .plc-card p{margin:8px 0 0;color:#666;font-size:14px}
            .plc-card.gold h2{color:#d97706}
            .plc-card.green h2{color:#059669}
            .plc-recent{background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;margin:20px 0}
            .plc-recent table{width:100%;border-collapse:collapse}
            .plc-recent th,.plc-recent td{padding:10px;text-align:left;border-bottom:1px solid #eee}
            .plc-recent th{font-weight:600;color:#374151}
        </style>

        <div class="plc-grid">
            <div class="plc-card"><h2><?php echo esc_html($stats['today_sessions']); ?></h2><p>Chats Today</p></div>
            <div class="plc-card"><h2><?php echo esc_html($stats['today_messages']); ?></h2><p>Messages Today</p></div>
            <div class="plc-card"><h2><?php echo esc_html($stats['week_sessions']); ?></h2><p>Chats This Week</p></div>
            <div class="plc-card"><h2><?php echo esc_html(round($stats['avg_messages'], 1)); ?></h2><p>Avg Messages/Chat</p></div>
            <div class="plc-card green"><h2><?php echo esc_html($stats['emails']); ?></h2><p>Emails Captured</p></div>
            <div class="plc-card gold"><h2>$<?php echo esc_html(number_format($stats['total_cost'], 4)); ?></h2><p>Total API Cost</p></div>
        </div>

        <?php
        // Character interaction stats from tracker
        $upload_dir = wp_upload_dir();
        $tracker_base = $upload_dir['basedir'] . '/pl-tracker-data';
        $ci_stats = ['total_sessions' => 0, 'tap_sessions' => 0, 'char_taps' => 0, 'heart_taps' => 0,
            'taps_by_depth' => array_fill(0, 10, 0), 'taps_by_anim' => [], 'tappers_depth' => [], 'nontappers_depth' => [],
            'tappers_return' => 0, 'nontappers_return' => 0, 'tappers_total' => 0, 'nontappers_total' => 0];

        for ($i = 0; $i < 7; $i++) {
            $day_dir = $tracker_base . '/' . gmdate('Y-m-d', strtotime("-{$i} days"));
            if (!is_dir($day_dir)) continue;
            foreach (glob($day_dir . '/*.json') as $f) {
                $s = json_decode(file_get_contents($f), true);
                if (!$s) continue;
                $ci_stats['total_sessions']++;
                $cci = $s['char_interaction'] ?? [];
                $ct = intval($cci['char_taps'] ?? 0);
                $ht = intval($cci['heart_taps'] ?? 0);
                if (($ct + $ht) > 0) {
                    $ci_stats['tap_sessions']++;
                    $ci_stats['char_taps'] += $ct;
                    $ci_stats['heart_taps'] += $ht;
                    foreach ($cci['tap_log'] ?? [] as $tap) {
                        $di = min(9, max(0, floor(($tap['depth_pct'] ?? 0) / 10)));
                        $ci_stats['taps_by_depth'][$di]++;
                        $anim = $tap['animation'] ?? 'unknown';
                        $ci_stats['taps_by_anim'][$anim] = ($ci_stats['taps_by_anim'][$anim] ?? 0) + 1;
                    }
                    $ci_stats['tappers_depth'][] = $s['max_depth_pct'] ?? 0;
                    $ci_stats['tappers_total']++;
                    if (!empty($s['returning'])) $ci_stats['tappers_return']++;
                } else {
                    $ci_stats['nontappers_depth'][] = $s['max_depth_pct'] ?? 0;
                    $ci_stats['nontappers_total']++;
                    if (!empty($s['returning'])) $ci_stats['nontappers_return']++;
                }
            }
        }
        $tap_rate = $ci_stats['total_sessions'] > 0 ? round(($ci_stats['tap_sessions'] / $ci_stats['total_sessions']) * 100, 1) : 0;
        $avg_tapper_depth = count($ci_stats['tappers_depth']) > 0 ? round(array_sum($ci_stats['tappers_depth']) / count($ci_stats['tappers_depth']), 1) : 0;
        $avg_nontapper_depth = count($ci_stats['nontappers_depth']) > 0 ? round(array_sum($ci_stats['nontappers_depth']) / count($ci_stats['nontappers_depth']), 1) : 0;
        $tapper_return = $ci_stats['tappers_total'] > 0 ? round(($ci_stats['tappers_return'] / $ci_stats['tappers_total']) * 100, 1) : 0;
        $nontapper_return = $ci_stats['nontappers_total'] > 0 ? round(($ci_stats['nontappers_return'] / $ci_stats['nontappers_total']) * 100, 1) : 0;
        ?>

        <div class="plc-recent" style="margin-top:24px">
            <h3>Character &amp; Heart Interactions (Last 7 Days)</h3>
            <div class="plc-grid" style="margin-top:12px">
                <div class="plc-card"><h2><?php echo esc_html($ci_stats['tap_sessions']); ?></h2><p>Sessions with Taps</p></div>
                <div class="plc-card"><h2><?php echo esc_html($tap_rate); ?>%</h2><p>Tap Rate</p></div>
                <div class="plc-card"><h2><?php echo esc_html($ci_stats['char_taps']); ?></h2><p>Character Taps</p></div>
                <div class="plc-card"><h2><?php echo esc_html($ci_stats['heart_taps']); ?></h2><p>Heart Taps</p></div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:16px">
                <div>
                    <h4>Tappers vs Non-Tappers</h4>
                    <table style="width:100%;border-collapse:collapse;font-size:13px">
                        <tr style="border-bottom:1px solid #eee"><th style="text-align:left;padding:8px">Metric</th><th style="padding:8px">Tappers</th><th style="padding:8px">Non-Tappers</th></tr>
                        <tr style="border-bottom:1px solid #eee"><td style="padding:8px">Avg Scroll Depth</td><td style="padding:8px;text-align:center;font-weight:600;color:<?php echo $avg_tapper_depth > $avg_nontapper_depth ? '#059669' : '#dc2626'; ?>"><?php echo esc_html($avg_tapper_depth); ?>%</td><td style="padding:8px;text-align:center"><?php echo esc_html($avg_nontapper_depth); ?>%</td></tr>
                        <tr style="border-bottom:1px solid #eee"><td style="padding:8px">Return Rate</td><td style="padding:8px;text-align:center;font-weight:600;color:<?php echo $tapper_return > $nontapper_return ? '#059669' : '#dc2626'; ?>"><?php echo esc_html($tapper_return); ?>%</td><td style="padding:8px;text-align:center"><?php echo esc_html($nontapper_return); ?>%</td></tr>
                        <tr><td style="padding:8px">Sessions</td><td style="padding:8px;text-align:center"><?php echo esc_html($ci_stats['tappers_total']); ?></td><td style="padding:8px;text-align:center"><?php echo esc_html($ci_stats['nontappers_total']); ?></td></tr>
                    </table>
                </div>
                <div>
                    <h4>Taps by Depth Zone</h4>
                    <table style="width:100%;border-collapse:collapse;font-size:13px">
                        <?php for ($di = 0; $di < 10; $di++):
                            $zone = ($di*10) . '-' . (($di+1)*10) . '%';
                            $dcount = $ci_stats['taps_by_depth'][$di];
                            $max_depth_count = max(1, max($ci_stats['taps_by_depth']));
                            $bar_pct = round(($dcount / $max_depth_count) * 100);
                        ?>
                        <tr style="border-bottom:1px solid #f0f0f0">
                            <td style="padding:4px 8px;width:70px"><?php echo esc_html($zone); ?></td>
                            <td style="padding:4px 8px"><div style="background:#e9d5ff;height:16px;border-radius:4px;width:<?php echo $bar_pct; ?>%;min-width:<?php echo $dcount > 0 ? '4px' : '0'; ?>"></div></td>
                            <td style="padding:4px 8px;width:40px;text-align:right;color:#666"><?php echo esc_html($dcount); ?></td>
                        </tr>
                        <?php endfor; ?>
                    </table>
                </div>
            </div>

            <?php if (!empty($ci_stats['taps_by_anim'])): arsort($ci_stats['taps_by_anim']); ?>
            <div style="margin-top:16px">
                <h4>Taps by Animation</h4>
                <div style="display:flex;gap:12px;flex-wrap:wrap">
                    <?php foreach ($ci_stats['taps_by_anim'] as $anim => $acount): ?>
                    <span style="background:#f3e8ff;padding:4px 12px;border-radius:12px;font-size:13px"><?php echo esc_html($anim); ?>: <strong><?php echo esc_html($acount); ?></strong></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="plc-recent">
            <h3>Recent Chats</h3>
            <?php
            $recent = $wpdb->get_results("SELECT * FROM $t ORDER BY created_at DESC LIMIT 15");
            if ($recent): ?>
            <table>
                <tr>
                    <th>Time</th>
                    <th>Post</th>
                    <th>Device</th>
                    <th>Messages</th>
                    <th>Depth</th>
                    <th>Visit #</th>
                    <th>Cost</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
                <?php foreach ($recent as $s): ?>
                <tr>
                    <td><?php echo esc_html(human_time_diff(strtotime($s->created_at))); ?> ago</td>
                    <td><?php echo esc_html(mb_substr($s->post_title, 0, 40)); ?></td>
                    <td><?php echo esc_html($s->device); ?></td>
                    <td><?php echo esc_html($s->messages_count); ?></td>
                    <td><?php echo esc_html($s->scroll_depth); ?>%</td>
                    <td><?php echo esc_html($s->visit_number); ?></td>
                    <td>$<?php echo esc_html(number_format($s->estimated_cost_usd, 4)); ?></td>
                    <td><?php if ($s->starred): ?>*<?php endif; ?> <?php if ($s->email_captured): ?>E<?php endif; ?></td>
                    <td><a href="<?php echo esc_url(admin_url('admin.php?page=pl-ai-chat-history&session=' . $s->id)); ?>">View</a></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php else: ?>
                <p>No chats yet. The character will invite visitors to chat once enabled!</p>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

// ============================================
// ADMIN: Chat History (list + single view)
// ============================================
function plchat_admin_history() {
    global $wpdb;

    // Handle bulk delete all
    if (!empty($_POST['plchat_delete_all']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'plchat_delete_all')) {
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}pl_chat_messages");
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}pl_chat_sessions");
        echo '<div class="notice notice-success"><p>All chat history deleted.</p></div>';
    }

    // Handle single session delete
    if (!empty($_GET['delete']) && !empty($_GET['_wpnonce'])) {
        $del_id = absint($_GET['delete']);
        if (wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'plchat_delete_' . $del_id)) {
            $wpdb->delete("{$wpdb->prefix}pl_chat_messages", ['session_id' => $del_id]);
            $wpdb->delete("{$wpdb->prefix}pl_chat_sessions", ['id' => $del_id]);
            echo '<div class="notice notice-success"><p>Chat #' . esc_html($del_id) . ' deleted.</p></div>';
        }
    }

    // Single session view
    $session_id = absint($_GET['session'] ?? 0);
    if ($session_id) {
        plchat_admin_session_view($session_id);
        return;
    }

    // List view
    $page = max(1, absint($_GET['paged'] ?? 1));
    $per_page = 25;
    $offset = ($page - 1) * $per_page;

    $t = $wpdb->prefix . 'pl_chat_sessions';
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t");
    $sessions = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t ORDER BY created_at DESC LIMIT %d OFFSET %d", $per_page, $offset));
    $total_pages = ceil($total / $per_page);

    ?>
    <div class="wrap">
        <h1 style="display:inline">Chat History (<?php echo esc_html($total); ?> sessions)</h1>
        <?php if ($total > 0): ?>
        <form method="post" style="display:inline;margin-left:12px" onsubmit="return confirm('Delete ALL chat history? This cannot be undone!')">
            <?php wp_nonce_field('plchat_delete_all'); ?>
            <input type="hidden" name="plchat_delete_all" value="1">
            <button type="submit" class="button" style="color:#dc2626;border-color:#dc2626">Delete All Chats</button>
        </form>
        <?php endif; ?>

        <style>
            .plc-hist{background:#fff;border:1px solid #ddd;border-radius:8px;overflow:hidden;margin:20px 0}
            .plc-hist table{width:100%;border-collapse:collapse}
            .plc-hist th,.plc-hist td{padding:12px;text-align:left;border-bottom:1px solid #f0f0f0;font-size:13px}
            .plc-hist th{background:#f8f8f8;font-weight:600}
            .plc-hist tr:hover{background:#faf5ff}
            .plc-badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600}
            .plc-badge.mobile{background:#dbeafe;color:#1d4ed8}
            .plc-badge.desktop{background:#f3e8ff;color:#7c3aed}
            .plc-badge.reader{background:#d1fae5;color:#065f46}
            .plc-badge.scanner{background:#fef3c7;color:#92400e}
            .plc-pag{margin:20px 0;text-align:center}
            .plc-pag a{padding:8px 14px;margin:0 2px;border:1px solid #ddd;border-radius:4px;text-decoration:none;color:#374151}
            .plc-pag a.current{background:#7c3aed;color:#fff;border-color:#7c3aed}
        </style>

        <div class="plc-hist">
            <table>
                <tr>
                    <th>ID</th>
                    <th>Time</th>
                    <th>Visitor</th>
                    <th>Post</th>
                    <th>Device</th>
                    <th>Pattern</th>
                    <th>Messages</th>
                    <th>Duration</th>
                    <th>Depth</th>
                    <th>Cost</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
                <?php foreach ($sessions as $s): ?>
                <tr>
                    <td><a href="<?php echo esc_url(admin_url('admin.php?page=pl-ai-chat-history&session=' . $s->id)); ?>">#<?php echo esc_html($s->id); ?></a></td>
                    <td><?php echo esc_html(date('M j, g:ia', strtotime($s->created_at))); ?></td>
                    <td><code><?php echo esc_html(substr($s->visitor_id, 0, 10)); ?></code>
                        <?php if ($s->visit_number > 1): ?><small>(visit #<?php echo esc_html($s->visit_number); ?>)</small><?php endif; ?>
                    </td>
                    <td><?php echo esc_html(mb_substr($s->post_title, 0, 35)); ?></td>
                    <td><span class="plc-badge <?php echo esc_attr($s->device); ?>"><?php echo esc_html($s->device); ?></span></td>
                    <td><span class="plc-badge <?php echo esc_attr($s->scroll_pattern); ?>"><?php echo esc_html($s->scroll_pattern); ?></span></td>
                    <td><?php echo esc_html($s->messages_count); ?></td>
                    <td><?php echo $s->session_duration_s ? esc_html(round($s->session_duration_s / 60, 1) . 'm') : '-'; ?></td>
                    <td><?php echo esc_html($s->scroll_depth); ?>%</td>
                    <td>$<?php echo esc_html(number_format($s->estimated_cost_usd, 4)); ?></td>
                    <td>
                        <?php if ($s->starred): ?>*<?php endif; ?>
                        <?php if ($s->email_captured): ?>E<?php endif; ?>
                        <?php if ($s->converted): ?>C<?php endif; ?>
                    </td>
                    <td><a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=pl-ai-chat-history&delete=' . $s->id), 'plchat_delete_' . $s->id)); ?>" onclick="return confirm('Delete this chat permanently?')" style="color:#dc2626">Delete</a></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="plc-pag">
            <?php for ($i = 1; $i <= min($total_pages, 20); $i++): ?>
                <a href="<?php echo esc_url(add_query_arg('paged', $i)); ?>" class="<?php echo $i === $page ? 'current' : ''; ?>"><?php echo esc_html($i); ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php
}

// ============================================
// ADMIN: Single Session View (full chat)
// ============================================
function plchat_admin_session_view($session_id) {
    global $wpdb;

    $s = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}pl_chat_sessions WHERE id = %d", $session_id
    ));
    if (!$s) { echo '<div class="wrap"><h1>Session not found</h1></div>'; return; }

    $messages = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}pl_chat_messages WHERE session_id = %d ORDER BY id ASC", $session_id
    ));

    ?>
    <div class="wrap">
        <h1>
            <a href="<?php echo esc_url(admin_url('admin.php?page=pl-ai-chat-history')); ?>">&#8592; Back</a> &nbsp;
            Chat #<?php echo esc_html($session_id); ?>
            <?php if ($s->starred): ?> *<?php endif; ?>
            <?php if ($s->email_captured): ?> (<?php echo esc_html($s->email_captured); ?>)<?php endif; ?>
            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=pl-ai-chat-history&delete=' . $session_id), 'plchat_delete_' . $session_id)); ?>" onclick="return confirm('Delete this entire chat session and all messages?')" class="button" style="color:#dc2626;border-color:#dc2626;margin-left:12px">Delete Chat</a>
        </h1>

        <style>
            .plc-meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;background:#f8f8f8;padding:16px;border-radius:8px;margin:16px 0;font-size:13px}
            .plc-meta div{line-height:1.6}
            .plc-meta strong{color:#374151}
            .plc-chat{max-width:600px;margin:20px 0;background:#fff;border:1px solid #ddd;border-radius:12px;padding:20px}
            .plc-msg{margin:12px 0;padding:10px 14px;border-radius:16px;max-width:85%;font-size:14px;line-height:1.5}
            .plc-msg.assistant{background:#f3e8ff;border:1px solid #e9d5ff;margin-right:auto}
            .plc-msg.user{background:#dbeafe;border:1px solid #bfdbfe;margin-left:auto;text-align:right}
            .plc-msg.system{background:#f0f0f0;font-size:11px;color:#666;margin:0 auto;max-width:100%;white-space:pre-wrap;max-height:100px;overflow:hidden;cursor:pointer}
            .plc-msg.system.expanded{max-height:none}
            .plc-msg-time{font-size:10px;color:#999;margin-top:4px}
            .plc-msg-meta{font-size:10px;color:#999}
        </style>

        <div class="plc-meta">
            <div><strong>Post:</strong> <?php echo esc_html($s->post_title); ?></div>
            <div><strong>Device:</strong> <?php echo esc_html($s->device); ?></div>
            <div><strong>Location:</strong> <?php echo esc_html($s->city . ', ' . $s->country); ?></div>
            <div><strong>Visit #:</strong> <?php echo esc_html($s->visit_number); ?></div>
            <div><strong>Scroll:</strong> <?php echo esc_html($s->scroll_depth); ?>% (<?php echo esc_html($s->scroll_pattern); ?>)</div>
            <div><strong>Referrer:</strong> <?php echo esc_html(wp_parse_url($s->referrer, PHP_URL_HOST) ?: $s->referrer); ?></div>
            <div><strong>Char taps:</strong> <?php echo esc_html($s->char_taps_before); ?> / Heart: <?php echo esc_html($s->heart_taps_before); ?></div>
            <div><strong>Messages:</strong> <?php echo esc_html($s->messages_count); ?></div>
            <div><strong>Duration:</strong> <?php echo $s->session_duration_s ? esc_html(round($s->session_duration_s / 60, 1) . ' min') : '-'; ?></div>
            <div><strong>Cost:</strong> $<?php echo esc_html(number_format($s->estimated_cost_usd, 4)); ?></div>
            <div><strong>Started:</strong> <?php echo esc_html(date('M j, Y g:ia', strtotime($s->created_at))); ?></div>
            <div><strong>Tokens:</strong> <?php echo esc_html(number_format($s->total_tokens_in)); ?> in / <?php echo esc_html(number_format($s->total_tokens_out)); ?> out</div>
        </div>

        <div class="plc-chat">
            <h3>Conversation</h3>
            <?php foreach ($messages as $m): ?>
                <div class="plc-msg <?php echo esc_attr($m->role); ?>"
                     <?php if ($m->role === 'system'): ?>onclick="this.classList.toggle('expanded')" title="Click to expand system prompt"<?php endif; ?>>
                    <?php echo nl2br(esc_html($m->content)); ?>
                    <div class="plc-msg-time"><?php echo esc_html(date('g:ia', strtotime($m->created_at))); ?>
                        <?php if ($m->tokens_out): ?>
                            <span class="plc-msg-meta">&middot; <?php echo esc_html($m->tokens_in); ?>+<?php echo esc_html($m->tokens_out); ?> tokens &middot; <?php echo esc_html($m->response_ms); ?>ms</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}

// ============================================
// ADMIN: Live Chats (active sessions)
// ============================================
function plchat_admin_live() {
    global $wpdb;
    $t = $wpdb->prefix . 'pl_chat_sessions';

    // Sessions updated in last 5 minutes
    $cutoff = gmdate('Y-m-d H:i:s', strtotime('-5 minutes'));
    $live = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE last_message_at >= %s ORDER BY last_message_at DESC", $cutoff));

    ?>
    <div class="wrap">
        <h1>Live Chats (<?php echo count($live); ?> active)</h1>
        <p>Sessions with messages in the last 5 minutes. <em>Auto-refreshes every 10 seconds.</em></p>

        <style>
            .plc-live{background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;margin:16px 0}
            .plc-live-card{border:1px solid #e5e7eb;border-radius:8px;padding:16px;margin:12px 0;display:flex;justify-content:space-between;align-items:center}
            .plc-live-card:hover{border-color:#7c3aed;background:#faf5ff}
            .plc-pulse{width:10px;height:10px;border-radius:50%;background:#ef4444;display:inline-block;animation:plcpulse 1.5s infinite}
            @keyframes plcpulse{0%,100%{opacity:1}50%{opacity:.3}}
        </style>

        <div class="plc-live">
            <?php if (empty($live)): ?>
                <p>No active chats right now.</p>
            <?php else: ?>
                <?php foreach ($live as $s): ?>
                <div class="plc-live-card">
                    <div>
                        <span class="plc-pulse"></span>
                        <strong><?php echo esc_html(mb_substr($s->post_title, 0, 40)); ?></strong>
                        &mdash; <?php echo esc_html($s->messages_count); ?> messages
                        &mdash; <?php echo esc_html($s->device); ?>
                        <?php if ($s->city): ?> &mdash; <?php echo esc_html($s->city); ?><?php endif; ?>
                    </div>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=pl-ai-chat-history&session=' . $s->id)); ?>" class="button">View Chat</a>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <script>setTimeout(function(){ location.reload(); }, 10000);</script>
    </div>
    <?php
}

// ============================================
// ADMIN: Analytics
// ============================================
function plchat_admin_analytics() {
    global $wpdb;
    $t = $wpdb->prefix . 'pl_chat_sessions';
    $days = absint($_GET['days'] ?? 7);

    $since = gmdate('Y-m-d', strtotime("-{$days} days"));

    // Daily breakdown
    $daily = $wpdb->get_results($wpdb->prepare("
        SELECT DATE(created_at) as day,
            COUNT(*) as sessions,
            SUM(messages_count) as messages,
            COUNT(DISTINCT visitor_id) as visitors,
            AVG(messages_count) as avg_msgs,
            AVG(session_duration_s) as avg_dur,
            SUM(estimated_cost_usd) as cost,
            SUM(CASE WHEN converted=1 THEN 1 ELSE 0 END) as conversions
        FROM $t WHERE created_at >= %s
        GROUP BY DATE(created_at) ORDER BY day DESC
    ", $since));

    // Top posts by chat engagement
    $top_posts = $wpdb->get_results($wpdb->prepare("
        SELECT post_title, COUNT(*) as chats, AVG(messages_count) as avg_msgs,
            SUM(CASE WHEN converted=1 THEN 1 ELSE 0 END) as conversions
        FROM $t WHERE created_at >= %s AND post_title != ''
        GROUP BY post_title ORDER BY chats DESC LIMIT 15
    ", $since));

    // Device breakdown
    $devices = $wpdb->get_results($wpdb->prepare("
        SELECT device, COUNT(*) as chats, AVG(messages_count) as avg_msgs,
            SUM(CASE WHEN converted=1 THEN 1 ELSE 0 END) as conversions
        FROM $t WHERE created_at >= %s
        GROUP BY device
    ", $since));

    // Scroll pattern breakdown
    $patterns = $wpdb->get_results($wpdb->prepare("
        SELECT scroll_pattern, COUNT(*) as chats, AVG(messages_count) as avg_msgs
        FROM $t WHERE created_at >= %s AND scroll_pattern != ''
        GROUP BY scroll_pattern
    ", $since));

    // Return visitors vs new
    $loyalty = $wpdb->get_results($wpdb->prepare("
        SELECT
            CASE WHEN visit_number = 1 THEN 'New' WHEN visit_number <= 3 THEN 'Returning' ELSE 'Loyal (4+)' END as tier,
            COUNT(*) as chats, AVG(messages_count) as avg_msgs
        FROM $t WHERE created_at >= %s
        GROUP BY tier
    ", $since));

    // Total cost
    $total_cost = (float) $wpdb->get_var($wpdb->prepare("SELECT SUM(estimated_cost_usd) FROM $t WHERE created_at >= %s", $since));
    $total_tokens = (int) $wpdb->get_var($wpdb->prepare("SELECT SUM(total_tokens_in + total_tokens_out) FROM $t WHERE created_at >= %s", $since));
    $total_sessions_count = 0;
    foreach ($daily as $d) {
        $total_sessions_count += $d->sessions;
    }

    ?>
    <div class="wrap">
        <h1>Chat Analytics</h1>

        <div style="margin:16px 0">
            <?php foreach ([7,14,30] as $d): ?>
                <a href="<?php echo esc_url(add_query_arg('days', $d)); ?>"
                   class="button <?php echo $days === $d ? 'button-primary' : ''; ?>"><?php echo esc_html($d); ?> days</a>
            <?php endforeach; ?>
        </div>

        <style>
            .plc-analytics{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin:16px 0}
            .plc-analytics>div{background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px}
            .plc-analytics h3{margin-top:0;color:#374151}
            .plc-analytics table{width:100%;border-collapse:collapse;font-size:13px}
            .plc-analytics th,.plc-analytics td{padding:8px;text-align:left;border-bottom:1px solid #f0f0f0}
            .plc-analytics th{font-weight:600;color:#6b7280}
            .plc-cost-box{background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:16px;margin:16px 0;display:inline-block}
        </style>

        <div class="plc-cost-box">
            <strong>API Cost (<?php echo esc_html($days); ?>d):</strong> $<?php echo esc_html(number_format($total_cost, 4)); ?>
            &middot; <strong>Tokens:</strong> <?php echo esc_html(number_format($total_tokens)); ?>
            &middot; <strong>Avg cost/chat:</strong> $<?php echo esc_html($total_sessions_count > 0 ? number_format($total_cost / $total_sessions_count, 4) : '0.0000'); ?>
        </div>

        <div class="plc-analytics">
            <div>
                <h3>Daily Breakdown</h3>
                <table>
                    <tr><th>Date</th><th>Chats</th><th>Messages</th><th>Visitors</th><th>Avg Msgs</th><th>Conversions</th><th>Cost</th></tr>
                    <?php foreach ($daily as $d): ?>
                    <tr>
                        <td><?php echo esc_html(date('M j', strtotime($d->day))); ?></td>
                        <td><?php echo esc_html($d->sessions); ?></td>
                        <td><?php echo esc_html($d->messages ?: 0); ?></td>
                        <td><?php echo esc_html($d->visitors); ?></td>
                        <td><?php echo esc_html(round($d->avg_msgs, 1)); ?></td>
                        <td><?php echo esc_html($d->conversions); ?></td>
                        <td>$<?php echo esc_html(number_format($d->cost ?: 0, 4)); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>

            <div>
                <h3>Top Posts</h3>
                <table>
                    <tr><th>Post</th><th>Chats</th><th>Avg Msgs</th><th>Converts</th></tr>
                    <?php foreach ($top_posts as $p): ?>
                    <tr>
                        <td><?php echo esc_html(mb_substr($p->post_title, 0, 40)); ?></td>
                        <td><?php echo esc_html($p->chats); ?></td>
                        <td><?php echo esc_html(round($p->avg_msgs, 1)); ?></td>
                        <td><?php echo esc_html($p->conversions); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>

            <div>
                <h3>By Device</h3>
                <table>
                    <tr><th>Device</th><th>Chats</th><th>Avg Messages</th><th>Conversions</th></tr>
                    <?php foreach ($devices as $d): ?>
                    <tr>
                        <td><?php echo esc_html($d->device); ?></td>
                        <td><?php echo esc_html($d->chats); ?></td>
                        <td><?php echo esc_html(round($d->avg_msgs, 1)); ?></td>
                        <td><?php echo esc_html($d->conversions); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>

            <div>
                <h3>By Scroll Pattern</h3>
                <table>
                    <tr><th>Pattern</th><th>Chats</th><th>Avg Messages</th></tr>
                    <?php foreach ($patterns as $p): ?>
                    <tr>
                        <td><?php echo esc_html($p->scroll_pattern); ?></td>
                        <td><?php echo esc_html($p->chats); ?></td>
                        <td><?php echo esc_html(round($p->avg_msgs, 1)); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>

                <h3 style="margin-top:20px">By Visit Loyalty</h3>
                <table>
                    <tr><th>Tier</th><th>Chats</th><th>Avg Messages</th></tr>
                    <?php foreach ($loyalty as $l): ?>
                    <tr>
                        <td><?php echo esc_html($l->tier); ?></td>
                        <td><?php echo esc_html($l->chats); ?></td>
                        <td><?php echo esc_html(round($l->avg_msgs, 1)); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
    </div>
    <?php
}

// ============================================
// ADMIN: Settings
// ============================================
function plchat_admin_settings() {
    ?>
    <div class="wrap">
        <h1>AI Chat Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields('plchat_settings'); ?>

            <style>
                .plc-settings{max-width:700px}
                .plc-settings .form-table th{width:180px}
                .plc-settings textarea{width:100%;font-family:monospace;font-size:13px}
                .plc-settings input[type=text],.plc-settings input[type=password]{width:100%}
                .plc-help{color:#6b7280;font-size:12px;margin-top:4px}
            </style>

            <div class="plc-settings">
                <table class="form-table">
                    <tr>
                        <th>Enable Chat</th>
                        <td>
                            <label>
                                <input type="checkbox" name="plchat_enabled" value="1" <?php checked(get_option('plchat_enabled'), '1'); ?>>
                                Enable AI chat on blog posts
                            </label>
                            <p class="plc-help">When enabled, visitors can tap the character to start a conversation.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Anthropic API Key</th>
                        <td>
                            <input type="password" name="plchat_api_key" value="<?php echo esc_attr(get_option('plchat_api_key')); ?>" placeholder="sk-ant-...">
                            <p class="plc-help">Get your key at <a href="https://console.anthropic.com/" target="_blank">console.anthropic.com</a></p>
                        </td>
                    </tr>
                    <tr>
                        <th>Model</th>
                        <td>
                            <select name="plchat_model">
                                <option value="claude-haiku-4-5-20251001" <?php selected(get_option('plchat_model', PLCHAT_MODEL), 'claude-haiku-4-5-20251001'); ?>>
                                    Haiku 4.5 — $1/$5 per MTok (recommended)
                                </option>
                                <option value="claude-sonnet-4-5-20250929" <?php selected(get_option('plchat_model'), 'claude-sonnet-4-5-20250929'); ?>>
                                    Sonnet 4.5 — $3/$15 per MTok (smarter, 3x cost)
                                </option>
                            </select>
                            <p class="plc-help">Haiku is fast and cheap — perfect for short chat. Sonnet for deeper conversations.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Character Name</th>
                        <td>
                            <input type="text" name="plchat_character_name" value="<?php echo esc_attr(get_option('plchat_character_name', 'Cheer')); ?>">
                            <p class="plc-help">The name used in the chat UI and system prompt.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Max Messages/Session</th>
                        <td>
                            <input type="number" name="plchat_max_messages" value="<?php echo esc_attr(get_option('plchat_max_messages', PLCHAT_MAX_MESSAGES)); ?>" min="4" max="50" style="width:80px">
                            <p class="plc-help">Limits API cost. 20 messages = ~$0.01-0.03 per chat session.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Taps to Show Chat Prompt</th>
                        <td>
                            <input type="number" name="plchat_taps_to_prompt" value="<?php echo esc_attr(get_option('plchat_taps_to_prompt', '3')); ?>" min="1" max="20" style="width:80px">
                            <p class="plc-help">Character taps before "Want to chat?" speech bubble appears.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Taps to Open Chat</th>
                        <td>
                            <input type="number" name="plchat_taps_to_open" value="<?php echo esc_attr(get_option('plchat_taps_to_open', '4')); ?>" min="2" max="20" style="width:80px">
                            <p class="plc-help">Character taps to auto-open chat panel. Must be more than prompt taps.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Heart Taps to Open Chat</th>
                        <td>
                            <input type="number" name="plchat_heart_taps_to_open" value="<?php echo esc_attr(get_option('plchat_heart_taps_to_open', '3')); ?>" min="1" max="20" style="width:80px">
                            <p class="plc-help">Heart taps needed to open chat.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Proactive Invite Delay (seconds)</th>
                        <td>
                            <input type="number" name="plchat_proactive_delay" value="<?php echo esc_attr(get_option('plchat_proactive_delay', '60')); ?>" min="0" max="300" style="width:80px">
                            <p class="plc-help">Seconds before "I can chat!" appears. Set 0 to disable proactive invite.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Show on Mobile</th>
                        <td>
                            <label>
                                <input type="checkbox" name="plchat_show_on_mobile" value="1" <?php checked(get_option('plchat_show_on_mobile', '1'), '1'); ?>>
                                Enable chat on mobile devices
                            </label>
                            <p class="plc-help">Uncheck to disable chat on phones/tablets (character still shows, just no chat).</p>
                        </td>
                    </tr>
                    <tr>
                        <th>System Prompt</th>
                        <td>
                            <textarea name="plchat_system_prompt" rows="18"><?php echo esc_textarea(get_option('plchat_system_prompt', plchat_default_system_prompt())); ?></textarea>
                            <p class="plc-help">Use {name} for character name. Visitor context is appended automatically. This controls the AI's personality and behavior.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Save Settings'); ?>
            </div>
        </form>
    </div>
    <?php
}

// ============================================
// FRONTEND: Pass chat config to JS
// ============================================
add_action('wp_footer', function() {
    if (!is_singular() || get_option('plchat_enabled') !== '1' || empty(get_option('plchat_api_key'))) return;

    $post_id = get_the_ID();
    $categories = get_the_category($post_id);
    $cat_name = !empty($categories) ? $categories[0]->name : '';

    ?>
    <script>
    window.__plChat = {
        enabled: true,
        endpoint: <?php echo wp_json_encode(esc_url_raw(rest_url('pl-chat/v1'))); ?>,
        pid: <?php echo intval($post_id); ?>,
        pt: <?php echo wp_json_encode(get_the_title($post_id)); ?>,
        pc: <?php echo wp_json_encode($cat_name); ?>,
        name: <?php echo wp_json_encode(get_option('plchat_character_name', 'Cheer')); ?>,
        tapsToPrompt: <?php echo intval(get_option('plchat_taps_to_prompt', 3)); ?>,
        tapsToOpen: <?php echo intval(get_option('plchat_taps_to_open', 4)); ?>,
        heartTapsToOpen: <?php echo intval(get_option('plchat_heart_taps_to_open', 3)); ?>,
        proactiveDelay: <?php echo intval(get_option('plchat_proactive_delay', 60)); ?>,
        showOnMobile: <?php echo intval(get_option('plchat_show_on_mobile', 1)); ?>
    };
    </script>
    <?php
}, 998);
