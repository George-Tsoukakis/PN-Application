<?php
/*
 * 2.15.4: επιλογή renderer DataMatrix για τα tests.
 *
 *   qrrp_test_renderer( 'fake' ) — tests λογικής (provenance, email, λήξη, όρια):
 *       ψεύτικη QRRP_DataMatrix που δεν χρειάζεται GD. Επιστρέφει έγκυρο PNG 1x1
 *       και κρατά το raw που της δόθηκε, ώστε το test να ελέγχει τι θα κωδικοποιούνταν.
 *   qrrp_test_renderer( 'real' ) — integration tests: η πραγματική κλάση. Χωρίς GD
 *       τυπώνει «SKIP <test>: ...» και τερματίζει με 0 (όχι ψευδές FAIL).
 */

function qrrp_test_renderer( $mode ) {
	$pd = getenv( 'PDIR' ) ?: dirname( __DIR__, 2 ) . '/qr-rebuilder-pro';

	if ( 'real' === $mode ) {
		if ( ! extension_loaded( 'gd' ) ) {
			echo 'SKIP ' . basename( $_SERVER['argv'][0] ?? 'test' ) . ": η επέκταση GD λείπει (integration test για πραγματικό DataMatrix)\n";
			exit( 0 );
		}

		require_once $pd . '/includes/class-qrrp-datamatrix.php';

		return;
	}

	if ( class_exists( 'QRRP_DataMatrix', false ) ) {
		return;
	}

	eval( '
	final class QRRP_DataMatrix {
		public static $raws = array();
		const PNG_1X1 = "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAAAAAA6fptVAAAACklEQVR4nGP4DwABAQEAWk1v8QAAAABJRU5ErkJggg==";
		public static function png_bytes_from_raw( $raw, $args = array() ) {
			self::$raws[] = (string) $raw;
			return base64_decode( self::PNG_1X1 );
		}
		public static function png_base64_from_raw( $raw, $args = array() ) {
			self::$raws[] = (string) $raw;
			return self::PNG_1X1;
		}
		public static function png_base64_with_geometry_from_raw( $raw, $args = array() ) {
			self::$raws[] = (string) $raw;
			return array(
				"png"      => self::PNG_1X1,
				"geometry" => array( "symbol_rows" => 22, "symbol_cols" => 22, "quiet_zone" => 2, "total_width_modules" => 26 ),
			);
		}
	}' );
}
