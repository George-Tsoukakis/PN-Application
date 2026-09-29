<?php
/**
 * WordPress admin integration for PlanDose: menu registration, shared page
 * chrome/helpers, the audit log writer, and the deleted-user cleanup hook.
 *
 * This class is a thin coordinator. The actual settings screen, invoice
 * handling, and subscriptions dashboard/actions live in their own classes:
 * - class-plandose-admin-settings.php      → Plandose_Admin_Settings
 * - class-plandose-admin-invoices.php      → Plandose_Admin_Invoices
 * - class-plandose-admin-subscriptions.php → Plandose_Admin_Subscriptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plandose_Admin {

	/**
	 * Capability required for all PlanDose admin screens/actions.
	 *
	 * Centralized (rather than hardcoding 'manage_options' at each call
	 * site) so a site can, via the 'plandose_manage_capability' filter,
	 * delegate PlanDose management to a narrower custom capability/role
	 * without granting full site administrator access.
	 */
	public static function capability() {
		$capability = apply_filters( 'plandose_manage_capability', 'manage_options' );
		$capability = is_string( $capability ) ? sanitize_key( $capability ) : '';

		return '' !== $capability ? $capability : 'manage_options';
	}

	/**
	 * May the current user see the pharmacies' contact and tax
	 * details (the subscriber lists, the CSV export, the audit log)?
	 *
	 * The PlanDose capability alone is not enough: it can be delegated
	 * (plandose_manage_capability) to a role that should manage PlanDose
	 * without seeing every customer's email, mobile and AFM. Viewing the
	 * accounts additionally needs core's list_users.
	 *
	 * @return bool
	 */
	public static function can_view_accounts() {
		return current_user_can( self::capability() ) && current_user_can( 'list_users' );
	}

	/**
	 * May the current user change PlanDose data of this account
	 * (subscription, access override, invoices)?
	 *
	 * Checked explicitly instead of relying on edit_user alone, because on
	 * a single site map_meta_cap() turns edit_user into the plain
	 * edit_users capability (administrators included), and edit_user on
	 * one's own ID always passes. So:
	 *
	 * - the PlanDose capability and edit_user are both required;
	 * - someone who is not a site administrator (manage_options) may not
	 *   change their OWN subscription or access — no self-granted Pro;
	 * - nor an administrator's account, nor that of another
	 *   delegated PlanDose manager.
	 *
	 * @param int $user_id Target account.
	 * @return bool
	 */
	public static function can_manage_account( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id || ! current_user_can( self::capability() ) || ! current_user_can( 'edit_user', $user_id ) ) {
			return false;
		}

		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		if ( get_current_user_id() === $user_id ) {
			return false;
		}

		return ! user_can( $user_id, 'manage_options' ) && ! user_can( $user_id, self::capability() );
	}

	/**
	 * Guards against registering the admin hooks / re-running the component
	 * init loop more than once. WordPress already de-duplicates identical
	 * static-method callbacks by unique id, so for the two add_action() calls
	 * this is defensive; it also keeps each component's init() from being
	 * invoked twice.
	 *
	 * @var bool
	 */
	private static $initialized = false;

	public static function init() {
		if ( self::$initialized ) {
			return;
		}

		self::$initialized = true;

		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );

		$components = array(
			'Plandose_Admin_Settings',
			'Plandose_Admin_Invoices',
			'Plandose_Invoice_Migration',
			'Plandose_Admin_Subscriptions',
		);

		foreach ( $components as $component ) {
			if ( class_exists( $component ) && method_exists( $component, 'init' ) ) {
				call_user_func( array( $component, 'init' ) );
			}
		}
	}

	/**
	 * Non-autoloaded option holding a small identity snapshot of every
	 * deleted pharmacy whose invoices were kept. Without it the
	 * retained invoices would be listed under a bare user ID, since the
	 * name, email and AFM disappear with the WordPress account.
	 */
	const DELETED_ACCOUNTS_OPTION = 'plandose_deleted_accounts';

	/**
	 * Hooked to 'delete_user', which WordPress fires BEFORE it removes the
	 * account — the last moment the pharmacy's name, email and AFM can
	 * still be read. Only accounts that actually have invoices are recorded.
	 *
	 * @param int $user_id User about to be deleted.
	 */
	public static function snapshot_before_delete( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id || ! class_exists( 'Plandose_Subscriptions' ) ) {
			return;
		}

		$row = Plandose_Subscriptions::get_row( $user_id, false );

		if ( ! $row || ! Plandose_Subscriptions::decode_invoices( $row->invoices ) ) {
			return;
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return;
		}

		$name = Plandose_Access::get_meta_with_fallback( $user_id, Plandose_Access::PHARMACY_NAME_META_KEYS );
		$afm  = Plandose_Access::get_meta_with_fallback( $user_id, Plandose_Access::AFM_META_KEYS );

		$accounts = get_option( self::DELETED_ACCOUNTS_OPTION, array() );
		$accounts = is_array( $accounts ) ? $accounts : array();

		$accounts[ $user_id ] = array(
			'name'       => sanitize_text_field( (string) ( $name ? $name : $user->display_name ) ),
			'email'      => sanitize_email( (string) $user->user_email ),
			'afm'        => sanitize_text_field( (string) $afm ),
			'deleted_at' => current_time( 'mysql' ),
		);

		update_option( self::DELETED_ACCOUNTS_OPTION, $accounts, false );
	}

	/**
	 * Snapshot recorded by snapshot_before_delete(), or an empty array.
	 *
	 * @param int $user_id Deleted user's ID.
	 * @return array{name?:string,email?:string,afm?:string,deleted_at?:string}
	 */
	public static function deleted_account_snapshot( $user_id ) {
		$accounts = get_option( self::DELETED_ACCOUNTS_OPTION, array() );
		$user_id  = absint( $user_id );

		return ( is_array( $accounts ) && isset( $accounts[ $user_id ] ) && is_array( $accounts[ $user_id ] ) )
			? $accounts[ $user_id ]
			: array();
	}

	/**
	 * Remove a snapshot once its invoices have been deleted.
	 *
	 * @param int $user_id Deleted user's ID.
	 */
	public static function forget_deleted_account( $user_id ) {
		$accounts = get_option( self::DELETED_ACCOUNTS_OPTION, array() );
		$user_id  = absint( $user_id );

		if ( ! is_array( $accounts ) || ! isset( $accounts[ $user_id ] ) ) {
			return;
		}

		unset( $accounts[ $user_id ] );
		update_option( self::DELETED_ACCOUNTS_OPTION, $accounts, false );
	}

	/**
	 * Hooked to 'deleted_user' (after WordPress has removed the account),
	 * registered unconditionally from plandose.php so WP-CLI deletions are
	 * covered too.
	 *
	 * Invoices are NEVER deleted here. They are the site owner's
	 * accounting records and stay until the administrator deletes them from
	 * PlanDose → Συνδρομές → «Τιμολόγια διαγραμμένων λογαριασμών». An
	 * account with invoices has its subscription row retired (see
	 * Plandose_Subscriptions::retire_row()), because that row is the only
	 * record of which files belong to it; an account without invoices is
	 * removed completely. Print history is dropped either way.
	 *
	 * @param int $user_id Deleted user's ID.
	 */
	public static function handle_user_deleted( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return;
		}

		if ( class_exists( 'Plandose_Subscriptions' ) ) {
			$row      = Plandose_Subscriptions::get_row( $user_id, false );
			$invoices = $row ? Plandose_Subscriptions::decode_invoices( $row->invoices ) : array();

			if ( $invoices ) {
				Plandose_Subscriptions::retire_row( $user_id );
				self::audit( 'account_deleted_invoices_kept', $user_id, array( 'invoices' => count( $invoices ) ) );
			} else {
				Plandose_Subscriptions::delete_row( $user_id );
			}
		}

		if ( class_exists( 'Plandose_Ajax' ) && method_exists( 'Plandose_Ajax', 'delete_rate_limit_for_user' ) ) {
			Plandose_Ajax::delete_rate_limit_for_user( $user_id );
		}

		// Its print log goes with the account, like the print
		// history (it could not be reached by a privacy request later).
		if ( class_exists( 'Plandose_Print_Log' ) ) {
			Plandose_Print_Log::delete_for_user( $user_id );
		}
	}

	/**
	 * Record a sensitive admin action to the plandose_audit_log table.
	 *
	 * Covers subscription status changes, manual access overrides, invoice
	 * upload/view, and the automated legacy-invoice migration — actions
	 * that touch AFM/tax data, pharmacy identity, or a pharmacist's access
	 * to the tool, and which admins may later need to account for. Public
	 * so the Settings/Invoices/Subscriptions classes can all log to the
	 * same table. Deliberately best-effort: a logging failure never blocks
	 * the underlying action.
	 *
	 * @param string $event          Event key (see the audit page labels).
	 * @param int    $target_user_id Account the action concerns, or 0.
	 * @param mixed  $meta           Details, stored as JSON.
	 * @param bool   $as_system      An automatic change made by the plugin
	 *                               itself: no acting user and no IP, so it is
	 *                               not credited to whoever happened to send
	 *                               the request that ran it.
	 * @return bool
	 */
	public static function audit( $event, $target_user_id = 0, $meta = array(), $as_system = false ) {
		global $wpdb;

		if ( ! class_exists( 'Plandose_Subscriptions' ) || ! method_exists( 'Plandose_Subscriptions', 'audit_table_name' ) ) {
			return false;
		}

		$event = sanitize_key( (string) $event );
		$event = substr( $event, 0, 64 );

		if ( '' === $event ) {
			return false;
		}

		$table = Plandose_Subscriptions::audit_table_name();

		$ip = '';
		if ( ! $as_system && isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$remote_addr = trim( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) );

			if ( filter_var( $remote_addr, FILTER_VALIDATE_IP ) ) {
				// Guarded on the class actually being called, so a
				// deployment missing only Plandose_Settings does not fatal.
				//
				// The fallback is 1, matching Plandose_Settings::default_settings().
				// It is unreachable in practice (settings() merges the defaults
				// in), but 0 would mean that if it ever were reached the plugin would
				// quietly start writing full IP addresses into the audit log —
				// the wrong way round for a privacy default.
				$anonymize = class_exists( 'Plandose_Settings' )
					&& (int) Plandose_Settings::setting( 'audit_anonymize_ip', 1 );

				if ( $anonymize ) {
					$remote_addr = self::anonymize_ip( $remote_addr );
				}

				$ip = substr( $remote_addr, 0, 45 );
			}
		}

		if ( ! is_array( $meta ) ) {
			$meta = array( 'value' => sanitize_text_field( (string) $meta ) );
		}

		$encoded_meta = wp_json_encode(
			$meta,
			defined( 'JSON_INVALID_UTF8_SUBSTITUTE' ) ? JSON_INVALID_UTF8_SUBSTITUTE : 0
		);

		if ( false === $encoded_meta ) {
			$encoded_meta = '{}';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- audit log insert into a custom plugin table; nothing to cache.
		$result = $wpdb->insert(
			$table,
			array(
				'created_at'     => current_time( 'mysql' ),
				'event'          => $event,
				'admin_user_id'  => $as_system ? 0 : get_current_user_id(),
				'target_user_id' => absint( $target_user_id ),
				'ip'             => $ip,
				'meta'           => $encoded_meta,
			),
			array( '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		if ( false === $result && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- failure logged for the site admin (debug.log), not shown to users.
			error_log( '[PlanDose] Audit log insert failed for event: ' . $event );
		}

		return false !== $result;
	}

	/**
	 * Reduce an IP address to a coarser form for privacy (last IPv4 octet,
	 * last 80 bits of IPv6), using core's wp_privacy_anonymize_ip().
	 *
	 * @param string $ip Raw IP address.
	 * @return string Anonymized IP address.
	 */
	public static function anonymize_ip( $ip ) {
		return (string) wp_privacy_anonymize_ip( $ip );
	}

	/**
	 * Delete audit-log rows older than the configured retention window.
	 * A retention of 0 means "keep forever" and skips deletion entirely.
	 * Hooked to the daily cleanup cron (see plandose.php).
	 */
	public static function cleanup_audit_log() {
		global $wpdb;

		if ( ! class_exists( 'Plandose_Subscriptions' ) || ! method_exists( 'Plandose_Subscriptions', 'audit_table_name' ) || ! class_exists( 'Plandose_Settings' ) ) {
			return;
		}

		$retention_days = (int) Plandose_Settings::setting( 'audit_retention_days', 180 );

		if ( $retention_days <= 0 ) {
			return;
		}

		$table = Plandose_Subscriptions::audit_table_name();

		// Compare against the same wall-clock basis audit() writes with
		// (current_time( 'mysql' )), so retention is measured in site-local
		// time. current_datetime() is site-timezone "now"; subtracting days
		// with modify() is calendar-aware (correct across DST transitions) and
		// avoids the current_time( 'timestamp' ) form flagged by
		// WordPress.DateTime.CurrentTimeTimestamp.
		$cutoff = current_datetime()->modify( '-' . $retention_days . ' days' )->format( 'Y-m-d H:i:s' );

		// In chunks, so a first run over a large backlog neither
		// holds a long lock on the table nor runs into the time limit.
		for ( $chunk = 0; $chunk < self::AUDIT_CLEANUP_MAX_CHUNKS; $chunk++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table; $table is Plandose_Subscriptions::audit_table_name() ($wpdb->prefix + fixed suffix), not user input.
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM `{$table}` WHERE created_at < %s ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + fixed suffix; every value is bound.
					$cutoff,
					self::AUDIT_CLEANUP_CHUNK
				)
			);

			if ( false === $deleted || $deleted < self::AUDIT_CLEANUP_CHUNK ) {
				break;
			}
		}
	}

	/** Audit rows deleted per statement, and statements per run. */
	const AUDIT_CLEANUP_CHUNK      = 1000;
	const AUDIT_CLEANUP_MAX_CHUNKS = 200;

	public static function admin_menu() {
		$cap = self::capability();

		if ( ! class_exists( 'Plandose_Admin_Subscriptions' ) || ! class_exists( 'Plandose_Admin_Settings' ) ) {
			return;
		}

		add_menu_page(
			__( 'PlanDose', 'plandose' ),
			__( 'PlanDose', 'plandose' ),
			$cap,
			'plandose',
			array( 'Plandose_Admin_Subscriptions', 'render_dashboard_page' ),
			'dashicons-clipboard',
			58
		);

		add_submenu_page( 'plandose', __( 'Dashboard', 'plandose' ), __( 'Dashboard', 'plandose' ), $cap, 'plandose', array( 'Plandose_Admin_Subscriptions', 'render_dashboard_page' ) );
		add_submenu_page( 'plandose', __( 'Συνδρομές', 'plandose' ), __( 'Συνδρομές', 'plandose' ), $cap, 'plandose-subscriptions', array( 'Plandose_Admin_Subscriptions', 'render_subscriptions_page' ) );
		add_submenu_page( 'plandose', __( 'Δωρεάν', 'plandose' ), __( 'Δωρεάν', 'plandose' ), $cap, 'plandose-free', array( 'Plandose_Admin_Subscriptions', 'render_free_page' ) );
		add_submenu_page( 'plandose', __( 'Pro', 'plandose' ), __( 'Pro', 'plandose' ), $cap, 'plandose-pro', array( 'Plandose_Admin_Subscriptions', 'render_pro_page' ) );
		// Every print, newest first (Plandose_Admin_Prints).
		if ( class_exists( 'Plandose_Admin_Prints' ) ) {
			add_submenu_page( 'plandose', __( 'Εκτυπώσεις', 'plandose' ), __( 'Εκτυπώσεις', 'plandose' ), $cap, Plandose_Admin_Prints::PAGE, array( 'Plandose_Admin_Prints', 'render_page' ) );
		}
		add_submenu_page( 'plandose', __( 'Ημερολόγιο', 'plandose' ), __( 'Ημερολόγιο', 'plandose' ), $cap, 'plandose-audit', array( 'Plandose_Admin_Subscriptions', 'render_audit_page' ) );
		add_submenu_page( 'plandose', __( 'Settings', 'plandose' ), __( 'Settings', 'plandose' ), $cap, 'plandose-settings', array( 'Plandose_Admin_Settings', 'render_settings_page' ) );

		if ( class_exists( 'Plandose_Diagnostics' ) ) {
			add_submenu_page( 'plandose', __( 'Διαγνωστικά', 'plandose' ), __( 'Διαγνωστικά', 'plandose' ), $cap, 'plandose-diagnostics', array( 'Plandose_Diagnostics', 'render_page' ) );
		}
	}

	/**
	 * Screen IDs of the PlanDose admin pages. Used to load admin CSS/JS only
	 * on our own screens, instead of on any admin page whose hook merely
	 * contains the substring "plandose" (which could match an unrelated
	 * plugin's page and leak our styles/scripts onto it).
	 */
	public static function admin_screen_ids() {
		return array(
			'toplevel_page_plandose',
			'plandose_page_plandose-subscriptions',
			'plandose_page_plandose-free',
			'plandose_page_plandose-pro',
			'plandose_page_plandose-prints',
			'plandose_page_plandose-audit',
			'plandose_page_plandose-settings',
			'plandose_page_plandose-diagnostics',
		);
	}

	public static function admin_assets( $hook ) {
		$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$screen_id = $screen ? (string) $screen->id : '';
		$hook      = (string) $hook;

		$allowed = self::admin_screen_ids();

		if ( ! in_array( $screen_id, $allowed, true ) && ! in_array( $hook, $allowed, true ) ) {
			return;
		}

		$css_path = PLANDOSE_PATH . 'assets/css/plandose-admin.css';
		$js_path  = PLANDOSE_PATH . 'assets/js/plandose-admin.js';

		if ( is_readable( $css_path ) ) {
			wp_enqueue_style(
				'plandose-admin',
				PLANDOSE_URL . 'assets/css/plandose-admin.css',
				array(),
				// + file time, so a re-uploaded build is never served from cache.
				PLANDOSE_VERSION . '.' . (int) filemtime( $css_path )
			);
		}

		if ( is_readable( $js_path ) ) {
			wp_enqueue_script(
				'plandose-admin',
				PLANDOSE_URL . 'assets/js/plandose-admin.js',
				array(),
				PLANDOSE_VERSION . '.' . (int) filemtime( $js_path ),
				true
			);

			wp_localize_script(
				'plandose-admin',
				'PlandoseAdminConfig',
				array(
					'i18n' => array(
						'confirmMakeFree'    => __( 'Σίγουρα θέλετε να μετατρέψετε αυτή τη συνδρομή σε Free;', 'plandose' ),
						'confirmDenyAccess'  => __( 'Σίγουρα θέλετε να αποκλείσετε την πρόσβαση αυτού του χρήστη στο PlanDose;', 'plandose' ),
						'confirmResetAccess' => __( 'Σίγουρα θέλετε να καταργήσετε τη χειροκίνητη ρύθμιση πρόσβασης και να επιστρέψετε στον αυτόματο έλεγχο;', 'plandose' ),
						'lazyLoading'        => __( 'Φόρτωση…', 'plandose' ),
						'lazyError'          => __( 'Οι λεπτομέρειες δεν φορτώθηκαν. Δοκιμάστε ξανά ή ανοίξτε τον σύνδεσμο.', 'plandose' ),
					),
				)
			);
		}
	}

	/**
	 * Shared "wrap" header used by every PlanDose admin screen.
	 *
	 * The <hr class="wp-header-end"> is where core's common.js moves the
	 * admin notices. Without it they are moved right after the first
	 * `.wrap h1`, which here sits inside the flex header next to the logo.
	 *
	 * @param string        $title   Page title.
	 * @param string        $desc    Optional one-line description.
	 * @param callable|null $actions Optional callback that prints the page's
	 *                               header actions (buttons/forms), shown at
	 *                               the right of the title. It must escape
	 *                               its own output.
	 */
	public static function page_header( $title, $desc = '', $actions = null ) {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Δεν έχετε δικαίωμα πρόσβασης.', 'plandose' ) );
		}
		?>
		<div class="wrap plandose-admin-wrap">
			<div class="plandose-admin-head">
				<div class="plandose-logo">PN</div>
				<div class="plandose-admin-title"><h1><?php echo esc_html( $title ); ?></h1><?php if ( $desc ) : ?><p><?php echo esc_html( $desc ); ?></p><?php endif; ?></div>
				<?php if ( is_callable( $actions ) ) : ?>
					<div class="plandose-admin-head-actions"><?php call_user_func( $actions ); ?></div>
				<?php endif; ?>
			</div>
			<hr class="wp-header-end">
		<?php
	}

	public static function page_footer() {
		echo '</div>';
	}
}