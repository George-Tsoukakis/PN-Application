<?php
/**
 * TEST-ONLY worker of test-counter-1800.php: loads WordPress, waits for the
 * common start time, takes one use of a counter, prints 1 (taken) or 0.
 * argv: start time (float), mode «counter» or «transient» (the 1.7.0 way).
 */
$_SERVER['HTTP_HOST'] = '127.0.0.1';
require getenv( 'WP_LOAD' );
$start = (float) $argv[1];
$mode  = $argv[2];
if ( microtime( true ) < $start ) {
	time_sleep_until( $start );
}
if ( 'counter' === $mode ) {
	echo PNChat_Counter::take( 'test:race', 5, 60 ) ? '1' : '0';
} else {
	// What 1.7.0 did: read, add one, save.
	$used = (int) get_transient( 'pnchat_test_race' );
	if ( $used >= 5 ) {
		echo '0';
	} else {
		set_transient( 'pnchat_test_race', $used + 1, 60 );
		echo '1';
	}
}
