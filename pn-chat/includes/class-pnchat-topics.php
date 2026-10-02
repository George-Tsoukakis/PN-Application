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
		self::$topics = null;
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
	private static function hits( $text ) {
		$folded = PNChat_Text::fold( wp_strip_all_tags( (string) $text ) );
		if ( '' === $folded ) {
			return array();
		}
		$words = explode( ' ', $folded );
		$hits  = array();
		foreach ( self::all() as $t ) {
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
	public static function detect( $text ) {
		$hits = self::hits( $text );
		if ( ! $hits ) {
			return '';
		}
		arsort( $hits );
		return (string) array_key_first( $hits );
	}

	/**
	 * Topic of a trained entry, from its title, questions and answer.
	 *
	 * @param int $id Entry id.
	 * @return string
	 */
	public static function of_entry( $id ) {
		static $cache = array();
		if ( ! isset( $cache[ $id ] ) ) {
			$e            = PNChat_Store::entry( (int) $id );
			$cache[ $id ] = $e ? self::detect( $e['title'] . ' . ' . implode( ' . ', $e['phrasings'] ) . ' . ' . implode( ' . ', $e['keywords'] ) . ' . ' . $e['answer'] ) : '';
		}
		return $cache[ $id ];
	}

	/**
	 * A topic name from the client, if it is one of ours.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public static function valid( $name ) {
		foreach ( self::all() as $t ) {
			if ( $t['name'] === $name ) {
				return $name;
			}
		}
		return '';
	}
}
