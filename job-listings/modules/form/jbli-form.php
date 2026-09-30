<?php
/**
 * Module: Submission Form
 *
 * Shortcode: [new-listing]
 *
 * Entry point for the listing submission form. Loads focused sub-modules
 * and wires together the shortcode renderer, the admin-post handler, and
 * the form submission processor.
 *
 * Sub-module layout:
 *   form-parts/jbli-permissions.php  — who can access / edit a listing.
 *   form-parts/jbli-validation.php   — sanitize + validate all POST fields.
 *   form-parts/jbli-saving.php       — write post record, taxonomy terms, meta.
 *   form-parts/jbli-rate-limit.php   — active-listings cap + cooldown transients.
 *
 * @package JobListings
 * @since   9.6.6
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/form-parts/jbli-permissions.php';
require_once __DIR__ . '/form-parts/jbli-validation.php';
require_once __DIR__ . '/form-parts/jbli-saving.php';
require_once __DIR__ . '/form-parts/jbli-rate-limit.php';

/**
 * Render the [new-listing] shortcode.
 *
 * Handles three states:
 *  1. Unauthenticated — shows login gate.
 *  2. Wrong role      — shows error notice.
 *  3. Authenticated pharmacist / admin — renders the form (new or edit).
 *
 * @return string HTML output.
 */
