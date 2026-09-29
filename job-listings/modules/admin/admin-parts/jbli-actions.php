<?php
/**
 * Admin Panel: Action Handler
 *
 * Processes POST actions from the admin panel:
 * approve, deactivate, renew, delete, backfill_storage, save_settings.
 *
 * Security model:
 *  1. current_user_can( 'manage_options' ) guard.
 *  2. Action whitelist check.
 *  3. wp_verify_nonce() with action+post_id key.
 *  4. Post existence + CPT type check (for post-specific actions).
 *
 * Loaded by jbli-admin-panel.php.
 *
 * @package JobListings
 * @since   9.9.24
 */

defined( 'ABSPATH' ) || exit;

function jbli_admin_panel_handle_action(): void {

	if ( ! isset( $_POST['jbli_admin_action'] ) ) { return; }

	if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Unauthorized', 'job-listings' ), 403 ); }


	$jbli_action   = sanitize_key( wp_unslash( $_POST['jbli_admin_action'] ?? '' ) );
	$jbli_post_id  = absint( $_POST['job_post_id'] ?? 0 );
	$jbli_nonce    = sanitize_text_field( wp_unslash( $_POST['_job_listing_admin_nonce'] ?? '' ) );
	$jbli_f_nomos  = absint( $_POST['f_nomos']  ?? 0 );
	$jbli_f_cat    = absint( $_POST['f_cat']    ?? 0 );
	$jbli_f_status = sanitize_key( wp_unslash( $_POST['f_status'] ?? '' ) );
	$jbli_f_s      = sanitize_text_field( wp_unslash( $_POST['f_s'] ?? '' ) );


	$jbli_f_s = trim( $jbli_f_s );

	if ( function_exists( 'jbli_strlen' ) && jbli_strlen( $jbli_f_s ) > 80 )
	{
		$jbli_f_s = function_exists( 'jbli_substr' )
			? jbli_substr( $jbli_f_s, 0, 80 )
			: substr( $jbli_f_s, 0, 80 );
	}


	$jbli_zero_post_actions = array( 'backfill_storage', 'save_settings' );

	if ( ! $jbli_action || ( ! $jbli_post_id && ! in_array( $jbli_action, $jbli_zero_post_actions, true ) ) )
	{
		wp_die( esc_html__( 'Invalid request', 'job-listings' ), 400 );
	}


	$jbli_allowed_actions = array( 'approve', 'jbli_deactivate', 'renew', 'delete', 'backfill_storage', 'save_settings' );

	if ( ! in_array( $jbli_action, $jbli_allowed_actions, true ) )
	{
		wp_die( esc_html__( 'Invalid action', 'job-listings' ), 400 );
	}


	if ( ! wp_verify_nonce( $jbli_nonce, 'jbli_admin_' . $jbli_action . '_' . $jbli_post_id ) )
	{
		wp_die( esc_html__( 'Security check failed', 'job-listings' ), 403 );
	}


	$post = null;

	if ( ! in_array( $jbli_action, $jbli_zero_post_actions, true ) )
	{
		$post = get_post( $jbli_post_id );

		if ( ! $post instanceof WP_Post || JBLI_CPT !== $post->post_type )
		{
			wp_die( esc_html__( 'Post not found', 'job-listings' ), 404 );
		}
	}


	$jbli_message = jbli_admin_execute_action( $jbli_action, $jbli_post_id, $post );


	$jbli_target_page = in_array( $jbli_action, $jbli_zero_post_actions, true ) ? 'jbli_settings' : 'jbli_admin_panel';
	$jbli_target_base = admin_url( 'admin.php' );

	$jbli_redirect = add_query_arg(
		array_filter( array(
			'page'       => $jbli_target_page,
			'job_done'   => $jbli_message,
			'jbli_saved' => $jbli_message,
			'f_nomos'    => $jbli_f_nomos  ?: null,
			'f_cat'      => $jbli_f_cat    ?: null,
			'f_status'   => $jbli_f_status ?: null,
			'f_s'        => $jbli_f_s      ?: null,
		) ),
		$jbli_target_base
	);

	wp_safe_redirect( $jbli_redirect );
	exit;

}

