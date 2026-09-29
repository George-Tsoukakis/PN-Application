<?php
/**
 * PlanDose 1.27.0 — php-admin checks against the test WordPress. TEST-ONLY.
 *
 *   php tests/php/admin/storage-admin-1270.php /home/claude/wpenv/wp-load.php
 *
 * Covers: verified-private storage (live probe → public → upload refused in
 * production; private custom folder → accepted; folder in the web root
 * without a URL → refused), WP-CLI never PASSing on the path alone, the
 * double-extension check, the octet-stream fallback, find-orphan-invoices
 * not creating folders, protection-file refresh, and the migration budget,
 * per-row cursor and orphan clean-up.
 *
 * The PHP built-in server of the test site ignores .htaccess, so its
 * default uploads folder really IS public: exactly the case to refuse.
 */
$_SERVER['HTTP_HOST'] = '127.0.0.1:8899';
$wp_load = $argv[1];
require $wp_load;

$fails = 0;
function check( $ok, $what ) {
	global $fails;
	echo ( $ok ? 'ok     ' : 'NOT OK ' ) . $what . "\n";
	if ( ! $ok ) {
		++$fails;
	}
}

function child( $scenario, $args ) {
	global $wp_load;
	$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/lib/child-1270.php' ) . ' ' . escapeshellarg( $wp_load ) . ' ' . escapeshellarg( $scenario ) . ' ' . escapeshellarg( wp_json_encode( $args ) );
	$raw = shell_exec( $cmd . ' 2>/dev/null' );
	$pos = strrpos( (string) $raw, '{' );
	$json = json_decode( false === $pos ? '' : substr( $raw, strpos( $raw, '{' ) ), true );
	return is_array( $json ) ? $json : array( 'raw' => $raw );
}

$tmp = sys_get_temp_dir() . '/pd-1270-' . wp_generate_password( 8, false, false );

// ---------------------------------------------------------------- L1
$cases = array(
	'invoice.pl.pdf'     => false,
	'timologio.js.pdf'   => false,
	'invoice.2024.03.pdf' => false,
	'invoice.pdf'        => false,
	'τιμολόγιο.pdf'      => false,
	'invoice.php.pdf'    => true,
	'a.phtml.jpg'        => true,
	'x.PHP5.pdf'         => true,
	'.htaccess.pdf'      => true,
	'report.html.pdf'    => true,
	'image.svg.png'      => true,
	'invoice.php_.pdf'   => true,
	'invoice.phar.pdf'   => true,
	'a.aspx.pdf'         => true,
	'a.shtml.pdf'        => true,
);
foreach ( $cases as $name => $expected ) {
	check( Plandose_Admin_Invoices::has_dangerous_inner_extension( $name ) === $expected, "L1: '$name' " . ( $expected ? 'refused' : 'accepted' ) );
}

// ---------------------------------------------------------------- L2
wp_mkdir_p( $tmp );
$pdf = $tmp . '/a.pdf';
file_put_contents( $pdf, "%PDF-1.4\n%\xE2\xE3\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n" );
$png = $tmp . '/a.png';
file_put_contents( $png, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ) );
$junk = $tmp . '/junk.pdf';
file_put_contents( $junk, "MZ\x90\x00 not a pdf" );

check( true === Plandose_Invoice_Storage::content_matches_mime_sniffed( $pdf, 'application/pdf', 'application/octet-stream' ), 'L2: octet-stream from fileinfo + real %PDF- signature → accepted' );
check( true === Plandose_Invoice_Storage::content_matches_mime_sniffed( $png, 'image/png', 'application/octet-stream' ), 'L2: octet-stream + real PNG header (getimagesize) → accepted' );
check( false === Plandose_Invoice_Storage::content_matches_mime_sniffed( $junk, 'application/pdf', 'application/octet-stream' ), 'L2: octet-stream + no PDF signature → refused' );
check( false === Plandose_Invoice_Storage::content_matches_mime_sniffed( $pdf, 'application/pdf', 'text/plain' ), 'L2: a definite other type is still refused' );
check( true === Plandose_Invoice_Storage::content_matches_mime( $pdf, 'application/pdf' ), 'L2: normal fileinfo path unchanged' );