function jbli_render_form() {

	ob_start();


	if ( ! is_user_logged_in( ) )
	{
		$jbli_form_icon = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M8 7V5a4 4 0 0 1 8 0v2"/><line x1="12" y1="12" x2="12" y2="16"/><circle cx="12" cy="12" r="1" fill="#059669"/></svg>';

		$jbli_html = function_exists( 'jbli_login_gate_html' )
			? jbli_login_gate_html(
				__( 'Καταχώρηση Αγγελίας', 'job-listings' ),
				__( 'Για να καταχωρήσετε αγγελία εργασίας πρέπει να συνδεθείτε ή να δημιουργήσετε λογαριασμό φαρμακείου.', 'job-listings' ),
				$jbli_form_icon
			)
			: '';
		echo $jbli_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe HTML component.

		return ob_get_clean();
	}


	if ( ! jbli_form_user_can_submit( ) )
	{
		$jbli_html = jbli_notice(
			__( 'Μόνο εγγεγραμμένα φαρμακεία μπορούν να καταχωρήσουν αγγελίες.', 'job-listings' ),
			'error'
		);
		echo $jbli_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe HTML component.

		return ob_get_clean();
	}


	$jbli_edit_id    = 0;
	$jbli_job        = null;
	$jbli_is_expired = false;

	if ( ! empty( $_GET['job_edit'] ) )
	{
		$jbli_result = jbli_form_resolve_edit_post();

		if ( is_string( $jbli_result ) )
		{

			echo $jbli_result; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe HTML component.
			return ob_get_clean();
		}

		$jbli_job        = $jbli_result;
		$jbli_edit_id    = $jbli_job->ID;
		$jbli_is_expired = (
			'job-expired' === $jbli_job->post_status ||
			(bool) get_post_meta( $jbli_job->ID, JBLI_META_EXPIRED, true )
		);
	}


	$jbli_notice  = jbli_print_transient_notice();
	$jbli_user_id = get_current_user_id();

	$jbli_phone  = (string) ( get_user_meta( $jbli_user_id, 'phone_1', true )
		?: get_user_meta( $jbli_user_id, 'user_registration_phone_1', true ) );
	$jbli_mobile = (string) ( get_user_meta( $jbli_user_id, 'mobile_phone', true )
		?: get_user_meta( $jbli_user_id, 'user_registration_mobile_phone', true ) );


	if ( '' === trim( $jbli_phone ) && '' !== trim( $jbli_mobile ) ) { $jbli_phone = $jbli_mobile; }

	$jbli_user_data  = get_userdata( $jbli_user_id );
	$jbli_user_email = (
		$jbli_user_data instanceof WP_User &&
		is_email( $jbli_user_data->user_email )
	)
		? $jbli_user_data->user_email
		: '';


	$jbli_v = array();

	if ( $jbli_job instanceof WP_Post )
	{
		$jbli_nomos_ids    = wp_get_post_terms( $jbli_job->ID, 'job_nomos',    array( 'fields' => 'ids' ) );
		$jbli_category_ids = wp_get_post_terms( $jbli_job->ID, 'job_category', array( 'fields' => 'ids' ) );

		$jbli_v = array(
			'jbli_position'      => get_post_meta( $jbli_job->ID, JBLI_META_POSITION,      true ),
			'description'        => $jbli_job->post_content,
			'jbli_salary'        => get_post_meta( $jbli_job->ID, JBLI_META_SALARY,        true ),
			'jbli_type'          => get_post_meta( $jbli_job->ID, JBLI_META_TYPE,          true ),
			'jbli_contact_phone' => get_post_meta( $jbli_job->ID, JBLI_META_CONTACT_PHONE, true ),
			'contact_email'      => get_post_meta( $jbli_job->ID, JBLI_META_EMAIL,         true ),
			'category'           => ! is_wp_error( $jbli_category_ids ) && ! empty( $jbli_category_ids ) ? (int) $jbli_category_ids[0] : 0,
			'nomos'              => ! is_wp_error( $jbli_nomos_ids )    && ! empty( $jbli_nomos_ids )    ? (int) $jbli_nomos_ids[0]    : 0,
			'jbli_address'       => (string) get_post_meta( $jbli_job->ID, JBLI_META_ADDRESS, true ),
			'lat'                => (string) get_post_meta( $jbli_job->ID, JBLI_META_LAT,     true ),
			'lng'                => (string) get_post_meta( $jbli_job->ID, JBLI_META_LNG,     true ),
		);
	}


	$jbli_error_fields = array();

	$jbli_saved = get_current_user_id() > 0
		? get_transient( 'jbli_form_data_' . get_current_user_id() )
		: false;

	if ( is_array( $jbli_saved ) && ! empty( $jbli_saved ) )
	{
		delete_transient( 'jbli_form_data_' . get_current_user_id() );

		$jbli_error_fields = isset( $jbli_saved['_jbli_error_fields'] ) && is_array( $jbli_saved['_jbli_error_fields'] )
			? array_map( 'sanitize_key', $jbli_saved['_jbli_error_fields'] )
			: array();

		$jbli_v = array(
			'jbli_position'      => (string) ( $jbli_saved['job_position']      ?? $jbli_v['jbli_position']      ?? '' ),
			'description'        => (string) ( $jbli_saved['job_description']    ?? $jbli_v['description']   ?? '' ),
			'jbli_salary'        => (string) ( $jbli_saved['job_salary']         ?? $jbli_v['jbli_salary']        ?? '' ),
			'jbli_type'          => (string) ( $jbli_saved['job_type']           ?? $jbli_v['jbli_type']          ?? '' ),
			'jbli_contact_phone' => (string) ( $jbli_saved['job_contact_phone']  ?? $jbli_v['jbli_contact_phone'] ?? '' ),
			'contact_email'      => (string) ( $jbli_saved['job_contact_email']  ?? $jbli_v['contact_email'] ?? '' ),
			'category'           => (int)    ( $jbli_saved['job_category']       ?? $jbli_v['category']      ?? 0  ),
			'nomos'              => (int)    ( $jbli_saved['job_nomos']          ?? $jbli_v['nomos']         ?? 0  ),
			'jbli_address'       => (string) ( $jbli_saved['job_address']        ?? $jbli_v['jbli_address']       ?? '' ),
			'lat'                => (string) ( $jbli_saved['job_lat']            ?? $jbli_v['lat']           ?? '' ),
			'lng'                => (string) ( $jbli_saved['job_lng']            ?? $jbli_v['lng']           ?? '' ),
		);


		if ( ! empty( $jbli_saved['job_contact_phone'] ) ) { $jbli_phone = (string) $jbli_saved['job_contact_phone']; }
	}

	unset( $jbli_saved );


	$jbli_back_url = get_permalink();

	if ( ! is_string( $jbli_back_url ) || '' === $jbli_back_url )
	{
		$jbli_back_url = function_exists( 'jbli_get_form_page_url' )
			? jbli_get_form_page_url()
			: home_url( '/nea-aggelia/' );
	}


	if ( $jbli_edit_id > 0 ) { $jbli_back_url = add_query_arg( 'job_edit', (int) $jbli_edit_id, $jbli_back_url ); }


	$jbli_form_layout = array(
		'container' => 'jbli_submission_form jbli_submission_form_new_listing',
		'grid'      => 'jbli_form_grid',
		'two_col'   => 'jbli_form_grid jbli_form_grid_2',
		'field'     => 'jbli_form_field',
		'full'      => 'jbli_form_field jbli_form_field_full',
	);


	$jbli_template = __DIR__ . '/jbli-form-template.php';

	if ( is_readable( $jbli_template ) )
	{
		include $jbli_template;
	} else {
		$jbli_html = jbli_notice(
			__( 'Το template της φόρμας δεν βρέθηκε.', 'job-listings' ),
			'error'
		);
		echo $jbli_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe HTML component.
	}

	return ob_get_clean();

}

add_shortcode( 'new-listing', 'jbli_render_form' );

/* jbli_form_media_type() lives in includes/helpers-parts/jbli-media.php since 9.9.51. */

