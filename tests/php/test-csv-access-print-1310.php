<?php
/**
 * 1.31.0: coverage for the subscribers CSV export, the account-type guard,
 * the print transaction / new-charge helpers and the pharmacy text
 * normalisation, against the real test WordPress + MariaDB. TEST-ONLY.
 *
 * - CSV: csv_safe() (formula injection), csv_row_from_item(),
 *   effective_pro_days(), and handle_export_csv() end-to-end (streamed in
 *   a child PHP process — it ends with exit — and refused in-process);
 * - Plandose_Account_Type: a non-admin cannot add/update/delete their own
 *   category (any key spelling MySQL treats as the same key, by meta ID
 *   too) and the refusal is audited; admin, registration window, cron,
 *   WP-CLI and the trusted-context filter are allowed;
 * - Plandose_Print_Transaction begin/commit/rollback/lost() with a real
 *   KILLed connection; Plandose_Print_New_Charge::commit_confirmed(),
 *   ensure_charge_records(), charge_locked() failure paths;
 *   Plandose_Print_Charges::use_reprint(), forget_request(),
 *   engines_state();
 * - Plandose_Access::normalize_text() / accent_map(): pharmacy spellings.
 */

$pdt_is_child = isset( $argv[1] ) && '--export-child' === $argv[1];

require __DIR__ . '/lib.php';

/* ---- child: stream the CSV export as the given admin ----------------------- */
if ( $pdt_is_child ) {
	$admin_id = isset( $argv[2] ) ? (int) $argv[2] : 0;
	wp_set_current_user( $admin_id );
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST                     = array( '_wpnonce' => wp_create_nonce( 'plandose_export_csv' ) );
	$_REQUEST                  = $_POST;

	// The subscribers the export should list, as the list itself sees them.
	$ids   = array();
	$after = 0;
	do {
		$batch = Plandose_Subscriber_Query::query_subscribers( array( 'after_id' => $after, 'per_page' => 500, 'with_total' => false ) );
		foreach ( $batch['items'] as $item ) {
			$ids[] = (int) $item->user->ID;
		}
		$more  = $batch['last_id'] > $after;
		$after = $batch['last_id'];
	} while ( $more );
	fwrite( STDERR, 'PDT_IDS ' . wp_json_encode( $ids ) . "\n" ); // phpcs:ignore

	Plandose_Admin_Subscriptions::handle_export_csv();
	fwrite( STDERR, "PDT_RETURNED\n" ); // phpcs:ignore -- handle_export_csv() must exit.
	exit( 3 );
}

global $wpdb;

ini_set( 'error_log', '/dev/null' ); // phpcs:ignore -- expected log lines.

$audit_table = Plandose_Subscriptions::audit_table_name();
$my_users    = array();

/** Run a handler; its redirect / wp_die come back as a string. */
function pdt1310_run( $callback ) {
	$redirect = static function ( $location ) {
		throw new RuntimeException( 'redirect:' . $location );
	};
	$die      = static function () {
		return static function ( $message, $title = '', $args = array() ) {
			$code = is_array( $args ) && isset( $args['response'] ) ? $args['response'] : ( is_int( $args ) ? $args : '' );
			throw new RuntimeException( 'die:' . $code . ':' . wp_strip_all_tags( is_string( $message ) ? $message : '' ) );
		};
	};
	// Never let the export stream (and exit) in THIS process: a nonce that
	// verifies ends the call here.
	$nonce = static function ( $action, $result ) {
		if ( $result ) {
			throw new RuntimeException( 'nonce-passed:' . $action );
		}
	};
	add_filter( 'wp_redirect', $redirect );
	add_filter( 'wp_die_handler', $die );
	add_action( 'check_admin_referer', $nonce, 10, 2 );
	try {
		call_user_func( $callback );
		$out = 'returned';
	} catch ( RuntimeException $e ) {
		$out = $e->getMessage();
	}
	remove_filter( 'wp_redirect', $redirect );
	remove_filter( 'wp_die_handler', $die );
	remove_action( 'check_admin_referer', $nonce, 10 );

	return $out;
}

/** KILL wpdb's own connection through a second one (as test-print-1302). */
function pdt1310_kill() {
	global $wpdb;
	$thread = $wpdb->dbh instanceof mysqli ? (int) $wpdb->dbh->thread_id : 0;
	$host   = DB_HOST;
	$port   = null;
	if ( preg_match( '/^(.+):(\d+)$/', $host, $m ) ) {
		$host = $m[1];
		$port = (int) $m[2];
	}
	$side = new mysqli( $host, DB_USER, DB_PASSWORD, DB_NAME, $port );
	$side->query( 'KILL ' . $thread );
	$side->close();
	usleep( 100000 );
}

/** Plandose_Print_Result fields. */
function pdt1310_result( $result ) {
	$out = array();
	foreach ( array( 'success', 'payload' ) as $prop ) {
		$p = new ReflectionProperty( 'Plandose_Print_Result', $prop );
		$p->setAccessible( true );
		$out[ $prop ] = $p->getValue( $result );
	}
	return $out;
}

/** Count audit rows. */
function pdt1310_audit_count( $event, $target = null, $admin = null ) {
	global $wpdb;
	$sql  = 'SELECT COUNT(*) FROM ' . Plandose_Subscriptions::audit_table_name() . ' WHERE event = %s';
	$args = array( $event );
	if ( null !== $target ) {
		$sql   .= ' AND target_user_id = %d';
		$args[] = $target;
	}
	if ( null !== $admin ) {
		$sql   .= ' AND admin_user_id = %d';
		$args[] = $admin;
	}
	return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore
}

$new_user = static function ( $meta = array(), $role = 'subscriber' ) use ( &$my_users ) {
	$id         = pdt_user( $meta, $role );
	$my_users[] = $id;
	return $id;
};

// Audit rows and leftover category meta of this file's users (runs before
// pdt_user's own cleanup, which deletes users with no current user — the
// guard would keep their category rows).
pdt_defer(
	static function () use ( &$my_users, $wpdb, $audit_table ) {
		if ( ! $my_users ) {
			return;
		}
		$in = implode( ',', array_map( 'intval', $my_users ) );
		$wpdb->query( "DELETE FROM {$audit_table} WHERE admin_user_id IN ({$in}) OR target_user_id IN ({$in})" ); // phpcs:ignore
		$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE user_id IN ({$in})" ); // phpcs:ignore
		$wpdb->query( 'DELETE FROM ' . Plandose_Print_Log::table_name() . " WHERE user_id IN ({$in})" ); // phpcs:ignore
		foreach ( $my_users as $id ) {
			delete_transient( 'plandose_acct_blocked_' . $id );
		}
	}
);

