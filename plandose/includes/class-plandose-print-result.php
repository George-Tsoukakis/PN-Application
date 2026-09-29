<?php
/**
 * The answer to one print registration (plandose_register_print), as a
 * value: success or error, the JSON payload and the HTTP status. Every
 * answer the print flow can give is built by one of the named
 * constructors below and sent by send(), so the wire format lives in
 * this file only.
 *
 * The client (assets/js/api.js) reads these flags: already_recorded and
 * duplicate_ignored (recorded, print), server_error (not recorded, retry
 * with the same request id), limit_reached, replay_limit,
 * invalid_request and invalid_token (not recorded, do not print).
 *
 * Works with:
 * - includes/class-plandose-ajax.php (register_print(), check_print())
 * - includes/class-plandose-print-replay.php
 * - includes/class-plandose-print-new-charge.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plandose_Print_Result {

	/**
	 * @var bool
	 */
	private $success;

	/**
	 * @var array<string,mixed>
	 */
	private $payload;

	/**
	 * HTTP status; null leaves WordPress's default (200).
	 *
	 * @var int|null
	 */
	private $status;

	/**
	 * @param bool                $success Sent with wp_send_json_success().
	 * @param array<string,mixed> $payload The JSON data.
	 * @param int|null            $status  HTTP status, or null.
	 */
	private function __construct( $success, array $payload, $status = null ) {
		$this->success = (bool) $success;
		$this->payload = $payload;
		$this->status  = $status;
	}

	/**
	 * The pharmacy's print status, part of every answer but invalid_token.
	 *
	 * @param object|null $row    Subscription row.
	 * @param bool        $is_pro Pro active.
	 * @param int         $limit  Effective monthly limit (0 = unlimited).
	 * @return array<string,mixed>
	 */
	public static function status_payload( $row, $is_pro, $limit ) {
		// This month's count, as register_print() enforces it. The
		// raw column still holds last month's total when the monthly reset
		// failed, and check_print() would then refuse a pharmacy on the 1st.
		$count     = Plandose_Subscriptions::current_month_count( $row );
		$remaining = 0 === (int) $limit ? null : max( 0, (int) $limit - $count );

		return array(
			'unlimited'     => 0 === (int) $limit,
			'is_pro'        => (bool) $is_pro,
			'limit'         => (int) $limit,
			'print_count'   => $count,
			'remaining'     => $remaining,
			'last_print_at' => ( is_object( $row ) && ! empty( $row->last_print_at ) ) ? $row->last_print_at : '',
		);
	}

	/**
	 * The print token is missing or malformed (HTTP 400).
	 *
	 * @return self
	 */
	public static function invalid_token() {
		return new self(
			false,
			array(
				'message'       => __( 'Μη έγκυρο αναγνωριστικό εκτύπωσης. Ανανεώστε τη σελίδα και δοκιμάστε ξανά.', 'plandose' ),
				'invalid_token' => true,
			),
			400
		);
	}

	/**
	 * A plain success with the print status: a new print was charged
	 * (register_print()) or printing is allowed (check_print()).
	 *
	 * @param object|null $row    Subscription row.
	 * @param bool        $is_pro Pro active.
	 * @param int         $limit  Effective limit.
	 * @return self
	 */
	public static function ok( $row, $is_pro, $limit ) {
		return new self( true, self::status_payload( $row, $is_pro, $limit ) );
	}

	/**
	 * «Already charged, free reprint» — also the answer to a replayed
	 * request.
	 *
	 * @param object|null $row           Subscription row.
	 * @param bool        $is_pro        Pro active.
	 * @param int         $limit         Effective limit.
	 * @param int         $reprints_used Free reprints used on this charge so far.
	 * @return self
	 */
	public static function already_recorded( $row, $is_pro, $limit, $reprints_used ) {
		return new self(
			true,
			array_merge(
				self::status_payload( $row, $is_pro, $limit ),
				array(
					'already_recorded'   => true,
					'free_reprints_left' => max( 0, Plandose_Ajax::MAX_FREE_REPRINTS - (int) $reprints_used ),
				)
			)
		);
	}

	/**
	 * «A concurrent request holds / handled this token».
	 *
	 * @param object|null $row    Subscription row.
	 * @param bool        $is_pro Pro active.
	 * @param int         $limit  Effective limit.
	 * @return self
	 */
	public static function duplicate_ignored( $row, $is_pro, $limit ) {
		return new self(
			true,
			array_merge(
				self::status_payload( $row, $is_pro, $limit ),
				array(
					'duplicate_ignored' => true,
				)
			)
		);
	}

	/**
	 * «Not recorded, try again» (HTTP 500).
	 *
	 * @param object|null $row    Subscription row.
	 * @param bool        $is_pro Pro active.
	 * @param int         $limit  Effective limit.
	 * @return self
	 */
	public static function server_error( $row, $is_pro, $limit ) {
		return new self(
			false,
			array_merge(
				self::status_payload( $row, $is_pro, $limit ),
				array(
					'message'      => __( 'Δεν ήταν δυνατή η καταγραφή της εκτύπωσης. Δοκιμάστε ξανά.', 'plandose' ),
					'server_error' => true,
				)
			),
			500
		);
	}

	/**
	 * The monthly limit is reached (HTTP 200, as it always was).
	 *
	 * @param object|null $row    Subscription row.
	 * @param bool        $is_pro Pro active.
	 * @param int         $limit  Effective limit.
	 * @return self
	 */
	public static function limit_reached( $row, $is_pro, $limit ) {
		return new self(
			false,
			array_merge(
				self::status_payload( $row, $is_pro, $limit ),
				array(
					/* translators: %d: monthly print limit */
					'message'       => sprintf( __( 'Έχετε φτάσει το όριο των %d εκτυπώσεων για αυτόν τον μήνα.', 'plandose' ), (int) $limit ),
					'limit_reached' => true,
				)
			)
		);
	}

	/**
	 * This request id was already answered MAX_REPLAYS times (HTTP 409).
	 *
	 * @param object|null $row    Subscription row.
	 * @param bool        $is_pro Pro active.
	 * @param int         $limit  Effective limit.
	 * @return self
	 */
	public static function replay_limit( $row, $is_pro, $limit ) {
		return new self(
			false,
			array_merge(
				self::status_payload( $row, $is_pro, $limit ),
				array(
					'message'      => sprintf(
						/* translators: %d: how many times one print request may be repeated */
						__( 'Αυτή η εκτύπωση έχει ήδη επαναληφθεί %d φορές και δεν επαναλαμβάνεται ξανά. Δεν χρεώθηκε και δεν εκτυπώθηκε. Πατήστε ξανά «Εκτύπωση» για νέα εκτύπωση.', 'plandose' ),
						Plandose_Print_Charges::MAX_REPLAYS
					),
					'replay_limit' => true,
				)
			),
			409
		);
	}

	/**
	 * A request id recorded for another token (HTTP 400).
	 *
	 * @param object|null $row    Subscription row.
	 * @param bool        $is_pro Pro active.
	 * @param int         $limit  Effective limit.
	 * @return self
	 */
	public static function invalid_request( $row, $is_pro, $limit ) {
		return new self(
			false,
			array_merge(
				self::status_payload( $row, $is_pro, $limit ),
				array(
					'message'         => __( 'Μη έγκυρο αίτημα εκτύπωσης. Δοκιμάστε ξανά.', 'plandose' ),
					'invalid_request' => true,
				)
			),
			400
		);
	}

	/**
	 * Send the answer and end the request.
	 */
	public function send() {
		if ( $this->success ) {
			wp_send_json_success( $this->payload, $this->status );
		} else {
			wp_send_json_error( $this->payload, $this->status );
		}
	}
}
