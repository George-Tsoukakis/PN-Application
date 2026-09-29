<?php
/**
 * PlanDose 1.27.0 — child process for storage-admin-1270.php. Constants such
 * as PLANDOSE_INVOICE_DIR can only be defined once per process, so each
 * scenario runs here. TEST-ONLY.
 *
 * Usage: php child-1270.php <wp-load.php> <scenario> <json args>
 * Prints one JSON object.
 */
$_SERVER['HTTP_HOST'] = '127.0.0.1:8899';
$scenario = $argv[2];
$args     = json_decode( $argv[3], true );

foreach ( isset( $args['define'] ) ? $args['define'] : array() as $name => $value ) {
	define( $name, $value );
}

// The tool's own guard needs WP-CLI's SAPI, which a plain php child has too.
require $argv[1];

$out = array();

switch ( $scenario ) {
	case 'orphan_tool':
		ob_start();
		include $args['tool'];
		$out['output'] = ob_get_clean();
		clearstatcache();
		$out['dir_exists'] = is_dir( PLANDOSE_INVOICE_DIR );
		break;

	case 'probe_public':
		$dir = Plandose_Invoice_Storage::invoice_dir();
		if ( is_wp_error( $dir ) ) {
			$out['dir_error'] = $dir->get_error_message();
			break;
		}
		$state         = Plandose_Invoice_Storage::probe_privacy( $dir, false );
		$out['status'] = $state['status'];
		$out['reason'] = $state['reason'];
		$out['url']    = $state['url'];
		$out['code']   = $state['http_code'];
		break;

	case 'upload_allowed':
		$backup = get_option( Plandose_Invoice_Storage::PRIVACY_OPTION, null );
		if ( ! empty( $args['docroot'] ) ) {
			Plandose_Invoice_Storage::set_document_root_override( $args['docroot'] );
		}
		if ( ! empty( $args['production'] ) ) {
			add_filter( 'plandose_invoice_storage_is_production', '__return_true' );
		}
		$dir = Plandose_Invoice_Storage::invoice_dir();
		if ( is_wp_error( $dir ) ) {
			$out['dir_error'] = $dir->get_error_message();
			break;
		}
		$state            = Plandose_Invoice_Storage::probe_privacy( $dir, false );
		$out['status']    = $state['status'];
		$out['reason']    = $state['reason'];
		$allowed          = Plandose_Invoice_Storage::upload_allowed();
		$out['allowed']   = true === $allowed;
		$out['error']     = is_wp_error( $allowed ) ? $allowed->get_error_code() : '';
		$live             = Plandose_Diagnostics::check_storage( array( 'live' => true, 'persist' => false ) );
		$out['diag']      = $live[1]['status'];
		if ( null === $backup ) {
			delete_option( Plandose_Invoice_Storage::PRIVACY_OPTION );
		} else {
			update_option( Plandose_Invoice_Storage::PRIVACY_OPTION, $backup, false );
		}
		break;
}

echo wp_json_encode( $out );
