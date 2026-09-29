<?php
/**
 * Wires PlanDose into WordPress's built-in personal-data tools
 * (Tools -> Export Personal Data / Erase Personal Data) and into the
 * Privacy Policy Guide.
 *
 * Without this, a subject-access or erasure request handled through
 * WordPress would return none of the data PlanDose stores. The plan data
 * itself is never persisted, but subscriptions, the monthly print history,
 * the audit log and invoice metadata are, and they can carry VAT/tax IDs
 * and IP addresses.
 *
 * Works with:
 * - includes/class-plandose-subscriptions.php
 * - includes/class-plandose-admin.php
 * - includes/class-plandose-admin-invoices.php
 * - includes/class-plandose-print-charges.php (optional)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plandose_Privacy {

	/**
	 * Identifier used for both the exporter and the eraser.
	 */
	const EXPORTER_ID = 'plandose';

	/**
	 * How many audit rows to hand back per exporter/eraser page. The tools
	 * call us repeatedly with an increasing $page until we report done.
	 */
	const PAGE_SIZE = 100;

	/**
	 * Print-log and print-charge rows per exporter page. Those rows are
	 * small, and a busy Pro pharmacy has thousands of them in the print
	 * log's retention window, so a page holds more of them than of audit
	 * rows. Filterable via 'plandose_privacy_export_rows_per_page'.
	 */
	const ROWS_PAGE_SIZE = 500;

	/**
	 * Prevents double registration, matching the other PlanDose classes.
	 *
	 * @var bool
	 */
	private static $initialized = false;

	public static function init() {
		if ( self::$initialized ) {
			return;
		}

		if ( ! class_exists( 'Plandose_Subscriptions' ) ) {
			return;
		}

		self::$initialized = true;

		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'add_privacy_policy_content' ) );
	}

	/**
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters[ self::EXPORTER_ID ] = array(
			'exporter_friendly_name' => __( 'PlanDose', 'plandose' ),
			'callback'               => array( __CLASS__, 'export' ),
		);

		return $exporters;
	}

	/**
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers[ self::EXPORTER_ID ] = array(
			'eraser_friendly_name' => __( 'PlanDose', 'plandose' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Resolve an email address to a WordPress user.
	 *
	 * @param string $email Email address supplied by the privacy tool.
	 * @return WP_User|null
	 */
	private static function user_from_email( $email ) {
		$user = get_user_by( 'email', $email );

		return $user ? $user : null;
	}

	/**
	 * IDs of DELETED pharmacy accounts whose identity snapshot (kept with
	 * their retained invoices, see
	 * Plandose_Admin::snapshot_before_delete()) carries this email.
	 *
	 * Without this, a former pharmacy's access or erasure request would
	 * find nothing — get_user_by() cannot see a deleted account — even
	 * though PlanDose still holds its name, email, AFM and invoices.
	 *
	 * @param string $email Email address supplied by the privacy tool.
	 * @return int[]
	 */
	private static function deleted_account_ids( $email ) {
		$email = strtolower( trim( (string) $email ) );

		if ( '' === $email || ! class_exists( 'Plandose_Admin' ) ) {
			return array();
		}

		$accounts = get_option( Plandose_Admin::DELETED_ACCOUNTS_OPTION, array() );

		if ( ! is_array( $accounts ) ) {
			return array();
		}

		$ids = array();

		foreach ( $accounts as $user_id => $snapshot ) {
			$user_id = absint( $user_id );

			if (
				! $user_id ||
				! is_array( $snapshot ) ||
				empty( $snapshot['email'] ) ||
				strtolower( trim( (string) $snapshot['email'] ) ) !== $email
			) {
				continue;
			}

			// A live account with this ID is not a deleted one; it is
			// handled through get_user_by() like any other.
			if ( get_userdata( $user_id ) ) {
				continue;
			}

			$ids[] = $user_id;
		}

		return $ids;
	}

	/**
	 * The identity snapshot of a deleted account, exported on its own.
	 *
	 * @param int   $user_id  Deleted user's ID.
	 * @param array $snapshot Stored snapshot.
	 * @return array
	 */
	private static function deleted_account_export_item( $user_id, $snapshot ) {
		$fields = array(
			'name'       => __( 'Επωνυμία φαρμακείου', 'plandose' ),
			'email'      => __( 'Email', 'plandose' ),
			'afm'        => __( 'ΑΦΜ', 'plandose' ),
			'deleted_at' => __( 'Ημερομηνία διαγραφής λογαριασμού', 'plandose' ),
		);
		$data   = array();

		foreach ( $fields as $key => $label ) {
			if ( ! empty( $snapshot[ $key ] ) ) {
				$data[] = array(
					'name'  => $label,
					'value' => (string) $snapshot[ $key ],
				);
			}
		}

		return array(
			'group_id'    => 'plandose-deleted-account',
			'group_label' => __( 'PlanDose — Στοιχεία διαγραμμένου λογαριασμού (τηρούνται με τα τιμολόγια)', 'plandose' ),
			'item_id'     => 'plandose-deleted-account-' . (int) $user_id,
			'data'        => $data,
		);
	}

	/**
	 * Export everything PlanDose holds about one person.
	 *
	 * Page 1 returns the subscription row, the manual-access override, the
	 * invoice metadata (never the file contents — the admin exports those
	 * deliberately if a request calls for it) and the monthly print history.
	 * Later pages walk the audit log, which can be long on an established
	 * install, and the print log and print-charge rows (every page, until
	 * all three are exhausted).
	 *
	 * @param string $email Email address of the data subject.
	 * @param int    $page  1-based page number.
	 * @return array
	 */
	public static function export( $email, $page = 1 ) {
		$page    = max( 1, (int) $page );
		$user    = self::user_from_email( $email );
		$deleted = self::deleted_account_ids( $email );

		if ( ! $user && empty( $deleted ) ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$export_items = array();
		$subject_ids  = $deleted;

		if ( $user ) {
			array_unshift( $subject_ids, (int) $user->ID );
		}

		// Every paged stream (audit log, and the print log and charge rows
		// of each account) is walked in step, page N of each on exporter
		// page N; the export is done once all of them are exhausted. The
		// one-off groups go on page 1 only, so they appear exactly once.
		$rows_done = true;

		foreach ( $deleted as $deleted_id ) {
			if ( 1 === $page ) {
				$export_items[] = self::deleted_account_export_item(
					$deleted_id,
					Plandose_Admin::deleted_account_snapshot( $deleted_id )
				);

				// Everything erase() would delete for a deleted
				// account is exported too.
				foreach ( array(
					self::subscription_export_item( $deleted_id ),
					self::invoices_export_item( $deleted_id ),
					self::print_history_export_item( $deleted_id ),
				) as $item ) {
					if ( $item ) {
						$export_items[] = $item;
					}
				}
			}

			foreach ( array(
				self::print_charges_export_item( $deleted_id, $page, $rows_done ),
				self::print_log_export_item( $deleted_id, $page, $rows_done ),
			) as $item ) {
				if ( $item ) {
					$export_items[] = $item;
				}
			}
		}

		if ( $user && 1 === $page ) {
			$subscription = self::subscription_export_item( $user->ID );

			if ( $subscription ) {
				$export_items[] = $subscription;
			}

			$override = self::manual_access_export_item( $user->ID );

			if ( $override ) {
				$export_items[] = $override;
			}

			$invoices = self::invoices_export_item( $user->ID );

			if ( $invoices ) {
				$export_items[] = $invoices;
			}

			$history = self::print_history_export_item( $user->ID );

			if ( $history ) {
				$export_items[] = $history;
			}
		}

		if ( $user ) {
			$charges = self::print_charges_export_item( $user->ID, $page, $rows_done );

			if ( $charges ) {
				$export_items[] = $charges;
			}

			$print_log = self::print_log_export_item( $user->ID, $page, $rows_done );

			if ( $print_log ) {
				$export_items[] = $print_log;
			}
		}

		$audit = self::audit_export_items( $subject_ids, $page );

		$export_items = array_merge( $export_items, $audit['items'] );

		return array(
			'data' => $export_items,
			'done' => $audit['done'] && $rows_done,
		);
	}

	/**
	 * Print-log / print-charge rows per exporter page, after the filter.
	 *
	 * @return int
	 */
	private static function rows_page_size() {
		/**
		 * Filters how many print-log / print-charge rows go on one
		 * personal-data exporter page.
		 *
		 * @param int $size Rows per page.
		 */
		$size = (int) apply_filters( 'plandose_privacy_export_rows_per_page', self::ROWS_PAGE_SIZE );

		return max( 1, min( 5000, $size ) );
	}

	/**
	 * The item_id for one page of a paged per-account group.
	 *
	 * WordPress merges items that share an item_id across exporter pages by
	 * PREPENDING the later page's data, which would print the pages in
	 * reverse order; a distinct id per page keeps them in order. Page 1
	 * keeps the plain id.
	 *
	 * @param string $prefix  Group prefix, e.g. 'plandose-print-log'.
	 * @param int    $user_id User ID.
	 * @param int    $page    1-based page number.
	 * @return string
	 */
	private static function paged_item_id( $prefix, $user_id, $page ) {
		$id = $prefix . '-' . (int) $user_id;

		return 1 === (int) $page ? $id : $id . '-' . (int) $page;
	}

	/**
	 * The manual access override, exported on its own.
	 *
	 * It lives in user meta, not in the subscriptions table, and
	 * set_manual_access() will happily record one for a user who has never
	 * had a subscription row. Folding it into subscription_export_item()
	 * would export nothing at all in that case, even though a stored
	 * "allow"/"deny" is a decision the site has taken about that person.
	 *
	 * @param int $user_id User ID.
	 * @return array|null
	 */
	private static function manual_access_export_item( $user_id ) {
		$override = Plandose_Access::get_manual_access( $user_id );

		if ( '' === $override ) {
			return null;
		}

		return array(
			'group_id'    => 'plandose-access',
			'group_label' => __( 'PlanDose — Πρόσβαση στο εργαλείο', 'plandose' ),
			'item_id'     => 'plandose-access-' . (int) $user_id,
			'data'        => array(
				array(
					'name'  => __( 'Χειροκίνητη ρύθμιση πρόσβασης', 'plandose' ),
					'value' => $override,
				),
			),
		);
	}

	/**
	 * Subscription status, expiry and print counters.
	 *
	 * @param int $user_id User ID.
	 * @return array|null
	 */
	private static function subscription_export_item( $user_id ) {
		$row = Plandose_Subscriptions::get_row( $user_id, false );

		if ( ! $row ) {
			return null;
		}

		$data = array(
			array(
				'name'  => __( 'Κατάσταση συνδρομής', 'plandose' ),
				'value' => Plandose_Subscriptions::is_pro_active( $row ) ? 'Pro' : 'Free',
			),
			array(
				'name'  => __( 'Ημερομηνία λήξης Pro', 'plandose' ),
				'value' => $row->sub_end_date ? $row->sub_end_date : __( '—', 'plandose' ),
			),
			array(
				'name'  => __( 'Εκτυπώσεις τρέχοντος μήνα', 'plandose' ),
				'value' => Plandose_Subscriptions::current_month_count( $row ),
			),
			array(
				'name'  => __( 'Μηδενισμός μετρητή', 'plandose' ),
				'value' => $row->count_reset_at ? $row->count_reset_at : __( '—', 'plandose' ),
			),
			array(
				'name'  => __( 'Τελευταία εκτύπωση', 'plandose' ),
				'value' => $row->last_print_at ? $row->last_print_at : __( '—', 'plandose' ),
			),
		);

		return array(
			'group_id'    => 'plandose-subscription',
			'group_label' => __( 'PlanDose — Συνδρομή', 'plandose' ),
			'item_id'     => 'plandose-subscription-' . (int) $user_id,
			'data'        => $data,
		);
	}

	/**
	 * Invoice metadata: which files exist and what they are called. The
	 * files themselves are not embedded in the export.
	 *
	 * @param int $user_id User ID.
	 * @return array|null
	 */
	private static function invoices_export_item( $user_id ) {
		$row = Plandose_Subscriptions::get_row( $user_id, false );

		if ( ! $row ) {
			return null;
		}

		$invoices = Plandose_Subscriptions::decode_invoices( $row->invoices );

		if ( empty( $invoices ) ) {
			return null;
		}

		$data = array();
		$i    = 0;

		foreach ( $invoices as $invoice ) {
			++$i;

			$data[] = array(
				'name'  => sprintf(
					/* translators: %d: invoice number in the list */
					__( 'Τιμολόγιο %d', 'plandose' ),
					$i
				),
				'value' => is_numeric( $invoice )
					? sprintf(
						/* translators: %d: WordPress attachment ID */
						__( 'Συνημμένο #%d', 'plandose' ),
						(int) $invoice
					)
					: basename( (string) $invoice ),
			);
		}

		return array(
			'group_id'    => 'plandose-invoices',
			'group_label' => __( 'PlanDose — Τιμολόγια', 'plandose' ),
			'item_id'     => 'plandose-invoices-' . (int) $user_id,
			'data'        => $data,
		);
	}

	/**
	 * Monthly print totals for closed months.
	 *
	 * This is a usage profile of the pharmacy — how much it worked, month
	 * by month — so it is personal data and belongs in a subject-access
	 * response even though it names no patient and no medicine. The
	 * running month is not here: it is still the live counter and is
	 * already exported by subscription_export_item().
	 *
	 * Capped at ten years, which is past any retention period that could
	 * reasonably apply to it.
	 *
	 * @param int $user_id User ID.
	 * @return array|null
	 */
	private static function print_history_export_item( $user_id ) {
		if ( ! method_exists( 'Plandose_Subscriptions', 'get_print_history' ) ) {
			return null;
		}

		$rows = Plandose_Subscriptions::get_print_history( $user_id, 120 );
		$data = array();

		foreach ( (array) $rows as $row ) {
			$data[] = array(
				'name'  => (string) $row->ym,
				'value' => (int) $row->prints,
			);
		}

		// Closed months still waiting to be archived.
		if ( method_exists( 'Plandose_Subscriptions', 'unarchived_months' ) ) {
			foreach ( Plandose_Subscriptions::unarchived_months() as $entry ) {
				if ( (int) $user_id === $entry['user_id'] ) {
					$data[] = array(
						/* translators: %s: month YYYY-MM */
						'name'  => sprintf( __( '%s (προς αρχειοθέτηση)', 'plandose' ), $entry['ym'] ),
						'value' => $entry['prints'],
					);
				}
			}
		}

		if ( empty( $data ) ) {
			return null;
		}

		return array(
			'group_id'    => 'plandose-print-history',
			'group_label' => __( 'PlanDose — Μηνιαίο ιστορικό εκτυπώσεων', 'plandose' ),
			'item_id'     => 'plandose-print-history-' . (int) $user_id,
			'data'        => $data,
		);
	}

	/**
	 * The print-charge ledger rows (Plandose_Print_Charges): when a
	 * print was charged and how many free reprints it used. Kept only for
	 * the free-reprint window; the token hash is not exported (a technical
	 * value with no meaning to the person).
	 *
	 * One exporter page of rows; $done is cleared while rows may remain.
	 *
	 * @param int  $user_id User ID.
	 * @param int  $page    1-based page number.
	 * @param bool $done    Set to false when a further page may hold rows.
	 * @return array|null
	 */
	private static function print_charges_export_item( $user_id, $page, &$done ) {
		if ( ! class_exists( 'Plandose_Print_Charges' ) || ! method_exists( 'Plandose_Print_Charges', 'rows_for_user' ) ) {
			return null;
		}

		$size = self::rows_page_size();
		$rows = Plandose_Print_Charges::rows_for_user( $user_id, $size, ( $page - 1 ) * $size );

		if ( empty( $rows ) ) {
			return null;
		}

		if ( count( $rows ) >= $size ) {
			$done = false;
		}

		$data = array();

		foreach ( $rows as $row ) {
			$data[] = array(
				'name'  => wp_date( 'Y-m-d H:i:s', (int) $row->charged_ts ),
				'value' => sprintf(
					/* translators: %d: free reprints used */
					__( 'Χρεωμένη εκτύπωση, δωρεάν επανεκτυπώσεις: %d', 'plandose' ),
					(int) $row->reprints
				),
			);
		}

		return array(
			'group_id'    => 'plandose-print-charges',
			'group_label' => __( 'PlanDose — Πρόσφατες χρεώσεις εκτυπώσεων', 'plandose' ),
			'item_id'     => self::paged_item_id( 'plandose-print-charges', $user_id, $page ),
			'data'        => $data,
		);
	}

	/**
	 * The print log — when, charged or free reprint, Free/Pro, the
	 * monthly count. No plan data (there is none on the server).
	 *
	 * One exporter page of rows (a year of a busy pharmacy's prints does
	 * not fit in one); $done is cleared while rows may remain.
	 *
	 * @param int  $user_id User ID.
	 * @param int  $page    1-based page number.
	 * @param bool $done    Set to false when a further page may hold rows.
	 * @return array|null
	 */
	private static function print_log_export_item( $user_id, $page, &$done ) {
		if ( ! class_exists( 'Plandose_Print_Log' ) ) {
			return null;
		}

		$size = self::rows_page_size();
		$rows = Plandose_Print_Log::rows_for_user( $user_id, $size, ( $page - 1 ) * $size );

		if ( empty( $rows ) ) {
			return null;
		}

		if ( count( $rows ) >= $size ) {
			$done = false;
		}

		$data = array();

		foreach ( $rows as $row ) {
			$data[] = array(
				'name'  => wp_date( 'Y-m-d H:i:s', (int) $row->printed_ts ),
				'value' => ( Plandose_Print_Log::KIND_REPRINT === $row->kind ? __( 'Δωρεάν επανεκτύπωση', 'plandose' ) : __( 'Χρεωμένη εκτύπωση', 'plandose' ) ) .
					' (' . ( (int) $row->is_pro ? 'Pro' : 'Free' ) . ')',
			);
		}

		return array(
			'group_id'    => 'plandose-print-log',
			'group_label' => __( 'PlanDose — Ιστορικό εκτυπώσεων', 'plandose' ),
			'item_id'     => self::paged_item_id( 'plandose-print-log', $user_id, $page ),
			'data'        => $data,
		);
	}

	/**
	 * Audit-log rows that concern this person, in two clearly separated
	 * groups — because one row can hold two different people's data.
	 *
	 * A row records an admin acting ON a pharmacist. The `meta` describes
	 * what was done to the TARGET; the `ip` belongs to the ADMIN who did
	 * it. Handing a requester the whole row would disclose the other
	 * party's data to them, so each group carries only the fields that are
	 * actually the requester's own:
	 *
	 * - as target: date, action, details  (never the acting admin's IP)
	 * - as actor:  date, action, their IP (never the details, which
	 *              describe somebody else's account)
	 *
	 * Takes every account ID that belongs to the requester — the
	 * live account and any deleted ones found by deleted_account_ids().
	 *
	 * @param int[] $user_ids User IDs of the data subject.
	 * @param int   $page     1-based page number.
	 * @return array{items:array,done:bool}
	 */
	private static function audit_export_items( $user_ids, $page ) {
		global $wpdb;

		$user_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $user_ids ) ) ) );

		if ( empty( $user_ids ) ) {
			return array(
				'items' => array(),
				'done'  => true,
			);
		}

		$table        = Plandose_Subscriptions::audit_table_name();
		$offset       = ( $page - 1 ) * self::PAGE_SIZE;
		$placeholders = implode( ', ', array_fill( 0, count( $user_ids ), '%d' ) );
		$args         = array_merge( $user_ids, $user_ids, array( self::PAGE_SIZE, $offset ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table; $table is Plandose_Subscriptions::audit_table_name() ($wpdb->prefix + fixed suffix), not user input; $placeholders is a generated list of %d.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, created_at, event, ip, meta, admin_user_id, target_user_id FROM `{$table}` WHERE target_user_id IN ({$placeholders}) OR admin_user_id IN ({$placeholders}) ORDER BY id ASC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $table is $wpdb->prefix + fixed suffix; {$placeholders} is a generated list of %d.
				$args
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return array(
				'items' => array(),
				'done'  => true,
			);
		}

		$items = array();

		foreach ( $rows as $row ) {
			$base = array(
				array(
					'name'  => __( 'Ημερομηνία', 'plandose' ),
					'value' => $row->created_at,
				),
				array(
					'name'  => __( 'Ενέργεια', 'plandose' ),
					'value' => $row->event,
				),
			);

			// The person this action was performed on.
			if ( in_array( (int) $row->target_user_id, $user_ids, true ) ) {
				$data = $base;
				$meta = json_decode( (string) $row->meta, true );

				if ( is_array( $meta ) && ! empty( $meta ) ) {
					$data[] = array(
						'name'  => __( 'Λεπτομέρειες', 'plandose' ),
						'value' => wp_json_encode( $meta ),
					);
				}

				$items[] = array(
					'group_id'    => 'plandose-audit',
					'group_label' => __( 'PlanDose — Ενέργειες διαχειριστή στον λογαριασμό σας', 'plandose' ),
					'item_id'     => 'plandose-audit-' . (int) $row->id,
					'data'        => $data,
				);
			}

			// The person who performed the action — their own IP.
			if ( in_array( (int) $row->admin_user_id, $user_ids, true ) ) {
				$data = $base;

				if ( '' !== (string) $row->ip ) {
					$data[] = array(
						'name'  => __( 'Διεύθυνση IP', 'plandose' ),
						'value' => $row->ip,
					);
				}

				$items[] = array(
					'group_id'    => 'plandose-audit-actor',
					'group_label' => __( 'PlanDose — Ενέργειες που πραγματοποιήσατε', 'plandose' ),
					'item_id'     => 'plandose-audit-actor-' . (int) $row->id,
					'data'        => $data,
				);
			}
		}

		return array(
			'items' => $items,
			'done'  => count( $rows ) < self::PAGE_SIZE,
		);
	}

	/**
	 * Erase what can lawfully be erased, and say plainly what was kept.
	 *
	 * Invoices are NOT deleted. They are accounting documents and Greek tax
	 * law requires them to be retained for a fixed period regardless of an
	 * erasure request, so the subscription row that points at them has to
	 * survive too. WordPress supports exactly this case: we report
	 * items_retained = true and attach a message the admin can pass on to
	 * the person who asked.
	 *
	 * The message deliberately does not promise automatic deletion later.
	 * Nothing in the plugin tracks when a retention period expires, and
	 * readme.txt states plainly that invoices are removed only when an
	 * administrator removes them. Saying "they will be deleted" would have
	 * committed the controller to something the software does not do.
	 *
	 * What is erased:
	 * - a manual access override other than 'deny' (a legacy 'allow'
	 *   grants nothing and has no reason to be kept)
	 * - the free-text metadata on audit rows where they are the TARGET
	 * - the IP address on audit rows where they were the acting ADMIN
	 *   (each field is erased only for the party it belongs to)
	 * - the monthly print history, unconditionally
	 * - the short-lived print receipts and the print-charge rows
	 *   (Plandose_Print_Charges: token hashes and timestamps only)
	 * - the whole subscription row, when no invoices are attached and the
	 *   subscription is not an active Pro one — except for a LIVE
	 *   account that has printed this month: its row is reduced to the
	 *   monthly counter (see Plandose_Subscriptions::minimize_row()) so the
	 *   erasure does not hand out a fresh Free allowance; that is reported
	 *   as retained
	 * - the same for any DELETED account carrying the requester's
	 *   email (deleted_account_ids()); its identity snapshot is erased when
	 *   no invoices are left, otherwise kept with them and reported
	 *
	 * What is kept, and reported as kept with the reason:
	 * - a manual 'deny' override: an access-control decision the site has
	 *   taken (abuse/fraud prevention). Erasing it would let a blocked
	 *   pharmacy back in simply by asking.
	 * - the subscription row of an ACTIVE Pro subscription: it is what the
	 *   running contract is performed with (end date, print limits). It
	 *   can be erased by a new request once the subscription has ended.
	 * - rows with invoices (tax retention).
	 *
	 * A database error is never reported as an erasure. The failed
	 * part gets its own message, does not set items_removed, and counts as
	 * retained (the data is still there). The erasure itself is written to
	 * the audit log with the account ID(s) and counts only — no email,
	 * name or IP of the data subject.
	 *
	 * @param string $email Email address of the data subject.
	 * @param int    $page  1-based page number.
	 * @return array
	 */
	public static function erase( $email, $page = 1 ) {
		$page    = max( 1, (int) $page );
		$user    = self::user_from_email( $email );
		$deleted = self::deleted_account_ids( $email );

		$response = array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);

		if ( ( ! $user && empty( $deleted ) ) || 1 !== $page ) {
			return $response;
		}

		$subject_ids = $deleted;

		if ( $user ) {
			array_unshift( $subject_ids, (int) $user->ID );
		}

		/*
		 * removed/retained/errors are keyed by the kind of data, so a kind
		 * found on more than one account (live + deleted) is reported once.
		 * stats is what goes into the audit log: counts and flags only.
		 */
		$state = array(
			'removed'  => array(),
			'retained' => array(),
			'errors'   => array(),
			'stats'    => array(
				'user_ids'                    => array_map( 'intval', $subject_ids ),
				'deleted_accounts'            => count( $deleted ),
				'access_override'             => 'none',
				'audit_meta_cleared'          => 0,
				'audit_ip_cleared'            => 0,
				'print_history_rows_deleted'  => 0,
				'print_receipts_deleted'      => false,
				'print_charges_deleted'       => false,
				'subscription_rows_deleted'   => 0,
				'subscription_rows_minimized' => 0,
				'subscription_rows_kept_pro'  => 0,
				'subscription_rows_kept_tax'  => 0,
				'invoices_retained'           => 0,
				'identity_snapshots_erased'   => 0,
			),
		);

		// 1. Manual access override (live accounts only: user meta goes
		// with a deleted account).
		if ( $user ) {
			self::erase_manual_access( (int) $user->ID, $state );
		}

		foreach ( $subject_ids as $user_id ) {
			$is_deleted = in_array( $user_id, $deleted, true );

			// 2–3. Audit log, print history, receipts, print charges.
			self::erase_account_data( $user_id, $state );

			// 4. Subscription row.
			// get_row() returns null on a DB error too; tell it apart
			// from "no row" so invoices are not mistaken for absent.
			global $wpdb;
			$wpdb->last_error = '';
			$row              = Plandose_Subscriptions::get_row( $user_id, false );

			if ( '' !== $wpdb->last_error ) {
				$state['errors']['subscription_read'] = __( 'Σφάλμα βάσης δεδομένων: η εγγραφή συνδρομής και τα στοιχεία ταυτότητας ΔΕΝ διαγράφηκαν, επειδή δεν ήταν δυνατός ο έλεγχος για τιμολόγια. Επαναλάβετε το αίτημα διαγραφής.', 'plandose' );
				continue;
			}

			$invoices = $row ? Plandose_Subscriptions::decode_invoices( $row->invoices ) : array();
			// Only a LIVE account has a contract to perform;
			// a deleted account's active Pro row is erased like any other.
			$active   = ! $is_deleted && $row && Plandose_Subscriptions::is_pro_active( $row );

			if ( $active ) {
				++$state['stats']['subscription_rows_kept_pro'];
				$state['retained']['subscription_active'] = sprintf(
					/* translators: %s: last day of the Pro subscription (Y-m-d) */
					__( 'Διατηρήθηκε η εγγραφή της ενεργής συνδρομής Pro (λήξη: %s). Λόγος: είναι απαραίτητη για την εκτέλεση της σύμβασης που βρίσκεται σε ισχύ. Μπορεί να διαγραφεί με νέο αίτημα διαγραφής μετά τη λήξη της συνδρομής.', 'plandose' ),
					(string) $row->sub_end_date
				);
			}

			if ( ! empty( $invoices ) ) {
				++$state['stats']['subscription_rows_kept_tax'];
				$state['stats']['invoices_retained'] += count( $invoices );
			} elseif ( $row && ! $active && ! $is_deleted && Plandose_Subscriptions::current_month_count( $row ) > 0 ) {
				// Live account with prints this month — keep only the
				// counter, or the next print would start the month from 0.
				$result = Plandose_Subscriptions::minimize_row( $user_id );

				if ( false === $result ) {
					$state['errors']['subscription'] = __( 'Σφάλμα βάσης δεδομένων: η εγγραφή συνδρομής ΔΕΝ διαγράφηκε. Επαναλάβετε το αίτημα διαγραφής.', 'plandose' );
				} else {
					++$state['stats']['subscription_rows_minimized'];

					if ( $result ) {
						$state['removed']['subscription_details'] = __( 'Διαγράφηκαν τα στοιχεία της συνδρομής (κατάσταση Pro, ημερομηνία λήξης, ώρα τελευταίας εκτύπωσης).', 'plandose' );
					}

					$state['retained']['print_counter'] = __( 'Διατηρήθηκε μόνο ο μετρητής εκτυπώσεων του τρέχοντος μήνα (πλήθος εκτυπώσεων και μήνας), χωρίς άλλα στοιχεία. Λόγος: είναι το μηνιαίο όριο δωρεάν εκτυπώσεων του λογαριασμού, που παραμένει ενεργός· αν διαγραφόταν, το όριο θα ξεκινούσε ξανά από την αρχή. Από τον επόμενο μήνα ο μετρητής δεν αφορά πλέον το όριο και μπορεί να διαγραφεί με νέο αίτημα διαγραφής.', 'plandose' );
				}
			} elseif ( $row && ! $active ) {
				$result = Plandose_Subscriptions::delete_row( $user_id );

				if ( false === $result ) {
					$state['errors']['subscription'] = __( 'Σφάλμα βάσης δεδομένων: η εγγραφή συνδρομής ΔΕΝ διαγράφηκε. Επαναλάβετε το αίτημα διαγραφής.', 'plandose' );
				} elseif ( $result ) {
					++$state['stats']['subscription_rows_deleted'];
					$state['removed']['subscription'] = __( 'Διαγράφηκε η εγγραφή συνδρομής (κατάσταση, ημερομηνία λήξης, μετρητές εκτυπώσεων).', 'plandose' );
				}
			}

			// 5. The identity snapshot of a deleted account exists only to
			// say whose retained invoices these are. With no invoices left
			// it has no purpose and is erased; otherwise it is kept with
			// them and reported as retained.
			if ( $is_deleted && empty( $invoices ) && class_exists( 'Plandose_Admin' ) ) {
				Plandose_Admin::forget_deleted_account( $user_id );
				++$state['stats']['identity_snapshots_erased'];
				$state['removed']['snapshot'] = __( 'Διαγράφηκαν τα στοιχεία ταυτότητας (επωνυμία, email, ΑΦΜ) διαγραμμένου λογαριασμού, αφού δεν υπάρχουν πλέον τιμολόγια.', 'plandose' );
			}
		}

		$retained_invoices = (int) $state['stats']['invoices_retained'];

		if ( $retained_invoices > 0 ) {
			$state['retained']['invoices'] = sprintf(
				/* translators: %d: number of invoice files kept */
				_n(
					'Διατηρήθηκε %d τιμολόγιο και η σχετική εγγραφή συνδρομής, λόγω της υποχρέωσης διατήρησης φορολογικών παραστατικών. Μετά τη λήξη της προβλεπόμενης περιόδου πρέπει να διαγραφούν χειροκίνητα, σύμφωνα με την πολιτική διατήρησης του υπευθύνου επεξεργασίας.',
					'Διατηρήθηκαν %d τιμολόγια και η σχετική εγγραφή συνδρομής, λόγω της υποχρέωσης διατήρησης φορολογικών παραστατικών. Μετά τη λήξη της προβλεπόμενης περιόδου πρέπει να διαγραφούν χειροκίνητα, σύμφωνα με την πολιτική διατήρησης του υπευθύνου επεξεργασίας.',
					$retained_invoices,
					'plandose'
				),
				$retained_invoices
			);
		}

		if ( ! empty( $deleted ) && $retained_invoices > 0 ) {
			$state['retained']['snapshot'] = __( 'Για διαγραμμένο λογαριασμό φαρμακείου διατηρούνται μαζί με τα τιμολόγια η επωνυμία, το email και ο ΑΦΜ του, ώστε να είναι γνωστό σε ποιον ανήκουν. Διαγράφονται μαζί με τα τιμολόγια από το PlanDose → Συνδρομές → «Τιμολόγια διαγραμμένων λογαριασμών».', 'plandose' );
		}

		$response['items_removed']  = ! empty( $state['removed'] );
		$response['items_retained'] = ! empty( $state['retained'] ) || ! empty( $state['errors'] );
		$response['messages']       = array_values(
			array_merge( $state['removed'], $state['retained'], $state['errors'] )
		);

		if ( empty( $response['messages'] ) ) {
			$response['messages'][] = __( 'Το PlanDose δεν είχε αποθηκευμένα δεδομένα προς διαγραφή για αυτό το πρόσωπο.', 'plandose' );
		}

		// 6. Record the erasure itself: account ID(s), counts and flags —
		// never the subject's email, name or IP. Written after the audit
		// rows were anonymized, so it is not caught by that step.
		if ( class_exists( 'Plandose_Admin' ) && method_exists( 'Plandose_Admin', 'audit' ) ) {
			$meta             = $state['stats'];
			$meta['removed']  = array_keys( $state['removed'] );
			$meta['retained'] = array_keys( $state['retained'] );
			$meta['errors']   = array_keys( $state['errors'] );

			Plandose_Admin::audit( 'privacy_erasure', (int) $subject_ids[0], $meta );
		}

		return $response;
	}

	/**
	 * Step 1 of erase(): the manual access override of a live account.
	 *
	 * 'deny' is kept and reported (see erase()). Anything else stored under
	 * the meta key — a legacy 'allow', or a value get_manual_access() does
	 * not even recognize — is removed, and the removal is verified against
	 * the raw meta rather than trusted.
	 *
	 * @param int   $user_id Live user ID.
	 * @param array $state   erase() bookkeeping, updated in place.
	 */
	private static function erase_manual_access( $user_id, &$state ) {
		if ( 'deny' === Plandose_Access::get_manual_access( $user_id ) ) {
			$state['stats']['access_override'] = 'deny_retained';
			$state['retained']['access_deny']  = __( 'Διατηρήθηκε η χειροκίνητη απαγόρευση πρόσβασης στο PlanDose. Λόγος: είναι απόφαση ελέγχου πρόσβασης του υπευθύνου επεξεργασίας, για την πρόληψη καταχρήσεων και απάτης· αν διαγραφόταν, ο λογαριασμός θα αποκτούσε ξανά πρόσβαση.', 'plandose' );
			return;
		}

		$raw = get_user_meta( $user_id, Plandose_Access::MANUAL_ACCESS_META_KEY, true );

		if ( '' === $raw || null === $raw || false === $raw || array() === $raw ) {
			return;
		}

		Plandose_Access::set_manual_access( $user_id, '' );

		$left = get_user_meta( $user_id, Plandose_Access::MANUAL_ACCESS_META_KEY, true );

		if ( '' === $left || null === $left || false === $left ) {
			$state['stats']['access_override'] = 'cleared';
			$state['removed']['access']        = __( 'Διαγράφηκε η χειροκίνητη ρύθμιση πρόσβασης στο PlanDose.', 'plandose' );
		} else {
			$state['stats']['access_override'] = 'error';
			$state['errors']['access']         = __( 'Σφάλμα βάσης δεδομένων: η χειροκίνητη ρύθμιση πρόσβασης ΔΕΝ διαγράφηκε. Επαναλάβετε το αίτημα διαγραφής.', 'plandose' );
		}
	}

	/**
	 * Erase the audit-log personal data, the print history, the print
	 * receipts and the print charges of one account ID (live or deleted).
	 *
	 * Audit rows are anonymized rather than deleted, so the record that an
	 * action happened survives while the personal data in it does not.
	 * Each column is erased only for the person it belongs to: `meta`
	 * describes what was done to the target, `ip` is the acting admin's.
	 * Clearing the admin's IP on a target's request would erase a third
	 * party's data.
	 *
	 * The monthly print history is deleted unconditionally: the retention
	 * argument covers accounting documents, not a usage profile.
	 *
	 * Reports into erase()'s $state instead of returning a bool, so
	 * a failed query ($wpdb->query() === false) becomes an error message
	 * rather than a silent "done".
	 *
	 * @param int   $user_id User ID.
	 * @param array $state   erase() bookkeeping, updated in place.
	 */
	private static function erase_account_data( $user_id, &$state ) {
		global $wpdb;

		$user_id     = (int) $user_id;
		$audit_table = Plandose_Subscriptions::audit_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table; $audit_table is Plandose_Subscriptions::audit_table_name() ($wpdb->prefix + fixed suffix), not user input.
		$meta_cleared = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$audit_table}` SET meta = '{}' WHERE target_user_id = %d AND meta <> '{}'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + fixed suffix; every value is bound.
				$user_id
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table; $audit_table is Plandose_Subscriptions::audit_table_name() ($wpdb->prefix + fixed suffix), not user input.
		$ip_cleared = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$audit_table}` SET ip = '' WHERE admin_user_id = %d AND ip <> ''", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + fixed suffix; every value is bound.
				$user_id
			)
		);

		if ( false === $meta_cleared || false === $ip_cleared ) {
			$state['errors']['audit'] = __( 'Σφάλμα βάσης δεδομένων: οι εγγραφές του αρχείου ενεργειών (audit log) ΔΕΝ ανωνυμοποιήθηκαν πλήρως. Επαναλάβετε το αίτημα διαγραφής.', 'plandose' );
		}

		$state['stats']['audit_meta_cleared'] += (int) $meta_cleared;
		$state['stats']['audit_ip_cleared']   += (int) $ip_cleared;

		if ( (int) $meta_cleared > 0 || (int) $ip_cleared > 0 ) {
			$state['removed']['audit'] = __( 'Ανωνυμοποιήθηκαν οι εγγραφές του αρχείου ενεργειών (audit log) που αφορούν το πρόσωπο: αφαιρέθηκαν οι λεπτομέρειες και οι διευθύνσεις IP του.', 'plandose' );
		}

		if ( method_exists( 'Plandose_Subscriptions', 'delete_history_for_user' ) ) {
			$history = Plandose_Subscriptions::delete_history_for_user( $user_id );

			if ( false === $history ) {
				$state['errors']['history'] = __( 'Σφάλμα βάσης δεδομένων: το μηνιαίο ιστορικό εκτυπώσεων ΔΕΝ διαγράφηκε. Επαναλάβετε το αίτημα διαγραφής.', 'plandose' );
			} elseif ( $history ) {
				$state['stats']['print_history_rows_deleted'] += (int) $history;
				$state['removed']['history']                   = __( 'Διαγράφηκε το μηνιαίο ιστορικό εκτυπώσεων.', 'plandose' );
			}
		}

		// The short-lived print receipts (hashes of print tokens,
		// see Plandose_Ajax::PRINT_RECEIPT_META_KEY).
		if (
			class_exists( 'Plandose_Ajax' ) &&
			defined( 'Plandose_Ajax::PRINT_RECEIPT_META_KEY' ) &&
			method_exists( 'Plandose_Ajax', 'delete_print_receipts' ) &&
			'' !== get_user_meta( $user_id, Plandose_Ajax::PRINT_RECEIPT_META_KEY, true )
		) {
			Plandose_Ajax::delete_print_receipts( $user_id );

			if ( '' === get_user_meta( $user_id, Plandose_Ajax::PRINT_RECEIPT_META_KEY, true ) ) {
				$state['stats']['print_receipts_deleted'] = true;
				$state['removed']['receipts']             = __( 'Διαγράφηκαν τα προσωρινά τεχνικά αναγνωριστικά εκτυπώσεων.', 'plandose' );
			} else {
				$state['errors']['receipts'] = __( 'Σφάλμα βάσης δεδομένων: τα προσωρινά τεχνικά αναγνωριστικά εκτυπώσεων ΔΕΝ διαγράφηκαν. Επαναλάβετε το αίτημα διαγραφής.', 'plandose' );
			}
		}

		// The per-user request counters (rate limit, a few seconds
		// each) go too, not only when the account is deleted.
		if ( class_exists( 'Plandose_Ajax' ) && method_exists( 'Plandose_Ajax', 'delete_rate_limit_for_user' ) ) {
			Plandose_Ajax::delete_rate_limit_for_user( $user_id );
		}

		// Print charges — the user ID, token hashes, timestamps and
		// reprint counters; no plan data. Nothing in them needs keeping.
		if ( class_exists( 'Plandose_Print_Charges' ) && method_exists( 'Plandose_Print_Charges', 'delete_for_user' ) ) {
			$charges = Plandose_Print_Charges::delete_for_user( $user_id );

			if ( false === $charges ) {
				$state['errors']['charges'] = __( 'Σφάλμα βάσης δεδομένων: οι εγγραφές χρέωσης εκτυπώσεων ΔΕΝ διαγράφηκαν. Επαναλάβετε το αίτημα διαγραφής.', 'plandose' );
			} elseif ( $charges > 0 ) {
				$state['stats']['print_charges_deleted'] = true;
				$state['removed']['charges']             = __( 'Διαγράφηκαν οι εγγραφές χρέωσης εκτυπώσεων (τεχνικά αναγνωριστικά και χρονοσημάνσεις).', 'plandose' );
			}
		}

		// The print log — dates and kinds of prints; nothing in it
		// needs keeping.
		if ( class_exists( 'Plandose_Print_Log' ) ) {
			$log = Plandose_Print_Log::delete_for_user( $user_id );

			if ( false === $log ) {
				$state['errors']['print_log'] = __( 'Σφάλμα βάσης δεδομένων: το ιστορικό εκτυπώσεων ΔΕΝ διαγράφηκε. Επαναλάβετε το αίτημα διαγραφής.', 'plandose' );
			} elseif ( $log > 0 ) {
				$state['removed']['print_log'] = __( 'Διαγράφηκε το ιστορικό εκτυπώσεων (ημερομηνίες και είδος εκτύπωσης).', 'plandose' );
			}
		}
	}

	/**
	 * Suggested privacy-policy text, shown to the admin in the Privacy
	 * Policy Guide so they can paste it into their own policy.
	 */
	public static function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content =
			'<p class="privacy-policy-tutorial">' .
			esc_html__( 'Προτεινόμενο κείμενο για το PlanDose. Προσαρμόστε το στην πραγματική πρακτική του φαρμακείου σας.', 'plandose' ) .
			'</p>' .
			'<p><strong>' . esc_html__( 'Τι δεν αποθηκεύεται', 'plandose' ) . '</strong></p>' .
			'<p>' . esc_html__( 'Τα δεδομένα του πλάνου δοσολογίας (όνομα ασθενή, φάρμακα, δόσεις, ημέρες, σημειώσεις) δεν αποστέλλονται και δεν αποθηκεύονται στον διακομιστή. Υπάρχουν μόνο προσωρινά στον browser του φαρμακοποιού, για όσο διαρκεί η δημιουργία και η εκτύπωση του πλάνου.', 'plandose' ) . '</p>' .
			'<p><strong>' . esc_html__( 'Τι αποθηκεύεται', 'plandose' ) . '</strong></p>' .
			'<p>' . esc_html__( 'Για κάθε εγγεγραμμένο φαρμακείο τηρούνται: η κατάσταση της συνδρομής και η ημερομηνία λήξης της, ο μηνιαίος μετρητής εκτυπώσεων και το ιστορικό του ανά μήνα (μόνο συνολικοί αριθμοί, χωρίς καμία πληροφορία για ασθενείς ή φάρμακα), για 30 λεπτά ένα τεχνικό αναγνωριστικό κάθε εκτύπωσης (ώστε μια επανάληψη να μη χρεώνεται δεύτερη φορά), για 12 μήνες ένα ιστορικό εκτυπώσεων (ημερομηνία και ώρα, αν χρεώθηκε ή ήταν δωρεάν επανεκτύπωση, Free/Pro — χωρίς καμία πληροφορία για ασθενείς ή φάρμακα), τυχόν χειροκίνητη ρύθμιση πρόσβασης, καθώς και τα τιμολόγια που έχει ανεβάσει ο διαχειριστής μαζί με τα μεταδεδομένα τους. Τηρείται επίσης αρχείο ενεργειών διαχειριστή, το οποίο μπορεί να περιλαμβάνει διεύθυνση IP.', 'plandose' ) . '</p>' .
			'<p><strong>' . esc_html__( 'Χρόνος διατήρησης', 'plandose' ) . '</strong></p>' .
			'<p>' . esc_html__( 'Το αρχείο ενεργειών διαγράφεται αυτόματα μετά το διάστημα που ορίζεται στις ρυθμίσεις του PlanDose. Τα τιμολόγια διατηρούνται για όσο χρόνο επιβάλλει η φορολογική νομοθεσία και δεν διαγράφονται με αίτημα διαγραφής δεδομένων. Αν διαγραφεί ο λογαριασμός ενός φαρμακείου, μαζί με τα τιμολόγιά του διατηρούνται η επωνυμία, το email και ο ΑΦΜ του, μέχρι να διαγραφούν τα τιμολόγια.', 'plandose' ) . '</p>' .
			'<p>' . esc_html__( 'Με αίτημα διαγραφής δεδομένων επίσης δεν διαγράφονται: τυχόν απαγόρευση πρόσβασης που έχει ορίσει ο διαχειριστής (για την πρόληψη καταχρήσεων), η εγγραφή μιας ενεργής συνδρομής Pro, μέχρι τη λήξη της, και, για λογαριασμό που παραμένει ενεργός, ο μετρητής εκτυπώσεων του τρέχοντος μήνα (μόνο το πλήθος), ώστε να ισχύει το μηνιαίο όριο. Οι εγγραφές του αρχείου ενεργειών που αφορούν το πρόσωπο ανωνυμοποιούνται.', 'plandose' ) . '</p>';

		wp_add_privacy_policy_content( __( 'PlanDose', 'plandose' ), $content );
	}
}