add_action( 'admin_init', 'jbli_admin_panel_handle_action' );

/**
 * Execute a single validated admin action.
 *
 * Extracted from the handler so each case is testable independently.
 * Dies on failure; returns the $jbli_message string on success.
 *
 * @param string       $jbli_action  One of the allowed action strings.
 * @param int          $jbli_post_id Listing post ID (0 for post-agnostic actions).
 * @param WP_Post|null $post    Loaded WP_Post (null for post-agnostic actions).
 * @return string Message key for the redirect query arg.
 */
function jbli_admin_execute_action( string $jbli_action, int $jbli_post_id, ?WP_Post $post ): string {

	switch ( $jbli_action ) {

		case 'approve':
			return jbli_action_approve( $jbli_post_id, $post );

		case 'jbli_deactivate':
			return jbli_action_deactivate( $jbli_post_id );

		case 'renew':
			return jbli_action_renew( $jbli_post_id );

		case 'delete':
			return jbli_action_delete( $jbli_post_id );

		case 'backfill_storage':
			return jbli_action_backfill();

		case 'save_settings':
			return jbli_action_save_settings();
	}


	wp_die( esc_html__( 'Invalid action', 'job-listings' ), 400 );

}

function jbli_action_approve( int $jbli_post_id, WP_Post $post ): string {

	$jbli_is_expired = (
		'job-expired' === $post->post_status ||
		(bool) get_post_meta( $jbli_post_id, JBLI_META_EXPIRED, true )
	);

	if ( $jbli_is_expired )
	{
		if ( ! function_exists( 'jbli_renew_job' ) )
		{
			wp_die( esc_html__( 'Renew function not available.', 'job-listings' ), 500 );
		}

		$jbli_result = jbli_renew_job( $jbli_post_id );

		if ( is_wp_error( $jbli_result ) )
		{
			wp_die( esc_html__( 'Could not renew expired listing.', 'job-listings' ), 500 );
		}

		$jbli_message = 'approved_renewed';
	} else {

		if ( ! function_exists( 'jbli_activate_job' ) )
		{
			wp_die( esc_html__( 'Activate function not available.', 'job-listings' ), 500 );
		}

		$jbli_result = jbli_activate_job( $jbli_post_id );

		if ( is_wp_error( $jbli_result ) )
		{
			wp_die( esc_html__( 'Could not approve listing.', 'job-listings' ), 500 );
		}

		$jbli_message = 'approved';
	}

	clean_post_cache( $jbli_post_id );
	do_action( 'jbli_admin_approved', $jbli_post_id );

	return $jbli_message;

}

function jbli_action_deactivate( int $jbli_post_id ): string {

	if ( ! function_exists( 'jbli_deactivate_job' ) )
	{
		wp_die( esc_html__( 'Deactivate function not available.', 'job-listings' ), 500 );
	}

	$jbli_result = jbli_deactivate_job( $jbli_post_id );

	if ( is_wp_error( $jbli_result ) ) { wp_die( esc_html__( 'Could not deactivate listing.', 'job-listings' ), 500 ); }

	clean_post_cache( $jbli_post_id );
	do_action( 'jbli_admin_deactivated', $jbli_post_id );

	return 'deactivated';

}

function jbli_action_renew( int $jbli_post_id ): string {

	if ( ! function_exists( 'jbli_renew_job' ) )
	{
		wp_die( esc_html__( 'Renew function not available.', 'job-listings' ), 500 );
	}

	$jbli_result = jbli_renew_job( $jbli_post_id );

	if ( is_wp_error( $jbli_result ) ) { wp_die( esc_html__( 'Could not renew listing.', 'job-listings' ), 500 ); }

	clean_post_cache( $jbli_post_id );
	do_action( 'jbli_admin_renewed', $jbli_post_id );

	return 'renewed';

}

