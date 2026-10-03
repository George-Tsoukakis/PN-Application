<?php
/**
 * Database: the trained entries (answers and refusals) and the question log.
 *
 * @package PNChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tables and queries.
 */
final class PNChat_Store {

	const DB_VERSION = 6;

	/**
	 * Question statuses and their labels.
	 *
	 * @return array<string,string>
	 */
	public static function statuses() {
		return array(
			'unanswered' => 'Αναπάντητη',
			'partial'    => 'Μερική απάντηση',
			'unhelpful'  => 'Δεν βοήθησε',
			'answered'   => 'Απαντήθηκε',
			'blocked'    => 'Απαγορευμένη',
			'site'       => 'Βρέθηκε στο site',
			'ai'         => 'Απάντηση AI (για έλεγχο)',
			'trained'    => 'Εκπαιδεύτηκε',
			'replied'    => 'Στάλθηκε e-mail',
			'dismissed'  => 'Αγνοήθηκε',
		);
	}

	/**
	 * Statuses that still need someone to look at them.
	 *
	 * @return string[]
	 */
	public static function open_statuses() {
		return array( 'unanswered', 'partial', 'unhelpful' );
	}

	/**
	 * Entries table.
	 *
	 * @return string
	 */
	public static function entries_table() {
		global $wpdb;
		return $wpdb->prefix . 'pnchat_entries';
	}

	/**
	 * Questions table.
	 *
	 * @return string
	 */
	public static function questions_table() {
		global $wpdb;
		return $wpdb->prefix . 'pnchat_questions';
	}

	/**
	 * Creates or upgrades the tables.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$e       = self::entries_table();
		$q       = self::questions_table();

		dbDelta(
			"CREATE TABLE {$e} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				kind varchar(10) NOT NULL DEFAULT 'answer',
				title varchar(255) NOT NULL DEFAULT '',
				phrasings text NOT NULL,
				keywords text NOT NULL,
				answer longtext NOT NULL,
				active tinyint(1) NOT NULL DEFAULT 1,
				hits bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY kind_active (kind,active)
			) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$q} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				question text NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'unanswered',
				matched varchar(255) NOT NULL DEFAULT '',
				unmatched text NOT NULL,
				email varchar(190) NOT NULL DEFAULT '',
				name varchar(190) NOT NULL DEFAULT '',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				token_hash char(64) NOT NULL DEFAULT '',
				reply longtext NOT NULL,
				replied_at datetime NULL DEFAULT NULL,
				draft longtext NULL,
				page_url varchar(255) NOT NULL DEFAULT '',
				conv char(32) NOT NULL DEFAULT '',
				hint_entry bigint(20) unsigned NOT NULL DEFAULT 0,
				offered varchar(100) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY status_created (status,created_at),
				KEY conv (conv),
				KEY email (email),
				KEY created_at (created_at)
			) {$charset};"
		);
		dbDelta(
			'CREATE TABLE ' . PNChat_Counter::table() . " (
				k varchar(100) NOT NULL,
				n bigint(20) NOT NULL DEFAULT 0,
				exp bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (k),
				KEY exp (exp)
			) {$charset};"
		);
		// 1.9.0: entries proposed by the AI, waiting for approval.
		dbDelta( PNChat_Learn::table_sql( $charset ) );
		// 1.10.0: wordings learned from conversations.
		dbDelta( PNChat_Lessons::table_sql( $charset ) );
		update_option( 'pnchat_db_version', self::DB_VERSION, false );
		// 1.8.0: the AI usage totals move from an option to the counters.
		// Here, so that every path that installs (activation, upgrade) does it.
		PNChat_AI::migrate_usage();
	}

	/**
	 * Splits a textarea into trimmed non-empty lines.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function lines( $text ) {
		$out = array();
		foreach ( preg_split( '/\R/u', (string) $text ) as $l ) {
			$l = trim( (string) $l );
			if ( '' !== $l ) {
				$out[] = $l;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Row to entry array.
	 *
	 * @param array<string,mixed> $row DB row.
	 * @return array<string,mixed>
	 */
	private static function hydrate( array $row ) {
		return array(
			'id'         => (int) $row['id'],
			'kind'       => 'block' === $row['kind'] ? 'block' : 'answer',
			'title'      => (string) $row['title'],
			'phrasings'  => self::lines( (string) $row['phrasings'] ),
			'keywords'   => self::lines( (string) $row['keywords'] ),
			'answer'     => (string) $row['answer'],
			'active'     => (int) $row['active'],
			'hits'       => (int) $row['hits'],
			'created_at' => (string) $row['created_at'],
			'updated_at' => (string) $row['updated_at'],
		);
	}

