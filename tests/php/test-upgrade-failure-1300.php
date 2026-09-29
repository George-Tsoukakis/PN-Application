<?php
/**
 * PlanDose — a failing database upgrade keeps the daily cron jobs and
 * Διαγνωστικά alive (jobs whose tables are not upgraded are skipped and
 * reported), while the print tool stays off; the upgrade lock is only
 * taken over after PLANDOSE_UPGRADE_LOCK_TTL. TEST-ONLY.
 *
 * The failed state is checked in a child PHP process (this file with
 * --child), because plandose_init() has already run normally in this one.
 */

$pdt_is_child = isset( $argv[1] ) && '--child' === $argv[1];

if ( $pdt_is_child ) {
	// A wp-admin request, so is_admin() and the admin-only hooks apply.
	define( 'WP_ADMIN', true );
}

require __DIR__ . '/lib.php';

if ( $pdt_is_child ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	$out = array(
		'bootstrap_error' => isset( $GLOBALS['plandose_bootstrap_error'] ) ? $GLOBALS['plandose_bootstrap_error'] : null,
		'jobs'            => array(),
	);

	foreach ( plandose_daily_jobs() as $job ) {
		$out['jobs'][ $job['callback'][0] . '::' . $job['callback'][1] ] = false !== has_action( PLANDOSE_CLEANUP_CRON_HOOK, $job['callback'] );
	}

	$out['job_count']     = count( $out['jobs'] );
	$out['gate']          = false !== has_action( PLANDOSE_CLEANUP_CRON_HOOK, 'plandose_gate_daily_jobs' );
	$out['heartbeat']     = false !== has_action( PLANDOSE_CLEANUP_CRON_HOOK, array( 'Plandose_Diagnostics', 'record_cron_run' ) );
	$out['migration']     = false !== has_action( Plandose_Invoice_Migration::CRON_HOOK, array( 'Plandose_Invoice_Migration', 'run_cron_batch' ) );
	$out['scheduled']     = (bool) wp_next_scheduled( PLANDOSE_CLEANUP_CRON_HOOK );
	$out['reprobe']       = false !== has_action( 'admin_post_plandose_diagnostics_reprobe', array( 'Plandose_Diagnostics', 'handle_reprobe' ) );
	$out['ajax_register'] = false !== has_action( 'wp_ajax_plandose_register_print', array( 'Plandose_Ajax', 'register_print' ) );
	$out['ajax_check']    = false !== has_action( 'wp_ajax_plandose_check_print', array( 'Plandose_Ajax', 'check_print' ) );
	$out['frontend']      = false !== has_action( 'wp_enqueue_scripts', array( 'Plandose_Frontend', 'assets' ) );

	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	wp_set_current_user( $admins ? (int) $admins[0] : 0 );
	do_action( 'admin_menu' );

	global $admin_page_hooks;
	$out['menu_diagnostics'] = isset( $admin_page_hooks[ Plandose_Diagnostics::PAGE ] );
	$out['menu_main']        = isset( $admin_page_hooks['plandose'] );

	ob_start();
	Plandose_Diagnostics::render_page();
	$html = ob_get_clean();

	$out['page_rendered'] = false !== strpos( $html, 'pd-diag-table' );

	echo "\nPDT_JSON " . wp_json_encode( $out ) . "\n";
	exit( 0 );
}

global $wpdb;

$real_version = get_option( 'plandose_version' );
pdt_defer(
	static function () use ( $real_version ) {
		update_option( 'plandose_version', $real_version );
		delete_option( PLANDOSE_UPGRADE_LOCK );
		delete_transient( PLANDOSE_UPGRADE_BACKOFF );
		delete_option( Plandose_Diagnostics::CRON_SKIPPED_OPTION );
		plandose_register_daily_jobs();
	}
);

// ---- the failed state, in a fresh request ----------------------------------------------
update_option( 'plandose_version', '0.0.1-pdt' );
set_transient( PLANDOSE_UPGRADE_BACKOFF, time(), 10 * MINUTE_IN_SECONDS );

