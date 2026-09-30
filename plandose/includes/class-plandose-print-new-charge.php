<?php
/**
 * A new print charge: under the per-token print lock, the charge row, the
 * request record and the monthly counter are written in ONE transaction
 * (Plandose_Print_Transaction), and the answer is «recorded» only once
 * the commit is confirmed.
 *
 * Every path that took the lock and did not charge releases it BEFORE
 * building its answer, so a legitimate retry with the same token is never
 * mistaken for a duplicate of a print that did not happen. A confirmed
 * charge keeps the lock until it expires (the debounce).
 *
 * Works with:
 * - includes/class-plandose-print-lock.php, class-plandose-print-transaction.php
 * - includes/class-plandose-print-replay.php (answers for a request id
 *   found recorded meanwhile)
 * - includes/class-plandose-print-charges.php (the ledger),
 *   class-plandose-subscriptions.php (the counter)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plandose_Print_New_Charge {

	/**
	 * Charge the request as a new print. Called for what
	 * Plandose_Print_Replay::answer() did not answer.
	 *
	 * @param Plandose_Print_Request $request The request.
	 * @return Plandose_Print_Result
	 */
	public static function charge( Plandose_Print_Request $request ) {
		$user_id = $request->user_id;

		// Acquire the lock BEFORE attempting the increment, atomically: of
		// two genuinely concurrent requests for the same token, only one
		// can win this call and proceed past it. A token re-charged past
		// its free reprints is locked under a key of its own (the token's
		// original lock belongs to the first print).
		$lock_token = $request->charge ? $request->token . '|recharge|' . (int) $request->charge->charged_ts : $request->token;

		if ( ! Plandose_Print_Lock::acquire( $user_id, $lock_token, $request->request_hash ) ) {
			return self::answer_lock_held( $request, $lock_token );
		}

		// Read once more under the lock: act on the rows as they are now.
		$prior    = Plandose_Print_Charges::find_request( $user_id, $request->request_hash );
		$previous = Plandose_Print_Charges::find( $user_id, $request->receipt_key );

		if ( false === $prior || false === $previous ) {
			Plandose_Print_Lock::release( $user_id, $lock_token );

			return self::server_error( $request, $request->row );
		}

		if ( $prior ) {
			// This very request was handled by a concurrent copy of it.
			Plandose_Print_Lock::release( $user_id, $lock_token );

			return Plandose_Print_Replay::answer_known( $request, $prior );
		}

		if ( $previous && Plandose_Print_Charges::is_live( $previous ) && (int) $previous->reprints < Plandose_Ajax::MAX_FREE_REPRINTS ) {
			// Charged by a concurrent request meanwhile: not a new print.
			// «Try again», not duplicate_ignored — that is a success
			// and would print without charging or using a free reprint for
			// this request. The retry uses a free reprint.
			Plandose_Print_Lock::release( $user_id, $lock_token );

			return self::server_error( $request, $request->row );
		}

		return self::charge_locked( $request, $lock_token, $previous );
	}

	/**
	 * The lock is held by another request for the same token.
	 *
	 * @param Plandose_Print_Request $request    The request.
	 * @param string                 $lock_token Lock token.
	 * @return Plandose_Print_Result
	 */
	private static function answer_lock_held( Plandose_Print_Request $request, $lock_token ) {
		$user_id = $request->user_id;

		// Never '' since 1.30.1 (Plandose_Print_Request::read_post()
		// refuses a request without an id). Kept fail-closed: without an
		// id this request cannot be told apart from another one, so it is
		// answered «try again», never «already recorded, print».
		if ( '' === $request->request_hash ) {
			return self::server_error( $request, $request->row );
		}

		/*
		 * Wait only for a copy of THIS request. The lock stores its
		 * holder's request hash; any other holder (a different press for
		 * the same token, e.g. a second tab) is answered «try again» at
		 * once instead of tying up this PHP worker for 3 s. The retry then
		 * finds the token charged and uses a free reprint, or charges it if
		 * the other request failed.
		 */
		if ( Plandose_Print_Lock::holder( $user_id, $lock_token ) !== $request->request_hash ) {
			return self::server_error( $request, $request->row );
		}

		// A copy of THIS request holds the lock — wait (up to ~3 s)
		// for its outcome and answer with it, rather than «duplicate»
		// before anything is known.
		for ( $wait = 0; $wait < 15; $wait++ ) {
			usleep( 200000 );
			$prior = Plandose_Print_Charges::find_request( $user_id, $request->request_hash );

			if ( $prior ) {
				return Plandose_Print_Replay::answer_known( $request, $prior );
			}
		}

		/*
		 * No record of this request after the wait. Nothing shows that
		 * the request holding the lock has charged anything: it could still
		 * be running, or fail after the answer went out, so answering
		 * duplicate_ignored — a success — would let the sheet print
		 * uncharged. Success only when the token's
		 * charge row shows a charge made since this request started;
		 * otherwise «try again» (the same token is sent again, and a charge
		 * that did land by then is answered as a free reprint, never
		 * charged twice).
		 */
		if ( self::charged_since( $request, $request->charge, $request->started ) ) {
			return self::duplicate_ignored( $request );
		}

		return self::server_error( $request, Plandose_Subscriptions::get_row( $user_id ) );
	}

	/**
	 * The charge itself, with the lock held.
	 *
	 * The charge row, the request record and the counter are
	 * written in ONE transaction — all or nothing. A separate, unchecked
	 * receipt write after the counter would, on a failure in between,
	 * leave a charged print with no receipt and charge its free reprint
	 * again. A refused START TRANSACTION stops
	 * here with nothing written. On a table engine without transactions
	 * (see Plandose_Print_Charges::engines_ok(), shown to admins)
	 * undo_charge() puts things back by hand.
	 *
	 * @param Plandose_Print_Request $request    The request.
	 * @param string                 $lock_token Lock token (held).
	 * @param object|null            $previous   The token's charge row before this charge.
	 * @return Plandose_Print_Result
	 */
	private static function charge_locked( Plandose_Print_Request $request, $lock_token, $previous ) {
		$user_id        = $request->user_id;
		$receipt_key    = $request->receipt_key;
		$request_hash   = $request->request_hash;
		$limit          = $request->limit;
		$charge_started = time();

		$tx = Plandose_Print_Transaction::begin();

		if ( ! $tx ) {
			Plandose_Print_Lock::release( $user_id, $lock_token );

			return self::server_error( $request, $request->row );
		}

		if ( ! Plandose_Print_Charges::write_charge( $user_id, $receipt_key, $previous ) ) {
			$tx->rollback();

			if ( $tx->lost() ) {
				Plandose_Print_Charges::undo_charge( $user_id, $receipt_key, $previous );
			}
			Plandose_Print_Lock::release( $user_id, $lock_token );

			// Answer «already handled» only when the row shows that another
			// request really charged this token just now; otherwise (a
			// database error, or the row vanished in the daily cleanup)
			// nothing was charged and the client must retry. The client
			// takes duplicate_ignored as «recorded» and prints.
			if ( self::charged_since( $request, $previous, $charge_started ) ) {
				return self::duplicate_ignored( $request );
			}

			return self::server_error( $request, $request->row );
		}

		if ( '' !== $request_hash ) {
			$recorded = Plandose_Print_Charges::record_request( $user_id, $request_hash, $receipt_key, Plandose_Print_Charges::KIND_CHARGE );

			if ( 'ok' !== $recorded ) {
				$tx->rollback();
				if ( Plandose_Print_Charges::engines_bad( true ) || $tx->lost() ) {
					Plandose_Print_Charges::undo_charge( $user_id, $receipt_key, $previous, 'duplicate' === $recorded ? '' : $request_hash );
				}
				Plandose_Print_Lock::release( $user_id, $lock_token );

				if ( 'duplicate' === $recorded ) {
					return Plandose_Print_Replay::answer_recorded( $request );
				}

				return self::server_error( $request, $request->row );
			}
		}

		if ( 0 === (int) $limit ) {
			// Unlimited plan: nothing to cap against, but the print still
			// needs to be counted — this figure feeds both this
			// pharmacy's own row and the site-wide "Εκτυπώσεις Μήνα" KPI
			// on the admin dashboard (see
			// increment_print_count_unconditionally() for why
			// try_increment_print_count() isn't reused here).
			$incremented = Plandose_Subscriptions::increment_print_count_unconditionally( $user_id, $request->today );
		} else {
			$incremented = Plandose_Subscriptions::try_increment_print_count( $user_id, $limit, $request->today );
		}

		if ( ! $incremented ) {
			$tx->rollback();
			// Only without transactions, or when the transaction was lost
			// to a reconnect (the rest then ran in autocommit): after a real
			// ROLLBACK there is nothing to undo, and rewriting rows by hand
			// could clobber a concurrent request's write.
			if ( Plandose_Print_Charges::engines_bad( true ) || $tx->lost() ) {
				Plandose_Print_Charges::undo_charge( $user_id, $receipt_key, $previous, $request_hash );
			}
			Plandose_Print_Lock::release( $user_id, $lock_token );

			$row = Plandose_Subscriptions::get_row( $user_id );

			// try_increment_print_count() returns false both when the
			// limit was genuinely reached and when the UPDATE itself
			// failed (e.g. a transient DB error). Re-checking the fresh
			// row tells the two apart. This month's count only — a
			// counter still holding last month (its reset failed) is a
			// server problem to retry, never "limit reached".
			if ( 0 !== (int) $limit && $row && Plandose_Subscriptions::current_month_count( $row ) >= (int) $limit ) {
				return Plandose_Print_Result::limit_reached( $row, $request->is_pro, $limit );
			}

			return self::server_error( $request, $row );
		}

		/*
		 * Was the transaction lost to a silent wpdb reconnect (see
		 * Plandose_Print_Transaction)? Checked right after the counter
		 * UPDATE, with no statement in between: connection_id() reads the
		 * id on the PHP side, so the check itself cannot reconnect.
		 *
		 * - Not lost: the charge row, the request row and the counter were
		 *   all written in this transaction. Nothing else runs before
		 *   COMMIT, so a connection lost from here on can only be lost AT
		 *   the COMMIT, where commit_confirmed() tells it from the rows.
		 *   (Before 1.30.2 the look-up of the rows ran here too: a
		 *   connection lost during it rolled the counter back with the old
		 *   transaction, the rows were written again in autocommit and the
		 *   sheet printed uncounted.)
		 * - Lost: the reconnect happened at or before the counter UPDATE,
		 *   so that UPDATE ran on the NEW connection, in autocommit — it
		 *   stuck, exactly once. The rows written before the reconnect may
		 *   be gone with the old transaction: put them back (verified,
		 *   see ensure_charge_records()) and answer success. The counter is
		 *   never incremented again here, so this is never a double charge.
		 */
		if ( $tx->lost() ) {
			self::ensure_charge_records( $request, $charge_started );
		} elseif ( ! self::commit_confirmed( $tx, $request, $previous, $charge_started ) ) {
			/*
			 * A connection lost AT the COMMIT. wpdb reconnects on «gone
			 * away» and re-runs COMMIT on the new connection, where it
			 * succeeds (there is nothing to commit) — while the server
			 * rolled the killed transaction back: charge row, request row
			 * and counter. So a COMMIT that is not confirmed is verified
			 * against the rows themselves; here they are missing: nothing
			 * was charged. The lock goes, so the client's retry (same
			 * request id) charges exactly once.
			 */
			Plandose_Print_Lock::release( $user_id, $lock_token );

			return self::server_error( $request, Plandose_Subscriptions::get_row( $user_id ) );
		}

		$row = Plandose_Subscriptions::get_row( $user_id );

		// The admin print log — after the confirmed commit, best
		// effort: a lost log row never changes this answer.
		$request->log_print( 'charge', $row ? Plandose_Subscriptions::current_month_count( $row ) : 0 );

		// A print does not drop the (expensive) list caches, but it
		// does drop the one-row KPI set, so «Εκτυπώσεις Μήνα» counts
		// this print at once — otherwise it lags by up to the stats TTL
		// (300 s), which reads as a print not being counted.
		if ( class_exists( 'Plandose_Subscriber_Query' ) ) {
			delete_transient( Plandose_Subscriber_Query::STATS_CACHE_KEY );
		}

		return Plandose_Print_Result::ok( $row, $request->is_pro, $limit );
	}

	/**
	 * COMMIT the charge transaction and confirm it. When COMMIT
	 * is not confirmed (see Plandose_Print_Transaction::commit()), the
	 * charge row (charged since this attempt) and, with a request id, its
	 * request row must both be there: they were written in the same
	 * transaction as the counter, so their presence means it committed.
	 *
	 * @param Plandose_Print_Transaction $tx             The open transaction.
	 * @param Plandose_Print_Request     $request        The request.
	 * @param object|null                $previous       Charge row before this charge.
	 * @param int                        $charge_started Unix time the charge was attempted.
	 * @return bool True when the charge is known to be stored.
	 */
	private static function commit_confirmed( Plandose_Print_Transaction $tx, Plandose_Print_Request $request, $previous, $charge_started ) {
		if ( $tx->commit() ) {
			return true;
		}

		if ( ! self::charged_since( $request, $previous, $charge_started ) ) {
			return false;
		}

		return '' === $request->request_hash || is_object( Plandose_Print_Charges::find_request( $request->user_id, $request->request_hash ) );
	}

	/**
	 * After the counter was charged in autocommit on a new connection
	 * (the transaction was lost, see the note above its call in
	 * charge_locked()), confirm that the token's charge row and this
	 * request's record exist, writing them again if the reconnect lost
	 * them. Tried twice. Only on that rare path: a transaction that was
	 * not lost commits the rows together with the counter.
	 *
	 * The answer to the client stays «success» either way: the counter WAS
	 * charged, and an error would make the client send the same request
	 * again — which, with its record missing, would charge it a second
	 * time. When the rows still cannot be written, that is logged: the
	 * one consequence left is that a free reprint of this plan is charged
	 * as a new print.
	 *
	 * @param Plandose_Print_Request $request        The request.
	 * @param int                    $charge_started Unix time the charge was attempted.
	 * @return bool True when both records are confirmed.
	 */
	private static function ensure_charge_records( Plandose_Print_Request $request, $charge_started ) {
		$user_id      = $request->user_id;
		$receipt_key  = $request->receipt_key;
		$request_hash = $request->request_hash;

		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$written    = Plandose_Print_Charges::find( $user_id, $receipt_key );
			$charge_ok  = is_object( $written ) && (int) $written->charged_ts >= (int) $charge_started;
			$request_ok = true;

			if ( '' !== $request_hash ) {
				$request_ok = is_object( Plandose_Print_Charges::find_request( $user_id, $request_hash ) );
			}

			if ( $charge_ok && $request_ok ) {
				return true;
			}

			// The last pass only looks: no write is left unchecked.
			if ( 2 === $attempt ) {
				break;
			}

			// false = the lookup itself failed; writing blind could only
			// add a duplicate-key error, so look again on the next pass.
			if ( ! $charge_ok && false !== $written ) {
				Plandose_Print_Charges::write_charge( $user_id, $receipt_key, $written ? $written : null );
			}

			if ( ! $request_ok ) {
				Plandose_Print_Charges::record_request( $user_id, $request_hash, $receipt_key, Plandose_Print_Charges::KIND_CHARGE );
			}
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Billing inconsistency the site owner must be able to see; no plan or patient data.
		error_log(
			sprintf(
				'PlanDose: print charged for user %d, but its charge/request record could not be written after a lost database connection. A reprint of this plan within 30 minutes may be charged as a new print.',
				(int) $user_id
			)
		);

		return false;
	}

	/**
	 * Whether this token's charge row shows a charge made at
	 * or after $since (by this request, or a concurrent one) — as opposed
	 * to $previous, the row as it was before.
	 *
	 * @param Plandose_Print_Request $request  The request.
	 * @param object|null            $previous The row before the charge, if any.
	 * @param int                    $since    Unix time the charge was attempted.
	 * @return bool
	 */
	private static function charged_since( Plandose_Print_Request $request, $previous, $since ) {
		$now = Plandose_Print_Charges::find( $request->user_id, $request->receipt_key );

		// A concurrent request may have taken its timestamp before this one
		// started and then waited on a row lock: allow the lock window.
		if ( ! is_object( $now ) || (int) $now->charged_ts < (int) $since - Plandose_Ajax::PRINT_DEBOUNCE_SECONDS ) {
			return false;
		}

		return ! is_object( $previous ) || (int) $now->charged_ts !== (int) $previous->charged_ts;
	}

	/**
	 * «A concurrent request holds / handled this token», with the status
	 * read now.
	 *
	 * @param Plandose_Print_Request $request The request.
	 * @return Plandose_Print_Result
	 */
	private static function duplicate_ignored( Plandose_Print_Request $request ) {
		return Plandose_Print_Result::duplicate_ignored( Plandose_Subscriptions::get_row( $request->user_id ), $request->is_pro, $request->limit );
	}

	/**
	 * «Not recorded, try again».
	 *
	 * @param Plandose_Print_Request $request The request.
	 * @param object|null            $row     Subscription row to report.
	 * @return Plandose_Print_Result
	 */
	private static function server_error( Plandose_Print_Request $request, $row ) {
		return Plandose_Print_Result::server_error( $row, $request->is_pro, $request->limit );
	}
}
