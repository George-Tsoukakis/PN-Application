<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Κόψιμο κειμένου σε χαρακτήρες Unicode, κοινό για Ajax, Mailer και Admin.
 * Μισός χαρακτήρας UTF-8 κάνει το esc_html() να επιστρέψει κενό. Δεν κάνει
 * sanitization· αυτό μένει στον caller.
 *
 * @package QR_ReBuilder_Pro
 */
final class QRRP_Text {

	/**
	 * Κόβει σε το πολύ $max_length χαρακτήρες (code points, όχι bytes).
	 *
	 * Έξοδος πάντα έγκυρο UTF-8, ίδια με ή χωρίς mbstring: άκυρα bytes → U+FFFD,
	 * NFC όταν υπάρχει Normalizer, και με intl κόψιμο σε όριο grapheme ώστε να
	 * μη χωρίζονται τόνοι ή ακολουθίες emoji (ZWJ).
	 *
	 * @param string $value      Το κείμενο (ήδη sanitized από τον caller).
	 * @param int    $max_length Μέγιστο πλήθος χαρακτήρων.
	 * @return string
	 */
	public static function truncate( $value, $max_length ) {
		$value      = is_scalar( $value ) ? (string) $value : '';
		$max_length = max( 0, (int) $max_length );

		if ( 0 === $max_length || '' === $value ) {
			return '';
		}

		/* Γρήγορος δρόμος: καθαρό ASCII εντός ορίου μένει ως έχει. */
		if ( strlen( $value ) <= $max_length && 1 === preg_match( '/^[\x00-\x7F]*$/', $value ) ) {
			return $value;
		}

		$value = self::normalize( self::scrub( $value ) );

		return self::cut( $value, $max_length, function_exists( 'mb_substr' ), function_exists( 'grapheme_extract' ) );
	}

	/**
	 * Κάθε άκυρο byte UTF-8 → U+FFFD. Χωρίς mbstring, ώστε να μην εξαρτάται από
	 * το mb_substitute_character() ή τις διαθέσιμες επεκτάσεις.
	 */
	private static function scrub( $value ) {
		if ( 1 === preg_match( '//u', $value ) ) {
			return $value;
		}

		$scrubbed = preg_replace_callback(
			'/[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}|(.)/s',
			static function ( $m ) {
				return isset( $m[1] ) ? "\u{FFFD}" : $m[0];
			},
			$value
		);

		return is_string( $scrubbed ) ? $scrubbed : '';
	}

	/** NFC, ώστε το «ά» να μετρά ένας χαρακτήρας και όταν έρθει ως α + U+0301. */
	private static function normalize( $value ) {
		if ( ! class_exists( 'Normalizer' ) ) {
			return $value;
		}

		$normalized = Normalizer::normalize( $value, Normalizer::FORM_C );

		return is_string( $normalized ) ? $normalized : $value;
	}

	/**
	 * Κόβει έγκυρο UTF-8. Με intl κρατά μόνο ολόκληρα graphemes· αν ούτε το
	 * πρώτο χωρά, πέφτει σε κόψιμο code point αντί να επιστρέψει κενό.
	 *
	 * @param bool $use_mb       Χρήση mbstring.
	 * @param bool $use_grapheme Χρήση grapheme_extract() (intl).
	 */
	private static function cut( $value, $max_length, $use_mb, $use_grapheme ) {
		if ( $use_grapheme ) {
			$next  = 0;
			$whole = grapheme_extract( $value, $max_length, GRAPHEME_EXTR_MAXCHARS, 0, $next );

			if ( is_string( $whole ) && '' !== $whole ) {
				return $whole;
			}
		}

		if ( $use_mb ) {
			return mb_substr( $value, 0, $max_length, 'UTF-8' );
		}

		return self::truncate_without_mbstring( $value, $max_length );
	}

	/** Χωρίς mbstring: μετρά τα bytes που ξεκινούν χαρακτήρα (όχι 10xxxxxx). Θέλει έγκυρο UTF-8. */
	private static function truncate_without_mbstring( $value, $max_length ) {
		$length = strlen( $value );
		$chars  = 0;

		for ( $i = 0; $i < $length; $i++ ) {
			if ( 0x80 !== ( ord( $value[ $i ] ) & 0xC0 ) ) {
				if ( $chars === $max_length ) {
					return substr( $value, 0, $i );
				}

				++$chars;
			}
		}

		return $value;
	}
}