/* ======================================================================
 * 1. CSV export
 * ==================================================================== */

// ---- csv_safe() ---------------------------------------------------------
$safe = static function ( $v ) {
	return pdt_call( 'Plandose_Admin_Subscriptions', 'csv_safe', $v );
};
pdt_same( "'=HYPERLINK(\"http://evil\",\"x\")", $safe( '=HYPERLINK("http://evil","x")' ), 'csv_safe: leading = is prefixed with a quote' );
pdt_same( "'+306912345678", $safe( '+306912345678' ), 'csv_safe: leading + (an international mobile) is prefixed — documented «force text»' );
pdt_same( "'-12", $safe( '-12' ), 'csv_safe: leading - is prefixed, even a plain negative number (by design: shown as text, value kept)' );
pdt_same( "'-12", $safe( -12 ), 'csv_safe: an int -12 likewise' );
pdt_same( "'@SUM(A1:A9)", $safe( '@SUM(A1:A9)' ), 'csv_safe: leading @ is prefixed' );
pdt_same( "' =1+1", $safe( ' =1+1' ), 'csv_safe: = after leading spaces is still caught' );
pdt_same( "'\t=1+1", $safe( "\t=1+1" ), 'csv_safe: tab + = is prefixed' );
pdt_same( "'\tplain", $safe( "\tplain" ), 'csv_safe: a leading tab alone is prefixed' );
pdt_same( "'\rplain", $safe( "\rplain" ), 'csv_safe: a leading CR is prefixed' );
pdt_same( "'\nplain", $safe( "\nplain" ), 'csv_safe: a leading LF is prefixed' );
pdt_same( "'=cmd", $safe( "\0=cmd" ), 'csv_safe: NUL bytes are removed before the check (no bypass via \\0)' );
pdt_same( 'a=b', $safe( 'a=b' ), 'csv_safe: = inside a value is untouched' );
pdt_same( 'Φαρμακείο Παπαδοπούλου «Υγεία»', $safe( 'Φαρμακείο Παπαδοπούλου «Υγεία»' ), 'csv_safe: Greek text untouched' );
pdt_same( '123456789', $safe( '123456789' ), 'csv_safe: a plain AFM untouched' );
pdt_same( '42', $safe( 42 ), 'csv_safe: a positive int becomes its string' );
pdt_same( '', $safe( '' ), 'csv_safe: empty stays empty' );
pdt_same( '', $safe( null ), 'csv_safe: null → empty' );
pdt_same( 'x@y.gr', $safe( 'x@y.gr' ), 'csv_safe: an email (@ not first) untouched' );

// ---- csv_row_from_item() ------------------------------------------------
$prev_ym = Plandose_Subscriptions::recent_months( 1 )[0];
$old_ym  = Plandose_Subscriptions::recent_months( 3 )[2];
$item    = (object) array(
	'user'     => (object) array( 'ID' => 77, 'display_name' => '+Evil Name', 'user_email' => '=x@y.gr' ),
	'pharmacy' => '=cmd|"/c calc"!A1',
	'mobile'   => '6912345678',
	'afm'      => '@123',
	'is_pro'   => true,
	'row'      => (object) array(
		'sub_end_date'   => '2027-01-31',
		'last_print_at'  => '2026-09-01 10:00:00',
		'print_count'    => 5,
		'count_reset_at' => current_time( 'Y-m-d' ) . ' 00:00:00',
	),
);
$row = Plandose_Admin_Subscriptions::csv_row_from_item( $item, array( $prev_ym => 4, $old_ym => 3, '2000-01' => 0 ) );
pdt_same(
	array( 77, "'=cmd|\"/c calc\"!A1", '6912345678', "'@123", "'+Evil Name", "'=x@y.gr", 'Pro', '2027-01-31', 5, '2026-09-01 10:00:00', 4, 7, 2 ),
	$row,
	'csv_row_from_item: 13 columns in header order, text fields neutralised, history summed'
);
$item->is_pro                   = false;
$item->row->count_reset_at      = '2000-01-01 00:00:00';
$row                            = Plandose_Admin_Subscriptions::csv_row_from_item( $item );
pdt_same( array( 'Free', 0, 0, 0, 0 ), array( $row[6], $row[8], $row[10], $row[11], $row[12] ), 'csv_row_from_item: Free; a counter of another month is 0; no history → zeros' );
$row = Plandose_Admin_Subscriptions::csv_row_from_item( (object) array(), 'not-an-array' );
pdt_same( array( 0, '', '', '', '', '', 'Free', '', 0, '', 0, 0, 0 ), $row, 'csv_row_from_item: an empty item and a non-array history do not break the row' );

// ---- effective_pro_days() -------------------------------------------------
$fake_settings = null;
$pre_settings  = static function ( $pre ) use ( &$fake_settings ) {
	return null === $fake_settings ? $pre : $fake_settings;
};
add_filter( 'pre_option_plandose_settings', $pre_settings );
$epd = static function ( $days ) {
	return pdt_call( 'Plandose_Admin_Subscriptions', 'effective_pro_days', $days );
};
$fake_settings = array_merge( (array) get_option( 'plandose_settings', array() ), array( 'default_pro_days' => 400, 'max_add_days' => 200 ) );
pdt_same( 200, $epd( 0 ), 'effective_pro_days: default above the ceiling → clamped to max_add_days' );
pdt_same( 50, $epd( 50 ), 'effective_pro_days: an explicit request below the ceiling is kept' );
pdt_same( 200, $epd( 5000 ), 'effective_pro_days: an explicit request above the ceiling is clamped' );
pdt_same( 200, $epd( -5 ), 'effective_pro_days: a negative request falls back to the (clamped) default' );
pdt_same( 200, $epd( 'abc' ), 'effective_pro_days: a non-numeric request falls back to the default' );
$fake_settings = array_merge( (array) get_option( 'plandose_settings', array() ), array( 'default_pro_days' => 30, 'max_add_days' => 1095 ) );
pdt_same( 30, $epd( 0 ), 'effective_pro_days: default below the ceiling is used as is' );
$fake_settings = null;
remove_filter( 'pre_option_plandose_settings', $pre_settings );

