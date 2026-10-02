<?php
/**
 * Small talk: short remarks that are not questions («ωραίο tool», «οκ»,
 * «καληνύχτα», «δεν με βοήθησες»). The chat answers them naturally instead
 * of «δεν έχουμε πληροφορίες» and an e-mail form. A trained entry that
 * answers the remark (e.g. «Ευχαριστώ») always wins.
 *
 * @package PNChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small-talk detection and replies.
 */
final class PNChat_Smalltalk {

	/**
	 * Most words a remark has; longer texts are treated as questions.
	 */
	const MAX_WORDS = 8;

	/**
	 * Phrases per kind, Greek as typed (accents, case and Greeklish are
	 * folded). A word matches itself and, from four letters, the longer
	 * words it begins («τέλει» → τέλειο, τέλεια). Filter «pnchat_smalltalk_phrases».
	 *
	 * @return array<string,string[]>
	 */
	public static function phrases() {
		return (array) apply_filters(
			'pnchat_smalltalk_phrases',
			array(
				// First on purpose: «ωραίο αλλά δεν δουλεύει» is a complaint.
				'complaint' => array( 'δεν με βοήθησ', 'δεν βοήθησ', 'δε βοήθησ', 'δεν βοηθάς', 'δεν βοηθάει', 'δεν κατάλαβες', 'δεν καταλαβαίνεις', 'δεν δουλεύει', 'δε δουλεύει', 'δεν λειτουργεί', 'άχρηστ', 'χάλια', 'λάθος απάντηση', 'δεν απάντησες' ),
				'bye'       => array( 'αντίο', 'καληνύχτα', 'τα λέμε', 'καλή συνέχεια', 'καλή σας μέρα', 'καλό βράδυ', 'γεια χαρά', 'bye' ),
				'praise'    => array( 'ωραίο', 'ωραία', 'ωραίος', 'ωραίες', 'ωραίοι', 'ωραιο', 'τέλει', 'μπράβο', 'εξαιρετικ', 'φοβερ', 'καταπληκτικ', 'υπέροχ', 'χρήσιμ', 'πολύ καλό', 'πολύ καλή', 'top', 'super', 'σούπερ', 'cool', 'nice', 'great', 'μου αρέσει', 'το λατρεύω' ),
				'ok'        => array( 'οκ', 'ok', 'okay', 'εντάξει', 'κατάλαβα', 'καταλαβα', 'σωστά', 'σύμφωνοι', 'ναι', 'όχι', 'μάλιστα', 'αχά', 'ευχαριστώ', 'ευχαριστούμε', 'thanks', 'thank you', 'merci' ),
			)
		);
	}

	/**
	 * Words that make a text a question, anywhere in it. Compared as typed
	 * (lower case, with accents): folded, «τι» would equal «τη» and «πού»
	 * the «που» of «χαίρομαι που…».
	 *
	 * @return string[]
	 */
	private static function question_words() {
		return array( 'τι', 'τί', 'πώς', 'πως', 'πού', 'ποιο', 'ποια', 'ποιος', 'ποιοι', 'ποιες', 'πόσο', 'ποσο', 'πόσα', 'ποσα', 'πόσες', 'πότε', 'ποτε', 'γιατί', 'γιατι', 'μπορώ', 'μπορω', 'μπορεί', 'μπορείτε', 'υπάρχει', 'θέλω', 'θελω', 'ψάχνω', 'βοήθεια', 'how', 'what', 'why', 'where', 'when', 'can', 'pws', 'pos', 'giati' );
	}

	/**
	 * The kind of remark a text is, or '' when it is (or may be) a question.
	 *
	 * @param string $text Visitor's text.
	 * @return string 'complaint', 'bye', 'praise', 'ok' or ''.
	 */
	public static function detect( $text ) {
		$text = trim( (string) $text );
		// A question mark (Latin «?» or Greek «;») means a question.
		if ( '' === $text || preg_match( '/[?;;]/u', $text ) ) {
			return '';
		}
		$words = array_values( array_filter( explode( ' ', PNChat_Text::fold( $text ) ), 'strlen' ) );
		if ( ! $words || count( $words ) > self::MAX_WORDS ) {
			return '';
		}
		$raw = preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( $text, 'UTF-8' ), -1, PREG_SPLIT_NO_EMPTY );
		if ( array_intersect( (array) $raw, self::question_words() ) ) {
			return '';
		}
		foreach ( self::phrases() as $kind => $list ) {
			if ( self::has_any( $words, (array) $list ) ) {
				return (string) $kind;
			}
		}
		return '';
	}

	/**
	 * A question without the remark it starts with: «Ωραία, τι κάνει;» →
	 * «τι κάνει;». Only praise and «οκ» words are taken off, one or two of
	 * them («όχι» stays), and only when two words or more are left.
	 *
	 * @param string $text Visitor's text.
	 * @return string
	 */
	public static function strip_lead( $text ) {
		$text  = trim( (string) $text );
		$terms = array();
		foreach ( array( 'praise', 'ok' ) as $kind ) {
			$terms = array_merge( $terms, (array) ( self::phrases()[ $kind ] ?? array() ) );
		}
		// «Όχι» changes what follows («όχι δωρεάν»): it stays.
		$terms = array_diff( $terms, array( 'όχι' ) );
		$out = $text;
		for ( $n = 0; $n < 2; $n++ ) {
			if ( ! preg_match( '/^([\p{L}\p{N}]+)[\s,.!·…-]+(.+)$/us', $out, $m ) ) {
				break;
			}
			if ( ! self::has_any( array( PNChat_Text::fold( $m[1] ) ), $terms ) || count( preg_split( '/\s+/u', trim( $m[2] ) ) ) < 2 ) {
				break;
			}
			$out = trim( $m[2] );
		}
		return $out;
	}

	/**
	 * One of the phrases is in the folded words.
	 *
	 * @param string[] $words Folded words.
	 * @param string[] $terms Phrases as typed.
	 * @return bool
	 */
	private static function has_any( array $words, array $terms ) {
		$n = count( $words );
		foreach ( $terms as $term ) {
			$parts = array_values(
				array_filter(
					explode( ' ', PNChat_Text::fold( (string) $term ) ),
					function ( $p ) {
						return '' !== $p;
					}
				)
			);
			$k     = count( $parts );
			for ( $i = 0; $k && $i + $k <= $n; $i++ ) {
				$all = true;
				foreach ( $parts as $j => $p ) {
					$w = $words[ $i + $j ];
					if ( $w !== $p && ( strlen( $p ) < 4 || 0 !== strpos( $w, $p ) ) ) {
						$all = false;
						break;
					}
				}
				if ( $all ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * The reply to a remark (settings «smalltalk_*»), with the topic of the
	 * conversation when there is one.
	 *
	 * @param string $kind  Kind from detect().
	 * @param string $topic Conversation topic ('' for none).
	 * @return string
	 */
	public static function reply( $kind, $topic = '' ) {
		$s   = PNChat_Settings::get();
		$key = 'smalltalk_' . $kind;
		if ( 'praise' === $kind && '' !== $topic ) {
			$key = 'smalltalk_praise_topic';
		}
		return PNChat_Settings::fill( (string) ( $s[ $key ] ?? '' ), 'topic', $topic );
	}
}
