<?php
/**
 * One print registration (plandose_register_print): the validated input
 * (print token, optional request id — only ever kept as hashes), the
 * pharmacy's subscription state it is judged against, and the token's
 * charge row as the replay step last read it.
 *
 * Built by Plandose_Ajax::register_print() after its guard; read by
 * Plandose_Print_Replay and Plandose_Print_New_Charge. Never holds any
 * plan or patient data: the client sends nothing but the two ids.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plandose_Print_Request {

	/** @var int Pharmacy user ID. */
	public $user_id;

	/** @var string The validated print token. */
	public $token;

	/** @var string md5 of the token (print_charges.token_hash). */
	public $receipt_key;

	/** @var string md5 of the request id, or '' when none was sent. */
	public $request_hash;

	/** @var object|null Subscription row as read at the start. */
	public $row;

	/** @var bool Pro active. */
	public $is_pro;

	/** @var int Effective monthly limit (0 = unlimited). */
	public $limit;

	/** @var string Site-local 'Y-m-d' the month reset and the increment use. */
	public $today;

	/** @var int Unix time this request started (see charged_since()). */
	public $started;

	/**
	 * The token's charge row as last read before the charge path: null
	 * when never charged (or gone), else expired or out of free reprints.
	 * The new charge is locked and compared against it.
	 *
	 * @var object|null
	 */
	public $charge = null;

	/**
	 * @param int         $user_id      Pharmacy user ID.
	 * @param string      $token        Validated print token.
	 * @param string      $request_hash See request_hash().
	 * @param object|null $row          Subscription row.
	 * @param bool        $is_pro       Pro active.
	 * @param int         $limit        Effective limit.
	 * @param string      $today        Site-local 'Y-m-d'.
	 */
	public function __construct( $user_id, $token, $request_hash, $row, $is_pro, $limit, $today ) {
		$this->user_id      = (int) $user_id;
		$this->token        = (string) $token;
		$this->request_hash = (string) $request_hash;
		$this->row          = $row;
		$this->is_pro       = $is_pro;
		$this->limit        = $limit;
		$this->today        = $today;
		$this->receipt_key  = self::receipt_key( $this->token );
		// When this request started, for charged_since() after the
		// lock wait.
		$this->started = time();
	}

	/**
	 * Read and validate the POSTed ids.
	 *
	 * The client generates one token per print action (see doPrintSafely()
	 * in assets/js/print.js) so genuinely separate prints aren't mistaken for
	 * duplicate resubmits of the same print — see Plandose_Print_Lock::acquire().
	 *
	 * Validated against the exact shapes PD.createPrintToken()
	 * in assets/js/api.js produces, not a loose character
	 * whitelist (see is_valid_token()). And only a string: a token[]=x
	 * body makes $_POST['token'] an array, which (string) would turn into
	 * "Array" — a valid-looking token that every such request would share,
	 * so they would all collide on one print lock.
	 *
	 * request_id is the id of this press of Print (see
	 * consumePrintCreditOnce() in assets/js/api.js). Optional — an older
	 * cached script sends none — and only ever stored as a hash. A request
	 * id that already charged the token or used a free reprint is answered
	 * from its record when it is sent again: the same request never
	 * charges or uses a free reprint twice (Plandose_Print_Charges,
	 * requests table), for the whole PRINT_RECEIPT_TTL, as the
	 * client promises after a lost answer, but at most
	 * Plandose_Print_Charges::MAX_REPLAYS times (a replay prints without
	 * charging anything). The next one is refused (replay_limit): not
	 * charged, not printed.
	 *
	 * The request id is required. Every script since 1.24.0 sends one;
	 * without it the lock-held path answered «already recorded, print»
	 * with nothing charged, so a client that left it out (or sent a
	 * malformed one) could print past the Free limit by racing
	 * same-token requests. A missing or malformed id is refused like a
	 * malformed token.
	 *
	 * Call only after the nonce check (Plandose_Ajax::guard()).
	 *
	 * @return array{token:string,request_hash:string}|null Null when the
	 *         token or the request id is missing or malformed.
	 */
	public static function read_post() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in Plandose_Ajax::guard() (check_ajax_referer) before this is called; the raw value is only ever accepted if it matches the strict pattern in full.
		$raw_token = isset( $_POST['token'] ) ? wp_unslash( $_POST['token'] ) : '';
		$token     = is_string( $raw_token ) ? $raw_token : '';

		if ( ! self::is_valid_token( $token ) ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in Plandose_Ajax::guard(); only accepted if it matches the strict token pattern in full.
		$raw_request  = isset( $_POST['request_id'] ) ? wp_unslash( $_POST['request_id'] ) : '';
		$request_hash = self::request_hash( $raw_request );

		if ( '' === $request_hash ) {
			return null;
		}

		return array(
			'token'        => $token,
			'request_hash' => $request_hash,
		);
	}

	/**
	 * The print-token shapes PD.createPrintToken() produces. Also
	 * used for the request id.
	 * - 32 lowercase hex digits (16 bytes from crypto.getRandomValues());
	 * - the no-Web-Crypto fallback "<Date.now()>-<counter>-<random>",
	 *   whose last part is String(Math.random()).slice(2): normally
	 *   digits, but exponent notation for a tiny random value can put
	 *   'e' and '-' there too (e.g. "1e-7".slice(2) === "-7").
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_valid_token( $value ) {
		return is_string( $value )
			&& '' !== $value
			&& (bool) preg_match( '/^(?:[0-9a-f]{32}|[0-9]{10,16}-[0-9]{1,10}-[0-9e-]{0,40})$/D', $value );
	}

	/**
	 * md5 of a valid request id; '' for none or a malformed one.
	 *
	 * @param mixed $raw_request Raw request_id.
	 * @return string
	 */
	public static function request_hash( $raw_request ) {
		return ( is_string( $raw_request ) && self::is_valid_token( $raw_request ) )
			? md5( 'plandose_print_request|' . $raw_request )
			: '';
	}

	/**
	 * Receipt key for a print token. A hash, so the ledger never
	 * holds the token itself.
	 *
	 * @param string $token Validated print token.
	 * @return string
	 */
	public static function receipt_key( $token ) {
		return md5( 'plandose_print_receipt|' . (string) $token );
	}

	/**
	 * One row in the admin print log (Plandose_Print_Log). Called
	 * only once a charge or a free reprint is committed; replays of the
	 * same request and «already handled» answers write nothing, so a print
	 * is listed once. Never throws.
	 *
	 * @param string $kind        Plandose_Print_Log::KIND_*.
	 * @param int    $month_count Monthly counter after a charged print.
	 */
	public function log_print( $kind, $month_count ) {
		if ( ! class_exists( 'Plandose_Print_Log' ) ) {
			return;
		}

		try {
			Plandose_Print_Log::record( $this->user_id, $kind, (bool) $this->is_pro, (int) $month_count );
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Never let the log break a print.
			error_log( 'PlanDose: print-log write failed: ' . $e->getMessage() );
		}
	}
}