// ---------------------------------------------------------------- B.1 probe on the default (public) folder
$priv_backup = get_option( Plandose_Invoice_Storage::PRIVACY_OPTION, null );
$dir         = Plandose_Invoice_Storage::invoice_dir();
check( ! is_wp_error( $dir ), 'B1: default invoice folder available' );

$state = Plandose_Invoice_Storage::probe_privacy( $dir, true );
check( 'public' === $state['status'] && 200 === $state['http_code'], 'B1: probe of the default folder on the built-in server → public (HTTP 200 with canary): ' . $state['status'] . '/' . $state['reason'] );
check( ! glob( trailingslashit( $dir ) . Plandose_Invoice_Storage::CANARY_PREFIX . '*' ), 'B1: canary deleted after the probe' );
$cached = get_option( Plandose_Invoice_Storage::PRIVACY_OPTION );
check( is_array( $cached ) && 'public' === $cached['status'] && $cached['checked_at'] > time() - 60, 'B1: verdict cached (non-autoloaded option)' );

add_filter( 'plandose_invoice_storage_is_production', '__return_true' );
$allowed = Plandose_Invoice_Storage::upload_allowed();
check( is_wp_error( $allowed ) && 'plandose_invoice_storage_not_private' === $allowed->get_error_code(), 'B1: production + public default folder → upload refused' );
check( is_wp_error( $allowed ) && false !== strpos( $allowed->get_error_message(), 'PLANDOSE_INVOICE_DIR' ), 'B1: refusal message says how to fix it' );

define( 'PLANDOSE_ALLOW_UNVERIFIED_INVOICE_DIR', true );
check( is_wp_error( Plandose_Invoice_Storage::upload_allowed() ), 'B1: the unverified override never overrides a PUBLIC verdict' );
remove_filter( 'plandose_invoice_storage_is_production', '__return_true' );

add_filter( 'plandose_invoice_storage_is_production', '__return_false' );
// 1.27.5: a PUBLIC folder is refused on every site, test sites included.
check( is_wp_error( Plandose_Invoice_Storage::upload_allowed() ), 'B1: non-production + public folder → upload refused too (1.27.5)' );
remove_filter( 'plandose_invoice_storage_is_production', '__return_false' );

$diag = Plandose_Diagnostics::privacy_result( Plandose_Invoice_Storage::privacy_status( false ) );
check( 'FAIL' === $diag['status'], 'B2: diagnostics reports the public folder as FAIL' );

// ---------------------------------------------------------------- Round 3: fail closed + URL encoding
$uploads_base = wp_get_upload_dir();
$enc = Plandose_Invoice_Storage::path_to_url( $uploads_base['basedir'] . '/a#1/b%41/c d/τιμ/x+y' );
check( $enc === $uploads_base['baseurl'] . '/a%231/b%2541/c%20d/' . rawurlencode( 'τιμ' ) . '/x%2By', 'R3a: path_to_url encodes every segment: ' . $enc );

$leftover = function () use ( $dir, $uploads_base ) {
	return array_merge(
		(array) glob( trailingslashit( $dir ) . Plandose_Invoice_Storage::CANARY_PREFIX . '*' ),
		(array) glob( trailingslashit( $uploads_base['basedir'] ) . Plandose_Invoice_Storage::CANARY_PREFIX . '*' )
	);
};

// Blocked loopback / firewall: everything 403 → must NOT read as private.
$block_all = static function () {
	return array( 'headers' => array(), 'body' => 'Forbidden', 'response' => array( 'code' => 403, 'message' => 'Forbidden' ), 'cookies' => array(), 'filename' => null );
};
add_filter( 'pre_http_request', $block_all );
$state = Plandose_Invoice_Storage::probe_privacy( $dir, false );
remove_filter( 'pre_http_request', $block_all );
check( 'unverified' === $state['status'] && 'control_failed' === $state['reason'], 'R3b: every request 403 (firewall/blocked loopback) → unverified/control_failed, not private: ' . $state['status'] . '/' . $state['reason'] );
check( ! $leftover(), 'R3b: both canaries deleted' );

