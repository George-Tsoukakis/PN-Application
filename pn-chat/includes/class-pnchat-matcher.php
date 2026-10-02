<?php
/**
 * The "brain": finds the trained answers for a question. Pure PHP, no
 * WordPress calls and no outside service; everything it knows is the list
 * of entries and synonyms the administrators trained.
 *
 * An entry is an array:
 *   id        int
 *   kind      'answer' | 'block'
 *   title     string    shown above the answer
 *   phrasings string[]  the ways the question is asked (main question first)
 *   keywords  string[]  words or phrases that point straight to this entry
 *   answer    string    the reply (answers) or the refusal text (blocks)
 *
 * @package PNChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Question matcher.
 */
final class PNChat_Matcher {

	/**
	 * Weight of a question word the brain has never seen (greetings, filler).
	 */
	const UNKNOWN_WEIGHT = 0.5;

	/**
	 * Most that unknown words can weigh together, so a long, polite question
	 * is not punished for its politeness.
	 */
	const UNKNOWN_CAP = 1.6;

	/**
	 * Indexed entries.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $entries = array();

	/**
	 * Token => inverse document frequency.
	 *
	 * @var array<string,float>
	 */
	private $idf = array();

	/**
	 * Folded single word => canonical word.
	 *
	 * @var array<string,string>
	 */
	private $syn_words = array();

	/**
	 * Folded multi-word phrase => canonical word, longest first.
	 *
	 * @var array<string,string>
	 */
	private $syn_phrases = array();

	/**
	 * weight() results of this request (it scans the whole vocabulary).
	 *
	 * @var array<string,float|null>
	 */
	private $weights = array();

	/**
	 * Subject terms (topic names: «PlanDose», «QR ReBuilder»), folded.
	 *
	 * @var array<int,string[]>
	 */
	private $subjects = array();

	/**
	 * Lowest score that counts as an answer.
	 *
	 * @var float
	 */
	private $threshold;

	/**
	 * Most entries combined in one reply.
	 *
	 * @var int
	 */
	private $max_items;

	/**
	 * Builds the index.
	 *
	 * @param array<int,array<string,mixed>> $entries   Active entries.
	 * @param array<int,string[]>            $synonyms  Groups of words that mean the same.
	 * @param float                          $threshold 0..1.
	 * @param int                            $max_items 1..5.
	 * @param array<int,string[]>            $subjects  Folded subject terms (topic names and their words).
	 */
	public function __construct( array $entries, array $synonyms = array(), $threshold = 0.5, $max_items = 3, array $subjects = array() ) {
		$this->subjects  = array_values( array_filter( $subjects ) );
		$this->threshold = max( 0.2, min( 0.95, (float) $threshold ) );
		$this->max_items = max( 1, min( 5, (int) $max_items ) );
		$this->load_synonyms( $synonyms );

		$df = array();
		foreach ( $entries as $e ) {
			$kind = ( isset( $e['kind'] ) && 'block' === $e['kind'] ) ? 'block' : 'answer';

			$phr = array();
			foreach ( (array) ( $e['phrasings'] ?? array() ) as $p ) {
				$t = $this->tokens( (string) $p );
				if ( $t ) {
					$phr[] = $t;
				}
			}
			$kw = array();
			foreach ( (array) ( $e['keywords'] ?? array() ) as $k ) {
				$t = $this->tokens( (string) $k, false );
				if ( $t ) {
					$kw[] = $t;
				}
			}
			if ( ! $phr && ! $kw ) {
				continue;
			}

			$seen = array();
			foreach ( array_merge( $phr, $kw ) as $list ) {
				foreach ( $list as $tok ) {
					$seen[ $tok ] = true;
				}
			}
			foreach ( array_keys( $seen ) as $tok ) {
				$df[ $tok ] = ( $df[ $tok ] ?? 0 ) + 1;
			}

			$this->entries[] = array(
				'id'        => (int) ( $e['id'] ?? 0 ),
				'kind'      => $kind,
				'title'     => (string) ( $e['title'] ?? '' ),
				'answer'    => (string) ( $e['answer'] ?? '' ),
				'phrasings' => $phr,
				'keywords'  => $kw,
			);
		}

		$n = count( $this->entries );
		foreach ( $df as $tok => $count ) {
			$this->idf[ (string) $tok ] = 1.0 + log( ( $n + 1 ) / ( $count + 0.5 ) );
		}
	}

