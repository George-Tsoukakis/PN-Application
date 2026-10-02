<?php
/**
 * Runs when the plugin is deleted from the Plugins screen. Keeps the brain
 * and the questions unless "keep on uninstall" was switched off.
 *
 * @package PNChat
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_clear_scheduled_hook( 'pnchat_daily' );
wp_clear_scheduled_hook( 'pnchat_site_index' );
// The API key is a secret, not data: it never outlives the plugin.
delete_option( 'pnchat_ai_key' );

$pnchat_settings = get_option( 'pnchat_settings', array() );
$pnchat_keep     = ! is_array( $pnchat_settings ) || ! array_key_exists( 'keep_on_uninstall', $pnchat_settings ) || ! empty( $pnchat_settings['keep_on_uninstall'] );
if ( $pnchat_keep ) {
	return;
}

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuerySchemaChange
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i, %i', $wpdb->prefix . 'pnchat_entries', $wpdb->prefix . 'pnchat_questions' ) );
delete_post_meta_by_key( '_pnchat_tokens' );
foreach ( array( 'pnchat_settings', 'pnchat_db_version', 'pnchat_brain_version', 'pnchat_snapshots', 'pnchat_seeded', 'pnchat_settings_version', 'pnchat_site_index_started', 'pnchat_ai_usage' ) as $pnchat_opt ) {
	delete_option( $pnchat_opt );
}
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like( '_transient_pnchat_' ) . '%', $wpdb->esc_like( '_transient_timeout_pnchat_' ) . '%' ) );