// ---- handle_export_csv(): refusals (in-process) --------------------------
$admin    = $new_user( array(), 'administrator' );
$customer = $new_user( array( 'account_type' => 'Εταιρία', 'pharmacy_name' => 'PDT Εταιρία' ) );
$ph_a     = $new_user(
	array(
		'account_type'  => 'Φαρμακείο',
		'pharmacy_name' => '=HYPERLINK("http://evil.test","click")',
		'mobile_phone'  => '+306900000001',
		'afm'           => '-99',
	)
);
$ph_b     = $new_user(
	array(
		'account_type'  => 'Φαρμακείο',
		'pharmacy_name' => '@SUM(1+1)',
		'mobile_phone'  => "\t=2+2",
		'afm'           => '123456789',
	)
);
$ph_c     = $new_user(
	array(
		'account_type'  => 'ΦΑΡΜΑΚΕΙΟ',
		'pharmacy_name' => 'Φαρμακείο «Υγεία», Αθήνα "Κέντρο"',
		'afm'           => '987654321',
	)
);
Plandose_Subscriptions::get_row( $ph_a );
Plandose_Subscriptions::grant_pro_days_if_unchanged( $ph_a, 30, Plandose_Subscriptions::state_token( Plandose_Subscriptions::get_row( $ph_a ) ) );
Plandose_Subscriber_Query::invalidate_subscriber_cache();

$export = static function ( $method, $nonce_action ) {
	$_SERVER['REQUEST_METHOD'] = $method;
	$_POST                     = array( '_wpnonce' => wp_create_nonce( $nonce_action ) );
	$_REQUEST                  = $_POST;
	return pdt1310_run( array( 'Plandose_Admin_Subscriptions', 'handle_export_csv' ) );
};

wp_set_current_user( $customer );
$out = $export( 'POST', 'plandose_export_csv' );
pdt_check( 0 === strpos( $out, 'die:403:' ), 'export: a non-admin (with a valid nonce of their own) is refused 403 (' . $out . ')' );

wp_set_current_user( $admin );
$out = $export( 'POST', 'some_other_action' );
pdt_check( 0 === strpos( $out, 'die:403:' ), 'export: an admin with a bad nonce is refused 403 (' . $out . ')' );

$out = $export( 'GET', 'plandose_export_csv' );
pdt_check( 0 === strpos( $out, 'die:405:' ), 'export: GET is refused 405 (' . $out . ')' );

$no_list = static function ( $allcaps ) {
	$allcaps['list_users'] = false;
	return $allcaps;
};
add_filter( 'user_has_cap', $no_list );
$out = $export( 'POST', 'plandose_export_csv' );
remove_filter( 'user_has_cap', $no_list );
pdt_check( 0 === strpos( $out, 'die:403:' ), 'export: manage_options without list_users is refused 403 (' . $out . ')' );

$out = $export( 'POST', 'plandose_export_csv' );
pdt_same( 'nonce-passed:plandose_export_csv', $out, 'export: an admin with list_users and a good nonce gets past the checks (stopped before streaming here)' );
pdt_same( 0, pdt1310_audit_count( 'export_subscribers_csv_started', null, $admin ), 'export: refused requests are not audited as exports' );

$_POST = $_REQUEST = array();
wp_set_current_user( 0 );

// ---- handle_export_csv(): end-to-end in a child process -------------------
$proc = proc_open(
	array( PHP_BINARY, __FILE__, '--export-child', (string) $admin ),
	array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
	$pipes
);
$csv    = stream_get_contents( $pipes[1] );
$errout = stream_get_contents( $pipes[2] );
fclose( $pipes[1] );
fclose( $pipes[2] );
$code = proc_close( $proc );

pdt_same( 0, $code, 'export child: exit status 0 (handle_export_csv() ends with exit)' );
pdt_check( false === strpos( $errout, 'PDT_RETURNED' ), 'export child: the handler did not return' );
$expected_ids = preg_match( '/^PDT_IDS (.+)$/m', $errout, $m ) ? json_decode( $m[1], true ) : null;
pdt_check( is_array( $expected_ids ) && in_array( $ph_a, $expected_ids, true ), 'export child: subscriber list read' . ( is_array( $expected_ids ) ? ' (' . count( $expected_ids ) . ' subscribers)' : ' — ' . substr( $errout, 0, 300 ) ) );
pdt_same( "\xEF\xBB\xBF", substr( $csv, 0, 3 ), 'export: starts with a UTF-8 BOM' );

$fh = fopen( 'php://memory', 'w+b' );
fwrite( $fh, substr( $csv, 3 ) );
rewind( $fh );
$lines = array();
while ( false !== ( $l = fgetcsv( $fh, 0, ',', '"', '' ) ) ) {
	if ( array( null ) !== $l ) {
		$lines[] = $l;
	}
}
fclose( $fh );

pdt_same(
	array( 'User ID', 'Pharmacy', 'Mobile', 'AFM', 'Name', 'Email', 'Status', 'End Date', 'Prints', 'Last Print', 'Prev Month Prints', 'Prints Last 12m', 'Active Months 12m' ),
	isset( $lines[0] ) ? $lines[0] : null,
	'export: header row'
);
$body   = array_slice( $lines, 1 );
$by_id  = array();
$ids    = array();
$widths = array();
foreach ( $body as $l ) {
	$ids[]                  = (int) $l[0];
	$by_id[ (int) $l[0] ][] = $l;
	$widths[ count( $l ) ]  = true;
}
pdt_same( array( 13 => true ), $widths, 'export: every row has 13 columns' );
pdt_same( is_array( $expected_ids ) ? $expected_ids : array(), $ids, 'export: one row per subscriber, in user-ID order (' . count( $ids ) . ' rows)' );
foreach ( array( $ph_a, $ph_b, $ph_c ) as $p ) {
	pdt_same( 1, isset( $by_id[ $p ] ) ? count( $by_id[ $p ] ) : 0, "export: pharmacy $p listed exactly once" );
}
pdt_check( ! isset( $by_id[ $customer ] ) && ! isset( $by_id[ $admin ] ), 'export: the non-pharmacy account and the admin are not listed' );

