<?php
/**
 * Plugin Name: PlanDose
 * Plugin URI: https://pharmacyneeds.gr
 * Description: Δημιουργεί εκτυπώσιμα πλάνα δοσολογίας για ασθενείς μέσα από ένα popup εργαλείο στην αρχική οθόνη.
 * Version: 1.30.2
 * Author: PharmacyNeeds
 * Author URI: https://pharmacyneeds.gr
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: plandose
 * Domain Path: /languages
 * Requires at least: 6.3
 * Requires PHP: 8.0
 * Update URI: https://pharmacyneeds.gr/plandose
 *
 * SINGLE SITE ONLY. Activation is refused on any multisite install (see
 * plandose_activate()): uninstall and deactivation only clean up the current
 * site, and the pharmacy lists read the network-wide usermeta table.
 *
 * Update URI: the plugin is distributed directly, not through wordpress.org.
 * The header stops WordPress from offering a same-slug wordpress.org plugin
 * as an "update" to it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PlanDose constants.
 */
if ( ! defined( 'PLANDOSE_VERSION' ) ) {
	define( 'PLANDOSE_VERSION', '1.30.2' );
}

if ( ! defined( 'PLANDOSE_FILE' ) ) {
	define( 'PLANDOSE_FILE', __FILE__ );
}

