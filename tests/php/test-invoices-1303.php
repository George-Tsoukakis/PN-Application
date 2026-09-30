<?php
/**
 * 1.30.3: invoice storage / migration / uninstall review fixes. TEST-ONLY.
 *
 * - uninstall removes the invoice files BEFORE dropping the tables, and
 *   keeps the tables (keep-data mode) when a listed file is still there;
 *   an unusable table prefix is keep-data mode too;
 * - Plandose_Invoice_Storage::filesystem() uses WP_Filesystem only for the
 *   'direct' method (never an unconnected FTP/SSH global);
 * - the migration cron chain survives a busy lock; an attachment ID listed
 *   twice in a row is rewritten everywhere; a failed originals read-back
 *   leaves no record of rolled-back copies; the page-load batch never runs
 *   the live privacy probe.
 *
 * Nothing here really drops a table or deletes an option: every DROP /
 * DELETE query the uninstall sends is recorded and replaced by a no-op.
 */

require __DIR__ . '/lib.php';

global $wpdb;

$dir = Plandose_Invoice_Storage::invoice_dir();
pdt_check( ! is_wp_error( $dir ), 'default invoice folder available' );

$table = Plandose_Subscriptions::table_name();

// ---- shared option backups ------------------------------------------------------

$o_names = array(
	'plandose_settings',
	Plandose_Invoice_Storage::PRIVACY_OPTION,
	Plandose_Invoice_Migration::LEGACY_MIGRATION_OPTION,
	Plandose_Invoice_Migration::LEGACY_MIGRATION_CURSOR_OPTION,
	Plandose_Invoice_Migration::LEGACY_MIGRATION_FAILED_OPTION,
	Plandose_Invoice_Migration::PENDING_COPIES_OPTION,
	Plandose_Invoice_Migration::LEGACY_MIGRATION_LOCK_OPTION,
	Plandose_Admin_Invoices::LEGACY_ORIGINALS_OPTION,
);
$o_backup = array();
foreach ( $o_names as $name ) {
	$o_backup[ $name ] = get_option( $name, null );
}
$cron_next = wp_next_scheduled( Plandose_Invoice_Migration::CRON_HOOK );
pdt_defer(
	static function () use ( $o_backup, $cron_next ) {
		foreach ( $o_backup as $name => $value ) {
			if ( null === $value ) {
				delete_option( $name );
			} else {
				update_option( $name, $value, false );
			}
		}
		wp_clear_scheduled_hook( Plandose_Invoice_Migration::CRON_HOOK );
		if ( false !== $cron_next ) {
			wp_schedule_single_event( $cron_next, Plandose_Invoice_Migration::CRON_HOOK );
		}
		if ( function_exists( 'plandose_schedule_cleanup' ) ) {
			plandose_schedule_cleanup();
		}
	}
);

// ================================================================================
// 2. uninstall: files first, tables only once every listed file is gone
// ================================================================================