	/**
	 * Answers a question.
	 *
	 * @param string $question The visitor's text.
	 * @return array{status:string,items:array<int,array<string,mixed>>,unmatched:string[],parts:array<int,array<string,mixed>>}
	 *   status: 'answered' (all parts), 'partial' (some parts), 'blocked'
	 *   (only refusals), 'unanswered' (nothing). parts: per-part debug scores.
	 */
	public function ask( $question ) {
		$question = trim( (string) $question );
		$result   = array(
			'status'    => 'unanswered',
			'items'     => array(),
			'unmatched' => array(),
			'parts'     => array(),
		);
		if ( '' === $question || ! $this->entries ) {
			return $result;
		}

		$parts = PNChat_Text::split_parts( $question );

		// The whole question first: a trained phrasing may itself contain «και».
		$whole        = $this->rank( $question );
		$picked       = array();
		$unmatched    = array();
		$whole_strong = $whole && $whole[0]['score'] >= $this->threshold;

		if ( count( $parts ) > 1 ) {
			$whole_hit = $whole_strong ? $this->choose( $whole ) : null;
			$used_whole = false;
			foreach ( $parts as $part ) {
				$ranked            = $this->rank( $part );
				$result['parts'][] = array(
					'text' => $part,
					'top'  => array_slice( $ranked, 0, 5 ),
				);
				$hit = $this->choose( $ranked );
				if ( $hit ) {
					$picked[] = $hit;
				} elseif ( $whole_hit && $this->part_covered( $part, $whole_hit ) ) {
					// The part only makes sense with the rest of the question
					// (a trained phrasing that itself contains «και»).
					if ( ! $used_whole ) {
						$picked[]   = $whole_hit;
						$used_whole = true;
					}
				} elseif ( $this->has_content( $part ) ) {
					$unmatched[] = $part;
				}
			}
		} else {
			$result['parts'][] = array(
				'text' => $question,
				'top'  => array_slice( $whole, 0, 5 ),
			);
			$hit = $this->choose( $whole );
			if ( $hit ) {
				$picked[] = $hit;
				// Close, and confident on their own: a question that touches two
				// trained topics.
				foreach ( array_slice( $whole, 1 ) as $c ) {
					if ( $c['score'] >= $this->threshold + 0.1 && $c['score'] >= 0.85 * $hit['score'] && $c['kind'] === $hit['kind'] ) {
						$picked[] = $c;
					}
				}
			} else {
				$unmatched[] = $question;
			}
		}

		// Unique, in order, at most max_items; refusals always kept.
		$items = array();
		$ids   = array();
		foreach ( $picked as $p ) {
			if ( ! $p || isset( $ids[ $p['id'] ] ) ) {
				continue;
			}
			$answers = count(
				array_filter(
					$items,
					function ( $i ) {
						return 'answer' === $i['kind'];
					}
				)
			);
			if ( 'answer' === $p['kind'] && $answers >= $this->max_items ) {
				continue;
			}
			$ids[ $p['id'] ] = true;
			$items[]         = $p;
		}

		$has_answer = false;
		$has_block  = false;
		foreach ( $items as $i ) {
			if ( 'block' === $i['kind'] ) {
				$has_block = true;
			} else {
				$has_answer = true;
			}
		}

		$result['items']     = $items;
		$result['unmatched'] = $unmatched;
		if ( $has_answer ) {
			$result['status'] = $unmatched ? 'partial' : 'answered';
		} elseif ( $has_block ) {
			$result['status'] = 'blocked';
		}
		return $result;
	}

