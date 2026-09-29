<?php
/**
 * Invoice storage:
 *
 * - the .htaccess template has no «Options» line (a 500 on hosts whose
 *   AllowOverride lacks Options made the privacy probe fail), and a folder
 *   still holding the old template gets it rewritten;
 * - reading an invoice (download, existence checks, hashing) does not need a
 *   writable folder and creates nothing;
 * - when the folder cannot be used, the legacy-original actions report
 *   «storage_unavailable» and never mark a copy missing or delete an original.
 *
 * Non-writable folder: chmod 0555 is enough for a normal user. Running as
 * root, is_writable() ignores modes, so the folder is additionally made
 * immutable (chattr +i), which root's access(W_OK) does honour. Where neither
 * works the check is reported as skipped and the resolver's no-write path is
 * still covered by the «missing folder is not created» checks.
 */

require __DIR__ . '/lib.php';

$admin = pdt_user( array(), 'administrator' );
wp_set_current_user( $admin );

$dir = Plandose_Invoice_Storage::invoice_dir();
pdt_check( ! is_wp_error( $dir ), 'default invoice folder available' );
$dir = (string) $dir;

// ---------------------------------------------------------------- .htaccess template
$tpl = Plandose_Invoice_Storage::protection_file_templates();
pdt_check( false === stripos( $tpl['.htaccess'], 'Options' ), '.htaccess template has no Options line' );
pdt_check( false !== strpos( $tpl['.htaccess'], 'Require all denied' ) && false !== strpos( $tpl['.htaccess'], 'Deny from all' ), '… and still denies everything (Apache 2.4 and 2.2)' );
pdt_check( in_array( '.htaccess', Plandose_Invoice_Storage::uninstall_protection_files(), true ), '… and is still a PlanDose file for uninstall' );

$htaccess = trailingslashit( $dir ) . '.htaccess';
$old_ht   = "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
file_put_contents( $htaccess, $old_ht );
pdt_same( 'outdated', Plandose_Invoice_Storage::protection_file_state( $htaccess, '.htaccess' ), 'old .htaccess (with Options -Indexes) is recognised as an old PlanDose template' );
file_put_contents( $htaccess, str_replace( "\n", "\r\n", $old_ht ) );
pdt_same( 'outdated', Plandose_Invoice_Storage::protection_file_state( $htaccess, '.htaccess' ), '… also with CRLF line endings' );
Plandose_Invoice_Storage::invoice_dir();
clearstatcache();
pdt_same( $tpl['.htaccess'], (string) file_get_contents( $htaccess ), 'invoice_dir() rewrites it with the current template' );
pdt_same( 'current', Plandose_Invoice_Storage::protection_file_state( $htaccess, '.htaccess' ), '… now current' );
file_put_contents( $htaccess, "Options -Indexes\nRequire all denied\n# host\n" );
Plandose_Invoice_Storage::invoice_dir();
pdt_check( false !== strpos( (string) file_get_contents( $htaccess ), '# host' ), 'an .htaccess someone else edited is left alone' );
file_put_contents( $htaccess, $tpl['.htaccess'] );

// ---------------------------------------------------------------- reading from a non-writable folder
$name = 'pd1300-' . wp_generate_password( 20, false, false ) . '.pdf';
$file = trailingslashit( $dir ) . $name;
file_put_contents( $file, "%PDF-1.4\n% pd1300\n%%EOF\n" );

$immutable = false;
$unlock    = static function () use ( $dir, &$immutable ) {
	if ( $immutable ) {
		exec( 'chattr -i ' . escapeshellarg( $dir ) . ' 2>/dev/null' );
		$immutable = false;
	}
	@chmod( $dir, 0750 ); // phpcs:ignore
	clearstatcache();
};
// Also on a fatal error: an immutable folder, or a stray file in it, would
// break later tests.
$release = static function () use ( $unlock, $file ) {
	$unlock();
	@unlink( $file ); // phpcs:ignore
};
register_shutdown_function( $release );
pdt_defer( $release );

chmod( $dir, 0555 );
clearstatcache();
if ( is_writable( $dir ) && function_exists( 'exec' ) ) {
	exec( 'chattr +i ' . escapeshellarg( $dir ) . ' 2>/dev/null', $unused, $code );
	$immutable = 0 === $code;
	clearstatcache();
}