$src = file_get_contents( PLANDOSE_PATH . 'uninstall.php' );
$src = preg_replace( '/^<\?php/', '', $src );
$src = str_replace( "defined( 'WP_UNINSTALL_PLUGIN' )", 'true', $src );
$src = preg_replace( '/\nplandose_run_uninstall\(\);\s*$/', "\n", $src );
pdt_check( false === strpos( $src, "\nplandose_run_uninstall();" ), 'uninstall.php loaded without its run call' );
eval( $src ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test only.

$settings                           = get_option( 'plandose_settings' );
$settings                           = is_array( $settings ) ? $settings : array();
$settings['keep_data_on_uninstall'] = 0;
update_option( 'plandose_settings', $settings );
delete_option( Plandose_Admin_Invoices::LEGACY_ORIGINALS_OPTION ); // No Media Library originals left.

$u_uninstall = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
Plandose_Subscriptions::get_row( $u_uninstall );
$inv_name = 'pd1303-' . strtolower( wp_generate_password( 16, false, false ) ) . '.pdf';
$inv_path = trailingslashit( $dir ) . $inv_name;
$wpdb->update( $table, array( 'invoices' => wp_json_encode( array( $inv_name ) ) ), array( 'user_id' => $u_uninstall ) );

// A foreign file keeps the folder's protection files in place whatever happens.
$keep_path = trailingslashit( $dir ) . 'pd1303-keep-' . strtolower( wp_generate_password( 8, false, false ) ) . '.txt';
file_put_contents( $keep_path, 'keep' );
pdt_defer(
	static function () use ( $keep_path, $inv_path ) {
		clearstatcache();
		if ( is_file( $keep_path ) ) {
			unlink( $keep_path );
		}
		if ( is_dir( $inv_path ) ) {
			rmdir( $inv_path );
		} elseif ( is_file( $inv_path ) ) {
			unlink( $inv_path );
		}
	}
);

/**
 * Run the uninstall with every DROP / DELETE recorded and neutralized, the
 * invoice-list read limited to the test pharmacy, and error_log() captured.
 *
 * @return array{0: string[], 1: string} Neutralized queries, log text.
 */
function pdt1303_uninstall( $user_id ) {
	global $wpdb;

	$seen   = array();
	$filter = static function ( $query ) use ( &$seen, $user_id, $wpdb ) {
		$q = ltrim( $query );

		if ( 0 === stripos( $q, 'DROP ' ) || 0 === stripos( $q, 'DELETE ' ) ) {
			$seen[] = $q;
			return 'SELECT 1';
		}

		if ( 0 === strpos( $q, 'SELECT invoices FROM `' . $wpdb->prefix . 'plandose_subscriptions`' ) ) {
			return $q . ' AND user_id = ' . (int) $user_id;
		}

		return $query;
	};

	$log      = wp_tempnam( 'pd1303-log' );
	$old_log  = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- test only.
	add_filter( 'query', $filter );
	plandose_run_uninstall();
	remove_filter( 'query', $filter );
	ini_set( 'error_log', (string) $old_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- test only.
	$text = (string) file_get_contents( $log );
	unlink( $log );
	wp_cache_flush(); // Neutralized deletes still dropped the cached copies.

	return array( $seen, $text );
}

$drops = static function ( array $seen ) {
	return array_values( array_filter( $seen, static function ( $q ) {
		return 0 === stripos( $q, 'DROP TABLE' );
	} ) );
};
$option_deletes = static function ( array $seen ) {
	return array_values( array_filter( $seen, static function ( $q ) {
		return false !== strpos( $q, "'plandose_settings'" );
	} ) );
};

// A: the listed invoice cannot be removed (a directory with its name stands
// in for a permission error: the cleanup deletes only regular files).
mkdir( $inv_path );
list( $seen, $log ) = pdt1303_uninstall( $u_uninstall );
clearstatcache();
pdt_same( array(), $drops( $seen ), 'listed invoice still on disk → no table dropped' );
pdt_same( array(), $option_deletes( $seen ), '… and the options kept (keep-data mode)' );
pdt_check( false !== strpos( $log, 'could not be removed from the invoice folder' ), '… and the reason is logged' );
pdt_check( is_file( trailingslashit( $dir ) . '.htaccess' ), '… and the folder keeps its protection files' );
rmdir( $inv_path );

// B: control — the same invoice as a real, removable file: it is deleted
// first, then all six tables would be dropped.
file_put_contents( $inv_path, "%PDF-1.4\n% pd1303\n%%EOF\n" );
update_option( 'plandose_settings', $settings );
list( $seen, $log ) = pdt1303_uninstall( $u_uninstall );
clearstatcache();
pdt_check( ! file_exists( $inv_path ), 'removable invoice → deleted by the uninstall' );
pdt_same( 6, count( $drops( $seen ) ), '… and then the six tables are dropped: ' . wp_json_encode( $drops( $seen ) ) );
pdt_same( 1, count( $option_deletes( $seen ) ), '… and the options deleted' );
pdt_check( false === strpos( $log, 'could not be removed' ), '… with nothing logged about files' );

// C: an unusable table prefix is keep-data mode for everything.
update_option( 'plandose_settings', $settings );
$real_prefix   = $wpdb->prefix;
$wpdb->prefix  = 'wp-bad';
list( $seen, $log ) = pdt1303_uninstall( $u_uninstall );
$wpdb->prefix = $real_prefix;
pdt_same( array(), $drops( $seen ), 'bad table prefix → no table dropped' );
pdt_same( array(), $option_deletes( $seen ), '… and the options kept, not a half-uninstall' );
pdt_check( false !== strpos( $log, 'table prefix' ), '… and logged' );
update_option( 'plandose_settings', $o_backup['plandose_settings'] );

// The helper itself.
$tmp = trailingslashit( get_temp_dir() ) . 'pd1303-' . wp_generate_password( 8, false, false );
mkdir( $tmp );
file_put_contents( $tmp . '/a.pdf', 'x' );
pdt_same( 0, plandose_uninstall_invoice_files_left( $tmp, array() ), 'files_left: nothing listed → 0' );
pdt_same( 1, plandose_uninstall_invoice_files_left( $tmp, array( 'a.pdf', 'b.pdf' ) ), 'files_left: counts only the listed files still there' );
pdt_same( -1, plandose_uninstall_invoice_files_left( '', array( 'a.pdf' ) ), 'files_left: unknown folder with files listed → -1 (keep)' );
pdt_same( 0, plandose_uninstall_invoice_files_left( $tmp . '/missing', array( 'a.pdf' ) ), 'files_left: a folder that does not exist holds none' );
unlink( $tmp . '/a.pdf' );
rmdir( $tmp );

// ================================================================================
// 3. filesystem(): WP_Filesystem only for the 'direct' method
// ================================================================================

$fs_prop = new ReflectionProperty( 'Plandose_Invoice_Storage', 'filesystem' );
$fs_prop->setAccessible( true );
$global_before = isset( $GLOBALS['wp_filesystem'] ) ? $GLOBALS['wp_filesystem'] : null;
pdt_defer(
	static function () use ( $fs_prop, $global_before ) {
		$fs_prop->setValue( null, null );
		$GLOBALS['wp_filesystem'] = $global_before;
	}
);

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';

// An FTP host: WP_Filesystem() set the global before connect() failed.
$unconnected              = new WP_Filesystem_Base();
$GLOBALS['wp_filesystem'] = $unconnected;
$ftp                      = static function () {
	return 'ftpext';
};
add_filter( 'filesystem_method', $ftp );
$fs_prop->setValue( null, null );
pdt_same( null, pdt_call( 'Plandose_Invoice_Storage', 'filesystem' ), "method 'ftpext' → no WP_Filesystem object (plain-PHP fallback)" );
pdt_same( false, $fs_prop->getValue(), '… and the decision is cached' );
$probe_file = trailingslashit( $dir ) . 'pd1303-fs-' . strtolower( wp_generate_password( 8, false, false ) ) . '.txt';
pdt_check( pdt_call( 'Plandose_Invoice_Storage', 'fs_put_contents', $probe_file, 'fs', 'test' ) && 'fs' === file_get_contents( $probe_file ), '… writes still work through the fallback (the unconnected global is ignored)' );
pdt_check( Plandose_Invoice_Storage::fs_delete( $probe_file, 'test' ) && ! file_exists( $probe_file ), '… and deletes too' );
remove_filter( 'filesystem_method', $ftp );

$fs_prop->setValue( null, null );
$direct = pdt_call( 'Plandose_Invoice_Storage', 'filesystem' );
pdt_check( 'direct' !== get_filesystem_method() || $direct instanceof WP_Filesystem_Direct, "method 'direct' → a WP_Filesystem_Direct instance" );
pdt_check( $unconnected === $GLOBALS['wp_filesystem'], '… and the global $wp_filesystem is neither used nor replaced' );
pdt_check( $direct === pdt_call( 'Plandose_Invoice_Storage', 'filesystem' ), '… the same instance on the next call' );
$fs_prop->setValue( null, null );
$GLOBALS['wp_filesystem'] = $global_before;

// ================================================================================
// 4. migration
// ================================================================================

$private_state = static function ( $checked_at ) use ( $dir ) {
	return array(
		'status'        => 'private',
		'reason'        => 'http_refused',
		'checked_at'    => $checked_at,
		'dir'           => untrailingslashit( wp_normalize_path( $dir ) ),
		'home'          => home_url( '/' ),
		'url'           => '',
		'http_code'     => 404,
		'detail'        => '',
		'docroot_known' => true,
	);
};

$http_calls = 0;
$count_http = static function ( $pre ) use ( &$http_calls ) {
	++$http_calls;
	return new WP_Error( 'pd1303_no_http', 'no HTTP in this test' );
};
add_filter( 'pre_http_request', $count_http );
pdt_defer(
	static function () use ( $count_http ) {
		remove_filter( 'pre_http_request', $count_http );
	}
);

// ---- 4a. a busy lock does not end the cron chain -------------------------------

delete_option( Plandose_Invoice_Migration::LEGACY_MIGRATION_OPTION );
delete_option( Plandose_Invoice_Migration::LEGACY_MIGRATION_FAILED_OPTION );
wp_clear_scheduled_hook( Plandose_Invoice_Migration::CRON_HOOK );
update_option( Plandose_Invoice_Migration::LEGACY_MIGRATION_LOCK_OPTION, time(), false ); // Held by another run.
pdt_check( Plandose_Invoice_Migration::migration_pending(), '4a: migration pending' );
Plandose_Invoice_Migration::run_cron_batch();
$next = wp_next_scheduled( Plandose_Invoice_Migration::CRON_HOOK );
pdt_check( false !== $next && $next <= time() + 60, '4a: lock busy → the next batch is scheduled shortly' );
delete_option( Plandose_Invoice_Migration::LEGACY_MIGRATION_LOCK_OPTION );
wp_clear_scheduled_hook( Plandose_Invoice_Migration::CRON_HOOK );

// ---- fixtures: legacy PDF attachments --------------------------------------------

$uploads     = wp_upload_dir();
$attachments = array();
$make_att    = static function ( $tag ) use ( $uploads, &$attachments ) {
	$file = trailingslashit( $uploads['path'] ) . 'pd1303-legacy-' . $tag . '-' . wp_generate_password( 8, false, false ) . '.pdf';
	file_put_contents( $file, "%PDF-1.4\n% pd1303 $tag\n%%EOF\n" );
	$id            = wp_insert_attachment( array( 'post_mime_type' => 'application/pdf', 'post_title' => 'pd1303 ' . $tag, 'post_status' => 'inherit' ), $file );
	$attachments[] = $id;
	return (int) $id;
};
pdt_defer(
	static function () use ( &$attachments ) {
		foreach ( $attachments as $id ) {
			wp_delete_attachment( $id, true );
		}
	}
);

$row_list = static function ( $uid ) {
	$row = Plandose_Subscriptions::get_row( $uid, false );
	return Plandose_Subscriptions::decode_invoices( $row ? $row->invoices : '' );
};
$remove_copies = static function ( array $entries ) use ( $dir ) {
	foreach ( $entries as $entry ) {
		if ( ! is_numeric( $entry ) && is_file( trailingslashit( $dir ) . $entry ) ) {
			unlink( trailingslashit( $dir ) . $entry );
		}
	}
};
$run = static function ( $uid, $probe = true ) {
	update_option( Plandose_Invoice_Migration::LEGACY_MIGRATION_CURSOR_OPTION, $uid - 1, false );
	return pdt_call( 'Plandose_Invoice_Migration', 'run_legacy_invoice_migration', false, 20, 10, $probe );
};

delete_option( Plandose_Admin_Invoices::LEGACY_ORIGINALS_OPTION );
delete_option( Plandose_Invoice_Migration::PENDING_COPIES_OPTION );
update_option( Plandose_Invoice_Storage::PRIVACY_OPTION, $private_state( time() ), false );

// ---- 4b. the same attachment ID twice in one row ---------------------------------

$u_dup = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
Plandose_Subscriptions::get_row( $u_dup );
$att_dup   = $make_att( 'dup' );
$att_other = $make_att( 'other' );
Plandose_Subscriptions::update( $u_dup, array( 'invoices' => wp_json_encode( array( $att_dup, 'x-upload.pdf', (string) $att_dup, $att_other ) ) ) );
$run( $u_dup );
$after = $row_list( $u_dup );
pdt_same( 0, count( array_filter( $after, 'is_numeric' ) ), '4b: every occurrence of a duplicated ID is rewritten: ' . wp_json_encode( $after ) );
pdt_check( 4 === count( $after ) && $after[0] === $after[2] && 1 === preg_match( Plandose_Invoice_Migration::COPY_PATTERN, $after[0] ), '4b: … both to the same copy' );
pdt_same( 'x-upload.pdf', $after[1], '4b: … other entries untouched' );
pdt_check( is_file( trailingslashit( $dir ) . $after[0] ) && is_file( trailingslashit( $dir ) . $after[3] ), '4b: … and the copies exist' );
$orig = Plandose_Admin_Invoices::legacy_originals();
pdt_check( isset( $orig[ $att_dup ] ) && $after[0] === $orig[ $att_dup ]['filename'] && empty( $orig[ $att_dup ]['extra'] ), '4b: … one original recorded for it' );
$remove_copies( $after );
delete_option( Plandose_Admin_Invoices::LEGACY_ORIGINALS_OPTION );

// ---- 4c. a failed originals read-back leaves no record behind --------------------

$u_rb = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
Plandose_Subscriptions::get_row( $u_rb );
$att_rb = $make_att( 'readback' );
Plandose_Subscriptions::update( $u_rb, array( 'invoices' => wp_json_encode( array( $att_rb ) ) ) );

// The list IS written, but the read-back right after the write sees it
// without the new entries (a replica lag, a stale cache layer).
$strip_next = false;
$arm        = static function () use ( &$strip_next ) {
	$strip_next = true;
};
$strip      = static function ( $value ) use ( &$strip_next ) {
	if ( $strip_next ) {
		$strip_next = false;
		return array();
	}
	return $value;
};
$opt = Plandose_Admin_Invoices::LEGACY_ORIGINALS_OPTION;
add_action( 'add_option_' . $opt, $arm );
add_action( 'update_option_' . $opt, $arm );
add_filter( 'option_' . $opt, $strip );
$before_files = glob( trailingslashit( $dir ) . Plandose_Invoice_Migration::COPY_PREFIX . '*' );
$run( $u_rb );
remove_filter( 'option_' . $opt, $strip );
remove_action( 'add_option_' . $opt, $arm );
remove_action( 'update_option_' . $opt, $arm );
wp_cache_delete( $opt, 'options' );
wp_cache_delete( 'alloptions', 'options' );

$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $opt ) );
$raw = null === $raw ? array() : maybe_unserialize( $raw );
pdt_same( array( $att_rb ), array_map( 'intval', $row_list( $u_rb ) ), '4c: read-back failed → the row keeps the attachment ID' );
pdt_same( $before_files, glob( trailingslashit( $dir ) . Plandose_Invoice_Migration::COPY_PREFIX . '*' ), '4c: … its copy is rolled back' );
pdt_check( is_array( $raw ) && ! isset( $raw[ $att_rb ] ), '4c: … and the originals list does not keep a record of the rolled-back copy: ' . wp_json_encode( $raw ) );
delete_option( Plandose_Invoice_Migration::LEGACY_MIGRATION_FAILED_OPTION );
delete_option( Plandose_Admin_Invoices::LEGACY_ORIGINALS_OPTION );

