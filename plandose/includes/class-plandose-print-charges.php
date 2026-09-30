<?php
/**
 * The print-charge ledger.
 *
 * Two tables, both InnoDB, no plan data in either:
 *
 * `*_plandose_print_charges` — one row per print token this pharmacy was
 * charged for, for PRINT_RECEIPT_TTL seconds: user, an md5 of the token,
 * when it was charged and how many free reprints it has used.
 *
 * `*_plandose_print_requests` — one row per request id (one press of
 * «Εκτύπωση», see assets/js/api.js) that CHARGED a token or USED a free
 * reprint, for PRINT_RECEIPT_TTL seconds: user, an md5 of the request id,
 * the token it belongs to and what it did. Unique per (user, request).
 * The same request sent again — retries after lost answers — is answered
 * from this row and never charges or uses a free reprint a second time
 * (idempotency), at most MAX_REPLAYS times (replay_count, see
 * use_replay()).
 *
 * The charge row, the request row and the counter are written in ONE
 * transaction (Plandose_Print_New_Charge); a free reprint is a
 * conditional UPDATE (`reprints < max`) in one transaction with its
 * request row. A receipt written AFTER the counter as a separate
 * unchecked write would leave a charged print without a receipt on a
 * failure in between (its free reprint then charged again), and a free
 * reprint counted with an unchecked write would let concurrent reprints
 * of one plan all go free.
 *
 * Works with: includes/class-plandose-ajax.php, class-plandose-subscriptions.php
 * (schema), class-plandose-privacy.php (eraser), class-plandose-cli.php
 * (engine check), uninstall.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plandose_Print_Charges {

	/**
	 * The charged_ts the last write_charge() of this request wrote
	 * (0 = none yet); see undo_charge().
	 *
	 * @var int
	 */
	private static $last_written_ts = 0;

	/** What a request did. */
	const KIND_CHARGE  = 'charge';
	const KIND_REPRINT = 'reprint';

	/**
	 * How many times one request id may be answered from its record
	 * («already recorded», the client prints) within PRINT_RECEIPT_TTL.
	 *
	 * A replay prints without charging or using a free reprint, so an
	 * unbounded number would let one request id print again and again for 30
	 * minutes. The client re-sends a request id only after a lost answer.
	 * Counted atomically in requests.replay_count (use_replay()); the next
	 * replay is refused, neither charged nor printed.
	 *
	 * A limit of its own, not Plandose_Ajax::MAX_FREE_REPRINTS: replays of
	 * one press and free reprints of one plan are different things, and
	 * changing the free-reprint allowance must not silently change how
	 * often a lost answer may be retried. Same value today (2) — the
	 * client's «έως %d επαναλήψεις» notices (print.js, api.js) print the
	 * maxFreeReprints figure, so change both together or give the notice
	 * this number.
	 */
	const MAX_REPLAYS = 2;

	/** Transient caching the storage-engine check (see engines_ok()). */
	const ENGINE_CHECK_TRANSIENT = 'plandose_engine_check';

	/**
	 * Charge table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'plandose_print_charges';
	}

	/**
	 * Request table name.
	 *
	 * @return string
	 */
	public static function requests_table_name() {
		global $wpdb;

		return $wpdb->prefix . 'plandose_print_requests';
	}

	/**
	 * CREATE TABLE statements for dbDelta(). ENGINE=InnoDB explicitly: the
	 * guarantees above need transactions.
	 *
	 * @param string $charset_collate From $wpdb->get_charset_collate().
	 * @return string[]
	 */
	public static function schema_sql( $charset_collate ) {
		$table    = self::table_name();
		$requests = self::requests_table_name();

		return array(
			"CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			token_hash CHAR(32) NOT NULL,
			charged_ts INT UNSIGNED NOT NULL,
			reprints SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY user_token (user_id,token_hash),
			KEY charged_ts (charged_ts)
		) ENGINE=InnoDB {$charset_collate};",
			"CREATE TABLE {$requests} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			request_hash CHAR(32) NOT NULL,
			token_hash CHAR(32) NOT NULL,
			kind VARCHAR(8) NOT NULL,
			created_ts INT UNSIGNED NOT NULL,
			replay_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY user_request (user_id,request_hash),
			KEY created_ts (created_ts)
		) ENGINE=InnoDB {$charset_collate};",
		);
	}

	/**
	 * Oldest timestamp that is still live.
	 *
	 * @return int
	 */
	public static function live_since() {
		return time() - Plandose_Ajax::PRINT_RECEIPT_TTL;
	}

	/**
	 * The charge row for one token, live or not; null when absent. false on
	 * a database error, so a failed read is never taken for "not charged".
	 *
	 * @param int    $user_id    Pharmacy user ID.
	 * @param string $token_hash md5 of the token.
	 * @return object|null|false
	 */
	public static function find( $user_id, $token_hash ) {
		global $wpdb;

		$table = self::table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table; {$table} is $wpdb->prefix + fixed suffix; must read the database, never a cache.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, user_id, token_hash, charged_ts, reprints FROM {$table} WHERE user_id = %d AND token_hash = %s",
				absint( $user_id ),
				(string) $token_hash
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( null === $row && '' !== (string) $wpdb->last_error ) {
			return false;
		}

		return $row ? $row : null;
	}

	/**
	 * Whether a charge row is still inside the free-reprint window.
	 *
	 * @param object $row From find().
	 * @return bool
	 */
	public static function is_live( $row ) {
		return is_object( $row ) && (int) $row->charged_ts >= self::live_since();
	}

	/**
	 * The live request row for one request id; null when absent (or older
	 * than the window), false on a database error.
	 *
	 * @param int    $user_id      Pharmacy user ID.
	 * @param string $request_hash md5 of the request id.
	 * @return object|null|false
	 */
	public static function find_request( $user_id, $request_hash ) {
		global $wpdb;

		if ( '' === (string) $request_hash ) {
			return null;
		}

		$table = self::requests_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, user_id, request_hash, token_hash, kind, created_ts FROM {$table} WHERE user_id = %d AND request_hash = %s",
				absint( $user_id ),
				(string) $request_hash
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( null === $row && '' !== (string) $wpdb->last_error ) {
			return false;
		}

		if ( ! $row || (int) $row->created_ts < self::live_since() ) {
			return null;
		}

		return $row;
	}

	/**
	 * Record what a request did. Must run inside the caller's transaction.
	 *
	 * @param int    $user_id      Pharmacy user ID.
	 * @param string $request_hash md5 of the request id.
	 * @param string $token_hash   md5 of the token.
	 * @param string $kind         KIND_CHARGE or KIND_REPRINT.
	 * @return string 'ok', 'duplicate' (this request id is already
	 *                recorded — answer from it) or 'error'.
	 */
	public static function record_request( $user_id, $request_hash, $token_hash, $kind ) {
		global $wpdb;

		$table = self::requests_table_name();

		/*
		 * No range DELETE of expired rows here: inside the transaction its
		 * gap locks deadlocked concurrent prints of the same pharmacy. An
		 * expired row with the same id (only possible for an id re-sent
		 * after 30 minutes) is removed by its primary key after the
		 * duplicate-key error, and the insert tried once more.
		 */
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$suppress = $wpdb->suppress_errors( true );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
			$result = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table} (user_id, request_hash, token_hash, kind, created_ts) VALUES (%d, %s, %s, %s, %d)",
					absint( $user_id ),
					(string) $request_hash,
					(string) $token_hash,
					(string) $kind,
					time()
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->suppress_errors( $suppress );

			if ( false !== $result && (int) $result > 0 ) {
				return 'ok';
			}

			if ( ! self::last_error_is_duplicate_key() ) {
				return 'error';
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, created_ts FROM {$table} WHERE user_id = %d AND request_hash = %s", absint( $user_id ), (string) $request_hash ) );

			if ( ! $existing || (int) $existing->created_ts >= self::live_since() ) {
				return 'duplicate';
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table; by primary key.
			$gone = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id = %d AND created_ts < %d", (int) $existing->id, self::live_since() ) );

			// A failed delete (e.g. a deadlock, which also ends the
			// transaction) must not be followed by an insert outside it.
			if ( false === $gone ) {
				return 'error';
			}
		}

		return 'error';
	}

	/**
	 * Whether the last failed statement was rejected by a UNIQUE key.
	 *
	 * Decided on the MySQL error number (ER_DUP_ENTRY = 1062), not on the
	 * message text: with a translated server (lc_messages, e.g. el_GR) the
	 * text does not contain "duplicate". The text check is only a fallback
	 * for a non-mysqli connection (custom db drop-ins).
	 *
	 * @return bool
	 */
	public static function last_error_is_duplicate_key() {
		global $wpdb;

		$dbh = isset( $wpdb->dbh ) ? $wpdb->dbh : null;

		if ( $dbh instanceof mysqli ) {
			return 1062 === (int) mysqli_errno( $dbh ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_errno -- $wpdb exposes no error number; see the docblock.
		}

		return false !== stripos( (string) $wpdb->last_error, 'duplicate' );
	}

	/**
	 * Count one answered replay of a request record, atomically:
	 * only while the record is live and has replays left. Of any number of
	 * concurrent replays at most MAX_REPLAYS can get 1 (the row lock
	 * serializes the conditional UPDATE).
	 *
	 * @param object $request Row from find_request().
	 * @return int|false 1 when counted (answer it), 0 when used up or
	 *                   expired, false on a database error.
	 */
	public static function use_replay( $request ) {
		global $wpdb;

		if ( ! is_object( $request ) || empty( $request->id ) ) {
			return false;
		}

		$table = self::requests_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table; conditional update is the whole point.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET replay_count = replay_count + 1 WHERE id = %d AND replay_count < %d AND created_ts >= %d",
				(int) $request->id,
				self::MAX_REPLAYS,
				self::live_since()
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( false === $updated ) {
			return false;
		}

		return (int) $updated > 0 ? 1 : 0;
	}

	/**
	 * Count one free reprint, atomically: only while the row is live, has
	 * reprints left and still holds the values it was read with.
	 *
	 * @param object $row From find().
	 * @param int    $max Free reprints allowed per charge.
	 * @return int|false 1 when counted, 0 when not (used up, expired or
	 *                   changed meanwhile), false on a database error.
	 */
	public static function use_reprint( $row, $max ) {
		global $wpdb;

		$table = self::table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table; conditional update is the whole point.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET reprints = reprints + 1
				 WHERE id = %d AND reprints = %d AND reprints < %d AND charged_ts = %d AND charged_ts >= %d",
				(int) $row->id,
				(int) $row->reprints,
				(int) $max,
				(int) $row->charged_ts,
				self::live_since()
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( false === $updated ) {
			return false;
		}

		return (int) $updated > 0 ? 1 : 0;
	}

	/**
	 * Write the charge row for a new (or re-charged) print. Must run inside
	 * the caller's transaction, together with the counter increment.
	 *
	 * @param int         $user_id    Pharmacy user ID.
	 * @param string      $token_hash md5 of the token.
	 * @param object|null $previous   The token's existing row (expired or
	 *                                out of free reprints), if any.
	 * @return bool False when the row could not be written — including
	 *              when $previous changed meanwhile or another request
	 *              inserted the same token first.
	 */
	public static function write_charge( $user_id, $token_hash, $previous = null ) {
		global $wpdb;

		$table = self::table_name();
		$now   = time();

		self::$last_written_ts = 0;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
		if ( is_object( $previous ) ) {
			$result = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table}
					 SET charged_ts = %d, reprints = 0
					 WHERE id = %d AND charged_ts = %d AND reprints = %d",
					$now,
					(int) $previous->id,
					(int) $previous->charged_ts,
					(int) $previous->reprints
				)
			);
		} else {
			$suppress = $wpdb->suppress_errors( true );
			$result   = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table} (user_id, token_hash, charged_ts, reprints) VALUES (%d, %s, %d, 0)",
					absint( $user_id ),
					(string) $token_hash,
					$now
				)
			);
			$wpdb->suppress_errors( $suppress );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$written = false !== $result && (int) $result > 0;

		// Remembered for undo_charge(), which then puts back only
		// a row that still holds what THIS request wrote — and nothing at
		// all when this request's write did not happen.
		if ( $written ) {
			self::$last_written_ts = $now;
		}

		return $written;
	}

	/**
	 * Undo write_charge() / record_request() by hand, for a table engine
	 * without transactions (see engines_ok()). Harmless after a real
	 * rollback. Best effort.
	 *
	 * @param int         $user_id      Pharmacy user ID.
	 * @param string      $token_hash   md5 of the token.
	 * @param object|null $previous     What write_charge() replaced, if any.
	 * @param string      $request_hash md5 of the request id recorded, or ''.
	 */
	public static function undo_charge( $user_id, $token_hash, $previous = null, $request_hash = '' ) {
		global $wpdb;

		$table   = self::table_name();
		$written = (int) self::$last_written_ts;

		/*
		 * Compare-and-swap. Only a row that still holds exactly
		 * what write_charge() wrote in this request (its time, no reprint
		 * used) is put back. Restoring or deleting the row whatever it held
		 * would let the undo of a request that lost its connection erase a
		 * charge another request had meanwhile committed for the same token —
		 * and that pharmacist's next «Εκτύπωση ξανά» would be charged again.
		 */
		if ( $written < 1 ) {
			return;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
		if ( is_object( $previous ) ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET charged_ts = %d, reprints = %d WHERE id = %d AND charged_ts = %d AND reprints = 0",
					(int) $previous->charged_ts,
					(int) $previous->reprints,
					(int) $previous->id,
					$written
				)
			);
		} else {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE user_id = %d AND token_hash = %s AND charged_ts = %d AND reprints = 0",
					absint( $user_id ),
					(string) $token_hash,
					$written
				)
			);
		}

		// The request row only as THIS charge wrote it (same token, a
		// charge): a record of the same request id for anything else is
		// not this undo's to delete.
		if ( '' !== (string) $request_hash ) {
			$requests = self::requests_table_name();
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$requests} WHERE user_id = %d AND request_hash = %s AND token_hash = %s AND kind = %s",
					absint( $user_id ),
					(string) $request_hash,
					(string) $token_hash,
					self::KIND_CHARGE
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Delete one request record (non-transactional fallback). Best effort.
	 *
	 * @param int    $user_id      Pharmacy user ID.
	 * @param string $request_hash md5 of the request id.
	 */
	public static function forget_request( $user_id, $request_hash ) {
		global $wpdb;

		if ( '' === (string) $request_hash ) {
			return;
		}

		$requests = self::requests_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$requests} WHERE user_id = %d AND request_hash = %s", absint( $user_id ), (string) $request_hash ) );
	}

	/**
	 * Start a transaction. Returns false when the server refused it — the
	 * caller must then not write anything.
	 *
	 * @return bool
	 */
	public static function begin() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control.
		return false !== $wpdb->query( 'START TRANSACTION' );
	}

	/**
	 * @return bool
	 */
	public static function commit() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control.
		return false !== $wpdb->query( 'COMMIT' );
	}

	/**
	 * @return bool
	 */
	public static function rollback() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control.
		return false !== $wpdb->query( 'ROLLBACK' );
	}

	/**
	 * The tables a print writes in one transaction, with their storage
	 * engine. Only InnoDB guarantees «all or nothing» here.
	 *
	 * @return array<string,string> table => engine ('' when the table is missing).
	 */
	public static function engines() {
		global $wpdb;

		$tables = array(
			Plandose_Subscriptions::table_name(),
			self::table_name(),
			self::requests_table_name(),
		);
		$result = array_fill_keys( $tables, '' );

		// information_schema may report the name in another case
		// (lower_case_table_names=1 with a mixed-case prefix), so match
		// case-insensitively and key the result by OUR spelling.
		$by_lower = array();

		foreach ( $tables as $table ) {
			$by_lower[ strtolower( $table ) ] = $table;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME AS t, ENGINE AS e FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s, %s, %s)',
				$tables[0],
				$tables[1],
				$tables[2]
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( (array) $rows as $row ) {
			$key = strtolower( (string) $row->t );

			if ( isset( $by_lower[ $key ] ) ) {
				$result[ $by_lower[ $key ] ] = (string) $row->e;
			}
		}

		return $result;
	}

	/**
	 * Whether every table in engines() is InnoDB. Cached for 12 hours;
	 * pass $fresh to re-check (activation, upgrade, `wp plandose check`).
	 *
	 * @param bool $fresh Skip the cache.
	 * @return bool
	 */
	public static function engines_ok( $fresh = false ) {
		return 'ok' === self::engines_state( $fresh );
	}

	/**
	 * 'ok' (all InnoDB), 'bad' (a table is known NOT to be InnoDB)
	 * or 'unknown' (the check itself failed). Only 'bad' is cached for long
	 * (1 hour); 'unknown' is never cached.
	 *
	 * @param bool $fresh Skip the cache.
	 * @return string
	 */
	public static function engines_state( $fresh = false ) {
		if ( ! $fresh ) {
			$cached = get_transient( self::ENGINE_CHECK_TRANSIENT );

			if ( 'ok' === $cached || 'bad' === $cached ) {
				return $cached;
			}
		}

		global $wpdb;

		$engines = self::engines();

		if ( '' !== (string) $wpdb->last_error ) {
			return 'unknown';
		}

		$state = 'ok';

		foreach ( $engines as $engine ) {
			if ( '' === $engine ) {
				return 'unknown';
			}

			if ( 0 !== strcasecmp( $engine, 'InnoDB' ) ) {
				$state = 'bad';
			}
		}

		set_transient( self::ENGINE_CHECK_TRANSIENT, $state, 'ok' === $state ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );

		return $state;
	}

	/**
	 * Rows are put back by hand only when a table is KNOWN not to
	 * be transactional — never on a failed or unknown check, where it could
	 * clobber a concurrent request's committed write. The undo paths pass
	 * $fresh, so a table converted to InnoDB since the last cached check
	 * is seen at once (they run only after a failure, so the extra query
	 * is rare).
	 *
	 * @param bool $fresh Skip the cache.
	 * @return bool
	 */
	public static function engines_bad( $fresh = false ) {
		return 'bad' === self::engines_state( $fresh );
	}

	/**
	 * The id of wpdb's current client connection. wpdb silently
	 * reconnects on «server has gone away» and runs the rest in autocommit
	 * on a NEW connection; a changed id tells the caller its transaction
	 * was lost.
	 *
	 * Read from the PHP side (mysqli::$thread_id), not with a query: it
	 * costs nothing, cannot itself trigger the reconnect, and behind a
	 * database proxy (ProxySQL etc.) it is the proxy session's id, which
	 * stays the same for the whole request. 0 when unavailable — the
	 * caller then simply skips the reconnect check.
	 *
	 * @return int
	 */
	public static function connection_id() {
		global $wpdb;

		$dbh = isset( $wpdb->dbh ) ? $wpdb->dbh : null;

		if ( $dbh instanceof mysqli ) {
			return (int) $dbh->thread_id;
		}

		return 0;
	}

	/**
	 * Admin notice when a table is not InnoDB (see engines_ok()).
	 */
	public static function render_engine_notice() {
		if ( ! class_exists( 'Plandose_Admin' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || false === strpos( (string) $screen->id, 'plandose' ) ) {
			return;
		}

		// Shown for a missing table too (engines_state() reports
		// that as 'unknown' so it never triggers a manual undo). Cached per
		// hour so the admin screens do not query information_schema on
		// every load.
		$bad = get_transient( 'plandose_engine_notice' );

		if ( ! is_array( $bad ) ) {
			$bad = array();

			foreach ( self::engines() as $table => $engine ) {
				if ( 0 !== strcasecmp( $engine, 'InnoDB' ) ) {
					$bad[] = $table . ' (' . ( '' === $engine ? __( 'λείπει', 'plandose' ) : $engine ) . ')';
				}
			}

			set_transient( 'plandose_engine_notice', $bad, HOUR_IN_SECONDS );
		}

		if ( empty( $bad ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'PlanDose: η βάση δεν υποστηρίζει συναλλαγές για τις χρεώσεις εκτυπώσεων.', 'plandose' ) . '</strong> ';
		echo esc_html(
			sprintf(
				/* translators: %s: list of database tables and their storage engine */
				__( 'Οι πίνακες %s δεν είναι InnoDB ή λείπουν. Οι εκτυπώσεις λειτουργούν, αλλά μετά από σφάλμα βάσης η χρέωση αναιρείται με το χέρι αντί για αυτόματα. Μετατρέψτε τους σε InnoDB (ALTER TABLE … ENGINE=InnoDB) αφού πάρετε backup.', 'plandose' ),
				implode( ', ', $bad )
			)
		);
		echo '</p></div>';
	}

	/**
	 * One page of this pharmacy's charge rows, oldest first by id
	 * (privacy exporter; id order keeps offset paging stable). Only when and
	 * how often — the token hash is left out.
	 *
	 * @param int $user_id User ID.
	 * @param int $limit   Max rows.
	 * @param int $offset  Rows to skip.
	 * @return object[] Rows with id, charged_ts and reprints.
	 */
	public static function rows_for_user( $user_id, $limit = 500, $offset = 0 ) {
		global $wpdb;

		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return array();
		}

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, charged_ts, reprints FROM {$table} WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d", $user_id, max( 1, (int) $limit ), max( 0, (int) $offset ) ) );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Delete this pharmacy's rows in both tables (privacy eraser).
	 *
	 * @param int $user_id User ID.
	 * @return int|false Rows deleted (0 when none), false on a database error.
	 */
	public static function delete_for_user( $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return 0;
		}

		$table    = self::table_name();
		$requests = self::requests_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE user_id = %d", $user_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
		$deleted_requests = $wpdb->query( $wpdb->prepare( "DELETE FROM {$requests} WHERE user_id = %d", $user_id ) );

		if ( false === $deleted || false === $deleted_requests ) {
			return false;
		}

		return (int) $deleted + (int) $deleted_requests;
	}

	/**
	 * Delete rows past the free-reprint window (daily cleanup), in batches.
	 *
	 * @return int Rows deleted.
	 */
	public static function cleanup_expired() {
		global $wpdb;

		$total = 0;

		/*
		 * The 1.21.1–1.23.x receipts in user meta are not read; a
		 * receipt only ever counted within the free-reprint window. Once
		 * that window has passed since the ledger took over, none can be
		 * live: delete them all, once.
		 */
		$since = (int) get_option( 'plandose_print_charges_since', 0 );

		if ( 0 === $since ) {
			update_option( 'plandose_print_charges_since', time(), false );
		} elseif ( $since < self::live_since() && ! get_option( 'plandose_legacy_receipts_purged' ) ) {
			delete_metadata( 'user', 0, Plandose_Ajax::PRINT_RECEIPT_META_KEY, '', true );
			update_option( 'plandose_legacy_receipts_purged', 1, false );
		}

		foreach ( array( self::table_name() => 'charged_ts', self::requests_table_name() => 'created_ts' ) as $table => $column ) {
			for ( $i = 0; $i < 20; $i++ ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table; $column is one of two fixed names.
				$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE {$column} < %d LIMIT 1000", self::live_since() ) );

				if ( ! $deleted ) {
					break;
				}

				$total += (int) $deleted;

				if ( (int) $deleted < 1000 ) {
					break;
				}
			}
		}

		return $total;
	}
}