if ( ! defined( 'PLANDOSE_PATH' ) ) {
	define( 'PLANDOSE_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'PLANDOSE_URL' ) ) {
	define( 'PLANDOSE_URL', plugin_dir_url( __FILE__ ) );
}

/**
 * plugin_basename() form of this file, e.g. "plandose/plandose.php".
 * Used by plandose_load_textdomain() below to locate the /languages folder.
 */
if ( ! defined( 'PLANDOSE_BASENAME' ) ) {
	define( 'PLANDOSE_BASENAME', plugin_basename( __FILE__ ) );
}

/*
 * NOT DEFINED HERE: PLANDOSE_FREE_MONTHLY_LIMIT and PLANDOSE_MAX_PLAN_DAYS.
 *
 * Both are optional overrides a site may define in wp-config.php, read by
 * Plandose_Settings::default_free_monthly_limit() and
 * ::default_max_plan_days(). The defaults live in Plandose_Settings
 * (DEFAULT_FREE_MONTHLY_LIMIT, DEFAULT_MAX_PLAN_DAYS); the effective figure
 * comes from the settings row. If the constant exists at all, someone put it
 * in wp-config.php deliberately.
 */

/**
 * Daily maintenance cron hook. Its jobs are listed in plandose_daily_jobs()
 * below; among them:
 *
 * - Plandose_Subscriptions::cleanup_stale_print_locks() deletes tiny
 *   per-user-per-month wp_options rows left by pre-1.7.2 installs.
 * - Plandose_Admin::cleanup_audit_log() prunes audit rows past retention.
 * - Plandose_Subscriptions::archive_idle_months() closes out the print
 *   counters of pharmacies that have stopped using the tool.
 *
 * Defined here with the other constants so it is available before any
 * required class file is loaded, in case one references it at load time.
 */
if ( ! defined( 'PLANDOSE_CLEANUP_CRON_HOOK' ) ) {
	define( 'PLANDOSE_CLEANUP_CRON_HOOK', 'plandose_daily_cleanup' );
}

/**
 * Files required by the plugin, in dependency-safe load order.
 */
function plandose_required_files() {
	return array(
		'includes/class-plandose-settings.php',
		'includes/class-plandose-lock.php',
		'includes/class-plandose-access.php',
		'includes/class-plandose-account-type.php',
		'includes/class-plandose-subscriptions.php',
		// Print-charge ledger (one transaction with the counter).
		'includes/class-plandose-print-charges.php',
		'includes/class-plandose-print-log.php',
		'includes/class-plandose-subscriber-query.php',
		'includes/class-plandose-ajax.php',
		// The register_print() flow (request, replay, lock, transaction, charge, answer).
		'includes/class-plandose-print-request.php',
		'includes/class-plandose-print-result.php',
		'includes/class-plandose-print-lock.php',
		'includes/class-plandose-print-transaction.php',
		'includes/class-plandose-print-replay.php',
		'includes/class-plandose-print-new-charge.php',
		'includes/class-plandose-admin.php',
		'includes/class-plandose-admin-settings.php',
		'includes/class-plandose-invoice-storage.php',
		'includes/class-plandose-admin-invoices.php',
		'includes/class-plandose-invoice-migration.php',
		'includes/class-plandose-admin-subscriptions.php',
		'includes/class-plandose-admin-prints.php',
		'includes/class-plandose-frontend.php',
		'includes/class-plandose-privacy.php',
		// PlanDose → Διαγνωστικά, shared with `wp plandose check`.
		'includes/class-plandose-diagnostics.php',
		// `wp plandose check`. The file returns early unless WP_CLI is defined.
		'includes/class-plandose-cli.php',
	);
}

/**
 * Load all plugin classes without causing an uncontrolled PHP fatal when
 * an update/upload left the plugin directory incomplete.
 *
 * @return bool True when every required file was loaded.
 */
function plandose_load_required_files() {
	foreach ( plandose_required_files() as $relative_file ) {
		$path = PLANDOSE_PATH . $relative_file;

		if ( ! is_readable( $path ) ) {
			// Store only the raw relative path here. This runs on every load,
			// very early (before 'init'), so calling __() now would translate
			// too soon and trigger a _load_textdomain_just_in_time notice on
			// WordPress 6.7+. The translated message is built later, at a safe
			// point, in plandose_render_bootstrap_notice() / plandose_activate().
			$GLOBALS['plandose_missing_file'] = $relative_file;

			return false;
		}

		require_once $path;
	}

	return true;
}

plandose_load_required_files();

/**
 * Load the plugin's translations from /languages.
 *
 * WordPress auto-loads translations for plugins hosted on wordpress.org, but
 * PlanDose is distributed directly — without this call nothing loads its text
 * domain, so every __() call would return the untranslated source string
 * and a bundled .mo would be ignored. This is what makes the
 * Text Domain / Domain Path headers above mean anything.
 *
 * Hooked to 'init', not 'plugins_loaded': calling this earlier triggers the
 * _load_textdomain_just_in_time notice on WordPress 6.7+ (the same constraint
 * that shapes plandose_bootstrap_error_message() and the deferred settings
 * seeding below).
 *
 * Priority -10 rather than the default 10, because
 * Plandose_Subscriptions::maybe_seed_default_settings() defers
 * seed_default_settings() to 'init' at priority 0, and that call writes
 * Plandose_Settings::default_settings() — which translates guest_message,
 * button_text and disclaimer — into the option row. Loading the text domain
 * after it would bake untranslated copy into the database permanently on a
 * fresh install, since add_option() never runs a second time.
 */
function plandose_load_textdomain() {
	load_plugin_textdomain( 'plandose', false, dirname( PLANDOSE_BASENAME ) . '/languages' );
}
add_action( 'init', 'plandose_load_textdomain', -10 );

/**
 * Ensure the cleanup event exists. Activation hooks are not guaranteed to
 * run when a plugin update is deployed by replacing files, so initialization
 * also repairs a missing schedule.
 *
 * @return bool True when the event exists or was scheduled successfully.
 */
function plandose_schedule_cleanup() {
	if ( wp_next_scheduled( PLANDOSE_CLEANUP_CRON_HOOK ) ) {
		return true;
	}

	// The $wp_error parameter is available since WordPress 5.7.
	$scheduled = wp_schedule_event(
		time() + ( 5 * MINUTE_IN_SECONDS ),
		'daily',
		PLANDOSE_CLEANUP_CRON_HOOK,
		array(),
		true
	);

	if ( true === $scheduled ) {
		return true;
	}

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		$error_message = is_wp_error( $scheduled )
			? $scheduled->get_error_message()
			: 'Unknown scheduling failure.';
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- failure logged for the site admin (debug.log), not shown to users.
		error_log( 'PlanDose: cleanup cron could not be scheduled — ' . $error_message );
	}

	return false;
}