// ---- 4d. the page-load batch never runs the live privacy probe -------------------

$u_pl = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
Plandose_Subscriptions::get_row( $u_pl );
$att_pl = $make_att( 'pageload' );
Plandose_Subscriptions::update( $u_pl, array( 'invoices' => wp_json_encode( array( $att_pl ) ) ) );

// Stale «private» verdict (two days old).
update_option( Plandose_Invoice_Storage::PRIVACY_OPTION, $private_state( time() - 2 * DAY_IN_SECONDS ), false );
$http_calls = 0;
$allowed    = Plandose_Invoice_Storage::upload_allowed( false );
pdt_same( 0, $http_calls, '4d: upload_allowed( false ) makes no HTTP request' );
pdt_same( 'plandose_invoice_storage_not_checked', is_wp_error( $allowed ) ? $allowed->get_error_code() : $allowed, '4d: … and refuses a stale verdict' );

$http_calls = 0;
$run( $u_pl, false );
pdt_same( 0, $http_calls, '4d: page-load batch with a stale verdict → no probe' );
pdt_same( array( $att_pl ), array_map( 'intval', $row_list( $u_pl ) ), '4d: … and nothing copied' );

update_option( Plandose_Invoice_Storage::PRIVACY_OPTION, $private_state( time() ), false );
$http_calls = 0;
pdt_same( true, Plandose_Invoice_Storage::upload_allowed( false ), '4d: fresh «private» verdict → allowed without a probe' );
$run( $u_pl, false );
$after = $row_list( $u_pl );
pdt_same( 0, $http_calls, '4d: page-load batch with a fresh verdict → still no HTTP request' );
pdt_same( 0, count( array_filter( $after, 'is_numeric' ) ), '4d: … and the row is migrated' );
$remove_copies( $after );

