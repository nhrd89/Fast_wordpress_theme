<?php
/**
 * PinLightning Ad Recommendations Engine
 *
 * Analyzes aggregated session stats and event-level data to generate
 * actionable recommendations for improving ad revenue and performance.
 * Renders as a section on the analytics dashboard + its own admin page.
 *
 * @package PinLightning
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ================================================================
 * 1. ADMIN MENU
 * ================================================================ */

function pl_ad_recommendations_menu() {
	add_submenu_page(
		'pl-ad-engine',
		'Recommendations',
		'Recommendations',
		'manage_options',
		'pl-ad-recommendations',
		'pl_ad_render_recommendations_page'
	);
}
add_action( 'admin_menu', 'pl_ad_recommendations_menu' );

/* ================================================================
 * 2. RECOMMENDATION RULES
 * ================================================================ */

/**
 * Generate all recommendations based on available data.
 *
 * Each recommendation has:
 *   - type:     'success' | 'warning' | 'danger' | 'info'
 *   - category: grouping label
 *   - title:    short headline
 *   - detail:   actionable description
 *   - metric:   the data point that triggered it
 *   - priority: 1 (critical) to 5 (nice-to-have)
 *
 * @param array $session_stats Combined session-level stats (from aggregator).
 * @param array $event_data   Event-level data (from events query, or empty).
 * @return array
 */
