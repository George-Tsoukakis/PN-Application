<?php
/**
 * Owns the plandose_subscriptions table: schema, row lookup, Pro status and
 * the print counters — plus plandose_print_history, the per-month aggregate
 * the live counter is archived into before it is zeroed.
 *
 * Settings live in Plandose_Settings, the access decision in Plandose_Access
 * and the subscriber list queries in Plandose_Subscriber_Query.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plandose_Subscriptions {

	/**
	 * Return subscriptions table name.
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'plandose_subscriptions';
	}

	/**
	 * Return audit log table name.
	 *
	 * Tracks sensitive admin actions (Pro/Free changes, manual access
	 * overrides, invoice uploads/views) for accountability, since the
	 * data involved (AFM, pharmacy identity, contact details) is
	 * sensitive. See Plandose_Admin::audit().
	 */
	public static function audit_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'plandose_audit_log';
	}

	/**
	 * Return the monthly print-history table name.
	 *
	 * One row per pharmacy per calendar month, holding the print total for
	 * a month that has ended. Written by maybe_reset_month() at the moment
	 * the live counter is zeroed — before that, the count for the running
	 * month lives only in plandose_subscriptions.print_count, which is
	 * where it stays until the month rolls over.
	 *
	 * Deliberately an aggregate, not a per-print log: it answers "which
	 * pharmacies actually use this, and are they growing or going quiet"
	 * without recording anything about individual plans or patients.
	 */
	public static function history_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'plandose_print_history';
	}

	/**
	 * Create or update plugin database tables.
	 *
	 * Does not update either `plandose_version` or `plandose_db_version`.
	 * Both are version-state options managed by the bootstrap/migration
	 * flow, not by this method:
	 * - `plandose_version` (plandose.php) is bumped only once the caller has
	 *   confirmed via this method's return value that the tables actually
	 *   exist (see plandose_activate()/plandose_init()).
	 * - `plandose_db_version` (maybe_migrate_legacy_defaults() below) is the
	 *   gate the legacy 30/90 settings migration checks; it must
	 *   still hold the *previously installed* version when that migration
	 *   runs, so this method must never touch it — doing so here would make
	 *   the migration think an upgrade already completed and skip it.
	 *
	 * @return bool True when all three plugin tables exist after dbDelta().
	 */
	public static function create_table() {
		global $wpdb;

		$table_name      = self::table_name();
		$audit_table     = self::audit_table_name();
		$history_table   = self::history_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			user_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(10) NOT NULL DEFAULT 'free',
			sub_end_date DATE NULL,
			print_count INT UNSIGNED NOT NULL DEFAULT 0,
			count_reset_at DATE NULL,
			last_print_at DATETIME NULL,
			invoices LONGTEXT NULL,
			PRIMARY KEY  (user_id),
			KEY status (status),
			KEY sub_end_date (sub_end_date),
			KEY count_reset_at (count_reset_at)
		) ENGINE=InnoDB {$charset_collate};";

		$audit_sql = "CREATE TABLE {$audit_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			event VARCHAR(64) NOT NULL,
			admin_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			target_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			ip VARCHAR(45) NOT NULL DEFAULT '',
			meta LONGTEXT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY event (event),
			KEY admin_user_id (admin_user_id),
			KEY target_user_id (target_user_id)
		) {$charset_collate};";

		$history_sql = "CREATE TABLE {$history_table} (
			user_id BIGINT UNSIGNED NOT NULL,
			ym CHAR(7) NOT NULL,
			prints INT UNSIGNED NOT NULL DEFAULT 0,
			archived_at DATETIME NULL,
			PRIMARY KEY  (user_id,ym),
			KEY ym (ym)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		dbDelta( $audit_sql );
		dbDelta( $history_sql );

		// Print-charge ledger (see Plandose_Print_Charges).
		if ( class_exists( 'Plandose_Print_Charges' ) ) {
			foreach ( Plandose_Print_Charges::schema_sql( $charset_collate ) as $charges_sql ) {
				dbDelta( $charges_sql );
			}

			// Re-check the storage engines now rather than in 12 hours.
			delete_transient( Plandose_Print_Charges::ENGINE_CHECK_TRANSIENT );
			delete_transient( 'plandose_engine_notice' );
		}

		/*
		 * The print log (Plandose_Print_Log). Best effort, like
		 * every write to it: deliberately NOT part of tables_exist(), so a
		 * site that cannot create it (lost CREATE rights) keeps printing
		 * and billing; Diagnostics reports the missing table.
		 */
		if ( class_exists( 'Plandose_Print_Log' ) ) {
			foreach ( Plandose_Print_Log::schema_sql( $charset_collate ) as $log_sql ) {
				dbDelta( $log_sql );
			}
		}

		self::maybe_seed_default_settings();

		$exist = self::tables_exist();

		if ( $exist ) {
			self::ensure_innodb();
		}

		return $exist;
	}

	/**
	 * dbDelta() never changes the engine of an EXISTING table, so an
	 * install whose subscriptions table was created before ENGINE=InnoDB was
	 * declared (or on a server whose default engine was MyISAM) would keep
	 * it. The print counter is written in one transaction with the charge
	 * ledger, which needs InnoDB. Runs from create_table(), i.e. on
	 * activation and on every version change (plandose_init()).
	 *
	 * Converts only tables known to be something else, and only when the
	 * server supports InnoDB. Best effort: a failed ALTER is logged and the
	 * admin engine notice (Plandose_Print_Charges) keeps reporting it.
	 */
	private static function ensure_innodb() {
		global $wpdb;

		if ( ! class_exists( 'Plandose_Print_Charges' ) ) {
			return;
		}

		$engines = Plandose_Print_Charges::engines();

		if ( '' !== (string) $wpdb->last_error ) {
			return;
		}

		$convert = array();

		foreach ( $engines as $table => $engine ) {
			if ( '' !== $engine && 0 !== strcasecmp( $engine, 'InnoDB' ) ) {
				$convert[] = $table;
			}
		}

		if ( empty( $convert ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check.
		$support = (string) $wpdb->get_var( "SELECT SUPPORT FROM information_schema.ENGINES WHERE ENGINE = 'InnoDB'" );

		if ( 0 !== strcasecmp( $support, 'YES' ) && 0 !== strcasecmp( $support, 'DEFAULT' ) ) {
			return;
		}

		foreach ( $convert as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- One of our own table names ($wpdb->prefix + fixed suffix).
			$ok = $wpdb->query( "ALTER TABLE `{$table}` ENGINE=InnoDB" );

			if ( false === $ok && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- failure logged for the site admin (debug.log), not shown to users.
				error_log( 'PlanDose: could not convert ' . $table . ' to InnoDB — ' . $wpdb->last_error );
			}
		}

		delete_transient( Plandose_Print_Charges::ENGINE_CHECK_TRANSIENT );
		delete_transient( 'plandose_engine_notice' );
	}

	/**
	 * Case-insensitive table-name match. With lower_case_table_names=1
	 * the server reports names in lower case, so a mixed-case $table_prefix
	 * would never compare equal and the tables would be reported missing.
	 *
	 * @param mixed  $found    Name returned by SHOW TABLES LIKE.
	 * @param string $expected Our table name.
	 * @return bool
	 */
	public static function same_table_name( $found, $expected ) {
		return is_string( $found ) && '' !== $found && 0 === strcasecmp( $found, (string) $expected );
	}

	/**
	 * Write the default settings row, but never before translations exist.
	 *
	 * Plandose_Settings::default_settings() calls __() for guest_message,
	 * button_text and disclaimer. create_table() runs from plandose_init()
	 * on 'plugins_loaded' whenever the plugin version has bumped, which is
	 * BEFORE WordPress loads any text domain — so seeding directly from
	 * there triggers the _load_textdomain_just_in_time notice on WordPress
	 * 6.7+ and bakes untranslated (or wrongly-locale) copy into the option
	 * that first time.
	 *
	 * When 'init' has already fired (plugin activation, WP-CLI, any later
	 * call) the option is written immediately; otherwise the write is
	 * deferred to 'init' at priority 0, which is still long before anything
	 * reads the setting. Either way the option ends up seeded exactly once:
	 * both paths re-check get_option() at the moment they run.
	 */
	private static function maybe_seed_default_settings() {
		if ( did_action( 'init' ) ) {
			self::seed_default_settings();
			return;
		}

		if ( ! has_action( 'init', array( __CLASS__, 'seed_default_settings' ) ) ) {
			add_action( 'init', array( __CLASS__, 'seed_default_settings' ), 0 );
		}
	}

	/**
	 * Add the plandose_settings option if it does not exist yet.
	 *
	 * Public because it doubles as an 'init' callback (see
	 * maybe_seed_default_settings()). add_option() is a no-op when the
	 * option already exists, so calling this repeatedly is harmless.
	 */
	public static function seed_default_settings() {
		if ( false === get_option( 'plandose_settings', false ) ) {
			add_option( 'plandose_settings', Plandose_Settings::default_settings() );
		}
	}

	/**
	 * Verify every plugin table actually exists. Used by create_table() to
	 * report real success/failure instead of assuming dbDelta() worked.
	 */
	private static function tables_exist() {
		global $wpdb;

		$table_name    = self::table_name();
		$audit_table   = self::audit_table_name();
		$history_table = self::history_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off schema existence check; no object cache applies.
		$found_main  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off schema existence check; no object cache applies.
		$found_audit = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $audit_table ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off schema existence check; no object cache applies.
		$found_history = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $history_table ) ) );

		$found_charges = true;

		if ( class_exists( 'Plandose_Print_Charges' ) ) {
			foreach ( array( Plandose_Print_Charges::table_name(), Plandose_Print_Charges::requests_table_name() ) as $charges_table ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off schema existence check; no object cache applies.
				if ( ! self::same_table_name( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $charges_table ) ) ), $charges_table ) ) {
					$found_charges = false;
				}
			}
		}

		return self::same_table_name( $found_main, $table_name )
			&& self::same_table_name( $found_audit, $audit_table )
			&& self::same_table_name( $found_history, $history_table )
			&& $found_charges;
	}

	/**
	 * Get or create subscription row.
	 *
	 * Pass $create = false to do a read-only lookup: this returns null
	 * instead of inserting a brand-new 'free' row for users who have
	 * never touched the tool. Used by the admin listing so that simply
	 * viewing PlanDose → Συνδρομές doesn't silently create a DB row for
	 * every matched pharmacist. Actual usage (print flow, header lookup,
	 * manual admin actions) still passes the default $create = true so a
	 * row exists once it's actually needed.
	 *
	 * The get_userdata() existence check applies ONLY to the creating path.
	 * It stops a row being inserted for a user_id that was never a real
	 * account; it has no business gating a plain read. WordPress fires
	 * 'deleted_user' AFTER removing the wp_users row, and both
	 * Plandose_Admin::handle_user_deleted() and the «Τιμολόγια διαγραμμένων
	 * λογαριασμών» screen must still read the row of an account that is gone
	 * — it is the only record of which invoice files belonged to it.
	 */
	public static function get_row( $user_id, $create = true ) {
		global $wpdb;

		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return null;
		}

		if ( $create && ! get_userdata( $user_id ) ) {
			return null;
		}

		$table = self::table_name();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table; {$table} is $wpdb->prefix + fixed suffix (not user input); prepare() cannot parameterize identifiers.
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $row && $create ) {
			// INSERT IGNORE — two first requests of the same user
			// (e.g. header + print) both miss the row; a plain INSERT would make
			// the loser fail with a duplicate-key error. The re-read below
			// returns whichever row won.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table insert; {$table} is $wpdb->prefix + fixed suffix.
			$inserted = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$table} (user_id, status, print_count, count_reset_at, last_print_at, invoices) VALUES (%d, 'free', 0, %s, NULL, NULL)",
					$user_id,
					current_time( 'Y-m-d' )
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( $inserted ) {
				Plandose_Subscriber_Query::invalidate_subscriber_cache();
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table; {$table} is $wpdb->prefix + fixed suffix (not user input); prepare() cannot parameterize identifiers.
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE user_id = %d",
					$user_id
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return $row;
	}

	/**
	 * In-memory default row used for display purposes only (admin listing)
	 * when a matched pharmacist has no subscription row yet and we're doing
	 * a read-only lookup. Never written to the database.
	 */
	public static function default_row_stub( $user_id ) {
		return (object) array(
			'user_id'        => (int) $user_id,
			'status'         => 'free',
			'sub_end_date'   => null,
			'print_count'    => 0,
			'count_reset_at' => null,
			'last_print_at'  => null,
			'invoices'       => null,
		);
	}

	/**
	 * First day of the current site-local month and of the next one, as
	 * 'Y-m-d' — the half-open range [start, next) a count_reset_at must
	 * fall in for print_count to belong to the running month.
	 *
	 * count_reset_at is a DATE written with current_time( 'Y-m-d' ), i.e.
	 * site-local wall clock, so the bounds are computed in the site
	 * timezone too. Comparing it against UTC month bounds would misfile
	 * the first/last hours of every month on any site not in UTC.
	 *
	 * @return array{0:string,1:string}
	 */
	public static function current_month_bounds( $today = null ) {
		$now = current_datetime();

		// The caller's own "today" (Y-m-d), so a request that
		// reset the month just before midnight increments the SAME month.
		if ( is_string( $today ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $today ) ) {
			$given = DateTimeImmutable::createFromFormat( '!Y-m-d', $today, wp_timezone() );

			if ( $given ) {
				$now = $given;
			}
		}

		return array(
			$now->modify( 'first day of this month' )->format( 'Y-m-d' ),
			$now->modify( 'first day of next month' )->format( 'Y-m-d' ),
		);
	}

	/**
	 * The print count of the RUNNING month for one subscription row.
	 *
	 * print_count is only zeroed when maybe_reset_month() runs for that
	 * pharmacy — on its next print, or when the daily
	 * archive_idle_months() sweep reaches it. Until then the column still
	 * holds the total of whichever month count_reset_at points at. Every
	 * reader that labels the figure "this month" (the «Εκτυπώσεις» card,
	 * the CSV «Prints» column, the «Εκτυπώσεις Μήνα» KPI via the SQL twin
	 * in Plandose_Subscriber_Query::stats()) would otherwise print that stale
	 * number as the current month's usage, sometimes for days after the 1st.
	 *
	 * Read-only by design: fixing the display must not depend on a write
	 * happening first, and the archiving itself stays with
	 * maybe_reset_month().
	 *
	 * @param object|null $row Subscription row (or default_row_stub()).
	 * @return int Prints in the current site-local month; 0 when the
	 *             stored count belongs to an earlier (or unknown) month.
	 */
	public static function current_month_count( $row ) {
		if ( ! is_object( $row ) || empty( $row->count_reset_at ) || empty( $row->print_count ) ) {
			return 0;
		}

		list( $month_start, $next_month ) = self::current_month_bounds();

		$reset_at = substr( (string) $row->count_reset_at, 0, 10 );

		if ( $reset_at < $month_start || $reset_at >= $next_month ) {
			return 0;
		}

		return max( 0, (int) $row->print_count );
	}

	/**
	 * Check if a subscription row is active Pro.
	 *
	 * sub_end_date is the LAST day of access (inclusive), hence >= today.
	 * See pro_end_date_for() for how that date is computed.
	 */
	public static function is_pro_active( $row ) {
		if ( ! is_object( $row ) ) {
			return false;
		}

		if ( empty( $row->status ) || 'pro' !== $row->status || empty( $row->sub_end_date ) ) {
			return false;
		}

		$end = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $row->sub_end_date, wp_timezone() );
		$now = new DateTimeImmutable( 'today', wp_timezone() );

		return $end instanceof DateTimeImmutable && $end >= $now;
	}

	/**
	 * Check whether a user currently has an active Pro subscription.
	 *
	 * Performs a read-only lookup so checking the user's status during a
	 * frontend page load does not create a new Free subscription row.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return bool True when the user has an active Pro subscription.
	 */
	public static function user_is_pro( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return false;
		}

		return self::is_pro_active( self::get_row( $user_id, false ) );
	}

	/**
	 * Update subscription row using whitelisted fields only.
	 */
	public static function update( $user_id, $fields ) {
		global $wpdb;

		$user_id = absint( $user_id );

		if ( ! $user_id || ! is_array( $fields ) ) {
			return false;
		}

		// A row that already exists is updated even when its account
		// is gone (get_row() with $create only refuses to CREATE a row for a
		// missing user): the legacy invoice migration rewrites retained rows
		// of deleted accounts.
		if ( ! self::get_row( $user_id ) && ! self::get_row( $user_id, false ) ) {
			return false;
		}

		$allowed = array(
			'status'         => '%s',
			'sub_end_date'   => '%s',
			'print_count'    => '%d',
			'count_reset_at' => '%s',
			'last_print_at'  => '%s',
			'invoices'       => '%s',
		);

		$data   = array();
		$format = array();

		foreach ( $fields as $key => $value ) {
			if ( ! array_key_exists( $key, $allowed ) ) {
				continue;
			}

			if ( 'status' === $key ) {
				$value = in_array( $value, array( 'free', 'pro' ), true ) ? $value : 'free';
			}

			if ( in_array( $key, array( 'sub_end_date', 'count_reset_at' ), true ) ) {
				if ( null === $value || '' === $value ) {
					$value = null;
				} else {
					// Strict Y-m-d only. sanitize_ymd() rejects impossible dates
					// (2026-02-31) and loose strtotime() forms ('next Friday')
					// that gmdate( 'Y-m-d', strtotime( $value ) ) would silently
					// rewrite to some other date.
					$value = Plandose_Settings::sanitize_ymd( $value );

					if ( '' === $value ) {
						continue;
					}
				}
			}

			if ( 'last_print_at' === $key ) {
				if ( null === $value || '' === $value ) {
					$value = null;
				} else {
					/*
					 * Strict 'Y-m-d H:i:s' only, stored verbatim.
					 *
					 * The column is written everywhere else with
					 * current_time( 'mysql' ) — site-local wall clock, no
					 * offset attached — so a value handed to this method has
					 * to be in that same basis to mean anything. A
					 * strtotime() + wp_date( …, wp_timezone() ) pair would read the
					 * string in the SERVER's default timezone and then
					 * re-render it in the SITE's, shifting the stored time
					 * by the difference between the two whenever they are
					 * not identical. It would also accept loose forms like
					 * 'yesterday', which have no business in a timestamp
					 * column.
					 */
					$parsed = DateTime::createFromFormat( 'Y-m-d H:i:s', (string) $value );

					if ( ! $parsed instanceof DateTime || $parsed->format( 'Y-m-d H:i:s' ) !== (string) $value ) {
						continue;
					}

					$value = (string) $value;
				}
			}

			if ( 'print_count' === $key ) {
				$value = max( 0, min( 4294967295, (int) $value ) ); // The column is INT UNSIGNED.
			}

			if ( 'invoices' === $key ) {
				if ( null === $value || '' === $value ) {
					$value = null;
				} else {
					$decoded = is_array( $value ) ? $value : json_decode( (string) $value, true );

					if ( ! is_array( $decoded ) ) {
						continue;
					}

					$decoded = array_values( array_filter( $decoded, static function ( $entry ) {
						return is_numeric( $entry ) || ( is_string( $entry ) && '' !== sanitize_file_name( basename( $entry ) ) );
					} ) );
					$value   = wp_json_encode( $decoded );

					if ( false === $value ) {
						continue;
					}
				}
			}

			$data[ $key ] = $value;
			$format[]     = $allowed[ $key ];
		}

		if ( empty( $data ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table update; no object cache applies to a write.
		$result = false !== $wpdb->update(
			self::table_name(),
			$data,
			array( 'user_id' => $user_id ),
			$format,
			array( '%d' )
		);

		if ( $result ) {
			Plandose_Subscriber_Query::invalidate_subscriber_cache();
		}

		return $result;
	}

	/**
	 * Move a subscription to Free only if it is still in the state
	 * the admin's form was rendered from (state_token()) — the same guard
	 * grant_pro_days_if_unchanged() has. Without it «Μετατροπή σε Free»
	 * would be a plain read-modify-write: an admin on a page loaded before a
	 * colleague extended the subscription would wipe the paid days.
	 *
	 * @param int    $user_id  Pharmacy user ID.
	 * @param string $expected state_token() the form was rendered from.
	 * @return array{0:string,1:?object} Outcome ('updated', 'unchanged'
	 *                                   — already Free —, 'stale' or
	 *                                   'failed') and the row as it was
	 *                                   before the change (for the audit).
	 */
	public static function make_free_if_unchanged( $user_id, $expected ) {
		global $wpdb;

		$user_id  = absint( $user_id );
		$expected = (string) $expected;

		if ( ! $user_id ) {
			return array( 'failed', null );
		}

		$row = self::get_row( $user_id, false );

		if ( ! $row ) {
			return array( 'failed', null );
		}

		if ( ! hash_equals( self::state_token( $row ), $expected ) ) {
			return array( 'stale', $row );
		}

		$table = self::table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table; {$table} is $wpdb->prefix + fixed suffix. Conditional write: the WHERE re-checks the state read above.
		if ( empty( $row->sub_end_date ) ) {
			$affected = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status = 'free', sub_end_date = NULL WHERE user_id = %d AND status = %s AND sub_end_date IS NULL",
					$user_id,
					(string) $row->status
				)
			);
		} else {
			$affected = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status = 'free', sub_end_date = NULL WHERE user_id = %d AND status = %s AND sub_end_date = %s",
					$user_id,
					(string) $row->status,
					(string) $row->sub_end_date
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( false === $affected ) {
			return array( 'failed', $row );
		}

		if ( 1 !== (int) $affected ) {
			// Changed between the read and the write — or already Free
			// with no end date (0 rows changed): the admin reloads.
			return array( 'free' === $row->status && empty( $row->sub_end_date ) ? 'unchanged' : 'stale', $row );
		}

		Plandose_Subscriber_Query::invalidate_subscriber_cache();

		return array( 'updated', $row );
	}

	/**
	 * The state an admin form was rendered from, as one opaque string:
	 * "status|sub_end_date" (end date empty when NULL). A missing row
	 * reads as "free|" — exactly what get_row() would create.
	 *
	 * @param object|null $row Subscription row.
	 * @return string
	 */
	public static function state_token( $row ) {
		$status = ( is_object( $row ) && isset( $row->status ) ) ? (string) $row->status : 'free';
		$end    = ( is_object( $row ) && ! empty( $row->sub_end_date ) ) ? (string) $row->sub_end_date : '';

		return $status . '|' . $end;
	}

	/**
	 * Grant $add_days of Pro ONLY if the subscription is still in the state
	 * the admin saw ($expected, from state_token()).
	 *
	 * Protects «Ενεργοποίηση / Παράταση Pro» against a double submit, a
	 * browser resubmit or two admins working from the same page: the first
	 * request changes sub_end_date, so every later one carrying the old
	 * state is refused instead of adding the days a second time. The check
	 * and the write are one conditional UPDATE, so two requests that arrive
	 * together cannot both pass.
	 *
	 * @param int    $user_id  Pharmacy user ID.
	 * @param int    $add_days Days to grant, clamped to 1 … max_add_days().
	 * @param string $expected state_token() the form was rendered with.
	 * @return string 'updated', 'stale' (state changed since the form was
	 *                shown; nothing written) or 'failed'.
	 */
	public static function grant_pro_days_if_unchanged( $user_id, $add_days, $expected ) {
		global $wpdb;

		$user_id  = absint( $user_id );
		$add_days = max( 1, min( Plandose_Settings::max_add_days(), (int) $add_days ) );
		$expected = (string) $expected;

		if ( ! $user_id || ! Plandose_Access::is_registered_pharmacist( $user_id ) ) {
			return 'failed';
		}

		$row = self::get_row( $user_id );

		if ( ! $row ) {
			return 'failed';
		}

		if ( ! hash_equals( self::state_token( $row ), $expected ) ) {
			return 'stale';
		}

		$new_end = self::pro_end_date_for( $row, $add_days, new DateTimeImmutable( 'today', wp_timezone() ) );
		$table   = self::table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table; {$table} is $wpdb->prefix + fixed suffix. Conditional write: the WHERE re-checks the state read above.
		if ( empty( $row->sub_end_date ) ) {
			$affected = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status = 'pro', sub_end_date = %s WHERE user_id = %d AND status = %s AND sub_end_date IS NULL",
					$new_end,
					$user_id,
					(string) $row->status
				)
			);
		} else {
			$affected = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status = 'pro', sub_end_date = %s WHERE user_id = %d AND status = %s AND sub_end_date = %s",
					$new_end,
					$user_id,
					(string) $row->status,
					(string) $row->sub_end_date
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( false === $affected ) {
			return 'failed';
		}

		if ( 1 !== (int) $affected ) {
			// Another request changed the row between the read and the write.
			return 'stale';
		}

		Plandose_Subscriber_Query::invalidate_subscriber_cache();

		return 'updated';
	}

	/**
	 * The sub_end_date that grants exactly $add_days more days.
	 *
	 * sub_end_date is the LAST day of access, inclusive (is_pro_active()
	 * accepts $end >= today), so "today + N days" would give N + 1 days
	 * of access: 365 days granted on 2026-09-21 would run through
	 * 2027-09-21 — 366 days.
	 *
	 * - New activation (no active Pro): today is day 1, so the last day
	 *   is today + (N - 1). N = 1 means "today only".
	 * - Extension of an active Pro (end >= today, including one that
	 *   ends tonight): N days are appended after the current last day,
	 *   end + N, so nothing already paid for is lost or double-counted.
	 *
	 * Calendar arithmetic via DateTimeImmutable::modify(), so leap days
	 * count as days like any other (2028-02-29 + 364 = 2029-02-27).
	 * Only newly written dates change; stored end dates are left alone.
	 *
	 * Split out of grant_pro_days_if_unchanged() so the rule can be tested
	 * with an arbitrary "today".
	 *
	 * @param object|null        $row      Current subscription row.
	 * @param int                $add_days Days to grant, >= 1.
	 * @param DateTimeImmutable  $today    Site-local today (midnight).
	 * @return string New sub_end_date, 'Y-m-d'.
	 */
	public static function pro_end_date_for( $row, $add_days, DateTimeImmutable $today ) {
		$add_days = max( 1, (int) $add_days );
		$today    = $today->setTime( 0, 0 );
		$end      = ( is_object( $row ) && ! empty( $row->sub_end_date ) )
			? DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $row->sub_end_date, $today->getTimezone() )
			: false;
		$active   = is_object( $row )
			&& isset( $row->status )
			&& 'pro' === $row->status
			&& $end instanceof DateTimeImmutable
			&& $end >= $today;

		if ( $active ) {
			return $end->modify( '+' . $add_days . ' days' )->format( 'Y-m-d' );
		}

		return $today->modify( '+' . ( $add_days - 1 ) . ' days' )->format( 'Y-m-d' );
	}

	/**
	 * Increment print count unconditionally (no limit check).
	 *
	 * Used for unlimited plans (limit === 0, e.g. Pro with no configured
	 * cap) where there is nothing to gate against. try_increment_print_count()
	 * can't be reused as-is for this because it always applies a `WHERE
	 * print_count < %d` cap, and there's no meaningful finite limit to pass
	 * it for an unlimited account. print_count is still tracked here (not
	 * skipped) since it feeds the admin dashboard's per-pharmacy and
	 * site-wide "prints this month" figures — those should reflect actual
	 * usage regardless of whether that usage is capped for the account.
	 */
	public static function increment_print_count_unconditionally( $user_id, $today = null ) {
		global $wpdb;

		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return false;
		}

		$table = self::table_name();

		/*
		 * Only a counter that belongs to the RUNNING month may be
		 * incremented. maybe_reset_month() runs just before this, but when
		 * it fails (a DB error, or five lost races) it returns false — the
		 * same value as "nothing to reset" — so the caller cannot tell.
		 * Incrementing anyway would add the print to LAST month's counter:
		 * a Free account already at its limit would be refused as "limit
		 * reached" on the 1st, and any other account's new prints would be
		 * archived as last month's. So zero rows match, the print is not
		 * recorded, and register_print() answers server_error (try again);
		 * the next request repeats the reset.
		 */
		list( $month_start, $next_month ) = self::current_month_bounds( $today );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table; {$table} is $wpdb->prefix + fixed suffix (not user input); prepare() cannot parameterize identifiers.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET print_count = print_count + 1, last_print_at = %s
				 WHERE user_id = %d AND print_count < 4294967295
				 AND count_reset_at >= %s AND count_reset_at < %s",
				current_time( 'mysql' ),
				$user_id,
				$month_start,
				$next_month
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// A print moves only the "prints" KPI, which may lag by the
		// stats TTL; the caches are not dropped per print.
		if ( (int) $updated > 0 ) {
			return true;
		}

		return false;
	}

	/**
	 * Increment print count only if under limit.
	 */
	public static function try_increment_print_count( $user_id, $limit, $today = null ) {
		global $wpdb;

		$user_id = absint( $user_id );
		$limit   = max( 1, (int) $limit );

		if ( ! $user_id ) {
			return false;
		}

		$table = self::table_name();

		/*
		 * Only a counter that belongs to the RUNNING month may be
		 * incremented. maybe_reset_month() runs just before this, but when
		 * it fails (a DB error, or five lost races) it returns false — the
		 * same value as "nothing to reset" — so the caller cannot tell.
		 * Incrementing anyway would add the print to LAST month's counter:
		 * a Free account already at its limit would be refused as "limit
		 * reached" on the 1st, and any other account's new prints would be
		 * archived as last month's. So zero rows match, the print is not
		 * recorded, and register_print() answers server_error (try again);
		 * the next request repeats the reset.
		 */
		list( $month_start, $next_month ) = self::current_month_bounds( $today );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table; {$table} is $wpdb->prefix + fixed suffix (not user input); prepare() cannot parameterize identifiers.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET print_count = print_count + 1, last_print_at = %s
				 WHERE user_id = %d AND print_count < %d
				 AND count_reset_at >= %s AND count_reset_at < %s",
				current_time( 'mysql' ),
				$user_id,
				$limit,
				$month_start,
				$next_month
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// No cache drop per print; see the unconditional variant.
		if ( (int) $updated > 0 ) {
			return true;
		}

		return false;
	}

	/**
	 * Reset monthly counter if month changed.
	 *
	 * @return bool True when this call zeroed an outgoing month's counter.
	 */
	/** How long a failing month archive may hold back the reset. */
	const ARCHIVE_RETRY_SECONDS = HOUR_IN_SECONDS;

	/**
	 * Closed months whose archive failed and whose counter was
	 * reset anyway (see maybe_reset_month()). A non-autoloaded option:
	 * list of array( user_id, ym 'YYYY-MM', prints, at unix time ), one
	 * entry per user and month (the larger figure kept), at most
	 * UNARCHIVED_MAX entries (oldest dropped — each one is in the error
	 * log too). Retried daily by retry_unarchived(); shown to admins until
	 * it is empty.
	 */
	const UNARCHIVED_OPTION = 'plandose_unarchived_months';
	const UNARCHIVED_MAX    = 500;

	/**
	 * The months waiting to be archived (see UNARCHIVED_OPTION),
	 * oldest first, each with integer user_id / prints / at and a valid ym.
	 *
	 * @return array<int,array{user_id:int,ym:string,prints:int,at:int}>
	 */
	public static function unarchived_months() {
		$raw = get_option( self::UNARCHIVED_OPTION, array() );
		$out = array();

		if ( ! is_array( $raw ) ) {
			return $out;
		}

		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['user_id'], $entry['ym'], $entry['prints'] ) ) {
				continue;
			}

			$user_id = absint( $entry['user_id'] );
			$ym      = (string) $entry['ym'];

			if ( ! $user_id || ! preg_match( '/^\d{4}-\d{2}$/D', $ym ) ) {
				continue;
			}

			$out[] = array(
				'user_id' => $user_id,
				'ym'      => $ym,
				'prints'  => absint( $entry['prints'] ),
				'at'      => isset( $entry['at'] ) ? absint( $entry['at'] ) : 0,
			);
		}

		return $out;
	}

	/**
	 * Store the list (empty → the option is deleted).
	 *
	 * @param array $entries Entries as unarchived_months() returns them.
	 * @return bool
	 */
	private static function save_unarchived( $entries ) {
		$entries = array_values( $entries );

		if ( ! $entries ) {
			delete_option( self::UNARCHIVED_OPTION );

			return true;
		}

		if ( count( $entries ) > self::UNARCHIVED_MAX ) {
			$entries = array_slice( $entries, -self::UNARCHIVED_MAX );
		}

		if ( false === get_option( self::UNARCHIVED_OPTION, false ) ) {
			return add_option( self::UNARCHIVED_OPTION, $entries, '', false );
		}

		return update_option( self::UNARCHIVED_OPTION, $entries, false );
	}

	/**
	 * Add (or raise) one month in the list.
	 *
	 * @param int    $user_id Pharmacy user ID.
	 * @param string $ym      'YYYY-MM'.
	 * @param int    $prints  Print total of that month.
	 */
	private static function remember_unarchived( $user_id, $ym, $prints ) {
		$entries = self::unarchived_months();

		foreach ( $entries as $i => $entry ) {
			if ( (int) $user_id === $entry['user_id'] && (string) $ym === $entry['ym'] ) {
				$entries[ $i ]['prints'] = max( $entry['prints'], absint( $prints ) );
				$entries[ $i ]['at']     = time();
				self::save_unarchived( $entries );

				return;
			}
		}

		$entries[] = array(
			'user_id' => absint( $user_id ),
			'ym'      => (string) $ym,
			'prints'  => absint( $prints ),
			'at'      => time(),
		);
		self::save_unarchived( $entries );
	}

	/**
	 * Archive the waiting months again (daily cron, and the
	 * «Αρχειοθέτηση ξανά» button in Διαγνωστικά). Each success leaves the
	 * list; record_month_history() is idempotent (GREATEST), so a month
	 * that did reach the table after all is not double-counted. Entries of
	 * accounts that no longer exist are dropped (their history was deleted
	 * with them).
	 *
	 * @return array{archived:int,left:int}
	 */
	public static function retry_unarchived() {
		$entries = self::unarchived_months();

		if ( ! $entries ) {
			return array(
				'archived' => 0,
				'left'     => 0,
			);
		}

		$left     = array();
		$archived = 0;

		foreach ( $entries as $entry ) {
			if ( ! get_userdata( $entry['user_id'] ) ) {
				continue;
			}

			if ( self::record_month_history( $entry['user_id'], $entry['ym'], $entry['prints'] ) ) {
				++$archived;
			} else {
				$left[] = $entry;
			}
		}

		self::save_unarchived( $left );

		return array(
			'archived' => $archived,
			'left'     => count( $left ),
		);
	}

	/**
	 * Forget a user's waiting months (account deleted / GDPR erase —
	 * called with the history, see delete_history_for_user()), or the whole
	 * list ($user_id = 0, after an admin noted the figures elsewhere).
	 *
	 * @param int $user_id User ID, or 0 for all.
	 * @return int How many entries were removed.
	 */
	public static function forget_unarchived( $user_id = 0 ) {
		$user_id = absint( $user_id );
		$entries = self::unarchived_months();
		$kept    = $user_id
			? array_filter(
				$entries,
				static function ( $entry ) use ( $user_id ) {
					return $entry['user_id'] !== $user_id;
				}
			)
			: array();

		if ( count( $kept ) !== count( $entries ) ) {
			self::save_unarchived( $kept );
		}

		if ( $user_id ) {
			delete_transient( 'plandose_archive_fail_' . $user_id );
		}

		return count( $entries ) - count( $kept );
	}

	public static function maybe_reset_month( $user_id, $today ) {
		global $wpdb;

		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return false;
		}

		$date = $today ? DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $today, wp_timezone() ) : new DateTimeImmutable( 'today', wp_timezone() );

		if ( ! $date instanceof DateTimeImmutable ) {
			$date = new DateTimeImmutable( 'today', wp_timezone() );
		}

		$today       = $date->format( 'Y-m-d' );
		$month_start = $date->modify( 'first day of this month' )->format( 'Y-m-d' );
		$next_month  = $date->modify( 'first day of next month' )->format( 'Y-m-d' );
		$table       = self::table_name();

		/*
		 * Archive the outgoing month BEFORE the counter is zeroed —
		 * otherwise the number is gone for good and all that survives is
		 * the running month. Written first, not after: record_month_history()
		 * is idempotent (it never lowers a stored total), so if the reset
		 * below fails the next call simply repeats both steps harmlessly.
		 * The reverse order would lose the month whenever the second write
		 * failed.
		 *
		 * count_reset_at is a date inside the month the live count belongs
		 * to, which is what identifies the month being closed. A NULL means
		 * the row has never been reset and there is no month to attribute
		 * the count to, so nothing is archived.
		 *
		 * The reset is a compare-and-swap on the exact values just
		 * archived. With an unconditional "zero it if the month is old", a
		 * print landing between the SELECT and the UPDATE (a second tab, a
		 * retried request, right at midnight on the 1st) would be added to a
		 * counter that is then zeroed without it: archived as N, wiped as
		 * N + 1, one print gone from both months. So the UPDATE
		 * only matches while print_count still equals the archived value;
		 * if anything moved in between, zero rows match, the loop re-reads
		 * and archives the new, larger figure (GREATEST keeps it monotonic),
		 * and tries again. Whatever gets zeroed is therefore always exactly
		 * what was archived. No transaction or row lock is needed, and the
		 * print path's single-statement increments stay lock-free.
		 */
		$updated = 0;

		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table; {$table} is $wpdb->prefix + fixed suffix (not user input); prepare() cannot parameterize identifiers.
			$outgoing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT print_count, count_reset_at FROM {$table} WHERE user_id = %d",
					$user_id
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( ! $outgoing ) {
				return false;
			}

			$reset_at = empty( $outgoing->count_reset_at ) ? '' : substr( (string) $outgoing->count_reset_at, 0, 10 );

			// Already in the running month: nothing to close.
			if ( '' !== $reset_at && $reset_at >= $month_start && $reset_at < $next_month ) {
				return false;
			}

			$count = (int) $outgoing->print_count;

			// The counter is zeroed only once the month is safely
			// archived: if the INSERT fails (lock wait timeout, disk full,
			// missing table), the UPDATE below would wipe the closed month
			// for good. So nothing is zeroed: the next print or the daily
			// sweep repeats both steps (the archive is idempotent, GREATEST).
			if ( $count > 0 && '' !== $reset_at && ! self::record_month_history( $user_id, substr( $reset_at, 0, 7 ), $count ) ) {
				// …but only for a while: a history table that stays broken
				// must not stop a pharmacy printing for good (the increments
				// only count once the month is reset). After an hour of
				// failures the month is logged and the reset goes ahead.
				$fail_key    = 'plandose_archive_fail_' . $user_id;
				$first_fail  = (int) get_transient( $fail_key );

				if ( $first_fail < 1 ) {
					$first_fail = time();
					set_transient( $fail_key, $first_fail, DAY_IN_SECONDS );
				}

				if ( ( time() - $first_fail ) < self::ARCHIVE_RETRY_SECONDS ) {
					return false;
				}

				// Kept where an admin sees it (wp-admin notice,
				// Διαγνωστικά, wp plandose check) and retried by the daily
				// cron — the error log alone is rarely read.
				self::remember_unarchived( $user_id, substr( $reset_at, 0, 7 ), $count );

				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- a second copy for server logs / monitoring.
				error_log( sprintf( 'PlanDose: the print history of user %d for %s (%d prints) could not be archived for over an hour; the counter was reset anyway and the figure kept in «%s» for retry. Check the table %s.', $user_id, substr( $reset_at, 0, 7 ), $count, self::UNARCHIVED_OPTION, self::history_table_name() ) );
			} elseif ( $count > 0 && '' !== $reset_at ) {
				delete_transient( 'plandose_archive_fail_' . $user_id );
			}

			// prepare() cannot bind NULL, so the NULL case gets its own
			// comparison rather than a '<=> %s' that would compare to ''.
			$reset_clause = '' === $reset_at ? 'count_reset_at IS NULL' : 'count_reset_at = %s';
			$args         = array( $today, $user_id, $count );

			if ( '' !== $reset_at ) {
				$args[] = $reset_at;
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table; {$table} is $wpdb->prefix + fixed suffix and {$reset_clause} is one of two fixed strings; every value is bound through prepare().
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table}
					 SET print_count = 0, count_reset_at = %s
					 WHERE user_id = %d
					 AND print_count = %d
					 AND {$reset_clause}",
					$args
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

			if ( false === $updated ) {
				// A real DB error, not a lost race: retrying the same
				// statement at once would only fail again. The next print
				// or the daily sweep will repeat both steps.
				return false;
			}

			// No cache drop here: zeroing a closed month's counter
			// changes no membership, and stats() already discards a
			// previous month's cached figures.
			if ( (int) $updated > 0 ) {
				return true;
			}
		}

		/*
		 * Five lost races in a row means the counter is being hammered;
		 * the row stays unreset for now and the next request (or the daily
		 * sweep) closes it. Everything archived so far is still correct —
		 * a later, larger total only ever raises the history row.
		 */
		return false;
	}

	/**
	 * Store the print total for one closed month.
	 *
	 * Idempotent by design: re-running with the same or a lower figure
	 * leaves the stored total alone (GREATEST), so a retry after a failed
	 * reset can never double-count or silently shrink a month. Two
	 * concurrent requests racing on the same month therefore both end up
	 * writing the same value.
	 *
	 * This table feeds reporting, not the print limit. A failure
	 * holds back the month's reset in maybe_reset_month() for at most
	 * ARCHIVE_RETRY_SECONDS (so a passing glitch loses nothing), then the
	 * figure is logged and the reset goes ahead — a broken history table
	 * never stops a pharmacist from printing for longer than that.
	 *
	 * @param int    $user_id Pharmacy user ID.
	 * @param string $ym      Month being closed, 'YYYY-MM'.
	 * @param int    $prints  Print total for that month.
	 * @return bool True when the row was written.
	 */
	public static function record_month_history( $user_id, $ym, $prints ) {
		global $wpdb;

		$user_id = absint( $user_id );
		$prints  = absint( $prints );
		$ym      = (string) $ym;

		if ( ! $user_id || ! preg_match( '/^\d{4}-\d{2}$/D', $ym ) ) {
			return false;
		}

		$history_table = self::history_table_name();
		$now           = current_time( 'mysql' );

		/*
		 * GREATEST( prints, %d ) rather than MySQL's VALUES() function:
		 * VALUES() is deprecated from MySQL 8.0.20 and its replacement
		 * (the row-alias form) is not available on MariaDB, so the literal
		 * is simply bound twice and the statement stays portable.
		 */
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table; {$history_table} is $wpdb->prefix + fixed suffix (not user input); prepare() cannot parameterize identifiers.
		$written = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$history_table} ( user_id, ym, prints, archived_at )
				 VALUES ( %d, %s, %d, %s )
				 ON DUPLICATE KEY UPDATE prints = GREATEST( prints, %d ), archived_at = %s",
				$user_id,
				$ym,
				$prints,
				$now,
				$prints,
				$now
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return false !== $written;
	}

	/**
	 * Closed months for one pharmacy, most recent first.
	 *
	 * Does NOT include the running month — that total is still live in
	 * plandose_subscriptions.print_count and only lands here once the
	 * month rolls over. Callers that want a continuous series have to
	 * append the current row themselves.
	 *
	 * @param int $user_id Pharmacy user ID.
	 * @param int $limit   How many months back, 1-120.
	 * @return array<int,object> Rows with ->ym and ->prints.
	 */
	public static function get_print_history( $user_id, $limit = 12 ) {
		global $wpdb;

		$user_id = absint( $user_id );
		$limit   = min( 120, max( 1, absint( $limit ) ) );

		if ( ! $user_id ) {
			return array();
		}

		$history_table = self::history_table_name();
		$table         = self::table_name();
		$month_start   = self::current_month_bounds()[0];

		// Closed months still sitting in print_count count too — see
		// the note on month_summary().
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables; {$history_table} and {$table} are $wpdb->prefix + fixed suffix (not user input); prepare() cannot parameterize identifiers.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ym, MAX( prints ) AS prints FROM (
					SELECT ym, prints FROM {$history_table}
					WHERE user_id = %d
					UNION ALL
					SELECT DATE_FORMAT( count_reset_at, '%%Y-%%m' ) AS ym, print_count AS prints FROM {$table}
					WHERE user_id = %d AND print_count > 0 AND count_reset_at IS NOT NULL AND count_reset_at < %s
				 ) merged
				 GROUP BY ym
				 ORDER BY ym DESC
				 LIMIT %d",
				$user_id,
				$user_id,
				$month_start,
				$limit
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $rows ) ) {
			return array();
		}

		foreach ( $rows as $row ) {
			$row->prints = (string) (int) $row->prints;
		}

		return $rows;
	}

	/**
	 * Closed-month history for many pharmacies in one query.
	 *
	 * The admin list renders up to 100 cards at a time; calling
	 * get_print_history() per card would mean up to 100 queries for one
	 * page view. This fetches the whole page's history at once and returns
	 * it keyed for direct lookup.
	 *
	 * The month list is bounded in PHP rather than with an "ORDER BY ym
	 * DESC LIMIT n" (which would cap the whole result set, not each
	 * pharmacy's slice), so the IN() clause stays small and predictable.
	 *
	 * @param array $user_ids Pharmacy user IDs.
	 * @param int   $months   How many closed months back, 1-36.
	 * @return array<int,array<string,int>> user_id => ( 'YYYY-MM' => prints ).
	 */
	public static function get_print_history_for_users( $user_ids, $months = 6 ) {
		global $wpdb;

		$months = min( 36, max( 1, absint( $months ) ) );

		$user_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $user_ids ) ) ) );

		if ( empty( $user_ids ) ) {
			return array();
		}

		$ym_list = self::recent_months( $months );

		$user_placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
		$ym_placeholders   = implode( ',', array_fill( 0, count( $ym_list ), '%s' ) );
		$history_table     = self::history_table_name();
		$table             = self::table_name();
		$month_start       = self::current_month_bounds()[0];
		$oldest_start      = end( $ym_list ) . '-01';

		// Closed months still sitting in print_count count too — see
		// the note on month_summary().
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin tables; {$history_table} and {$table} are $wpdb->prefix + fixed suffix and the placeholder lists are generated from counts, never from input. Every value is bound through prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, ym, MAX( prints ) AS prints FROM (
					SELECT user_id, ym, prints FROM {$history_table}
					WHERE user_id IN ( {$user_placeholders} )
					AND ym IN ( {$ym_placeholders} )
					UNION ALL
					SELECT user_id, DATE_FORMAT( count_reset_at, '%%Y-%%m' ) AS ym, print_count AS prints FROM {$table}
					WHERE user_id IN ( {$user_placeholders} )
					AND print_count > 0 AND count_reset_at >= %s AND count_reset_at < %s
				 ) merged
				 GROUP BY user_id, ym",
				array_merge( $user_ids, $ym_list, $user_ids, array( $oldest_start, $month_start ) )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$out = array();

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$out[ (int) $row->user_id ][ (string) $row->ym ] = (int) $row->prints;
			}
		}

		return $out;
	}

	/**
	 * The last N closed months, most recent first, as 'YYYY-MM'.
	 *
	 * Starts at last month: the running month is never in the history
	 * table (see get_print_history()).
	 *
	 * @param int $months How many months, 1-36.
	 * @return array<int,string>
	 */
	public static function recent_months( $months = 6 ) {
		$months = min( 36, max( 1, absint( $months ) ) );
		$cursor = current_datetime()->modify( 'first day of last month' );
		$list   = array();

		for ( $i = 0; $i < $months; $i++ ) {
			$list[] = $cursor->format( 'Y-m' );
			$cursor = $cursor->modify( 'first day of last month' );
		}

		return $list;
	}

	/**
	 * Site-wide totals for one closed month.
	 *
	 * 'pharmacies' counts only those that printed at all that month, which
	 * is the number worth watching: it separates real adoption from the
	 * subscriber count.
	 *
	 * @param string $ym Month, 'YYYY-MM'.
	 * @return array{prints:int,pharmacies:int}
	 */
	public static function month_summary( $ym ) {
		global $wpdb;

		$ym = (string) $ym;

		if ( ! preg_match( '/^\d{4}-\d{2}$/D', $ym ) ) {
			return array(
				'prints'     => 0,
				'pharmacies' => 0,
			);
		}

		$history_table = self::history_table_name();
		$table         = self::table_name();
		$ym_start      = $ym . '-01';
		$month_start   = self::current_month_bounds()[0];

		/*
		 * A month is only filed into the history table when its
		 * counter is reset — on the pharmacy's next print, or by the daily
		 * archive_idle_months() sweep. Until then (on the 1st, until the
		 * cron runs) the closed month's total still sits in print_count,
		 * and every "last month" figure would read 0 for that pharmacy.
		 * Counters whose count_reset_at falls in a CLOSED month are
		 * therefore read as that month's total too.
		 *
		 * A month can briefly exist in both places (archived, but the reset
		 * that follows failed). The copies hold the same figure or the live
		 * one is larger, never a different month's prints, so they are
		 * merged with MAX per pharmacy — the same rule record_month_history()
		 * applies with GREATEST — never added together. The running month
		 * is never read from print_count here.
		 */
		$live_sql    = '';
		$live_params = array();

		if ( $ym_start < $month_start ) {
			$live_sql    = "UNION ALL
					SELECT user_id, print_count AS prints FROM {$table}
					WHERE print_count > 0
					AND count_reset_at >= %s
					AND count_reset_at < DATE_ADD( %s, INTERVAL 1 MONTH )";
			$live_params = array( $ym_start, $ym_start );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin tables; {$history_table} and {$table} are $wpdb->prefix + fixed suffix (not user input) and {$live_sql} is one of two fixed strings whose placeholders the sniff cannot see; every value is bound through prepare().
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COALESCE( SUM( prints ), 0 ) AS prints, COUNT(*) AS pharmacies FROM (
					SELECT user_id, MAX( prints ) AS prints FROM (
						SELECT user_id, prints FROM {$history_table}
						WHERE ym = %s
						{$live_sql}
					) merged
					GROUP BY user_id
				 ) per_pharmacy
				 WHERE prints > 0",
				array_merge( array( $ym ), $live_params )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return array(
			'prints'     => $row ? (int) $row->prints : 0,
			'pharmacies' => $row ? (int) $row->pharmacies : 0,
		);
	}

	/**
	 * Remove every history row for one pharmacy.
	 *
	 * Called from delete_row() so account deletion and the GDPR eraser
	 * clear this table too — it is keyed by user_id and would otherwise
	 * outlive the account it describes.
	 *
	 * @param int $user_id Pharmacy user ID.
	 * @return int|false Rows deleted, or false on failure.
	 */
	public static function delete_history_for_user( $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return false;
		}

		// The months still waiting to be archived go with the history.
		self::forget_unarchived( $user_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table delete; no object cache applies to a write.
		return $wpdb->delete( self::history_table_name(), array( 'user_id' => $user_id ), array( '%d' ) );
	}

	/**
	 * Close out months for pharmacies that have stopped using the tool.
	 *
	 * maybe_reset_month() only ever runs when someone actually reaches the
	 * print flow, so a pharmacy that goes quiet leaves its last active
	 * month frozen in print_count and never archived — which is precisely
	 * the pharmacy worth noticing. This sweep, on the existing daily cron,
	 * closes those rows so the history is complete rather than
	 * survivorship-biased.
	 *
	 * Registered on PLANDOSE_CLEANUP_CRON_HOOK in plandose.php, alongside
	 * cleanup_stale_print_locks() and cleanup_audit_log().
	 *
	 * Works through the WHOLE backlog in batches instead of stopping
	 * after one. At a fixed 200 rows per daily run, a site with 1,000
	 * pharmacies would keep last month's total in print_count for four
	 * more days for the last of them. Readers do not trust a stale
	 * counter (see current_month_count()), but the history table should
	 * still be complete on the 2nd of the month, not the 6th.
	 *
	 * The batches walk forward by user_id, so a row maybe_reset_month()
	 * could not close (a DB error, or a pharmacy printing so fast that
	 * the compare-and-swap kept losing) is skipped rather than fetched
	 * again forever. A time budget keeps one cron request bounded on a
	 * very large site; anything left over is picked up the next day.
	 *
	 * @param int $batch_size  Rows per batch, 1-1000.
	 * @param int $time_budget Seconds after which no new batch starts.
	 * @return int Number of rows closed.
	 */
	public static function archive_idle_months( $batch_size = 200, $time_budget = 20 ) {
		global $wpdb;

		$batch_size  = min( 1000, max( 1, absint( $batch_size ) ) );
		$time_budget = max( 1, absint( $time_budget ) );
		$started     = microtime( true );

		$date        = new DateTimeImmutable( 'today', wp_timezone() );
		$today       = $date->format( 'Y-m-d' );
		$month_start = $date->modify( 'first day of this month' )->format( 'Y-m-d' );
		$next_month  = $date->modify( 'first day of next month' )->format( 'Y-m-d' );
		$table       = self::table_name();
		$after_id    = 0;
		$closed      = 0;

		do {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table; {$table} is $wpdb->prefix + fixed suffix (not user input); prepare() cannot parameterize identifiers.
			$user_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT user_id FROM {$table}
					 WHERE user_id > %d
					 AND print_count > 0
					 AND count_reset_at IS NOT NULL
					 AND ( count_reset_at < %s OR count_reset_at >= %s )
					 ORDER BY user_id ASC
					 LIMIT %d",
					$after_id,
					$month_start,
					$next_month,
					$batch_size
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( ! $user_ids ) {
				break;
			}

			foreach ( $user_ids as $user_id ) {
				$after_id = max( $after_id, (int) $user_id );

				if ( self::maybe_reset_month( (int) $user_id, $today ) ) {
					++$closed;
				}
			}
		} while ( count( $user_ids ) === $batch_size && ( microtime( true ) - $started ) < $time_budget );

		return $closed;
	}

	/**
	 * Decode invoices JSON safely.
	 */
	public static function decode_invoices( $invoices ) {
		if ( empty( $invoices ) ) {
			return array();
		}

		$decoded = is_array( $invoices ) ? $invoices : json_decode( (string) $invoices, true );

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		return array_values( array_filter( $decoded, static function ( $entry ) {
			return is_numeric( $entry ) || ( is_string( $entry ) && '' !== sanitize_file_name( basename( $entry ) ) );
		} ) );
	}

	/**
	 * Permanently remove a user's subscription row. Used when the WP user
	 * account itself is deleted, so the row doesn't sit around forever tied
	 * to a user_id that no longer exists (see Plandose_Admin::handle_user_deleted()).
	 */
	public static function delete_row( $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table delete; no object cache applies to a write.
		$result = $wpdb->delete( self::table_name(), array( 'user_id' => $user_id ), array( '%d' ) );

		/*
		 * Unconditional, not gated on $result: a pharmacy whose
		 * subscription row was already gone can still have history rows,
		 * and those must not survive the account they belong to.
		 */
		self::delete_history_for_user( $user_id );

		if ( $result ) {
			Plandose_Subscriber_Query::invalidate_subscriber_cache();
		}

		return $result;
	}

	/**
	 * Privacy erasure of a LIVE Free account that has printed this
	 * month. Deleting the row would re-create it on the next print with
	 * print_count 0 — a fresh monthly allowance for asking. Instead the row
	 * keeps only the counter (print_count, count_reset_at) and a plain
	 * 'free' status; the Pro end date and last print time are cleared.
	 *
	 * @param int $user_id User ID.
	 * @return int|false Rows changed (0 when nothing needed clearing), false on a database error.
	 */
	public static function minimize_row( $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return false;
		}

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table; {$table} is $wpdb->prefix + fixed suffix.
		$result = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'free', sub_end_date = NULL, last_print_at = NULL WHERE user_id = %d", $user_id ) );

		if ( false !== $result ) {
			Plandose_Subscriber_Query::invalidate_subscriber_cache();
		}

		return false === $result ? false : (int) $result;
	}

	/**
	 * Keep a deleted pharmacy's invoices, drop everything else.
	 *
	 * Invoices are the site owner's own accounting records and stay until
	 * the administrator deletes them. The `invoices` column of this row is
	 * the only record of which files on disk belong to which pharmacy, so
	 * for an account that had invoices the row is kept — but emptied of
	 * everything that described the live subscription. print_count and
	 * count_reset_at are cleared so archive_idle_months() (which only picks
	 * rows with print_count > 0 and a count_reset_at) never files history
	 * rows for an account that no longer exists.
	 *
	 * Written directly rather than through update(), which refuses to touch
	 * a row whose WordPress account is gone.
	 *
	 * @param int $user_id Deleted user's ID.
	 * @return bool True when the row was written.
	 */
	public static function retire_row( $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return false;
		}

		self::delete_history_for_user( $user_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table update; no object cache applies to a write.
		$result = $wpdb->update(
			self::table_name(),
			array(
				'status'         => 'free',
				'sub_end_date'   => null,
				'print_count'    => 0,
				'count_reset_at' => null,
				'last_print_at'  => null,
			),
			array( 'user_id' => $user_id ),
			array( '%s', '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);

		Plandose_Subscriber_Query::invalidate_subscriber_cache();

		return false !== $result;
	}

	/**
	 * Invoice lists of accounts that no longer exist in WordPress.
	 *
	 * These are the rows retire_row() kept. They never appear in the
	 * Subscriptions list, which is built from wp_users, so the admin screen
	 * reads them from here instead.
	 *
	 * @return array<int,array<int,string|int>> user_id => invoice entries.
	 */
	public static function deleted_account_invoices() {
		global $wpdb;

		$table = self::table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table; {$table} is $wpdb->prefix + fixed suffix and $wpdb->users is core's. No user input.
		$rows = $wpdb->get_results(
			"SELECT s.user_id, s.invoices
			 FROM {$table} s
			 LEFT JOIN {$wpdb->users} u ON u.ID = s.user_id
			 WHERE u.ID IS NULL
			 AND s.invoices IS NOT NULL
			 AND TRIM( s.invoices ) NOT IN ( '', '[]', 'null' )
			 ORDER BY s.user_id ASC"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = array();

		foreach ( (array) $rows as $row ) {
			$invoices = self::decode_invoices( $row->invoices );

			if ( $invoices ) {
				$out[ (int) $row->user_id ] = $invoices;
			}
		}

		return $out;
	}

	/**
	 * Delete stale *legacy* print-lock debounce options.
	 *
	 * Active print-debounce locks are stored as short-lived
	 * transient-backed entries, not plain wp_options rows. The current
	 * lifecycle is managed by Plandose_Print_Lock::acquire() and
	 * Plandose_Print_Lock::release(), and those transient-backed locks
	 * expire automatically after PRINT_DEBOUNCE_SECONDS.
	 *
	 * This method exists purely to finish sweeping up rows created by
	 * *pre-1.7.2* installs, which used a plain wp_options row keyed
	 * "plandose_print_lock_{user_id}_{Ym}" that nothing ever deleted on its
	 * own — over years of use those could accumulate one row per user per
	 * month. The LIKE match below cannot catch current transient-backed rows:
	 * those are stored under WordPress's own "_transient_..." option-name
	 * prefix, which never matches the legacy "plandose_print_lock_..."
	 * pattern.
	 *
	 * Runs daily via wp_cron (see plandose.php), but only removes options
	 * from months before the previous one — a lock for the current month
	 * (or last month, kept as a boundary-safety margin) is left in place
	 * until that month has fully elapsed. Once a site's legacy rows are
	 * fully swept, this simply finds nothing to do on every future run; it
	 * can be removed in a later version once pre-1.7.2 upgrades are rare.
	 */
	public static function cleanup_stale_print_locks() {
		global $wpdb;

		// current_datetime() is site-timezone "now" as an immutable object.
		// 'first day of last month' avoids the strtotime('-1 month') overflow
		// that lands on the wrong month on the 29th-31st (e.g. Mar 31 minus one
		// month resolves to Mar 03), and avoids the current_time('timestamp')
		// form flagged by WordPress.DateTime.CurrentTimeTimestamp.
		$now         = current_datetime();
		$keep_months = array(
			$now->format( 'Ym' ),
			$now->modify( 'first day of last month' )->format( 'Ym' ),
		);

		$like = $wpdb->esc_like( 'plandose_print_lock_' ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Daily cron sweep of legacy option rows by name pattern; a cached lookup would not reflect rows created since.
		$option_names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$like
			)
		);

		if ( ! $option_names ) {
			return;
		}

		foreach ( $option_names as $option_name ) {
			// Pre-1.7.2 legacy rows only: their name ended in the 6-character
			// "Ym" month stamp ("plandose_print_lock_{user_id}_{Ym}"),
			// regardless of how many digits the user_id had.
			// Plandose_Print_Lock::key() has no month stamp —
			// it ends in an md5 of the print token and is stored under the
			// "_transient_" prefix, which this LIKE never matches anyway.
			$month = substr( $option_name, -6 );

			if ( ! in_array( $month, $keep_months, true ) ) {
				delete_option( $option_name );
			}
		}
	}
}