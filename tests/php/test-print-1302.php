<?php
/**
 * 1.30.2: print-charge review fixes. TEST-ONLY.
 *
 * - A connection lost right AFTER the counter UPDATE (before COMMIT) never
 *   answers «success» with the counter rolled back (an uncounted print);
 *   a connection lost BEFORE it still charges exactly once.
 * - A normal charge runs no statement between the counter UPDATE and COMMIT
 *   (no look-up of the rows it just wrote).
 * - undo_charge() deletes a request row only as that charge wrote it.
 * - A free reprint without a request id fails closed.
 * - Plandose_Print_Log::counts_since_each(): one query, same figures.
 * - Admin prints: array user meta never shows «Array»; DST-safe periods.
 *
 * The connection is KILLed for real through a second connection, as in
 * tests/wp-integration/pd-test-mu.php; wpdb then reconnects and re-runs the
 * statement in autocommit.
 */
require __DIR__ . '/lib.php';

global $wpdb;

$uid = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );

$pdt_counter_re = '/^\s*UPDATE\s+\S*plandose_subscriptions\b.*print_count\s*=\s*print_count\s*\+/is';

/** KILL wpdb's own connection through a second one. */
function pdt_kill_wpdb_connection() {
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

/** The fields of a Plandose_Print_Result. */
function pdt_result( $result ) {
	$out = array();
	foreach ( array( 'success', 'payload' ) as $prop ) {
		$p = new ReflectionProperty( 'Plandose_Print_Result', $prop );
		$p->setAccessible( true );
		$out[ $prop ] = $p->getValue( $result );
	}
	return $out;
}

/** A print request as register_print() builds it. */
function pdt_request( $uid, $token, $rid ) {
	list( $row, $is_pro, $limit, $today ) = pdt_call( 'Plandose_Ajax', 'get_current_print_context', $uid );
	return new Plandose_Print_Request( $uid, $token, Plandose_Print_Request::request_hash( $rid ), $row, $is_pro, $limit, $today );
}

/** register_print() without the HTTP part. */
function pdt_print( $uid, $token, $rid ) {
	$request = pdt_request( $uid, $token, $rid );
	$result  = Plandose_Print_Replay::answer( $request );
	if ( null === $result ) {
		$result = Plandose_Print_New_Charge::charge( $request );
	}
	return pdt_result( $result );
}

function pdt_state( $uid, $token, $rid ) {
	global $wpdb;
	return array(
		'count'   => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT print_count FROM ' . Plandose_Subscriptions::table_name() . ' WHERE user_id = %d', $uid ) ),
		'charge'  => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Plandose_Print_Charges::table_name() . ' WHERE user_id = %d AND token_hash = %s', $uid, Plandose_Print_Request::receipt_key( $token ) ) ),
		'request' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Plandose_Print_Charges::requests_table_name() . ' WHERE user_id = %d AND request_hash = %s', $uid, Plandose_Print_Request::request_hash( $rid ) ) ),
	);
}

$token_of = static function () {
	return md5( wp_generate_password( 20, false ) );
};

// A query filter that records statements and fires one fault.
$GLOBALS['pdt_fault'] = '';
$GLOBALS['pdt_seen']  = array();
$GLOBALS['pdt_armed'] = false;
add_filter(
	'query',
	static function ( $query ) use ( $pdt_counter_re ) {
		if ( ! is_string( $query ) || '' === $GLOBALS['pdt_fault'] ) {
			return $query;
		}
		$GLOBALS['pdt_seen'][] = $query;
		$is_counter            = (bool) preg_match( $pdt_counter_re, $query );

		if ( 'kill_before_increment' === $GLOBALS['pdt_fault'] && $is_counter ) {
			$GLOBALS['pdt_fault'] = 'fired';
			pdt_kill_wpdb_connection();
			return $query;
		}
		if ( 'kill_after_increment' === $GLOBALS['pdt_fault'] ) {
			if ( $GLOBALS['pdt_armed'] ) {
				$GLOBALS['pdt_fault'] = 'fired';
				pdt_kill_wpdb_connection();
				return $query;
			}
			if ( $is_counter ) {
				$GLOBALS['pdt_armed'] = true;
			}
		}
		return $query;
	},
	1
);
$arm = static function ( $fault ) {
	$GLOBALS['pdt_fault'] = $fault;
	$GLOBALS['pdt_seen']  = array();
	$GLOBALS['pdt_armed'] = false;
};

$wpdb->suppress_errors( true );

// ---- A normal charge: COMMIT follows the counter UPDATE directly.
$token = $token_of();
$rid   = $token_of();
$arm( 'record' );
$res  = pdt_print( $uid, $token, $rid );
$seen = $GLOBALS['pdt_seen'];
$arm( '' );
pdt_same( true, $res['success'], 'normal charge succeeds' );
$pos  = null;
foreach ( $seen as $i => $q ) {
	if ( preg_match( $pdt_counter_re, $q ) ) {
		$pos = $i;
	}
}
pdt_check( null !== $pos && isset( $seen[ $pos + 1 ] ) && preg_match( '/^\s*COMMIT\b/i', $seen[ $pos + 1 ] ), 'no statement between the counter UPDATE and COMMIT (no row look-ups on a normal charge)' );
$st = pdt_state( $uid, $token, $rid );
pdt_same( array( 'count' => 1, 'charge' => 1, 'request' => 1 ), $st, 'counter, charge row, request row' );

