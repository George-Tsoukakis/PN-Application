<?php
/**
 * Πολιτική προέλευσης: πριν από κάθε αναδημιουργία αποφασίζει αν η tuple
 * PC/SN/LOT/EXP επιτρέπεται και με ποια ετικέτα provenance. Επιτρέπεται μόνο:
 *   (α) επιτρεπτή ερμηνεία του source_raw που υπέβαλε ο client (scan),
 *   (β) ακριβές ταίριασμα με έγκυρο email token (email_token), ή
 *   (γ) δηλωμένη ανθρώπινη ανακατασκευή με challenge (manual_reconstruction).
 *
 * Όρια: το source_raw το στέλνει ο client, οπότε scan σημαίνει «συνεπές με το
 * υποβληθέν source_raw», όχι αποδεδειγμένη φυσική σάρωση. Ένα challenge
 * αποδεικνύει round trip μέσω της διεπαφής — καταγράφει δήλωση του χρήστη,
 * όχι σύγκριση με τη συσκευασία. Κανένα client flag δεν διαβάζεται.
 *
 * @package QR_Rebuilder_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Φάση: '2.12.1' ανέχεται απουσία source_raw, '2.12.2' την απορρίπτει. */
if ( ! defined( 'QRRP_PROVENANCE_PHASE' ) ) {
	define( 'QRRP_PROVENANCE_PHASE', '2.12.2' );
}

final class QRRP_Provenance {

	/**
	 * Ετικέτες provenance που απονέμει ο server. SCAN = συνεπές με το
	 * source_raw του client (όχι απόδειξη φυσικής σάρωσης).
	 */
	const SCAN                  = 'scan';
	const SCAN_UNVERIFIED       = 'scan_unverified';
	const EMAIL_TOKEN           = 'email_token';
	const MANUAL_RECONSTRUCTION = 'manual_reconstruction';

	/** Καταστάσεις membership. */
	const MATCH    = 'MATCH';
	const NO_MATCH = 'NO_MATCH';
	const UNKNOWN  = 'UNKNOWN';

	/** Διάρκεια challenge, ανεξάρτητη από το qrrp_rebuild_token_ttl. */
	const CHALLENGE_TTL = 900;

