<?php
/**
 * PinLightning Smart Ad Engine
 *
 * Phase 1: Admin settings page, ads.txt handler, frontend config provider.
 * Replaces hardcoded constants with database-backed WordPress Settings API.
 *
 * Content scanner + zone injection preserved from original.
 *
 * @package PinLightning
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ================================================================
 * 1. DEFAULTS & SETTINGS
 * ================================================================ */

/**
 * Default settings for the ad engine.
 *
 * @return array
 */
function pl_ad_defaults() {
	return array(
		// Global Controls.
		'enabled'               => false,
		'dummy_mode'            => false,
		'debug_overlay'         => false,
		'record_data'           => true,

		// Engagement Gate.
		'gate_scroll_pct'       => 10,
		'gate_time_sec'         => 3,
		'gate_dir_changes'      => 0,

		// Density Controls.
		'max_display_ads'       => 12,
		'min_spacing_px'        => 200,
		'min_paragraphs_before' => 2,
		'min_gap_paragraphs'    => 3,

		// Device Controls.
		'mobile_enabled'        => true,
		'desktop_enabled'       => true,

		// Format Toggles.
		'fmt_interstitial'      => true,
		'fmt_anchor'            => true,
		'fmt_top_anchor'        => true,
		'fmt_300x250'           => true,
		'fmt_970x250'           => true,
		'fmt_728x90'            => true,
		'fmt_320x100'           => true,
		'fmt_160x600'           => true,
		'fmt_pause'             => true,
		'fmt_video'             => true,
		'pause_min_ads'         => 2,

		// Passback.
		'passback_enabled'      => false,

		// Network.
		'network_code'          => '22953639975',
		'slot_prefix'           => '/21849154601,22953639975/',

		// Ad Unit Slot Names.
		'slot_interstitial'     => 'Ad.Plus-Interstitial',
		'slot_anchor'           => 'Ad.Plus-Anchor',
		'slot_top_anchor'       => 'Ad.Plus-Anchor-Small',
		'slot_300x250'          => 'Ad.Plus-300x250',
		'slot_970x250'          => 'Ad.Plus-970x250',
		'slot_728x90'           => 'Ad.Plus-728x90',
		'slot_320x100'          => 'Ad.Plus-320x100',
		'slot_160x600'          => 'Ad.Plus-160x600',
		'slot_pause'            => 'Ad.Plus-Pause-300x250',

		// Backfill Network (Newor Media / Waldo).
		'backfill_script_url'       => '//cdn.thisiswaldo.com/static/js/24273.js',
		'backfill_display_tags'     => "waldo-tag-29686\nwaldo-tag-29688\nwaldo-tag-29690\nwaldo-tag-24348\nwaldo-tag-24350\nwaldo-tag-24352",
		'backfill_anchor_tag'       => 'waldo-tag-24358',
		'backfill_interstitial_tag' => '',
		'backfill_check_delay'      => 3000,
	);
}

/**
 * Get merged settings (saved values + defaults).
 *
 * @return array
 */
function pl_ad_settings() {
	static $cached = null;
	if ( null !== $cached ) {
		return $cached;
	}
	$saved  = get_option( 'pl_ad_settings', array() );
	$cached = wp_parse_args( $saved, pl_ad_defaults() );
	$cached['dummy_mode'] = true; // EMERGENCY: force dummy until viewability fixed
	return $cached;
}

/**
 * One-time migration: replace cheerfultalks.com slot names with Ad.Plus-* in saved DB option.
 */
function pl_ad_migrate_slot_names() {
	$saved = get_option( 'pl_ad_settings', array() );
	if ( empty( $saved ) ) {
		return;
	}

	$dirty = false;
	$map   = array(
		'slot_interstitial' => 'Ad.Plus-Interstitial',
		'slot_anchor'       => 'Ad.Plus-Anchor',
		'slot_300x250'      => 'Ad.Plus-300x250',
		'slot_970x250'      => 'Ad.Plus-970x250',
		'slot_728x90'       => 'Ad.Plus-728x90',
		'slot_pause'        => 'Ad.Plus-Pause-300x250',
	);

	foreach ( $map as $key => $new_val ) {
		if ( isset( $saved[ $key ] ) && strpos( $saved[ $key ], 'cheerfultalks' ) !== false ) {
			$saved[ $key ] = $new_val;
			$dirty = true;
		}
	}

	// Also migrate fmt_pause if it was saved as false.
	if ( isset( $saved['fmt_pause'] ) && ! $saved['fmt_pause'] ) {
		$saved['fmt_pause'] = true;
		$dirty = true;
	}

	// Migrate min_paragraphs_before from 3 to 2 (earlier first zone).
	if ( isset( $saved['min_paragraphs_before'] ) && (int) $saved['min_paragraphs_before'] === 3 ) {
		$saved['min_paragraphs_before'] = 2;
		$dirty = true;
	}

	// Migrate gate_dir_changes from 1 to 0 (removed as gate requirement).
	if ( isset( $saved['gate_dir_changes'] ) && (int) $saved['gate_dir_changes'] === 1 ) {
		$saved['gate_dir_changes'] = 0;
		$dirty = true;
	}

	// Migrate pause_min_ads from 4 to 2 (speed-gated system produces 1-3 ads, not 4).
	if ( isset( $saved['pause_min_ads'] ) && (int) $saved['pause_min_ads'] === 4 ) {
		$saved['pause_min_ads'] = 2;
		$dirty = true;
	}

	// V4 migration: force-update density/gate settings from v3 to v4 values.
	$v4_migrations = array(
		'max_display_ads' => array( 'old_max' => 10, 'new' => 35 ),
		'min_spacing_px'  => array( 'old_min' => 400, 'new' => 200 ),
		'gate_scroll_pct' => array( 'old_min' => 12, 'new' => 10 ),
		'gate_time_sec'   => array( 'old_min' => 4,  'new' => 3 ),
	);
	foreach ( $v4_migrations as $key => $m ) {
		if ( isset( $saved[ $key ] ) ) {
			$val = (int) $saved[ $key ];
			if ( isset( $m['old_max'] ) && $val <= $m['old_max'] && $val !== $m['new'] ) {
				$saved[ $key ] = $m['new'];
				$dirty = true;
			} elseif ( isset( $m['old_min'] ) && $val >= $m['old_min'] && $val !== $m['new'] ) {
				$saved[ $key ] = $m['new'];
				$dirty = true;
			}
		}
	}

	// V4: enable new formats if not yet saved.
	$v4_new_formats = array( 'fmt_top_anchor', 'fmt_320x100', 'fmt_160x600', 'fmt_video', 'fmt_728x90' );
	foreach ( $v4_new_formats as $fmt ) {
		if ( ! isset( $saved[ $fmt ] ) ) {
			$saved[ $fmt ] = true;
			$dirty = true;
		}
	}

	if ( $dirty ) {
		update_option( 'pl_ad_settings', $saved );
	}
}
add_action( 'admin_init', 'pl_ad_migrate_slot_names' );

