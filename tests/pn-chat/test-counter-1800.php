<?php
/**
 * 1.8.0: atomic counters (rate limits, AI daily limit, AI totals).
 */
require __DIR__ . '/lib.php';
global $wpdb;
$ct = PNChat_Counter::table();
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE k LIKE %s', $ct, 'test:%' ) );
pnt_defer(
	function () use ( $wpdb, $ct ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE k LIKE %s', $ct, 'test:%' ) );
		delete_transient( 'pnchat_test_race' );
	}
);

// ---- basic -------------------------------------------------------------------
pnt_same( 1, PNChat_Counter::add( 'test:a' ), 'add: a new counter is 1' );
pnt_same( 2, PNChat_Counter::add( 'test:a' ), 'add: then 2' );
pnt_same( 7, PNChat_Counter::add( 'test:a', 5 ), 'add: +5 is 7' );
pnt_same( 7, PNChat_Counter::get( 'test:a' ), 'get: 7' );

$ok = 0;
for ( $i = 0; $i < 8; $i++ ) {
	$ok += PNChat_Counter::take( 'test:b', 3, 60 ) ? 1 : 0;
}
pnt_same( 3, $ok, 'take: 3 of 8 pass a limit of 3' );
pnt_same( 3, PNChat_Counter::get( 'test:b' ), 'take: refused uses are not counted' );
PNChat_Counter::give_back( 'test:b' );
pnt_check( PNChat_Counter::take( 'test:b', 3, 60 ), 'give_back: the use can be taken again' );

// Window over: starts again from 1.
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET exp = %d WHERE k = %s', $ct, time() - 1, 'test:b' ) );
pnt_same( 0, PNChat_Counter::get( 'test:b' ), 'get: 0 once the window ended' );
pnt_same( 1, PNChat_Counter::add( 'test:b', 1, 60 ), 'add: an ended window starts again at 1' );
PNChat_Counter::purge();
pnt_same( 1, PNChat_Counter::get( 'test:b' ), 'purge keeps live counters' );
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET exp = %d WHERE k = %s', $ct, time() - 2 * HOUR_IN_SECONDS, 'test:b' ) );
PNChat_Counter::purge();
pnt_same( null, $wpdb->get_var( $wpdb->prepare( 'SELECT n FROM %i WHERE k = %s', $ct, 'test:b' ) ), 'purge deletes ended counters' );

// ---- real race: 30 PHP processes at the same moment, limit 5 -----------------
function pnt_race( $mode ) {
	$start = microtime( true ) + 3.0;
	$procs = array();
	for ( $i = 0; $i < 30; $i++ ) {
		$procs[] = popen( 'php ' . escapeshellarg( __DIR__ . '/race-worker.php' ) . ' ' . $start . ' ' . $mode . ' 2>&1', 'r' );
	}
	$taken = 0;
	foreach ( $procs as $p ) {
		$taken += (int) trim( (string) stream_get_contents( $p ) );
		pclose( $p );
	}
	return $taken;
}
$taken = pnt_race( 'counter' );
pnt_same( 5, $taken, '30 parallel requests, limit 5: exactly 5 pass' );
pnt_same( 5, PNChat_Counter::get( 'test:race' ), '30 parallel requests: the counter says 5' );
echo 'info   the 1.7.0 transient way let ' . pnt_race( 'transient' ) . " of 30 pass a limit of 5\n";