$raw  = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --child 2>&1' );
$json = null;

if ( is_string( $raw ) && preg_match( '/^PDT_JSON (.+)$/m', $raw, $m ) ) {
	$json = json_decode( $m[1], true );
}

if ( ! is_array( $json ) ) {
	pdt_check( false, 'child request ran — output: ' . substr( (string) $raw, 0, 2000 ) );
	pdt_done();
}

pdt_same( 'db_upgrade_failed', $json['bootstrap_error'], 'child: the upgrade is in the failed state' );
pdt_check( $json['job_count'] >= 6, 'child: the daily jobs are known (' . $json['job_count'] . ')' );
foreach ( $json['jobs'] as $label => $hooked ) {
	pdt_check( $hooked, "failed upgrade: daily job $label is still hooked" );
}
pdt_check( $json['gate'], 'failed upgrade: the schema gate runs before the jobs' );
pdt_check( $json['heartbeat'], 'failed upgrade: the cron heartbeat is hooked' );
pdt_check( $json['migration'], 'failed upgrade: the invoice-migration cron event is hooked' );
pdt_check( $json['scheduled'], 'failed upgrade: the daily event stays scheduled' );
pdt_check( $json['menu_diagnostics'], 'failed upgrade: Διαγνωστικά has a menu entry' );
pdt_check( ! $json['menu_main'], '… while the rest of the PlanDose menu stays off' );
pdt_check( $json['page_rendered'], 'failed upgrade: the Διαγνωστικά page renders' );
pdt_check( $json['reprobe'], 'failed upgrade: the Διαγνωστικά actions are hooked' );
pdt_check( ! $json['ajax_register'] && ! $json['ajax_check'], 'failed upgrade: the print AJAX handlers are NOT registered' );
pdt_check( ! $json['frontend'], 'failed upgrade: the front-end tool is NOT loaded' );

delete_transient( PLANDOSE_UPGRADE_BACKOFF );

// ---- the schema gate (version still behind) ------------------------------------------
$prev_log = ini_set( 'error_log', '/dev/null' );

// Print log lacks a column the schema defines.
$need_col = static function ( $columns ) {
	$columns[ Plandose_Print_Log::table_name() ][] = 'pdt_missing_col';
	return $columns;
};
add_filter( 'plandose_diagnostics_expected_columns', $need_col );

// The audit table "does not exist".
$audit     = Plandose_Subscriptions::audit_table_name();
$no_audit  = static function ( $q ) use ( $audit ) {
	if ( is_string( $q ) && 0 === strpos( $q, 'SHOW COLUMNS FROM `' . $audit . '`' ) ) {
		return 'SHOW COLUMNS FROM `pdt_no_such_table_1300`';
	}
	return $q;
};
add_filter( 'query', $no_audit );

plandose_register_daily_jobs();
$wpdb->last_error = '';
ob_start();
plandose_gate_daily_jobs();
$printed = ob_get_clean();
remove_filter( 'query', $no_audit );
remove_filter( 'plandose_diagnostics_expected_columns', $need_col );

pdt_same( '', $printed, 'gate prints nothing (missing table is not a DB error on screen)' );
pdt_same( false, has_action( PLANDOSE_CLEANUP_CRON_HOOK, array( 'Plandose_Print_Log', 'cleanup_expired' ) ), 'table lacking a column: its job is skipped this run' );
pdt_same( false, has_action( PLANDOSE_CLEANUP_CRON_HOOK, array( 'Plandose_Admin', 'cleanup_audit_log' ) ), 'missing table: its job is skipped this run' );
pdt_check( false !== has_action( PLANDOSE_CLEANUP_CRON_HOOK, array( 'Plandose_Print_Charges', 'cleanup_expired' ) ), 'complete tables: that job still runs' );
pdt_check( false !== has_action( PLANDOSE_CLEANUP_CRON_HOOK, array( 'Plandose_Subscriptions', 'archive_idle_months' ) ), '… and so does the archive sweep' );
pdt_check( false !== has_action( PLANDOSE_CLEANUP_CRON_HOOK, array( 'Plandose_Subscriptions', 'cleanup_stale_print_locks' ) ), '… and the options-only job' );
pdt_check( false !== has_action( PLANDOSE_CLEANUP_CRON_HOOK, array( 'Plandose_Diagnostics', 'record_cron_run' ) ), 'the heartbeat is still recorded (WP-Cron does run)' );

