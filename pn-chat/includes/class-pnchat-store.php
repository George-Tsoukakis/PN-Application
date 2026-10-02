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

	const DB_VERSION = 2;

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
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY status_created (status,created_at),
				KEY email (email),
				KEY created_at (created_at)
			) {$charset};"
		);
		update_option( 'pnchat_db_version', self::DB_VERSION, false );
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
			self::bump();
			return false === $ok ? 0 : (int) $id;
		}
		$row['created_at'] = isset( $data['created_at'] ) && preg_match( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', (string) $data['created_at'] ) ? (string) $data['created_at'] : $now;
		$row['hits']       = absint( $data['hits'] ?? 0 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert( self::entries_table(), $row );
		self::bump();
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Deletes an entry.
	 *
	 * @param int $id Id.
	 * @return void
	 */
	public static function delete_entry( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( self::entries_table(), array( 'id' => (int) $id ) );
		self::bump();
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
	 * Marks the brain as changed (drops the cached matcher).
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
			'created_at' => current_time( 'mysql', true ),
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert( self::questions_table(), $row );
		return $ok ? (int) $wpdb->insert_id : 0;
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
		$allowed = array_intersect_key( $cols, array_flip( array( 'status', 'email', 'name', 'reply', 'replied_at', 'token_hash', 'draft' ) ) );
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
		if ( 'open' !== $filter && 'email' !== $filter && ! array_key_exists( $filter, self::statuses() ) ) {
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
				. ' OR status = %s ) AND ( %s = \'\' OR question LIKE %s OR email LIKE %s ) ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d',
				self::questions_table(),
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
				. ' OR status = %s ) AND ( %s = \'\' OR question LIKE %s OR email LIKE %s )',
				self::questions_table(),
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
	 * @return void
	 */
	public static function delete_questions( array $ids ) {
		global $wpdb;
		foreach ( array_unique( array_filter( array_map( 'absint', $ids ) ) ) as $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d', self::questions_table(), $id ) );
		}
	}

	/**
	 * All questions (for the brain download).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all_questions() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT question, status, unmatched, email, name, reply, replied_at, created_at FROM %i ORDER BY id ASC', self::questions_table() ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
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
					'page_url'   => '',
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

	/**
	 * Drops the tables (uninstall).
	 *
	 * @return void
	 */
	public static function drop() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuerySchemaChange
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i, %i', self::entries_table(), self::questions_table() ) );
	}
}
