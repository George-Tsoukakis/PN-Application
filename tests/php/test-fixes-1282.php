<?php
/**
 * 1.28.2: server-side fixes from the 1.28.1 review, against the real test
 * WordPress + MariaDB. TEST-ONLY.
 *
 * - the monthly reset never zeroes a month it could not archive;
 * - «Μετατροπή σε Free» only from the state the form showed (CAS);
 * - undo_charge() never erases a charge another request wrote;
 * - a print lock is released only by the request that holds it;
 * - account_type cannot be deleted for every user by an untrusted caller;
 * - uninstall keeps everything when the invoice list cannot be read;
 * - the dashboard KPIs are not cached after a failed query;
 * - privacy and user-deletion hooks are registered before the upgrade.
 */
require __DIR__ . '/lib.php';

global $wpdb;

$table   = Plandose_Subscriptions::table_name();
$history = Plandose_Subscriptions::history_table_name();
$today   = current_time( 'Y-m-d' );

/** Make the next SQL statement matching $needle fail (a real DB error). */
function pdt_fail_query( $needle ) {
	$filter = static function ( $query ) use ( $needle ) {
		return false !== stripos( $query, $needle ) ? 'SELECT * FROM pdt_no_such_table_1282' : $query;
	};
	add_filter( 'query', $filter );

	return static function () use ( $filter ) {
		remove_filter( 'query', $filter );
	};
}

// ---- monthly reset: archive first, or nothing ---------------------------------
$uid = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
$wpdb->insert( $table, array( 'user_id' => $uid, 'status' => 'free', 'print_count' => 9, 'count_reset_at' => '2020-03-05' ) );

$undo  = pdt_fail_query( 'INSERT INTO ' . $history );
$reset = Plandose_Subscriptions::maybe_reset_month( $uid, $today );
$undo();

pdt_same( false, $reset, 'archive failed → maybe_reset_month() reports no reset' );
pdt_same( 9, (int) $wpdb->get_var( $wpdb->prepare( "SELECT print_count FROM {$table} WHERE user_id = %d", $uid ) ), 'archive failed → the closed month (9 prints) is NOT zeroed' );
pdt_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$history} WHERE user_id = %d", $uid ) ), 'nothing archived yet' );

pdt_check( (int) get_transient( 'plandose_archive_fail_' . $uid ) > 0, 'the first failure is remembered' );

pdt_same( true, Plandose_Subscriptions::maybe_reset_month( $uid, $today ), 'next call (DB fine): archived and reset' );
pdt_same( false, get_transient( 'plandose_archive_fail_' . $uid ), 'the failure mark is cleared' );
pdt_same( 9, (int) $wpdb->get_var( $wpdb->prepare( "SELECT prints FROM {$history} WHERE user_id = %d AND ym = '2020-03'", $uid ) ), 'March 2020 archived with 9 prints' );
pdt_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT print_count FROM {$table} WHERE user_id = %d", $uid ) ), 'counter zeroed only after that' );

// A history table that stays broken holds the reset back for an hour at
// most — then the month is logged and printing goes on.
$uid_b = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
$wpdb->insert( $table, array( 'user_id' => $uid_b, 'status' => 'free', 'print_count' => 4, 'count_reset_at' => '2020-04-02' ) );
set_transient( 'plandose_archive_fail_' . $uid_b, time() - Plandose_Subscriptions::ARCHIVE_RETRY_SECONDS - 5, DAY_IN_SECONDS );
$undo = pdt_fail_query( 'INSERT INTO ' . $history );
ini_set( 'error_log', '/dev/null' ); // phpcs:ignore -- the expected log line.
$reset = Plandose_Subscriptions::maybe_reset_month( $uid_b, $today );
$undo();
pdt_same( true, $reset, 'failing for over an hour → the reset goes ahead' );
pdt_same( true, Plandose_Subscriptions::try_increment_print_count( $uid_b, 450, $today ), '… and the pharmacy can print' );
delete_transient( 'plandose_archive_fail_' . $uid_b );

// ---- make_free only from the state the form showed ------------------------------
$pro = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
$wpdb->insert( $table, array( 'user_id' => $pro, 'status' => 'pro', 'sub_end_date' => '2099-01-31' ) );
$form_token = Plandose_Subscriptions::state_token( Plandose_Subscriptions::get_row( $pro, false ) );