function pl_ad_generate_recommendations( $session_stats, $event_data ) {
	$recs = array();
	$s    = $session_stats;
	$e    = $event_data;

	$sessions = $s['total_sessions'] ?? 0;

	// ---- FILL RATE ----
	$fill_rate = ( $s['total_ad_requests'] ?? 0 ) > 0
		? ( $s['total_ad_fills'] / $s['total_ad_requests'] ) * 100 : 0;

	if ( $fill_rate > 0 && $fill_rate < 50 ) {
		$recs[] = array(
			'type'     => 'danger',
			'category' => 'Fill Rate',
			'title'    => 'Critical: Fill rate below 50%',
			'detail'   => 'Only ' . round( $fill_rate, 1 ) . '% of ad requests are filling. Consider adding backup demand sources, reducing the number of ad zones on pages with low fill, or contacting your ad provider about demand issues.',
			'metric'   => round( $fill_rate, 1 ) . '% fill rate',
			'priority' => 1,
		);
	} elseif ( $fill_rate >= 50 && $fill_rate < 75 ) {
		$recs[] = array(
			'type'     => 'warning',
			'category' => 'Fill Rate',
			'title'    => 'Fill rate needs improvement',
			'detail'   => 'At ' . round( $fill_rate, 1 ) . '%, there is room to improve. Try enabling passback/backfill networks, or review if certain slot types consistently underperform.',
			'metric'   => round( $fill_rate, 1 ) . '% fill rate',
			'priority' => 2,
		);
	} elseif ( $fill_rate >= 85 ) {
		$recs[] = array(
			'type'     => 'success',
			'category' => 'Fill Rate',
			'title'    => 'Excellent fill rate',
			'detail'   => 'Fill rate at ' . round( $fill_rate, 1 ) . '% is above industry benchmarks. Your demand partners are well-configured.',
			'metric'   => round( $fill_rate, 1 ) . '% fill rate',
			'priority' => 5,
		);
	}

	// ---- VIEWABILITY ----
	$viewability = ( $s['total_zones_activated'] ?? 0 ) > 0
		? ( ( $s['total_viewable_ads'] ?? 0 ) / $s['total_zones_activated'] ) * 100 : 0;

	if ( $viewability > 0 && $viewability < 50 ) {
		$recs[] = array(
			'type'     => 'danger',
			'category' => 'Viewability',
			'title'    => 'Viewability below 50%',
			'detail'   => 'At ' . round( $viewability, 1 ) . '%, most ads are not being seen. Review ad placement positions — ads placed too far below the fold or in rarely-scrolled areas hurt CPMs. Consider lazy-loading ads closer to the viewport.',
			'metric'   => round( $viewability, 1 ) . '% viewability',
			'priority' => 1,
		);
	} elseif ( $viewability >= 50 && $viewability < 70 ) {
		$recs[] = array(
			'type'     => 'warning',
			'category' => 'Viewability',
			'title'    => 'Viewability below industry target',
			'detail'   => 'Industry target is 70%+. At ' . round( $viewability, 1 ) . '%, consider moving low-viewability zones higher in content, or reducing ads in positions that users scroll past quickly.',
			'metric'   => round( $viewability, 1 ) . '% viewability',
			'priority' => 2,
		);
	} elseif ( $viewability >= 80 ) {
		$recs[] = array(
			'type'     => 'success',
			'category' => 'Viewability',
			'title'    => 'Premium viewability achieved',
			'detail'   => 'At ' . round( $viewability, 1 ) . '%, your viewability qualifies for premium CPM rates. This is a strong selling point for direct deals.',
			'metric'   => round( $viewability, 1 ) . '% viewability',
			'priority' => 5,
		);
	}

	// ---- ENGAGEMENT (TIME ON PAGE) ----
	$avg_time = $sessions > 0 ? ( $s['total_time_s'] ?? 0 ) / $sessions : 0;

	if ( $avg_time > 0 && $avg_time < 15 ) {
		$recs[] = array(
			'type'     => 'warning',
			'category' => 'Engagement',
			'title'    => 'Low average time on page',
			'detail'   => 'Average session is only ' . round( $avg_time ) . 's. Short sessions mean fewer ad impressions per visit. Consider improving content quality, adding engagement hooks (polls, quizzes), or optimizing page load speed.',
			'metric'   => round( $avg_time ) . 's avg time',
			'priority' => 2,
		);
	} elseif ( $avg_time >= 60 ) {
		$recs[] = array(
			'type'     => 'success',
			'category' => 'Engagement',
			'title'    => 'Strong engagement depth',
			'detail'   => 'Average ' . round( $avg_time ) . 's per session indicates deep reader engagement. This is ideal for maximizing in-content ad viewability and refresh revenue.',
			'metric'   => round( $avg_time ) . 's avg time',
			'priority' => 5,
		);
	}

	// ---- SCROLL DEPTH ----
	$avg_scroll = $sessions > 0 ? ( $s['total_scroll_pct'] ?? 0 ) / $sessions : 0;

	if ( $avg_scroll > 0 && $avg_scroll < 40 ) {
		$recs[] = array(
			'type'     => 'warning',
			'category' => 'Engagement',
			'title'    => 'Low scroll depth',
			'detail'   => 'Average scroll depth is ' . round( $avg_scroll ) . '%. Ads placed in the lower half of content may rarely be seen. Consider front-loading high-value ad zones in the first 40% of content.',
			'metric'   => round( $avg_scroll ) . '% avg scroll',
			'priority' => 3,
		);
	}

	// ---- OVERLAY PERFORMANCE ----
	// Anchor ad.
	$anchor_fill = ( $s['anchor_fired'] ?? 0 ) > 0
		? ( ( $s['anchor_filled'] ?? 0 ) / $s['anchor_fired'] ) * 100 : 0;
	if ( ( $s['anchor_fired'] ?? 0 ) > 10 && $anchor_fill < 50 ) {
		$recs[] = array(
			'type'     => 'warning',
			'category' => 'Overlays',
			'title'    => 'Anchor ad fill rate is low',
			'detail'   => 'Anchor ads fill at only ' . round( $anchor_fill ) . '%. The sticky anchor format typically has high demand — check if the ad size or placement is causing issues.',
			'metric'   => round( $anchor_fill ) . '% anchor fill',
			'priority' => 3,
		);
	}

	// Interstitial.
	$inter_fire = $s['interstitial_fired'] ?? 0;
	if ( $sessions > 50 && $inter_fire === 0 ) {
		$recs[] = array(
			'type'     => 'info',
			'category' => 'Overlays',
			'title'    => 'Interstitial ads not firing',
			'detail'   => 'Interstitials have the highest eCPM ($3-8) but none have fired. Verify interstitial trigger conditions (scroll depth, time on page) are achievable by your audience.',
			'metric'   => '0 interstitials in ' . $sessions . ' sessions',
			'priority' => 2,
		);
	} elseif ( $inter_fire > 0 ) {
		$inter_view = ( $s['interstitial_viewable'] ?? 0 );
		$inter_vr   = round( ( $inter_view / $inter_fire ) * 100 );
		if ( $inter_vr >= 80 ) {
			$recs[] = array(
				'type'     => 'success',
				'category' => 'Overlays',
				'title'    => 'Interstitial viewability is excellent',
				'detail'   => $inter_vr . '% viewability on interstitials. This format is your highest eCPM earner.',
				'metric'   => $inter_vr . '% interstitial viewability',
				'priority' => 5,
			);
		}
	}

	// ---- REFRESH ANALYSIS ----
	if ( ! empty( $e ) ) {
		$e_ref  = $e['refreshes'] ?? 0;
		$e_skip = $e['refresh_skips'] ?? 0;
		$skip_rate = ( $e_ref + $e_skip ) > 0
			? ( $e_skip / ( $e_ref + $e_skip ) ) * 100 : 0;

		if ( $skip_rate > 50 && ( $e_ref + $e_skip ) > 20 ) {
			$recs[] = array(
				'type'     => 'warning',
				'category' => 'Refresh',
				'title'    => 'High refresh skip rate',
				'detail'   => round( $skip_rate, 1 ) . '% of refresh attempts are skipped (tab hidden or ad out of viewport). Consider increasing the viewport check margin or the refresh interval to ensure ads are visible when refreshed.',
				'metric'   => round( $skip_rate, 1 ) . '% skip rate',
				'priority' => 3,
			);
		} elseif ( $e_ref > 100 && $skip_rate < 20 ) {
			$recs[] = array(
				'type'     => 'success',
				'category' => 'Refresh',
				'title'    => 'Refresh efficiency is high',
				'detail'   => 'Only ' . round( $skip_rate, 1 ) . '% skip rate with ' . number_format( $e_ref ) . ' successful refreshes. Refresh is generating incremental revenue effectively.',
				'metric'   => number_format( $e_ref ) . ' refreshes at ' . round( $skip_rate, 1 ) . '% skip rate',
				'priority' => 5,
			);
		}

		// ---- EVENT-LEVEL FILL BY SLOT TYPE ----
		if ( ! empty( $e['by_slot'] ) ) {
			foreach ( $e['by_slot'] as $slot_row ) {
				$s_imp  = (int) ( $slot_row['impressions'] ?? 0 );
				$s_emp  = (int) ( $slot_row['empties'] ?? 0 );
				$s_fill = ( $s_imp + $s_emp ) > 0 ? ( $s_imp / ( $s_imp + $s_emp ) ) * 100 : 0;

				if ( ( $s_imp + $s_emp ) > 20 && $s_fill < 40 ) {
					$recs[] = array(
						'type'     => 'danger',
						'category' => 'Fill Rate',
						'title'    => ucfirst( $slot_row['slot_type'] ) . ' slots have very low fill',
						'detail'   => 'The "' . $slot_row['slot_type'] . '" slot type fills at only ' . round( $s_fill, 1 ) . '%. Consider reducing the number of these slots, adding passback demand, or switching to a different ad size.',
						'metric'   => round( $s_fill, 1 ) . '% fill for ' . $slot_row['slot_type'],
						'priority' => 2,
					);
				}
			}
		}

		// ---- DEVICE IMBALANCE ----
		if ( ! empty( $e['by_device'] ) && count( $e['by_device'] ) >= 2 ) {
			$device_vr = array();
			foreach ( $e['by_device'] as $dev_row ) {
				$d_imp = (int) ( $dev_row['impressions'] ?? 0 );
				$d_vw  = (int) ( $dev_row['viewables'] ?? 0 );
				if ( $d_imp > 20 ) {
					$device_vr[ $dev_row['device'] ] = round( ( $d_vw / $d_imp ) * 100, 1 );
				}
			}
			if ( isset( $device_vr['mobile'] ) && isset( $device_vr['desktop'] ) ) {
				$gap = $device_vr['desktop'] - $device_vr['mobile'];
				if ( $gap > 20 ) {
					$recs[] = array(
						'type'     => 'warning',
						'category' => 'Device',
						'title'    => 'Mobile viewability lags desktop by ' . round( $gap ) . '%',
						'detail'   => 'Mobile: ' . $device_vr['mobile'] . '% vs Desktop: ' . $device_vr['desktop'] . '%. Mobile users scroll faster and see fewer ads. Consider reducing ad density on mobile or using sticky formats.',
						'metric'   => round( $gap ) . '% viewability gap',
						'priority' => 3,
					);
				}
			}
		}
	}

	// ---- ZONE-LEVEL ANALYSIS ----
	if ( ! empty( $s['by_zone'] ) ) {
		$zero_view_zones = array();
		$low_fill_zones  = array();

		foreach ( $s['by_zone'] as $zid => $z ) {
			$activations = $z['activations'] ?? 0;
			if ( $activations < 5 ) {
				continue;
			}
			$combined = ( $z['filled'] ?? 0 ) + ( $z['passback_filled'] ?? 0 );
			$z_fill   = $activations > 0 ? ( $combined / $activations ) * 100 : 0;
			$z_view   = $combined > 0 ? ( ( $z['viewable'] ?? 0 ) / $combined ) * 100 : 0;

			if ( $z_view < 20 && $combined > 0 ) {
				$zero_view_zones[] = $zid . ' (' . round( $z_view ) . '%)';
			}
			if ( $z_fill < 30 ) {
				$low_fill_zones[] = $zid . ' (' . round( $z_fill ) . '%)';
			}
		}

		if ( ! empty( $zero_view_zones ) ) {
			$recs[] = array(
				'type'     => 'danger',
				'category' => 'Zones',
				'title'    => count( $zero_view_zones ) . ' zone(s) with near-zero viewability',
				'detail'   => 'These zones are filling but almost never seen: ' . implode( ', ', array_slice( $zero_view_zones, 0, 5 ) ) . '. They waste ad requests and hurt overall viewability metrics. Consider removing or repositioning them.',
				'metric'   => count( $zero_view_zones ) . ' problem zones',
				'priority' => 1,
			);
		}

		if ( ! empty( $low_fill_zones ) ) {
			$recs[] = array(
				'type'     => 'warning',
				'category' => 'Zones',
				'title'    => count( $low_fill_zones ) . ' zone(s) with fill below 30%',
				'detail'   => 'These zones rarely fill: ' . implode( ', ', array_slice( $low_fill_zones, 0, 5 ) ) . '. They may be requesting unsupported sizes or have demand issues.',
				'metric'   => count( $low_fill_zones ) . ' low-fill zones',
				'priority' => 2,
			);
		}
	}

	// ---- PAUSE BANNER ----
	$pause_shown = $s['total_pause_banners_shown'] ?? 0;
	$pause_cont  = $s['total_pause_banners_continued'] ?? 0;
	if ( $pause_shown > 10 ) {
		$cont_rate = round( ( $pause_cont / $pause_shown ) * 100 );
		if ( $cont_rate < 30 ) {
			$recs[] = array(
				'type'     => 'info',
				'category' => 'Pause Banner',
				'title'    => 'Low pause banner continue rate',
				'detail'   => 'Only ' . $cont_rate . '% of users continue reading after a pause banner. This may indicate users are leaving. Consider reducing pause banner frequency or improving the continue CTA.',
				'metric'   => $cont_rate . '% continue rate',
				'priority' => 3,
			);
		}
	}

	// ---- ADS PER SESSION ----
	$avg_ads = $sessions > 0 ? ( $s['total_zones_activated'] ?? 0 ) / $sessions : 0;
	if ( $avg_ads > 0 && $avg_ads < 2 ) {
		$recs[] = array(
			'type'     => 'info',
			'category' => 'Density',
			'title'    => 'Low ads per session',
			'detail'   => 'Average ' . round( $avg_ads, 1 ) . ' ads/session. If content is long enough, you may be leaving revenue on the table. Consider adjusting injection spacing or Layer 2 density settings.',
			'metric'   => round( $avg_ads, 1 ) . ' ads/session',
			'priority' => 3,
		);
	} elseif ( $avg_ads > 8 ) {
		$recs[] = array(
			'type'     => 'warning',
			'category' => 'Density',
			'title'    => 'High ad density',
			'detail'   => 'Average ' . round( $avg_ads, 1 ) . ' ads/session. High density can hurt viewability and user experience. If viewability is below 60%, consider reducing ad count to improve quality.',
			'metric'   => round( $avg_ads, 1 ) . ' ads/session',
			'priority' => 3,
		);
	}

	// Sort by priority (critical first).
	usort( $recs, function( $a, $b ) {
		return $a['priority'] - $b['priority'];
	} );

	return $recs;
}

