<?php
/**
 * PlanDose 1.28.5 — the PHP fixes of the joint review. TEST-ONLY.
 *
 * - uninstall with «delete data» keeps everything while old invoices are
 *   still in the Media Library (not migrated, or originals not deleted);
 * - `wp plandose check` never picks an administrator on its own;
 * - the asset preflight checks calendar-qr.js, and pro-labels.js only
 *   for Pro accounts.
 */

require __DIR__ . '/lib.php';

global $wpdb;

// ---- uninstall.php, loaded without its run call ------------------------------------
$src = file_get_contents( PLANDOSE_PATH . 'uninstall.php' );
$src = preg_replace( '/^<\?php/', '', $src );
$src = str_replace( "if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {\n\texit;\n}", '', $src );
$src = preg_replace( '/\nplandose_run_uninstall\(\);\s*$/', "\n", $src );
pdt_check( false === strpos( $src, "\nplandose_run_uninstall();" ), 'uninstall.php loaded without its run call' );
eval( $src ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test only.

$settings_before  = get_option( 'plandose_settings' );
$originals_before = get_option( 'plandose_legacy_originals', null );
pdt_defer(
	static function () use ( $settings_before, $originals_before ) {
		update_option( 'plandose_settings', $settings_before );
		if ( null === $originals_before ) {
			delete_option( 'plandose_legacy_originals' );
		} else {
			update_option( 'plandose_legacy_originals', $originals_before, false );
		}
	}
);

$settings                           = is_array( $settings_before ) ? $settings_before : array();
$settings['keep_data_on_uninstall'] = 0;
update_option( 'plandose_settings', $settings );

$table        = Plandose_Subscriptions::table_name();
$table_exists = static function () use ( $wpdb, $table ) {
	return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
};
pdt_check( $table_exists(), 'subscriptions table present before the test' );

// A: migrated, but the public originals were never deleted.
update_option( 'plandose_legacy_originals', array( 987654 => array( 'user_id' => 1, 'filename' => 'abc.pdf', 'migrated_at' => time() ) ), false );
plandose_run_uninstall();
pdt_check( $table_exists(), 'originals still in the Media Library → tables kept' );
pdt_check( is_array( get_option( 'plandose_legacy_originals' ) ) && get_option( 'plandose_legacy_originals' ), '… and the list of originals kept' );
pdt_check( false !== get_option( 'plandose_settings' ), '… and the settings kept' );

// The originals rule is the one of Plandose_Admin_Invoices::legacy_originals().
pdt_same( 0, plandose_uninstall_count_legacy_originals( array( 5 => array( 'user_id' => 1 ), 0 => array( 'filename' => 'a.pdf' ), 6 => 'x' ) ), 'malformed originals do not block the uninstall' );
pdt_same( 0, plandose_uninstall_count_legacy_originals( array( 7 => array( 'filename' => 'a.pdf', 'skip' => 'original_missing' ) ) ), 'an original whose file is gone does not block it' );
pdt_same( 2, plandose_uninstall_count_legacy_originals( array( 8 => array( 'filename' => 'a.pdf', 'skip' => 'in_content' ), 9 => array( 'uncopied' => 1 ) ) ), 'other originals do' );
pdt_check( Plandose_Admin_Invoices::legacy_invoices_block_uninstall(), 'Settings warning: shown for originals not deleted' );

// B: never migrated — a numeric attachment ID (int, and as a string) in a row.
delete_option( 'plandose_legacy_originals' );
foreach ( array( '[4242]', '["4243"]' ) as $blob ) {
	$u = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
	Plandose_Subscriptions::get_row( $u );
	$wpdb->update( $table, array( 'invoices' => $blob ), array( 'user_id' => $u ) );
	update_option( 'plandose_settings', $settings );
	plandose_run_uninstall();
	pdt_check( $table_exists(), "legacy attachment ID $blob not migrated → tables kept" );
	pdt_check( Plandose_Admin_Invoices::legacy_invoices_block_uninstall(), "Settings warning: shown for $blob not migrated" );
	$wpdb->update( $table, array( 'invoices' => '' ), array( 'user_id' => $u ) );
}

// ---- wp plandose check: never an administrator by itself ----------------------------
if ( ! defined( 'WP_CLI' ) ) {
	define( 'WP_CLI', false );
}
if ( ! class_exists( 'WP_CLI' ) ) {
	// Just enough of WP_CLI for the picker (it only warns).
	class WP_CLI { // phpcs:ignore
		public static function warning( $m ) {}
		public static function log( $m ) {}
	}
}
if ( ! class_exists( 'Plandose_CLI' ) ) {
	$cli_src = file_get_contents( PLANDOSE_PATH . 'includes/class-plandose-cli.php' );
	$cli_src = preg_replace( '/^<\?php/', '', $cli_src );
	$cli_src = preg_replace( '/if \( ! defined\( \'WP_CLI\' \) \|\| ! WP_CLI \) \{\s*return;\s*\}/', '', $cli_src );
	$cli_src = preg_replace( '/\n\s*WP_CLI::add_command\([^;]*;/', "\n", $cli_src );
	eval( $cli_src ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test only.
}
pdt_check( class_exists( 'Plandose_CLI' ), 'Plandose_CLI loaded' );

$settings_now                        = get_option( 'plandose_settings' );
$settings_now['allow_admin_preview'] = 1;
update_option( 'plandose_settings', $settings_now );

// The picker takes the lowest IDs first: give the site's first administrator
// (ID 1, below every pharmacy) an account_type, straight in the table so the
// category guard does not refuse it.
$admin = 1;
pdt_check( user_can( $admin, 'manage_options' ), 'user 1 is an administrator' );
$wpdb->insert( $wpdb->usermeta, array( 'user_id' => $admin, 'meta_key' => 'account_type', 'meta_value' => 'Φαρμακείο' ) ); // phpcs:ignore
$admin_meta_id = (int) $wpdb->insert_id;
clean_user_cache( $admin );
pdt_defer(
	static function () use ( $wpdb, $admin, $admin_meta_id ) {
		$wpdb->delete( $wpdb->usermeta, array( 'umeta_id' => $admin_meta_id ) );
		clean_user_cache( $admin );
	}
);
pdt_check( Plandose_Access::can_use_tool( $admin ), 'admin preview on: the admin passes can_use_tool()' );
$picked = Plandose_CLI::pick_cache_test_user( '' );
pdt_check( ! $picked || ! user_can( $picked, 'manage_options' ), 'the automatic pick is never an administrator' );
$pharmacy = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
$picked   = Plandose_CLI::pick_cache_test_user( '' );
pdt_check( $picked && ! user_can( $picked, 'manage_options' ), 'a pharmacy is picked instead' );

// ---- the asset preflight --------------------------------------------------------------
$frontend = file_get_contents( PLANDOSE_PATH . 'includes/class-plandose-frontend.php' );
preg_match( '/function assets_available\(.*?\n\t}\n/s', $frontend, $m );
pdt_check( ! empty( $m ), 'assets_available() found' );
pdt_check( false !== strpos( $m[0], "'assets/js/calendar-qr.js'" ), 'calendar-qr.js is in the preflight' );
pdt_check( (bool) preg_match( "/if \( \\\$is_pro \) \{\s*\\\$files\[\] = 'assets\/js\/pro-labels.js';/", $m[0] ), 'pro-labels.js only for Pro' );

$free = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
wp_set_current_user( $free );
pdt_check( true === pdt_call( 'Plandose_Frontend', 'assets_available', false ), 'Free pharmacy: every file it is served is present' );
wp_set_current_user( 0 );

pdt_done();