/* ================================================================
 * 2. ADMIN MENU & SETTINGS PAGE
 * ================================================================ */

/**
 * Register the Ad Engine admin menu page.
 */
function pl_ad_admin_menu() {
	add_menu_page(
		'Ad Engine',
		'Ad Engine',
		'manage_options',
		'pl-ad-engine',
		'pl_ad_settings_page',
		'dashicons-money-alt',
		59
	);
}
add_action( 'admin_menu', 'pl_ad_admin_menu' );

/**
 * Register the settings group.
 */
function pl_ad_register_settings() {
	register_setting( 'pl_ad_settings_group', 'pl_ad_settings', array(
		'type'              => 'array',
		'sanitize_callback' => 'pl_ad_sanitize_settings',
		'default'           => pl_ad_defaults(),
	) );
}
add_action( 'admin_init', 'pl_ad_register_settings' );

/**
 * Sanitize all settings on save.
 *
 * @param  array $input Raw POST data.
 * @return array        Sanitized settings.
 */
function pl_ad_sanitize_settings( $input ) {
	$defaults = pl_ad_defaults();
	$clean    = array();

	// Booleans — unchecked checkboxes are absent from POST.
	$bools = array(
		'enabled', 'dummy_mode', 'debug_overlay', 'record_data',
		'mobile_enabled', 'desktop_enabled',
		'fmt_interstitial', 'fmt_anchor', 'fmt_top_anchor', 'fmt_300x250',
		'fmt_970x250', 'fmt_728x90', 'fmt_320x100', 'fmt_160x600',
		'fmt_pause', 'fmt_video',
		'passback_enabled',
	);
	foreach ( $bools as $key ) {
		$clean[ $key ] = ! empty( $input[ $key ] );
	}

	// Integers with min/max clamping.
	$ints = array(
		'gate_scroll_pct'       => array( 0, 100 ),
		'gate_time_sec'         => array( 0, 60 ),
		'gate_dir_changes'      => array( 0, 10 ),
		'max_display_ads'       => array( 0, 20 ),
		'min_spacing_px'        => array( 200, 2000 ),
		'min_paragraphs_before' => array( 0, 20 ),
		'min_gap_paragraphs'    => array( 1, 20 ),
		'pause_min_ads'         => array( 0, 10 ),
	);
	foreach ( $ints as $key => $range ) {
		$val = isset( $input[ $key ] ) ? (int) $input[ $key ] : $defaults[ $key ];
		$clean[ $key ] = max( $range[0], min( $range[1], $val ) );
	}

	// Text fields.
	$texts = array(
		'network_code', 'slot_prefix',
		'slot_interstitial', 'slot_anchor', 'slot_top_anchor', 'slot_300x250',
		'slot_970x250', 'slot_728x90', 'slot_320x100', 'slot_160x600', 'slot_pause',
		'backfill_script_url', 'backfill_anchor_tag', 'backfill_interstitial_tag',
	);
	foreach ( $texts as $key ) {
		$clean[ $key ] = isset( $input[ $key ] ) ? sanitize_text_field( $input[ $key ] ) : $defaults[ $key ];
	}

	// Textarea fields.
	$clean['backfill_display_tags'] = isset( $input['backfill_display_tags'] )
		? sanitize_textarea_field( $input['backfill_display_tags'] )
		: $defaults['backfill_display_tags'];

	// Backfill check delay (integer with clamping).
	$delay = isset( $input['backfill_check_delay'] ) ? (int) $input['backfill_check_delay'] : $defaults['backfill_check_delay'];
	$clean['backfill_check_delay'] = max( 1000, min( 10000, $delay ) );

	return $clean;
}

/**
 * Render the settings page with tabbed navigation.
 */
