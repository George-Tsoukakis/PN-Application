<?php
/**
 * One database transaction of the print flow (a charge, or a free reprint
 * with its request record), with the detection of a transaction lost to a
 * silent wpdb reconnect.
 *
 * wpdb reconnects on «server has gone away» and re-runs the failed
 * statement on a NEW connection, in autocommit: whatever the old
 * connection's transaction had written is rolled back by the server,
 * while the statements after the reconnect stick. Neither START
 * TRANSACTION nor COMMIT reports that — a COMMIT re-run on the fresh
 * connection even "succeeds" with nothing to commit. The only trace is a
 * different connection id, so the id is read before START TRANSACTION
 * and compared after the statements that matter (lost()) and after
 * COMMIT (commit()). Callers decide what a lost
 * transaction means for their rows: see Plandose_Print_Replay and
 * Plandose_Print_New_Charge.
 *
 * Works with: includes/class-plandose-print-charges.php (begin(),
 * commit(), rollback(), connection_id()).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plandose_Print_Transaction {

	/**
	 * Connection id read before START TRANSACTION (0 = unknown, never
	 * reported lost).
	 *
	 * @var int
	 */
	private $connection;

	/**
	 * @param int $connection From Plandose_Print_Charges::connection_id().
	 */
	private function __construct( $connection ) {
		$this->connection = (int) $connection;
	}

	/**
	 * START TRANSACTION. The connection id is read BEFORE and
	 * checked right after it: a reconnect triggered by either statement
	 * shows up as a difference, and then — as when the server refused the
	 * transaction — it is rolled back and null returned: the caller must
	 * not write anything.
	 *
	 * @return self|null
	 */
	public static function begin() {
		$tx = new self( Plandose_Print_Charges::connection_id() );

		if ( ! Plandose_Print_Charges::begin() || $tx->lost() ) {
			Plandose_Print_Charges::rollback();

			return null;
		}

		return $tx;
	}

	/**
	 * Whether this transaction was lost to a silent wpdb reconnect
	 * (the connection id changed since begin()). Statements after the
	 * reconnect ran in autocommit.
	 *
	 * @return bool
	 */
	public function lost() {
		return self::changed_since( $this->connection );
	}

	/**
	 * ROLLBACK.
	 */
	public function rollback() {
		Plandose_Print_Charges::rollback();
	}

	/**
	 * COMMIT. True only when COMMIT succeeded on the connection the
	 * transaction was begun on. False means «unknown»: COMMIT failed, or
	 * wpdb re-ran it (or an earlier statement) on a new connection after
	 * the old one was lost — the transaction is then rolled back although
	 * COMMIT "succeeded". The caller must then check its rows to know
	 * whether it went through.
	 *
	 * This only covers the transaction as a whole. A statement that
	 * must not be lost silently (the print counter) is checked with
	 * lost() by the caller right after it, before anything else runs —
	 * see Plandose_Print_New_Charge::charge_locked().
	 *
	 * @return bool
	 */
	public function commit() {
		return Plandose_Print_Charges::commit() && ! $this->lost();
	}

	/**
	 * @param int $connection A connection id read earlier.
	 * @return bool
	 */
	private static function changed_since( $connection ) {
		return (int) $connection > 0 && Plandose_Print_Charges::connection_id() !== (int) $connection;
	}
}