/**
 * Turn whatever bootstrap failure was recorded into a human message.
 *
 * Both failure flags store an untranslated marker, never a finished string:
 * $GLOBALS['plandose_missing_file'] holds a raw relative path, and
 * $GLOBALS['plandose_bootstrap_error'] holds a short code. Both are set on
 * 'plugins_loaded', which is before WordPress loads any text domain, so
 * calling __() at the moment they are set would translate too early and
 * trigger a _load_textdomain_just_in_time notice on WordPress 6.7+.
 * Translation is therefore deferred to here, which only ever runs from
 * 'admin_notices' or from the activation hook — both safely after 'init'.
 *
 * @return string Translated message, or '' when nothing failed.
 */
function plandose_bootstrap_error_message() {
	if ( ! empty( $GLOBALS['plandose_missing_file'] ) ) {
		return sprintf(
			/* translators: %s: missing plugin file */
			__( 'Το PlanDose δεν μπορεί να ξεκινήσει επειδή λείπει ή δεν είναι αναγνώσιμο το αρχείο: %s', 'plandose' ),
			(string) $GLOBALS['plandose_missing_file']
		);
	}

	if ( empty( $GLOBALS['plandose_bootstrap_error'] ) ) {
		return '';
	}

	$code = (string) $GLOBALS['plandose_bootstrap_error'];

	$messages = array(
		'db_upgrade_failed' => __( 'Το PlanDose δεν μπόρεσε να ολοκληρώσει την αναβάθμιση της βάσης δεδομένων. Ελέγξτε τα δικαιώματα του χρήστη της βάσης και το error log.', 'plandose' ),
	);

	// An unrecognized value is passed through as-is, so a message set by an
	// older release (which stored finished text here) still renders.
	return isset( $messages[ $code ] ) ? $messages[ $code ] : $code;
}

/**
 * Show a controlled admin notice when bootstrap or schema initialization
 * failed, rather than allowing later class calls to produce unrelated errors.
 */
function plandose_render_bootstrap_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	$message = plandose_bootstrap_error_message();

	if ( '' === $message ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html( $message )
	);
}
add_action( 'admin_notices', 'plandose_render_bootstrap_notice' );

/**
 * A site that activated PlanDose on multisite before activation was refused
 * there keeps running, but its administrators are told it is not supported.
 */
function plandose_render_multisite_notice() {
	if ( ! is_multisite() || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-warning"><p>%s</p></div>',
		esc_html__( 'Το PlanDose δεν υποστηρίζεται σε multisite: οι λίστες φαρμακείων μετράνε χρήστες όλου του δικτύου και η απεγκατάσταση καθαρίζει μόνο αυτό το site.', 'plandose' )
	);
}
add_action( 'admin_notices', 'plandose_render_multisite_notice' );

/**
 * Plugin activation.
 */
function plandose_activate( $network_wide = false ) {
	// Refused on ANY multisite, not only network-wide. Activated on one site
	// of a network, the pharmacy lists still read the network-wide usermeta
	// table and uninstall cleans only the site it runs on.
	if ( is_multisite() ) {
		wp_die(
			esc_html__( 'Το PlanDose δεν υποστηρίζεται σε εγκατάσταση multisite. Χρησιμοποιήστε το σε απλή (single site) εγκατάσταση WordPress.', 'plandose' ),
			'',
			array( 'back_link' => true )
		);
	}

	$bootstrap_error = plandose_bootstrap_error_message();

	if ( '' !== $bootstrap_error ) {
		wp_die( esc_html( $bootstrap_error ) );
	}

	// Through the same lock as every other request (plandose_maybe_upgrade()),
	// so it never runs dbDelta() alongside a request that is already
	// upgrading. Forced: activation re-verifies the tables even when the
	// stored version is current, and retries during a failure back-off.
	// 'busy' means that other request is creating the tables right now;
	// it records the version (or the failure and its notice) itself.
	$upgrade = plandose_maybe_upgrade( true );

	if ( 'done' !== $upgrade && 'busy' !== $upgrade ) {
		wp_die(
			esc_html__( 'Το PlanDose δεν μπόρεσε να δημιουργήσει ή να επιβεβαιώσει τους απαιτούμενους πίνακες βάσης δεδομένων.', 'plandose' )
		);
	}

	if ( method_exists( 'Plandose_Settings', 'maybe_migrate_legacy_defaults' ) ) {
		Plandose_Settings::maybe_migrate_legacy_defaults();
	}

	if ( method_exists( 'Plandose_Settings', 'maybe_migrate_free_limit' ) ) {
		Plandose_Settings::maybe_migrate_free_limit();
	}

	plandose_schedule_cleanup();
}
register_activation_hook( __FILE__, 'plandose_activate' );

