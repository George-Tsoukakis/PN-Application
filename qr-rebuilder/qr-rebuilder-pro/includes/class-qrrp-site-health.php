<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Έλεγχοι Site Health του QR ReBuilder Pro: δημιουργία εικόνας, διάρκεια
 * page cache σε σχέση με το nonce, ταυτότητα επισκεπτών για το όριο
 * αιτημάτων, και εγγραφές συνδέσμων email που δεν γίνονται δεκτές.
 *
 * Κάθε έλεγχος χωρίζεται σε *_diagnostics() (συλλογή από το περιβάλλον) και
 * *_verdict() (καθαρή συνάρτηση), ώστε η κρίση να ελέγχεται χωρίς
 * πραγματικό περιβάλλον.
 */
final class QRRP_Site_Health {

	/** 2.15.7: cache του ελέγχου παλαιών συνδέσμων (12 ώρες). */
	public const LEGACY_TOKENS_CACHE = 'qrrp_sh_legacy_tokens';

	public static function init() {
		add_filter( 'site_status_tests', array( __CLASS__, 'register_site_health_test' ) );
	}

	/**
	 * @param mixed $tests Οι έλεγχοι του πυρήνα.
	 * @return mixed
	 */
	public static function register_site_health_test( $tests ) {
		if ( ! is_array( $tests ) ) {
			return $tests;
		}

		$tests['direct']['qrrp_image_support'] = array(
			'label' => __( 'Δημιουργία GS1 DataMatrix', 'qr-rebuilder-pro' ),
			'test'  => array( __CLASS__, 'run_site_health_test' ),
		);

		$tests['direct']['qrrp_page_cache_lifespan'] = array(
			'label' => __( 'Διάρκεια cache της σελίδας του εργαλείου', 'qr-rebuilder-pro' ),
			'test'  => array( __CLASS__, 'run_page_cache_test' ),
		);

		$tests['direct']['qrrp_rate_limit_identity'] = array(
			'label' => __( 'Ταυτότητα επισκεπτών για το όριο αιτημάτων', 'qr-rebuilder-pro' ),
			'test'  => array( __CLASS__, 'run_rate_limit_identity_test' ),
		);

		$tests['direct']['qrrp_legacy_tokens'] = array(
			'label' => __( 'Παλαιοί σύνδεσμοι email', 'qr-rebuilder-pro' ),
			'test'  => array( __CLASS__, 'run_legacy_tokens_test' ),
		);

		$tests['direct']['qrrp_token_capacity'] = array(
			'label' => __( 'Χωρητικότητα συνδέσμων email', 'qr-rebuilder-pro' ),
			'test'  => array( __CLASS__, 'run_token_capacity_test' ),
		);

		return $tests;
	}

	/**
	 * Κοινό περιτύλιγμα αποτελέσματος για όλους τους ελέγχους.
	 *
	 * @param array  $verdict {status,label,description}
	 * @param string $test    Το αναγνωριστικό του ελέγχου.
	 * @return array
	 */
	private static function site_health_result( array $verdict, $test ) {
		return array(
			'label'       => $verdict['label'],
			'status'      => $verdict['status'],
			'badge'       => array(
				'label' => __( 'QR ReBuilder Pro', 'qr-rebuilder-pro' ),
				'color' => 'blue',
			),
			'description' => $verdict['description'],
			'actions'     => '',
			'test'        => (string) $test,
		);
	}

	public static function run_page_cache_test() {
		return self::site_health_result(
			self::page_cache_verdict( self::page_cache_diagnostics() ),
			'qrrp_page_cache_lifespan'
		);
	}

	public static function run_rate_limit_identity_test() {
		return self::site_health_result(
			self::rate_limit_identity_verdict( self::rate_limit_identity_diagnostics() ),
			'qrrp_rate_limit_identity'
		);
	}

	public static function run_legacy_tokens_test() {
		return self::site_health_result(
			self::legacy_tokens_verdict( self::legacy_tokens_diagnostics() ),
			'qrrp_legacy_tokens'
		);
	}

	public static function run_token_capacity_test() {
		$usage = ( class_exists( 'QRRP_Tokens' ) && is_callable( array( 'QRRP_Tokens', 'index_usage' ) ) )
			? QRRP_Tokens::index_usage()
			: null;

		return self::site_health_result( self::token_capacity_verdict( $usage, time() ), 'qrrp_token_capacity' );
	}