$ra = isset( $by_id[ $ph_a ] ) ? $by_id[ $ph_a ][0] : array_fill( 0, 13, null );
$rb = isset( $by_id[ $ph_b ] ) ? $by_id[ $ph_b ][0] : array_fill( 0, 13, null );
$rc = isset( $by_id[ $ph_c ] ) ? $by_id[ $ph_c ][0] : array_fill( 0, 13, null );
pdt_same( "'=HYPERLINK(\"http://evil.test\",\"click\")", $ra[1], 'export: = pharmacy name neutralised' );
pdt_same( "'+306900000001", $ra[2], 'export: + mobile neutralised' );
pdt_same( "'-99", $ra[3], 'export: - AFM neutralised' );
pdt_same( 'Pro', $ra[6], 'export: Pro status' );
pdt_check( (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $ra[7] ), 'export: Pro end date present (' . $ra[7] . ')' );
pdt_same( "'@SUM(1+1)", $rb[1], 'export: @ pharmacy name neutralised' );
pdt_same( "'\t=2+2", $rb[2], 'export: tab-led mobile neutralised' );
pdt_same( '123456789', $rb[3], 'export: plain AFM untouched' );
pdt_same( 'Free', $rb[6], 'export: Free status' );
pdt_same( 'Φαρμακείο «Υγεία», Αθήνα "Κέντρο"', $rc[1], 'export: Greek name with comma and quotes round-trips' );
pdt_same( 1, pdt1310_audit_count( 'export_subscribers_csv_started', null, $admin ), 'export: «started» audited once' );
$meta = $wpdb->get_var( $wpdb->prepare( "SELECT meta FROM {$audit_table} WHERE event = %s AND admin_user_id = %d", 'export_subscribers_csv', $admin ) ); // phpcs:ignore
$meta = json_decode( (string) $meta, true );
pdt_same( count( $ids ), is_array( $meta ) && isset( $meta['rows'] ) ? (int) $meta['rows'] : null, 'export: completion audited with the row count' );

/* ======================================================================
 * 2. Account-type guard
 * ==================================================================== */

$registering = new ReflectionProperty( 'Plandose_Account_Type', 'registering' );
$registering->setAccessible( true );
$not_registering = static function ( $uid ) use ( $registering ) {
	$list = $registering->getValue();
	unset( $list[ $uid ] );
	$registering->setValue( null, $list );
	delete_transient( 'plandose_acct_blocked_' . $uid );
};
$db_meta = static function ( $uid, $key ) use ( $wpdb ) {
	return $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s ORDER BY umeta_id", $uid, $key ) ); // phpcs:ignore
};
$mid_of = static function ( $uid, $key ) use ( $wpdb ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT umeta_id FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s ORDER BY umeta_id LIMIT 1", $uid, $key ) ); // phpcs:ignore
};

$self = $new_user( array( 'account_type' => 'Εταιρία' ) );
pdt_same( array( 'Εταιρία' ), $db_meta( $self, 'account_type' ), 'precondition: new user wrote its category during registration' );
$not_registering( $self );
wp_set_current_user( $self );

pdt_same( false, update_user_meta( $self, 'account_type', 'Φαρμακείο' ), 'guard: non-admin cannot update their own account_type' );
pdt_same( array( 'Εταιρία' ), $db_meta( $self, 'account_type' ), '… the stored value is unchanged' );
pdt_same( 1, pdt1310_audit_count( 'account_type_change_blocked', $self ), '… and the refusal is audited' );
$audit_meta = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta FROM {$audit_table} WHERE event = %s AND target_user_id = %d", 'account_type_change_blocked', $self ) ), true ); // phpcs:ignore
pdt_same( array( 'key' => 'account_type' ), $audit_meta, '… with the key in the audit meta' );
pdt_same( $self, (int) $wpdb->get_var( $wpdb->prepare( "SELECT admin_user_id FROM {$audit_table} WHERE event = %s AND target_user_id = %d", 'account_type_change_blocked', $self ) ), '… by the acting user' ); // phpcs:ignore

pdt_same( false, add_user_meta( $self, 'user_registration_account_type', 'Φαρμακείο' ), 'guard: non-admin cannot add user_registration_account_type' );
pdt_same( array(), $db_meta( $self, 'user_registration_account_type' ), '… nothing written' );
pdt_same( 1, pdt1310_audit_count( 'account_type_change_blocked', $self ), '… audit throttled to one entry per user per hour' );

foreach ( array( 'Account_Type', 'account_type ', 'ACCOUNT_TYPE', 'User_Registration_Account_Type  ' ) as $variant ) {
	pdt_same( false, update_user_meta( $self, $variant, 'Φαρμακείο' ), "guard: key variant «{$variant}» is guarded (update)" );
	pdt_same( false, add_user_meta( $self, $variant, 'Φαρμακείο' ), "guard: key variant «{$variant}» is guarded (add)" );
}
wp_cache_delete( $self, 'user_meta' );
pdt_check( ! Plandose_Access::is_registered_pharmacist( $self ), 'guard: after all attempts the user is still not a pharmacy' );
pdt_same( array( 'Εταιρία' ), $db_meta( $self, 'account_type' ), '… account_type still «Εταιρία»' );

pdt_same( false, delete_user_meta( $self, 'account_type' ), 'guard: non-admin cannot delete their own account_type' );
// 1.31.1: the variants are guarded too (see also test-bugfixes-1311.php).
foreach ( array( 'Account_Type ', 'ACCOUNT_TYPE' ) as $variant ) {
	pdt_same( false, delete_user_meta( $self, $variant ), "guard: key variant «{$variant}» is guarded (delete)" );
	pdt_same( false, update_user_meta( $self, $variant, '' ), "guard: key variant «{$variant}» cannot clear the category (update to '')" );
}
pdt_same( array( 'Εταιρία' ), $db_meta( $self, 'account_type' ), '… still stored' );

pdt_check( false !== update_user_meta( $self, 'account_type', 'ΕΤΑΙΡΙΑ' ), 'guard: re-saving the same category (case/accents differ) is not blocked' );
update_user_meta( $self, 'account_type', 'Εταιρία' );
pdt_check( false !== update_user_meta( $self, 'pdt_other_key', 'Φαρμακείο' ), 'guard: unrelated meta keys are not affected' );

$mid = $mid_of( $self, 'account_type' );
pdt_same( false, update_metadata_by_mid( 'user', $mid, 'Φαρμακείο' ), 'guard: update_metadata_by_mid on the category row is blocked' );
pdt_same( false, update_metadata_by_mid( 'user', $mid, 'Εταιρία', 'pdt_renamed' ), 'guard: renaming the category row away is blocked' );
pdt_same( false, delete_metadata_by_mid( 'user', $mid ), 'guard: delete_metadata_by_mid on the category row is blocked' );
pdt_same( array( 'Εταιρία' ), $db_meta( $self, 'account_type' ), '… the row survives the by-mid paths' );
$other_mid = $mid_of( $self, 'pdt_other_key' );
pdt_same( false, update_metadata_by_mid( 'user', $other_mid, 'Φαρμακείο', 'User_Registration_Account_Type' ), 'guard: renaming another row INTO a category key with a new value is blocked' );
pdt_same( array(), $db_meta( $self, 'user_registration_account_type' ), '… no category row created' );
pdt_check( false !== update_metadata_by_mid( 'user', $other_mid, 'x' ), 'guard: by-mid update of an unrelated row passes' );

pdt_same( false, Plandose_Account_Type::guard_delete( null, 0, 'account_type', '', true ), 'guard: a non-admin delete_all of account_type is refused' );

