<?php
/**
 * 1.28.3: a month whose archive failed and whose counter was reset anyway
 * is no longer only an error-log line: it is kept, retried daily, shown to
 * admins (notice + Διαγνωστικά + wp plandose check) until archived or
 * cleared, forgotten with the account, exported by GDPR. TEST-ONLY.
 */
require __DIR__ . '/lib.php';

global $wpdb;

$table   = Plandose_Subscriptions::table_name();
$history = Plandose_Subscriptions::history_table_name();
$today   = current_time( 'Y-m-d' );
$opt     = Plandose_Subscriptions::UNARCHIVED_OPTION;

$saved = get_option( $opt, null );
pdt_defer(
	static function () use ( $opt, $saved ) {
		null === $saved ? delete_option( $opt ) : update_option( $opt, $saved, false );
	}
);
delete_option( $opt );

function pdt_fail_history() {
	$filter = static function ( $query ) {
		return false !== stripos( $query, 'INSERT INTO ' . Plandose_Subscriptions::history_table_name() ) ? 'SELECT * FROM pdt_no_such_table_1283' : $query;
	};
	add_filter( 'query', $filter );

	return static function () use ( $filter ) {
		remove_filter( 'query', $filter );
	};
}

/** Run an admin-post handler; its redirect / wp_die come back as a string. */
function pdt_run_handler( $callback ) {
	$redirect = static function ( $location ) {
		throw new RuntimeException( 'redirect:' . $location );
	};
	$die      = static function () {
		return static function ( $message, $title = '', $args = array() ) {
			throw new RuntimeException( 'die:' . ( is_array( $args ) && isset( $args['response'] ) ? $args['response'] : '' ) );
		};
	};
	add_filter( 'wp_redirect', $redirect );
	add_filter( 'wp_die_handler', $die );
	try {
		call_user_func( $callback );
		$out = 'returned';
	} catch ( RuntimeException $e ) {
		$out = $e->getMessage();
	}
	remove_filter( 'wp_redirect', $redirect );
	remove_filter( 'wp_die_handler', $die );

	return $out;
}

ini_set( 'error_log', '/dev/null' ); // phpcs:ignore -- the expected log lines.

// ---- a month lost after the hour is kept -----------------------------------------
$uid = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
$wpdb->insert( $table, array( 'user_id' => $uid, 'status' => 'free', 'print_count' => 17, 'count_reset_at' => '2020-05-03' ) );
set_transient( 'plandose_archive_fail_' . $uid, time() - Plandose_Subscriptions::ARCHIVE_RETRY_SECONDS - 5, DAY_IN_SECONDS );

$undo = pdt_fail_history();
pdt_same( true, Plandose_Subscriptions::maybe_reset_month( $uid, $today ), 'after the hour the reset goes ahead' );
$undo();

$list = Plandose_Subscriptions::unarchived_months();
pdt_same( 1, count( $list ), 'the lost month is kept' );
pdt_check( $list && $uid === $list[0]['user_id'] && '2020-05' === $list[0]['ym'] && 17 === $list[0]['prints'], 'with user, month and prints' );
pdt_same( 'no', (string) $wpdb->get_var( $wpdb->prepare( "SELECT CASE WHEN autoload IN ('no','off','auto-off') THEN 'no' ELSE 'yes' END FROM {$wpdb->options} WHERE option_name = %s", $opt ) ), 'stored non-autoloaded' );

// ---- shown: Διαγνωστικά, wp plandose check, admin notice ----------------------------
$check = Plandose_Diagnostics::check_history();
pdt_same( 'FAIL', $check['status'], 'Διαγνωστικά: FAIL while a month waits' );
pdt_check( (bool) preg_grep( '/2020-05: 17/u', $check['details'] ), 'the month and its prints are listed' );
pdt_check( false !== strpos( file_get_contents( PLANDOSE_PATH . 'includes/class-plandose-cli.php' ), 'check_history()' ), 'wp plandose check includes it (exit 1 on FAIL)' );

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$admin  = (int) $admins[0];

wp_set_current_user( $admin );
ob_start();
Plandose_Diagnostics::render_unarchived_notice();
$notice = ob_get_clean();
pdt_check( false !== strpos( $notice, 'notice-error' ) && false !== strpos( $notice, 'plandose-diagnostics' ), 'admin sees the notice with a link to Διαγνωστικά' );

wp_set_current_user( $uid );
ob_start();
Plandose_Diagnostics::render_unarchived_notice();
pdt_same( '', ob_get_clean(), 'a pharmacy account sees no notice' );
wp_set_current_user( 0 );

// ---- GDPR export ------------------------------------------------------------------
$export = Plandose_Privacy::export( get_userdata( $uid )->user_email, 1 );
$names  = array();
foreach ( $export['data'] as $group ) {
	foreach ( $group['data'] as $pair ) {
		$names[] = $pair['name'];
	}
}
pdt_check( (bool) preg_grep( '/2020-05 \(προς αρχειοθέτηση\)/u', $names ), 'the waiting month is in the personal-data export' );

