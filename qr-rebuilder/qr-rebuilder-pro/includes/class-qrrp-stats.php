<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Μετρητές χρήσης ανά μήνα: επιτυχείς δημιουργίες κωδικού, απορρίψεις και
 * το «σχήμα» των αναγνώσεων. Το `ok` μετρά δημιουργίες, όχι συσκευασίες
 * (αναδημιουργίες και επανεκτυπώσεις μετράνε ξεχωριστά).
 *
 * Αποθηκεύεται μόνο μήνας και αριθμός — ποτέ PC/SN/LOT/EXP, χρήστης, IP ή
 * raw payload.
 */
class QRRP_Stats {

	/**
	 * Ένα option με όλους τους μήνες (σταθερό όνομα για το uninstall). Υπό
	 * ακραία ταυτοχρονία μπορεί να χαθεί μία μέτρηση — αποδεκτό.
	 */
	const OPTION = 'qrrp_stats';

	/** Ίδιο όριο προσπαθειών με τα άλλα CAS του plugin. */
	const CAS_ATTEMPTS = 5;

	/**
	 * Μετρητές ανάγνωσης, ανεξάρτητες σημαίες από το αποτέλεσμα του parser.
	 * `inferred`: δεν ήρθαν separators και τα όρια συμπεράνθηκαν.
	 */
	const SCAN_BUCKETS = array( 'scans', 'inferred', 'confirm', 'ambiguous', 'extra_ai', 'low' );

	/**
	 * Διαμέριση κατά symbology identifier: κάθε σάρωση σε ακριβώς έναν κάδο.
	 * Παλαιότεροι μήνες δεν έχουν αυτούς τους μετρητές.
	 */
	const SYMBOLOGY_BUCKETS = array( 'symbology_d2', 'symbology_other', 'symbology_none' );

	/** Κάθε μετρητής ενός μήνα, με τη σειρά αποθήκευσης. */
	const ALL_BUCKETS = array( 'ok', 'rejected', 'scans', 'inferred', 'confirm', 'ambiguous', 'extra_ai', 'low', 'symbology_d2', 'symbology_other', 'symbology_none' );

	/**
	 * Οι μήνες και τα σύνολα. Τα σύνολα υπολογίζονται εδώ και δεν
	 * αποθηκεύονται, ώστε να μην αποκλίνουν από τους μήνες.
	 *
	 * @return array{months:array,totals:array,total_ok:int,total_rejected:int,since:string}
	 */
	public static function read() {
		$stored = get_option( self::OPTION, array() );
		$months = self::normalize( $stored );

		$totals = array_fill_keys( self::ALL_BUCKETS, 0 );

		foreach ( $months as $counts ) {
			foreach ( self::ALL_BUCKETS as $bucket ) {
				$totals[ $bucket ] += $counts[ $bucket ];
			}
		}

		$keys = array_keys( $months );
		sort( $keys );

		return array(
			'months'         => $months,
			'totals'         => $totals,
			'total_ok'       => $totals['ok'],
			'total_rejected' => $totals['rejected'],
			'since'          => empty( $keys ) ? '' : $keys[0],
		);
	}

	/** Οι μετρητές ενός μήνα, με όλα τα κλειδιά παρόντα. */
	public static function month( $key ) {
		$data = self::read();

		return isset( $data['months'][ $key ] )
			? $data['months'][ $key ]
			: self::empty_month();
	}

	/**
	 * Έγκυρο κλειδί μήνα 'YYYY-MM'. Ελέγχεται και το εύρος του μήνα: ένα
	 * «2026-13» θα κυλούσε στον Ιανουάριο του επόμενου έτους στην gmmktime().
	 *
	 * @param mixed $key Υποψήφιο κλειδί.
	 * @return bool
	 */
	public static function is_month_key( $key ) {
		if ( ! is_scalar( $key ) || 1 !== preg_match( '/^\d{4}-(\d{2})\z/', (string) $key, $m ) ) {
			return false;
		}

		$month = (int) $m[1];

		return $month >= 1 && $month <= 12;
	}

	/* ============ Δημόσιο snapshot ============ */

