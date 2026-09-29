<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Server-side δημιουργία GS1 DataMatrix (ECC200, τετράγωνο) ως PNG, από το
 * επικυρωμένο GS1 element string (raw) του QRRP_GS1_Parser.
 *
 * Το raw έχει ASCII 29 (GS) ανάμεσα στα μεταβλητού μήκους AIs. Σε GS1 mode η
 * tc-lib-barcode μετατρέπει κάθε GS του input σε FNC1 (codeword 232), αλλά δεν
 * προσθέτει το leading FNC1· γι' αυτό προτάσσεται ένα GS μόνο στο input του
 * encoder, ποτέ στο canonical raw.
 *
 * Κάθε PNG επαληθεύεται πριν επιστραφεί, ανεξάρτητα από τα εσωτερικά της
 * βιβλιοθήκης: τα modules διαβάζονται από την ίδια την τελική εικόνα, τα
 * codewords εξάγονται με τον αλγόριθμο τοποθέτησης του ISO/IEC 16022, ελέγχεται
 * το Reed-Solomon, και τα δεδομένα αποκωδικοποιούνται ξανά. Αν η επαλήθευση
 * δεν ολοκληρωθεί, η δημιουργία αποτυγχάνει (fail closed).
 *
 * Βιβλιοθήκη: vendor/autoload-qrrp.php (QRRPVendor\Com\Tecnick\*).
 */
final class QRRP_DataMatrix {

	/** Μέγιστη πλευρά της εικόνας σε pixels (άσχετο με το όριο bytes του raw). */
	private const MAX_DIMENSION = 4096;

	/**
	 * Μέγιστο μήκος raw σε bytes. Το μεγαλύτερο τετράγωνο σύμβολο (144x144) έχει
	 * 1558 data codewords· με ένα codeword ανά χαρακτήρα στη χειρότερη περίπτωση
	 * και ένα για το leading FNC1, 1556 bytes χωράνε πάντα.
	 */
	private const MAX_PAYLOAD_BYTES = 1556;

	private const DEFAULT_SCALE = 12;
	private const DEFAULT_QUIET = 4;

	private const GROUP_SEPARATOR_BYTE = "\x1D";

	/** GS1 character set 82 συν GS. */
	private const CHARSET_PATTERN = '/^[!"%&\'()*+,\-.\/0-9:;<=>?A-Z_a-z\x1D]*\z/';

	/** ECC200 τετράγωνα (ISO/IEC 16022, Table 7): πλευρά => [data region, regions/πλευρά, data cw, ecc cw, blocks]. */
	private const SQUARE_SYMBOLS = array(
		10  => array( 8, 1, 3, 5, 1 ),
		12  => array( 10, 1, 5, 7, 1 ),
		14  => array( 12, 1, 8, 10, 1 ),
		16  => array( 14, 1, 12, 12, 1 ),
		18  => array( 16, 1, 18, 14, 1 ),
		20  => array( 18, 1, 22, 18, 1 ),
		22  => array( 20, 1, 30, 20, 1 ),
		24  => array( 22, 1, 36, 24, 1 ),
		26  => array( 24, 1, 44, 28, 1 ),
		32  => array( 14, 2, 62, 36, 1 ),
		36  => array( 16, 2, 86, 42, 1 ),
		40  => array( 18, 2, 114, 48, 1 ),
		44  => array( 20, 2, 144, 56, 1 ),
		48  => array( 22, 2, 174, 68, 1 ),
		52  => array( 24, 2, 204, 84, 2 ),
		64  => array( 14, 4, 280, 112, 2 ),
		72  => array( 16, 4, 368, 144, 4 ),
		80  => array( 18, 4, 456, 192, 4 ),
		88  => array( 20, 4, 576, 224, 4 ),
		96  => array( 22, 4, 696, 272, 4 ),
		104 => array( 24, 4, 816, 336, 6 ),
		120 => array( 18, 6, 1050, 408, 6 ),
		132 => array( 20, 6, 1304, 496, 8 ),
		144 => array( 22, 6, 1558, 620, 10 ),
	);

	/** True αν υπάρχουν η βιβλιοθήκη και κάθε συνάρτηση GD της δημιουργίας και της επαλήθευσης. */
	public static function is_available() {
		self::load_library();

		if ( ! class_exists( '\\QRRPVendor\\Com\\Tecnick\\Barcode\\Barcode' ) ) {
			return false;
		}

		foreach ( self::required_gd_functions() as $function ) {
			if ( ! function_exists( $function ) ) {
				return false;
			}
		}

		return true;
	}

	/** Οι συναρτήσεις GD της βιβλιοθήκης (getGd), του flatten και της ανάγνωσης modules. */
	private static function required_gd_functions() {
		return array(
			'imagecreate',
			'imagecreatetruecolor',
			'imagecreatefromstring',
			'imagecolorallocate',
			'imagecolortransparent',
			'imagefilledrectangle',
			'imagecopy',
			'imagecolorat',
			'imagecolorsforindex',
			'imagesx',
			'imagesy',
			'imagepng',
		);
	}

	/**
	 * Το GS1 DataMatrix ως PNG bytes με συμπαγές λευκό φόντο.
	 *
	 * @param string $raw  GS1 element string με ASCII 29 separators.
	 * @param array  $args Προαιρετικά: 'scale' (px/module), 'quiet' (modules).
	 * @return string|WP_Error
	 */
	public static function png_bytes_from_raw( $raw, $args = array() ) {
		return self::render_png( $raw, $args );
	}