	/**
	 * 2.15.7: γεμάτο ευρετήριο σημαίνει email χωρίς σύνδεσμο ανακατασκευής.
	 * Απόρριψη τις τελευταίες 7 ημέρες → 'recommended'· πάνω από 80% → 'recommended'.
	 *
	 * @param array|null $usage Ό,τι επιστρέφει η QRRP_Tokens::index_usage().
	 * @param int        $now   Τρέχουσα χρονοσφραγίδα.
	 * @return array{status:string, label:string, description:string}
	 */
	public static function token_capacity_verdict( $usage, $now ) {
		if ( ! is_array( $usage ) ) {
			return array(
				'status'      => 'recommended',
				'label'       => __( 'Δεν ήταν δυνατός ο έλεγχος της χωρητικότητας συνδέσμων', 'qr-rebuilder-pro' ),
				'description' => '<p>' . esc_html__( 'Το τμήμα διαχείρισης συνδέσμων δεν φορτώθηκε. Συνήθως σημαίνει ελλιπές ανέβασμα του πρόσθετου — ανεβάστε ξανά ολόκληρο τον φάκελο.', 'qr-rebuilder-pro' ) . '</p>',
			);
		}

		$count   = isset( $usage['count'] ) ? (int) $usage['count'] : 0;
		$max     = isset( $usage['max'] ) ? max( 1, (int) $usage['max'] ) : 1;
		$full_at = isset( $usage['full_at'] ) ? (int) $usage['full_at'] : 0;
		$usage_p = sprintf(
			/* translators: 1: live email rebuild links, 2: maximum. */
			esc_html__( 'Ενεργοί σύνδεσμοι: %1$d από %2$d.', 'qr-rebuilder-pro' ),
			$count,
			$max
		);
		$advice  = esc_html__( 'Μειώστε τη διάρκεια των συνδέσμων (φίλτρο qrrp_rebuild_token_ttl) ή αυξήστε το ταβάνι (φίλτρο qrrp_token_index_max, έως 10000).', 'qr-rebuilder-pro' );

		if ( $full_at > 0 && $now - $full_at < 7 * DAY_IN_SECONDS ) {
			return array(
				'status'      => 'recommended',
				'label'       => __( 'Κάποια email στάλθηκαν χωρίς σύνδεσμο ανακατασκευής', 'qr-rebuilder-pro' ),
				'description' => '<p>' . esc_html__( 'Τις τελευταίες 7 ημέρες το όριο ενεργών συνδέσμων γέμισε και νέα email στάλθηκαν χωρίς σύνδεσμο (ο κωδικός επισυνάπτεται κανονικά).', 'qr-rebuilder-pro' ) . '</p><p>' . $usage_p . '</p><p>' . $advice . '</p>',
			);
		}

		if ( $count * 5 >= $max * 4 ) {
			return array(
				'status'      => 'recommended',
				'label'       => __( 'Οι ενεργοί σύνδεσμοι email πλησιάζουν το όριο', 'qr-rebuilder-pro' ),
				'description' => '<p>' . $usage_p . '</p><p>' . $advice . '</p>',
			);
		}

		return array(
			'status'      => 'good',
			'label'       => __( 'Υπάρχει χώρος για νέους συνδέσμους email', 'qr-rebuilder-pro' ),
			'description' => '<p>' . $usage_p . '</p>',
		);
	}

	public static function run_site_health_test() {
		return self::site_health_result(
			self::site_health_verdict( self::image_diagnostics() ),
			'qrrp_image_support'
		);
	}

