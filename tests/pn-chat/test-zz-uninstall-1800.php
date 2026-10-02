<?php
/**
 * 1.8.0 uninstall: the counters table and the API key always go; the data
 * goes only with «keep on uninstall» off. 1.9.0: the reading queue and the
 * uploaded PDFs always go; the proposals with the data. Runs last: it re-creates the
 * tables and the starter brain afterwards. TEST-ONLY.
 */
require __DIR__ . '/lib.php';
global $wpdb;
$exists = function ( $table ) use ( $wpdb ) {
	return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
};
$saved = get_option( PNChat_Settings::OPTION );
pnt_defer(
	function () use ( $saved ) {
		update_option( PNChat_Settings::OPTION, $saved, false );
		PNChat_Store::install();
		if ( ! PNChat_Store::entries() ) {
			delete_option( 'pnchat_seeded' );
			PNChat_Seed::maybe_seed();
		}
	}
);
function pnt_uninstall() {
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		define( 'WP_UNINSTALL_PLUGIN', 'pn-chat/pn-chat.php' );
	}
	include PNCHAT_PATH . 'uninstall.php';
}

require_once ABSPATH . 'wp-admin/includes/file.php';
update_option( 'pnchat_ai_key', 'sk-ant-test-0000000000000000000000', false );
$tmp = wp_tempnam( 'pnt' );
file_put_contents( $tmp, "%PDF-1.4\n" );
$job = PNChat_Learn::store_upload( $tmp, 'a.pdf' );
wp_delete_file( $tmp );
PNChat_Learn::enqueue( array( $job ), 'a' );
pnt_settings( array( 'keep_on_uninstall' => 1 ) );
pnt_uninstall();
pnt_check( ! get_option( PNChat_Learn::QUEUE ) && ! is_dir( PNChat_Learn::dir() ) && ! wp_next_scheduled( PNChat_Learn::CRON ), 'keep on: reading queue, uploaded PDFs and their schedule gone' );
pnt_check( $exists( PNChat_Learn::table() ), 'keep on: proposals kept' );
pnt_check( ! $exists( PNChat_Counter::table() ), 'keep on: counters table dropped' );
pnt_same( false, get_option( 'pnchat_ai_key' ), 'keep on: API key deleted' );
pnt_check( $exists( PNChat_Store::entries_table() ) && $exists( PNChat_Store::questions_table() ), 'keep on: brain and questions kept' );

PNChat_Store::install();
PNChat_Settings::save( array_merge( PNChat_Settings::get(), array( 'keep_on_uninstall' => 0 ) ) );
pnt_uninstall();
pnt_check( ! $exists( PNChat_Store::entries_table() ) && ! $exists( PNChat_Store::questions_table() ) && ! $exists( PNChat_Learn::table() ), 'keep off: tables dropped (proposals too)' );
pnt_same( false, get_option( 'pnchat_settings' ), 'keep off: settings deleted' );

pnt_done();