/**
 * Plugin deactivation.
 */
function plandose_deactivate() {
	wp_clear_scheduled_hook( PLANDOSE_CLEANUP_CRON_HOOK );
	// The background legacy-invoice migration (Plandose_Invoice_Migration::CRON_HOOK).
	wp_clear_scheduled_hook( 'plandose_legacy_invoice_migration' );
}
register_deactivation_hook( __FILE__, 'plandose_deactivate' );

/** Option row used as the upgrade mutex (see plandose_maybe_upgrade()). */
if ( ! defined( 'PLANDOSE_UPGRADE_LOCK' ) ) {
	define( 'PLANDOSE_UPGRADE_LOCK', 'plandose_upgrade_lock' );
}

/**
 * Seconds after which another request may take over the upgrade lock (its
 * holder is presumed dead). Longer than the slowest plausible dbDelta():
 * an ALTER TABLE on a large table keeps running in MySQL after the PHP
 * request that started it has hit its time limit, and a takeover while it
 * runs would start a second, concurrent ALTER of the same table. Refreshing
 * the lock between tables would not help there, because one ALTER alone
 * can outlast a short TTL. Overridable in wp-config.php.
 */
if ( ! defined( 'PLANDOSE_UPGRADE_LOCK_TTL' ) ) {
	define( 'PLANDOSE_UPGRADE_LOCK_TTL', 30 * MINUTE_IN_SECONDS );
}

/** Transient set after a failed upgrade; no retry while it exists. */
if ( ! defined( 'PLANDOSE_UPGRADE_BACKOFF' ) ) {
	define( 'PLANDOSE_UPGRADE_BACKOFF', 'plandose_upgrade_failed' );
}

/**
 * Run the schema upgrade once per version change, under a lock.
 *
 * Without the lock every concurrent request after a file deploy would run
 * dbDelta() at the same time, and a failed upgrade would be retried on every
 * request — anonymous front-end hits included. So:
 * - one request holds PLANDOSE_UPGRADE_LOCK (Plandose_Lock, INSERT IGNORE)
 *   while it upgrades; the others carry on with the existing tables
 *   (schema changes are additive) and do not run dbDelta();
 * - a lock older than PLANDOSE_UPGRADE_LOCK_TTL (30 minutes) is taken
 *   over (its holder died);
 * - after a failure no request retries for 10 minutes, and the admin
 *   notice (plandose_render_bootstrap_notice()) is shown meanwhile.
 *
 * @param bool $force Run even when the stored version is current or a
 *                   back-off is active (activation). The lock still applies.
 * @return string 'current' (nothing to do), 'done', 'busy' (another
 *                request is upgrading) or 'failed'.
 */