foreach ( array( 404, 429 ) as $blocked_code ) {
	$only_code = static function () use ( $blocked_code ) {
		return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => $blocked_code, 'message' => '' ), 'cookies' => array(), 'filename' => null );
	};
	add_filter( 'pre_http_request', $only_code );
	$state = Plandose_Invoice_Storage::probe_privacy( $dir, false );
	remove_filter( 'pre_http_request', $only_code );
	check( 'unverified' === $state['status'], "R3b: every request $blocked_code → unverified" );
}

// Only the invoice folder refused, control really served → private.
$deny_invoices = static function ( $pre, $args, $url ) {
	if ( false !== strpos( $url, '/plandose-invoices/' ) ) {
		return array( 'headers' => array(), 'body' => 'nope', 'response' => array( 'code' => 404, 'message' => 'Not Found' ), 'cookies' => array(), 'filename' => null );
	}
	return $pre;
};
add_filter( 'pre_http_request', $deny_invoices, 10, 3 );
$state = Plandose_Invoice_Storage::probe_privacy( $dir, false );
remove_filter( 'pre_http_request', $deny_invoices, 10 );
check( 'private' === $state['status'] && 'http_refused' === $state['reason'] && 200 === $state['control_code'], 'R3b: invoice canary 404 + control 200 with content → private: ' . wp_json_encode( $state ) );
check( ! $leftover(), 'R3b: both canaries deleted (private path)' );

// Folder names that break an unencoded URL, on the real server (public).
foreach ( array( 'pdtest#1', 'pdtest%41', 'pd test', 'τιμολόγια', 'a+b' ) as $odd ) {
	$odd_dir = $uploads_base['basedir'] . '/pd1270-' . wp_generate_password( 4, false, false ) . '/' . $odd;
	$r       = child( 'probe_public', array( 'define' => array( 'PLANDOSE_INVOICE_DIR' => $odd_dir ) ) );
	check( isset( $r['status'] ) && 'public' === $r['status'] && 200 === $r['code'], "R3a: public folder «{$odd}» detected as public: " . wp_json_encode( $r ) );
	exec( 'rm -rf ' . escapeshellarg( dirname( $odd_dir ) ) );
}

// ---------------------------------------------------------------- B.1 private custom folder (child process)
$private_dir = $tmp . '/private/plandose-invoices';
$r = child(
	'upload_allowed',
	array(
		'define'     => array( 'PLANDOSE_INVOICE_DIR' => $private_dir ),
		'docroot'    => ABSPATH,
		'production' => true,
	)
);
check( isset( $r['status'] ) && 'private' === $r['status'] && 'outside_web_root' === $r['reason'], 'B1: custom folder outside the web root → private without a request: ' . wp_json_encode( $r ) );
check( ! empty( $r['allowed'] ), 'B1: production + verified private folder → upload accepted' );
check( isset( $r['diag'] ) && 'PASS' === $r['diag'], 'B1: diagnostics PASS when DOCUMENT_ROOT is known' );

// M1: same folder, WP-CLI without DOCUMENT_ROOT → never PASS.
$r = child( 'upload_allowed', array( 'define' => array( 'PLANDOSE_INVOICE_DIR' => $tmp . '/private2/plandose-invoices' ) ) );
check( isset( $r['diag'] ) && 'WARN' === $r['diag'], 'M1: WP-CLI without DOCUMENT_ROOT/--docroot → WARN, not PASS: ' . wp_json_encode( $r ) );

// Folder inside the document root but without a WordPress URL → unverified → refused.
$r = child(
	'upload_allowed',
	array(
		'define'     => array( 'PLANDOSE_INVOICE_DIR' => $tmp . '/web/plandose-invoices' ),
		'docroot'    => $tmp,
		'production' => true,
	)
);
check( isset( $r['status'] ) && 'unverified' === $r['status'] && 'in_web_root_no_url' === $r['reason'], 'B1: folder in DOCUMENT_ROOT without a URL → unverified: ' . wp_json_encode( $r ) );
check( empty( $r['allowed'] ) && 'plandose_invoice_storage_not_private' === $r['error'], 'B1: production + unverified → upload refused' );

