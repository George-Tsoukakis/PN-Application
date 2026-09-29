<?php
/**
 * Form Validation
 *
 * Sanitizes and validates all POST fields for a listing submission.
 * Returns a clean fields array on success, or an error notice HTML string
 * on the first validation failure.
 *
 * All functions here are stateless — they read from $_POST and return
 * results; they do not write to the database or redirect.
 *
 * Loaded by jbli-form.php.
 *
 * @package JobListings
 * @since   9.9.22
 */

defined( 'ABSPATH' ) || exit;

/**
 * Remember / read which POST fields failed validation.
 *
 * The form handler redirects, so the field names have to travel with the
 * saved form data. Validation records them here; jbli_form_adminpost_handler()
 * reads them straight after jbli_process_form_submit() returns an error.
 *
 * @since 9.9.41
 *
 * @param string[]|null $jbli_fields Field names to store, or null to read.
 * @return string[] The stored field names.
 */
function jbli_form_error_fields( $jbli_fields = null ) {

	static $jbli_stored = array();

	if ( is_array( $jbli_fields ) ) { $jbli_stored = array_values( array_unique( $jbli_fields ) ); }

	return $jbli_stored;
}

/**
 * Maximum number of characters (letters, spaces included) in a listing title.
 *
 * @since 9.9.47 (replaces the 9.9.46 word limit)
 * @return int
 */
function jbli_title_max_chars() {

	/* Set in Αγγελίες → Ρυθμίσεις → «Τίτλος αγγελίας» (9.9.48); the filter still wins. */
	$jbli_max = (int) get_option( 'jbli_title_max_chars', 15 );

	return min( 120, max( 5, (int) apply_filters( 'jbli_title_max_chars', $jbli_max > 0 ? $jbli_max : 15 ) ) );

}

/**
 * Validate and sanitize form POST data.
 *
 * @since 9.9.20
 * @since 9.9.22 Moved to form-parts/jbli-validation.php.
 *
 * @param int $jbli_edit_id 0 for new listings, post ID for edits.
 * @return array|string Sanitized fields array, or error notice HTML.
 */
