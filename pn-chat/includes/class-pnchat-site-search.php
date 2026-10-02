<?php
/**
 * Search in the site's own published pages and posts, for questions the
 * trained brain cannot answer. No outside service: each page keeps its folded
 * words in a post meta (updated when the page is saved), and a question is
 * matched against them with the same Greek / Greeklish folding as the brain.
 *
 * @package PNChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site search.
 */
final class PNChat_Site_Search {

	const META        = '_pnchat_tokens';
	const MAX_WORDS   = 1500;  // Distinct words kept per page.
	const MAX_CHARS   = 60000; // Characters of a page that are read.
	const MIN_SCORE   = 0.6;   // Share of the question a page must cover.
	const BATCH       = 40;    // Pages indexed per background run.
	const CRON_HOOK   = 'pnchat_site_index';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'on_save' ), 20, 2 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_batch' ) );
	}

	/**
	 * Post types searched.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		$types = PNChat_Store::lines( str_replace( ',', "\n", (string) PNChat_Settings::value( 'site_types' ) ) );
		$types = array_values( array_filter( $types, 'post_type_exists' ) );
		return (array) apply_filters( 'pnchat_site_post_types', $types ? $types : array( 'post', 'page' ) );
	}

	/**
	 * Page ids never shown (settings list of ids/slugs, shop and account pages).
	 *
	 * @return int[]
	 */
	public static function excluded_ids() {
		$ids = array();
		foreach ( PNChat_Store::lines( str_replace( ',', "\n", (string) PNChat_Settings::value( 'site_exclude' ) ) ) as $item ) {
			if ( ctype_digit( $item ) ) {
				$ids[] = (int) $item;
				continue;
			}
			$slug  = sanitize_title( basename( untrailingslashit( (string) wp_parse_url( $item, PHP_URL_PATH ) ) ) );
			$found = $slug ? get_page_by_path( $slug, OBJECT, self::post_types() ) : null;
			if ( $found ) {
				$ids[] = (int) $found->ID;
			}
		}
		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'cart', 'checkout', 'myaccount' ) as $wc ) {
				$ids[] = (int) wc_get_page_id( $wc );
			}
		}
		$ids[] = (int) get_option( 'page_for_posts' );
		return array_values( array_unique( array_filter( array_map( 'intval', (array) apply_filters( 'pnchat_site_excluded_ids', $ids ) ) ) ) );
	}

	/**
	 * The page may appear in answers: public, published, no password.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public static function searchable( $post ) {
		return $post instanceof WP_Post
			&& 'publish' === $post->post_status
			&& '' === (string) $post->post_password
			&& in_array( $post->post_type, self::post_types(), true )
			&& ! in_array( (int) $post->ID, self::excluded_ids(), true );
	}

	/**
	 * Plain text of a page (title not included).
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function plain_text( $post ) {
		$html = (string) $post->post_content;
		$html = (string) preg_replace( '#<(script|style|noscript|svg)\b[^>]*>.*?</\1>#is', ' ', $html );
		$html = (string) preg_replace( '/<!--.*?-->/s', ' ', $html );
		$html = strip_shortcodes( $html );
		// Block boundaries and line breaks end sentences.
		$html = (string) preg_replace( '#</(p|li|h[1-6]|div|tr|td|th|section|article|blockquote)>|<br\s*/?>#i', "\n", $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
		$text = (string) preg_replace( "/[ \t\x{00A0}]+/u", ' ', $text );
		$text = (string) preg_replace( "/\n\s*\n+/", "\n", $text );
		return trim( mb_substr( $text, 0, self::MAX_CHARS ) );
	}

	/**
	 * Index string of a page: " title words | body words ".
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function build_tokens( $post ) {
		$stop  = PNChat_Text::stopwords();
		$words = function ( $text, $limit ) use ( $stop ) {
			$out = array();
			foreach ( explode( ' ', PNChat_Text::fold( $text ) ) as $w ) {
				if ( '' === $w || isset( $stop[ $w ] ) || isset( $out[ $w ] ) || mb_strlen( $w ) < 2 ) {
					continue;
				}
				$out[ $w ] = true;
				if ( count( $out ) >= $limit ) {
					break;
				}
			}
			return array_keys( $out );
		};
		$title = $words( get_the_title( $post ), 60 );
		$body  = $words( self::plain_text( $post ) . ' ' . (string) $post->post_excerpt, self::MAX_WORDS );
		return ' ' . implode( ' ', $title ) . ' | ' . implode( ' ', $body ) . ' ';
	}

	/**
	 * Indexes one page (or removes it when it is not searchable).
	 *
	 * @param int $post_id Post id.
	 * @return bool Indexed.
	 */
	public static function index_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! self::searchable( $post ) ) {
			delete_post_meta( (int) $post_id, self::META );
			return false;
		}
		update_post_meta( (int) $post_id, self::META, self::build_tokens( $post ) );
		return true;
	}

	/**
	 * Keeps the index current when a page is saved.
	 *
	 * @param int     $post_id Post id.
	 * @param WP_Post $post    Post.
	 * @return void
	 */
	public static function on_save( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! $post instanceof WP_Post ) {
			return;
		}
		if ( in_array( $post->post_type, self::post_types(), true ) || '' !== (string) get_post_meta( $post_id, self::META, true ) ) {
			self::index_post( $post_id );
		}
	}

	/**
	 * Ids of searchable pages that have no index yet.
	 *
	 * @param int $limit Most ids.
	 * @return int[]
	 */
	public static function missing_ids( $limit ) {
		$excluded = self::excluded_ids();
		$q        = new WP_Query(
			array(
				'post_type'              => self::post_types(),
				'post_status'            => 'publish',
				'has_password'           => false,
				// Excluded pages never get an index, so they are skipped below.
				'posts_per_page'         => (int) $limit + count( $excluded ),
				'fields'                 => 'ids',
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- runs in the background, in small batches.
				'meta_query'             => array(
					array(
						'key'     => self::META,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		$ids = array_values( array_diff( array_map( 'intval', $q->posts ), $excluded ) );
		return array_slice( $ids, 0, (int) $limit );
	}

	/**
	 * Cron callback (actions return nothing).
	 *
	 * @return void
	 */
	public static function run_batch() {
		self::index_batch();
	}

	/**
	 * Background indexing: a batch now, the next one a minute later.
	 *
	 * @return int Pages indexed.
	 */
	public static function index_batch() {
		$n = 0;
		foreach ( self::missing_ids( self::BATCH ) as $id ) {
			if ( self::index_post( $id ) ) {
				++$n;
			}
		}
		if ( $n >= self::BATCH && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_HOOK );
		}
		return $n;
	}

	/**
	 * Rebuilds the whole index now (admin button).
	 *
	 * @return int Pages indexed.
	 */
	public static function rebuild() {
		delete_post_meta_by_key( self::META );
		$n = 0;
		do {
			$batch = self::missing_ids( 200 );
			foreach ( $batch as $id ) {
				if ( self::index_post( $id ) ) {
					++$n;
				}
			}
		} while ( count( $batch ) >= 200 && $n < 5000 );
		return $n;
	}

	/**
	 * Starts background indexing (activation, settings change).
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK );
		}
	}

	/**
	 * Indexed pages: id => index string.
	 *
	 * @return array<int,string>
	 */
	private static function documents() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT pm.post_id, pm.meta_value FROM %i pm INNER JOIN %i p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_status = %s AND p.post_password = %s',
				$wpdb->postmeta,
				$wpdb->posts,
				self::META,
				'publish',
				''
			),
			ARRAY_A
		);
		$docs     = array();
		$excluded = array_flip( self::excluded_ids() );
		foreach ( (array) $rows as $r ) {
			if ( ! isset( $excluded[ (int) $r['post_id'] ] ) ) {
				$docs[ (int) $r['post_id'] ] = (string) $r['meta_value'];
			}
		}
		return $docs;
	}

	/**
	 * Number of indexed pages.
	 *
	 * @return int
	 */
	public static function count() {
		return count( self::documents() );
	}

	/**
	 * Question words, each with its synonyms (folded).
	 *
	 * @param string $text Question.
	 * @return array<int,string[]>
	 */
	private static function query_words( $text ) {
		$groups = array();
		foreach ( PNChat_Matcher::parse_synonyms( (string) PNChat_Settings::value( 'synonyms' ) ) as $g ) {
			$folded = array();
			foreach ( $g as $w ) {
				$f = PNChat_Text::fold( $w );
				if ( '' !== $f && false === strpos( $f, ' ' ) ) {
					$folded[] = $f;
				}
			}
			$groups[] = $folded;
		}
		$out = array();
		foreach ( PNChat_Text::tokens( $text ) as $t ) {
			if ( mb_strlen( $t ) < 2 ) {
				continue;
			}
			$alts = array( $t );
			foreach ( $groups as $g ) {
				if ( in_array( $t, $g, true ) ) {
					$alts = array_values( array_unique( array_merge( $alts, $g ) ) );
				}
			}
			$out[ $t ] = $alts;
		}
		return array_values( $out );
	}

	/**
	 * Best similarity of a word (or its synonyms) inside an index string.
	 *
	 * @param string[] $alts Word and synonyms.
	 * @param string   $doc  Part of an index string.
	 * @return float
	 */
	private static function best_in( array $alts, $doc ) {
		$best = 0.0;
		foreach ( $alts as $w ) {
			if ( false !== strpos( $doc, ' ' . $w . ' ' ) ) {
				return 1.0;
			}
			if ( strlen( $w ) < 4 ) {
				continue;
			}
			$prefix = substr( $w, 0, 4 );
			if ( false === strpos( $doc, ' ' . $prefix ) ) {
				continue;
			}
			if ( preg_match_all( '/ (' . preg_quote( $prefix, '/' ) . '[^ ]*)/', $doc, $m ) ) {
				foreach ( $m[1] as $cand ) {
					$best = max( $best, PNChat_Text::token_similarity( $w, $cand ) );
				}
			}
		}
		return $best;
	}

	/**
	 * Pages that answer the question, best first.
	 *
	 * @param string $question Question.
	 * @param int    $limit    Most results.
	 * @return array<int,array{id:int,score:float,title:string,url:string,snippet:string}>
	 */
	public static function search( $question, $limit = 3 ) {
		$words = self::query_words( $question );
		if ( ! $words ) {
			return array();
		}
		$docs = self::documents();
		if ( ! $docs ) {
			return array();
		}

		// Similarity of every word in every page, and document frequencies.
		$hits = array();
		$df   = array_fill( 0, count( $words ), 0 );
		foreach ( $docs as $id => $doc ) {
			$bar   = strpos( $doc, ' | ' );
			$title = false === $bar ? '' : substr( $doc, 0, $bar + 1 );
			$body  = false === $bar ? $doc : substr( $doc, $bar + 2 );
			foreach ( $words as $i => $alts ) {
				$st = self::best_in( $alts, $title );
				$sb = 1.0 === $st ? 0.0 : self::best_in( $alts, $body );
				$s  = max( $st, 0.85 * $sb );
				if ( $s >= 0.6 ) {
					$hits[ $id ][ $i ] = array( $s, $st >= 0.75 );
					++$df[ $i ];
				}
			}
		}
		if ( ! $hits ) {
			return array();
		}

		$n       = count( $docs );
		$weights = array();
		foreach ( $words as $i => $alts ) {
			// A word no page has: probably filler; it counts a little.
			$weights[ $i ] = $df[ $i ] > 0 ? 1.0 + log( ( $n + 1 ) / ( $df[ $i ] + 0.5 ) ) : 0.5;
		}
		$total = array_sum( $weights );
		$known = count( array_filter( $df ) );

		$scored = array();
		foreach ( $hits as $id => $h ) {
			$sum   = 0.0;
			$title = false;
			foreach ( $h as $i => $hit ) {
				$sum  += $weights[ $i ] * $hit[0];
				$title = $title || $hit[1];
			}
			$score = $sum / $total + ( $title ? 0.1 : 0.0 );
			// Most of the known words must be on the page (at least two of
			// them when the question has two or more).
			if ( $score < self::MIN_SCORE || count( $h ) < min( 2, $known ) ) {
				continue;
			}
			$scored[ $id ] = min( 1.0, $score );
		}
		arsort( $scored );
		// Only pages close to the best one: a page that shares two common
		// words with the question is noise next to the page about it.
		$top    = $scored ? (float) reset( $scored ) : 0.0;
		$scored = array_filter(
			$scored,
			function ( $sc ) use ( $top ) {
				return $sc >= 0.8 * $top;
			}
		);

		$out = array();
		foreach ( array_slice( $scored, 0, max( 1, (int) $limit ), true ) as $id => $score ) {
			$post = get_post( $id );
			if ( ! self::searchable( $post ) ) {
				continue;
			}
			$out[] = array(
				'id'      => (int) $id,
				'score'   => round( $score, 3 ),
				'title'   => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
				'url'     => (string) get_permalink( $post ),
				'snippet' => self::snippet( $post, $words ),
			);
		}
		return $out;
	}

	/**
	 * The sentence of the page that best matches the question.
	 *
	 * @param WP_Post             $post  Post.
	 * @param array<int,string[]> $words Question words with synonyms.
	 * @return string
	 */
	private static function snippet( $post, array $words ) {
		$text      = self::plain_text( $post );
		$sentences = preg_split( '/(?<=[.;!?·])\s+|\n+/u', $text );
		$best      = '';
		$best_s    = 0.0;
		foreach ( (array) $sentences as $i => $sentence ) {
			$sentence = trim( (string) $sentence );
			if ( mb_strlen( $sentence ) < 25 ) {
				continue;
			}
			$doc = ' ' . implode( ' ', explode( ' ', PNChat_Text::fold( $sentence ) ) ) . ' ';
			$s   = 0.0;
			foreach ( $words as $alts ) {
				$s += self::best_in( $alts, $doc );
			}
			if ( $s > $best_s ) {
				$best_s = $s;
				$best   = $sentence;
				// A short sentence borrows the next one for context.
				if ( mb_strlen( $sentence ) < 90 && isset( $sentences[ $i + 1 ] ) ) {
					$best .= ' ' . trim( (string) $sentences[ $i + 1 ] );
				}
			}
		}
		if ( '' === $best ) {
			$best = '' !== trim( (string) $post->post_excerpt ) ? (string) $post->post_excerpt : $text;
		}
		$best = trim( (string) preg_replace( '/\s+/u', ' ', $best ) );
		return mb_strlen( $best ) > 260 ? rtrim( mb_substr( $best, 0, 257 ) ) . '…' : $best;
	}

	/**
	 * A result as safe HTML for the chat.
	 *
	 * @param array<string,mixed> $r Result.
	 * @return string
	 */
	public static function render( array $r ) {
		return '<p>' . esc_html( (string) $r['snippet'] ) . '</p><p><a href="' . esc_url( (string) $r['url'] ) . '" target="_blank" rel="noopener">Διαβάστε περισσότερα →</a></p>';
	}
}