if ( is_writable( $dir ) ) {
	echo "skip   non-writable folder (cannot make it read-only here: root without chattr)\n";
} else {
	$w = Plandose_Invoice_Storage::invoice_dir();
	pdt_check( is_wp_error( $w ) && 'plandose_invoice_dir_not_writable' === $w->get_error_code(), 'setup: the folder is not writable (invoice_dir() refuses it)' );

	$r = Plandose_Invoice_Storage::readable_invoice_dir();
	pdt_same( $dir, $r, 'readable_invoice_dir() still resolves it' );

	$p = Plandose_Invoice_Storage::invoice_path( $name );
	pdt_same( $file, $p, 'invoice_path() (download path) resolves in a non-writable folder' );
	pdt_check( is_string( $p ) && is_file( $p ) && is_readable( $p ) && false !== hash_file( 'sha256', $p ), '… and the file is readable and hashable' );
	$bad = Plandose_Invoice_Storage::invoice_path( '../' . $name );
	pdt_check( is_wp_error( $bad ) && 'plandose_invoice_bad_filename' === $bad->get_error_code(), '… path traversal still refused' );
	$res = Plandose_Invoice_Storage::invoice_path( '.htaccess' );
	pdt_check( is_wp_error( $res ), '… protection files still refused' );
}
$unlock();

// ---------------------------------------------------------------- missing folder: reported, never created
$ghost_base = trailingslashit( sys_get_temp_dir() ) . 'pd1300-' . wp_generate_password( 8, false, false );
mkdir( $ghost_base );
pdt_defer(
	static function () use ( $ghost_base ) {
		@rmdir( $ghost_base . '/plandose-invoices' ); // phpcs:ignore
		@rmdir( $ghost_base ); // phpcs:ignore
	}
);
$ghost_filter = static function ( $u ) use ( $ghost_base ) {
	$u['basedir'] = $ghost_base;
	return $u;
};
add_filter( 'upload_dir', $ghost_filter );
$p = Plandose_Invoice_Storage::invoice_path( $name );
pdt_check( is_wp_error( $p ) && 'plandose_invoice_dir_missing' === $p->get_error_code(), 'invoice_path() on a missing folder → plandose_invoice_dir_missing' );
pdt_check( ! file_exists( $ghost_base . '/plandose-invoices' ), '… and the folder is not created by a read' );
remove_filter( 'upload_dir', $ghost_filter );

// ---------------------------------------------------------------- storage_unavailable
$opt        = Plandose_Admin_Invoices::LEGACY_ORIGINALS_OPTION;
$opt_backup = get_option( $opt, null );
pdt_defer(
	static function () use ( $opt, $opt_backup ) {
		if ( null === $opt_backup ) {
			delete_option( $opt );
		} else {
			update_option( $opt, $opt_backup, false );
		}
	}
);

$uploads   = wp_upload_dir();
$orig_file = trailingslashit( $uploads['path'] ) . 'pd1300-legacy-' . wp_generate_password( 8, false, false ) . '.pdf';
file_put_contents( $orig_file, "%PDF-1.4\n% pd1300 legacy\n%%EOF\n" );
$att = wp_insert_attachment( array( 'post_mime_type' => 'application/pdf', 'post_title' => 'pd1300 legacy', 'post_status' => 'inherit' ), $orig_file );
pdt_defer(
	static function () use ( $att, $orig_file ) {
		if ( get_post( $att ) ) {
			wp_delete_attachment( $att, true );
		}
		@unlink( $orig_file ); // phpcs:ignore
	}
);

// Copy name recorded, file NOT in the folder: with a working folder this
// is «copy_missing»; with a broken one it must not be.
$copy = 'lm-' . wp_generate_password( 32, false, false ) . '.pdf';
$set  = static function () use ( $opt, $att, $copy ) {
	update_option(
		$opt,
		array(
			$att => array(
				'user_id'     => 0,
				'filename'    => $copy,
				'migrated_at' => time(),
			),
		),
		false
	);
};
$set();

$broken = static function ( $u ) {
	$u['error'] = 'pd1300: storage broken';
	return $u;
};
add_filter( 'upload_dir', $broken );

$entry  = Plandose_Admin_Invoices::legacy_originals()[ $att ];
$reason = pdt_call( 'Plandose_Admin_Invoices', 'legacy_original_block_reason', $att, $entry, get_post( $att ), array() );
pdt_same( 'storage_unavailable', $reason, 'broken folder: block reason is storage_unavailable, not copy_missing' );
pdt_check( Plandose_Admin_Invoices::legacy_original_reason_label( 'storage_unavailable' ) !== Plandose_Admin_Invoices::legacy_original_reason_label( 'no-such-reason' ), '… with its own label' );

