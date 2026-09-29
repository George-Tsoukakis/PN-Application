<?php

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

wp_clear_scheduled_hook( 'jbli_hourly_cron' );
wp_clear_scheduled_hook( 'jbli_daily_reminder_cron' );
flush_rewrite_rules();

if ( ! (bool) get_option( 'jbli_delete_on_uninstall', false ) )
{
	return;
}

global $wpdb;

$jbli_post_ids = array_map(
	'absint',
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	(array) $wpdb->get_col(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s",
			'job_listing'
		)
	)
);

if ( ! empty( $jbli_post_ids ) )
{
	$jbli_batch_size = 200;
	$jbli_chunks     = array_chunk( $jbli_post_ids, $jbli_batch_size );

	foreach ( $jbli_chunks as $jbli_chunk ) {

		$jbli_placeholders = implode( ', ', array_fill( 0, count( $jbli_chunk ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$jbli_placeholders})", ...$jbli_chunk ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ({$jbli_placeholders})", ...$jbli_chunk ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->posts} WHERE ID IN ({$jbli_placeholders})", ...$jbli_chunk ) );

	}

	foreach ( $jbli_post_ids as $jbli_pid ) {

		clean_post_cache( $jbli_pid );

	}
}

$jbli_prefix = esc_sql(
	$wpdb->prefix
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS `{$jbli_prefix}jbli_pharmacy_listings`" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS `{$jbli_prefix}jbli_pharmacies`" );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS `{$jbli_prefix}jbli_apply_submissions`" );

/* Current table names (schema 1.3.0+); the plural names above are the pre-1.3.0 ones. */
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS `{$jbli_prefix}jbli_pharmacy_listing`" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS `{$jbli_prefix}jbli_pharmacy`" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS `{$jbli_prefix}jbli_apply_submission`" );

$jbli_options = array(
	'jbli_version',
	'jbli_db_version',
	'jbli_delete_on_uninstall',
	'jbli_asset_bust',
	'jbli_dashboard_page_id',
	'jbli_form_page_id',
	'jbli_form_media_url',
	'jbli_title_max_chars',
	'jbli_listings_page_id',
	'jbli_apply_email_subject',
	'jbli_apply_email_body',
);

foreach ( $jbli_options as $jbli_option ) {

	delete_option( $jbli_option );

}

delete_transient( 'jbli_listings_page_url' );
delete_transient( 'jbli_form_page_url' );
delete_transient( 'jbli_dashboard_page_url' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s
		 OR option_name LIKE %s OR option_name LIKE %s
		 OR option_name LIKE %s OR option_name LIKE %s",
		"_transient_jbli_view_%",
		"_transient_timeout_jbli_view_%",
		"_transient_jbli_rate_%",
		"_transient_timeout_jbli_rate_%",
		"_transient_jbli_edit_rate_%",
		"_transient_timeout_jbli_edit_rate_%"
	)
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		"_transient_jbli_apply_%",
		"_transient_timeout_jbli_apply_%"
	)
);

$jbli_meta_keys = array(
	'jbli_position',
	'jbli_pharmacy_name',
	'jbli_address',
	'jbli_lat',
	'jbli_lng',
	'jbli_type',
	'jbli_salary',
	'jbli_contact_phone',
	'jbli_expires',
	'jbli_expired',
	'jbli_reminder_sent',
	'jbli_featured',
	'jbli_views',
	'jbli_email',
);

foreach ( $jbli_meta_keys as $jbli_key ) {

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $jbli_key ), array( '%s' ) );

}