function pl_ad_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Handle ads.txt save (separate from Settings API).
	if ( isset( $_POST['pl_ads_txt_save'] ) && check_admin_referer( 'pl_ads_txt_nonce' ) ) {
		$ads_txt = isset( $_POST['pl_ads_txt_content'] ) ? sanitize_textarea_field( $_POST['pl_ads_txt_content'] ) : '';
		pl_ad_write_ads_txt( $ads_txt );
		echo '<div class="notice notice-success is-dismissible"><p>ads.txt saved successfully.</p></div>';
	}

	// Handle Newor Media ads.txt merge.
	if ( isset( $_POST['pl_ads_txt_merge_newor'] ) && check_admin_referer( 'pl_ads_txt_nonce' ) ) {
		$upload_dir = wp_upload_dir();
		$newor_path = $upload_dir['basedir'] . '/ads.txt';
		if ( file_exists( $newor_path ) ) {
			$current    = pl_ad_read_ads_txt();
			$newor_data = file_get_contents( $newor_path );
			$merged     = rtrim( $current ) . "\n\n# --- Newor Media (Waldo) ads.txt — merged " . current_time( 'Y-m-d H:i' ) . " ---\n" . $newor_data;
			pl_ad_write_ads_txt( $merged );
			echo '<div class="notice notice-success is-dismissible"><p style="color:#6d28d9;font-weight:600">Newor Media ads.txt merged successfully (' . number_format( count( file( $newor_path ) ) ) . ' lines appended).</p></div>';
		} else {
			echo '<div class="notice notice-error is-dismissible"><p>Newor Media ads.txt not found at <code>' . esc_html( $newor_path ) . '</code>.</p></div>';
		}
	}

	$settings = pl_ad_settings();
	$tab      = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'global';

	?>
	<div class="wrap">
		<h1>PinLightning Ad Engine</h1>

		<p>
			<?php if ( $settings['enabled'] ) : ?>
				<span style="color:#46b450;font-weight:600;">&#9679; Ads Active</span>
				<?php if ( $settings['dummy_mode'] ) : ?>
					&mdash; <span style="color:#f0b849;">Dummy Mode</span>
				<?php endif; ?>
			<?php else : ?>
				<span style="color:#dc3232;">&#9679; Ads Disabled</span>
			<?php endif; ?>
		</p>

		<div style="margin-bottom:10px;display:flex;gap:8px;">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=pl-ad-analytics-dashboard' ) ); ?>" class="button button-primary">Analytics Dashboard</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=pl-ad-live-sessions' ) ); ?>" class="button">Live Sessions</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=pl-ad-optimizer' ) ); ?>" class="button">Optimizer</a>
		</div>

		<nav class="nav-tab-wrapper">
			<a href="?page=pl-ad-engine&tab=global" class="nav-tab <?php echo 'global' === $tab ? 'nav-tab-active' : ''; ?>">Global Controls</a>
			<a href="?page=pl-ad-engine&tab=codes" class="nav-tab <?php echo 'codes' === $tab ? 'nav-tab-active' : ''; ?>">Ad Codes</a>
			<a href="?page=pl-ad-engine&tab=adstxt" class="nav-tab <?php echo 'adstxt' === $tab ? 'nav-tab-active' : ''; ?>">ads.txt</a>
		</nav>

		<?php if ( 'adstxt' === $tab ) : ?>
			<?php pl_ad_render_ads_txt_tab(); ?>
		<?php else : ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'pl_ad_settings_group' ); ?>
				<?php
				if ( 'codes' === $tab ) {
					pl_ad_render_codes_tab( $settings );
				} else {
					pl_ad_render_global_tab( $settings );
				}
				?>
				<?php submit_button(); ?>
			</form>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Global Controls tab content.
 *
 * @param array $s Current settings.
 */
