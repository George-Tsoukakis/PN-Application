<?php
/**
 * Duplicate-print lock: one short-lived lock per pharmacy and print token,
 * taken before a new charge so that of two concurrent requests for the
 * same token only one can charge it.
 *
 * Works with:
 * - includes/class-plandose-print-new-charge.php (the only caller)
 * - includes/class-plandose-lock.php (the INSERT IGNORE primitive)
 * - includes/class-plandose-subscriptions.php (cleanup_stale_print_locks())
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plandose_Print_Lock {

	/**
	 * Cache group used by the atomic path (see acquire_atomic() below).
	 */
	const CACHE_GROUP = 'plandose_print_lock';

	/**
	 * The value this request wrote into each print lock it holds
	 * (non-persistent-cache sites), so release() can delete its own lock
	 * and never someone else's.
	 *
	 * @var array<string,string>
	 */
	private static $owned = array();

	/**
	 * Lock key for duplicate print protection.
	 *
	 * Scoped by both user and the client-generated per-print-action
	 * token (see `token` in assets/js/print.js doPrintSafely()), not just by
	 * user_id. Keyed on user_id alone, the lock would block ANY print
	 * registration from that pharmacist for the
	 * next PRINT_DEBOUNCE_SECONDS — including a genuinely different plan
	 * for a different patient, not just an accidental resend of the same
	 * one. A pharmacist printing several plans back-to-back (especially
	 * as the popup resets immediately after each print, see
	 * assets/js/print.js resetForNextPatient()) would have some of those prints
	 * silently dropped as "duplicates" and never counted, and the monthly
	 * counter would lag well behind the pharmacy's actual
	 * print volume. Tokens are unique per print action, so this only
	 * catches true resubmits of the same action (double click, afterprint
	 * + fallback timeout both firing, retried request, etc.).
	 *
	 * Not month-scoped (contrast with the pre-1.7.2 plain-option
	 * format): the lock carries its own short TTL (PRINT_DEBOUNCE_SECONDS)
	 * one way or another (cache key TTL, or the transient timeout option —
	 * see acquire() below), so the row disappears on its own a
	 * few seconds after being set, with nothing left for a monthly sweep
	 * to do for new locks. See Plandose_Subscriptions::cleanup_stale_print_locks()
	 * for the cleanup of any lingering pre-1.7.2 rows on upgraded sites.
	 *
	 * @param int    $user_id Pharmacy user ID.
	 * @param string $token   Lock token.
	 * @return string
	 */
	public static function key( $user_id, $token ) {
		return 'plandose_print_lock_' . absint( $user_id ) . '_' . md5( (string) $token );
	}

	/**
	 * Atomically test-and-set the duplicate-print lock for a user/token in
	 * a single step. Returns true if the lock was newly acquired by THIS
	 * call (no existing duplicate — the caller now exclusively holds the
	 * lock and should proceed), or false if another request already holds
	 * it (this is a duplicate).
	 *
	 * One atomic step rather than two separate operations — a read
	 * (print_lock_exists()) followed later by a write (set_print_lock())
	 * once a print was confirmed as recorded. With two steps, two genuinely
	 * concurrent requests for the same token could both pass the read
	 * before either had written the lock, both proceed to increment the
	 * print count, and both eventually set the lock — the same print
	 * charged twice. A single atomic acquire closes that window: whichever
	 * request wins the atomic insert is the only one that proceeds; the
	 * other gets a duplicate response immediately, before ever touching
	 * the DB increment.
	 *
	 * Any caller that acquires the lock but then fails to actually record
	 * the print (a DB error, or the limit was reached at the DB level)
	 * MUST call release() so a legitimate client retry using
	 * the same token isn't wrongly treated as a duplicate — see
	 * Plandose_Print_New_Charge.
	 *
	 * Same dispatch pattern as Plandose_Ajax::is_rate_limited(): a genuinely
	 * atomic op on a real persistent object cache, and otherwise a
	 * single-statement INSERT IGNORE decided on MySQL's affected-row count
	 * (see Plandose_Lock, and the note on acquire_non_atomic() about
	 * why add_option() is not the atomic operation it may look like).
	 *
	 * @param int    $user_id      Pharmacy user ID.
	 * @param string $token        Lock token.
	 * @param string $request_hash md5 of the request id, or ''.
	 * @return bool
	 */
	public static function acquire( $user_id, $token, $request_hash = '' ) {
		$token = is_string( $token ) && '' !== $token ? $token : 'legacy';

		// The lock stores "<time>:<request hash>", so a request
		// that finds it held can tell whether the holder is a copy of
		// itself (see holder()).
		$value = time() . ':' . (string) $request_hash;

		if ( wp_using_ext_object_cache() ) {
			return self::acquire_atomic( $user_id, $token, $value );
		}

		return self::acquire_non_atomic( $user_id, $token, $value );
	}

	/**
	 * The request hash stored in a held print lock; '' when the
	 * holder sent no request id (or wrote a pre-1.27.0 value), null when the
	 * lock is not held (any more).
	 *
	 * @param int    $user_id Pharmacy user ID.
	 * @param string $token   Lock token (as passed to acquire()).
	 * @return string|null
	 */
	public static function holder( $user_id, $token ) {
		$token = is_string( $token ) && '' !== $token ? $token : 'legacy';
		$key   = self::key( $user_id, $token );

		if ( wp_using_ext_object_cache() ) {
			$value = wp_cache_get( $key, self::CACHE_GROUP );
		} else {
			wp_cache_delete( '_transient_' . $key, 'options' );
			$value = get_option( '_transient_' . $key );
		}

		if ( false === $value || null === $value ) {
			return null;
		}

		$parts = explode( ':', (string) $value, 2 );

		return isset( $parts[1] ) ? $parts[1] : '';
	}

	/**
	 * Atomic acquire for sites with a real persistent object cache
	 * (Redis, Memcached, etc.). wp_cache_add() only writes if the key is
	 * currently absent and reports whether it did — a genuine atomic
	 * "insert only if absent" on these backends, so of two concurrent
	 * calls for the same key only one can ever return true. The TTL lives
	 * on the cache key itself, so the lock clears on its own with no
	 * separate cleanup needed.
	 *
	 * A false return here is treated as "lock already held" (fail toward
	 * treating the request as a duplicate rather than risking a double
	 * charge) — the same conservative choice made for a failed
	 * wp_cache_incr() in Plandose_Ajax::is_rate_limited_atomic(), just
	 * without a non-atomic fallback available mid-request for this
	 * particular op.
	 */
	private static function acquire_atomic( $user_id, $token, $value ) {
		$key = self::key( $user_id, $token );

		return (bool) wp_cache_add( $key, $value, self::CACHE_GROUP, Plandose_Ajax::PRINT_DEBOUNCE_SECONDS );
	}

	/**
	 * Atomic acquire for sites without a persistent object cache.
	 *
	 * Deliberately bypasses get_transient()/set_transient(): both of those
	 * are a read followed by a separate write, which would reintroduce the
	 * exact race this method exists to close. It writes the transient's own
	 * storage-layer option row directly instead — using the standard
	 * `_transient_`/`_transient_timeout_` names so the row stays fully
	 * interoperable with WordPress's own transient reads and garbage
	 * collection, and stays outside the LIKE 'plandose_print_lock_%'
	 * pattern that Plandose_Subscriptions::cleanup_stale_print_locks()
	 * sweeps (see the note on that method).
	 *
	 * It does NOT use add_option() to do that, although the UNIQUE key on
	 * wp_options.option_name may seem to make the insert atomic. It does
	 * not: core's add_option() is a
	 * get_option() check followed by an INSERT ... ON DUPLICATE KEY UPDATE,
	 * so the unique key never rejects anything and two concurrent callers
	 * could both be told they had won — and both then charge the same
	 * pharmacist for the same print. Plandose_Lock::claim() decides on the
	 * row count of an INSERT IGNORE instead, which genuinely admits one
	 * winner; the file docblock on that class has the full account.
	 *
	 * Reclaiming an already-expired lock is atomic too, via a
	 * compare-and-swap on the timestamp the caller just read. That case
	 * only matters after the debounce window has fully elapsed, but it
	 * costs one extra statement to close, and "rare" is not the same as
	 * "cannot happen" when the consequence is a double charge.
	 */
	private static function acquire_non_atomic( $user_id, $token, $value ) {
		$key          = self::key( $user_id, $token );
		$option_name  = '_transient_' . $key;
		$timeout_name = '_transient_timeout_' . $key;
		$now          = time();
		$expiration   = $now + Plandose_Ajax::PRINT_DEBOUNCE_SECONDS;

		if ( Plandose_Lock::claim( $option_name, $value ) ) {
			self::$owned[ $key ] = $value;
			// update_option() creates the timeout when absent and overwrites any
			// stale value left behind by an interrupted request, so the fresh
			// lock never inherits a past expiry that WordPress's transient GC
			// could act on. We do NOT roll back / delete the base lock on a
			// timeout-write hiccup: this request already won the claim, and
			// returning false here would make register_print() drop a
			// legitimate print as a "duplicate". The expiry check below reads
			// $option_name directly, so it stays correct even if this timeout
			// write ever failed.
			update_option( $timeout_name, $expiration, false );
			return true;
		}

		// "<time>:<request hash>" or a bare time (older locks);
		// (int) reads the leading time of either.
		$existing_raw  = get_option( $option_name );
		$existing_time = is_scalar( $existing_raw ) ? absint( (int) $existing_raw ) : 0;

		if ( $existing_time > 0 && ( $now - $existing_time ) < Plandose_Ajax::PRINT_DEBOUNCE_SECONDS ) {
			return false;
		}

		/*
		 * Past the debounce window, or the row holds something unreadable.
		 * Take it over only if it still holds exactly the value just read,
		 * so of several requests racing to reclaim the same dead lock only
		 * one wins. A row holding something absint() could not parse will
		 * not match and the reclaim simply declines — better than guessing
		 * at a lock whose real state is unknown. The next attempt a few
		 * seconds later starts fresh from claim() once WordPress's own
		 * transient garbage collection has removed the row.
		 */
		if ( $existing_time < 1 || ! Plandose_Lock::claim_if_unchanged( $option_name, (string) $existing_raw, $value ) ) {
			return false;
		}

		self::$owned[ $key ] = $value;
		update_option( $timeout_name, $expiration, false );

		return true;
	}

	/**
	 * Release a previously acquired print lock. Only call this when a
	 * lock was acquired via acquire() but the print it was
	 * guarding did NOT end up recorded (a DB error, or the limit was hit
	 * at the DB level) — see Plandose_Print_New_Charge. This lets a legitimate
	 * client retry using the same token proceed immediately instead of
	 * being wrongly treated as a duplicate of a print that never actually
	 * happened.
	 *
	 * @param int    $user_id Pharmacy user ID.
	 * @param string $token   Lock token (as passed to acquire()).
	 */
	public static function release( $user_id, $token ) {
		$token = is_string( $token ) && '' !== $token ? $token : 'legacy';
		$key   = self::key( $user_id, $token );

		if ( wp_using_ext_object_cache() ) {
			wp_cache_delete( $key, self::CACHE_GROUP );
			return;
		}

		/*
		 * Release only the lock THIS request wrote. Deleting the row
		 * whatever it held would let a request slower than the debounce
		 * window, whose lock another request had meanwhile reclaimed, delete
		 * that request's lock and let a third one in beside it. (The charge
		 * itself would stay single — UNIQUE key and CAS — but the lock is
		 * there to keep it simple.) A lock this request never took is left
		 * to expire.
		 */
		if ( isset( self::$owned[ $key ] ) ) {
			$owned = self::$owned[ $key ];
			unset( self::$owned[ $key ] );

			if ( Plandose_Lock::release_if_owned( '_transient_' . $key, $owned ) ) {
				Plandose_Lock::release( '_transient_timeout_' . $key );
			}
		}
	}
}
