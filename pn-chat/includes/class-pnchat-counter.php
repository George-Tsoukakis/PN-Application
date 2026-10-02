<?php
/**
 * Counters that stay right under concurrent requests: rate limits, the daily
 * AI limit and the AI usage totals. One row per counter; every change is a
 * single INSERT … ON DUPLICATE KEY UPDATE, so two requests never read the
 * same value and both pass (the read → add 1 → save race of transients).
 *
 * @package PNChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Atomic counters.
 */
final class PNChat_Counter {

	/**
	 * Counters table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'pnchat_counters';
	}

	/**
	 * Adds to a counter and returns its new value. A counter with a window
	 * starts again from zero once the window has ended.
	 *
	 * @param string $key    Counter name (at most 100 characters).
	 * @param int    $delta  Amount, 1 or more.
	 * @param int    $window Seconds the count lasts; 0 = forever.
	 * @return int|null New value; null when the database refused.
	 */
	public static function add( $key, $delta = 1, $window = 0 ) {
		global $wpdb;
		$key   = substr( (string) $key, 0, 100 );
		$delta = max( 1, (int) $delta );
		$now   = time();
		$exp   = $window > 0 ? $now + (int) $window : 0;
		// LAST_INSERT_ID( expr ) hands the new value back to this connection
		// only. A new row reports one affected row; an updated row two.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i ( k, n, exp ) VALUES ( %s, %d, %d ) ON DUPLICATE KEY UPDATE'
				. ' n = LAST_INSERT_ID( IF( exp > 0 AND exp <= %d, %d, n + %d ) ),'
				. ' exp = IF( exp > 0 AND exp <= %d, %d, exp )',
				self::table(),
				$key,
				$delta,
				$exp,
				$now,
				$delta,
				$delta,
				$now,
				$exp
			)
		);
		if ( false === $ok ) {
			return null;
		}
		return 1 === (int) $wpdb->rows_affected ? $delta : (int) $wpdb->insert_id;
	}

	/**
	 * Takes one use if fewer than $limit were taken in the window.
	 *
	 * @param string $key       Counter name.
	 * @param int    $limit     Uses allowed in the window.
	 * @param int    $window    Seconds.
	 * @param bool   $fail_open Answer when the database refused.
	 * @return bool
	 */
	public static function take( $key, $limit, $window, $fail_open = true ) {
		$n = self::add( $key, 1, $window );
		if ( null === $n ) {
			return $fail_open;
		}
		if ( $n > (int) $limit ) {
			// Refused uses do not count, so "used today" stays exact.
			self::give_back( $key );
			return false;
		}
		return true;
	}

	/**
	 * Returns one use (the call it was taken for never happened).
	 *
	 * @param string $key Counter name.
	 * @return void
	 */
	public static function give_back( $key ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET n = n - 1 WHERE k = %s AND n > 0', self::table(), substr( (string) $key, 0, 100 ) ) );
	}

	/**
	 * Current value (0 when missing or when its window ended).
	 *
	 * @param string $key Counter name.
	 * @return int
	 */
	public static function get( $key ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT n, exp FROM %i WHERE k = %s', self::table(), substr( (string) $key, 0, 100 ) ), ARRAY_A );
		if ( ! is_array( $row ) || ( (int) $row['exp'] > 0 && (int) $row['exp'] <= time() ) ) {
			return 0;
		}
		return (int) $row['n'];
	}

	/**
	 * Values of every counter whose name starts with $prefix.
	 *
	 * @param string $prefix Name prefix.
	 * @return array<string,int> Name without the prefix => value.
	 */
	public static function all( $prefix ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT k, n FROM %i WHERE k LIKE %s AND exp = 0', self::table(), $wpdb->esc_like( $prefix ) . '%' ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ substr( (string) $r['k'], strlen( $prefix ) ) ] = (int) $r['n'];
		}
		return $out;
	}

	/**
	 * Deletes counters whose window ended (daily clean-up).
	 *
	 * @return void
	 */
	public static function purge() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE exp > 0 AND exp < %d', self::table(), time() - HOUR_IN_SECONDS ) );
	}
}
