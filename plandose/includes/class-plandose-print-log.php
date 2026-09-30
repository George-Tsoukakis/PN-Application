<?php
/**
 * The print log — one row per print a pharmacy made, for the admin
 * screen PlanDose → Εκτυπώσεις.
 *
 * What a row holds, and nothing more: the pharmacy (user ID), when, whether
 * the print was charged or was a free reprint («Εκτύπωση ξανά»), whether
 * the account was Pro at the time, and — for a charged print — the monthly
 * counter right after it. Nothing about the plan, the patient or the
 * medicines: those never reach the server (see readme, «Privacy»).
 *
 * Written AFTER the charge (or the free reprint) is committed, and best
 * effort: a failed write is logged to the PHP error log and never changes
 * the answer the pharmacist gets. The charge ledger
 * (Plandose_Print_Charges) stays the only source of truth for billing; its
 * rows live 30 minutes, these RETENTION_DAYS (365).
 *
 * Works with:
 * - includes/class-plandose-ajax.php            (record() after a print)
 * - includes/class-plandose-admin-prints.php    (the admin screen)
 * - includes/class-plandose-privacy.php         (export / erase)
 * - includes/class-plandose-subscriptions.php   (create_table())
 * - uninstall.php                               (DROP TABLE)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plandose_Print_Log {

	/** A print that counted against the monthly limit. */
	const KIND_CHARGE = 'charge';

	/** «Εκτύπωση ξανά» of a charged plan, within its free reprints. */
	const KIND_REPRINT = 'reprint';

	/** Days a row is kept; older rows go in the daily cleanup. */
	const RETENTION_DAYS = 365;

	/** Rows deleted per statement in the daily cleanup. */
	const CLEANUP_BATCH = 1000;

	/**
	 * @return string Table name.
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'plandose_print_log';
	}

	/**
	 * CREATE TABLE for dbDelta(). Additive only: a new table, nothing
	 * existing is altered.
	 *
	 * @param string $charset_collate From $wpdb->get_charset_collate().
	 * @return string[]
	 */
	public static function schema_sql( $charset_collate ) {
		$table = self::table_name();

		return array(
			"CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			printed_ts INT UNSIGNED NOT NULL,
			kind VARCHAR(8) NOT NULL,
			is_pro TINYINT UNSIGNED NOT NULL DEFAULT 0,
			month_count INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY printed_ts (printed_ts),
			KEY user_printed (user_id,printed_ts)
		) ENGINE=InnoDB {$charset_collate};",
		);
	}

	/**
	 * @return string[] The kinds a row may hold.
	 */
	public static function kinds() {
		return array( self::KIND_CHARGE, self::KIND_REPRINT );
	}

	/**
	 * Record one print. Best effort: never throws, never blocks the print.
	 *
	 * @param int    $user_id     Pharmacy user ID.
	 * @param string $kind        self::KIND_CHARGE or self::KIND_REPRINT.
	 * @param bool   $is_pro      Pro at the time of the print.
	 * @param int    $month_count The monthly counter after a charged print (0 for a reprint).
	 * @return bool Whether the row was written.
	 */
	public static function record( $user_id, $kind, $is_pro, $month_count = 0 ) {
		global $wpdb;

		$user_id = absint( $user_id );

		if ( ! $user_id || ! in_array( $kind, self::kinds(), true ) ) {
			return false;
		}

		$suppress = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table, one insert per print.
		$ok = $wpdb->insert(
			self::table_name(),
			array(
				'user_id'     => $user_id,
				'printed_ts'  => time(),
				'kind'        => $kind,
				'is_pro'      => $is_pro ? 1 : 0,
				'month_count' => max( 0, (int) $month_count ),
			),
			array( '%d', '%d', '%s', '%d', '%d' )
		);

		$wpdb->suppress_errors( $suppress );

		if ( false === $ok ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operators need to see a lost log row; the print itself went through.
			error_log( 'PlanDose: the print of user ' . $user_id . ' was recorded, but its print-log row could not be written: ' . $wpdb->last_error );

			return false;
		}

		return true;
	}

	/**
	 * One page of the log, newest first.
	 *
	 * @param array $args {
	 *     @type int[]  $user_ids Only these pharmacies (empty: all). A list
	 *                            that is empty on purpose (a search with
	 *                            no match) is passed as array( 0 ).
	 *     @type string $kind     '' (both), 'charge' or 'reprint'.
	 *     @type string $from     Y-m-d, site time zone, inclusive ('' = none).
	 *     @type string $to       Y-m-d, site time zone, inclusive ('' = none).
	 *     @type int    $paged    1-based page.
	 *     @type int    $per_page Rows per page (1–200).
	 * }
	 * @return array{rows:object[],total:int,error:bool}
	 */
	public static function query( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'user_ids' => array(),
				'kind'     => '',
				'from'     => '',
				'to'       => '',
				'paged'    => 1,
				'per_page' => 50,
			)
		);

		$where  = array( '1=1' );
		$params = array();

		$user_ids = array_values( array_unique( array_map( 'absint', (array) $args['user_ids'] ) ) );

		if ( $user_ids ) {
			$where[] = 'user_id IN (' . implode( ',', array_fill( 0, count( $user_ids ), '%d' ) ) . ')';
			$params  = array_merge( $params, $user_ids );
		}

		if ( in_array( $args['kind'], self::kinds(), true ) ) {
			$where[]  = 'kind = %s';
			$params[] = $args['kind'];
		}

		$from = self::day_start_ts( (string) $args['from'] );

		if ( null !== $from ) {
			$where[]  = 'printed_ts >= %d';
			$params[] = $from;
		}

		$to = self::next_day_start_ts( (string) $args['to'] );

		if ( null !== $to ) {
			// The next midnight, not +24 h: a day is 23 or 25 hours long
			// when the clocks change.
			$where[]  = 'printed_ts < %d';
			$params[] = $to;
		}

		$per_page = max( 1, min( 200, (int) $args['per_page'] ) );
		$offset   = ( max( 1, min( 1000000, (int) $args['paged'] ) ) - 1 ) * $per_page;
		$table    = self::table_name();
		$sql      = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared -- Custom plugin table; $sql is built only from fixed fragments with placeholders, every value goes through prepare().
		$total = $wpdb->get_var( $params ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$sql}", $params ) : "SELECT COUNT(*) FROM {$table} WHERE {$sql}" );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, printed_ts, kind, is_pro, month_count FROM {$table} WHERE {$sql} ORDER BY printed_ts DESC, id DESC LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, $offset ) )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared

		if ( null === $total || ! is_array( $rows ) ) {
			return array( 'rows' => array(), 'total' => 0, 'error' => true );
		}

		return array( 'rows' => $rows, 'total' => (int) $total, 'error' => false );
	}

	/**
	 * Prints since a moment, by kind.
	 *
	 * @param int $since Unix time.
	 * @return array{charge:int,reprint:int}|null Null on a database error.
	 */
	public static function counts_since( $since ) {
		$counts = self::counts_since_each( array( (int) $since ) );

		return null === $counts ? null : $counts[0];
	}

	/**
	 * Prints since each of several moments, by kind — the KPI tiles of
	 * the screen — in ONE query (conditional sums over the rows since the
	 * earliest moment) instead of one per tile.
	 *
	 * @param int[] $moments Unix times.
	 * @return array<int,array{charge:int,reprint:int}>|null Keyed like
	 *         $moments; null on a database error.
	 */
	public static function counts_since_each( $moments ) {
		global $wpdb;

		$moments = array_map( 'intval', array_values( (array) $moments ) );

		if ( ! $moments ) {
			return array();
		}

		$table   = self::table_name();
		$columns = array();
		$args    = array();

		foreach ( $moments as $i => $since ) {
			$columns[] = "COALESCE(SUM(kind = %s AND printed_ts >= %d), 0) AS c{$i}, COALESCE(SUM(kind = %s AND printed_ts >= %d), 0) AS r{$i}";
			array_push( $args, self::KIND_CHARGE, $since, self::KIND_REPRINT, $since );
		}

		$args[] = min( $moments );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Custom plugin table; the column list holds only placeholders and fixed aliases.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . implode( ', ', $columns ) . " FROM {$table} WHERE printed_ts >= %d", $args ), ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $row ) || '' !== (string) $wpdb->last_error ) {
			return null;
		}

		$out = array();

		foreach ( $moments as $i => $since ) {
			$out[ $i ] = array(
				self::KIND_CHARGE  => isset( $row[ 'c' . $i ] ) ? (int) $row[ 'c' . $i ] : 0,
				self::KIND_REPRINT => isset( $row[ 'r' . $i ] ) ? (int) $row[ 'r' . $i ] : 0,
			);
		}

		return $out;
	}

	/**
	 * Unix time of 00:00 (site time zone) of a Y-m-d date, or null.
	 *
	 * @param string $ymd Date.
	 * @return int|null
	 */
	public static function day_start_ts( $ymd ) {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}\z/', $ymd ) ) {
			return null;
		}

		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, wp_timezone() );

		if ( ! $date || $date->format( 'Y-m-d' ) !== $ymd ) {
			return null;
		}

		return $date->getTimestamp();
	}

	/**
	 * Unix time of 00:00 (site time zone) of the day AFTER a Y-m-d date.
	 *
	 * @param string $ymd Date.
	 * @return int|null
	 */
	public static function next_day_start_ts( $ymd ) {
		if ( null === self::day_start_ts( $ymd ) ) {
			return null;
		}

		return DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, wp_timezone() )->modify( '+1 day' )->getTimestamp();
	}

	/**
	 * One page of this pharmacy's rows, oldest first by id (privacy
	 * exporter). Ordered by id so that offset paging is stable: a print
	 * logged while an export runs lands after every page already handed out.
	 *
	 * @param int $user_id User ID.
	 * @param int $limit   Maximum rows.
	 * @param int $offset  Rows to skip.
	 * @return object[] Rows with id, printed_ts, kind, is_pro, month_count.
	 */
	public static function rows_for_user( $user_id, $limit = 5000, $offset = 0 ) {
		global $wpdb;

		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return array();
		}

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, printed_ts, kind, is_pro, month_count FROM {$table} WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d", $user_id, max( 1, (int) $limit ), max( 0, (int) $offset ) ) );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Delete this pharmacy's rows (privacy eraser).
	 *
	 * @param int $user_id User ID.
	 * @return int|false Rows deleted, false on a database error.
	 */
	public static function delete_for_user( $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return 0;
		}

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE user_id = %d", $user_id ) );

		return false === $deleted ? false : (int) $deleted;
	}

	/**
	 * Daily cleanup: rows older than RETENTION_DAYS, in batches.
	 *
	 * @return int Rows deleted.
	 */
	public static function cleanup_expired() {
		global $wpdb;

		$table   = self::table_name();
		$cutoff  = time() - self::RETENTION_DAYS * DAY_IN_SECONDS;
		$deleted = 0;

		for ( $i = 0; $i < 50; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
			$n = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE printed_ts < %d LIMIT %d", $cutoff, self::CLEANUP_BATCH ) );

			if ( ! $n ) {
				break;
			}

			$deleted += (int) $n;

			if ( $n < self::CLEANUP_BATCH ) {
				break;
			}
		}

		return $deleted;
	}
}
