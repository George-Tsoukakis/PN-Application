<?php
/**
 * 1.31.1: regression tests for the bugs found by the 1.31.0 tests
 * (test-csv-access-print-1310.php, test-invoice-handlers-1310.php).
 *
 *   1. Account-type guard: a non-admin cannot clear or change their own
 *      category through a key variant ('ACCOUNT_TYPE', 'Account_Type ',
 *      'account_typé', …) that the database collation maps onto the real
 *      account_type row.
 *   2. Account deletion: wp_delete_user() of a non-admin's own account (or
 *      with no user, as pdt_user()'s cleanup does) removes the category
 *      rows with the account and audits nothing as «blocked».
 *   3. PLANDOSE_INVOICE_DIR with '..': refused by validate_custom_invoice_dir(),
 *      and resolve_path() normalises '..' in the part that does not exist.
 *   4. PLANDOSE_INVOICE_DIR with a NUL byte: no ValueError / fatal.
 *   5. Name/path validation regexes: a trailing "\n" no longer matches '$'.
 *
 * TEST-ONLY. Run by tests/php/run.sh (WP_LOAD = the test WordPress).
 */

$pdt_is_child = isset( $argv[1] ) && '--invoice-dir-child' === $argv[1];

if ( $pdt_is_child ) {
	// PLANDOSE_INVOICE_DIR must be defined before WordPress loads. argv
	// cannot carry a NUL byte, so it is passed as «<NUL>».
	define( 'PLANDOSE_INVOICE_DIR', str_replace( '<NUL>', "\0", (string) $argv[2] ) );
}

require __DIR__ . '/lib.php';

if ( $pdt_is_child ) {
	$out = array();
	try {
		$cfg                = Plandose_Invoice_Storage::configured_invoice_dir();
		$out['custom']      = Plandose_Invoice_Storage::using_custom_invoice_dir();
		$out['config']      = is_wp_error( $cfg ) ? $cfg->get_error_code() : 'ok';
		$out['public']      = Plandose_Invoice_Storage::invoice_dir_is_public();
		// A known web root, as in a web request: without it the probe only
		// says «docroot_unknown» and would hide a wrong «private» verdict.
		Plandose_Invoice_Storage::set_document_root_override( ABSPATH );
		$probe              = Plandose_Invoice_Storage::probe_privacy( null, false );
		$out['probe']       = $probe['status'] . '/' . $probe['reason'];
		$out['deletable']   = Plandose_Invoice_Storage::custom_invoice_dir_is_deletable( PLANDOSE_INVOICE_DIR );
	} catch ( Throwable $e ) {
		$out['error'] = get_class( $e ) . ': ' . $e->getMessage();
	}
	echo "\nPD1311:" . wp_json_encode( $out );
	exit( 0 );
}

global $wpdb;

ini_set( 'error_log', '/dev/null' ); // phpcs:ignore -- expected log lines.

$audit_table = Plandose_Subscriptions::audit_table_name();
$my_users    = array();

$new_user = static function ( $meta = array(), $role = 'subscriber' ) use ( &$my_users ) {
	$id         = pdt_user( $meta, $role );
	$my_users[] = $id;
	return $id;
};

pdt_defer(
	static function () use ( &$my_users, $wpdb, $audit_table ) {
		if ( ! $my_users ) {
			return;
		}
		$in = implode( ',', array_map( 'intval', $my_users ) );
		$wpdb->query( "DELETE FROM {$audit_table} WHERE admin_user_id IN ({$in}) OR target_user_id IN ({$in})" ); // phpcs:ignore
	}
);