// Another non-admin, on someone else's account.
$other = $new_user( array( 'account_type' => 'Εταιρία' ) );
$not_registering( $other );
$not_registering( $self );
wp_set_current_user( $other );
pdt_same( false, update_user_meta( $self, 'account_type', 'Φαρμακείο' ), 'guard: a non-admin cannot change another user\'s category' );

// Admin.
wp_set_current_user( $admin );
pdt_same( null, Plandose_Account_Type::guard_delete( null, 0, 'account_type', '', true ), 'guard: an admin delete_all is let through (not blocked)' );
pdt_same( true, update_user_meta( $self, 'account_type', 'Φαρμακείο' ), 'guard: an admin may change the category' );
pdt_same( array( 'Φαρμακείο' ), $db_meta( $self, 'account_type' ), '… written' );
pdt_same( true, update_metadata_by_mid( 'user', $mid, 'Εταιρία' ), 'guard: an admin may update by meta ID' );
pdt_check( false !== add_user_meta( $self, 'user_registration_account_type', 'Εταιρία' ), 'guard: an admin may add a category key' );
pdt_same( true, delete_user_meta( $self, 'user_registration_account_type' ), 'guard: an admin may delete a category key' );
pdt_same( 0, pdt1310_audit_count( 'account_type_change_blocked', $self, $admin ), 'guard: admin changes are not audited as blocked' );

// Registration window.
$fresh = $new_user( array( 'account_type' => 'Εταιρία' ) );
wp_set_current_user( $fresh );
pdt_check( false !== update_user_meta( $fresh, 'account_type', 'Φαρμακείο' ), 'registration: a user created in this request may still write its category (even logged in as itself)' );
$not_registering( $fresh );
$wpdb->update( $wpdb->users, array( 'user_registered' => gmdate( 'Y-m-d H:i:s', time() - 20 * MINUTE_IN_SECONDS ) ), array( 'ID' => $fresh ) );
clean_user_cache( $fresh );
Plandose_Account_Type::mark_registering_id( $fresh );
pdt_same( false, update_user_meta( $fresh, 'account_type', 'Εταιρία' ), 'registration: user_register re-fired for a 20-minute-old account gives no exemption' );
$wpdb->update( $wpdb->users, array( 'user_registered' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'ID' => $fresh ) );
clean_user_cache( $fresh );
Plandose_Account_Type::mark_registering_id( $fresh );
pdt_check( false !== update_user_meta( $fresh, 'account_type', 'Εταιρία' ), 'registration: user_register for a 1-minute-old account allows the write' );
$not_registering( $fresh );

// Anonymous request, cron, the trusted-context filter.
wp_set_current_user( 0 );
pdt_same( false, pdt_call( 'Plandose_Account_Type', 'is_trusted_context', $self ), 'trusted: an anonymous web request is not trusted' );
pdt_same( false, update_user_meta( $self, 'account_type', 'Φαρμακείο' ), 'trusted: anonymous write blocked' );
pdt_same( false, Plandose_Account_Type::guard_delete( null, 0, 'account_type', '', true ), 'trusted: anonymous delete_all blocked' );

add_filter( 'wp_doing_cron', '__return_true' );
pdt_same( true, pdt_call( 'Plandose_Account_Type', 'is_trusted_context', $self ), 'trusted: WP-Cron is trusted' );
pdt_same( true, update_user_meta( $self, 'account_type', 'Φαρμακείο' ), 'trusted: anonymous write under cron allowed' );
remove_filter( 'wp_doing_cron', '__return_true' );

$trust_one = static function () {
	return 1;
};
add_filter( 'plandose_account_type_trusted_context', $trust_one );
pdt_same( false, pdt_call( 'Plandose_Account_Type', 'is_trusted_context', $self ), 'trusted: the filter returning a truthy non-true (1) is not trust' );
remove_filter( 'plandose_account_type_trusted_context', $trust_one );
$seen_uid  = null;
$trust_yes = static function ( $trusted, $uid ) use ( &$seen_uid ) {
	$seen_uid = $uid;
	return true;
};
add_filter( 'plandose_account_type_trusted_context', $trust_yes, 10, 2 );
pdt_same( true, update_user_meta( $self, 'account_type', 'Εταιρία' ), 'trusted: the filter returning true allows an anonymous write' );
pdt_same( $self, $seen_uid, '… and receives the target user ID' );
remove_filter( 'plandose_account_type_trusted_context', $trust_yes, 10 );

// WP-CLI (a real wp-cli process: WP_CLI is defined there).
$wp_cli = getenv( 'PD_WP_CLI' ) ? getenv( 'PD_WP_CLI' ) : rtrim( (string) getenv( 'PD_WP_PATH' ), '/' ) . '/wp';
if ( is_executable( $wp_cli ) ) {
	$cli_out = shell_exec( escapeshellarg( $wp_cli ) . ' eval ' . escapeshellarg( 'echo "PDT_CLI " . var_export( update_user_meta( ' . $self . ', "account_type", "Φαρμακείο" ), true ) . "\n";' ) . ' 2>&1' );
	pdt_check( false !== strpos( (string) $cli_out, 'PDT_CLI true' ), 'trusted: a WP-CLI write with no user is allowed (' . trim( substr( (string) $cli_out, 0, 200 ) ) . ')' );
	pdt_same( array( 'Φαρμακείο' ), $db_meta( $self, 'account_type' ), '… written' );
} else {
	echo "skip   WP-CLI trusted context: no wp-cli at $wp_cli\n";
}

/* ======================================================================
 * 3. Print transaction / new charge / charges
 * ==================================================================== */

$pu      = $new_user( array( 'account_type' => 'Φαρμακείο' ) );
$not_registering( $pu );
Plandose_Subscriptions::get_row( $pu );
$ctable  = Plandose_Print_Charges::table_name();
$rtable  = Plandose_Print_Charges::requests_table_name();
$key_of  = static function () {
	return md5( 'pdt1310' . wp_generate_password( 20, false ) );
};
$reqrows = static function ( $uid, $rhash ) use ( $wpdb, $rtable ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$rtable} WHERE user_id = %d AND request_hash = %s", $uid, $rhash ) ); // phpcs:ignore
};
$count_of = static function ( $uid ) use ( $wpdb ) {
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT print_count FROM ' . Plandose_Subscriptions::table_name() . ' WHERE user_id = %d', $uid ) ); // phpcs:ignore
};
$wpdb->suppress_errors( true );

