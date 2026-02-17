<?php
/**
 * Engagement Email Capture — WordPress AJAX handler + database table.
 *
 * Stores emails submitted through engagement blocks (inline forms,
 * exit-intent overlays, AI chat) in a dedicated table.
 *
 * @package PinLightning
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create the eb_email_leads database table.
 */
function pl_create_email_leads_table() {
	global $wpdb;
	$table           = $wpdb->prefix . 'eb_email_leads';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE IF NOT EXISTS $table (
		id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		email varchar(255) NOT NULL,
		post_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
		post_title varchar(255) NOT NULL DEFAULT '',
		source varchar(50) NOT NULL DEFAULT 'inline',
		favorites text,
		category varchar(100) NOT NULL DEFAULT '',
		ip_address varchar(45) NOT NULL DEFAULT '',
		created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		KEY email_idx (email),
		KEY post_id_idx (post_id),
		KEY created_at_idx (created_at)
	) $charset_collate;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}
add_action( 'after_switch_theme', 'pl_create_email_leads_table' );

/**
 * Ensure the table exists for installs that didn't switch theme.
 * Uses an option flag so we only run SHOW TABLES once.
 */
function pl_check_email_table_version() {
	if ( get_option( 'eb_email_table_version' ) !== '1.0' ) {
		pl_create_email_leads_table();
		update_option( 'eb_email_table_version', '1.0' );
	}
}
add_action( 'init', 'pl_check_email_table_version' );

/**
 * AJAX handler for email capture (logged-in and non-logged-in users).
 */
function pl_handle_email_capture() {
	// Verify nonce.
	if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'eb_email_nonce' ) ) {
		wp_send_json_error( 'Invalid nonce', 403 );
	}

	$email = sanitize_email( $_POST['email'] ?? '' );
	if ( ! is_email( $email ) ) {
		wp_send_json_error( 'Invalid email', 400 );
	}

	global $wpdb;
	$table = $wpdb->prefix . 'eb_email_leads';

	// Check for duplicate email on same post (prevent spam).
	$exists = $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM $table WHERE email = %s AND post_id = %d",
		$email,
		intval( $_POST['post_id'] ?? 0 )
	) );

	if ( $exists ) {
		wp_send_json_success( array( 'message' => 'already_captured' ) );
		return;
	}

	$wpdb->insert( $table, array(
		'email'      => $email,
		'post_id'    => intval( $_POST['post_id'] ?? 0 ),
		'post_title' => sanitize_text_field( $_POST['post_title'] ?? '' ),
		'source'     => sanitize_text_field( $_POST['source'] ?? 'inline' ),
		'favorites'  => sanitize_text_field( $_POST['favorites'] ?? '[]' ),
		'category'   => sanitize_text_field( $_POST['category'] ?? '' ),
		'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
		'created_at' => current_time( 'mysql' ),
	) );

	wp_send_json_success( array( 'message' => 'captured', 'id' => $wpdb->insert_id ) );
}
add_action( 'wp_ajax_eb_email_capture', 'pl_handle_email_capture' );
add_action( 'wp_ajax_nopriv_eb_email_capture', 'pl_handle_email_capture' );