// ---- Connection lost right AFTER the counter UPDATE (before COMMIT).
$token  = $token_of();
$rid    = $token_of();
$before = pdt_state( $uid, $token, $rid )['count'];
$arm( 'kill_after_increment' );
$res = pdt_print( $uid, $token, $rid );
$fired = 'fired' === $GLOBALS['pdt_fault'];
$arm( '' );
pdt_check( $fired, 'the fault fired (connection killed after the counter UPDATE)' );
$st = pdt_state( $uid, $token, $rid );
if ( $res['success'] ) {
	pdt_same( $before + 1, $st['count'], 'success after a lost connection: the print IS counted' );
} else {
	pdt_same( $before, $st['count'], '«try again»: nothing counted' );
	pdt_same( 0, $st['charge'] + $st['request'], '«try again»: no rows left behind' );
}
pdt_same( false, $res['success'], 'answered «try again» (the transaction was rolled back with the connection)' );
$res = pdt_print( $uid, $token, $rid );
pdt_same( true, $res['success'], 'the retry (same request id) succeeds' );
pdt_same( array( 'count' => $before + 1, 'charge' => 1, 'request' => 1 ), pdt_state( $uid, $token, $rid ), 'the retry charges exactly once' );
$res = pdt_print( $uid, $token, $rid );
pdt_same( $before + 1, pdt_state( $uid, $token, $rid )['count'], 'a replay of it is not charged again' );

// ---- Connection lost BEFORE the counter UPDATE: the UPDATE ran in autocommit.
$token  = $token_of();
$rid    = $token_of();
$before = pdt_state( $uid, $token, $rid )['count'];
$arm( 'kill_before_increment' );
$res   = pdt_print( $uid, $token, $rid );
$fired = 'fired' === $GLOBALS['pdt_fault'];
$arm( '' );
pdt_check( $fired, 'the fault fired (connection killed before the counter UPDATE)' );
pdt_same( true, $res['success'], 'answered success' );
pdt_same( array( 'count' => $before + 1, 'charge' => 1, 'request' => 1 ), pdt_state( $uid, $token, $rid ), 'counted once, rows put back' );
pdt_print( $uid, $token, $rid );
pdt_same( $before + 1, pdt_state( $uid, $token, $rid )['count'], 'its retry is not charged again' );

$wpdb->suppress_errors( false );

// ---- undo_charge(): the request row only as this charge wrote it.
$rtable = Plandose_Print_Charges::requests_table_name();
$tok_a  = md5( 'pdt-undo-a' . wp_generate_password( 6, false ) );
$tok_b  = md5( 'pdt-undo-b' . wp_generate_password( 6, false ) );
$rhash  = md5( 'pdt-undo-req' . wp_generate_password( 6, false ) );
pdt_same( true, Plandose_Print_Charges::write_charge( $uid, $tok_a, null ), 'write_charge for the undo test' );
pdt_same( 'ok', Plandose_Print_Charges::record_request( $uid, $rhash, $tok_b, Plandose_Print_Charges::KIND_CHARGE ), 'a request row of ANOTHER token' );
Plandose_Print_Charges::undo_charge( $uid, $tok_a, null, $rhash );
pdt_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$rtable} WHERE user_id = %d AND request_hash = %s", $uid, $rhash ) ), 'undo_charge leaves a request row of another token alone' );
pdt_same( null, Plandose_Print_Charges::find( $uid, $tok_a ), 'undo_charge removed its own charge row' );
$wpdb->delete( $rtable, array( 'user_id' => $uid, 'request_hash' => $rhash ) );
pdt_same( true, Plandose_Print_Charges::write_charge( $uid, $tok_a, null ), 'write_charge again' );
pdt_same( 'ok', Plandose_Print_Charges::record_request( $uid, $rhash, $tok_a, Plandose_Print_Charges::KIND_CHARGE ), 'its own request row' );
Plandose_Print_Charges::undo_charge( $uid, $tok_a, null, $rhash );
pdt_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$rtable} WHERE user_id = %d AND request_hash = %s", $uid, $rhash ) ), 'undo_charge deletes its own request row' );

// ---- MAX_REPLAYS: a constant of its own.
pdt_same( 2, Plandose_Print_Charges::MAX_REPLAYS, 'MAX_REPLAYS is 2' );
$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/plandose/includes/class-plandose-print-charges.php' );
pdt_check( false === strpos( $src, 'MAX_REPLAYS = Plandose_Ajax::' ), 'MAX_REPLAYS no longer aliases MAX_FREE_REPRINTS' );