	/**
	 * Η μόνη δημόσια είσοδος.
	 *
	 * @param array $request {
	 *     @type string $source_raw           Το raw της σάρωσης, όπως το έστειλε ο client.
	 *     @type array  $fields               PC/SN/LOT/EXP, κεφαλαία κλειδιά.
	 *     @type string $rebuild_token        Handle email token, αν υπάρχει.
	 *     @type string $challenge            Handle provenance challenge, αν υπάρχει.
	 *     @type bool   $acknowledged         Ο χρήστης επιβεβαίωσε την ασάφεια.
	 *     @type array  $baseline             Η ανάγνωση από την οποία ξεκίνησε, αν δηλώθηκε.
	 *     @type string $entry_mode           'manual' = εισαγωγή χωρίς σάρωση.
	 *     @type bool   $manual_entry_allowed Αν επιτρέπεται εισαγωγή χωρίς σάρωση (απόφαση server).
	 * }
	 * @return array Η ετυμηγορία· βλ. allow() / deny().
	 */
	public static function evaluate( array $request ) {
		$fields    = isset( $request['fields'] ) && is_array( $request['fields'] ) ? $request['fields'] : array();
		$submitted = QRRP_GS1_Parser::canonical_reading_signature( $fields );

		$raw           = isset( $request['source_raw'] ) && is_scalar( $request['source_raw'] ) ? (string) $request['source_raw'] : '';
		$rebuild_token = isset( $request['rebuild_token'] ) && is_scalar( $request['rebuild_token'] ) ? (string) $request['rebuild_token'] : '';
		$challenge     = isset( $request['challenge'] ) && is_scalar( $request['challenge'] ) ? (string) $request['challenge'] : '';
		$acknowledged  = ! empty( $request['acknowledged'] );

		/* (β) Email token: ακριβές ταίριασμα, ή baseline για ανακατασκευή. */
		$token_tuple = null;

		if ( '' !== $rebuild_token ) {
			$payload = QRRP_Tokens::verify_token_for_request( $rebuild_token, 'email_rebuild' );

			if ( is_array( $payload ) ) {
				$token_tuple = self::signature_from_payload( $payload );

				if ( $token_tuple === $submitted ) {
					/*
					 * Χωρίς source_raw εδώ: τα extras του email προέρχονται από το
					 * ίδιο το token (verified_baseline), όχι από νέα αναζήτηση.
					 */
					return self::allow(
						self::EMAIL_TOKEN,
						null,
						array(),
						false,
						$rebuild_token,
						'',
						self::explode_signature( $token_tuple )
					);
				}

				/* Επεξεργασμένο prefill → ανακατασκευή με baseline το token. */
			}
		}

		/* Χωρίς source_raw. */
		if ( '' === $raw ) {
			if ( null !== $token_tuple ) {
				return self::manual_branch( $submitted, $challenge, $token_tuple, null, $rebuild_token, '' );
			}

			/* Σύνδεσμος email που έληξε ή χρησιμοποιήθηκε ήδη: σαφές μήνυμα αντί για «λείπει η σάρωση». */
			if ( '' !== $rebuild_token ) {
				return self::deny( 'rebuild_token_invalid', 409 );
			}

			/*
			 * Εισαγωγή από το τυπωμένο HRI: μόνο όταν το επιτρέπει ο server και
			 * πάντα με challenge· το entry_mode μόνο επιλέγει την άρνηση.
			 */
			if (
				isset( $request['entry_mode'] ) && 'manual' === $request['entry_mode']
				&& ! empty( $request['manual_entry_allowed'] )
			) {
				return self::manual_entry_branch( $submitted, $challenge );
			}

			return self::missing_source_raw();
		}

		/* (α) Membership απέναντι στο υποβληθέν source_raw. */
		$admissible = QRRP_GS1_Parser::admissible_readings( $raw );
		$signatures = self::signatures_of( $admissible['readings'] );
		$truncated  = ! empty( $admissible['truncated'] );

		/* Μη αναγνώσιμη σάρωση: δικό της μήνυμα, όχι «αλλάζετε τιμές». */
		if ( array() === $signatures && ! $truncated ) {
			return self::deny( 'source_raw_unparseable', 409 );
		}

		$state = self::membership( $submitted, $signatures, $truncated );

		/* UNKNOWN δεν χρειάζεται parse(): γλιτώνει μια πλήρη αναζήτηση (2.15.2). */
		if ( self::UNKNOWN === $state ) {
			return self::unknown_branch( $submitted, $challenge, $raw );
		}

		/*
		 * admissible_readings() κρίνει membership· parse() κρίνει αν πρέπει να
		 * ρωτηθεί ο χρήστης. Καθαρή σάρωση μπορεί να έχει κι άλλη admissible
		 * ερμηνεία, οπότε η ασάφεια δεν κρίνεται από count( $signatures ).
		 */
		$parsed = QRRP_GS1_Parser::parse( $raw );

		if ( self::MATCH === $state ) {
			/*
			 * Επιβεβαίωση όταν ο parser τη ζητά ή το σύνολο κόπηκε· το 409
			 * μεταφέρει τις υποψήφιες τιμές για τον picker.
			 */
			if ( ( ! empty( $parsed['requires_confirmation'] ) || $truncated ) && ! $acknowledged ) {
				return self::deny(
					'ambiguity_not_confirmed',
					409,
					array(
						'ambiguous'        => ! empty( $parsed['ambiguous'] ) || $truncated,
						'contested_fields' => ( isset( $parsed['contested_fields'] ) && is_array( $parsed['contested_fields'] ) )
							? $parsed['contested_fields']
							: array(),
						'warnings'         => ( isset( $parsed['warnings'] ) && is_array( $parsed['warnings'] ) )
							? array_values( array_filter( $parsed['warnings'], 'is_string' ) )
							: array(),
					)
				);
			}

			/*
			 * 2.15.2: admissible δεν σημαίνει «χωρίς ερώτηση». Όταν ο parser έχει
			 * μία καθαρή ανάγνωση (π.χ. SN=ABC24012) και υποβάλλεται άλλη admissible
			 * (SN=ABC, που υποθέτει GS που λείπει), αυτό είναι αλλαγή πεδίου: πάει
			 * από ρητή ανακατασκευή με baseline την ανάγνωση του parser, ώστε ούτε
			 * σιωπηλό «scan» ούτε extras (π.χ. AI 240) από τη μαντεμένη ανάγνωση.
			 */
			if ( ! $truncated && self::deviates_from_clear_reading( $submitted, $signatures, $parsed ) ) {
				return self::manual_branch( $submitted, $challenge, $token_tuple, $signatures, $rebuild_token, $raw, $request, $parsed );
			}

			return self::allow( self::SCAN, self::MATCH, array(), false, $rebuild_token );
		}

		return self::manual_branch( $submitted, $challenge, $token_tuple, $signatures, $rebuild_token, $raw, $request, $parsed );
	}