$registering = new ReflectionProperty( 'Plandose_Account_Type', 'registering' );
$registering->setAccessible( true );
$deleting = new ReflectionProperty( 'Plandose_Account_Type', 'deleting' );
$deleting->setAccessible( true );
$not_registering = static function ( $uid ) use ( $registering ) {
	$list = $registering->getValue();
	unset( $list[ $uid ] );
	$registering->setValue( null, $list );
	delete_transient( 'plandose_acct_blocked_' . $uid );
};
$audit_count = static function ( $event, $target ) use ( $wpdb, $audit_table ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$audit_table} WHERE event = %s AND target_user_id = %d", $event, $target ) ); // phpcs:ignore
};
// Every usermeta row of a user whose key is (byte-for-byte) a category key.
$category_rows = static function ( $uid ) use ( $wpdb ) {
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d ORDER BY umeta_id", $uid ), ARRAY_A ); // phpcs:ignore
	$out  = array();
	foreach ( (array) $rows as $r ) {
		if ( in_array( $r['meta_key'], Plandose_Access::account_type_meta_keys(), true ) ) {
			$out[] = $r['meta_key'] . '=' . $r['meta_value'];
		}
	}
	return $out;
};
// Whether the usermeta.meta_key collation maps $variant onto 'account_type'.
$db_equal = static function ( $variant ) use ( $wpdb ) {
	$col  = $wpdb->get_row( "SHOW FULL COLUMNS FROM {$wpdb->usermeta} LIKE 'meta_key'", ARRAY_A ); // phpcs:ignore
	$coll = is_array( $col ) && isset( $col['Collation'] ) ? (string) $col['Collation'] : '';
	if ( ! preg_match( '/^[A-Za-z0-9]+_[A-Za-z0-9_]+\z/', $coll ) ) {
		return null;
	}
	$cs = strstr( $coll, '_', true );
	return '1' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT CONVERT(%s USING {$cs}) COLLATE {$coll} = CONVERT('account_type' USING {$cs}) COLLATE {$coll}", $variant ) ); // phpcs:ignore
};

$admin = $new_user( array(), 'administrator' );

/* ======================================================================
 * 1. Key variants can neither clear nor change the category
 * ==================================================================== */

$is_key = static function ( $k ) {
	return pdt_call( 'Plandose_Account_Type', 'is_category_key', $k );
};
foreach ( array( 'account_type', 'ACCOUNT_TYPE', 'Account_Type ', 'account_typé', 'ÀCCOUNT_TYPE', 'User_Registration_Account_Type', 'user_registration_account_typè  ' ) as $k ) {
	pdt_same( true, $is_key( $k ), "1: is_category_key( «{$k}» )" );
}
foreach ( array( 'account_types', 'pdt_other_key', 'account-type', 'κατηγορία', '', ' account_type' ) as $k ) {
	pdt_same( false, $is_key( $k ), "1: not a category key: «{$k}»" );
}
$fullwidth = 'ａｃｃｏｕｎｔ_ｔｙｐｅ';
pdt_same( true === $db_equal( $fullwidth ), $is_key( $fullwidth ), '1: a full-width spelling is a category key exactly when the column collation says so (' . var_export( $db_equal( $fullwidth ), true ) . ')' );

$self = $new_user( array( 'account_type' => 'Εταιρία' ) );
$not_registering( $self );
wp_set_current_user( $self );

$variants = array( 'Account_Type ', 'ACCOUNT_TYPE', 'account_type ', 'Account_Type' );
foreach ( array( 'account_typé', 'ÀCCOUNT_TYPE', $fullwidth ) as $v ) {
	if ( true === $db_equal( $v ) ) {
		$variants[] = $v;
	} else {
		echo "SKIP   1: the column collation does not map «{$v}» onto account_type\n";
	}
}
foreach ( $variants as $v ) {
	pdt_same( false, delete_user_meta( $self, $v ), "1: non-admin delete_user_meta( «{$v}» ) is refused" );
	pdt_same( false, delete_user_meta( $self, $v, 'Εταιρία' ), "1: … also with the value given" );
	pdt_same( false, update_user_meta( $self, $v, '' ), "1: non-admin update_user_meta( «{$v}», '' ) is refused" );
	pdt_same( false, update_user_meta( $self, $v, 'Φαρμακείο' ), "1: non-admin update_user_meta( «{$v}», «Φαρμακείο» ) is refused" );
	wp_cache_delete( $self, 'user_meta' );
	pdt_same( array( 'account_type=Εταιρία' ), $category_rows( $self ), "1: … account_type still «Εταιρία» after «{$v}»" );
}
pdt_same( false, add_user_meta( $self, 'ACCOUNT_TYPE', 'Φαρμακείο' ), '1: non-admin add_user_meta( «ACCOUNT_TYPE», «Φαρμακείο» ) is refused' );
wp_cache_delete( $self, 'user_meta' );
pdt_check( ! Plandose_Access::is_registered_pharmacist( $self ), '1: after every attempt the user is still not a pharmacy' );
pdt_check( $audit_count( 'account_type_change_blocked', $self ) >= 1, '1: the refusals are audited' );

