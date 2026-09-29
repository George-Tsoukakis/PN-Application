<?php
/**
 * Expiry: Expiry & Reminder Actions
 *
 * Batch-processes listings that need expiring or reminder emails.
 * These functions are called by the WP-Cron hooks registered in jbli-cron.php.
 * Loaded by jbli-expiry.php.
 *
 * @package JobListings
 * @since   9.9.26
 */

defined( 'ABSPATH' ) || exit;

/**
 * Set expiry date when a listing is first published.
 *
 * Fires on transition_post_status. Only sets the expiry meta if it
 * doesn't already exist, so manual expiry dates are never overwritten.
 *
 * @param string  $jbli_new_status New post status.
 * @param string  $jbli_old_status Previous post status.
 * @param WP_Post $post       Post object.
 */
function jbli_set_expiry_on_publish( $jbli_new_status, $jbli_old_status, $post ) {

	if ( ! $post instanceof WP_Post ) { return; }

	$jbli_new_status = (string) $jbli_new_status;
	$jbli_old_status = (string) $jbli_old_status;

	if ( JBLI_CPT !== $post->post_type ) { return; }

	if ( 'publish' !== $jbli_new_status || 'publish' === $jbli_old_status ) { return; }

	if ( get_post_meta( $post->ID, JBLI_META_EXPIRES, true ) ) { return; }

	update_post_meta( $post->ID, JBLI_META_EXPIRES, jbli_future_datetime( 30 ) );

}

add_action( 'transition_post_status', 'jbli_set_expiry_on_publish', 10, 3 );

function jbli_run_expiry() {

	$jbli_batch_size = 50;
	$jbli_now        = current_time( 'mysql' );
	$jbli_max_runs   = 20;
	$jbli_runs       = 0;

	do {
		$jbli_ids = get_posts( array(
			'post_type'        => JBLI_CPT,
			'post_status'      => 'publish',
			'posts_per_page'   => $jbli_batch_size,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters
			'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'       => JBLI_META_EXPIRES,
					'value'     => $jbli_now,
					'compare'   => '<',
					'jbli_type' => 'DATETIME',
				),
			),
		) );

		foreach ( $jbli_ids as $jbli_id ) {

			jbli_expire_job( (int) $jbli_id );

		}

		$jbli_found = count( $jbli_ids );
		$jbli_runs++;
	} 
	while ( $jbli_found === $jbli_batch_size && $jbli_runs < $jbli_max_runs );

}

/**
 * Expire a single listing.
 *
 * Sets status to job-expired, flags the meta, syncs storage, sends email,
 * and fires the jbli_expired action.
 *
 * @param int $jbli_post_id Listing post ID.
 */
function jbli_expire_job( $jbli_post_id ) {

	$jbli_post_id = absint( $jbli_post_id );

	if ( JBLI_CPT !== get_post_type( $jbli_post_id ) ) { return; }

	if ( get_post_meta( $jbli_post_id, JBLI_META_EXPIRED, true ) ) { return; }

	$jbli_updated = wp_update_post(
		array( 'ID' => $jbli_post_id, 'post_status' => 'job-expired' ),
		true
	);

	if ( is_wp_error( $jbli_updated ) )
	{
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG )
		{
			error_log( sprintf(
				'[Job Listings] Failed expiring listing #%d: %s',
				$jbli_post_id,
				$jbli_updated->get_error_message()
			) );
		}

		return;
	}

	update_post_meta( $jbli_post_id, JBLI_META_EXPIRED, 1 );

	if ( function_exists( 'jbli_sync_listing_storage' ) ) { jbli_sync_listing_storage( $jbli_post_id ); }

	jbli_send_expiry_email( $jbli_post_id );

	do_action( 'jbli_expired', $jbli_post_id );

}

function jbli_run_expiry_reminders() {

	$jbli_target_start = jbli_future_datetime( 3, 'midnight' );
	$jbli_target_end   = jbli_future_datetime( 4, 'midnight' );

	$jbli_ids = get_posts( array(
		'post_type'        => JBLI_CPT,
		'post_status'      => 'publish',
		'posts_per_page'   => 100,
		'fields'           => 'ids',
		'no_found_rows'    => true,
		'suppress_filters' => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters
		'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'relation' => 'AND',
			array(
				'key'       => JBLI_META_EXPIRES,
				'value'     => array( $jbli_target_start, $jbli_target_end ),
				'compare'   => 'BETWEEN',
				'jbli_type' => 'DATETIME',
			),
			array( 'key'     => JBLI_META_REMINDER_SENT, 'compare' => 'NOT EXISTS', ),
		),
	) );

	foreach ( $jbli_ids as $jbli_id ) {

		$jbli_id = (int) $jbli_id;

		if ( get_post_meta( $jbli_id, JBLI_META_REMINDER_SENT, true ) ) { continue; }

		update_post_meta( $jbli_id, JBLI_META_REMINDER_SENT, 1 );

		$jbli_sent = jbli_send_reminder_email( $jbli_id );

		if ( false === $jbli_sent ) { delete_post_meta( $jbli_id, JBLI_META_REMINDER_SENT ); }

	}

}
