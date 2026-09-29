<?php
/**
 * Κοινός χώρος αποθήκευσης opaque tokens (transients με envelope
 * schema 2 + type) για email_rebuild (Mailer/Shortcode), provenance_challenge
 * (Provenance) και validated_output (Ajax). Αποθηκεύει και επαληθεύει· η
 * σημασιολογία κάθε τύπου ανήκει στον καταναλωτή του.
 *
 * Μόνο τα μακρόβια email_rebuild καταγράφονται στο ευρετήριο cleanup
 * (qrrp_token_index), ώστε το uninstall να τα βρίσκει και σε external object
 * cache. Οι βραχύβιοι τύποι (TTL ≤ 1 ώρα) λήγουν μόνοι τους· 2.15.4: οι
 * ληγμένες γραμμές τους σβήνονται και από το ωριαίο sweep (sweep_expired()),
 * ώστε να μη μαζεύονται σε sites χωρίς WP-Cron.
 *
 * @package QR_Rebuilder_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keyed, domain-separated fingerprint (HMAC-SHA256 με wp_salt('auth')) για
 * βραχύβια contexts. Το "\0" κάνει το purpose/value μη διφορούμενο· τα
 * purposes audit_* είναι δεσμευμένα και απορρίπτονται.
 *
 * @return string|false
 */
function qrrp_fingerprint( $purpose, $value, $key_version = 1 ) {
	if ( 1 !== (int) $key_version ) {
		return false;
	}

	$purpose = is_scalar( $purpose ) ? (string) $purpose : '';
	$value   = is_scalar( $value ) ? (string) $value : '';

	if ( '' === $purpose ) {
		return false;
	}

	if ( 0 === strpos( $purpose, 'audit_' ) ) {
		return false;
	}

	return hash_hmac( 'sha256', $purpose . "\0" . $value, wp_salt( 'auth' ) );
}

final class QRRP_Tokens {

	/** Οι μόνοι επιτρεπτοί τύποι· κάθε νέος τύπος είναι νέα κατηγορία δικαιωμάτων. */
	private const TYPES = array( 'email_rebuild', 'provenance_challenge', 'validated_output' );

	private const SCHEMA = 2;

	private const TOKEN_INDEX_OPTION = 'qrrp_token_index';
	private const TOKEN_INDEX_MAX    = 500;

	private const TOKEN_INDEX_CAS_ATTEMPTS = 5;

	/** Τύποι που καταγράφονται στο ευρετήριο cleanup. */
	private const INDEXED_TYPES = array( 'email_rebuild' );

	/** Μέγιστο TTL για τύπους εκτός ευρετηρίου. */
	private const UNINDEXED_MAX_TTL = 3600;

	/**
	 * Αποθηκεύει token και επιστρέφει το δημόσιο handle του, ή '' σε αποτυχία
	 * (άγνωστος τύπος, TTL <= 0, TTL > 1 ώρα εκτός ευρετηρίου, CSPRNG, ευρετήριο).
	 * Τα reserved πεδία μπαίνουν αριστερά του «+», οπότε το payload δεν μπορεί
	 * να αλλάξει schema/type.
	 *
	 * @param string $type    email_rebuild, provenance_challenge ή validated_output.
	 * @param array  $payload Τα δεδομένα του token.
	 * @param int    $ttl     Διάρκεια σε δευτερόλεπτα.
	 * @return string
	 */
	public static function issue( $type, array $payload, $ttl ) {
		$type = is_scalar( $type ) ? (string) $type : '';
		$ttl  = (int) $ttl;

		if ( ! in_array( $type, self::TYPES, true ) || $ttl <= 0 ) {
			return '';
		}

		$indexed = in_array( $type, self::INDEXED_TYPES, true );

		if ( ! $indexed && $ttl > self::UNINDEXED_MAX_TTL ) {
			return '';
		}

		$token = self::random_token( 16 );

		if ( '' === $token ) {
			return '';
		}

		$envelope = array(
			'schema' => self::SCHEMA,
			'type'   => $type,
		) + $payload;

		$key = self::transient_key( $token );

		if ( ! set_transient( $key, $envelope, $ttl ) ) {
			return '';
		}

		if ( $indexed && ! self::remember_token_in_index( $key, time() + $ttl ) ) {
			delete_transient( $key );

			return '';
		}

		return $token;
	}

