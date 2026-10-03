<?php
/**
 * Learning from conversations, without AI. The answers stay the ones an
 * administrator wrote; what is learned is how visitors ask.
 *
 * - «Μήπως εννοείτε…;»: when the chat is not sure, it offers the closest
 *   entries. The one the visitor taps confirms that their wording means it.
 * - Rephrasing: a question the chat did not understand, followed in the same
 *   conversation by one it answered.
 *
 * Each wording is counted once per conversation. A wording confirmed by taps
 * in N different conversations (setting, default 3) is added to its entry on
 * its own, if it is not medical, not close to a refusal and does not change
 * any answer of the test set. Everything else waits in «Μάθηση» for approval,
 * and whatever was added on its own can be undone.
 *
 * @package PNChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lessons from conversations and the test set.
 */
final class PNChat_Lessons {

	const TESTS     = 'pnchat_tests';
	const MAX_TESTS = 1000;
	const MAX_CONVS = 20;
	const FLOOR     = 0.25; // Lowest score offered in «Μήπως εννοείτε».

	/**
	 * Lessons table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'pnchat_lessons';
	}

	/**
	 * SQL of the lessons table (for dbDelta).
	 *
	 * @param string $charset Charset and collation.
	 * @return string
	 */
	public static function table_sql( $charset ) {
		return 'CREATE TABLE ' . self::table() . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				phrase varchar(255) NOT NULL DEFAULT '',
				phrase_key varchar(190) NOT NULL DEFAULT '',
				entry_id bigint(20) unsigned NOT NULL DEFAULT 0,
				convs text NOT NULL,
				click_convs text NOT NULL,
				seen int(10) unsigned NOT NULL DEFAULT 0,
				clicks int(10) unsigned NOT NULL DEFAULT 0,
				status varchar(10) NOT NULL DEFAULT 'pending',
				note varchar(255) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY phrase_entry (phrase_key,entry_id),
				KEY status_updated (status,updated_at)
			) {$charset};";
	}

	/**
	 * Key of a wording: folded, without the question mark.
	 *
	 * @param string $phrase Wording.
	 * @return string
	 */
	public static function key( $phrase ) {
		return substr( PNChat_Text::fold( (string) $phrase ), 0, 190 );
	}

	/* ------------------------------------------------------------------ */
	/* «Μήπως εννοείτε…;»                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * The closest entries for a question the chat could not answer: up to
	 * three, each the first wording of an entry. None when a refusal is close
	 * (safer to say nothing than to offer around it) or when nothing is close.
	 *
	 * @param string $question Question.
	 * @param string $context  Conversation topic ('' for none).
	 * @return array<int,array{id:int,text:string}>
	 */
	public static function suggestions( $question, $context = '' ) {
		$m    = PNChat_Brain::matcher();
		$q    = '' !== $context ? rtrim( (string) $question, " \t?;;.!" ) . ' ' . $context : (string) $question;
		$pool = array();
		foreach ( array_slice( $m->rank( $q ), 0, 8 ) as $r ) {
			if ( (float) $r['score'] < self::FLOOR ) {
				break;
			}
			if ( 'block' === $r['kind'] ) {
				return array();
			}
			$pool[ (int) $r['id'] ] = (float) $r['score'];
		}
		if ( ! $pool ) {
			return array();
		}
		// In a conversation the topic's name makes every entry of the topic
		// look close: the question's own words decide the order.
		$own = array();
		if ( '' !== $context ) {
			foreach ( $m->rank( (string) $question ) as $r ) {
				$own[ (int) $r['id'] ] = (float) $r['score'];
			}
			// Only the topic makes them close: fine for a short follow-up
			// («Πρέπει να πληρώσω;»), not for a long question of its own
			// («Τι καιρό θα κάνει αύριο στη Θεσσαλονίκη;»).
			$related = false;
			foreach ( array_keys( $pool ) as $pid ) {
				$related = $related || ( $own[ $pid ] ?? 0 ) >= 0.15;
			}
			if ( ! $related && self::word_count( (string) $question ) > 4 ) {
				return array();
			}
		}
		$ids = array_keys( $pool );
		usort(
			$ids,
			function ( $a, $b ) use ( $pool, $own ) {
				$d = ( $own[ $b ] ?? 0 ) <=> ( $own[ $a ] ?? 0 );
				return $d ? $d : $pool[ $b ] <=> $pool[ $a ];
			}
		);
		$out = array();
		foreach ( $ids as $id ) {
			$e = PNChat_Store::entry( $id );
			if ( $e && ! empty( $e['phrasings'] ) ) {
				$out[] = array(
					'id'   => $id,
					'text' => (string) $e['phrasings'][0],
				);
			}
			if ( count( $out ) >= 3 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Words of a question.
	 *
	 * @param string $text Question.
	 * @return int
	 */
	private static function word_count( $text ) {
		return count( preg_split( '/[^\p{L}\p{N}]+/u', (string) $text, -1, PREG_SPLIT_NO_EMPTY ) );
	}

	/* ------------------------------------------------------------------ */
	/* lessons                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Counts that, in a conversation, a wording meant an entry.
	 *
	 * @param string $phrase   The visitor's wording.
	 * @param int    $entry_id Entry it meant.
	 * @param string $conv     Conversation (hashed); '' counts nothing.
	 * @param string $source   'click' («Μήπως εννοείτε», «Αναφέρεστε…;»), 'thumb' (👍) or 'rephrase'.
	 * @return array<string,mixed>|null The lesson after it (null: not a lesson).
	 */
	public static function record( $phrase, $entry_id, $conv, $source ) {
		global $wpdb;
		// E-mails, phone numbers and AMKA never become part of a lesson.
		$phrase = trim( preg_replace( '/\s+/u', ' ', PNChat_AI::redact( (string) $phrase ) ) );
		if ( false !== strpos( $phrase, '[' ) ) {
			return null;
		}
		$key = self::key( $phrase );
		$words  = '' === $key ? 0 : count( explode( ' ', $key ) );
		$e      = PNChat_Store::entry( (int) $entry_id );
		if ( '' === $conv || ! $e || 'answer' !== $e['kind'] || empty( $e['active'] ) || $words < 1 || $words > 20 || mb_strlen( $phrase ) > 250 ) {
			return null;
		}
		foreach ( (array) $e['phrasings'] as $p ) {
			if ( self::key( $p ) === $key ) {
				return null; // Already one of its wordings.
			}
		}
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO %i ( phrase, phrase_key, entry_id, convs, click_convs, created_at, updated_at ) VALUES ( %s, %s, %d, '', '', %s, %s )",
				self::table(),
				mb_substr( $phrase, 0, 250 ),
				$key,
				(int) $entry_id,
				$now,
				$now
			)
		);
		$row = self::find( $key, (int) $entry_id );
		if ( ! $row || 'pending' !== $row['status'] ) {
			return $row;
		}
		$convs  = array_filter( explode( ',', (string) $row['convs'] ) );
		$clicks = array_filter( explode( ',', (string) $row['click_convs'] ) );
		if ( ! in_array( $conv, $convs, true ) ) {
			$convs[] = $conv;
		}
		// A tapped button or a 👍 is the visitor's own confirmation.
		if ( in_array( $source, array( 'click', 'thumb' ), true ) && ! in_array( $conv, $clicks, true ) ) {
			$clicks[] = $conv;
		}
		$convs  = array_slice( $convs, -self::MAX_CONVS );
		$clicks = array_slice( $clicks, -self::MAX_CONVS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			self::table(),
			array(
				'convs'       => implode( ',', $convs ),
				'click_convs' => implode( ',', $clicks ),
				'seen'        => count( $convs ),
				'clicks'      => count( $clicks ),
				'updated_at'  => $now,
			),
			array( 'id' => (int) $row['id'] )
		);
		$row = self::get( (int) $row['id'] );
		$n   = (int) PNChat_Settings::value( 'learn_auto' );
		if ( $row && $n > 0 && (int) $row['clicks'] >= $n ) {
			self::try_auto( $row );
			$row = self::get( (int) $row['id'] );
		}
		return $row;
	}

	/**
	 * One lesson by wording and entry.
	 *
	 * @param string $key      Wording key.
	 * @param int    $entry_id Entry.
	 * @return array<string,mixed>|null
	 */
	private static function find( $key, $entry_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE phrase_key = %s AND entry_id = %d', self::table(), $key, $entry_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * One lesson.
	 *
	 * @param int $id Id.
	 * @return array<string,mixed>|null
	 */
	public static function get( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Lessons by status, latest first.
	 *
	 * @param string $status pending | auto | added | rejected.
	 * @param int    $limit  How many.
	 * @return array<int,array<string,mixed>>
	 */
	public static function by_status( $status, $limit = 100 ) {
		global $wpdb;
		$order = 'pending' === $status ? 'clicks DESC, seen DESC, updated_at DESC' : 'updated_at DESC';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $order is one of two fixed strings.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status = %s ORDER BY {$order} LIMIT %d", self::table(), $status, $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Lessons waiting for a decision.
	 *
	 * @return int
	 */
	public static function count_pending() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'pending'", self::table() ) );
	}

	/**
	 * Why a wording may not be added to an entry on its own ('' = it may).
	 *
	 * @param string $phrase   Wording.
	 * @param int    $entry_id Entry.
	 * @return string
	 */
	public static function why_not( $phrase, $entry_id ) {
		if ( PNChat_AI::is_medical( $phrase ) ) {
			return 'Μοιάζει με ιατρική ερώτηση.';
		}
		$m    = PNChat_Brain::matcher();
		$rank = $m->rank( $phrase );
		foreach ( array_slice( $rank, 0, 5 ) as $r ) {
			if ( 'block' === $r['kind'] && (float) $r['score'] >= self::FLOOR ) {
				return sprintf( 'Είναι κοντά στην απαγόρευση «%s».', $r['title'] );
			}
		}
		// Try it on a copy of the brain: the wording must reach its entry and
		// no question of the test set may change answer.
		$entries = PNChat_Store::entries( null, true );
		foreach ( $entries as $i => $e ) {
			if ( (int) $e['id'] === (int) $entry_id ) {
				$entries[ $i ]['phrasings'][] = $phrase;
			}
		}
		$after = PNChat_Brain::matcher_for( $entries );
		$a     = $after->ask( $phrase );
		if ( 'answered' !== $a['status'] || (int) $a['items'][0]['id'] !== (int) $entry_id ) {
			return 'Ακόμα και με την προσθήκη, η ερώτηση θα πήγαινε σε άλλη γνώση.';
		}
		$before = self::run_tests( $m );
		$now    = self::run_tests( $after );
		$broken = array_diff( array_keys( $before['ok'] ), array_keys( $now['ok'] ) );
		if ( $broken ) {
			$t = self::tests()[ (int) reset( $broken ) ] ?? array( 'q' => '' );
			return sprintf( 'Θα άλλαζε την απάντηση %d δοκιμών (π.χ. «%s»).', count( $broken ), $t['q'] );
		}
		return '';
	}

	/**
	 * Adds a confirmed wording on its own, if it is safe; otherwise notes why
	 * and leaves it for the administrator.
	 *
	 * @param array<string,mixed> $row Lesson.
	 * @return bool Added.
	 */
	private static function try_auto( array $row ) {
		$why = self::why_not( (string) $row['phrase'], (int) $row['entry_id'] );
		if ( '' !== $why ) {
			self::set( (int) $row['id'], array( 'note' => 'Δεν μπήκε μόνο του: ' . $why ) );
			return false;
		}
		return self::apply( $row, 'auto' );
	}

	/**
	 * Adds the wording to its entry (and to the test set).
	 *
	 * @param array<string,mixed> $row    Lesson.
	 * @param string              $status 'auto' or 'added'.
	 * @return bool
	 */
	private static function apply( array $row, $status ) {
		$e = PNChat_Store::entry( (int) $row['entry_id'] );
		if ( ! $e ) {
			return false;
		}
		foreach ( (array) $e['phrasings'] as $p ) {
			if ( self::key( $p ) === (string) $row['phrase_key'] ) {
				self::set( (int) $row['id'], array( 'status' => $status ) );
				return true;
			}
		}
		if ( ! PNChat_Store::add_phrasing( (int) $row['entry_id'], (string) $row['phrase'] ) ) {
			return false;
		}
		PNChat_Brain::matcher( true );
		self::set(
			(int) $row['id'],
			array(
				'status' => $status,
				'note'   => 'auto' === $status ? 'Μπήκε μόνο του: ' . (int) $row['clicks'] . ' επισκέπτες το επιβεβαίωσαν.' : '',
			)
		);
		self::add_test( (string) $row['phrase'], '', (int) $row['entry_id'], 'auto' === $status ? 'αυτόματη μάθηση' : 'έγκριση μάθησης' );
		self::close_questions( $row );
		return true;
	}

	/**
	 * Questions of the log with this wording are answered now.
	 *
	 * @param array<string,mixed> $row Lesson.
	 * @return void
	 */
	private static function close_questions( array $row ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'trained' WHERE hint_entry = %d AND status IN ( 'unanswered', 'partial' )", PNChat_Store::questions_table(), (int) $row['entry_id'] ) );
	}

	/**
	 * Updates a lesson.
	 *
	 * @param int                 $id   Id.
	 * @param array<string,mixed> $cols Columns.
	 * @return bool
	 */
	private static function set( $id, array $cols ) {
		global $wpdb;
		$cols['updated_at'] = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $wpdb->update( self::table(), $cols, array( 'id' => (int) $id ) );
	}

	/**
	 * The administrator's decision on a lesson.
	 *
	 * @param int    $id Lesson.
	 * @param string $do add | reject | undo.
	 * @return bool
	 */
	public static function decide( $id, $do ) {
		$row = self::get( $id );
		if ( ! $row ) {
			return false;
		}
		if ( 'add' === $do && 'pending' === $row['status'] ) {
			return self::apply( $row, 'added' );
		}
		if ( 'reject' === $do && 'pending' === $row['status'] ) {
			return self::set( $id, array( 'status' => 'rejected' ) );
		}
		if ( 'undo' === $do && in_array( $row['status'], array( 'auto', 'added' ), true ) ) {
			$e = PNChat_Store::entry( (int) $row['entry_id'] );
			if ( $e ) {
				$e['phrasings'] = array_values(
					array_filter(
						(array) $e['phrasings'],
						function ( $p ) use ( $row ) {
							return self::key( $p ) !== (string) $row['phrase_key'];
						}
					)
				);
				if ( ! $e['phrasings'] && ! $e['keywords'] ) {
					return false; // Its only wording: nothing would be left.
				}
				if ( ! PNChat_Store::save_entry( $e, (int) $e['id'] ) ) {
					return false;
				}
				PNChat_Brain::matcher( true );
			}
			self::remove_test_for( (string) $row['phrase_key'], (int) $row['entry_id'] );
			// Rejected, so the same visitors' taps do not bring it back.
			return self::set( $id, array( 'status' => 'rejected', 'note' => 'Αναιρέθηκε.' ) );
		}
		return false;
	}

	/* ------------------------------------------------------------------ */
	/* test set                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Test questions with the entry that must answer them.
	 *
	 * @return array<int,array{q:string,ctx:string,entry:int,title:string,source:string}>
	 */
	public static function tests() {
		$t = get_option( self::TESTS, array() );
		return is_array( $t ) ? array_values( $t ) : array();
	}

	/**
	 * Adds a test (the same question and entry only once).
	 *
	 * @param string $q        Question.
	 * @param string $ctx      Conversation topic.
	 * @param int    $entry_id Entry that must answer it.
	 * @param string $source   Where it came from.
	 * @return bool
	 */
	public static function add_test( $q, $ctx, $entry_id, $source ) {
		$q = trim( sanitize_text_field( (string) $q ) );
		$e = PNChat_Store::entry( (int) $entry_id );
		if ( '' === $q || ! $e ) {
			return false;
		}
		$tests = self::tests();
		foreach ( $tests as $t ) {
			if ( self::key( $t['q'] ) === self::key( $q ) && (int) $t['entry'] === (int) $entry_id && $t['ctx'] === $ctx ) {
				return true;
			}
		}
		$tests[] = array(
			'q'      => $q,
			'ctx'    => sanitize_text_field( (string) $ctx ),
			'entry'  => (int) $entry_id,
			'title'  => (string) $e['title'],
			'source' => (string) $source,
		);
		return update_option( self::TESTS, array_slice( $tests, -self::MAX_TESTS ), false );
	}

	/**
	 * Removes a test.
	 *
	 * @param int $i Index.
	 * @return void
	 */
	public static function remove_test( $i ) {
		$tests = self::tests();
		unset( $tests[ (int) $i ] );
		update_option( self::TESTS, array_values( $tests ), false );
	}

	/**
	 * Removes the test made for a lesson.
	 *
	 * @param string $key      Wording key.
	 * @param int    $entry_id Entry.
	 * @return void
	 */
	private static function remove_test_for( $key, $entry_id ) {
		$tests = array_filter(
			self::tests(),
			function ( $t ) use ( $key, $entry_id ) {
				return ! ( self::key( $t['q'] ) === $key && (int) $t['entry'] === (int) $entry_id );
			}
		);
		update_option( self::TESTS, array_values( $tests ), false );
	}

	/**
	 * Runs the test set.
	 *
	 * @param PNChat_Matcher|null $m Matcher (null: the site's).
	 * @return array{total:int,ok:array<int,bool>,fail:array<int,array{test:array<string,mixed>,got:string}>}
	 */
	public static function run_tests( $m = null ) {
		$m    = $m ? $m : PNChat_Brain::matcher();
		$out  = array(
			'total' => 0,
			'ok'    => array(),
			'fail'  => array(),
		);
		foreach ( self::tests() as $i => $t ) {
			++$out['total'];
			$q = '' !== $t['ctx'] ? rtrim( $t['q'], " \t?;;.!" ) . ' ' . $t['ctx'] : $t['q'];
			$a = $m->ask( $q );
			$ids = 'answered' === $a['status'] ? array_map( 'intval', array_column( $a['items'], 'id' ) ) : array();
			if ( $ids && (int) $t['entry'] === $ids[0] ) {
				$out['ok'][ $i ] = true;
			} else {
				$out['fail'][ $i ] = array(
					'test' => $t,
					'got'  => $ids ? (string) $a['items'][0]['title'] : ( 'blocked' === $a['status'] ? 'απαγόρευση' : 'καμία απάντηση' ),
				);
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* what the conversations show                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * «Τι ρωτάνε και δεν ξέρει»: unanswered questions of the last days,
	 * grouped by their most telling shared word.
	 *
	 * @param int $days  How far back.
	 * @param int $limit Groups.
	 * @return array<int,array{word:string,count:int,questions:string[],ids:int[]}>
	 */
	public static function unanswered_groups( $days = 30, $limit = 10 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, question FROM %i WHERE status IN ( 'unanswered', 'partial' ) AND hint_entry = 0 AND created_at >= %s ORDER BY id DESC LIMIT 2000", PNChat_Store::questions_table(), gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ), ARRAY_A );
		$stop  = PNChat_Text::stopwords();
		$by    = array();
		$shown = array();
		$text  = array();
		// Verbs every question has: they say nothing about the subject.
		$common = array();
		foreach ( array( 'θέλω', 'θέλει', 'θέλετε', 'κάνω', 'κάνει', 'κάνετε', 'μπορώ', 'μπορεί', 'μπορείτε', 'έχω', 'έχει', 'έχετε', 'είναι', 'είμαι', 'υπάρχει', 'γίνεται', 'πρέπει', 'ξέρω', 'ξέρετε', 'λέτε', 'δίνετε', 'βρίσκω', 'βρω', 'χρειάζομαι', 'χρειάζεται', 'παρακαλώ', 'γεια', 'καλησπέρα', 'καλημέρα' ) as $w ) {
			$f                                                      = PNChat_Text::fold( $w );
			$common[ mb_substr( $f, 0, max( 4, mb_strlen( $f ) - 2 ) ) ] = true;
		}
		foreach ( (array) $rows as $r ) {
			$seen = array();
			foreach ( preg_split( '/[^\p{L}\p{N}]+/u', (string) $r['question'], -1, PREG_SPLIT_NO_EMPTY ) as $w ) {
				$f = PNChat_Text::fold( $w );
				// A rough stem groups «εκτύπωση» and «εκτυπώσεις».
				$stem = mb_substr( $f, 0, max( 4, mb_strlen( $f ) - 2 ) );
				if ( mb_strlen( $f ) < 3 || isset( $stop[ $f ] ) || isset( $common[ $stem ] ) || isset( $seen[ $stem ] ) || ctype_digit( $f ) ) {
					continue;
				}
				$seen[ $stem ]         = true;
				$by[ $stem ][]         = (int) $r['id'];
				$shown[ $stem ][ $w ]  = ( $shown[ $stem ][ $w ] ?? 0 ) + 1;
				$text[ (int) $r['id'] ] = (string) $r['question'];
			}
		}
		uasort(
			$by,
			function ( $a, $b ) {
				return count( $b ) <=> count( $a );
			}
		);
		$out  = array();
		$used = array();
		foreach ( $by as $stem => $ids ) {
			$ids = array_values( array_diff( $ids, $used ) );
			if ( count( $ids ) < 2 ) {
				continue;
			}
			arsort( $shown[ $stem ] );
			$out[] = array(
				'word'      => (string) array_key_first( $shown[ $stem ] ),
				'count'     => count( $ids ),
				'questions' => array_slice( array_values( array_unique( array_map( fn( $id ) => $text[ $id ], $ids ) ) ), 0, 8 ),
				'ids'       => array_slice( $ids, 0, 50 ),
			);
			$used = array_merge( $used, $ids );
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * What the chat did and learned in the last days, for «Τι έμαθε αυτή
	 * την εβδομάδα».
	 *
	 * @param int $days How far back.
	 * @return array{questions:int,answered:int,unknown:int,new_wordings:int,learned_auto:int,learned_added:int,pending:int,proposals:int,groups:array<int,array<string,mixed>>}
	 */
	public static function week_summary( $days = 7 ) {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - (int) $days * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n FROM %i WHERE created_at >= %s GROUP BY status', PNChat_Store::questions_table(), $since ), ARRAY_A );
		$by     = array();
		foreach ( (array) $rows as $r ) {
			$by[ (string) $r['status'] ] = (int) $r['n'];
		}
		$count = function ( $where ) use ( $wpdb, $since ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- $where is fixed SQL of this class.
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ' . $where . ' AND updated_at >= %s', self::table(), $since ) );
		};
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$new = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE created_at >= %s', self::table(), $since ) );
		return array(
			'questions'     => array_sum( $by ),
			'answered'      => ( $by['answered'] ?? 0 ) + ( $by['site'] ?? 0 ) + ( $by['ai'] ?? 0 ),
			// Not answered, half answered, 👎, or answered later by e-mail.
			'unknown'       => array_sum( $by ) - ( $by['answered'] ?? 0 ) - ( $by['site'] ?? 0 ) - ( $by['ai'] ?? 0 ),
			'new_wordings'  => $new,
			'learned_auto'  => $count( "status = 'auto'" ),
			'learned_added' => $count( "status = 'added'" ),
			'pending'       => self::count_pending(),
			'proposals'     => PNChat_Learn::count_pending(),
			'groups'        => self::unanswered_groups( (int) $days, 3 ),
		);
	}

	/**
	 * Words visitors type in questions the chat could not answer and that no
	 * entry or synonym has («χρεώνετε»): the administrator says which known
	 * word they mean («κόστος») and they become synonyms.
	 *
	 * @param int $days How far back.
	 * @param int $min  Questions a word must appear in.
	 * @return array<int,array{word:string,like:string,count:int,example:string}>
	 */
	public static function word_suggestions( $days = 90, $min = 3 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT question FROM %i WHERE status IN ( 'unanswered', 'partial', 'unhelpful' ) AND created_at >= %s ORDER BY id DESC LIMIT 2000", PNChat_Store::questions_table(), gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ), ARRAY_A );
		$known = array();
		foreach ( PNChat_Store::entries( null, true ) as $e ) {
			foreach ( array_merge( (array) $e['phrasings'], (array) $e['keywords'] ) as $p ) {
				foreach ( preg_split( '/[^\p{L}\p{N}]+/u', (string) $p, -1, PREG_SPLIT_NO_EMPTY ) as $w ) {
					$f = PNChat_Text::fold( $w );
					if ( mb_strlen( $f ) >= 4 ) {
						$known[ $f ] = $known[ $f ] ?? $w;
					}
				}
			}
		}
		foreach ( PNChat_Matcher::parse_synonyms( (string) PNChat_Settings::value( 'synonyms' ) ) as $g ) {
			foreach ( $g as $w ) {
				$known[ PNChat_Text::fold( $w ) ] = $w;
			}
		}
		$stop  = PNChat_Text::stopwords();
		foreach ( (array) get_option( 'pnchat_ignored_words', array() ) as $w ) {
			$known[ (string) $w ] = (string) $w;
		}
		$count = array();
		$ex    = array();
		foreach ( (array) $rows as $r ) {
			$seen = array();
			foreach ( preg_split( '/[^\p{L}\p{N}]+/u', (string) $r['question'], -1, PREG_SPLIT_NO_EMPTY ) as $w ) {
				$f = PNChat_Text::fold( $w );
				if ( mb_strlen( $f ) < 4 || isset( $known[ $f ] ) || isset( $stop[ $f ] ) || isset( $seen[ $f ] ) ) {
					continue;
				}
				$seen[ $f ]  = true;
				$count[ $f ] = ( $count[ $f ] ?? 0 ) + 1;
				$ex[ $f ]    = $ex[ $f ] ?? array( $w, (string) $r['question'] );
			}
		}
		arsort( $count );
		$out = array();
		foreach ( $count as $f => $n ) {
			if ( $n < $min ) {
				break;
			}
			// A known word it looks like, as a suggestion for the administrator.
			$best = '';
			$sim  = 0.0;
			foreach ( $known as $kf => $kw ) {
				$sv = PNChat_Text::token_similarity( (string) $f, (string) $kf );
				if ( $sv > $sim ) {
					$sim  = $sv;
					$best = $kw;
				}
			}
			$out[] = array(
				'word'    => $ex[ $f ][0],
				'like'    => $best,
				'count'   => $n,
				'example' => $ex[ $f ][1],
			);
			if ( count( $out ) >= 15 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Adds «word» to the synonym group of «like» (or a new group of both).
	 *
	 * @param string $word Visitor's word.
	 * @param string $like Known word.
	 * @return bool
	 */
	public static function add_synonym( $word, $like ) {
		$word = trim( sanitize_text_field( (string) $word ) );
		$like = trim( sanitize_text_field( (string) $like ) );
		if ( '' === $word || '' === $like || false !== strpos( $word . $like, ',' ) ) {
			return false;
		}
		$s     = PNChat_Settings::get();
		$lines = PNChat_Store::lines( (string) $s['synonyms'] );
		$fl    = PNChat_Text::fold( $like );
		$done  = false;
		foreach ( $lines as $i => $line ) {
			$words = array_map( 'trim', explode( ',', $line ) );
			if ( in_array( $fl, array_map( array( 'PNChat_Text', 'fold' ), $words ), true ) ) {
				$lines[ $i ] = $line . ', ' . $word;
				$done        = true;
				break;
			}
		}
		if ( ! $done ) {
			$lines[] = $like . ', ' . $word;
		}
		$s['synonyms'] = implode( "\n", $lines );
		$ok            = PNChat_Settings::save( $s );
		PNChat_Store::bump();
		PNChat_Brain::matcher( true );
		return $ok;
	}

	/**
	 * «Χρειάζονται διόρθωση»: entries whose answer visitors marked 👎.
	 *
	 * @param int $days How far back.
	 * @param int $min  Votes.
	 * @return array<int,array{entry:array<string,mixed>,count:int,questions:string[]}>
	 */
	public static function needs_fixing( $days = 90, $min = 2 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT question, matched FROM %i WHERE status = 'unhelpful' AND matched <> '' AND created_at >= %s ORDER BY id DESC LIMIT 2000", PNChat_Store::questions_table(), gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ), ARRAY_A );
		$by   = array();
		foreach ( (array) $rows as $r ) {
			$first = (int) strtok( (string) $r['matched'], ',' );
			if ( $first ) {
				$by[ $first ][] = (string) $r['question'];
			}
		}
		uasort(
			$by,
			function ( $a, $b ) {
				return count( $b ) <=> count( $a );
			}
		);
		$out = array();
		foreach ( $by as $id => $qs ) {
			$e = PNChat_Store::entry( (int) $id );
			if ( $e && count( $qs ) >= $min ) {
				$out[] = array(
					'entry'     => $e,
					'count'     => count( $qs ),
					'questions' => array_slice( array_values( array_unique( $qs ) ), 0, 5 ),
				);
			}
		}
		return $out;
	}
}
