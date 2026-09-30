<?php
/* Listings map (9.9.62): every seeded νομός has a pin inside the map. */
if ( ! function_exists( 'remove_accents' ) ) {
	function remove_accents( $s ) { return strtr( $s, array( 'ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ό' => 'ο', 'ύ' => 'υ', 'ώ' => 'ω', 'ϊ' => 'ι', 'ΐ' => 'ι', 'Ά' => 'Α', 'Έ' => 'Ε', 'Ή' => 'Η', 'Ί' => 'Ι', 'Ό' => 'Ο', 'Ύ' => 'Υ', 'Ώ' => 'Ω' ) ); }
}
jbli_test_load( 'modules/listings/listings-parts/jbli-map.php', 'jbli_listings_map_key' );

$map = require $GLOBALS['JBLI_PLUGIN'] . 'modules/listings/jbli-map-data.php';
$src = file_get_contents( $GLOBALS['JBLI_PLUGIN'] . 'includes/jbli-taxonomies.php' );
preg_match( '/function jbli_seed_nomoi\(\).*?array\((.*?)\);/s', $src, $m );
preg_match_all( "/'([^']+)'/u", $m[1] ?? '', $names );

$keys = array();
foreach ( $map['pins'] as $n => $xy ) {
	$keys[ jbli_listings_map_key( $n ) ] = $xy;
	ok( $xy[0] > 0 && $xy[0] < $map['width'] && $xy[1] > 0 && $xy[1] < $map['height'], "pin '$n' is inside the map" );
}

ok( 51 === count( $names[1] ), '51 seeded νομοί found' );
foreach ( $names[1] as $n ) { ok( isset( $keys[ jbli_listings_map_key( $n ) ] ), "νομός '$n' has a pin" ); }
ok( jbli_listings_map_key( 'ΑΤΤΙΚΉ ' ) === jbli_listings_map_key( 'Αττική' ), 'name match ignores case, accents and spaces' );
ok( is_readable( $GLOBALS['JBLI_PLUGIN'] . 'modules/listings/img/jbli-greece.svg' ), 'map outline file exists' );
