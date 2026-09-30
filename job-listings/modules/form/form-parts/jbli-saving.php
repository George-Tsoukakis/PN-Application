<?php
/**
 * Form Saving
 *
 * Persists a validated listing to the database:
 *   1. Inserts or updates the WP post record.
 *   2. Sets taxonomy terms (category, nomos).
 *   3. Writes post meta (position, salary, type, phone, address, coords…).
 *   4. Initialises expiry and featured meta on new listings.
 *
 * All functions here write to the DB; they do not validate, redirect, or
 * interact with caches — those concerns belong to jbli-validation.php and
 * process_form_submit() in jbli-form.php.
 *
 * Loaded by jbli-form.php.
 *
 * @package JobListings
 * @since   9.9.22
 */

defined( 'ABSPATH' ) || exit;

/**
 * Build the human-readable listing title from pharmacy, position, and nomos.
 *
 * Extracted here (from jbli-form.php) so jbli-saving.php is self-contained.
 *
 * @param string $jbli_pharmacy Pharmacy display name.
 * @param string $jbli_position Job position name.
 * @param string $jbli_nomos    Region/nomos name (optional).
 * @return string
 */
function jbli_build_listing_title( $jbli_pharmacy, $jbli_position, $jbli_nomos = '' ) {

	$jbli_pharmacy = (string) $jbli_pharmacy;
	$jbli_position = (string) $jbli_position;
	$jbli_nomos    = (string) $jbli_nomos;

	$jbli_title = sprintf(
		/* translators: 1: Pharmacy name, 2: Job position */
		__( 'Φαρμακείο: %1$s — Αναζητά %2$s', 'job-listings' ),
		$jbli_pharmacy ?: __( 'Φαρμακείο', 'job-listings' ),
		$jbli_position
	);

	if ( $jbli_nomos ) { $jbli_title .= ' | ' . sanitize_text_field( $jbli_nomos ); }

	return (string) apply_filters(
		'jbli_generated_title',
		$jbli_title,
		$jbli_pharmacy,
		$jbli_position,
		$jbli_nomos
	);

}

/**
 * Insert or update the WP post record for a listing.
 *
 * @since 9.9.20
 * @since 9.9.22 Moved to form-parts/jbli-saving.php.
 *
 * @param int          $jbli_edit_id     0 = new post; >0 = post to update.
 * @param array        $jbli_fields      Validated fields from jbli_validate_form_data().
 * @param bool         $jbli_was_expired Whether the listing being edited was expired.
 * @param WP_Post|null $jbli_edit_post   The existing WP_Post (null for new listings).
 * @param int          $jbli_user_id     Current user ID.
 * @return int|WP_Error Post ID on success, WP_Error on failure.
 */
function jbli_save_listing_post( $jbli_edit_id, array $jbli_fields, $jbli_was_expired, $jbli_edit_post, $jbli_user_id ) {

	/*
	 * 9.9.57: the title carries the listing owner's pharmacy (an admin editing
	 * someone's listing used to put their own name in it). Imported listings
	 * keep the pharmacy name found in the ad.
	 */
	$jbli_owner_id = $jbli_edit_post instanceof WP_Post ? (int) $jbli_edit_post->post_author : (int) $jbli_user_id;
	$jbli_pharmacy = jbli_get_pharmacy_name( $jbli_owner_id );

	if ( $jbli_edit_id && function_exists( 'jbli_listing_is_imported' ) && jbli_listing_is_imported( $jbli_edit_id ) )
	{
		$jbli_imported_name = (string) get_post_meta( $jbli_edit_id, JBLI_META_PHARMACY_NAME, true );

		if ( '' !== $jbli_imported_name ) { $jbli_pharmacy = $jbli_imported_name; }
	}

	$jbli_nomos_term = get_term( (int) $jbli_fields['nomos_id'], 'job_nomos' );
	$jbli_nomos_name = $jbli_nomos_term instanceof WP_Term ? $jbli_nomos_term->name : '';
	$jbli_title      = jbli_build_listing_title( $jbli_pharmacy, $jbli_fields['jbli_position'], $jbli_nomos_name );

	$jbli_post_data = array(
		'post_type'    => JBLI_CPT,
		'post_title'   => $jbli_title,
		'post_content' => $jbli_fields['description'],
	);

	if ( $jbli_edit_id )
	{
		$jbli_post_data['ID']          = $jbli_edit_id;
		$jbli_post_data['post_status'] = $jbli_was_expired
			? 'job-expired'
			: ( $jbli_edit_post instanceof WP_Post ? $jbli_edit_post->post_status : 'publish' );

		if ( function_exists( 'jbli_build_post_slug' ) )
		{
			$jbli_post_data['post_name'] = jbli_build_post_slug( $jbli_title, $jbli_edit_id );
		}

		return wp_update_post( $jbli_post_data, true );
	}


	/*
	 * 9.9.57: created as a draft; jbli_process_form_submit() publishes it
	 * only after the terms and meta are saved, so a failure half-way never
	 * leaves a public listing without category, area or contact details.
	 */
	$jbli_post_data['post_status'] = 'draft';
	$jbli_post_data['post_author'] = $jbli_user_id;

	if ( function_exists( 'jbli_build_post_slug' ) )
	{
		$jbli_post_data['post_name'] = jbli_build_post_slug( $jbli_title, 0 );
	}

	return wp_insert_post( $jbli_post_data, true );

}