// Re-saving the same category through a variant still passes.
pdt_check( false !== update_user_meta( $self, 'ACCOUNT_TYPE', 'ΕΤΑΙΡΙΑ' ), '1: re-saving the same category through a variant is not blocked' );
pdt_same( false, update_metadata_by_mid( 'user', (int) $wpdb->get_var( $wpdb->prepare( "SELECT umeta_id FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = 'account_type'", $self ) ), '', 'ACCOUNT_TYPE' ), '1: by-mid: renaming to a variant while clearing the value is refused' ); // phpcs:ignore
wp_set_current_user( $admin );
update_user_meta( $self, 'account_type', 'Εταιρία' );

// Another user's rows with a variant key of the same user: a non-category
// key that happens to be absent is not affected.
wp_set_current_user( $self );
pdt_check( false !== update_user_meta( $self, 'pdt_other_key', 'x' ), '1: unrelated keys still pass' );
pdt_check( delete_user_meta( $self, 'pdt_other_key' ), '1: … and can be deleted' );
// A variant key under which nothing is stored, written empty, changes nothing.
$bare = $new_user( array() );
$not_registering( $bare );
wp_set_current_user( $bare );
pdt_check( false !== update_user_meta( $bare, 'ACCOUNT_TYPE', '' ), '1: writing an empty category where none is stored is not blocked' );
pdt_same( false, update_user_meta( $bare, 'ACCOUNT_TYPE', 'Φαρμακείο' ), '1: … but setting one is' );
pdt_same( true, delete_user_meta( $bare, 'ACCOUNT_TYPE' ), '1: deleting an empty category row is not blocked' );

// Administrator: unchanged.
wp_set_current_user( $admin );
pdt_same( true, delete_user_meta( $self, 'ACCOUNT_TYPE' ), '1: an admin may delete through a variant' );
wp_cache_delete( $self, 'user_meta' );
pdt_same( array(), $category_rows( $self ), '1: … the row is gone' );
pdt_check( false !== update_user_meta( $self, 'account_type', 'Εταιρία' ), '1: an admin may write the category back' );
pdt_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$audit_table} WHERE event = %s AND target_user_id = %d AND admin_user_id = %d", 'account_type_change_blocked', $self, $admin ) ), '1: admin changes are not audited as blocked' ); // phpcs:ignore

/* ======================================================================
 * 2. Account deletion takes the category rows with it
 * ==================================================================== */

// A subscriber deleting their own account (as a registration plugin's
// «delete my account» does) under their own session.
$leaver = $new_user( array( 'account_type' => 'Φαρμακείο', 'user_registration_account_type' => 'Φαρμακείο' ) );
$not_registering( $leaver );
wp_set_current_user( $leaver );
pdt_same( false, delete_user_meta( $leaver, 'account_type' ), '2: precondition: the living account cannot delete its category' );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$audit_table} WHERE target_user_id = %d", $leaver ) ); // phpcs:ignore
delete_transient( 'plandose_acct_blocked_' . $leaver );
pdt_same( true, wp_delete_user( $leaver ), '2: self-deletion with wp_delete_user() succeeds' );
pdt_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d", $leaver ) ), '2: … no usermeta row is left (no orphaned account_type)' ); // phpcs:ignore
pdt_same( 0, $audit_count( 'account_type_change_blocked', $leaver ), "2: … and no «account_type_change_blocked» audit entry" );
pdt_same( array(), $deleting->getValue(), '2: … the deletion mark is cleared afterwards' );

// While an account is being deleted, OTHER accounts stay guarded.
$victim  = $new_user( array( 'account_type' => 'Φαρμακείο' ) );
$leaver2 = $new_user( array( 'account_type' => 'Εταιρία' ) );
$not_registering( $victim );
$not_registering( $leaver2 );
$hook = static function () use ( $victim ) {
	delete_user_meta( $victim, 'account_type' );
};
add_action( 'delete_user', $hook );
wp_set_current_user( $leaver2 );
wp_delete_user( $leaver2 );
remove_action( 'delete_user', $hook );
pdt_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d", $leaver2 ) ), '2: the deleted account leaves no rows' ); // phpcs:ignore
wp_cache_delete( $victim, 'user_meta' );
pdt_same( array( 'account_type=Φαρμακείο' ), $category_rows( $victim ), "2: … while another account's category, touched from a delete_user hook, is kept" );

