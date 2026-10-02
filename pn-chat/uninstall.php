<?php
/**
 * Runs when the plugin is deleted from the Plugins screen. Keeps the brain
 * and the questions unless "keep on uninstall" was switched off. On a
 * multisite network every site is cleaned with its own setting.
 *
 * @package PNChat
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! function_exists( 'pnchat_uninstall_site' ) ) {
	/**
	 * Cleans the current site.
	 *
	 * @return void
	 */
	function pnchat_uninstall_site() {
		global $wpdb;
		wp_clear_scheduled_hook( 'pnchat_daily' );
		wp_clear_scheduled_hook( 'pnchat_hourly' );
		wp_clear_scheduled_hook( 'pnchat_site_index' );
		wp_clear_scheduled_hook( 'pnchat_learn' );
		wp_clear_scheduled_hook( 'pnchat_learn_weekly' );
		// PDFs waiting to be read.
		$up  = wp_upload_dir( null, false );
		$dir = trailingslashit( (string) $up['basedir'] ) . 'pn-chat-learn';
		if ( is_dir( $dir ) ) {
			foreach ( array_merge( (array) glob( $dir . '/*' ), array( $dir . '/.htaccess' ) ) as $pnchat_file ) {
				wp_delete_file( (string) $pnchat_file );
			}
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
		delete_option( 'pnchat_learn_queue' );
		delete_option( 'pnchat_learn_state' );
		// The API key is a secret, not data: it never outlives the plugin.
		delete_option( 'pnchat_ai_key' );
		// Rate limits and AI totals are not data either.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuerySchemaChange
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'pnchat_counters' ) );

		$settings = get_option( 'pnchat_settings', array() );
		$keep     = ! is_array( $settings ) || ! array_key_exists( 'keep_on_uninstall', $settings ) || ! empty( $settings['keep_on_uninstall'] );
		if ( $keep ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuerySchemaChange
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i, %i, %i', $wpdb->prefix . 'pnchat_entries', $wpdb->prefix . 'pnchat_questions', $wpdb->prefix . 'pnchat_proposals' ) );
		delete_post_meta_by_key( '_pnchat_tokens' );
		delete_post_meta_by_key( '_pnchat_learned' );
		foreach ( array( 'pnchat_settings', 'pnchat_db_version', 'pnchat_brain_version', 'pnchat_snapshots', 'pnchat_seeded', 'pnchat_settings_version', 'pnchat_site_index_started', 'pnchat_ai_usage' ) as $opt ) {
			delete_option( $opt );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like( '_transient_pnchat_' ) . '%', $wpdb->esc_like( '_transient_timeout_pnchat_' ) . '%' ) );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $pnchat_site ) {
		switch_to_blog( (int) $pnchat_site );
		pnchat_uninstall_site();
		restore_current_blog();
	}
} else {
	pnchat_uninstall_site();
}
