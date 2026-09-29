<?php
/**
 * Rate limiter των AJAX endpoints, με μετρητές στον πίνακα options.
 *
 * Κάθε παράθυρο είναι transient (`_transient_qrrp_rl_*` + γραμμή λήξης), αλλά
 * γράφεται απευθείας στη βάση: το object-cache API δεν έχει ατομικό
 * «increment με TTL», ενώ η βάση έχει unique index στο option_name
 * (INSERT IGNORE) και compare-and-swap πάνω στην ακριβή προηγούμενη τιμή.
 * Γι' αυτό ο μετρητής είναι πάντα DB-authoritative, ακόμη και με Redis.
 *
 * Τα ονόματα `qrrp_rl_*` και `qrrp_rl_last_sweep` τα καθαρίζει το uninstall.php.
 *
 * @package QR_ReBuilder_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class QRRP_Rate_Limiter {

	/** Προσπάθειες compare-and-swap πριν το αίτημα μπλοκαριστεί. */
	public const CAS_ATTEMPTS = 5;

	/** Πότε δεσμεύτηκε τελευταία φορά πέρασμα καθαρισμού. */
	public const SWEEP_OPTION = 'qrrp_rl_last_sweep';

	/** Το πολύ ένα πέρασμα καθαρισμού ανά ώρα. */
	public const SWEEP_INTERVAL = HOUR_IN_SECONDS;

	/** Παράθυρα ανά batch καθαρισμού. */
	public const SWEEP_BATCH = 200;

	/** Ανώτατο πλήθος batches ανά είδος γραμμής σε ένα πέρασμα. */
	public const SWEEP_MAX_BATCHES = 50;

	/** Χρονικό όριο ενός περάσματος, σε δευτερόλεπτα. */
	public const SWEEP_TIME_BUDGET = 1.0;

	/**
	 * Ηλικία πάνω από την οποία ένα παράθυρο χωρίς γραμμή λήξης θεωρείται
	 * σκουπίδι. Πολλαπλάσιο του μεγαλύτερου παραθύρου (24 ώρες: όριο ανά
	 * παραλήπτη, 2.15.4), ώστε να μη σβήνεται ποτέ ζωντανός μετρητής.
	 */
	public const SWEEP_ORPHAN_AGE = 2 * DAY_IN_SECONDS;

	/**
	 * Μετρά ένα αίτημα και λέει αν επιτρέπεται.
	 *
	 * Fail-closed: σφάλμα βάσης ή εξάντληση των CAS retries σημαίνει άρνηση.
	 *
	 * @param string $action Λογικό όνομα ενέργειας ('parse', 'rebuild', …).
	 * @param int    $limit  Αιτήματα που επιτρέπονται μέσα στο παράθυρο.
	 * @param int    $window Διάρκεια παραθύρου σε δευτερόλεπτα.
	 * @param string $scope  'actor' (ανά χρήστη/IP) ή 'global' (ένας μετρητής για όλο το site).
	 * @return bool true αν το αίτημα επιτρέπεται (και μετρήθηκε).
	 */
	public static function hit( $action, $limit, $window, $scope = 'actor' ) {
		$key = ( 'global' === $scope )
			? 'qrrp_rl_global_' . $action
			: self::actor_key( $action );

		return self::hit_key( $key, (int) $limit, (int) $window, (string) $action, (string) $scope );
	}

	/**
	 * 2.15.3: ανάγνωση χωρίς μέτρηση — αν ο μετρητής έχει ακόμη περιθώριο.
	 * Για τα συνολικά ταβάνια επισκεπτών πριν από το όριο ανά IP: όταν το
	 * συνολικό ταβάνι έχει εξαντληθεί, δεν γράφεται καμία νέα γραμμή ανά IP.
	 * Δεν είναι ατομικό· το οριστικό όχι το δίνει πάντα το hit().
	 *
	 * @return bool false μόνο όταν το παράθυρο είναι ενεργό και γεμάτο.
	 */
	public static function has_capacity( $action, $limit, $window, $scope = 'global' ) {
		global $wpdb;

		if ( ! self::db_available() ) {
			return false;
		}

		$key = ( 'global' === $scope )
			? 'qrrp_rl_global_' . $action
			: self::actor_key( $action );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Ίδια ακριβής τιμή με το hit_key().
		$stored_raw = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", '_transient_' . $key )
		);

		if ( null === $stored_raw ) {
			return true;
		}

		$stored = maybe_unserialize( $stored_raw );
		$now    = time();

		if ( ! is_array( $stored ) || ! isset( $stored['count'], $stored['start'] ) ) {
			return true;
		}

		$start = (int) $stored['start'];

		if ( $start > $now || ( $now - $start ) >= (int) $window ) {
			return true;
		}

		return (int) $stored['count'] < (int) $limit;
	}

	/**
	 * 2.15.3: μετρητής για ένα θέμα που δεν είναι ο actor (π.χ. παραλήπτης
	 * email). Στη βάση γράφεται μόνο hash με κλειδί του site, ποτέ η τιμή.
	 *
	 * @param string $action  Λογικό όνομα ενέργειας.
	 * @param string $subject Το θέμα (ήδη κανονικοποιημένο από τον καλούντα).
	 * @return bool
	 */
	public static function hit_subject( $action, $subject, $limit, $window ) {
		$digest = hash_hmac( 'sha256', (string) $subject, wp_salt( 'nonce' ) );
		$key    = 'qrrp_rl_' . md5( $action . '|s:' . $digest );

		return self::hit_key( $key, (int) $limit, (int) $window, (string) $action, 'subject' );
	}

	/**
	 * Το κλειδί του μετρητή για τον τρέχοντα actor.
	 *
	 * Συνδεδεμένος χρήστης: user id. Επισκέπτης: HMAC της κανονικοποιημένης
	 * διεύθυνσης (βλ. client_bucket()), ώστε η IP να μη γράφεται στη βάση.
	 *
	 * @param string $action Λογικό όνομα ενέργειας.
	 * @return string
	 */
	public static function actor_key( $action ) {
		if ( is_user_logged_in() ) {
			$actor = 'u:' . get_current_user_id();
		} else {
			$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) && is_scalar( $_SERVER['REMOTE_ADDR'] )
				? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
				: 'unknown';

			if ( '' === $remote_addr ) {
				$remote_addr = 'unknown';
			}

			/*
			 * Προεπιλογή το REMOTE_ADDR, που δεν πλαστογραφείται με κεφαλίδες.
			 * Εγκαταστάσεις πίσω από αξιόπιστο reverse proxy το αλλάζουν ρητά.
			 */
			$filtered_addr = apply_filters( 'qrrp_rate_limit_remote_addr', $remote_addr );
			if ( is_scalar( $filtered_addr ) && '' !== trim( (string) $filtered_addr ) ) {
				$remote_addr = substr( trim( (string) $filtered_addr ), 0, 128 );
			}

			$actor = 'g:' . hash_hmac( 'sha256', self::client_bucket( $remote_addr ), wp_salt( 'nonce' ) );
		}

		return 'qrrp_rl_' . md5( $action . '|' . $actor );
	}

	/**
	 * Κανονικοποιεί μια διεύθυνση στον «κουβά» του limiter.
	 *
	 * IPv4 μένει ως έχει. IPv6 ομαδοποιείται ανά /64, γιατί ένας πελάτης
	 * συνήθως ελέγχει ολόκληρο /64 και θα άλλαζε διεύθυνση σε κάθε αίτημα.
	 * IPv4-mapped IPv6 (::ffff:a.b.c.d) αντιμετωπίζεται ως IPv4. Ό,τι δεν
	 * αναλύεται ως IP επιστρέφεται αυτούσιο (σε πεζά).
	 *
	 * @param string $addr Διεύθυνση όπως τη δίνει ο server ή το φίλτρο.
	 * @return string
	 */
	public static function client_bucket( $addr ) {
		$addr      = strtolower( trim( (string) $addr ) );
		$candidate = $addr;

		if ( 1 === preg_match( '/^\[([^\]]+)\](?::\d+)?$/', $candidate, $m ) ) {
			$candidate = $m[1];
		}

		/* Zone id (fe80::1%eth0) δεν ανήκει στη διεύθυνση. */
		$zone = strpos( $candidate, '%' );
		if ( false !== $zone ) {
			$candidate = substr( $candidate, 0, $zone );
		}

		$packed = ( '' !== $candidate && function_exists( 'inet_pton' ) ) ? inet_pton( $candidate ) : false;

		if ( ! is_string( $packed ) ) {
			return $addr;
		}

		if ( 16 === strlen( $packed ) ) {
			if ( str_repeat( "\0", 10 ) . "\xff\xff" === substr( $packed, 0, 12 ) ) {
				return (string) inet_ntop( substr( $packed, 12 ) );
			}

			return inet_ntop( substr( $packed, 0, 8 ) . str_repeat( "\0", 8 ) ) . '/64';
		}

		return (string) inet_ntop( $packed );
	}

	/**
	 * Ο ατομικός μετρητής: INSERT IGNORE για τη γέννηση του παραθύρου, CAS για
	 * κάθε αύξηση και για το reset ληγμένου παραθύρου. Κανένα αίτημα δεν
	 * επιστρέφει «επιτρέπεται» πριν μετρηθεί.
	 *
	 * @return bool
	 */
	private static function hit_key( $key, $limit, $window, $action, $scope ) {
		global $wpdb;

		if ( ! self::db_available() ) {
			self::log( 'db_unavailable', $action, $scope, $key );

			return false;
		}

		$now    = time();
		$option = '_transient_' . $key;

		for ( $attempt = 0; $attempt < self::CAS_ATTEMPTS; $attempt++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Το CAS χρειάζεται την ακριβή τιμή της βάσης, όχι cached.
			$stored_raw = $wpdb->get_var(
				$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option )
			);

			if ( null === $stored_raw ) {
				/*
				 * Η γέννηση του παραθύρου είναι κι αυτή κούρσα: μόνο το INSERT
				 * IGNORE ξέρει ποιος κέρδισε. Ο χαμένος ξαναδιαβάζει και
				 * μετριέται με CAS. (Το add_option() είναι upsert, όχι create.)
				 */
				if ( self::create_window( $option, $key, $window, $now ) ) {
					return true;
				}

				continue;
			}

			$stored = maybe_unserialize( $stored_raw );
			$start  = null;
			$count  = 0;

			if ( is_array( $stored ) && isset( $stored['count'], $stored['start'] ) ) {
				$stored_start = (int) $stored['start'];

				/* Έγκυρη αρχή = μέσα στο παράθυρο και όχι στο μέλλον (αλλαγή ώρας). */
				if ( $stored_start <= $now && ( $now - $stored_start ) < $window ) {
					$count = (int) $stored['count'];
					$start = $stored_start;
				}
			}

			if ( null === $start ) {
				/* Ληγμένο ή αλλοιωμένο παράθυρο: reset με CAS, ποτέ με set_transient(). */
				if ( self::reset_window( $option, $key, $stored_raw, $window, $now ) ) {
					return true;
				}

				continue;
			}

			if ( $count >= $limit ) {
				return false;
			}

			$next = maybe_serialize(
				array(
					'count' => $count + 1,
					'start' => $start,
				)
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-swap στην ακριβή προηγούμενη τιμή.
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

				return true;
			}
		}

		/* Ακραίο contention στο ίδιο κλειδί ή πρόβλημα βάσης: fail-closed, αλλά ορατό. */
		self::log( 'cas_exhausted', $action, $scope, $key );

		return false;
	}

	/** @return bool Αν το $wpdb έχει όσα χρειάζεται ο limiter. */
	private static function db_available() {
		global $wpdb;

		return is_object( $wpdb ) && isset( $wpdb->options )
			&& is_callable( array( $wpdb, 'get_var' ) )
			&& is_callable( array( $wpdb, 'get_results' ) )
			&& is_callable( array( $wpdb, 'query' ) )
			&& is_callable( array( $wpdb, 'prepare' ) );
	}

	/** Η τιμή ενός φρέσκου παραθύρου, serialized όπως τη γράφει το transients API. */
	private static function fresh_window( $now ) {
		return maybe_serialize(
			array(
				'count' => 1,
				'start' => (int) $now,
			)
		);
	}

	/**
	 * Ατομική δημιουργία παραθύρου με INSERT IGNORE.
	 *
	 * Οποιαδήποτε αποτυχία επιστρέφει false· ο καλών ξαναπροσπαθεί και μετά
	 * από CAS_ATTEMPTS μπλοκάρει, ώστε σπασμένος πίνακας ≠ «χωρίς όριο».
	 *
	 * @return bool true αν το παράθυρο το δημιουργήσαμε εμείς.
	 */
	private static function create_window( $option, $key, $window, $now ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Το unique index κρίνει ποιος δημιούργησε το παράθυρο.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
				$option,
				self::fresh_window( $now ),
				'no'
			)
		);

		if ( 1 !== (int) $inserted ) {
			return false;
		}

		self::write_timeout( $key, $window, $now );
		self::forget_option_cache( $option, $key );
		self::maybe_sweep( $now );

		return true;
	}

	/**
	 * Ατομικό reset ληγμένου/αλλοιωμένου παραθύρου με CAS.
	 *
	 * Εδώ κρέμεται επίσης ο καθαρισμός: ένας μόνιμος μετρητής δημιουργείται
	 * μία φορά, αλλά κάνει reset σε κάθε λήξη παραθύρου.
	 *
	 * @return bool true αν το reset ήταν δικό μας (μετρηθήκαμε ως το 1ο αίτημα).
	 */
	private static function reset_window( $option, $key, $stored_raw, $window, $now ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-swap στην ακριβή προηγούμενη τιμή.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				self::fresh_window( $now ),
				$option,
				$stored_raw
			)
		);

		if ( 1 !== (int) $updated ) {
			return false;
		}

		self::write_timeout( $key, $window, $now );
		self::forget_option_cache( $option, $key );
		self::maybe_sweep( $now );

		return true;
	}

	/**
	 * Γράφει τη γραμμή λήξης του transient (upsert: το παράθυρο είναι ήδη δικό μας).
	 * Το TTL είναι λίγο μεγαλύτερο από το παράθυρο, ώστε τη λήξη να την κρίνει
	 * το 'start' και όχι ο καθαρισμός.
	 */
	private static function write_timeout( $key, $window, $now ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Συνοδεύει το ατομικό INSERT/CAS του παραθύρου.
		$written = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
				'_transient_timeout_' . $key,
				(string) ( (int) $now + (int) $window + MINUTE_IN_SECONDS ),
				'no'
			)
		);

		if ( false === $written ) {
			self::log( 'timeout_write_failed', 'internal', 'internal' );
		}
	}

	/** Καθαρίζει τις cached τιμές (και το «δεν υπάρχει») του request. */
	private static function forget_option_cache( $option, $key ) {
		wp_cache_delete( $option, 'options' );
		wp_cache_delete( '_transient_timeout_' . $key, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Τρέχει τον καθαρισμό αν πέρασε το SWEEP_INTERVAL και το πέρασμα είναι δικό μας.
	 *
	 * Ο core δεν σκουπίζει DB transients όταν υπάρχει external object cache,
	 * ενώ εμείς γράφουμε τους μετρητές στη βάση, άρα χρειάζεται δικός μας reaper.
	 * Καλείται μόνο σε γέννηση/reset παραθύρου, όχι σε κάθε αίτημα.
	 *
	 * @param int $now Τρέχον timestamp.
	 * @return bool true αν έτρεξε πέρασμα.
	 */
	public static function maybe_sweep( $now ) {
		global $wpdb;

		if ( ! self::db_available() ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Το CAS της δέσμευσης χρειάζεται την ακριβή τιμή.
		$previous = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::SWEEP_OPTION )
		);

		$last = ( null === $previous ) ? 0 : (int) $previous;

		if ( $last > 0 && ( $now - $last ) < self::SWEEP_INTERVAL ) {
			return false;
		}

		/* Δέσμευση πριν τη δουλειά: αποκλείει δεύτερο ταυτόχρονο πέρασμα και retry-storm σε σφάλμα βάσης. */
		if ( ! self::claim_sweep( $now, $previous ) ) {
			return false;
		}

		self::sweep( $now );

		/* 2.15.4: στο ίδιο ωριαίο πέρασμα, τα ληγμένα tokens (βλ. QRRP_Tokens::sweep_expired()). */
		if ( class_exists( 'QRRP_Tokens' ) && is_callable( array( 'QRRP_Tokens', 'sweep_expired' ) ) ) {
			try {
				QRRP_Tokens::sweep_expired( $now );
			} catch ( \Throwable $qrrp_token_sweep_failed ) {
				self::log( 'token_sweep_failed', 'internal', 'internal' );
			}
		}

		return true;
	}

	/**
	 * Αδειάζει τα ληγμένα παράθυρα σε batches, μέχρι να μην μείνει τίποτα ή να
	 * εξαντληθεί το SWEEP_TIME_BUDGET / SWEEP_MAX_BATCHES. Οι διαγραφές είναι
	 * CAS πάνω στις ακριβείς τιμές, άρα ασφαλείς απέναντι σε παράλληλα αιτήματα.
	 *
	 * @param int $now Τρέχον timestamp.
	 * @return int Πόσες γραμμές σβήστηκαν.
	 */
	public static function sweep( $now ) {
		if ( ! self::db_available() ) {
			return 0;
		}

		$deadline = microtime( true ) + self::SWEEP_TIME_BUDGET;
		$deleted  = 0;

		foreach ( array( 'sweep_expired_batch', 'sweep_orphan_window_batch' ) as $pass ) {
			$after = 0;

			for ( $batch = 0; $batch < self::SWEEP_MAX_BATCHES; $batch++ ) {
				$result   = self::$pass( $now, $after );
				$deleted += $result['deleted'];

				if ( $result['done'] || microtime( true ) >= $deadline ) {
					break;
				}

				$after = $result['last_id'];
			}

			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}

		return $deleted;
	}

	/**
	 * Ατομική δέσμευση του περάσματος: INSERT IGNORE αν λείπει η γραμμή, αλλιώς
	 * CAS στην ακριβή προηγούμενη τιμή. Το query επιστρέφει 1 (δικό μας),
	 * 0 (πρόλαβε άλλος — φυσιολογικό) ή false (σφάλμα — καταγράφεται).
	 *
	 * @param int         $now      Τώρα, σε unix time.
	 * @param string|null $previous Η ακριβής αποθηκευμένη τιμή, ή null αν λείπει.
	 * @return bool
	 */
	private static function claim_sweep( $now, $previous ) {
		global $wpdb;

		if ( null === $previous ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Το unique index κρίνει ποιος δέσμευσε το πέρασμα.
			$claimed = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
					self::SWEEP_OPTION,
					(string) (int) $now,
					'no'
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-swap· το update_option() δεν εκφράζει «μόνο αν δεν άλλαξε».
			$claimed = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
					(string) (int) $now,
					self::SWEEP_OPTION,
					(string) $previous
				)
			);
		}

		if ( false === $claimed ) {
			self::log( 'reaper_state_write_failed', 'internal', 'internal' );

			return false;
		}

		if ( 1 !== (int) $claimed ) {
			return false;
		}

		wp_cache_delete( self::SWEEP_OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		return true;
	}

	/** Το LIKE pattern για ένα πρόθεμα option_name. */
	private static function like_prefix( $prefix ) {
		global $wpdb;

		return is_callable( array( $wpdb, 'esc_like' ) )
			? $wpdb->esc_like( $prefix ) . '%'
			: addcslashes( $prefix, '_%\\' ) . '%';
	}

	/**
	 * Ένα batch ληγμένων παραθύρων (keyset στο option_id μετά το $after).
	 *
	 * Διαβάζονται οι ακριβείς τιμές λήξης και παραθύρου, και το DELETE
	 * ταιριάζει μόνο αν παραμένουν ίδιες: ένα παράλληλο increment/reset κάνει
	 * το predicate να αποτύχει αντί να σβηστεί ζωντανός μετρητής.
	 *
	 * @param int $now   Τρέχον timestamp.
	 * @param int $after Τελευταίο option_id του προηγούμενου batch.
	 * @return array{deleted:int, done:bool, last_id:int}
	 */
	private static function sweep_expired_batch( $now, $after ) {
		global $wpdb;

		$done           = array(
			'deleted' => 0,
			'done'    => true,
			'last_id' => (int) $after,
		);
		$timeout_prefix = '_transient_timeout_qrrp_rl_';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Συντήρηση γραμμών που ο core δεν σκουπίζει με external object cache.
		$timeout_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d AND option_id > %d ORDER BY option_id ASC LIMIT %d",
				self::like_prefix( $timeout_prefix ),
				(int) $now,
				(int) $after,
				self::SWEEP_BATCH
			)
		);

		if ( null === $timeout_rows ) {
			self::log( 'reaper_select_failed', 'internal', 'internal' );

			return $done;
		}

		if ( ! is_array( $timeout_rows ) || empty( $timeout_rows ) ) {
			return $done;
		}

		$last_id     = (int) $after;
		$timeouts    = array();
		$value_names = array();

		foreach ( $timeout_rows as $row ) {
			$last_id       = max( $last_id, is_object( $row ) && isset( $row->option_id ) ? (int) $row->option_id : 0 );
			$timeout_name  = is_object( $row ) && isset( $row->option_name ) ? (string) $row->option_name : '';
			$timeout_value = is_object( $row ) && isset( $row->option_value ) ? (string) $row->option_value : '';

			if ( 0 !== strpos( $timeout_name, $timeout_prefix ) ) {
				continue;
			}

			$key    = substr( $timeout_name, strlen( '_transient_timeout_' ) );
			$expiry = (int) $timeout_value;

			if ( $expiry <= 0 || $expiry >= $now ) {
				continue;
			}

			$value_name                = '_transient_' . $key;
			$timeouts[ $timeout_name ] = array(
				'expiry'     => $expiry,
				'raw'        => $timeout_value,
				'value_name' => $value_name,
			);
			$value_names[ $value_name ] = true;
		}

		$result = array(
			'deleted' => 0,
			'done'    => count( $timeout_rows ) < self::SWEEP_BATCH || $last_id <= (int) $after,
			'last_id' => $last_id,
		);

		if ( empty( $value_names ) ) {
			return $result;
		}

		$value_names  = array_keys( $value_names );
		$placeholders = implode( ', ', array_fill( 0, count( $value_names ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded maintenance sweep.
		$value_rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Τα {$placeholders} είναι παραγόμενα '%s'· οι τιμές περνούν από prepare().
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ( {$placeholders} )",
				$value_names
			)
		);

		if ( null === $value_rows ) {
			self::log( 'reaper_value_select_failed', 'internal', 'internal' );
			$result['done'] = true;

			return $result;
		}

		$current_values = array();
		foreach ( is_array( $value_rows ) ? $value_rows : array() as $row ) {
			$name = is_object( $row ) && isset( $row->option_name ) ? (string) $row->option_name : '';
			$raw  = is_object( $row ) && isset( $row->option_value ) ? (string) $row->option_value : '';
			if ( '' !== $name ) {
				$current_values[ $name ] = $raw;
			}
		}

		$clauses     = array();
		$args        = array();
		$cache_names = array();
		$orphans     = array();
		$orphan_args = array();

		foreach ( $timeouts as $timeout_name => $timeout_data ) {
			$value_name = $timeout_data['value_name'];

			if ( ! array_key_exists( $value_name, $current_values ) ) {
				/* Λήξη χωρίς τιμή: το join DELETE δεν θα την έπιανε ποτέ. */
				$orphans[]     = $timeout_name;
				$orphan_args[] = $timeout_name;
				$orphan_args[] = $timeout_data['raw'];
				$cache_names[] = $timeout_name;
				continue;
			}

			$value_raw = $current_values[ $value_name ];
			$stored    = maybe_unserialize( $value_raw );

			/*
			 * Φρέσκο παράθυρο με παλιά λήξη = είμαστε ανάμεσα στο reset και το
			 * upsert της λήξης. Δεν το αγγίζουμε. Αλλοιωμένη τιμή σβήνεται.
			 */
			if ( is_array( $stored ) && isset( $stored['start'] )
				&& (int) $stored['start'] >= (int) $timeout_data['expiry'] ) {
				continue;
			}

			$clauses[] = '(timeout_opt.option_name = %s AND timeout_opt.option_value = %s AND value_opt.option_value = %s)';
			$args[]    = $timeout_name;
			$args[]    = $timeout_data['raw'];
			$args[]    = $value_raw;

			$cache_names[] = $timeout_name;
			$cache_names[] = $value_name;
		}

		$result['deleted'] += self::delete_exact_rows( $orphans, $orphan_args );

		if ( ! empty( $clauses ) ) {
			/* strlen('_transient_timeout_') = 19, άρα SUBSTRING(..., 20) => qrrp_rl_*. */
			$sql = "DELETE timeout_opt, value_opt
				FROM {$wpdb->options} timeout_opt
				INNER JOIN {$wpdb->options} value_opt
					ON value_opt.option_name = CONCAT('_transient_', SUBSTRING(timeout_opt.option_name, 20))
				WHERE " . implode( ' OR ', $clauses );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Το $sql έχει μόνο σταθερές και παραγόμενα '%s'· κάθε τιμή περνά από prepare().
			$deleted = $wpdb->query( $wpdb->prepare( $sql, $args ) );

			if ( false === $deleted ) {
				self::log( 'reaper_delete_failed', 'internal', 'internal' );
				$result['done'] = true;
			} else {
				$result['deleted'] += (int) $deleted;
			}
		}

		foreach ( array_unique( $cache_names ) as $option_name ) {
			wp_cache_delete( $option_name, 'options' );
		}
		wp_cache_delete( 'notoptions', 'options' );

		return $result;
	}

	/**
	 * Ένα batch παραθύρων χωρίς γραμμή λήξης (αν απέτυχε το write_timeout()).
	 *
	 * Για το WordPress ένα transient χωρίς λήξη δεν λήγει ποτέ, άρα θα έμενε
	 * για πάντα. Το LEFT JOIN φιλτράρει στην SQL, ώστε το LIMIT να φράζει τη
	 * δουλειά και όχι την ορατότητα· η ηλικία κρίνεται στην PHP από το 'start'.
	 *
	 * @param int $now   Τρέχον timestamp.
	 * @param int $after Τελευταίο option_id του προηγούμενου batch.
	 * @return array{deleted:int, done:bool, last_id:int}
	 */
	private static function sweep_orphan_window_batch( $now, $after ) {
		global $wpdb;

		$value_prefix = '_transient_qrrp_rl_';
		$done         = array(
			'deleted' => 0,
			'done'    => true,
			'last_id' => (int) $after,
		);

		/* SUBSTRING( v.option_name, 12 ) = ό,τι ακολουθεί το '_transient_' (11 χαρακτήρες). */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Συντήρηση γραμμών που ο core δεν σκουπίζει με external object cache.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT v.option_id, v.option_name, v.option_value
				FROM {$wpdb->options} v
				LEFT JOIN {$wpdb->options} t
					ON t.option_name = CONCAT( '_transient_timeout_', SUBSTRING( v.option_name, 12 ) )
				WHERE v.option_name LIKE %s
					AND t.option_id IS NULL
					AND v.option_id > %d
				ORDER BY v.option_id ASC
				LIMIT %d",
				self::like_prefix( $value_prefix ),
				(int) $after,
				self::SWEEP_BATCH
			)
		);

		if ( null === $rows ) {
			self::log( 'reaper_window_select_failed', 'internal', 'internal' );

			return $done;
		}

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return $done;
		}

		$last_id = (int) $after;
		$names   = array();
		$args    = array();

		foreach ( $rows as $row ) {
			$last_id = max( $last_id, is_object( $row ) && isset( $row->option_id ) ? (int) $row->option_id : 0 );
			$name    = is_object( $row ) && isset( $row->option_name ) ? (string) $row->option_name : '';
			$raw     = is_object( $row ) && isset( $row->option_value ) ? (string) $row->option_value : '';

			if ( 0 !== strpos( $name, $value_prefix ) ) {
				continue;
			}

			$stored = maybe_unserialize( $raw );

			if ( ! is_array( $stored ) || ! isset( $stored['start'] ) ) {
				continue;
			}

			if ( ( $now - (int) $stored['start'] ) < self::SWEEP_ORPHAN_AGE ) {
				continue;
			}

			$names[] = $name;
			$args[]  = $name;
			$args[]  = $raw;
		}

		$deleted = self::delete_exact_rows( $names, $args );

		foreach ( $names as $name ) {
			wp_cache_delete( $name, 'options' );
		}

		return array(
			'deleted' => $deleted,
			'done'    => count( $rows ) < self::SWEEP_BATCH || $last_id <= (int) $after,
			'last_id' => $last_id,
		);
	}

	/**
	 * Σβήνει γραμμές μόνο αν η τιμή τους είναι ακόμη ακριβώς αυτή που διαβάστηκε.
	 *
	 * @param array $names Ονόματα γραμμών.
	 * @param array $args  Ζεύγη ( option_name, option_value ).
	 * @return int Πόσες γραμμές σβήστηκαν.
	 */
	private static function delete_exact_rows( $names, $args ) {
		global $wpdb;

		if ( empty( $names ) || empty( $args ) ) {
			return 0;
		}

		$clauses = array_fill( 0, count( $names ), '( option_name = %s AND option_value = %s )' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded maintenance sweep.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Τα $clauses είναι παραγόμενα '%s'· οι τιμές περνούν από prepare().
				"DELETE FROM {$wpdb->options} WHERE " . implode( ' OR ', $clauses ),
				$args
			)
		);

		if ( false === $deleted ) {
			self::log( 'reaper_orphan_delete_failed', 'internal', 'internal' );

			return 0;
		}

		return (int) $deleted;
	}

	/**
	 * Ψευδώνυμο του κλειδιού για τα logs: HMAC με το αλάτι του site, 16 hex.
	 *
	 * Ο χώρος των actor (user ids, IPv4) είναι μικρός, οπότε σκέτο hash θα
	 * αντιστρεφόταν με brute force. 64 bits κρατούν τις συγκρούσεις αμελητέες.
	 *
	 * @param string $key Το κλειδί του παραθύρου.
	 * @return string 16 hex, ή 'internal' για συμβάντα χωρίς actor ή χωρίς αλάτι.
	 */
	private static function fingerprint( $key ) {
		$key = (string) $key;

		if ( '' === $key ) {
			return 'internal';
		}

		$salt = function_exists( 'wp_salt' ) ? (string) wp_salt( 'nonce' ) : '';

		if ( '' === $salt ) {
			return 'internal';
		}

		return substr( hash_hmac( 'sha256', $key, $salt ), 0, 16 );
	}

	/**
	 * Ίχνος για fail-closed αποφάσεις και αποτυχίες συντήρησης.
	 *
	 * Το action `qrrp_rate_limit_failed` τρέχει πάντα· το error_log μόνο με
	 * WP_DEBUG. Περνούν μόνο event/action/scope και το ψευδώνυμο του κλειδιού,
	 * ποτέ IP, user id, email, GS1 δεδομένα ή tokens.
	 *
	 * @param string $event  Σταθερό αναγνωριστικό συμβάντος.
	 * @param string $action Λογικό action ή 'internal'.
	 * @param string $scope  'actor', 'global' ή 'internal'.
	 * @param string $key    Το κλειδί του παραθύρου· κενό για εσωτερικά συμβάντα.
	 */
	private static function log( $event, $action, $scope, $key = '' ) {
		$event  = sanitize_key( (string) $event );
		$action = sanitize_key( (string) $action );
		$scope  = sanitize_key( (string) $scope );
		$actor  = self::fingerprint( $key );

		do_action( 'qrrp_rate_limit_failed', $event, $action, $scope, $actor );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only ίχνος χωρίς δεδομένα χρήστη.
			error_log( sprintf( 'QRRP rate limiter: event=%s action=%s scope=%s actor=%s', $event, $action, $scope, $actor ) );
		}
	}
}
