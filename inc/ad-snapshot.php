<?php
/**
 * PinLightning Ad Engine — Stable Snapshot System
 *
 * Allows saving and reverting all ad engine files to a known-good state.
 * Two AJAX actions:
 *   - pl_snapshot_save   — copies ad engine files to backup directory
 *   - pl_snapshot_revert — restores files from backup directory
 *
 * Backup location: {theme_dir}/backup/ad-engine-stable/
 *
 * @package PinLightning
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * List of ad engine files to snapshot (relative to theme root).
 *
 * @return array
 */
function pl_snapshot_file_list() {
	$files = array(
		'assets/js/smart-ads.js',
		'assets/js/initial-ads.js',
		'inc/ad-engine.php',
		'inc/ad-optimizer.php',
		'inc/ad-system.php',
		'inc/ad-data-recorder.php',
		'inc/ad-live-sessions.php',
		'inc/ad-injection-lab.php',
		'inc/ad-analytics-dashboard.php',
		'inc/ad-analytics-aggregator.php',
		'inc/ad-analytics-events.php',
		'inc/ad-recommendations.php',
		'inc/engagement-breaks.php',
	);

	return $files;
}

/**
 * Get the backup directory path.
 *
 * @return string
 */
function pl_snapshot_backup_dir() {
	return get_template_directory() . '/backup/ad-engine-stable';
}

/**
 * Read existing snapshot metadata.
 *
 * @return array|false  Parsed snapshot.json or false if none exists.
 */
function pl_snapshot_get_meta() {
	$meta_path = pl_snapshot_backup_dir() . '/snapshot.json';
	if ( ! file_exists( $meta_path ) ) {
		return false;
	}
	$json = file_get_contents( $meta_path );
	$data = json_decode( $json, true );
	return is_array( $data ) ? $data : false;
}

/* ================================================================
 * AJAX: SAVE SNAPSHOT
 * ================================================================ */

add_action( 'wp_ajax_pl_snapshot_save', 'pl_snapshot_ajax_save' );

function pl_snapshot_ajax_save() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Permission denied.' );
	}
	check_ajax_referer( 'pl_snapshot_nonce', 'nonce' );

	$theme_dir  = get_template_directory();
	$backup_dir = pl_snapshot_backup_dir();
	$files      = pl_snapshot_file_list();

	// Create backup directory structure.
	$subdirs = array( 'assets/js', 'inc' );
	foreach ( $subdirs as $sub ) {
		$dir = $backup_dir . '/' . $sub;
		if ( ! is_dir( $dir ) ) {
			if ( ! mkdir( $dir, 0755, true ) ) {
				wp_send_json_error( 'Failed to create directory: ' . $sub );
			}
		}
	}

	$backed_up = array();
	$errors    = array();

	foreach ( $files as $rel ) {
		$src = $theme_dir . '/' . $rel;
		$dst = $backup_dir . '/' . $rel;

		if ( ! file_exists( $src ) ) {
			// File doesn't exist in theme — skip silently (optional file).
			continue;
		}

		if ( copy( $src, $dst ) ) {
			$backed_up[] = array(
				'file' => $rel,
				'md5'  => md5_file( $dst ),
				'size' => filesize( $dst ),
			);
		} else {
			$errors[] = $rel;
		}
	}

	if ( ! empty( $errors ) ) {
		wp_send_json_error( 'Failed to copy: ' . implode( ', ', $errors ) );
	}

	// Get git hash if available.
	$git_hash = 'unknown';
	if ( function_exists( 'shell_exec' ) ) {
		$hash = shell_exec( 'cd ' . escapeshellarg( $theme_dir ) . ' && git rev-parse --short HEAD 2>/dev/null' );
		if ( $hash ) {
			$git_hash = trim( $hash );
		}
	}

	// Write metadata.
	$meta = array(
		'timestamp' => gmdate( 'c' ),
		'git_hash'  => $git_hash,
		'files'     => $backed_up,
	);

	file_put_contents(
		$backup_dir . '/snapshot.json',
		wp_json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
	);

	wp_send_json_success( array(
		'message'   => 'Snapshot saved successfully.',
		'timestamp' => $meta['timestamp'],
		'git_hash'  => $git_hash,
		'count'     => count( $backed_up ),
	) );
}

