<?php
/**
 * Access helpers: tool page, capabilities, pharmacist detection, manual entry.
 * Global (not QRRP_Admin) because the front end reads them too.
 *
 * @package QR_ReBuilder_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ID της σελίδας εργαλείου, ή 0 = «δεν έχει οριστεί» (τότε ισχύει η αυτόματη
 * ανίχνευση). Χωρίς query· οι καταναλωτές αποφασίζουν τι χρειάζονται.
 */
function qrrp_tool_page_id() {
	$stored = get_option( 'qrrp_tool_page_id', 0 );

	if ( ! is_scalar( $stored ) ) {
		return 0;
	}

	$id = (int) $stored;

	return $id > 0 ? $id : 0;
}

/** Δικαίωμα εργαλείου όταν λείπει η γραμμή του option (άκυρη τιμή → βλ. qrrp_tool_capability()). */
function qrrp_default_tool_capability() {
	return 'read';
}

function qrrp_allowed_tool_capabilities() {
	return array( 'read', 'edit_posts', 'manage_options', 'qrrp_pharmacist' );
}

/** Μη κενές τιμές δικαιώματος email· το '' («ίδιο με το εργαλείο») είναι επίσης θεμιτό. */
function qrrp_allowed_email_capabilities() {
	return array( 'edit_posts', 'manage_options', 'qrrp_pharmacist' );
}

/** Δικαίωμα email για απούσα γραμμή: η σταθερά, αν είναι αποδεκτή, αλλιώς fail-closed. */
function qrrp_default_email_capability() {
	$default = sanitize_key( (string) QRRP_DEFAULT_EMAIL_CAPABILITY );

	return in_array( $default, qrrp_allowed_email_capabilities(), true ) ? $default : 'manage_options';
}

/**
 * Είναι ο χρήστης φαρμακοποιός (Κατηγορία «Φαρμακείο»); Οι διαχειριστές πάντα.
 *
 * Πηγή: pn_uf_get_category() αν υπάρχει, αλλιώς τα meta keys της φόρμας
 * εγγραφής (αρκεί ένα να ταιριάζει). Η κατηγορία είναι αυτο-δηλωμένη, όχι
 * επαληθευμένη· επαληθευμένη πηγή συνδέεται με το φίλτρο 'qrrp_is_pharmacist'
 * (bool, $user_id), που δεν πρέπει να καλεί current_user_can( 'qrrp_pharmacist' ).
 *
 * @param int $user_id
 * @return bool
 */
function qrrp_user_is_pharmacist( $user_id ) {
	$user_id = (int) $user_id;

	if ( $user_id < 1 ) {
		return false;
	}

	if ( user_can( $user_id, 'manage_options' ) ) {
		return true;
	}

	$is = false;

	if ( function_exists( 'pn_uf_get_category' ) ) {
		/* 2.15.7: ίδια ανεκτική σύγκριση με τα meta keys (τόνοι/κεφαλαία). */
		$category = pn_uf_get_category( $user_id );
		$is       = is_scalar( $category ) && 'φαρμακειο' === qrrp_fold_greek( (string) $category );
	} else {
		foreach ( array( 'account_type', 'user_registration_account_type', 'user_registration_Account_Type' ) as $key ) {
			$value = get_user_meta( $user_id, $key, true );

			if ( is_array( $value ) ) {
				$value = reset( $value );
			}

			if ( is_scalar( $value ) && 'φαρμακειο' === qrrp_fold_greek( (string) $value ) ) {
				$is = true;
				break;
			}
		}
	}

	return (bool) apply_filters( 'qrrp_is_pharmacist', $is, $user_id );
}