	/**
	 * Διάρκεια του page cache σε σχέση με τη ζωή του nonce.
	 *
	 * Για τους ανώνυμους η σελίδα του εργαλείου επιτρέπεται σε full-page cache,
	 * επειδή το nonce τους είναι κοινό. Αν όμως το cache κρατά περισσότερο από
	 * ένα tick του nonce (nonce_life / 2), το αποθηκευμένο HTML κουβαλά ληγμένο
	 * nonce και κάθε AJAX του επισκέπτη αποτυγχάνει. Το όριο υπολογίζεται από το
	 * φίλτρο nonce_life, όχι ως σταθερά.
	 *
	 * @return array{guests_allowed:bool, lifespan:int|null, infinite:bool, source:string, safe_max:int}
	 */
	public static function page_cache_diagnostics() {
		/*
		 * Το nonce_life φιλτράρεται με το ίδιο $action που παράγει το nonce της
		 * σελίδας (WP 6.1+), ώστε ένα action-specific φίλτρο να μετρά σωστά.
		 */
		$nonce_life = (int) apply_filters( 'nonce_life', DAY_IN_SECONDS, QRRP_Ajax::NONCE_ACTION );
		$safe_max   = (int) floor( max( 1, $nonce_life ) / 2 );

		$lifespan = null;
		$source   = '';
		$infinite = false;

		/*
		 * Ονομαστικά ανιχνεύεται μόνο το WP Rocket· για τα υπόλοιπα υπάρχει το
		 * φίλτρο qrrp_page_cache_lifespan. Στο WP Rocket διάρκεια 0 σημαίνει ότι το
		 * cache δεν λήγει ποτέ. Η μονάδα διαβάζεται από λίστα επιτρεπτών τιμών.
		 */
		$rocket = get_option( 'wp_rocket_settings' );

		if ( is_array( $rocket ) && isset( $rocket['purge_cron_interval'] ) ) {
			$interval = absint( $rocket['purge_cron_interval'] );
			$unit     = isset( $rocket['purge_cron_unit'] ) && is_scalar( $rocket['purge_cron_unit'] ) ? (string) $rocket['purge_cron_unit'] : 'HOUR_IN_SECONDS';
			$units    = array(
				'MINUTE_IN_SECONDS' => MINUTE_IN_SECONDS,
				'HOUR_IN_SECONDS'   => HOUR_IN_SECONDS,
				'DAY_IN_SECONDS'    => DAY_IN_SECONDS,
				'WEEK_IN_SECONDS'   => WEEK_IN_SECONDS,
				'MONTH_IN_SECONDS'  => MONTH_IN_SECONDS,
				'YEAR_IN_SECONDS'   => YEAR_IN_SECONDS,
			);
			$seconds  = isset( $units[ $unit ] ) ? (int) $units[ $unit ] : HOUR_IN_SECONDS;
			$source   = 'WP Rocket';

			if ( $interval > 0 ) {
				$lifespan = $interval * $seconds;
			} else {
				$infinite = true;
			}
		}

		/**
		 * Δηλώστε τη διάρκεια του page cache όταν δεν ανιχνεύεται αυτόματα.
		 *
		 * @param int|null $lifespan Δευτερόλεπτα, ή null αν είναι άγνωστη ή άπειρη.
		 * @param string   $source   Ονομασία της πηγής, για την περιγραφή.
		 */
		$filtered = apply_filters( 'qrrp_page_cache_lifespan', $lifespan, $source );

		if ( is_numeric( $filtered ) && (int) $filtered > 0 ) {
			$lifespan = (int) $filtered;
			$source   = ( '' !== $source && ! $infinite ) ? $source : __( 'φίλτρο qrrp_page_cache_lifespan', 'qr-rebuilder-pro' );
			$infinite = false;
		}

		return array(
			'guests_allowed' => ( '1' === (string) get_option( 'qrrp_allow_guests', '0' ) ),
			'lifespan'       => $lifespan,
			'infinite'       => $infinite,
			'source'         => $source,
			'safe_max'       => $safe_max,
		);
	}