	/**
	 * Entries.
	 *
	 * @param string|null $kind        'answer', 'block' or null for both.
	 * @param bool        $active_only Only active ones.
	 * @param string      $search      Optional text filter.
	 * @return array<int,array<string,mixed>>
	 */
	public static function entries( $kind = null, $active_only = false, $search = '' ) {
		global $wpdb;
		$like = '' !== $search ? '%' . $wpdb->esc_like( $search ) . '%' : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE ( %s = \'\' OR kind = %s ) AND ( %d = 0 OR active = 1 )'
				. ' AND ( %s = \'\' OR title LIKE %s OR phrasings LIKE %s OR keywords LIKE %s OR answer LIKE %s )'
				. ' ORDER BY title ASC, id ASC',
				self::entries_table(),
				(string) $kind,
				(string) $kind,
				$active_only ? 1 : 0,
				$like,
				$like,
				$like,
				$like,
				$like
			),
			ARRAY_A
		);
		return array_map( array( __CLASS__, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * One entry.
	 *
	 * @param int $id Id.
	 * @return array<string,mixed>|null
	 */
	public static function entry( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::entries_table(), $id ), ARRAY_A );
		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Inserts or updates an entry.
	 *
	 * @param array<string,mixed> $data Entry (phrasings/keywords as arrays).
	 * @param int                 $id   0 to insert.
	 * @return int Id, 0 on failure.
	 */
	public static function save_entry( array $data, $id = 0 ) {
		$saved = self::write_entry( $data, $id );
		self::bump();
		return $saved;
	}

	/**
	 * Writes an entry without marking the brain as changed.
	 *
	 * @param array<string,mixed> $data Entry.
	 * @param int                 $id   0 to insert.
	 * @return int Id, 0 on failure.
	 */
	private static function write_entry( array $data, $id = 0 ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$row = array(
			'kind'       => ( isset( $data['kind'] ) && 'block' === $data['kind'] ) ? 'block' : 'answer',
			'title'      => mb_substr( (string) ( $data['title'] ?? '' ), 0, 255 ),
			'phrasings'  => implode( "\n", self::lines( implode( "\n", (array) ( $data['phrasings'] ?? array() ) ) ) ),
			'keywords'   => implode( "\n", self::lines( implode( "\n", (array) ( $data['keywords'] ?? array() ) ) ) ),
			'answer'     => (string) ( $data['answer'] ?? '' ),
			'active'     => empty( $data['active'] ) ? 0 : 1,
			'updated_at' => $now,
		);
		if ( $id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ok = $wpdb->update( self::entries_table(), $row, array( 'id' => $id ) );
			return false === $ok ? 0 : (int) $id;
		}
		$row['created_at'] = isset( $data['created_at'] ) && preg_match( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', (string) $data['created_at'] ) ? (string) $data['created_at'] : $now;
		$row['hits']       = absint( $data['hits'] ?? 0 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert( self::entries_table(), $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Replaces every entry with the given ones, all or nothing: when one of
	 * them cannot be written, or the COMMIT fails, the entries that were
	 * there come back. Whether they did is checked on their content, not
	 * their number; a table without transactions (MyISAM) gets them back
	 * by hand, with their ids.
	 *
	 * @param array<int,array<string,mixed>> $entries Clean entries.
	 * @return int|WP_Error Entries written.
	 */
	public static function replace_entries( array $entries ) {
		global $wpdb;
		$previous = self::raw_entries();
		$before   = self::fingerprint( $previous );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'START TRANSACTION' );
		$ok    = false !== $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', self::entries_table() ) );
		$added = 0;
		foreach ( $entries as $e ) {
			if ( ! $ok ) {
				break;
			}
			$ok = self::write_entry( $e ) > 0;
			if ( $ok ) {
				++$added;
			}
		}
		if ( $ok && false !== $wpdb->query( 'COMMIT' ) ) {
			self::bump();
			return $added;
		}
		$wpdb->query( 'ROLLBACK' );
		$restored = self::fingerprint( self::raw_entries() ) === $before;
		if ( ! $restored ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', self::entries_table() ) );
			foreach ( $previous as $row ) {
				$wpdb->insert( self::entries_table(), $row );
			}
			$restored = self::fingerprint( self::raw_entries() ) === $before;
		}
		// phpcs:enable
		self::bump();
		return new WP_Error(
			'pnchat_replace',
			$restored
				? 'μια γνώση δεν γράφτηκε στη βάση, οπότε ο εγκέφαλος έμεινε όπως ήταν'
				: 'μια γνώση δεν γράφτηκε στη βάση και οι προηγούμενες γνώσεις δεν επανήλθαν όλες· επαναφέρετε το αυτόματο αντίγραφο από το «Εγκέφαλος»'
		);
	}

	/**
	 * Every entry row as stored, by id.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function raw_entries() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id ASC', self::entries_table() ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * What the entries say (not their use counts, which visitors change).
	 *
	 * @param array<int,array<string,mixed>> $rows From raw_entries().
	 * @return string
	 */
	private static function fingerprint( array $rows ) {
		$keep = array_flip( array( 'id', 'kind', 'title', 'phrasings', 'keywords', 'answer', 'active' ) );
		return md5(
			(string) wp_json_encode(
				array_map(
					function ( $r ) use ( $keep ) {
						return array_map( 'strval', array_intersect_key( $r, $keep ) );
					},
					$rows
				)
			)
		);
	}

	/**
	 * Deletes an entry.
	 *
	 * @param int $id Id.
	 * @return bool False when the database refused.
	 */
	public static function delete_entry( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->delete( self::entries_table(), array( 'id' => (int) $id ) );
		self::bump();
		return false !== $ok;
	}

	/**
	 * Adds one phrasing to an existing entry (training from a question).
	 *
	 * @param int    $id       Entry id.
	 * @param string $phrasing New phrasing.
	 * @return bool
	 */
	public static function add_phrasing( $id, $phrasing ) {
		$e = self::entry( $id );
		if ( ! $e ) {
			return false;
		}
		$e['phrasings'][] = $phrasing;
		return self::save_entry( $e, $id ) > 0;
	}

	/**
	 * Counts that an entry was used.
	 *
	 * @param int[] $ids Ids.
	 * @return void
	 */
	public static function count_hits( array $ids ) {
		global $wpdb;
		// At most a few entries per answer: one small update each.
		foreach ( array_unique( array_filter( array_map( 'absint', $ids ) ) ) as $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET hits = hits + 1 WHERE id = %d', self::entries_table(), $id ) );
		}
	}

	/**
	 * Deletes every entry (before a "replace" import).
	 *
	 * @return void
	 */
	public static function delete_all_entries() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', self::entries_table() ) );
		self::bump();
	}

	/**
	 * Marks the brain as changed (the version tells that it changed).
	 *
	 * @return void
	 */
	public static function bump() {
		update_option( 'pnchat_brain_version', time() . wp_rand( 100, 999 ), false );
	}

	/**
	 * Logs a question.
	 *
	 * @param array<string,mixed> $data Columns.
	 * @return int Id.
	 */
	public static function log_question( array $data ) {
		global $wpdb;
		$row = array(
			'question'   => mb_substr( (string) ( $data['question'] ?? '' ), 0, 1000 ),
			'status'     => array_key_exists( (string) ( $data['status'] ?? '' ), self::statuses() ) ? (string) $data['status'] : 'unanswered',
			'matched'    => mb_substr( implode( ',', array_map( 'absint', (array) ( $data['matched'] ?? array() ) ) ), 0, 255 ),
			'unmatched'  => implode( "\n", (array) ( $data['unmatched'] ?? array() ) ),
			'email'      => '',
			'name'       => '',
			'user_id'    => absint( $data['user_id'] ?? 0 ),
			'token_hash' => (string) ( $data['token_hash'] ?? '' ),
			'reply'      => '',
			'page_url'   => mb_substr( (string) ( $data['page_url'] ?? '' ), 0, 255 ),
			'draft'      => isset( $data['draft'] ) ? wp_json_encode( $data['draft'] ) : null,
			'conv'       => (string) ( $data['conv'] ?? '' ),
			'offered'    => mb_substr( implode( ',', array_map( 'absint', (array) ( $data['offered'] ?? array() ) ) ), 0, 100 ),
			'created_at' => current_time( 'mysql', true ),
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert( self::questions_table(), $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Learning from conversations: a visitor's question the chat could not
	 * answer, followed in the same conversation by one it answered (the
	 * visitor said it in other words), gets that entry as a hint. The
	 * administrator sees «Μάλλον εννοούσε …» and adds it with one click;
	 * nothing changes on its own.
	 *
	 * @param string $conv     Conversation (hashed).
	 * @param int    $entry_id Entry that answered.
	 * @param int    $minutes  How far back.
	 * @return int The question that got the hint (0 for none).
	 */
	public static function hint_previous( $conv, $entry_id, $minutes = 10 ) {
		global $wpdb;
		if ( '' === $conv || ! $entry_id ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM %i WHERE conv = %s AND hint_entry = 0 AND status IN ( 'unanswered', 'partial' ) AND created_at >= %s ORDER BY id DESC LIMIT 1",
				self::questions_table(),
				$conv,
				gmdate( 'Y-m-d H:i:s', time() - $minutes * MINUTE_IN_SECONDS )
			)
		);
		if ( ! $id ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( self::questions_table(), array( 'hint_entry' => (int) $entry_id ), array( 'id' => $id ) );
		return $id;
	}

	/**
	 * One question.
	 *
	 * @param int $id Id.
	 * @return array<string,mixed>|null
	 */
	public static function question( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::questions_table(), $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * The AI answer stored with a question (proposed entry), if any.
	 *
	 * @param array<string,mixed> $q Question row.
	 * @return array<string,mixed>|null
	 */
	public static function draft_of( array $q ) {
		$d = isset( $q['draft'] ) && is_string( $q['draft'] ) ? json_decode( $q['draft'], true ) : null;
		return is_array( $d ) && isset( $d['entry'] ) && is_array( $d['entry'] ) ? $d : null;
	}

	/**
	 * Updates columns of a question.
	 *
	 * @param int                 $id   Id.
	 * @param array<string,mixed> $cols Columns.
	 * @return bool
	 */
	public static function update_question( $id, array $cols ) {
		global $wpdb;
		$allowed = array_intersect_key( $cols, array_flip( array( 'status', 'email', 'name', 'reply', 'replied_at', 'token_hash', 'draft', 'hint_entry' ) ) );
		if ( ! $allowed ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $wpdb->update( self::questions_table(), $allowed, array( 'id' => (int) $id ) );
	}

	/**
	 * Questions page.
	 *
	 * @param string $filter   'open', 'email', a status, or 'all'.
	 * @param string $search   Text filter.
	 * @param int    $page     1-based page.
	 * @param int    $per_page Rows per page.
	 * @return array{rows:array<int,array<string,mixed>>,total:int}
	 */
	public static function questions( $filter = 'open', $search = '', $page = 1, $per_page = 30 ) {
		global $wpdb;
		if ( ! in_array( $filter, array( 'open', 'email', 'hint' ), true ) && ! array_key_exists( $filter, self::statuses() ) ) {
			$filter = 'all';
		}
		$like   = '' !== $search ? '%' . $wpdb->esc_like( $search ) . '%' : '';
		$offset = max( 0, ( $page - 1 ) * $per_page );
		// One fixed query for every filter: 'all', 'open', 'email' or a status.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE ( %s = \'all\''
				. ' OR ( %s = \'open\' AND status IN ( \'unanswered\', \'partial\', \'unhelpful\' ) )'
				. ' OR ( %s = \'email\' AND email <> \'\' AND status IN ( \'unanswered\', \'partial\', \'unhelpful\', \'trained\', \'ai\' ) )'
				. ' OR ( %s = \'hint\' AND hint_entry > 0 AND status IN ( \'unanswered\', \'partial\' ) )'
				. ' OR status = %s ) AND ( %s = \'\' OR question LIKE %s OR email LIKE %s ) ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d',
				self::questions_table(),
				$filter,
				$filter,
				$filter,
				$filter,
				$filter,
				$like,
				$like,
				$like,
				$per_page,
				$offset
			),
			ARRAY_A
		);
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE ( %s = \'all\''
				. ' OR ( %s = \'open\' AND status IN ( \'unanswered\', \'partial\', \'unhelpful\' ) )'
				. ' OR ( %s = \'email\' AND email <> \'\' AND status IN ( \'unanswered\', \'partial\', \'unhelpful\', \'trained\', \'ai\' ) )'
				. ' OR ( %s = \'hint\' AND hint_entry > 0 AND status IN ( \'unanswered\', \'partial\' ) )'
				. ' OR status = %s ) AND ( %s = \'\' OR question LIKE %s OR email LIKE %s )',
				self::questions_table(),
				$filter,
				$filter,
				$filter,
				$filter,
				$filter,
				$like,
				$like,
				$like
			)
		);
		// phpcs:enable
		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Counts per status, plus 'open' and 'email'.
	 *
	 * @return array<string,int>
	 */
	public static function question_counts() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS n, SUM(email <> '') AS e FROM %i GROUP BY status", self::questions_table() ), ARRAY_A );
		$counts = array(
			'all'   => 0,
			'open'  => 0,
			'email' => 0,
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'hint'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE hint_entry > 0 AND status IN ( 'unanswered', 'partial' )", self::questions_table() ) ),
		);
		foreach ( (array) $rows as $r ) {
			$s            = (string) $r['status'];
			$n            = (int) $r['n'];
			$counts[ $s ] = $n;
			$counts['all'] += $n;
			if ( in_array( $s, self::open_statuses(), true ) ) {
				$counts['open'] += $n;
			}
			if ( in_array( $s, array_merge( self::open_statuses(), array( 'trained', 'ai' ) ), true ) ) {
				$counts['email'] += (int) $r['e'];
			}
		}
		return $counts;
	}

	/**
	 * Deletes questions.
	 *
	 * @param int[] $ids Ids.
	 * @return bool False when the database refused one.
	 */
	public static function delete_questions( array $ids ) {
		global $wpdb;
		$ok = true;
		foreach ( array_unique( array_filter( array_map( 'absint', $ids ) ) ) as $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ok = false !== $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d', self::questions_table(), $id ) ) && $ok;
		}
		return $ok;
	}

	/**
	 * All questions (for the brain download).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all_questions() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT question, status, unmatched, email, name, reply, replied_at, draft, page_url, created_at FROM %i ORDER BY id ASC', self::questions_table() ), ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			// The AI answer waiting for review travels with its question.
			$r['draft'] = self::draft_of( $r );
			$out[]      = $r;
		}
		return $out;
	}

	/**
	 * Restores logged questions from a download.
	 *
	 * @param array<int,mixed> $rows Rows.
	 * @return int Restored count.
	 */
	public static function import_questions( array $rows ) {
		global $wpdb;
		$n = 0;
		foreach ( $rows as $r ) {
			if ( ! is_array( $r ) || empty( $r['question'] ) ) {
				continue;
			}
			$created = isset( $r['created_at'] ) && preg_match( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', (string) $r['created_at'] ) ? (string) $r['created_at'] : current_time( 'mysql', true );
			$replied = isset( $r['replied_at'] ) && preg_match( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', (string) $r['replied_at'] ) ? (string) $r['replied_at'] : null;
			$email   = sanitize_email( (string) ( $r['email'] ?? '' ) );
			$page    = esc_url_raw( (string) ( $r['page_url'] ?? '' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ok = $wpdb->insert(
				self::questions_table(),
				array(
					'question'   => mb_substr( sanitize_textarea_field( (string) $r['question'] ), 0, 1000 ),
					'status'     => array_key_exists( (string) ( $r['status'] ?? '' ), self::statuses() ) ? (string) $r['status'] : 'unanswered',
					'matched'    => '',
					'unmatched'  => sanitize_textarea_field( (string) ( $r['unmatched'] ?? '' ) ),
					'email'      => is_email( $email ) ? $email : '',
					'name'       => sanitize_text_field( (string) ( $r['name'] ?? '' ) ),
					'user_id'    => 0,
					'token_hash' => '',
					'reply'      => wp_kses_post( (string) ( $r['reply'] ?? '' ) ),
					'replied_at' => $replied,
					'page_url'   => mb_substr( wp_http_validate_url( $page ) ? $page : '', 0, 255 ),
					'draft'      => self::clean_draft( $r['draft'] ?? null ),
					'created_at' => $created,
				)
			);
			if ( $ok ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * An imported AI answer, cleaned like an imported entry; null for none.
	 *
	 * @param mixed $draft Array, or the JSON of one.
	 * @return string|null JSON for the draft column.
	 */
	private static function clean_draft( $draft ) {
		if ( is_string( $draft ) ) {
			$draft = json_decode( $draft, true );
		}
		if ( ! is_array( $draft ) || ! isset( $draft['entry'] ) ) {
			return null;
		}
		$entry = PNChat_Brain::clean_entry( $draft['entry'] );
		if ( ! $entry ) {
			return null;
		}
		$sources = array();
		foreach ( (array) ( $draft['sources'] ?? array() ) as $src ) {
			if ( is_array( $src ) && isset( $src['url'] ) ) {
				$sources[] = array(
					'title' => sanitize_text_field( (string) ( $src['title'] ?? '' ) ),
					'url'   => esc_url_raw( (string) $src['url'] ),
				);
			}
		}
		return (string) wp_json_encode(
			array(
				'found'   => true,
				'entry'   => $entry,
				'sources' => $sources,
				'note'    => sanitize_text_field( (string) ( $draft['note'] ?? '' ) ),
			)
		);
	}

	/**
	 * Deletes questions older than the retention period.
	 *
	 * @param int $days Days; 0 keeps everything.
	 * @return void
	 */
	public static function purge_old( $days ) {
		global $wpdb;
		$days = absint( $days );
		if ( $days < 1 ) {
			return;
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', self::questions_table(), $cutoff ) );
	}

}
