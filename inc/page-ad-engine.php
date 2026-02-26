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

function pl_page_ad_defaults() {
	return array(
		'enabled'          => true,
		'dummy_mode'       => true,
		'slot_prefix'      => '/21849154601,22953639975/',
		'network_code'     => '22953639975',

		// Per-page-type toggles.
		'homepage_enabled' => true,
		'category_enabled' => true,
		'page_enabled'     => true,

		// Slot limits per page type.
		'homepage_max'     => 3,
		'category_max'     => 10,
		'page_max'         => 3,

		// Format toggles.
		'fmt_anchor'       => true,
		'fmt_interstitial' => false,

		// Spacing.
		'desktop_spacing'  => 300,
		'mobile_spacing'   => 250,

		// Category archive: inject after every Nth post.
		'category_every_n' => 3,
	);
}

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

function pl_page_ad_register_settings() {
	register_setting( 'pl_page_ad_settings_group', 'pl_page_ad_settings', array(
		'type'              => 'array',
		'sanitize_callback' => 'pl_page_ad_sanitize',
	) );
}
add_action( 'admin_init', 'pl_page_ad_register_settings' );

function pl_page_ad_sanitize( $input ) {
	$defaults = pl_page_ad_defaults();
	$clean    = array();

	$bools = array(
		'enabled', 'dummy_mode',
		'homepage_enabled', 'category_enabled', 'page_enabled',
		'fmt_anchor', 'fmt_interstitial',
	);
	foreach ( $bools as $key ) {
		$clean[ $key ] = ! empty( $input[ $key ] );
	}

	$ints = array(
		'homepage_max', 'category_max', 'page_max',
		'desktop_spacing', 'mobile_spacing', 'category_every_n',
	);
	foreach ( $ints as $key ) {
		$clean[ $key ] = isset( $input[ $key ] ) ? absint( $input[ $key ] ) : $defaults[ $key ];
	}

	$strings = array( 'slot_prefix', 'network_code' );
	foreach ( $strings as $key ) {
		$clean[ $key ] = isset( $input[ $key ] ) ? sanitize_text_field( $input[ $key ] ) : $defaults[ $key ];
	}

	$clean['desktop_spacing'] = max( 200, $clean['desktop_spacing'] );
	$clean['mobile_spacing']  = max( 200, $clean['mobile_spacing'] );

	return $clean;
}

/* ================================================================
 * 3. ADMIN TAB RENDERING
 * ================================================================ */