// A colleague extends it after the page was loaded.
$wpdb->update( $table, array( 'sub_end_date' => '2099-12-31' ), array( 'user_id' => $pro ) );

list( $outcome, $before ) = Plandose_Subscriptions::make_free_if_unchanged( $pro, $form_token );
pdt_same( 'stale', $outcome, 'stale form → refused' );
pdt_same( '2099-12-31', $wpdb->get_var( $wpdb->prepare( "SELECT sub_end_date FROM {$table} WHERE user_id = %d", $pro ) ), 'the paid end date is kept' );

$fresh = Plandose_Subscriptions::state_token( Plandose_Subscriptions::get_row( $pro, false ) );
list( $outcome, $before ) = Plandose_Subscriptions::make_free_if_unchanged( $pro, $fresh );
pdt_same( 'updated', $outcome, 'current form → Free' );
pdt_same( '2099-12-31', $before ? $before->sub_end_date : null, 'the removed end date is returned for the audit' );
$row = $wpdb->get_row( $wpdb->prepare( "SELECT status, sub_end_date FROM {$table} WHERE user_id = %d", $pro ) );
pdt_check( $row && 'free' === $row->status && null === $row->sub_end_date, 'row is Free with no end date' );

list( $outcome ) = Plandose_Subscriptions::make_free_if_unchanged( $pro, '' );
pdt_same( 'stale', $outcome, 'an empty token never matches' );

list( $outcome ) = Plandose_Subscriptions::make_free_if_unchanged( $pro, Plandose_Subscriptions::state_token( Plandose_Subscriptions::get_row( $pro, false ) ) );
pdt_same( 'unchanged', $outcome, 'already Free → «unchanged» (nothing to audit)' );

// ---- undo_charge(): compare-and-swap ------------------------------------------------
$payer = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
$ctab  = Plandose_Print_Charges::table_name();
$tok   = md5( 'pdt-undo-1282' );

pdt_same( true, Plandose_Print_Charges::write_charge( $payer, $tok ), 'charge written' );
// Another request charged the same token meanwhile (a later time).
$wpdb->query( $wpdb->prepare( "UPDATE {$ctab} SET charged_ts = charged_ts + 7 WHERE user_id = %d AND token_hash = %s", $payer, $tok ) );
Plandose_Print_Charges::undo_charge( $payer, $tok );
pdt_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ctab} WHERE user_id = %d AND token_hash = %s", $payer, $tok ) ), 'undo leaves the other request\'s charge alone' );

// A write that did not happen leaves nothing for undo to touch, even a
// row another request wrote in the same second.
$tok3 = md5( 'pdt-undo-1282-c' );
Plandose_Print_Charges::write_charge( $payer, $tok3 );
$undo = pdt_fail_query( 'INSERT INTO ' . $ctab );
pdt_same( false, Plandose_Print_Charges::write_charge( $payer, md5( 'pdt-undo-1282-d' ) ), 'a failed write' );
$undo();
$wpdb->query( $wpdb->prepare( "UPDATE {$ctab} SET charged_ts = %d WHERE user_id = %d AND token_hash = %s", time(), $payer, $tok3 ) );
Plandose_Print_Charges::undo_charge( $payer, $tok3 );
pdt_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ctab} WHERE user_id = %d AND token_hash = %s", $payer, $tok3 ) ), 'undo after a failed write deletes nothing' );

$tok2 = md5( 'pdt-undo-1282-b' );
Plandose_Print_Charges::write_charge( $payer, $tok2 );
Plandose_Print_Charges::undo_charge( $payer, $tok2 );
pdt_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ctab} WHERE user_id = %d AND token_hash = %s", $payer, $tok2 ) ), 'undo removes its own, untouched charge' );