	/**
	 * @param array $info Ό,τι επιστρέφει η page_cache_diagnostics().
	 * @return array{status:string, label:string, description:string}
	 */
	public static function page_cache_verdict( array $info ) {
		$guests   = ! empty( $info['guests_allowed'] );
		$lifespan = isset( $info['lifespan'] ) && is_numeric( $info['lifespan'] ) ? (int) $info['lifespan'] : null;
		$source   = isset( $info['source'] ) ? (string) $info['source'] : '';
		$safe_max = isset( $info['safe_max'] ) ? (int) $info['safe_max'] : (int) floor( DAY_IN_SECONDS / 2 );

		/*
		 * Χωρίς πρόσβαση επισκεπτών ο ανώνυμος δεν βλέπει εργαλείο, οπότε δεν
		 * υπάρχει nonce σε cached σελίδα και ο έλεγχος δεν ισχύει.
		 */
		if ( ! $guests ) {
			return array(
				'status'      => 'good',
				'label'       => __( 'Δεν ισχύει: η πρόσβαση επισκεπτών είναι κλειστή', 'qr-rebuilder-pro' ),
				'description' => '<p>' . esc_html__( 'Το εργαλείο απαιτεί σύνδεση, οπότε η σελίδα δεν σερβίρεται ποτέ από κοινόχρηστο cache με ενεργό εργαλείο. Αν κάποτε ανοίξετε την πρόσβαση επισκεπτών, αυτός ο έλεγχος θα αρχίσει να μετράει τη διάρκεια του page cache.', 'qr-rebuilder-pro' ) . '</p>',
			);
		}

		$hours = static function ( $seconds ) {
			return number_format_i18n( $seconds / HOUR_IN_SECONDS, 1 );
		};

		/* Cache που δεν λήγει ποτέ ξεπερνά αναγκαστικά κάθε όριο. */
		if ( ! empty( $info['infinite'] ) && null === $lifespan ) {
			$where = '' !== $source
				? sprintf(
					/* translators: %s: name of the cache plugin or source. */
					esc_html__( 'Το page cache δεν λήγει ποτέ (%s: διάρκεια 0).', 'qr-rebuilder-pro' ),
					esc_html( $source )
				)
				: esc_html__( 'Το page cache δεν λήγει ποτέ.', 'qr-rebuilder-pro' );

			return array(
				'status'      => 'critical',
				'label'       => __( 'Το page cache κρατά περισσότερο από όσο ζει το nonce', 'qr-rebuilder-pro' ),
				'description' => '<p>' . $where . ' ' . sprintf(
					/* translators: %s: maximum safe cache lifetime in hours. */
					esc_html__( 'Το όριο είναι %s ώρες. Οι ανώνυμοι επισκέπτες θα παίρνουν αποθηκευμένη σελίδα με ληγμένο nonce και κάθε σάρωση ή δημιουργία θα αποτυγχάνει με σφάλμα ασφαλείας — και η ανανέωση της σελίδας ΔΕΝ το λύνει, γιατί επιστρέφει το ίδιο αποθηκευμένο αντίγραφο. Μειώστε τη διάρκεια του page cache.', 'qr-rebuilder-pro' ),
					esc_html( $hours( $safe_max ) )
				) . '</p>',
			);
		}

		/*
		 * Άγνωστη διάρκεια: 'recommended' και όχι 'good', γιατί το πράσινο θα
		 * σήμαινε «ελέγχθηκε». Φτάνει εδώ μόνο με ανοιχτή πρόσβαση επισκεπτών.
		 */
		if ( null === $lifespan ) {
			return array(
				'status'      => 'recommended',
				'label'       => __( 'Δεν μπορεί να επαληθευτεί η διάρκεια του page cache', 'qr-rebuilder-pro' ),
				'description' => '<p>' . sprintf(
					/* translators: %s: maximum safe cache lifetime in hours. */
					esc_html__( 'Το πρόσθετο δεν μπόρεσε να διαβάσει τη διάρκεια του page cache αυτού του ιστότοπου, οπότε δεν την ελέγχει. Ο κανόνας: για τους ανώνυμους επισκέπτες η σελίδα του εργαλείου επιτρέπεται να μπει σε cache, αλλά η διάρκεια πρέπει να μένει κάτω από %s ώρες. Πάνω από αυτό, το nonce μέσα στο αποθηκευμένο HTML λήγει και κάθε ενέργεια του επισκέπτη αποτυγχάνει με σφάλμα ασφαλείας. Δηλώστε τη διάρκεια με το φίλτρο qrrp_page_cache_lifespan για να ενεργοποιηθεί ο έλεγχος.', 'qr-rebuilder-pro' ),
					esc_html( $hours( $safe_max ) )
				) . '</p>',
			);
		}

		$where = '' !== $source
			? sprintf(
				/* translators: 1: cache lifetime in hours, 2: name of the cache plugin or source. */
				esc_html__( 'Μετρήθηκε διάρκεια %1$s ωρών (%2$s).', 'qr-rebuilder-pro' ),
				esc_html( $hours( $lifespan ) ),
				esc_html( $source )
			)
			: sprintf(
				/* translators: %s: cache lifetime in hours. */
				esc_html__( 'Μετρήθηκε διάρκεια %s ωρών.', 'qr-rebuilder-pro' ),
				esc_html( $hours( $lifespan ) )
			);

		if ( $lifespan >= $safe_max ) {
			return array(
				'status'      => 'critical',
				'label'       => __( 'Το page cache κρατά περισσότερο από όσο ζει το nonce', 'qr-rebuilder-pro' ),
				'description' => '<p>' . $where . ' ' . sprintf(
					/* translators: %s: maximum safe cache lifetime in hours. */
					esc_html__( 'Το όριο είναι %s ώρες. Οι ανώνυμοι επισκέπτες θα παίρνουν αποθηκευμένη σελίδα με ληγμένο nonce και κάθε σάρωση ή δημιουργία θα αποτυγχάνει με σφάλμα ασφαλείας — και η ανανέωση της σελίδας ΔΕΝ το λύνει, γιατί επιστρέφει το ίδιο αποθηκευμένο αντίγραφο. Μειώστε τη διάρκεια του page cache.', 'qr-rebuilder-pro' ),
					esc_html( $hours( $safe_max ) )
				) . '</p>',
			);
		}

		/* Ζώνη προειδοποίησης αναλογική του ορίου (80%). */
		if ( $lifespan >= (int) floor( $safe_max * 0.8 ) ) {
			return array(
				'status'      => 'recommended',
				'label'       => __( 'Το page cache πλησιάζει το όριο του nonce', 'qr-rebuilder-pro' ),
				'description' => '<p>' . $where . ' ' . sprintf(
					/* translators: %s: maximum safe cache lifetime in hours. */
					esc_html__( 'Το όριο είναι %s ώρες. Λειτουργεί, αλλά μια αύξηση της διάρκειας θα σπάσει τις ενέργειες των ανώνυμων επισκεπτών.', 'qr-rebuilder-pro' ),
					esc_html( $hours( $safe_max ) )
				) . '</p>',
			);
		}

		return array(
			'status'      => 'good',
			'label'       => __( 'Η διάρκεια του page cache είναι μέσα στα όρια', 'qr-rebuilder-pro' ),
			'description' => '<p>' . $where . ' ' . sprintf(
				/* translators: %s: maximum safe cache lifetime in hours. */
				esc_html__( 'Το όριο είναι %s ώρες.', 'qr-rebuilder-pro' ),
				esc_html( $hours( $safe_max ) )
			) . '</p>',
		);
	}

