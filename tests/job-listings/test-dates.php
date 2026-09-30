<?php
/* Site-local datetimes (9.9.58). */
jbli_test_load( 'includes/expiry-parts/jbli-lifecycle.php', 'jbli_local_datetime_to_ts' );
jbli_test_load( 'includes/expiry-parts/jbli-lifecycle.php', 'jbli_future_datetime' );

$ts = jbli_local_datetime_to_ts( '2026-10-10 23:30:00' );
ok( '10/10/2026 23:30' === wp_date( 'd/m/Y H:i', $ts ), 'late-evening expiry shows the same day' );
ok( '2026-10-10T23:30:00+03:00' === wp_date( 'c', $ts ), 'JSON-LD validThrough keeps local time and offset' );
ok( '00:00:00' === substr( jbli_future_datetime( 3, 'midnight' ), 11 ), 'midnight is local midnight' );
$diff = jbli_local_datetime_to_ts( jbli_future_datetime( 30 ) ) - time();
ok( abs( $diff - 30 * DAY_IN_SECONDS ) <= HOUR_IN_SECONDS + 5, '+30 days is 30 days (±1h DST)' );
ok( false === jbli_local_datetime_to_ts( '' ) && false === jbli_local_datetime_to_ts( 'garbage' ), 'invalid input → false' );
ok( jbli_local_datetime_to_ts( '2026-10-30T23:59:59+00:00' ) === strtotime( '2026-10-30T23:59:59+00:00' ), 'explicit offset respected' );