/** Πεζά και χωρίς τόνους, για ανεκτική σύγκριση ελληνικών (χωρίς mbstring). */
function qrrp_fold_greek( $text ) {
	$upper = array( 'Α','Β','Γ','Δ','Ε','Ζ','Η','Θ','Ι','Κ','Λ','Μ','Ν','Ξ','Ο','Π','Ρ','Σ','Τ','Υ','Φ','Χ','Ψ','Ω','Ά','Έ','Ή','Ί','Ό','Ύ','Ώ','Ϊ','Ϋ' );
	$lower = array( 'α','β','γ','δ','ε','ζ','η','θ','ι','κ','λ','μ','ν','ξ','ο','π','ρ','σ','τ','υ','φ','χ','ψ','ω','α','ε','η','ι','ο','υ','ω','ι','υ' );
	$text  = str_replace( $upper, $lower, strtolower( trim( (string) $text ) ) );

	return strtr(
		$text,
		array( 'ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ό' => 'ο', 'ύ' => 'υ', 'ώ' => 'ω', 'ϊ' => 'ι', 'ΐ' => 'ι', 'ϋ' => 'υ', 'ΰ' => 'υ', 'ς' => 'σ' )
	);
}

/**
 * user_has_cap: δίνει το qrrp_pharmacist σε φαρμακοποιούς, μόνο όταν ζητείται.
 * Μόνο προσθέτει· δικαίωμα δοσμένο ρητά (π.χ. από role editor) μένει.
 */
function qrrp_grant_pharmacist_cap( $allcaps, $caps, $args, $user ) {
	if (
		in_array( 'qrrp_pharmacist', (array) $caps, true )
		&& empty( $allcaps['qrrp_pharmacist'] )
		&& is_object( $user ) && isset( $user->ID )
	) {
		$allcaps['qrrp_pharmacist'] = ! empty( $allcaps['manage_options'] ) || qrrp_user_is_pharmacist( (int) $user->ID );
	}

	return $allcaps;
}

/**
 * Αποδεκτό δικαίωμα εργαλείου; Κρίνεται στην ακατέργαστη τιμή, γιατί το
 * sanitize_key() αδειάζει τα μη-ASCII και το κενό δεν είναι ποτέ δεκτό εδώ.
 */
function qrrp_is_allowed_tool_capability( $value ) {
	if ( ! is_scalar( $value ) ) {
		return false;
	}

	$raw = trim( (string) $value );

	if ( '' === $raw ) {
		return false;
	}

	return in_array( sanitize_key( $raw ), qrrp_allowed_tool_capabilities(), true );
}

/**
 * Το δικαίωμα χρήσης που επιβάλλεται. Απούσα γραμμή → προεπιλογή· άκυρη τιμή
 * (και null/false από cache ή pre_option_*) → 'manage_options'. Το sentinel
 * ξεχωρίζει τις δύο περιπτώσεις.
 */
function qrrp_tool_capability() {
	$missing = new stdClass();
	$stored  = get_option( 'qrrp_capability', $missing );

	if ( $missing === $stored ) {
		return qrrp_default_tool_capability();
	}

	if ( qrrp_is_allowed_tool_capability( $stored ) ) {
		return sanitize_key( trim( (string) $stored ) );
	}

	return 'manage_options';
}

/**
 * 2.15.3: τα domains στα οποία μπορεί να στείλει email ένας επισκέπτης, από
 * το option qrrp_guest_email_domains (ένα ανά γραμμή). Κενή λίστα = κανένα.
 *
 * @return string[]
 */
function qrrp_guest_email_domains() {
	$stored = get_option( 'qrrp_guest_email_domains', '' );

	return qrrp_without_webmail_domains( qrrp_parse_email_domains( is_scalar( $stored ) ? (string) $stored : '' ) );
}

/**
 * 2.15.5: δημόσιες υπηρεσίες email, όπου ο καθένας ανοίγει λογαριασμό. Στη
 * λίστα domains επισκεπτών θα έκαναν το site αναμεταδότη email προς
 * οποιονδήποτε (π.χ. gmail.com → κάθε χρήστης Gmail). Ακριβές ταίριασμα,
 * όπως και η λίστα. Φίλτρο: qrrp_webmail_domains.
 *
 * @return string[]
 */
