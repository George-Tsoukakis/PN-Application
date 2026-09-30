<?php
/**
 * Storage Queries
 *
 * Read-side helpers: counts, dashboard listing IDs, active listings.
 * All queries use the custom storage index for performance and bypass
 * the heavier WP_Query / post_author JOIN path.
 *
 * Extracted from jbli-storage.php for maintainability.
 *
 * @package JobListings
 * @since   9.9.22
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dashboard listing statuses.
 * Single source of truth — used by all query helpers below.
 *
 * @return string[]
 */
function jbli_dashboard_statuses() {

	return array( 'publish', 'draft', 'pending', 'job-expired' );

}

/**
 * Count active (published) listings for a pharmacy account.
 *
 * Caches for 5 minutes; flushed on any sync/delete/trash event.
 *
 * @param int $jbli_pharmacy_id Pharmacy row ID.
 * @return int
 */
function jbli_count_active_listings_by_pharmacy( $jbli_pharmacy_id ) {

	global $wpdb;

	$jbli_pharmacy_id = absint( $jbli_pharmacy_id );

	if ( $jbli_pharmacy_id <= 0 ) { return 0; }

	$jbli_cache_key = 'active_count_ph' . $jbli_pharmacy_id;
	$jbli_cached    = wp_cache_get( $jbli_cache_key, 'job-listings' );

	if ( false !== $jbli_cached ) { return (int) $jbli_cached; }

	$jbli_table = jbli_pharmacy_listings_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$jbli_count = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$jbli_table} WHERE jbli_pharmacy_id = %d AND jbli_status = %s",
			$jbli_pharmacy_id,
			'publish'
		)
	);

	wp_cache_set( $jbli_cache_key, $jbli_count, 'job-listings', 5 * MINUTE_IN_SECONDS );

	return $jbli_count;

}

/**
 * Count active listings by user_id.
 *
 * Kept for backward compatibility.
 * Prefer jbli_count_active_listings_by_pharmacy() for new code.
 *
 * @param int $jbli_user_id WordPress user ID.
 * @return int
 */
function jbli_count_active_listings_storage( $jbli_user_id ) {

	global $wpdb;

	$jbli_user_id = absint( $jbli_user_id );

	if ( $jbli_user_id <= 0 ) { return 0; }

	$jbli_cache_key = 'active_count_u' . $jbli_user_id;
	$jbli_cached    = wp_cache_get( $jbli_cache_key, 'job-listings' );

	if ( false !== $jbli_cached ) { return (int) $jbli_cached; }

	$jbli_table = jbli_pharmacy_listings_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$jbli_count = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$jbli_table} WHERE jbli_user_id = %d AND jbli_status = %s",
			$jbli_user_id,
			'publish'
		)
	);

	wp_cache_set( $jbli_cache_key, $jbli_count, 'job-listings', 5 * MINUTE_IN_SECONDS );

	return $jbli_count;

}

/**
 * Get dashboard listing post IDs for a user, ordered by updated_at DESC.
 *
 * Queries by pharmacy_id (preferred); falls back to user_id when no pharmacy
 * row exists yet. Pass the returned IDs to WP_Query via post__in to avoid
 * the full post_author JOIN on large tables.
 *
 * Note: currently 1 user = 1 pharmacy. Multi-pharmacy support is not yet
 * implemented — see jbli-storage.php / jbli_pharmacy_key().
 *
 * @since 9.9.0
 * @since 9.9.1  Queries by pharmacy_id instead of user_id.
 * @since 9.9.22 Moved to jbli-storage-queries.php.
 *
 * @param int $jbli_user_id WordPress user ID.
 * @param int $jbli_limit   Max rows to return (0 = no limit).
 * @return int[]
 */
function jbli_get_dashboard_listing_ids( $jbli_user_id, $jbli_limit = 100 ) {

	$jbli_user_id = absint( $jbli_user_id );

	if ( $jbli_user_id <= 0 ) { return array(); }

	$jbli_pharmacy_id = jbli_get_or_create_pharmacy_id( $jbli_user_id );

	if ( $jbli_pharmacy_id > 0 ) { return jbli_query_listing_ids_by_pharmacy( $jbli_pharmacy_id, $jbli_limit ); }


	return jbli_query_listing_ids_by_user( $jbli_user_id, $jbli_limit );

}

/**
 * Query helper: fetch listing IDs by pharmacy_id.
 *
 * @param int $jbli_pharmacy_id Pharmacy row ID.
 * @param int $jbli_limit       Max rows (0 = no limit).
 * @return int[]
 */
