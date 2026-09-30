<?php
/**
 * The print answers that charge nothing: a request id sent again (a
 * retry after a lost answer) is answered from its record, and a token
 * this pharmacy was already charged for uses one of its free reprints.
 * Only what falls through both — a token never charged, expired, or out
 * of free reprints — reaches Plandose_Print_New_Charge.
 *
 * Works with:
 * - includes/class-plandose-print-request.php, class-plandose-print-result.php
 * - includes/class-plandose-print-transaction.php (the free reprint and
 *   its request record in one transaction)
 * - includes/class-plandose-print-charges.php (the ledger)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plandose_Print_Replay {

	/**
	 * Answer the request without a new charge, when it is a replay or a
	 * free reprint. Null when it must be charged: $request->charge then
	 * holds the token's charge row as last read.
	 *
	 * @param Plandose_Print_Request $request The request.
	 * @return Plandose_Print_Result|null
	 */
	public static function answer( Plandose_Print_Request $request ) {
		// 1. The same request again: answer from its record — up to
		// MAX_REPLAYS times per request id, on top of the charged print and
		// its free reprints (the full per-charge ceiling is spelled out
		// above Plandose_Ajax::PRINT_RECEIPT_META_KEY).
		$prior = Plandose_Print_Charges::find_request( $request->user_id, $request->request_hash );

		if ( false === $prior ) {
			return self::server_error( $request );
		}

		if ( $prior ) {
			return self::answer_known( $request, $prior );
		}

		$charge = Plandose_Print_Charges::find( $request->user_id, $request->receipt_key );

		if ( false === $charge ) {
			return self::server_error( $request );
		}

		/*
		 * 2. A token this pharmacy was already charged for — «Εκτύπωση ξανά»
		 * of the same plan — uses one of its free reprints, before the
		 * monthly limit. The reprint and its request record are written in
		 * one transaction. Past MAX_FREE_REPRINTS (or PRINT_RECEIPT_TTL) it
		 * falls through and is charged as a new print.
		 */
		if ( $charge && Plandose_Print_Charges::is_live( $charge ) ) {
			for ( $attempt = 0; $attempt < 3; $attempt++ ) {
				if ( (int) $charge->reprints >= Plandose_Ajax::MAX_FREE_REPRINTS ) {
					break;
				}

				$outcome = self::try_free_reprint( $request, $charge );

				if ( 'used' === $outcome ) {
					// The admin print log (after the commit, best effort).
					$request->log_print( 'reprint', 0 );

					return Plandose_Print_Result::already_recorded( $request->row, $request->is_pro, $request->limit, (int) $charge->reprints + 1 );
				}

				if ( 'replay' === $outcome ) {
					return self::answer_recorded( $request );
				}

				if ( 'error' === $outcome ) {
					return self::server_error( $request );
				}

				// 'changed': another request changed the row first. Look
				// again before deciding anything — it may have used the last
				// free reprint, or re-charged the token.
				$charge = Plandose_Print_Charges::find( $request->user_id, $request->receipt_key );

				if ( false === $charge ) {
					return self::server_error( $request );
				}

				if ( ! $charge || ! Plandose_Print_Charges::is_live( $charge ) ) {
					break;
				}
			}

			/*
			 * Still live with free reprints left after three lost
			 * races: concurrent reprints of this token are in flight. It is
			 * NOT a new print, and nothing was used for this request, so the
			 * answer is «try again» (the retry uses a free reprint) — never
			 * the charge path, which would answer it as a success.
			 */
			if ( $charge && Plandose_Print_Charges::is_live( $charge ) && (int) $charge->reprints < Plandose_Ajax::MAX_FREE_REPRINTS ) {
				return self::server_error( $request );
			}
		}

		$request->charge = $charge;

		return null;
	}

	/**
	 * Answer a request id found in the requests table. Replayed only
	 * when it was recorded for THIS token; a request id recorded for another
	 * token is refused (invalid_request, nothing charged) — the shipped
	 * script never does that (one id per press, tied to its token), and
	 * answering it would let one id print a different plan for free.
	 *
	 * @param Plandose_Print_Request $request The request.
	 * @param object                 $prior   Row from find_request().
	 * @return Plandose_Print_Result
	 */
	public static function answer_known( Plandose_Print_Request $request, $prior ) {
		if ( is_object( $prior ) && hash_equals( (string) $prior->token_hash, (string) $request->receipt_key ) ) {
			// Bounded — see Plandose_Print_Charges::MAX_REPLAYS.
			$counted = Plandose_Print_Charges::use_replay( $prior );

			if ( 1 === $counted ) {
				return self::replayed( $request );
			}

			if ( false === $counted ) {
				return self::server_error( $request );
			}

			return Plandose_Print_Result::replay_limit( $request->row, $request->is_pro, $request->limit );
		}

		return Plandose_Print_Result::invalid_request( $request->row, $request->is_pro, $request->limit );
	}

	/**
	 * A write reported that this request id is already recorded:
	 * read that record and answer from it (answer_known()); when it cannot
	 * be read, «try again».
	 *
	 * @param Plandose_Print_Request $request The request.
	 * @return Plandose_Print_Result
	 */
	public static function answer_recorded( Plandose_Print_Request $request ) {
		$prior = Plandose_Print_Charges::find_request( $request->user_id, $request->request_hash );

		if ( ! is_object( $prior ) ) {
			return self::server_error( $request );
		}

		return self::answer_known( $request, $prior );
	}

	/**
	 * Answer a request id that was already handled, without charging
	 * or using anything. The client treats it as «recorded» and prints.
	 * Callers go through answer_known(), which checks the token.
	 *
	 * @param Plandose_Print_Request $request The request.
	 * @return Plandose_Print_Result
	 */
	private static function replayed( Plandose_Print_Request $request ) {
		$charge = Plandose_Print_Charges::find( $request->user_id, $request->receipt_key );
		$used   = ( is_object( $charge ) && Plandose_Print_Charges::is_live( $charge ) ) ? (int) $charge->reprints : Plandose_Ajax::MAX_FREE_REPRINTS;

		return Plandose_Print_Result::already_recorded( Plandose_Subscriptions::get_row( $request->user_id ), $request->is_pro, $request->limit, $used );
	}

	/**
	 * Use one free reprint of a live charge, recording the request
	 * in the same transaction (when there is a request id).
	 *
	 * @param Plandose_Print_Request $request The request.
	 * @param object                 $charge  Live charge row.
	 * @return string 'used', 'replay' (this request id is already recorded),
	 *                'changed' (the row changed meanwhile — look again) or
	 *                'error'.
	 */
	private static function try_free_reprint( Plandose_Print_Request $request, $charge ) {
		$user_id      = $request->user_id;
		$request_hash = $request->request_hash;

		// Never '' since 1.30.1 (Plandose_Print_Request::read_post()
		// refuses a request without an id). Kept fail-closed: a free
		// reprint without its request record could not be told from a
		// retry of it, so «try again», never a reprint.
		if ( '' === $request_hash ) {
			return 'error';
		}

		$tx = Plandose_Print_Transaction::begin();

		if ( ! $tx ) {
			return 'error';
		}

		$recorded = Plandose_Print_Charges::record_request( $user_id, $request_hash, $request->receipt_key, Plandose_Print_Charges::KIND_REPRINT );

		if ( 'ok' !== $recorded ) {
			$tx->rollback();

			if ( 'duplicate' !== $recorded && $tx->lost() ) {
				Plandose_Print_Charges::forget_request( $user_id, $request_hash );
			}

			return 'duplicate' === $recorded ? 'replay' : 'error';
		}

		$used = Plandose_Print_Charges::use_reprint( $charge, Plandose_Ajax::MAX_FREE_REPRINTS );

		if ( 1 !== $used ) {
			$tx->rollback();
			// Without transactions the request row would stay behind. Only
			// that row: the charge row is not ours to touch here (another
			// request just changed it — writing old values back would hand
			// out an extra free reprint).
			// And only without transactions: after a real ROLLBACK the row
			// is already gone, and a concurrent copy of this same request
			// may have committed its own row since — deleting it would
			// break idempotency.
			if ( Plandose_Print_Charges::engines_bad( true ) || $tx->lost() ) {
				Plandose_Print_Charges::forget_request( $user_id, $request_hash );
			}

			return false === $used ? 'error' : 'changed';
		}

		// A reconnect after the request row was written: that row was lost
		// with the old transaction. Put it back so the request stays
		// idempotent (the reprint count may then be one short — a free
		// reprint more, never a charge).
		if ( $tx->lost() && ! Plandose_Print_Charges::find_request( $user_id, $request_hash ) ) {
			Plandose_Print_Charges::record_request( $user_id, $request_hash, $request->receipt_key, Plandose_Print_Charges::KIND_REPRINT );
		}

		// Not confirmed (see Plandose_Print_Transaction::commit()): the
		// request row tells whether it went through.
		if ( ! $tx->commit() ) {
			$check = Plandose_Print_Charges::find_request( $user_id, $request_hash );

			return is_object( $check ) ? 'used' : 'error';
		}

		return 'used';
	}

	/**
	 * «Not recorded, try again», with the status read at the start.
	 *
	 * @param Plandose_Print_Request $request The request.
	 * @return Plandose_Print_Result
	 */
	private static function server_error( Plandose_Print_Request $request ) {
		return Plandose_Print_Result::server_error( $request->row, $request->is_pro, $request->limit );
	}
}
