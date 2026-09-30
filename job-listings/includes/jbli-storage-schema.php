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
 *  1.3.0 — tables and columns renamed with the jbli_ prefix (new tables,
 *           created by dbDelta with all keys; nothing is copied over).
 *  1.3.1 — upgrade path for 1.3.0: rename legacy _job_* post meta and
 *           rebuild the storage index from the listing posts.
 *  1.4.0 — jbli_status (pending/sent/failed) and an email key on
 *           jbli_apply_submission (email delivery + privacy export/erase).
 */
defined( 'JBLI_DB_VERSION' ) || define( 'JBLI_DB_VERSION', '1.4.0' );

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
		jbli_status VARCHAR(10) NOT NULL DEFAULT 'sent',
		PRIMARY KEY  (id),
		KEY jbli_post_id (jbli_post_id),
		KEY jbli_submitted_at (jbli_submitted_at),
		KEY jbli_applicant_email (jbli_applicant_email(100))
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
 * Run the migrations dbDelta cannot handle.
 *
 * Since 1.3.0 the storage lives in new jbli_* tables that dbDelta creates
 * with every key, so the pre-1.3.0 index/email migrations (which used the
 * old column names and only produced SQL errors) are gone. What is left is
 * carrying existing listings over to the new keys and tables.
 *
 * @param string $jbli_from_version The DB version stored before this update.
 */
function jbli_run_migrations( $jbli_from_version ) {

	$jbli_from_version = (string) $jbli_from_version;

	if ( '' !== $jbli_from_version && version_compare( $jbli_from_version, '1.3.1', '>=' ) ) { return; }

	if ( function_exists( 'jbli_migrate_meta_key_prefix' ) ) { jbli_migrate_meta_key_prefix(); }

	/* The backfill queries the CPT, so it has to wait for init. */
	if ( did_action( 'init' ) )
	{
		jbli_run_storage_backfill_migration();
	} else {
		add_action( 'init', 'jbli_run_storage_backfill_migration', 99 );
	}

}

/**
 * Rebuild the storage index (dashboards, active-listing counts) from posts.
 *
 * @return void
 */
function jbli_run_storage_backfill_migration() {

	if ( ! function_exists( 'jbli_backfill_storage' ) ) { return; }

	$jbli_result = jbli_backfill_storage();

	if ( empty( $jbli_result['success'] ) && defined( 'WP_DEBUG' ) && WP_DEBUG )
	{
		error_log( '[Job Listings] storage backfill after upgrade failed: ' . (string) ( $jbli_result['error'] ?? '' ) );
	}

}