	/**
	 * Site Health: ενεργά ευρετηριασμένα tokens χωρίς έγκυρο envelope —
	 * legacy (ούτε schema ούτε type) και unreadable (οτιδήποτε άλλο άκυρο).
	 *
	 * @return array { 'legacy' => int, 'unreadable' => int }
	 */
	public static function legacy_payload_counts() {
		$index = get_option( self::TOKEN_INDEX_OPTION, array() );

		$counts = array(
			'legacy'     => 0,
			'unreadable' => 0,
		);

		if ( ! is_array( $index ) ) {
			return $counts;
		}

		foreach ( array_keys( $index ) as $key ) {
			if ( ! is_string( $key ) || 0 !== strpos( $key, 'qrrp_tok_' ) ) {
				++$counts['unreadable'];
				continue;
			}

			$payload = get_transient( $key );

			if ( false === $payload ) {
				continue;
			}

			if ( ! is_array( $payload ) ) {
				++$counts['unreadable'];
				continue;
			}

			$has_schema = array_key_exists( 'schema', $payload );
			$has_type   = array_key_exists( 'type', $payload );

			if ( ! $has_schema && ! $has_type ) {
				++$counts['legacy'];
				continue;
			}

			if (
				$has_schema
				&& $has_type
				&& self::SCHEMA === $payload['schema']
				&& is_string( $payload['type'] )
				&& in_array( $payload['type'], self::TYPES, true )
			) {
				continue;
			}

			++$counts['unreadable'];
		}

		return $counts;
	}

	/**
	 * Ο ενιαίος verifier: πλήρες envelope, ίδιος τύπος και κάθε πεδίο του
	 * $expected_context === με το αποθηκευμένο. Δεν καταναλώνει· ο καλών καλεί
	 * consume() ή forget() μετά την επιτυχή ολοκλήρωση.
	 *
	 * @return array|false Το payload, ή false.
	 */
	public static function verify_token_for_request( $token, $expected_type, array $expected_context = array() ) {
		$expected_type = is_scalar( $expected_type ) ? (string) $expected_type : '';

		if ( ! in_array( $expected_type, self::TYPES, true ) ) {
			return false;
		}

		$payload = self::lookup( $token );

		if ( false === $payload ) {
			return false;
		}

		$has_schema = array_key_exists( 'schema', $payload );
		$has_type   = array_key_exists( 'type', $payload );

		if (
			! $has_schema
			|| ! $has_type
			|| self::SCHEMA !== $payload['schema']
			|| ! is_string( $payload['type'] )
			|| ! in_array( $payload['type'], self::TYPES, true )
		) {
			return false;
		}

		$actual_type = $payload['type'];

		if ( $actual_type !== $expected_type ) {
			return false;
		}

		foreach ( $expected_context as $field => $value ) {
			if ( ! array_key_exists( $field, $payload ) || $payload[ $field ] !== $value ) {
				return false;
			}
		}

		return $payload;
	}

	/**
	 * Κρυπτογραφικά τυχαία hex συμβολοσειρά, ή '' αν αποτύχει η random_bytes()
	 * (χωρίς μη-κρυπτογραφικό fallback). Public: τη χρησιμοποιεί και ο Mailer.
	 */
	public static function random_token( $bytes ) {
		$bytes = max( 4, (int) $bytes );

		try {
			return bin2hex( random_bytes( $bytes ) );
		} catch ( \Throwable $exception ) {
			return '';
		}
	}

	/**
	 * 'qrrp_tok_' + SHA-256 του token: η βάση δεν κρατά ποτέ το ίδιο το bearer
	 * token. Ο έλεγχος μορφής γίνεται στα σημεία εισόδου, όχι εδώ.
	 */
	public static function transient_key( $token ) {
		return 'qrrp_tok_' . hash( 'sha256', (string) $token );
	}

	/** Δημόσια μορφή token: ακριβώς 32 πεζά hex. */
	private static function is_public_token_form( $token ) {
		return is_string( $token ) && 1 === preg_match( '/^[a-f0-9]{32}$/', $token );
	}

	/**
	 * Ωμό payload χωρίς έλεγχο envelope/τύπου, για τον verifier. Με
	 * $expected_type γίνεται πλήρης επαλήθευση.
	 *
	 * @internal Χωρίς $expected_type.
	 * @return array|false
	 */
	public static function lookup( $token, $expected_type = null ) {
		if ( null !== $expected_type ) {
			return self::verify_token_for_request( $token, $expected_type );
		}

		if ( ! self::is_public_token_form( $token ) ) {
			return false;
		}

		$data = get_transient( self::transient_key( $token ) );

		return is_array( $data ) ? $data : false;
	}