function plandose_maybe_upgrade( $force = false ) {
	if ( ! $force && get_option( 'plandose_version' ) === PLANDOSE_VERSION ) {
		return 'current';
	}

	if ( ! $force && false !== get_transient( PLANDOSE_UPGRADE_BACKOFF ) ) {
		return 'failed';
	}

	if ( ! class_exists( 'Plandose_Lock' ) || ! class_exists( 'Plandose_Subscriptions' ) || ! method_exists( 'Plandose_Subscriptions', 'create_table' ) ) {
		return 'failed';
	}

	$now   = time();
	$owned = Plandose_Lock::claim( PLANDOSE_UPGRADE_LOCK, $now );

	if ( ! $owned ) {
		$held_since = get_option( PLANDOSE_UPGRADE_LOCK );

		if ( false !== $held_since && (int) $held_since < $now - (int) PLANDOSE_UPGRADE_LOCK_TTL ) {
			$owned = Plandose_Lock::claim_if_unchanged( PLANDOSE_UPGRADE_LOCK, $held_since, $now );
		}
	}

	if ( ! $owned ) {
		return 'busy';
	}

	// Another request may have finished the upgrade between our first read
	// and the claim.
	wp_cache_delete( 'plandose_version', 'options' );
	wp_cache_delete( 'alloptions', 'options' );

	if ( ! $force && get_option( 'plandose_version' ) === PLANDOSE_VERSION ) {
		Plandose_Lock::release_if_owned( PLANDOSE_UPGRADE_LOCK, $now );

		return 'current';
	}

	$ok = (bool) Plandose_Subscriptions::create_table();

	if ( $ok ) {
		update_option( 'plandose_version', PLANDOSE_VERSION );
		delete_transient( PLANDOSE_UPGRADE_BACKOFF );
	} else {
		set_transient( PLANDOSE_UPGRADE_BACKOFF, $now, 10 * MINUTE_IN_SECONDS );
	}

	Plandose_Lock::release_if_owned( PLANDOSE_UPGRADE_LOCK, $now );

	return $ok ? 'done' : 'failed';
}

/**
 * The jobs on the daily cleanup cron (PLANDOSE_CLEANUP_CRON_HOOK): callback,
 * priority, and the plugin tables each one reads or writes. The tables are
 * what plandose_gate_daily_jobs() checks while the schema is not upgraded.
 *
 * Registered outside is_admin() (see plandose_register_daily_jobs()) so
 * WP-Cron, which runs in a front-end context, actually fires them.
 *
 * @return array<int,array{callback:callable,priority:int,tables:string[]}>
 */
function plandose_daily_jobs() {
	$jobs = array();

	if ( class_exists( 'Plandose_Subscriptions' ) && method_exists( 'Plandose_Subscriptions', 'cleanup_stale_print_locks' ) ) {
		$jobs[] = array(
			'callback' => array( 'Plandose_Subscriptions', 'cleanup_stale_print_locks' ),
			'priority' => 10,
			'tables'   => array(),
		);
	}

	// Prune audit-log rows past the configured retention window.
	if ( class_exists( 'Plandose_Admin' ) && method_exists( 'Plandose_Admin', 'cleanup_audit_log' ) && class_exists( 'Plandose_Subscriptions' ) ) {
		$jobs[] = array(
			'callback' => array( 'Plandose_Admin', 'cleanup_audit_log' ),
			'priority' => 10,
			'tables'   => array( Plandose_Subscriptions::audit_table_name() ),
		);
	}

	// Drop print-charge rows past the free-reprint window.
	if ( class_exists( 'Plandose_Print_Charges' ) ) {
		$jobs[] = array(
			'callback' => array( 'Plandose_Print_Charges', 'cleanup_expired' ),
			'priority' => 10,
			'tables'   => array( Plandose_Print_Charges::table_name(), Plandose_Print_Charges::requests_table_name() ),
		);
	}

	// Print-log rows past their retention (Plandose_Print_Log).
	if ( class_exists( 'Plandose_Print_Log' ) ) {
		$jobs[] = array(
			'callback' => array( 'Plandose_Print_Log', 'cleanup_expired' ),
			'priority' => 10,
			'tables'   => array( Plandose_Print_Log::table_name() ),
		);
	}

	// Archive the print counters of pharmacies that have gone quiet.
	//
	// maybe_reset_month() only runs when someone actually reaches the print
	// flow, so a pharmacy that stops using the tool leaves its last active
	// month frozen in print_count and never filed into the history table.
	// Without this sweep the history would only ever contain months from
	// pharmacies that kept printing — survivorship bias built straight into
	// the numbers, and the pharmacies worth noticing are exactly the ones
	// it would omit.
	if ( class_exists( 'Plandose_Subscriptions' ) && method_exists( 'Plandose_Subscriptions', 'archive_idle_months' ) ) {
		$jobs[] = array(
			'callback' => array( 'Plandose_Subscriptions', 'archive_idle_months' ),
			'priority' => 10,
			'tables'   => array( Plandose_Subscriptions::table_name(), Plandose_Subscriptions::history_table_name() ),
		);
	}

	// Months whose archive failed are retried daily (and shown to
	// admins until they are in the history — see Plandose_Diagnostics).
	if ( class_exists( 'Plandose_Subscriptions' ) && method_exists( 'Plandose_Subscriptions', 'retry_unarchived' ) ) {
		$jobs[] = array(
			'callback' => array( 'Plandose_Subscriptions', 'retry_unarchived' ),
			'priority' => 5,
			'tables'   => array( Plandose_Subscriptions::history_table_name() ),
		);
	}

	return $jobs;
}