/* ================================================================
 * 3. RENDER RECOMMENDATIONS
 * ================================================================ */

/**
 * Render the recommendations section (used both standalone and embedded).
 *
 * @param array $recs Recommendations array from pl_ad_generate_recommendations().
 */
function pl_ad_render_recommendations( $recs ) {
	if ( empty( $recs ) ) {
		echo '<div class="pl-section"><h2>Recommendations</h2>';
		echo '<p style="color:#888;">Not enough data to generate recommendations yet. Check back after collecting more sessions.</p></div>';
		return;
	}

	$type_icons = array(
		'danger'  => '<span style="color:#d63638;font-size:18px;">&#9888;</span>',
		'warning' => '<span style="color:#dba617;font-size:18px;">&#9888;</span>',
		'success' => '<span style="color:#00a32a;font-size:18px;">&#10003;</span>',
		'info'    => '<span style="color:#2271b1;font-size:18px;">&#8505;</span>',
	);
	$type_bg = array(
		'danger'  => '#fef0f0',
		'warning' => '#fef8ee',
		'success' => '#edfcf2',
		'info'    => '#f0f6fc',
	);
	$type_border = array(
		'danger'  => '#d63638',
		'warning' => '#dba617',
		'success' => '#00a32a',
		'info'    => '#2271b1',
	);

	$counts = array( 'danger' => 0, 'warning' => 0, 'success' => 0, 'info' => 0 );
	foreach ( $recs as $r ) {
		$counts[ $r['type'] ]++;
	}

	?>
	<div class="pl-section">
		<h2>Recommendations (<?php echo count( $recs ); ?>)</h2>
		<div style="display:flex;gap:12px;margin-bottom:16px;font-size:13px;">
			<?php if ( $counts['danger'] ) : ?>
				<span style="color:#d63638;font-weight:600;">&#9888; <?php echo $counts['danger']; ?> critical</span>
			<?php endif; ?>
			<?php if ( $counts['warning'] ) : ?>
				<span style="color:#dba617;font-weight:600;">&#9888; <?php echo $counts['warning']; ?> warnings</span>
			<?php endif; ?>
			<?php if ( $counts['success'] ) : ?>
				<span style="color:#00a32a;font-weight:600;">&#10003; <?php echo $counts['success']; ?> positive</span>
			<?php endif; ?>
			<?php if ( $counts['info'] ) : ?>
				<span style="color:#2271b1;font-weight:600;">&#8505; <?php echo $counts['info']; ?> info</span>
			<?php endif; ?>
		</div>

		<?php foreach ( $recs as $r ) :
			$bg     = $type_bg[ $r['type'] ] ?? '#f9f9f9';
			$border = $type_border[ $r['type'] ] ?? '#ddd';
			$icon   = $type_icons[ $r['type'] ] ?? '';
		?>
		<div style="background:<?php echo $bg; ?>;border-left:4px solid <?php echo $border; ?>;padding:12px 16px;margin-bottom:10px;border-radius:0 4px 4px 0;">
			<div style="display:flex;align-items:flex-start;gap:8px;">
				<div style="flex-shrink:0;margin-top:2px;"><?php echo $icon; ?></div>
				<div style="flex:1;">
					<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
						<strong style="font-size:14px;"><?php echo esc_html( $r['title'] ); ?></strong>
						<span style="font-size:11px;color:#888;background:#fff;padding:1px 6px;border-radius:3px;border:1px solid #ddd;"><?php echo esc_html( $r['category'] ); ?></span>
					</div>
					<p style="margin:0 0 4px;font-size:13px;color:#1d2327;"><?php echo esc_html( $r['detail'] ); ?></p>
					<span style="font-size:11px;color:#888;">Metric: <?php echo esc_html( $r['metric'] ); ?></span>
				</div>
			</div>
		</div>
		<?php endforeach; ?>
	</div>
	<?php
}