	/**
	 * Ατομική κατανάλωση: επαλήθευση και διαγραφή. Επιτυγχάνει μόνο αν αυτή η
	 * κλήση αφαίρεσε την εγγραφή (η delete_transient() επιστρέφει true μόνο όταν
	 * διαγράφηκε γραμμή/κλειδί), οπότε από ταυτόχρονα αιτήματα κερδίζει ένα.
	 *
	 * @return array|false Το payload αν καταναλώθηκε τώρα, αλλιώς false.
	 */
	public static function consume( $token, $expected_type, array $expected_context = array() ) {
		$payload = self::verify_token_for_request( $token, $expected_type, $expected_context );

		if ( false === $payload ) {
			return false;
		}

		$key = self::transient_key( $token );

		if ( ! delete_transient( $key ) ) {
			return false;
		}

		if ( in_array( $payload['type'], self::INDEXED_TYPES, true ) ) {
			self::forget_token_in_index( $key );
		}

		return $payload;
	}

	/** Αποσύρει token χωρίς έλεγχο τύπου· άκυρη μορφή = καμία ενέργεια. */
	public static function forget( $token ) {
		if ( ! self::is_public_token_form( $token ) ) {
			return;
		}

		$key = self::transient_key( $token );

		delete_transient( $key );
		self::forget_token_in_index( $key );
	}

	/**
	 * Uninstall: σβήνει τα ευρετηριασμένα tokens (και από object cache), το
	 * ευρετήριο και κάθε υπόλοιπη γραμμή qrrp_tok_* στον πίνακα options.
	 * Βραχύβια tokens σε external object cache λήγουν μόνα τους (≤ 1 ώρα).
	 *
	 * @return int
	 */
	public static function purge_all() {
		$index = get_option( self::TOKEN_INDEX_OPTION, array() );

		if ( ! is_array( $index ) ) {
			delete_option( self::TOKEN_INDEX_OPTION );
			return self::purge_unindexed_rows();
		}

		$purged = 0;

		foreach ( array_keys( $index ) as $key ) {
			if ( is_string( $key ) && 0 === strpos( $key, 'qrrp_tok_' ) ) {
				delete_transient( $key );
				++$purged;
			}
		}

		delete_option( self::TOKEN_INDEX_OPTION );

		return $purged + self::purge_unindexed_rows();
	}

	/**
	 * 2.15.4: σβήνει ληγμένα tokens (value + timeout) από το wp_options.
	 *
	 * Το core τα σβήνει μόνο όταν διαβαστούν ή με το ημερήσιο WP-Cron· σε site
	 * χωρίς cron οι βραχύβιοι τύποι (challenge, proof) μαζεύονταν. Καλείται από
	 * το ωριαίο sweep του QRRP_Rate_Limiter. Ληγμένο token δεν γίνεται ποτέ ξανά
	 * έγκυρο (τυχαίο κλειδί, η λήξη δεν ανανεώνεται), άρα η διαγραφή είναι
	 * ασφαλής για κάθε τύπο· το ευρετήριο καθαρίζει μόνο του στην επόμενη αλλαγή.
	 * Με external object cache δεν υπάρχουν τέτοιες γραμμές και δεν γίνεται τίποτα.
	 *
	 * @param int   $now         Τρέχον timestamp.
	 * @param int   $max_batches Όριο batches των 200 ανά κλήση (έως 10.000 tokens).
	 * @param float $budget      Όριο χρόνου σε δευτερόλεπτα ανά κλήση.
	 * @return int Πόσα tokens σβήστηκαν.
	 */
	public static function sweep_expired( $now, $max_batches = 50, $budget = 0.5 ) {
		global $wpdb;

		if ( ! self::wpdb_usable() || ! is_callable( array( $wpdb, 'esc_like' ) ) || ! is_callable( array( $wpdb, 'get_col' ) ) ) {
			return 0;
		}

		$timeout_prefix = '_transient_timeout_qrrp_tok_';
		$deleted        = 0;
		$started        = microtime( true );

		for ( $batch = 0; $batch < (int) $max_batches && ( microtime( true ) - $started ) < (float) $budget; $batch++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Συντήρηση γραμμών που ο core σβήνει μόνο με cron.
			$names = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d LIMIT %d",
					$wpdb->esc_like( $timeout_prefix ) . '%',
					(int) $now,
					200
				)
			);

			if ( ! is_array( $names ) || array() === $names ) {
				break;
			}

			$rows = array();

			foreach ( $names as $name ) {
				$name = (string) $name;

				if ( 0 !== strpos( $name, $timeout_prefix ) ) {
					continue;
				}

				$rows[] = $name;
				$rows[] = '_transient_' . substr( $name, strlen( '_transient_timeout_' ) );
			}

			if ( array() === $rows ) {
				break;
			}