$r = child(
	'upload_allowed',
	array(
		'define'     => array(
			'PLANDOSE_INVOICE_DIR'                 => $tmp . '/web2/plandose-invoices',
			'PLANDOSE_ALLOW_UNVERIFIED_INVOICE_DIR' => true,
		),
		'docroot'    => $tmp,
		'production' => true,
	)
);
check( ! empty( $r['allowed'] ), 'B1: production + unverified + PLANDOSE_ALLOW_UNVERIFIED_INVOICE_DIR → accepted' );

// ---------------------------------------------------------------- L6: orphan tool creates nothing
$ghost = $tmp . '/ghost/deeper/plandose-invoices';
$r     = child(
	'orphan_tool',
	array(
		'define' => array( 'PLANDOSE_INVOICE_DIR' => $ghost ),
		'tool'   => WP_PLUGIN_DIR . '/plandose/tools/find-orphan-invoices.php',
	)
);
check( isset( $r['dir_exists'] ) && false === $r['dir_exists'] && ! is_dir( $tmp . '/ghost' ), 'L6: find-orphan-invoices does not create the invoice folder' );
check( isset( $r['output'] ) && false !== strpos( $r['output'], 'does not exist yet' ), 'L6: find-orphan-invoices says there is nothing to scan' );

// ---------------------------------------------------------------- L16: protection file refresh
$web_config = trailingslashit( $dir ) . 'web.config';
$old        = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n    <security>\n      <authorization>\n        <remove users=\"*\" roles=\"\" verbs=\"\" />\n        <add accessType=\"Deny\" users=\"*\" />\n      </authorization>\n    </security>\n  </system.webServer>\n</configuration>\n";
file_put_contents( $web_config, $old );
check( 'outdated' === Plandose_Invoice_Storage::protection_file_state( $web_config, 'web.config' ), 'L16: the pre-1.27 web.config is recognised as an old PlanDose template' );
Plandose_Invoice_Storage::invoice_dir();
check( false !== strpos( (string) file_get_contents( $web_config ), 'requestFiltering' ), 'L16: invoice_dir() rewrites it with the requestFiltering deny-all' );
file_put_contents( $web_config, "<configuration><!-- edited by the host --></configuration>\n" );
Plandose_Invoice_Storage::invoice_dir();
check( false !== strpos( (string) file_get_contents( $web_config ), 'edited by the host' ), 'L16: a web.config someone else edited is left alone' );
check( 'foreign' === Plandose_Invoice_Storage::protection_file_state( $web_config, 'web.config' ), 'L16: … and reported as foreign' );
$tpl = Plandose_Invoice_Storage::protection_file_templates();
file_put_contents( $web_config, $tpl['web.config'] );

// ---------------------------------------------------------------- M2: migration budget, cursor, clean-up
$opt_names = array(
	Plandose_Invoice_Migration::LEGACY_MIGRATION_OPTION,
	Plandose_Invoice_Migration::LEGACY_MIGRATION_CURSOR_OPTION,
	Plandose_Invoice_Migration::LEGACY_MIGRATION_FAILED_OPTION,
	Plandose_Invoice_Migration::PENDING_COPIES_OPTION,
	Plandose_Admin_Invoices::LEGACY_ORIGINALS_OPTION,
);
$opt_backup = array();
foreach ( $opt_names as $name ) {
	$opt_backup[ $name ] = get_option( $name, null );
	delete_option( $name );
}

$login = 'pdt_mig_' . wp_generate_password( 6, false, false );
$uid   = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login . '@example.test', 'role' => 'subscriber' ) );
update_user_meta( $uid, 'account_type', 'Φαρμακείο' );
Plandose_Subscriptions::get_row( $uid );

$attachments = array();
$uploads     = wp_upload_dir();
for ( $i = 0; $i < 5; $i++ ) {
	$file = trailingslashit( $uploads['path'] ) . 'pd-legacy-' . $login . '-' . $i . '.pdf';
	file_put_contents( $file, "%PDF-1.4\n% legacy $i\n%%EOF\n" );
	$attachments[] = wp_insert_attachment( array( 'post_mime_type' => 'application/pdf', 'post_title' => 'legacy ' . $i, 'post_status' => 'inherit' ), $file );
}
Plandose_Subscriptions::update( $uid, array( 'invoices' => wp_json_encode( $attachments ) ) );

$run = new ReflectionMethod( 'Plandose_Invoice_Migration', 'run_legacy_invoice_migration' );
$run->setAccessible( true );

