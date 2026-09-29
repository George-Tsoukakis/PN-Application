<?php
/**
 * Module: Apply Submissions — Admin view & CSV export
 *
 * Provides:
 *   - Table-name helper: jbli_apply_submissions_table()
 *   - AJAX handler for admin submissions modal: jbli_apply_submissions
 *   - CSV export action: jbli_apply_export_csv
 *
 * Loaded by modules/apply/jbli-apply.php.
 *
 * @package JobListings
 * @since   9.9.41
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'jbli_apply_submissions_table' ) )
{
	function jbli_apply_submissions_table(): string {

		global $wpdb;
		return $wpdb->prefix . 'jbli_apply_submissions';

	}
}

/**
 * Save a submission record to the custom DB table.
 *
 * @param int    $jbli_post_id Listing post ID.
 * @param string $jbli_name    Applicant full name.
 * @param string $jbli_phone   Applicant phone.
 * @param string $jbli_email   Applicant email.
 * @return bool True on success, false on failure.
 */
function jbli_apply_save_submission( int $jbli_post_id, string $jbli_name, string $jbli_phone, string $jbli_email ): bool {

	global $wpdb;

	$jbli_table = jbli_apply_submissions_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$jbli_result = $wpdb->insert(
		$jbli_table,
		array(
			'jbli_post_id'         => $jbli_post_id,
			'jbli_applicant_name'  => $jbli_name,
			'jbli_applicant_phone' => $jbli_phone,
			'jbli_applicant_email' => $jbli_email,
			'jbli_submitted_at'    => current_time( 'mysql' ),
		),
		array( '%d', '%s', '%s', '%s', '%s' )
	);

	return false !== $jbli_result;

}

function jbli_admin_ajax_get_submissions(): void {

	if ( ! current_user_can( 'manage_options' ) )
	{
		wp_send_json_error( array( 'jbli_message' => __( 'Unauthorized', 'job-listings' ) ), 403 );
	}

	$jbli_post_id = absint( $_POST['post_id'] ?? 0 );

	if ( ! $jbli_post_id )
	{
		wp_send_json_error( array( 'jbli_message' => __( 'Μη έγκυρο αίτημα.', 'job-listings' ) ) );
	}

	global $wpdb;
	$jbli_table = jbli_apply_submissions_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$jbli_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, jbli_applicant_name, jbli_applicant_phone, jbli_applicant_email, jbli_submitted_at
			   FROM {$jbli_table}
			  WHERE jbli_post_id = %d
			  ORDER BY jbli_submitted_at DESC",
			$jbli_post_id
		)
	);

	if ( empty( $jbli_rows ) )
	{
		wp_send_json_success( array(
			'html'  => '<p class="jbli_sub_modal_text_muted">' . esc_html__( 'Δεν υπάρχουν υποβολές για αυτή την αγγελία.', 'job-listings' ) . '</p>',
			'count' => 0,
		) );
	}

	$jbli_post_title = get_the_title( $jbli_post_id );

	ob_start();
	?>

	<p class="jbli_sub_modal_info_text">
		<?php

			printf(
				/* translators: 1: number of submissions, 2: job listing title */
				esc_html__( '%1$s υποβολές για «%2$s»', 'job-listings' ),
				'<strong>' . esc_html( (string) count( $jbli_rows ) ) . '</strong>',
				esc_html( $jbli_post_title )
			);

		?>
	</p>
	<table class="wp-list-table widefat fixed striped jbli_sub_modal_table">
		<thead>
			<tr>
				<th class="jbli_sub_modal_th_cb"><input type="checkbox" id="jbli_sub_select_all" checked></th>
				<th class="jbli_sub_modal_th_name"><?php esc_html_e( 'Ονοματεπώνυμο', 'job-listings' ); ?></th>
				<th class="jbli_sub_modal_th_phone"><?php esc_html_e( 'Κινητό', 'job-listings' ); ?></th>
				<th class="jbli_sub_modal_th_email"><?php esc_html_e( 'Email', 'job-listings' ); ?></th>
				<th class="jbli_sub_modal_th_date"><?php esc_html_e( 'Ημ/νία', 'job-listings' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php

			foreach ( $jbli_rows as $jbli_row ) {

				?>
					<tr>
						<td><input type="checkbox" class="jbli_sub_row_cb" value="<?php echo esc_attr( $jbli_row->id ); ?>" checked></td>
						<td><?php echo esc_html( $jbli_row->jbli_applicant_name ); ?></td>
						<td><?php echo esc_html( $jbli_row->jbli_applicant_phone ); ?></td>
						<td><?php echo esc_html( $jbli_row->jbli_applicant_email ); ?></td>
						<td><?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( $jbli_row->jbli_submitted_at ) ) ); ?></td>
					</tr>
				<?php
			}

		?>
		</tbody>
	</table>
	<?php
	$jbli_html = (string) ob_get_clean();

	wp_send_json_success( array( 'html'  => $jbli_html, 'count' => count( $jbli_rows ), ) );

}

add_action( 'wp_ajax_jbli_apply_submissions', 'jbli_admin_ajax_get_submissions' );