	/**
	 * Όπως η png_bytes_from_raw(), σε base64 (χωρίς data: prefix).
	 *
	 * @return string|WP_Error
	 */
	public static function png_base64_from_raw( $raw, $args = array() ) {
		$png = self::png_bytes_from_raw( $raw, $args );

		if ( is_wp_error( $png ) ) {
			return $png;
		}

		return base64_encode( $png );
	}

	/**
	 * Το PNG (base64) μαζί με τη γεωμετρία του ίδιου συμβόλου. Χωριστή μέθοδος
	 * ώστε η png_base64_from_raw() να κρατά την υπογραφή string|WP_Error.
	 *
	 * @return array{png:string,geometry:array}|WP_Error
	 */
	public static function png_base64_with_geometry_from_raw( $raw, $args = array() ) {
		$geometry = null;
		$png      = self::render_png( $raw, $args, $geometry );

		if ( is_wp_error( $png ) ) {
			return $png;
		}

		return array(
			'png'      => base64_encode( $png ),
			'geometry' => is_array( $geometry ) ? $geometry : array(),
		);
	}

	/**
	 * Έλεγχος εισόδου, κωδικοποίηση, απόδοση σε λευκό φόντο, επαλήθευση της
	 * τελικής εικόνας. Το $geometry γεμίζει μόνο όταν επιστρέφεται PNG.
	 *
	 * @return string|WP_Error
	 */
	private static function render_png( $raw, $args, &$geometry = null ) {
		$geometry = null;
		$raw      = is_scalar( $raw ) ? (string) $raw : '';

		if ( '' === $raw ) {
			return new WP_Error( 'qrrp_dm_empty', __( 'Δεν δόθηκαν δεδομένα GS1 για δημιουργία DataMatrix.', 'qr-rebuilder-pro' ) );
		}

		if ( strlen( $raw ) > min( qrrp_max_raw_bytes(), self::MAX_PAYLOAD_BYTES ) ) {
			return new WP_Error( 'qrrp_dm_too_large', __( 'Τα δεδομένα GS1 υπερβαίνουν το επιτρεπτό μέγεθος.', 'qr-rebuilder-pro' ) );
		}

		if ( ! preg_match( self::CHARSET_PATTERN, $raw ) ) {
			return new WP_Error( 'qrrp_dm_invalid_chars', __( 'Τα δεδομένα GS1 περιέχουν χαρακτήρες εκτός του συνόλου χαρακτήρων GS1.', 'qr-rebuilder-pro' ) );
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'qrrp_dm_unavailable',
				__( 'Η δημιουργία GS1 DataMatrix δεν είναι διαθέσιμη στον server (λείπει η επέκταση GD ή η απαραίτητη βιβλιοθήκη).', 'qr-rebuilder-pro' )
			);
		}

		$scale = isset( $args['scale'] ) ? (int) $args['scale'] : self::DEFAULT_SCALE;
		$quiet = isset( $args['quiet'] ) ? (int) $args['quiet'] : self::DEFAULT_QUIET;

		$scale = (int) apply_filters( 'qrrp_datamatrix_scale', $scale, $raw );
		$quiet = (int) apply_filters( 'qrrp_datamatrix_quiet_zone', $quiet, $raw );

		$scale = max( 2, min( 40, $scale ) );
		$quiet = max( 1, min( 16, $quiet ) );

		/* Ακριβώς ένα leading GS, που ο encoder κάνει leading FNC1. */
		$encoder_input = self::GROUP_SEPARATOR_BYTE . ltrim( $raw, self::GROUP_SEPARATOR_BYTE );

		try {
			$barcode = new \QRRPVendor\Com\Tecnick\Barcode\Barcode();

			/*
			 * 'DATAMATRIX,S,GS1' = SHAPE,MODE: τετράγωνο σύμβολο σε GS1 mode.
			 * Αρνητικό width/height = pixels ανά module· αρνητικό padding = quiet
			 * zone σε modules (θετική τιμή θα ήταν απόλυτα pixels).
			 */
			$obj = $barcode->getBarcodeObj(
				'DATAMATRIX,S,GS1',
				$encoder_input,
				-1 * $scale,
				-1 * $scale,
				'black',
				array( -1 * $quiet, -1 * $quiet, -1 * $quiet, -1 * $quiet )
			);

			$symbol = $obj->getArray();
			$nrows  = isset( $symbol['nrows'] ) ? (int) $symbol['nrows'] : 0;
			$ncols  = isset( $symbol['ncols'] ) ? (int) $symbol['ncols'] : 0;

			/* Το μέγεθος είναι γνωστό πριν από την απόδοση· απορρίπτεται νωρίς. */
			$width  = ( $ncols + 2 * $quiet ) * $scale;
			$height = ( $nrows + 2 * $quiet ) * $scale;

			if ( $ncols < 1 || $nrows < 1 || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION ) {
				return new WP_Error( 'qrrp_dm_image_too_large', __( 'Η εικόνα του GS1 DataMatrix θα ξεπερνούσε το μέγιστο επιτρεπτό μέγεθος. Μειώστε την κλίμακα ή το περιθώριο.', 'qr-rebuilder-pro' ) );
			}

			/* false: πάντα GD, ώστε το αποτέλεσμα να μην εξαρτάται από το αν υπάρχει Imagick. */
			$source_png = $obj->getPngData( false );
		} catch ( \Throwable $exception ) {
			return new WP_Error(
				'qrrp_dm_encode_failed',
				__( 'Η δημιουργία του GS1 DataMatrix απέτυχε.', 'qr-rebuilder-pro' )
			);
		}

		if ( ! is_string( $source_png ) || "\x89PNG\r\n\x1a\n" !== substr( $source_png, 0, 8 ) ) {
			return new WP_Error( 'qrrp_dm_bad_png', __( 'Το παραγόμενο αρχείο δεν είναι έγκυρη εικόνα.', 'qr-rebuilder-pro' ) );
		}

