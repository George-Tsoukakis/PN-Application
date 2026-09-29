<?php
/**
 * Storage Migrations — Backfill
 *
 * Contains the backfill routine that syncs all existing CPT posts into the
 * custom storage index. Separated from the schema migration helpers
 * (jbli-storage-schema.php) because backfill is a large, admin-triggered operation,
 * not a lightweight startup check.
 *
 * @package JobListings
 * @since   9.9.22
 */

defined( 'ABSPATH' ) || exit;

/**
 * One-time migration: rename _job_* postmeta keys to jbli_* keys.
 *
 * Safe to run multiple times (idempotent).  Called automatically on plugin
 * load via jbli_run_migrations() when JBLI_DB_VERSION is bumped.
 *
 * @since 9.9.41
 */
function jbli_migrate_meta_key_prefix() {

	global $wpdb;

	$jbli_meta_map = array(
		'_job_position'      => 'jbli_position',
		'_job_pharmacy_name' => 'jbli_pharmacy_name',
		'_job_address'       => 'jbli_address',
		'_job_lat'           => 'jbli_lat',
		'_job_lng'           => 'jbli_lng',
		'_job_type'          => 'jbli_type',
		'_job_salary'        => 'jbli_salary',
		'_job_contact_phone' => 'jbli_contact_phone',
		'_job_expires'       => 'jbli_expires',
		'_job_expired'       => 'jbli_expired',
		'_job_reminder_sent' => 'jbli_reminder_sent',
		'_job_featured'      => 'jbli_featured',
		'_job_views'         => 'jbli_views',
		'_job_email'         => 'jbli_email',
	);

	foreach ( $jbli_meta_map as $jbli_old_key => $jbli_new_key ) {

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$jbli_exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1",
			$jbli_new_key
		) );

		if ( $jbli_exists ) { continue; }

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->postmeta} SET meta_key = %s WHERE meta_key = %s",
			$jbli_new_key,
			$jbli_old_key
		) );

	}

}

function jbli_seed_featured_meta_on_publish( string $jbli_new_status, string $jbli_old_status, WP_Post $post ): void {

	if ( 'publish' !== $jbli_new_status || $post->post_type !== JBLI_CPT ) { return; }


	if ( '' === get_post_meta( $post->ID, JBLI_META_FEATURED, true ) )
	{
		update_post_meta( $post->ID, JBLI_META_FEATURED, 0 );
	}

}

add_action( 'transition_post_status', 'jbli_seed_featured_meta_on_publish', 10, 3 );

function jbli_backfill_storage( $jbli_limit = 0 ) {

	global $wpdb;


	@set_time_limit( 300 );
	wp_raise_memory_limit( 'admin' );

	$jbli_batch_size = 100;
	$jbli_total      = 0;
	$jbli_offset     = 0;
	$jbli_last_error = '';

	do {
		$jbli_fetch = $jbli_limit > 0 ? min( $jbli_batch_size, $jbli_limit - $jbli_total ) : $jbli_batch_size;

		$jbli_ids = get_posts(
			array(
				'post_type'              => JBLI_CPT,
				'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'job-expired' ),
				'posts_per_page'         => $jbli_fetch,
				'offset'                 => $jbli_offset,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		if ( empty( $jbli_ids ) ) { break; }


		foreach ( $jbli_ids as $jbli_post_id ) {


			if ( '' === get_post_meta( (int ) $jbli_post_id, JBLI_META_FEATURED, true ) )
			{
				update_post_meta( (int) $jbli_post_id, JBLI_META_FEATURED, 0 );
			}

		}


		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'START TRANSACTION' );
		$jbli_batch_ok = true;

		foreach ( $jbli_ids as $jbli_post_id ) {

			jbli_sync_listing_storage( (int) $jbli_post_id );

			if ( $wpdb->last_error )
			{
				$jbli_batch_ok   = false;
				$jbli_last_error = $wpdb->last_error;
				break;
			}

		}

		if ( $jbli_batch_ok )
		{
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( 'COMMIT' );
			$jbli_total  += count( $jbli_ids );
			$jbli_offset += count( $jbli_ids );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( 'ROLLBACK' );

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG )
			{
				error_log(
					sprintf(
						'[Job Listings] backfill batch failed at offset %d: %s',
						$jbli_offset,
						$jbli_last_error
					)
				);
			}

			break;
		}

	} while ( count( $jbli_ids ) === $jbli_fetch && ( 0 === $jbli_limit || $jbli_total < $jbli_limit ) );

	return array( 'success' => '' === $jbli_last_error, 'synced'  => $jbli_total, 'error'   => $jbli_last_error, );

}
