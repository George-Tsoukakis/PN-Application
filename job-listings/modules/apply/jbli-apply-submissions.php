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

/* jbli_apply_submissions_table() lives in includes/jbli-storage.php (9.9.57: the unused duplicate here was removed). */

/**
 * Save a submission record to the custom DB table.
 *
 * @since 9.9.57 Returns the row ID and starts as "pending" (see jbli_apply_set_submission_status()).
 *
 * @param int    $jbli_post_id Listing post ID.
 * @param string $jbli_name    Applicant full name.
 * @param string $jbli_phone   Applicant phone.
 * @param string $jbli_email   Applicant email.
 * @return int Row ID, or 0 on failure.
 */
function jbli_apply_save_submission( int $jbli_post_id, string $jbli_name, string $jbli_phone, string $jbli_email ): int {

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
			'jbli_status'          => 'pending',
		),
		array( '%d', '%s', '%s', '%s', '%s', '%s' )
	);

	return false !== $jbli_result ? (int) $wpdb->insert_id : 0;

}

/**
 * Record whether the email to the pharmacy went out.
 *
 * @since 9.9.57
 *
 * @param int    $jbli_id     Row ID.
 * @param string $jbli_status 'sent' | 'failed'.
 * @return void
 */
function jbli_apply_set_submission_status( int $jbli_id, string $jbli_status ): void {

	global $wpdb;

	if ( ! in_array( $jbli_status, array( 'pending', 'sent', 'failed' ), true ) ) { return; }

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->update( jbli_apply_submissions_table(), array( 'jbli_status' => $jbli_status ), array( 'id' => $jbli_id ), array( '%s' ), array( '%d' ) );

}

/**
 * Days an application is kept before it is deleted automatically.
 *
 * Listings run for 30 days; the default 60 leaves the pharmacy another
 * month to get back to applicants. Set in Ρυθμίσεις (option
 * jbli_apply_retention_days) or with the filter.
 *
 * @since 9.9.57
 * @return int
 */
function jbli_apply_retention_days(): int {

	$jbli_days = (int) get_option( 'jbli_apply_retention_days', 60 );

	return max( 7, min( 730, (int) apply_filters( 'jbli_apply_retention_days', $jbli_days > 0 ? $jbli_days : 60 ) ) );

}

/**
 * Delete applications older than the retention period (daily cron).
 *
 * @since 9.9.57
 * @return int Rows deleted.
 */
function jbli_apply_purge_old_submissions(): int {

	global $wpdb;

	$jbli_table  = jbli_apply_submissions_table();
	/* jbli_submitted_at is stored in site-local time (current_time('mysql')). */
	$jbli_cutoff = wp_date( 'Y-m-d H:i:s', time() - jbli_apply_retention_days() * DAY_IN_SECONDS );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$jbli_deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$jbli_table} WHERE jbli_submitted_at < %s", $jbli_cutoff ) );

	return (int) $jbli_deleted;

}

add_action( 'jbli_apply_purge_cron', 'jbli_apply_purge_old_submissions' );

/**
 * Schedule the daily purge.
 *
 * @since 9.9.57
 * @return void
 */
function jbli_apply_schedule_purge(): void {

	if ( ! wp_next_scheduled( 'jbli_apply_purge_cron' ) ) { wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'jbli_apply_purge_cron' ); }

}

add_action( 'init', 'jbli_apply_schedule_purge' );

/**
 * A listing deleted for good takes its applications with it.
 *
 * @since 9.9.57
 * @param int $jbli_post_id Post ID.
 * @return void
 */
function jbli_apply_delete_listing_submissions( $jbli_post_id ): void {

	if ( JBLI_CPT !== get_post_type( (int) $jbli_post_id ) ) { return; }

	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->delete( jbli_apply_submissions_table(), array( 'jbli_post_id' => (int) $jbli_post_id ), array( '%d' ) );

}

add_action( 'before_delete_post', 'jbli_apply_delete_listing_submissions' );

/**
 * Applications by email address (privacy export / erase).
 *
 * @param string $jbli_email Email.
 * @param int    $jbli_page  Page (1-based).
 * @return array[]
 */
function jbli_apply_rows_by_email( string $jbli_email, int $jbli_page ): array {

	global $wpdb;

	$jbli_table = jbli_apply_submissions_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$jbli_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, jbli_post_id, jbli_applicant_name, jbli_applicant_phone, jbli_applicant_email, jbli_submitted_at
			   FROM {$jbli_table}
			  WHERE jbli_applicant_email = %s
			  ORDER BY id ASC
			  LIMIT %d OFFSET %d",
			$jbli_email,
			100,
			( max( 1, $jbli_page ) - 1 ) * 100
		),
		ARRAY_A
	);

	return is_array( $jbli_rows ) ? $jbli_rows : array();

}