	/** Υποφάκελος μέσα στο uploads· ένα αρχείο, κανένα προσωπικό δεδομένο. */
	const SNAPSHOT_DIR  = 'qrrp';
	const SNAPSHOT_FILE = 'usage.json';

	/**
	 * Διαδρομή του δημόσιου snapshot. Η σελίδα του εργαλείου μπορεί να
	 * σερβίρεται από page cache, οπότε ο μετρητής ενημερώνεται από στατικό
	 * αρχείο (χωρίς bootstrap του WordPress). Πηγή αλήθειας μένει το option.
	 *
	 * @return string Απόλυτη διαδρομή, ή '' αν το uploads δεν είναι διαθέσιμο.
	 */
	private static function snapshot_path() {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return '';
		}

		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		return rtrim( (string) $uploads['basedir'], '/\\' ) . '/' . self::SNAPSHOT_DIR . '/' . self::SNAPSHOT_FILE;
	}

	/**
	 * Η δημόσια διεύθυνση του snapshot, ή '' αν το αρχείο δεν υπάρχει (ώστε
	 * ο browser να μη ζητήσει 404).
	 *
	 * @return string
	 */
	public static function snapshot_url() {
		$path = self::snapshot_path();

		if ( '' === $path || ! is_readable( $path ) ) {
			return '';
		}

		$uploads = wp_upload_dir();

		if ( empty( $uploads['baseurl'] ) ) {
			return '';
		}

		return rtrim( (string) $uploads['baseurl'], '/' ) . '/' . self::SNAPSHOT_DIR . '/' . self::SNAPSHOT_FILE;
	}

	/**
	 * Γράφει το snapshot ατομικά (προσωρινό αρχείο + rename). Αποτυγχάνει
	 * σιωπηλά, και δεν αντικαθιστά μεγαλύτερο σύνολο του ίδιου μήνα.
	 *
	 * @param array|null $months Οι μήνες όπως γράφτηκαν στη βάση (έξοδος της
	 *                           normalize()). Αν λείπουν, διαβάζεται το option.
	 * @return bool True αν γράφτηκε.
	 */
	public static function publish_public_snapshot( $months = null ) {
		try {
			$path = self::snapshot_path();

			if ( '' === $path ) {
				return false;
			}

			$dir = dirname( $path );

			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				return false;
			}

			if ( ! is_writable( $dir ) ) {
				return false;
			}

			if ( ! is_array( $months ) ) {
				$months = self::normalize( get_option( self::OPTION, array() ) );
			}

			$period = self::current_month();
			$total  = 0;

			foreach ( $months as $counts ) {
				$total += (int) $counts['ok'];
			}

			$month_ok = isset( $months[ $period ] ) ? (int) $months[ $period ]['ok'] : 0;

			self::clean_stale_snapshot_tmp( $dir );

			if ( ! self::snapshot_may_advance( $path, $period, $total ) ) {
				return false;
			}

			/*
			 * Το αρχείο είναι δημόσιο: περιέχει μόνο ό,τι φαίνεται ήδη στη
			 * σελίδα, συν τον μήνα στον οποίο αναφέρεται.
			 */
			$payload = wp_json_encode(
				array(
					'total'  => $total,
					'month'  => $month_ok,
					'period' => $period,
				)
			);

			if ( ! is_string( $payload ) ) {
				return false;
			}

			$tmp = $path . '.' . wp_generate_password( 8, false, false ) . '.tmp';

			if ( false === file_put_contents( $tmp, $payload, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Ατομική εγγραφή σε προσωρινό αρχείο· το WP_Filesystem δεν προσφέρει rename().
				return false;
			}

			if ( ! rename( $tmp, $path ) ) {
				wp_delete_file( $tmp );

				return false;
			}

			return true;
		} catch ( Throwable $ignored ) {
			unset( $ignored );

			return false;
		}
	}

	/**
	 * Γράφει μόνο αν το νέο σύνολο δεν είναι μικρότερο από αυτό που ήδη
	 * δημοσιεύτηκε για τον ίδιο μήνα.
	 *
	 * @param string $path   Διαδρομή του snapshot.
	 * @param string $period Τρέχων μήνας 'YYYY-MM'.
	 * @param int    $total  Νέο σύνολο.
	 * @return bool
	 */
	private static function snapshot_may_advance( $path, $period, $total ) {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return true;
		}

		$existing = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Τοπικό αρχείο.

		if ( ! is_array( $existing ) || ! isset( $existing['period'], $existing['total'] )
			|| ! is_scalar( $existing['period'] ) || (string) $existing['period'] !== (string) $period
			|| ! is_numeric( $existing['total'] ) ) {
			return true;
		}

		return (int) $total >= (int) $existing['total'];
	}

	/**
	 * Σβήνει προσωρινά αρχεία παλαιότερα της μίας ώρας (π.χ. από διακοπείσα
	 * εγγραφή).
	 *
	 * @param string $dir Φάκελος του snapshot.
	 */
	private static function clean_stale_snapshot_tmp( $dir ) {
		$stale = glob( $dir . '/' . self::SNAPSHOT_FILE . '.*.tmp' );

		if ( ! is_array( $stale ) ) {
			return;
		}

		$cutoff = time() - HOUR_IN_SECONDS;

		foreach ( $stale as $file ) {
			$mtime = @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Μπορεί να σβήστηκε ήδη από άλλη αίτηση.

			if ( false !== $mtime && $mtime < $cutoff ) {
				wp_delete_file( $file );
			}
		}
	}

	/**
	 * Το δημόσιο μπλοκ χρήσης: μόνο σύνολο και τρέχων μήνας (οι απορρίψεις
	 * είναι διαγνωστικό του διαχειριστή). Το εξωτερικό `.qrrp-wrapper`
	 * φέρνει τις μεταβλητές CSS όταν το μπλοκ αποδίδεται εκτός wrapper.
	 *
	 * @return string Έτοιμο HTML, ή '' όταν δεν υπάρχει τίποτα να δειχτεί.
	 */
	public static function public_markup() {
		$data = self::read();

		if ( '' === $data['since'] || $data['total_ok'] < 1 ) {
			return '';
		}

		$current = self::current_month();
		$month   = isset( $data['months'][ $current ] ) ? $data['months'][ $current ]['ok'] : 0;

		$total_label = _n(
			'Συνολικό DataMatrix που δημιουργήθηκε επιτυχώς',
			'Συνολικά DataMatrix που δημιουργήθηκαν επιτυχώς',
			$data['total_ok'],
			'qr-rebuilder-pro'
		);

		$month_label = _n(
			'DataMatrix που δημιουργήθηκε επιτυχώς αυτόν τον μήνα',
			'DataMatrix που δημιουργήθηκαν επιτυχώς αυτόν τον μήνα',
			$month,
			'qr-rebuilder-pro'
		);

		/*
		 * Η PHP αποδίδει τους αριθμούς κανονικά (ορατοί χωρίς JavaScript)· το
		 * qrrp-usage.js τους ενημερώνει από το snapshot του data-qrrp-usage-src.
		 */
		$src = self::snapshot_url();

		$html = '<div class="qrrp-wrapper qrrp-wrapper-usage">'
			. '<div class="qrrp-usage" role="status"'
			. ( '' !== $src ? ' data-qrrp-usage-src="' . esc_url( $src ) . '"' : '' )
			. '>'
			. '<div class="qrrp-usage-item">'
			. '<span class="qrrp-usage-value qrrp-usage-value-total">' . esc_html( number_format_i18n( $data['total_ok'] ) ) . '</span>'
			. '<span class="qrrp-usage-label">' . esc_html( $total_label ) . '</span>'
			. '</div>';

		/*
		 * Το μπλοκ του μήνα αποδίδεται πάντα (κρυμμένο όταν είναι μηδέν), ώστε
		 * μια cached σελίδα να έχει πού να γράψει το JS μετά την αλλαγή μήνα.
		 */
		$html .= '<div class="qrrp-usage-item qrrp-usage-item-month"' . ( $month > 0 ? '' : ' hidden' ) . '>'
			. '<span class="qrrp-usage-value qrrp-usage-value-month">' . esc_html( number_format_i18n( $month ) ) . '</span>'
			. '<span class="qrrp-usage-label">' . esc_html( $month_label ) . '</span>'
			. '</div>';

		return $html . '</div></div>';
	}

	/**
	 * Ο τρέχων μήνας ως 'YYYY-MM', στη ζώνη ώρας του site (όπως κρίνει
	 * ημερομηνίες και ο parser).
	 */
	public static function current_month() {
		return wp_date( 'Y-m' );
	}

	/** Ο προηγούμενος μήνας ως 'YYYY-MM', υπολογισμένος από τον τρέχοντα. */
	public static function previous_month() {
		$current = self::current_month();

		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})\z/', (string) $current, $m ) ) {
			return '';
		}

		$year  = (int) $m[1];
		$month = (int) $m[2] - 1;

		if ( $month < 1 ) {
			$month = 12;
			--$year;
		}

		return sprintf( '%04d-%02d', $year, $month );
	}

	/** Επιτυχής παραγωγή κωδικού. */
	public static function record_success() {
		self::record( array( 'ok' ) );
	}

	/**
	 * Απόρριψη αιτήματος δημιουργίας: αποτυχία επικύρωσης GS1, ελέγχου
	 * προέλευσης ή επαλήθευσης του ήδη δημιουργημένου κωδικού.
	 */
	public static function record_rejection() {
		self::record( array( 'rejected' ) );
	}

	/**
	 * Καταγράφει το σχήμα μιας ανάγνωσης σε μία εγγραφή. Χωρίς αποδιπλασιασμό
	 * ανά συσκευασία (θα απαιτούσε σημαία από τον browser).
	 *
	 * @param mixed $result Ό,τι επέστρεψε ο QRRP_GS1_Parser::parse().
	 */
	public static function record_scan( $result ) {
		if ( ! is_array( $result ) ) {
			return;
		}

		$buckets = array( 'scans' );

		if ( ! empty( $result['inferred_boundaries'] ) ) {
			$buckets[] = 'inferred';
		}

		if ( ! empty( $result['requires_confirmation'] ) ) {
			$buckets[] = 'confirm';
		}

		if ( ! empty( $result['ambiguous'] ) ) {
			$buckets[] = 'ambiguous';
		}

		if ( ! empty( $result['extra_ais_present'] ) ) {
			$buckets[] = 'extra_ai';
		}

		if ( isset( $result['confidence'] ) && 'low' === $result['confidence'] ) {
			$buckets[] = 'low';
		}

		/*
		 * AIM symbology identifier, όπως τον ξεχώρισε ο parser: «]d2» (GS1
		 * DataMatrix), άλλος identifier, ή κανένας. Μόνο μέτρηση — καμία
		 * πολιτική δεν βασίζεται ακόμη σε αυτό.
		 */
		$symbology = isset( $result['normalization']['symbology_identifier'] )
			&& is_scalar( $result['normalization']['symbology_identifier'] )
			? (string) $result['normalization']['symbology_identifier']
			: '';

		if ( ']d2' === $symbology ) {
			$buckets[] = 'symbology_d2';
		} elseif ( '' !== $symbology ) {
			$buckets[] = 'symbology_other';
		} else {
			$buckets[] = 'symbology_none';
		}

		self::record( $buckets );
	}

	/** Ένας μήνας χωρίς καμία καταγραφή, με όλα τα κλειδιά παρόντα. */
	private static function empty_month() {
		return array_fill_keys( self::ALL_BUCKETS, 0 );
	}

	/**
	 * Αυξάνει τους δοσμένους μετρητές του τρέχοντος μήνα. Fail-silent, σε
	 * αντίθεση με τα fail-closed CAS του rate limiter/tokens: ένα σφάλμα εδώ
	 * δεν επιτρέπεται να χαλάσει μια επιτυχημένη δημιουργία.
	 *
	 * @param array $buckets Ονόματα μετρητών από το ALL_BUCKETS· καθένας +1.
	 */
	private static function record( array $buckets ) {
		try {
			$month = self::current_month();

			if ( ! self::is_month_key( $month ) ) {
				return;
			}

			$buckets = array_values( array_intersect( self::ALL_BUCKETS, $buckets ) );

			if ( empty( $buckets ) ) {
				return;
			}

			$committed_months = null;
			$committed        = self::mutate(
				static function ( array $months ) use ( $month, $buckets ) {
					if ( ! isset( $months[ $month ] ) ) {
						$months[ $month ] = self::empty_month();
					}

					foreach ( $buckets as $bucket ) {
						++$months[ $month ][ $bucket ];
					}

					return $months;
				},
				$committed_months
			);

			/*
			 * Δημοσίευση μόνο μετά από επιτυχές commit και μόνο όταν άλλαξε
			 * δημόσιος αριθμός (ok), με τα δεδομένα που μόλις γράφτηκαν.
			 */
			if ( $committed && in_array( 'ok', $buckets, true ) ) {
				self::publish_public_snapshot( $committed_months );
			}
		} catch ( Throwable $ignored ) {
			unset( $ignored );
		}
	}

	/**
	 * Κανονικοποίηση: άκυροι μήνες απορρίπτονται, κάθε μετρητής του
	 * ALL_BUCKETS γίνεται μη αρνητικός ακέραιος (0 αν λείπει).
	 *
	 * @param mixed $stored Ό,τι επέστρεψε το get_option().
	 * @return array<string,array<string,int>> 'YYYY-MM' => bucket => πλήθος.
	 */
	private static function normalize( $stored ) {
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$months = array();

		foreach ( $stored as $key => $counts ) {
			$key = (string) $key;

			if ( ! self::is_month_key( $key ) || ! is_array( $counts ) ) {
				continue;
			}

			$bucket_counts = array();

			foreach ( self::ALL_BUCKETS as $bucket ) {
				$value                   = isset( $counts[ $bucket ] ) && is_scalar( $counts[ $bucket ] )
					? (int) $counts[ $bucket ]
					: 0;
				$bucket_counts[ $bucket ] = max( 0, $value );
			}

			$months[ $key ] = $bucket_counts;
		}

		return $months;
	}

	/**
	 * Compare-and-swap πάνω στο wp_options, με ανάγνωση απευθείας από τη
	 * βάση. Η πρώτη δημιουργία γίνεται με INSERT IGNORE (unique index).
	 *
	 * @param callable   $mutator   Παίρνει τον πίνακα μηνών, επιστρέφει τον νέο.
	 * @param array|null $committed Out: οι κανονικοποιημένοι μήνες που γράφτηκαν.
	 * @return bool true μόνο αν η αλλαγή γράφτηκε.
	 */
	private static function mutate( callable $mutator, &$committed = null ) {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! isset( $wpdb->options )
			|| ! is_callable( array( $wpdb, 'get_var' ) )
			|| ! is_callable( array( $wpdb, 'query' ) ) ) {
			return false;
		}

		$option = self::OPTION;

		for ( $attempt = 0; $attempt < self::CAS_ATTEMPTS; $attempt++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-swap· μια cached ανάγνωση θα το αναιρούσε.
			$stored_raw = $wpdb->get_var(
				$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option )
			);

			$months      = ( null === $stored_raw ) ? array() : self::normalize( maybe_unserialize( $stored_raw ) );
			$next_months = self::normalize( call_user_func( $mutator, $months ) );
			$next        = maybe_serialize( $next_months );

			if ( null === $stored_raw ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Ατομική δημιουργία μέσω του unique index του option_name.
				$inserted = $wpdb->query(
					$wpdb->prepare(
						"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
						$option,
						$next,
						'no'
					)
				);

				if ( 1 === (int) $inserted ) {
					self::forget_option_cache( $option );
					$committed = $next_months;

					return true;
				}

				continue;
			}

			if ( $next === $stored_raw ) {
				$committed = $next_months;

				return true;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-swap· το update_option() δεν μπορεί να εκφράσει «μόνο αν δεν άλλαξε».
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
					$next,
					$option,
					$stored_raw
				)
			);

			if ( 1 === (int) $updated ) {
				self::forget_option_cache( $option );
				$committed = $next_months;

				return true;
			}
		}

		/* Εξάντληση του CAS: χάνεται μία μέτρηση, σκόπιμα χωρίς log. */
		return false;
	}

	private static function forget_option_cache( $option ) {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $option, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
	}
}