// ---- retry: fails while the table is broken, archives once it works ------------------
$undo = pdt_fail_history();
$r    = Plandose_Subscriptions::retry_unarchived();
$undo();
pdt_same( array( 'archived' => 0, 'left' => 1 ), $r, 'retry with the table still broken: kept' );

$r = Plandose_Subscriptions::retry_unarchived();
pdt_same( array( 'archived' => 1, 'left' => 0 ), $r, 'retry with the table working: archived' );
pdt_same( 17, (int) $wpdb->get_var( $wpdb->prepare( "SELECT prints FROM {$history} WHERE user_id = %d AND ym = '2020-05'", $uid ) ), 'May 2020 is in the history with 17 prints' );
pdt_same( false, get_option( $opt, false ), 'the list is empty (option deleted)' );
pdt_same( 'PASS', Plandose_Diagnostics::check_history()['status'], 'Διαγνωστικά: PASS again' );
pdt_check( false !== has_action( PLANDOSE_CLEANUP_CRON_HOOK, array( 'Plandose_Subscriptions', 'retry_unarchived' ) ), 'retried by the daily cron' );

// ---- forgotten with the account ------------------------------------------------------
pdt_call( 'Plandose_Subscriptions', 'remember_unarchived', $uid, '2020-06', 3 );
$other = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
pdt_call( 'Plandose_Subscriptions', 'remember_unarchived', $other, '2020-06', 4 );
Plandose_Subscriptions::delete_history_for_user( $uid );
$left = Plandose_Subscriptions::unarchived_months();
pdt_check( 1 === count( $left ) && $other === $left[0]['user_id'], 'deleting a user\'s history (account deleted / GDPR erase) drops only their months' );

// Same month twice: one entry, the larger figure.
pdt_call( 'Plandose_Subscriptions', 'remember_unarchived', $other, '2020-06', 9 );
pdt_call( 'Plandose_Subscriptions', 'remember_unarchived', $other, '2020-06', 2 );
$left = Plandose_Subscriptions::unarchived_months();
pdt_check( 1 === count( $left ) && 9 === $left[0]['prints'], 'one entry per user and month, larger figure kept' );

// ---- the buttons: POST + nonce + manage_options ------------------------------------
$_SERVER['REQUEST_METHOD'] = 'POST';

wp_set_current_user( $other );
$_POST = $_REQUEST = array( 'action' => 'plandose_unarchived', 'pd_do' => 'dismiss', '_wpnonce' => wp_create_nonce( 'plandose_unarchived' ) );
pdt_same( 'die:403', pdt_run_handler( array( 'Plandose_Diagnostics', 'handle_unarchived' ) ), 'a non-admin is refused (403)' );
pdt_same( 1, count( Plandose_Subscriptions::unarchived_months() ), '… and nothing is cleared' );

wp_set_current_user( $admin );
$_POST = $_REQUEST = array( 'action' => 'plandose_unarchived', 'pd_do' => 'dismiss', '_wpnonce' => 'bad' );
pdt_check( 0 === strpos( pdt_run_handler( array( 'Plandose_Diagnostics', 'handle_unarchived' ) ), 'die:' ), 'a bad nonce is refused' );
pdt_same( 1, count( Plandose_Subscriptions::unarchived_months() ), '… and nothing is cleared' );

$_POST = $_REQUEST = array( 'action' => 'plandose_unarchived', 'pd_do' => 'retry', '_wpnonce' => wp_create_nonce( 'plandose_unarchived' ) );
$out = pdt_run_handler( array( 'Plandose_Diagnostics', 'handle_unarchived' ) );
pdt_check( false !== strpos( $out, 'plandose_unarchived=retry' ) && false !== strpos( $out, 'archived=1' ), '«Αρχειοθέτηση ξανά» archives and reports it' );
pdt_same( 9, (int) $wpdb->get_var( $wpdb->prepare( "SELECT prints FROM {$history} WHERE user_id = %d AND ym = '2020-06'", $other ) ), 'June 2020 archived with 9' );

pdt_call( 'Plandose_Subscriptions', 'remember_unarchived', $other, '2020-07', 5 );
$_POST = $_REQUEST = array( 'action' => 'plandose_unarchived', 'pd_do' => 'dismiss', '_wpnonce' => wp_create_nonce( 'plandose_unarchived' ) );
$out = pdt_run_handler( array( 'Plandose_Diagnostics', 'handle_unarchived' ) );
pdt_check( false !== strpos( $out, 'plandose_unarchived=dismissed' ), '«Καθαρισμός λίστας» clears it' );
pdt_same( array(), Plandose_Subscriptions::unarchived_months(), 'list empty' );
$audit = $wpdb->get_var( "SELECT meta FROM {$wpdb->prefix}plandose_audit_log WHERE event = 'unarchived_dismissed' ORDER BY id DESC LIMIT 1" );
pdt_check( is_string( $audit ) && false !== strpos( $audit, $other . ':2020-07:5' ), 'the cleared figures are in the audit log' );

$_POST = $_REQUEST = array();
wp_set_current_user( 0 );

// ---- uninstall removes the option -------------------------------------------------
pdt_check( false !== strpos( file_get_contents( PLANDOSE_PATH . 'uninstall.php' ), "'plandose_unarchived_months'" ), 'uninstall.php removes the option' );

pdt_done();