// ---- Plandose_Print_Transaction -------------------------------------------
$k  = $key_of();
$tx = Plandose_Print_Transaction::begin();
pdt_check( $tx instanceof Plandose_Print_Transaction, 'tx: begin() opens a transaction' );
pdt_same( false, $tx->lost(), 'tx: not lost right after begin()' );
Plandose_Print_Charges::write_charge( $pu, $k, null );
$tx->rollback();
pdt_same( null, Plandose_Print_Charges::find( $pu, $k ), 'tx: rollback() discards the row' );

$k  = $key_of();
$tx = Plandose_Print_Transaction::begin();
Plandose_Print_Charges::write_charge( $pu, $k, null );
pdt_same( true, $tx->commit(), 'tx: commit() on the same connection → true' );
pdt_check( is_object( Plandose_Print_Charges::find( $pu, $k ) ), 'tx: … the row is stored' );

$k  = $key_of();
$tx = Plandose_Print_Transaction::begin();
Plandose_Print_Charges::write_charge( $pu, $k, null );
pdt1310_kill();
$wpdb->query( 'SELECT 1' ); // wpdb reconnects and re-runs it.
pdt_same( true, $tx->lost(), 'tx: lost() after the connection was killed and wpdb reconnected' );
pdt_same( false, $tx->commit(), 'tx: commit() after a reconnect → false (COMMIT «succeeds» on the new connection)' );
pdt_same( null, Plandose_Print_Charges::find( $pu, $k ), 'tx: … and the row written before the kill is gone' );

pdt1310_kill();
$tx = Plandose_Print_Transaction::begin();
pdt_same( null, $tx, 'tx: begin() whose START TRANSACTION reconnected → null' );

$refuse_start = static function ( $q ) {
	return is_string( $q ) && preg_match( '/^\s*START\s+TRANSACTION\b/i', $q ) ? 'START TRANSACTION PDT_REFUSED' : $q;
};
add_filter( 'query', $refuse_start );
$tx = Plandose_Print_Transaction::begin();
remove_filter( 'query', $refuse_start );
pdt_same( null, $tx, 'tx: begin() refused by the server → null' );

// ---- commit_confirmed() ---------------------------------------------------
$new_req = static function ( $rid = null ) use ( $pu, $key_of ) {
	$rh = null === $rid ? Plandose_Print_Request::request_hash( $key_of() ) : $rid;
	return new Plandose_Print_Request( $pu, $key_of(), $rh, null, false, 30, current_time( 'Y-m-d' ) );
};
$confirm = static function ( $tx, $req, $started ) {
	return pdt_call( 'Plandose_Print_New_Charge', 'commit_confirmed', $tx, $req, null, $started );
};

$req = $new_req();
$t0  = time();
$tx  = Plandose_Print_Transaction::begin();
Plandose_Print_Charges::write_charge( $pu, $req->receipt_key, null );
Plandose_Print_Charges::record_request( $pu, $req->request_hash, $req->receipt_key, Plandose_Print_Charges::KIND_CHARGE );
pdt_same( true, $confirm( $tx, $req, $t0 ), 'commit_confirmed: a plain commit → true' );

$req = $new_req();
$tx  = Plandose_Print_Transaction::begin();
Plandose_Print_Charges::write_charge( $pu, $req->receipt_key, null );
Plandose_Print_Charges::record_request( $pu, $req->request_hash, $req->receipt_key, Plandose_Print_Charges::KIND_CHARGE );
pdt1310_kill();
pdt_same( false, $confirm( $tx, $req, $t0 ), 'commit_confirmed: connection lost AT COMMIT, rows rolled back → false' );
pdt_same( array( null, 0 ), array( Plandose_Print_Charges::find( $pu, $req->receipt_key ), $reqrows( $pu, $req->request_hash ) ), '… and indeed nothing is stored' );

$req = $new_req();
$tx  = Plandose_Print_Transaction::begin();
pdt1310_kill();
$wpdb->query( 'SELECT 1' );
Plandose_Print_Charges::write_charge( $pu, $req->receipt_key, null ); // autocommit now.
Plandose_Print_Charges::record_request( $pu, $req->request_hash, $req->receipt_key, Plandose_Print_Charges::KIND_CHARGE );
pdt_same( true, $confirm( $tx, $req, $t0 ), 'commit_confirmed: unconfirmed COMMIT but both rows present → true' );

$req = $new_req();
$tx  = Plandose_Print_Transaction::begin();
pdt1310_kill();
$wpdb->query( 'SELECT 1' );
Plandose_Print_Charges::write_charge( $pu, $req->receipt_key, null );
pdt_same( false, $confirm( $tx, $req, $t0 ), 'commit_confirmed: unconfirmed COMMIT, request row missing → false' );

$req = $new_req( '' );
$tx  = Plandose_Print_Transaction::begin();
pdt1310_kill();
$wpdb->query( 'SELECT 1' );
Plandose_Print_Charges::write_charge( $pu, $req->receipt_key, null );
pdt_same( true, $confirm( $tx, $req, $t0 ), 'commit_confirmed: unconfirmed COMMIT, no request id, charge row present → true' );

// ---- ensure_charge_records() ------------------------------------------------
$ensure = static function ( $req, $started ) {
	return pdt_call( 'Plandose_Print_New_Charge', 'ensure_charge_records', $req, $started );
};
$req = $new_req();
$t0  = time();
pdt_same( true, $ensure( $req, $t0 ), 'ensure_charge_records: missing rows are written → true' );
$c = Plandose_Print_Charges::find( $pu, $req->receipt_key );
pdt_check( is_object( $c ) && (int) $c->charged_ts >= $t0, '… charge row written, charged now' );
pdt_same( 1, $reqrows( $pu, $req->request_hash ), '… request row written' );

$inserts = 0;
$count_i = static function ( $q ) use ( &$inserts ) {
	if ( is_string( $q ) && preg_match( '/^\s*(INSERT|UPDATE)\b/i', $q ) ) {
		++$inserts;
	}
	return $q;
};
add_filter( 'query', $count_i );
$again = $ensure( $req, $t0 );
remove_filter( 'query', $count_i );
pdt_same( array( true, 0 ), array( $again, $inserts ), 'ensure_charge_records: rows already there → true with no write' );

$req = $new_req();
$wpdb->insert( $ctable, array( 'user_id' => $pu, 'token_hash' => $req->receipt_key, 'charged_ts' => time() - 7200, 'reprints' => 3 ) );
$t0 = time();
pdt_same( true, $ensure( $req, $t0 ), 'ensure_charge_records: an old row for the token is renewed → true' );
$c = Plandose_Print_Charges::find( $pu, $req->receipt_key );
pdt_check( is_object( $c ) && (int) $c->charged_ts >= $t0 && 0 === (int) $c->reprints, '… charged_ts renewed, reprints reset' );