// ---- print lock: released only by its holder --------------------------------------
if ( wp_using_ext_object_cache() ) {
	pdt_check( true, 'print lock (skipped: persistent object cache path)' );
} else {
	$lk = 'pdt-lock-1282';
	pdt_same( true, Plandose_Print_Lock::acquire( $payer, $lk, 'h1' ), 'lock acquired' );
	$key = Plandose_Print_Lock::key( $payer, $lk );
	// Another request took the (expired) lock over meanwhile.
	$wpdb->update( $wpdb->options, array( 'option_value' => ( time() + 1 ) . ':other' ), array( 'option_name' => '_transient_' . $key ) );
	wp_cache_delete( '_transient_' . $key, 'options' );
	Plandose_Print_Lock::release( $payer, $lk );
	pdt_same( ( time() + 1 ) . ':other', (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", '_transient_' . $key ) ), 'the other holder\'s lock is NOT deleted' );
	$wpdb->delete( $wpdb->options, array( 'option_name' => '_transient_' . $key ) );
	$wpdb->delete( $wpdb->options, array( 'option_name' => '_transient_timeout_' . $key ) );

	pdt_same( true, Plandose_Print_Lock::acquire( $payer, $lk . 'b', 'h2' ), 'second lock acquired' );
	$key2 = Plandose_Print_Lock::key( $payer, $lk . 'b' );
	Plandose_Print_Lock::release( $payer, $lk . 'b' );
	pdt_same( null, $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", '_transient_' . $key2 ) ), 'its own lock is released' );
}

// ---- account_type: no delete-all by an untrusted caller ---------------------------
wp_set_current_user( 0 );
$victim = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
delete_metadata( 'user', 0, 'account_type', '', true );
pdt_same( 'Φαρμακείο', get_user_meta( $victim, 'account_type', true ), 'anonymous delete_metadata(…, delete_all) is refused' );

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
pdt_check( ! empty( $admins ), 'precondition: an administrator exists' );

// ---- KPIs: a failed query is not cached ------------------------------------------
delete_transient( Plandose_Subscriber_Query::STATS_CACHE_KEY );
$undo  = pdt_fail_query( 'AS expiring' );
$stats = Plandose_Subscriber_Query::stats();
$undo();
pdt_same( false, get_transient( Plandose_Subscriber_Query::STATS_CACHE_KEY ), 'failed stats query → nothing cached' );
$stats = Plandose_Subscriber_Query::stats();
pdt_check( is_array( get_transient( Plandose_Subscriber_Query::STATS_CACHE_KEY ) ), 'successful stats query → cached' );

// ---- hooks registered before the upgrade --------------------------------------------
$src      = file_get_contents( PLANDOSE_PATH . 'plandose.php' );
$init     = substr( $src, strpos( $src, 'function plandose_init()' ) );
$upgrade  = strpos( $init, "'failed' === plandose_maybe_upgrade()" );
pdt_check( false !== $upgrade && strpos( $init, 'Plandose_Privacy::init()' ) < $upgrade, 'privacy exporter/eraser registered before the upgrade check' );
pdt_check( false !== $upgrade && strpos( $init, "add_action( 'deleted_user'" ) < $upgrade, 'user-deletion cleanup registered before the upgrade check' );
pdt_check( false !== has_filter( 'wp_privacy_personal_data_erasers', array( 'Plandose_Privacy', 'register_eraser' ) ), 'eraser registered on this request' );

// ---- uninstall: an unreadable invoice list keeps everything -----------------------
$src = file_get_contents( PLANDOSE_PATH . 'uninstall.php' );
$src = preg_replace( '/^<\?php/', '', $src );
$src = preg_replace( '/\nplandose_run_uninstall\(\);\s*$/', "\n", $src );
$src = str_replace( "defined( 'WP_UNINSTALL_PLUGIN' )", 'true', $src );
eval( $src ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test only: load the uninstall functions without running them.

$settings_before = get_option( 'plandose_settings' );
pdt_defer(
	static function () use ( $settings_before ) {
		update_option( 'plandose_settings', $settings_before );
	}
);
$settings                           = is_array( $settings_before ) ? $settings_before : array();
$settings['keep_data_on_uninstall'] = 0;
update_option( 'plandose_settings', $settings );

wp_schedule_single_event( time() + 3600, PLANDOSE_CLEANUP_CRON_HOOK . '_pdt_probe' ); // unrelated, stays
$undo = pdt_fail_query( 'SELECT invoices FROM' );
plandose_run_uninstall();
$undo();
pdt_same( false, wp_next_scheduled( PLANDOSE_CLEANUP_CRON_HOOK ), 'keep-data mode still unschedules the cron (as always)' );
wp_clear_scheduled_hook( PLANDOSE_CLEANUP_CRON_HOOK . '_pdt_probe' );
update_option( 'plandose_settings', $settings_before );

pdt_same( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ), 'unreadable invoice list → subscriptions table NOT dropped' );
pdt_same( $history, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $history ) ) ), '… nor the history table' );
pdt_check( false !== get_option( 'plandose_settings' ), '… and the settings are kept' );

pdt_done();
