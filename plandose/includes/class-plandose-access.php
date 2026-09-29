<?php
/**
 * Decides who may use the tool: account-type detection, the per-user manual
 * override, and the single source of truth for the allow/deny decision
 * (access_evaluation(), of which can_use_tool() is the boolean wrapper).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plandose_Access {

	/**
	 * User meta key for the per-user manual access override (see
	 * get_manual_access()/set_manual_access() below).
	 */
	const MANUAL_ACCESS_META_KEY = 'plandose_manual_access';

	/**
	 * The user meta keys the pharmacy name and the ΑΦΜ may be
	 * stored under, in lookup order — ONE list for every reader (cards,
	 * CSV, search, print header, deleted-account snapshot), so no reader
	 * (e.g. the search) can miss a key the others know.
	 */
	const PHARMACY_NAME_META_KEYS = array( 'pharmacy_name', 'user_registration_pharmacy_name', 'company', 'billing_company' );
	const AFM_META_KEYS           = array( 'afm', 'user_registration_afm', 'billing_afm', 'vat_number', 'billing_vat', 'tax_id' );

	/**
	 * Greek capitals mapped to their lowercase form, accents preserved.
	 *
	 * Kept as a method rather than inlined into lowercase() because
	 * pharmacy_account_type_sql_variants() derives the spellings the
	 * database is matched against from these same maps — see the note
	 * there on why the two must not be written out twice.
	 *
	 * @return array<string,string>
	 */
	private static function greek_capital_map() {
		return array(
			'Α' => 'α', 'Β' => 'β', 'Γ' => 'γ', 'Δ' => 'δ', 'Ε' => 'ε',
			'Ζ' => 'ζ', 'Η' => 'η', 'Θ' => 'θ', 'Ι' => 'ι', 'Κ' => 'κ',
			'Λ' => 'λ', 'Μ' => 'μ', 'Ν' => 'ν', 'Ξ' => 'ξ', 'Ο' => 'ο',
			'Π' => 'π', 'Ρ' => 'ρ', 'Σ' => 'σ', 'Τ' => 'τ', 'Υ' => 'υ',
			'Φ' => 'φ', 'Χ' => 'χ', 'Ψ' => 'ψ', 'Ω' => 'ω',
			'Ά' => 'ά', 'Έ' => 'έ', 'Ή' => 'ή', 'Ί' => 'ί', 'Ό' => 'ό',
			'Ύ' => 'ύ', 'Ώ' => 'ώ', 'Ϊ' => 'ϊ', 'Ϋ' => 'ϋ',
		);
	}

	/**
	 * Accented lowercase Greek mapped to its plain form, plus final sigma
	 * folded to medial sigma. Applied by normalize_text() on top of
	 * lowercasing.
	 *
	 * @return array<string,string>
	 */
	private static function accent_map() {
		return array(
			'ά' => 'α',
			'έ' => 'ε',
			'ή' => 'η',
			'ί' => 'ι',
			'ό' => 'ο',
			'ύ' => 'υ',
			'ώ' => 'ω',
			'ϊ' => 'ι',
			'ΐ' => 'ι',
			'ϋ' => 'υ',
			'ΰ' => 'υ',
			'ς' => 'σ',
		);
	}

	/**
	 * Safely lowercase text.
	 */
	private static function lowercase( $text ) {
		$text = (string) $text;

		if ( function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( $text, 'UTF-8' );
		}

		/*
		 * Without ext/mbstring, strtolower() only lowercases ASCII — Greek
		 * capitals pass through untouched. That is not a cosmetic problem
		 * here: account-type matching runs through this method, so
		 * "Φαρμακείο" would never normalize to "φαρμακειο" and EVERY
		 * pharmacy would silently fail detection at once, with no error
		 * anywhere to explain why. mbstring is normally present, but a PHP
		 * upgrade that drops it must not take the whole access gate down,
		 * so the Greek alphabet is mapped explicitly as a fallback.
		 */
		return strtr( strtolower( $text ), self::greek_capital_map() );
	}

	/**
	 * Safely uppercase text. The mirror of lowercase(), and used only to
	 * derive the spellings in pharmacy_account_type_sql_variants().
	 */
	private static function uppercase( $text ) {
		$text = (string) $text;

		if ( function_exists( 'mb_strtoupper' ) ) {
			return mb_strtoupper( $text, 'UTF-8' );
		}

		return strtr( strtoupper( $text ), array_flip( self::greek_capital_map() ) );
	}

	/**
	 * Uppercase the first letter only, leaving the rest untouched — the
	 * shape a <select> label is normally written in ("Φαρμακείο").
	 *
	 * ucfirst() is not usable here: a Greek letter is two bytes in UTF-8
	 * and ucfirst() would mangle the first of them. The mbstring path does
	 * it properly; the fallback lifts the leading character out by its
	 * byte length and maps it through the same capital table the rest of
	 * this class uses.
	 */
	private static function titlecase( $text ) {
		$text = (string) $text;

		if ( '' === $text ) {
			return $text;
		}

		if ( function_exists( 'mb_substr' ) && function_exists( 'mb_strtoupper' ) ) {
			return mb_strtoupper( mb_substr( $text, 0, 1, 'UTF-8' ), 'UTF-8' ) . mb_substr( $text, 1, null, 'UTF-8' );
		}

		// Leading byte determines how many bytes the first character spans.
		$lead   = ord( $text[0] );
		$length = 1;

		if ( $lead >= 0xF0 ) {
			$length = 4;
		} elseif ( $lead >= 0xE0 ) {
			$length = 3;
		} elseif ( $lead >= 0xC0 ) {
			$length = 2;
		}

		$first = substr( $text, 0, $length );
		$rest  = substr( $text, $length );

		return strtr( strtoupper( $first ), array_flip( self::greek_capital_map() ) ) . $rest;
	}

	/**
	 * Normalize text for looser comparisons.
	 */
	public static function normalize_text( $text ) {
		// No-break and zero-width spaces (pasted or written by form
		// plugins) count as ordinary whitespace, so "Φαρμακείο\u00a0" is
		// still recognised.
		$text = str_replace( array( "\u{00A0}", "\u{200B}", "\u{FEFF}" ), array( ' ', '', '' ), (string) $text );

		return strtr( self::lowercase( trim( $text ) ), self::accent_map() );
	}

	/**
	 * Read user meta using several fallback keys.
	 */
	public static function get_meta_with_fallback( $user_id, $keys = array() ) {
		$user_id = absint( $user_id );

		if ( ! $user_id || ! is_array( $keys ) ) {
			return '';
		}

		foreach ( $keys as $key ) {
			$value = get_user_meta( $user_id, $key, true );

			if ( '' !== $value && null !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Account type meta fallback keys.
	 */
	public static function account_type_meta_keys() {
		return array(
			'account_type',
			'user_registration_account_type',
			'user_registration_Account_Type',
		);
	}

	/**
	 * Get account type.
	 */
	public static function get_account_type( $user_id ) {
		return self::get_meta_with_fallback( $user_id, self::account_type_meta_keys() );
	}

	/**
	 * The account_type values that count as a pharmacy, spelled exactly as
	 * the registration form's <select> stores them.
	 *
	 * This is the single source of truth. Everything else about pharmacy
	 * matching is derived from it: the normalized forms PHP compares
	 * against, and the spellings the Subscriptions query looks for in the
	 * database. Add a new accepted option here and both follow. Compared
	 * after normalize_text() (case/accents), as an exact match — never a
	 * substring test. «Εταιρία» must never be listed.
	 *
	 * @return string[]
	 */
	private static function pharmacy_account_type_labels() {
		return array(
			'Φαρμακείο',
		);
	}

	/**
	 * The accepted values in normalized form, for comparison against a
	 * normalize_text()'d meta value. Exact match, not a substring test.
	 *
	 * @return string[]
	 */
	public static function pharmacy_account_type_values() {
		return array_values(
			array_unique(
				array_map( array( __CLASS__, 'normalize_text' ), self::pharmacy_account_type_labels() )
			)
		);
	}

	/**
	 * Every spelling of an accepted label that could plausibly be sitting
	 * in the database, for the Subscriptions list's SQL to match against.
	 *
	 * WHY THIS IS NOT JUST pharmacy_account_type_values()
	 *
	 * PHP normalizes the stored value before comparing it. SQL cannot: it
	 * would take a chain of forty-odd REPLACE() calls to fold Greek case
	 * and accents inside a query, applied to a column, on every subscriber
	 * lookup. So the query compares raw spellings instead — which means the
	 * list of spellings has to be complete enough to cover what the column
	 * actually holds.
	 *
	 * A query that hardcodes two of them ('φαρμακείο' and 'φαρμακειο') and
	 * leans on MySQL's LOWER() plus the column's collation to bridge the
	 * rest works on a case- and accent-insensitive collation and quietly
	 * stops working on one that
	 * is neither — and the failure is invisible: the pharmacy can still use
	 * the tool, because can_use_tool() goes through the PHP path, but it
	 * never appears in PlanDose → Συνδρομές, so nobody can manage it.
	 *
	 * Deriving the spellings here from the same labels closes the gap for
	 * the case and accent variants a <select> can realistically produce
	 * (as stored, lowercased, uppercased, and each of those with accents
	 * stripped). It does not attempt arbitrary mixed case such as
	 * "φΑρΜαΚεΙο" — a fixed option list cannot produce that, and matching
	 * it would mean the REPLACE chain after all. A case-insensitive
	 * collation still covers those on top of this; this list is what makes
	 * the match work without depending on one.
	 *
	 * @return string[]
	 */
	public static function pharmacy_account_type_sql_variants() {
		$variants = array();

		foreach ( self::pharmacy_account_type_labels() as $label ) {
			$label = trim( (string) $label );

			foreach ( array( $label, self::normalize_text( $label ) ) as $base ) {
				$variants[] = $base;
				$variants[] = self::lowercase( $base );
				$variants[] = self::uppercase( $base );
				$variants[] = self::titlecase( self::lowercase( $base ) );
			}
		}

		return array_values( array_unique( array_filter( $variants, static function ( $v ) { return '' !== (string) $v; } ) ) );
	}

	/**
	 * Whether the user's account is a pharmacy.
	 *
	 * Reads ONLY the exact 'account_type' meta key written by the User
	 * Registration plugin's select field, and compares it EXACTLY (after
	 * case/accent normalization) against pharmacy_account_type_values().
	 *
	 * There is no loose fallback: reading other meta keys when
	 * 'account_type' is empty and granting access on any substring match
	 * against 'pharmac', 'φαρμακειο' and similar would let a value such as
	 * "pharmaceutical company" pass as a pharmacy. No account on the site
	 * relies on those keys, so such a fallback would be pure risk.
	 *
	 * This is the ONLY way into PlanDose for a non-admin. The manual
	 * "allow" override does not let in an account this rule does not
	 * recognise; fix the account's «Κατηγορία Επιχείρησης» instead
	 * (Plandose_Account_Type adds that field to the user profile screen).
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_registered_pharmacist( $user_id ) {
		$user_id = is_numeric( $user_id ) ? (int) $user_id : 0;

		if ( $user_id < 1 ) {
			return false;
		}

		/*
		 * Every key in account_type_meta_keys(), not only
		 * 'account_type'. User Registration writes the same select field
		 * as 'user_registration_account_type' on some forms and versions,
		 * so pharmacies registered that way would otherwise be invisible to
		 * PlanDose (not listed, not counted, no access) although WordPress
		 * shows them as «Φαρμακείο». Still an EXACT match after
		 * normalization — never a substring test — so a value such as
		 * "pharmaceutical company" or «Εταιρία» never passes. A select
		 * stored as an array counts when one of its values matches.
		 */
		foreach ( self::account_type_meta_keys() as $key ) {
			$raw    = get_user_meta( $user_id, $key, true );
			$values = is_array( $raw ) ? $raw : array( $raw );

			foreach ( $values as $value ) {
				if ( is_scalar( $value ) && '' !== (string) $value && in_array( self::normalize_text( $value ), self::pharmacy_account_type_values(), true ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Get the manual access override for a user: 'allow', 'deny', or ''
	 * (no override — falls back to the account_type heuristic).
	 *
	 * The account_type-based detection in is_registered_pharmacist() is
	 * convenient auto-detection, but it's a soft signal: it's driven by
	 * free-text user meta that a registration form (or, in principle, the
	 * user themself depending on how that plugin is configured) writes.
	 * Only 'deny' has an effect — it lets an admin shut out a
	 * specific pharmacy. A stored 'allow' is legacy and ignored by
	 * access_evaluation(): it cannot admit a non-pharmacy.
	 */
	public static function get_manual_access( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return '';
		}

		$value = get_user_meta( $user_id, self::MANUAL_ACCESS_META_KEY, true );

		return in_array( $value, array( 'allow', 'deny' ), true ) ? $value : '';
	}

	/**
	 * Set (or clear, with '') the manual access override for a user.
	 *
	 * No override changes subscriber membership (only «Φαρμακείο»
	 * accounts are subscribers) and only 'deny' affects access.
	 * The subscriber cache is still invalidated so the admin screens show
	 * the new state at once. Invalidation also removes any legacy
	 * full-dataset transient left behind by earlier PlanDose versions.
	 *
	 * Returns bool: whether the override now actually matches what was
	 * requested. Checked against the resulting state rather than trusting
	 * delete_user_meta()/update_user_meta()'s own return value, since both
	 * report false for a genuine failure AND for a harmless no-op (e.g.
	 * resetting an override that was already unset) — verifying the final
	 * state avoids misreporting that second case as a failure. Callers
	 * (see Plandose_Admin_Subscriptions::handle_subscription_action())
	 * use this to only write an audit log entry once the change is
	 * actually confirmed.
	 */
	public static function set_manual_access( $user_id, $value ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return false;
		}

		$value = in_array( $value, array( 'allow', 'deny' ), true ) ? $value : '';

		if ( '' === $value ) {
			delete_user_meta( $user_id, self::MANUAL_ACCESS_META_KEY );
		} else {
			update_user_meta( $user_id, self::MANUAL_ACCESS_META_KEY, $value );
		}

		Plandose_Subscriber_Query::invalidate_subscriber_cache();

		return self::get_manual_access( $user_id ) === $value;
	}

	/**
	 * Evaluate tool access AND say why, in one place.
	 *
	 * PHARMACY-ONLY POLICY. PlanDose is offered only to accounts
	 * whose «Κατηγορία Επιχείρησης» is «Φαρμακείο». The order is:
	 *
	 *   1. manual 'deny'   → blocked (an admin can still shut out a pharmacy);
	 *   2. hidden role     → blocked;
	 *   3. admin preview   → allowed (administrators testing the tool; they
	 *                        are never listed or counted as subscribers);
	 *   4. «Φαρμακείο»     → allowed;
	 *   5. everyone else   → blocked ('not_pharmacist').
	 *
	 * There is no way in for NON-pharmacies — no manual 'allow' override,
	 * no allowed_roles setting, no switching require_pharmacy off — because
	 * each would let an account that is not a pharmacy use (and be billed
	 * for) a pharmacy tool. A stored legacy 'allow' is simply
	 * ignored — it can neither grant access to a non-pharmacy nor take it
	 * away from a pharmacy — and the admin screen offers to clear it.
	 *
	 * This is the single implementation of the access policy. can_use_tool()
	 * is a thin wrapper over it, and the admin Subscriptions screen renders
	 * the reason, so the answer the pharmacist gets on the front end and the
	 * answer the admin sees in the dashboard can never drift apart.
	 *
	 * Note what this is NOT. It answers "may this person open the tool right
	 * now", which is a different question from "is this a pharmacy account",
	 * the question subscriber_where_sql() answers when building the
	 * Subscriptions list: with allow_admin_preview on, every administrator
	 * passes this check but none of them belongs in a list of pharmacies.
	 *
	 * @param int $user_id User ID.
	 * @return array{allowed:bool,reason:string}
	 */
	public static function access_evaluation( $user_id ) {
		// (int), not absint(): a negative id is nonsense input, and absint()
		// would turn -5 into a lookup for user 5 — a different account.
		$user_id = is_numeric( $user_id ) ? (int) $user_id : 0;

		if ( $user_id < 1 ) {
			return array(
				'allowed' => false,
				'reason'  => 'invalid_user',
			);
		}

		if ( 'deny' === self::get_manual_access( $user_id ) ) {
			return array(
				'allowed' => false,
				'reason'  => 'manual_deny',
			);
		}

		$settings = Plandose_Settings::settings();
		$user     = get_userdata( $user_id );

		if ( ! $user ) {
			return array(
				'allowed' => false,
				'reason'  => 'invalid_user',
			);
		}

		$roles = (array) $user->roles;

		if ( ! empty( $settings['hidden_roles'] ) && array_intersect( $roles, (array) $settings['hidden_roles'] ) ) {
			return array(
				'allowed' => false,
				'reason'  => 'hidden_role',
			);
		}

		if ( ! empty( $settings['allow_admin_preview'] ) && user_can( $user_id, class_exists( 'Plandose_Admin' ) ? Plandose_Admin::capability() : 'manage_options' ) ) {
			return array(
				'allowed' => true,
				'reason'  => 'admin_preview',
			);
		}

		if ( self::is_registered_pharmacist( $user_id ) ) {
			return array(
				'allowed' => true,
				'reason'  => 'registered_pharmacist',
			);
		}

		return array(
			'allowed' => false,
			'reason'  => 'not_pharmacist',
		);
	}

	/**
	 * Whether the user may open the tool. See access_evaluation() for the
	 * policy itself and for why this differs from the Subscriptions list.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function can_use_tool( $user_id ) {
		$evaluation = self::access_evaluation( $user_id );

		return $evaluation['allowed'];
	}
}