/**
 * Hook the daily jobs, the schema gate in front of them, and make sure the
 * event is scheduled. Safe to call more than once (add_action() dedupes).
 */
function plandose_register_daily_jobs() {
	$jobs = plandose_daily_jobs();

	foreach ( $jobs as $job ) {
		add_action( PLANDOSE_CLEANUP_CRON_HOOK, $job['callback'], $job['priority'] );
	}

	// Before every job (the earliest runs at priority 5).
	add_action( PLANDOSE_CLEANUP_CRON_HOOK, 'plandose_gate_daily_jobs', -100 );

	if ( $jobs ) {
		plandose_schedule_cleanup();
	}
}

/**
 * First callback of the daily cron run. While the stored schema version is
 * not the code's (an upgrade failing or in progress), every job whose
 * tables are missing or lack a column is unhooked for the rest of this
 * request, so it neither fatals nor half-works on an old schema; jobs whose
 * tables are complete still run. What was skipped is logged and shown in
 * Διαγνωστικά (Plandose_Diagnostics::gate_daily_jobs()).
 */
function plandose_gate_daily_jobs() {
	if ( ! class_exists( 'Plandose_Diagnostics' ) || ! method_exists( 'Plandose_Diagnostics', 'gate_daily_jobs' ) ) {
		return;
	}

	foreach ( Plandose_Diagnostics::gate_daily_jobs( plandose_daily_jobs() ) as $job ) {
		remove_action( PLANDOSE_CLEANUP_CRON_HOOK, $job['callback'], $job['priority'] );
	}
}

/**
 * Initialize plugin.
 */
