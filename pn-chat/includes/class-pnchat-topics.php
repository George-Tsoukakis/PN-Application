<?php
/**
 * Conversation topics, so a follow-up question without a subject («Είναι
 * δωρεάν;») is understood in the topic of the previous answer («…το QR
 * ReBuilder;»). Topics are a settings list, one per line: the name first,
 * then other words that mean it.
 *
 * @package PNChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Topic detection.
 */
final class PNChat_Topics {

	/**
	 * Parsed topics of this request.
	 *
	 * @var array<int,array{name:string,terms:array<int,string[]>}>|null
	 */
	private static $topics = null;

	/**
	 * Settings topics and the ones taken from entry titles, this request.
	 *
	 * @var array<int,array{name:string,terms:array<int,string[]>}>|null
	 */
	private static $conversation = null;

	/**
	 * Topics from the settings.
	 *
	 * @return array<int,array{name:string,terms:array<int,string[]>}>
	 */
	public static function all() {
		if ( null === self::$topics ) {
			self::$topics = self::parse( (string) PNChat_Settings::value( 'topics' ) );
		}
		return self::$topics;
	}

	/**
	 * Drops the cache (settings changed).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$topics       = null;
		self::$conversation = null;
	}

	/**
	 * Topics that keep a conversation going: the settings list, plus every
	 * title prefix used by two or more entries («eΔΑΠΥ: …», «Γενόσημα: …»),
	 * so a follow-up («Και πώς την ακυρώνω;») stays on it without anyone
	 * listing it in the settings. Only for the conversation: the matcher's
	 * subjects and the AI's «tools only» check use the settings list alone.
	 *
	 * @return array<int,array{name:string,terms:array<int,string[]>}>
	 */
	public static function conversation() {
		if ( null !== self::$conversation ) {
			return self::$conversation;
		}
		$list  = self::all();
		$known = array();
		foreach ( $list as $t ) {
			foreach ( $t['terms'] as $words ) {
				$known[ implode( ' ', $words ) ] = true;
			}
		}
		$count = array();
		$names = array();
		foreach ( PNChat_Store::entries( 'answer', true ) as $e ) {
			$prefix = self::title_prefix( (string) $e['title'] );
			$f      = PNChat_Text::fold( $prefix );
			if ( '' === $f || isset( $known[ $f ] ) ) {
				continue;
			}
			$count[ $f ] = ( $count[ $f ] ?? 0 ) + 1;
			$names[ $f ] = $names[ $f ] ?? $prefix;
		}
		foreach ( $count as $f => $n ) {
			if ( $n >= 2 && mb_strlen( $f ) >= 3 ) {
				$list[] = array(
					'name'  => $names[ $f ],
					'terms' => array( explode( ' ', $f ) ),
				);
			}
		}
		self::$conversation = (array) apply_filters( 'pnchat_conversation_topics', $list );
		return self::$conversation;
	}

	/**
	 * «eΔΑΠΥ» of «eΔΑΠΥ: Άνοιγμα περιόδου»; '' for a title without one.
	 *
	 * @param string $title Entry title.
	 * @return string
	 */
	public static function title_prefix( $title ) {
		$pos = mb_strpos( $title, ':' );
		if ( false === $pos || $pos < 2 || $pos > 60 ) {
			return '';
		}
		return trim( mb_substr( $title, 0, $pos ) );
	}

	/**
	 * One topic per line: «Name, word, other words».
	 *
	 * @param string $text Setting.
	 * @return array<int,array{name:string,terms:array<int,string[]>}>
	 */
	public static function parse( $text ) {
		$out = array();
		foreach ( PNChat_Store::lines( (string) $text ) as $line ) {
			$terms = array();
			$name  = '';
			foreach ( explode( ',', $line ) as $w ) {
				$w = trim( $w );
				if ( '' === $w ) {
					continue;
				}
				if ( '' === $name ) {
					$name = $w;
				}
				$f = PNChat_Text::fold( $w );
				if ( '' !== $f ) {
					$terms[] = explode( ' ', $f );
				}
			}
			if ( '' !== $name && $terms ) {
				$out[] = array(
					'name'  => $name,
					'terms' => $terms,
				);
			}
		}
		return $out;
	}

	/**
	 * How often each topic is named in a text.
	 *
	 * @param string $text Any text.
	 * @return array<string,int> Topic name => hits (topics with hits only).
	 */
	private static function hits( $text, $conversation = false ) {
		$folded = PNChat_Text::fold( wp_strip_all_tags( (string) $text ) );
		if ( '' === $folded ) {
			return array();
		}
		$words = explode( ' ', $folded );
		$hits  = array();
		foreach ( $conversation ? self::conversation() : self::all() as $t ) {
			foreach ( $t['terms'] as $term ) {
				$all = true;
				foreach ( $term as $tw ) {
					$found = false;
					foreach ( $words as $w ) {
						if ( $w === $tw || PNChat_Text::token_similarity( $w, $tw ) >= 0.85 ) {
							$found = true;
							break;
						}
					}
					if ( ! $found ) {
						$all = false;
						break;
					}
				}
				if ( $all ) {
					$hits[ $t['name'] ] = ( $hits[ $t['name'] ] ?? 0 ) + 1;
				}
			}
		}
		return $hits;
	}

	/**
	 * The topic a text is about ('' for none): the most named one.
	 *
	 * @param string $text Any text.
	 * @return string
	 */
	public static function detect( $text, $conversation = false ) {
		$hits = self::hits( $text, $conversation );
		if ( ! $hits ) {
			return '';
		}
		arsort( $hits );
		return (string) array_key_first( $hits );
	}

	/**
	 * Topic of a trained entry: from its title, questions and keywords; the
	 * answer only when those name no topic (an answer that mentions another
	 * product in passing must not move the entry to that topic).
	 *
	 * @param int $id Entry id.
	 * @return string
	 */
	public static function of_entry( $id ) {
		static $cache = array();
		if ( ! isset( $cache[ $id ] ) ) {
			$e            = PNChat_Store::entry( (int) $id );
			$cache[ $id ] = $e ? self::of_entry_data( $e ) : '';
		}
		return $cache[ $id ];
	}

	/**
	 * Topic of an entry given as data (no database read).
	 *
	 * @param array<string,mixed> $e Entry.
	 * @return string
	 */
	public static function of_entry_data( array $e ) {
		// The title prefix says it best («Γενόσημα: …» is about generics
		// even when it mentions shortages).
		$prefix = self::title_prefix( (string) $e['title'] );
		$t      = '' !== $prefix ? self::detect( $prefix, true ) : '';
		if ( '' === $t ) {
			$t = self::detect( $e['title'] . ' . ' . implode( ' . ', (array) $e['phrasings'] ) . ' . ' . implode( ' . ', (array) $e['keywords'] ), true );
		}
		if ( '' === $t ) {
			$t = self::detect( (string) $e['answer'] );
		}
		return $t;
	}

	/**
	 * A topic name from the client, if it is one of ours.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public static function valid( $name ) {
		foreach ( self::conversation() as $t ) {
			if ( $t['name'] === $name ) {
				return $name;
			}
		}
		return '';
	}
}
