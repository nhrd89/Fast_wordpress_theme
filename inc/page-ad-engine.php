<?php
/**
 * PinLightning Page Ad Engine
 *
 * Completely separate ad system for non-post pages (homepage, category, static pages).
 * Isolated from post ads (smart-ads.js / initial-ads.js) — different settings,
 * globals, CSS classes, GPT slot IDs, and REST endpoints.
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
 * Default settings for page ads.
 *
 * @return array
 */
function pl_page_ad_defaults() {
	return array(
		'enabled'          => false,
		'dummy_mode'       => true,
		'slot_prefix'      => '/21849154601,22953639975/',
		'network_code'     => '22953639975',

		// Per-page-type toggles.
		'homepage_enabled' => true,
		'category_enabled' => true,
		'page_enabled'     => true,

		// Slot limits per page type.
		'homepage_max'     => 3,
		'category_max'     => 4,
		'page_max'         => 2,

		// Format toggles.
		'fmt_anchor'       => true,
		'fmt_interstitial' => false,

		// Spacing.
		'desktop_spacing'  => 600,
		'mobile_spacing'   => 400,

		// Category archive: inject after every Nth post.
		'category_every_n' => 4,
	);
}

/**
 * Get merged page ad settings.
 *
 * @return array
 */
function pl_page_ad_settings() {
	static $cached = null;
	if ( null !== $cached ) {
		return $cached;
	}
	$defaults = pl_page_ad_defaults();
	$saved    = get_option( 'pl_page_ad_settings', array() );
	$cached   = wp_parse_args( $saved, $defaults );
	return $cached;
}

/* ================================================================
 * 2. SETTINGS REGISTRATION
 * ================================================================ */

/**
 * Register page ad settings with the Settings API.
 */
function pl_page_ad_register_settings() {
	register_setting( 'pl_page_ad_settings_group', 'pl_page_ad_settings', array(
		'type'              => 'array',
		'sanitize_callback' => 'pl_page_ad_sanitize',
	) );
}
add_action( 'admin_init', 'pl_page_ad_register_settings' );

/**
 * Sanitize page ad settings.
 *
 * @param array $input Raw input.
 * @return array Sanitized settings.
 */
function pl_page_ad_sanitize( $input ) {
	$defaults = pl_page_ad_defaults();
	$clean    = array();

	// Booleans (checkboxes).
	$bools = array(
		'enabled', 'dummy_mode',
		'homepage_enabled', 'category_enabled', 'page_enabled',
		'fmt_anchor', 'fmt_interstitial',
	);
	foreach ( $bools as $key ) {
		$clean[ $key ] = ! empty( $input[ $key ] );
	}

	// Integers.
	$ints = array(
		'homepage_max', 'category_max', 'page_max',
		'desktop_spacing', 'mobile_spacing', 'category_every_n',
	);
	foreach ( $ints as $key ) {
		$clean[ $key ] = isset( $input[ $key ] ) ? absint( $input[ $key ] ) : $defaults[ $key ];
	}

	// Strings.
	$strings = array( 'slot_prefix', 'network_code' );
	foreach ( $strings as $key ) {
		$clean[ $key ] = isset( $input[ $key ] ) ? sanitize_text_field( $input[ $key ] ) : $defaults[ $key ];
	}

	// Enforce minimum spacing.
	$clean['desktop_spacing'] = max( 400, $clean['desktop_spacing'] );
	$clean['mobile_spacing']  = max( 300, $clean['mobile_spacing'] );

	return $clean;
}

/* ================================================================
 * 3. ADMIN TAB RENDERING
 * ================================================================ */

/**
 * Render the Page Ads tab content inside the ad engine admin page.
 *
 * Called from pl_ad_settings_page() when tab=pageads.
 */