	/**
	 * Τι βλέπει ο rate limiter ως ταυτότητα ανώνυμου επισκέπτη.
	 *
	 * Ο κουβάς του ορίου είναι ανά REMOTE_ADDR. Πίσω από CDN/reverse proxy που
	 * δεν ξαναγράφει τη διεύθυνση, όλοι οι επισκέπτες μοιράζονται έναν κουβά.
	 * Ο έλεγχος συγκρίνει το REMOTE_ADDR με την κεφαλίδα προώθησης του τρέχοντος
	 * αιτήματος. Η κεφαλίδα χρησιμοποιείται μόνο για διάγνωση — είναι
	 * πλαστογραφήσιμη.
	 *
	 * @return array{guests_allowed:bool, remote_addr:string, forwarded_for:string, filtered:bool}
	 */
	public static function rate_limit_identity_diagnostics() {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Τιμές μόνο για εμφάνιση στη διάγνωση· περνούν από sanitize_text_field παρακάτω και δεν χρησιμοποιούνται για καμία απόφαση.
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) && is_scalar( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		$forwarded = '';

		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP' ) as $header ) {
			if ( isset( $_SERVER[ $header ] ) && is_scalar( $_SERVER[ $header ] ) ) {
				$forwarded = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
				break;
			}
		}
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotValidated

		return array(
			'guests_allowed' => ( '1' === (string) get_option( 'qrrp_allow_guests', '0' ) ),
			'remote_addr'    => $remote,
			'forwarded_for'  => $forwarded,
			'filtered'       => has_filter( 'qrrp_rate_limit_remote_addr' ),
		);
	}

	/**
	 * @param array $info Ό,τι επιστρέφει η rate_limit_identity_diagnostics().
	 * @return array{status:string, label:string, description:string}
	 */
	public static function rate_limit_identity_verdict( array $info ) {
		$guests    = ! empty( $info['guests_allowed'] );
		$remote    = isset( $info['remote_addr'] ) ? trim( (string) $info['remote_addr'] ) : '';
		$forwarded = isset( $info['forwarded_for'] ) ? trim( (string) $info['forwarded_for'] ) : '';
		$filtered  = ! empty( $info['filtered'] );

		if ( ! $guests ) {
			return array(
				'status'      => 'good',
				'label'       => __( 'Δεν ισχύει: η πρόσβαση επισκεπτών είναι κλειστή', 'qr-rebuilder-pro' ),
				'description' => '<p>' . esc_html__( 'Το όριο αιτημάτων μετρά ανά συνδεδεμένο χρήστη, οπότε η διεύθυνση IP δεν χρησιμοποιείται ως ταυτότητα.', 'qr-rebuilder-pro' ) . '</p>',
			);
		}

		if ( $filtered ) {
			return array(
				'status'      => 'good',
				'label'       => __( 'Η ταυτότητα επισκέπτη ορίζεται ρητά από τον ιστότοπο', 'qr-rebuilder-pro' ),
				'description' => '<p>' . esc_html__( 'Υπάρχει ενεργό φίλτρο qrrp_rate_limit_remote_addr, δηλαδή κάποιος έχει δηλώσει πώς εξάγεται η διεύθυνση του επισκέπτη πίσω από proxy. Το πρόσθετο δεν κρίνει την ορθότητά του.', 'qr-rebuilder-pro' ) . '</p>',
			);
		}

		/* Χωρίς κεφαλίδα προώθησης δεν φαίνεται πρόβλημα από αυτό το αίτημα. */
		if ( '' === $forwarded ) {
			return array(
				'status'      => 'good',
				'label'       => __( 'Οι επισκέπτες μετρώνται ανά διεύθυνση IP', 'qr-rebuilder-pro' ),
				'description' => '<p>' . esc_html__( 'Δεν εντοπίστηκε κεφαλίδα προώθησης σε αυτό το αίτημα, οπότε η διεύθυνση που βλέπει το πρόσθετο είναι πιθανότατα του ίδιου του επισκέπτη.', 'qr-rebuilder-pro' ) . '</p>',
			);
		}

		$client = trim( (string) strtok( $forwarded, ',' ) );

		if ( '' !== $remote && $client === $remote ) {
			return array(
				'status'      => 'good',
				'label'       => __( 'Ο web server μεταφράζει σωστά τη διεύθυνση πίσω από το CDN', 'qr-rebuilder-pro' ),
				'description' => '<p>' . esc_html__( 'Υπάρχει κεφαλίδα προώθησης και συμφωνεί με τη διεύθυνση που βλέπει η PHP, δηλαδή κάθε επισκέπτης μετράει χωριστά στο όριο αιτημάτων.', 'qr-rebuilder-pro' ) . '</p>',
			);
		}

		return array(
			'status'      => 'recommended',
			'label'       => __( 'Όλοι οι επισκέπτες μοιράζονται ένα όριο αιτημάτων', 'qr-rebuilder-pro' ),
			'description' => '<p>' . sprintf(
				/* translators: 1: address PHP sees, 2: address reported by the forwarding header. */
				esc_html__( 'Η PHP βλέπει %1$s ενώ η κεφαλίδα προώθησης δηλώνει %2$s. Ο ιστότοπος είναι πίσω από CDN ή reverse proxy που δεν μεταφράζει τη διεύθυνση, οπότε το πρόσθετο μετράει όλους τους ανώνυμους επισκέπτες σαν έναν: μόλις ένας πιάσει το όριο, μπλοκάρονται και οι υπόλοιποι.', 'qr-rebuilder-pro' ),
				'<code>' . esc_html( $remote ) . '</code>',
				'<code>' . esc_html( $client ) . '</code>'
			) . '</p><p>' . esc_html__( 'Η σωστή λύση είναι ο web server να ξαναγράφει τη διεύθυνση (π.χ. real_ip module) μόνο για τους διακομιστές του CDN. Εναλλακτικά, ορίστε τη ρητά με το φίλτρο qrrp_rate_limit_remote_addr — ποτέ διαβάζοντας τυφλά την κεφαλίδα, γιατί είναι πλαστογραφήσιμη.', 'qr-rebuilder-pro' ) . '</p>',
		);
	}

	/**
	 * Κρίση για τη δημιουργία εικόνας (βιβλιοθήκη barcode + GD).
	 *
	 * Διαφέρει από την QRRP_DataMatrix::is_available(): εκείνη απαντά «μπορώ να
	 * προσπαθήσω;», ενώ εδώ ελέγχεται και αν η βιβλιοθήκη είναι το ενσωματωμένο
	 * αντίγραφο. Η ίδια κρίση τροφοδοτεί και την ειδοποίηση της σελίδας
	 * ρυθμίσεων.
	 *
	 * @param array $info Ό,τι επιστρέφει η QRRP_DataMatrix::diagnostics().
	 * @return array{status:string, label:string, description:string}
	 */
	public static function site_health_verdict( array $info ) {
		$library_loaded = ! empty( $info['library_loaded'] );
		$is_bundled     = ! empty( $info['library_is_bundled'] );
		$gd_available   = ! empty( $info['gd_available'] );
		$missing_gd     = isset( $info['missing_gd_functions'] ) && is_array( $info['missing_gd_functions'] )
			? $info['missing_gd_functions']
			: array();
		$path           = isset( $info['library_path'] ) ? (string) $info['library_path'] : '';

		$parts = array();

		if ( ! $library_loaded ) {
			$parts[] = esc_html__( 'Δεν φορτώθηκε η ενσωματωμένη βιβλιοθήκη barcode (vendor/tc-lib-barcode). Συνήθως σημαίνει ελλιπές ή κατεστραμμένο ανέβασμα του πρόσθετου — ανεβάστε ξανά ολόκληρο τον φάκελο.', 'qr-rebuilder-pro' );
		} elseif ( ! $is_bundled ) {
			/*
			 * Βιβλιοθήκη εκτός του ενσωματωμένου αντιγράφου (symlinked vendor, μερική
			 * αντιγραφή, διπλό αντίγραφο). Το κείμενο είναι το ίδιο με της
			 * QRRP_DataMatrix, ώστε η ίδια αιτία να περιγράφεται παντού ίδια.
			 */
			$parts[] = sprintf(
				/* translators: %s: absolute path of the loaded library, shown to administrators only. */
				esc_html__( 'Η βιβλιοθήκη barcode δεν φορτώθηκε από το ενσωματωμένο αντίγραφο του QR ReBuilder Pro. Συνήθως σημαίνει ελλιπές ανέβασμα, διακοπείσα αναβάθμιση ή διπλό αντίγραφο του φακέλου — ανεβάστε ξανά ολόκληρο τον φάκελο του πρόσθετου. Το πρόσθετο επαληθεύει κάθε κωδικό που παράγει και θα σταματήσει τη δημιουργία αν δεν μπορεί να επιβεβαιώσει το FNC1 — δηλαδή ή θα παράγεται σωστό GS1 DataMatrix ή θα εμφανίζεται σφάλμα, ποτέ σιωπηλά λάθος κωδικός. Φορτωμένη βιβλιοθήκη: %s', 'qr-rebuilder-pro' ),
				'<code>' . esc_html( $path ) . '</code>'
			);
		}

		if ( ! $gd_available ) {
			$parts[] = sprintf(
				/* translators: %s: comma-separated list of missing GD functions. */
				esc_html__( 'Λείπει η επέκταση GD της PHP ή κάποιες από τις συναρτήσεις της που χρησιμοποιεί το πρόσθετο (%s). Ζητήστε από τον πάροχο φιλοξενίας να ενεργοποιήσει την GD με υποστήριξη PNG.', 'qr-rebuilder-pro' ),
				'<code>' . esc_html( implode( ', ', $missing_gd ) ) . '</code>'
			);
		}

		/*
		 * Critical μόνο όταν η δημιουργία είναι σίγουρα αδύνατη· η ξένη βιβλιοθήκη
		 * από μόνη της είναι 'recommended'.
		 */
		if ( ! $library_loaded || ! $gd_available ) {
			$parts[] = esc_html__( 'Η σάρωση και η ανάλυση GS1 λειτουργούν κανονικά· η εκτύπωση, η αποθήκευση και η αποστολή με email θα αποτυγχάνουν.', 'qr-rebuilder-pro' );

			return array(
				'status'      => 'critical',
				'label'       => __( 'Το QR ReBuilder Pro δεν μπορεί να δημιουργήσει GS1 DataMatrix', 'qr-rebuilder-pro' ),
				'description' => '<p>' . implode( '</p><p>', $parts ) . '</p>',
			);
		}

		if ( ! $is_bundled ) {
			return array(
				'status'      => 'recommended',
				'label'       => __( 'Η βιβλιοθήκη barcode δεν είναι το ενσωματωμένο αντίγραφο', 'qr-rebuilder-pro' ),
				'description' => '<p>' . implode( '</p><p>', $parts ) . '</p>',
			);
		}

		return array(
			'status'      => 'good',
			'label'       => __( 'Η δημιουργία GS1 DataMatrix λειτουργεί', 'qr-rebuilder-pro' ),
			'description' => '<p>' . esc_html__( 'Ο server διαθέτει την επέκταση GD με όλες τις συναρτήσεις που χρειάζεται το πρόσθετο, και χρησιμοποιείται η ενσωματωμένη βιβλιοθήκη barcode.', 'qr-rebuilder-pro' ) . '</p>',
		);
	}

	/**
	 * Πλήθος εγγραφών συνδέσμων email σε μορφή που ο verifier απορρίπτει.
	 * Ξεχωριστός έλεγχος από το qrrp_image_support, γιατί είναι πληροφοριακός.
	 *
	 * @return array{available:bool, legacy:int, unreadable:int}
	 */
	private static function legacy_tokens_diagnostics() {
		if ( ! class_exists( 'QRRP_Tokens' ) || ! is_callable( array( 'QRRP_Tokens', 'legacy_payload_counts' ) ) ) {
			return array(
				'available'  => false,
				'legacy'     => 0,
				'unreadable' => 0,
			);
		}

		/*
		 * 2.15.7: ένα get_transient ανά εγγραφή του ευρετηρίου (έως 2000) σε κάθε
		 * φόρτωση του Site Health ήταν ακριβό· το αποτέλεσμα κρατιέται 12 ώρες.
		 */
		$counts = get_transient( self::LEGACY_TOKENS_CACHE );

		if ( ! is_array( $counts ) ) {
			$counts = QRRP_Tokens::legacy_payload_counts();
			set_transient( self::LEGACY_TOKENS_CACHE, $counts, 12 * HOUR_IN_SECONDS );
		}

		return array(
			'available'  => true,
			'legacy'     => isset( $counts['legacy'] ) ? (int) $counts['legacy'] : 0,
			'unreadable' => isset( $counts['unreadable'] ) ? (int) $counts['unreadable'] : 0,
		);
	}

	/**
	 * Εγγραφές χωρίς schema/type (legacy) ή με μερικό envelope (unreadable)
	 * απορρίπτονται από τον verifier και καμία τρέχουσα διαδρομή δεν τις
	 * παράγει, οπότε χρειάζονται διερεύνηση: 'recommended', όχι 'critical'.
	 *
	 * @param array $info Ό,τι επιστρέφει η legacy_tokens_diagnostics().
	 * @return array{status:string, label:string, description:string}
	 */
	public static function legacy_tokens_verdict( array $info ) {
		if ( empty( $info['available'] ) ) {
			return array(
				'status'      => 'recommended',
				'label'       => __( 'Δεν ήταν δυνατός ο έλεγχος των παλαιών συνδέσμων', 'qr-rebuilder-pro' ),
				'description' => '<p>' . esc_html__( 'Το τμήμα διαχείρισης συνδέσμων δεν φορτώθηκε. Συνήθως σημαίνει ελλιπές ανέβασμα του πρόσθετου — ανεβάστε ξανά ολόκληρο τον φάκελο.', 'qr-rebuilder-pro' ) . '</p>',
			);
		}

		$legacy     = (int) $info['legacy'];
		$unreadable = (int) $info['unreadable'];

		if ( 0 === $legacy && 0 === $unreadable ) {
			return array(
				'status'      => 'good',
				'label'       => __( 'Δεν υπάρχουν παλαιοί σύνδεσμοι email σε εκκρεμότητα', 'qr-rebuilder-pro' ),
				'description' => '<p>' . esc_html__( 'Κάθε ενεργός σύνδεσμος έχει τη σημερινή μορφή.', 'qr-rebuilder-pro' ) . '</p>',
			);
		}

		$parts = array();

		if ( $legacy > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: αριθμός ενεργών εγγραφών συνδέσμων παλαιάς μορφής. */
				esc_html( _n(
					'Εντοπίστηκε %d ενεργή εγγραφή συνδέσμου email παλαιάς μορφής, την οποία η τρέχουσα έκδοση δεν αποδέχεται.',
					'Εντοπίστηκαν %d ενεργές εγγραφές συνδέσμων email παλαιάς μορφής, τις οποίες η τρέχουσα έκδοση δεν αποδέχεται.',
					$legacy,
					'qr-rebuilder-pro'
				) ),
				$legacy
			);
		}

		if ( $unreadable > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: αριθμός εγγραφών που δεν διαβάστηκαν. */
				esc_html( _n(
					'%d εγγραφή δεν μπόρεσε να διαβαστεί και δεν κατατάσσεται.',
					'%d εγγραφές δεν μπόρεσαν να διαβαστούν και δεν κατατάσσονται.',
					$unreadable,
					'qr-rebuilder-pro'
				) ),
				$unreadable
			);
		}

		$parts[] = esc_html__( 'Ο έλεγχος συνδέσμων απορρίπτει αυτές τις εγγραφές. Η τρέχουσα έκδοση δεν τις δημιουργεί, άρα η εμφάνισή τους χρειάζεται διερεύνηση.', 'qr-rebuilder-pro' );

		return array(
			'status'      => 'recommended',
			'label'       => __( 'Εντοπίστηκαν εγγραφές συνδέσμων που δεν γίνονται δεκτές', 'qr-rebuilder-pro' ),
			'description' => '<p>' . implode( '</p><p>', $parts ) . '</p>',
		);
	}

	/**
	 * Τα diagnostics της QRRP_DataMatrix, ή ένα ασφαλές «όλα λείπουν» αν λείπει
	 * η ίδια η κλάση.
	 *
	 * @return array
	 */
	public static function image_diagnostics() {
		if ( ! class_exists( 'QRRP_DataMatrix' ) || ! is_callable( array( 'QRRP_DataMatrix', 'diagnostics' ) ) ) {
			return array(
				'library_loaded'       => false,
				'library_is_bundled'   => false,
				'library_path'         => '',
				'gd_available'         => false,
				'missing_gd_functions' => array(),
			);
		}

		return QRRP_DataMatrix::diagnostics();
	}
}

QRRP_Site_Health::init();
