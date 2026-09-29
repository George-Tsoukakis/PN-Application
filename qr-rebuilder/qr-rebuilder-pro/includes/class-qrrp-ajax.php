<?php
/**
 * AJAX endpoints του εργαλείου: parse, rebuild, send_email.
 *
 * Κάθε endpoint: POST + nonce + capability (check_permissions), rate limit,
 * και μόνο μετά ανάγνωση εισόδου. Τα GS1 δεδομένα μένουν byte-exact και τα
 * επικυρώνει μόνο ο QRRP_GS1_Parser· την προέλευση τους κρίνει ο QRRP_Provenance.
 *
 * @package QR_ReBuilder_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-qrrp-rate-limiter.php';

final class QRRP_Ajax {

	/**
	 * Η ενέργεια nonce του εργαλείου (τη χρησιμοποιούν και Shortcode, Admin).
	 * Αλλαγή τιμής ακυρώνει το nonce κάθε ανοιχτής σελίδας μέχρι το refresh.
	 */
	public const NONCE_ACTION = 'qrrp_nonce';

	/** Διάρκεια του validated_output proof: βραχύβιο, για τις άμεσες ενέργειες μετά το rebuild. */
	private const VALIDATED_OUTPUT_TTL = 15 * MINUTE_IN_SECONDS;

	/** Μέγιστο μήκος (bytes) για tokens, challenges και baseline τιμές του POST. */
	private const MAX_HANDLE_BYTES = 256;

	public static function init() {
		add_action( 'wp_ajax_qrrp_parse', array( __CLASS__, 'parse' ) );
		add_action( 'wp_ajax_qrrp_rebuild', array( __CLASS__, 'rebuild' ) );
		add_action( 'wp_ajax_qrrp_send_email', array( __CLASS__, 'send_email' ) );
		add_action( 'wp_ajax_qrrp_refresh_nonce', array( __CLASS__, 'refresh_nonce' ) );

		if ( self::guest_access_enabled() ) {
			add_action( 'wp_ajax_nopriv_qrrp_parse', array( __CLASS__, 'parse' ) );
			add_action( 'wp_ajax_nopriv_qrrp_rebuild', array( __CLASS__, 'rebuild' ) );
			add_action( 'wp_ajax_nopriv_qrrp_refresh_nonce', array( __CLASS__, 'refresh_nonce' ) );
		}

		if ( self::guest_email_enabled() ) {
			add_action( 'wp_ajax_nopriv_qrrp_send_email', array( __CLASS__, 'send_email' ) );
		}
	}

	/** Μία πηγή για admin και frontend: qrrp_tool_capability() στο κεντρικό αρχείο. */
	private static function configured_capability() {
		return qrrp_tool_capability();
	}

	private static function guest_access_enabled() {
		return '1' === get_option( 'qrrp_allow_guests', '0' )
			&& 'read' === self::configured_capability();
	}

	/**
	 * Η αποστολή email από επισκέπτες είναι ξεχωριστό opt-in: δημιουργεί
	 * εξερχόμενη αλληλογραφία και θα μπορούσε να γίνει mail relay.
	 */
	private static function guest_email_enabled() {
		return self::guest_access_enabled()
			&& '1' === get_option( 'qrrp_allow_guest_email', '0' );
	}

	private static function check_permissions() {
		self::require_post();

		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			self::json_error(
				'invalid_nonce',
				__( 'Ο έλεγχος ασφαλείας απέτυχε. Ανανεώστε τη σελίδα και δοκιμάστε ξανά.', 'qr-rebuilder-pro' ),
				403
			);
		}

		self::check_actor();
	}

	private static function require_post() {
		$request_method = isset( $_SERVER['REQUEST_METHOD'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) )
			: '';

		if ( 'POST' !== strtoupper( $request_method ) ) {
			self::json_error(
				'method_not_allowed',
				__( 'Μη επιτρεπτή μέθοδος αιτήματος.', 'qr-rebuilder-pro' ),
				405
			);
		}
	}

	/** Δικαίωμα του actor (χωρίς nonce): συνδεδεμένος με το capability, ή επισκέπτης με ανοιχτό εργαλείο. */
	private static function check_actor() {
		if ( is_user_logged_in() ) {
			if ( ! current_user_can( self::configured_capability() ) ) {
				self::json_error(
					'forbidden',
					__( 'Δεν έχετε δικαίωμα χρήσης αυτού του εργαλείου.', 'qr-rebuilder-pro' ),
					403
				);
			}

			return;
		}

		if ( ! self::guest_access_enabled() ) {
			self::json_error(
				'guest_access_disabled',
				__( 'Η πρόσβαση επισκεπτών δεν είναι ενεργοποιημένη.', 'qr-rebuilder-pro' ),
				403
			);
		}
	}

	/**
	 * Το ελάχιστο δικαίωμα για αποστολή email.
	 *
	 * Τιμές του option `qrrp_email_capability` (βλ. qrrp_allowed_email_capabilities()):
	 *   ''                => ίδιο με το δικαίωμα του εργαλείου (ρητή επιλογή)
	 *   'edit_posts'      => Συνεργάτες και άνω
	 *   'manage_options'  => Μόνο διαχειριστές
	 *   'qrrp_pharmacist' => Εγγεγραμμένοι φαρμακοποιοί
	 *
	 * Η προεπιλογή έρχεται από τη σταθερά QRRP_DEFAULT_EMAIL_CAPABILITY, όχι από
	 * literal: όταν το get_option() παίρνει δικό του default, το default του
	 * register_setting() δεν συμβουλεύεται ποτέ. Κάθε άκυρη ή corrupt τιμή
	 * (option ή φίλτρο) πέφτει fail-closed στην προεπιλογή, ποτέ στο 'read'.
	 *
	 * @return string
	 */
	private static function email_capability() {
		$configured = self::configured_capability();

		$default = qrrp_default_email_capability();

		$setting = get_option( 'qrrp_email_capability', $default );

		/*
		 * Ο τύπος ελέγχεται πριν από κάθε κανονικοποίηση: ένα array ή ένα μη-ASCII
		 * σκουπίδι θα γινόταν '' και θα διαβαζόταν ως ρητό «ίδιο με το εργαλείο».
		 */
		if ( ! is_scalar( $setting ) ) {
			$base = $default;
		} else {
			$raw_setting = trim( (string) $setting );

			if ( '' === $raw_setting ) {
				$base = $configured;
			} else {
				$normalized = sanitize_key( $raw_setting );

				if ( in_array( $normalized, qrrp_allowed_email_capabilities(), true ) ) {
					$base = $normalized;
				} else {
					$base = $default;
				}
			}
		}

		$email_capability = apply_filters( 'qrrp_email_capability', $base );
		$email_capability = is_scalar( $email_capability ) ? sanitize_key( (string) $email_capability ) : '';

		return '' !== $email_capability ? $email_capability : $default;
	}

	/**
	 * Αν ο τρέχων actor μπορεί να στείλει email. Ίδιοι κανόνες με το endpoint,
	 * ώστε το UI να μη δείχνει ενέργεια που θα απορριφθεί.
	 *
	 * @return bool
	 */
	public static function can_send_email() {
		if ( ! is_user_logged_in() ) {
			/* 2.15.3: χωρίς επιτρεπτά domains (ή δικό σας φίλτρο) δεν υπάρχει παραλήπτης. */
			return self::guest_email_enabled() && qrrp_guest_email_has_recipients();
		}

		return current_user_can( self::configured_capability() )
			&& current_user_can( self::email_capability() );
	}

	private static function check_email_permissions() {
		if ( self::can_send_email() ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			self::json_error(
				'guest_email_disabled',
				__( 'Η αποστολή email από επισκέπτες δεν είναι ενεργοποιημένη.', 'qr-rebuilder-pro' ),
				403
			);
		}

		self::json_error(
			'email_forbidden',
			__( 'Δεν έχετε δικαίωμα αποστολής email από αυτό το εργαλείο.', 'qr-rebuilder-pro' ),
			403
		);
	}

	/**
	 * 2.15.3: φρέσκο nonce για σελίδα που σερβιρίστηκε από cache μετά τη λήξη
	 * του δικού της. Χωρίς nonce (αυτός είναι ο σκοπός), αλλά με POST, τους
	 * ίδιους ελέγχους actor και rate limit. Το nonce δεν δίνει τίποτα που δεν
	 * δίνει ήδη η φόρτωση της σελίδας· cross-origin σελίδα δεν διαβάζει την απάντηση.
	 */
	public static function refresh_nonce() {
		self::require_post();
		self::check_actor();
		self::enforce_request_limits( 'refresh_nonce', 30, 300 );

		nocache_headers();

		self::json_success( array( 'nonce' => wp_create_nonce( self::NONCE_ACTION ) ) );
	}

	public static function parse() {
		self::check_permissions();
		self::enforce_request_limits( 'parse', 120, 600 );

		/* Χωρίς sanitize_text_field(): θα έσβηνε το GS (ASCII 29). Ο parser κάνει την επικύρωση. */
		$raw = self::post_scalar( 'raw' );

		if ( '' === $raw ) {
			self::json_error( 'missing_raw', __( 'Δεν δόθηκαν δεδομένα QR.', 'qr-rebuilder-pro' ), 400 );
		}

		if ( strlen( $raw ) > qrrp_max_raw_bytes() ) {
			self::json_error( 'raw_too_large', __( 'Τα δεδομένα QR υπερβαίνουν το επιτρεπτό μέγεθος.', 'qr-rebuilder-pro' ), 413 );
		}

		self::require_parser_method( 'parse' );

		$result = self::guarded( 'parse', static fn() => QRRP_GS1_Parser::parse( $raw ) );

		/* Πριν την απάντηση: η json_success() καταλήγει σε wp_die(). Η record_scan() είναι fail-silent. */
		QRRP_Stats::record_scan( $result );

		self::json_success( $result );
	}

	/**
	 * Authoritative rebuild: ο browser στέλνει πεδία, αλλά μόνο ο server τα
	 * επικυρώνει, ελέγχει την προέλευσή τους και χτίζει το GS1 string και την εικόνα.
	 *
	 * Σειρά: επικύρωση πεδίων (400) → provenance (409) → extras → λήξη (409) →
	 * εικόνα → κατανάλωση handles → καταγραφή → απάντηση.
	 */
	public static function rebuild() {
		self::check_permissions();
		self::enforce_request_limits( 'rebuild', 120, 300 );

		$fields = self::posted_gs1_fields();

		self::require_parser_method( 'validate_and_build' );
		$result = self::guarded( 'validate_and_build', static fn() => QRRP_GS1_Parser::validate_and_build( $fields ) );

		if ( ! empty( $result['errors'] ) ) {
			QRRP_Stats::record_rejection();

			self::json_error(
				'invalid_fields',
				implode( ' ', $result['errors'] ),
				400,
				array( 'errors' => $result['errors'] )
			);
		}

		/*
		 * Η policy τρέχει πριν από την εικόνα: τα υποβληθέντα πεδία δεν είναι από
		 * μόνα τους απόδειξη προέλευσης, και μια απόρριψη δεν πρέπει να εμφανίζεται
		 * ως datamatrix_failed.
		 */
		$verdict = self::enforce_provenance( $fields );

		/* Δεύτερο χτίσιμο μόνο όταν υπάρχουν αποδεδειγμένα extras της συσκευασίας. */
		$passthrough = self::derive_passthrough( $fields, $verdict );

		if ( array() !== $passthrough ) {
			$rebuilt = self::guarded(
				'validate_and_build',
				static fn() => QRRP_GS1_Parser::validate_and_build( $fields, $passthrough )
			);

			/*
			 * Fail closed, χωρίς επιστροφή στα τέσσερα πεδία: ένα σύμβολο που του
			 * λείπουν αποδεδειγμένα πεδία είναι σιωπηλά λάθος ετικέτα.
			 */
			if ( ! empty( $rebuilt['errors'] ) || empty( $rebuilt['raw'] ) ) {
				self::json_error(
					'passthrough_build_failed',
					__( 'Δεν ήταν δυνατή η αναδημιουργία του κωδικού με όλα τα πεδία της συσκευασίας.', 'qr-rebuilder-pro' ),
					500
				);
			}

			$result = $rebuilt;
		}

		self::require_expiry_acknowledgement( $fields );

		$image = self::generate_datamatrix_png_base64( $result['raw'] );

		/*
		 * Single-use handles καίγονται μόνο εδώ, αφού υπάρχει η εικόνα: ένας link
		 * scanner που απλώς ανοίγει το URL δεν καταναλώνει τίποτα.
		 */
		if ( ! self::consume_verified_handles( $verdict ) ) {
			self::json_error(
				'token_already_used',
				__( 'Ο σύνδεσμος ή η επιβεβαίωση χρησιμοποιήθηκε ήδη. Ξαναδοκίμασε από την αρχή.', 'qr-rebuilder-pro' ),
				409
			);
		}

		QRRP_Stats::record_success();

		$response = array(
			'raw'             => $result['raw'],
			'png'             => 'data:image/png;base64,' . $image['png'],
			'geometry'        => self::print_geometry( isset( $image['geometry'] ) ? $image['geometry'] : array() ),

			/* Πάντα παρόν ('' χωρίς extras) για τον έλεγχο συνέπειας του client. */
			'passthrough_raw' => isset( $result['passthrough_raw'] ) ? $result['passthrough_raw'] : '',
		) + self::provenance_metadata( $verdict );

		$validated_output = self::issue_validated_output_proof(
			$fields,
			$result['raw'],
			$verdict
		);

		if ( '' !== $validated_output ) {
			$response['validated_output'] = $validated_output;
		}

		self::json_success( $response );
	}

	/**
	 * Ληγμένο προϊόν: απαιτεί ρητή επιβεβαίωση (409 expiry_not_confirmed).
	 *
	 * Ξεχωριστό από το requires_confirmation του parser, που αφορά αβέβαιη
	 * ανάγνωση. Ελέγχεται στον server επειδή μόνο εδώ είναι γνωστή η ημερομηνία
	 * που θα τυπωθεί. Κοινό για rebuild και send_email.
	 *
	 * @param array $fields Τα GS1 πεδία, με κλειδί 'EXP'.
	 */
	private static function require_expiry_acknowledgement( array $fields ) {
		/* Μόνο η κλήση είναι guarded· το 409 παρακάτω δεν πρέπει να καταποθεί. */
		$expired = self::guarded( 'expiry_check', static fn() => QRRP_GS1_Parser::date_is_in_past( $fields['EXP'] ) );

		if ( ! $expired ) {
			return;
		}

		if ( '1' === self::post_scalar( 'expiry_confirmed' ) ) {
			return;
		}

		self::json_error(
			'expiry_not_confirmed',
			__( 'Η ημερομηνία λήξης έχει παρέλθει. Επιβεβαιώστε ότι θέλετε να δημιουργήσετε κωδικό για ληγμένο προϊόν.', 'qr-rebuilder-pro' ),
			409,
			array( 'expired' => true )
		);
	}

	/**
	 * Καταναλώνει μόνο τα handles που επαλήθευσε ο server (ποτέ τιμές του POST),
	 * και μόνο μετά από επιτυχία. Αποτυχία διαγραφής δεν ακυρώνει την απάντηση.
	 *
	 * @param array $verdict Verdict με consume_token / consume_challenge / consume_proof.
	 * @return bool false αν κάποιο handle είχε ήδη καταναλωθεί (ταυτόχρονο αίτημα).
	 */
	private static function consume_verified_handles( array $verdict ) {
		$types = array(
			'consume_token'     => 'email_rebuild',
			'consume_challenge' => 'provenance_challenge',
			'consume_proof'     => 'validated_output',
		);
		$all_consumed = true;

		foreach ( $types as $key => $type ) {
			$handle = isset( $verdict[ $key ] ) && is_scalar( $verdict[ $key ] ) ? (string) $verdict[ $key ] : '';

			if ( '' === $handle ) {
				continue;
			}

			try {
				/* Ατομικό: από δύο ταυτόχρονα αιτήματα, μόνο ένα βρίσκει τη γραμμή να σβήσει. */
				if ( false === QRRP_Tokens::consume( $handle, $type ) ) {
					$all_consumed = false;
				}
			} catch ( \Throwable $qrrp_cleanup_failed ) {
				self::report_unexpected_failure( 'token_cleanup', $qrrp_cleanup_failed );
				$all_consumed = false;
			}
		}

		return $all_consumed;
	}

	/**
	 * Η γέφυρα προς τον QRRP_Provenance, κοινή για rebuild και send_email (και
	 * τα δύο παράγουν την ίδια ετικέτα). Επιστρέφει το verdict ή απαντά 409.
	 *
	 * Το source_raw είναι η αρχική σάρωση, όχι το ανακατασκευασμένο raw: αλλιώς
	 * η έξοδος θα ελεγχόταν απέναντι στον εαυτό της.
	 *
	 * @param array $fields Τα υποβληθέντα GS1 πεδία.
	 * @return array
	 */
	private static function enforce_provenance( array $fields ) {
		if ( ! class_exists( 'QRRP_Provenance' ) ) {
			self::dependency_error();
		}

		/* Έλεγχος μεταφοράς: μεγάλη κατασκευασμένη είσοδος ανοίγει τεράστιο χώρο αναζήτησης στον parser. */
		$source_raw = self::post_scalar( 'source_raw' );

		if ( strlen( $source_raw ) > qrrp_max_raw_bytes() ) {
			self::json_error(
				'source_raw_too_large',
				__( 'Τα αρχικά δεδομένα της σάρωσης υπερβαίνουν το επιτρεπτό μέγεθος.', 'qr-rebuilder-pro' ),
				413
			);
		}

		/* Η ανάγνωση του POST μένει έξω από το guarded(): μπορεί να απαντήσει 413. */
		$context = array(
			'source_raw'           => $source_raw,
			'fields'               => $fields,
			'rebuild_token'        => self::post_handle( 'rebuild_token' ),
			'challenge'            => self::post_handle( 'provenance_challenge' ),
			'acknowledged'         => '1' === self::post_scalar( 'ambiguity_confirmed' ),
			'baseline'             => self::posted_baseline_fields(),
			'entry_mode'           => 'manual' === self::post_scalar( 'entry_mode' ) ? 'manual' : '',
			'manual_entry_allowed' => qrrp_manual_entry_allowed(),
		);

		$verdict = self::guarded( 'provenance', static fn() => QRRP_Provenance::evaluate( $context ) );

		if ( ! empty( $verdict['allowed'] ) ) {
			return $verdict;
		}

		QRRP_Stats::record_rejection();

		/* Το 409 κουβαλά τη διαδρομή ανάκτησης (challenge, picker) ώστε να μην είναι αδιέξοδο. */
		$payload = array();

		foreach (
			array(
				'challenge',
				'changed_fields',
				'changed_fields_unknown',
				'source_state',
				'ambiguous',
				'contested_fields',
				'warnings',
			) as $key
		) {
			if ( isset( $verdict[ $key ] ) ) {
				$payload[ $key ] = $verdict[ $key ];
			}
		}

		self::json_error(
			(string) $verdict['error_code'],
			self::provenance_message( (string) $verdict['error_code'] ),
			(int) $verdict['status'],
			$payload
		);
	}

	/**
	 * Το baseline του picker, αν δηλώθηκε. Ο QRRP_Provenance το δέχεται μόνο
	 * αν αποδεικνύεται μέλος του admissible set.
	 *
	 * @return array|null
	 */
	private static function posted_baseline_fields() {
		$out = array();

		foreach ( array( 'PC', 'SN', 'LOT', 'EXP' ) as $label ) {
			$value = self::post_handle( 'baseline_' . strtolower( $label ) );

			if ( '' !== $value ) {
				$out[ $label ] = $value;
			}
		}

		return array() === $out ? null : $out;
	}

	/**
	 * Επαληθεύει ότι ένα validated_output handle ανήκει ακριβώς σε αυτή την
	 * canonical tuple και σε αυτό το raw. Provenance/source_state έρχονται μόνο
	 * από το payload του server. Δεν καταναλώνει το proof.
	 *
	 * @return array|false Το payload του server ή false.
	 */
	private static function verified_validated_output( array $fields, $raw, $handle ) {
		$handle = is_scalar( $handle ) ? (string) $handle : '';

		if ( '' === $handle ) {
			return false;
		}

		$signature = QRRP_GS1_Parser::canonical_reading_signature( $fields );

		$tuple_fp = qrrp_fingerprint(
			'validated_output_tuple',
			$signature,
			1
		);

		$raw_fp = qrrp_fingerprint(
			'validated_output_raw',
			(string) $raw,
			1
		);

		if (
			! is_string( $tuple_fp ) || '' === $tuple_fp
			|| ! is_string( $raw_fp ) || '' === $raw_fp
		) {
			return false;
		}

		$payload = QRRP_Tokens::verify_token_for_request(
			$handle,
			'validated_output',
			array(
				'tuple_fp' => $tuple_fp,
				'raw_fp'   => $raw_fp,
			)
		);

		return is_array( $payload ) ? $payload : false;
	}

	/**
	 * Εκδίδει βραχύβιο proof ότι αυτό ακριβώς το output παρήχθη επιτυχώς.
	 *
	 * Μόνο πάνω σε αποδεδειγμένη provenance (ποτέ σε compatibility exception).
	 * Αποθηκεύονται μόνο keyed fingerprints της tuple και του raw. Αποτυχία
	 * έκδοσης δεν ακυρώνει ετικέτα που ήδη παρήχθη.
	 *
	 * @return string Το handle, ή '' αν δεν εκδόθηκε.
	 */
	private static function issue_validated_output_proof( array $fields, $raw, array $verdict ) {
		if (
			! empty( $verdict['compatibility_exception'] )
			|| empty( $verdict['provenance'] )
		) {
			return '';
		}

		$signature = QRRP_GS1_Parser::canonical_reading_signature( $fields );

		$tuple_fp = qrrp_fingerprint(
			'validated_output_tuple',
			$signature,
			1
		);

		$raw_fp = qrrp_fingerprint(
			'validated_output_raw',
			(string) $raw,
			1
		);

		if (
			! is_string( $tuple_fp ) || '' === $tuple_fp
			|| ! is_string( $raw_fp ) || '' === $raw_fp
		) {
			return '';
		}

		$payload = array(
			'tuple_fp'               => $tuple_fp,
			'raw_fp'                 => $raw_fp,
			'provenance'             => (string) $verdict['provenance'],
			'source_state'           => isset( $verdict['source_state'] ) ? $verdict['source_state'] : null,
			'changed_fields'         => isset( $verdict['changed_fields'] ) && is_array( $verdict['changed_fields'] )
				? $verdict['changed_fields']
				: array(),
			'changed_fields_unknown' => ! empty( $verdict['changed_fields_unknown'] ),
		);

		try {
			return QRRP_Tokens::issue(
				'validated_output',
				$payload,
				self::VALIDATED_OUTPUT_TTL
			);
		} catch ( \Throwable $qrrp_validated_output_failed ) {
			self::report_unexpected_failure(
				'validated_output',
				$qrrp_validated_output_failed
			);

			return '';
		}
	}

	/**
	 * Τα πεδία provenance της επιτυχούς απάντησης, μόνο για εμφάνιση: ο server
	 * δεν διαβάζει ποτέ provenance από αίτημα.
	 *
	 * @return array
	 */
	private static function provenance_metadata( array $verdict ) {
		$out = array();

		foreach ( array( 'provenance', 'source_state', 'changed_fields', 'changed_fields_unknown', 'compatibility_exception' ) as $key ) {
			if ( isset( $verdict[ $key ] ) && null !== $verdict[ $key ] ) {
				$out[ $key ] = $verdict[ $key ];
			}
		}

		/*
		 * 2.15.3: για επισκέπτη η «σάρωση» είναι μόνο δήλωση: ο server δεν
		 * ξέρει αν τα bytes ήρθαν από σαρωτή, επικόλληση ή script. Προς τα έξω
		 * (JS, email, εκτύπωση) δηλώνεται ως user_declared· η εσωτερική
		 * διαδρομή (source_method) μένει για διάγνωση.
		 */
		if (
			! is_user_logged_in()
			&& isset( $out['provenance'] )
			&& in_array( $out['provenance'], array( QRRP_Provenance::SCAN, QRRP_Provenance::SCAN_UNVERIFIED ), true )
		) {
			$out['source_method'] = $out['provenance'];
			$out['provenance']    = 'user_declared';
		}

		return $out;
	}

	/**
	 * Τα αποδεδειγμένα extras (AI πέρα από PC/SN/LOT/EXP) που πρέπει να
	 * ξαναμπούν στο σύμβολο. Η άγκυρα επιλέγεται από τον τύπο provenance:
	 *
	 *   scan                  → source_raw + υποβληθείσα tuple
	 *   manual_reconstruction → source_raw + verified_baseline
	 *   email_token (χωρίς source_raw) → extras του επαληθευμένου token
	 *   οτιδήποτε άλλο        → κανένα
	 *
	 * Τίποτα από εδώ δεν διαβάζεται από τον client· το source_raw ξαναδιαβάζεται
	 * μόνο αφού η enforce_provenance() απέδειξε ότι στηρίζει αυτά τα πεδία.
	 *
	 * @return array
	 */
	private static function derive_passthrough( array $fields, array $verdict ) {
		if ( empty( $verdict['allowed'] ) ) {
			return array();
		}

		$provenance = isset( $verdict['provenance'] ) ? (string) $verdict['provenance'] : '';
		$source_raw = self::post_scalar( 'source_raw' );

		if ( '' === $source_raw ) {
			/* Email token (ή manual reconstruction με γονικό email token): τα extras ανήκουν στο token. */
			if ( ! in_array( $provenance, array( 'email_token', 'manual_reconstruction' ), true ) ) {
				return array();
			}

			$token = isset( $verdict['consume_token'] ) && is_scalar( $verdict['consume_token'] )
				? (string) $verdict['consume_token']
				: '';

			if ( '' === $token || ! class_exists( 'QRRP_Tokens' ) ) {
				return array();
			}

			$payload = self::guarded(
				'token_passthrough',
				static fn() => QRRP_Tokens::verify_token_for_request( $token, 'email_rebuild' )
			);

			return is_array( $payload ) && isset( $payload['extras'] ) && is_array( $payload['extras'] )
				? $payload['extras']
				: array();
		}

		if ( ! method_exists( 'QRRP_GS1_Parser', 'passthrough_for_reading' ) ) {
			return array();
		}

		if ( 'scan' === $provenance ) {
			$anchor = $fields;
		} elseif ( 'manual_reconstruction' === $provenance && is_array( $verdict['verified_baseline'] ) ) {
			$anchor = $verdict['verified_baseline'];
		} else {
			return array();
		}

		$out = self::guarded(
			'passthrough',
			static fn() => QRRP_GS1_Parser::passthrough_for_reading( $source_raw, $anchor )
		);

		if ( ! empty( $out['proven'] ) ) {
			return ! empty( $out['extras'] ) ? $out['extras'] : array();
		}

		/*
		 * Η σάρωση έχει πρόσθετα πεδία που δεν αποδεικνύονται (ή η αναζήτηση
		 * κόπηκε): fail closed. Ένα σύμβολο χωρίς πεδίο της συσκευασίας μοιάζει
		 * έγκυρο και είναι λάθος ετικέτα. Χωρίς ένδειξη extras ισχύει το παλιό.
		 */
		$unproven = isset( $out['unproven_ais'] ) && is_array( $out['unproven_ais'] ) ? $out['unproven_ais'] : array();

		if ( array() !== $unproven || ! empty( $out['truncated'] ) ) {
			self::extras_unprovable_error( $unproven );
		}

		return array();
	}

	/**
	 * 409: η αρχική σάρωση έχει πρόσθετα AI που δεν μεταφέρονται με ασφάλεια.
	 *
	 * @param string[] $ais Τα AI που διακυβεύονται (μπορεί να είναι κενό).
	 */
	private static function extras_unprovable_error( array $ais ) {
		QRRP_Stats::record_rejection();

		$ais = array_values(
			array_filter(
				array_map( 'strval', $ais ),
				static fn( $ai ) => 1 === preg_match( '/^[0-9]{2,4}$/', $ai )
			)
		);

		$message = array() === $ais
			? __( 'Ο κωδικός περιέχει πρόσθετα πεδία που δεν μπορούν να μεταφερθούν με ασφάλεια στον νέο κωδικό. Σαρώστε ξανά τη συσκευασία.', 'qr-rebuilder-pro' )
			: sprintf(
				/* translators: %s: λίστα GS1 Application Identifiers, π.χ. «AI 240, AI 403». */
				__( 'Ο κωδικός περιέχει πρόσθετα πεδία (%s) που δεν μπορούν να μεταφερθούν με ασφάλεια στον νέο κωδικό. Σαρώστε ξανά τη συσκευασία.', 'qr-rebuilder-pro' ),
				implode( ', ', array_map( static fn( $ai ) => 'AI ' . $ai, $ais ) )
			);

		self::json_error( 'extras_unprovable', $message, 409, array( 'unproven_ais' => $ais ) );
	}

	/** Μηνύματα προς τον χρήστη για τους κωδικούς του QRRP_Provenance. */
	private static function provenance_message( $code ) {
		$messages = array(
			'source_raw_required'      => __( 'Λείπει η αρχική σάρωση. Σαρώστε ξανά τη συσκευασία.', 'qr-rebuilder-pro' ),
			'rebuild_token_invalid'    => __( 'Ο σύνδεσμος του email έληξε ή έχει ήδη χρησιμοποιηθεί. Σαρώστε ξανά τη συσκευασία.', 'qr-rebuilder-pro' ),
			'source_raw_unparseable'   => __( 'Η σάρωση δεν διαβάστηκε. Σαρώστε ξανά τη συσκευασία.', 'qr-rebuilder-pro' ),
			'scan_unverified_required' => __( 'Η σάρωση δεν μπόρεσε να επαληθευτεί πλήρως. Ελέγξτε τα στοιχεία στη συσκευασία και επιβεβαιώστε.', 'qr-rebuilder-pro' ),
			'manual_override_required' => __( 'Οι τιμές που ζητάτε να κωδικοποιηθούν δεν προκύπτουν από τη σάρωση. Επιβεβαιώστε τις από το HRI της συσκευασίας.', 'qr-rebuilder-pro' ),
			'manual_entry_confirmation_required' => __( 'Δημιουργία χωρίς σάρωση: επιβεβαιώστε ότι τα PC, SN, LOT και EXP τα διαβάσατε από την ίδια τη συσκευασία.', 'qr-rebuilder-pro' ),
			'ambiguity_not_confirmed'  => __( 'Η ανάγνωση δεν είναι μονοσήμαντη. Ελέγξτε τη συσκευασία και επιβεβαιώστε.', 'qr-rebuilder-pro' ),
			'manual_edit_not_allowed'  => __( 'Η αλλαγή τιμών που δεν προκύπτουν από τη σάρωση επιτρέπεται μόνο σε συνδεδεμένους χρήστες.', 'qr-rebuilder-pro' ),
		);

		return isset( $messages[ $code ] )
			? $messages[ $code ]
			: __( 'Δεν ήταν δυνατή η επαλήθευση της προέλευσης των στοιχείων.', 'qr-rebuilder-pro' );
	}

	/**
	 * Server-side DataMatrix από το authoritative raw.
	 *
	 * @return array{png:string, geometry:array} Όχι σκέτο string: ο καλών διαβάζει το ['png'].
	 */
	private static function generate_datamatrix_png_base64( $raw ) {
		if ( ! class_exists( 'QRRP_DataMatrix' ) || ! is_callable( array( 'QRRP_DataMatrix', 'png_base64_with_geometry_from_raw' ) ) ) {
			self::dependency_error();
		}

		$image = self::guarded( 'datamatrix', static fn() => QRRP_DataMatrix::png_base64_with_geometry_from_raw( $raw ) );

		if ( is_wp_error( $image ) ) {
			self::json_error( 'datamatrix_failed', $image->get_error_message(), 500 );
		}

		return is_array( $image ) ? $image : array( 'png' => '', 'geometry' => array() );
	}

	/**
	 * Μέγεθος συμβόλου και X-dimension για το τρέχον πλάτος εκτύπωσης.
	 *
	 * Το X-dimension διαιρεί με τα modules της εικόνας (μαζί με το quiet zone),
	 * γιατί το CSS δίνει τα χιλιοστά στην εικόνα. Είναι ενημερωτικό: κάτω από
	 * το ελάχιστο GS1 επιστρέφεται print_warning, όχι απόρριψη. Σε έγκυρη
	 * geometry το print_warning υπάρχει πάντα ('' όταν όλα είναι εντός).
	 *
	 * @param mixed $geometry Γεωμετρία από τον QRRP_DataMatrix.
	 * @return array
	 */
	private static function print_geometry( $geometry ) {
		if ( ! is_array( $geometry ) || empty( $geometry['total_width_modules'] ) ) {
			return array();
		}

		$modules  = (int) $geometry['total_width_modules'];
		$width_mm = qrrp_print_barcode_mm();
		$x        = qrrp_x_dimension_mm( $width_mm, $modules );
		$minimum  = qrrp_gs1_minimum_x_dimension_mm();

		$below = ( null !== $x && $x < $minimum );

		return array(
			'symbol_rows'            => (int) $geometry['symbol_rows'],
			'symbol_cols'            => (int) $geometry['symbol_cols'],
			'quiet_zone'             => (int) $geometry['quiet_zone'],
			'total_width_modules'    => $modules,
			'print_width_mm'         => (int) $width_mm,
			'x_dimension_mm'         => ( null === $x ) ? null : round( $x, 4 ),
			'print_warning'          => $below ? 'x_dimension_below_gs1_minimum' : '',
			'minimum_x_dimension_mm' => $minimum,
			'minimum_print_width_mm' => qrrp_gs1_minimum_print_width_mm( $modules ),
		);
	}

	/**
	 * Στέλνει την ετικέτα με email. Ο Mailer ξαναφτιάχνει την εικόνα στον server
	 * από το επικυρωμένο raw· ο browser δεν ανεβάζει PNG.
	 *
	 * Η προέλευση αποδεικνύεται με έναν από δύο τρόπους:
	 *   - validated_output proof από το προηγούμενο rebuild (δεμένο σε tuple και
	 *     raw). Άκυρο proof απορρίπτεται χωρίς fallback σε χαλαρότερη διαδρομή.
	 *   - χωρίς proof: enforce_provenance() → extras → πλήρες χτίσιμο → ακριβής
	 *     σύγκριση με το υποβληθέν raw.
	 *
	 * Το συνολικό όριο email μετρά μόνο αιτήματα που πέρασαν όλους τους ελέγχους.
	 */
	public static function send_email() {
		self::check_permissions();
		self::check_email_permissions();

		/*
		 * 2.15.3: με εξαντλημένο το συνολικό ταβάνι επισκεπτών δεν γράφεται νέα
		 * γραμμή ανά IP. Το ίδιο το ταβάνι μετρά παρακάτω (enforce_email_quota()).
		 */
		if ( ! is_user_logged_in() && ! QRRP_Rate_Limiter::has_capacity( 'guest_email_global', self::guest_email_global_limit(), 15 * MINUTE_IN_SECONDS ) ) {
			self::rate_limit_error();
		}

		self::enforce_rate_limit( 'send_email', 5, 15 * MINUTE_IN_SECONDS );

		$to = sanitize_email( trim( self::post_scalar( 'email' ) ) );

		if ( '' === $to || ! is_email( $to ) ) {
			self::json_error( 'invalid_email', __( 'Μη έγκυρη διεύθυνση email.', 'qr-rebuilder-pro' ), 400 );
		}

		/*
		 * 2.15.3: επισκέπτες στέλνουν μόνο σε domains της λίστας των ρυθμίσεων
		 * (κενή λίστα = κανένα), και λίγα email ανά παραλήπτη. Νωρίς, πριν από
		 * κάθε βαριά δουλειά. Ο Mailer ξαναελέγχει με το ίδιο φίλτρο.
		 */
		if ( ! is_user_logged_in() && ! qrrp_guest_email_recipient_allowed( $to ) ) {
			self::json_error(
				'recipient_not_allowed',
				__( 'Η αποστολή σε αυτή τη διεύθυνση δεν επιτρέπεται για επισκέπτες.', 'qr-rebuilder-pro' ),
				403
			);
		}

		$fields        = self::posted_gs1_fields();
		$submitted_raw = self::post_scalar( 'raw' );

		if ( '' === $submitted_raw ) {
			self::json_error(
				'missing_raw',
				__( 'Λείπουν τα επικυρωμένα δεδομένα του QR. Δημιουργήστε ξανά τον κωδικό.', 'qr-rebuilder-pro' ),
				400
			);
		}

		if ( strlen( $submitted_raw ) > qrrp_max_raw_bytes() ) {
			self::json_error(
				'raw_too_large',
				__( 'Τα επικυρωμένα δεδομένα του QR υπερβαίνουν το επιτρεπτό μέγεθος.', 'qr-rebuilder-pro' ),
				413
			);
		}

		/* Εδώ μόνο επικύρωση πεδίων (400)· η ταύτιση με το raw γίνεται παρακάτω, ανά διαδρομή. */
		self::require_parser_method( 'validate_and_build' );
		$rebuilt = self::guarded( 'validate_and_build', static fn() => QRRP_GS1_Parser::validate_and_build( $fields ) );

		if ( ! empty( $rebuilt['errors'] ) ) {
			self::json_error(
				'invalid_fields',
				implode( ' ', $rebuilt['errors'] ),
				400,
				array( 'errors' => $rebuilt['errors'] )
			);
		}

		if ( ! isset( $rebuilt['raw'] ) || ! is_string( $rebuilt['raw'] ) || '' === $rebuilt['raw'] ) {
			self::json_error(
				'raw_mismatch',
				__( 'Τα στοιχεία δεν συμφωνούν με τον δημιουργημένο QR. Δημιουργήστε ξανά τον κωδικό και δοκιμάστε πάλι.', 'qr-rebuilder-pro' ),
				409
			);
		}

		$mail_extras = array();

		$validated_output = self::post_handle( 'validated_output' );

		if ( '' !== $validated_output ) {
			$verified_output = self::verified_validated_output(
				$fields,
				$submitted_raw,
				$validated_output
			);

			if ( false === $verified_output ) {
				QRRP_Stats::record_rejection();

				self::json_error(
					'validated_output_invalid',
					__(
						'Η επαλήθευση του ήδη δημιουργημένου κωδικού έληξε ή δεν αντιστοιχεί στα τρέχοντα στοιχεία. Δημιουργήστε ξανά τον κωδικό.',
						'qr-rebuilder-pro'
					),
					409
				);
			}

			/*
			 * Verdict με το ίδιο schema με τον QRRP_Provenance. Δεν υπάρχει άγκυρα
			 * πηγής (verified_baseline = null). Το proof ΔΕΝ καταναλώνεται: επιτρέπει
			 * αποστολή της ίδιας ετικέτας σε περισσότερους παραλήπτες για 15 λεπτά,
			 * πάντα μέσα στα όρια email (το JS το χρειάζεται για κάθε αποστολή).
			 */
			$verdict = array(
				'allowed'                 => true,
				'provenance'              => isset( $verified_output['provenance'] )
					? $verified_output['provenance']
					: null,
				'source_state'            => isset( $verified_output['source_state'] )
					? $verified_output['source_state']
					: null,
				'changed_fields'          => isset( $verified_output['changed_fields'] )
					&& is_array( $verified_output['changed_fields'] )
						? $verified_output['changed_fields']
						: array(),
				'changed_fields_unknown'  => ! empty( $verified_output['changed_fields_unknown'] ),
				'error_code'              => null,
				'status'                  => 200,
				'compatibility_exception' => null,
				'verified_baseline'       => null,
				'consume_token'           => '',
				'consume_challenge'       => '',
				'consume_proof'           => '',
			);

			/*
			 * Τα extras διαβάζονται από το raw μόνο επειδή το proof μόλις απέδειξε
			 * ότι αυτό το raw το εξέδωσε ο server. Πριν την επαλήθευση θα ήταν
			 * αυτο-επαλήθευση.
			 */
			$proof_passthrough = self::guarded(
				'passthrough',
				static fn() => QRRP_GS1_Parser::passthrough_for_reading( $submitted_raw, $fields )
			);

			if ( empty( $proof_passthrough['proven'] ) ) {
				self::json_error(
					'passthrough_build_failed',
					__( 'Δεν ήταν δυνατή η επαλήθευση όλων των πεδίων του ήδη δημιουργημένου κωδικού.', 'qr-rebuilder-pro' ),
					500
				);
			}

			$mail_extras = ( ! empty( $proof_passthrough['extras'] ) && is_array( $proof_passthrough['extras'] ) )
				? $proof_passthrough['extras']
				: array();
		} else {
			$verdict = self::enforce_provenance( $fields );

			$passthrough = self::derive_passthrough( $fields, $verdict );

			if ( array() !== $passthrough ) {
				$full = self::guarded(
					'validate_and_build',
					static fn() => QRRP_GS1_Parser::validate_and_build( $fields, $passthrough )
				);

				/* Εσωτερική ασυνέπεια του server δεν παρουσιάζεται ως raw_mismatch του client. */
				if ( ! empty( $full['errors'] ) || empty( $full['raw'] ) ) {
					self::json_error(
						'passthrough_build_failed',
						__( 'Δεν ήταν δυνατή η αναδημιουργία του κωδικού με όλα τα πεδία της συσκευασίας.', 'qr-rebuilder-pro' ),
						500
					);
				}

				$rebuilt = $full;
			}

			$mail_extras = $passthrough;

			if ( ! hash_equals( $rebuilt['raw'], $submitted_raw ) ) {
				self::json_error(
					'raw_mismatch',
					__( 'Τα στοιχεία δεν συμφωνούν με τον δημιουργημένο QR. Δημιουργήστε ξανά τον κωδικό και δοκιμάστε πάλι.', 'qr-rebuilder-pro' ),
					409
				);
			}
		}

		self::require_expiry_acknowledgement( $fields );

		$customer_name = self::sanitize_limited_text( 'customer_name', 200 );

		/* 32 = QRRP_Mailer::MAX_PRINT_DATE_CHARS, ώστε ο Mailer να μην κόβει ξανά. */
		$print_date = self::sanitize_limited_text( 'print_date', 32 );

		if ( ! class_exists( 'QRRP_Mailer' ) || ! is_callable( array( 'QRRP_Mailer', 'send' ) ) ) {
			self::dependency_error();
		}

		self::enforce_email_quota();
		self::enforce_recipient_limit( $to );

		/*
		 * 2.15.2: τα single-use handles (email token, challenge) καίγονται ΠΡΙΝ από
		 * την αποστολή. Το email δεν ανακαλείται, οπότε από δύο ταυτόχρονα αιτήματα
		 * με το ίδιο handle μόνο αυτό που το καταναλώνει ατομικά στέλνει. Αντίτιμο:
		 * αν αποτύχει ο SMTP, το handle έχει χαθεί και ο χρήστης ξαναρχίζει. Η
		 * συνήθης διαδρομή (validated_output proof) δεν καταναλώνει τίποτα.
		 */
		$consumes_handles = ! empty( $verdict['consume_token'] ) || ! empty( $verdict['consume_challenge'] );

		if ( ! self::consume_verified_handles( $verdict ) ) {
			self::json_error(
				'token_already_used',
				__( 'Ο σύνδεσμος ή η επιβεβαίωση χρησιμοποιήθηκε ήδη. Ξαναδοκίμασε από την αρχή.', 'qr-rebuilder-pro' ),
				409
			);
		}

		$page_url = self::post_scalar( 'page_url' );
		$result   = self::guarded(
			'send_email',
			static fn() => QRRP_Mailer::send( $to, $fields, $customer_name, $print_date, $submitted_raw, $page_url, $mail_extras, self::provenance_metadata( $verdict ) )
		);

		if ( is_wp_error( $result ) ) {
			$message = $result->get_error_message();

			if ( $consumes_handles ) {
				$message = trim(
					$message . ' ' . __( 'Ο σύνδεσμος ή η επιβεβαίωση έχει πια χρησιμοποιηθεί: ξεκίνησε ξανά από τη σάρωση του κωδικού και μετά στείλε το email.', 'qr-rebuilder-pro' )
				);
			}

			self::json_error( 'mail_send_failed', $message, 500 );
		}

		self::json_success( array( 'sent' => true ) + self::provenance_metadata( $verdict ) );
	}

	/**
	 * Ακριβής scalar τιμή του POST, μόνο με wp_unslash().
	 *
	 * Χωρίς sanitization, σκόπιμα: τα GS1 strings πρέπει να μείνουν byte-exact
	 * (το sanitize_text_field() σβήνει το GS και συμπτύσσει κενά). Κάθε τιμή
	 * επικυρώνεται από τον QRRP_GS1_Parser πριν χρησιμοποιηθεί και γίνεται
	 * escape στην έξοδο. Nonce και capability έχουν ελεγχθεί ήδη.
	 *
	 * @param string $key POST key.
	 * @return string
	 */
	private static function post_scalar( $key ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in check_permissions() before any handler reads input.
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- GS1 payloads must stay byte-exact; validated by QRRP_GS1_Parser::validate_and_build().
		if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) {
			return '';
		}

		return (string) wp_unslash( $_POST[ $key ] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	/**
	 * Όπως η post_scalar(), για tokens και baseline τιμές με φραγμένο μήκος.
	 * Υπερμεγέθης τιμή απορρίπτεται με 413 πριν φτάσει στον QRRP_Provenance.
	 *
	 * @param string $key POST key.
	 * @return string
	 */
	private static function post_handle( $key ) {
		$value = self::post_scalar( $key );

		if ( strlen( $value ) > self::MAX_HANDLE_BYTES ) {
			self::json_error(
				'input_too_large',
				__( 'Ένα από τα δεδομένα του αιτήματος υπερβαίνει το επιτρεπτό μέγεθος.', 'qr-rebuilder-pro' ),
				413
			);
		}

		return $value;
	}

	private static function posted_gs1_fields() {
		return array(
			'PC'  => self::post_scalar( 'pc' ),
			'SN'  => self::post_scalar( 'sn' ),
			'LOT' => self::post_scalar( 'lot' ),
			'EXP' => self::post_scalar( 'exp' ),
		);
	}

	/** Sanitize εδώ, κόψιμο στην QRRP_Text. Το όριο είναι σε χαρακτήρες, όχι bytes. */
	private static function sanitize_limited_text( $key, $max_length ) {
		return QRRP_Text::truncate( sanitize_text_field( self::post_scalar( $key ) ), $max_length );
	}

	/**
	 * Μετρά το αίτημα στον QRRP_Rate_Limiter και απαντά 429 αν ξεπεράστηκε το όριο.
	 *
	 * @param string $action Λογικό όνομα ενέργειας ('parse', 'rebuild', …).
	 * @param int    $limit  Πόσα αιτήματα επιτρέπονται μέσα στο παράθυρο.
	 * @param int    $window Διάρκεια παραθύρου σε δευτερόλεπτα.
	 * @param string $scope  'actor' ή 'global'.
	 */
	private static function enforce_rate_limit( $action, $limit, $window, $scope = 'actor' ) {
		if ( ! class_exists( 'QRRP_Rate_Limiter' ) ) {
			self::dependency_error();
		}

		if ( ! QRRP_Rate_Limiter::hit( $action, $limit, $window, $scope ) ) {
			self::rate_limit_error();
		}
	}

	/**
	 * Όριο ανά actor και, για επισκέπτες, συνολικό ταβάνι (ανά 10 λεπτά).
	 *
	 * Το όριο ανά IP παρακάμπτεται με πολλές διευθύνσεις· το συνολικό όχι. Οι
	 * συνδεδεμένοι χρήστες δεν μετρούν στο συνολικό, ώστε μια επίθεση
	 * επισκεπτών να μη σταματά το προσωπικό. 2.15.3: το συνολικό ταβάνι
	 * ελέγχεται (χωρίς μέτρηση) πριν από το όριο ανά IP, ώστε με εξαντλημένο
	 * ταβάνι να μη γράφονται νέες γραμμές ανά IP / IPv6 /64. Φίλτρα:
	 * qrrp_guest_{action}_global_limit (ελάχιστο 10).
	 *
	 * @param string $action               'parse', 'rebuild', 'refresh_nonce'.
	 * @param int    $actor_limit          Όριο ανά actor ανά 10 λεπτά.
	 * @param int    $guest_global_default Προεπιλεγμένο συνολικό ταβάνι επισκεπτών.
	 */
	private static function enforce_request_limits( $action, $actor_limit, $guest_global_default ) {
		$window = 10 * MINUTE_IN_SECONDS;

		if ( is_user_logged_in() ) {
			self::enforce_rate_limit( $action, $actor_limit, $window );

			return;
		}

		$limit = (int) apply_filters( 'qrrp_guest_' . $action . '_global_limit', $guest_global_default );
		$limit = max( 10, $limit );

		if ( ! class_exists( 'QRRP_Rate_Limiter' ) ) {
			self::dependency_error();
		}

		if ( ! QRRP_Rate_Limiter::has_capacity( 'guest_' . $action . '_global', $limit, $window ) ) {
			self::rate_limit_error();
		}

		self::enforce_rate_limit( $action, $actor_limit, $window );
		self::enforce_rate_limit( 'guest_' . $action . '_global', $limit, $window, 'global' );
	}

	/**
	 * Όριο ανά παραλήπτη για επισκέπτες (2.15.3). 2.15.4: προεπιλογή 3 ανά 24
	 * ώρες. Φίλτρα: qrrp_guest_email_per_recipient_limit (ελάχιστο 1) και
	 * qrrp_guest_email_per_recipient_window (δευτερόλεπτα, 1 ώρα έως 24 ώρες).
	 * Κλειδί είναι hash της κανονικοποιημένης διεύθυνσης, όχι η ίδια.
	 */
	private static function enforce_recipient_limit( $to ) {
		if ( is_user_logged_in() ) {
			self::enforce_authenticated_email_limits( $to );

			return;
		}

		$limit  = max( 1, (int) apply_filters( 'qrrp_guest_email_per_recipient_limit', 3 ) );
		$window = (int) apply_filters( 'qrrp_guest_email_per_recipient_window', DAY_IN_SECONDS );
		$window = max( HOUR_IN_SECONDS, min( DAY_IN_SECONDS, $window ) );

		if ( ! QRRP_Rate_Limiter::hit_subject( 'guest_email_recipient', qrrp_normalize_email_for_limit( $to ), $limit, $window ) ) {
			self::json_error(
				'recipient_rate_limited',
				__( 'Στάλθηκαν ήδη αρκετά email σε αυτή τη διεύθυνση σήμερα. Δοκιμάστε ξανά αργότερα.', 'qr-rebuilder-pro' ),
				429
			);
		}
	}

	/**
	 * 2.15.5: όρια email για συνδεδεμένους χρήστες. Η ιδιότητα «φαρμακοποιός»
	 * μπορεί να είναι αυτο-δηλωμένη, άρα ένας λογαριασμός με δικαίωμα email δεν
	 * είναι απόδειξη καλής πίστης. Δύο φράγματα κατάχρησης ανά χρήστη, όχι όρια
	 * χρήσης (παράθυρο 24 ωρών):
	 *
	 * - ημερήσιο (qrrp_user_email_daily_limit()), ώστε ένας λογαριασμός να μη
	 *   στέλνει ~480 email/ημέρα·
	 * - ανά αποστολέα και παραλήπτη (qrrp_user_email_per_recipient_limit()),
	 *   ώστε ένας λογαριασμός να μη βομβαρδίζει μία διεύθυνση. Ο μετρητής είναι
	 *   ανά ζεύγος και όχι κοινός: αλλιώς ένας χρήστης θα μπορούσε να
	 *   «κλειδώσει» μια διεύθυνση (π.χ. το inbox του φαρμακείου) για όλους.
	 *   Πολλοί λογαριασμοί μαζί περιορίζονται από το συνολικό ταβάνι
	 *   (enforce_email_quota()).
	 *
	 * Ο έλεγχος ανά παραλήπτη γίνεται πρώτος, ώστε μια άρνησή του να μη χρεώνει
	 * το ημερήσιο όριο. Οι διαχειριστές εξαιρούνται (qrrp_user_email_limits_exempt()).
	 * Κλειδί = HMAC του user id και της κανονικοποιημένης διεύθυνσης.
	 *
	 * @param string $to Επικυρωμένη διεύθυνση παραλήπτη.
	 */
	private static function enforce_authenticated_email_limits( $to ) {
		if ( qrrp_user_email_limits_exempt() ) {
			return;
		}

		$subject = get_current_user_id() . '|' . qrrp_normalize_email_for_limit( $to );

		if ( ! QRRP_Rate_Limiter::hit_subject( 'user_email_recipient', $subject, qrrp_user_email_per_recipient_limit(), DAY_IN_SECONDS ) ) {
			self::json_error(
				'recipient_rate_limited',
				__( 'Στάλθηκαν ήδη αρκετά email σε αυτή τη διεύθυνση σήμερα. Δοκιμάστε ξανά αργότερα.', 'qr-rebuilder-pro' ),
				429
			);
		}

		if ( ! QRRP_Rate_Limiter::hit( 'send_email_daily', qrrp_user_email_daily_limit(), DAY_IN_SECONDS ) ) {
			self::json_error(
				'daily_email_limit',
				__( 'Φτάσατε το ημερήσιο όριο αποστολής email. Δοκιμάστε ξανά αύριο.', 'qr-rebuilder-pro' ),
				429
			);
		}
	}

	private static function guest_email_global_limit() {
		return max( 5, (int) apply_filters( 'qrrp_guest_email_global_limit', 50 ) );
	}

	/**
	 * Συνολικό ταβάνι email για όλο το site, πέρα από το 5/15λεπτο ανά χρήστη.
	 *
	 * Χωρίς αυτό, πολλοί λογαριασμοί (π.χ. Subscribers όταν το email capability
	 * είναι ρητά «ίδιο με το εργαλείο») θα είχαν ανεξάρτητους κουβάδες και το
	 * site θα γινόταν όχημα εξερχόμενης αλληλογραφίας. Επισκέπτες και
	 * συνδεδεμένοι έχουν ξεχωριστούς μετρητές, ώστε επίθεση επισκεπτών να μη
	 * σταματά το προσωπικό. Το όριο των συνδεδεμένων είναι φράγμα κατάχρησης,
	 * όχι όριο χρήσης. Φίλτρα: qrrp_guest_email_global_limit,
	 * qrrp_authenticated_email_global_limit.
	 */
	private static function enforce_email_quota() {
		if ( ! is_user_logged_in() ) {
			self::enforce_rate_limit( 'guest_email_global', self::guest_email_global_limit(), 15 * MINUTE_IN_SECONDS, 'global' );

			return;
		}

		$limit = (int) apply_filters( 'qrrp_authenticated_email_global_limit', 200 );
		$limit = max( 20, $limit );

		self::enforce_rate_limit( 'authenticated_email_global', $limit, 15 * MINUTE_IN_SECONDS, 'global' );
	}

	private static function rate_limit_error() {
		self::json_error(
			'rate_limit_exceeded',
			__( 'Έγιναν πάρα πολλά αιτήματα. Δοκιμάστε ξανά αργότερα.', 'qr-rebuilder-pro' ),
			429
		);
	}

	private static function require_parser_method( $method ) {
		if ( ! class_exists( 'QRRP_GS1_Parser' ) || ! is_callable( array( 'QRRP_GS1_Parser', $method ) ) ) {
			self::dependency_error();
		}
	}

	/**
	 * Τηλεμετρία που δεν μπορεί να αλλάξει την απάντηση: το do_action() τρέχει
	 * κώδικα τρίτων και τυλίγεται, ώστε ένα σπασμένο callback να μη γίνει γυμνό
	 * 500. Εκπέμπεται στάδιο + κλάση, ποτέ το μήνυμα (μπορεί να έχει GS1 ή email).
	 *
	 * @param string     $stage     Σταθερό αναγνωριστικό σταδίου.
	 * @param \Throwable $throwable Τι πετάχτηκε.
	 */
	private static function report_unexpected_failure( $stage, \Throwable $throwable ) {
		try {
			do_action( 'qrrp_unexpected_failure', $stage, get_class( $throwable ) );
		} catch ( \Throwable $qrrp_telemetry_failed ) {
			unset( $qrrp_telemetry_failed );
		}
	}

	/**
	 * Απρόσμενο Throwable γίνεται JSON 500 αντί για γυμνό σφάλμα.
	 *
	 * Τυλίγει μόνο την εργασία, ποτέ την εκπομπή της απάντησης: στα tests οι
	 * wp_send_json_* πετούν εξαίρεση τερματισμού, και ένα try γύρω από όλο τον
	 * handler θα την κατάπινε.
	 *
	 * @param string   $stage Σταθερό αναγνωριστικό σταδίου.
	 * @param callable $work  Η εργασία.
	 * @return mixed
	 */
	private static function guarded( $stage, callable $work ) {
		try {
			return $work();
		} catch ( \Throwable $qrrp_unexpected ) {
			self::report_unexpected_failure( $stage, $qrrp_unexpected );

			self::json_error(
				'server_error',
				__( 'Παρουσιάστηκε απρόσμενο σφάλμα. Δοκιμάστε ξανά.', 'qr-rebuilder-pro' ),
				500
			);
		}
	}

	private static function dependency_error() {
		self::json_error(
			'dependency_unavailable',
			__( 'Μια απαραίτητη λειτουργία του plugin δεν είναι διαθέσιμη.', 'qr-rebuilder-pro' ),
			500
		);
	}

	/**
	 * Η σημερινή ημερομηνία του server σε κάθε απάντηση, ώστε ο client να μην
	 * κρίνει τη λήξη με ημερομηνία από cached σελίδα. Ίδια συνάρτηση (wp_date)
	 * με την QRRP_GS1_Parser::date_is_in_past().
	 */
	private static function server_today() {
		return wp_date( 'Y-m-d' );
	}

	/** Προσθέτει τα κοινά πεδία κάθε απάντησης και στέλνει επιτυχία. */
	private static function json_success( array $data ) {
		$data['server_today'] = self::server_today();

		wp_send_json_success( $data );
	}

	private static function json_error( $code, $message, $status, $extra = array() ) {
		$data = array_merge(
			array(
				'code'    => $code,
				'message' => $message,
			),
			is_array( $extra ) ? $extra : array(),
			array( 'server_today' => self::server_today() )
		);

		wp_send_json_error( $data, $status );
	}
}

QRRP_Ajax::init();
