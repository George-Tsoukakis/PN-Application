<?php
/**
 * 1.27.5: the invoice-folder privacy probe fails closed.
 *
 * - only a real refusal (4xx) of the invoice canary, with the control canary
 *   served, is «private»; a 2xx without the canary (WAF / CDN page), 204 or
 *   a redirect is «unverified»;
 * - a folder with no URL is never «private» when DOCUMENT_ROOT is unknown
 *   (WP-CLI, php-cli cron), and that result is never cached;
 * - a cached verdict made for another site (home URL) or an old CLI
 *   «outside_web_root» without DOCUMENT_ROOT is ignored;
 * - a folder found PUBLIC is refused on every site, test sites included.
 */

require __DIR__ . '/lib.php';

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
pdt_check( ! is_wp_error( $dir ), 'default invoice folder available' );

/**
 * Answer the invoice canary with $code/$body; serve the control canary
 * with its real content (read from disk before it is deleted).
 */
function pdt_fake_http( $code, $body ) {
	return static function ( $pre, $args, $url ) use ( $code, $body ) {
		if ( false !== strpos( $url, '/plandose-invoices/' ) ) {
			return array( 'headers' => array(), 'body' => $body, 'response' => array( 'code' => $code, 'message' => '' ), 'cookies' => array(), 'filename' => null );
		}
		$uploads = wp_get_upload_dir();
		$path    = str_replace( $uploads['baseurl'], $uploads['basedir'], strtok( $url, '?' ) );
		$path    = rawurldecode( $path );
		$content = is_readable( $path ) ? (string) file_get_contents( $path ) : '';
		return array( 'headers' => array(), 'body' => $content, 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	};
}

$cases = array(
	array( 200, '<html>Just a moment… (challenge)</html>', 'unverified', 'unexpected_response' ),
	array( 204, '', 'unverified', 'unexpected_response' ),
	array( 302, '', 'unverified', 'redirect' ),
	array( 500, '', 'unverified', 'server_error' ),
	array( 403, 'Forbidden', 'private', 'http_refused' ),
	array( 404, 'Not Found', 'private', 'http_refused' ),
);

foreach ( $cases as $c ) {
	list( $code, $body, $status, $reason ) = $c;
	$f = pdt_fake_http( $code, $body );
	add_filter( 'pre_http_request', $f, 10, 3 );
	$state = Plandose_Invoice_Storage::probe_privacy( $dir, false );
	remove_filter( 'pre_http_request', $f, 10 );
	pdt_same( $status . '/' . $reason, $state['status'] . '/' . $state['reason'], "invoice canary HTTP $code (control served)" );
}

// ---- no DOCUMENT_ROOT: never «private», never cached
$outside = sys_get_temp_dir() . '/pd1275-' . wp_generate_password( 6, false, false ) . '/plandose-invoices';
unset( $_SERVER['DOCUMENT_ROOT'] );
Plandose_Invoice_Storage::set_document_root_override( null );
delete_option( $opt );
$state = Plandose_Invoice_Storage::probe_privacy( $outside, true );
pdt_same( 'unverified/docroot_unknown', $state['status'] . '/' . $state['reason'], 'folder without URL, DOCUMENT_ROOT unknown → unverified' );
pdt_same( false, get_option( $opt, false ), '… and nothing cached' );
$diag = Plandose_Diagnostics::privacy_result( $state );
pdt_same( 'WARN', $diag['status'], '… diagnostics WARN with the --docroot hint' );

Plandose_Invoice_Storage::set_document_root_override( ABSPATH );
$state = Plandose_Invoice_Storage::probe_privacy( $outside, false );
pdt_same( 'private/outside_web_root', $state['status'] . '/' . $state['reason'], 'same folder with DOCUMENT_ROOT known → private' );
Plandose_Invoice_Storage::set_document_root_override( null );

// ---- cached verdicts that must not be trusted
$configured = untrailingslashit( wp_normalize_path( $dir ) );
update_option(
	$opt,
	array( 'status' => 'private', 'reason' => 'http_refused', 'checked_at' => time(), 'dir' => $configured, 'url' => '', 'http_code' => 404, 'detail' => '', 'docroot_known' => true, 'home' => 'https://another-host.example/' ),
	false
);
pdt_same( 'unknown', Plandose_Invoice_Storage::privacy_status( false )['status'], 'a verdict cached for another site (restored backup) is ignored' );

update_option(
	$opt,
	array( 'status' => 'private', 'reason' => 'outside_web_root', 'checked_at' => time(), 'dir' => $configured, 'url' => '', 'http_code' => 0, 'detail' => '', 'docroot_known' => false, 'home' => home_url( '/' ) ),
	false
);
pdt_same( 'unknown', Plandose_Invoice_Storage::privacy_status( false )['status'], 'an old «outside_web_root» made without DOCUMENT_ROOT is ignored' );

update_option(
	$opt,
	array( 'status' => 'private', 'reason' => 'http_refused', 'checked_at' => time(), 'dir' => $configured, 'url' => '', 'http_code' => 404, 'detail' => '', 'docroot_known' => true, 'home' => home_url( '/' ) ),
	false
);
pdt_same( 'private', Plandose_Invoice_Storage::privacy_status( false )['status'], 'a fresh verdict for this site and folder is used' );

// ---- a PUBLIC folder is refused outside production too
$served = static function ( $pre, $args, $url ) {
	$uploads = wp_get_upload_dir();
	$path    = rawurldecode( str_replace( $uploads['baseurl'], $uploads['basedir'], strtok( $url, '?' ) ) );
	return array( 'headers' => array(), 'body' => is_readable( $path ) ? (string) file_get_contents( $path ) : '', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
};
delete_option( $opt );
add_filter( 'pre_http_request', $served, 10, 3 );
add_filter( 'plandose_invoice_storage_is_production', '__return_false' );
$allowed = Plandose_Invoice_Storage::upload_allowed();
$status  = Plandose_Invoice_Storage::privacy_status( false )['status'];
remove_filter( 'plandose_invoice_storage_is_production', '__return_false' );
remove_filter( 'pre_http_request', $served, 10 );
pdt_same( 'public', $status, 'every canary served → public' );
pdt_check( is_wp_error( $allowed ), 'non-production + public folder → upload refused' );

pdt_done();