function plandose_init() {
	if (
		! empty( $GLOBALS['plandose_missing_file'] ) ||
		! empty( $GLOBALS['plandose_bootstrap_error'] )
	) {
		return;
	}

	// The data-protection hooks come BEFORE the schema upgrade, so they
	// stay registered while an upgrade is failing (backoff): otherwise
	// deleting a pharmacy in that window would leave its subscription row
	// and invoice files (with its tax number) behind, and a GDPR
	// export/erase would silently skip PlanDose's data. They only touch
	// tables present in every schema version; a real DB outage makes them
	// fail loudly.
	//
	// Registered on every request, admin and front end alike: WordPress
	// builds the exporter/eraser lists during the privacy-request flow,
	// and wp_add_privacy_policy_content() runs on admin_init.
	if ( class_exists( 'Plandose_Privacy' ) ) {
		Plandose_Privacy::init();
	}

	// Registered unconditionally (not just when is_admin()) since user
	// deletion can also happen via WP-CLI or other non-admin contexts.
	if ( class_exists( 'Plandose_Admin' ) && method_exists( 'Plandose_Admin', 'handle_user_deleted' ) ) {
		add_action( 'delete_user', array( 'Plandose_Admin', 'snapshot_before_delete' ) );
		add_action( 'deleted_user', array( 'Plandose_Admin', 'handle_user_deleted' ) );
	}

	// The daily cron jobs and Diagnostics also come before the schema
	// upgrade. Were they registered after it, a daily event firing while
	// an upgrade is failing would run with no callbacks and be moved to
	// the next day (a day of pruning/archiving lost, no heartbeat), and
	// Διαγνωστικά would vanish exactly when it is needed. Each job whose
	// tables are not upgraded yet is skipped for that run (see
	// plandose_gate_daily_jobs()); the tool itself stays off below.
	plandose_register_daily_jobs();

	// Every request — it hooks the daily cron heartbeat and the invoice
	// migration's cron event, which run outside wp-admin.
	if ( class_exists( 'Plandose_Diagnostics' ) ) {
		Plandose_Diagnostics::init();
	}

	// Re-run table creation whenever the plugin's version has bumped since
	// the last load — not just on the activation hook. Sites that deploy
	// updates by overwriting files (FTP/git pull/etc.) without an explicit
	// deactivate+reactivate cycle would otherwise never pick up schema
	// changes introduced in a later version.
	if ( 'failed' === plandose_maybe_upgrade() ) {
		// A code, not a translated string: this runs on 'plugins_loaded',
		// before any text domain is loaded. plandose_bootstrap_error_message()
		// translates it later, at a point where that is safe.
		$GLOBALS['plandose_bootstrap_error'] = 'db_upgrade_failed';

		// The PlanDose menu (Plandose_Admin) is not registered in this
		// state; Διαγνωστικά gets a menu entry of its own.
		if ( is_admin() && class_exists( 'Plandose_Diagnostics' ) && method_exists( 'Plandose_Diagnostics', 'init_standalone_menu' ) ) {
			Plandose_Diagnostics::init_standalone_menu();
		}

		return;
	}

	if ( class_exists( 'Plandose_Settings' ) && method_exists( 'Plandose_Settings', 'maybe_migrate_legacy_defaults' ) ) {
		Plandose_Settings::maybe_migrate_legacy_defaults();
	}

	if ( class_exists( 'Plandose_Settings' ) && method_exists( 'Plandose_Settings', 'maybe_migrate_free_limit' ) ) {
		Plandose_Settings::maybe_migrate_free_limit();
	}

	// Registered unconditionally (not just when is_admin()) since another
	// plugin, a REST request, or WP-CLI can change a user's account_type
	// or contact meta outside of wp-admin too. The hook also removes any
	// legacy full-dataset transient left behind by earlier releases.
	if ( class_exists( 'Plandose_Subscriber_Query' ) && method_exists( 'Plandose_Subscriber_Query', 'init_cache_hooks' ) ) {
		Plandose_Subscriber_Query::init_cache_hooks();
	}

	if ( class_exists( 'Plandose_Ajax' ) ) {
		Plandose_Ajax::init();
	}

	if ( is_admin() && class_exists( 'Plandose_Admin' ) ) {
		Plandose_Admin::init();
	}

	// A refused «selected pages» save (Plandose_Settings::sanitize_settings()).
	if ( is_admin() && class_exists( 'Plandose_Settings' ) && method_exists( 'Plandose_Settings', 'render_scope_refused_notice' ) ) {
		add_action( 'admin_notices', array( 'Plandose_Settings', 'render_scope_refused_notice' ) );
	}

	if ( class_exists( 'Plandose_Frontend' ) ) {
		Plandose_Frontend::init();
	}

	// Warn admins when the charge tables cannot use transactions.
	if ( is_admin() && class_exists( 'Plandose_Print_Charges' ) ) {
		add_action( 'admin_notices', array( 'Plandose_Print_Charges', 'render_engine_notice' ) );
	}
}
add_action( 'plugins_loaded', 'plandose_init', 10 );