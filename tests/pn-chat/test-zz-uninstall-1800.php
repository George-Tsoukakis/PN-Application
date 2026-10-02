<?php
/**
 * 1.8.0 uninstall: the counters table and the API key always go; the data
 * goes only with «keep on uninstall» off. Runs last: it re-creates the
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

update_option( 'pnchat_ai_key', 'sk-ant-test-0000000000000000000000', false );
pnt_settings( array( 'keep_on_uninstall' => 1 ) );
pnt_uninstall();
pnt_check( ! $exists( PNChat_Counter::table() ), 'keep on: counters table dropped' );
pnt_same( false, get_option( 'pnchat_ai_key' ), 'keep on: API key deleted' );
pnt_check( $exists( PNChat_Store::entries_table() ) && $exists( PNChat_Store::questions_table() ), 'keep on: brain and questions kept' );

PNChat_Store::install();
PNChat_Settings::save( array_merge( PNChat_Settings::get(), array( 'keep_on_uninstall' => 0 ) ) );
pnt_uninstall();
pnt_check( ! $exists( PNChat_Store::entries_table() ) && ! $exists( PNChat_Store::questions_table() ), 'keep off: tables dropped' );
pnt_same( false, get_option( 'pnchat_settings' ), 'keep off: settings deleted' );

pnt_done();