// No current user (pdt_user()'s cleanup, a cron-less anonymous request).
$anon = $new_user( array( 'account_type' => 'Εταιρία' ) );
$not_registering( $anon );
wp_set_current_user( 0 );
wp_delete_user( $anon );
pdt_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d", $anon ) ), '2: deletion with no current user leaves no category rows' ); // phpcs:ignore
pdt_same( 0, $audit_count( 'account_type_change_blocked', $anon ), '2: … nor a blocked audit entry' );

// And a living account is still guarded after all of that.
wp_set_current_user( $victim );
pdt_same( false, delete_user_meta( $victim, 'account_type' ), '2: a living account is still guarded afterwards' );
pdt_same( false, delete_metadata_by_mid( 'user', (int) $wpdb->get_var( $wpdb->prepare( "SELECT umeta_id FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = 'account_type'", $victim ) ) ), '2: … also by meta ID' ); // phpcs:ignore

/* ======================================================================
 * 3./4. PLANDOSE_INVOICE_DIR with '..' or NUL
 * ==================================================================== */

$tmp = untrailingslashit( wp_normalize_path( sys_get_temp_dir() ) ) . '/pd1311-' . strtolower( wp_generate_password( 8, false, false ) );
wp_mkdir_p( $tmp );
pdt_defer(
	static function () use ( $tmp ) {
		foreach ( array_reverse( (array) glob( $tmp . '/*' ) ) as $f ) {
			is_dir( $f ) ? rmdir( $f ) : unlink( $f );
		}
		rmdir( $tmp );
	}
);
$real_tmp = untrailingslashit( wp_normalize_path( realpath( $tmp ) ) );
$uploads  = wp_get_upload_dir();
$up_base  = untrailingslashit( wp_normalize_path( $uploads['basedir'] ) );
$up_real  = untrailingslashit( wp_normalize_path( realpath( $uploads['basedir'] ) ) );
$resolve  = static function ( $p ) {
	return pdt_call( 'Plandose_Invoice_Storage', 'resolve_path', $p );
};
$code = static function ( $r ) {
	return is_wp_error( $r ) ? $r->get_error_code() : $r;
};

$climb = $tmp . '/missing/../..' . str_repeat( '/..', substr_count( $real_tmp, '/' ) ) . $up_real . '/pd1311-new';
pdt_same( $up_real . '/pd1311-new', $resolve( $climb ), "3: resolve_path: '..' in the missing tail is resolved lexically" );
pdt_check( Plandose_Invoice_Storage::path_is_in_public_tree( $climb ), "3: path_is_in_public_tree: a '..' path that lands in uploads → public" );
pdt_same( $real_tmp . '/b', $resolve( $tmp . '/a/../b' ), "3: resolve_path: missing 'a/../b' → b" );
pdt_same( $real_tmp . '/b', $resolve( $tmp . '/a/./../b/.' ), "3: resolve_path: '.' in the missing tail is dropped" );
pdt_same( '/pd1311-top', $resolve( $tmp . '/a' . str_repeat( '/..', 60 ) . '/pd1311-top' ), "3: resolve_path: '..' never climbs above /" );
pdt_same( $real_tmp . '/new/deeper', $resolve( $tmp . '/new/deeper' ), '3: resolve_path: a plain missing tail is unchanged' );

pdt_same( 'plandose_invoice_custom_dir_dotdot', $code( Plandose_Invoice_Storage::validate_custom_invoice_dir( $climb ) ), "3: validate_custom_invoice_dir refuses '..'" );
pdt_same( 'plandose_invoice_custom_dir_dotdot', $code( Plandose_Invoice_Storage::validate_custom_invoice_dir( $tmp . '/a/b/..' ) ), "3: … also a trailing '..'" );
pdt_same( 'plandose_invoice_custom_dir_dotdot', $code( Plandose_Invoice_Storage::validate_custom_invoice_dir( 'C:\\a\\b\\..\\c' ) ), "3: … also with backslashes" );
pdt_same( true, Plandose_Invoice_Storage::validate_custom_invoice_dir( $tmp . '/a..b/inv' ), "3: … but not a name that merely contains '..'" );
pdt_same( false, Plandose_Invoice_Storage::custom_invoice_dir_is_deletable( $tmp . '/x/../x' ), "3: custom_invoice_dir_is_deletable refuses '..'" );