function jbli_action_delete( int $jbli_post_id ): string {

	$jbli_deleted = wp_trash_post( $jbli_post_id );

	if ( ! $jbli_deleted ) { wp_die( esc_html__( 'Could not delete listing.', 'job-listings' ), 500 ); }

	clean_post_cache( $jbli_post_id );
	do_action( 'jbli_admin_deleted', $jbli_post_id );

	return 'deleted';

}

function jbli_action_backfill(): string {

	if ( ! function_exists( 'jbli_backfill_storage' ) )
	{
		wp_die( esc_html__( 'Backfill function not available.', 'job-listings' ), 500 );
	}

	$jbli_result = jbli_backfill_storage();

	if ( empty( $jbli_result['success'] ) ) { wp_die( esc_html__( 'Backfill failed.', 'job-listings' ), 500 ); }

	do_action( 'jbli_admin_backfilled', $jbli_result['synced'] );

	return 'backfilled';

}

function jbli_action_save_settings(): string {

	$jbli_delete_on_uninstall = isset( $_POST['jbli_delete_on_uninstall'] )
		? (bool) absint( $_POST['jbli_delete_on_uninstall'] )
		: false;

	update_option( 'jbli_delete_on_uninstall', $jbli_delete_on_uninstall, false );

	/* The Danger Zone form posts only its own checkbox; unchecked privacy boxes must not be read as "off". */
	if ( 'danger' === sanitize_key( wp_unslash( $_POST['jbli_settings_section'] ?? '' ) ) )
	{
		do_action( 'jbli_admin_settings_saved' );

		return 'settings_saved';
	}

	update_option( 'jbli_public_phone',   isset( $_POST['jbli_public_phone'] )   ? '1' : '0', false );
	update_option( 'jbli_public_email',   isset( $_POST['jbli_public_email'] )   ? '1' : '0', false );
	update_option( 'jbli_public_address', isset( $_POST['jbli_public_address'] ) ? '1' : '0', false );


	do_action( 'jbli_admin_settings_saved' );

	return 'settings_saved';

}