function qrrp_webmail_domains() {
	$domains = preg_split(
		'/\s+/',
		'gmail.com googlemail.com
		yahoo.com yahoo.gr yahoo.co.uk yahoo.de yahoo.fr ymail.com rocketmail.com
		hotmail.com hotmail.gr hotmail.co.uk hotmail.de hotmail.fr hotmail.it
		outlook.com outlook.com.gr live.com live.gr live.co.uk msn.com windowslive.com
		icloud.com me.com mac.com aol.com aim.com
		gmx.com gmx.net gmx.de web.de mail.com email.com yandex.com yandex.ru mail.ru
		proton.me protonmail.com protonmail.ch pm.me tutanota.com tutanota.de tuta.io tuta.com
		zoho.com zohomail.com fastmail.com hushmail.com mailfence.com
		freemail.gr in.gr otenet.gr windtools.gr hol.gr forthnet.gr',
		-1,
		PREG_SPLIT_NO_EMPTY
	);

	$filtered = apply_filters( 'qrrp_webmail_domains', $domains );

	if ( ! is_array( $filtered ) ) {
		return $domains;
	}

	/* Ίδια κανονικοποίηση με τη λίστα του διαχειριστή (πεζά, χωρίς «@», κενά ή τελείες στα άκρα). */
	$out = array();

	foreach ( $filtered as $domain ) {
		if ( is_string( $domain ) ) {
			$domain = trim( ltrim( trim( strtolower( $domain ) ), '@' ), '.' );

			if ( '' !== $domain ) {
				$out[] = $domain;
			}
		}
	}

	return $out;
}

/** 2.15.5: αν ο τρέχων χρήστης εξαιρείται από τα όρια email συνδεδεμένων (διαχειριστές). */
function qrrp_user_email_limits_exempt() {
	return (bool) apply_filters( 'qrrp_user_email_limits_exempt', current_user_can( 'manage_options' ), get_current_user_id() );
}

/** 2.15.5: email ανά συνδεδεμένο χρήστη ανά 24 ώρες (ελάχιστο 5). */
function qrrp_user_email_daily_limit() {
	return max( 5, (int) apply_filters( 'qrrp_user_email_daily_limit', 50 ) );
}

/** 2.15.5: email ενός συνδεδεμένου χρήστη προς την ίδια διεύθυνση ανά 24 ώρες (ελάχιστο 1). */
function qrrp_user_email_per_recipient_limit() {
	return max( 1, (int) apply_filters( 'qrrp_user_email_per_recipient_limit', 10 ) );
}

/** 2.15.5: αν τα domains δημόσιων υπηρεσιών email επιτρέπονται ρητά στη λίστα επισκεπτών. */
function qrrp_guest_email_webmail_allowed() {
	return (bool) apply_filters( 'qrrp_guest_email_allow_webmail', false );
}

/**
 * Αφαιρεί από τη λίστα τα domains δημόσιων υπηρεσιών email. Όποιος τα θέλει
 * ρητά επιστρέφει true από το φίλτρο qrrp_guest_email_allow_webmail.
 *
 * @param string[] $domains  Κανονικοποιημένα domains (qrrp_parse_email_domains()).
 * @param string[] $removed  Έξοδος: όσα αφαιρέθηκαν.
 * @return string[]
 */
function qrrp_without_webmail_domains( array $domains, &$removed = null ) {
	$removed = array();

	if ( qrrp_guest_email_webmail_allowed() ) {
		return $domains;
	}

	$webmail = array_flip( qrrp_webmail_domains() );
	$kept    = array();

	foreach ( $domains as $domain ) {
		if ( isset( $webmail[ $domain ] ) ) {
			$removed[] = $domain;
		} else {
			$kept[] = $domain;
		}
	}

	return $kept;
}

