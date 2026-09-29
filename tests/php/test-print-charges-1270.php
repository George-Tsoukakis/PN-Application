<?php
/**
 * 1.27.0: duplicate-key detection by error number (Greek server messages),
 * the 60-second replay window of a request id, case-insensitive engine map.
 */
require __DIR__ . '/lib.php';

global $wpdb;

$uid   = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
$hash  = md5( 'pdt-request-' . wp_generate_password( 8, false ) );
$token = md5( 'pdt-token' );

// ---- 1062 with lc_messages = el_GR: the message says «Διπλή εγγραφή», not "duplicate".
$wpdb->query( "SET SESSION lc_messages = 'el_GR'" );
pdt_defer( static function () use ( $wpdb ) { $wpdb->query( "SET SESSION lc_messages = 'en_US'" ); } );

pdt_same( 'ok', Plandose_Print_Charges::record_request( $uid, $hash, $token, Plandose_Print_Charges::KIND_CHARGE ), 'first record_request inserts' );
pdt_same( 'duplicate', Plandose_Print_Charges::record_request( $uid, $hash, $token, Plandose_Print_Charges::KIND_CHARGE ), 'second record_request with a Greek error message is still «duplicate» (errno 1062)' );
// The same duplicate by hand: the message is Greek, the number is 1062.
$suppress = $wpdb->suppress_errors( true );
$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . Plandose_Print_Charges::requests_table_name() . " (user_id, request_hash, token_hash, kind, created_ts) VALUES (%d, %s, %s, 'charge', %d)", $uid, $hash, $token, time() ) );
$wpdb->suppress_errors( $suppress );
pdt_check( '' !== $wpdb->last_error && false === stripos( (string) $wpdb->last_error, 'duplicate' ), 'the server message is not English (the pre-1.27.0 text check would have missed it): ' . $wpdb->last_error );
pdt_same( true, Plandose_Print_Charges::last_error_is_duplicate_key(), 'last_error_is_duplicate_key() sees errno 1062' );

// Any other error is not a duplicate.
$suppress = $wpdb->suppress_errors( true );
$wpdb->query( 'INSERT INTO pdt_no_such_table (x) VALUES (1)' );
$wpdb->suppress_errors( $suppress );
pdt_same( false, Plandose_Print_Charges::last_error_is_duplicate_key(), 'a missing-table error is not a duplicate key' );

// ---- Replays: the whole window, at most MAX_REPLAYS times (atomic replay_count).
pdt_same( 2, Plandose_Print_Charges::MAX_REPLAYS, 'MAX_REPLAYS equals the client promise (MAX_FREE_REPRINTS)' );

$wpdb->update(
	Plandose_Print_Charges::requests_table_name(),
	array( 'created_ts' => time() - 600 ),
	array( 'user_id' => $uid, 'request_hash' => $hash )
);
$req = Plandose_Print_Charges::find_request( $uid, $hash );
pdt_check( is_object( $req ), 'a 10-minute-old record is still found (window = PRINT_RECEIPT_TTL)' );
pdt_same( 1, Plandose_Print_Charges::use_replay( $req ), 'replay 1 counted' );
pdt_same( 1, Plandose_Print_Charges::use_replay( $req ), 'replay 2 counted' );
pdt_same( 0, Plandose_Print_Charges::use_replay( $req ), 'replay 3 refused' );
pdt_same( '2', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT replay_count FROM ' . Plandose_Print_Charges::requests_table_name() . ' WHERE id = %d', $req->id ) ), 'replay_count stops at 2' );

// Racing connections: of 6 concurrent UPDATEs at most MAX_REPLAYS win.
$wpdb->update( Plandose_Print_Charges::requests_table_name(), array( 'replay_count' => 0 ), array( 'id' => $req->id ) );
$sql   = $wpdb->prepare( 'UPDATE ' . Plandose_Print_Charges::requests_table_name() . ' SET replay_count = replay_count + 1 WHERE id = %d AND replay_count < %d', $req->id, Plandose_Print_Charges::MAX_REPLAYS );
$conns = array();
for ( $i = 0; $i < 6; $i++ ) {
	$c = new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
	$c->query( $sql, MYSQLI_ASYNC );
	$conns[] = $c;
}
$won = 0;
foreach ( $conns as $c ) {
	$r = $c->reap_async_query();
	$won += $c->affected_rows > 0 ? 1 : 0;
	$c->close();
}
pdt_same( 2, $won, 'concurrent replays: exactly MAX_REPLAYS succeed' );

// Past the window the record is gone and the id is recorded anew.
$wpdb->update( Plandose_Print_Charges::requests_table_name(), array( 'created_ts' => Plandose_Print_Charges::live_since() - 5 ), array( 'id' => $req->id ) );
pdt_same( null, Plandose_Print_Charges::find_request( $uid, $hash ), 'record older than the window is not found' );
pdt_same( 0, Plandose_Print_Charges::use_replay( $req ), 'an expired record is never replayed' );
pdt_same( 'ok', Plandose_Print_Charges::record_request( $uid, $hash, $token, Plandose_Print_Charges::KIND_REPRINT ), 'the same id after the window is recorded again as a NEW request (old row replaced)' );

$rows = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Plandose_Print_Charges::requests_table_name() . ' WHERE user_id = %d AND request_hash = %s', $uid, $hash ) );
pdt_same( 1, $rows, 'still one row for that id' );