function pl_page_ad_render_tab() {
	$s = pl_page_ad_settings();
	?>
	<form method="post" action="options.php">
		<?php settings_fields( 'pl_page_ad_settings_group' ); ?>

		<h2>Page Ads — Non-Post Pages</h2>
		<p class="description">Separate ad system for homepages, category archives, and static pages. Completely isolated from post ads.</p>

		<table class="form-table">
			<tr>
				<th>Master Switch</th>
				<td>
					<label><input type="checkbox" name="pl_page_ad_settings[enabled]" value="1" <?php checked( $s['enabled'] ); ?>> Enable page ads</label>
				</td>
			</tr>
			<tr>
				<th>Dummy Mode</th>
				<td>
					<label><input type="checkbox" name="pl_page_ad_settings[dummy_mode]" value="1" <?php checked( $s['dummy_mode'] ); ?>> Show colored placeholder boxes instead of real ads</label>
					<p class="description">Use this to verify positioning before going live.</p>
				</td>
			</tr>
		</table>

		<h3>Page Type Controls</h3>
		<table class="form-table">
			<tr>
				<th>Homepage</th>
				<td>
					<label><input type="checkbox" name="pl_page_ad_settings[homepage_enabled]" value="1" <?php checked( $s['homepage_enabled'] ); ?>> Enable ads on homepage</label>
					&nbsp;&mdash;&nbsp; Max slots: <input type="number" name="pl_page_ad_settings[homepage_max]" value="<?php echo esc_attr( $s['homepage_max'] ); ?>" min="0" max="10" style="width:60px">
				</td>
			</tr>
			<tr>
				<th>Category Archives</th>
				<td>
					<label><input type="checkbox" name="pl_page_ad_settings[category_enabled]" value="1" <?php checked( $s['category_enabled'] ); ?>> Enable ads on category pages</label>
					&nbsp;&mdash;&nbsp; Max slots: <input type="number" name="pl_page_ad_settings[category_max]" value="<?php echo esc_attr( $s['category_max'] ); ?>" min="0" max="10" style="width:60px">
					<br>Inject ad after every <input type="number" name="pl_page_ad_settings[category_every_n]" value="<?php echo esc_attr( $s['category_every_n'] ); ?>" min="2" max="10" style="width:60px"> posts
				</td>
			</tr>
			<tr>
				<th>Static Pages</th>
				<td>
					<label><input type="checkbox" name="pl_page_ad_settings[page_enabled]" value="1" <?php checked( $s['page_enabled'] ); ?>> Enable ads on static pages</label>
					&nbsp;&mdash;&nbsp; Max slots: <input type="number" name="pl_page_ad_settings[page_max]" value="<?php echo esc_attr( $s['page_max'] ); ?>" min="0" max="10" style="width:60px">
				</td>
			</tr>
		</table>

		<h3>Formats</h3>
		<table class="form-table">
			<tr>
				<th>Overlay Formats</th>
				<td>
					<label><input type="checkbox" name="pl_page_ad_settings[fmt_anchor]" value="1" <?php checked( $s['fmt_anchor'] ); ?>> Bottom Anchor</label><br>
					<label><input type="checkbox" name="pl_page_ad_settings[fmt_interstitial]" value="1" <?php checked( $s['fmt_interstitial'] ); ?>> Interstitial</label>
				</td>
			</tr>
		</table>

		<h3>Spacing</h3>
		<table class="form-table">
			<tr>
				<th>Desktop (&ge;1025px)</th>
				<td>
					<input type="number" name="pl_page_ad_settings[desktop_spacing]" value="<?php echo esc_attr( $s['desktop_spacing'] ); ?>" min="400" max="1500" style="width:80px"> px between ads
				</td>
			</tr>
			<tr>
				<th>Mobile (&lt;1025px)</th>
				<td>
					<input type="number" name="pl_page_ad_settings[mobile_spacing]" value="<?php echo esc_attr( $s['mobile_spacing'] ); ?>" min="300" max="1500" style="width:80px"> px between ads
				</td>
			</tr>
		</table>

		<h3>Network</h3>
		<table class="form-table">
			<tr>
				<th>Slot Prefix</th>
				<td><input type="text" name="pl_page_ad_settings[slot_prefix]" value="<?php echo esc_attr( $s['slot_prefix'] ); ?>" class="regular-text"></td>
			</tr>
			<tr>
				<th>Network Code</th>
				<td><input type="text" name="pl_page_ad_settings[network_code]" value="<?php echo esc_attr( $s['network_code'] ); ?>" class="regular-text"></td>
			</tr>
		</table>

		<?php submit_button(); ?>
	</form>
	<?php
}

/* ================================================================
 * 4. FRONTEND ENQUEUE — !is_single() ONLY
 * ================================================================ */

/**
 * Output page ad config as inline global variable.
 * Only on non-single pages when page ads are enabled.
 */