// ---- A free reprint without a request id fails closed.
$token = $token_of();
$rid   = $token_of();
pdt_same( true, pdt_print( $uid, $token, $rid )['success'], 'charge for the reprint test' );
$charge  = Plandose_Print_Charges::find( $uid, Plandose_Print_Request::receipt_key( $token ) );
$request = new Plandose_Print_Request( $uid, $token, '', null, false, 30, current_time( 'Y-m-d' ) );
pdt_same( 'error', pdt_call( 'Plandose_Print_Replay', 'try_free_reprint', $request, $charge ), "try_free_reprint with request_hash '' → 'error'" );
pdt_same( 0, (int) Plandose_Print_Charges::find( $uid, Plandose_Print_Request::receipt_key( $token ) )->reprints, '… and no free reprint used' );

// ---- counts_since_each(): one query, the same figures as counts_since().
$moments = array( time() - 3600, time() - 7 * DAY_IN_SECONDS, time() - 30 * DAY_IN_SECONDS );
$queries = 0;
$counter = static function ( $q ) use ( &$queries ) {
	if ( is_string( $q ) && false !== strpos( $q, Plandose_Print_Log::table_name() ) ) {
		++$queries;
	}
	return $q;
};
add_filter( 'query', $counter );
$each = Plandose_Print_Log::counts_since_each( $moments );
remove_filter( 'query', $counter );
pdt_same( 1, $queries, 'counts_since_each(): one query for three periods' );
pdt_check( is_array( $each ) && 3 === count( $each ), 'three results' );
foreach ( $moments as $i => $m ) {
	pdt_same( Plandose_Print_Log::counts_since( $m ), $each[ $i ], 'period ' . $i . ' matches counts_since()' );
}
Plandose_Print_Log::record( $uid, Plandose_Print_Log::KIND_REPRINT, false, 0 );
$after = Plandose_Print_Log::counts_since_each( $moments );
pdt_same( $each[0]['reprint'] + 1, $after[0]['reprint'], 'a new reprint is counted in the shortest period' );
pdt_same( $each[2]['charge'], $after[2]['charge'], '… and not as a charge' );
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Plandose_Print_Log::table_name() . ' WHERE user_id = %d', $uid ) );

// ---- Admin prints: labels() with array user meta; DST-safe periods.
update_user_meta( $uid, Plandose_Access::PHARMACY_NAME_META_KEYS[0], array( 'x' => 'y' ) );
$labels = pdt_call( 'Plandose_Admin_Prints', 'labels', array( (object) array( 'user_id' => $uid ) ) );
pdt_check( isset( $labels[ $uid ] ) && 'Array' !== $labels[ $uid ]['name'] && is_string( $labels[ $uid ]['name'] ) && '' !== $labels[ $uid ]['name'], 'array-valued name meta falls back to the display name: ' . ( isset( $labels[ $uid ] ) ? $labels[ $uid ]['name'] : '?' ) );
update_user_meta( $uid, Plandose_Access::PHARMACY_NAME_META_KEYS[0], 'Φαρμακείο Δοκιμής' );
$labels = pdt_call( 'Plandose_Admin_Prints', 'labels', array( (object) array( 'user_id' => $uid ) ) );
pdt_same( 'Φαρμακείο Δοκιμής', $labels[ $uid ]['name'], 'a string name is shown' );
delete_user_meta( $uid, Plandose_Access::PHARMACY_NAME_META_KEYS[0] );

$found = pdt_call( 'Plandose_Admin_Prints', 'search_user_ids', 'pdt-no-such-pharmacy-' . wp_generate_password( 8, false ) );
pdt_same( array( 'ids' => array( 0 ), 'capped' => false ), $found, 'search_user_ids(): no match, not capped (capped = MAX_SUBSCRIBER_PAGE_SIZE matches, shown as a notice)' );

$saved_tz = get_option( 'timezone_string' );
update_option( 'timezone_string', 'Europe/Athens' );
pdt_defer( static function () use ( $saved_tz ) { update_option( 'timezone_string', $saved_tz ); } );
// 2 Nov 2026, 10:00 Athens: the 7- and 30-day tiles reach back across the
// switch from summer time (25 Oct 2026).
$now     = new DateTimeImmutable( '2026-11-02 10:00:00', new DateTimeZone( 'Europe/Athens' ) );
$periods = Plandose_Admin_Prints::summary_periods( $now );
$fmt     = static function ( $ts ) {
	return wp_date( 'Y-m-d H:i', $ts, new DateTimeZone( 'Europe/Athens' ) );
};
pdt_same( '2026-11-02 00:00', $fmt( $periods[0][1] ), 'today starts at 00:00' );
pdt_same( '2026-10-27 00:00', $fmt( $periods[1][1] ), '7 days start at 00:00 (not 01:00) across the DST change' );
pdt_same( '2026-10-04 00:00', $fmt( $periods[2][1] ), '30 days start at 00:00 (not 01:00) across the DST change' );

pdt_done();
