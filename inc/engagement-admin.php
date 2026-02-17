<?php
/**
 * Engagement Email Leads — Admin page with stats, table, CSV export.
 *
 * @package PinLightning
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function pl_eb_email_leads_admin_menu() {
	add_menu_page(
		'Email Leads',
		'Email Leads',
		'manage_options',
		'eb-email-leads',
		'pl_eb_email_leads_page',
		'dashicons-email-alt',
		30
	);
}
add_action( 'admin_menu', 'pl_eb_email_leads_admin_menu' );

function pl_eb_email_leads_page() {
	global $wpdb;
	$table = $wpdb->prefix . 'eb_email_leads';

	// Handle CSV export.
	if ( isset( $_GET['export'] ) && $_GET['export'] === 'csv' && current_user_can( 'manage_options' ) ) {
		check_admin_referer( 'eb_export_csv' );
		$leads = $wpdb->get_results( "SELECT * FROM $table ORDER BY created_at DESC", ARRAY_A );
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=email-leads-' . date( 'Y-m-d' ) . '.csv' );
		$output = fopen( 'php://output', 'w' );
		if ( ! empty( $leads ) ) {
			fputcsv( $output, array_keys( $leads[0] ) );
			foreach ( $leads as $row ) {
				fputcsv( $output, $row );
			}
		}
		fclose( $output );
		exit;
	}

	// Handle delete.
	if ( isset( $_POST['delete_id'] ) && current_user_can( 'manage_options' ) ) {
		check_admin_referer( 'eb_delete_lead' );
		$wpdb->delete( $table, array( 'id' => intval( $_POST['delete_id'] ) ) );
		echo '<div class="notice notice-success"><p>Lead deleted.</p></div>';
	}

	// Get stats.
	$total       = $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
	$today       = $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM $table WHERE DATE(created_at) = %s",
		current_time( 'Y-m-d' )
	) );
	$this_week   = $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM $table WHERE created_at >= %s",
		date( 'Y-m-d', strtotime( '-7 days' ) )
	) );
	$unique_emails = $wpdb->get_var( "SELECT COUNT(DISTINCT email) FROM $table" );

	// Get leads with pagination.
	$per_page    = 30;
	$page        = max( 1, intval( $_GET['paged'] ?? 1 ) );
	$offset      = ( $page - 1 ) * $per_page;
	$leads       = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM $table ORDER BY created_at DESC LIMIT %d OFFSET %d",
		$per_page, $offset
	) );
	$total_pages = ceil( $total / $per_page );

	// Get top posts by email captures.
	$top_posts = $wpdb->get_results(
		"SELECT post_id, post_title, category, COUNT(*) as lead_count
		 FROM $table
		 GROUP BY post_id
		 ORDER BY lead_count DESC
		 LIMIT 5"
	);

	// Get source breakdown.
	$sources = $wpdb->get_results(
		"SELECT source, COUNT(*) as count FROM $table GROUP BY source ORDER BY count DESC"
	);

	?>
	<div class="wrap">
		<h1>Email Leads <a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=eb-email-leads&export=csv' ), 'eb_export_csv' ); ?>" class="page-title-action">Export CSV</a></h1>

		<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:20px 0;">
			<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;text-align:center;">
				<div style="font-size:2rem;font-weight:700;color:#d81b60;"><?php echo number_format( $total ); ?></div>
				<div style="font-size:.85rem;color:#666;">Total Leads</div>
			</div>
			<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;text-align:center;">
				<div style="font-size:2rem;font-weight:700;color:#4caf50;"><?php echo number_format( $today ); ?></div>
				<div style="font-size:.85rem;color:#666;">Today</div>
			</div>
			<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;text-align:center;">
				<div style="font-size:2rem;font-weight:700;color:#2196f3;"><?php echo number_format( $this_week ); ?></div>
				<div style="font-size:.85rem;color:#666;">This Week</div>
			</div>
			<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;text-align:center;">
				<div style="font-size:2rem;font-weight:700;color:#ff9800;"><?php echo number_format( $unique_emails ); ?></div>
				<div style="font-size:.85rem;color:#666;">Unique Emails</div>
			</div>
		</div>

		<?php if ( $top_posts ) : ?>
		<div style="display:grid;grid-template-columns:2fr 1fr;gap:16px;margin-bottom:20px;">
			<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;">
				<h3 style="margin:0 0 10px;font-size:.95rem;">Top Posts by Captures</h3>
				<?php foreach ( $top_posts as $tp ) : ?>
					<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f0f0f0;">
						<span style="font-size:.85rem;"><?php echo esc_html( $tp->post_title ?: 'Post #' . $tp->post_id ); ?></span>
						<strong style="color:#d81b60;"><?php echo $tp->lead_count; ?></strong>
					</div>
				<?php endforeach; ?>
			</div>
			<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;">
				<h3 style="margin:0 0 10px;font-size:.95rem;">Capture Source</h3>
				<?php foreach ( $sources as $s ) : ?>
					<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f0f0f0;">
						<span style="font-size:.85rem;"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $s->source ) ) ); ?></span>
						<strong><?php echo $s->count; ?></strong>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php endif; ?>

		<table class="widefat striped">
			<thead>
				<tr>
					<th>Email</th>
					<th>Post</th>
					<th>Source</th>
					<th>Category</th>
					<th>Favorites</th>
					<th>Date</th>
					<th>Actions</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $leads ) ) : ?>
					<tr><td colspan="7" style="text-align:center;padding:2rem;color:#999;">No email leads captured yet. They will appear here as visitors submit their email in engagement blocks.</td></tr>
				<?php else : ?>
					<?php foreach ( $leads as $lead ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $lead->email ); ?></strong></td>
						<td>
							<?php if ( $lead->post_id ) : ?>
								<a href="<?php echo get_permalink( $lead->post_id ); ?>" target="_blank">
									<?php echo esc_html( $lead->post_title ?: 'Post #' . $lead->post_id ); ?>
								</a>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
						<td><span style="background:#f0f0f0;padding:2px 8px;border-radius:4px;font-size:.8rem;"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $lead->source ) ) ); ?></span></td>
						<td><?php echo esc_html( $lead->category ); ?></td>
						<td style="font-size:.8rem;"><?php echo esc_html( $lead->favorites ); ?></td>
						<td><?php echo date( 'M j, Y g:ia', strtotime( $lead->created_at ) ); ?></td>
						<td>
							<form method="post" style="display:inline;" onsubmit="return confirm('Delete this lead?');">
								<?php wp_nonce_field( 'eb_delete_lead' ); ?>
								<input type="hidden" name="delete_id" value="<?php echo $lead->id; ?>">
								<button type="submit" class="button-link" style="color:#a00;">Delete</button>
							</form>
						</td>
					</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<?php if ( $total_pages > 1 ) : ?>
		<div class="tablenav">
			<div class="tablenav-pages">
				<?php echo paginate_links( array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => $page,
					'total'   => $total_pages,
				) ); ?>
			</div>
		</div>
		<?php endif; ?>
	</div>
	<?php
}