	/* --- Membership --- */
	/* ------------------------------------------------------------------ */

	/**
	 * MATCH ισχύει και με truncated σύνολο. NO_MATCH απαιτεί πλήρες σύνολο·
	 * αλλιώς UNKNOWN, ώστε μια κομμένη αναζήτηση να μην καταγράφεται ως αλλαγή.
	 */
	private static function membership( $submitted, array $signatures, $truncated ) {
		if ( in_array( $submitted, $signatures, true ) ) {
			return self::MATCH;
		}

		return $truncated ? self::UNKNOWN : self::NO_MATCH;
	}

	/*
	 * Κλάδοι με challenge: ένα έγκυρο challenge για το ίδιο context επιτρέπει
	 * το αίτημα· νέο εκδίδεται μόνο όταν δεν παρουσιάστηκε έγκυρο.
	 */

	/** UNKNOWN: χωρίς changed_fields, αφού η tuple μπορεί να είναι σωστή. */
	private static function unknown_branch( $submitted, $challenge, $raw ) {
		$context = array(
			'challenge_kind' => self::SCAN_UNVERIFIED,
			'source_fp'      => self::fp( 'challenge_source', $raw ),
			'tuple_fp'       => self::fp( 'challenge_tuple', $submitted ),
		);

		if ( self::challenge_is_valid( $challenge, $context ) ) {
			return self::allow( self::SCAN_UNVERIFIED, self::UNKNOWN, array(), false, '', $challenge );
		}

		return self::deny(
			'scan_unverified_required',
			409,
			array( 'challenge' => self::issue_challenge( $context ) )
		);
	}

	/**
	 * NO_MATCH ή επεξεργασμένο prefill: ρητή ανακατασκευή, με challenge δεμένο
	 * σε tuple, σάρωση, baseline και parent token.
	 */
	private static function manual_branch( $submitted, $challenge, $token_tuple, $signatures, $rebuild_token, $raw, array $request = array(), array $parsed = array() ) {
		/*
		 * 2.15.3: κάθε ανακατασκευή (NO_MATCH, ή άλλη ανάγνωση από την καθαρή του
		 * parser) είναι χειροκίνητη δημιουργία με άλλο όνομα. Όπου αυτή δεν
		 * επιτρέπεται (επισκέπτες με κλειστή τη ρύθμιση), ούτε η ανακατασκευή.
		 * Επεξεργασμένο prefill email token έχει δική του άγκυρα και μένει.
		 */
		if (
			null === $token_tuple
			&& array_key_exists( 'manual_entry_allowed', $request )
			&& empty( $request['manual_entry_allowed'] )
		) {
			return self::deny( 'manual_edit_not_allowed', 403 );
		}

		$baseline = self::verified_baseline( $request, $token_tuple, $signatures, $parsed );

		$context = array(
			'challenge_kind' => self::MANUAL_RECONSTRUCTION,
			'tuple_fp'       => self::fp( 'challenge_tuple', $submitted ),
		);

		if ( '' !== $raw ) {
			$context['source_fp'] = self::fp( 'challenge_source', $raw );
		}

		if ( null !== $baseline ) {
			$context['baseline_fp'] = self::fp( 'challenge_baseline', $baseline );
		}

		if ( '' !== $rebuild_token ) {
			$context['parent_token_fp'] = self::fp( 'challenge_parent', $rebuild_token );
		}

		$diff = self::diff_from_baseline( $baseline, $submitted );

		if ( self::challenge_is_valid( $challenge, $context ) ) {
			/* Άγκυρα των extras το baseline· null = δεν υπάρχει baseline. */
			return self::allow(
				self::MANUAL_RECONSTRUCTION,
				'' === $raw ? null : self::NO_MATCH,
				$diff['changed_fields'],
				$diff['unknown'],
				$rebuild_token,
				$challenge,
				null === $baseline ? null : self::explode_signature( $baseline )
			);
		}

		return self::deny(
			'manual_override_required',
			409,
			array(
				'challenge'              => self::issue_challenge( $context ),
				'changed_fields'         => $diff['changed_fields'],
				'changed_fields_unknown' => $diff['unknown'],
			)
		);
	}