	/**
	 * All entries scored for one text, best first (score > 0 only).
	 *
	 * @param string $text Question or part.
	 * @return array<int,array<string,mixed>>
	 */
	public function rank( $text ) {
		$q = $this->tokens( $text );
		if ( ! $q ) {
			return array();
		}
		$out = array();
		foreach ( $this->entries as $e ) {
			$score = $this->score( $q, $e );
			if ( $score > 0.0 ) {
				$out[] = array(
					'id'     => $e['id'],
					'kind'   => $e['kind'],
					'title'  => $e['title'],
					'answer' => $e['answer'],
					'score'  => round( $score, 3 ),
				);
			}
		}
		usort(
			$out,
			function ( $a, $b ) {
				if ( $a['score'] === $b['score'] ) {
					// Refusals win ties: safer to refuse than to answer.
					if ( $a['kind'] !== $b['kind'] ) {
						return 'block' === $a['kind'] ? -1 : 1;
					}
					return $a['id'] <=> $b['id'];
				}
				return $a['score'] < $b['score'] ? 1 : -1;
			}
		);
		return $out;
	}

	/**
	 * Best hit of a ranking, or null. A refusal close to the best answer
	 * wins: safer to refuse than to answer a forbidden question.
	 *
	 * @param array<int,array<string,mixed>> $ranked From rank().
	 * @return array<string,mixed>|null
	 */
	private function choose( array $ranked ) {
		if ( ! $ranked || $ranked[0]['score'] < $this->threshold ) {
			return null;
		}
		$best = $ranked[0];
		if ( 'answer' === $best['kind'] ) {
			foreach ( $ranked as $c ) {
				if ( 'block' === $c['kind'] && $c['score'] >= $this->threshold && $c['score'] >= 0.9 * $best['score'] ) {
					return $c;
				}
			}
		}
		return $best;
	}

	/**
	 * Score of one entry for the question tokens: the best of its phrasings,
	 * raised when one of its keywords is in the question.
	 *
	 * @param string[]             $q Question tokens.
	 * @param array<string,mixed>  $e Indexed entry.
	 * @return float 0..1
	 */
	private function score( array $q, array $e ) {
		$best = 0.0;
		foreach ( $e['phrasings'] as $p ) {
			$sim = $this->similarity( $q, $p );
			// The phrasing names a subject («…το PlanDose;») the question does
			// not: subject names are common across entries, so their weight is
			// low, yet they are what the question is about.
			if ( $sim > 0.0 && $this->subjects ) {
				foreach ( $this->subjects as $term ) {
					if ( $this->contains_all( $p, $term, 0.85 ) && ! $this->contains_all( $q, $term, 0.85 ) ) {
						$sim *= 0.7;
						break;
					}
				}
			}
			$best = max( $best, $sim );
		}
		foreach ( $e['keywords'] as $k ) {
			if ( $this->contains_keyword( $q, $k ) ) {
				// Keyword present: at least just above the default threshold,
				// and better the more of the question it explains.
				$known = $k;
				foreach ( $e['phrasings'] as $p ) {
					$known = array_merge( $known, $p );
				}
				$best = max( $best + 0.25, 0.55 + 0.3 * $this->precision( $q, $known ) );
			}
		}
		return min( 1.0, $best );
	}

	/**
	 * F1 of the two token lists, weighted by idf.
	 *
	 * @param string[] $q Question tokens.
	 * @param string[] $p Phrasing tokens.
	 * @return float
	 */
	private function similarity( array $q, array $p ) {
		$recall_num = 0.0;
		$recall_den = 0.0;
		foreach ( $p as $pt ) {
			$w           = $this->idf[ $pt ] ?? 1.0;
			$recall_den += $w;
			$recall_num += $w * $this->best_match( $pt, $q );
		}
		if ( $recall_den <= 0.0 || $recall_num <= 0.0 ) {
			return 0.0;
		}
		$recall    = $recall_num / $recall_den;
		$precision = $this->precision( $q, $p );
		if ( $precision <= 0.0 ) {
			return 0.0;
		}
		$f1 = 2 * $recall * $precision / ( $recall + $precision );
		// Less than half of the trained question is there (one common word
		// such as «κόστος» out of «κόστος + QR ReBuilder») and the question
		// also says other things («πόσο κάνει ένα αυτοκίνητο»): not this one.
		// A short question fully explained by the entry («πόσο κάνει;») stays.
		if ( $recall < 0.5 && $precision < 0.9 ) {
			$f1 *= $recall / 0.5;
		}
		// Both sides only partly matched («Δουλεύετε Σάββατο απόγευμα στην
		// Πάτρα;» against «Πώς δουλεύει το QR;»: one shared word): weak.
		if ( $recall < 0.67 && $precision < 0.67 ) {
			$f1 *= min( $recall, $precision ) / 0.67;
		}
		return $f1;
	}

