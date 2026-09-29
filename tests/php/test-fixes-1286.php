<?php
/**
 * PlanDose 1.28.6 — the smaller findings of the 1.28.4 review. TEST-ONLY.
 *
 * - page links of the subscriber lists do not carry the one-shot result
 *   parameters (the «done» notice showed again on every page);
 * - raw transport errors (cURL text, internal addresses) reach site
 *   administrators and WP-CLI only;
 * - the dead settings require_pharmacy / allowed_roles are gone;
 * - a run without DOCUMENT_ROOT (php-cli cron) keeps the web server's
 *   «outside the web root» verdict instead of blocking the invoice
 *   migration once that verdict is a day old.
 *
 * Runs with PLANDOSE_INVOICE_DIR outside the web root (defined before
 * WordPress loads), as the recommended setup.
 */

$pdt_inv_root = sys_get_temp_dir() . '/pd1286-' . bin2hex( random_bytes( 4 ) );
define( 'PLANDOSE_INVOICE_DIR', $pdt_inv_root . '/private/invoices' );
unset( $_SERVER['DOCUMENT_ROOT'] );

require __DIR__ . '/lib.php';

pdt_defer(
	static function () use ( $pdt_inv_root ) {
		// Remove the throw-away invoice folder (files first, then folders).
		if ( is_dir( $pdt_inv_root ) ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $pdt_inv_root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $it as $f ) {
				$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
			}
			rmdir( $pdt_inv_root );
		}
	}
);

// ---- page links without one-shot parameters ------------------------------------------
$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=plandose-subscriptions&status=pro&s=abc&plandose_result=updated&plandose_probe=1&plandose_unarchived=retry&archived=2&left=0&paged=1';
$url                    = Plandose_Admin_Subscriptions::page_url( 3 );
pdt_check( false !== strpos( $url, 'paged=3' ), 'page link: page number set' );
pdt_check( false !== strpos( $url, 'status=pro' ) && false !== strpos( $url, 's=abc' ), 'page link: filters kept' );
foreach ( array( 'plandose_result', 'plandose_probe', 'plandose_unarchived', 'archived=', 'left=' ) as $arg ) {
	pdt_check( false === strpos( $url, $arg ), "page link: no «{$arg}»" );
}

// ---- raw transport errors for administrators only ------------------------------------
$state = array( 'status' => 'unverified', 'reason' => 'request_failed', 'http_code' => 0, 'detail' => 'cURL error 7: Failed to connect to 10.0.0.5 port 443' );
wp_set_current_user( pdt_user( array(), 'editor' ) );
$label = Plandose_Invoice_Storage::privacy_reason_label( $state );
pdt_check( false === strpos( $label, '10.0.0.5' ), 'non-administrator: no raw cURL text' );
pdt_check( false !== strpos( $label, 'loopback' ), '… the reason is still said' );
wp_set_current_user( 1 );
pdt_check( false !== strpos( Plandose_Invoice_Storage::privacy_reason_label( $state ), '10.0.0.5' ), 'administrator: full detail' );
wp_set_current_user( 0 );

// ---- dead settings removed ----------------------------------------------------------------
$defaults = Plandose_Settings::default_settings();
pdt_check( ! array_key_exists( 'require_pharmacy', $defaults ) && ! array_key_exists( 'allowed_roles', $defaults ), 'defaults: no require_pharmacy / allowed_roles' );
$clean = Plandose_Settings::sanitize_settings( array( 'require_pharmacy' => 0, 'allowed_roles' => array( 'editor' ) ) );
pdt_check( ! array_key_exists( 'require_pharmacy', $clean ) && ! array_key_exists( 'allowed_roles', $clean ), 'a stored row from an older version loses them' );

// ---- php-cli cron keeps the web server's verdict --------------------------------------------
$opt    = Plandose_Invoice_Storage::PRIVACY_OPTION;
$backup = get_option( $opt, null );
pdt_defer(
	static function () use ( $opt, $backup ) {
		Plandose_Invoice_Storage::set_document_root_override( null );
		if ( null === $backup ) {
			delete_option( $opt );
		} else {
			update_option( $opt, $backup, false );
		}
	}
);

$dir = Plandose_Invoice_Storage::invoice_dir();
pdt_check( ! is_wp_error( $dir ), 'custom invoice folder outside the web root is usable' );
$configured = untrailingslashit( wp_normalize_path( (string) Plandose_Invoice_Storage::configured_invoice_dir() ) );
Plandose_Invoice_Storage::set_document_root_override( null );

// What the web server stored two days ago.
$web_verdict = array( 'status' => 'private', 'reason' => 'outside_web_root', 'checked_at' => time() - 2 * DAY_IN_SECONDS, 'dir' => $configured, 'url' => '', 'http_code' => 0, 'detail' => '', 'docroot_known' => true, 'home' => home_url( '/' ) );
update_option( $opt, $web_verdict, false );
$state = Plandose_Invoice_Storage::privacy_status( true );
pdt_same( 'private/outside_web_root', $state['status'] . '/' . $state['reason'], 'no DOCUMENT_ROOT: the web verdict is kept' );
pdt_check( ! empty( $state['stale'] ), '… marked stale (the next web request re-checks)' );
pdt_same( 'private', Plandose_Invoice_Storage::privacy_status( true, true )['status'], '… also on a forced re-check (upload_allowed / migration)' );
pdt_same( $web_verdict, get_option( $opt ), '… and the stored verdict is not overwritten' );

pdt_same( 'WARN', Plandose_Diagnostics::privacy_result( Plandose_Invoice_Storage::privacy_status( true ) )['status'], '… Diagnostics / wp plandose check: WARN, with the re-check hint' );

// Not for ever: past PRIVACY_CLI_KEEP_MAX the run is blocked again.
update_option( $opt, array_merge( $web_verdict, array( 'checked_at' => time() - Plandose_Invoice_Storage::PRIVACY_CLI_KEEP_MAX - 60 ) ), false );
pdt_same( 'unverified/docroot_unknown', implode( '/', array_intersect_key( Plandose_Invoice_Storage::privacy_status( true ), array( 'status' => 1, 'reason' => 1 ) ) ), 'a verdict older than 7 days is not kept' );
update_option( $opt, $web_verdict, false );

// Only a web-made «outside the web root» verdict is kept this way.
update_option( $opt, array_merge( $web_verdict, array( 'status' => 'public', 'reason' => 'http_served' ) ), false );
pdt_check( 'private' !== Plandose_Invoice_Storage::privacy_status( true )['status'], 'a stored «public» verdict is never turned into private' );

// With DOCUMENT_ROOT known the probe runs as before and refreshes the verdict.
update_option( $opt, $web_verdict, false );
Plandose_Invoice_Storage::set_document_root_override( ABSPATH );
$state = Plandose_Invoice_Storage::privacy_status( true );
pdt_same( 'private/outside_web_root', $state['status'] . '/' . $state['reason'], 'DOCUMENT_ROOT known: probed again' );
pdt_check( empty( $state['stale'] ) && (int) get_option( $opt )['checked_at'] >= time() - 60, '… and the verdict refreshed' );
Plandose_Invoice_Storage::set_document_root_override( null );

pdt_done();
