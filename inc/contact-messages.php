<?php
/**
 * PinLightning Contact Messages — Modern contact form with admin dashboard.
 *
 * @package PinLightning
 * @since 1.0.0
 */

if (!defined('ABSPATH')) exit;

// ─── DATABASE TABLE ───
function pl_messages_create_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'pl_messages';
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    dbDelta("CREATE TABLE {$table} (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        email VARCHAR(255) NOT NULL,
        subject VARCHAR(255) DEFAULT '',
        message TEXT NOT NULL,

        -- Visitor context
        page_url VARCHAR(500) DEFAULT '',
        visitor_id VARCHAR(100) DEFAULT '',
        device VARCHAR(20) DEFAULT '',
        browser VARCHAR(100) DEFAULT '',
        country VARCHAR(100) DEFAULT '',
        city VARCHAR(100) DEFAULT '',
        ip_address VARCHAR(45) DEFAULT '',

        -- Management
        status ENUM('unread','read','replied','archived','spam') DEFAULT 'unread',
        admin_notes TEXT,
        replied_at DATETIME NULL,

        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

        INDEX idx_status (status),
        INDEX idx_created (created_at),
        INDEX idx_email (email)
    ) {$charset}");
}

add_action('init', function() {
    global $wpdb;
    $table = $wpdb->prefix . 'pl_messages';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
        pl_messages_create_table();
    }
});

// ─── REST ENDPOINT ───
add_action('rest_api_init', function() {
    register_rest_route('pl/v1', '/contact', [
        'methods' => 'POST',
        'callback' => 'pl_contact_submit',
        'permission_callback' => '__return_true',
    ]);
});

function pl_contact_submit($request) {
    global $wpdb;
    $table = $wpdb->prefix . 'pl_messages';
    $body = $request->get_json_params();

    // Validate
    $name = sanitize_text_field($body['name'] ?? '');
    $email = sanitize_email($body['email'] ?? '');
    $subject = sanitize_text_field($body['subject'] ?? '');
    $message = sanitize_textarea_field($body['message'] ?? '');

    if (empty($name)) return new WP_REST_Response(['error' => 'Name is required'], 400);
    if (empty($email) || !is_email($email)) return new WP_REST_Response(['error' => 'Valid email is required'], 400);
    if (empty($message)) return new WP_REST_Response(['error' => 'Message is required'], 400);
    if (strlen($message) < 10) return new WP_REST_Response(['error' => 'Message is too short'], 400);

    // Simple honeypot check
    if (!empty($body['website_url'])) {
        return new WP_REST_Response(['success' => true, 'message' => 'Thank you!']); // Silently reject bots
    }

    // Rate limit: max 3 messages per IP per hour
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $rate_key = 'pl_msg_rate_' . md5($ip);
    $recent = get_transient($rate_key) ?: 0;
    if ($recent >= 3) {
        return new WP_REST_Response(['error' => 'Too many messages. Please try again later.'], 429);
    }
    set_transient($rate_key, $recent + 1, HOUR_IN_SECONDS);

    // Geo lookup
    $country = '';
    $city = '';
    if ($ip && $ip !== '127.0.0.1' && $ip !== '::1') {
        $geo_cache = 'pl_geo_' . md5($ip);
        $geo = get_transient($geo_cache);
        if ($geo === false) {
            $resp = wp_remote_get("http://ip-api.com/json/{$ip}?fields=country,city", ['timeout' => 2]);
            if (!is_wp_error($resp)) {
                $geo = json_decode(wp_remote_retrieve_body($resp), true);
                if (!empty($geo['country'])) set_transient($geo_cache, $geo, DAY_IN_SECONDS);
            }
        }
        $country = $geo['country'] ?? '';
        $city = $geo['city'] ?? '';
    }

    // Insert
    $result = $wpdb->insert($table, [
        'name' => $name,
        'email' => $email,
        'subject' => $subject,
        'message' => $message,
        'page_url' => esc_url_raw($body['page_url'] ?? ''),
        'visitor_id' => sanitize_text_field($body['visitor_id'] ?? ''),
        'device' => sanitize_text_field($body['device'] ?? ''),
        'browser' => sanitize_text_field($body['browser'] ?? ''),
        'country' => $country,
        'city' => $city,
        'ip_address' => $ip,
        'status' => 'unread',
    ]);

    if ($result === false) {
        return new WP_REST_Response(['error' => 'Something went wrong. Please try again.'], 500);
    }

    // Email notification to admin
    $admin_email = get_option('admin_email');
    $site_name = get_bloginfo('name');
    $email_subject = "[{$site_name}] New message from {$name}" . ($subject ? ": {$subject}" : '');
    $email_body = "New contact form message:\n\n"
        . "Name: {$name}\n"
        . "Email: {$email}\n"
        . "Subject: {$subject}\n\n"
        . "Message:\n{$message}\n\n"
        . "---\n"
        . "Device: " . ($body['device'] ?? '') . "\n"
        . "Country: {$country}" . ($city ? ", {$city}" : '') . "\n"
        . "View in dashboard: " . admin_url('admin.php?page=pl-messages');

    wp_mail($admin_email, $email_subject, $email_body, [
        'From: ' . $site_name . ' <' . $admin_email . '>',
        'Reply-To: ' . $name . ' <' . $email . '>',
    ]);

    return new WP_REST_Response([
        'success' => true,
        'message' => 'Message sent! We\'ll get back to you soon.',
    ]);
}

