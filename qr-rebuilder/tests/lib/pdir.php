<?php
/*
 * 2.15.4: ο φάκελος του plugin που ελέγχεται. Σειρά: μεταβλητή PDIR, αλλιώς
 * φάκελος «qr-rebuilder-pro» δίπλα στον φάκελο των tests (όπως βγαίνουν τα δύο
 * zip στον ίδιο φάκελο). Αλλιώς καθαρό μήνυμα και έξοδος 2 — ποτέ διαδρομή
 * άλλου υπολογιστή.
 */
if ( ! function_exists( 'qrrp_test_pdir' ) ) {
	function qrrp_test_pdir() {
		static $pdir = null;

		if ( null !== $pdir ) {
			return $pdir;
		}

		$env        = getenv( 'PDIR' );
		$tests      = dirname( __DIR__ );
		$candidates = ( false !== $env && '' !== $env )
			? array( $env )
			: array( dirname( $tests ) . '/qr-rebuilder-pro', $tests . '/qr-rebuilder-pro' );

		foreach ( $candidates as $dir ) {
			$dir = rtrim( $dir, '/\\' );

			if ( is_file( $dir . '/qr-rebuilder-pro.php' ) ) {
				$pdir = realpath( $dir );
				putenv( 'PDIR=' . $pdir );

				return $pdir;
			}
		}

		fwrite(
			STDERR,
			( false !== $env && '' !== $env )
				? "PDIR=$env: δεν βρέθηκε qr-rebuilder-pro.php εκεί.\n"
				: "Δεν βρέθηκε το plugin. Βάλτε τον φάκελο qr-rebuilder-pro δίπλα στον φάκελο των tests, ή ορίστε PDIR=/path/to/qr-rebuilder-pro\n"
		);
		exit( 2 );
	}
}

qrrp_test_pdir();