/**
 * Personal data exporter (Εργαλεία → Εξαγωγή προσωπικών δεδομένων).
 *
 * @since 9.9.57
 * @param string $jbli_email Email.
 * @param int    $jbli_page  Page.
 * @return array
 */
function jbli_apply_privacy_exporter( $jbli_email, $jbli_page = 1 ): array {

	$jbli_rows  = jbli_apply_rows_by_email( (string) $jbli_email, (int) $jbli_page );
	$jbli_items = array();

	foreach ( $jbli_rows as $jbli_row ) {

		$jbli_items[] = array(
			'group_id'    => 'jbli-applications',
			'group_label' => __( 'Εκδηλώσεις ενδιαφέροντος για αγγελίες', 'job-listings' ),
			'item_id'     => 'jbli-application-' . (int) $jbli_row['id'],
			'data'        => array(
				array( 'name' => __( 'Αγγελία', 'job-listings' ),       'value' => get_the_title( (int) $jbli_row['jbli_post_id'] ) ),
				array( 'name' => __( 'Ονοματεπώνυμο', 'job-listings' ), 'value' => $jbli_row['jbli_applicant_name'] ),
				array( 'name' => __( 'Τηλέφωνο', 'job-listings' ),      'value' => $jbli_row['jbli_applicant_phone'] ),
				array( 'name' => __( 'Email', 'job-listings' ),         'value' => $jbli_row['jbli_applicant_email'] ),
				array( 'name' => __( 'Ημ/νία', 'job-listings' ),        'value' => $jbli_row['jbli_submitted_at'] ),
			),
		);

	}

	return array( 'data' => $jbli_items, 'done' => count( $jbli_rows ) < 100 );

}

/**
 * Personal data eraser (Εργαλεία → Διαγραφή προσωπικών δεδομένων).
 *
 * @since 9.9.57
 * @param string $jbli_email Email.
 * @param int    $jbli_page  Page (always 1: erased rows are gone).
 * @return array
 */
function jbli_apply_privacy_eraser( $jbli_email, $jbli_page = 1 ): array {

	global $wpdb;

	$jbli_email = (string) $jbli_email;
	$jbli_count = 0;

	if ( is_email( $jbli_email ) )
	{
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$jbli_count = (int) $wpdb->delete( jbli_apply_submissions_table(), array( 'jbli_applicant_email' => $jbli_email ), array( '%s' ) );
	}

	return array(
		'items_removed'  => $jbli_count > 0,
		'items_retained' => false,
		'messages'       => array(),
		'done'           => true,
	);

}

function jbli_apply_register_privacy_exporter( $jbli_exporters ) {

	$jbli_exporters['job-listings-applications'] = array(
		'exporter_friendly_name' => __( 'Αιτήσεις σε αγγελίες εργασίας', 'job-listings' ),
		'callback'               => 'jbli_apply_privacy_exporter',
	);

	return $jbli_exporters;

}

add_filter( 'wp_privacy_personal_data_exporters', 'jbli_apply_register_privacy_exporter' );

function jbli_apply_register_privacy_eraser( $jbli_erasers ) {

	$jbli_erasers['job-listings-applications'] = array(
		'eraser_friendly_name' => __( 'Αιτήσεις σε αγγελίες εργασίας', 'job-listings' ),
		'callback'             => 'jbli_apply_privacy_eraser',
	);

	return $jbli_erasers;

}

add_filter( 'wp_privacy_personal_data_erasers', 'jbli_apply_register_privacy_eraser' );

/**
 * Text for Ρυθμίσεις → Απόρρητο → οδηγός πολιτικής απορρήτου.
 *
 * @since 9.9.57
 * @return void
 */
function jbli_apply_privacy_policy_content(): void {

	if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) { return; }

	wp_add_privacy_policy_content(
		__( 'Αγγελίες εργασίας', 'job-listings' ),
		'<p>' . esc_html( sprintf(
			/* translators: %d: days applications are kept */
			__( 'Όταν εκδηλώνετε ενδιαφέρον για μια αγγελία, το ονοματεπώνυμο, το τηλέφωνο και το email σας στέλνονται στο φαρμακείο της αγγελίας. Κρατάμε αντίγραφο για %d ημέρες και μετά διαγράφεται αυτόματα. Διαγράφεται επίσης όταν διαγραφεί η αγγελία.', 'job-listings' ),
			jbli_apply_retention_days()
		) ) . '</p>'
	);

}