function jbli_form_adminpost_handler() {

	if ( ! is_user_logged_in( ) )
	{
		wp_safe_redirect( wp_login_url() );
		exit;
	}


	$jbli_raw_back = esc_url_raw( wp_unslash( $_POST['jbli_back_url'] ?? '' ) );
	$jbli_edit_id  = absint( $_POST['jbli_edit_id'] ?? 0 );


	$jbli_back_url = wp_validate_redirect(
		$jbli_raw_back,
		function_exists( 'jbli_get_form_page_url' )
			? jbli_get_form_page_url()
			: home_url( '/nea-aggelia/' )
	);

	$jbli_result = jbli_process_form_submit( $jbli_edit_id );


	if ( is_string( $jbli_result ) )
	{
		$jbli_user_id = get_current_user_id();

		if ( $jbli_user_id > 0 )
		{

			$jbli_saved_fields = array(
				'job_position'               => sanitize_text_field( wp_unslash( $_POST['job_position']             ?? '' ) ),
				'job_description'            => wp_kses( wp_unslash( $_POST['job_description']          ?? '' ), jbli_allowed_html() ),
				'job_salary'                 => sanitize_text_field( wp_unslash( $_POST['job_salary']    ?? '' ) ),
				'job_type'                   => sanitize_text_field( wp_unslash( $_POST['job_type']      ?? '' ) ),
				'job_contact_phone'          => sanitize_text_field( wp_unslash( $_POST['job_contact_phone']        ?? '' ) ),
				'job_contact_email'          => sanitize_email( wp_unslash( $_POST['job_contact_email']             ?? '' ) ),
				'job_category'               => absint( $_POST['job_category']             ?? 0 ),
				'job_nomos'                  => absint( $_POST['job_nomos']                ?? 0 ),
				'job_address'                => sanitize_text_field( wp_unslash( $_POST['job_address']              ?? '' ) ),
				'job_lat'                    => sanitize_text_field( wp_unslash( $_POST['job_lat']                  ?? '' ) ),
				'job_lng'                    => sanitize_text_field( wp_unslash( $_POST['job_lng']                  ?? '' ) ),
				'job_public_contact_consent' => isset( $_POST['job_public_contact_consent'] ) ? 1 : 0,
				'_jbli_error_fields'         => function_exists( 'jbli_form_error_fields' )
					? jbli_form_error_fields()
					: array(),
			);

			set_transient( 'jbli_form_data_' . $jbli_user_id, $jbli_saved_fields, MINUTE_IN_SECONDS * 5 );
		}

		jbli_redirect_with_notice( $jbli_back_url, wp_strip_all_tags( $jbli_result ), 'error' );
		exit;
	}


	if ( is_int( $jbli_result ) && $jbli_result < 0 )
	{
		jbli_redirect_with_notice(
			$jbli_back_url,
			__( 'Οι αλλαγές αποθηκεύτηκαν. Ανανεώστε την αγγελία από το dashboard για να εμφανιστεί ξανά.', 'job-listings' ),
			'warning'
		);
		exit;
	}


	if ( is_int( $jbli_result ) && $jbli_result > 0 )
	{
		$jbli_redirect = get_permalink( $jbli_result );

		if ( ! is_string( $jbli_redirect ) ) { $jbli_redirect = $jbli_back_url; }

		$jbli_message = $jbli_edit_id
			? __( 'Η αγγελία ενημερώθηκε επιτυχώς.', 'job-listings' )
			: __( 'Η αγγελία δημοσιεύτηκε και θα εμφανίζεται για 30 ημέρες.', 'job-listings' );

		jbli_redirect_with_notice( $jbli_redirect, $jbli_message, 'success' );
		exit;
	}


	jbli_redirect_with_notice(
		$jbli_back_url,
		__( 'Σφάλμα αποθήκευσης. Δοκιμάστε ξανά.', 'job-listings' ),
		'error'
	);
	exit;

}

add_action( 'admin_post_job_listing_submit',        'jbli_form_adminpost_handler' );
add_action( 'admin_post_nopriv_job_listing_submit', 'jbli_form_adminpost_handler' );

/**
 * Handle the listing form and dashboard buttons on the front end.
 *
 * The forms post back to their own page instead of /wp-admin/admin-post.php.
 * admin-post.php runs admin_init, and security plugins or theme code that
 * keep non-admins out of wp-admin (redirect/403 on admin_init) silently
 * swallowed every submission from pharmacy accounts. The admin-post hooks
 * above stay for forms rendered by older cached pages.
 *
 * @since 9.9.44
 * @return void
 */