/**
 * Save taxonomy terms and post meta for a listing.
 *
 * Only writes a meta value when it has actually changed to avoid
 * unnecessary DB writes on every edit.
 *
 * @since 9.9.20
 * @since 9.9.22 Moved to form-parts/jbli-saving.php.
 * @since 9.9.35 Added $jbli_owner_id param so admin edits don't overwrite pharmacy name.
 *               Email is now saved from the form field, not the account profile.
 *
 * @param int   $jbli_post_id  Listing post ID.
 * @param array $jbli_fields   Validated fields from jbli_validate_form_data().
 * @param int   $jbli_user_id  Current user ID (person submitting the form).
 * @param bool  $jbli_is_new   True when creating a new listing.
 * @param int   $jbli_owner_id Original post author ID (0 = same as $jbli_user_id).
 * @return string|null Error notice HTML on failure, null on success.
 */
function jbli_save_listing_meta( $jbli_post_id, array $jbli_fields, $jbli_user_id, $jbli_is_new, $jbli_owner_id = 0 ) {

	$jbli_post_id  = (int) $jbli_post_id;
	$jbli_owner_id = $jbli_owner_id > 0 ? (int) $jbli_owner_id : (int) $jbli_user_id;


	$jbli_category_result = wp_set_post_terms( $jbli_post_id, array( (int) $jbli_fields['category_id'] ), 'job_category' );

	if ( is_wp_error( $jbli_category_result ) )
	{
		return jbli_notice(
			sprintf(
				/* translators: %s: Error message */
				__( 'Σφάλμα αποθήκευσης κατηγορίας: %s', 'job-listings' ),
				esc_html( $jbli_category_result->get_error_message() )
			),
			'error'
		);
	}

	$jbli_nomos_result = wp_set_post_terms( $jbli_post_id, array( (int) $jbli_fields['nomos_id'] ), 'job_nomos' );

	if ( is_wp_error( $jbli_nomos_result ) )
	{
		return jbli_notice(
			sprintf(
				/* translators: %s: Error message */
				__( 'Σφάλμα αποθήκευσης νομού: %s', 'job-listings' ),
				esc_html( $jbli_nomos_result->get_error_message() )
			),
			'error'
		);
	}


	$jbli_pharmacy = jbli_get_pharmacy_name( $jbli_owner_id );

	/*
	 * 9.9.57: an imported listing belongs to the admin who imported it; keep
	 * the pharmacy name found in the ad instead of the admin's own.
	 */
	if ( function_exists( 'jbli_listing_is_imported' ) && jbli_listing_is_imported( $jbli_post_id ) )
	{
		$jbli_imported_name = (string) get_post_meta( $jbli_post_id, JBLI_META_PHARMACY_NAME, true );

		if ( '' !== $jbli_imported_name ) { $jbli_pharmacy = $jbli_imported_name; }
	}

	$jbli_meta_map = array(
		JBLI_META_POSITION      => $jbli_fields['jbli_position'],
		JBLI_META_SALARY        => $jbli_fields['jbli_salary'],
		JBLI_META_TYPE          => $jbli_fields['jbli_type'],
		JBLI_META_CONTACT_PHONE => $jbli_fields['jbli_contact_phone'],
		JBLI_META_EMAIL         => $jbli_fields['contact_email'],
		JBLI_META_PHARMACY_NAME => $jbli_pharmacy,
		JBLI_META_ADDRESS       => $jbli_fields['jbli_address'],
		JBLI_META_LAT           => $jbli_fields['lat'],
		JBLI_META_LNG           => $jbli_fields['lng'],
	);

	foreach ( $jbli_meta_map as $jbli_key => $jbli_value ) {

		if ( get_post_meta( $jbli_post_id, $jbli_key, true ) !== $jbli_value )
		{
			update_post_meta( $jbli_post_id, $jbli_key, $jbli_value );
		}

	}


	if ( $jbli_is_new && ! get_post_meta( $jbli_post_id, JBLI_META_EXPIRES, true ) && function_exists( 'jbli_future_datetime' ) )
	{
		update_post_meta( $jbli_post_id, JBLI_META_EXPIRES, jbli_future_datetime( 30 ) );
	}


	if ( '' === get_post_meta( $jbli_post_id, JBLI_META_FEATURED, true ) )
	{
		update_post_meta( $jbli_post_id, JBLI_META_FEATURED, 0 );
	}

	return null;

}