// The cron / manual path still re-checks a stale verdict live.
update_option( Plandose_Invoice_Storage::PRIVACY_OPTION, $private_state( time() - 2 * DAY_IN_SECONDS ), false );
$http_calls = 0;
Plandose_Invoice_Storage::upload_allowed();
pdt_check( $http_calls > 0, '4d: upload_allowed() (cron, uploads) still probes a stale verdict' );

// maybe_migrate_legacy_invoices() passes $probe = false.
$mig_src = file_get_contents( PLANDOSE_PATH . 'includes/class-plandose-invoice-migration.php' );
pdt_check( false !== strpos( $mig_src, 'self::run_legacy_invoice_migration( false, self::INLINE_BUDGET_SECONDS, self::INLINE_BUDGET_FILES, false );' ), '4d: the page-load path asks for the cached verdict only' );

// ================================================================================
// 5. find-orphan-invoices.php
// ================================================================================

$tool = file_get_contents( PLANDOSE_PATH . 'tools/find-orphan-invoices.php' );
pdt_check( false !== strpos( $tool, 'Plandose_Invoice_Storage::readable_invoice_dir()' ) && false === strpos( $tool, '$plandose_dir = Plandose_Invoice_Storage::configured_invoice_dir();' ), '5: the orphan scanner reads only a marker-checked folder' );
pdt_check( false !== strpos( $tool, 'Plandose_Invoice_Storage::is_canary_name( $plandose_entry )' ), '5: … and skips probe canaries' );

pdt_done();
