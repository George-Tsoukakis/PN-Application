<?php
/**
 * Pharmacy Storage Layer
 *
 * Entry point for the custom storage layer. Defines shared primitives
 * (table names, cache flush, pharmacy identity) and then loads the
 * sub-modules that own each concern.
 *
 * Load order matters: primitives are defined first so every sub-module
 * can call them safely at parse time (hooks, default args, etc.).
 *
 * Sub-module layout:
 *   jbli-storage-schema.php      — CREATE TABLE, dbDelta, ALTER TABLE migrations.
 *   jbli-storage-sync.php        — save_post / delete / trash hooks + sync().
 *   jbli-storage-queries.php     — count helpers, dashboard listing ID queries.
 *   jbli-storage-migrations.php  — backfill routine (admin-triggered, batched).
 *
 * @package JobListings
 * @since   9.7.1
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return the fully-qualified pharmacies table name.
 *
 * @return string e.g. wpjbli_pharmacies
 */
function jbli_pharmacies_table() {

	global $wpdb;
	return $wpdb->prefix . 'jbli_pharmacy';

}

/**
 * Return the fully-qualified pharmacy-listings mapping table name.
 *
 * @return string e.g. wpjbli_pharmacy_listings
 */
function jbli_pharmacy_listings_table() {

	global $wpdb;
	return $wpdb->prefix . 'jbli_pharmacy_listing';

}

if ( ! function_exists( 'jbli_apply_submissions_table' ) )
{
	function jbli_apply_submissions_table(): string {

		global $wpdb;
		return $wpdb->prefix . 'jbli_apply_submission';

	}
}

/**
 * Flush all cached counts and listing IDs for a pharmacy / user pair.
 *
 * Call after any status change, sync, delete, renew, or activate so that
 * dashboard counts and listing-limit checks are always fresh.
 *
 * @since 9.9.0
 *
 * @param int $jbli_pharmacy_id Pharmacy row ID (pass 0 to skip).
 * @param int $jbli_user_id     WordPress user ID (pass 0 to skip).
 */
function jbli_flush_pharmacy_cache( $jbli_pharmacy_id = 0, $jbli_user_id = 0 ) {

	if ( $jbli_pharmacy_id > 0 )
	{
		wp_cache_delete( 'active_count_ph' . $jbli_pharmacy_id, 'job-listings' );
		wp_cache_delete( 'listing_ids_ph' . $jbli_pharmacy_id,  'job-listings' );
	}

	if ( $jbli_user_id > 0 )
	{
		wp_cache_delete( 'pharmacy_id_u' . $jbli_user_id,  'job-listings' );
		wp_cache_delete( 'active_count_u' . $jbli_user_id, 'job-listings' );
		wp_cache_delete( 'listing_ids_u' . $jbli_user_id,  'job-listings' );
	}

}

/**
 * Build a stable, site-scoped pharmacy key for a given user.
 *
 * Incorporates home_url() so a database migrated from staging cannot
 * accidentally collide with production keys.
 *
 * @param int $jbli_user_id WordPress user ID.
 * @return string 64-character hex string (SHA-256).
 */
function jbli_pharmacy_key( $jbli_user_id ) {

	$jbli_user_id = absint( $jbli_user_id );
	return hash( 'sha256', home_url( '/' ) . '|job-listings|' . $jbli_user_id );

}

/**
 * Get or create the pharmacy row for a given user.
 *
 * Cache hit  → returns immediately without a DB query.
 * Cache miss → checks for an existing row and creates one if needed.
 * Updates name/email only when they have actually changed to avoid a
 * gratuitous write on every listing save.
 *
 * @param int $jbli_user_id WordPress user ID.
 * @return int Pharmacy row ID, or 0 on failure.
 */
function jbli_get_or_create_pharmacy_id( $jbli_user_id ) {

	global $wpdb;

	$jbli_user_id = absint( $jbli_user_id );

	if ( $jbli_user_id <= 0 ) { return 0; }

	$jbli_cache_key = 'pharmacy_id_u' . $jbli_user_id;
	$jbli_cached    = wp_cache_get( $jbli_cache_key, 'job-listings' );

	if ( false !== $jbli_cached ) { return (int) $jbli_cached; }

	$jbli_table = jbli_pharmacies_table();
	$jbli_now   = current_time( 'mysql' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$jbli_existing = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$jbli_table} WHERE jbli_user_id = %d LIMIT 1",
			$jbli_user_id
		)
	);

	$jbli_name  = jbli_get_pharmacy_name( $jbli_user_id );
	$jbli_user  = get_userdata( $jbli_user_id );
	$jbli_email = (
		$jbli_user instanceof WP_User &&
		is_email( $jbli_user->user_email )
	)
		? $jbli_user->user_email
		: null;

	if ( $jbli_existing > 0 )
	{

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$jbli_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT jbli_name, jbli_email FROM {$jbli_table} WHERE id = %d LIMIT 1",
				$jbli_existing
			)
		);

		if ( ! $jbli_row || (string) $jbli_row->jbli_name !== (string) $jbli_name || $jbli_row->jbli_email !== $jbli_email )
		{
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				$jbli_table,
				array( 'jbli_name'       => $jbli_name, 'jbli_email'      => $jbli_email, 'jbli_updated_at' => $jbli_now, ),
				array( 'id' => $jbli_existing ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
		}

		wp_cache_set( $jbli_cache_key, $jbli_existing, 'job-listings', HOUR_IN_SECONDS );

		return $jbli_existing;
	}


	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$jbli_inserted = $wpdb->insert(
		$jbli_table,
		array(
			'jbli_user_id'      => $jbli_user_id,
			'jbli_pharmacy_key' => jbli_pharmacy_key( $jbli_user_id ),
			'jbli_name'         => $jbli_name,
			'jbli_email'   	    => $jbli_email,
			'jbli_created_at'   => $jbli_now,
			'jbli_updated_at'   => $jbli_now,
		),
		array( '%d', '%s', '%s', '%s', '%s', '%s' )
	);

	$jbli_new_id = $jbli_inserted ? (int) $wpdb->insert_id : 0;

	if ( $jbli_new_id > 0 ) { wp_cache_set( $jbli_cache_key, $jbli_new_id, 'job-listings', HOUR_IN_SECONDS ); }

	return $jbli_new_id;

}

require_once JBLI_DIR . 'includes/jbli-storage-schema.php';
require_once JBLI_DIR . 'includes/jbli-storage-sync.php';
require_once JBLI_DIR . 'includes/jbli-storage-queries.php';
require_once JBLI_DIR . 'includes/jbli-storage-migrations.php';
