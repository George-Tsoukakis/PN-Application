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
	const META_TEXT   = '_pnchat_text';    // The text read, for snippets and rows.
	const META_TIME   = '_pnchat_text_at'; // The page's change time it was read at.
	const META_READ   = '_pnchat_read_at'; // When the page was last read.
	const REFRESH     = 'pnchat_site_refresh';
	const MAX_WORDS   = 6000;   // Distinct words kept per page.
	const MAX_CHARS   = 200000; // Characters of a page that are read.
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
		add_action( self::REFRESH, array( __CLASS__, 'run_refresh' ) );
		// A table plugin's table changed: the pages showing it read again.
		add_action( 'tablepress_event_saved_table', array( __CLASS__, 'request_refresh' ) );
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
		// Once per request: searchable() asks for every page it checks.
		static $cache = array();
		$key = (string) PNChat_Settings::value( 'site_exclude' ) . '|' . implode( ',', self::post_types() );
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}
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
		$ids[]         = (int) get_option( 'page_for_posts' );
		$cache[ $key ] = array_values( array_unique( array_filter( array_map( 'intval', (array) apply_filters( 'pnchat_site_excluded_ids', $ids ) ) ) ) );
		return $cache[ $key ];
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
	 * Plain text of a page (title not included): what the page shows,
	 * shortcodes and blocks included, one table row per line with its cells
	 * joined by « · ». Saved with the index, so a question does not render
	 * pages again.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function plain_text( $post ) {
		$saved = get_post_meta( (int) $post->ID, self::META_TEXT, true );
		if ( is_string( $saved ) && '' !== $saved && (string) get_post_meta( (int) $post->ID, self::META_TIME, true ) === (string) $post->post_modified_gmt ) {
			return $saved;
		}
		return self::read_text( $post );
	}

	/**
	 * Reads the text of a page now.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function read_text( $post ) {
		$raw  = (string) $post->post_content;
		$html = self::rendered( $post );
		// Tables that a script builds from data in the page.
		$data = self::script_rows( $raw . "\n" . $html );
		$html = (string) preg_replace( '#<(script|style|noscript|svg|template)\b[^>]*>.*?</\1>#is', ' ', $html );
		$html = (string) preg_replace( '/<!--.*?-->/s', ' ', $html );
		$html = strip_shortcodes( $html );
		// A table row is one line, its cells joined by « · ».
		$html = (string) preg_replace( '#</t[dh]>#i', ' · ', $html );
		// Block boundaries and line breaks end sentences.
		$html = (string) preg_replace( '#</(p|li|h[1-6]|div|tr|section|article|blockquote|dt|dd|figcaption|caption)>|<br\s*/?>#i', "\n", $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
		$text = (string) preg_replace( "/[ \t\x{00A0}]+/u", ' ', $text );
		$text = (string) preg_replace( '/^[ ·]+|[ ·]+$/mu', '', $text );
		$text = (string) preg_replace( '/( · )+/u', ' · ', $text );
		$text = (string) preg_replace( "/\n\s*\n+/", "\n", $text );
		if ( $data ) {
			$text .= "\n" . implode( "\n", $data );
		}
		return trim( mb_substr( trim( $text ), 0, self::MAX_CHARS ) );
	}

	/**
	 * The page as the visitor sees it: blocks and shortcodes run (a table
	 * plugin's [table id=1], a dynamic block). Falls back to the stored
	 * content when that fails or is turned off.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private static function rendered( $post ) {
		static $busy = false;
		$raw = (string) $post->post_content;
		$has = false !== strpos( $raw, '[' ) || false !== strpos( $raw, '<!-- wp:' );
		if ( $busy || ! $has || ! apply_filters( 'pnchat_site_render', true, $post ) ) {
			return $raw;
		}
		$busy = true;
		// setup_postdata() changes these globals; all are put back below.
		$names = array( 'post', 'id', 'authordata', 'currentday', 'currentmonth', 'page', 'pages', 'multipage', 'more', 'numpages' );
		$saved = array();
		foreach ( $names as $name ) {
			if ( array_key_exists( $name, $GLOBALS ) ) {
				$saved[ $name ] = $GLOBALS[ $name ];
			}
		}
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- shortcodes read the current page; restored below.
		$GLOBALS['post'] = $post;
		setup_postdata( $post );
		$level = ob_get_level();
		ob_start();
		try {
			$html = function_exists( 'do_blocks' ) && has_blocks( $raw ) ? do_blocks( $raw ) : $raw;
			$html = do_shortcode( $html );
		} catch ( Throwable $e ) {
			$html = $raw;
		}
		// What a shortcode printed instead of returning is part of the page.
		while ( ob_get_level() > $level + 1 ) {
			ob_end_flush();
		}
		$printed = (string) ob_get_clean();
		foreach ( $names as $name ) {
			if ( array_key_exists( $name, $saved ) ) {
				$GLOBALS[ $name ] = $saved[ $name ]; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring.
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
		$busy = false;
		return (string) $html . ( '' !== trim( $printed ) ? "\n" . $printed : '' );
	}

	/**
	 * Rows of data that a script of the page turns into a table, e.g.
	 * [{"name":"Aerolin","atc":"R03AC02"}, …] or [["Aerolin","R03AC02"], …]:
	 * each record becomes one line, its values joined by « · ». Only lists of
	 * at least five records count, so settings of a script are left out.
	 *
	 * @param string $html Page HTML.
	 * @return string[]
	 */
	private static function script_rows( $html ) {
		if ( ! preg_match_all( '#<script\b[^>]*>(.*?)</script>#is', $html, $m ) ) {
			return array();
		}
		$out = array();
		foreach ( $m[1] as $js ) {
			if ( strlen( $js ) < 80 ) {
				continue;
			}
			// Innermost {…} or […] groups with two or more quoted values.
			if ( ! preg_match_all( '/[\[{]([^\[\]{}]{4,2000})[\]}]/u', $js, $groups ) ) {
				continue;
			}
			$rows = array();
			foreach ( $groups[1] as $g ) {
				if ( ! preg_match_all( '/"((?:[^"\\\\]|\\\\.)*)"(\s*:)?|\'((?:[^\'\\\\]|\\\\.)*)\'(\s*:)?/u', $g, $q, PREG_SET_ORDER ) ) {
					continue;
				}
				$cells = array();
				foreach ( $q as $v ) {
					$is_key = ! empty( $v[2] ) || ! empty( $v[4] );
					$val    = '' !== $v[1] ? $v[1] : ( $v[3] ?? '' );
					if ( $is_key || '' === trim( $val ) ) {
						continue;
					}
					// JSON escapes (\u03b1) are read as JSON does.
					$json = '' !== $v[1] ? $v[1] : str_replace( array( "\\'", '"' ), array( "'", '\\"' ), $val );
					$dec  = json_decode( '"' . $json . '"' );
					$val  = is_string( $dec ) ? $dec : stripslashes( $val );
					$val  = trim( html_entity_decode( wp_strip_all_tags( $val ), ENT_QUOTES, 'UTF-8' ) );
					if ( '' !== $val && preg_match( '/\p{L}|\d{3}/u', $val ) && ! preg_match( '#^(https?:|/|\#|\.[a-z]|[a-z]+-[a-z-]+$)#', $val ) ) {
						$cells[] = $val;
					}
				}
				if ( count( $cells ) >= 2 ) {
					$rows[] = implode( ' · ', $cells );
				}
			}
			if ( count( $rows ) >= 5 ) {
				$out = array_merge( $out, $rows );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Index string of a page: " title words | body words ".
	 *
	 * @param WP_Post     $post Post.
	 * @param string|null $text The page's text, when already read.
	 * @return string
	 */
	public static function build_tokens( $post, $text = null ) {
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
		$body  = $words( ( null === $text ? self::read_text( $post ) : $text ) . ' ' . (string) $post->post_excerpt, self::MAX_WORDS );
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
			delete_post_meta( (int) $post_id, self::META_TEXT );
			delete_post_meta( (int) $post_id, self::META_TIME );
			delete_post_meta( (int) $post_id, self::META_READ );
			return false;
		}
		$text = self::read_text( $post );
		update_post_meta( (int) $post_id, self::META, self::build_tokens( $post, $text ) );
		// wp_slash: update_post_meta() unslashes, and the text has backslashes of its own.
		update_post_meta( (int) $post_id, self::META_TEXT, wp_slash( $text ) );
		update_post_meta( (int) $post_id, self::META_TIME, (string) $post->post_modified_gmt );
		update_post_meta( (int) $post_id, self::META_READ, time() );
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
	 * Rebuilds the whole index (admin button): the first batch now, the rest
	 * in the background, a batch a minute, so no request runs for minutes.
	 *
	 * @return int Pages indexed now.
	 */
	public static function rebuild() {
		delete_post_meta_by_key( self::META );
		delete_post_meta_by_key( self::META_TEXT );
		delete_post_meta_by_key( self::META_TIME );
		delete_post_meta_by_key( self::META_READ );
		return self::index_batch();
	}

	/**
	 * Reads again indexed pages last read before a time (oldest first):
	 * what shortcodes show (a table of a plugin, a list) can change without
	 * the page being saved.
	 *
	 * @param int $before Unix time.
	 * @param int $limit  Most pages.
	 * @return int Pages read.
	 */
	public static function refresh_some( $before, $limit ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT t.post_id FROM %i t LEFT JOIN %i r ON r.post_id = t.post_id AND r.meta_key = %s WHERE t.meta_key = %s AND ( r.meta_value IS NULL OR CAST( r.meta_value AS UNSIGNED ) < %d ) ORDER BY CAST( r.meta_value AS UNSIGNED ) ASC LIMIT %d',
				$wpdb->postmeta,
				$wpdb->postmeta,
				self::META_READ,
				self::META,
				(int) $before,
				(int) $limit
			)
		);
		$n = 0;
		foreach ( (array) $ids as $id ) {
			self::index_post( (int) $id );
			++$n;
		}
		return $n;
	}

	/**
	 * Asks for every indexed page to be read again, in the background.
	 *
	 * @return void
	 */
	public static function request_refresh() {
		update_option( self::REFRESH, time(), false );
		if ( ! wp_next_scheduled( self::REFRESH ) ) {
			wp_schedule_single_event( time() + 10, self::REFRESH );
		}
	}

	/**
	 * Cron: a batch of the requested re-reading, the next one a minute later.
	 *
	 * @return void
	 */
	public static function run_refresh() {
		$since = (int) get_option( self::REFRESH );
		if ( ! $since ) {
			return;
		}
		if ( self::refresh_some( $since, self::BATCH ) >= self::BATCH ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::REFRESH );
			return;
		}
		delete_option( self::REFRESH );
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
	 * Indexed pages: id => index string. With question words, only the pages
	 * that hold at least one of them (or of their first four letters) are
	 * read: the same test best_in() starts with, done by the database, so a
	 * large site is not loaded whole for every question.
	 *
	 * @param array<int,string[]>|null $words Question words with synonyms; null for all pages.
	 * @return array<int,string>
	 */
	private static function documents( $words = null ) {
		global $wpdb;
		$sql  = 'SELECT pm.post_id, pm.meta_value FROM %i pm INNER JOIN %i p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_status = %s AND p.post_password = %s';
		$args = array( $wpdb->postmeta, $wpdb->posts, self::META, 'publish', '' );
		$like = self::candidate_patterns( $words );
		if ( $like ) {
			$sql .= ' AND ( ' . implode( ' OR ', array_fill( 0, count( $like ), 'pm.meta_value LIKE %s' ) ) . ' )';
			foreach ( $like as $pattern ) {
				$args[] = '%' . $wpdb->esc_like( $pattern ) . '%';
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- placeholders only, built above.
		$rows     = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
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
	 * LIKE patterns that every page best_in() could match must contain;
	 * none (read everything) when a word cannot be turned into one safely.
	 *
	 * @param array<int,string[]>|null $words Question words with synonyms.
	 * @return string[]
	 */
	private static function candidate_patterns( $words ) {
		if ( ! $words || ! apply_filters( 'pnchat_site_search_prefilter', true ) ) {
			return array();
		}
		$out = array();
		foreach ( $words as $alts ) {
			foreach ( $alts as $w ) {
				$p = strlen( $w ) >= 4 ? ' ' . substr( $w, 0, 4 ) : ' ' . $w . ' ';
				if ( ! preg_match( '//u', $p ) ) {
					return array();
				}
				$out[ $p ] = true;
			}
		}
		return count( $out ) <= 60 ? array_keys( $out ) : array();
	}

	/**
	 * Number of indexed pages.
	 *
	 * @return int
	 */
	public static function count() {
		static $n = null;
		if ( null === $n ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT pm.post_id FROM %i pm INNER JOIN %i p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_status = %s AND p.post_password = %s', $wpdb->postmeta, $wpdb->posts, self::META, 'publish', '' ) );
			$n   = count( array_diff( array_map( 'intval', (array) $ids ), self::excluded_ids() ) );
		}
		return $n;
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
			// Codes and amounts (R03AC02, 500mg) match only exactly.
			if ( strlen( $w ) < 4 || preg_match( '/\d/', $w ) ) {
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
		$docs = self::documents( $words );
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

		// Pages without any question word were not read, but they count.
		$n       = max( count( $docs ), self::count() );
		$weights = array();
		foreach ( $words as $i => $alts ) {
			// A word no page has: probably filler; it counts a little.
			$weights[ $i ] = $df[ $i ] > 0 ? 1.0 + log( ( $n + 1 ) / ( $df[ $i ] + 0.5 ) ) : 0.5;
		}
		$total = array_sum( $weights );
		$known = count( array_filter( $df ) );

		$front  = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
		$scored = array();
		$titled = array();
		foreach ( $hits as $id => $h ) {
			$sum   = 0.0;
			$title = false;
			foreach ( $h as $i => $hit ) {
				$sum  += $weights[ $i ] * $hit[0];
				$title = $title || $hit[1];
			}
			$score = $sum / $total + ( $title ? 0.1 : 0.0 );
			// The home page announces everything in a line or two; the page
			// about the subject says more.
			if ( $id === $front ) {
				$score *= 0.75;
			}
			// Most of the known words must be on the page (at least two of
			// them when the question has two or more).
			if ( $score < self::MIN_SCORE || count( $h ) < min( 2, $known ) ) {
				continue;
			}
			$scored[ $id ] = min( 1.0, $score );
			// Every known word of the question is in the title: the page is
			// about it, and its opening says what it is.
			$titled[ $id ] = count( array_filter( array_column( $h, 1 ) ) ) >= max( 1, $known );
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
				'snippet' => ! empty( $titled[ $id ] ) ? self::intro( $post ) : self::snippet( $post, $words ),
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
		// Sentences; a table row stays whole.
		$sentences = array();
		foreach ( explode( "\n", $text ) as $line ) {
			$parts     = false !== strpos( $line, ' · ' ) ? array( $line ) : (array) preg_split( '/(?<=[.;!?·])\s+/u', $line );
			$sentences = array_merge( $sentences, $parts );
		}
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
				if ( mb_strlen( $sentence ) < 90 && isset( $sentences[ $i + 1 ] ) && false === strpos( (string) $sentences[ $i + 1 ], ' · ' ) ) {
					$best .= ' ' . trim( (string) $sentences[ $i + 1 ] );
				}
			}
		}
		if ( '' === $best ) {
			$best = self::intro( $post );
		}
		$best = trim( (string) preg_replace( '/\s+/u', ' ', $best ) );
		return mb_strlen( $best ) > 260 ? rtrim( mb_substr( $best, 0, 257 ) ) . '…' : $best;
	}

	/**
	 * The opening of a page: its excerpt, or its first sentences that are
	 * not headings, menus or table rows.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private static function intro( $post ) {
		$out = trim( wp_strip_all_tags( (string) $post->post_excerpt ) );
		if ( '' === $out ) {
			foreach ( explode( "\n", self::plain_text( $post ) ) as $line ) {
				$line = trim( $line );
				if ( mb_strlen( $line ) < 40 || false !== strpos( $line, ' · ' ) ) {
					continue;
				}
				$out .= ( '' === $out ? '' : ' ' ) . $line;
				if ( mb_strlen( $out ) >= 160 ) {
					break;
				}
			}
		}
		$out = trim( (string) preg_replace( '/\s+/u', ' ', $out ) );
		if ( mb_strlen( $out ) > 320 ) {
			// Cut at the last full sentence that fits.
			$cut = mb_substr( $out, 0, 320 );
			$end = max( (int) mb_strrpos( $cut, '. ' ), (int) mb_strrpos( $cut, '; ' ), (int) mb_strrpos( $cut, '! ' ) );
			$out = $end > 120 ? mb_substr( $cut, 0, $end + 1 ) : rtrim( mb_substr( $cut, 0, 317 ) ) . '…';
		}
		return $out;
	}

	/**
	 * Rows of the site's tables (and data lists) that name a word of the
	 * question: «Είναι το Aerolin στη λίστα;» finds the row of Aerolin in
	 * the table of the page with the list. Also notes a product name that
	 * no page has, when the question is about a page with a table, so the
	 * answer can say it is not in it.
	 *
	 * @param string $question Question.
	 * @param string $covered  Text already in the answer (a word in it is not looked up).
	 * @return array{found:array<int,array{term:string,id:int,title:string,url:string,rows:string[]}>,missing:array<int,array{term:string,id:int,title:string,url:string,rows:int}>,yes:bool}
	 */
	public static function lookup( $question, $covered = '' ) {
		$out = array(
			'found'   => array(),
			'missing' => array(),
			'yes'     => false,
		);
		$skip  = ' ' . PNChat_Text::fold( $covered ) . ' ';
		$stop  = PNChat_Text::stopwords();
		$greek = (bool) preg_match( '/\p{Greek}/u', $question );
		$terms = array();
		foreach ( (array) preg_split( '/[^\p{L}\p{N}]+/u', $question, -1, PREG_SPLIT_NO_EMPTY ) as $orig ) {
			$f = PNChat_Text::fold( (string) $orig );
			if ( '' === $f || false !== strpos( $f, ' ' ) || isset( $stop[ $f ] ) || isset( $terms[ $f ] ) ) {
				continue;
			}
			if ( ctype_digit( $f ) ? strlen( $f ) < 5 : mb_strlen( $f ) < 4 ) {
				continue;
			}
			if ( false !== strpos( $skip, ' ' . $f . ' ' ) ) {
				continue;
			}
			// «aerolin» is shown as «Aerolin».
			$terms[ $f ] = preg_match( '/^[a-z]/', (string) $orig ) && ! preg_match( '/[A-Z]/', (string) $orig ) ? ucfirst( (string) $orig ) : (string) $orig;
		}
		if ( ! $terms ) {
			return $out;
		}
		$folded     = ' ' . PNChat_Text::fold( $question ) . ' ';
		$out['yes'] = (bool) preg_match( '/ (ine|iparx\w*|periex\w*|perilam\w*|anik\w*|exi|exoun|lista\w*|mesa|kalipt\w*) /', $folded );
		$rare       = max( 3, (int) ceil( 0.05 * self::count() ) );

		foreach ( $terms as $f => $orig ) {
			// Codes and barcodes (R03AC02) match only exactly; names allow a typo.
			$need  = preg_match( '/\d/', $f ) ? 1.0 : 0.85;
			$pages = array();
			$topic = false;
			foreach ( self::documents( array( array( $f ) ) ) as $id => $doc ) {
				$bar   = strpos( $doc, ' | ' );
				$title = false === $bar ? '' : substr( $doc, 0, $bar + 1 );
				$body  = false === $bar ? $doc : substr( $doc, $bar + 2 );
				// A word in a title is a subject, not an item of a list.
				if ( self::best_in( array( $f ), $title ) >= $need ) {
					$topic = true;
					break;
				}
				if ( self::best_in( array( $f ), $body ) >= $need ) {
					$pages[] = (int) $id;
				}
			}
			if ( $topic ) {
				continue;
			}
			if ( ! $pages ) {
				// A product name (Latin letters in a Greek question) that no
				// page has: when the question is about a page with a table,
				// it is not in that table.
				if ( $greek && preg_match( '/^[a-z][a-z0-9-]*$/i', $orig ) ) {
					$rest = trim( (string) preg_replace( '/\b' . preg_quote( $orig, '/' ) . '\b/iu', ' ', $question ) );
					foreach ( self::search( $rest, 1 ) as $r ) {
						$rows = self::rows_of( get_post( $r['id'] ) );
						if ( count( $rows ) >= 10 ) {
							$out['missing'][] = array(
								'term'  => $orig,
								'id'    => (int) $r['id'],
								'title' => $r['title'],
								'url'   => $r['url'],
								'rows'  => count( $rows ),
							);
						}
					}
				}
				continue;
			}
			if ( count( $pages ) > $rare ) {
				continue;
			}
			// Pages whose title has more of the question first, then newer.
			$found = array();
			foreach ( $pages as $id ) {
				$post = get_post( $id );
				if ( ! self::searchable( $post ) ) {
					continue;
				}
				// The rows with the word itself, else those with a near spelling.
				$rows  = array();
				$near  = array();
				$spelt = '';
				foreach ( self::rows_of( $post ) as $row ) {
					if ( mb_strlen( $row ) > 400 ) {
						continue;
					}
					$sim = self::best_in( array( $f ), ' ' . PNChat_Text::fold( $row ) . ' ' );
					if ( 1.0 === $sim && count( $rows ) < 3 ) {
						$rows[] = $row;
					} elseif ( $sim >= $need && count( $near ) < 3 ) {
						$near[] = $row;
						if ( '' === $spelt ) {
							$spelt = self::closest_word( $f, $row );
						}
					}
				}
				$name = $orig;
				if ( ! $rows && '' !== $spelt ) {
					// «aerolim» found as «Aerolin»: the page's spelling.
					$name = $spelt;
				}
				$rows = $rows ? $rows : $near;
				if ( ! $rows ) {
					continue;
				}
				$title = html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );
				$tf    = ' ' . PNChat_Text::fold( $title ) . ' ';
				$fit   = 0.0;
				foreach ( self::query_words( $question ) as $alts ) {
					$fit += self::best_in( $alts, $tf );
				}
				$found[] = array(
					'term'  => $name,
					'id'    => (int) $id,
					'title' => $title,
					'url'   => (string) get_permalink( $post ),
					'rows'  => $rows,
					'fit'   => $fit,
					'date'  => (string) $post->post_date_gmt,
				);
			}
			usort(
				$found,
				function ( $a, $b ) {
					return array( $b['fit'], $b['date'] ) <=> array( $a['fit'], $a['date'] );
				}
			);
			foreach ( array_slice( $found, 0, 2 ) as $hit ) {
				unset( $hit['fit'], $hit['date'] );
				$out['found'][] = $hit;
			}
			if ( count( $out['found'] ) >= 4 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * The word of a text closest to a folded word, as the text writes it.
	 *
	 * @param string $folded Folded word.
	 * @param string $text   Text.
	 * @return string
	 */
	private static function closest_word( $folded, $text ) {
		$best = '';
		$top  = 0.0;
		foreach ( (array) preg_split( '/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY ) as $w ) {
			$sim = PNChat_Text::token_similarity( $folded, PNChat_Text::fold( (string) $w ) );
			if ( $sim > $top ) {
				$top  = $sim;
				$best = (string) $w;
			}
		}
		return $best;
	}

	/**
	 * The table rows (lines with cells) of a page.
	 *
	 * @param WP_Post|null $post Post.
	 * @return string[]
	 */
	public static function rows_of( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return array();
		}
		$rows = array();
		foreach ( explode( "\n", self::plain_text( $post ) ) as $line ) {
			if ( false !== strpos( $line, ' · ' ) ) {
				$rows[] = trim( $line );
			}
		}
		return $rows;
	}

	/**
	 * A lookup as chat items: the rows found, or that a name is not in the
	 * page's table.
	 *
	 * @param array<string,mixed> $look Result of lookup().
	 * @return array<int,array{kind:string,title:string,html:string,id:int}>
	 */
	public static function render_lookup( array $look ) {
		$items = array();
		$named = array();
		foreach ( $look['found'] as $hit ) {
			$first = ! isset( $named[ $hit['term'] ] );
			$named[ $hit['term'] ] = true;
			$lead  = $first && ! empty( $look['yes'] ) ? 'Ναι — ' : '';
			$html  = '<p>' . esc_html( $lead . ( '' === $lead ? 'Το' : 'το' ) . ' «' . $hit['term'] . '» υπάρχει στη σελίδα ' ) . '<strong>' . esc_html( '«' . $hit['title'] . '»' ) . '</strong>:</p><ul>';
			foreach ( $hit['rows'] as $row ) {
				$html .= '<li>' . esc_html( $row ) . '</li>';
			}
			$html   .= '</ul><p><a href="' . esc_url( $hit['url'] ) . '" target="_blank" rel="noopener">Δείτε όλη τη σελίδα →</a></p>';
			$items[] = array(
				'kind'  => 'site',
				'title' => '',
				'html'  => $html,
				'id'    => (int) $hit['id'],
			);
		}
		foreach ( $look['missing'] as $miss ) {
			$html    = '<p>' . esc_html( 'Όχι — το «' . $miss['term'] . '» δεν υπάρχει στη σελίδα ' ) . '<strong>' . esc_html( '«' . $miss['title'] . '»' ) . '</strong>' . esc_html( ' (ελέγξαμε ' . $miss['rows'] . ' γραμμές του πίνακα).' ) . '</p><p><a href="' . esc_url( $miss['url'] ) . '" target="_blank" rel="noopener">Δείτε όλη τη σελίδα →</a></p>';
			$items[] = array(
				'kind'  => 'site',
				'title' => '',
				'html'  => $html,
				'id'    => (int) $miss['id'],
			);
		}
		return $items;
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
