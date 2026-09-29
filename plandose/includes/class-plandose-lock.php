<?php
/**
 * Atomic claim/release primitives on wp_options rows.
 *
 * WHY THIS EXISTS
 *
 * Two places in PlanDose need a mutual-exclusion lock that actually holds
 * across concurrent PHP requests: the duplicate-print debounce in
 * Plandose_Ajax (where losing it charges a pharmacist twice for one print)
 * and the legacy-invoice migration in Plandose_Invoice_Migration (where
 * losing it copies every attachment twice and orphans one set on disk).
 *
 * add_option() looks as if it could provide that lock: the UNIQUE KEY
 * WordPress has on wp_options.option_name would seem to let only one of
 * two concurrent add_option() calls for the same option name succeed,
 * with MySQL itself as the arbiter.
 *
 * That is not what add_option() does. Its actual body is:
 *
 *     $notoptions = wp_cache_get( 'notoptions', 'options' );
 *     if ( ! is_array( $notoptions ) || ! isset( $notoptions[ $option ] ) ) {
 *         if ( false !== get_option( $option ) ) { return false; }
 *     }
 *     ...
 *     $result = $wpdb->query( "INSERT INTO ... ON DUPLICATE KEY UPDATE ..." );
 *
 * The ON DUPLICATE KEY UPDATE means the unique key never rejects anything —
 * a colliding INSERT silently becomes an UPDATE and reports success. The
 * only thing separating two callers is the get_option() read above it,
 * which is precisely a read-then-write race. Two requests that both pass
 * that read both get true back, and
 * both proceed as though they hold the lock.
 *
 * The primitives below use INSERT IGNORE and a compare-and-swap UPDATE, and
 * decide on the row count MySQL reports. There is no read to race on: the
 * statement either changed a row or it did not, and only one concurrent
 * caller can be the one that did.
 *
 * @package PlanDose
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plandose_Lock {

	/**
	 * wp_options.option_name is VARCHAR(191). A longer name would be
	 * silently truncated by INSERT IGNORE (which suppresses the truncation
	 * error along with the duplicate-key one), and two different long names
	 * could then collide into the same row. Rejected outright instead.
	 */
	const MAX_OPTION_NAME_LENGTH = 191;

	/**
	 * Insert an option row if and only if no row with that name exists yet.
	 *
	 * This is the whole lock: winning the insert IS holding the lock.
	 *
	 * INSERT IGNORE reports 1 affected row when it inserted and 0 when the
	 * UNIQUE key on option_name rejected the row, so of any number of
	 * genuinely concurrent callers exactly one can see 1. IGNORE also
	 * downgrades other errors to warnings and returns 0, and $wpdb->query()
	 * returns false outright on failure — both of which cast to 0 here and
	 * are therefore treated as "somebody else has it". Failing toward "not
	 * acquired" is the safe direction for a mutex: the caller does less
	 * work than it might have, rather than doing work twice.
	 *
	 * @param string $option_name Option row to claim.
	 * @param mixed  $value       Value to store (serialized like core does).
	 * @return bool True only when THIS call created the row.
	 */
	public static function claim( $option_name, $value ) {
		global $wpdb;

		$option_name = (string) $option_name;

		if ( '' === $option_name || strlen( $option_name ) > self::MAX_OPTION_NAME_LENGTH ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The point of this method is an uncached, single-statement atomic insert; add_option() cannot express it (see the file docblock).
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, %s )",
				$option_name,
				maybe_serialize( $value ),
				self::autoload_off()
			)
		);

		if ( 1 !== (int) $inserted ) {
			return false;
		}

		self::forget_cached( $option_name );

		return true;
	}

	/**
	 * Overwrite an option row only while it still holds the exact value the
	 * caller previously read from it.
	 *
	 * Used to reclaim a lock whose holder died mid-run. A plain
	 * delete-then-claim would let two requests both observe the same stale
	 * lock, both delete it, and both claim it. Here the UPDATE matches on
	 * the old value, so the first reclaimer changes the row out from under
	 * every other one: MySQL's row lock does the arbitrating, and the losers
	 * match nothing and get 0 back.
	 *
	 * Note the deliberate requirement that $expected and $value differ. If
	 * they were equal, MySQL would match the row but change nothing and
	 * report 0 affected rows, which this method would read as "somebody
	 * beat me to it" — a false negative. Callers reclaiming a timestamp
	 * lock satisfy this naturally (a lock is only reclaimable once it is
	 * older than its TTL, so "now" can never equal it), but the guard makes
	 * that a property of the primitive rather than of each caller.
	 *
	 * @param string $option_name Option row to reclaim.
	 * @param mixed  $expected    Value the row must still hold.
	 * @param mixed  $value       Value to write.
	 * @return bool True only when THIS call changed the row.
	 */
	public static function claim_if_unchanged( $option_name, $expected, $value ) {
		global $wpdb;

		$option_name = (string) $option_name;

		if ( '' === $option_name || strlen( $option_name ) > self::MAX_OPTION_NAME_LENGTH ) {
			return false;
		}

		$serialized_expected = maybe_serialize( $expected );
		$serialized_value    = maybe_serialize( $value );

		if ( $serialized_expected === $serialized_value ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-swap on a single row; a cached read cannot express "change it only if unchanged".
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$serialized_value,
				$option_name,
				$serialized_expected
			)
		);

		if ( 1 !== (int) $updated ) {
			return false;
		}

		self::forget_cached( $option_name );

		return true;
	}

	/**
	 * Release a lock. Plain delete_option(), which already handles every
	 * cache WordPress keeps for the row — there is nothing atomic to get
	 * right on the way out, only on the way in.
	 *
	 * @param string $option_name Option row to release.
	 * @return bool
	 */
	public static function release( $option_name ) {
		return delete_option( (string) $option_name );
	}

	/**
	 * Release a lock only while it still holds the value THIS
	 * holder wrote.
	 *
	 * release() deletes whatever is there. If a holder outlived the TTL,
	 * another request may have reclaimed the row (claim_if_unchanged()
	 * always writes a different value), and the first holder's release()
	 * would then delete the NEW holder's lock and let a third request in.
	 * Deleting on the exact value makes the release a no-op in that case.
	 *
	 * @param string $option_name Option row to release.
	 * @param mixed  $value       Value this holder claimed it with.
	 * @return bool True when this call deleted the row.
	 */
	public static function release_if_owned( $option_name, $value ) {
		global $wpdb;

		$option_name = (string) $option_name;

		if ( '' === $option_name || strlen( $option_name ) > self::MAX_OPTION_NAME_LENGTH ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-delete on a single row; delete_option() cannot express "only if it still holds my value".
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$option_name,
				maybe_serialize( $value )
			)
		);

		self::forget_cached( $option_name );

		return 1 === (int) $deleted;
	}

	/**
	 * Drop whatever WordPress has cached about an option we wrote behind
	 * its back.
	 *
	 * Two entries matter. The row's own cache key is stale because our
	 * INSERT/UPDATE never went through update_option(). The 'notoptions'
	 * list is worse: a get_option() miss BEFORE we inserted records the
	 * name there as known-absent, and on a site with a persistent object
	 * cache that entry is shared across requests — so without this, another
	 * request can go on believing the lock does not exist while we hold it.
	 *
	 * Locks are written non-autoloaded, so the 'alloptions' bucket never
	 * contains them and needs no attention here.
	 *
	 * @param string $option_name Option row that was written directly.
	 */
	private static function forget_cached( $option_name ) {
		wp_cache_delete( $option_name, 'options' );

		$notoptions = wp_cache_get( 'notoptions', 'options' );

		if ( is_array( $notoptions ) && isset( $notoptions[ $option_name ] ) ) {
			unset( $notoptions[ $option_name ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
	}

	/**
	 * The literal this WordPress version writes into options.autoload for a
	 * non-autoloaded option.
	 *
	 * It changed in 6.6.0: 'no' became 'off', with 'yes'/'on'/'auto-on'/
	 * 'auto' being the values that actually autoload. Both old and new
	 * spellings are excluded from autoloading, so either would work — but
	 * matching what core writes keeps our rows indistinguishable from
	 * core's own, which matters because these are stored under the standard
	 * '_transient_' names and core's transient code reads and expires them.
	 *
	 * @return string
	 */
	private static function autoload_off() {
		if ( function_exists( 'wp_determine_option_autoload_value' ) ) {
			return (string) wp_determine_option_autoload_value( '', '', '', false );
		}

		return 'no';
	}
}