	/**
	 * Εισαγωγή από το μηδέν: changed_fields_unknown = true. Το 'entry' χωρίζει
	 * αυτά τα challenges από εκείνα με σάρωση.
	 */
	private static function manual_entry_branch( $submitted, $challenge ) {
		$context = array(
			'challenge_kind' => self::MANUAL_RECONSTRUCTION,
			'entry'          => 'manual_entry',
			'tuple_fp'       => self::fp( 'challenge_tuple', $submitted ),
		);

		if ( self::challenge_is_valid( $challenge, $context ) ) {
			return self::allow( self::MANUAL_RECONSTRUCTION, null, array(), true, '', $challenge );
		}

		return self::deny(
			'manual_entry_confirmation_required',
			409,
			array(
				'challenge'              => self::issue_challenge( $context ),
				'changed_fields'         => array(),
				'changed_fields_unknown' => true,
			)
		);
	}

	/* --- Baseline και diff --- */
	/* ------------------------------------------------------------------ */

	/**
	 * Baseline επαληθευμένο από τον server, κατά προτεραιότητα: tuple email
	 * token· baseline του client αν είναι admissible· επιλογή του parser αν δεν
	 * ζητά confirmation και είναι admissible. Αλλιώς null (όχι εικασία).
	 */
	private static function verified_baseline( array $request, $token_tuple, $signatures, array $parsed = array() ) {
		if ( null !== $token_tuple ) {
			return $token_tuple;
		}

		/*
		 * 2.15.2: η καθαρή επιλογή του parser προηγείται του baseline του client.
		 * Αλλιώς ένας client θα δήλωνε ως baseline μια μαντεμένη admissible
		 * ανάγνωση και θα έφερνε τα extras της. Το baseline του client μετρά μόνο
		 * όταν ο parser ζητά επιβεβαίωση (picker).
		 */
		$chosen = self::clear_reading_signature( $parsed );

		if ( null !== $chosen && is_array( $signatures ) && in_array( $chosen, $signatures, true ) ) {
			return $chosen;
		}

		if ( isset( $request['baseline'] ) && is_array( $request['baseline'] ) ) {
			$claimed = QRRP_GS1_Parser::canonical_reading_signature( $request['baseline'] );

			if ( is_array( $signatures ) && in_array( $claimed, $signatures, true ) ) {
				return $claimed;
			}
		}

		return null;
	}

	/**
	 * Η signature της ανάγνωσης του parser όταν δεν ζητά επιβεβαίωση, αλλιώς null.
	 *
	 * @param array $parsed Αποτέλεσμα QRRP_GS1_Parser::parse().
	 * @return string|null
	 */
	private static function clear_reading_signature( array $parsed ) {
		if ( ! empty( $parsed['requires_confirmation'] ) || ! isset( $parsed['fields'] ) || ! is_array( $parsed['fields'] ) ) {
			return null;
		}

		return QRRP_GS1_Parser::canonical_reading_signature( $parsed['fields'] );
	}

	/**
	 * True όταν ο parser έχει καθαρή, admissible ανάγνωση και η υποβληθείσα
	 * είναι διαφορετική (άλλη admissible ερμηνεία του ίδιου raw).
	 */
	private static function deviates_from_clear_reading( $submitted, array $signatures, array $parsed ) {
		$chosen = self::clear_reading_signature( $parsed );

		return null !== $chosen
			&& in_array( $chosen, $signatures, true )
			&& $chosen !== $submitted;
	}

	/** changed_fields μόνο απέναντι σε baseline· αλλιώς κενό + unknown = true. */
	private static function diff_from_baseline( $baseline, $submitted ) {
		if ( null === $baseline ) {
			return array(
				'changed_fields' => array(),
				'unknown'        => true,
			);
		}

		$before  = self::explode_signature( $baseline );
		$after   = self::explode_signature( $submitted );
		$changed = array();

		foreach ( $after as $label => $value ) {
			if ( ! isset( $before[ $label ] ) || $before[ $label ] !== $value ) {
				$changed[] = $label;
			}
		}

		return array(
			'changed_fields' => $changed,
			'unknown'        => false,
		);
	}

	/* --- Challenges --- */
	/* ------------------------------------------------------------------ */