/**
 * 2.15.4: αν οι επισκέπτες έχουν καθόλου πιθανό παραλήπτη, ώστε να φαίνεται το
 * email στο εργαλείο. Ναι όταν υπάρχει λίστα domains. Ένα φίλτρο παραληπτών
 * (qrrp_guest_email_recipient_allowed) που απλώς περιορίζει δεν αρκεί πια:
 * όποιος επιτρέπει παραλήπτες εκτός λίστας το δηλώνει ρητά, επιστρέφοντας
 * true από το qrrp_guest_email_open_recipients.
 */
function qrrp_guest_email_has_recipients() {
	return array() !== qrrp_guest_email_domains()
		|| (bool) apply_filters( 'qrrp_guest_email_open_recipients', false );
}

/**
 * Κανονικοποιεί λίστα domains (γραμμές, κόμματα ή κενά): πεζά, χωρίς «@» και
 * τελεία στην αρχή/τέλος, μόνο έγκυροι χαρακτήρες, έως 50, χωρίς διπλά.
 *
 * @return string[]
 */
function qrrp_parse_email_domains( $text, &$rejected = null ) {
	$out      = array();
	$rejected = array();

	foreach ( preg_split( '/[\s,;]+/', strtolower( (string) $text ), -1, PREG_SPLIT_NO_EMPTY ) as $entry ) {
		$domain = trim( ltrim( trim( $entry ), '@' ), '.' );

		if ( '' === $domain ) {
			continue;
		}

		/* 2.15.4: οι άκυρες και όσες περισσεύουν πάνω από 50 επιστρέφονται, για μήνυμα στον διαχειριστή. */
		if (
			1 !== preg_match( '/\A(?=.{1,253}\z)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}\z/', $domain )
			|| ( count( $out ) >= 50 && ! isset( $out[ $domain ] ) )
		) {
			$rejected[] = $entry;
			continue;
		}

		$out[ $domain ] = true;
	}

	return array_keys( $out );
}

/**
 * Κλειδί ορίου ανά παραλήπτη: trim, πεζά και χωρίς «+tag» στο τοπικό μέρος
 * (info+1@ = info@), ώστε το όριο να μην παρακάμπτεται. Μόνο ως hash στη βάση.
 */
function qrrp_normalize_email_for_limit( $email ) {
	$email = strtolower( trim( (string) $email ) );
	$at    = strrpos( $email, '@' );

	if ( false === $at ) {
		return $email;
	}

	$local = substr( $email, 0, $at );
	$plus  = strpos( $local, '+' );

	if ( false !== $plus && $plus > 0 ) {
		$local = substr( $local, 0, $plus );
	}

	return $local . substr( $email, $at );
}

/**
 * 2.15.3: αν ένας επισκέπτης μπορεί να στείλει σε αυτή τη διεύθυνση. Default
 * deny: μόνο domains της λίστας (ακριβές ταίριασμα, όχι subdomains). Το
 * φίλτρο qrrp_guest_email_recipient_allowed μπορεί να αλλάξει την απόφαση.
 */
function qrrp_guest_email_recipient_allowed( $email ) {
	$email  = strtolower( trim( (string) $email ) );
	$at     = strrpos( $email, '@' );
	$domain = ( false === $at ) ? '' : substr( $email, $at + 1 );

	$allowed = '' !== $domain && in_array( $domain, qrrp_guest_email_domains(), true );

	return (bool) apply_filters( 'qrrp_guest_email_recipient_allowed', $allowed, $email );
}

/**
 * Δημιουργία κωδικού χωρίς σάρωση: συνδεδεμένοι πάντα (το δικαίωμα εργαλείου
 * το ελέγχει η Ajax)· επισκέπτες μόνο με ανοιχτό εργαλείο και ενεργή ρύθμιση.
 */
function qrrp_manual_entry_allowed() {
	if ( is_user_logged_in() ) {
		return true;
	}

	return '1' === get_option( 'qrrp_allow_guests', '0' )
		&& 'read' === qrrp_tool_capability()
		&& '1' === get_option( 'qrrp_allow_guest_manual_entry', '0' );
}
