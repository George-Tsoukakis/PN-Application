<?php
/**
 * Storage Schema & Migrations
 *
 * Handles CREATE TABLE, dbDelta, and ALTER TABLE migrations for the
 * custom storage tables. Extracted from jbli-storage.php for maintainability.
 *
 * @package JobListings
 * @since   9.9.22
 */

defined( 'ABSPATH' ) || exit;

/**
 * Storage schema version history:
 *
 *  1.0.0 — Initial tables (pharmacies + pharmacy_listings).
 *  1.1.0 — Added composite KEY pharmacy_status (pharmacy_id, status)
 *           and KEY status (status) to wpjbli_pharmacy_listings.
 *  1.2.0 — email column on jbli_pharmacies upgraded from KEY to UNIQUE KEY.
 *  1.2.1 — email column changed to NULL DEFAULT NULL before UNIQUE KEY.
 */
defined( 'JBLI_DB_VERSION' ) || define( 'JBLI_DB_VERSION', '1.3.0' );

function jbli_install_storage() {

	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$jbli_charset_collate = $wpdb->get_charset_collate();
	$jbli_pharmacies      = jbli_pharmacies_table();
	$jbli_listings        = jbli_pharmacy_listings_table();

	$jbli_sql_pharmacies = "CREATE TABLE {$jbli_pharmacies} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		jbli_user_id BIGINT UNSIGNED NOT NULL,
		jbli_pharmacy_key CHAR(64) NOT NULL,
		jbli_name VARCHAR(190) NOT NULL DEFAULT '',
		jbli_email VARCHAR(190) NULL DEFAULT NULL,
		jbli_created_at DATETIME NOT NULL,
		jbli_updated_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY jbli_user_id (jbli_user_id),
		UNIQUE KEY jbli_pharmacy_key (jbli_pharmacy_key),
		UNIQUE KEY jbli_email (jbli_email)
	) {$jbli_charset_collate};";

	$jbli_sql_listings = "CREATE TABLE {$jbli_listings} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		jbli_pharmacy_id BIGINT UNSIGNED NOT NULL,
		jbli_user_id BIGINT UNSIGNED NOT NULL,
		jbli_post_id BIGINT UNSIGNED NOT NULL,
		jbli_status VARCHAR(20) NOT NULL DEFAULT '',
		jbli_title TEXT NOT NULL,
		jbli_snapshot LONGTEXT NULL,
		jbli_created_at DATETIME NOT NULL,
		jbli_updated_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY jbli_post_id (jbli_post_id),
		KEY jbli_pharmacy_status (jbli_pharmacy_id, jbli_status),
		KEY jbli_user_status (jbli_user_id, jbli_status),
		KEY jbli_status (jbli_status),
		KEY jbli_updated_at (jbli_updated_at)
	) {$jbli_charset_collate};";

	$jbli_had_buffer = ob_get_level();
	ob_start();

	dbDelta( $jbli_sql_pharmacies );
	dbDelta( $jbli_sql_listings );

	$jbli_submissions = jbli_apply_submissions_table();

	$jbli_sql_submissions = "CREATE TABLE {$jbli_submissions} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		jbli_post_id BIGINT UNSIGNED NOT NULL,
		jbli_applicant_name VARCHAR(190) NOT NULL DEFAULT '',
		jbli_applicant_phone VARCHAR(50) NOT NULL DEFAULT '',
		jbli_applicant_email VARCHAR(190) NOT NULL DEFAULT '',
		jbli_submitted_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY jbli_post_id (jbli_post_id),
		KEY jbli_submitted_at (jbli_submitted_at)
	) {$jbli_charset_collate};";

	dbDelta( $jbli_sql_submissions );

	if ( ob_get_level( ) > $jbli_had_buffer ) { ob_end_clean(); }


}

function jbli_maybe_install_storage() {

	$jbli_stored = get_option( 'jbli_db_version', '' );

	if ( JBLI_DB_VERSION === $jbli_stored ) { return; }

	jbli_install_storage();

	$jbli_had_buffer = ob_get_level();
	ob_start();

	jbli_run_migrations( $jbli_stored );

	if ( ob_get_level( ) > $jbli_had_buffer ) { ob_end_clean(); }

	update_option( 'jbli_db_version', JBLI_DB_VERSION, false );

}