function jbli_frontend_post_router() {

	if ( is_admin() || wp_doing_ajax() || 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) { return; }

	$jbli_action = sanitize_key( wp_unslash( $_POST['action'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Each handler verifies its own nonce.

	if ( 'job_listing_submit' === $jbli_action )
	{
		jbli_form_adminpost_handler();
		exit;
	}

	if ( 'job_listing_dash_action' === $jbli_action && function_exists( 'jbli_dash_adminpost_handler' ) )
	{
		jbli_dash_adminpost_handler();
		exit;
	}

}

add_action( 'wp_loaded', 'jbli_frontend_post_router' );

/**
 * Process a job listing form submission.
 *
 * Orchestrates in order:
 *  1. Nonce / security check.
 *  2. Role / permission check.
 *  3. Edit-mode validation (post exists, user owns it).
 *  4. Field validation & sanitization.
 *  5. Rate limiting (cap + cooldown).
 *  6. Save WP post record.
 *  7. Save taxonomy terms + post meta.
 *  8. Sync storage index + bust caches.
 *  9. Fire action hooks + return result.
 *
 * @param int $jbli_edit_id 0 for new listings, post ID for edits.
 * @return int|string Post ID on success (negative = expired edit saved);
 *                    error notice HTML string on any failure.
 */
function jbli_process_form_submit( $jbli_edit_id = 0 ) {

	$jbli_edit_id     = absint( $jbli_edit_id );
	$jbli_was_expired = false;
	$jbli_edit_post   = null;


	if ( ! isset( $_POST['_job_listing_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_job_listing_nonce'] ) ), 'jbli_submit' ) )
	{
		return jbli_notice( __( 'Σφάλμα ασφαλείας. Παρακαλώ δοκιμάστε ξανά.', 'job-listings' ), 'error' );
	}


	if ( ! jbli_form_user_can_submit( ) )
	{
		return jbli_notice( __( 'Δεν έχετε άδεια υποβολής αγγελίας.', 'job-listings' ), 'error' );
	}


	if ( $jbli_edit_id )
	{
		$jbli_edit_post = get_post( $jbli_edit_id );

		if ( ! $jbli_edit_post instanceof WP_Post || JBLI_CPT !== $jbli_edit_post->post_type )
		{
			return jbli_notice( __( 'Η αγγελία δεν βρέθηκε.', 'job-listings' ), 'error' );
		}

		if ( ! jbli_can_edit_listing( $jbli_edit_id ) )
		{
			return jbli_notice( __( 'Δεν έχετε άδεια επεξεργασίας αυτής της αγγελίας.', 'job-listings' ), 'error' );
		}

		$jbli_was_expired = (
			'job-expired' === $jbli_edit_post->post_status ||
			(bool) get_post_meta( $jbli_edit_id, JBLI_META_EXPIRED, true )
		);
	}


	$jbli_fields = jbli_validate_form_data( $jbli_edit_id );

	if ( is_string( $jbli_fields ) ) { return $jbli_fields; }


	$jbli_user_id    = get_current_user_id();
	$jbli_rate_error = jbli_check_rate_limits( $jbli_user_id, (bool) $jbli_edit_id );

	if ( $jbli_rate_error ) { return $jbli_rate_error; }


	$jbli_post_id = jbli_save_listing_post( $jbli_edit_id, $jbli_fields, $jbli_was_expired, $jbli_edit_post, $jbli_user_id );

	// Nothing was written, so the user must be able to retry straight away.
	if ( is_wp_error( $jbli_post_id ) || ! $jbli_post_id ) { jbli_clear_rate_limit( $jbli_user_id, (bool) $jbli_edit_id ); }

	if ( is_wp_error( $jbli_post_id ) )
	{
		return jbli_notice(
			sprintf(
				/* translators: %s: Error message */
				__( 'Σφάλμα αποθήκευσης: %s', 'job-listings' ),
				esc_html( $jbli_post_id->get_error_message() )
			),
			'error'
		);
	}

	if ( ! $jbli_post_id )
	{
		return jbli_notice( __( 'Σφάλμα αποθήκευσης. Δοκιμάστε ξανά.', 'job-listings' ), 'error' );
	}


	$jbli_owner_id   = $jbli_edit_post instanceof WP_Post ? (int) $jbli_edit_post->post_author : (int) $jbli_user_id;
	$jbli_meta_error = jbli_save_listing_meta( $jbli_post_id, $jbli_fields, $jbli_user_id, ! $jbli_edit_id, $jbli_owner_id );

	if ( $jbli_meta_error ) { return $jbli_meta_error; }


	if ( function_exists( 'jbli_sync_listing_storage' ) ) { jbli_sync_listing_storage( (int) $jbli_post_id ); }

	clean_post_cache( (int) $jbli_post_id );


	if ( $jbli_edit_id )
	{
		do_action( 'jbli_updated', (int) $jbli_post_id );

		if ( $jbli_was_expired ) { return -(int) $jbli_post_id; }
	} else {
		do_action( 'jbli_created', (int) $jbli_post_id );
	}

	return (int) $jbli_post_id;

}
