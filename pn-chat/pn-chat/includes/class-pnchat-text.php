<?php
/**
 * Text folding for the matcher. Pure PHP, no WordPress calls (tests load it alone).
 *
 * Greek and Greeklish are folded to the same phonetic Latin form, so that
 * «Τι είναι το PlanDose;», «τι ειναι το plandose» and «ti einai to plandose»
 * produce the same tokens. Folding also absorbs the usual Greek spelling
 * slips (ι/η/υ/ει/οι, ο/ω, αι/ε, double letters), because both the
 * question and the trained texts go through it.
 *
 * @package PNChat
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'PNCHAT_TESTING' ) ) {
	exit;
}

/**
 * Normalisation and tokenisation.
 */
final class PNChat_Text {

	/**
	 * Greek digraphs, applied before the single letters.
	 *
	 * @var array<string,string>
	 */
	const GREEK_DIGRAPHS = array(
		'ου' => 'ou',
		'αυ' => 'av',
		'ευ' => 'ev',
		'μπ' => 'mp',
		'ντ' => 'nt',
		'γκ' => 'g',
		'γγ' => 'g',
		'τσ' => 'ts',
		'τζ' => 'tz',
	);

	/**
	 * Greek letters (accents already removed).
	 *
	 * @var array<string,string>
	 */
	const GREEK_LETTERS = array(
		'α' => 'a',
		'β' => 'v',
		'γ' => 'g',
		'δ' => 'd',
		'ε' => 'e',
		'ζ' => 'z',
		'η' => 'i',
		'θ' => '8',
		'ι' => 'i',
		'κ' => 'k',
		'λ' => 'l',
		'μ' => 'm',
		'ν' => 'n',
		'ξ' => 'x',
		'ο' => 'o',
		'π' => 'p',
		'ρ' => 'r',
		'σ' => 's',
		'ς' => 's',
		'τ' => 't',
		'υ' => 'i',
		'φ' => 'f',
		'χ' => 'x',
		'ψ' => 'ps',
		'ω' => 'o',
	);

	/**
	 * Latin (Greeklish) rewrites to the same phonetic form. strtr() tries the
	 * longest key first and never rewrites its own output, so Greek μπ/ντ are
	 * transliterated to "mp"/"nt" above and folded here, once, like Greeklish.
	 *
	 * @var array<string,string>
	 */
	const LATIN_FOLDS = array(
		'th' => '8',
		'ph' => 'f',
		'ch' => 'x',
		'ks' => 'x',
		'mp' => 'b',
		'nt' => 'd',
		'af' => 'av',
		'ef' => 'ev',
		'h'  => 'i',
		'w'  => 'o',
		'y'  => 'i',
		'u'  => 'ou',
		'c'  => 'k',
		'q'  => 'k',
		'j'  => 'g',
	);

	/**
	 * Vowel pairs that sound like one vowel.
	 *
	 * @var array<string,string>
	 */
	const VOWEL_FOLDS = array(
		'oou' => 'ou',
		'ouou' => 'ou',
		'ei' => 'i',
		'oi' => 'i',
		'ai' => 'e',
	);