function jbli_validate_form_data( $jbli_edit_id ) {

	$jbli_errors = array();


	$jbli_position      = sanitize_text_field( wp_unslash( $_POST['job_position']      ?? '' ) );
	$jbli_description   = wp_kses( wp_unslash( $_POST['job_description']  ?? '' ), jbli_allowed_html() );
	// Option keys are matched against a whitelist below. sanitize_key() would
	// strip characters such as "+" and turn the valid "2200+" into "2200".
	$jbli_salary        = sanitize_text_field( wp_unslash( $_POST['job_salary']   ?? '' ) );
	$jbli_type          = sanitize_text_field( wp_unslash( $_POST['job_type']     ?? '' ) );
	$jbli_contact_phone = jbli_sanitize_phone( sanitize_text_field( wp_unslash( $_POST['job_contact_phone'] ?? '' ) ) );
	$jbli_contact_email = sanitize_email( wp_unslash( $_POST['job_contact_email'] ?? '' ) );
	$jbli_category_id   = absint( $_POST['job_category'] ?? 0 );
	$jbli_nomos_id      = absint( $_POST['job_nomos']    ?? 0 );
	$jbli_address       = sanitize_text_field( wp_unslash( $_POST['job_address']   ?? '' ) );
	$jbli_lat           = function_exists( 'jbli_sanitize_lat' )
		? jbli_sanitize_lat( wp_unslash( $_POST['job_lat'] ?? '' ) )
		: '';
	$jbli_lng           = function_exists( 'jbli_sanitize_lng' )
		? jbli_sanitize_lng( wp_unslash( $_POST['job_lng'] ?? '' ) )
		: '';


	if ( ! $jbli_edit_id )
	{

		$jbli_consent = isset( $_POST['job_public_contact_consent'] )
			? (int) $_POST['job_public_contact_consent']
			: 0;

		if ( 1 !== $jbli_consent )
		{
			$jbli_errors[] = array( 'field' => 'job_public_contact_consent', 'message' => __( 'Παρακαλώ αποδεχτείτε τους όρους χρήσης στοιχείων επικοινωνίας.', 'job-listings' ) );
		}
	}


	if ( '' === $jbli_position )
	{
		$jbli_errors[] = array( 'field' => 'job_position', 'message' => __( 'Παρακαλώ συμπληρώστε τη θέση εργασίας.', 'job-listings' ) );
	}

	/* Short titles, e.g. "Φαρμακοποιός". */
	$jbli_max_title = jbli_title_max_chars();

	if ( jbli_strlen( $jbli_position ) > $jbli_max_title )
	{
		$jbli_errors[] = array(
			'field'   => 'job_position',
			/* translators: %d: maximum number of characters */
			'message' => sprintf( __( 'Ο τίτλος μπορεί να έχει έως %d χαρακτήρες.', 'job-listings' ), $jbli_max_title ),
		);
	}


	if ( '' === trim( wp_strip_all_tags( $jbli_description ) ) )
	{
		$jbli_errors[] = array( 'field' => 'job_description', 'message' => __( 'Παρακαλώ συμπληρώστε την περιγραφή της αγγελίας.', 'job-listings' ) );
	}

	$jbli_desc_plain = wp_strip_all_tags( $jbli_description );

	if ( function_exists( 'jbli_strlen' ) && jbli_strlen( $jbli_desc_plain ) > 3000 )
	{
		$jbli_errors[] = array( 'field' => 'job_description', 'message' => __( 'Η περιγραφή δεν μπορεί να υπερβαίνει τους 3000 χαρακτήρες.', 'job-listings' ) );
	}


	if ( ! $jbli_category_id || ! term_exists( $jbli_category_id, 'job_category' ) )
	{
		$jbli_errors[] = array( 'field' => 'job_category', 'message' => __( 'Παρακαλώ επιλέξτε έγκυρη κατηγορία εργασίας.', 'job-listings' ) );
	}


	if ( ! $jbli_nomos_id || ! term_exists( $jbli_nomos_id, 'job_nomos' ) )
	{
		$jbli_errors[] = array( 'field' => 'job_nomos', 'message' => __( 'Παρακαλώ επιλέξτε έγκυρο νομό.', 'job-listings' ) );
	}


	if ( '' === $jbli_contact_phone )
	{
		$jbli_errors[] = array( 'field' => 'job_contact_phone', 'message' => __( 'Παρακαλώ συμπληρώστε τηλέφωνο επικοινωνίας.', 'job-listings' ) );
	}

	$jbli_digits = preg_replace( '/\D/', '', $jbli_contact_phone );

	if ( ! is_string( $jbli_digits ) || strlen( $jbli_digits ) < 10 )
	{
		$jbli_errors[] = array( 'field' => 'job_contact_phone', 'message' => __( 'Το τηλέφωνο πρέπει να έχει τουλάχιστον 10 ψηφία.', 'job-listings' ) );
	}


	if ( '' === $jbli_contact_email )
	{
		$jbli_errors[] = array( 'field' => 'job_contact_email', 'message' => __( 'Παρακαλώ συμπληρώστε email επικοινωνίας.', 'job-listings' ) );
	}

	if ( ! is_email( $jbli_contact_email ) )
	{
		$jbli_errors[] = array( 'field' => 'job_contact_email', 'message' => __( 'Παρακαλώ συμπληρώστε έγκυρο email επικοινωνίας.', 'job-listings' ) );
	}


	if ( '' === $jbli_salary ) { $jbli_errors[] = array( 'field' => 'job_salary', 'message' => __( 'Παρακαλώ επιλέξτε αμοιβή.', 'job-listings' ) ); }

	if ( ! array_key_exists( $jbli_salary, jbli_salary_options( ) ) )
	{
		$jbli_errors[] = array( 'field' => 'job_salary', 'message' => __( 'Μη έγκυρη επιλογή αμοιβής.', 'job-listings' ) );
	}


	if ( '' === $jbli_type )
	{
		$jbli_errors[] = array( 'field' => 'job_type', 'message' => __( 'Παρακαλώ επιλέξτε τύπο απασχόλησης.', 'job-listings' ) );
	}

	if ( ! array_key_exists( $jbli_type, jbli_type_options( ) ) )
	{
		$jbli_errors[] = array( 'field' => 'job_type', 'message' => __( 'Μη έγκυρος τύπος απασχόλησης.', 'job-listings' ) );
	}


	if ( '' === $jbli_address )
	{
		$jbli_errors[] = array( 'field' => 'job_address', 'message' => __( 'Παρακαλώ συμπληρώστε τη διεύθυνση του φαρμακείου.', 'job-listings' ) );
	}

	if ( jbli_strlen( $jbli_address ) > 200 )
	{
		$jbli_errors[] = array( 'field' => 'job_address', 'message' => __( 'Η διεύθυνση δεν μπορεί να υπερβαίνει τους 200 χαρακτήρες.', 'job-listings' ) );
	}

	if ( ! empty( $jbli_errors ) )
	{
		jbli_form_error_fields(
			array_values(
				array_filter(
					wp_list_pluck( $jbli_errors, 'field' )
				)
			)
		);

		return jbli_notice(
			implode( ' ', wp_list_pluck( $jbli_errors, 'message' ) ),
			'error'
		);
	}

	jbli_form_error_fields( array() );

	return array(
		'jbli_position'      => $jbli_position,
		'description'        => $jbli_description,
		'jbli_salary'        => $jbli_salary,
		'jbli_type'          => $jbli_type,
		'jbli_contact_phone' => $jbli_contact_phone,
		'contact_email'      => $jbli_contact_email,
		'category_id'        => $jbli_category_id,
		'nomos_id'           => $jbli_nomos_id,
		'jbli_address'       => $jbli_address,
		'lat'                => $jbli_lat,
		'lng'                => $jbli_lng,
	);

}