function jbli_apply_export_csv(): void {

	if ( ! isset( $_GET['action'] ) || 'jbli_export_submissions' !== sanitize_key( wp_unslash( $_GET['action'] ) ) )
	{
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Unauthorized', 'job-listings' ), 403 ); }

	$jbli_post_id = absint( $_GET['post_id'] ?? 0 );
	$jbli_nonce   = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) );

	if ( ! $jbli_post_id || ! wp_verify_nonce( $jbli_nonce, 'jbli_export_csv_' . $jbli_post_id ) )
	{
		wp_die( esc_html__( 'Security check failed', 'job-listings' ), 403 );
	}

	$post = get_post( $jbli_post_id );

	if ( ! $post instanceof WP_Post || JBLI_CPT !== $post->post_type )
	{
		wp_die( esc_html__( 'Post not found', 'job-listings' ), 404 );
	}

	global $wpdb;

	$jbli_table 	= jbli_apply_submissions_table();
	$jbli_ids_raw 	= sanitize_text_field( wp_unslash( $_GET['ids'] ?? '' ) );
	$jbli_ids 		= array_map( 'absint', explode( ',', $jbli_ids_raw ) );
	$jbli_ids 		= array_filter( $jbli_ids );

	if ( ! empty( $jbli_ids ) )
	{
		$jbli_placeholders = implode( ',', array_fill( 0, count( $jbli_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$jbli_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT jbli_applicant_name, jbli_applicant_phone, jbli_applicant_email, jbli_submitted_at
				   FROM {$jbli_table}
				  WHERE jbli_post_id = %d
				    AND id IN ($jbli_placeholders)
				  ORDER BY jbli_submitted_at ASC",
				array_merge( array( $jbli_post_id ), $jbli_ids )
			),
			ARRAY_A
		);
	} else {
		$jbli_rows = array();
	}

	$jbli_fields = array( 'name', 'jbli_phone', 'jbli_email', 'date', 'post_id' );

	$jbli_filename = 'listing-' . $jbli_post_id . '-submissions-' . gmdate( 'Y-m-d' ) . '.csv';

	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $jbli_filename . '"' );
	header( 'Cache-Control: no-store, no-cache' );
	header( 'Pragma: no-cache' );

	echo "\xEF\xBB\xBF";

	$jbli_out = fopen( 'php://output', 'w' );

	if ( false === $jbli_out ) { wp_die( esc_html__( 'Could not open output stream.', 'job-listings' ), 500 ); }

	$jbli_headers = array();

	if ( in_array( 'name', $jbli_fields, true ) ) { $jbli_headers[] = __( 'Ονοματεπώνυμο', 'job-listings' ); }

	if ( in_array( 'phone', $jbli_fields, true ) ) { $jbli_headers[] = __( 'Κινητό', 'job-listings' ); }

	if ( in_array( 'jbli_email', $jbli_fields, true ) ) { $jbli_headers[] = __( 'Email', 'job-listings' ); }

	if ( in_array( 'date', $jbli_fields, true ) ) { $jbli_headers[] = __( 'Ημ/νία Υποβολής', 'job-listings' ); }

	if ( in_array( 'post_id', $jbli_fields, true ) ) { $jbli_headers[] = __( 'Αγγελία ID', 'job-listings' ); }

	fputcsv( $jbli_out, $jbli_headers );

	if ( ! empty( $jbli_rows ) )
	{
		foreach ( $jbli_rows as $jbli_row ) {

			$jbli_line = array();

			if ( in_array( 'name', $jbli_fields, true ) ) { $jbli_line[] = $jbli_row['jbli_applicant_name']; }

			if ( in_array( 'phone', $jbli_fields, true ) ) { $jbli_line[] = $jbli_row['jbli_applicant_phone']; }

			if ( in_array( 'jbli_email', $jbli_fields, true ) ) { $jbli_line[] = $jbli_row['jbli_applicant_email']; }

			if ( in_array( 'date', $jbli_fields, true ) )
			{
				$jbli_line[] = wp_date( 'd/m/Y H:i', strtotime( $jbli_row['jbli_submitted_at'] ) );
			}

			if ( in_array( 'post_id', $jbli_fields, true ) ) { $jbli_line[] = $jbli_post_id; }
			fputcsv( $jbli_out, $jbli_line );

		}
	}

	fclose( $jbli_out );
	exit;

}

add_action( 'admin_init', 'jbli_apply_export_csv' );

function jbli_apply_submissions_admin_footer(): void {

	$jbli_screen = get_current_screen();

	if ( ! $jbli_screen || false === strpos( $jbli_screen->id, 'jbli_admin_panel' ) ) { return; }

	?>
	<div id="jbli_sub_modal" class="jbli_sub_modal">
		<div class="jbli_sub_modal_box">
			<div class="jbli_sub_modal_header">
				<strong class="jbli_sub_modal_header_title"><?php esc_html_e( 'Υποβολές Ενδιαφέροντος', 'job-listings' ); ?></strong>
				<div class="jbli_sub_modal_header_actions">
					<a id="jbli_sub_export" href="#" class="button button-small" target="_blank">
						⬇ <?php esc_html_e( 'Εξαγωγή CSV', 'job-listings' ); ?>
					</a>
					<button type="button" id="jbli_sub_close" class="button button-small"
						aria-label="<?php esc_attr_e( 'Κλείσιμο', 'job-listings' ); ?>">✕</button>
				</div>
			</div>
			<div id="jbli_sub_body" class="jbli_sub_modal_body">
				<p class="jbli_sub_modal_text_muted"><?php esc_html_e( 'Φόρτωση…', 'job-listings' ); ?></p>
			</div>
		</div>
	</div>
	<?php

}

add_action( 'admin_footer', 'jbli_apply_submissions_admin_footer' );
