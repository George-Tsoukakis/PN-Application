<?php
/**
 * PSR-4 autoloader για τις ενσωματωμένες tc-lib-barcode και tc-lib-color
 * (Nicola Asuni / Tecnick.com, LGPL-3.0), που χρησιμοποιούνται μόνο από την
 * QRRP_DataMatrix.
 *
 * Οι κλάσεις έχουν ιδιωτικό πρόθεμα `QRRPVendor\Com\Tecnick\…` (βλ.
 * QRRP-VENDOR-NOTES.md), οπότε δεν συγκρούονται με αντίγραφο της ίδιας
 * βιβλιοθήκης από άλλο πρόσθετο και ο loader δεν χρειάζεται να είναι πρώτος.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'qrrp_register_vendor_autoloader' ) ) {

	function qrrp_register_vendor_autoloader() {
		static $registered = false;

		if ( $registered ) {
			return;
		}

		$registered = true;

		/*
		 * Πρόθεμα => πραγματικός κατάλογος με τελικό separator, υπολογισμένος μία
		 * φορά. Ο separator κάνει τον έλεγχο «μέσα στον κατάλογο» πραγματικό όριο
		 * καταλόγου: το realpath() τον αφαιρεί, και χωρίς αυτόν το …/src-evil/
		 * θα περνούσε ως μέρος του …/src.
		 */
		$prefixes = array();

		foreach (
			array(
				'QRRPVendor\\Com\\Tecnick\\Barcode\\' => __DIR__ . '/tc-lib-barcode/src',
				'QRRPVendor\\Com\\Tecnick\\Color\\'   => __DIR__ . '/tc-lib-color/src',
			) as $prefix => $dir
		) {
			$real = realpath( $dir );

			if ( false !== $real ) {
				$prefixes[ $prefix ] = rtrim( $real, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
			}
		}

		spl_autoload_register(
			static function ( $class ) use ( $prefixes ) {
				foreach ( $prefixes as $prefix => $real_base ) {
					$len = strlen( $prefix );

					if ( 0 !== strncmp( $class, $prefix, $len ) ) {
						continue;
					}

					/* Μόνο αρχεία μέσα στον κατάλογο της βιβλιοθήκης (όχι «..», symlink προς τα έξω, NUL). */
					$relative  = substr( $class, $len );
					$real_file = ( false === strpos( $relative, "\0" ) )
						? realpath( $real_base . str_replace( '\\', '/', $relative ) . '.php' )
						: false;

					if (
						false !== $real_file
						&& 0 === strncmp( $real_file, $real_base, strlen( $real_base ) )
						&& is_file( $real_file )
					) {
						require $real_file;
					}

					return;
				}
			},
			true,
			false
		);
	}
}

qrrp_register_vendor_autoloader();
