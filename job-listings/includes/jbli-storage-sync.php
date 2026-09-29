<?php
/**
 * Storage Sync
 *
 * Keeps the custom storage index (wpjbli_pharmacy_listings) in sync with
 * WordPress CPT saves, deletes, and trash events.
 *
 * Extracted from jbli-storage.php for maintainability.
 *
 * @package JobListings
 * @since   9.9.22
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sync a single listing to the per-pharmacy storage index.
 *
 * Creates the row if it doesn't exist; updates it otherwise.
 * Loads all post meta in one call to avoid N separate get_post_meta() hits.
 *
 * @param int $jbli_post_id Post ID.
 */
function jbli_sync_listing_storage( $jbli_post_id ) {

	global $wpdb;

	$jbli_post_id = absint( $jbli_post_id );
	$post         = get_post( $jbli_post_id );

	if ( ! $post instanceof WP_Post || JBLI_CPT !== $post->post_type || 'auto-draft' === $post->post_status )
	{
		return;
	}

	$jbli_user_id     = (int) $post->post_author;
	$jbli_pharmacy_id = jbli_get_or_create_pharmacy_id( $jbli_user_id );

	if ( $jbli_pharmacy_id <= 0 ) { return; }

	$jbli_table    = jbli_pharmacy_listings_table();
	$jbli_now      = current_time( 'mysql' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$jbli_existing = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$jbli_table} WHERE jbli_post_id = %d LIMIT 1",
			$jbli_post_id
		)
	);


	$jbli_all_meta = get_post_meta( $jbli_post_id );

	$jbli_snapshot = wp_json_encode(
		array(
			'jbli_position'      => (string) ( $jbli_all_meta[ JBLI_META_POSITION ][0]      ?? '' ),
			'jbli_pharmacy_name' => (string) ( $jbli_all_meta[ JBLI_META_PHARMACY_NAME ][0] ?? '' ),
			'jbli_salary'        => (string) ( $jbli_all_meta[ JBLI_META_SALARY ][0]        ?? '' ),
			'jbli_type'          => (string) ( $jbli_all_meta[ JBLI_META_TYPE ][0]          ?? '' ),
			'jbli_email'         => (string) ( $jbli_all_meta[ JBLI_META_EMAIL ][0]         ?? '' ),
			'expires'            => (string) ( $jbli_all_meta[ JBLI_META_EXPIRES ][0]       ?? '' ),
		),
		JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
	);

	$jbli_data = array(
		'jbli_pharmacy_id' => $jbli_pharmacy_id,
		'jbli_user_id'     => $jbli_user_id,
		'jbli_post_id'     => $jbli_post_id,
		'jbli_status'      => $post->post_status,
		'jbli_title'       => (string) $post->post_title,
		'jbli_snapshot'    => is_string( $jbli_snapshot ) ? $jbli_snapshot : '{}',
		'jbli_updated_at'  => $jbli_now,
	);

	$jbli_formats = array( '%d', '%d', '%d', '%s', '%s', '%s', '%s' );

	if ( $jbli_existing > 0 )
	{
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $jbli_table, $jbli_data, array( 'id' => $jbli_existing ), $jbli_formats, array( '%d' ) );
	} else {
		$jbli_data['jbli_created_at'] = $jbli_now;
		$jbli_formats[]          = '%s';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert( $jbli_table, $jbli_data, $jbli_formats );
	}

	jbli_flush_pharmacy_cache( $jbli_pharmacy_id, $jbli_user_id );

}

/**
 * Hook: sync storage when a listing is saved from WP admin or front end.
 *
 * save_post_{CPT} covers all saves and status changes.
 * transition_post_status was removed to prevent double sync.
 *
 * @param int     $jbli_post_id Post ID.
 * @param WP_Post $post    Post object.
 * @param bool    $jbli_update  True on update, false on insert.
 */
function jbli_sync_storage_on_save( $jbli_post_id, $post, $jbli_update ) {

	unset( $jbli_update );

	if ( wp_is_post_autosave( $jbli_post_id ) || wp_is_post_revision( $jbli_post_id ) ) { return; }

	if ( JBLI_CPT !== $post->post_type ) { return; }

	jbli_sync_listing_storage( $jbli_post_id );

}

add_action( 'save_post_' . JBLI_CPT, 'jbli_sync_storage_on_save', 20, 3 );

/**
 * Hook: remove storage record when a listing is permanently deleted.
 *
 * @param int $jbli_post_id Post ID.
 */
function jbli_delete_storage_on_delete( $jbli_post_id ) {

	global $wpdb;

	$post = get_post( $jbli_post_id );

	if ( ! $post instanceof WP_Post || JBLI_CPT !== $post->post_type ) { return; }

	$jbli_table = jbli_pharmacy_listings_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$jbli_row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, jbli_pharmacy_id, jbli_user_id FROM {$jbli_table} WHERE jbli_post_id = %d LIMIT 1",
			$jbli_post_id
		)
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->delete( $jbli_table, array( 'jbli_post_id' => $jbli_post_id ), array( '%d' ) );

	if ( $jbli_row ) { jbli_flush_pharmacy_cache( (int) $jbli_row->jbli_pharmacy_id, (int) $jbli_row->jbli_user_id ); }

}

add_action( 'before_delete_post', 'jbli_delete_storage_on_delete', 10, 1 );

/**
 * Hook: mark storage record as trashed when a listing is trashed.
 *
 * @param int $jbli_post_id Post ID.
 */
function jbli_trash_storage_on_trash( $jbli_post_id ) {

	global $wpdb;

	$post = get_post( $jbli_post_id );

	if ( ! $post instanceof WP_Post || JBLI_CPT !== $post->post_type ) { return; }

	$jbli_table = jbli_pharmacy_listings_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$jbli_row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, jbli_pharmacy_id, jbli_user_id FROM {$jbli_table} WHERE jbli_post_id = %d LIMIT 1",
			$jbli_post_id
		)
	);

	if ( ! $jbli_row ) { return; }

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->update(
		$jbli_table,
		array( 'jbli_status'     => 'trash', 'jbli_updated_at' => current_time( 'mysql' ), ),
		array( 'jbli_post_id' => $jbli_post_id ),
		array( '%s', '%s' ),
		array( '%d' )
	);

	jbli_flush_pharmacy_cache( (int) $jbli_row->jbli_pharmacy_id, (int) $jbli_row->jbli_user_id );

}

add_action( 'trashed_post', 'jbli_trash_storage_on_trash', 10, 1 );