function jbli_query_listing_ids_by_pharmacy( $jbli_pharmacy_id, $jbli_limit ) {

	global $wpdb;

	$jbli_cache_key = 'listing_ids_ph' . $jbli_pharmacy_id;
	$jbli_cached    = wp_cache_get( $jbli_cache_key, 'job-listings' );

	$jbli_hit = jbli_cached_ids_for_limit( $jbli_cached, (int) $jbli_limit );

	if ( null !== $jbli_hit ) { return $jbli_hit; }

	$jbli_ids = jbli_fetch_listing_ids( 'jbli_pharmacy_id', $jbli_pharmacy_id, $jbli_limit );

	wp_cache_set( $jbli_cache_key, array( 'limit' => (int) $jbli_limit, 'ids' => $jbli_ids ), 'job-listings', 5 * MINUTE_IN_SECONDS );

	return $jbli_ids;

}

/**
 * Query helper: fetch listing IDs by user_id (fallback path).
 *
 * @param int $jbli_user_id WordPress user ID.
 * @param int $jbli_limit   Max rows (0 = no limit).
 * @return int[]
 */
function jbli_query_listing_ids_by_user( $jbli_user_id, $jbli_limit ) {

	global $wpdb;

	$jbli_cache_key = 'listing_ids_u' . $jbli_user_id;
	$jbli_cached    = wp_cache_get( $jbli_cache_key, 'job-listings' );

	$jbli_hit = jbli_cached_ids_for_limit( $jbli_cached, (int) $jbli_limit );

	if ( null !== $jbli_hit ) { return $jbli_hit; }

	$jbli_ids = jbli_fetch_listing_ids( 'jbli_user_id', $jbli_user_id, $jbli_limit );

	wp_cache_set( $jbli_cache_key, array( 'limit' => (int) $jbli_limit, 'ids' => $jbli_ids ), 'job-listings', 5 * MINUTE_IN_SECONDS );

	return $jbli_ids;

}

/**
 * Reuse a cached ID list only if it was fetched with a limit that covers this one.
 *
 * 9.9.57: the cache key had no limit, so a call with limit 10 could be
 * answered with a list cached for limit 100 (or the other way round).
 * The entry now remembers its limit; a smaller request is served by slicing.
 *
 * @param mixed $jbli_cached Cache value.
 * @param int   $jbli_limit  Requested limit (0 = no limit).
 * @return int[]|null IDs, or null on a miss.
 */
function jbli_cached_ids_for_limit( $jbli_cached, $jbli_limit ) {

	if ( ! is_array( $jbli_cached ) || ! isset( $jbli_cached['ids'], $jbli_cached['limit'] ) ) { return null; }

	$jbli_cached_limit = (int) $jbli_cached['limit'];
	$jbli_ids          = array_map( 'intval', (array) $jbli_cached['ids'] );

	if ( 0 === $jbli_cached_limit ) { return $jbli_limit > 0 ? array_slice( $jbli_ids, 0, $jbli_limit ) : $jbli_ids; }

	if ( $jbli_limit > 0 && $jbli_limit <= $jbli_cached_limit ) { return array_slice( $jbli_ids, 0, $jbli_limit ); }

	return null;

}

/**
 * Low-level query: SELECT post_id FROM listings table filtered by one column.
 *
 * @param string $jbli_column  'pharmacy_id' or 'user_id'.
 * @param int    $jbli_value   Column value to filter by.
 * @param int    $jbli_limit   Max rows (0 = no limit).
 * @return int[]
 */
function jbli_fetch_listing_ids( $jbli_column, $jbli_value, $jbli_limit ) {

	global $wpdb;

	$jbli_table        = jbli_pharmacy_listings_table();
	$jbli_statuses     = jbli_dashboard_statuses();
	$jbli_placeholders = implode( ', ', array_fill( 0, count( $jbli_statuses ), '%s' ) );
	$jbli_sql_args     = array_merge( array( $jbli_value ), $jbli_statuses );
	$jbli_limit_clause = $jbli_limit > 0 ? ' LIMIT ' . (int) $jbli_limit : '';


	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$jbli_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT jbli_post_id FROM {$jbli_table}
			  WHERE {$jbli_column} = %d
			    AND jbli_status IN ({$jbli_placeholders})
			  ORDER BY jbli_updated_at DESC" . $jbli_limit_clause,
			...$jbli_sql_args
		)
	);

	return array_map( 'intval', (array) $jbli_ids );

}