	/**
	 * Common Greek / English words that carry no topic.
	 * Folded at first use, so they can be written naturally here.
	 *
	 * @var string[]
	 */
	const STOPWORDS = array(
		'ο', 'η', 'το', 'οι', 'τα', 'του', 'της', 'των', 'τον', 'την', 'τη', 'τους', 'τις',
		'ένας', 'μια', 'μία', 'ένα', 'ενός', 'μιας',
		'και', 'κι', 'ή', 'να', 'θα', 'με', 'σε', 'σας', 'μας', 'μου', 'σου', 'τους',
		'στο', 'στη', 'στην', 'στον', 'στα', 'στις', 'στους', 'στου', 'στης',
		'για', 'από', 'απο', 'προς', 'πως', 'πώς', 'τι', 'τί', 'ποιο', 'ποια', 'ποιος',
		'που', 'πού', 'είναι', 'ειναι', 'ειμαι', 'είμαι', 'είσαι', 'ήταν', 'έχω', 'έχει',
		'αυτό', 'αυτή', 'αυτός', 'αυτά', 'εγώ', 'εσύ', 'εσείς', 'εμείς', 'μπορώ', 'μπορείτε',
		'μπορεί', 'θέλω', 'θελω', 'παρακαλώ', 'λοιπόν', 'δηλαδή', 'όταν', 'αν', 'εάν',
		'κάνει', 'κάνω', 'κάνετε', 'γίνεται', 'υπάρχει', 'έχετε', 'θέλετε', 'ξέρετε', 'πείτε', 'πες',
		'ήθελα', 'ρωτήσω', 'ερώτηση', 'σχετικά', 'σχετικα',
		'πιο', 'πολύ', 'κάτι', 'κάποιο', 'κάποια', 'όλα', 'ότι', 'οτι', 'δε', 'δεν', 'μη', 'μην',
		'the', 'a', 'an', 'is', 'are', 'what', 'how', 'to', 'of', 'and', 'or', 'in', 'on',
		'for', 'do', 'does', 'i', 'you', 'can', 'my', 'me', 'it', 'this', 'that', 'with',
	);

	/**
	 * Folded stop-words, built once.
	 *
	 * @var array<string,bool>|null
	 */
	private static $stop = null;

	/**
	 * Lower-case, strip accents and punctuation, and transliterate to the
	 * phonetic Latin form. Returns words separated by single spaces.
	 *
	 * @param string $text Any text.
	 * @return string
	 */
	public static function fold( $text ) {
		$text = (string) $text;
		if ( '' === $text ) {
			return '';
		}
		if ( function_exists( 'mb_strtolower' ) ) {
			$text = mb_strtolower( $text, 'UTF-8' );
		} else {
			$text = strtolower( $text );
		}

		// Accents: decompose and drop the combining marks when intl is there,
		// otherwise the explicit Greek table below does the same job.
		if ( class_exists( 'Normalizer' ) ) {
			$decomposed = Normalizer::normalize( $text, Normalizer::FORM_D );
			if ( is_string( $decomposed ) ) {
				$text = (string) preg_replace( '/\p{Mn}+/u', '', $decomposed );
			}
		}
		$text = strtr(
			$text,
			array(
				'ά' => 'α',
				'έ' => 'ε',
				'ή' => 'η',
				'ί' => 'ι',
				'ϊ' => 'ι',
				'ΐ' => 'ι',
				'ό' => 'ο',
				'ύ' => 'υ',
				'ϋ' => 'υ',
				'ΰ' => 'υ',
				'ώ' => 'ω',
				'à' => 'a',
				'á' => 'a',
				'é' => 'e',
				'è' => 'e',
				'í' => 'i',
				'ó' => 'o',
				'ú' => 'u',
				'ü' => 'u',
				'ö' => 'o',
				'ä' => 'a',
			)
		);

		// Anything that is not a letter or a digit separates words.
		$text = (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );

		$text = strtr( $text, self::GREEK_DIGRAPHS );
		$text = strtr( $text, self::GREEK_LETTERS );

		// Greeklish "8" (θ) must survive the digit/letter split, so the Latin
		// folds run per word and only on letter runs.
		$words = preg_split( '/\s+/', trim( $text ) );
		$out   = array();
		foreach ( (array) $words as $word ) {
			if ( '' === $word ) {
				continue;
			}
			if ( preg_match( '/^[0-9]+$/', $word ) ) {
				$out[] = $word;
				continue;
			}
			$word = strtr( $word, self::LATIN_FOLDS );
			$word = strtr( $word, self::VOWEL_FOLDS );
			// Double letters: "llll" -> "l", "kk" -> "k".
			$word = (string) preg_replace( '/(.)\1+/u', '$1', $word );
			if ( '' !== $word ) {
				$out[] = $word;
			}
		}
		return implode( ' ', $out );
	}

