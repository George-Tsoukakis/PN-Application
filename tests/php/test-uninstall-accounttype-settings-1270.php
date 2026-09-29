<?php
/**
 * 1.27.0: uninstall never unlinks a protection file listed as an invoice;
 * the account-type guard covers the by-meta-ID paths and no longer trusts
 * an anonymous web request; «selected pages» with no page.
 */
require __DIR__ . '/lib.php';

global $wpdb;

// ---- uninstall.php helpers, loaded without running the uninstall.
$src = file_get_contents( PLANDOSE_PATH . 'uninstall.php' );
$src = preg_replace( '/^<\?php/', '', $src );
$src = str_replace( "if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {\n\texit;\n}", '', $src );
$src = preg_replace( '/\nplandose_run_uninstall\(\);\s*$/', "\n", $src );
pdt_check( false === strpos( $src, "\nplandose_run_uninstall();" ), 'uninstall.php loaded without its run call' );
eval( $src ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test only.

// Round 2: every option / transient / cron hook 1.27.0 code writes is removed.
$raw_uninstall = file_get_contents( PLANDOSE_PATH . 'uninstall.php' );
foreach ( array( "'plandose_invoice_privacy'", "'plandose_cleanup_last_run'", "'plandose_legacy_invoice_migration_pending'", "wp_clear_scheduled_hook( 'plandose_legacy_invoice_migration' )", "'plandose_upgrade_lock'", "'plandose_upgrade_failed'", "'plandose_subscriber_count_cache'", 'plandose\\\\_invoice\\\\_warning', 'plandose\\\\_scope\\\\_refused', 'plandose\\\\_acct\\\\_blocked' ) as $needle ) {
	pdt_check( false !== strpos( $raw_uninstall, $needle ), "uninstall.php removes $needle" );
}

// plandose_deactivate() clears the legacy-migration cron hook too.
wp_schedule_single_event( time() + 3600, 'plandose_legacy_invoice_migration' );
plandose_deactivate();
pdt_same( false, wp_next_scheduled( 'plandose_legacy_invoice_migration' ), 'deactivation clears plandose_legacy_invoice_migration' );
plandose_schedule_cleanup(); // put the daily event back for the shared site

$protection = plandose_uninstall_protection_files();
pdt_same( Plandose_Invoice_Storage::uninstall_protection_files(), $protection, 'protection list comes from Plandose_Invoice_Storage' );

$filtered = plandose_uninstall_filter_invoice_names(
	array( 'inv-1.pdf', 'index.php', 'INDEX.PHP', 'Web.Config', '.htaccess', 'index.html', '.plandose-invoice-storage', 'inv-2.png', 'inv-1.pdf' ),
	$protection
);
pdt_same( array( 'inv-1.pdf', 'inv-2.png' ), $filtered, 'protection names (any case) are dropped from the known-invoice list' );

// Fallback deleter (used when the storage class is missing): same guarantee.
$dir = sys_get_temp_dir() . '/pdt-inv-' . wp_generate_password( 6, false );
mkdir( $dir );
foreach ( array( 'inv-1.pdf', 'index.php', '.htaccess', 'foreign.txt' ) as $f ) {
	file_put_contents( $dir . '/' . $f, 'x' );
}
pdt_defer( static function () use ( $dir ) {
	foreach ( (array) glob( $dir . '/{,.}*', GLOB_BRACE ) as $f ) {
		if ( is_file( $f ) ) {
			unlink( $f );
		}
	}
	@rmdir( $dir );
} );
plandose_uninstall_delete_known_files( $dir, array( 'inv-1.pdf', 'index.php', '.htaccess' ) );
pdt_same( false, file_exists( $dir . '/inv-1.pdf' ), 'fallback deleter removes the listed invoice' );
pdt_same( true, file_exists( $dir . '/index.php' ) && file_exists( $dir . '/.htaccess' ), 'fallback deleter keeps protection files listed as invoices while a foreign file remains' );

// ---- Account type: by-meta-ID writes.
$owner = pdt_user( array( 'account_type' => 'Εταιρία', 'pdt_other_key' => 'x' ) );
// The account was created in this request, so it has the registration
// exemption; a later request would not. Drop it.
$reg = new ReflectionProperty( 'Plandose_Account_Type', 'registering' );
$reg->setAccessible( true );
$reg->setValue( null, array() );
$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$admin = (int) $admin[0];
$mid   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT umeta_id FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = 'account_type'", $owner ) );
$mid2  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT umeta_id FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = 'pdt_other_key'", $owner ) );
$type  = static function () use ( $owner, $wpdb ) {
	return $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = 'account_type'", $owner ) );
};

wp_set_current_user( $owner );
pdt_same( false, update_metadata_by_mid( 'user', $mid, 'Φαρμακείο' ), 'owner: update_metadata_by_mid() to «Φαρμακείο» is refused' );
pdt_same( false, update_metadata_by_mid( 'user', $mid2, 'Φαρμακείο', 'account_type' ), 'owner: renaming another row to account_type is refused' );
pdt_same( false, update_metadata_by_mid( 'user', $mid, 'x', 'pdt_renamed' ), 'owner: renaming the account_type row away is refused' );
pdt_same( false, delete_metadata_by_mid( 'user', $mid ), 'owner: delete_metadata_by_mid() of the category is refused' );
pdt_same( array( 'Εταιρία' ), $type(), 'category unchanged' );
pdt_same( true, update_metadata_by_mid( 'user', $mid2, 'y' ), 'owner: unrelated by-mid update still works' );
// (core itself answers false for an unchanged value, so ask the guard.)
pdt_same( null, Plandose_Account_Type::guard_update_by_mid( null, $mid, 'Εταιρία' ), 'owner: re-saving the same value by mid passes the guard' );
pdt_same( false, update_user_meta( $owner, 'account_type', 'Φαρμακείο' ), 'owner: update_user_meta() refused (unchanged rule)' );

// Anonymous web request (this script is not WP-CLI and not cron).
wp_set_current_user( 0 );
pdt_same( false, defined( 'WP_CLI' ) && WP_CLI, 'precondition: not running under WP-CLI' );
pdt_same( false, update_user_meta( $owner, 'account_type', 'Φαρμακείο' ), 'anonymous request: change refused (was allowed)' );
pdt_same( false, update_metadata_by_mid( 'user', $mid, 'Φαρμακείο' ), 'anonymous request: by-mid change refused' );

add_filter( 'wp_doing_cron', '__return_true' );
pdt_same( true, (bool) update_user_meta( $owner, 'account_type', 'Φαρμακείο' ), 'cron: change allowed' );
pdt_same( true, (bool) update_user_meta( $owner, 'account_type', 'Εταιρία' ), 'cron: set back' );
remove_filter( 'wp_doing_cron', '__return_true' );

add_filter( 'plandose_account_type_trusted_context', '__return_true' );
pdt_same( true, update_metadata_by_mid( 'user', $mid, 'Φαρμακείο' ), 'importer filter plandose_account_type_trusted_context: by-mid change allowed' );
remove_filter( 'plandose_account_type_trusted_context', '__return_true' );

wp_set_current_user( $admin );
pdt_same( true, update_metadata_by_mid( 'user', $mid, 'Εταιρία' ), 'administrator: by-mid change allowed' );
pdt_same( array( 'Εταιρία' ), $type(), 'category written by the admin' );
wp_set_current_user( 0 );

// ---- Settings: «selected pages» with no page.
$stored = array( 'display_scope' => 'selected', 'display_pages' => array() );
$pre    = static function () use ( &$stored ) {
	return $stored;
};
add_filter( 'pre_option_plandose_settings', $pre );

pdt_same( 'selected', Plandose_Settings::setting( 'display_scope' ), 'read: a stored «selected» with no page stays «selected» (no silent «everywhere»)' );
pdt_same( false, Plandose_Settings::should_display_here(), 'should_display_here(): no selected page → shown nowhere' );

$stored = array( 'display_scope' => 'selected', 'display_pages' => array( 12 ) );
wp_set_current_user( $admin );
delete_transient( Plandose_Settings::SCOPE_REFUSED_TRANSIENT . $admin );
$saved = Plandose_Settings::sanitize_settings( array( 'display_scope' => 'selected', 'display_pages' => array() ) );
pdt_same( array( 'selected', array( 12 ) ), array( $saved['display_scope'], $saved['display_pages'] ), 'save: refused, the previous scope and pages are kept' );
pdt_check( (bool) get_transient( Plandose_Settings::SCOPE_REFUSED_TRANSIENT . $admin ), 'save: the admin gets a notice' );

ob_start();
Plandose_Settings::render_scope_refused_notice();
$notice = ob_get_clean();
pdt_check( false !== strpos( $notice, 'notice-error' ) && ! get_transient( Plandose_Settings::SCOPE_REFUSED_TRANSIENT . $admin ), 'notice rendered once' );

$stored = array( 'display_scope' => 'selected', 'display_pages' => array() );
$saved  = Plandose_Settings::sanitize_settings( array( 'display_scope' => 'selected', 'display_pages' => array() ) );
pdt_same( 'everywhere', $saved['display_scope'], 'save: with no usable previous scope the fallback is «everywhere»' );
delete_transient( Plandose_Settings::SCOPE_REFUSED_TRANSIENT . $admin );

$saved = Plandose_Settings::sanitize_settings( array( 'display_scope' => 'selected', 'display_pages' => array( 5, -3 ) ) );
pdt_same( array( 'selected', array( 5 ) ), array( $saved['display_scope'], $saved['display_pages'] ), 'save: «selected» with a page is kept' );
pdt_same( false, (bool) get_transient( Plandose_Settings::SCOPE_REFUSED_TRANSIENT . $admin ), 'no notice for a valid save' );

remove_filter( 'pre_option_plandose_settings', $pre );
wp_set_current_user( 0 );

pdt_same( array_values( Plandose_Invoice_Storage::ALLOWED_INVOICE_MIMES ), Plandose_Settings::valid_invoice_mimes(), 'MIME list comes from Plandose_Invoice_Storage' );
$clean = Plandose_Settings::sanitize_settings( array( 'invoice_mimes' => array( 'image/png', 'text/html' ) ), 'read' );
pdt_same( array( 'image/png' ), $clean['invoice_mimes'], 'unknown MIME types are dropped' );

pdt_done();