// ---- engines(): keyed by our spelling even when the server reports another case.
$filter = static function ( $query ) {
	if ( is_string( $query ) && false !== strpos( $query, 'information_schema.TABLES' ) ) {
		return "SELECT UPPER(TABLE_NAME) AS t, ENGINE AS e FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '%plandose%'";
	}
	return $query;
};
add_filter( 'query', $filter );
$engines = Plandose_Print_Charges::engines();
remove_filter( 'query', $filter );

pdt_same(
	array( Plandose_Subscriptions::table_name(), Plandose_Print_Charges::table_name(), Plandose_Print_Charges::requests_table_name() ),
	array_keys( $engines ),
	'engines() keys are our table names'
);
pdt_check( 3 === count( array_filter( $engines, static function ( $e ) { return 0 === strcasecmp( $e, 'InnoDB' ); } ) ), 'upper-cased names from the server still map to their engines: ' . wp_json_encode( $engines ) );

pdt_same( true, Plandose_Subscriptions::same_table_name( 'WP_PLANDOSE_SUBSCRIPTIONS', 'wp_plandose_subscriptions' ), 'table name comparison is case-insensitive' );
pdt_same( false, Plandose_Subscriptions::same_table_name( null, 'wp_plandose_subscriptions' ), 'missing table is not a match' );

// ---- subscriptions table is InnoDB after create_table() (ENGINE declared + ensure_innodb()).
// Run on a separate table prefix so the shared test site is never touched.
$real_prefix  = $wpdb->prefix;
$wpdb->prefix = 'pdt' . wp_rand( 1000, 9999 ) . '_';
$pdt_tables   = array();
pdt_defer(
	static function () use ( $wpdb, $real_prefix, &$pdt_tables ) {
		foreach ( $pdt_tables as $t ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$t}`" );
		}
		$wpdb->prefix = $real_prefix;
	}
);
$pdt_tables = array(
	Plandose_Subscriptions::table_name(),
	Plandose_Subscriptions::audit_table_name(),
	Plandose_Subscriptions::history_table_name(),
	Plandose_Print_Charges::table_name(),
	Plandose_Print_Charges::requests_table_name(),
);
$engine_of = static function ( $t ) use ( $wpdb ) {
	return $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $t ) );
};

pdt_check( Plandose_Subscriptions::create_table(), 'create_table() on a fresh prefix succeeds' );
pdt_same( 'InnoDB', $engine_of( Plandose_Subscriptions::table_name() ), 'new subscriptions table is InnoDB' );

$wpdb->query( 'ALTER TABLE ' . Plandose_Subscriptions::table_name() . ' ENGINE=MyISAM' );
pdt_same( 'MyISAM', $engine_of( Plandose_Subscriptions::table_name() ), 'precondition: an old MyISAM subscriptions table' );
pdt_check( Plandose_Subscriptions::create_table(), 'create_table() (the version-change path) succeeds' );
pdt_same( 'InnoDB', $engine_of( Plandose_Subscriptions::table_name() ), 'the MyISAM subscriptions table is converted to InnoDB on upgrade' );

$wpdb->prefix = $real_prefix;

pdt_done();