	/** Εκδίδει challenge στον κοινό χώρο QRRP_Tokens (εκτός ευρετηρίου, TTL 15'). */
	private static function issue_challenge( array $context ) {
		return QRRP_Tokens::issue( 'provenance_challenge', $context, self::CHALLENGE_TTL );
	}

	/**
	 * Όλο το context ελέγχεται με ===. Δεν καταναλώνει: ο QRRP_Ajax καταναλώνει
	 * το consume_challenge μετά την επιτυχία, ώστε μια αποτυχία να επιτρέπει retry.
	 */
	private static function challenge_is_valid( $challenge, array $context ) {
		if ( '' === $challenge ) {
			return false;
		}

		return false !== QRRP_Tokens::verify_token_for_request( $challenge, 'provenance_challenge', $context );
	}

	/* --- Απουσία σάρωσης --- */
	/* ------------------------------------------------------------------ */

	/** Φάση 2.12.2: άρνηση. Φάση 2.12.1: άδεια χωρίς provenance, με compatibility_exception. */
	private static function missing_source_raw() {
		if ( version_compare( QRRP_PROVENANCE_PHASE, '2.12.2', '>=' ) ) {
			return self::deny( 'source_raw_required', 400 );
		}

		$verdict = self::allow( null, null, array(), false, '' );

		$verdict['compatibility_exception'] = 'missing_source_raw_phase1';

		return $verdict;
	}

	/* --- Βοηθητικά --- */
	/* ------------------------------------------------------------------ */

	/** Τα πεδία του email token είναι πεζά· η signature θέλει κεφαλαία. */
	private static function signature_from_payload( array $payload ) {
		return QRRP_GS1_Parser::canonical_reading_signature(
			array(
				'PC'  => isset( $payload['pc'] ) ? $payload['pc'] : '',
				'SN'  => isset( $payload['sn'] ) ? $payload['sn'] : '',
				'LOT' => isset( $payload['lot'] ) ? $payload['lot'] : '',
				'EXP' => isset( $payload['exp'] ) ? $payload['exp'] : '',
			)
		);
	}

	private static function signatures_of( $readings ) {
		$out = array();

		foreach ( (array) $readings as $reading ) {
			$out[] = QRRP_GS1_Parser::canonical_reading_signature( (array) $reading );
		}

		return $out;
	}

	/** Αντίστροφο της canonical_reading_signature(): «|» εκτός GS1 charset, «=» με limit 2. */
	private static function explode_signature( $signature ) {
		$out = array();

		foreach ( explode( '|', (string) $signature ) as $part ) {
			$pair = explode( '=', $part, 2 );

			if ( 2 === count( $pair ) ) {
				$out[ $pair[0] ] = $pair[1];
			}
		}

		return $out;
	}

	/** Fingerprint για context challenge· αποτυχία → κενό string. */
	private static function fp( $purpose, $value ) {
		$fp = qrrp_fingerprint( $purpose, (string) $value );

		return is_string( $fp ) ? $fp : '';
	}

	/**
	 * Θετική ετυμηγορία. consume_*: handles που καταναλώνει ο καλών μετά την
	 * επιτυχία. verified_baseline: εσωτερική άγκυρα για τα extras, ποτέ στον browser.
	 */
	private static function allow( $provenance, $source_state, array $changed, $unknown, $consume_token = '', $consume_challenge = '', $verified_baseline_fields = null ) {
		return array(
			'allowed'                 => true,
			'provenance'              => $provenance,
			'source_state'            => $source_state,
			'changed_fields'          => $changed,
			'changed_fields_unknown'  => (bool) $unknown,
			'error_code'              => null,
			'status'                  => 200,
			'compatibility_exception' => null,
			'consume_token'           => $consume_token,
			'consume_challenge'       => $consume_challenge,
			'verified_baseline'       => $verified_baseline_fields,
		);
	}

	/** Αρνητική ετυμηγορία, με το ίδιο schema κλειδιών με την allow(). */
	private static function deny( $code, $status, array $extra = array() ) {
		return array_merge(
			array(
				'allowed'                 => false,
				'provenance'              => null,
				'source_state'            => null,
				'changed_fields'          => array(),
				'changed_fields_unknown'  => false,
				'error_code'              => $code,
				'status'                  => $status,
				'compatibility_exception' => null,
				'consume_token'           => '',
				'consume_challenge'       => '',
				'verified_baseline'       => null,
			),
			$extra
		);
	}
}