$nul = $tmp . "/pri\0vate/inv";
try {
	pdt_same( 'plandose_invoice_custom_dir_invalid', $code( Plandose_Invoice_Storage::validate_custom_invoice_dir( $nul ) ), '4: validate_custom_invoice_dir refuses a NUL byte (no ValueError)' );
	pdt_same( '', $resolve( $nul ), '4: resolve_path( NUL ) → \'\'' );
	pdt_same( true, Plandose_Invoice_Storage::path_is_in_public_tree( $nul ), '4: path_is_in_public_tree( NUL ) → public (fail safe)' );
	pdt_same( false, Plandose_Invoice_Storage::custom_invoice_dir_is_deletable( $nul ), '4: custom_invoice_dir_is_deletable( NUL ) → false' );
} catch ( Throwable $e ) {
	pdt_check( false, '4: NUL byte handled without an exception — got ' . get_class( $e ) . ': ' . $e->getMessage() );
}

// With the constant really defined (one child process per value).
$child = static function ( $value ) {
	$wp_load = getenv( 'WP_LOAD' ) ? getenv( 'WP_LOAD' ) : '/home/claude/wpenv/wp-load.php';
	$out     = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --invoice-dir-child ' . escapeshellarg( $value ) . ' 2>&1' );
	$pos     = strrpos( $out, 'PD1311:' );
	return false === $pos ? array( 'raw' => substr( $out, -400 ) ) : json_decode( substr( $out, $pos + 7 ), true );
};
$r = $child( $climb );
pdt_check( is_array( $r ) && isset( $r['config'] ) && 'plandose_invoice_custom_dir_dotdot' === $r['config'], "3: PLANDOSE_INVOICE_DIR with '..' → configured_invoice_dir() refuses it: " . wp_json_encode( $r ) );
pdt_check( is_array( $r ) && isset( $r['public'] ) && true === $r['public'], "3: … invoice_dir_is_public() → true (it lands in uploads)" );
pdt_check( is_array( $r ) && isset( $r['probe'] ) && 0 !== strpos( (string) $r['probe'], 'private/' ), "3: … the probe never reports it private (got " . ( is_array( $r ) && isset( $r['probe'] ) ? $r['probe'] : '?' ) . ')' );
$r = $child( $tmp . '/pri<NUL>vate/inv' );
pdt_check( is_array( $r ) && ! isset( $r['error'] ) && isset( $r['config'] ) && 'plandose_invoice_custom_dir_invalid' === $r['config'], '4: PLANDOSE_INVOICE_DIR with a NUL byte → no fatal, configured_invoice_dir() refuses it: ' . wp_json_encode( $r ) );
pdt_check( is_array( $r ) && isset( $r['public'], $r['probe'], $r['deletable'] ) && true === $r['public'] && 0 !== strpos( (string) $r['probe'], 'private/' ) && false === $r['deletable'], '4: … public (fail safe), never probed private, never deletable' );

/* ======================================================================
 * 5. A trailing "\n" does not satisfy the validation regexes
 * ==================================================================== */

$name = 'plandose-probe-' . str_repeat( 'a', 24 ) . '.pdf';
pdt_same( true, Plandose_Invoice_Storage::is_canary_name( $name ), '5: is_canary_name( canary ) → true' );
pdt_same( false, Plandose_Invoice_Storage::is_canary_name( $name . "\n" ), '5: is_canary_name( canary . "\n" ) → false' );
pdt_same( 0, preg_match( Plandose_Invoice_Migration::COPY_PATTERN, 'lm-' . str_repeat( 'b', 32 ) . ".pdf\n" ), '5: COPY_PATTERN rejects a trailing "\n"' );
pdt_same( 1, preg_match( Plandose_Invoice_Migration::COPY_PATTERN, 'lm-' . str_repeat( 'b', 32 ) . '.pdf' ), '5: … and still matches a real copy name' );
pdt_same( false, pdt_call( 'Plandose_Frontend', 'is_locale_name', "el\n" ), '5: is_locale_name( "el\n" ) → false' );
pdt_same( true, pdt_call( 'Plandose_Frontend', 'is_locale_name', 'de_DE_formal' ), '5: is_locale_name( de_DE_formal ) → true' );
pdt_same( null, Plandose_Print_Log::day_start_ts( "2026-01-02\n" ), '5: day_start_ts( date . "\n" ) → null' );
pdt_check( is_int( Plandose_Print_Log::day_start_ts( '2026-01-02' ) ), '5: day_start_ts( date ) → a timestamp' );

pdt_done();