	/**
	 * Folded tokens without stop-words. Keeps stop-words when the text is
	 * made only of them (so that «τι;» is not an empty question).
	 *
	 * @param string $text Any text.
	 * @return string[]
	 */
	public static function tokens( $text ) {
		$folded = self::fold( $text );
		if ( '' === $folded ) {
			return array();
		}
		$all  = explode( ' ', $folded );
		$stop = self::stopwords();
		$kept = array();
		foreach ( $all as $tok ) {
			if ( ! isset( $stop[ $tok ] ) ) {
				$kept[] = $tok;
			}
		}
		return $kept ? $kept : $all;
	}

	/**
	 * Folded stop-word set.
	 *
	 * @return array<string,bool>
	 */
	public static function stopwords() {
		if ( null === self::$stop ) {
			self::$stop = array();
			foreach ( self::STOPWORDS as $w ) {
				foreach ( explode( ' ', self::fold( $w ) ) as $f ) {
					if ( '' !== $f ) {
						self::$stop[ $f ] = true;
					}
				}
			}
		}
		return self::$stop;
	}

	/**
	 * Light stem: Greek endings vary a lot (εκτύπωση, εκτυπώσεις, εκτυπώνω),
	 * so two words match when they share a long enough beginning.
	 *
	 * @param string $a Folded token.
	 * @param string $b Folded token.
	 * @return float 1.0 identical, 0.0..0.9 partial, 0 no match.
	 */
	public static function token_similarity( $a, $b ) {
		if ( $a === $b ) {
			return 1.0;
		}
		$la = strlen( $a );
		$lb = strlen( $b );
		$min = min( $la, $lb );
		if ( $min < 3 ) {
			return 0.0;
		}
		// Shared beginning.
		$p = 0;
		while ( $p < $min && $a[ $p ] === $b[ $p ] ) {
			++$p;
		}
		if ( $p >= 4 && $p >= 0.6 * max( $la, $lb ) ) {
			return 0.9;
		}
		if ( $p >= 5 && $p === $min ) {
			// One word is the start of the other (πλάνο / πλάνου / πλάνων...).
			return 0.85;
		}
		if ( $p >= 4 && $p >= 0.5 * max( $la, $lb ) ) {
			return 0.75;
		}
		// One typo in a longer word.
		if ( $min >= 5 && abs( $la - $lb ) <= 1 && levenshtein( $a, $b ) <= 1 ) {
			return 0.8;
		}
		if ( $min >= 8 && abs( $la - $lb ) <= 2 && levenshtein( $a, $b ) <= 2 ) {
			return 0.7;
		}
		return 0.0;
	}

	/**
	 * Splits a question into its parts, so that «Τι είναι το PlanDose και πόσο
	 * κοστίζει;» can be answered piece by piece.
	 *
	 * @param string $text The visitor's question.
	 * @return string[] Original-text parts (not folded); at least one.
	 */
	public static function split_parts( $text ) {
		$text  = trim( (string) $text );
		$joins = 'και|κι|επίσης|επισης|ακόμα|ακομα|ακόμη|ακομη|αλλά|αλλα|kai|ki|episis|akoma|alla|and|also';
		$asks  = 'τι|τί|πως|πώς|πόσο|ποσο|πόσα|ποσα|πόσες|ποσες|ποιο|ποια|ποιος|ποιοι|ποιες|πού|που|πότε|ποτε|γιατί|γιατι|μπορώ|μπορω|μπορεί|μπορει|μπορούν|μπορουν|υπάρχει|υπαρχει|έχει|εχει|ti|pos|pws|poso|posa|poses|poio|poia|poios|poioi|poies|pou|pote|giati|mporo|mporw|mporei|mporoun|yparxei|iparxei|exei|what|how|can|is|does|where|when|why|who';
		$parts = preg_split( '/[?;;!\n]+|\.\s+|\s+(?:' . $joins . ')\s+(?=(?:' . $asks . ')\b)/iu', $text );
		$out   = array();
		foreach ( (array) $parts as $p ) {
			$p = trim( (string) $p, " \t,.-" );
			if ( '' !== $p ) {
				$out[] = $p;
			}
		}
		return $out ? $out : array( $text );
	}
}