// 1.28.2: the migration copies only into storage verified private, like
// uploads. This built-in server serves the default folder (B1: PUBLIC), so
// first: nothing is copied there …
$mig_dir = Plandose_Invoice_Storage::invoice_dir();
update_option(
	Plandose_Invoice_Storage::PRIVACY_OPTION,
	array( 'status' => 'public', 'reason' => 'served', 'checked_at' => time(), 'dir' => untrailingslashit( wp_normalize_path( $mig_dir ) ), 'home' => home_url( '/' ), 'url' => '', 'http_code' => 200, 'detail' => '', 'docroot_known' => true ),
	false
);
$run->invoke( null, false, 60, 2 );
$still = Plandose_Subscriptions::decode_invoices( Plandose_Subscriptions::get_row( $uid, false )->invoices );
check( 5 === count( array_filter( $still, 'is_numeric' ) ), '1.28.2 M2: storage verified PUBLIC → the migration copies nothing' );
check( ! glob( trailingslashit( $mig_dir ) . Plandose_Invoice_Migration::COPY_PREFIX . '*' ), '1.28.2 M2: … and writes no file there' );

// … then the rest of M2/M3 with the folder marked verified private.
update_option(
	Plandose_Invoice_Storage::PRIVACY_OPTION,
	array( 'status' => 'private', 'reason' => 'denied', 'checked_at' => time(), 'dir' => untrailingslashit( wp_normalize_path( $mig_dir ) ), 'home' => home_url( '/' ), 'url' => '', 'http_code' => 404, 'detail' => '', 'docroot_known' => true ),
	false
);

$list = function () use ( $uid ) {
	$row = Plandose_Subscriptions::get_row( $uid, false );
	return Plandose_Subscriptions::decode_invoices( $row ? $row->invoices : '' );
};
$count_numeric = function ( $entries ) {
	return count( array_filter( $entries, 'is_numeric' ) );
};

$hit = $run->invoke( null, false, 20, 2 );
$after1 = $list();
check( true === $hit, 'M2: run stops on its file budget (2 copies)' );
check( 3 === $count_numeric( $after1 ), 'M2: the 2 copies made are committed to the row (3 legacy entries left): ' . wp_json_encode( $after1 ) );
check( (int) get_option( Plandose_Invoice_Migration::LEGACY_MIGRATION_CURSOR_OPTION, 0 ) < $uid, 'M2: cursor stays before the partly migrated row' );
check( ! get_option( Plandose_Invoice_Migration::LEGACY_MIGRATION_OPTION ), 'M2: not marked done' );
check( ! get_option( Plandose_Invoice_Migration::PENDING_COPIES_OPTION ), 'M2: pending-copies journal empty after a committed row' );
$names_ok = true;
foreach ( $after1 as $entry ) {
	if ( ! is_numeric( $entry ) && 1 !== preg_match( Plandose_Invoice_Migration::COPY_PATTERN, $entry ) ) {
		$names_ok = false;
	}
}
check( $names_ok, 'M2: migrated copies use the lm-<32>.<ext> naming' );

$run->invoke( null, false, 20, 2 );
$run->invoke( null, false, 20, 2 );
$after3 = $list();
check( 0 === $count_numeric( $after3 ), 'M2: later runs finish the row: ' . wp_json_encode( $after3 ) );
check( (int) get_option( Plandose_Invoice_Migration::LEGACY_MIGRATION_CURSOR_OPTION, 0 ) >= $uid || get_option( Plandose_Invoice_Migration::LEGACY_MIGRATION_OPTION ), 'M2: cursor persisted past the finished row (or pass done)' );

$hit0 = $run->invoke( null, false, 0, 25 );
check( is_bool( $hit0 ), 'M2: a zero time budget is clamped (no fatal)' );