function pl_ad_render_global_tab( $s ) {
	?>
	<table class="form-table">
		<tr><th colspan="2"><h2>Master Switch</h2></th></tr>
		<tr>
			<th>Ads Enabled</th>
			<td>
				<label><input type="checkbox" name="pl_ad_settings[enabled]" value="1" <?php checked( $s['enabled'] ); ?>> Enable ad serving</label>
				<p class="description">Master kill switch. When off, no ads load or inject.</p>
			</td>
		</tr>
		<tr>
			<th>Dummy Mode</th>
			<td>
				<label><input type="checkbox" name="pl_ad_settings[dummy_mode]" value="1" <?php checked( $s['dummy_mode'] ); ?>> Show colored placeholders instead of real ads</label>
				<p class="description">For development/testing. Shows size labels and colored boxes.</p>
			</td>
		</tr>
		<tr>
			<th>Debug Overlay</th>
			<td>
				<label><input type="checkbox" name="pl_ad_settings[debug_overlay]" value="1" <?php checked( $s['debug_overlay'] ); ?>> Show debug overlay for admins</label>
			</td>
		</tr>
		<tr>
			<th>Record Viewability Data</th>
			<td>
				<label><input type="checkbox" name="pl_ad_settings[record_data]" value="1" <?php checked( $s['record_data'] ); ?>> Send viewability data to REST endpoint</label>
			</td>
		</tr>

		<tr><th colspan="2"><h2>Engagement Gate</h2></th></tr>
		<tr>
			<th>Scroll Threshold (%)</th>
			<td>
				<input type="number" name="pl_ad_settings[gate_scroll_pct]" value="<?php echo esc_attr( $s['gate_scroll_pct'] ); ?>" min="0" max="100" class="small-text">
				<p class="description">User must scroll this % before ads load. Proves engagement.</p>
			</td>
		</tr>
		<tr>
			<th>Time on Page (seconds)</th>
			<td>
				<input type="number" name="pl_ad_settings[gate_time_sec]" value="<?php echo esc_attr( $s['gate_time_sec'] ); ?>" min="0" max="60" class="small-text">
				<p class="description">Minimum seconds on page before ads load.</p>
			</td>
		</tr>
		<tr>
			<th>Direction Changes</th>
			<td>
				<input type="number" name="pl_ad_settings[gate_dir_changes]" value="<?php echo esc_attr( $s['gate_dir_changes'] ); ?>" min="0" max="10" class="small-text">
				<p class="description">Required scroll direction changes (proves active reading).</p>
			</td>
		</tr>

		<tr><th colspan="2"><h2>Density Controls</h2></th></tr>
		<tr>
			<th>Max Display Ads</th>
			<td>
				<input type="number" name="pl_ad_settings[max_display_ads]" value="<?php echo esc_attr( $s['max_display_ads'] ); ?>" min="0" max="20" class="small-text">
				<p class="description">Maximum in-content display ads per page (12 default — higher values risk eCPM drop from low viewability).</p>
			</td>
		</tr>
		<tr>
			<th>Min Spacing (px)</th>
			<td>
				<input type="number" name="pl_ad_settings[min_spacing_px]" value="<?php echo esc_attr( $s['min_spacing_px'] ); ?>" min="200" max="2000" class="small-text">
				<p class="description">Minimum pixels between ad zones.</p>
			</td>
		</tr>
		<tr>
			<th>Skip First N Paragraphs</th>
			<td>
				<input type="number" name="pl_ad_settings[min_paragraphs_before]" value="<?php echo esc_attr( $s['min_paragraphs_before'] ); ?>" min="0" max="20" class="small-text">
				<p class="description">No ads in the first N paragraphs of content.</p>
			</td>
		</tr>
		<tr>
			<th>Min Gap (paragraphs)</th>
			<td>
				<input type="number" name="pl_ad_settings[min_gap_paragraphs]" value="<?php echo esc_attr( $s['min_gap_paragraphs'] ); ?>" min="1" max="20" class="small-text">
				<p class="description">Minimum paragraphs between ad zones.</p>
			</td>
		</tr>

		<tr><th colspan="2"><h2>Device Controls</h2></th></tr>
		<tr>
			<th>Mobile</th>
			<td><label><input type="checkbox" name="pl_ad_settings[mobile_enabled]" value="1" <?php checked( $s['mobile_enabled'] ); ?>> Enable ads on mobile</label></td>
		</tr>
		<tr>
			<th>Desktop</th>
			<td><label><input type="checkbox" name="pl_ad_settings[desktop_enabled]" value="1" <?php checked( $s['desktop_enabled'] ); ?>> Enable ads on desktop</label></td>
		</tr>

		<tr><th colspan="2"><h2>Format Toggles</h2></th></tr>
		<tr>
			<th>Interstitial</th>
			<td><label><input type="checkbox" name="pl_ad_settings[fmt_interstitial]" value="1" <?php checked( $s['fmt_interstitial'] ); ?>> Full-screen overlay between page views</label></td>
		</tr>
		<tr>
			<th>Anchor (Bottom Sticky)</th>
			<td><label><input type="checkbox" name="pl_ad_settings[fmt_anchor]" value="1" <?php checked( $s['fmt_anchor'] ); ?>> Sticky banner at bottom of viewport</label></td>
		</tr>
		<tr>
			<th>Top Anchor</th>
			<td><label><input type="checkbox" name="pl_ad_settings[fmt_top_anchor]" value="1" <?php checked( $s['fmt_top_anchor'] ); ?>> Sticky banner at top of viewport</label></td>
		</tr>
		<tr>
			<th>300x250</th>
			<td><label><input type="checkbox" name="pl_ad_settings[fmt_300x250]" value="1" <?php checked( $s['fmt_300x250'] ); ?>> In-content medium rectangle</label></td>
		</tr>
		<tr>
			<th>970x250</th>
			<td><label><input type="checkbox" name="pl_ad_settings[fmt_970x250]" value="1" <?php checked( $s['fmt_970x250'] ); ?>> In-content billboard (desktop only)</label></td>
		</tr>
		<tr>
			<th>728x90</th>
			<td><label><input type="checkbox" name="pl_ad_settings[fmt_728x90]" value="1" <?php checked( $s['fmt_728x90'] ); ?>> Leaderboard (nav zone, tablet)</label></td>
		</tr>
		<tr>
			<th>320x100</th>
			<td><label><input type="checkbox" name="pl_ad_settings[fmt_320x100]" value="1" <?php checked( $s['fmt_320x100'] ); ?>> Large mobile banner (nav zone, mobile)</label></td>
		</tr>
		<tr>
			<th>160x600</th>
			<td><label><input type="checkbox" name="pl_ad_settings[fmt_160x600]" value="1" <?php checked( $s['fmt_160x600'] ); ?>> Skyscraper (sidebar)</label></td>
		</tr>
		<tr>
			<th>Pause Banner</th>
			<td>
				<label><input type="checkbox" name="pl_ad_settings[fmt_pause]" value="1" <?php checked( $s['fmt_pause'] ); ?>> Banner shown when user pauses scrolling</label>
				<br><label style="margin-top:4px;display:inline-block">Min display ads before pause: <input type="number" name="pl_ad_settings[pause_min_ads]" value="<?php echo (int) $s['pause_min_ads']; ?>" min="0" max="10" style="width:60px"></label>
			</td>
		</tr>
		<tr>
			<th>InPage Video</th>
			<td><label><input type="checkbox" name="pl_ad_settings[fmt_video]" value="1" <?php checked( $s['fmt_video'] ); ?>> playerPro inpage video (intro section)</label></td>
		</tr>

		<tr><th colspan="2"><h2>Passback (Backfill)</h2></th></tr>
		<tr>
			<th>Newor Media Passback</th>
			<td>
				<label><input type="checkbox" name="pl_ad_settings[passback_enabled]" value="1" <?php checked( $s['passback_enabled'] ); ?>> Try Newor Media (Waldo) when Ad.Plus returns no-fill</label>
				<p class="description">Waldo script loads <strong>only</strong> on first no-fill — zero PageSpeed impact if Ad.Plus fills every slot. Site ID: 24273.</p>
			</td>
		</tr>
	</table>
	<?php
}