function pl_page_ad_render_tab() {
	$s = pl_page_ad_settings();
	$stats_url = rest_url( 'pl/v1/page-ad-stats' );
	$nonce     = wp_create_nonce( 'wp_rest' );
	?>
	<!-- Stats Dashboard -->
	<div id="pl-page-ad-stats" style="background:#f9f9f9;border:1px solid #ddd;padding:16px 20px;margin:10px 0 20px;border-radius:6px">
		<h3 style="margin-top:0">Page Ad Stats</h3>
		<div id="plPaStatsBody"><em>Loading...</em></div>
		<button type="button" id="pl-clear-page-ad-stats" class="button" style="color:#dc3545;border-color:#dc3545;margin-top:10px;">Clear All Page Ad Data</button>
	</div>
	<script>
	(function(){
		var url = <?php echo wp_json_encode( $stats_url ); ?>;
		var nonce = <?php echo wp_json_encode( $nonce ); ?>;
		var clearNonce = <?php echo wp_json_encode( wp_create_nonce( 'pl_clear_page_ad_stats' ) ); ?>;
		fetch(url + '?days=30', { headers: { 'X-WP-Nonce': nonce } })
			.then(function(r){ return r.json(); })
			.then(function(data){
				var body = document.getElementById('plPaStatsBody');
				if (!data || !data.today) { body.innerHTML = '<p>No data yet.</p>'; return; }

				var html = '<table class="widefat striped" style="max-width:800px"><thead><tr>'
					+ '<th>Period</th><th>Page Type</th><th>Impressions</th><th>Viewable</th><th>Clicks</th><th>Fill Rate</th>'
					+ '</tr></thead><tbody>';

				var periods = [
					{ key: 'today', label: 'Today' },
					{ key: 'last_7_days', label: 'Last 7 Days' },
					{ key: 'last_30_days', label: 'Last 30 Days' }
				];
				var types = ['homepage', 'category', 'page'];

				periods.forEach(function(p) {
					var pd = data[p.key];
					if (!pd) return;
					types.forEach(function(t) {
						var d = pd[t];
						if (!d) return;
						var total = (d.filled || 0) + (d.empty || 0);
						var fr = total > 0 ? Math.round((d.filled || 0) / total * 100) + '%' : '-';
						html += '<tr><td>' + p.label + '</td><td>' + t + '</td>'
							+ '<td>' + (d.impressions || 0) + '</td>'
							+ '<td>' + (d.viewable || 0) + '</td>'
							+ '<td>' + (d.clicks || 0) + '</td>'
							+ '<td>' + fr + '</td></tr>';
					});
				});
				html += '</tbody></table>';

				if (data.by_domain) {
					html += '<h4 style="margin-top:16px">By Domain</h4><table class="widefat striped" style="max-width:600px"><thead><tr>'
						+ '<th>Domain</th><th>Impressions</th><th>Viewable</th><th>Clicks</th></tr></thead><tbody>';
					for (var dom in data.by_domain) {
						var dd = data.by_domain[dom];
						html += '<tr><td>' + dom + '</td><td>' + (dd.impressions||0) + '</td><td>' + (dd.viewable||0) + '</td><td>' + (dd.clicks||0) + '</td></tr>';
					}
					html += '</tbody></table>';
				}

				body.innerHTML = html;
			})
			.catch(function(){ document.getElementById('plPaStatsBody').innerHTML = '<p style="color:#c00">Failed to load stats.</p>'; });

		document.getElementById('pl-clear-page-ad-stats').addEventListener('click', function() {
			if (!confirm('Are you sure? This will permanently delete ALL page ad analytics data across all sites.')) return;
			var btn = this;
			btn.disabled = true;
			btn.textContent = 'Clearing...';
			fetch(ajaxurl + '?action=pl_clear_page_ad_stats&_ajax_nonce=' + clearNonce, { method: 'POST' })
				.then(function(r){ return r.json(); })
				.then(function(d){
					if (d.success) {
						alert('Page ad data cleared.');
						location.reload();
					} else {
						alert('Failed to clear data: ' + (d.data && d.data.message || 'Unknown error'));
						btn.disabled = false;
						btn.textContent = 'Clear All Page Ad Data';
					}
				})
				.catch(function(){
					alert('Request failed.');
					btn.disabled = false;
					btn.textContent = 'Clear All Page Ad Data';
				});
		});
	})();
	</script>

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
					&nbsp;&mdash;&nbsp; Max inline slots: <input type="number" name="pl_page_ad_settings[homepage_max]" value="<?php echo esc_attr( $s['homepage_max'] ); ?>" min="0" max="10" style="width:60px">
					<p class="description">3 fixed positions (top leaderboard, middle rectangle, bottom leaderboard) + optional anchor + interstitial.</p>
				</td>
			</tr>
			<tr>
				<th>Category Archives</th>
				<td>
					<label><input type="checkbox" name="pl_page_ad_settings[category_enabled]" value="1" <?php checked( $s['category_enabled'] ); ?>> Enable ads on category pages</label>
					&nbsp;&mdash;&nbsp; Max inline slots: <input type="number" name="pl_page_ad_settings[category_max]" value="<?php echo esc_attr( $s['category_max'] ); ?>" min="0" max="20" style="width:60px">
					<br>Inject ad after every <input type="number" name="pl_page_ad_settings[category_every_n]" value="<?php echo esc_attr( $s['category_every_n'] ); ?>" min="2" max="10" style="width:60px"> posts
					<p class="description">First ad before post #1, then every N posts. All category ads use rectangle format (300x250 / 336x280).</p>
				</td>
			</tr>
			<tr>
				<th>Static Pages</th>
				<td>
					<label><input type="checkbox" name="pl_page_ad_settings[page_enabled]" value="1" <?php checked( $s['page_enabled'] ); ?>> Enable ads on static pages</label>
					&nbsp;&mdash;&nbsp; Max inline slots: <input type="number" name="pl_page_ad_settings[page_max]" value="<?php echo esc_attr( $s['page_max'] ); ?>" min="0" max="10" style="width:60px">
				</td>
			</tr>
		</table>

		<h3>Overlay Formats</h3>
		<table class="form-table">
			<tr>
				<th>Bottom Anchor</th>
				<td>
					<label><input type="checkbox" name="pl_page_ad_settings[fmt_anchor]" value="1" <?php checked( $s['fmt_anchor'] ); ?>> Show anchor ad (once per session)</label>
				</td>
			</tr>
			<tr>
				<th>Interstitial</th>
				<td>
					<label><input type="checkbox" name="pl_page_ad_settings[fmt_interstitial]" value="1" <?php checked( $s['fmt_interstitial'] ); ?>> Show interstitial after 3s delay (homepage + category only, once per session)</label>
				</td>
			</tr>
		</table>

		<h3>Spacing</h3>
		<table class="form-table">
			<tr>
				<th>Desktop (&ge;1025px)</th>
				<td>
					<input type="number" name="pl_page_ad_settings[desktop_spacing]" value="<?php echo esc_attr( $s['desktop_spacing'] ); ?>" min="200" max="1500" style="width:80px"> px between ads
					<p class="description">Category pages use 300px (desktop) / 250px (mobile) regardless of this setting.</p>
				</td>
			</tr>
			<tr>
				<th>Mobile (&lt;1025px)</th>
				<td>
					<input type="number" name="pl_page_ad_settings[mobile_spacing]" value="<?php echo esc_attr( $s['mobile_spacing'] ); ?>" min="200" max="1500" style="width:80px"> px between ads
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

function pl_page_ads_enqueue() {
	if ( is_single() ) {
		return;
	}

	$s = pl_page_ad_settings();

	if ( ! $s['enabled'] ) {
		return;
	}

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
		return;
	}

	$config = array(
		'dummy'            => (bool) $s['dummy_mode'],
		'pageType'         => $page_type,
		'maxSlots'         => $max_slots,
		'slotPrefix'       => $s['slot_prefix'],
		'networkCode'      => $s['network_code'],
		'fmtAnchor'        => (bool) $s['fmt_anchor'],
		'fmtInterstitial'  => (bool) $s['fmt_interstitial'],
		'desktopSpacing'   => (int) $s['desktop_spacing'],
		'mobileSpacing'    => (int) $s['mobile_spacing'],
		'eventEndpoint'    => rest_url( 'pl/v1/page-ad-event' ),
		'nonce'            => wp_create_nonce( 'wp_rest' ),
	);

	add_action( 'wp_footer', function() use ( $config ) {
		echo '<script>var plPageAds=' . wp_json_encode( $config ) . ';</script>' . "\n";
	}, 97 );

	add_action( 'wp_footer', function() {
		$file = PINLIGHTNING_DIR . '/assets/js/page-ads.js';
		$src  = PINLIGHTNING_URI . '/assets/js/page-ads.js?ver=' . filemtime( $file );
		echo '<script>window.addEventListener("load",function(){var s=document.createElement("script");s.src="' . esc_url( $src ) . '";document.body.appendChild(s)},{ once:true });</script>' . "\n";
	}, 100 );
}
add_action( 'wp_enqueue_scripts', 'pl_page_ads_enqueue', 20 );

