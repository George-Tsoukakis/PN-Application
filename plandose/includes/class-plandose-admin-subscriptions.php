<?php
/**
 * PlanDose admin: the subscriptions dashboard (Dashboard/Συνδρομές/Δωρεάν/Pro
 * screens), manual subscription actions (Pro/Free, manual access override),
 * CSV export, and the audit log screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plandose_Admin_Subscriptions {

	/**
	 * Prevents the subscriptions-admin hooks from being registered more than
	 * once. Defensive, matching the other PlanDose classes.
	 *
	 * @var bool
	 */
	private static $initialized = false;

	public static function init() {
		if ( self::$initialized ) {
			return;
		}

		self::$initialized = true;

		add_action( 'admin_notices', array( __CLASS__, 'render_action_notice' ) );
		add_action( 'admin_post_plandose_subscription_action', array( __CLASS__, 'handle_subscription_action' ) );
		add_action( 'admin_post_plandose_export_csv', array( __CLASS__, 'handle_export_csv' ) );
		add_action( 'wp_ajax_' . self::UNCOUNTED_AJAX_ACTION, array( __CLASS__, 'ajax_uncounted_users' ) );
	}

	/**
	 * Return a safe PlanDose admin redirect URL.
	 */
	private static function redirect_url() {
		$fallback = admin_url( 'admin.php?page=plandose-subscriptions' );
		$referer  = wp_get_referer();

		if ( ! $referer ) {
			return $fallback;
		}

		return wp_validate_redirect( $referer, $fallback );
	}

	/**
	 * Redirect after an admin action with a short result code.
	 */
	private static function redirect_with_result( $result ) {
		$url = add_query_arg(
			'plandose_result',
			sanitize_key( (string) $result ),
			self::redirect_url()
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Display the outcome of a subscription/admin action.
	 */
	public static function render_action_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display of an action-result notice keyed by our own admin-post redirect; the underlying action already verified its own nonce. Nothing here changes state.
		if ( ! isset( $_GET['plandose_result'] ) || ! current_user_can( Plandose_Admin::capability() ) ) {
			return;
		}

		$result = sanitize_key( wp_unslash( $_GET['plandose_result'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$map    = array(
			'success'      => array( 'success', __( 'Η ενέργεια ολοκληρώθηκε επιτυχώς.', 'plandose' ) ),
			'invalid_user' => array( 'error', __( 'Ο επιλεγμένος χρήστης δεν βρέθηκε.', 'plandose' ) ),
			'invalid_action' => array( 'error', __( 'Η ζητούμενη ενέργεια δεν είναι έγκυρη.', 'plandose' ) ),
			'invalid_days'   => array( 'error', __( 'Ο αριθμός ημερών δεν είναι έγκυρος: δώστε έναν ακέραιο αριθμό ημερών από 1 και πάνω. Δεν έγινε καμία αλλαγή.', 'plandose' ) ),
			'failed'       => array( 'error', __( 'Η ενέργεια δεν αποθηκεύτηκε. Δοκιμάστε ξανά και ελέγξτε το error log.', 'plandose' ) ),
			// Pro form submitted from an outdated page (double click, resubmit, another admin).
			'stale'        => array( 'warning', __( 'Η συνδρομή είχε ήδη αλλάξει (π.χ. διπλό πάτημα ή ενέργεια άλλου διαχειριστή), οπότε δεν προστέθηκαν ημέρες. Ελέγξτε τη νέα ημερομηνία λήξης και επαναλάβετε μόνο αν χρειάζεται.', 'plandose' ) ),
			'invoices_removed' => array( 'success', __( 'Τα τιμολόγια του διαγραμμένου λογαριασμού διαγράφηκαν οριστικά.', 'plandose' ) ),
			// Refusals under the pharmacy-only rule.
			'not_pharmacy'     => array( 'error', self::not_pharmacy_message() ),
			'allow_retired'    => array( 'error', __( 'Η χειροκίνητη έγκριση πρόσβασης καταργήθηκε: το PlanDose είναι διαθέσιμο μόνο σε λογαριασμούς με Κατηγορία «Φαρμακείο».', 'plandose' ) ),
		);

		if ( ! isset( $map[ $result ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $map[ $result ][0] ),
			esc_html( $map[ $result ][1] )
		);
	}

	/**
	 * The one message used wherever a subscription action is
	 * refused because the account is not a pharmacy. Kept in one place so
	 * the notice and the hint next to the disabled buttons say the same.
	 *
	 * @return string
	 */
	private static function not_pharmacy_message() {
		return __( 'Ο χρήστης δεν έχει Κατηγορία Επιχείρησης «Φαρμακείο». Ορίστε πρώτα την κατηγορία στο προφίλ του.', 'plandose' );
	}

	/**
	 * Strictly validate a Y-m-d date string from a request param.
	 *
	 * sanitize_text_field() alone still lets through arbitrary strings
	 * that strtotime() will happily (and sometimes surprisingly) parse
	 * as relative dates, which makes the expiry_from/expiry_to dashboard
	 * filters behave unpredictably. Returns '' for anything that isn't a
	 * real, calendar-valid Y-m-d date.
	 */
	private static function sanitize_ymd_date( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return Plandose_Settings::sanitize_ymd( sanitize_text_field( wp_unslash( (string) $value ) ) );
	}

	/**
	 * Resolve the actual number of Pro days a manual action will apply,
	 * clamped the same way Plandose_Subscriptions::grant_pro_days_if_unchanged()
	 * itself clamps add_days — so the dashboard button label, the value
	 * passed to grant_pro_days_if_unchanged(), and what gets written to the audit
	 * log always agree. Without this, default_pro_days (admin-configurable,
	 * no fixed ceiling) and max_add_days (a separate safety ceiling, default
	 * 1095) could disagree — e.g. default_pro_days = 3650 would show
	 * "Ενεργοποίηση Pro (3650 ημέρες)" and audit `days => 3650`, while
	 * only 1095 would be silently applied.
	 */
	private static function effective_pro_days( $requested_days = 0 ) {
		$requested_days = max( 0, (int) $requested_days );
		$days           = $requested_days > 0 ? $requested_days : (int) Plandose_Settings::setting( 'default_pro_days', 365 );

		return max( 1, min( Plandose_Settings::max_add_days(), $days ) );
	}

	/**
	 * How a card shows its end date: 'expiring' (red) exactly when the
	 * «Λήγουν Σύντομα» filter and KPI count it — 0 ≤ days_left ≤
	 * expiring_days() (Plandose_Subscriber_Query) — 'expired' (muted, with
	 * its own «έληξε πριν …» text) for a lapsed Pro, '' otherwise. A plain
	 * days_left <= N was also true for negative values, so lapsed cards
	 * turned red as "expiring" while the filter left them out.
	 *
	 * @param int|null $days_left Days until sub_end_date; null without one.
	 * @return string 'expiring', 'expired' or ''.
	 */
	private static function expiry_state( $days_left ) {
		if ( null === $days_left ) {
			return '';
		}

		$days_left = (int) $days_left;

		if ( $days_left < 0 ) {
			return 'expired';
		}

		return $days_left <= Plandose_Settings::expiring_days() ? 'expiring' : '';
	}

	public static function handle_subscription_action() {
		if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			wp_die( esc_html__( 'Μη επιτρεπτή μέθοδος αιτήματος.', 'plandose' ), '', array( 'response' => 405 ) );
		}

		if ( ! current_user_can( Plandose_Admin::capability() ) ) {
			wp_die( esc_html__( 'Δεν έχετε δικαίωμα πρόσβασης.', 'plandose' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'plandose_subscription_action' );

		$user_id     = isset( $_POST['user_id'] ) ? Plandose_Admin_Invoices::parse_user_id( wp_unslash( $_POST['user_id'] ) ) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- digits only (parse_user_id()).
		$action      = isset( $_POST['pd_action'] ) ? sanitize_key( wp_unslash( $_POST['pd_action'] ) ) : '';
		// null = no field at all (the configured default applies); anything
		// sent is validated below rather than turned into 0 → default.
		$custom_days = isset( $_POST['custom_days'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['custom_days'] ) ) ) : null;
		/*
		 * 'allow_access' is not an action. Under the pharmacy-only rule a
		 * manual "allow" grants nothing (see
		 * Plandose_Access::access_evaluation()), so storing a new one would
		 * only record a decision that has no effect. Clearing an old one
		 * ('reset_access') and blocking a pharmacy ('deny_access') remain.
		 */
		$allowed     = array( 'make_pro', 'extend', 'make_free', 'deny_access', 'reset_access' );

		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			self::redirect_with_result( 'invalid_user' );
		}

		// The general PlanDose capability governs access to the dashboard,
		// but changing a *specific* account additionally requires
		// edit_user on it, and never one's own account or an
		// administrator's unless one is an administrator — see
		// Plandose_Admin::can_manage_account().
		if ( ! Plandose_Admin::can_manage_account( $user_id ) ) {
			wp_die( esc_html__( 'Δεν έχετε δικαίωμα να επεξεργαστείτε αυτόν τον χρήστη.', 'plandose' ), '', array( 'response' => 403 ) );
		}

		if ( 'allow_access' === $action ) {
			self::redirect_with_result( 'allow_retired' );
		}

		if ( ! in_array( $action, $allowed, true ) ) {
			self::redirect_with_result( 'invalid_action' );
		}

		/*
		 * Activating or extending Pro creates/changes a
		 * subscription, which only a «Φαρμακείο» account may hold. Refused
		 * here with an explanation rather than left to the backstop in
		 * Plandose_Subscriptions::grant_pro_days_if_unchanged(), whose bare 'failed'
		 * would read as the generic «δεν αποθηκεύτηκε» database error.
		 * make_free stays open to everyone so a legacy Pro row on a
		 * non-pharmacy can still be closed.
		 *
		 * deny_access is refused the same way: a non-pharmacy has no
		 * access to block (Plandose_Access::access_evaluation()), and the
		 * dashboard renders that button disabled for one (action_button()).
		 * reset_access stays open, so a leftover override on a
		 * non-pharmacy can still be cleared.
		 */
		if ( in_array( $action, array( 'make_pro', 'extend', 'deny_access' ), true ) && ! Plandose_Access::is_registered_pharmacist( $user_id ) ) {
			self::redirect_with_result( 'not_pharmacy' );
		}

		/*
		 * An explicit 0, a negative, a fraction or an emptied field is a
		 * typing mistake, not a request for the default: silently granting
		 * default_pro_days (365) instead would hand out a year the admin
		 * never asked for. Refused with a notice. Only a form without the
		 * field gets the default; a number above max_add_days() is still
		 * clamped by effective_pro_days() as before.
		 */
		if ( in_array( $action, array( 'make_pro', 'extend' ), true ) && null !== $custom_days && ( ! preg_match( '/^[0-9]+$/', $custom_days ) || (int) $custom_days < 1 ) ) {
			self::redirect_with_result( 'invalid_days' );
		}

		$success = false;
		$days    = self::effective_pro_days( null === $custom_days ? 0 : (int) $custom_days );

		if ( 'make_pro' === $action || 'extend' === $action ) {
			/*
			 * The form carries the state it was rendered from
			 * (Plandose_Subscriptions::state_token()). The days are granted
			 * only if the subscription is still in that state, so a double
			 * submit, a browser resubmit or two admins on the same page
			 * cannot add the days twice (365 → 730). A form without the
			 * field (a page cached from before 1.22.3) is refused the same
			 * way: the admin reloads and sees the current end date first.
			 */
			$expected = isset( $_POST['expected_state'] ) ? sanitize_text_field( wp_unslash( $_POST['expected_state'] ) ) : '';

			if ( '' === $expected ) {
				self::redirect_with_result( 'stale' );
			}

			$before  = Plandose_Subscriptions::get_row( $user_id, false );
			$outcome = Plandose_Subscriptions::grant_pro_days_if_unchanged( $user_id, $days, $expected );

			if ( 'stale' === $outcome ) {
				self::redirect_with_result( 'stale' );
			}

			$success = 'updated' === $outcome;

			if ( $success ) {
				// The end date before and after, so any grant can
				// be traced and undone from the log.
				$after = Plandose_Subscriptions::get_row( $user_id, false );
				Plandose_Admin::audit(
					$action,
					$user_id,
					array(
						'days'       => $days,
						'old_status' => $before ? (string) $before->status : 'free',
						'old_end'    => $before && ! empty( $before->sub_end_date ) ? (string) $before->sub_end_date : '',
						'new_end'    => $after && ! empty( $after->sub_end_date ) ? (string) $after->sub_end_date : '',
					)
				);
			}
		} elseif ( 'make_free' === $action ) {
			/*
			 * Guarded like make_pro/extend (expected_state), and the audit
			 * keeps the end date that was removed, so a wrong click does
			 * not lose the paid end date without a trace.
			 */
			$expected = isset( $_POST['expected_state'] ) ? sanitize_text_field( wp_unslash( $_POST['expected_state'] ) ) : '';

			if ( '' === $expected ) {
				self::redirect_with_result( 'stale' );
			}

			list( $outcome, $before ) = Plandose_Subscriptions::make_free_if_unchanged( $user_id, $expected );

			if ( 'stale' === $outcome ) {
				self::redirect_with_result( 'stale' );
			}

			// Already Free: nothing changed, nothing to audit.
			$success = 'updated' === $outcome || 'unchanged' === $outcome;

			if ( 'updated' === $outcome ) {
				Plandose_Admin::audit(
					'make_free',
					$user_id,
					array(
						'old_status' => $before ? (string) $before->status : '',
						'old_end'    => $before && ! empty( $before->sub_end_date ) ? (string) $before->sub_end_date : '',
					)
				);
			}
		} else {
			$override = array(
				'deny_access'  => 'deny',
				'reset_access' => '',
			);

			$success = Plandose_Access::set_manual_access( $user_id, $override[ $action ] );

			if ( $success ) {
				Plandose_Admin::audit( $action, $user_id );
			}
		}

		self::redirect_with_result( $success ? 'success' : 'failed' );
	}

	public static function handle_export_csv() {
		if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			wp_die( esc_html__( 'Μη επιτρεπτή μέθοδος αιτήματος.', 'plandose' ), '', array( 'response' => 405 ) );
		}

		// The export carries every pharmacy's email, mobile and AFM.
		if ( ! Plandose_Admin::can_view_accounts() ) {
			wp_die( esc_html__( 'Δεν έχετε δικαίωμα πρόσβασης.', 'plandose' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'plandose_export_csv' );

		if ( headers_sent() ) {
			wp_die( esc_html__( 'Δεν είναι δυνατή η έναρξη της εξαγωγής CSV επειδή έχουν ήδη σταλεί δεδομένα εξόδου.', 'plandose' ), '', array( 'response' => 500 ) );
		}

		// Logged BEFORE streaming. When the browser disconnects
		// mid-download PHP stops at the next flush(), so the completion
		// entry below is never written — a partial download of personal
		// data would otherwise leave no trace at all.
		Plandose_Admin::audit( 'export_subscribers_csv_started', 0 );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="plandose-subscribers.csv"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow, noarchive' );

		$out = fopen( 'php://output', 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- CSV streamed to php://output; WP_Filesystem cannot write there.

		if ( false === $out ) {
			wp_die( esc_html__( 'Δεν ήταν δυνατό να ανοίξει το αρχείο εξαγωγής.', 'plandose' ), '', array( 'response' => 500 ) );
		}

		// Excel on Windows recognizes UTF-8 CSVs reliably when a BOM exists.
		fwrite( $out, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CSV streamed to php://output; WP_Filesystem cannot write there.
		/*
		 * The last three arguments are passed explicitly on purpose.
		 * PHP 8.4 deprecates relying on the default $escape value, so
		 * every call without them emits a deprecation notice into the
		 * error log on a modern host. Passing '' also disables PHP's
		 * proprietary backslash-escaping, which was never part of RFC
		 * 4180 and which Excel and Google Sheets do not implement — so
		 * this is the more correct behaviour as well as the quieter one.
		 * The separator and enclosure are the same values the default
		 * would have used, so existing exports are byte-identical unless
		 * a field actually contained a backslash.
		 */
		fputcsv(
			$out,
			array(
				'User ID',
				'Pharmacy',
				'Mobile',
				'AFM',
				'Name',
				'Email',
				'Status',
				'End Date',
				'Prints',
				'Last Print',
				'Prev Month Prints',
				'Prints Last 12m',
				'Active Months 12m',
			),
			',',
			'"',
			''
		);

		// Remove active output buffers so each completed batch can be sent to
		// the client instead of accumulating in PHP memory.
		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}

		if ( function_exists( 'session_write_close' ) ) {
			@session_write_close(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- no active session is normal; this only releases the session lock.
		}

		self::raise_export_limits();

		$after_id   = 0;
		$batch_size = min( 500, Plandose_Subscriber_Query::MAX_SUBSCRIBER_PAGE_SIZE );
		$written    = 0;

		do {
			// Keyset batches by user ID (see query_subscribers()): an
			// OFFSET over the name order would skip or repeat a pharmacy
			// renamed, added or removed while the export runs.
			$result = Plandose_Subscriber_Query::query_subscribers(
				array(
					'after_id'   => $after_id,
					'per_page'   => $batch_size,
					'with_total' => false,
				)
			);

			if ( ! is_array( $result ) || ! isset( $result['items'] ) || ! is_array( $result['items'] ) ) {
				fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- CSV streamed to php://output; WP_Filesystem cannot write there.
				exit;
			}

			$items = $result['items'];

			/*
			 * One history query per batch, not per row. The export is the
			 * whole point of keeping this table: sorted on "Prints Last
			 * 12m" it is the list of pharmacies actually using the tool.
			 */
			$batch_user_ids = array();

			foreach ( $items as $item ) {
				if ( isset( $item->user->ID ) ) {
					$batch_user_ids[] = (int) $item->user->ID;
				}
			}

			$batch_history = Plandose_Subscriptions::get_print_history_for_users( $batch_user_ids, 12 );

			foreach ( $items as $item ) {
				$item_id      = isset( $item->user->ID ) ? (int) $item->user->ID : 0;
				$item_history = isset( $batch_history[ $item_id ] ) ? $batch_history[ $item_id ] : array();

				// Explicit separator/enclosure/escape — see the note on the
				// header row above.
				fputcsv( $out, self::csv_row_from_item( $item, $item_history ), ',', '"', '' );
				++$written;
			}

			fflush( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fflush -- CSV streamed to php://output; WP_Filesystem cannot write there.
			flush();

			if ( connection_aborted() ) {
				break;
			}

			$last_id = isset( $result['last_id'] ) ? (int) $result['last_id'] : 0;

			/*
			 * Continue while the batch had any IDs, rather than while it
			 * returned a full batch of items — and decided on the IDs, not
			 * on the items.
			 *
			 * hydrate_subscriber_items() skips any ID whose user row
			 * get_users() did not return — filtered out by another plugin,
			 * or deleted between the two queries — so a full batch of IDs
			 * can legitimately come back as 499 items (or none at all).
			 * Stopping on a short or empty item list would read that as
			 * "the list has ended", silently truncating the export with
			 * nothing on screen to say so — the same failure the docblock
			 * on Plandose_Subscriber_Query::stats() describes.
			 *
			 * The run ends when a batch past the last ID comes back empty
			 * (last_id 0) — at the cost of one extra query when the row
			 * count is an exact multiple of the batch size.
			 */
			$more     = $last_id > $after_id;
			$after_id = $last_id;
		} while ( $more );

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- CSV streamed to php://output; WP_Filesystem cannot write there.

		if ( $written > 0 ) {
			Plandose_Admin::audit(
				'export_subscribers_csv',
				0,
				array( 'rows' => $written )
			);
		}

		exit;
	}

	/**
	 * Give the CSV export room on a large site: the admin memory limit
	 * (WP_MAX_MEMORY_LIMIT) and no execution time limit, where the host
	 * allows them. Best effort — a host that disables set_time_limit()
	 * keeps its own limit.
	 */
	private static function raise_export_limits() {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}

		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );

		if ( function_exists( 'set_time_limit' ) && ! in_array( 'set_time_limit', $disabled, true ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- a long export on a large site; a host that forbids it only warns.
		}
	}

	/**
	 * Convert one hydrated subscriber item into a CSV row.
	 *
	 * Kept separate from handle_export_csv() so batch/export tests can verify
	 * field ordering and formula-injection protection without invoking headers
	 * or terminating the PHP process.
	 *
	 * $history is optional so the single-argument call still produces a
	 * valid row (the history columns come out as 0) — callers that have not
	 * fetched history do not have to.
	 *
	 * @param object $item    Hydrated subscriber item.
	 * @param array  $history 'YYYY-MM' => prints for this pharmacy.
	 * @return array
	 */
	public static function csv_row_from_item( $item, $history = array() ) {
		$history = is_array( $history ) ? $history : array();

		$previous_months = Plandose_Subscriptions::recent_months( 1 );
		$previous_month  = isset( $previous_months[0] ) ? $previous_months[0] : '';

		$previous_prints = isset( $history[ $previous_month ] ) ? (int) $history[ $previous_month ] : 0;
		$total_prints    = array_sum( array_map( 'intval', $history ) );

		/*
		 * Months with at least one print. Two pharmacies can share a
		 * 12-month total and be nothing alike: one printed steadily all
		 * year, the other did it once and stopped.
		 */
		$active_months = count(
			array_filter(
				$history,
				static function ( $value ) {
					return (int) $value > 0;
				}
			)
		);

		return array(
			isset( $item->user->ID ) ? (int) $item->user->ID : 0,
			self::csv_safe( isset( $item->pharmacy ) ? $item->pharmacy : '' ),
			self::csv_safe( isset( $item->mobile ) ? $item->mobile : '' ),
			self::csv_safe( isset( $item->afm ) ? $item->afm : '' ),
			self::csv_safe( isset( $item->user->display_name ) ? $item->user->display_name : '' ),
			self::csv_safe( isset( $item->user->user_email ) ? $item->user->user_email : '' ),
			! empty( $item->is_pro ) ? 'Pro' : 'Free',
			isset( $item->row->sub_end_date ) ? $item->row->sub_end_date : '',
			// The running month only — see current_month_count().
			isset( $item->row ) ? Plandose_Subscriptions::current_month_count( $item->row ) : 0,
			isset( $item->row->last_print_at ) ? $item->row->last_print_at : '',
			$previous_prints,
			$total_prints,
			$active_months,
		);
	}

	/**
	 * Neutralize CSV/Excel/Sheets formula injection.
	 *
	 * These fields (pharmacy name, mobile, AFM, display name, email) come
	 * from user-editable profile meta. If a value starts with =, +, -, @,
	 * or a tab/CR control character, spreadsheet apps can interpret it as a
	 * formula when the CSV is opened. We defuse it by prefixing a single
	 * quote, which spreadsheet apps treat as "force text".
	 */
	private static function csv_safe( $value ) {
		$value = str_replace( "\0", '', (string) $value );

		if ( '' === $value ) {
			return $value;
		}

		$trimmed = ltrim( $value, " \t\r\n" );

		if ( '' !== $trimmed && in_array( $trimmed[0], array( '=', '+', '-', '@' ), true ) ) {
			return "'" . $value;
		}

		if ( in_array( $value[0], array( "\t", "\r", "\n" ), true ) ) {
			return "'" . $value;
		}

		return $value;
	}

	public static function render_dashboard_page() {
		self::render_subscriptions_page();
	}

	/**
	 * Set by render_free_page()/render_pro_page() to pin the list to one
	 * status.
	 *
	 * Not written into $_GET: the filter card's hidden `page` field carries
	 * the CURRENT screen, so submitting it from Δωρεάν would reload
	 * plandose-free, which would overwrite $_GET['status'] again and make
	 * the Κατάσταση dropdown inert on exactly the two screens where it
	 * looks most useful.
	 *
	 * Held in a property instead, so filter_target_page() below can send
	 * the form to the general Συνδρομές screen, where a chosen status is
	 * honoured.
	 *
	 * @var string '' | 'free' | 'pro'
	 */
	private static $forced_status = '';

	public static function render_free_page() {
		self::$forced_status = 'free';
		self::render_subscriptions_page( __( 'PlanDose → Δωρεάν Συνδρομητές', 'plandose' ) );
	}

	public static function render_pro_page() {
		self::$forced_status = 'pro';
		self::render_subscriptions_page( __( 'PlanDose → Pro Συνδρομητές', 'plandose' ) );
	}

	/**
	 * Which admin page the filter card submits to.
	 *
	 * On a status-pinned screen that is the general Συνδρομές screen, since
	 * the pinned one would discard the choice. Everywhere else it is simply
	 * the current page. See $forced_status above.
	 *
	 * @return string Admin page slug.
	 */
	private static function filter_target_page() {
		if ( '' !== self::$forced_status ) {
			return 'plandose-subscriptions';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: echoes the current admin page slug back into the filter form; no state change.
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'plandose-subscriptions';
	}

	public static function render_subscriptions_page( $title = '' ) {
		if ( ! current_user_can( Plandose_Admin::capability() ) ) {
			wp_die( esc_html__( 'Δεν έχετε δικαίωμα πρόσβασης.', 'plandose' ) );
		}

		// The lists show every pharmacy's contact and tax details.
		if ( ! Plandose_Admin::can_view_accounts() ) {
			wp_die( esc_html__( 'Η λίστα συνδρομητών περιέχει στοιχεία επικοινωνίας και ΑΦΜ όλων των φαρμακείων και χρειάζεται επιπλέον το δικαίωμα «list_users».', 'plandose' ), '', array( 'response' => 403 ) );
		}

		$title          = $title ? $title : __( 'PlanDose → Συνδρομές', 'plandose' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin list-table filters below: these GET params only narrow which rows are displayed and trigger no state change, mirroring core WP_List_Table behaviour. Each value is validated/sanitized before use.
		$status         = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all';
		$status         = in_array( $status, array( 'all', 'free', 'pro', 'expiring' ), true ) ? $status : 'all';
		$status         = '' !== self::$forced_status ? self::$forced_status : $status;
		$search         = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$invoice_status = isset( $_GET['invoice_status'] ) ? sanitize_key( wp_unslash( $_GET['invoice_status'] ) ) : 'all';
		$invoice_status = in_array( $invoice_status, array( 'all', 'has', 'missing' ), true ) ? $invoice_status : 'all';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_ymd_date() unslashes and sanitizes internally and returns only a validated Y-m-d string or ''.
		$expiry_from    = isset( $_GET['expiry_from'] ) ? self::sanitize_ymd_date( $_GET['expiry_from'] ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_ymd_date() unslashes and sanitizes internally and returns only a validated Y-m-d string or ''.
		$expiry_to      = isset( $_GET['expiry_to'] ) ? self::sanitize_ymd_date( $_GET['expiry_to'] ) : '';
		$paged          = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$per_page       = isset( $_GET['per_page'] ) ? max( 5, min( 100, absint( $_GET['per_page'] ) ) ) : 20;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( $expiry_from && $expiry_to && $expiry_from > $expiry_to ) {
			$temp        = $expiry_from;
			$expiry_from = $expiry_to;
			$expiry_to   = $temp;
		}

		$stats  = Plandose_Subscriber_Query::stats();
		$result = Plandose_Subscriber_Query::query_subscribers( array( 'status' => $status, 'search' => $search, 'invoice_status' => $invoice_status, 'expiry_from' => $expiry_from, 'expiry_to' => $expiry_to, 'paged' => $paged, 'per_page' => $per_page ) );

		// A page past the end (stale link, fewer results after a
		// filter change) shows the last page instead of an empty list.
		$last_page = max( 1, (int) ceil( (int) $result['total'] / $per_page ) );

		if ( $paged > $last_page ) {
			$paged  = $last_page;
			$result = Plandose_Subscriber_Query::query_subscribers( array( 'status' => $status, 'search' => $search, 'invoice_status' => $invoice_status, 'expiry_from' => $expiry_from, 'expiry_to' => $expiry_to, 'paged' => $paged, 'per_page' => $per_page ) );
		}

		/*
		 * "Εκτυπώσεις Μήνα" alone says nothing about direction: early in a
		 * month it is always low and always looks like a collapse. The
		 * closed previous month is the figure that can actually be
		 * compared, and "ενεργά φαρμακεία" separates real adoption from
		 * the subscriber count — a handful of pharmacies printing a lot
		 * is a very different business from all of them printing once.
		 */
		$months          = Plandose_Subscriptions::recent_months( 1 );
		$previous_month  = isset( $months[0] ) ? $months[0] : '';
		$previous_totals = Plandose_Subscriptions::month_summary( $previous_month );

		/*
		 * One query for the whole page instead of one per card. Six months
		 * is what the card strip shows.
		 */
		$page_user_ids = array();

		if ( ! empty( $result['items'] ) && is_array( $result['items'] ) ) {
			foreach ( $result['items'] as $history_item ) {
				if ( isset( $history_item->user->ID ) ) {
					$page_user_ids[] = (int) $history_item->user->ID;
				}
			}
		}

		$page_history = Plandose_Subscriptions::get_print_history_for_users( $page_user_ids, 6 );

		/*
		 * Prime the user cache for this page once. hydrate_subscriber_items()
		 * fetches only a few user fields (no WP_User objects), so every
		 * card's can_manage_account() / user_can() / get_edit_user_link()
		 * would otherwise load its user with a query of its own.
		 */
		if ( $page_user_ids ) {
			cache_users( $page_user_ids );
		}
		$history_months = Plandose_Subscriptions::recent_months( 6 );

		$tabs   = array(
			'all'      => __( 'Όλοι', 'plandose' ),
			'free'     => __( 'Δωρεάν', 'plandose' ),
			'pro'      => 'Pro',
			'expiring' => __( 'Λήγουν Σύντομα', 'plandose' ),
		);

		Plandose_Admin::page_header(
			$title,
			__( 'Διαχειριστείτε όλες τις συνδρομές των φαρμακείων και παρακολουθήστε τη χρήση και τις λήξεις.', 'plandose' ),
			static function () {
				?>
				<form class="plandose-export-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="plandose_export_csv" />
					<?php wp_nonce_field( 'plandose_export_csv' ); ?>
					<button type="submit" class="button">↧ <?php esc_html_e( 'Εξαγωγή CSV', 'plandose' ); ?></button>
				</form>
				<?php
			}
		);
		?>
		<div class="plandose-kpis">
			<?php self::kpi( '👥', __( 'Σύνολο Συνδρομητών', 'plandose' ), number_format_i18n( $stats['total'] ) ); ?>
			<?php self::kpi( '🟢', __( 'Δωρεάν', 'plandose' ), number_format_i18n( $stats['free'] ) ); ?>
			<?php self::kpi( '👑', __( 'Pro Ενεργές', 'plandose' ), number_format_i18n( $stats['pro'] ) ); ?>
			<?php self::kpi( '🕒', __( 'Λήγουν Σύντομα', 'plandose' ), number_format_i18n( $stats['expiring'] ) ); ?>
			<?php self::kpi( '🖨️', __( 'Εκτυπώσεις Μήνα', 'plandose' ), number_format_i18n( $stats['prints'] ) ); ?>
			<?php
			self::kpi(
				'📆',
				/* translators: %s: previous calendar month, e.g. 2026-08 */
				sprintf( __( 'Εκτυπώσεις %s', 'plandose' ), $previous_month ),
				number_format_i18n( $previous_totals['prints'] )
			);
			?>
			<?php
			self::kpi(
				'🏥',
				/* translators: %s: previous calendar month, e.g. 2026-08 */
				sprintf( __( 'Ενεργά φαρμακεία %s', 'plandose' ), $previous_month ),
				number_format_i18n( $previous_totals['pharmacies'] )
			);
			?>
		</div>

		<form class="plandose-filter-card" method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::filter_target_page() ); ?>" />

			<label class="plandose-filter-field plandose-filter-search">
				<span><?php esc_html_e( 'Αναζήτηση', 'plandose' ); ?></span>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Φαρμακείο, όνομα, email, ΑΦΜ…', 'plandose' ); ?>" />
			</label>

			<label class="plandose-filter-field">
				<span><?php esc_html_e( 'Κατάσταση', 'plandose' ); ?></span>
				<select name="status">
					<option value="all" <?php selected( $status, 'all' ); ?>><?php esc_html_e( 'Όλες οι καταστάσεις', 'plandose' ); ?></option>
					<option value="free" <?php selected( $status, 'free' ); ?>><?php esc_html_e( 'Δωρεάν', 'plandose' ); ?></option>
					<option value="pro" <?php selected( $status, 'pro' ); ?>>Pro</option>
					<option value="expiring" <?php selected( $status, 'expiring' ); ?>><?php esc_html_e( 'Λήγουν σύντομα', 'plandose' ); ?></option>
				</select>
			</label>

			<label class="plandose-filter-field">
				<span><?php esc_html_e( 'Τιμολόγιο', 'plandose' ); ?></span>
				<select name="invoice_status">
					<option value="all" <?php selected( $invoice_status, 'all' ); ?>><?php esc_html_e( 'Όλα τα τιμολόγια', 'plandose' ); ?></option>
					<option value="has" <?php selected( $invoice_status, 'has' ); ?>><?php esc_html_e( 'Με τιμολόγιο', 'plandose' ); ?></option>
					<option value="missing" <?php selected( $invoice_status, 'missing' ); ?>><?php esc_html_e( 'Εκκρεμή τιμολόγια', 'plandose' ); ?></option>
				</select>
			</label>

			<label class="plandose-filter-field">
				<span><?php esc_html_e( 'Λήξη από', 'plandose' ); ?></span>
				<input type="date" name="expiry_from" value="<?php echo esc_attr( $expiry_from ); ?>" />
			</label>

			<label class="plandose-filter-field">
				<span><?php esc_html_e( 'Λήξη έως', 'plandose' ); ?></span>
				<input type="date" name="expiry_to" value="<?php echo esc_attr( $expiry_to ); ?>" />
			</label>

			<label class="plandose-filter-field">
				<span><?php esc_html_e( 'Ανά σελίδα', 'plandose' ); ?></span>
				<select name="per_page">
					<option value="20" <?php selected( $per_page, 20 ); ?>>20</option>
					<option value="50" <?php selected( $per_page, 50 ); ?>>50</option>
					<option value="100" <?php selected( $per_page, 100 ); ?>>100</option>
				</select>
			</label>

			<div class="plandose-filter-actions">
				<button class="button button-primary"><?php esc_html_e( 'Εφαρμογή', 'plandose' ); ?></button>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=plandose-subscriptions' ) ); ?>"><?php esc_html_e( 'Καθαρισμός', 'plandose' ); ?></a>
			</div>
		</form>

		<?php self::render_manual_access_lookup(); ?>

		<div class="plandose-main-card plandose-main-card-full">
				<div class="plandose-tabs">
					<?php foreach ( $tabs as $key => $label ) : ?>
						<?php
						// Switching tab must not silently discard the search term or
						// the other filters the admin just set; only the page resets.
						$tab_url = add_query_arg(
							array_filter(
								array(
									'page'           => 'plandose-subscriptions',
									'status'         => $key,
									's'              => $search,
									'invoice_status' => 'all' === $invoice_status ? '' : $invoice_status,
									'expiry_from'    => $expiry_from,
									'expiry_to'      => $expiry_to,
									'per_page'       => 20 === $per_page ? '' : $per_page,
								),
								static function ( $value ) {
									return '' !== $value && null !== $value;
								}
							),
							admin_url( 'admin.php' )
						);
						?>
						<a class="<?php echo $status === $key ? 'active' : ''; ?>" href="<?php echo esc_url( $tab_url ); ?>"><?php echo esc_html( $label ); ?></a>
					<?php endforeach; ?>
				</div>
				<?php if ( empty( $result['items'] ) ) : ?>
					<p class="plandose-empty">
						<?php
						if ( $stats['total'] > 0 ) {
							esc_html_e( 'Κανένας συνδρομητής δεν ταιριάζει με τα φίλτρα που έχετε επιλέξει.', 'plandose' );
						} else {
							esc_html_e( 'Δεν βρέθηκαν συνδρομητές.', 'plandose' );
						}
						?>
					</p>
				<?php else : ?>
				<div class="plandose-cards">
				<?php foreach ( $result['items'] as $item ) :
					$invoice_list = Plandose_Subscriptions::decode_invoices( $item->row->invoices );
					$expiry_state = self::expiry_state( $item->days_left );
					?>
					<article class="plandose-card<?php echo $item->is_pro ? ' is-pro' : ' is-free'; ?>">
						<header class="pd-card-head">
							<div class="pd-card-title">
								<h3><?php echo esc_html( $item->pharmacy ); ?></h3>
								<?php if ( ! empty( $item->city ) ) : ?><span class="pd-muted"><?php echo esc_html( $item->city ); ?></span><?php endif; ?>
							</div>
							<span class="pd-badge <?php echo $item->is_pro ? 'pro' : 'free'; ?>"><?php echo $item->is_pro ? 'Pro' : 'Free'; ?></span>
						</header>

						<div class="pd-card-contact">
							<?php
							/*
							 * On most accounts the WordPress display name IS the
							 * pharmacy name, so printing both just showed the same
							 * text twice. Only show it when it actually differs.
							 */
							$contact_name = trim( (string) $item->user->display_name );
							if ( '' !== $contact_name && 0 !== strcasecmp( $contact_name, trim( (string) $item->pharmacy ) ) ) :
								?>
								<span class="pd-contact-name"><?php echo esc_html( $contact_name ); ?></span>
							<?php endif; ?>
							<a href="mailto:<?php echo esc_attr( $item->user->user_email ); ?>"><?php echo esc_html( $item->user->user_email ); ?></a>
							<?php if ( ! empty( $item->mobile ) ) : ?><span class="pd-muted">📱 <?php echo esc_html( $item->mobile ); ?></span><?php endif; ?>
							<?php if ( ! empty( $item->afm ) ) : ?><span class="pd-muted"><?php esc_html_e( 'ΑΦΜ:', 'plandose' ); ?> <?php echo esc_html( $item->afm ); ?></span><?php endif; ?>
						</div>

						<dl class="pd-card-facts">
							<div>
								<dt><?php esc_html_e( 'Λήξη', 'plandose' ); ?></dt>
								<dd<?php echo 'expiring' === $expiry_state ? ' class="pd-danger"' : ( 'expired' === $expiry_state ? ' class="pd-muted"' : '' ); ?>>
									<?php
									/*
									 * sub_end_date is the LAST day the subscription is
									 * valid, not the day it stops: is_pro_active()
									 * accepts $end >= today. Granting 365 days
									 * on 2026-09-03 therefore sets it to 2027-09-02 —
									 * the grant day counts as day 1, so the pharmacy
									 * gets exactly 365 days (see
									 * Plandose_Subscriptions::pro_end_date_for()).
									 *
									 * So days_left === 0 means "expires tonight"
									 * while the badge still correctly reads Pro; "σε 0
									 * ημέρες" would read as already gone, and negative
									 * values (lapsed) must not look like tonight either.
									 * Three distinct states, three messages.
									 */
									if ( null === $item->days_left ) {
										esc_html_e( 'Δωρεάν', 'plandose' );
									} elseif ( $item->days_left < 0 ) {
										$days_gone = absint( $item->days_left );
										echo esc_html(
											sprintf(
												/* translators: %d: days since the subscription expired. */
												_n( 'έληξε πριν %d ημέρα', 'έληξε πριν %d ημέρες', $days_gone, 'plandose' ),
												$days_gone
											)
										);
									} elseif ( 0 === $item->days_left ) {
										esc_html_e( 'λήγει σήμερα', 'plandose' );
									} else {
										echo esc_html(
											sprintf(
												/* translators: %d: number of days left until the subscription expires. */
												_n( 'σε %d ημέρα', 'σε %d ημέρες', $item->days_left, 'plandose' ),
												$item->days_left
											)
										);
									}
									?>
								</dd>
								<?php if ( $item->row->sub_end_date ) : ?><dd class="pd-muted"><?php echo esc_html( $item->row->sub_end_date ); ?></dd><?php endif; ?>
							</div>
							<div>
								<dt><?php esc_html_e( 'Εκτυπώσεις', 'plandose' ); ?></dt>
								<?php // This month only — a counter the monthly reset has not reached yet still holds last month's total. ?>
								<dd><?php echo esc_html( number_format_i18n( Plandose_Subscriptions::current_month_count( $item->row ) ) ); ?></dd>
								<dd class="pd-muted"><?php echo ! empty( $item->row->last_print_at ) ? esc_html( $item->row->last_print_at ) : esc_html__( 'Καμία εκτύπωση', 'plandose' ); ?></dd>
							</div>
							<div>
								<dt><?php esc_html_e( 'Τιμολόγιο', 'plandose' ); ?></dt>
								<dd><?php echo $invoice_list ? '<span class="pd-badge paid">' . esc_html__( 'Υπάρχει', 'plandose' ) . '</span>' : '<span class="pd-badge pending">' . esc_html__( 'Εκκρεμές', 'plandose' ) . '</span>'; ?></dd>
							</div>
						</dl>

						<?php
						/*
						 * The six closed months before this one. A month with
						 * no row is shown as 0 rather than skipped, so the
						 * shape of the series is honest: a gap in the middle
						 * of the strip is exactly the signal worth seeing.
						 *
						 * Read left to right, oldest first, which is how a
						 * trend reads. recent_months() returns newest first.
						 */
						$user_history  = isset( $page_history[ (int) $item->user->ID ] ) ? $page_history[ (int) $item->user->ID ] : array();
						$strip_months  = array_reverse( $history_months );
						$history_total = array_sum( $user_history );
						?>
						<div class="pd-card-history">
							<span class="pd-card-history-label">
								<?php esc_html_e( 'Ιστορικό 6 μηνών', 'plandose' ); ?>
								<strong><?php echo esc_html( number_format_i18n( $history_total ) ); ?></strong>
								<?php if ( class_exists( 'Plandose_Admin_Prints' ) ) : ?>
									<a class="pd-card-prints-link" href="<?php echo esc_url( Plandose_Admin_Prints::url( (int) $item->user->ID ) ); ?>"><?php esc_html_e( 'Όλες οι εκτυπώσεις', 'plandose' ); ?></a>
								<?php endif; ?>
							</span>
							<ul class="pd-history-strip">
								<?php foreach ( $strip_months as $strip_ym ) : ?>
									<?php $strip_value = isset( $user_history[ $strip_ym ] ) ? (int) $user_history[ $strip_ym ] : 0; ?>
									<li<?php echo 0 === $strip_value ? ' class="pd-history-empty"' : ''; ?>>
										<strong><?php echo esc_html( number_format_i18n( $strip_value ) ); ?></strong>
										<em><?php echo esc_html( substr( $strip_ym, 5, 2 ) . '/' . substr( $strip_ym, 2, 2 ) ); ?></em>
									</li>
								<?php endforeach; ?>
							</ul>
						</div>

						<?php if ( Plandose_Admin::can_manage_account( $item->user->ID ) ) : ?>
						<details class="pd-card-manage">
							<summary><?php esc_html_e( 'Διαχείριση', 'plandose' ); ?></summary>
							<div class="pd-card-manage-body">
								<section class="pd-manage-section">
									<h4 class="pd-manage-title"><?php esc_html_e( 'Συνδρομή', 'plandose' ); ?></h4>
									<?php self::pro_action_form( $item->user->ID, $item->is_pro, Plandose_Subscriptions::state_token( $item->row ) ); ?>
									<?php if ( $item->is_pro ) : ?>
										<div class="pd-manage-grid pd-manage-grid-one">
											<?php self::action_button( $item->user->ID, 'make_free', __( 'Μετατροπή σε Free', 'plandose' ), '', Plandose_Subscriptions::state_token( $item->row ) ); ?>
										</div>
									<?php endif; ?>
								</section>

								<section class="pd-manage-section">
									<h4 class="pd-manage-title"><?php esc_html_e( 'Πρόσβαση', 'plandose' ); ?></h4>
									<?php self::access_override_control( $item->user->ID ); ?>
								</section>

								<section class="pd-manage-section">
									<h4 class="pd-manage-title"><?php esc_html_e( 'Χρήστης &amp; αρχεία', 'plandose' ); ?></h4>
									<div class="pd-manage-grid">
										<a class="button" href="<?php echo esc_url( get_edit_user_link( $item->user->ID ) ); ?>"><?php esc_html_e( 'Προβολή', 'plandose' ); ?></a>
										<?php self::invoice_upload_form( $item->user->ID, $invoice_list ); ?>
									</div>
								</section>
							</div>
						</details>
						<?php endif; ?>
					</article>
				<?php endforeach; ?>
				</div>
				<?php endif; ?>
				<?php self::pagination( $result['total'], $per_page, $paged ); ?>
				<?php
				/*
				 * The diagnostic only makes sense when the detection itself is
				 * broken — i.e. the site has no recognised subscribers AT ALL.
				 * Not on any empty list: a perfectly normal filter (e.g.
				 * "Λήγουν Σύντομα" on a site with no Pro users) would print a
				 * scary "δεν βρέθηκε κανένας" notice contradicted by its own
				 * table, and dump 50 users' names and emails on screen for no
				 * reason.
				 */
				if ( empty( $result['items'] ) && $stats['total'] < 1 ) :
					self::render_debug_panel();
				endif;

				self::render_uncounted_users();
				?>
		</div>

		<div class="plandose-bottom-grid">
			<div class="plandose-side-card"><h3><?php esc_html_e( 'Σύνοψη Συνδρομών', 'plandose' ); ?></h3><?php
			// The ring shows the real Pro/Free split. Integer percent, so
			// esc_attr is enough.
			$donut_total = (int) $stats['total'];
			$donut_pro   = $donut_total > 0 ? (int) round( min( (int) $stats['pro'], $donut_total ) * 100 / $donut_total ) : 0;
			?>
			<div class="plandose-donut<?php echo $donut_total > 0 ? '' : ' is-empty'; ?>" style="<?php echo esc_attr( '--pd-pro-pct: ' . $donut_pro . '%;' ); ?>"><span><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?><small><?php esc_html_e( 'Σύνολο', 'plandose' ); ?></small></span></div><p class="plandose-donut-key is-free"><strong>Free:</strong> <?php echo esc_html( number_format_i18n( $stats['free'] ) ); ?></p><p class="plandose-donut-key is-pro"><strong>Pro:</strong> <?php echo esc_html( number_format_i18n( $stats['pro'] ) ); ?></p></div>

			<div class="plandose-bottom-actions">
				<h3><?php esc_html_e( 'Γρήγορες Ενέργειες', 'plandose' ); ?></h3>
				<div class="plandose-bottom-actions-buttons">
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=plandose-free' ) ); ?>"><?php esc_html_e( 'Δωρεάν συνδρομητές', 'plandose' ); ?></a>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=plandose-pro' ) ); ?>"><?php esc_html_e( 'Pro συνδρομητές', 'plandose' ); ?></a>
				</div>
			</div>
		</div>
		<?php
		if ( '' === self::$forced_status ) {
			self::render_deleted_account_invoices();
		}

		Plandose_Admin::page_footer();
	}

	/**
	 * Invoices kept from pharmacy accounts that have been deleted.
	 *
	 * Deleting a WordPress account does not delete its invoices — they are
	 * the site owner's accounting records. Those accounts are gone from
	 * wp_users, so they can never appear in the list above; this card is
	 * where their invoices can still be viewed and, when the administrator
	 * decides, deleted for good. Renders nothing when there are none.
	 */
	private static function render_deleted_account_invoices() {
		if ( ! method_exists( 'Plandose_Subscriptions', 'deleted_account_invoices' ) ) {
			return;
		}

		$accounts = Plandose_Subscriptions::deleted_account_invoices();

		if ( empty( $accounts ) ) {
			return;
		}
		?>
		<div class="plandose-main-card plandose-main-card-full plandose-deleted-invoices">
			<h3><?php esc_html_e( 'Τιμολόγια διαγραμμένων λογαριασμών', 'plandose' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Οι παρακάτω λογαριασμοί φαρμακείων έχουν διαγραφεί, αλλά τα τιμολόγιά τους διατηρούνται μέχρι να τα διαγράψετε εσείς.', 'plandose' ); ?></p>
			<table class="widefat striped plandose-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Φαρμακείο', 'plandose' ); ?></th>
						<th><?php esc_html_e( 'ΑΦΜ', 'plandose' ); ?></th>
						<th><?php esc_html_e( 'Διαγραφή λογαριασμού', 'plandose' ); ?></th>
						<th><?php esc_html_e( 'Τιμολόγια', 'plandose' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $accounts as $user_id => $invoices ) :
					$snap  = Plandose_Admin::deleted_account_snapshot( $user_id );
					$name  = ! empty( $snap['name'] ) ? $snap['name'] : sprintf(
						/* translators: %d: former WordPress user ID */
						__( 'Λογαριασμός #%d', 'plandose' ),
						$user_id
					);
					$label = $name;
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( $name ); ?></strong>
							<?php if ( ! empty( $snap['email'] ) ) : ?><br><span class="pd-muted"><?php echo esc_html( $snap['email'] ); ?></span><?php endif; ?>
						</td>
						<td><?php echo esc_html( ! empty( $snap['afm'] ) ? $snap['afm'] : '—' ); ?></td>
						<td><?php echo esc_html( ! empty( $snap['deleted_at'] ) ? mysql2date( get_option( 'date_format' ), $snap['deleted_at'] ) : '—' ); ?></td>
						<td>
							<?php Plandose_Admin_Invoices::render_invoice_links( $user_id, $invoices, array( 'allow_delete' => false ) ); ?>
						</td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pd-confirm-submit" data-confirm="<?php
								/* translators: %s: pharmacy name */
								echo esc_attr( sprintf( __( 'Οριστική διαγραφή όλων των τιμολογίων του «%s»; Η ενέργεια δεν αναιρείται.', 'plandose' ), $label ) );
							?>">
								<input type="hidden" name="action" value="plandose_delete_deleted_account_invoices" />
								<input type="hidden" name="user_id" value="<?php echo (int) $user_id; ?>" />
								<?php wp_nonce_field( 'plandose_delete_deleted_account_invoices_' . $user_id ); ?>
								<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Οριστική διαγραφή', 'plandose' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Search-any-user card for manual access overrides.
	 *
	 * Looks up any WordPress user directly by email, username, or ID,
	 * whether or not the list below includes them.
	 *
	 * It does not grant access to accounts the list does not
	 * recognise — only «Φαρμακείο» accounts may use PlanDose, and the
	 * remedy for a pharmacy with a wrong or empty «Κατηγορία» is to fix
	 * the category in its profile. What remains is blocking a pharmacy and
	 * clearing an old override. A non-pharmacy is marked as such, with
	 * every action that would have no effect for it disabled.
	 */
	private static function render_manual_access_lookup() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only user lookup: these GET params only populate a search field and look a user up for display; the actual grant/deny action is a separate nonce-protected POST.
		$query = isset( $_GET['manual_lookup'] ) ? sanitize_text_field( wp_unslash( $_GET['manual_lookup'] ) ) : '';
		$page  = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'plandose-subscriptions';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$found = null;

		if ( '' !== $query ) {
			if ( is_email( $query ) ) {
				$found = get_user_by( 'email', $query );
			} elseif ( ctype_digit( $query ) ) {
				$found = get_user_by( 'ID', (int) $query );
			} else {
				$found = get_user_by( 'login', $query );
			}

			// The result shows the user's email and roles. When the
			// PlanDose capability is delegated (plandose_manage_capability)
			// to someone who is not a full administrator, they must not be
			// able to look up accounts they may not edit — administrators
			// included. Shown as "not found", like any other miss.
			if ( $found && ! Plandose_Admin::can_manage_account( $found->ID ) ) {
				$found = null;
			}
		}
		?>
		<div class="plandose-main-card plandose-manual-lookup">
			<h3><?php esc_html_e( 'Χειροκίνητη πρόσβαση για συγκεκριμένο χρήστη', 'plandose' ); ?></h3>
			<p class="pd-muted"><?php esc_html_e( 'Αναζήτηση οποιουδήποτε χρήστη με email, username ή user ID, για αποκλεισμό ενός φαρμακείου ή για κατάργηση παλιάς χειροκίνητης ρύθμισης. Το PlanDose είναι διαθέσιμο μόνο σε λογαριασμούς με Κατηγορία «Φαρμακείο»· αν ένα φαρμακείο δεν εμφανίζεται στη λίστα, διορθώστε την Κατηγορία στο προφίλ του.', 'plandose' ); ?></p>
			<form method="get" class="plandose-manual-lookup-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( $page ); ?>" />
				<input type="text" name="manual_lookup" value="<?php echo esc_attr( $query ); ?>" placeholder="<?php esc_attr_e( 'email, username, ή user ID...', 'plandose' ); ?>" />
				<button class="button button-primary"><?php esc_html_e( 'Αναζήτηση', 'plandose' ); ?></button>
			</form>

			<?php if ( '' !== $query ) : ?>
				<?php if ( ! $found ) : ?>
					<p class="pd-danger"><?php esc_html_e( 'Δεν βρέθηκε χρήστης με αυτά τα στοιχεία.', 'plandose' ); ?></p>
				<?php else : ?>
					<?php $found_is_pharmacy = Plandose_Access::is_registered_pharmacist( $found->ID ); ?>
					<div class="plandose-manual-lookup-result">
						<div>
							<strong><?php echo esc_html( $found->display_name ); ?></strong>
							(<a href="mailto:<?php echo esc_attr( $found->user_email ); ?>"><?php echo esc_html( $found->user_email ); ?></a>)
							<?php if ( $found_is_pharmacy ) : ?>
								<span class="pd-badge pro"><?php esc_html_e( 'Φαρμακείο', 'plandose' ); ?></span>
							<?php else : ?>
								<span class="pd-badge pending"><?php esc_html_e( 'Όχι φαρμακείο', 'plandose' ); ?></span>
							<?php endif; ?>
							<br>
							<span class="pd-muted">
								ID: <?php echo (int) $found->ID; ?> ·
								<?php
								$found_roles = implode( ', ', (array) $found->roles );
								echo esc_html( $found_roles ? $found_roles : '—' );
								?>
							</span>
							<?php if ( ! $found_is_pharmacy ) : ?>
								<p class="pd-danger">
									<?php echo esc_html( self::not_pharmacy_message() ); ?>
									<a href="<?php echo esc_url( get_edit_user_link( $found->ID ) ); ?>"><?php esc_html_e( 'Άνοιγμα προφίλ', 'plandose' ); ?></a>
								</p>
							<?php endif; ?>
						</div>
						<?php self::access_override_control( $found->ID, $found_is_pharmacy ); ?>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * «Χρήστες που δεν μετράνε ως φαρμακεία» — collapsed by
	 * default, under the list. Explains the gap between WordPress's user
	 * count and PlanDose's subscriber count, one user at a time.
	 *
	 * Only the cached counts are rendered with the page. The users are
	 * loaded when the box is opened: by plandose-admin.js from
	 * ajax_uncounted_users(), or — without JavaScript — by following the
	 * link inside, which reloads the page with ?pd_uncounted=1.
	 */
	private static function render_uncounted_users() {
		if ( ! current_user_can( 'list_users' ) ) {
			return;
		}

		$data = Plandose_Subscriber_Query::uncounted_summary();

		if ( $data['total'] < 1 ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view flag: only decides whether the (already permitted) details are rendered inline.
		$inline = isset( $_GET['pd_uncounted'] ) && '1' === sanitize_key( wp_unslash( $_GET['pd_uncounted'] ) );

		$ajax_url = add_query_arg(
			array(
				'action' => self::UNCOUNTED_AJAX_ACTION,
				'nonce'  => wp_create_nonce( self::UNCOUNTED_AJAX_ACTION ),
			),
			admin_url( 'admin-ajax.php' )
		);

		$fallback_url = add_query_arg( 'pd_uncounted', '1', remove_query_arg( self::ONE_SHOT_ARGS ) ) . '#pd-uncounted';
		?>
		<details id="pd-uncounted" class="plandose-main-card pd-uncounted"<?php echo $inline ? ' open' : ''; ?><?php echo $inline ? '' : ' data-pd-lazy-url="' . esc_url( $ajax_url ) . '"'; ?>>
			<summary>
				<strong>
					<?php
					printf(
						/* translators: %s: number of users */
						esc_html__( 'Χρήστες που δεν μετράνε ως φαρμακεία: %s', 'plandose' ),
						esc_html( number_format_i18n( $data['total'] ) )
					);
					?>
				</strong>
				<span class="pd-muted"><?php esc_html_e( '(χωρίς τους διαχειριστές — πατήστε για λεπτομέρειες)', 'plandose' ); ?></span>
			</summary>
			<p class="pd-muted"><?php esc_html_e( 'Ένας χρήστης μετράει ως φαρμακείο μόνο όταν η «Κατηγορία» του (account_type) είναι «Φαρμακείο» — μόνο αυτοί μπορούν να είναι συνδρομητές και να χρησιμοποιούν το PlanDose. Αν κάποιος από τους παρακάτω είναι φαρμακείο, διορθώστε την Κατηγορία στο προφίλ του.', 'plandose' ); ?></p>

			<h4><?php esc_html_e( 'Ανά τιμή «Κατηγορίας»', 'plandose' ); ?></h4>
			<table class="widefat striped pd-uncounted-groups">
				<thead><tr><th><?php esc_html_e( 'Αποθηκευμένη τιμή', 'plandose' ); ?></th><th><?php esc_html_e( 'Χρήστες', 'plandose' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $data['groups'] as $label => $count ) : ?>
						<tr>
							<td><?php echo '' === (string) $label ? '<em>' . esc_html__( '(κενή — δεν έχει οριστεί Κατηγορία)', 'plandose' ) . '</em>' : '<code>' . esc_html( (string) $label ) . '</code>'; ?></td>
							<td><?php echo esc_html( number_format_i18n( $count ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<div class="pd-uncounted-details" data-pd-lazy-target aria-live="polite">
				<?php if ( $inline ) : ?>
					<?php self::render_uncounted_details(); ?>
				<?php else : ?>
					<p><a href="<?php echo esc_url( $fallback_url ); ?>"><?php esc_html_e( 'Εμφάνιση των χρηστών', 'plandose' ); ?></a></p>
				<?php endif; ?>
			</div>
		</details>
		<?php
	}

	/** admin-ajax action (and nonce action) of the uncounted-users details. */
	const UNCOUNTED_AJAX_ACTION = 'plandose_uncounted_users';

	/** Users listed in the uncounted-users details. */
	const UNCOUNTED_DETAIL_LIMIT = 300;

	/**
	 * The users table of the «Χρήστες που δεν μετράνε» box. Callers check
	 * the permissions (list page, or ajax_uncounted_users()).
	 */
	private static function render_uncounted_details() {
		$summary = Plandose_Subscriber_Query::uncounted_summary();
		$total   = (int) $summary['total'];
		$rows    = $total > 0
			? Plandose_Subscriber_Query::uncounted_rows( Plandose_Subscriber_Query::uncounted_ids( self::UNCOUNTED_DETAIL_LIMIT ) )
			: array();
		?>
			<h4><?php esc_html_e( 'Χρήστες', 'plandose' ); ?></h4>
			<table class="widefat striped pd-uncounted-users">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Χρήστης', 'plandose' ); ?></th>
						<th><?php esc_html_e( 'Ρόλοι', 'plandose' ); ?></th>
						<th><?php esc_html_e( 'Κατηγορία ανά meta key', 'plandose' ); ?></th>
						<th><?php esc_html_e( 'Χειροκίνητη πρόσβαση', 'plandose' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( get_edit_user_link( $row['id'] ) ); ?>"><strong><?php echo esc_html( $row['name'] ); ?></strong></a><br>
								<span class="pd-muted"><?php echo esc_html( $row['email'] ); ?></span>
							</td>
							<td><?php echo esc_html( $row['roles'] ? $row['roles'] : '—' ); ?></td>
							<td>
								<?php if ( empty( $row['values'] ) ) : ?>
									<em class="pd-muted"><?php esc_html_e( '(κενή)', 'plandose' ); ?></em>
								<?php else : ?>
									<?php foreach ( $row['values'] as $key => $value ) : ?>
										<div><code><?php echo esc_html( $key ); ?></code> = "<?php echo esc_html( $value ); ?>"</div>
									<?php endforeach; ?>
								<?php endif; ?>
							</td>
							<td>
								<?php
								if ( 'deny' === $row['manual'] ) {
									esc_html_e( 'Αποκλεισμός', 'plandose' );
								} elseif ( 'allow' === $row['manual'] ) {
									// A leftover grant from before the
									// pharmacy-only rule. Linked to the lookup card,
									// where it can be cleared.
									printf(
										'%1$s <a href="%2$s">%3$s</a>',
										esc_html__( 'Έγκριση (παλιά ρύθμιση — χωρίς ισχύ)', 'plandose' ),
										esc_url(
											add_query_arg(
												array(
													'page'          => 'plandose-subscriptions',
													'manual_lookup' => (int) $row['id'],
												),
												admin_url( 'admin.php' )
											)
										),
										esc_html__( 'Κατάργηση', 'plandose' )
									);
								} else {
									echo '—';
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $total > count( $rows ) ) : ?>
				<p class="pd-muted">
					<?php
					printf(
						/* translators: %s: number of users shown */
						esc_html__( 'Εμφανίζονται οι %s πιο πρόσφατοι.', 'plandose' ),
						esc_html( number_format_i18n( count( $rows ) ) )
					);
					?>
				</p>
			<?php endif; ?>
		<?php
	}

	/**
	 * admin-ajax: the uncounted-users details, as escaped HTML in JSON.
	 * Same permissions as the list screen that shows the box (the PlanDose
	 * capability and list_users), plus a nonce.
	 */
	public static function ajax_uncounted_users() {
		if ( ! check_ajax_referer( self::UNCOUNTED_AJAX_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Η σελίδα έληξε. Ανανεώστε τη σελίδα και δοκιμάστε ξανά.', 'plandose' ) ), 403 );
		}

		if ( ! Plandose_Admin::can_view_accounts() ) {
			wp_send_json_error( array( 'message' => __( 'Δεν έχετε δικαίωμα πρόσβασης.', 'plandose' ) ), 403 );
		}

		ob_start();
		self::render_uncounted_details();
		$html = (string) ob_get_clean();

		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * Shown only when the subscriber list comes back empty. Dumps the raw
	 * account_type value(s) per user so a mismatched meta key/value can be
	 * spotted directly in wp-admin.
	 */
	private static function render_debug_panel() {
		// This panel dumps user emails and account_type meta as a diagnostic.
		// The PlanDose management capability can be delegated to a narrower
		// role via the 'plandose_manage_capability' filter, so gate the PII
		// dump on the standard user-listing capability rather than exposing it
		// to any delegated role.
		if ( ! current_user_can( 'list_users' ) ) {
			return;
		}

		$rows = Plandose_Subscriber_Query::debug_account_types( 50 );
		?>
		<div class="pd-debug-panel">
			<h3><?php esc_html_e( 'Γιατί δεν βλέπω κανέναν; (διαγνωστικό)', 'plandose' ); ?></h3>
			<p><?php esc_html_e( 'Δεν βρέθηκε κανένας χρήστης με account_type που να περιέχει "φαρμακείο" ή "pharmacy". Παρακάτω βλέπεις τους 50 πιο πρόσφατους χρήστες και την τιμή που έχει όντως αποθηκευτεί για κάθε γνωστό meta key — αν η στήλη "Ταιριάζει" είναι Όχι σε όλους, το πρόβλημα είναι στο πεδίο εγγραφής (αποθηκεύει με διαφορετικό meta key ή διαφορετική τιμή απ\' όσο περιμέναμε).', 'plandose' ); ?></p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Χρήστης', 'plandose' ); ?></th>
						<th><?php esc_html_e( 'Ρόλοι', 'plandose' ); ?></th>
						<th><?php esc_html_e( 'Τιμές account_type ανά meta key', 'plandose' ); ?></th>
						<th><?php esc_html_e( 'Ταιριάζει;', 'plandose' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'Δεν υπάρχουν καθόλου χρήστες στο site.', 'plandose' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $row['name'] ); ?></strong><br><span class="pd-muted"><?php echo esc_html( $row['email'] ); ?></span></td>
							<td><?php echo esc_html( $row['roles'] ? $row['roles'] : '—' ); ?></td>
							<td>
								<?php if ( empty( $row['values'] ) ) : ?>
									<span class="pd-muted"><?php esc_html_e( '(καμία τιμή σε κανένα γνωστό meta key)', 'plandose' ); ?></span>
								<?php else : ?>
									<?php foreach ( $row['values'] as $key => $value ) : ?>
										<div><code><?php echo esc_html( $key ); ?></code> = "<?php echo esc_html( $value ); ?>"</div>
									<?php endforeach; ?>
								<?php endif; ?>
							</td>
							<td><?php echo $row['matches'] ? '<span class="pd-badge pro">' . esc_html__( 'Ναι', 'plandose' ) . '</span>' : '<span class="pd-badge pending">' . esc_html__( 'Όχι', 'plandose' ) . '</span>'; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private static function kpi( $icon, $label, $value ) {
		printf( '<div class="plandose-kpi"><span>%s</span><div><strong>%s</strong><em>%s</em></div></div>', esc_html( $icon ), esc_html( $value ), esc_html( $label ) );
	}

	/**
	 * One single-action POST form.
	 *
	 * $disabled_reason renders the button disabled with that text
	 * as its tooltip — used for actions that would have no effect on a
	 * non-pharmacy. The handler refuses them regardless; this only keeps
	 * the admin from pressing a button that cannot work.
	 */
	private static function action_button( $user_id, $action, $label, $disabled_reason = '', $state_token = '' ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pd-inline-form">
			<input type="hidden" name="action" value="plandose_subscription_action" />
			<input type="hidden" name="pd_action" value="<?php echo esc_attr( $action ); ?>" />
			<input type="hidden" name="user_id" value="<?php echo esc_attr( $user_id ); ?>" />
			<?php if ( '' !== $state_token ) : ?>
				<input type="hidden" name="expected_state" value="<?php echo esc_attr( $state_token ); ?>" />
			<?php endif; ?>
			<?php wp_nonce_field( 'plandose_subscription_action' ); ?>
			<?php if ( '' !== $disabled_reason ) : ?>
				<button class="button" type="submit" disabled="disabled" title="<?php echo esc_attr( $disabled_reason ); ?>"><?php echo esc_html( $label ); ?></button>
			<?php else : ?>
				<button class="button" type="submit"><?php echo esc_html( $label ); ?></button>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * Activate/extend Pro for one pharmacy, with a freely editable
	 * duration.
	 *
	 * The field defaults to the configured duration from Settings →
	 * Συνδρομές, but the admin can type any number of days — shorter
	 * (a 10-day trial) or longer (two years) — up to max_add_days().
	 * The value is clamped again server-side by effective_pro_days(),
	 * so the max attribute here is a convenience, not the guard.
	 *
	 * The preset buttons are plain type="button" and only fill the field
	 * (see initProDayPresets() in plandose-admin.js), which keeps a
	 * single unambiguous custom_days value in the POST body.
	 */
	private static function pro_action_form( $user_id, $is_pro, $state_token ) {
		$action   = $is_pro ? 'extend' : 'make_pro';
		$days     = self::effective_pro_days();
		$max_days = Plandose_Settings::max_add_days();
		$presets  = array_values(
			array_unique(
				array_filter(
					array( 10, 30, 90, 180, 365, $days ),
					static function ( $preset ) use ( $max_days ) {
						return $preset >= 1 && $preset <= $max_days;
					}
				)
			)
		);
		sort( $presets );
		$field_id = 'pd-days-' . (int) $user_id;
		$label    = $is_pro ? __( 'Παράταση Pro', 'plandose' ) : __( 'Ενεργοποίηση Pro', 'plandose' );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pd-inline-form pd-pro-form">
			<input type="hidden" name="action" value="plandose_subscription_action" />
			<input type="hidden" name="pd_action" value="<?php echo esc_attr( $action ); ?>" />
			<input type="hidden" name="user_id" value="<?php echo esc_attr( $user_id ); ?>" />
			<input type="hidden" name="expected_state" value="<?php echo esc_attr( $state_token ); ?>" />
			<?php wp_nonce_field( 'plandose_subscription_action' ); ?>
			<div class="pd-pro-row">
				<div class="pd-pro-days">
					<label for="<?php echo esc_attr( $field_id ); ?>"><?php esc_html_e( 'Ημέρες', 'plandose' ); ?></label>
					<input
						type="number"
						id="<?php echo esc_attr( $field_id ); ?>"
						name="custom_days"
						class="pd-pro-days-input"
						value="<?php echo esc_attr( $days ); ?>"
						min="1"
						max="<?php echo esc_attr( $max_days ); ?>"
						step="1"
						inputmode="numeric"
					/>
					<span class="pd-pro-days-max">
						<?php
						/* translators: %s: the largest number of days an admin may add at once. */
						echo esc_html( sprintf( __( 'έως %s', 'plandose' ), number_format_i18n( $max_days ) ) );
						?>
					</span>
				</div>
				<?php if ( count( $presets ) > 1 ) : ?>
					<div class="pd-pro-presets" role="group" aria-label="<?php esc_attr_e( 'Γρήγορη επιλογή ημερών', 'plandose' ); ?>">
						<?php foreach ( $presets as $preset ) : ?>
							<button type="button" class="pd-pro-preset" data-pd-days="<?php echo esc_attr( $preset ); ?>" data-pd-target="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( number_format_i18n( $preset ) ); ?></button>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
			<button class="button button-primary pd-pro-submit" type="submit"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Compact invoice uploader for a single row: choosing a file submits the
	 * form immediately (initInvoiceAutoSubmit() in plandose-admin.js). If the pharmacy already has an invoice on file, a
	 * "Προβολή" link to the most recent one is shown next to it.
	 */
	private static function invoice_upload_form( $user_id, $invoice_list ) {
		$has_invoice = ! empty( $invoice_list );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="pd-inline-form pd-invoice-form">
			<input type="hidden" name="action" value="plandose_upload_invoice" />
			<input type="hidden" name="user_id" value="<?php echo esc_attr( $user_id ); ?>" />
			<?php wp_nonce_field( 'plandose_upload_invoice' ); ?>
			<label class="button pd-invoice-label">
				📎 <?php echo $has_invoice ? esc_html__( 'Νέο τιμολόγιο', 'plandose' ) : esc_html__( 'Τιμολόγιο', 'plandose' ); ?>
				<input type="file" name="plandose_invoice_file" accept="application/pdf,image/jpeg,image/png,image/webp" class="pd-invoice-file" />
			</label>
		</form>
		<?php
		// The full list (newest first, older ones folded) with view
		// and per-invoice delete buttons, rendered OUTSIDE the upload form
		// because it carries forms of its own.
		if ( $has_invoice ) {
			Plandose_Admin_Invoices::render_invoice_links( $user_id, $invoice_list );
		}
		?>
		<?php
	}

	/**
	 * Manual access override control for a single dashboard row.
	 *
	 * Shows the EFFECTIVE access state first — whether this pharmacy can
	 * actually open the tool right now — with the reason underneath, so a
	 * pharmacy blocked by a role rule does not read as "Αυτόματο" here
	 * while the tool refuses to load for them.
	 *
	 * This is why the Subscriptions list and can_use_tool() are allowed to
	 * describe different populations: the list is every pharmacy account,
	 * and each card states its own access truthfully. See
	 * Plandose_Access::access_evaluation() for the policy itself.
	 *
	 * Two choices only — «Κανονική πρόσβαση» (no override) and
	 * «Αποκλεισμός» (deny). No «Επίτρεψε πρόσβαση» button: under
	 * the pharmacy-only rule a manual "allow" grants nothing, and the
	 * handler refuses to store one. An "allow" saved by an earlier version
	 * is shown as a leftover without effect, with the button to clear it.
	 * Blocking only means something for a pharmacy (nobody else has
	 * access to lose), so for a non-pharmacy that button is disabled.
	 *
	 * @param int       $user_id     Account.
	 * @param bool|null $is_pharmacy Precomputed is_registered_pharmacist(),
	 *                               or null to compute it here.
	 */
	private static function access_override_control( $user_id, $is_pharmacy = null ) {
		$override    = Plandose_Access::get_manual_access( $user_id );
		$evaluation  = Plandose_Access::access_evaluation( $user_id );
		$is_pharmacy = null === $is_pharmacy ? Plandose_Access::is_registered_pharmacist( $user_id ) : (bool) $is_pharmacy;

		/*
		 * Every reason code access_evaluation() can return, including the
		 * ones only earlier versions produced ('manual_allow',
		 * 'allowed_role', 'pharmacy_not_required'), so a card never goes
		 * blank whichever version of Plandose_Access is loaded. An unknown
		 * code is shown raw rather than dropped.
		 */
		$reasons     = array(
			'manual_allow'          => __( 'Παλιά χειροκίνητη έγκριση — δεν ισχύει πλέον από την έκδοση 1.21.0', 'plandose' ),
			'manual_deny'           => __( 'Χειροκίνητος αποκλεισμός από διαχειριστή', 'plandose' ),
			'hidden_role'           => __( 'Ο ρόλος του χρήστη είναι εξαιρημένος στις Ρυθμίσεις', 'plandose' ),
			'admin_preview'         => __( 'Διαχειριστής — ενεργή η προεπισκόπηση στις Ρυθμίσεις', 'plandose' ),
			'allowed_role'          => __( 'Επιτρεπόμενος ρόλος (παλιά ρύθμιση — δεν ισχύει πλέον)', 'plandose' ),
			'pharmacy_not_required' => __( 'Χωρίς απαίτηση φαρμακείου (παλιά ρύθμιση — δεν ισχύει πλέον)', 'plandose' ),
			'registered_pharmacist' => __( 'Λογαριασμός με Κατηγορία «Φαρμακείο»', 'plandose' ),
			'not_pharmacist'        => __( 'Ο λογαριασμός δεν έχει Κατηγορία Επιχείρησης «Φαρμακείο»', 'plandose' ),
			'invalid_user'          => __( 'Άγνωστος χρήστης', 'plandose' ),
		);
		$reason_code = isset( $evaluation['reason'] ) ? (string) $evaluation['reason'] : '';
		$reason_text = isset( $reasons[ $reason_code ] ) ? $reasons[ $reason_code ] : $reason_code;
		?>
		<div class="pd-access-override">
			<p class="pd-access-state">
				<?php if ( ! empty( $evaluation['allowed'] ) ) : ?>
					<span class="pd-badge pro"><?php esc_html_e( 'Έχει πρόσβαση', 'plandose' ); ?></span>
				<?php else : ?>
					<span class="pd-badge pending"><?php esc_html_e( 'Δεν έχει πρόσβαση', 'plandose' ); ?></span>
				<?php endif; ?>

				<?php if ( 'deny' === $override ) : ?>
					<span class="pd-badge pd-muted-badge"><?php esc_html_e( 'Αποκλεισμός', 'plandose' ); ?></span>
				<?php elseif ( 'allow' === $override ) : ?>
					<span class="pd-badge pd-muted-badge"><?php esc_html_e( 'Παλιά έγκριση — χωρίς ισχύ', 'plandose' ); ?></span>
				<?php else : ?>
					<span class="pd-badge pd-muted-badge"><?php esc_html_e( 'Κανονική πρόσβαση', 'plandose' ); ?></span>
				<?php endif; ?>
			</p>

			<?php if ( '' !== $reason_text ) : ?>
				<p class="pd-access-reason"><?php echo esc_html( $reason_text ); ?></p>
			<?php endif; ?>

			<?php if ( 'allow' === $override ) : ?>
				<p class="pd-access-reason">
					<?php
					echo $is_pharmacy
						? esc_html__( 'Υπάρχει αποθηκευμένη χειροκίνητη έγκριση από παλαιότερη έκδοση. Δεν χρειάζεται: το φαρμακείο έχει πρόσβαση κανονικά. Μπορείτε να την καταργήσετε.', 'plandose' )
						: esc_html__( 'Υπάρχει αποθηκευμένη χειροκίνητη έγκριση από παλαιότερη έκδοση. Δεν ισχύει πλέον, επειδή ο λογαριασμός δεν έχει Κατηγορία «Φαρμακείο». Μπορείτε να την καταργήσετε.', 'plandose' );
					?>
				</p>
			<?php endif; ?>
			<div class="pd-manage-grid">
				<?php
				if ( '' !== $override ) {
					self::action_button( $user_id, 'reset_access', __( 'Κανονική πρόσβαση', 'plandose' ) );
				}

				if ( 'deny' !== $override ) {
					self::action_button(
						$user_id,
						'deny_access',
						__( 'Αποκλεισμός', 'plandose' ),
						$is_pharmacy ? '' : self::not_pharmacy_message()
					);
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * The one-shot parameters of an action redirect (the result notice,
	 * the invoice-storage probe, the archive retry), which must not travel
	 * with the page links: every page link would show the «done» notice
	 * again for an action that did not happen.
	 */
	const ONE_SHOT_ARGS = array( 'plandose_result', 'plandose_probe', 'plandose_unarchived', 'archived', 'left', 'pd_uncounted' );

	/**
	 * URL of page $page of the current list: every filter kept, the
	 * one-shot parameters left out.
	 *
	 * @param int $page Page number.
	 * @return string Raw URL (escape on output).
	 */
	public static function page_url( $page ) {
		return remove_query_arg( self::ONE_SHOT_ARGS, add_query_arg( 'paged', (int) $page ) );
	}

	/**
	 * Page links plus a "showing X–Y of Z" line, so the admin can tell at
	 * a glance how far through the list they are. add_query_arg() with two
	 * arguments rewrites the CURRENT request URI, so every active filter
	 * is carried across pages automatically (through page_url(), without
	 * the one-shot parameters).
	 */
	private static function pagination( $total, $per_page, $paged ) {
		$total    = max( 0, (int) $total );
		$per_page = max( 1, (int) $per_page );
		$pages    = (int) ceil( $total / $per_page );

		if ( $total < 1 ) {
			return;
		}

		$first = ( ( $paged - 1 ) * $per_page ) + 1;
		$last  = min( $total, $paged * $per_page );

		echo '<div class="plandose-pagination-bar">';
		echo '<p class="plandose-pagination-count">';
		printf(
			/* translators: 1: first result on this page, 2: last result on this page, 3: total number of results. */
			esc_html__( 'Εμφάνιση %1$s–%2$s από %3$s', 'plandose' ),
			esc_html( number_format_i18n( $first ) ),
			esc_html( number_format_i18n( $last ) ),
			esc_html( number_format_i18n( $total ) )
		);
		echo '</p>';

		if ( $pages > 1 ) {
			echo '<div class="plandose-pagination">';

			if ( $paged > 1 ) {
				echo '<a class="pd-page-step" href="' . esc_url( self::page_url( $paged - 1 ) ) . '" rel="prev">&laquo;</a>';
			}

			// First, last and current±1, with "…" at every gap (a
			// gap of a single page shows that page instead).
			$shown = array_unique( array( 1, $pages, max( 1, $paged - 1 ), $paged, min( $pages, $paged + 1 ) ) );
			sort( $shown );
			$prev = 0;

			foreach ( $shown as $i ) {
				if ( $i - $prev === 2 ) {
					$gap_page = $i - 1;
					echo '<a href="' . esc_url( self::page_url( $gap_page ) ) . '">' . esc_html( number_format_i18n( $gap_page ) ) . '</a>';
				} elseif ( $i - $prev > 2 ) {
					echo '<span>…</span>';
				}
				echo '<a class="' . ( $i === $paged ? 'active' : '' ) . '" href="' . esc_url( self::page_url( $i ) ) . '">' . esc_html( number_format_i18n( $i ) ) . '</a>';
				$prev = $i;
			}

			if ( $paged < $pages ) {
				echo '<a class="pd-page-step" href="' . esc_url( self::page_url( $paged + 1 ) ) . '" rel="next">&raquo;</a>';
			}

			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Read-only view of the last 200 sensitive admin actions (see
	 * Plandose_Admin::audit()). Intentionally simple (no filters/pagination)
	 * — this is a lightweight accountability trail, not a full reporting tool.
	 */
	public static function render_audit_page() {
		if ( ! current_user_can( Plandose_Admin::capability() ) ) {
			wp_die( esc_html__( 'Δεν έχετε δικαίωμα πρόσβασης.', 'plandose' ) );
		}

		// The log names accounts and holds admins' IP addresses.
		if ( ! Plandose_Admin::can_view_accounts() ) {
			wp_die( esc_html__( 'Το ημερολόγιο ενεργειών χρειάζεται επιπλέον το δικαίωμα «list_users».', 'plandose' ), '', array( 'response' => 403 ) );
		}

		global $wpdb;

		Plandose_Admin::page_header( __( 'PlanDose → Ημερολόγιο Ενεργειών', 'plandose' ), __( 'Οι τελευταίες ευαίσθητες ενέργειες διαχειριστών: αλλαγές συνδρομής, χειροκίνητη πρόσβαση, τιμολόγια.', 'plandose' ) );

		$table = Plandose_Subscriptions::audit_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table; $table is Plandose_Subscriptions::audit_table_name() ($wpdb->prefix + fixed suffix), not user input. Query has no variable parameters (fixed LIMIT), so prepare() is unnecessary.
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 200" );

		/*
		 * Load every admin and target account named in these rows
		 * with one users query (plus one meta query) up front. The loop
		 * below calls get_userdata() twice per row, which would otherwise be
		 * up to 400 single-user queries for a 200-row page.
		 */
		$audit_user_ids = array();

		foreach ( (array) $rows as $audit_row ) {
			$audit_user_ids[] = (int) $audit_row->admin_user_id;
			$audit_user_ids[] = (int) $audit_row->target_user_id;
		}

		$audit_user_ids = array_values( array_unique( array_filter( $audit_user_ids ) ) );

		if ( $audit_user_ids ) {
			cache_users( $audit_user_ids );
		}

		$event_labels = array(
			'make_pro'                 => __( 'Ενεργοποίηση Pro', 'plandose' ),
			'extend'                   => __( 'Παράταση Pro', 'plandose' ),
			'make_free'                => __( 'Μετατροπή σε Free', 'plandose' ),
			'cli_cache_check'          => __( 'Έλεγχος cache από WP-CLI (προσωρινή συνεδρία)', 'plandose' ),
			'unarchived_retry'         => __( 'Αρχειοθέτηση ξανά μηνών ιστορικού', 'plandose' ),
			'unarchived_dismissed'     => __( 'Καθαρισμός λίστας μη αρχειοθετημένων μηνών', 'plandose' ),
			'free_limit_migrated'      => __( 'Αλλαγή μηνιαίου ορίου δωρεάν εκτυπώσεων στη νέα προεπιλογή', 'plandose' ),
			// Kept for entries written before 1.21.0, when this action existed.
			'allow_access'             => __( 'Χειροκίνητη επιτρεπόμενη πρόσβαση (παλιά ενέργεια)', 'plandose' ),
			'deny_access'              => __( 'Χειροκίνητος αποκλεισμός πρόσβασης', 'plandose' ),
			'reset_access'             => __( 'Επαναφορά σε κανονική πρόσβαση', 'plandose' ),
			'invoice_upload'           => __( 'Ανέβασμα τιμολογίου', 'plandose' ),
			'invoice_view'             => __( 'Προβολή τιμολογίου', 'plandose' ),
			'save_settings'            => __( 'Αποθήκευση ρυθμίσεων', 'plandose' ),
			'legacy_invoice_migration' => __( 'Αυτόματη μεταφορά παλιών τιμολογίων', 'plandose' ),
			'export_subscribers_csv'    => __( 'Εξαγωγή συνδρομητών σε CSV', 'plandose' ),
			'export_subscribers_csv_started' => __( 'Έναρξη εξαγωγής συνδρομητών σε CSV', 'plandose' ),
			'save_settings_failed'      => __( 'Αποτυχία αποθήκευσης ρυθμίσεων', 'plandose' ),
			'account_deleted_invoices_kept'   => __( 'Διαγραφή λογαριασμού — τα τιμολόγια διατηρήθηκαν', 'plandose' ),
			'deleted_account_invoices_removed' => __( 'Διαγραφή τιμολογίων διαγραμμένου λογαριασμού', 'plandose' ),
			'invoice_delete'                   => __( 'Διαγραφή τιμολογίου', 'plandose' ),
			'legacy_invoice_migration_retry'   => __( 'Επανάληψη μεταφοράς τιμολογίων', 'plandose' ),
			'legacy_invoice_migration_rerun'   => __( 'Αυτόματη επανάληψη μεταφοράς τιμολογίων', 'plandose' ),
			'account_type_change'              => __( 'Αλλαγή Κατηγορίας Επιχείρησης', 'plandose' ),
			'account_type_change_blocked'      => __( 'Απόπειρα αλλαγής Κατηγορίας από τον ίδιο τον χρήστη (απορρίφθηκε)', 'plandose' ),
			'privacy_erasure'                  => __( 'Διαγραφή προσωπικών δεδομένων (GDPR)', 'plandose' ),
			'legacy_original_deleted'          => __( 'Διαγραφή δημόσιου πρωτοτύπου τιμολογίου', 'plandose' ),
			'legacy_original_skipped'          => __( 'Πρωτότυπο τιμολογίου δεν διαγράφηκε (έλεγχος)', 'plandose' ),
			'legacy_original_gone'             => __( 'Πρωτότυπο τιμολογίου δεν υπάρχει πια', 'plandose' ),
			'legacy_originals_cleanup'         => __( 'Διαγραφή πρωτοτύπων τιμολογίων', 'plandose' ),
			'legacy_original_deleted_uncopied' => __( 'Διαγραφή δημόσιου πρωτοτύπου χωρίς αντίγραφο', 'plandose' ),
			'legacy_invoice_migration_cleanup' => __( 'Καθαρισμός ημιτελών αντιγράφων μεταφοράς', 'plandose' ),
			'invoice_upload_refused_storage'   => __( 'Απόρριψη τιμολογίου: μη ιδιωτικός φάκελος', 'plandose' ),
			'invoice_storage_probe'            => __( 'Έλεγχος ιδιωτικότητας φακέλου τιμολογίων', 'plandose' ),
		);
		?>
		<div class="plandose-main-card plandose-main-card-full">
			<table class="widefat striped plandose-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Ημερομηνία', 'plandose' ); ?></th>
						<th><?php esc_html_e( 'Ενέργεια', 'plandose' ); ?></th>
						<th><?php esc_html_e( 'Διαχειριστής', 'plandose' ); ?></th>
						<th><?php esc_html_e( 'Χρήστης-στόχος', 'plandose' ); ?></th>
						<th>IP</th>
						<th><?php esc_html_e( 'Λεπτομέρειες', 'plandose' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'Δεν υπάρχουν ακόμα καταχωρήσεις.', 'plandose' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( (array) $rows as $row ) : ?>
					<?php
					$admin_user  = $row->admin_user_id ? get_userdata( $row->admin_user_id ) : false;
					$target_user = $row->target_user_id ? get_userdata( $row->target_user_id ) : false;
					$label       = isset( $event_labels[ $row->event ] ) ? $event_labels[ $row->event ] : $row->event;
					?>
					<tr>
						<td><?php echo esc_html( $row->created_at ); ?></td>
						<td><?php echo esc_html( $label ); ?></td>
						<td><?php echo esc_html( $admin_user ? $admin_user->user_login : ( (int) $row->admin_user_id ? '#' . (int) $row->admin_user_id : __( 'Σύστημα', 'plandose' ) ) ); ?></td>
						<td><?php echo esc_html( $target_user ? $target_user->user_login : ( $row->target_user_id ? '#' . (int) $row->target_user_id : '—' ) ); ?></td>
						<td><?php echo esc_html( $row->ip ); ?></td>
						<td><code><?php echo esc_html( $row->meta ); ?></code></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
		Plandose_Admin::page_footer();
	}
}