<?php
/**
 * Runs every tests/job-listings/test-*.php file. Exit code 1 on any failure.
 */
require __DIR__ . '/bootstrap.php';

foreach ( glob( __DIR__ . '/test-*.php' ) as $jbli_test ) {
	$before = $GLOBALS['FAILS'];
	require $jbli_test;
	echo ( $GLOBALS['FAILS'] === $before ? 'ok   ' : 'FAIL ' ) . basename( $jbli_test ) . "\n";
}

echo "\n{$GLOBALS['PASSES']} passed, {$GLOBALS['FAILS']} failed\n";
exit( $GLOBALS['FAILS'] ? 1 : 0 );