$skipped = get_option( Plandose_Diagnostics::CRON_SKIPPED_OPTION );
pdt_check( is_array( $skipped ) && in_array( 'Plandose_Print_Log::cleanup_expired', $skipped['jobs'], true ) && in_array( 'Plandose_Admin::cleanup_audit_log', $skipped['jobs'], true ) && 2 === count( $skipped['jobs'] ), 'the skipped jobs are recorded' );
pdt_check( is_array( $skipped ) && in_array( $audit, $skipped['tables'], true ), '… with the tables that were not ready' );

$cron   = Plandose_Diagnostics::check_cron();
$warn   = array_values( array_filter( $cron, static function ( $r ) { return 'Εργασίες που παραλείφθηκαν' === $r['title']; } ) );
pdt_check( 1 === count( $warn ) && 'WARN' === $warn[0]['status'], 'Διαγνωστικά: WARN for the skipped jobs' );

// Schema current again: nothing skipped, the note goes away.
plandose_register_daily_jobs();
update_option( 'plandose_version', PLANDOSE_VERSION );
plandose_gate_daily_jobs();
pdt_check( false !== has_action( PLANDOSE_CLEANUP_CRON_HOOK, array( 'Plandose_Print_Log', 'cleanup_expired' ) ), 'schema current: every job runs' );
pdt_same( false, get_option( Plandose_Diagnostics::CRON_SKIPPED_OPTION ), 'schema current: the skipped note is cleared' );
pdt_check( ! in_array( 'Εργασίες που παραλείφθηκαν', wp_list_pluck( Plandose_Diagnostics::check_cron(), 'title' ), true ), 'Διαγνωστικά: no WARN any more' );

// Version behind but every table complete: nothing skipped.
update_option( 'plandose_version', '0.0.1-pdt' );
plandose_gate_daily_jobs();
pdt_same( false, get_option( Plandose_Diagnostics::CRON_SKIPPED_OPTION ), 'version behind, tables complete: nothing skipped' );

ini_set( 'error_log', false === $prev_log ? '' : $prev_log );

// ---- upgrade lock TTL -----------------------------------------------------------------
pdt_check( PLANDOSE_UPGRADE_LOCK_TTL >= 15 * MINUTE_IN_SECONDS, 'lock TTL is at least 15 minutes' );
delete_option( PLANDOSE_UPGRADE_LOCK );
pdt_check( Plandose_Lock::claim( PLANDOSE_UPGRADE_LOCK, time() - 10 * MINUTE_IN_SECONDS ), 'a request took the lock 10 minutes ago (slow dbDelta)' );
wp_cache_delete( PLANDOSE_UPGRADE_LOCK, 'options' );
pdt_same( 'busy', plandose_maybe_upgrade(), '10-minute-old lock: NOT taken over (no second concurrent dbDelta)' );
pdt_same( '0.0.1-pdt', get_option( 'plandose_version' ), '… and the version is untouched' );

$wpdb->update( $wpdb->options, array( 'option_value' => (string) ( time() - PLANDOSE_UPGRADE_LOCK_TTL - 60 ) ), array( 'option_name' => PLANDOSE_UPGRADE_LOCK ) );
wp_cache_delete( PLANDOSE_UPGRADE_LOCK, 'options' );
pdt_same( 'done', plandose_maybe_upgrade(), 'lock older than the TTL: taken over, upgrade runs' );
pdt_same( PLANDOSE_VERSION, get_option( 'plandose_version' ), '… version bumped' );

pdt_done();
