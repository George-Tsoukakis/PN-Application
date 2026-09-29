<?php
/**
 * AJAX endpoints for the PlanDose popup: print-limit gate and pharmacy header lookup.
 *
 * Works with:
 * - assets/js/api.js
 * - assets/js/print.js
 * - assets/js/state.js
 * - includes/class-plandose-subscriptions.php
 * - includes/class-plandose-print-*.php (the register_print() flow)
 *
 * Print flow:
 * - check_print()    : read-only check before print.
 * - register_print() : records one print credit. The client calls it
 *                      BEFORE the plan is written into the print
 *                      window, and prints only once it succeeds (see
 *                      doPrintSafely() in assets/js/print.js). It
 *                      receives nothing but a random token — never any
 *                      patient data.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plandose_Ajax {

	/**
	 * Seconds during which duplicate print registrations are ignored.
	 *
	 * This protects against:
	 * - double click
	 * - browser firing afterprint + fallback timeout
	 * - slow AJAX / repeated requests
	 * - a manual retry after the client gave up on a request that the
	 *   server had in fact already recorded
	 *
	 * MUST STAY LARGER THAN THE CLIENT'S AJAX TIMEOUT (PD.AJAX_TIMEOUT_MS
	 * in assets/js/state.js, currently 15s). With a shorter window, a
	 * register_print request that timed out on the client would leave the
	 * lock already expired by the time the pharmacist could press Print
	 * again. The retry correctly reuses the same token (see doPrintSafely()
	 * in assets/js/print.js), but the server would have nothing left to
	 * recognise it by and would count the same print twice.
	 *
	 * A long window is only safe because the lock is scoped per token
	 * rather than per user (see Plandose_Print_Lock::key()): it cannot block
	 * a genuinely different plan for a different patient, only a resubmit
	 * of the same print action.
	 */
	const PRINT_DEBOUNCE_SECONDS = 60;

	/**
	 * Print receipts — the server's own record of which print tokens
	 * it has already charged, kept per pharmacy in user meta, ONE META ROW
	 * PER RECEIPT (so two prints of different plans at the same moment,
	 * e.g. two PCs on one pharmacy login, never overwrite each other's
	 * receipt).
	 *
	 * The 60-second lock above only guards concurrent requests. Whether a
	 * token was already paid for is decided here, for PRINT_RECEIPT_TTL
	 * (30 minutes), and BEFORE
	 * the monthly limit: a retry after a lost response (even for the last
	 * free print of the month, even minutes later) is answered
	 * `already_recorded` and never charged twice or refused.
	 *
	 * Free reprints of the same plan go through the same check, so the
	 * browser cannot grant itself a print: a token the server never
	 * recorded is a new print, subject to the limit. At most
	 * MAX_FREE_REPRINTS reprints per recorded plan are free; the next one is
	 * charged as a new print.
	 *
	 * Each row is "<md5 of token>:<unix time charged>:<free reprints used>" —
	 * never any plan data. Rows older than PRINT_RECEIPT_TTL are pruned.
	 *
	 * The free-reprint window is 2 reprints within 30 minutes. The server
	 * only ever sees a token, never the plan, so a reused token can print a
	 * DIFFERENT plan on every free reprint. The window is still enough for
	 * «I pressed Cancel / wrong printer / paper jam» (the plan itself is
	 * wiped after 10 idle minutes anyway), while a reused token yields at
	 * most 3 prints per charge, and only for half an hour.
	 *
	 * On top of those 3, each of the 3 request ids
	 * may be answered again up to Plandose_Print_Charges::MAX_REPLAYS times
	 * when its answer was lost, within the request record's own 30 minutes.
	 * A client that fakes lost answers can therefore reach 3 × (1 + 2) = 9
	 * sheets per charge within about an hour. The sheet itself is built in
	 * the browser, so the limit is an honest-client limit in any case (see
	 * readme.txt, «Known limits»); these bounds keep an honest client's
	 * retries free without making abuse free.
	 *
	 * The user-meta receipts themselves are not read: the ledger
	 * (Plandose_Print_Charges) holds this record, and they only ever counted
	 * for PRINT_RECEIPT_TTL. The key stays so leftover rows are still
	 * erased (privacy eraser, daily cleanup, uninstall).
	 */
	const PRINT_RECEIPT_META_KEY  = 'plandose_print_receipt';
	const PRINT_RECEIPT_TTL       = 1800;
	const MAX_FREE_REPRINTS       = 2;

	/**
	 * Simple per-user request throttle for these AJAX endpoints (see
	 * is_rate_limited() below). Generous enough for normal popup use
	 * (opening the modal, typing drugs, previewing, printing) but stops
	 * a runaway/malicious client from hammering the endpoints.
	 */
	const RATE_LIMIT_MAX_REQUESTS   = 30;
	const RATE_LIMIT_WINDOW_SECONDS = 10;

	/**
	 * Separate buckets prevent a noisy read-only endpoint (for example,
	 * header refreshes from multiple tabs) from blocking the financially
	 * relevant print-registration endpoint.
	 */
	const RATE_LIMIT_BUCKET_CHECK_PRINT    = 'check_print';
	const RATE_LIMIT_BUCKET_REGISTER_PRINT = 'register_print';
	const RATE_LIMIT_BUCKET_HEADER         = 'header';
	const RATE_LIMIT_BUCKET_NONCE          = 'nonce';

	/**
	 * Guards against registering the AJAX hooks more than once. As with
	 * Plandose_Subscriber_Query::init_cache_hooks(), WordPress already
	 * de-duplicates identical static-method callbacks by unique id, so this
	 * is defensive rather than corrective.
	 *
	 * @var bool
	 */
	private static $initialized = false;

	/**
	 * Register AJAX hooks.
	 */
	public static function init() {
		if ( self::$initialized ) {
			return;
		}

		self::$initialized = true;

		add_action( 'wp_ajax_plandose_check_print', array( __CLASS__, 'check_print' ) );
		add_action( 'wp_ajax_plandose_register_print', array( __CLASS__, 'register_print' ) );
		add_action( 'wp_ajax_plandose_get_header', array( __CLASS__, 'get_header' ) );
		add_action( 'wp_ajax_plandose_refresh_nonce', array( __CLASS__, 'refresh_nonce' ) );
		// Intentionally no wp_ajax_nopriv_* hooks anywhere in this class:
		// every endpoint here is for logged-in pharmacists only, and guests
		// should get WordPress's default "invalid action" (0) response
		// rather than ever reaching PHP-level guard logic.
	}

	/**
	 * AJAX actions in this class mutate session-related state (nonce
	 * rotation, rate-limit counters, print counters) or expose private
	 * account data, so they must not be callable through a cacheable GET.
	 */
	private static function require_post_request() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';

		if ( 'POST' !== $method ) {
			wp_send_json_error(
				array(
					'message'        => __( 'Μη έγκυρη μέθοδος αιτήματος.', 'plandose' ),
					'invalid_method' => true,
				),
				405
			);
		}
	}

	/**
	 * Common AJAX guard.
	 *
	 * Verifies nonce, global plugin enabled setting, and user
	 * permission. Returns current user ID if everything is allowed.
	 *
	 * Order matters here: authorization (can_use_tool()) is checked BEFORE
	 * the rate limit. Otherwise a logged-in user with no PlanDose access at
	 * all could still spend down their own rate-limit bucket (and get a
	 * generic "too many requests" message) purely by hammering an endpoint
	 * they are not allowed to use in the first place. Checking
	 * authorization first means someone without access always gets the
	 * clear "not allowed" message, and the rate limiter only ever meters
	 * requests from users who are actually entitled to use the tool.
	 */
	private static function guard( $bucket = 'general' ) {
		self::require_post_request();
		check_ajax_referer( 'plandose_nonce', 'nonce' );

		$settings = Plandose_Settings::settings();

		if ( empty( $settings['enabled'] ) ) {
			wp_send_json_error(
				array(
					'message'  => __( 'Το εργαλείο PlanDose είναι προσωρινά απενεργοποιημένο.', 'plandose' ),
					'disabled' => true,
				)
			);
		}

		/*
		 * No "login_required" branch: init() registers only wp_ajax_* hooks,
		 * never wp_ajax_nopriv_*, so admin-ajax.php answers a guest with "0"
		 * before any handler — and so this guard — can run. Should a nopriv
		 * hook ever be added, a guest still fails closed:
		 * get_current_user_id() is 0 and can_use_tool( 0 ) is false.
		 */
		$user_id = get_current_user_id();

		if ( ! Plandose_Access::can_use_tool( $user_id ) ) {
			wp_send_json_error(
				array(
					'message'     => __( 'Το εργαλείο είναι διαθέσιμο μόνο σε εγγεγραμμένους φαρμακοποιούς.', 'plandose' ),
					'not_allowed' => true,
				)
			);
		}

		if ( self::is_rate_limited( $user_id, $bucket ) ) {
			wp_send_json_error(
				array(
					'message'      => __( 'Πολλά αιτήματα σε σύντομο χρονικό διάστημα. Δοκιμάστε ξανά σε λίγα δευτερόλεπτα.', 'plandose' ),
					'rate_limited' => true,
				),
				429
			);
		}

		return $user_id;
	}

	/**
	 * Cache group used by the atomic rate-limit path (see
	 * is_rate_limited_atomic() below).
	 */
	const RATE_LIMIT_CACHE_GROUP = 'plandose_rate_limit';

	/**
	 * Transient key used to track this user's request count for the
	 * current rate-limit window (see is_rate_limited_non_atomic()).
	 */
	private static function rate_limit_key( $user_id, $bucket = 'general' ) {
		$bucket = sanitize_key( (string) $bucket );

		if ( '' === $bucket ) {
			$bucket = 'general';
		}

		return 'plandose_rl_' . absint( $user_id ) . '_' . $bucket;
	}

	/**
	 * Simple fixed-window per-user request throttle.
	 *
	 * Dispatches to one of two implementations depending on what the site
	 * has available:
	 *
	 * - is_rate_limited_atomic(): used when wp_using_ext_object_cache() is
	 *   true (Redis/Memcached/etc. actually configured). wp_cache_incr()
	 *   is a genuine atomic increment on those backends, which closes the
	 *   race described below entirely.
	 * - is_rate_limited_non_atomic(): the transient-based
	 *   read-then-write counter, used as a fallback everywhere else
	 *   (including sites with no persistent object cache at all, where
	 *   wp_cache_* calls only live for the duration of a single request
	 *   and can't coordinate across concurrent requests).
	 *
	 * Concurrency note (applies to the non-atomic fallback only): that
	 * path is a read-then-write counter (get_transient() followed by a
	 * separate set_transient()), not an atomic increment. Two requests
	 * from the same user arriving genuinely simultaneously (e.g. two
	 * browser tabs, or a server under enough load that requests overlap)
	 * can both read the same $data['count'] before either writes back, so
	 * one increment can be lost and the effective limit is "usually N,
	 * occasionally N+1" rather than a hard ceiling. That's an acceptable
	 * trade-off for a per-user popup-abuse throttle on a site without a
	 * persistent object cache to begin with — being off by one under rare
	 * overlap doesn't materially change what this guards against — but
	 * it's the reason this isn't also used anywhere a hard/exact cap
	 * actually matters. Contrast with the monthly print-count limit in
	 * Plandose_Subscriptions::try_increment_print_count(), which needs to
	 * be exact regardless of caching setup and so always uses a single
	 * atomic `UPDATE ... WHERE count < limit` SQL statement instead of
	 * either counter here. The window/threshold behavior of this
	 * non-atomic fallback path (the only one exercisable without a real
	 * external object cache present) is intended to be covered by the
	 * project's automated test suite where one is maintained.
	 */
	public static function is_rate_limited( $user_id, $bucket = 'general' ) {
		if ( wp_using_ext_object_cache() ) {
			return self::is_rate_limited_atomic( $user_id, $bucket );
		}

		return self::is_rate_limited_non_atomic( $user_id, $bucket );
	}

	/**
	 * Atomic fixed-window counter for sites with a real persistent object
	 * cache (Redis, Memcached, etc.).
	 *
	 * The window is encoded directly into the cache key (floor(time() /
	 * WINDOW)) rather than stored as data inside it, so there's no
	 * "read the start time, decide if the window rolled over" step to
	 * race on — a new window is simply a different key. wp_cache_add()
	 * seeds the counter at 0 only if no one has already done so for this
	 * user+window (a no-op for every request after the first), and
	 * wp_cache_incr() then does the actual atomic increment-and-return
	 * that these backends guarantee even under real concurrent access.
	 * Keys carry their own TTL, so — like the transient-based fallback —
	 * nothing needs a separate cleanup pass once a window has passed.
	 */
	private static function is_rate_limited_atomic( $user_id, $bucket ) {
		$window_bucket = (int) floor( time() / self::RATE_LIMIT_WINDOW_SECONDS );
		$key           = self::rate_limit_key( $user_id, $bucket ) . '_' . $window_bucket;
		$ttl           = self::RATE_LIMIT_WINDOW_SECONDS + 2; // Small safety margin past the window itself.

		wp_cache_add( $key, 0, self::RATE_LIMIT_CACHE_GROUP, $ttl );

		$count = wp_cache_incr( $key, 1, self::RATE_LIMIT_CACHE_GROUP );

		if ( false === $count ) {
			// Backend claimed ext-object-cache support but didn't actually
			// implement incr/add usefully (shouldn't happen with a real
			// Redis/Memcached backend, but fail open to the safe fallback
			// rather than letting a request through unmetered).
			return self::is_rate_limited_non_atomic( $user_id, $bucket );
		}

		return $count > self::RATE_LIMIT_MAX_REQUESTS;
	}

	/**
	 * Transient-based read-then-write counter. See the
	 * concurrency note on is_rate_limited() above for the accepted
	 * trade-off this makes.
	 */
	private static function is_rate_limited_non_atomic( $user_id, $bucket ) {
		$key = self::rate_limit_key( $user_id, $bucket );
		$now = time();

		$data = get_transient( $key );

		if ( ! is_array( $data ) || empty( $data['start'] ) || ( $now - (int) $data['start'] ) >= self::RATE_LIMIT_WINDOW_SECONDS ) {
			// New window.
			set_transient( $key, array( 'start' => $now, 'count' => 1 ), self::RATE_LIMIT_WINDOW_SECONDS );

			return false;
		}

		$count = isset( $data['count'] ) ? (int) $data['count'] : 0;

		if ( $count >= self::RATE_LIMIT_MAX_REQUESTS ) {
			return true;
		}

		set_transient(
			$key,
			array( 'start' => (int) $data['start'], 'count' => $count + 1 ),
			self::RATE_LIMIT_WINDOW_SECONDS
		);

		return false;
	}

	/**
	 * Remove the rate-limit transient for a deleted user (see
	 * Plandose_Admin::handle_user_deleted()). Also cleans up the legacy
	 * plain-option form used before 1.7.2, in case it hadn't expired/been
	 * migrated yet on this site.
	 *
	 * Only clears the non-atomic (transient) counter. The atomic,
	 * cache-based counter used on sites with a persistent object cache
	 * (see is_rate_limited_atomic()) isn't addressable here — its key
	 * includes the current time window bucket, which this method has no
	 * reason to know — but that's fine: those entries carry their own
	 * short TTL (RATE_LIMIT_WINDOW_SECONDS + 2, a few seconds at most) and
	 * expire on their own almost immediately regardless.
	 */
	public static function delete_rate_limit_for_user( $user_id ) {
		$buckets = array(
			'general',
			self::RATE_LIMIT_BUCKET_CHECK_PRINT,
			self::RATE_LIMIT_BUCKET_REGISTER_PRINT,
			self::RATE_LIMIT_BUCKET_HEADER,
			self::RATE_LIMIT_BUCKET_NONCE,
		);

		foreach ( $buckets as $bucket ) {
			$key = self::rate_limit_key( $user_id, $bucket );
			delete_transient( $key );
			delete_option( $key );
		}

		// Clean up the pre-bucket key used by versions before this change.
		delete_transient( 'plandose_rl_' . absint( $user_id ) );
		delete_option( 'plandose_rl_' . absint( $user_id ) );
	}

	/**
	 * Reissue the AJAX nonce for a still-logged-in, still-allowed user.
	 *
	 * The default WordPress nonce lifetime is ~24h (split into two
	 * ~12h ticks). If a pharmacist leaves the PlanDose popup open across
	 * that boundary, every subsequent AJAX call would fail with the
	 * generic "-1" invalid-nonce response. assets/js/api.js polls
	 * this endpoint periodically to keep config.nonce fresh while the
	 * modal is open, so that never happens.
	 */
	public static function refresh_nonce() {
		self::require_post_request();

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'login_required' => true ) );
		}

		// Mirror guard(): a disabled plugin should not keep handing out fresh
		// nonces either. Placed after the login check so guests never trigger
		// the settings lookup.
		$settings = Plandose_Settings::settings();

		if ( empty( $settings['enabled'] ) ) {
			wp_send_json_error(
				array(
					'message'  => __( 'Το εργαλείο PlanDose είναι προσωρινά απενεργοποιημένο.', 'plandose' ),
					'disabled' => true,
				)
			);
		}

		$user_id = get_current_user_id();

		if ( ! Plandose_Access::can_use_tool( $user_id ) ) {
			wp_send_json_error( array( 'not_allowed' => true ) );
		}

		// This endpoint deliberately skips check_ajax_referer(): its whole
		// purpose is to reissue a nonce once the one the client is holding
		// may already be stale (see the class docblock above), so requiring
		// a still-valid nonce to reach it would defeat that purpose. It
		// shares the same per-user rate limit as the other endpoints
		// instead, which is plenty generous for its actual call pattern
		// (assets/js/api.js polls this every 10 minutes while the modal is
		// open, using NONCE_REFRESH_MS from assets/js/state.js) while still
		// capping how fast
		// it can be hit.
		if ( self::is_rate_limited( $user_id, self::RATE_LIMIT_BUCKET_NONCE ) ) {
			wp_send_json_error( array( 'rate_limited' => true ), 429 );
		}

		wp_send_json_success(
			array(
				'nonce' => wp_create_nonce( 'plandose_nonce' ),
			)
		);
	}

	/**
	 * Effective monthly print limit.
	 *
	 * Returns 0 for unlimited.
	 */
	private static function effective_limit( $is_pro ) {
		if ( $is_pro ) {
			return Plandose_Settings::pro_print_limit();
		}

		return Plandose_Settings::free_monthly_limit();
	}

	/**
	 * Get current user's row and reset monthly counter if needed.
	 */
	private static function get_current_print_context( $user_id ) {
		// "Today" is read once and handed to the increment too, so
		// a request that straddles midnight on the last day of a month
		// resets and increments the SAME month (otherwise it would match
		// no row and answer 500).
		$today = current_time( 'Y-m-d' );

		Plandose_Subscriptions::maybe_reset_month( $user_id, $today );

		$row = Plandose_Subscriptions::get_row( $user_id );

		if ( ! $row ) {
			wp_send_json_error(
				array(
					'message'      => __( 'Δεν ήταν δυνατή η ανάκτηση της συνδρομής σας. Δοκιμάστε ξανά.', 'plandose' ),
					'server_error' => true,
				),
				500
			);
		}

		$is_pro = Plandose_Subscriptions::is_pro_active( $row );
		$limit  = self::effective_limit( $is_pro );

		return array( $row, $is_pro, $limit, $today );
	}

	/**
	 * Check whether the current user may print.
	 *
	 * May initialize/reset the user's monthly subscription state as
	 * needed (see get_current_print_context()) — not strictly read-only.
	 */
	public static function check_print() {
		$user_id = self::guard( self::RATE_LIMIT_BUCKET_CHECK_PRINT );

		list( $row, $is_pro, $limit ) = self::get_current_print_context( $user_id );

		if ( 0 === (int) $limit ) {
			Plandose_Print_Result::ok( $row, $is_pro, $limit )->send();
		}

		if ( Plandose_Subscriptions::current_month_count( $row ) >= (int) $limit ) {
			Plandose_Print_Result::limit_reached( $row, $is_pro, $limit )->send();
		}

		Plandose_Print_Result::ok( $row, $is_pro, $limit )->send();
	}

	/**
	 * Register one print. Called by the client just BEFORE printing;
	 * the sheet is only printed when this succeeds.
	 *
	 * guard → validated ids (Plandose_Print_Request) → subscription state →
	 * replay or free reprint, nothing charged (Plandose_Print_Replay) → a new
	 * charge under the print lock, in one transaction
	 * (Plandose_Print_New_Charge) → one answer (Plandose_Print_Result).
	 */
	public static function register_print() {
		$user_id = self::guard( self::RATE_LIMIT_BUCKET_REGISTER_PRINT );

		// Validated before the subscription state is read (which may reset
		// the month): a malformed token never touches the subscription row.
		$input = Plandose_Print_Request::read_post();

		if ( null === $input ) {
			Plandose_Print_Result::invalid_token()->send();
		}

		list( $row, $is_pro, $limit, $today ) = self::get_current_print_context( $user_id );

		$request = new Plandose_Print_Request( $user_id, $input['token'], $input['request_hash'], $row, $is_pro, $limit, $today );
		$result  = Plandose_Print_Replay::answer( $request );

		if ( null === $result ) {
			$result = Plandose_Print_New_Charge::charge( $request );
		}

		$result->send();
	}

	/**
	 * Forget a pharmacy's print receipts (privacy eraser).
	 *
	 * @param int $user_id Pharmacy user ID.
	 */
	public static function delete_print_receipts( $user_id ) {
		delete_user_meta( absint( $user_id ), self::PRINT_RECEIPT_META_KEY );
	}

	/**
	 * Return pharmacy/contact header data for the printable plan.
	 */
	public static function get_header() {
		$user_id = self::guard( self::RATE_LIMIT_BUCKET_HEADER );
		$user    = wp_get_current_user();

		$pharmacy_name = Plandose_Access::get_meta_with_fallback( $user_id, Plandose_Access::PHARMACY_NAME_META_KEYS );

		$phone_1 = Plandose_Access::get_meta_with_fallback(
			$user_id,
			array(
				'phone_1',
				'user_registration_phone_1',
				'billing_phone',
			)
		);

		$mobile_phone = Plandose_Access::get_meta_with_fallback(
			$user_id,
			array(
				'mobile_phone',
				'user_registration_mobile_phone',
				'mobile',
			)
		);

		$address = Plandose_Access::get_meta_with_fallback(
			$user_id,
			array(
				'pharmacy_address',
				'user_registration_pharmacy_address',
				'address',
				'billing_address_1',
			)
		);

		// No contactName / account_type — nothing in assets/js reads
		// them, and the header sheet needs only these. Meta another
		// plugin stored as an array is ignored, not printed as «Array»
		// (with a PHP notice).
		$text = static function ( $value ) {
			return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
		};
		$name = $text( $pharmacy_name );

		wp_send_json_success(
			array(
				'name'         => '' !== $name ? $name : sanitize_text_field( $user->display_name ),
				'phone_1'      => $text( $phone_1 ),
				'mobile_phone' => $text( $mobile_phone ),
				'address'      => $text( $address ),
				'email'        => sanitize_email( $user->user_email ),
			)
		);
	}
}