$req      = $new_req();
$break_ch = static function ( $q ) use ( $ctable ) {
	return is_string( $q ) && preg_match( '/^\s*INSERT\s+INTO\s+' . preg_quote( $ctable, '/' ) . '\b/i', $q ) ? 'INSERT PDT_BROKEN' : $q;
};
add_filter( 'query', $break_ch );
$res = $ensure( $req, time() );
remove_filter( 'query', $break_ch );
pdt_same( false, $res, 'ensure_charge_records: a charge row that cannot be written → false (logged)' );
pdt_same( null, Plandose_Print_Charges::find( $pu, $req->receipt_key ), '… no charge row' );

// ---- charge_locked() failure paths (via charge()) --------------------------
$ctx = static function ( $token, $rid ) use ( $pu ) {
	list( $row, $is_pro, $limit, $today ) = pdt_call( 'Plandose_Ajax', 'get_current_print_context', $pu );
	return new Plandose_Print_Request( $pu, $token, Plandose_Print_Request::request_hash( $rid ), $row, $is_pro, $limit, $today );
};
$lock_free = static function ( $token ) use ( $pu ) {
	$free = Plandose_Print_Lock::acquire( $pu, $token, 'pdt-probe' );
	if ( $free ) {
		Plandose_Print_Lock::release( $pu, $token );
	}
	return $free;
};

$token  = $key_of();
$rid    = $key_of();
$before = $count_of( $pu );
add_filter( 'query', $refuse_start );
$res = pdt1310_result( Plandose_Print_New_Charge::charge( $ctx( $token, $rid ) ) );
remove_filter( 'query', $refuse_start );
pdt_check( ! $res['success'] && ! empty( $res['payload']['server_error'] ), 'charge_locked: START TRANSACTION refused → «try again»' );
pdt_same( array( $before, null, 0 ), array( $count_of( $pu ), Plandose_Print_Charges::find( $pu, Plandose_Print_Request::receipt_key( $token ) ), $reqrows( $pu, Plandose_Print_Request::request_hash( $rid ) ) ), '… nothing counted or written' );
pdt_check( $lock_free( $token ), '… and the print lock released' );

$break_counter = static function ( $q ) {
	return is_string( $q ) && preg_match( '/^\s*UPDATE\s+\S*plandose_subscriptions\b.*print_count\s*=\s*print_count\s*\+/is', $q ) ? 'UPDATE PDT_BROKEN' : $q;
};
$token  = $key_of();
$rid    = $key_of();
add_filter( 'query', $break_counter );
$res = pdt1310_result( Plandose_Print_New_Charge::charge( $ctx( $token, $rid ) ) );
remove_filter( 'query', $break_counter );
pdt_check( ! $res['success'] && ! empty( $res['payload']['server_error'] ), 'charge_locked: counter UPDATE failed → «try again», not «limit reached»' );
pdt_same( array( $before, null, 0 ), array( $count_of( $pu ), Plandose_Print_Charges::find( $pu, Plandose_Print_Request::receipt_key( $token ) ), $reqrows( $pu, Plandose_Print_Request::request_hash( $rid ) ) ), '… charge and request rows rolled back with it' );
pdt_check( $lock_free( $token ), '… and the print lock released' );

$limit = (int) pdt_call( 'Plandose_Ajax', 'get_current_print_context', $pu )[2];
if ( $limit > 0 ) {
	$wpdb->update( Plandose_Subscriptions::table_name(), array( 'print_count' => $limit, 'count_reset_at' => current_time( 'mysql' ) ), array( 'user_id' => $pu ) );
	$token = $key_of();
	$rid   = $key_of();
	$res   = pdt1310_result( Plandose_Print_New_Charge::charge( $ctx( $token, $rid ) ) );
	pdt_check( ! $res['success'] && ! empty( $res['payload']['limit_reached'] ), 'charge_locked: at the monthly limit → limit_reached' );
	pdt_same( array( $limit, null, 0 ), array( $count_of( $pu ), Plandose_Print_Charges::find( $pu, Plandose_Print_Request::receipt_key( $token ) ), $reqrows( $pu, Plandose_Print_Request::request_hash( $rid ) ) ), '… nothing counted or written' );
	pdt_check( $lock_free( $token ), '… and the print lock released' );
	$wpdb->update( Plandose_Subscriptions::table_name(), array( 'print_count' => 0 ), array( 'user_id' => $pu ) );
} else {
	echo "skip   limit_reached: the test user has an unlimited plan\n";
}

// ---- use_reprint() ------------------------------------------------------------
$k = $key_of();
Plandose_Print_Charges::write_charge( $pu, $k, null );
$r0 = Plandose_Print_Charges::find( $pu, $k );
pdt_same( 1, Plandose_Print_Charges::use_reprint( $r0, 2 ), 'use_reprint: first free reprint counted' );
pdt_same( 0, Plandose_Print_Charges::use_reprint( $r0, 2 ), 'use_reprint: a stale read (row changed meanwhile) is refused' );
pdt_same( 1, Plandose_Print_Charges::use_reprint( Plandose_Print_Charges::find( $pu, $k ), 2 ), 'use_reprint: second with a fresh read counted' );
pdt_same( 0, Plandose_Print_Charges::use_reprint( Plandose_Print_Charges::find( $pu, $k ), 2 ), 'use_reprint: past the maximum refused' );
pdt_same( 2, (int) Plandose_Print_Charges::find( $pu, $k )->reprints, 'use_reprint: reprints = 2' );
$k = $key_of();
$wpdb->insert( $ctable, array( 'user_id' => $pu, 'token_hash' => $k, 'charged_ts' => Plandose_Print_Charges::live_since() - 60, 'reprints' => 0 ) );
pdt_same( 0, Plandose_Print_Charges::use_reprint( Plandose_Print_Charges::find( $pu, $k ), 2 ), 'use_reprint: an expired charge gives no free reprint' );

// ---- forget_request() -----------------------------------------------------------
$pu2 = $new_user( array( 'account_type' => 'Φαρμακείο' ) );
$rh  = md5( $key_of() );
Plandose_Print_Charges::record_request( $pu, $rh, $key_of(), Plandose_Print_Charges::KIND_CHARGE );
Plandose_Print_Charges::record_request( $pu2, $rh, $key_of(), Plandose_Print_Charges::KIND_CHARGE );
Plandose_Print_Charges::forget_request( $pu, '' );
pdt_same( 1, $reqrows( $pu, $rh ), 'forget_request: an empty hash deletes nothing' );
Plandose_Print_Charges::forget_request( $pu, $rh );
pdt_same( array( 0, 1 ), array( $reqrows( $pu, $rh ), $reqrows( $pu2, $rh ) ), 'forget_request: deletes this user\'s record only' );

