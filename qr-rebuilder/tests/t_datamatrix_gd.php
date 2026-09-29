<?php
/*
 * 2.15.4 — integration: πραγματικό GS1 DataMatrix με GD (SKIP χωρίς GD).
 * Κάθε payload περνά από τον parser (validate_and_build) και από την πραγματική
 * QRRP_DataMatrix, που επαληθεύει μόνη της το τελικό PNG (FNC1, Reed-Solomon,
 * αποκωδικοποίηση) και αρνείται ό,τι δεν ταιριάζει. Εδώ ελέγχουμε ότι βγαίνει
 * PNG σωστών διαστάσεων για τις περιπτώσεις που άλλαξαν στην 2.15.3.
 * php t_datamatrix_gd.php
 */
require __DIR__ . '/bootajax.php';
$PD = getenv( 'PDIR' ) ?: dirname( __DIR__ ) . '/qr-rebuilder-pro';
if ( ! defined( 'QRRP_PLUGIN_DIR' ) ) { define( 'QRRP_PLUGIN_DIR', $PD . '/' ); }
if ( ! function_exists( 'qrrp_asset_version' ) ) { function qrrp_asset_version( $p ) { return '1'; } }
require_once __DIR__ . '/lib/renderer.php';
qrrp_test_renderer( 'real' );

$fails = 0;
function check( $label, $ok ) { global $fails; if ( ! $ok ) { $fails++; } echo ( $ok ? 'PASS' : 'FAIL' ), ' ', $label, "\n"; }

$cases = array(
	'basic'           => array( array( 'PC' => '05012345678900', 'SN' => 'SN1', 'LOT' => 'LOT1', 'EXP' => '2028-03-31' ), array() ),
	'DD=00 kept'      => array( array( 'PC' => '05012345678900', 'SN' => 'SN1', 'LOT' => 'LOT1', 'EXP' => '2028-02-00' ), array() ),
	'W serial (Σ)'    => array( array( 'PC' => '05012345678900', 'SN' => 'ABW12', 'LOT' => 'L1', 'EXP' => '2027-12-31' ), array() ),
	'max 20+20 chars' => array( array( 'PC' => '05012345678900', 'SN' => str_repeat( 'A', 20 ), 'LOT' => str_repeat( '9', 20 ), 'EXP' => '2030-01-31' ), array() ),
	'CSET82 punct'    => array( array( 'PC' => '05012345678900', 'SN' => 'A!"%&\'()*+', 'LOT' => ',-./:;<=>?_', 'EXP' => '2029-06-30' ), array() ),
);

foreach ( $cases as $label => $case ) {
	list( $fields, $extras ) = $case;
	$built = QRRP_GS1_Parser::validate_and_build( $fields, $extras );

	if ( ! empty( $built['errors'] ) || empty( $built['raw'] ) ) {
		check( "$label: fields valid", false );
		continue;
	}

	$out = QRRP_DataMatrix::png_base64_with_geometry_from_raw( $built['raw'] );
	$ok  = is_array( $out ) && ! empty( $out['png'] );
	check( "$label: real DataMatrix produced and self-verified", $ok );

	if ( ! $ok ) {
		continue;
	}

	$img = getimagesizefromstring( base64_decode( $out['png'] ) );
	$geo = $out['geometry'];
	check( "$label: PNG square, geometry " . $geo['symbol_rows'] . 'x' . $geo['symbol_cols'], is_array( $img ) && IMAGETYPE_PNG === $img[2] && $img[0] === $img[1] && $geo['symbol_rows'] >= 10 );

	/*
	 * 2.15.5: pad codewords κατά ISO/IEC 16022 §5.2.3 (253-state, θέση 1-based).
	 * Η tc-lib-barcode 2.14.0 τα υπολόγιζε με θέση 0-based· ο σαρωτής τα
	 * αγνοεί, οπότε μόνο αυτός ο έλεγχος το πιάνει. Μόνο σύμβολα ενός block,
	 * όπου τα data codewords προηγούνται των ECC.
	 */
	$sq = ( new ReflectionClassConstant( 'QRRP_DataMatrix', 'SQUARE_SYMBOLS' ) )->getValue();
	$n  = (int) $geo['symbol_rows'];
	if ( isset( $sq[ $n ] ) && 1 === $sq[ $n ][4] ) {
		$scale = ( new ReflectionClassConstant( 'QRRP_DataMatrix', 'DEFAULT_SCALE' ) )->getValue();
		$mods  = ( new ReflectionMethod( 'QRRP_DataMatrix', 'read_modules' ) )->invoke( null, base64_decode( $out['png'] ), $n, $n, $scale, (int) $geo['quiet_zone'] );
		$cw    = ( new ReflectionMethod( 'QRRP_DataMatrix', 'matrix_data_codewords' ) )->invoke( null, $mods );
		$data  = is_array( $cw ) ? array_slice( $cw, 0, $sq[ $n ][2] ) : array();
		$first = array_search( 129, $data, true );
		$pads_ok = is_array( $cw );
		if ( false !== $first ) {
			for ( $i = $first + 1; $i < count( $data ); $i++ ) {
				$r = ( ( 149 * ( $i + 1 ) ) % 253 ) + 1;
				$t = 129 + $r;
				$t = $t > 254 ? $t - 254 : $t;
				$pads_ok = $pads_ok && ( $data[ $i ] === $t );
			}
		}
		check( "$label: pad codewords ISO/IEC 16022 §5.2.3", $pads_ok );
	}

	if ( 'DD=00 kept' === $label ) {
		check( 'DD=00: encoded element string keeps 17280200', false !== strpos( $built['raw'], '17280200' ) );
	}
}

/* Μη έγκυρο raw: ο renderer αρνείται (fail closed), δεν βγάζει εικόνα. */
$bad = QRRP_DataMatrix::png_base64_with_geometry_from_raw( '' );
check( 'empty raw → WP_Error, no image', is_wp_error( $bad ) );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