// ---- AI daily limit and totals ----------------------------------------------
pnt_settings( array( 'ai_chat_daily' => 2 ) );
$day = 'ai_chat:' . gmdate( 'Ymd' );
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE k = %s', $ct, $day ) );
pnt_defer(
	function () use ( $wpdb, $ct, $day ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE k = %s', $ct, $day ) );
	}
);
pnt_check( PNChat_AI::chat_take() && PNChat_AI::chat_take(), 'AI day: 2 calls of 2' );
pnt_check( ! PNChat_AI::chat_take(), 'AI day: the third is refused' );
pnt_same( 2, PNChat_AI::chat_used_today(), 'AI day: used today 2' );
PNChat_AI::chat_give_back();
pnt_same( 1, PNChat_AI::chat_used_today(), 'AI day: a call that never reached Claude is given back' );
pnt_check( PNChat_AI::not_charged( new WP_Error( 'pnchat_ai_http', 'x', array( 'not_sent' => true ) ) ) && ! PNChat_AI::not_charged( new WP_Error( 'pnchat_ai_json', 'x' ) ), 'not_charged: request never sent yes, answered-but-bad no' );
pnt_check( ! PNChat_AI::not_charged( new WP_Error( 'pnchat_ai_http', 'x', array( 'not_sent' => false ) ) ) && ! PNChat_AI::not_charged( new WP_Error( 'pnchat_ai_http', 'x' ) ), 'not_charged: an uncertain HTTP error (time-out) is not given back' );
foreach ( array( 'cURL error 6: Could not resolve host' => true, 'cURL error 7: Failed to connect' => true, 'cURL error 35: SSL connect error' => true, 'cURL error 28: Operation timed out' => false, 'cURL error 52: Empty reply from server' => false, 'Something else' => false ) as $msg => $want ) {
	pnt_same( $want, PNChat_AI::never_sent( new WP_Error( 'http_request_failed', $msg ) ), "never_sent: «{$msg}»" );
}

// Ended limits are deleted at the next rate-limited request (1.8.1).
$wpdb->query( $wpdb->prepare( 'INSERT INTO %i ( k, n, exp ) VALUES ( %s, 3, %d )', $ct, 'test:rl-old', time() - 5 ) );
wp_set_current_user( 0 );
$_SERVER['REMOTE_ADDR'] = '203.0.113.200';
pnt_call( 'PNChat_Rest', 'rate_ok', 'test', 5, 60 );
pnt_same( null, $wpdb->get_var( $wpdb->prepare( 'SELECT n FROM %i WHERE k = %s', $ct, 'test:rl-old' ) ), 'purge: an ended limit (its IP hash) is gone at the next question' );
pnt_check( (bool) wp_next_scheduled( 'pnchat_hourly' ), 'purge: the hourly clean-up is scheduled' );
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE k LIKE %s', $ct, 'rl:test:%' ) );

// Upgrading by deactivate + activate also moves the old AI totals (1.8.1).
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE k LIKE %s', $ct, 'usage:%' ) );
update_option( 'pnchat_ai_usage', array( 'calls' => 4 ), false );
update_option( 'pnchat_db_version', 2, false );
pnchat_activate();
pnt_same( 4, PNChat_AI::usage()['calls'] ?? null, 'activation: old AI totals moved to the counters' );
pnt_same( false, get_option( 'pnchat_ai_usage' ), 'activation: old option removed' );

// Old option totals move into the counters.
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE k LIKE %s', $ct, 'usage:%' ) );
update_option( 'pnchat_ai_usage', array( 'calls' => 3, 'input_tokens' => 1000, 'micro_usd' => 5000 ), false );
PNChat_AI::migrate_usage();
$u = PNChat_AI::usage();
pnt_same( 3, $u['calls'] ?? null, 'usage migration: calls' );
pnt_same( 5000, $u['micro_usd'] ?? null, 'usage migration: cost' );
pnt_same( false, get_option( 'pnchat_ai_usage' ), 'usage migration: old option removed' );
// Cache writes cost 1.25x input: Opus 5.5, 1M written tokens = $5.
pnt_call( 'PNChat_AI', 'add_usage', array( 'cache_creation_input_tokens' => 1000000 ), 'claude-opus-5-5' );
pnt_same( 5000 + 5000000, PNChat_AI::usage()['micro_usd'] ?? null, 'cost: cache writes at 1.25x the input price' );
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE k LIKE %s', $ct, 'usage:%' ) );

pnt_done();