$batch = Plandose_Admin_Invoices::delete_legacy_originals_batch( 10 );
pdt_check( is_wp_error( $batch ) && 'plandose_storage_unavailable' === $batch->get_error_code(), 'broken folder: «Διαγραφή πρωτοτύπων» stops with plandose_storage_unavailable' );
pdt_check( is_wp_error( $batch ) && false !== strpos( $batch->get_error_message(), 'Δεν έγινε καμία αλλαγή' ), '… saying nothing was changed' );
$after = Plandose_Admin_Invoices::legacy_originals();
pdt_check( isset( $after[ $att ] ) && empty( $after[ $att ]['skip'] ), '… and the original is NOT marked (no copy_missing)' );

Plandose_Admin_Invoices::mark_legacy_copy_removed( $copy );
$after = Plandose_Admin_Invoices::legacy_originals();
pdt_check( isset( $after[ $att ] ) && empty( $after[ $att ]['skip'] ), 'broken folder: mark_legacy_copy_removed() does not mark copy_missing' );

$res = Plandose_Admin_Invoices::delete_uncopied_legacy_original( $att );
clearstatcache();
pdt_check( is_wp_error( $res ) && 'plandose_storage_unavailable' === $res->get_error_code(), 'broken folder: «Διαγραφή χωρίς αντίγραφο» refused with plandose_storage_unavailable' );
pdt_check( get_post( $att ) && file_exists( $orig_file ), '… and the public original is kept' );

// A copy_missing recorded before the folder broke is not offered for
// «Διαγραφή χωρίς αντίγραφο» while the folder cannot be read.
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
$ids = Plandose_Admin::admin_screen_ids();
WP_Screen::get( reset( $ids ) )->set_current_screen();
$render = static function () {
	ob_start();
	Plandose_Admin_Invoices::render_legacy_originals_notice();
	return (string) ob_get_clean();
};
$recorded                       = get_option( $opt );
$recorded[ $att ]['skip']       = 'copy_missing';
$recorded[ $att ]['checked_at'] = time();
update_option( $opt, $recorded, false );
$html = $render();
pdt_check( false !== strpos( $html, 'plandose-legacy-originals' ) && false === strpos( $html, 'plandose_delete_legacy_original_uncopied' ), 'broken folder: «Διαγραφή χωρίς αντίγραφο» not offered for an earlier copy_missing' );

remove_filter( 'upload_dir', $broken );

$html = $render();
pdt_check( false !== strpos( $html, 'plandose_delete_legacy_original_uncopied' ), 'control: offered again once the folder is readable' );
$set();

// Control: the same state with a working folder IS copy_missing, so the
// checks above are about the broken folder and nothing else.
Plandose_Admin_Invoices::mark_legacy_copy_removed( $copy );
$after = Plandose_Admin_Invoices::legacy_originals();
pdt_same( 'copy_missing', isset( $after[ $att ]['skip'] ) ? $after[ $att ]['skip'] : '', 'control: working folder, copy absent → copy_missing' );
$set();
$reason = pdt_call( 'Plandose_Admin_Invoices', 'legacy_original_block_reason', $att, $entry, get_post( $att ), array() );
pdt_same( 'copy_missing', $reason, 'control: block reason copy_missing with a working folder' );

// ---------------------------------------------------------------- download: file opened before headers
// is_readable() true yet fopen() failing cannot be staged reliably (root
// reads everything), so the order is checked in the handler's source.
$m   = new ReflectionMethod( 'Plandose_Admin_Invoices', 'handle_view_invoice' );
$src = implode( '', array_slice( file( $m->getFileName() ), $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1 ) );
$at  = array(
	'fopen'       => strpos( $src, 'fopen( $path' ),
	'disposition' => strpos( $src, "header(\n\t\t\t'Content-Disposition" ),
	'nocache'     => strpos( $src, 'nocache_headers()' ),
	'length'      => strpos( $src, 'Content-Length' ),
);
pdt_check( ! in_array( false, $at, true ) && $at['fopen'] < $at['nocache'] && $at['fopen'] < $at['disposition'] && $at['fopen'] < $at['length'], 'download: the file is opened before any download header is sent' );

pdt_done();