// Orphans of an interrupted run.
$orphan    = 'lm-' . wp_generate_password( 32, false, false ) . '.pdf';
$kept      = $after3[0];
$foreign   = 'someone-else.pdf';
file_put_contents( trailingslashit( $dir ) . $orphan, '%PDF-1.4 orphan' );
file_put_contents( trailingslashit( $dir ) . $foreign, '%PDF-1.4 foreign' );
update_option( Plandose_Invoice_Migration::PENDING_COPIES_OPTION, array( $orphan, $kept, $foreign ), false );
$deleted = Plandose_Invoice_Migration::cleanup_unrecorded_copies();
clearstatcache();
check( 1 === $deleted && ! file_exists( trailingslashit( $dir ) . $orphan ), 'M2: unrecorded lm- copy from a killed run is deleted' );
check( file_exists( trailingslashit( $dir ) . $kept ), 'M2: a journaled copy that IS referenced is kept' );
check( file_exists( trailingslashit( $dir ) . $foreign ), 'M2: a file not matching the migration naming is never touched' );
check( ! get_option( Plandose_Invoice_Migration::PENDING_COPIES_OPTION ), 'M2: journal cleared' );
unlink( trailingslashit( $dir ) . $foreign );

// Screen trigger only schedules; nothing on a non-PlanDose request.
check( false !== has_action( Plandose_Invoice_Migration::CRON_HOOK, array( 'Plandose_Invoice_Migration', 'run_cron_batch' ) ), 'M2: cron batch hooked outside wp-admin' );
check( false === has_action( 'admin_init', array( 'Plandose_Invoice_Migration', 'maybe_migrate_legacy_invoices' ) ), 'M2: no longer runs on every admin_init' );

// ---------------------------------------------------------------- M3: removed legacy entry is remembered
wp_set_current_user( 1 );
// Copy deleted → original marked copy_missing at once.
$copy_name = $after3[1];
$orig      = Plandose_Admin_Invoices::legacy_originals();
$att_for   = 0;
foreach ( $orig as $aid => $entry ) {
	if ( $entry['filename'] === $copy_name ) {
		$att_for = $aid;
	}
}
unlink( trailingslashit( $dir ) . $copy_name );
Plandose_Admin_Invoices::mark_legacy_copy_removed( $copy_name );
$orig = Plandose_Admin_Invoices::legacy_originals();
check( $att_for && isset( $orig[ $att_for ]['skip'] ) && 'copy_missing' === $orig[ $att_for ]['skip'], 'M3: deleting the only copy marks the original copy_missing (explicit delete offered)' );

$extra_file = trailingslashit( $uploads['path'] ) . 'pd-legacy-' . $login . '-x.pdf';
file_put_contents( $extra_file, "%PDF-1.4\n% legacy x\n%%EOF\n" );
$extra_id = wp_insert_attachment( array( 'post_mime_type' => 'application/pdf', 'post_title' => 'legacy x', 'post_status' => 'inherit' ), $extra_file );
check( Plandose_Admin_Invoices::record_uncopied_legacy_original( $extra_id, $uid ), 'M3: removed legacy entry recorded' );
$orig = Plandose_Admin_Invoices::legacy_originals();
check( isset( $orig[ $extra_id ] ) && ! empty( $orig[ $extra_id ]['uncopied'] ), 'M3: … as an uncopied original' );
$batch = Plandose_Admin_Invoices::delete_legacy_originals_batch( 50 );
$orig  = Plandose_Admin_Invoices::legacy_originals();
check( get_post( $extra_id ) && isset( $orig[ $extra_id ]['skip'] ) && 'no_copy' === $orig[ $extra_id ]['skip'], 'M3: the verified batch delete skips it (no copy)' );
$res = Plandose_Admin_Invoices::delete_uncopied_legacy_original( $extra_id );
clearstatcache();
check( true === $res && ! get_post( $extra_id ) && ! file_exists( $extra_file ), 'M3: explicit delete removes the public original' );

// ---------------------------------------------------------------- Round 4: expected columns
$cols = Plandose_Diagnostics::expected_columns();
$req  = Plandose_Print_Charges::requests_table_name();
check( isset( $cols[ $req ] ) && in_array( 'replay_count', $cols[ $req ], true ), 'R4: replay_count expected on print_requests (parsed from schema_sql())' );
// Every table the plugin owns (plugin_tables(), the list the engine and
// charset checks use) has its columns parsed from a schema_sql() — no count
// to update when a table is added.
$pd_tables   = array_keys( Plandose_Diagnostics::plugin_tables() );
$pd_expected = array_keys( $cols );
sort( $pd_tables );
sort( $pd_expected );
check( count( $pd_tables ) > 0 && $pd_tables === $pd_expected, 'R4: expected columns known for all ' . count( $pd_tables ) . ' plugin tables: ' . wp_json_encode( array_values( array_diff( $pd_tables, $pd_expected ) ) ) );
check( isset( $cols[ Plandose_Print_Log::table_name() ] ) && array() !== $cols[ Plandose_Print_Log::table_name() ], 'R4: print-log table columns known' );
$ok_row = Plandose_Diagnostics::columns_result();
check( 'PASS' === $ok_row['status'], 'R4: live schema complete → PASS' );

