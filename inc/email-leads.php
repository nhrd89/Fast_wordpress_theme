<?php
/**
 * PinLightning Email Leads — Unified email collection with rich visitor data.
 * All email entry points (newsletter, chat, popups) flow through this system.
 *
 * @package PinLightning
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ─── DATABASE TABLE ───
function pl_email_create_table() {
	global $wpdb;
	$table   = $wpdb->prefix . 'pl_email_leads_v2';
	$charset = $wpdb->get_charset_collate();

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	dbDelta( "CREATE TABLE {$table} (
		id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
		email VARCHAR(255) NOT NULL,

		source VARCHAR(50) NOT NULL DEFAULT 'newsletter',
		source_detail VARCHAR(255) DEFAULT '',
		page_url VARCHAR(500) DEFAULT '',
		page_title VARCHAR(255) DEFAULT '',
		post_id BIGINT UNSIGNED DEFAULT 0,

		visitor_id VARCHAR(100) DEFAULT '',

		device VARCHAR(20) DEFAULT '',
		browser VARCHAR(100) DEFAULT '',
		viewport_width INT DEFAULT 0,
		user_agent TEXT,

		country VARCHAR(100) DEFAULT '',
		country_code VARCHAR(5) DEFAULT '',
		city VARCHAR(100) DEFAULT '',
		region VARCHAR(100) DEFAULT '',
		timezone VARCHAR(100) DEFAULT '',
		language VARCHAR(20) DEFAULT '',

		scroll_depth DECIMAL(5,1) DEFAULT 0,
		time_on_page INT DEFAULT 0,
		active_time INT DEFAULT 0,
		quality_score DECIMAL(5,1) DEFAULT 0,
		scroll_pattern VARCHAR(20) DEFAULT '',

		interests TEXT,

		total_sessions INT DEFAULT 0,
		total_pageviews INT DEFAULT 0,
		returning_visitor TINYINT(1) DEFAULT 0,
		first_visit_date DATETIME NULL,
		pages_viewed TEXT,

		pin_saves INT DEFAULT 0,
		referrer VARCHAR(500) DEFAULT '',

		chat_session_id BIGINT UNSIGNED DEFAULT 0,
		chat_messages_before INT DEFAULT 0,
		chat_topic VARCHAR(255) DEFAULT '',

		status ENUM('active','unsubscribed','bounced','spam') DEFAULT 'active',
		tags VARCHAR(500) DEFAULT '',
		notes TEXT,
		score INT DEFAULT 0,

		created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
		updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

		UNIQUE KEY unique_email (email),
		INDEX idx_source (source),
		INDEX idx_status (status),
		INDEX idx_country (country),
		INDEX idx_device (device),
		INDEX idx_created (created_at),
		INDEX idx_score (score),
		INDEX idx_visitor (visitor_id)
	) {$charset}" );
}

add_action( 'after_switch_theme', 'pl_email_create_table' );

add_action( 'init', function() {
	global $wpdb;
	$table = $wpdb->prefix . 'pl_email_leads_v2';
	if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
		pl_email_create_table();
	}
} );

// ─── REST API: Collect Email ───
add_action( 'rest_api_init', function() {
	register_rest_route( 'pl/v1', '/subscribe', [
		'methods'             => 'POST',
		'callback'            => 'pl_email_subscribe',
		'permission_callback' => '__return_true',
	] );
	register_rest_route( 'pl/v1', '/unsubscribe', [
		'methods'             => 'GET',
		'callback'            => 'pl_email_unsubscribe',
		'permission_callback' => '__return_true',
	] );
} );

function pl_email_subscribe( $request ) {
	global $wpdb;
	$table = $wpdb->prefix . 'pl_email_leads_v2';

	$body  = $request->get_json_params();
	$email = sanitize_email( $body['email'] ?? '' );

	if ( empty( $email ) || ! is_email( $email ) ) {
		return new WP_REST_Response( [ 'error' => 'Invalid email address' ], 400 );
	}

	// Check existing
	$existing = $wpdb->get_row( $wpdb->prepare(
		"SELECT id, status FROM {$table} WHERE email = %s", $email
	) );

	if ( $existing ) {
		if ( $existing->status === 'unsubscribed' ) {
			$wpdb->update( $table, [ 'status' => 'active', 'updated_at' => current_time( 'mysql' ) ], [ 'id' => $existing->id ] );
			return new WP_REST_Response( [ 'success' => true, 'message' => 'Welcome back!', 'resubscribed' => true ] );
		}
		return new WP_REST_Response( [ 'success' => true, 'message' => 'Already subscribed!', 'existing' => true ] );
	}

	// Collect fields
	$source        = sanitize_text_field( $body['source'] ?? 'newsletter' );
	$source_detail = sanitize_text_field( $body['source_detail'] ?? '' );
	$visitor_id    = sanitize_text_field( $body['visitor_id'] ?? '' );
	$device        = sanitize_text_field( $body['device'] ?? '' );
	$browser       = sanitize_text_field( $body['browser'] ?? '' );
	$viewport      = absint( $body['viewport_width'] ?? 0 );
	$ua            = sanitize_text_field( $body['user_agent'] ?? ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
	$timezone_val  = sanitize_text_field( $body['timezone'] ?? '' );
	$language      = sanitize_text_field( $body['language'] ?? '' );

	// Server-side geo lookup
	$country      = '';
	$country_code = '';
	$city         = '';
	$region       = '';
	$ip           = $_SERVER['REMOTE_ADDR'] ?? '';

	if ( $ip && $ip !== '127.0.0.1' && $ip !== '::1' ) {
		$geo_cache = 'pl_geo_' . md5( $ip );
		$geo       = get_transient( $geo_cache );
		if ( $geo === false ) {
			$resp = wp_remote_get( "http://ip-api.com/json/{$ip}?fields=country,countryCode,city,regionName", [ 'timeout' => 2 ] );
			if ( ! is_wp_error( $resp ) ) {
				$geo = json_decode( wp_remote_retrieve_body( $resp ), true );
				if ( ! empty( $geo['country'] ) ) {
					set_transient( $geo_cache, $geo, DAY_IN_SECONDS );
				}
			}
		}
		if ( ! empty( $geo ) ) {
			$country      = $geo['country'] ?? '';
			$country_code = $geo['countryCode'] ?? '';
			$city         = $geo['city'] ?? '';
			$region       = $geo['regionName'] ?? '';
		}
	}

	// Behavior
	$scroll_depth   = floatval( $body['scroll_depth'] ?? 0 );
	$time_on_page   = absint( $body['time_on_page'] ?? 0 );
	$active_time    = absint( $body['active_time'] ?? 0 );
	$quality_score  = floatval( $body['quality_score'] ?? 0 );
	$scroll_pattern = sanitize_text_field( $body['scroll_pattern'] ?? '' );

	// Interests
	$interests = [];
	if ( ! empty( $body['interests'] ) && is_array( $body['interests'] ) ) {
		$interests = array_map( 'sanitize_text_field', array_slice( $body['interests'], 0, 20 ) );
	}

	// Page context
	$page_url   = esc_url_raw( $body['page_url'] ?? '' );
	$page_title = sanitize_text_field( $body['page_title'] ?? '' );
	$post_id    = absint( $body['post_id'] ?? 0 );

	// Engagement
	$total_sessions = absint( $body['total_sessions'] ?? 0 );
	$returning      = absint( $body['returning'] ?? 0 );
	$pages_viewed   = [];
	if ( ! empty( $body['pages_viewed'] ) && is_array( $body['pages_viewed'] ) ) {
		$pages_viewed = array_map( 'sanitize_text_field', array_slice( $body['pages_viewed'], 0, 50 ) );
	}

	// Pinterest
	$pin_saves = absint( $body['pin_saves'] ?? 0 );
	$referrer  = esc_url_raw( $body['referrer'] ?? '' );

	// Chat context
	$chat_session_id = absint( $body['chat_session_id'] ?? 0 );
	$chat_messages   = absint( $body['chat_messages'] ?? 0 );
	$chat_topic      = sanitize_text_field( $body['chat_topic'] ?? '' );

	// Lead score
	$score = pl_calculate_lead_score( [
		'returning'       => $returning,
		'total_sessions'  => $total_sessions,
		'scroll_depth'    => $scroll_depth,
		'active_time'     => $active_time,
		'quality_score'   => $quality_score,
		'pin_saves'       => $pin_saves,
		'chat_messages'   => $chat_messages,
		'interests_count' => count( $interests ),
	] );

	// Auto-tag
	$tags = [];
	if ( $source === 'chat' )                          $tags[] = 'chat-subscriber';
	if ( $source === 'newsletter' )                     $tags[] = 'newsletter';
	if ( $returning )                                   $tags[] = 'returning-visitor';
	if ( $pin_saves > 0 )                               $tags[] = 'pinterest-saver';
	if ( $scroll_depth >= 80 )                          $tags[] = 'deep-reader';
	if ( $quality_score >= 70 )                         $tags[] = 'high-engagement';
	if ( stripos( $referrer, 'pinterest' ) !== false )  $tags[] = 'from-pinterest';
	foreach ( array_slice( $interests, 0, 3 ) as $interest ) {
		$tags[] = 'interest:' . $interest;
	}

	// Insert
	$result = $wpdb->insert( $table, [
		'email'               => $email,
		'source'              => $source,
		'source_detail'       => $source_detail,
		'page_url'            => $page_url,
		'page_title'          => $page_title,
		'post_id'             => $post_id,
		'visitor_id'          => $visitor_id,
		'device'              => $device,
		'browser'             => $browser,
		'viewport_width'      => $viewport,
		'user_agent'          => $ua,
		'country'             => $country,
		'country_code'        => $country_code,
		'city'                => $city,
		'region'              => $region,
		'timezone'            => $timezone_val,
		'language'            => $language,
		'scroll_depth'        => $scroll_depth,
		'time_on_page'        => $time_on_page,
		'active_time'         => $active_time,
		'quality_score'       => $quality_score,
		'scroll_pattern'      => $scroll_pattern,
		'interests'           => wp_json_encode( $interests ),
		'total_sessions'      => $total_sessions,
		'total_pageviews'     => count( $pages_viewed ),
		'returning_visitor'   => $returning,
		'pages_viewed'        => wp_json_encode( $pages_viewed ),
		'pin_saves'           => $pin_saves,
		'referrer'            => $referrer,
		'chat_session_id'     => $chat_session_id,
		'chat_messages_before' => $chat_messages,
		'chat_topic'          => $chat_topic,
		'status'              => 'active',
		'tags'                => implode( ',', $tags ),
		'score'               => $score,
	] );

	if ( $result === false ) {
		return new WP_REST_Response( [ 'error' => 'Database error' ], 500 );
	}

	return new WP_REST_Response( [
		'success' => true,
		'message' => 'Successfully subscribed!',
		'lead_id' => $wpdb->insert_id,
		'score'   => $score,
		'tags'    => $tags,
	] );
}

// ─── LEAD SCORING ───
function pl_calculate_lead_score( $data ) {
	$score = 10; // Base
	if ( $data['returning'] )          $score += 15;
	$score += min( 20, $data['total_sessions'] * 2 );
	$score += min( 20, round( $data['scroll_depth'] * 0.2 ) );
	$score += min( 15, round( $data['active_time'] / 10 ) );
	$score += min( 10, round( $data['quality_score'] * 0.1 ) );
	$score += min( 10, $data['pin_saves'] * 3 );
	$score += min( 15, $data['chat_messages'] * 5 );
	$score += min( 10, $data['interests_count'] * 2 );
	return min( 100, $score );
}

// ─── UNSUBSCRIBE ───
function pl_email_unsubscribe( $request ) {
	global $wpdb;
	$table = $wpdb->prefix . 'pl_email_leads_v2';
	$token = sanitize_text_field( $request->get_param( 'token' ) ?? '' );

	if ( empty( $token ) ) {
		wp_die( 'Invalid unsubscribe link.' );
	}

	$email = base64_decode( $token );
	if ( ! is_email( $email ) ) {
		wp_die( 'Invalid unsubscribe link.' );
	}

	$wpdb->update( $table, [ 'status' => 'unsubscribed' ], [ 'email' => $email ] );

	wp_die(
		'<div style="max-width:500px;margin:80px auto;font-family:sans-serif;text-align:center">'
		. '<h2>You\'ve been unsubscribed</h2>'
		. '<p style="color:#666">You won\'t receive any more emails from us.</p>'
		. '<p><a href="' . esc_url( home_url( '/' ) ) . '">&larr; Back to ' . esc_html( get_bloginfo( 'name' ) ) . '</a></p>'
		. '</div>',
		'Unsubscribed',
		[ 'response' => 200 ]
	);
}

// ─── BRIDGE: AI CHAT → UNIFIED LEADS ───
function pl_chat_capture_email( $email, $session_data ) {
	$request = new WP_REST_Request( 'POST', '/pl/v1/subscribe' );
	$request->set_body( wp_json_encode( [
		'email'           => $email,
		'source'          => 'chat',
		'source_detail'   => 'ai_chat_conversation',
		'page_url'        => $session_data['page_url'] ?? '',
		'page_title'      => $session_data['page_title'] ?? '',
		'post_id'         => $session_data['post_id'] ?? 0,
		'visitor_id'      => $session_data['visitor_id'] ?? '',
		'device'          => $session_data['device'] ?? '',
		'chat_session_id' => $session_data['session_id'] ?? 0,
		'chat_messages'   => $session_data['messages_count'] ?? 0,
		'chat_topic'      => $session_data['topic'] ?? '',
		'referrer'        => $session_data['referrer'] ?? '',
	] ) );
	$request->set_header( 'Content-Type', 'application/json' );
	rest_do_request( $request );
}

// ─── CSV EXPORT (before any HTML output) ───
add_action( 'admin_init', function() {
	if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'pl-email-leads' ) return;
	if ( ! isset( $_GET['export'] ) || $_GET['export'] !== 'csv' ) return;
	if ( ! current_user_can( 'manage_options' ) ) return;

	global $wpdb;
	$table = $wpdb->prefix . 'pl_email_leads_v2';

	$filter_status = sanitize_text_field( $_GET['status'] ?? 'all' );
	$filter_source = sanitize_text_field( $_GET['source_filter'] ?? 'all' );
	$filter_tag    = sanitize_text_field( $_GET['tag_filter'] ?? '' );

	$where = 'WHERE 1=1';
	$args  = [];
	if ( $filter_status !== 'all' ) { $where .= $wpdb->prepare( ' AND status=%s', $filter_status ); }
	if ( $filter_source !== 'all' ) { $where .= $wpdb->prepare( ' AND source=%s', $filter_source ); }
	if ( $filter_tag )              { $where .= $wpdb->prepare( ' AND tags LIKE %s', '%' . $filter_tag . '%' ); }

	$leads = $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY created_at DESC", ARRAY_A );

	if ( ob_get_level() ) ob_end_clean();

	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="email-leads-' . gmdate( 'Y-m-d' ) . '.csv"' );
	header( 'Pragma: no-cache' );
	header( 'Expires: 0' );

	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" );

	fputcsv( $out, [ 'Email', 'Source', 'Status', 'Score', 'Device', 'Country', 'City',
		'Scroll Depth', 'Active Time', 'Quality Score', 'Pattern', 'Interests', 'Tags',
		'Returning', 'Sessions', 'Pin Saves', 'Referrer', 'Page', 'Date' ] );

	foreach ( $leads as $l ) {
		fputcsv( $out, [
			$l['email'], $l['source'], $l['status'], $l['score'],
			$l['device'], $l['country'], $l['city'],
			$l['scroll_depth'], $l['active_time'], $l['quality_score'],
			$l['scroll_pattern'], $l['interests'], $l['tags'],
			$l['returning_visitor'], $l['total_sessions'], $l['pin_saves'],
			$l['referrer'], $l['page_url'], $l['created_at'],
		] );
	}
	fclose( $out );
	exit;
} );

// ─── ADMIN MENU ───
add_action( 'admin_menu', function() {
	add_submenu_page(
		'pl-analytics',
		'Email Leads',
		"\xF0\x9F\x93\xA7 Email Leads",
		'manage_options',
		'pl-email-leads',
		'pl_email_leads_page'
	);
} );

function pl_email_leads_page() {
	global $wpdb;
	$table = $wpdb->prefix . 'pl_email_leads_v2';

	// Handle single delete
	if ( isset( $_GET['delete_lead'] ) && wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'pl_delete_lead_' . absint( $_GET['delete_lead'] ) ) ) {
		$del_id = absint( $_GET['delete_lead'] );
		$wpdb->delete( $table, [ 'id' => $del_id ] );
		echo '<div class="notice notice-success"><p>Lead deleted.</p></div>';
	}

	// Handle bulk actions
	if ( isset( $_POST['pl_email_action'] ) && wp_verify_nonce( $_POST['pl_email_nonce'] ?? '', 'pl_email_manage' ) ) {
		$action = sanitize_text_field( $_POST['pl_email_action'] );
		$ids    = array_map( 'absint', $_POST['lead_ids'] ?? [] );

		if ( ! empty( $ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			switch ( $action ) {
				case 'delete':
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", ...$ids ) );
					break;
				case 'unsubscribe':
					$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status='unsubscribed' WHERE id IN ({$placeholders})", ...$ids ) );
					break;
				case 'activate':
					$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status='active' WHERE id IN ({$placeholders})", ...$ids ) );
					break;
			}
			echo '<div class="notice notice-success"><p>Action completed.</p></div>';
		}
	}

	// Handle tag add
	if ( isset( $_POST['pl_add_tag'] ) && wp_verify_nonce( $_POST['pl_tag_nonce'] ?? '', 'pl_tag_manage' ) ) {
		$tag_ids = array_map( 'absint', $_POST['tag_lead_ids'] ?? [] );
		$new_tag = sanitize_text_field( $_POST['new_tag'] ?? '' );
		if ( ! empty( $tag_ids ) && ! empty( $new_tag ) ) {
			foreach ( $tag_ids as $tid ) {
				$current = $wpdb->get_var( $wpdb->prepare( "SELECT tags FROM {$table} WHERE id=%d", $tid ) );
				$tags    = array_filter( explode( ',', $current ) );
				if ( ! in_array( $new_tag, $tags, true ) ) {
					$tags[] = $new_tag;
					$wpdb->update( $table, [ 'tags' => implode( ',', $tags ) ], [ 'id' => $tid ] );
				}
			}
		}
	}

	// Filters
	$filter_status = sanitize_text_field( $_GET['status'] ?? 'all' );
	$filter_source = sanitize_text_field( $_GET['source_filter'] ?? 'all' );
	$filter_tag    = sanitize_text_field( $_GET['tag_filter'] ?? '' );
	$search        = sanitize_text_field( $_GET['s'] ?? '' );
	$page_num      = max( 1, absint( $_GET['paged'] ?? 1 ) );
	$per_page      = 50;
	$offset        = ( $page_num - 1 ) * $per_page;

	$where = '1=1';
	$args  = [];
	if ( $filter_status !== 'all' ) { $where .= ' AND status=%s'; $args[] = $filter_status; }
	if ( $filter_source !== 'all' ) { $where .= ' AND source=%s'; $args[] = $filter_source; }
	if ( $filter_tag )              { $where .= ' AND tags LIKE %s'; $args[] = '%' . $filter_tag . '%'; }
	if ( $search ) {
		$where .= ' AND (email LIKE %s OR country LIKE %s OR tags LIKE %s)';
		$like   = '%' . $search . '%';
		$args[] = $like;
		$args[] = $like;
		$args[] = $like;
	}

	$count_query = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
	$total       = $args ? $wpdb->get_var( $wpdb->prepare( $count_query, ...$args ) ) : $wpdb->get_var( $count_query );
	$data_query  = "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";
	$data_args   = array_merge( $args, [ $per_page, $offset ] );
	$leads       = $wpdb->get_results( $wpdb->prepare( $data_query, ...$data_args ) );
	$total_pages = ceil( $total / $per_page );

	// Stats
	$stats = [
		'total'         => $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ),
		'active'        => $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='active'" ),
		'today'         => $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE DATE(created_at) = %s", current_time( 'Y-m-d' ) ) ),
		'week'          => $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)" ),
		'avg_score'     => round( floatval( $wpdb->get_var( "SELECT AVG(score) FROM {$table} WHERE status='active'" ) ?: 0 ) ),
		'sources'       => $wpdb->get_results( "SELECT source, COUNT(*) as cnt FROM {$table} GROUP BY source ORDER BY cnt DESC" ),
		'top_countries' => $wpdb->get_results( "SELECT country, COUNT(*) as cnt FROM {$table} WHERE country != '' GROUP BY country ORDER BY cnt DESC LIMIT 5" ),
	];

	?>
	<div class="wrap" style="max-width:1400px">
		<style>
			.ple-grid{display:grid;gap:14px;margin-bottom:20px}
			.ple-grid-5{grid-template-columns:repeat(5,1fr)}
			@media(max-width:1200px){.ple-grid-5{grid-template-columns:repeat(3,1fr)}}
			.ple-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px;text-align:center}
			.ple-val{font-size:28px;font-weight:700;color:#111}
			.ple-label{font-size:11px;color:#888;text-transform:uppercase;margin-top:3px;letter-spacing:.5px}
			.ple-filters{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;align-items:center}
			.ple-filters select,.ple-filters input{padding:6px 12px;border:1px solid #ddd;border-radius:8px;font-size:13px}
			.ple-table{width:100%;border-collapse:collapse;font-size:13px;background:#fff;border-radius:10px;overflow:hidden;border:1px solid #e5e7eb}
			.ple-table th{text-align:left;padding:10px 12px;background:#f9fafb;font-size:11px;text-transform:uppercase;color:#555;letter-spacing:.5px;position:sticky;top:0;z-index:1}
			.ple-table td{padding:8px 12px;border-top:1px solid #f3f4f6;vertical-align:top}
			.ple-table tr:hover td{background:#fafafa}
			.ple-badge{display:inline-block;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600}
			.ple-tag{display:inline-block;padding:1px 6px;border-radius:4px;font-size:10px;background:#f0f0f0;color:#555;margin:1px}
			.ple-score{display:inline-block;width:28px;height:28px;line-height:28px;border-radius:50%;text-align:center;font-size:11px;font-weight:700;color:#fff}
			.ple-actions{display:flex;gap:6px;align-items:center;margin-bottom:16px}
			.ple-actions select,.ple-actions button{padding:6px 12px;border:1px solid #ddd;border-radius:8px;font-size:13px}
			.ple-pagination{display:flex;gap:6px;justify-content:center;margin-top:16px}
			.ple-pagination a{padding:6px 12px;border:1px solid #ddd;border-radius:6px;text-decoration:none;color:#555;font-size:13px}
			.ple-pagination a.current{background:#111;color:#fff;border-color:#111}
		</style>

		<h1 style="display:flex;align-items:center;gap:10px">
			&#x1F4E7; Email Leads
			<span style="font-size:13px;color:#888;font-weight:400"><?php echo number_format( $stats['total'] ); ?> total</span>
			<a href="<?php echo esc_url( add_query_arg( 'export', 'csv' ) ); ?>" class="button" style="margin-left:auto">&#x1F4E5; Export CSV</a>
		</h1>

		<!-- Stats Cards -->
		<div class="ple-grid ple-grid-5">
			<div class="ple-card">
				<div class="ple-val"><?php echo number_format( $stats['active'] ); ?></div>
				<div class="ple-label">Active Subscribers</div>
			</div>
			<div class="ple-card">
				<div class="ple-val"><?php echo number_format( $stats['today'] ); ?></div>
				<div class="ple-label">Today</div>
			</div>
			<div class="ple-card">
				<div class="ple-val"><?php echo number_format( $stats['week'] ); ?></div>
				<div class="ple-label">This Week</div>
			</div>
			<div class="ple-card">
				<div class="ple-val"><?php echo $stats['avg_score']; ?></div>
				<div class="ple-label">Avg Lead Score</div>
			</div>
			<div class="ple-card">
				<div class="ple-val"><?php echo count( $stats['sources'] ); ?></div>
				<div class="ple-label">Sources</div>
			</div>
		</div>

		<!-- Filters -->
		<form method="get" class="ple-filters">
			<input type="hidden" name="page" value="pl-email-leads" />
			<select name="status">
				<option value="all" <?php selected( $filter_status, 'all' ); ?>>All Status</option>
				<option value="active" <?php selected( $filter_status, 'active' ); ?>>Active</option>
				<option value="unsubscribed" <?php selected( $filter_status, 'unsubscribed' ); ?>>Unsubscribed</option>
			</select>
			<select name="source_filter">
				<option value="all" <?php selected( $filter_source, 'all' ); ?>>All Sources</option>
				<option value="newsletter" <?php selected( $filter_source, 'newsletter' ); ?>>Newsletter</option>
				<option value="chat" <?php selected( $filter_source, 'chat' ); ?>>AI Chat</option>
				<option value="popup" <?php selected( $filter_source, 'popup' ); ?>>Popup</option>
				<option value="exit_intent" <?php selected( $filter_source, 'exit_intent' ); ?>>Exit Intent</option>
			</select>
			<input type="text" name="tag_filter" value="<?php echo esc_attr( $filter_tag ); ?>" placeholder="Filter by tag..." />
			<input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search email, country..." />
			<button type="submit" class="button">Filter</button>
			<?php if ( $filter_status !== 'all' || $filter_source !== 'all' || $filter_tag || $search ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=pl-email-leads' ) ); ?>" style="color:#999;font-size:12px">Clear filters</a>
			<?php endif; ?>
		</form>

		<!-- Bulk Actions -->
		<form method="post" id="pleLeadsForm">
			<?php wp_nonce_field( 'pl_email_manage', 'pl_email_nonce' ); ?>
			<div class="ple-actions">
				<select name="pl_email_action">
					<option value="">Bulk Actions</option>
					<option value="delete">Delete</option>
					<option value="unsubscribe">Unsubscribe</option>
					<option value="activate">Re-activate</option>
				</select>
				<button type="submit" class="button" onclick="return confirm('Apply this action to selected leads?')">Apply</button>
				<span style="color:#aaa;font-size:12px;margin-left:8px">
					Showing <?php echo count( $leads ); ?> of <?php echo number_format( $total ); ?>
				</span>
			</div>

			<!-- Leads Table -->
			<div style="overflow-x:auto">
			<table class="ple-table">
				<thead>
					<tr>
						<th><input type="checkbox" id="pleCheckAll" /></th>
						<th>Email</th>
						<th>Score</th>
						<th>Source</th>
						<th>Location</th>
						<th>Device</th>
						<th>Behavior</th>
						<th>Interests</th>
						<th>Tags</th>
						<th>Status</th>
						<th>Date</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $leads ) ) : ?>
					<tr><td colspan="12" style="text-align:center;padding:40px;color:#aaa">No leads found</td></tr>
				<?php endif; ?>
				<?php foreach ( $leads as $lead ) :
					$score_color      = $lead->score >= 70 ? '#059669' : ( $lead->score >= 40 ? '#f59e0b' : '#9ca3af' );
					$status_color     = $lead->status === 'active' ? '#dcfce7' : '#fee2e2';
					$status_txt_color = $lead->status === 'active' ? '#166534' : '#991b1b';
					$lead_interests   = json_decode( $lead->interests, true ) ?: [];
					$lead_tags        = array_filter( explode( ',', $lead->tags ) );
				?>
				<tr>
					<td><input type="checkbox" name="lead_ids[]" value="<?php echo $lead->id; ?>" /></td>
					<td>
						<strong style="display:block"><?php echo esc_html( $lead->email ); ?></strong>
						<span style="font-size:11px;color:#999"><?php echo esc_html( $lead->visitor_id ); ?></span>
					</td>
					<td>
						<span class="ple-score" style="background:<?php echo $score_color; ?>"><?php echo $lead->score; ?></span>
					</td>
					<td>
						<span class="ple-badge" style="background:#e0f2fe;color:#0369a1"><?php echo esc_html( $lead->source ); ?></span>
						<?php if ( $lead->source_detail ) : ?>
							<div style="font-size:10px;color:#aaa"><?php echo esc_html( $lead->source_detail ); ?></div>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( $lead->country ) : ?>
							<div><?php echo esc_html( $lead->country ); ?></div>
							<?php if ( $lead->city ) : ?>
								<div style="font-size:11px;color:#999"><?php echo esc_html( $lead->city ); ?></div>
							<?php endif; ?>
						<?php else : ?>
							<span style="color:#ddd">&mdash;</span>
						<?php endif; ?>
					</td>
					<td>
						<span class="ple-badge" style="background:#f3f4f6;color:#555"><?php echo esc_html( $lead->device ?: '—' ); ?></span>
					</td>
					<td style="font-size:11px;line-height:1.8">
						&#x1F4CF; <?php echo $lead->scroll_depth; ?>% depth<br>
						&#x23F1; <?php echo $lead->active_time; ?>s active<br>
						&#x2B50; <?php echo $lead->quality_score; ?> quality
						<?php if ( $lead->scroll_pattern ) : ?>
							<br>&#x1F441; <?php echo esc_html( $lead->scroll_pattern ); ?>
						<?php endif; ?>
					</td>
					<td>
						<?php foreach ( array_slice( $lead_interests, 0, 4 ) as $interest ) : ?>
							<span class="ple-tag" style="background:#fef3c7;color:#92400e"><?php echo esc_html( $interest ); ?></span>
						<?php endforeach; ?>
						<?php if ( $lead->returning_visitor ) : ?>
							<span class="ple-tag" style="background:#dcfce7;color:#166534">returning</span>
						<?php endif; ?>
						<?php if ( $lead->pin_saves > 0 ) : ?>
							<span class="ple-tag" style="background:#fce7f3;color:#be185d">&#x1F4CC; <?php echo $lead->pin_saves; ?></span>
						<?php endif; ?>
					</td>
					<td>
						<?php foreach ( $lead_tags as $tag ) : ?>
							<span class="ple-tag"><?php echo esc_html( $tag ); ?></span>
						<?php endforeach; ?>
					</td>
					<td>
						<span class="ple-badge" style="background:<?php echo $status_color; ?>;color:<?php echo $status_txt_color; ?>">
							<?php echo esc_html( $lead->status ); ?>
						</span>
					</td>
					<td style="white-space:nowrap;font-size:12px;color:#888">
						<?php echo esc_html( date( 'M j', strtotime( $lead->created_at ) ) ); ?><br>
						<?php echo esc_html( date( 'H:i', strtotime( $lead->created_at ) ) ); ?>
					</td>
					<td>
						<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'delete_lead' => $lead->id ] ), 'pl_delete_lead_' . $lead->id ) ); ?>"
						   onclick="return confirm('Delete this lead?')"
						   style="color:#ef4444;font-size:11px;text-decoration:none">&#x1F5D1;&#xFE0F; Delete</a>
					</td>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		</form>

		<!-- Pagination -->
		<?php if ( $total_pages > 1 ) : ?>
		<div class="ple-pagination">
			<?php for ( $p = 1; $p <= min( $total_pages, 20 ); $p++ ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'paged', $p ) ); ?>"
					class="<?php echo $p === $page_num ? 'current' : ''; ?>">
					<?php echo $p; ?>
				</a>
			<?php endfor; ?>
		</div>
		<?php endif; ?>

		<script>
		document.getElementById('pleCheckAll').addEventListener('change', function() {
			var boxes = document.querySelectorAll('input[name="lead_ids[]"]');
			for (var i = 0; i < boxes.length; i++) boxes[i].checked = this.checked;
		});
		</script>
	</div>
	<?php
}