/* ================================================================
 * 4. STANDALONE PAGE
 * ================================================================ */

function pl_ad_render_recommendations_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$range = sanitize_key( $_GET['range'] ?? '7days' );
	$today = current_time( 'Y-m-d' );

	switch ( $range ) {
		case 'today':
			$start = $today;
			$end   = $today;
			break;
		case 'yesterday':
			$start = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
			$end   = $start;
			break;
		case '30days':
			$start = gmdate( 'Y-m-d', strtotime( '-29 days' ) );
			$end   = $today;
			break;
		default: // 7days
			$start = gmdate( 'Y-m-d', strtotime( '-6 days' ) );
			$end   = $today;
	}

	// Session-level data.
	$session_data = function_exists( 'pl_ad_get_stats_range' )
		? pl_ad_get_stats_range( $start, $end ) : array( 'combined' => array(), 'daily' => array() );
	$session_stats = $session_data['combined'];

	// Event-level data.
	$event_data = array();
	if ( function_exists( 'pl_ad_events_query_range' ) ) {
		global $wpdb;
		$te = $wpdb->prefix . 'pl_ad_events';
		$table_exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
			DB_NAME, $te
		) );
		if ( $table_exists ) {
			$event_data = pl_ad_events_query_range( $start, $end );
		}
	}

	$recs = pl_ad_generate_recommendations( $session_stats, $event_data );

	?>
	<div class="wrap" id="pl-analytics">
		<h1>Ad Recommendations</h1>
		<p style="color:#646970;font-size:13px;">Auto-generated insights based on your ad performance data. Analyzed period: <strong><?php echo esc_html( $start ); ?></strong> to <strong><?php echo esc_html( $end ); ?></strong>.</p>

		<div style="margin:15px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
			<?php
			$ranges = array(
				'today'     => 'Today',
				'yesterday' => 'Yesterday',
				'7days'     => 'Last 7 Days',
				'30days'    => 'Last 30 Days',
			);
			foreach ( $ranges as $k => $label ) {
				$active = ( $range === $k ) ? 'button-primary' : '';
				$url    = admin_url( 'admin.php?page=pl-ad-recommendations&range=' . $k );
				echo '<a href="' . esc_url( $url ) . '" class="button ' . $active . '">' . esc_html( $label ) . '</a>';
			}
			?>
			<span style="border-left:1px solid #c3c4c7;height:24px;margin:0 4px;"></span>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=pl-ad-analytics-dashboard&range=' . $range ) ); ?>" class="button">Analytics Dashboard</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=pl-ad-live-sessions' ) ); ?>" class="button">Live Sessions</a>
		</div>

		<?php pl_ad_render_recommendations( $recs ); ?>

		<!-- Score summary -->
		<?php
		$total_recs = count( $recs );
		$good_pct   = $total_recs > 0 ? round( ( $counts['success'] ?? 0 ) / $total_recs * 100 ) : 0;
		$bad_pct    = $total_recs > 0 ? round( ( ( $counts['danger'] ?? 0 ) + ( $counts['warning'] ?? 0 ) ) / $total_recs * 100 ) : 0;
		$counts = array( 'danger' => 0, 'warning' => 0, 'success' => 0, 'info' => 0 );
		foreach ( $recs as $r ) { $counts[ $r['type'] ]++; }
		$health_score = 100;
		$health_score -= $counts['danger'] * 15;
		$health_score -= $counts['warning'] * 5;
		$health_score += $counts['success'] * 3;
		$health_score = max( 0, min( 100, $health_score ) );
		$health_color = $health_score >= 75 ? '#00a32a' : ( $health_score >= 50 ? '#dba617' : '#d63638' );
		?>
		<div class="pl-section" style="text-align:center;padding:30px;">
			<h2 style="border:none;">Ad Health Score</h2>
			<div style="font-size:64px;font-weight:700;color:<?php echo $health_color; ?>;margin:10px 0;">
				<?php echo $health_score; ?>
			</div>
			<p style="color:#888;font-size:13px;">
				Based on <?php echo $total_recs; ?> checks:
				<?php echo $counts['danger']; ?> critical,
				<?php echo $counts['warning']; ?> warnings,
				<?php echo $counts['success']; ?> positive,
				<?php echo $counts['info']; ?> info.
			</p>
		</div>
	</div>

	<style>
	#pl-analytics { max-width: 1200px; }
	.pl-section { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 20px; margin: 20px 0; }
	.pl-section h2 { margin: 0 0 15px; font-size: 16px; border-bottom: 1px solid #eee; padding-bottom: 10px; }
	</style>
	<?php
}

/* ================================================================
 * 5. EMBED IN ANALYTICS DASHBOARD
 * ================================================================ */

/**
 * Render recommendations section for embedding in the analytics dashboard.
 *
 * @param array $session_stats Combined session stats.
 * @param string $start        Start date.
 * @param string $end          End date.
 */
function pl_ad_recommendations_for_dashboard( $session_stats, $start, $end ) {
	$event_data = array();
	if ( function_exists( 'pl_ad_events_query_range' ) ) {
		global $wpdb;
		$te = $wpdb->prefix . 'pl_ad_events';
		$table_exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
			DB_NAME, $te
		) );
		if ( $table_exists ) {
			$event_data = pl_ad_events_query_range( $start, $end );
		}
	}

	$recs = pl_ad_generate_recommendations( $session_stats, $event_data );
	pl_ad_render_recommendations( $recs );
}