/**
 * Run schema migrations that dbDelta cannot handle automatically.
 *
 * dbDelta adds missing columns/tables but does NOT add indexes to existing
 * tables. Each migration is guarded by an existence check so it is safe
 * to run repeatedly.
 *
 * @param string $jbli_from_version The DB version stored before this update.
 */
function jbli_run_migrations( $jbli_from_version ) {

	global $wpdb;

	$jbli_from_version = (string) $jbli_from_version;
	$jbli_listings     = jbli_pharmacy_listings_table();
	$jbli_pharmacies   = jbli_pharmacies_table();

	if ( '' === $jbli_from_version || version_compare( $jbli_from_version, '1.1.0', '<' ) )
	{
		jbli_migrate_add_listings_indexes( $jbli_listings );
	}

	if ( version_compare( $jbli_from_version, '1.2.1', '<' ) )
	{
		jbli_migrate_unique_email( $jbli_pharmacies, $jbli_listings );
	}

}

/**
 * Migration helper: add pharmacy_status and status indexes to listings table.
 *
 * @param string $jbli_listings Table name.
 */
function jbli_migrate_add_listings_indexes( $jbli_listings ) {

	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$jbli_has_pharmacy_status = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(1)
			 FROM information_schema.statistics
			 WHERE table_schema = DATABASE()
			   AND table_name   = %s
			   AND index_name   = 'pharmacy_status'",
			$jbli_listings
		)
	);

	if ( ! $jbli_has_pharmacy_status )
	{

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( "ALTER TABLE `{$jbli_listings}` ADD KEY `pharmacy_status` (`pharmacy_id`, `status`)" );
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$jbli_has_status = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(1)
			 FROM information_schema.statistics
			 WHERE table_schema = DATABASE()
			   AND table_name   = %s
			   AND index_name   = 'status'",
			$jbli_listings
		)
	);

	if ( ! $jbli_has_status )
	{

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( "ALTER TABLE `{$jbli_listings}` ADD KEY `status` (`status`)" );
	}

}

/**
 * Migration helper: enforce UNIQUE email on pharmacies table.
 *
 * Steps:
 *  1. Normalize empty emails → NULL (MySQL UNIQUE allows multiple NULLs).
 *  2. Make column nullable (dbDelta doesn't change nullability).
 *  3. Remove duplicate non-NULL emails, keeping the oldest row.
 *  4. Clean up orphaned pharmacy_listings rows after de-duplication.
 *  5. Upgrade the email index from KEY to UNIQUE KEY.
 *
 * @param string $jbli_pharmacies Table name.
 * @param string $jbli_listings   Table name.
 */
function jbli_migrate_unique_email( $jbli_pharmacies, $jbli_listings ) {

	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$wpdb->query( "UPDATE `{$jbli_pharmacies}` SET `email` = NULL WHERE `email` = ''" );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$wpdb->query( "ALTER TABLE `{$jbli_pharmacies}` MODIFY `email` VARCHAR(190) NULL DEFAULT NULL" );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$wpdb->query(
		"DELETE p1
		 FROM `{$jbli_pharmacies}` p1
		 INNER JOIN `{$jbli_pharmacies}` p2
		        ON p1.email = p2.email
		       AND p1.id > p2.id
		 WHERE p1.email IS NOT NULL
		   AND p1.email != ''"
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$wpdb->query(
		"DELETE pl
		   FROM `{$jbli_listings}` pl
		   LEFT JOIN `{$jbli_pharmacies}` p ON p.id = pl.pharmacy_id
		  WHERE pl.pharmacy_id IS NOT NULL
		    AND p.id IS NULL"
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$jbli_index_type = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT NON_UNIQUE
			 FROM information_schema.statistics
			 WHERE table_schema = DATABASE()
			   AND table_name   = %s
			   AND index_name   = 'jbli_email'
			 LIMIT 1",
			$jbli_pharmacies
		)
	);

	if ( '1' === (string ) $jbli_index_type )
	{
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( "ALTER TABLE `{$jbli_pharmacies}` DROP INDEX `email`, ADD UNIQUE KEY `email` (`email`)" );
	} 
	elseif ( null === $jbli_index_type ) 
	{
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( "ALTER TABLE `{$jbli_pharmacies}` ADD UNIQUE KEY `email` (`email`)" );
	}

}