/**
 * Ad Codes tab content.
 *
 * @param array $s Current settings.
 */
function pl_ad_render_codes_tab( $s ) {
	?>
	<h3>Primary Network (Ad.Plus / Google Ad Manager)</h3>
	<p class="description">These codes are used for the primary ad auction. Ads load after the engagement gate opens.</p>

	<table class="form-table">
		<tr>
			<th>Network Code</th>
			<td>
				<input type="text" name="pl_ad_settings[network_code]" value="<?php echo esc_attr( $s['network_code'] ); ?>" class="regular-text">
				<p class="description">Google Ad Manager network code (e.g. 22953639975)</p>
			</td>
		</tr>
		<tr>
			<th>Slot Path Prefix</th>
			<td>
				<input type="text" name="pl_ad_settings[slot_prefix]" value="<?php echo esc_attr( $s['slot_prefix'] ); ?>" class="regular-text">
				<p class="description">Full slot path = prefix + slot name</p>
			</td>
		</tr>
	</table>

	<h4>Ad Unit Slot Names</h4>
	<table class="form-table">
		<tr>
			<th>Interstitial</th>
			<td><input type="text" name="pl_ad_settings[slot_interstitial]" value="<?php echo esc_attr( $s['slot_interstitial'] ); ?>" class="regular-text"></td>
		</tr>
		<tr>
			<th>Anchor (Bottom)</th>
			<td><input type="text" name="pl_ad_settings[slot_anchor]" value="<?php echo esc_attr( $s['slot_anchor'] ); ?>" class="regular-text"></td>
		</tr>
		<tr>
			<th>Anchor (Top)</th>
			<td><input type="text" name="pl_ad_settings[slot_top_anchor]" value="<?php echo esc_attr( $s['slot_top_anchor'] ); ?>" class="regular-text"></td>
		</tr>
		<tr>
			<th>300x250</th>
			<td><input type="text" name="pl_ad_settings[slot_300x250]" value="<?php echo esc_attr( $s['slot_300x250'] ); ?>" class="regular-text"></td>
		</tr>
		<tr>
			<th>970x250</th>
			<td><input type="text" name="pl_ad_settings[slot_970x250]" value="<?php echo esc_attr( $s['slot_970x250'] ); ?>" class="regular-text"></td>
		</tr>
		<tr>
			<th>728x90</th>
			<td><input type="text" name="pl_ad_settings[slot_728x90]" value="<?php echo esc_attr( $s['slot_728x90'] ); ?>" class="regular-text"></td>
		</tr>
		<tr>
			<th>320x100</th>
			<td><input type="text" name="pl_ad_settings[slot_320x100]" value="<?php echo esc_attr( $s['slot_320x100'] ); ?>" class="regular-text"></td>
		</tr>
		<tr>
			<th>160x600</th>
			<td><input type="text" name="pl_ad_settings[slot_160x600]" value="<?php echo esc_attr( $s['slot_160x600'] ); ?>" class="regular-text"></td>
		</tr>
		<tr>
			<th>Pause Banner</th>
			<td><input type="text" name="pl_ad_settings[slot_pause]" value="<?php echo esc_attr( $s['slot_pause'] ); ?>" class="regular-text"></td>
		</tr>
	</table>

	<hr style="margin: 30px 0;">

	<h3>Backfill Network (Newor Media)</h3>
	<p class="description">When the primary network doesn't fill a slot, these codes are used as fallback. The backfill script only loads when a no-fill occurs &mdash; zero PageSpeed impact otherwise.</p>

	<table class="form-table">
		<tr>
			<th>Backfill Script URL</th>
			<td>
				<input type="text" name="pl_ad_settings[backfill_script_url]" value="<?php echo esc_attr( $s['backfill_script_url'] ); ?>" class="regular-text" style="width:400px;">
				<p class="description">Waldo/Newor script URL. Leave empty to disable backfill script loading.</p>
			</td>
		</tr>
		<tr>
			<th>Display Ad Tags</th>
			<td>
				<textarea name="pl_ad_settings[backfill_display_tags]" rows="6" class="large-text code" style="max-width:400px;"><?php echo esc_textarea( $s['backfill_display_tags'] ); ?></textarea>
				<p class="description">One Waldo tag ID per line (e.g. waldo-tag-29686). Tags are assigned to no-fill zones in order &mdash; first tag goes to first no-fill, second to next, etc. Maximum 6 tags per page.</p>
			</td>
		</tr>
		<tr>
			<th>Anchor/Sticky Footer Tag</th>
			<td>
				<input type="text" name="pl_ad_settings[backfill_anchor_tag]" value="<?php echo esc_attr( $s['backfill_anchor_tag'] ); ?>" class="regular-text">
				<p class="description">Waldo tag for sticky footer backfill when primary anchor doesn't fill. Leave empty to skip anchor backfill.</p>
			</td>
		</tr>
		<tr>
			<th>Interstitial Tag</th>
			<td>
				<input type="text" name="pl_ad_settings[backfill_interstitial_tag]" value="<?php echo esc_attr( $s['backfill_interstitial_tag'] ); ?>" class="regular-text">
				<p class="description">Waldo tag for interstitial backfill. Leave empty to skip interstitial backfill (recommended &mdash; Waldo doesn't support interstitials well).</p>
			</td>
		</tr>
		<tr>
			<th>Fill Check Delay (ms)</th>
			<td>
				<input type="number" name="pl_ad_settings[backfill_check_delay]" value="<?php echo esc_attr( $s['backfill_check_delay'] ); ?>" class="small-text" min="1000" max="10000" step="500">
				<p class="description">How long to wait for backfill to render before collapsing (default: 3000ms). Lower = faster collapse, higher = more chance to fill.</p>
			</td>
		</tr>
	</table>
	<?php
}

/**
 * ads.txt tab content.
 */
function pl_ad_render_ads_txt_tab() {
	$content = pl_ad_read_ads_txt();
	$path    = ABSPATH . 'ads.txt';
	$exists  = file_exists( $path );

	// Check for Newor Media ads.txt merge file.
	$upload_dir  = wp_upload_dir();
	$newor_path  = $upload_dir['basedir'] . '/ads.txt';
	$newor_exists = file_exists( $newor_path );
	$has_newor    = strpos( $content, 'thisiswaldo.com' ) !== false || strpos( $content, 'newormedia' ) !== false;
	?>
	<form method="post">
		<?php wp_nonce_field( 'pl_ads_txt_nonce' ); ?>
		<table class="form-table">
			<tr>
				<td>
					<p>
						File: <code><?php echo esc_html( $path ); ?></code><br>
						<?php if ( $exists ) : ?>
							Status: <span style="color:#46b450;font-weight:600;">File exists</span> &mdash;
							<a href="<?php echo esc_url( home_url( '/ads.txt' ) ); ?>" target="_blank">View live &rarr;</a>
						<?php else : ?>
							Status: <span style="color:#f0b849;font-weight:600;">File does not exist yet</span> (will be created on save)
						<?php endif; ?>
					</p>
					<?php if ( $newor_exists && ! $has_newor ) : ?>
						<p style="background:#f3e8ff;border:1px solid #6d28d9;border-radius:4px;padding:8px 12px;margin:8px 0">
							<strong style="color:#6d28d9">Newor Media ads.txt found</strong> at <code><?php echo esc_html( $newor_path ); ?></code>
							(<?php echo number_format( count( file( $newor_path ) ) ); ?> lines).
							<br>Click &ldquo;Merge Newor Media ads.txt&rdquo; to append it below the Ad.Plus entries.
						</p>
						<input type="submit" name="pl_ads_txt_merge_newor" class="button" style="background:#6d28d9;border-color:#6d28d9;color:#fff;margin-bottom:8px" value="Merge Newor Media ads.txt">
					<?php elseif ( $has_newor ) : ?>
						<p style="color:#6d28d9;font-weight:600;margin:4px 0">&#10003; Newor Media entries already present in ads.txt</p>
					<?php endif; ?>
					<textarea name="pl_ads_txt_content" rows="15" cols="80" class="large-text code" style="font-family:monospace"><?php echo esc_textarea( $content ); ?></textarea>
					<p class="description">
						Standard format: <code>google.com, pub-XXXXXXXXXXXXXXXX, DIRECT, f08c47fec0942fa0</code>
					</p>
				</td>
			</tr>
		</table>
		<input type="submit" name="pl_ads_txt_save" class="button button-primary" value="Save ads.txt">
	</form>
	<?php
}

/* ================================================================
 * 3. ADS.TXT READ / WRITE
 * ================================================================ */

/**
 * Read ads.txt content from ABSPATH.
 *
 * @return string
 */
function pl_ad_read_ads_txt() {
	$path = ABSPATH . 'ads.txt';
	if ( file_exists( $path ) && is_readable( $path ) ) {
		return file_get_contents( $path );
	}
	return '';
}

/**
 * Write ads.txt content to ABSPATH.
 *
 * @param string $content The ads.txt content.
 */
function pl_ad_write_ads_txt( $content ) {
	$path = ABSPATH . 'ads.txt';
	$content = str_replace( "\r\n", "\n", $content );
	$content = str_replace( "\r", "\n", $content );
	$content = rtrim( $content ) . "\n";
	file_put_contents( $path, $content );
}

/* ================================================================
 * 4. AD SIZE STRATEGY
 * ================================================================ */

/**
 * Get ad size for a zone based on position.
 *
 * Best performers: 300x250 ($0.49 eCPM), 970x250 ($0.80 eCPM).
 * Mobile: 300x250 dominates. Desktop: alternate 300x250 and 970x250.
 *
 * @param  int   $zone_number Zero-based zone index.
 * @return array              Keys: mobile, desktop.
 */
function pinlightning_get_zone_size( $zone_number ) {
	$mobile_sizes  = array( '300x250' );
	$desktop_sizes = array( '300x250', '970x250' );

	return array(
		'mobile'  => $mobile_sizes[ $zone_number % count( $mobile_sizes ) ],
		'desktop' => $desktop_sizes[ $zone_number % count( $desktop_sizes ) ],
	);
}

/* ================================================================
 * 5. CONTENT SCANNER — ZONE INJECTION
 * ================================================================ */

/**
 * Scan content and inject ad zones at optimal break points.
 *
 * Scoring rules:
 * +5  Before a new section (h2, h3)
 * +4  After an image/figure (natural visual pause)
 * +3  After a long paragraph (300+ chars)
 * +1  After any paragraph
 * -10 Inside list, blockquote, table, pre
 * -5  Directly after a heading
 *
 * @param  string $content Post content HTML.
 * @return string          Content with ad zone divs inserted.
 */
function pinlightning_scan_and_inject_zones( $content ) {
	$s = pl_ad_settings();

	if ( ! $s['enabled'] ) {
		return $content;
	}
	if ( ! is_singular() && ! isset( $GLOBALS['pinlightning_rest_content'] ) ) {
		return $content;
	}

	$min_before = (int) $s['min_paragraphs_before'];
	$min_gap    = (int) $s['min_gap_paragraphs'];
	$max_zones  = (int) $s['max_display_ads'];

	// Count paragraphs — skip short posts.
	$p_count = substr_count( strtolower( $content ), '</p>' );
	if ( $p_count < $min_before + 2 ) {
		return $content;
	}

	// Parse content into block elements.
	$dom = new DOMDocument();
	libxml_use_internal_errors( true );
	$wrapped = '<html><body>' . mb_convert_encoding( $content, 'HTML-ENTITIES', 'UTF-8' ) . '</body></html>';
	$dom->loadHTML( $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
	libxml_clear_errors();

	$body = $dom->getElementsByTagName( 'body' )->item( 0 );
	if ( ! $body ) {
		return $content;
	}

	// Build block list with scores.
	$blocks  = array();
	$p_index = 0;

	foreach ( $body->childNodes as $node ) {
		if ( $node->nodeType !== XML_ELEMENT_NODE ) {
			continue;
		}

		$tag      = strtolower( $node->tagName );
		$text_len = mb_strlen( trim( $node->textContent ) );
		$has_img  = $node->getElementsByTagName( 'img' )->length > 0 || 'figure' === $tag;

		$score = 0;

		// Positive signals.
		if ( 'p' === $tag && $text_len > 300 ) {
			$score += 3;
		} elseif ( 'p' === $tag ) {
			$score += 1;
		}
		if ( $has_img ) {
			$score += 4;
		}

		// Negative signals.
		if ( in_array( $tag, array( 'ul', 'ol', 'table', 'blockquote', 'pre', 'details' ), true ) ) {
			$score -= 10;
		}
		if ( in_array( $tag, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ) {
			$score -= 5;
		}

		if ( 'p' === $tag ) {
			$p_index++;
		}

		$blocks[] = array(
			'node'     => $node,
			'tag'      => $tag,
			'p_index'  => $p_index,
			'score'    => $score,
			'text_len' => $text_len,
		);
	}

	// Boost score if next element is a heading (section break = great ad spot).
	for ( $i = 0; $i < count( $blocks ) - 1; $i++ ) {
		if ( in_array( $blocks[ $i + 1 ]['tag'], array( 'h2', 'h3' ), true ) ) {
			$blocks[ $i ]['score'] += 5;
		}
	}

	// Select best positions respecting constraints.
	$positions = array();
	$last_ad_p = -$min_gap;
	$zone_num  = 0;

	for ( $i = 0; $i < count( $blocks ); $i++ ) {
		if ( $zone_num >= $max_zones ) {
			break;
		}
		if ( $blocks[ $i ]['score'] < 2 ) {
			continue;
		}
		if ( $blocks[ $i ]['p_index'] < $min_before ) {
			continue;
		}
		if ( $blocks[ $i ]['p_index'] - $last_ad_p < $min_gap ) {
			continue;
		}

		$positions[] = $i;
		$last_ad_p   = $blocks[ $i ]['p_index'];
		$zone_num++;
	}

	// Fallback: evenly spaced if scanner found too few spots.
	if ( $zone_num < 2 && $p_count >= 8 ) {
		$positions = array();
		$spacing   = max( $min_gap, intval( $p_count / 4 ) );
		$p_counter = 0;
		$zone_num  = 0;

		for ( $i = 0; $i < count( $blocks ); $i++ ) {
			if ( 'p' === $blocks[ $i ]['tag'] ) {
				$p_counter++;
			}
			if ( $p_counter >= $min_before && 0 === $p_counter % $spacing ) {
				$positions[] = $i;
				$zone_num++;
				if ( $zone_num >= $max_zones ) {
					break;
				}
			}
		}
	}

	// Rebuild HTML with ad zones inserted.
	$output       = '';
	$zone_counter = 0;

	foreach ( $blocks as $idx => $block ) {
		$output .= $dom->saveHTML( $block['node'] );

		if ( in_array( $idx, $positions, true ) ) {
			$zone_counter++;
			$zid   = 'auto-' . $zone_counter;
			$sizes = pinlightning_get_zone_size( $zone_counter - 1 );

			$output .= sprintf(
				'<div class="ad-zone" data-zone-id="%s" data-size-mobile="%s" data-size-desktop="%s" data-injected="false" data-score="%d" aria-hidden="true"></div>',
				esc_attr( $zid ),
				esc_attr( $sizes['mobile'] ),
				esc_attr( $sizes['desktop'] ),
				$block['score']
			);
		}
	}

	return $output;
}
// V4: content scanner disabled — ad injection handled by engagement-breaks.php
// via pl_inject_item_ads() and pl_inject_intro_ads().
// add_filter( 'the_content', 'pinlightning_scan_and_inject_zones', 55 );

/* ================================================================
 * 6. MANUAL ZONE HELPER
 * ================================================================ */

/**
 * Output an ad zone div for use in templates (single.php, etc.).
 *
 * @param  string $zone_id     Unique zone identifier.
 * @param  string $mobile_size Mobile ad size (default 300x250).
 * @param  string $desktop_size Desktop ad size (default 300x250).
 * @return string              HTML div or empty string if disabled.
 */
function pinlightning_ad_zone( $zone_id, $mobile_size = '300x250', $desktop_size = '300x250' ) {
	$s = pl_ad_settings();
	if ( ! $s['enabled'] ) {
		return '';
	}

	return sprintf(
		'<div class="ad-zone" data-zone-id="%s" data-size-mobile="%s" data-size-desktop="%s" data-injected="false" aria-hidden="true"></div>',
		esc_attr( $zone_id ),
		esc_attr( $mobile_size ),
		esc_attr( $desktop_size )
	);
}

/* ================================================================
 * 7. FRONTEND CONFIGURATION PROVIDER
 * ================================================================ */

/**
 * Pass ad engine settings to the frontend JS via wp_localize_script.
 */
function pinlightning_ads_enqueue() {
	$s = pl_ad_settings();

	if ( ! $s['enabled'] || ! is_singular() ) {
		return;
	}

	wp_localize_script( 'pinlightning-smart-ads', 'plAds', array(
		// Mode.
		'dummy'           => (bool) $s['dummy_mode'],
		'debug'           => (bool) $s['debug_overlay'] || isset( $_GET['pl_debug'] ) || current_user_can( 'manage_options' ),
		'record'          => (bool) $s['record_data'],

		// Engagement Gate.
		'gateScrollPct'   => (int) $s['gate_scroll_pct'],
		'gateTimeSec'     => (int) $s['gate_time_sec'],
		'gateDirChanges'  => (int) $s['gate_dir_changes'],

		// Density.
		'maxAds'          => (int) $s['max_display_ads'],
		'minSpacingPx'    => (int) $s['min_spacing_px'],

		// Device.
		'mobileEnabled'   => (bool) $s['mobile_enabled'],
		'desktopEnabled'  => (bool) $s['desktop_enabled'],

		// Formats.
		'fmtInterstitial' => (bool) $s['fmt_interstitial'],
		'fmtAnchor'       => (bool) $s['fmt_anchor'],
		'fmtTopAnchor'    => (bool) $s['fmt_top_anchor'],
		'fmt300x250'      => (bool) $s['fmt_300x250'],
		'fmt970x250'      => (bool) $s['fmt_970x250'],
		'fmt728x90'       => (bool) $s['fmt_728x90'],
		'fmt320x100'      => (bool) $s['fmt_320x100'],
		'fmt160x600'      => (bool) $s['fmt_160x600'],
		'fmtPause'        => (bool) $s['fmt_pause'],
		'fmtVideo'        => (bool) $s['fmt_video'],
		'pauseMinAds'     => (int) $s['pause_min_ads'],

		// Passback.
		'passbackEnabled' => (bool) $s['passback_enabled'],

		// Backfill Network.
		'backfillScriptUrl'       => $s['backfill_script_url'],
		'backfillDisplayTags'     => array_values( array_filter( array_map( 'trim', explode( "\n", $s['backfill_display_tags'] ) ) ) ),
		'backfillAnchorTag'       => $s['backfill_anchor_tag'],
		'backfillInterstitialTag' => $s['backfill_interstitial_tag'],
		'backfillCheckDelay'      => (int) $s['backfill_check_delay'],

		// Network / Slots.
		'networkCode'     => $s['network_code'],
		'slotPrefix'      => $s['slot_prefix'],
		'slots'           => array(
			'interstitial' => $s['slot_interstitial'],
			'anchor'       => $s['slot_anchor'],
			'topAnchor'    => $s['slot_top_anchor'],
			'300x250'      => $s['slot_300x250'],
			'970x250'      => $s['slot_970x250'],
			'728x90'       => $s['slot_728x90'],
			'320x100'      => $s['slot_320x100'],
			'160x600'      => $s['slot_160x600'],
			'pause'        => $s['slot_pause'],
		),

		// Context.
		'recordEndpoint'    => rest_url( 'pinlightning/v1/ad-data' ),
		'heartbeatEndpoint' => rest_url( 'pl-ads/v1/heartbeat' ),
		'liveMonitor'       => (bool) get_transient( 'pl_live_monitor_active' ),
		'nonce'             => wp_create_nonce( 'wp_rest' ),
		'postId'            => get_the_ID(),
		'postSlug'          => get_post_field( 'post_name', get_the_ID() ),
	) );
}
add_action( 'wp_enqueue_scripts', 'pinlightning_ads_enqueue', 20 );