/* ================================================================
 * 5. CATEGORY ARCHIVE AD ANCHORS (via the_post hook)
 *
 * Injects ad anchor: FIRST one before post #1 (top leaderboard),
 * then after every Nth post (default 3).
 * ================================================================ */

function pl_page_ad_category_anchors() {
	if ( ! is_category() || is_single() ) {
		return;
	}

	$s = pl_page_ad_settings();
	if ( ! $s['enabled'] || ! $s['category_enabled'] ) {
		return;
	}

	$every_n  = max( 2, (int) $s['category_every_n'] );
	$count    = 0;
	$injected = 0;

	// Top rectangle anchor before the first post.
	// NOTE: category.php uses a custom foreach loop, so this only fires if
	// a category page happens to use the standard WP loop (fallback safety).
	add_action( 'loop_start', function( $query ) use ( &$injected ) {
		if ( ! $query->is_main_query() || ! is_category() ) {
			return;
		}
		$injected++;
		echo '<div class="pl-page-ad-anchor" data-slot="cat-' . esc_attr( $injected ) . '" data-format="rectangle"></div>' . "\n";
	} );

	// After every Nth post.
	add_action( 'the_post', function( $post, $query ) use ( $every_n, &$count, &$injected ) {
		if ( ! $query->is_main_query() ) {
			return;
		}
		$count++;
		if ( $count % $every_n === 0 ) {
			$injected++;
			echo '<div class="pl-page-ad-anchor" data-slot="cat-' . esc_attr( $injected ) . '"></div>' . "\n";
		}
	}, 10, 2 );
}
add_action( 'template_redirect', 'pl_page_ad_category_anchors' );

/* ================================================================
 * 6. STATIC PAGE AD ANCHORS (via the_content filter)
 * ================================================================ */

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

	$parts = preg_split( '/(<\/p>)/i', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
	if ( ! $parts ) {
		return $content;
	}

	$p_count = 0;
	foreach ( $parts as $part ) {
		if ( strtolower( trim( $part ) ) === '</p>' ) {
			$p_count++;
		}
	}

	if ( $p_count < 3 ) {
		return $content;
	}

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