		$info = @getimagesizefromstring( $source_png ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $info || ! isset( $info[0], $info[1] ) || $width !== (int) $info[0] || $height !== (int) $info[1] ) {
			return new WP_Error( 'qrrp_dm_bad_png', __( 'Το παραγόμενο GS1 DataMatrix δεν πέρασε τον έλεγχο εγκυρότητας.', 'qr-rebuilder-pro' ) );
		}

		$png = self::flatten_to_png( $source_png, $width, $height );

		if ( is_wp_error( $png ) ) {
			return $png;
		}

		$verified = self::verify_rendered_symbol( $png, $nrows, $ncols, $scale, $quiet, $encoder_input );

		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		/* Η γεωμετρία περιγράφει αυτή την εικόνα, με scale/quiet μετά τα φίλτρα. */
		$geometry = array(
			'symbol_rows'         => $nrows,
			'symbol_cols'         => $ncols,
			'quiet_zone'          => $quiet,
			'module_scale'        => $scale,
			'total_width_modules' => qrrp_total_print_modules( $ncols, $quiet ),
		);

		return $png;
	}

	/**
	 * Επαληθεύει την τελική εικόνα: modules → codewords (τοποθέτηση ISO/IEC
	 * 16022 + Reed-Solomon) → πρώτο codeword FNC1 → αποκωδικοποίηση ίση με το
	 * input του encoder. Δεν χρησιμοποιεί τίποτα από τα εσωτερικά της βιβλιοθήκης.
	 *
	 * Η παράκαμψη (φίλτρο qrrp_datamatrix_verify_gs1) ισχύει μόνο αν έχει
	 * οριστεί ρητά η σταθερά QRRP_ALLOW_UNVERIFIED_GS1 — ποτέ με σκέτο φίλτρο.
	 *
	 * @return true|WP_Error
	 */
	private static function verify_rendered_symbol( $png, $nrows, $ncols, $scale, $quiet, $encoder_input ) {
		$bypass_allowed = defined( 'QRRP_ALLOW_UNVERIFIED_GS1' ) && QRRP_ALLOW_UNVERIFIED_GS1;

		if ( $bypass_allowed && ! (bool) apply_filters( 'qrrp_datamatrix_verify_gs1', true ) ) {
			return true;
		}

		$modules   = self::read_modules( $png, $nrows, $ncols, $scale, $quiet );
		$codewords = ( null === $modules ) ? null : self::matrix_data_codewords( $modules );

		return self::gs1_mode_verdict( $codewords, $encoder_input );
	}

	/**
	 * Η απόφαση πάνω στα data codewords ($codewords null = δεν διαβάστηκαν).
	 *
	 * @return true|WP_Error
	 */
	private static function gs1_mode_verdict( $codewords, $encoder_input ) {
		if ( ! is_array( $codewords ) || ! isset( $codewords[0] ) ) {
			self::log_library_path( 'qrrp_dm_unverifiable' );

			return new WP_Error( 'qrrp_dm_unverifiable', self::unverifiable_message() );
		}

		if ( 232 !== (int) $codewords[0] ) {
			self::log_library_path( 'qrrp_dm_not_gs1 (codeword ' . (int) $codewords[0] . ')' );

			return new WP_Error(
				'qrrp_dm_not_gs1',
				sprintf(
					/* translators: %d: first codeword found instead of 232. */
					__( 'Ο κωδικός δεν δημιουργήθηκε ως GS1 DataMatrix (πρώτο codeword %d αντί για 232 = FNC1). Η δημιουργία σταμάτησε για να μη σταλεί μη συμβατός κωδικός. Ο διαχειριστής μπορεί να δει λεπτομέρειες στο Εργαλεία → Υγεία ιστότοπου.', 'qr-rebuilder-pro' ),
					(int) $codewords[0]
				)
			);
		}

		return self::round_trip_verdict( $codewords, $encoder_input );
	}

	/**
	 * Αποκωδικοποιεί τα data codewords και απαιτεί ακριβώς τα δεδομένα που
	 * ζητήθηκαν.
	 *
	 * Δύο διαφορετικά ευρήματα με διαφορετική πολιτική:
	 *  - διαφορετικά ή μη αποκωδικοποιήσιμα δεδομένα => πάντα άρνηση·
	 *  - εσωτερικός separator ως literal GS (C40 Shift1+29) αντί για FNC1 =>
	 *    τα δεδομένα είναι σωστά και το GS1 επιτρέπει και τα δύο ως διαχωριστή,
	 *    οπότε μόνο καταγράφεται· άρνηση μόνο με το φίλτρο
	 *    qrrp_datamatrix_require_fnc1 (τοπική αυστηρότερη πολιτική). Το leading
	 *    FNC1 είναι πάντα υποχρεωτικό.
	 *
	 * @return true|WP_Error
	 */
	private static function round_trip_verdict( $codewords, $encoder_input ) {
		$decoded = self::decode_codewords( $codewords );

		if ( null === $decoded ) {
			self::log_library_path( 'qrrp_dm_undecodable' );

			return new WP_Error(
				'qrrp_dm_undecodable',
				__( 'Ο κωδικός που παρήχθη δεν αποκωδικοποιείται σύμφωνα με το πρότυπο, οπότε η δημιουργία σταμάτησε αντί να παραδοθεί ετικέτα που δεν μπορεί να επαληθευτεί. Αναφέρετέ το στον διαχειριστή του ιστότοπου.', 'qr-rebuilder-pro' )
			);
		}

		if ( $decoded['data'] !== $encoder_input ) {
			self::log_library_path( 'qrrp_dm_payload_mismatch' );

			return new WP_Error(
				'qrrp_dm_payload_mismatch',
				__( 'Ο κωδικός που παρήχθη ΔΕΝ αποκωδικοποιείται στα δεδομένα που ζητήθηκαν, οπότε η δημιουργία σταμάτησε. Μην τυπώσετε κωδικό από άλλη πηγή για αυτή τη συσκευασία — αναφέρετέ το στον διαχειριστή του ιστότοπου.', 'qr-rebuilder-pro' )
			);
		}

		if ( $decoded['literal_gs'] > 0 ) {
			self::report_non_conformant_separator( $decoded['literal_gs'] );

			/*
			 * 2.15.7: αυστηρό από προεπιλογή. Το GS1 (και η EU FMD) θέλουν FNC1 ως
			 * διαχωριστή· η τρέχουσα βιβλιοθήκη το βγάζει πάντα, οπότε αυτό πιάνει
			 * μόνο μελλοντική παλινδρόμηση της βιβλιοθήκης, πριν τυπωθεί ετικέτα.
			 * Επιστροφή false από το φίλτρο = παλιά ανεκτική συμπεριφορά.
			 */
			if ( (bool) apply_filters( 'qrrp_datamatrix_require_fnc1', true ) ) {
				return new WP_Error(
					'qrrp_dm_separator_not_fnc1',
					__( 'Ο διαχωριστής των πεδίων κωδικοποιήθηκε ως χαρακτήρας GS αντί για FNC1, που απαιτεί το πρότυπο GS1. Η δημιουργία σταμάτησε αντί να παραδοθεί μη σύμφωνη ετικέτα. Αναφέρετέ το στον διαχειριστή του ιστότοπου.', 'qr-rebuilder-pro' )
				);
			}
		}

		return true;
	}

	/** Καταγράφει separators που βγήκαν ως literal GS· εξαιρέσεις τρίτων δεν αλλάζουν το αποτέλεσμα. */
	private static function report_non_conformant_separator( $count ) {
		try {
			do_action( 'qrrp_datamatrix_separator_not_fnc1', (int) $count );
		} catch ( \Throwable $exception ) {
			unset( $exception );
		}

		self::log_library_path( 'qrrp_dm_separator_not_fnc1 (' . (int) $count . ')' );
	}

	/**
	 * Διαβάζει τα modules του συμβόλου από το PNG. Κάθε module δειγματοληπτείται
	 * στις δύο γωνίες και στο κέντρο του και πρέπει να είναι ομοιόμορφο· το quiet
	 * zone πρέπει να είναι λευκό.
	 *
	 * @return array<int, array<int, int>>|null Πίνακας [γραμμή][στήλη] με 1 = σκούρο.
	 */
	private static function read_modules( $png, $nrows, $ncols, $scale, $quiet ) {
		$image = @imagecreatefromstring( $png ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $image ) {
			return null;
		}

		$total_rows = $nrows + 2 * $quiet;
		$total_cols = $ncols + 2 * $quiet;

		if ( imagesx( $image ) !== $total_cols * $scale || imagesy( $image ) !== $total_rows * $scale ) {
			return null;
		}

		$offsets = array( 0, intdiv( $scale, 2 ), $scale - 1 );
		$modules = array();

		for ( $row = 0; $row < $total_rows; $row++ ) {
			for ( $col = 0; $col < $total_cols; $col++ ) {
				$value = null;

				foreach ( $offsets as $offset ) {
					$sample = self::pixel_is_dark( $image, $col * $scale + $offset, $row * $scale + $offset );

					if ( null !== $value && $sample !== $value ) {
						return null;
					}

					$value = $sample;
				}

				$in_symbol = $row >= $quiet && $row < $quiet + $nrows && $col >= $quiet && $col < $quiet + $ncols;

				if ( ! $in_symbol ) {
					if ( 1 === $value ) {
						return null;
					}
					continue;
				}

				$modules[ $row - $quiet ][ $col - $quiet ] = $value;
			}
		}

		return $modules;
	}

	/** @return int 1 για σκούρο pixel, 0 για ανοιχτό. */
	private static function pixel_is_dark( $image, $x, $y ) {
		$rgba = imagecolorsforindex( $image, imagecolorat( $image, $x, $y ) );
		$luma = ( 299 * $rgba['red'] + 587 * $rgba['green'] + 114 * $rgba['blue'] ) / 1000;

		return $luma < 128 ? 1 : 0;
	}

	/**
	 * Από τον πίνακα modules στα data codewords (ISO/IEC 16022 ECC200):
	 * έλεγχος finder/timing patterns, αφαίρεσή τους, ανάγνωση με τον αλγόριθμο
	 * τοποθέτησης (Annex F), και έλεγχος Reed-Solomon ανά block.
	 *
	 * @param array<int, array<int, int>> $modules
	 * @return array<int, int>|null
	 */
	private static function matrix_data_codewords( $modules ) {
		$size = count( $modules );

		if ( ! isset( self::SQUARE_SYMBOLS[ $size ] ) || count( $modules[0] ) !== $size ) {
			return null;
		}

		list( $region, $regions, $data_count, $ecc_count, $blocks ) = self::SQUARE_SYMBOLS[ $size ];

		$block   = $region + 2;
		$mapping = array();

		for ( $row = 0; $row < $size; $row++ ) {
			$rdx = $row % $block;

			for ( $col = 0; $col < $size; $col++ ) {
				$cdx = $col % $block;

				if ( 0 === $rdx ) {
					$expected = ( 0 === $cdx % 2 ) ? 1 : 0;
				} elseif ( $block - 1 === $rdx || 0 === $cdx ) {
					$expected = 1;
				} elseif ( $block - 1 === $cdx ) {
					$expected = $rdx % 2;
				} else {
					$mapping[ intdiv( $row, $block ) * $region + $rdx - 1 ][ intdiv( $col, $block ) * $region + $cdx - 1 ] = $modules[ $row ][ $col ];
					continue;
				}

				if ( $modules[ $row ][ $col ] !== $expected ) {
					return null;
				}
			}
		}

		$positions = self::placement( $regions * $region );

		if ( count( $positions['codewords'] ) !== $data_count + $ecc_count ) {
			return null;
		}

		foreach ( $positions['fixed'] as $fixed ) {
			if ( $mapping[ $fixed[0] ][ $fixed[1] ] !== $fixed[2] ) {
				return null;
			}
		}

		$codewords = array();

		foreach ( $positions['codewords'] as $bits ) {
			$value = 0;

			foreach ( $bits as $bit ) {
				$value = ( $value << 1 ) | $mapping[ $bit[0] ][ $bit[1] ];
			}

			$codewords[] = $value;
		}

		if ( ! self::reed_solomon_ok( $codewords, $data_count, $ecc_count, $blocks ) ) {
			return null;
		}

		return array_slice( $codewords, 0, $data_count );
	}

	/**
	 * Ο αλγόριθμος τοποθέτησης ECC200 (ISO/IEC 16022, Annex F) για τετράγωνη
	 * περιοχή δεδομένων $n x $n.
	 *
	 * @param int $n Πλευρά της περιοχής δεδομένων χωρίς finder patterns.
	 * @return array{codewords: array<int, array<int, array{int,int}>>, fixed: array<int, array{int,int,int}>}
	 *         Για κάθε codeword οι 8 θέσεις του (από το MSB), και τα modules του
	 *         σταθερού μοτίβου κάτω δεξιά όταν υπάρχει.
	 */
	private static function placement( $n ) {
		$used      = array();
		$codewords = array();

		$module = static function ( $row, $col ) use ( $n, &$used ) {
			if ( $row < 0 ) {
				$row += $n;
				$col += 4 - ( ( $n + 4 ) % 8 );
			}

			if ( $col < 0 ) {
				$col += $n;
				$row += 4 - ( ( $n + 4 ) % 8 );
			}

			$used[ $row ][ $col ] = true;

			return array( $row, $col );
		};

		$utah = static function ( $row, $col ) use ( $module ) {
			return array(
				$module( $row - 2, $col - 2 ),
				$module( $row - 2, $col - 1 ),
				$module( $row - 1, $col - 2 ),
				$module( $row - 1, $col - 1 ),
				$module( $row - 1, $col ),
				$module( $row, $col - 2 ),
				$module( $row, $col - 1 ),
				$module( $row, $col ),
			);
		};

		$corner = static function ( $cells ) use ( $module ) {
			$bits = array();

			foreach ( $cells as $cell ) {
				$bits[] = $module( $cell[0], $cell[1] );
			}

			return $bits;
		};

		$row = 4;
		$col = 0;

		do {
			if ( $n === $row && 0 === $col ) {
				$codewords[] = $corner( array( array( $n - 1, 0 ), array( $n - 1, 1 ), array( $n - 1, 2 ), array( 0, $n - 2 ), array( 0, $n - 1 ), array( 1, $n - 1 ), array( 2, $n - 1 ), array( 3, $n - 1 ) ) );
			}

			if ( $n - 2 === $row && 0 === $col && 0 !== $n % 4 ) {
				$codewords[] = $corner( array( array( $n - 3, 0 ), array( $n - 2, 0 ), array( $n - 1, 0 ), array( 0, $n - 4 ), array( 0, $n - 3 ), array( 0, $n - 2 ), array( 0, $n - 1 ), array( 1, $n - 1 ) ) );
			}

			if ( $n - 2 === $row && 0 === $col && 4 === $n % 8 ) {
				$codewords[] = $corner( array( array( $n - 3, 0 ), array( $n - 2, 0 ), array( $n - 1, 0 ), array( 0, $n - 2 ), array( 0, $n - 1 ), array( 1, $n - 1 ), array( 2, $n - 1 ), array( 3, $n - 1 ) ) );
			}

			if ( $n + 4 === $row && 2 === $col && 0 === $n % 8 ) {
				$codewords[] = $corner( array( array( $n - 1, 0 ), array( $n - 1, $n - 1 ), array( 0, $n - 3 ), array( 0, $n - 2 ), array( 0, $n - 1 ), array( 1, $n - 3 ), array( 1, $n - 2 ), array( 1, $n - 1 ) ) );
			}

			do {
				if ( $row < $n && $col >= 0 && empty( $used[ $row ][ $col ] ) ) {
					$codewords[] = $utah( $row, $col );
				}
				$row -= 2;
				$col += 2;
			} while ( $row >= 0 && $col < $n );

			++$row;
			$col += 3;

			do {
				if ( $row >= 0 && $col < $n && empty( $used[ $row ][ $col ] ) ) {
					$codewords[] = $utah( $row, $col );
				}
				$row += 2;
				$col -= 2;
			} while ( $row < $n && $col >= 0 );

			$row += 3;
			++$col;
		} while ( $row < $n || $col < $n );

		$fixed = array();

		if ( empty( $used[ $n - 1 ][ $n - 1 ] ) ) {
			$fixed = array(
				array( $n - 1, $n - 1, 1 ),
				array( $n - 2, $n - 2, 1 ),
				array( $n - 1, $n - 2, 0 ),
				array( $n - 2, $n - 1, 0 ),
			);
		}

		return array(
			'codewords' => $codewords,
			'fixed'     => $fixed,
		);
	}

	/**
	 * Έλεγχος Reed-Solomon: για κάθε block (interleaving i mod blocks) όλα τα
	 * syndromes στις ρίζες α^1..α^k του GF(256) με πολυώνυμο 301 είναι μηδέν.
	 *
	 * @return bool
	 */
	private static function reed_solomon_ok( $codewords, $data_count, $ecc_count, $blocks ) {
		static $exp = null;
		static $log = null;

		if ( null === $exp ) {
			$exp = array();
			$log = array_fill( 0, 256, 0 );
			$x   = 1;

			for ( $i = 0; $i < 255; $i++ ) {
				$exp[ $i ] = $x;
				$log[ $x ] = $i;
				$x       <<= 1;

				if ( $x & 0x100 ) {
					$x ^= 301;
				}
			}
		}

		$per_block = intdiv( $ecc_count, $blocks );

		for ( $b = 0; $b < $blocks; $b++ ) {
			$word = array();

			for ( $i = $b; $i < $data_count; $i += $blocks ) {
				$word[] = $codewords[ $i ];
			}

			for ( $i = $data_count + $b; $i < $data_count + $ecc_count; $i += $blocks ) {
				$word[] = $codewords[ $i ];
			}

			for ( $j = 1; $j <= $per_block; $j++ ) {
				$syndrome = 0;

				foreach ( $word as $value ) {
					$syndrome = ( 0 === $syndrome ? 0 : $exp[ ( $log[ $syndrome ] + $j ) % 255 ] ) ^ $value;
				}

				if ( 0 !== $syndrome ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Αποκωδικοποιητής ροής data codewords (high level, ISO/IEC 16022), γραμμένος
	 * από το πρότυπο και όχι από τον encoder. Επιστρέφει null για κάθε κατασκευή
	 * που δεν γνωρίζει με βεβαιότητα (Base 256, ECI, macro, structured append)·
	 * για GS1 charset 82 καμία νόμιμη ροή δεν τις χρειάζεται.
	 *
	 * @return array{data:string, literal_gs:int}|null
	 */
	private static function decode_codewords( $cdw ) {
		$cdw = array_values( array_map( 'intval', (array) $cdw ) );
		$len = count( $cdw );

		$out        = '';
		$literal_gs = 0;
		$mode       = 'ASCII';
		$upper      = false;
		$pending    = 0;
		$idx        = 0;

		while ( $idx < $len ) {
			$code = $cdw[ $idx ];

			if ( 'ASCII' === $mode ) {
				++$idx;

				if ( 129 === $code ) {
					break; // PAD: τέλος δεδομένων.
				}

				if ( 232 === $code ) {
					$out .= self::GROUP_SEPARATOR_BYTE;
					continue;
				}

				if ( 230 === $code || 239 === $code || 238 === $code ) {
					$mode    = ( 230 === $code ) ? 'C40' : ( ( 239 === $code ) ? 'TEXT' : 'X12' );
					$pending = 0;
					continue;
				}

				if ( 240 === $code ) {
					$mode = 'EDF';
					continue;
				}

				if ( 235 === $code ) {
					$upper = true;
					continue;
				}

				if ( $code >= 1 && $code <= 128 ) {
					$char = chr( $code - 1 );

					if ( $upper ) {
						$char  = chr( ( ord( $char ) + 128 ) & 0xFF );
						$upper = false;
					}

					if ( self::GROUP_SEPARATOR_BYTE === $char ) {
						++$literal_gs;
					}

					$out .= $char;
					continue;
				}

				if ( $code >= 130 && $code <= 229 ) {
					$out .= str_pad( (string) ( $code - 130 ), 2, '0', STR_PAD_LEFT );
					continue;
				}

				return null; // 231 Base 256, 233/234/236/237 macro/structured append, 241 ECI.
			}

			if ( 'EDF' === $mode ) {
				/*
				 * ISO/IEC 16022 §5.2.8.2: όταν απομένουν έως δύο codewords, ο
				 * encoder επιστρέφει σε ASCII χωρίς unlatch.
				 */
				if ( ( $len - $idx ) <= 2 && 31 !== ( $cdw[ $idx ] >> 2 ) ) {
					$mode = 'ASCII';
					continue;
				}

				$step = self::decode_edifact_group( $cdw, $idx, $len );

				if ( null === $step ) {
					return null;
				}

				$out  .= $step['text'];
				$idx  += $step['used'];
				$mode  = $step['unlatched'] ? 'ASCII' : 'EDF';
				continue;
			}

			if ( 254 === $code ) {
				$mode    = 'ASCII';
				$pending = 0; // Κρεμάμενο shift στο τελευταίο triplet είναι γέμισμα.
				++$idx;
				continue;
			}

			if ( ( $idx + 1 ) >= $len ) {
				/* ISO/IEC 16022 §5.2.5.2: ένα μόνο codeword στο τέλος είναι ASCII χωρίς unlatch. */
				$mode    = 'ASCII';
				$pending = 0;
				continue;
			}

			$value = ( $cdw[ $idx ] * 256 ) + $cdw[ $idx + 1 ] - 1;
			$idx  += 2;

			$triplet = array(
				intdiv( $value, 1600 ),
				intdiv( $value % 1600, 40 ),
				$value % 40,
			);

			if ( $triplet[0] > 39 ) {
				return null;
			}

			foreach ( $triplet as $item ) {
				$piece = self::decode_triplet_value( $mode, $item, $pending, $literal_gs );

				if ( null === $piece ) {
					return null;
				}

				$out .= $piece;
			}
		}

		return array(
			'data'       => $out,
			'literal_gs' => $literal_gs,
		);
	}

	/**
	 * Μία ομάδα EDIFACT: έως τέσσερις τιμές των 6 bit σε έως τρία codewords.
	 * Η ομάδα μπορεί να κλείνει νωρίτερα με unlatch (τιμή 31)· γι' αυτό κάθε
	 * τιμή ελέγχεται πριν διαβαστεί το επόμενο codeword, ώστε να μην
	 * καταναλωθεί codeword που ανήκει ήδη στο ASCII.
	 *
	 * Τιμή 0-31 => ASCII 64-95, 32-63 => ASCII 32-63.
	 *
	 * @return array{text:string, used:int, unlatched:bool}|null
	 */
	private static function decode_edifact_group( $cdw, $idx, $len ) {
		if ( $idx >= $len ) {
			return null;
		}

		$first = $cdw[ $idx ];
		$value = $first >> 2;

		if ( 31 === $value ) {
			return array( 'text' => '', 'used' => 1, 'unlatched' => true );
		}

		$text = self::edifact_character( $value );

		if ( ( $idx + 1 ) >= $len ) {
			return array( 'text' => $text, 'used' => 1, 'unlatched' => true );
		}

		$second = $cdw[ $idx + 1 ];
		$value  = ( ( $first & 0x03 ) << 4 ) | ( $second >> 4 );

		if ( 31 === $value ) {
			return array( 'text' => $text, 'used' => 2, 'unlatched' => true );
		}

		$text .= self::edifact_character( $value );

		if ( ( $idx + 2 ) >= $len ) {
			return array( 'text' => $text, 'used' => 2, 'unlatched' => true );
		}

		$third = $cdw[ $idx + 2 ];
		$value = ( ( $second & 0x0F ) << 2 ) | ( $third >> 6 );

		if ( 31 === $value ) {
			return array( 'text' => $text, 'used' => 3, 'unlatched' => true );
		}

		$text .= self::edifact_character( $value );
		$value = $third & 0x3F;

		if ( 31 === $value ) {
			return array( 'text' => $text, 'used' => 3, 'unlatched' => true );
		}

		return array(
			'text'      => $text . self::edifact_character( $value ),
			'used'      => 3,
			'unlatched' => false,
		);
	}

	/** Τιμή EDIFACT 0-63 σε χαρακτήρα (το 31 = unlatch το χειρίζεται ο καλών). */
	private static function edifact_character( $value ) {
		return ( $value < 32 ) ? chr( $value + 64 ) : chr( $value );
	}

	/**
	 * Μία τιμή triplet (0-39) σε C40/TEXT/X12, με τα shift sets. $pending = ενεργό
	 * shift set (0 = κανένα)· $literal_gs μετρά literal GS.
	 *
	 * @return string|null
	 */
	private static function decode_triplet_value( $mode, $item, &$pending, &$literal_gs ) {
		if ( 'X12' === $mode ) {
			$x12 = array( 0 => "\r", 1 => '*', 2 => '>', 3 => ' ' );

			if ( isset( $x12[ $item ] ) ) {
				return $x12[ $item ];
			}

			return self::decode_alphanumeric_value( $item, true );
		}

		if ( 0 !== $pending ) {
			$set     = $pending;
			$pending = 0;

			if ( 1 === $set ) {
				if ( $item > 31 ) {
					return null;
				}

				if ( 29 === $item ) {
					++$literal_gs;
				}

				return chr( $item );
			}

			if ( 2 === $set ) {
				return self::decode_shift_two_value( $item );
			}

			/*
			 * Shift 3 (ISO/IEC 16022 Annex C): 0 => '`', 1-26 => a-z (C40) ή
			 * A-Z (TEXT), 27-31 => { | } ~ DEL. Το set έχει μόνο 32 θέσεις.
			 */
			if ( $item > 31 ) {
				return null;
			}

			if ( 0 === $item || $item >= 27 ) {
				return chr( 96 + $item );
			}

			return chr( ( 'C40' === $mode ? 96 : 64 ) + $item );
		}

		if ( $item <= 2 ) {
			$pending = $item + 1;

			return '';
		}

		if ( 3 === $item ) {
			return ' ';
		}

		return self::decode_alphanumeric_value( $item, 'C40' === $mode );
	}

	/** Οι τιμές 4-39 του βασικού set: ψηφία και γράμματα (κεφαλαία σε C40/X12, πεζά σε TEXT). */
	private static function decode_alphanumeric_value( $item, $upper ) {
		if ( $item >= 4 && $item <= 13 ) {
			return (string) ( $item - 4 );
		}

		if ( $item >= 14 && $item <= 39 ) {
			return chr( ( $upper ? 65 : 97 ) + ( $item - 14 ) );
		}

		return null;
	}

	/**
	 * Shift 2: σημεία στίξης και το FNC1 στη θέση 27 (ο σωστός separator μέσα
	 * σε C40/TEXT· το Shift 1 + 29 είναι literal GS και μετριέται χωριστά).
	 *
	 * @return string|null
	 */
	private static function decode_shift_two_value( $item ) {
		if ( 27 === $item ) {
			return self::GROUP_SEPARATOR_BYTE;
		}

		if ( $item <= 14 ) {
			return chr( 33 + $item );
		}

		if ( $item >= 15 && $item <= 21 ) {
			return chr( 58 + ( $item - 15 ) );
		}

		if ( $item >= 22 && $item <= 26 ) {
			return chr( 91 + ( $item - 22 ) );
		}

		return null; // 28-30: Upper Shift κ.λπ. — εκτός charset 82.
	}

	/**
	 * Το μήνυμα όταν η επαλήθευση δεν ολοκληρώθηκε. Δεν περιέχει διαδρομές
	 * αρχείων: τα WP_Error φτάνουν στην AJAX απόκριση.
	 *
	 * @return string
	 */
	private static function unverifiable_message() {
		return __( 'Η δημιουργία σταμάτησε: δεν ήταν δυνατό να επαληθευτεί ότι ο κωδικός που παρήχθη είναι έγκυρο GS1 DataMatrix. Ένας κωδικός που δεν επαληθεύεται δεν παραδίδεται. Αν το πρόβλημα επιμένει, ανεβάστε ξανά ολόκληρο τον φάκελο του πρόσθετου· ο διαχειριστής μπορεί να δει ποια βιβλιοθήκη φορτώθηκε στο Εργαλεία → Υγεία ιστότοπου.', 'qr-rebuilder-pro' );
	}

	/**
	 * Καταγράφει (μόνο με WP_DEBUG) τη διαδρομή της φορτωμένης βιβλιοθήκης. Η
	 * διαδρομή δεν μπαίνει ποτέ σε μήνυμα προς τον χρήστη.
	 *
	 * @param string $context Ποιο σφάλμα προκάλεσε την καταγραφή.
	 */
	private static function log_library_path( $context ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		if ( ! function_exists( 'error_log' ) ) {
			return;
		}

		error_log( 'QR ReBuilder Pro: ' . $context . ' — loaded barcode library: ' . self::library_path() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Διαγνωστικά για το Site Health (βλ. QRRP_Admin): βιβλιοθήκη και GD
	 * χωριστά, και αν η βιβλιοθήκη φορτώθηκε από το ενσωματωμένο αντίγραφο.
	 *
	 * @return array{library_loaded:bool, library_is_bundled:bool, library_path:string, gd_available:bool, missing_gd_functions:array}
	 */
	public static function diagnostics() {
		self::load_library();

		$library_loaded = class_exists( '\\QRRPVendor\\Com\\Tecnick\\Barcode\\Barcode' );
		$missing_gd     = array();

		foreach ( self::required_gd_functions() as $function ) {
			if ( ! function_exists( $function ) ) {
				$missing_gd[] = $function;
			}
		}

		return array(
			'library_loaded'       => $library_loaded,
			'library_is_bundled'   => $library_loaded && self::library_is_bundled(),
			'library_path'         => $library_loaded ? self::library_path() : '',
			'gd_available'         => array() === $missing_gd,
			'missing_gd_functions' => $missing_gd,
		);
	}

	/**
	 * True αν η φορτωμένη βιβλιοθήκη βρίσκεται μέσα στο ενσωματωμένο vendor/
	 * (σύγκριση πραγματικών διαδρομών, όχι υποσυμβολοσειρών).
	 */
	private static function library_is_bundled( $path = null ) {
		$file = realpath( null === $path ? self::library_path() : (string) $path );
		$base = realpath( QRRP_PLUGIN_DIR . 'vendor/tc-lib-barcode' );

		if ( ! is_string( $file ) || ! is_string( $base ) || '' === $base ) {
			return false;
		}

		$base = rtrim( str_replace( '\\', '/', $base ), '/' ) . '/';
		$file = str_replace( '\\', '/', $file );

		return 0 === strpos( $file, $base );
	}

	/** Από πού φορτώθηκε η tc-lib-barcode (για διάγνωση). */
	public static function library_path() {
		try {
			$reflection = new \ReflectionClass( '\QRRPVendor\Com\Tecnick\Barcode\Barcode' );
			$file       = $reflection->getFileName();

			return is_string( $file ) ? $file : 'unknown';
		} catch ( \Throwable $exception ) {
			return 'unknown';
		}
	}

	/**
	 * Αποδίδει την (διαφανή) εικόνα της βιβλιοθήκης σε συμπαγές λευκό φόντο και
	 * την κωδικοποιεί σε PNG.
	 *
	 * Τα GdImage (PHP 8+) ελευθερώνονται αυτόματα· δεν καλείται imagedestroy().
	 *
	 * @return string|WP_Error
	 */
	private static function flatten_to_png( $source_png, $width, $height ) {
		$source = @imagecreatefromstring( $source_png ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$canvas = ( false === $source ) ? false : imagecreatetruecolor( $width, $height );

		if ( false === $canvas ) {
			return new WP_Error( 'qrrp_dm_gd_failed', __( 'Η επεξεργασία της εικόνας GS1 DataMatrix απέτυχε.', 'qr-rebuilder-pro' ) );
		}

		$white = imagecolorallocate( $canvas, 255, 255, 255 );
		imagefilledrectangle( $canvas, 0, 0, $width - 1, $height - 1, $white );
		imagecopy( $canvas, $source, 0, 0, 0, 0, $width, $height );

		/*
		 * Το output buffer είναι η εικόνα: ένα warning της GD με ενεργό
		 * display_errors θα κατέστρεφε τα bytes, γι' αυτό το @. Η επιτυχία
		 * κρίνεται από την επιστρεφόμενη τιμή.
		 */
		ob_start();
		$ok    = @imagepng( $canvas, null, 6 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$bytes = ob_get_clean();

		if ( ! $ok || ! is_string( $bytes ) || '' === $bytes ) {
			return new WP_Error( 'qrrp_dm_encode_failed', __( 'Η κωδικοποίηση της εικόνας GS1 DataMatrix απέτυχε.', 'qr-rebuilder-pro' ) );
		}

		return $bytes;
	}

	private static function load_library() {
		if ( class_exists( '\\QRRPVendor\\Com\\Tecnick\\Barcode\\Barcode' ) ) {
			return;
		}

		$autoloader = QRRP_PLUGIN_DIR . 'vendor/autoload-qrrp.php';

		if ( is_readable( $autoloader ) ) {
			require_once $autoloader;
		}
	}
}