/* ================================================================
 * AJAX: REVERT SNAPSHOT
 * ================================================================ */

add_action( 'wp_ajax_pl_snapshot_revert', 'pl_snapshot_ajax_revert' );

function pl_snapshot_ajax_revert() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Permission denied.' );
	}
	check_ajax_referer( 'pl_snapshot_nonce', 'nonce' );

	$theme_dir  = get_template_directory();
	$backup_dir = pl_snapshot_backup_dir();
	$meta       = pl_snapshot_get_meta();

	if ( ! $meta ) {
		wp_send_json_error( 'No snapshot found. Save a stable snapshot first.' );
	}

	// Verify all backup files exist before restoring.
	$missing = array();
	foreach ( $meta['files'] as $entry ) {
		$backup_path = $backup_dir . '/' . $entry['file'];
		if ( ! file_exists( $backup_path ) ) {
			$missing[] = $entry['file'];
		}
	}

	if ( ! empty( $missing ) ) {
		wp_send_json_error( 'Missing backup files: ' . implode( ', ', $missing ) );
	}

	// Restore each file.
	$restored = array();
	$errors   = array();

	foreach ( $meta['files'] as $entry ) {
		$src = $backup_dir . '/' . $entry['file'];
		$dst = $theme_dir . '/' . $entry['file'];

		if ( copy( $src, $dst ) ) {
			$restored[] = $entry['file'];
		} else {
			$errors[] = $entry['file'];
		}
	}

	if ( ! empty( $errors ) ) {
		wp_send_json_error( 'Failed to restore: ' . implode( ', ', $errors ) . '. Partial revert — check files manually.' );
	}

	// Log the revert.
	$log_entry = array(
		'action'    => 'revert',
		'timestamp' => gmdate( 'c' ),
		'snapshot'  => $meta['timestamp'],
		'git_hash'  => $meta['git_hash'],
		'files'     => $restored,
	);

	$log_path = $backup_dir . '/revert-log.json';
	$log      = array();
	if ( file_exists( $log_path ) ) {
		$existing = json_decode( file_get_contents( $log_path ), true );
		if ( is_array( $existing ) ) {
			$log = $existing;
		}
	}
	$log[] = $log_entry;
	// Keep last 20 entries.
	$log = array_slice( $log, -20 );
	file_put_contents( $log_path, wp_json_encode( $log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

	wp_send_json_success( array(
		'message'  => 'Reverted to snapshot from ' . $meta['timestamp'] . '.',
		'restored' => count( $restored ),
		'snapshot' => $meta['timestamp'],
		'git_hash' => $meta['git_hash'],
	) );
}

/* ================================================================
 * ADMIN UI — Snapshot Controls (rendered on Ad Engine page)
 * ================================================================ */

/**
 * Render the snapshot controls panel.
 * Called from pl_ad_settings_page() in ad-engine.php.
 */
function pl_snapshot_render_controls() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$meta  = pl_snapshot_get_meta();
	$nonce = wp_create_nonce( 'pl_snapshot_nonce' );

	?>
	<div id="pl-snapshot-panel" style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1;padding:16px 20px;margin:16px 0 20px;border-radius:2px;">
		<h3 style="margin:0 0 10px;font-size:14px;">Stable Snapshot</h3>

		<div id="pl-snapshot-status" style="margin-bottom:12px;font-size:13px;">
			<?php if ( $meta ) : ?>
				<span style="color:#00a32a;">&#10003;</span>
				Stable snapshot from <strong><?php echo esc_html( date_i18n( 'M j, Y g:i A', strtotime( $meta['timestamp'] ) ) ); ?></strong>
				(git: <code><?php echo esc_html( $meta['git_hash'] ); ?></code>)
				&mdash; <?php echo count( $meta['files'] ); ?> files
			<?php else : ?>
				<span style="color:#d63638;">&#9888;</span> No stable snapshot saved
			<?php endif; ?>
		</div>

		<div style="display:flex;gap:8px;align-items:center;">
			<button type="button" id="pl-snapshot-save" class="button" style="background:#00a32a;border-color:#00a32a;color:#fff;">
				&#128190; Save Current as Stable
			</button>
			<button type="button" id="pl-snapshot-revert" class="button" style="background:#dba617;border-color:#dba617;color:#fff;"
				<?php echo $meta ? '' : 'disabled'; ?>>
				&#9194; Revert to Stable
			</button>
			<span id="pl-snapshot-spinner" class="spinner" style="float:none;margin:0;"></span>
		</div>

		<div id="pl-snapshot-notice" style="margin-top:10px;display:none;"></div>
	</div>

	<script>
	(function() {
		var nonce = '<?php echo esc_js( $nonce ); ?>';
		var ajaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
		var snapshotDate = <?php echo $meta ? "'" . esc_js( date_i18n( 'M j, Y g:i A', strtotime( $meta['timestamp'] ) ) ) . "'" : 'null'; ?>;

		var saveBtn   = document.getElementById('pl-snapshot-save');
		var revertBtn = document.getElementById('pl-snapshot-revert');
		var spinner   = document.getElementById('pl-snapshot-spinner');
		var notice    = document.getElementById('pl-snapshot-notice');
		var status    = document.getElementById('pl-snapshot-status');

		function showNotice(msg, type) {
			notice.style.display = 'block';
			notice.innerHTML = '<div class="notice notice-' + type + ' inline" style="margin:0;padding:8px 12px;"><p>' + msg + '</p></div>';
		}

		function setLoading(on) {
			spinner.classList.toggle('is-active', on);
			saveBtn.disabled = on;
			revertBtn.disabled = on;
		}

		saveBtn.addEventListener('click', function() {
			if (!confirm('This will overwrite the existing stable snapshot. Continue?')) return;
			setLoading(true);
			notice.style.display = 'none';

			var fd = new FormData();
			fd.append('action', 'pl_snapshot_save');
			fd.append('nonce', nonce);

			fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function(r) { return r.json(); })
				.then(function(res) {
					setLoading(false);
					if (res.success) {
						showNotice('Snapshot saved: ' + res.data.count + ' files backed up (git: ' + res.data.git_hash + ').', 'success');
						status.innerHTML = '<span style="color:#00a32a;">&#10003;</span> Stable snapshot from <strong>' + new Date(res.data.timestamp).toLocaleString() + '</strong> (git: <code>' + res.data.git_hash + '</code>) &mdash; ' + res.data.count + ' files';
						revertBtn.disabled = false;
						snapshotDate = res.data.timestamp;
					} else {
						showNotice('Save failed: ' + (res.data || 'Unknown error'), 'error');
					}
				})
				.catch(function(e) {
					setLoading(false);
					showNotice('Network error: ' + e.message, 'error');
				});
		});

		revertBtn.addEventListener('click', function() {
			var msg = 'This will revert ALL ad engine files to the snapshot';
			if (snapshotDate) msg += ' from ' + snapshotDate;
			msg += '. Current changes will be lost. Continue?';
			if (!confirm(msg)) return;
			setLoading(true);
			notice.style.display = 'none';

			var fd = new FormData();
			fd.append('action', 'pl_snapshot_revert');
			fd.append('nonce', nonce);

			fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function(r) { return r.json(); })
				.then(function(res) {
					setLoading(false);
					if (res.success) {
						showNotice('Reverted ' + res.data.restored + ' files to snapshot from ' + res.data.snapshot + ' (git: ' + res.data.git_hash + ').', 'success');
					} else {
						showNotice('Revert failed: ' + (res.data || 'Unknown error'), 'error');
					}
				})
				.catch(function(e) {
					setLoading(false);
					showNotice('Network error: ' + e.message, 'error');
				});
		});
	})();
	</script>
	<?php
}