// ─── ADMIN MENU (Top-level, visible from main dashboard) ───
add_action('admin_menu', function() {
    global $wpdb;
    $table = $wpdb->prefix . 'pl_messages';
    $unread = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='unread'") ?: 0;
    $badge = $unread > 0 ? " <span class='update-plugins count-{$unread}'><span class='plugin-count'>{$unread}</span></span>" : '';

    add_menu_page(
        'Messages',
        "\xF0\x9F\x93\xAC Messages" . $badge,
        'manage_options',
        'pl-messages',
        'pl_messages_page',
        'dashicons-email-alt',
        26
    );
});

function pl_messages_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'pl_messages';

    // Handle single message view
    if (isset($_GET['view'])) {
        pl_message_detail_page();
        return;
    }

    // Handle single delete
    if (isset($_GET['delete_msg'])) {
        $del_id = absint($_GET['delete_msg']);
        if (wp_verify_nonce($_GET['_wpnonce'] ?? '', 'pl_delete_msg_' . $del_id)) {
            $wpdb->delete($table, ['id' => $del_id]);
            echo '<div class="notice notice-success"><p>Message deleted.</p></div>';
        }
    }

    // Handle bulk actions
    if (isset($_POST['pl_msg_action']) && wp_verify_nonce($_POST['pl_msg_nonce'] ?? '', 'pl_msg_manage')) {
        $action = sanitize_text_field($_POST['pl_msg_action']);
        $ids = array_map('absint', $_POST['msg_ids'] ?? []);
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            switch ($action) {
                case 'read': $wpdb->query($wpdb->prepare("UPDATE {$table} SET status='read' WHERE id IN ({$placeholders})", ...$ids)); break;
                case 'archive': $wpdb->query($wpdb->prepare("UPDATE {$table} SET status='archived' WHERE id IN ({$placeholders})", ...$ids)); break;
                case 'spam': $wpdb->query($wpdb->prepare("UPDATE {$table} SET status='spam' WHERE id IN ({$placeholders})", ...$ids)); break;
                case 'delete': $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id IN ({$placeholders})", ...$ids)); break;
            }
            echo '<div class="notice notice-success"><p>Done.</p></div>';
        }
    }

    // Filters
    $status = sanitize_text_field($_GET['status'] ?? 'all');
    $search = sanitize_text_field($_GET['s'] ?? '');
    $paged = max(1, absint($_GET['paged'] ?? 1));
    $per_page = 30;
    $offset = ($paged - 1) * $per_page;

    $where = "WHERE 1=1";
    $args = [];
    if ($status !== 'all') { $where .= " AND status=%s"; $args[] = $status; }
    if ($search) {
        $where .= " AND (name LIKE %s OR email LIKE %s OR message LIKE %s OR subject LIKE %s)";
        $like = '%' . $search . '%';
        $args[] = $like; $args[] = $like; $args[] = $like; $args[] = $like;
    }

    $count_query = "SELECT COUNT(*) FROM {$table} {$where}";
    $total = $args ? $wpdb->get_var($wpdb->prepare($count_query, ...$args)) : $wpdb->get_var($count_query);

    $data_query = "SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";
    $data_args = array_merge($args, [$per_page, $offset]);
    $messages = $wpdb->get_results($wpdb->prepare($data_query, ...$data_args));
    $total_pages = ceil($total / $per_page);

    // Status counts
    $counts = [
        'all' => $wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
        'unread' => $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='unread'"),
        'read' => $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='read'"),
        'replied' => $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='replied'"),
        'archived' => $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='archived'"),
        'spam' => $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='spam'"),
    ];

    ?>
    <div class="wrap" style="max-width:1200px">
        <style>
            .plm-tabs{display:flex;gap:4px;margin-bottom:16px;border-bottom:2px solid #e5e7eb;padding-bottom:0}
            .plm-tab{padding:8px 16px;font-size:13px;color:#666;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;transition:all .15s}
            .plm-tab:hover{color:#111}
            .plm-tab.active{color:#111;border-color:#111;font-weight:600}
            .plm-tab .count{background:#e5e7eb;color:#555;padding:1px 7px;border-radius:10px;font-size:11px;margin-left:4px}
            .plm-tab.active .count{background:#111;color:#fff}
            .plm-unread .count{background:#ef4444;color:#fff}

            .plm-table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden}
            .plm-table th{text-align:left;padding:10px 14px;background:#f9fafb;font-size:11px;text-transform:uppercase;color:#555;letter-spacing:.5px}
            .plm-table td{padding:10px 14px;border-top:1px solid #f3f4f6;font-size:13px}
            .plm-table tr:hover td{background:#fafafa}
            .plm-table tr.unread td{background:#eff6ff;font-weight:500}
            .plm-table tr.unread:hover td{background:#dbeafe}

            .plm-badge{display:inline-block;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600}
            .plm-preview{color:#888;font-weight:400;display:block;margin-top:2px;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:400px}
            .plm-actions{display:flex;gap:6px;margin-bottom:14px;align-items:center}
            .plm-actions select,.plm-actions button,.plm-actions input{padding:6px 12px;border:1px solid #ddd;border-radius:8px;font-size:13px}
            .plm-pagination{display:flex;gap:4px;justify-content:center;margin-top:16px}
            .plm-pagination a{padding:5px 12px;border:1px solid #ddd;border-radius:6px;text-decoration:none;color:#555;font-size:13px}
            .plm-pagination a.current{background:#111;color:#fff;border-color:#111}
        </style>

        <h1>&#x1F4EC; Messages</h1>

        <!-- Status Tabs -->
        <div class="plm-tabs">
            <?php
            $tab_labels = ['all' => 'All', 'unread' => 'Unread', 'read' => 'Read', 'replied' => 'Replied', 'archived' => 'Archived', 'spam' => 'Spam'];
            foreach ($tab_labels as $key => $label) :
                $is_active = ($status === $key);
                $extra_class = ($key === 'unread' && $counts['unread'] > 0) ? ' plm-unread' : '';
            ?>
            <a href="<?php echo esc_url(add_query_arg(['status' => $key, 'paged' => 1])); ?>"
                class="plm-tab<?php echo $is_active ? ' active' : ''; ?><?php echo $extra_class; ?>">
                <?php echo $label; ?>
                <?php if ($counts[$key] > 0) : ?>
                    <span class="count"><?php echo $counts[$key]; ?></span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- Search (separate form) -->
        <form method="get" class="plm-actions" style="justify-content:flex-end">
            <input type="hidden" name="page" value="pl-messages" />
            <input type="hidden" name="status" value="<?php echo esc_attr($status); ?>" />
            <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search messages..." style="width:220px" />
            <button type="submit" class="button">Search</button>
        </form>

        <!-- Bulk Actions -->
        <form method="post" id="plmForm">
        <?php wp_nonce_field('pl_msg_manage', 'pl_msg_nonce'); ?>
        <div class="plm-actions">
            <select name="pl_msg_action">
                <option value="">Bulk Actions</option>
                <option value="read">Mark Read</option>
                <option value="archive">Archive</option>
                <option value="spam">Spam</option>
                <option value="delete">Delete</option>
            </select>
            <button type="submit" class="button" onclick="return confirm('Apply to selected?')">Apply</button>
        </div>

        <!-- Messages Table -->
        <table class="plm-table">
            <thead>
                <tr>
                    <th style="width:30px"><input type="checkbox" id="plmCheckAll" /></th>
                    <th>From</th>
                    <th>Subject & Message</th>
                    <th>Location</th>
                    <th>Device</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th style="width:60px">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($messages)) : ?>
                <tr><td colspan="8" style="text-align:center;padding:40px;color:#aaa">No messages found</td></tr>
            <?php endif; ?>
            <?php foreach ($messages as $msg) :
                $is_unread = $msg->status === 'unread';
                $status_colors = [
                    'unread' => ['bg' => '#dbeafe', 'color' => '#1e40af'],
                    'read' => ['bg' => '#f3f4f6', 'color' => '#555'],
                    'replied' => ['bg' => '#dcfce7', 'color' => '#166534'],
                    'archived' => ['bg' => '#f5f5f4', 'color' => '#78716c'],
                    'spam' => ['bg' => '#fee2e2', 'color' => '#991b1b'],
                ];
                $sc = $status_colors[$msg->status] ?? $status_colors['read'];
            ?>
            <tr class="<?php echo $is_unread ? 'unread' : ''; ?>">
                <td><input type="checkbox" name="msg_ids[]" value="<?php echo $msg->id; ?>" /></td>
                <td>
                    <strong><?php echo esc_html($msg->name); ?></strong><br>
                    <span style="font-size:11px;color:#888"><?php echo esc_html($msg->email); ?></span>
                </td>
                <td>
                    <a href="<?php echo esc_url(add_query_arg('view', $msg->id)); ?>" style="color:#111;text-decoration:none;font-weight:<?php echo $is_unread ? '600' : '400'; ?>">
                        <?php echo esc_html($msg->subject ?: '(No subject)'); ?>
                    </a>
                    <span class="plm-preview"><?php echo esc_html(wp_trim_words($msg->message, 15, '...')); ?></span>
                </td>
                <td style="font-size:12px;color:#888">
                    <?php echo esc_html($msg->country ?: "\xE2\x80\x94"); ?>
                    <?php if ($msg->city) : ?><br><?php echo esc_html($msg->city); ?><?php endif; ?>
                </td>
                <td><span class="plm-badge" style="background:#f3f4f6;color:#555"><?php echo esc_html($msg->device ?: "\xE2\x80\x94"); ?></span></td>
                <td style="white-space:nowrap;font-size:12px;color:#888">
                    <?php echo human_time_diff(strtotime($msg->created_at), current_time('timestamp')); ?> ago
                </td>
                <td><span class="plm-badge" style="background:<?php echo $sc['bg']; ?>;color:<?php echo $sc['color']; ?>"><?php echo esc_html($msg->status); ?></span></td>
                <td>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=pl-messages&delete_msg=' . $msg->id), 'pl_delete_msg_' . $msg->id)); ?>"
                       onclick="return confirm('Delete this message?')"
                       style="color:#ef4444;font-size:11px;text-decoration:none">&#x1F5D1;&#xFE0F;</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </form>

        <!-- Pagination -->
        <?php if ($total_pages > 1) : ?>
        <div class="plm-pagination">
            <?php for ($p = 1; $p <= min($total_pages, 20); $p++) : ?>
                <a href="<?php echo esc_url(add_query_arg('paged', $p)); ?>" class="<?php echo $p === $paged ? 'current' : ''; ?>"><?php echo $p; ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>

        <script>
        document.getElementById('plmCheckAll').addEventListener('change', function() {
            var boxes = document.querySelectorAll('input[name="msg_ids[]"]');
            for (var i = 0; i < boxes.length; i++) boxes[i].checked = this.checked;
        });
        </script>
    </div>
    <?php
}

// ─── SINGLE MESSAGE VIEW ───
function pl_message_detail_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'pl_messages';
    $id = absint($_GET['view']);
    $msg = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id));

    if (!$msg) {
        echo '<div class="wrap"><h1>Message not found</h1></div>';
        return;
    }

    // Mark as read
    if ($msg->status === 'unread') {
        $wpdb->update($table, ['status' => 'read'], ['id' => $id]);
        $msg->status = 'read';
    }

    // Handle status update
    if (isset($_POST['pl_msg_status']) && wp_verify_nonce($_POST['pl_detail_nonce'] ?? '', 'pl_msg_detail')) {
        $new_status = sanitize_text_field($_POST['pl_msg_status']);
        $notes = sanitize_textarea_field($_POST['admin_notes'] ?? '');
        $update = ['status' => $new_status, 'admin_notes' => $notes];
        if ($new_status === 'replied') $update['replied_at'] = current_time('mysql');
        $wpdb->update($table, $update, ['id' => $id]);
        $msg->status = $new_status;
        $msg->admin_notes = $notes;
        echo '<div class="notice notice-success"><p>Updated.</p></div>';
    }

    ?>
    <div class="wrap" style="max-width:900px">
        <style>
            .plm-detail{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:28px;margin-top:16px}
            .plm-detail-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;padding-bottom:16px;border-bottom:1px solid #f3f4f6}
            .plm-detail-body{font-size:15px;line-height:1.8;color:#333;white-space:pre-wrap;margin-bottom:24px;padding:16px;background:#f9fafb;border-radius:8px}
            .plm-meta{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px}
            .plm-meta-item{font-size:12px;color:#888}
            .plm-meta-item strong{display:block;color:#333;font-size:13px}
            .plm-reply-form{border-top:1px solid #f3f4f6;padding-top:20px}
            .plm-reply-form textarea{width:100%;padding:10px 14px;border:1px solid #ddd;border-radius:8px;font-size:13px;resize:vertical;min-height:80px;font-family:inherit}
            .plm-reply-form select,.plm-reply-form button{padding:8px 16px;border:1px solid #ddd;border-radius:8px;font-size:13px}
        </style>

        <a href="<?php echo admin_url('admin.php?page=pl-messages'); ?>" style="text-decoration:none;color:#666;font-size:13px">&larr; Back to Messages</a>

        <div class="plm-detail">
            <div class="plm-detail-header">
                <div>
                    <h2 style="margin:0 0 4px"><?php echo esc_html($msg->subject ?: '(No subject)'); ?></h2>
                    <span style="font-size:14px;color:#888">
                        From <strong><?php echo esc_html($msg->name); ?></strong>
                         &lt;<?php echo esc_html($msg->email); ?>&gt;
                    </span>
                </div>
                <div style="text-align:right">
                    <span class="plm-badge" style="background:<?php echo $msg->status === 'unread' ? '#dbeafe' : '#f3f4f6'; ?>;color:<?php echo $msg->status === 'unread' ? '#1e40af' : '#555'; ?>">
                        <?php echo esc_html($msg->status); ?>
                    </span>
                    <div style="font-size:12px;color:#aaa;margin-top:4px">
                        <?php echo date('M j, Y \a\t g:i A', strtotime($msg->created_at)); ?>
                    </div>
                </div>
            </div>

            <div class="plm-detail-body"><?php echo esc_html($msg->message); ?></div>

            <div class="plm-meta">
                <div class="plm-meta-item">
                    <strong>&#x1F4CD; Location</strong>
                    <?php echo esc_html(($msg->country ?: 'Unknown') . ($msg->city ? ", {$msg->city}" : '')); ?>
                </div>
                <div class="plm-meta-item">
                    <strong>&#x1F4F1; Device</strong>
                    <?php echo esc_html($msg->device ?: 'Unknown'); ?> &middot; <?php echo esc_html($msg->browser ?: ''); ?>
                </div>
                <div class="plm-meta-item">
                    <strong>&#x1F194; Visitor</strong>
                    <?php echo esc_html($msg->visitor_id ?: 'Anonymous'); ?>
                </div>
            </div>

            <!-- Quick Reply by Email -->
            <div style="margin-bottom:20px">
                <a href="mailto:<?php echo esc_attr($msg->email); ?>?subject=Re: <?php echo esc_attr($msg->subject ?: 'Your message'); ?>"
                    class="button button-primary" style="font-size:13px">
                    &#x2709;&#xFE0F; Reply via Email
                </a>
            </div>

            <!-- Status & Notes -->
            <form method="post" class="plm-reply-form">
                <?php wp_nonce_field('pl_msg_detail', 'pl_detail_nonce'); ?>
                <div style="display:flex;gap:10px;align-items:center;margin-bottom:12px">
                    <label style="font-size:13px;font-weight:600">Status:</label>
                    <select name="pl_msg_status">
                        <?php foreach (['unread','read','replied','archived','spam'] as $s) : ?>
                        <option value="<?php echo $s; ?>" <?php selected($msg->status, $s); ?>><?php echo ucfirst($s); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="button">Update</button>
                </div>
                <textarea name="admin_notes" placeholder="Admin notes (only you can see these)..."><?php echo esc_textarea($msg->admin_notes); ?></textarea>
            </form>
        </div>
    </div>
    <?php
}

// ─── ADMIN BAR NOTIFICATION ───
add_action('admin_bar_menu', function($wp_admin_bar) {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $table = $wpdb->prefix . 'pl_messages';
    $unread = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='unread'") ?: 0;
    if ($unread == 0) return;

    $wp_admin_bar->add_node([
        'id' => 'pl-messages-alert',
        'title' => "&#x1F4EC; {$unread} new message" . ($unread > 1 ? 's' : ''),
        'href' => admin_url('admin.php?page=pl-messages&status=unread'),
        'meta' => ['class' => 'pl-messages-alert'],
    ]);
}, 100);