function pl_page_ads_enqueue() {
	// Double-lock: PHP-side condition.
	if ( is_single() ) {
		return;
	}

	$s = pl_page_ad_settings();

	if ( ! $s['enabled'] ) {
		return;
	}

	// Determine page type and whether this type is enabled.
	$page_type = 'other';
	$max_slots = 0;

	if ( is_front_page() || is_home() ) {
		if ( ! $s['homepage_enabled'] ) {
			return;
		}
		$page_type = 'homepage';
		$max_slots = (int) $s['homepage_max'];
	} elseif ( is_category() ) {
		if ( ! $s['category_enabled'] ) {
			return;
		}
		$page_type = 'category';
		$max_slots = (int) $s['category_max'];
	} elseif ( is_page() ) {
		if ( ! $s['page_enabled'] ) {
			return;
		}
		$page_type = 'page';
		$max_slots = (int) $s['page_max'];
	} else {
		// Not a supported page type.
		return;
	}

	$is_desktop = ! wp_is_mobile();

	$config = array(
		'dummy'          => (bool) $s['dummy_mode'],
		'pageType'       => $page_type,
		'maxSlots'       => $max_slots,
		'slotPrefix'     => $s['slot_prefix'],
		'networkCode'    => $s['network_code'],
		'fmtAnchor'      => (bool) $s['fmt_anchor'],
		'fmtInterstitial' => (bool) $s['fmt_interstitial'],
		'desktopSpacing' => (int) $s['desktop_spacing'],
		'mobileSpacing'  => (int) $s['mobile_spacing'],
		'eventEndpoint'  => rest_url( 'pl/v1/page-ad-event' ),
		'nonce'          => wp_create_nonce( 'wp_rest' ),
	);

	// Enqueue page-ads.js (deferred, loads after window.load).
	add_action( 'wp_footer', function() use ( $config ) {
		echo '<script>var plPageAds=' . wp_json_encode( $config ) . ';</script>' . "\n";
	}, 97 );

	// Load the page-ads.js script post-window.load (same pattern as post ads).
	add_action( 'wp_footer', function() {
		$src = PINLIGHTNING_URI . '/assets/js/page-ads.js?v=' . PINLIGHTNING_VERSION;
		echo '<script>window.addEventListener("load",function(){var s=document.createElement("script");s.src="' . esc_url( $src ) . '";s.defer=true;document.body.appendChild(s)},{ once:true });</script>' . "\n";
	}, 100 );
}
add_action( 'wp_enqueue_scripts', 'pl_page_ads_enqueue', 20 );

/* ================================================================
 * 5. CATEGORY ARCHIVE AD ANCHORS (via the_post hook)
 * ================================================================ */

/**
 * Inject ad anchor divs between posts in category archives.
 */
function pl_page_ad_category_anchors() {
	if ( ! is_category() || is_single() ) {
		return;
	}

	$s = pl_page_ad_settings();
	if ( ! $s['enabled'] || ! $s['category_enabled'] ) {
		return;
	}

	$every_n = max( 2, (int) $s['category_every_n'] );
	$max     = (int) $s['category_max'];
	$count   = 0;
	$injected = 0;

	add_action( 'the_post', function( $post, $query ) use ( $every_n, $max, &$count, &$injected ) {
		if ( ! $query->is_main_query() ) {
			return;
		}
		$count++;
		// Inject BEFORE the Nth+1 post (i.e. between post N and N+1).
		if ( $count > 1 && ( ( $count - 1 ) % $every_n === 0 ) && $injected < $max ) {
			$injected++;
			echo '<div class="pl-page-ad-anchor" data-slot="category-' . esc_attr( $injected ) . '"></div>' . "\n";
		}
	}, 10, 2 );
}
add_action( 'template_redirect', 'pl_page_ad_category_anchors' );

/* ================================================================
 * 6. STATIC PAGE AD ANCHORS (via the_content filter)
 * ================================================================ */

/**
 * Inject ad anchors into static page content.
 */
function pl_page_ad_static_content( $content ) {
	if ( ! is_page() || is_single() || is_admin() ) {
		return $content;
	}

	$s = pl_page_ad_settings();
	if ( ! $s['enabled'] || ! $s['page_enabled'] ) {
		return $content;
	}

	$max = (int) $s['page_max'];
	if ( $max < 1 ) {
		return $content;
	}

	// Split on </p> and inject anchors evenly.
	$parts = preg_split( '/(<\/p>)/i', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
	if ( ! $parts ) {
		return $content;
	}

	// Count paragraphs.
	$p_count = 0;
	foreach ( $parts as $part ) {
		if ( strtolower( trim( $part ) ) === '</p>' ) {
			$p_count++;
		}
	}

	if ( $p_count < 3 ) {
		return $content;
	}

	// Calculate spacing: inject after every N paragraphs.
	$interval = max( 2, (int) floor( $p_count / ( $max + 1 ) ) );
	$p_idx    = 0;
	$injected = 0;
	$output   = '';

	foreach ( $parts as $part ) {
		$output .= $part;
		if ( strtolower( trim( $part ) ) === '</p>' ) {
			$p_idx++;
			if ( $p_idx % $interval === 0 && $injected < $max ) {
				$injected++;
				$output .= '<div class="pl-page-ad-anchor" data-slot="page-' . $injected . '"></div>' . "\n";
			}
		}
	}

	return $output;
}
add_filter( 'the_content', 'pl_page_ad_static_content', 50 );
