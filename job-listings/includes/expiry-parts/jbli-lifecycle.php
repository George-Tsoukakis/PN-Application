<?php
/**
 * Expiry: Listing Lifecycle Helpers
 *
 * Canonical helpers for renewing, activating, and deactivating listings.
 * All listing status changes should go through these functions so storage
 * sync and action hooks fire consistently.
 *
 * Loaded by jbli-expiry.php.
 *
 * @package JobListings
 * @since   9.9.26
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return a future MySQL datetime string.
 *
 * @param int    $jbli_days Number of days from now.
 * @param string $jbli_time Optional time modifier (e.g. 'midnight').
 * @return string MySQL datetime (Y-m-d H:i:s).
 */
function jbli_future_datetime( $jbli_days, $jbli_time = '' ) {

	$jbli_days = absint( $jbli_days );
	$jbli_time = sanitize_text_field( (string) $jbli_time );

	$jbli_base = time();
	$jbli_spec = '+' . $jbli_days . ' days';

	if ( '' !== $jbli_time ) { $jbli_spec .= ' ' . $jbli_time; }

	$jbli_timestamp = strtotime( $jbli_spec, $jbli_base );

	if ( false === $jbli_timestamp ) { $jbli_timestamp = $jbli_base + ( $jbli_days * DAY_IN_SECONDS ); }

	return wp_date( 'Y-m-d H:i:s', $jbli_timestamp );

}

/**
 * Publish a listing and reset its 30-day expiry clock.
 *
 * Shared implementation used by both jbli_renew_job() and
 * jbli_activate_job() to eliminate duplicated logic.
 * wp_update_post() runs first; meta is only updated on success so the
 * listing is never left in an inconsistent state.
 *
 * @since 9.9.20
 * @since 9.9.26 Moved to expiry-parts/jbli-lifecycle.php.
 *
 * @param int    $jbli_post_id     Listing post ID (already absint'd by caller).
 * @param string $jbli_action_hook Action to fire on success.
 * @return true|WP_Error
 */
function jbli_publish_and_reset( $jbli_post_id, $jbli_action_hook ) {

	$jbli_updated = wp_update_post(
		array( 'ID' => $jbli_post_id, 'post_status' => 'publish' ),
		true
	);

	if ( is_wp_error( $jbli_updated ) )
	{
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG )
		{
			error_log( sprintf(
				'[Job Listings] Failed %s listing #%d: %s',
				$jbli_action_hook,
				$jbli_post_id,
				$jbli_updated->get_error_message()
			) );
		}

		return $jbli_updated;
	}

	delete_post_meta( $jbli_post_id, JBLI_META_EXPIRED );
	delete_post_meta( $jbli_post_id, JBLI_META_REMINDER_SENT );
	update_post_meta( $jbli_post_id, JBLI_META_EXPIRES, jbli_future_datetime( 30 ) );

	if ( function_exists( 'jbli_sync_listing_storage' ) ) { jbli_sync_listing_storage( $jbli_post_id ); }

	do_action( $jbli_action_hook, $jbli_post_id );

	return true;

}

/**
 * Renew a listing: publish it and reset the 30-day expiry clock.
 *
 * @since 9.9.4  Returns true|WP_Error (was void).
 * @since 9.9.20 Delegates to jbli_publish_and_reset().
 * @since 9.9.26 Moved to expiry-parts/jbli-lifecycle.php.
 *
 * @param int $jbli_post_id Listing post ID.
 * @return true|WP_Error
 */
function jbli_renew_job( $jbli_post_id ) {

	$jbli_post_id = absint( $jbli_post_id );

	if ( JBLI_CPT !== get_post_type( $jbli_post_id ) )
	{
		return new WP_Error( 'invalid_listing', __( 'Invalid listing.', 'job-listings' ) );
	}

	return jbli_publish_and_reset( $jbli_post_id, 'jbli_renewed' );

}

/**
 * Activate a listing: publish it, reset expiry clock, clear expired flags.
 *
 * Use for dashboard and admin approve actions.
 * Fires 'jbli_activated' instead of 'jbli_renewed'.
 *
 * @since 9.9.6
 * @since 9.9.20 Delegates to jbli_publish_and_reset().
 * @since 9.9.26 Moved to expiry-parts/jbli-lifecycle.php.
 *
 * @param int $jbli_post_id Listing post ID.
 * @return true|WP_Error
 */
function jbli_activate_job( $jbli_post_id ) {

	$jbli_post_id = absint( $jbli_post_id );

	if ( JBLI_CPT !== get_post_type( $jbli_post_id ) )
	{
		return new WP_Error( 'invalid_listing', __( 'Invalid listing.', 'job-listings' ) );
	}

	return jbli_publish_and_reset( $jbli_post_id, 'jbli_activated' );

}

/**
 * Deactivate a listing: set status to draft and sync storage.
 *
 * @since 9.9.7
 * @since 9.9.26 Moved to expiry-parts/jbli-lifecycle.php.
 *
 * @param int $jbli_post_id Listing post ID.
 * @return true|WP_Error
 */
function jbli_deactivate_job( $jbli_post_id ) {

	$jbli_post_id = absint( $jbli_post_id );

	if ( JBLI_CPT !== get_post_type( $jbli_post_id ) )
	{
		return new WP_Error( 'invalid_listing', __( 'Invalid listing.', 'job-listings' ) );
	}

	$jbli_updated = wp_update_post(
		array( 'ID' => $jbli_post_id, 'post_status' => 'draft' ),
		true
	);

	if ( is_wp_error( $jbli_updated ) )
	{
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG )
		{
			error_log( sprintf(
				'[Job Listings] Failed deactivating listing #%d: %s',
				$jbli_post_id,
				$jbli_updated->get_error_message()
			) );
		}

		return $jbli_updated;
	}

	if ( function_exists( 'jbli_sync_listing_storage' ) ) { jbli_sync_listing_storage( $jbli_post_id ); }

	do_action( 'jbli_deactivated', $jbli_post_id );

	return true;

}