	/**
	 * Share of the question explained by the tokens.
	 *
	 * @param string[] $q   Question tokens.
	 * @param string[] $ref Tokens of the entry.
	 * @return float
	 */
	private function precision( array $q, array $ref ) {
		$num     = 0.0;
		$den     = 0.0;
		$unknown = 0.0;
		foreach ( $q as $qt ) {
			$m = $this->best_match( $qt, $ref );
			$w = $this->weight( $qt );
			if ( null === $w ) {
				// Never trained anywhere: probably filler; counts a little.
				if ( $m > 0.0 ) {
					$num += self::UNKNOWN_WEIGHT * $m;
					$den += self::UNKNOWN_WEIGHT;
				} elseif ( $unknown < self::UNKNOWN_CAP ) {
					$add      = min( self::UNKNOWN_WEIGHT, self::UNKNOWN_CAP - $unknown );
					$unknown += $add;
					$den     += $add;
				}
				continue;
			}
			$den += $w;
			$num += $w * $m;
		}
		return $den > 0.0 ? $num / $den : 0.0;
	}

	/**
	 * Idf of a question word, matching trained words loosely; null if the
	 * brain has never seen anything like it.
	 *
	 * @param string $tok Folded token.
	 * @return float|null
	 */
	private function weight( $tok ) {
		if ( isset( $this->idf[ $tok ] ) ) {
			return $this->idf[ $tok ];
		}
		if ( array_key_exists( $tok, $this->weights ) ) {
			return $this->weights[ $tok ];
		}
		$best = null;
		$sim  = 0.0;
		foreach ( $this->idf as $known => $w ) {
			$s = PNChat_Text::token_similarity( $tok, (string) $known );
			if ( $s > $sim ) {
				$sim  = $s;
				$best = $w;
			}
		}
		$this->weights[ $tok ] = $best;
		return $best;
	}

	/**
	 * Best similarity of a token against a list.
	 *
	 * @param string   $tok  Token.
	 * @param string[] $list Tokens.
	 * @return float
	 */
	private function best_match( $tok, array $list ) {
		$best = 0.0;
		foreach ( $list as $t ) {
			$s = PNChat_Text::token_similarity( $tok, $t );
			if ( $s > $best ) {
				$best = $s;
				if ( 1.0 === $best ) {
					break;
				}
			}
		}
		return $best;
	}

	/**
	 * Share of the question's own words (the ones trained anywhere) that an
	 * entry has. «Πόσο κοστίζει;» is fully covered by «Κόστος συμμετοχής στην
	 * Κοινότητα Viber» (κοστίζει = κόστος); «Έχει εφαρμογή για iPhone;» is not
	 * covered by «Τι είναι το QR ReBuilder».
	 *
	 * @param string $text Question.
	 * @param int    $id   Entry id.
	 * @return float 0..1 (0 when the question has no trained word).
	 */
	public function coverage( $text, $id ) {
		$entry = null;
		foreach ( $this->entries as $e ) {
			if ( (int) $e['id'] === (int) $id ) {
				$entry = $e;
				break;
			}
		}
		if ( ! $entry ) {
			return 0.0;
		}
		$known = array();
		foreach ( array_merge( $entry['phrasings'], $entry['keywords'] ) as $list ) {
			$known = array_merge( $known, $list );
		}
		$n   = 0;
		$hit = 0;
		foreach ( array_unique( $this->tokens( $text ) ) as $t ) {
			if ( null === $this->weight( $t ) ) {
				continue;
			}
			++$n;
			if ( $this->best_match( $t, $known ) >= 0.75 ) {
				++$hit;
			}
		}
		return $n ? $hit / $n : 0.0;
	}