			$placeholders = implode( ', ', array_fill( 0, count( $rows ), '%s' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Τα {$placeholders} είναι παραγόμενα '%s'.
			$affected = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name IN ( {$placeholders} )", $rows ) );

			foreach ( $rows as $name ) {
				wp_cache_delete( $name, 'options' );
			}

			/* Σφάλμα βάσης: σταματά (το επόμενο ωριαίο πέρασμα ξαναπροσπαθεί). */
			if ( false === $affected ) {
				break;
			}

			$deleted += (int) floor( (int) $affected / 2 );

			if ( count( $names ) < 200 ) {
				break;
			}
		}

		return $deleted;
	}

	/** Διαγράφει με LIKE τις γραμμές value/timeout qrrp_tok_* που απέμειναν. */
	private static function purge_unindexed_rows() {
		global $wpdb;

		if ( ! self::wpdb_usable() || ! is_callable( array( $wpdb, 'esc_like' ) ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$values = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_qrrp_tok_' ) . '%'
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_timeout_qrrp_tok_' ) . '%'
			)
		);

		return max( 0, (int) $values );
	}

	/**
	 * Καταγράφει hashed key → λήξη στο ευρετήριο. True μόνο αν η αλλαγή
	 * γράφτηκε και το νέο κλειδί δεν κόπηκε από το ταβάνι.
	 */
	private static function remember_token_in_index( $key, $expires ) {
		$retained = false;

		$remembered = self::mutate_token_index(
			static function ( $index ) use ( $key, $expires, &$retained ) {
				$result = self::prepare_pruned_token_index( $index );

				/*
				 * 2.15.3: γεμάτο ευρετήριο → το νέο token απορρίπτεται (το email φεύγει
				 * χωρίς σύνδεσμο). Πριν, σβηνόταν το παλαιότερο ζωντανό token, οπότε
				 * μαζικές αποστολές ακύρωναν συνδέσμους που είχαν ήδη σταλεί.
				 */
				if ( count( $result['index'] ) >= self::TOKEN_INDEX_MAX ) {
					$retained = false;

					return $result;
				}

				$result['index'][ $key ] = (int) $expires;
				$retained                = true;

				return $result;
			}
		);

		if ( ! $remembered ) {
			self::log_token_index_failure( 'remember_failed' );
		} elseif ( ! $retained ) {
			self::log_token_index_failure( 'token_index_full' );
		}

		return $remembered && $retained;
	}

	/**
	 * Αφαιρεί κλειδί από το ευρετήριο. Το option δεν διαγράφεται όταν αδειάσει,
	 * ώστε να μη χαθεί εγγραφή ταυτόχρονου αιτήματος.
	 */
	private static function forget_token_in_index( $key ) {
		$forgotten = self::mutate_token_index(
			static function ( $index ) use ( $key ) {
				unset( $index[ $key ] );

				return array(
					'index'   => $index,
					'dropped' => array(),
				);
			}
		);

		if ( ! $forgotten ) {
			self::log_token_index_failure( 'forget_failed' );
		}

		return $forgotten;
	}

