<?php
/**
 * Form Permissions
 *
 * Checks who can access the submission form and edit a specific listing.
 * Called by the shortcode renderer (render) and the submit handler (process).
 * Loaded by jbli-form.php.
 *
 * @package JobListings
 * @since   9.9.22
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return true if the current user may submit or edit listings.
 *
 * Admins always pass. Pharmacists pass when their account type is recognised.
 *
 * @return bool
 */
function jbli_form_user_can_submit() {

	return jbli_is_pharmacist() || jbli_is_admin();

}

/**
 * Resolve and authorise an edit request from $_GET['job_edit'].
 *
 * Returns the WP_Post to edit on success, a notice HTML string on any
 * failure (not found, wrong CPT, no permission).
 *
 * @return WP_Post|string WP_Post on success, error notice HTML on failure.
 */
function jbli_form_resolve_edit_post() {

	$jbli_candidate = absint( $_GET['job_edit'] ?? 0 );

	if ( ! $jbli_candidate ) { return jbli_notice( __( 'Η αγγελία δεν βρέθηκε.', 'job-listings' ), 'error' ); }

	$post = get_post( $jbli_candidate );

	if ( ! $post instanceof WP_Post || JBLI_CPT !== $post->post_type )
	{
		return jbli_notice( __( 'Η αγγελία δεν βρέθηκε.', 'job-listings' ), 'error' );
	}

	if ( ! jbli_can_edit_listing( $jbli_candidate ) )
	{
		return jbli_notice( __( 'Δεν έχετε άδεια επεξεργασίας αυτής της αγγελίας.', 'job-listings' ), 'error' );
	}

	return $post;

}