	/**
	 * Every keyword token is (loosely) in the question.
	 *
	 * @param string[] $q  Question tokens.
	 * @param string[] $kw  Keyword tokens.
	 * @param float    $min Lowest similarity per word.
	 * @return bool
	 */
	private function contains_all( array $q, array $kw, $min = 0.75 ) {
		foreach ( $kw as $k ) {
			if ( $this->best_match( $k, $q ) < $min ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Every keyword token is in the question. Short ones (four letters or
	 * fewer, mostly acronyms: ΠΕΔΙ, ΑΜΚΑ, ΕΟΦ) only as they are: loosely,
	 * «ΠΕΔΙ» would be the start of «παιδιά» and pull a question about
	 * children to the entry.
	 *
	 * @param string[] $q  Question tokens.
	 * @param string[] $kw Keyword tokens.
	 * @return bool
	 */
	private function contains_keyword( array $q, array $kw ) {
		foreach ( $kw as $k ) {
			if ( strlen( $k ) <= 4 ? ! in_array( $k, $q, true ) : $this->best_match( $k, $q ) < 0.75 ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The part has a word that is not a stop-word (worth reporting).
	 *
	 * @param string $part Text.
	 * @return bool
	 */
	private function has_content( $part ) {
		$stop = PNChat_Text::stopwords();
		foreach ( $this->tokens( $part ) as $t ) {
			if ( ! isset( $stop[ $t ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The words of a part are all in the whole-question winner.
	 *
	 * @param string              $part Part text.
	 * @param array<string,mixed> $hit  Ranked item.
	 * @return bool
	 */
	private function part_covered( $part, array $hit ) {
		foreach ( $this->entries as $e ) {
			if ( $e['id'] !== $hit['id'] ) {
				continue;
			}
			$q = $this->tokens( $part );
			foreach ( $e['phrasings'] as $p ) {
				if ( $this->precision( $q, $p ) >= 0.75 ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Folded tokens with synonyms replaced by their group's first word.
	 *
	 * @param string $text          Any text.
	 * @param bool   $drop_stopwords Keywords keep every word the admin typed.
	 * @return string[]
	 */
	private function tokens( $text, $drop_stopwords = true ) {
		$folded = PNChat_Text::fold( $text );
		if ( '' === $folded ) {
			return array();
		}
		if ( $this->syn_phrases ) {
			$padded = ' ' . $folded . ' ';
			foreach ( $this->syn_phrases as $phrase => $canon ) {
				$padded = str_replace( ' ' . $phrase . ' ', ' ' . $canon . ' ', $padded );
			}
			$folded = trim( $padded );
		}
		$all = explode( ' ', $folded );
		foreach ( $all as $i => $t ) {
			if ( isset( $this->syn_words[ $t ] ) ) {
				$all[ $i ] = $this->syn_words[ $t ];
			}
		}
		if ( ! $drop_stopwords ) {
			return $all;
		}
		$stop = PNChat_Text::stopwords();
		$kept = array();
		foreach ( $all as $t ) {
			if ( ! isset( $stop[ $t ] ) ) {
				$kept[] = $t;
			}
		}
		return $kept ? $kept : $all;
	}

	/**
	 * Reads the synonym groups.
	 *
	 * @param array<int,string[]> $groups Groups.
	 * @return void
	 */
	private function load_synonyms( array $groups ) {
		foreach ( $groups as $group ) {
			$canon = null;
			foreach ( (array) $group as $word ) {
				$f = PNChat_Text::fold( (string) $word );
				if ( '' === $f ) {
					continue;
				}
				if ( null === $canon ) {
					// The canonical form is one token even for a phrase.
					$canon = str_replace( ' ', '_', $f );
				}
				if ( false !== strpos( $f, ' ' ) ) {
					$this->syn_phrases[ $f ] = $canon;
				} else {
					$this->syn_words[ $f ] = $canon;
				}
			}
		}
		uksort(
			$this->syn_phrases,
			function ( $a, $b ) {
				return strlen( $b ) <=> strlen( $a );
			}
		);
	}

	/**
	 * Splits the admin's synonym text: one group per line, words separated
	 * by commas or «=».
	 *
	 * @param string $text Textarea value.
	 * @return array<int,string[]>
	 */
	public static function parse_synonyms( $text ) {
		$groups = array();
		foreach ( preg_split( '/\R/u', (string) $text ) as $line ) {
			$words = array();
			foreach ( preg_split( '/[,=]/u', (string) $line ) as $w ) {
				$w = trim( (string) $w );
				if ( '' !== $w ) {
					$words[] = $w;
				}
			}
			if ( count( $words ) >= 2 ) {
				$groups[] = $words;
			}
		}
		return $groups;
	}
}
