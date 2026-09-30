<?php
/**
 * One-time migration of legacy Media Library attachment invoices into the
 * filename-based storage owned by Plandose_Invoice_Storage.
 *
 * Once every site has run it, this whole file can be deleted without
 * touching anything else.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plandose_Invoice_Migration {

	/** @var bool Guards against registering the hooks twice. */
	private static $initialized = false;

	/**
	 * Register the migration's own hooks.
	 *
	 * They live here, not in Plandose_Admin_Invoices::init(), so that
	 * deleting this file, once every site has migrated, removes the
	 * feature completely and leaves no dangling
	 * callback behind.
	 */
	public static function init() {
		if ( self::$initialized ) {
			return;
		}

		self::$initialized = true;

		// Only a PlanDose screen triggers the migration, and the
		// copying runs in a WP-Cron event (see maybe_migrate_legacy_invoices()).
		add_action( 'current_screen', array( __CLASS__, 'maybe_migrate_legacy_invoices' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_legacy_migration_notice' ) );
		add_action( 'admin_post_plandose_retry_invoice_migration', array( __CLASS__, 'handle_retry_migration' ) );
		self::init_cron();
	}

	/** @var bool Guards the cron hook registration. */
	private static $cron_initialized = false;

	/**
	 * The cron callback. Registered on every request (WP-Cron runs
	 * outside wp-admin), from Plandose_Diagnostics::init() as well as init().
	 */
	public static function init_cron() {
		if ( self::$cron_initialized ) {
			return;
		}

		self::$cron_initialized = true;

		add_action( self::CRON_HOOK, array( __CLASS__, 'run_cron_batch' ) );
	}

	/** Single WP-Cron event that runs one budgeted batch. */
	const CRON_HOOK = 'plandose_legacy_invoice_migration';

	/** Per-run budget in the cron event / a manual retry. */
	const BUDGET_SECONDS = 20;
	const BUDGET_FILES   = 25;

	/**
	 * Smaller budget for the fallback batch run inside a PlanDose
	 * page load, only when WP-Cron has not run the event for a while.
	 */
	const INLINE_BUDGET_SECONDS = 5;
	const INLINE_BUDGET_FILES   = 10;

	/** An event this late means WP-Cron is not running. */
	const CRON_OVERDUE = 900;

	/**
	 * Copies made in the current row but not yet written to the
	 * database. A request killed in between (time limit) leaves them here;
	 * the next run deletes those that nothing references
	 * (cleanup_unrecorded_copies()).
	 */
	const PENDING_COPIES_OPTION = 'plandose_legacy_invoice_migration_pending';

	/** File names of the migration's own copies (lm-<32>.<ext>). */
	const COPY_PREFIX  = 'lm-';
	const COPY_PATTERN = '/^lm-[A-Za-z0-9]{32}\.(pdf|jpg|jpeg|png|webp)\z/';

	/**
	 * Option flag marking that the legacy Media-Library-attachment invoice
	 * migration (see maybe_migrate_legacy_invoices()) has already run, so
	 * it never repeats its work on every page load.
	 */
	const LEGACY_MIGRATION_OPTION = 'plandose_legacy_invoice_migration_done';

	/**
	 * The entries the current (or last) pass could NOT convert.
	 *
	 * The done flag is set only when a pass ends with no failures, so a
	 * pass whose files all failed is never marked done and forgotten. A
	 * pass that ends WITH failures records them here with
	 * 'complete' => true and stops retrying on its own — a missing file
	 * will not appear by itself, and retrying it on every admin page load
	 * would only cost time — until an administrator presses
	 * «Επανάληψη μεταφοράς τιμολογίων» (handle_retry_migration()).
	 *
	 * Shape: array{ files:int, entries:array<int,int[]>, complete:bool, time:int }
	 * — entries maps pharmacy user ID to the attachment IDs that failed.
	 */
	const LEGACY_MIGRATION_FAILED_OPTION = 'plandose_legacy_invoice_migration_failed';

	/**
	 * Set once the one-time 1.21.0 re-check has run (see
	 * maybe_schedule_1210_rerun()). Sites migrated by releases before
	 * 1.21.0 were marked done while their legacy entries all failed;
	 * this re-opens the migration exactly once on such sites.
	 */
	const LEGACY_MIGRATION_RERUN_OPTION = 'plandose_legacy_invoice_migration_rerun_1_21_0';

	/**
	 * Set once the one-time 1.24.0 re-check has run (see
	 * maybe_schedule_1240_rerun()). On releases before 1.24.0 every legacy
	 * entry of a DELETED account's retained row failed, so a pass ended
	 * "complete with failures" and waited for «Επανάληψη» forever. Such rows
	 * can be written now, so a recorded failure state is cleared exactly
	 * once and the migration runs again by itself.
	 */
	const LEGACY_MIGRATION_RERUN_1240_OPTION = 'plandose_legacy_invoice_migration_rerun_1_24_0';

	/** At most this many pharmacies are listed in the failed option. */
	const LEGACY_MIGRATION_FAILED_MAX_USERS = 200;

	/**
	 * Transient behind the one-time result notice: the totals of the
	 * current pass, summed over its batches (see add_result()).
	 */
	const RESULT_TRANSIENT = 'plandose_legacy_migration_result';

	/**
	 * Tracks the last user_id processed by maybe_migrate_legacy_invoices(),
	 * so each run picks up strictly after where the previous one left off
	 * (WHERE user_id > cursor) instead of re-selecting the same LIMIT
	 * LEGACY_MIGRATION_BATCH_SIZE rows every time. Without this, a site
	 * with more non-empty-invoices rows than one batch would have every
	 * run re-fetch the same first batch — once those rows no longer have
	 * any numeric (legacy) entries left to convert, nothing about them
	 * changes on a re-fetch, the batch never comes back short, and
	 * LEGACY_MIGRATION_OPTION would never get marked done. Deleted once
	 * the migration itself is marked done (see below) — it has no
	 * further use at that point.
	 */
	const LEGACY_MIGRATION_CURSOR_OPTION = 'plandose_legacy_invoice_migration_cursor';

	/**
	 * How many subscriber rows the legacy migration inspects per run (on
	 * top of the time/copy budget). If a site has more legacy rows than
	 * this, the migration leaves the option unset and the next run resumes
	 * from LEGACY_MIGRATION_CURSOR_OPTION rather than re-inspecting rows it
	 * already passed.
	 */
	const LEGACY_MIGRATION_BATCH_SIZE = 300;

	/**
	 * Short-lived lock so two admins loading admin pages at nearly the same
	 * time can't both run the legacy migration over the same batch — which
	 * would copy each attachment twice (only one filename set ends up
	 * referenced by the row; the other becomes an orphan on disk). Claimed
	 * through Plandose_Lock, the same primitive the print lock uses. TTL is
	 * a generous ceiling for a single bounded batch; a lock older than this
	 * is treated as abandoned by an interrupted request and reclaimed.
	 */
	const LEGACY_MIGRATION_LOCK_OPTION = 'plandose_legacy_invoice_migration_lock';
	const LEGACY_MIGRATION_LOCK_TTL    = 300;

	/**
	 * Acquire the migration lock, or reclaim one abandoned by an
	 * interrupted run.
	 *
	 * Both steps go through Plandose_Lock, which decides on MySQL's
	 * affected-row count rather than on a read. add_option() is not enough:
	 * the UNIQUE key on wp_options.option_name does not admit only one
	 * concurrent insert, because core's add_option() is a get_option() check
	 * followed by an INSERT ... ON DUPLICATE KEY UPDATE, so the key never
	 * rejects anything and two admins landing on an admin page at the same
	 * moment could both be told they held the lock. Both would then copy the
	 * same batch of attachments, and one full set of copies would end up
	 * referenced by nothing: orphaned invoice files on disk, which is exactly
	 * what this lock exists to prevent. See the Plandose_Lock file docblock.
	 *
	 * The reclaim step is a compare-and-swap.
	 *
	 * @return bool True when this request now holds the lock.
	 */
	private static function acquire_legacy_migration_lock() {
		$now = time();

		if ( Plandose_Lock::claim( self::LEGACY_MIGRATION_LOCK_OPTION, $now ) ) {
			self::$lock_value = $now;
			return true;
		}

		$existing = absint( get_option( self::LEGACY_MIGRATION_LOCK_OPTION, 0 ) );

		if ( $existing > 0 && ( $now - $existing ) < self::LEGACY_MIGRATION_LOCK_TTL ) {
			return false;
		}

		// Stale lock: take it over only while it still holds the timestamp
		// this request just read, so of several concurrent reclaim attempts
		// exactly one changes the row and the rest match nothing and back
		// off. $now can never equal $existing here — a lock is only
		// reclaimable once it is at least TTL seconds old — so a matched
		// row is always a changed row.
		if ( Plandose_Lock::claim_if_unchanged( self::LEGACY_MIGRATION_LOCK_OPTION, $existing, $now ) ) {
			self::$lock_value = $now;
			return true;
		}

		return false;
	}

	/**
	 * Value this request claimed the migration lock with.
	 *
	 * @var int|null
	 */
	private static $lock_value = null;

	/**
	 * Release the migration lock — only while this request still
	 * owns it. A batch that ran past the TTL may have had its lock taken
	 * over; deleting that newer holder's lock would let a third run start.
	 */
	private static function release_legacy_migration_lock() {
		if ( null !== self::$lock_value ) {
			Plandose_Lock::release_if_owned( self::LEGACY_MIGRATION_LOCK_OPTION, self::$lock_value );
			self::$lock_value = null;
		}
	}

	/**
	 * On a PlanDose screen (not every admin page): make sure a
	 * pending migration has its WP-Cron event. Only when WP-Cron has left
	 * that event overdue does the page itself run a small batch.
	 *
	 * @param WP_Screen|null $screen Current screen (unused; read through on_plandose_screen()).
	 */
	public static function maybe_migrate_legacy_invoices( $screen = null ) {
		unset( $screen );

		if ( ! class_exists( 'Plandose_Admin_Invoices' ) || ! Plandose_Admin_Invoices::on_plandose_screen() ) {
			return;
		}

		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		self::maybe_schedule_1210_rerun();
		self::maybe_schedule_1240_rerun();

		if ( ! self::migration_pending() ) {
			return;
		}

		$next = wp_next_scheduled( self::CRON_HOOK );

		if ( false === $next ) {
			wp_schedule_single_event( time(), self::CRON_HOOK );
			return;
		}

		if ( $next > time() - self::CRON_OVERDUE ) {
			return;
		}

		// WP-Cron is not running (DISABLE_WP_CRON without a system cron,
		// blocked loopback): a small batch here keeps the migration moving.
		if ( ! self::acquire_legacy_migration_lock() ) {
			return;
		}

		try {
			self::cleanup_unrecorded_copies();
			// Cached privacy verdict only (see usable_storage()): this is an
			// ordinary page load, and the live probe is several HTTP
			// requests with their own timeouts.
			self::run_legacy_invoice_migration( false, self::INLINE_BUDGET_SECONDS, self::INLINE_BUDGET_FILES, false );
		} finally {
			self::release_legacy_migration_lock();
		}
	}

	/**
	 * The WP-Cron event. One budgeted batch; while work remains, the
	 * next event is scheduled.
	 */
	public static function run_cron_batch() {
		if ( ! self::migration_pending() ) {
			return;
		}

		// Busy (a page-load batch or a manual retry holds the lock): this
		// event has already been consumed, so returning without a successor
		// would end the chain — and with it the migration, until someone
		// happens to open a PlanDose screen. Try again shortly instead.
		if ( ! self::acquire_legacy_migration_lock() ) {
			self::$blocked = false;
			self::schedule_next_batch();
			return;
		}

		try {
			self::cleanup_unrecorded_copies();
			self::run_legacy_invoice_migration( false, self::BUDGET_SECONDS, self::BUDGET_FILES );
		} finally {
			self::release_legacy_migration_lock();
		}

		self::schedule_next_batch();
	}

	/**
	 * Schedule the next batch if the migration is not finished.
	 */
	private static function schedule_next_batch() {
		if ( self::migration_pending() && false === wp_next_scheduled( self::CRON_HOOK ) ) {
			// A run blocked by the storage (folder not writable,
			// or not verified private) waits an hour, not 30 seconds.
			wp_schedule_single_event( time() + ( self::$blocked ? self::BLOCKED_RETRY_SECONDS : 30 ), self::CRON_HOOK );
		}
	}

	/**
	 * The last run stopped because the invoice storage cannot be
	 * used (see run_legacy_invoice_migration()).
	 *
	 * @var bool
	 */
	private static $blocked = false;

	/**
	 * The invoice folder, if it may receive copies now: usable and
	 * verified private (as for uploads), else the reason as a WP_Error.
	 *
	 * @param bool $probe False on a page load: decide on the cached, fresh
	 *                    privacy verdict only and never run the live probe
	 *                    (see Plandose_Invoice_Storage::upload_allowed()).
	 * @return string|WP_Error
	 */
	private static function usable_storage( $probe = true ) {
		$dir = Plandose_Invoice_Storage::invoice_dir();

		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$allowed = Plandose_Invoice_Storage::upload_allowed( $probe );

		return is_wp_error( $allowed ) ? $allowed : $dir;
	}

	/** Retry delay after a blocked run. */
	const BLOCKED_RETRY_SECONDS = HOUR_IN_SECONDS;

	/**
	 * Whether a migration pass still has work to do — not done, and
	 * not a finished pass waiting for «Επανάληψη».
	 *
	 * @return bool
	 */
	public static function migration_pending() {
		if ( get_option( self::LEGACY_MIGRATION_OPTION ) ) {
			return false;
		}

		$failed = self::failed_state();

		return empty( $failed['complete'] );
	}

	/**
	 * Delete copies an interrupted run made but never recorded.
	 *
	 * Only names in PENDING_COPIES_OPTION that match the migration's own
	 * naming (COPY_PATTERN) and that no invoice list references are
	 * deleted. Runs under the migration lock, so no other run is copying.
	 * On a database error nothing is deleted and the journal is kept.
	 *
	 * @return int Files deleted.
	 */
	public static function cleanup_unrecorded_copies() {
		$pending = get_option( self::PENDING_COPIES_OPTION, array() );

		if ( empty( $pending ) || ! is_array( $pending ) ) {
			return 0;
		}

		$names = array();

		foreach ( $pending as $name ) {
			$name = (string) $name;

			if ( 1 === preg_match( self::COPY_PATTERN, $name ) ) {
				$names[ $name ] = true;
			}
		}

		$referenced = self::referenced_filenames( array_keys( $names ) );

		if ( null === $referenced ) {
			return 0;
		}

		$dir = Plandose_Invoice_Storage::configured_invoice_dir();

		if ( is_wp_error( $dir ) ) {
			return 0;
		}

		$deleted = 0;
		$forget  = array();
		$keep    = array();

		foreach ( array_keys( $names ) as $name ) {
			if ( isset( $referenced[ $name ] ) ) {
				continue;
			}

			$path = trailingslashit( $dir ) . $name;

			if ( is_file( $path ) && ! is_link( $path ) ) {
				if ( Plandose_Invoice_Storage::fs_delete( $path, 'Deleting unrecorded legacy migration copy' ) ) {
					++$deleted;
				} else {
					// Still on disk: stays journaled, so the next run
					// tries again instead of leaving an untracked orphan.
					$keep[] = $name;
				}
			}

			if ( class_exists( 'Plandose_Admin_Invoices' ) ) {
				foreach ( Plandose_Admin_Invoices::legacy_originals() as $attachment_id => $entry ) {
					$copies = array( $entry['filename'] );

					foreach ( isset( $entry['extra'] ) ? $entry['extra'] : array() as $extra ) {
						$copies[] = $extra['filename'];
					}

					if ( in_array( $name, $copies, true ) ) {
						$forget[] = array(
							'attachment_id' => (int) $attachment_id,
							'filename'      => $name,
						);
					}
				}
			}
		}

		if ( $forget ) {
			Plandose_Admin_Invoices::forget_legacy_originals( $forget );
		}

		self::save_journal( $keep );

		if ( $deleted > 0 ) {
			Plandose_Admin::audit( 'legacy_invoice_migration_cleanup', 0, array( 'deleted' => $deleted ) );
		}

		return $deleted;
	}

	/**
	 * Which of these file names some invoice list references. Chunked.
	 *
	 * @param string[] $names File names.
	 * @return array<string,true>|null Null on a database error.
	 */
	private static function referenced_filenames( array $names ) {
		global $wpdb;

		$wanted = array_fill_keys( $names, true );
		$found  = array();

		if ( empty( $wanted ) ) {
			return $found;
		}

		$table  = Plandose_Subscriptions::table_name();
		$cursor = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; batch scans and audit writes must hit live data, not a cache.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT user_id, invoices FROM {$table} WHERE user_id > %d AND invoices IS NOT NULL AND invoices != '' ORDER BY user_id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is from $wpdb->prefix.
					$cursor,
					500
				)
			);

			if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
				return null;
			}

			foreach ( $rows as $row ) {
				$cursor = max( $cursor, (int) $row->user_id );

				foreach ( Plandose_Subscriptions::decode_invoices( $row->invoices ) as $entry ) {
					if ( isset( $wanted[ (string) $entry ] ) ) {
						$found[ (string) $entry ] = true;
					}
				}
			}
		} while ( count( $rows ) === 500 );

		return $found;
	}

	/**
	 * Add one name to the pending-copies journal before the copy is made.
	 *
	 * @param string $name File name.
	 */
	private static function journal_copy( $name ) {
		$pending   = get_option( self::PENDING_COPIES_OPTION, array() );
		$pending   = is_array( $pending ) ? $pending : array();
		$pending[] = (string) $name;

		update_option( self::PENDING_COPIES_OPTION, array_values( array_unique( $pending ) ), false );
	}

	/**
	 * Drop names from the pending-copies journal: copies that are now
	 * recorded in the database or were deleted. A copy whose rollback
	 * failed must NOT be dropped — it stays on disk, and only the journal
	 * lets cleanup_unrecorded_copies() find it again.
	 *
	 * @param string[] $names File names.
	 */
	private static function unjournal( array $names ) {
		$pending = get_option( self::PENDING_COPIES_OPTION, array() );
		$pending = is_array( $pending ) ? array_map( 'strval', $pending ) : array();

		self::save_journal( array_diff( $pending, array_map( 'strval', $names ) ) );
	}

	/**
	 * Store the journal, or delete the option when nothing is left.
	 *
	 * @param string[] $names File names still pending.
	 */
	private static function save_journal( array $names ) {
		$names = array_values( array_unique( array_map( 'strval', $names ) ) );

		if ( empty( $names ) ) {
			delete_option( self::PENDING_COPIES_OPTION );
			return;
		}

		update_option( self::PENDING_COPIES_OPTION, $names, false );
	}

	/**
	 * One-time re-run on sites that releases before 1.21.0 marked done too
	 * early.
	 *
	 * Before 1.21.0 the done flag was set after a single pass regardless of
	 * the outcome, and in 1.20.0 and earlier every file failed its
	 * extension check. So a site can carry the done flag while its rows
	 * still hold legacy attachment IDs. If that is the case, the flag (and
	 * any cursor) is cleared once, so the normal batched migration runs
	 * again. Recorded as checked only when the check itself succeeded — a
	 * database error leaves it to be tried on a later admin page load.
	 */
	private static function maybe_schedule_1210_rerun() {
		if ( get_option( self::LEGACY_MIGRATION_RERUN_OPTION ) ) {
			return;
		}

		if ( get_option( self::LEGACY_MIGRATION_OPTION ) ) {
			$has_legacy = self::has_legacy_entries();

			if ( null === $has_legacy ) {
				return;
			}

			if ( $has_legacy ) {
				delete_option( self::LEGACY_MIGRATION_OPTION );
				delete_option( self::LEGACY_MIGRATION_CURSOR_OPTION );
				delete_option( self::LEGACY_MIGRATION_FAILED_OPTION );
				Plandose_Admin::audit( 'legacy_invoice_migration_rerun', 0, array( 'reason' => '1.21.0' ) );
			}
		}

		update_option( self::LEGACY_MIGRATION_RERUN_OPTION, 1 );
	}

	/**
	 * One-time re-open of a pass that ended with failures.
	 *
	 * Before 1.24.0 the legacy entries of a deleted account's retained row
	 * could never be written back (see LEGACY_MIGRATION_RERUN_1240_OPTION),
	 * so their copies were rolled back and counted as failures on every
	 * pass, and the pass then waited for «Επανάληψη» indefinitely. Clearing
	 * the recorded failures (and cursor) once lets the next admin page load
	 * start a fresh pass, which succeeds for those rows. The pass is
	 * idempotent — only entries that are still numeric are copied — so
	 * nothing is duplicated. Failures that remain afterwards (a file that is
	 * really missing) are recorded again.
	 */
	private static function maybe_schedule_1240_rerun() {
		if ( get_option( self::LEGACY_MIGRATION_RERUN_1240_OPTION ) ) {
			return;
		}

		$failed = self::failed_state();

		if ( ! empty( $failed['complete'] ) && $failed['files'] > 0 ) {
			delete_option( self::LEGACY_MIGRATION_FAILED_OPTION );
			delete_option( self::LEGACY_MIGRATION_CURSOR_OPTION );
			Plandose_Admin::audit( 'legacy_invoice_migration_rerun', 0, array( 'reason' => '1.24.0', 'previous_failed' => $failed['files'] ) );
		}

		update_option( self::LEGACY_MIGRATION_RERUN_1240_OPTION, 1, false );
	}

	/**
	 * Does any subscription row still hold a legacy (numeric attachment ID)
	 * invoice entry? Read in chunks so a large table is never loaded whole.
	 *
	 * @return bool|null Null when the query failed (do not decide anything).
	 */
	public static function has_legacy_entries() {
		global $wpdb;

		$table  = Plandose_Subscriptions::table_name();
		$cursor = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; batch scans and audit writes must hit live data, not a cache.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT user_id, invoices FROM {$table} WHERE user_id > %d AND invoices IS NOT NULL AND invoices != '' ORDER BY user_id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is from $wpdb->prefix (site-controlled); prepare() cannot parameterize identifiers.
					$cursor,
					500
				)
			);

			if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
				return null;
			}

			foreach ( $rows as $row ) {
				$cursor = max( $cursor, (int) $row->user_id );

				foreach ( Plandose_Subscriptions::decode_invoices( $row->invoices ) as $entry ) {
					if ( is_numeric( $entry ) ) {
						return true;
					}
				}
			}
		} while ( count( $rows ) === 500 );

		return false;
	}

	/**
	 * The recorded failures, normalized.
	 *
	 * @return array{files:int,entries:array,complete:bool,time:int}
	 */
	public static function failed_state() {
		$state = get_option( self::LEGACY_MIGRATION_FAILED_OPTION, array() );
		$state = is_array( $state ) ? $state : array();

		return array(
			'files'    => isset( $state['files'] ) ? absint( $state['files'] ) : 0,
			'entries'  => isset( $state['entries'] ) && is_array( $state['entries'] ) ? $state['entries'] : array(),
			'complete' => ! empty( $state['complete'] ),
			'time'     => isset( $state['time'] ) ? absint( $state['time'] ) : 0,
		);
	}

	/**
	 * «Επανάληψη μεταφοράς τιμολογίων»: start a fresh migration pass
	 * now. POST + PlanDose capability + nonce; audited.
	 *
	 * Everything happens under the migration lock, so a batch already
	 * running in another request is never reset underneath itself: if the
	 * lock is busy the admin is told to try again. The pass is idempotent —
	 * only entries that are still numeric are copied, and each row is
	 * rewritten under the per-pharmacy invoice lock — so pressing the button
	 * twice cannot duplicate anything. The first (budgeted) batch runs in
	 * this request; any further batches continue in WP-Cron events.
	 */
	public static function handle_retry_migration() {
		if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			wp_die( esc_html__( 'Μη επιτρεπτή μέθοδος αιτήματος.', 'plandose' ), '', array( 'response' => 405 ) );
		}

		if ( ! current_user_can( Plandose_Admin::capability() ) ) {
			wp_die( esc_html__( 'Δεν έχετε δικαίωμα πρόσβασης.', 'plandose' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'plandose_retry_invoice_migration' );

		$referer = wp_get_referer();
		$back    = $referer ? $referer : admin_url( 'admin.php?page=plandose-subscriptions' );

		if ( ! self::acquire_legacy_migration_lock() ) {
			set_transient( 'plandose_invoice_error_' . get_current_user_id(), __( 'Η μεταφορά τιμολογίων εκτελείται ήδη. Δοκιμάστε ξανά σε λίγα λεπτά.', 'plandose' ), 60 );
			wp_safe_redirect( $back );
			exit;
		}

		try {
			$previous = self::failed_state();

			delete_option( self::LEGACY_MIGRATION_OPTION );
			delete_option( self::LEGACY_MIGRATION_CURSOR_OPTION );
			delete_option( self::LEGACY_MIGRATION_FAILED_OPTION );

			Plandose_Admin::audit( 'legacy_invoice_migration_retry', 0, array( 'previous_failed' => $previous['files'] ) );

			self::cleanup_unrecorded_copies();
			self::run_legacy_invoice_migration( true, self::BUDGET_SECONDS, self::BUDGET_FILES );
		} finally {
			self::release_legacy_migration_lock();
		}

		self::schedule_next_batch();

		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * The «Επανάληψη μεταφοράς τιμολογίων» button. Public so any
	 * PlanDose screen can place it; render_legacy_migration_notice() already
	 * shows it on every PlanDose screen while failures are recorded.
	 */
	public static function render_retry_form() {
		if ( ! current_user_can( Plandose_Admin::capability() ) ) {
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pd-inline-form pd-retry-migration-form">
			<input type="hidden" name="action" value="plandose_retry_invoice_migration" />
			<?php wp_nonce_field( 'plandose_retry_invoice_migration' ); ?>
			<button type="submit" class="button"><?php esc_html_e( 'Επανάληψη μεταφοράς τιμολογίων', 'plandose' ); ?></button>
		</form>
		<?php
	}

	/**
	 * One-time migration: convert legacy Media Library attachment ID
	 * invoice entries into our own filename-based storage under
	 * invoice_dir(), the same format new uploads use.
	 *
	 * Why this exists: handle_view_invoice() supports numeric (attachment
	 * ID) entries as a read path so old sites keep working, and without a
	 * migration that legacy branch could never be retired. Media Library attachments also have a public attachment
	 * URL by default — something the whole point of the invoice_dir()
	 * design was to avoid — so a legacy row is weaker than a migrated one
	 * even though handle_view_invoice() never links that public URL itself.
	 *
	 * This copies (not moves) each attachment's file into invoice_dir()
	 * under a fresh random filename, exactly like a new upload, and
	 * rewrites that one array entry from an attachment ID to the new
	 * filename. The original Media Library attachment is left untouched
	 * here — something else on the site may still reference it — and it
	 * keeps its PUBLIC uploads/YYYY/MM/ URL. Every copied entry is
	 * therefore recorded through
	 * Plandose_Admin_Invoices::record_legacy_originals() (attachment ID →
	 * pharmacy, new filename, time), the notice says plainly that the
	 * originals still exist, and an administrator can remove them — after
	 * a SHA-256 comparison with the copy and reference checks — with
	 * «Διαγραφή πρωτοτύπων». Nothing is deleted automatically. Runs at most once per site (see
	 * LEGACY_MIGRATION_OPTION), in batches bounded by rows
	 * (LEGACY_MIGRATION_BATCH_SIZE), time and copied files ($budget_*).
	 *
	 * Always invoked under the migration lock.
	 *
	 * @param bool $manual         Report back even when nothing changed.
	 * @param int  $budget_seconds Stop before the next copy after this long.
	 * @param int  $budget_files   Stop after this many copies.
	 * @param bool $probe          May run the live privacy probe (false: page load, cached verdict only).
	 * @return bool Whether the run stopped on its budget.
	 */
	private static function run_legacy_invoice_migration( $manual = false, $budget_seconds = self::BUDGET_SECONDS, $budget_files = self::BUDGET_FILES, $probe = true ) {
		global $wpdb;

		$table  = Plandose_Subscriptions::table_name();
		$cursor = absint( get_option( self::LEGACY_MIGRATION_CURSOR_OPTION, 0 ) );

		/*
		 * The result notice sums every batch of one pass. A pass starts
		 * when no cursor is stored at all — not merely a cursor of 0: a
		 * batch whose budget ran out inside the FIRST row stores 0 and
		 * continues the same pass.
		 */
		if ( false === get_option( self::LEGACY_MIGRATION_CURSOR_OPTION, false ) ) {
			delete_transient( self::RESULT_TRANSIENT );
		}

		// Failures are collected across the batches of one pass. A
		// cursor of 0 means this batch starts a new pass, so anything left
		// over from an earlier pass is discarded first.
		if ( 0 === $cursor ) {
			delete_option( self::LEGACY_MIGRATION_FAILED_OPTION );
		}

		$failed_state = self::failed_state();

		/*
		 * The destination is checked (usable AND verified private,
		 * the rule new uploads follow — Plandose_Invoice_Storage::
		 * upload_allowed()) right before the FIRST copy of the run, not
		 * before: a pass with nothing left to copy still completes on a
		 * site whose storage is refused. Checking only the folder is not
		 * enough: on Nginx without the protection rule uploads are
		 * refused, and the migration must not copy the old invoices (with
		 * tax numbers) into the public folder either. A blocked run sets $blocked,
		 * so the cron backs off instead of retrying every 30 seconds.
		 */
		self::$blocked = false;
		$dir           = null;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; batch scans and audit writes must hit live data, not a cache.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, invoices FROM {$table} WHERE user_id > %d AND invoices IS NOT NULL AND invoices != '' ORDER BY user_id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is from $wpdb->prefix (site-controlled); prepare() cannot parameterize identifiers.
				$cursor,
				self::LEGACY_MIGRATION_BATCH_SIZE
			)
		);

		if ( null === $rows || '' !== $wpdb->last_error ) {
			// Query itself failed (e.g. table doesn't exist yet on a very
			// fresh install) — don't mark the migration done, just try
			// again on a later run once the table exists.
			return false;
		}

		$migrated_rows  = 0;
		$updated_users  = array();
		$migrated_files = 0;
		$failed_files   = 0;
		$last_user_id   = $cursor;

		$interrupted = false;

		// Time/copy budget, checked before every copy.
		$started    = microtime( true );
		$copies     = 0;
		$budget_hit = false;

		// Public originals recorded this batch (each row records its
		// own, before its list is rewritten — see below).
		$originals_recorded = 0;

		foreach ( $rows as $row ) {
			$invoices = Plandose_Subscriptions::decode_invoices( $row->invoices );

			if ( empty( $invoices ) ) {
				$last_user_id = max( $last_user_id, (int) $row->user_id );
				continue;
			}

			// Attachment ID => new filename, for the entries copied this run.
			$copied     = array();
			$row_failed = array();

			foreach ( $invoices as $entry ) {
				if ( ! is_numeric( $entry ) || isset( $copied[ (int) $entry ] ) || in_array( (int) $entry, $row_failed, true ) ) {
					continue;
				}

				if ( $copies >= max( 1, (int) $budget_files ) || ( microtime( true ) - $started ) >= max( 1, (int) $budget_seconds ) ) {
					$budget_hit = true;
					break;
				}

				if ( null === $dir ) {
					$dir = self::usable_storage( $probe );

					if ( is_wp_error( $dir ) ) {
						self::$blocked = true;

						if ( get_current_user_id() ) {
							set_transient( 'plandose_invoice_error_' . get_current_user_id(), $dir->get_error_message(), 60 );
						}

						// Nothing of this row was copied; earlier rows were
						// already rewritten in full. The cursor stays, so the
						// next run starts again from here.
						return false;
					}
				}

				$new_filename = self::migrate_single_attachment_to_file( (int) $entry, $dir );

				if ( $new_filename ) {
					++$copies;
					$copied[ (int) $entry ] = $new_filename;
				} else {
					++$failed_files;
					$row_failed[] = (int) $entry;
				}
			}

			if ( empty( $copied ) ) {
				// A row cut short by the budget is revisited from the start
				// next run: its failures are counted then, not twice.
				if ( $budget_hit ) {
					$failed_files -= count( $row_failed );
					$interrupted   = true;
					break;
				}

				if ( $row_failed ) {
					self::record_failures( $failed_state, (int) $row->user_id, $row_failed );
					update_option( self::LEGACY_MIGRATION_FAILED_OPTION, $failed_state, false );
				}

				$last_user_id = max( $last_user_id, (int) $row->user_id );
				update_option( self::LEGACY_MIGRATION_CURSOR_OPTION, $last_user_id, false );
				continue;
			}

			/*
			 * The list is written back under the same per-pharmacy
			 * lock an upload takes, and from a FRESH read. The batch read
			 * above happened before the (slow) file copies, so an invoice
			 * uploaded meanwhile would otherwise be overwritten in the
			 * database and its file left on disk referenced by nothing.
			 */
			$lock = Plandose_Admin_Invoices::acquire_invoice_list_lock( (int) $row->user_id, 5 );

			if ( false === $lock ) {
				// Busy: undo this row's copies and stop the batch here; the
				// cursor stays before this row, so a later run retries it. Not counted as failed — nothing failed (and
				// this row's copy failures, if any, are counted when it is
				// retried, not twice).
				$failed_files -= count( $row_failed );
				$settled       = array();

				foreach ( $copied as $new_filename ) {
					if ( Plandose_Invoice_Storage::fs_delete( trailingslashit( $dir ) . $new_filename, 'Rolling back migrated invoice file: invoice list busy' ) ) {
						$settled[] = $new_filename;
					}
				}

				// A copy that could not be deleted stays journaled.
				self::unjournal( $settled );
				$interrupted = true;
				break;
			}

			$updated  = false;
			$migrated = array();

			try {
				$fresh   = Plandose_Subscriptions::get_row( (int) $row->user_id, false );
				$current = $fresh ? Plandose_Subscriptions::decode_invoices( $fresh->invoices ) : array();

				// EVERY occurrence of a copied attachment ID is rewritten,
				// to the same copy: a row listing one ID twice (a hand-edited
				// row, an old double submit) would otherwise keep a numeric
				// entry for ever — has_legacy_entries() stays true and the
				// uninstall keeps all data — while the batch loop above
				// skips the ID as already copied. Two entries sharing one
				// file is safe: the delete handler removes the file only
				// once no entry lists it any more.
				foreach ( $current as $i => $entry ) {
					if ( is_numeric( $entry ) && isset( $copied[ (int) $entry ] ) ) {
						$current[ $i ]            = $copied[ (int) $entry ];
						$migrated[ (int) $entry ] = $copied[ (int) $entry ];
					}
				}

				if ( $migrated ) {
					/*
					 * Record the public originals FIRST. The
					 * list keeps the attachment IDs until they are safely
					 * stored; when recording fails, the row is not rewritten,
					 * its copies are rolled back below and it is retried like
					 * any failed copy — so no original can be lost from the
					 * «Διαγραφή πρωτοτύπων» list.
					 */
					$row_records = array();

					foreach ( $migrated as $attachment_id => $new_filename ) {
						$row_records[] = array(
							'attachment_id' => (int) $attachment_id,
							'user_id'       => (int) $row->user_id,
							'filename'      => $new_filename,
							'migrated_at'   => time(),
						);
					}

					$recorded = method_exists( 'Plandose_Admin_Invoices', 'record_legacy_originals' )
						? Plandose_Admin_Invoices::record_legacy_originals( $row_records )
						: false;

					if ( false !== $recorded ) {
						$updated = (bool) Plandose_Subscriptions::update( (int) $row->user_id, array( 'invoices' => wp_json_encode( $current ) ) );

						if ( $updated ) {
							$originals_recorded += count( $row_records );
						} else {
							Plandose_Admin_Invoices::forget_legacy_originals( $row_records );
						}
					} elseif ( method_exists( 'Plandose_Admin_Invoices', 'forget_legacy_originals' ) ) {
						// The read-back failed, but the list may still have
						// been (partly) written. Those records would name
						// copies that are rolled back below, so the
						// «πρωτότυπα» list would point at files that no
						// longer exist while the row still lists the IDs.
						// forget_legacy_originals() matches on the fresh,
						// random copy name, so nothing else is touched.
						Plandose_Admin_Invoices::forget_legacy_originals( $row_records );
					}
				}
			} finally {
				Plandose_Admin_Invoices::release_invoice_list_lock( $lock );
			}

			// Copies that did not end up in the database — the entry was
			// removed meanwhile, or the write failed — are deleted rather
			// than left as orphans.
			$settled = array();

			foreach ( $copied as $attachment_id => $new_filename ) {
				if ( $updated && isset( $migrated[ $attachment_id ] ) ) {
					$settled[] = $new_filename;
				} elseif ( Plandose_Invoice_Storage::fs_delete( trailingslashit( $dir ) . $new_filename, 'Rolling back migrated invoice file after DB update failure' ) ) {
					$settled[] = $new_filename;
				}
			}

			// This row's copies are now recorded or deleted; one whose
			// rollback failed stays journaled for cleanup_unrecorded_copies().
			self::unjournal( $settled );

			if ( $updated ) {
				$migrated_files += count( $migrated );
				++$migrated_rows;
				$updated_users[] = (int) $row->user_id;
			} elseif ( $migrated ) {
				$failed_files += count( $migrated );
				$row_failed = array_merge( $row_failed, array_map( 'intval', array_keys( $migrated ) ) );
			}

			if ( $budget_hit ) {
				// Partly done: the copies made are committed above; the rest
				// of this row is picked up next run (cursor stays before it).
				$failed_files -= count( $row_failed );
				$interrupted   = true;
				break;
			}

			if ( $row_failed ) {
				self::record_failures( $failed_state, (int) $row->user_id, $row_failed );
				update_option( self::LEGACY_MIGRATION_FAILED_OPTION, $failed_state, false );
			}

			// Persisted per row, so a run killed mid-batch resumes here.
			$last_user_id = max( $last_user_id, (int) $row->user_id );
			update_option( self::LEGACY_MIGRATION_CURSOR_OPTION, $last_user_id, false );
		}


		// A pass ends once a batch comes back short (every remaining
		// candidate row past the cursor was inspected this run). Otherwise,
		// advance the cursor so the next run resumes strictly after the
		// last row this run actually looked at, instead of re-selecting
		// the same rows again.
		//
		// The pass is marked DONE only when it ended with no
		// failures. With failures, it is recorded as complete-with-failures
		// instead: the notice then offers «Επανάληψη μεταφοράς τιμολογίων»,
		// and nothing retries by itself (see LEGACY_MIGRATION_FAILED_OPTION).
		$pass_complete = ! $interrupted && count( $rows ) < self::LEGACY_MIGRATION_BATCH_SIZE;

		if ( $pass_complete ) {
			delete_option( self::LEGACY_MIGRATION_CURSOR_OPTION );

			if ( $failed_state['files'] > 0 ) {
				$failed_state['complete'] = true;
				$failed_state['time']     = time();
				update_option( self::LEGACY_MIGRATION_FAILED_OPTION, $failed_state, false );
			} else {
				delete_option( self::LEGACY_MIGRATION_FAILED_OPTION );
				update_option( self::LEGACY_MIGRATION_OPTION, 1 );
			}
		} else {
			update_option( self::LEGACY_MIGRATION_CURSOR_OPTION, $last_user_id, false );

			if ( $failed_state['files'] > 0 ) {
				update_option( self::LEGACY_MIGRATION_FAILED_OPTION, $failed_state, false );
			}
		}

		// A manual retry always reports back, even «0 of 0», so the admin
		// sees that the button did something.
		if ( $manual || $migrated_files > 0 || $failed_files > 0 ) {
			self::add_result( $updated_users, $migrated_files, $failed_files );
		}

		if ( $migrated_files > 0 || $failed_files > 0 ) {

			Plandose_Admin::audit(
				'legacy_invoice_migration',
				0,
				array(
					'rows'               => $migrated_rows,
					'files'              => $migrated_files,
					'failed'             => $failed_files,
					'originals_recorded' => $originals_recorded,
					'budget_hit'         => $budget_hit ? 1 : 0,
				)
			);
		}

		return $budget_hit;
	}

	/**
	 * Add one batch's numbers to the pass's result notice. Adding zeros
	 * still creates the notice (a manual retry always reports back).
	 * Rows are visited in ascending user_id order, so the only pharmacy two
	 * batches can share is the one a budget cut in half: the last one
	 * counted before ('last_user') is not counted again.
	 *
	 * @param int[] $users  Pharmacies (user IDs) whose list was rewritten, in order.
	 * @param int   $files  Files copied.
	 * @param int   $failed Files that could not be copied.
	 */
	private static function add_result( array $users, $files, $failed ) {
		$result = get_transient( self::RESULT_TRANSIENT );
		$result = is_array( $result ) ? $result : array();
		$last   = isset( $result['last_user'] ) ? absint( $result['last_user'] ) : 0;
		$rows   = count( $users );

		if ( $rows > 0 && $last > 0 && (int) $users[0] === $last ) {
			--$rows;
		}

		set_transient(
			self::RESULT_TRANSIENT,
			array(
				'rows'      => ( isset( $result['rows'] ) ? absint( $result['rows'] ) : 0 ) + $rows,
				'files'     => ( isset( $result['files'] ) ? absint( $result['files'] ) : 0 ) + max( 0, (int) $files ),
				'failed'    => ( isset( $result['failed'] ) ? absint( $result['failed'] ) : 0 ) + max( 0, (int) $failed ),
				'last_user' => $users ? (int) end( $users ) : $last,
			),
			DAY_IN_SECONDS
		);
	}

	/**
	 * Add one row's failed attachment IDs to the pass's failure record.
	 * The file count is always exact; the per-pharmacy list is
	 * capped at LEGACY_MIGRATION_FAILED_MAX_USERS so the option stays small.
	 *
	 * @param array $state   failed_state() array, updated in place.
	 * @param int   $user_id Pharmacy user ID.
	 * @param int[] $ids     Attachment IDs that could not be migrated.
	 */
	private static function record_failures( array &$state, $user_id, array $ids ) {
		$state['files'] += count( $ids );

		if ( isset( $state['entries'][ $user_id ] ) || count( $state['entries'] ) < self::LEGACY_MIGRATION_FAILED_MAX_USERS ) {
			$existing                    = isset( $state['entries'][ $user_id ] ) ? (array) $state['entries'][ $user_id ] : array();
			$state['entries'][ $user_id ] = array_values( array_unique( array_merge( $existing, array_map( 'intval', $ids ) ) ) );
		}
	}

	/**
	 * Copy one legacy Media Library attachment's underlying file into
	 * invoice_dir() under a fresh random filename (COPY_PREFIX + 32
	 * random characters). Returns the new filename on success, or
	 * an empty string if the attachment or its file can't be found/copied
	 * (in which case the entry is left as-is and handle_view_invoice()'s
	 * legacy numeric branch keeps serving it as before).
	 */
	private static function migrate_single_attachment_to_file( $attachment_id, $dir ) {
		$source = get_attached_file( $attachment_id );

		if ( ! $source || ! file_exists( $source ) ) {
			return '';
		}

		$filetype = wp_check_filetype_and_ext( $source, basename( $source ) );
		$mime     = ! empty( $filetype['type'] ) ? strtolower( (string) $filetype['type'] ) : '';
		$ext      = ! empty( $filetype['ext'] ) ? Plandose_Invoice_Storage::clean_extension( $filetype['ext'] ) : '';

		if ( '' === $mime || ! in_array( $mime, Plandose_Settings::allowed_invoice_mimes(), true ) ) {
			return '';
		}

		$expected_exts = isset( Plandose_Invoice_Storage::ALLOWED_INVOICE_EXTENSIONS_BY_MIME[ $mime ] ) ? Plandose_Invoice_Storage::ALLOWED_INVOICE_EXTENSIONS_BY_MIME[ $mime ] : array();

		if ( '' === $ext || ! in_array( $ext, $expected_exts, true ) ) {
			return '';
		}

		// Same content check as validate_invoice_upload(): a legacy Media
		// Library attachment was never subjected to it either.
		if ( ! Plandose_Invoice_Storage::content_matches_mime( $source, $mime ) ) {
			return '';
		}

		// Own prefix, so cleanup_unrecorded_copies() can tell the
		// migration's copies from uploads; journaled before the copy.
		$filename = self::COPY_PREFIX . wp_generate_password( 32, false, false ) . '.' . $ext;

		if ( file_exists( trailingslashit( $dir ) . $filename ) ) {
			return '';
		}

		$target = trailingslashit( $dir ) . $filename;

		self::journal_copy( $filename );

		if ( ! Plandose_Invoice_Storage::fs_copy( $source, $target, 'Migrating legacy invoice attachment' ) ) {
			if ( file_exists( $target ) ) {
				Plandose_Invoice_Storage::fs_delete( $target, 'Removing partial legacy invoice copy' );
			}

			// Journaled only while a partial copy is still on disk.
			if ( ! file_exists( $target ) ) {
				self::unjournal( array( $filename ) );
			}

			return '';
		}

		Plandose_Invoice_Storage::fs_chmod( $target, 0640, 'chmod on migrated invoice file' );

		return $filename;
	}

	/**
	 * One-time admin notice reporting the result of
	 * maybe_migrate_legacy_invoices(), shown once to whichever admin next
	 * loads a PlanDose screen after a migration run actually changed
	 * something.
	 *
	 * Plus a persistent warning, with the «Επανάληψη μεταφοράς
	 * τιμολογίων» button, for as long as a finished pass has failures on
	 * record, so failed files never leave only a one-time notice as trace.
	 */
	public static function render_legacy_migration_notice() {
		if ( ! Plandose_Admin_Invoices::on_plandose_screen() ) {
			return;
		}

		$result = get_transient( self::RESULT_TRANSIENT );

		if ( $result ) {
			delete_transient( self::RESULT_TRANSIENT );

			$files  = isset( $result['files'] ) ? (int) $result['files'] : 0;
			$rows   = isset( $result['rows'] ) ? (int) $result['rows'] : 0;
			$failed = isset( $result['failed'] ) ? (int) $result['failed'] : 0;

			// Say what actually happened — the files were COPIED, and
			// the public Media Library originals still exist.
			$text = sprintf(
				/* translators: 1: number of files copied, 2: number of subscriber rows updated */
				__( 'PlanDose: αντιγράφηκαν %1$d παλιά τιμολόγια (από τη Media Library) σε %2$d φαρμακεία στην προστατευμένη αποθήκευση τιμολογίων.', 'plandose' ),
				$files,
				$rows
			);

			if ( $files > 0 ) {
				$text .= ' ' . __( 'Τα πρωτότυπα ΔΕΝ διαγράφηκαν: παραμένουν στη Media Library (wp-content/uploads/ΕΕΕΕ/ΜΜ/) με δημόσια διεύθυνση, μέχρι να τα ελέγξετε και να τα διαγράψετε με «Διαγραφή πρωτοτύπων».', 'plandose' );
			}

			if ( $failed > 0 ) {
				$text .= ' ' . sprintf(
					/* translators: %d: number of files that could not be copied */
					__( '%d αρχεία δεν ήταν δυνατό να αντιγραφούν και παρέμειναν όπως ήταν.', 'plandose' ),
					$failed
				);
			}

			$review = '';

			if ( $files > 0 ) {
				$review = sprintf(
					' <a href="%1$s">%2$s</a>',
					esc_url( admin_url( 'admin.php?page=plandose-subscriptions#plandose-legacy-originals' ) ),
					esc_html__( 'Έλεγχος πρωτοτύπων', 'plandose' )
				);
			}

			printf(
				'<div class="notice %1$s is-dismissible"><p>%2$s%3$s</p></div>',
				0 === $failed && 0 === $files ? 'notice-success' : 'notice-warning',
				esc_html( $text ),
				$review // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from esc_url() and esc_html__().
			);
		}

		$failed = self::failed_state();

		if ( empty( $failed['complete'] ) || $failed['files'] < 1 ) {
			return;
		}

		$user_ids = array_map( 'absint', array_keys( $failed['entries'] ) );
		$shown    = array_slice( $user_ids, 0, 10 );
		?>
		<div class="notice notice-warning">
			<p><?php
			echo esc_html(
				sprintf(
					/* translators: 1: number of invoice files, 2: number of pharmacies */
					__( 'PlanDose: %1$d παλιά τιμολόγια (Media Library) σε %2$d φαρμακεία δεν μεταφέρθηκαν στην προστατευμένη αποθήκευση. Εξακολουθούν να ανοίγουν από τη λίστα τιμολογίων όσο υπάρχει το αρχείο τους στη Media Library. Αφού διορθώσετε την αιτία (π.χ. επαναφέρετε το αρχείο που λείπει), πατήστε «Επανάληψη μεταφοράς τιμολογίων». Αν ένα αρχείο λείπει οριστικά, μπορείτε να αφαιρέσετε την εγγραφή από τη λίστα τιμολογίων του φαρμακείου.', 'plandose' ),
					$failed['files'],
					count( $user_ids )
				)
			);
			?></p>
			<?php if ( $shown ) : ?>
				<p class="description"><?php
				echo esc_html(
					sprintf(
						/* translators: %s: comma-separated list of user IDs */
						__( 'Φαρμακεία (ID χρήστη): %s', 'plandose' ),
						implode( ', ', $shown ) . ( count( $user_ids ) > count( $shown ) ? ', …' : '' )
					)
				);
				?></p>
			<?php endif; ?>
			<div style="margin:0.5em 0 0.75em"><?php self::render_retry_form(); ?></div>
		</div>
		<?php
	}
}