// Throw-away copy without replay_count, checked as if it were print_requests.
global $wpdb;
$copy = $wpdb->prefix . 'pd1270_requests_copy';
$wpdb->query( "DROP TABLE IF EXISTS `{$copy}`" ); // phpcs:ignore
$wpdb->query( "CREATE TABLE `{$copy}` LIKE `{$req}`" ); // phpcs:ignore
$wpdb->query( "ALTER TABLE `{$copy}` DROP COLUMN replay_count" ); // phpcs:ignore
$as_copy = static function ( $columns ) use ( $copy, $req ) {
	$columns[ $copy ] = $columns[ $req ];
	return $columns;
};
add_filter( 'plandose_diagnostics_expected_columns', $as_copy );
$bad_row = Plandose_Diagnostics::columns_result();
$db_rows = Plandose_Diagnostics::check_database();
remove_filter( 'plandose_diagnostics_expected_columns', $as_copy );
$wpdb->query( "DROP TABLE IF EXISTS `{$copy}`" ); // phpcs:ignore
check( 'FAIL' === $bad_row['status'] && in_array( $copy . '.replay_count', $bad_row['details'], true ), 'R4: missing replay_count → FAIL naming the column: ' . wp_json_encode( $bad_row['details'] ) );
check( 0 === strpos( $bad_row['hint'], 'Απενεργοποιήστε και ενεργοποιήστε ξανά το PlanDose (η αναβάθμιση της βάσης θα ξανατρέξει). Αν συνεχίζει, ζητήστε από τον πάροχο να ελέγξει ότι ο χρήστης της βάσης έχει δικαίωμα ALTER.' ), 'R4: fix hint as specified' );
check( in_array( 'FAIL', wp_list_pluck( $db_rows, 'status' ), true ), 'R4: check_database() (Διαγνωστικά + wp plandose check) carries the FAIL' );

$parsed = Plandose_Diagnostics::parse_create_table( "CREATE TABLE t_x (\n id BIGINT NOT NULL,\n `name` VARCHAR(5),\n PRIMARY KEY  (id),\n UNIQUE KEY u (name),\n KEY k (name)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;" );
check( $parsed && 't_x' === $parsed['table'] && array( 'id', 'name' ) === $parsed['columns'], 'R4: parse_create_table skips keys: ' . wp_json_encode( $parsed ) );

// ---------------------------------------------------------------- L14 / L4
check( 0 === Plandose_Admin_Invoices::parse_user_id( '4abc' ) && 0 === Plandose_Admin_Invoices::parse_user_id( array( '4' ) ), 'L4: parse_user_id refuses non-digit input' );

// ---------------------------------------------------------------- clean-up
foreach ( $list() as $entry ) {
	if ( ! is_numeric( $entry ) && file_exists( trailingslashit( $dir ) . $entry ) ) {
		unlink( trailingslashit( $dir ) . $entry );
	}
}
foreach ( $attachments as $aid ) {
	wp_delete_attachment( $aid, true );
}
Plandose_Subscriptions::delete_row( $uid );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $uid );
foreach ( $opt_backup as $name => $value ) {
	if ( null === $value ) {
		delete_option( $name );
	} else {
		update_option( $name, $value, false );
	}
}
if ( null === $priv_backup ) {
	delete_option( Plandose_Invoice_Storage::PRIVACY_OPTION );
} else {
	update_option( Plandose_Invoice_Storage::PRIVACY_OPTION, $priv_backup, false );
}
exec( 'rm -rf ' . escapeshellarg( $tmp ) );

echo $fails ? "FAILED: $fails\n" : "ALL OK\n";
exit( $fails ? 1 : 0 );