add_action( 'admin_init', 'jbli_apply_privacy_policy_content' );

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

	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'jbli_submissions_' . $jbli_post_id ) )
	{
		wp_send_json_error( array( 'jbli_message' => __( 'Security check failed', 'job-listings' ) ), 403 );
	}

	global $wpdb;
	$jbli_table = jbli_apply_submissions_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$jbli_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, jbli_applicant_name, jbli_applicant_phone, jbli_applicant_email, jbli_submitted_at, jbli_status
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
				<th class="jbli_sub_modal_th_status"><?php esc_html_e( 'Email', 'job-listings' ); ?></th>
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
						<td><?php echo esc_html( wp_date( 'd/m/Y H:i', (int) jbli_local_datetime_to_ts( $jbli_row->jbli_submitted_at ) ) ); ?></td>
						<td>
							<?php
								$jbli_st = (string) ( $jbli_row->jbli_status ?? 'sent' );
								if ( 'failed' === $jbli_st )       { echo '<span style="color:#b91c1c;" title="' . esc_attr__( 'Το email προς το φαρμακείο απέτυχε', 'job-listings' ) . '">✕ ' . esc_html__( 'Απέτυχε', 'job-listings' ) . '</span>'; }
								elseif ( 'pending' === $jbli_st )  { echo '<span style="color:#92400e;">… ' . esc_html__( 'Άγνωστο', 'job-listings' ) . '</span>'; }
								else                               { echo '<span style="color:#047857;">✓ ' . esc_html__( 'Στάλθηκε', 'job-listings' ) . '</span>'; }
							?>
						</td>
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

	$jbli_fields = array( 'name', 'phone', 'email', 'date', 'post_id' );

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

	if ( in_array( 'email', $jbli_fields, true ) ) { $jbli_headers[] = __( 'Email', 'job-listings' ); }

	if ( in_array( 'date', $jbli_fields, true ) ) { $jbli_headers[] = __( 'Ημ/νία Υποβολής', 'job-listings' ); }

	if ( in_array( 'post_id', $jbli_fields, true ) ) { $jbli_headers[] = __( 'Αγγελία ID', 'job-listings' ); }

	fputcsv( $jbli_out, $jbli_headers );

	if ( ! empty( $jbli_rows ) )
	{
		foreach ( $jbli_rows as $jbli_row ) {

			$jbli_line = array();

			if ( in_array( 'name', $jbli_fields, true ) ) { $jbli_line[] = jbli_csv_safe( $jbli_row['jbli_applicant_name'] ); }

			if ( in_array( 'phone', $jbli_fields, true ) ) { $jbli_line[] = jbli_csv_safe( $jbli_row['jbli_applicant_phone'] ); }

			if ( in_array( 'email', $jbli_fields, true ) ) { $jbli_line[] = jbli_csv_safe( $jbli_row['jbli_applicant_email'] ); }

			if ( in_array( 'date', $jbli_fields, true ) )
			{
				$jbli_line[] = wp_date( 'd/m/Y H:i', (int) jbli_local_datetime_to_ts( $jbli_row['jbli_submitted_at'] ) );
			}

			if ( in_array( 'post_id', $jbli_fields, true ) ) { $jbli_line[] = $jbli_post_id; }
			fputcsv( $jbli_out, $jbli_line );

		}
	}

	fclose( $jbli_out );
	exit;

}

add_action( 'admin_init', 'jbli_apply_export_csv' );

/**
 * Neutralise spreadsheet formulas in visitor-supplied CSV values.
 *
 * Applicants are anonymous, so a name like "=HYPERLINK(...)" must not run
 * as a formula when the pharmacy opens the export in Excel/Sheets.
 *
 * @param mixed $jbli_value Cell value.
 * @return string
 */
function jbli_csv_safe( $jbli_value ) {

	$jbli_value = (string) $jbli_value;

	/* Plain phone numbers such as "+30 210..." are not formulas. */
	if ( preg_match( '/^[+\d\s().\-]+$/', $jbli_value ) ) { return $jbli_value; }

	if ( '' !== $jbli_value && false !== strpos( "=+-@\t\r", $jbli_value[0] ) ) { return "'" . $jbli_value; }

	return $jbli_value;

}

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