if ( ! function_exists( 'jbli_fields_handle_save' ) )
{

		function jbli_fields_handle_save(): void {

		if ( ! isset( $_POST['jbli_fields_action'] ) ) { return; }

		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Unauthorized', 'job-listings' ), 403 ); }

		$jbli_nonce = sanitize_text_field( wp_unslash( $_POST['jbli_fields_nonce'] ?? '' ) );

		if ( ! wp_verify_nonce( $jbli_nonce, 'jbli_fields_save' ) )
		{
			wp_die( esc_html__( 'Security check failed', 'job-listings' ), 403 );
		}

		$jbli_sub_action = sanitize_key( wp_unslash( $_POST['jbli_fields_action'] ) );

		if ( 'save_apply_email' === $jbli_sub_action )
		{

			$jbli_subject = sanitize_text_field( wp_unslash( $_POST['jbli_apply_email_subject'] ?? '' ) );
			$jbli_body    = sanitize_textarea_field( wp_unslash( $_POST['jbli_apply_email_body'] ?? '' ) );

			update_option( 'jbli_apply_email_subject', $jbli_subject, false );
			update_option( 'jbli_apply_email_body',    $jbli_body,    false );

			wp_safe_redirect(
				add_query_arg(
					array( 'page' => 'jbli_settings', 'jbli_saved' => 'apply_email' ),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		if ( 'save_import_status' === $jbli_sub_action )
		{
			$jbli_mode = sanitize_key( wp_unslash( $_POST['jbli_import_status'] ?? 'publish' ) );

			update_option( 'jbli_import_status', 'pending' === $jbli_mode ? 'pending' : 'publish', false );

			wp_safe_redirect(
				add_query_arg(
					array( 'page' => 'jbli_settings', 'jbli_saved' => 'import_status' ),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		if ( 'save_title_limit' === $jbli_sub_action )
		{
			update_option( 'jbli_title_max_chars', min( 120, max( 5, absint( $_POST['jbli_title_max_chars'] ?? 15 ) ) ), false );

			wp_safe_redirect(
				add_query_arg(
					array( 'page' => 'jbli_settings', 'jbli_saved' => 'title_limit' ),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		if ( 'save_form_media' === $jbli_sub_action )
		{
			update_option( 'jbli_form_media_url', esc_url_raw( trim( wp_unslash( (string) ( $_POST['jbli_form_media_url'] ?? '' ) ) ) ), false );
			update_option( 'jbli_single_media_url', esc_url_raw( trim( wp_unslash( (string) ( $_POST['jbli_single_media_url'] ?? '' ) ) ) ), false );

			wp_safe_redirect(
				add_query_arg(
					array( 'page' => 'jbli_settings', 'jbli_saved' => 'form_media' ),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		if ( 'save_google_map' === $jbli_sub_action )
		{

			$jbli_api_key = sanitize_text_field( wp_unslash( $_POST['jbli_google_map_api_key'] ?? '' ) );

			update_option( 'jbli_google_map_api_key', $jbli_api_key, false );

			wp_safe_redirect(
				add_query_arg(
					array( 'page' => 'jbli_settings', 'jbli_saved' => 'google_map' ),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		if ( 'save_field_options' === $jbli_sub_action )
		{


			$jbli_salary = jbli_parse_parallel_arrays(
				(array) ( $_POST['jbli_salary_keys']   ?? array() ),
				(array) ( $_POST['jbli_salary_labels'] ?? array() )
			);
			$jbli_type = jbli_parse_parallel_arrays(
				(array) ( $_POST['jbli_type_keys']   ?? array() ),
				(array) ( $_POST['jbli_type_labels'] ?? array() )
			);

			update_option( 'jbli_salary_options', wp_json_encode( $jbli_salary ), false );
			update_option( 'jbli_type_options',   wp_json_encode( $jbli_type ),   false );

			wp_safe_redirect(
				add_query_arg(
					array( 'page' => 'jbli_settings', 'jbli_saved' => 'field_options' ),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

	}
}

add_action( 'admin_init', 'jbli_fields_handle_save' );

if ( ! function_exists( 'jbli_parse_key_label_lines' ) )
{

	/**
	 * Parse a textarea of "slug|Label" lines into an associative array.
	 *
	 * Lines that don't contain "|" or produce an empty slug are skipped.
	 *
	 * @param string $jbli_raw Raw textarea value.
	 * @return array<string,string>
	 */
	function jbli_parse_key_label_lines( string $jbli_raw ): array {

		$jbli_result = array();
		$jbli_lines  = explode( "\n", $jbli_raw );

		foreach ( $jbli_lines as $jbli_line ) {

			$jbli_line = trim( $jbli_line );

			if ( '' === $jbli_line ) { continue; }

			$jbli_parts = explode( '|', $jbli_line, 2 );

			if ( count( $jbli_parts ) !== 2 ) { continue; }

			$jbli_key   = sanitize_key( trim( $jbli_parts[0] ) );
			$jbli_label = sanitize_text_field( trim( $jbli_parts[1] ) );

			if ( $jbli_key && $jbli_label ) { $jbli_result[ $jbli_key ] = $jbli_label; }

		}

		return $jbli_result;

	}
}

if ( ! function_exists( 'jbli_parse_parallel_arrays' ) )
{
	/**
	 * Build an associative array from two parallel POST arrays (keys[] + labels[]).
	 *
	 * @param array $jbli_keys   Raw slug values.
	 * @param array $jbli_labels Raw label values.
	 * @return array<string,string>
	 */
	function jbli_parse_parallel_arrays( array $jbli_keys, array $jbli_labels ): array {

		$jbli_result = array();
		$jbli_count  = min( count( $jbli_keys ), count( $jbli_labels ) );

		for ( $jbli_i = 0; $jbli_i < $jbli_count; $jbli_i++ ) {

			$jbli_key   = sanitize_key( trim( wp_unslash( (string) $jbli_keys[ $jbli_i ] ) ) );
			$jbli_label = sanitize_text_field( trim( wp_unslash( (string) $jbli_labels[ $jbli_i ] ) ) );

			if ( $jbli_key !== '' && $jbli_label !== '' ) { $jbli_result[ $jbli_key ] = $jbli_label; }

		}

		return $jbli_result;

	}
}
