<?php
/**
 * Form Rate Limiting
 *
 * Enforces per-user submission limits:
 *   - Max active listings cap (new submissions only).
 *   - Transient-based cooldown to prevent rapid double-submits.
 *
 * Returns null when the user may proceed, or an error notice HTML string
 * when a limit is exceeded. Does not redirect.
 *
 * Loaded by jbli-form.php.
 *
 * @package JobListings
 * @since   9.9.22
 */

defined( 'ABSPATH' ) || exit;

/**
 * Count active published listings for a user via WP_Query.
 *
 * Fallback used only when the storage layer is unavailable.
 * Caps the query at ($jbli_max_active + 1) rows so the limit check is correct
 * even without an exact count.
 *
 * @since 9.9.0  Fixed posts_per_page to allow counts beyond 1.
 * @since 9.9.22 Moved to form-parts/jbli-rate-limit.php.
 *
 * @param int $jbli_user_id WordPress user ID.
 * @return int
 */
function jbli_count_active_listings_for_user( $jbli_user_id ) {

	$jbli_user_id    = absint( $jbli_user_id );
	$jbli_max_active = (int) apply_filters( 'jbli_max_active_per_user', 5 );

	$jbli_query = new WP_Query(
		array(
			'post_type'              => JBLI_CPT,
			'post_status'            => 'publish',
			'author'                 => $jbli_user_id,
			'posts_per_page'         => $jbli_max_active + 1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);

	return is_array( $jbli_query->posts ) ? count( $jbli_query->posts ) : 0;

}

/**
 * Resolve the current active listing count for a user.
 *
 * Prefers the storage index (by pharmacy_id); falls back to user_id index,
 * then to a direct WP_Query as a last resort.
 *
 * @param int $jbli_user_id WordPress user ID.
 * @return int
 */
function jbli_resolve_active_count( $jbli_user_id ) {

	$jbli_pharmacy_id = function_exists( 'jbli_get_or_create_pharmacy_id' )
		? jbli_get_or_create_pharmacy_id( $jbli_user_id )
		: 0;

	if ( $jbli_pharmacy_id > 0 && function_exists( 'jbli_count_active_listings_by_pharmacy' ) )
	{
		return jbli_count_active_listings_by_pharmacy( $jbli_pharmacy_id );
	}

	if ( function_exists( 'jbli_count_active_listings_storage' ) )
	{
		return jbli_count_active_listings_storage( $jbli_user_id );
	}

	return jbli_count_active_listings_for_user( $jbli_user_id );

}

/**
 * Whether a listing owner already has the maximum number of active listings.
 *
 * Used by the dashboard «Ενεργοποίηση» / «Ανανέωση» actions, so the cap
 * cannot be bypassed by pausing a listing, creating a new one and
 * re-activating the paused one. Admins are never capped.
 *
 * @since 9.9.57
 *
 * @param int $jbli_user_id Owner user ID.
 * @return bool
 */
function jbli_owner_at_active_cap( $jbli_user_id ) {

	if ( jbli_is_admin() ) { return false; }

	$jbli_max_active = (int) apply_filters( 'jbli_max_active_per_user', 5 );

	return jbli_resolve_active_count( absint( $jbli_user_id ) ) >= $jbli_max_active;

}

/**
 * Check all rate limits for a form submission.
 *
 * Checks the active-listings cap and the cooldown transient.
 * Returns null when the user may proceed, or an error notice HTML string
 * when a limit is exceeded.
 *
 * @param int  $jbli_user_id WordPress user ID.
 * @param bool $jbli_is_edit True for edits, false for new submissions.
 * @return string|null Error notice HTML, or null if within limits.
 */
function jbli_check_rate_limits( $jbli_user_id, $jbli_is_edit ) {


	if ( jbli_is_admin( ) ) { return null; }

	if ( ! $jbli_is_edit )
	{

		$jbli_max_active   = (int) apply_filters( 'jbli_max_active_per_user', 5 );
		$jbli_active_count = jbli_resolve_active_count( $jbli_user_id );

		if ( $jbli_active_count >= $jbli_max_active )
		{
			return jbli_notice(
				sprintf(
					/* translators: %d: maximum number of active listings */
					__( 'Έχετε φτάσει το μέγιστο όριο ενεργών αγγελιών (%d). Απενεργοποιήστε ή διαγράψτε μια υπάρχουσα πρώτα.', 'job-listings' ),
					$jbli_max_active
				),
				'error'
			);
		}


		$jbli_rate_key = 'jbli_rate_' . $jbli_user_id;

		if ( get_transient( $jbli_rate_key ) )
		{
			return jbli_notice(
				__( 'Παρακαλώ περιμένετε λίγα δευτερόλεπτα πριν υποβάλετε νέα αγγελία.', 'job-listings' ),
				'error'
			);
		}

		set_transient( $jbli_rate_key, 1, MINUTE_IN_SECONDS );

	} else {


		$jbli_edit_rate_key = 'jbli_edit_rate_' . $jbli_user_id;

		if ( get_transient( $jbli_edit_rate_key ) )
		{
			return jbli_notice(
				__( 'Παρακαλώ περιμένετε λίγα δευτερόλεπτα πριν αποθηκεύσετε ξανά.', 'job-listings' ),
				'error'
			);
		}

		set_transient( $jbli_edit_rate_key, 1, 30 );
	}

	return null;

}

/**
 * Release the cooldown set by jbli_check_rate_limits().
 *
 * Called when the save fails before anything was written, so the user is
 * not told to wait before retrying a submission that never went through.
 *
 * @param int  $jbli_user_id WordPress user ID.
 * @param bool $jbli_is_edit True for edits, false for new submissions.
 * @return void
 */
function jbli_clear_rate_limit( $jbli_user_id, $jbli_is_edit ) {

	delete_transient( ( $jbli_is_edit ? 'jbli_edit_rate_' : 'jbli_rate_' ) . absint( $jbli_user_id ) );

}