	/** Privacy-safe ίχνος: μόνο το είδος του συμβάντος. error_log μόνο σε WP_DEBUG. */
	private static function log_token_index_failure( $event ) {
		$event = sanitize_key( (string) $event );

		do_action( 'qrrp_token_index_failed', $event );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only trace χωρίς request/user/token δεδομένα.
			error_log( sprintf( 'QRRP token index: event=%s', $event ) );
		}
	}

	/**
	 * Ατομική ενημέρωση του ευρετηρίου με compare-and-swap. Ο mutator είναι
	 * καθαρή συνάρτηση (μπορεί να ξανατρέξει)· οι παρενέργειες εκτελούνται μόνο
	 * μετά το commit. Χωρίς non-atomic fallback: αποτυχία → false.
	 *
	 * @param callable $mutator array $index => array{index:array, dropped:array, evicted_live?:bool}
	 * @return bool
	 */
	private static function mutate_token_index( callable $mutator ) {
		global $wpdb;

		$option = self::TOKEN_INDEX_OPTION;

		if ( self::wpdb_usable() ) {
			for ( $attempt = 0; $attempt < self::TOKEN_INDEX_CAS_ATTEMPTS; $attempt++ ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-swap: μια cached τιμή αναιρεί τον έλεγχο.
				$stored_raw = $wpdb->get_var(
					$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option )
				);

				$index  = ( null === $stored_raw ) ? array() : maybe_unserialize( $stored_raw );
				$index  = is_array( $index ) ? $index : array();
				$result = self::normalize_index_mutation( call_user_func( $mutator, $index ) );
				$next   = maybe_serialize( $result['index'] );

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
						wp_cache_delete( $option, 'options' );
						wp_cache_delete( 'notoptions', 'options' );
						self::commit_index_side_effects( $result );
						return true;
					}

					continue;
				}

				if ( $next === $stored_raw ) {
					/* Καμία αλλαγή προς εγγραφή· οι παρενέργειες όμως ισχύουν. */
					self::commit_index_side_effects( $result );
					return true;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-swap· δεν μπορεί να περάσει από cache.
				$updated = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
						$next,
						$option,
						$stored_raw
					)
				);

				if ( 1 === (int) $updated ) {
					wp_cache_delete( $option, 'options' );
					self::commit_index_side_effects( $result );
					return true;
				}
			}
		}

		return false;
	}

	private static function wpdb_usable() {
		global $wpdb;

		return is_object( $wpdb ) && isset( $wpdb->options )
			&& is_callable( array( $wpdb, 'get_var' ) ) && is_callable( array( $wpdb, 'query' ) )
			&& is_callable( array( $wpdb, 'prepare' ) );
	}

	/** Δέχεται είτε το ζεύγος index/dropped είτε σκέτο πίνακα, για ανθεκτικότητα. */
	private static function normalize_index_mutation( $result ) {
		if ( is_array( $result ) && isset( $result['index'] ) && is_array( $result['index'] ) ) {
			return array(
				'index'        => $result['index'],
				'dropped'      => ( isset( $result['dropped'] ) && is_array( $result['dropped'] ) ) ? $result['dropped'] : array(),
				'evicted_live' => ! empty( $result['evicted_live'] ),
			);
		}

		return array(
			'index'        => is_array( $result ) ? $result : array(),
			'dropped'      => array(),
			'evicted_live' => false,
		);
	}

	/** Παρενέργειες του ευρετηρίου, μόνο μετά από επιτυχές commit. */
	private static function commit_index_side_effects( $result ) {
		self::drop_indexed_tokens( $result['dropped'] );

		if ( ! empty( $result['evicted_live'] ) ) {
			self::log_token_index_failure( 'token_index_evicted_live' );
		}
	}

	private static function drop_indexed_tokens( $keys ) {
		foreach ( (array) $keys as $key ) {
			self::drop_indexed_token( $key );
		}
	}

	/**
	 * Υπολογίζει το κλάδεμα: ληγμένες εγγραφές και, πάνω από TOKEN_INDEX_MAX,
	 * οι παλαιότερες. Οι δεύτερες είναι ζωντανά email tokens που θα σβηστούν,
	 * γι' αυτό επιστρέφεται evicted_live (καταγράφεται). Αν συμβαίνει συχνά,
	 * μικρότερο qrrp_rebuild_token_ttl — όχι μεγαλύτερο ταβάνι.
	 *
	 * @return array{index:array, dropped:array, evicted_live:bool}
	 */
	private static function prepare_pruned_token_index( $index ) {
		$now     = time();
		$dropped = array();

		$evicted_live = false;

		foreach ( $index as $key => $expires ) {
			if ( (int) $expires <= $now ) {
				$dropped[] = $key;
				unset( $index[ $key ] );
			}
		}

		if ( count( $index ) > self::TOKEN_INDEX_MAX ) {
			asort( $index );

			$over    = array_slice( $index, 0, count( $index ) - self::TOKEN_INDEX_MAX, true );
			$dropped = array_merge( $dropped, array_keys( $over ) );

			$index = array_slice( $index, -self::TOKEN_INDEX_MAX, null, true );

			$evicted_live = ( array() !== $over );
		}

		return array(
			'index'        => $index,
			'dropped'      => $dropped,
			'evicted_live' => $evicted_live,
		);
	}

	/**
	 * Σβήνει το transient ενός κλειδιού που βγήκε από το ευρετήριο — εκτός αν
	 * ανήκει σε μη ευρετηριασμένο τύπο (π.χ. εγγραφή παλαιότερης έκδοσης).
	 */
	private static function drop_indexed_token( $key ) {
		if ( ! is_string( $key ) || 0 !== strpos( $key, 'qrrp_tok_' ) ) {
			return;
		}

		$payload = get_transient( $key );

		if (
			is_array( $payload )
			&& isset( $payload['type'] )
			&& is_string( $payload['type'] )
			&& in_array( $payload['type'], self::TYPES, true )
			&& ! in_array( $payload['type'], self::INDEXED_TYPES, true )
		) {
			return;
		}

		delete_transient( $key );
	}
}
