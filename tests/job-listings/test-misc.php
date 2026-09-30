<?php
/* Option keys, cache limits, bot detection (9.9.58–9.9.59). */
jbli_test_load( 'includes/jbli-sanitizers.php', 'jbli_sanitize_option_key' );
ok( '2200+' === jbli_sanitize_option_key( '2200+' ), '"2200+" keeps the +' );
ok( 'negotiable' === jbli_sanitize_option_key( ' Negotiable ' ), 'lowercased and trimmed' );
ok( 'abc' === jbli_sanitize_option_key( 'a b<c>' ), 'other characters removed' );

jbli_test_load( 'includes/jbli-storage-queries.php', 'jbli_cached_ids_for_limit' );
$c = array( 'limit' => 100, 'ids' => range( 1, 50 ) );
ok( 10 === count( jbli_cached_ids_for_limit( $c, 10 ) ), 'smaller limit served by slicing' );
ok( 50 === count( jbli_cached_ids_for_limit( $c, 100 ) ), 'same limit served' );
ok( null === jbli_cached_ids_for_limit( $c, 0 ), 'unlimited request misses a limited entry' );
ok( null === jbli_cached_ids_for_limit( array( 'limit' => 10, 'ids' => array( 1 ) ), 100 ), 'larger limit misses' );
ok( null === jbli_cached_ids_for_limit( array( 1, 2, 3 ), 10 ), 'old cache format misses' );

jbli_test_load( 'includes/jbli-view-counter.php', 'jbli_is_bot_user_agent' );
$uas = array(
	'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1' => false,
	'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36 Edg/126.0' => false,
	'Mozilla/5.0 (Linux; Android 13; wv) AppleWebKit/537.36 Chrome/120 Mobile Safari/537.36 [FB_IAB/FB4A;FBAV/450.0;]' => false,
	'Mozilla/5.0 (compatible; Googlebot/2.1)' => true,
	'WhatsApp/2.23' => true,
	'facebookexternalhit/1.1' => true,
	'WP Rocket/Preload' => true,
	'' => true,
);
foreach ( $uas as $ua => $e ) { ok( jbli_is_bot_user_agent( $ua ) === $e, 'bot check: ' . substr( $ua, 0, 40 ) ); }