// ---- engines_state() --------------------------------------------------------------
$saved_engine = get_transient( Plandose_Print_Charges::ENGINE_CHECK_TRANSIENT );
pdt_defer(
	static function () use ( $saved_engine ) {
		false === $saved_engine ? delete_transient( Plandose_Print_Charges::ENGINE_CHECK_TRANSIENT ) : set_transient( Plandose_Print_Charges::ENGINE_CHECK_TRANSIENT, $saved_engine, HOUR_IN_SECONDS );
	}
);
delete_transient( Plandose_Print_Charges::ENGINE_CHECK_TRANSIENT );
pdt_same( 'ok', Plandose_Print_Charges::engines_state( true ), 'engines_state: InnoDB tables → ok' );
pdt_same( 'ok', get_transient( Plandose_Print_Charges::ENGINE_CHECK_TRANSIENT ), '… cached' );
set_transient( Plandose_Print_Charges::ENGINE_CHECK_TRANSIENT, 'bad', HOUR_IN_SECONDS );
pdt_same( 'bad', Plandose_Print_Charges::engines_state(), 'engines_state: a cached «bad» is used without $fresh' );
pdt_same( 'ok', Plandose_Print_Charges::engines_state( true ), 'engines_state: $fresh skips the cache' );

$myisam = static function ( $q ) use ( $ctable ) {
	if ( is_string( $q ) && false !== stripos( $q, 'information_schema.TABLES' ) ) {
		return str_replace( 'ENGINE AS e', "IF(TABLE_NAME = '" . esc_sql( $ctable ) . "', 'MyISAM', ENGINE) AS e", $q );
	}
	return $q;
};
add_filter( 'query', $myisam );
pdt_same( 'bad', Plandose_Print_Charges::engines_state( true ), 'engines_state: one MyISAM table → bad' );
pdt_same( true, Plandose_Print_Charges::engines_bad( true ), '… engines_bad()' );
pdt_same( false, Plandose_Print_Charges::engines_ok( true ), '… not engines_ok()' );
remove_filter( 'query', $myisam );

$broken = static function ( $q ) {
	return is_string( $q ) && false !== stripos( $q, 'information_schema.TABLES' ) ? 'SELECT PDT_BROKEN FROM' : $q;
};
set_transient( Plandose_Print_Charges::ENGINE_CHECK_TRANSIENT, 'ok', HOUR_IN_SECONDS );
add_filter( 'query', $broken );
pdt_same( 'unknown', Plandose_Print_Charges::engines_state( true ), 'engines_state: a failed check → unknown' );
pdt_same( false, Plandose_Print_Charges::engines_bad( true ), '… which is not «bad» (no hand-made undo)' );
remove_filter( 'query', $broken );
pdt_same( 'ok', get_transient( Plandose_Print_Charges::ENGINE_CHECK_TRANSIENT ), '… and «unknown» is not cached' );

$missing = static function ( $q ) use ( $rtable ) {
	return is_string( $q ) && false !== stripos( $q, 'information_schema.TABLES' ) ? str_replace( "'" . esc_sql( $rtable ) . "'", "'pdt_no_such_table'", $q ) : $q;
};
add_filter( 'query', $missing );
pdt_same( 'unknown', Plandose_Print_Charges::engines_state( true ), 'engines_state: a missing table → unknown' );
remove_filter( 'query', $missing );

$wpdb->suppress_errors( false );

/* ======================================================================
 * 4. Plandose_Access text normalisation
 * ==================================================================== */

foreach ( array( 'Φαρμακείο', 'ΦΑΡΜΑΚΕΙΟ', 'φαρμακειο', 'Φαρμακείο ', ' φαρμακείο', "Φαρμακείο\u{00A0}", "Φαρμα\u{200B}κείο", 'ΦΑΡΜΑΚΕΊΟ', 'φΑρΜαΚεΙο' ) as $v ) {
	pdt_same( 'φαρμακειο', Plandose_Access::normalize_text( $v ), 'normalize_text(«' . $v . '») → «φαρμακειο»' );
}
pdt_same( 'φαρμακοσ', Plandose_Access::normalize_text( 'ΦΑΡΜΑΚΟΣ' ), 'normalize_text: final sigma folded' );
pdt_same( 'αιουι', Plandose_Access::normalize_text( 'ΆΐΌΰΪ' ), 'normalize_text: accented capitals and dialytika folded' );

$amap = pdt_call( 'Plandose_Access', 'accent_map' );
pdt_same( array(), array_filter( $amap, static function ( $to, $from ) {
	return 1 !== mb_strlen( $from, 'UTF-8' ) || ! preg_match( '/^[α-ω]$/u', $to );
}, ARRAY_FILTER_USE_BOTH ), 'accent_map: every entry maps one character to a plain lowercase letter' );
pdt_same( strtr( 'άέήίόύώϊΐϋΰς', $amap ), strtr( strtr( 'άέήίόύώϊΐϋΰς', $amap ), $amap ), 'accent_map: idempotent' );

$as_pharmacy = static function ( $value, $key = 'account_type' ) use ( $new_user ) {
	$uid = $new_user( array( $key => $value ) );
	return Plandose_Access::is_registered_pharmacist( $uid );
};
foreach ( array( 'Φαρμακείο', 'ΦΑΡΜΑΚΕΙΟ', 'φαρμακειο', 'Φαρμακείο ' ) as $v ) {
	pdt_same( true, $as_pharmacy( $v ), "is_registered_pharmacist: «{$v}» counts" );
}
pdt_same( true, $as_pharmacy( 'φαρμακείο', 'user_registration_account_type' ), 'is_registered_pharmacist: user_registration_account_type counts too' );
pdt_same( true, $as_pharmacy( array( 'Φαρμακείο' ) ), 'is_registered_pharmacist: a select stored as an array counts' );
foreach ( array( 'Εταιρία', 'Φαρμακείο Παπαδόπουλος', 'Φαρμακεία', 'Φαρμακοποιός', 'pharmacy', 'φαρμακειοo', 'Φ α ρ μ α κ ε ί ο', '' ) as $v ) {
	pdt_same( false, $as_pharmacy( $v ), "is_registered_pharmacist: «{$v}» does not count" );
}
pdt_same( false, $as_pharmacy( 'Φαρμακείο', 'pharmacy_type' ), 'is_registered_pharmacist: an unrelated meta key does not count' );
pdt_same( false, Plandose_Access::is_registered_pharmacist( 0 ), 'is_registered_pharmacist: user 0 → false' );

wp_set_current_user( 0 );
$_POST = $_REQUEST = array();

pdt_done();
