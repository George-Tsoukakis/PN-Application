<?php
/**
 * 1.27.0: erasing a LIVE Free account keeps its monthly counter; the
 * exporter covers what the eraser deletes, for deleted accounts too.
 */
require __DIR__ . '/lib.php';

global $wpdb;

$table = Plandose_Subscriptions::table_name();
$today = current_time( 'Y-m-d' );

// ---- Live Free account with prints this month.
$uid   = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
$email = get_userdata( $uid )->user_email;
$wpdb->insert( $table, array( 'user_id' => $uid, 'status' => 'pro', 'sub_end_date' => '2020-01-31', 'print_count' => 7, 'count_reset_at' => $today, 'last_print_at' => current_time( 'mysql' ) ) );
Plandose_Subscriptions::record_month_history( $uid, '2020-01', 12 );
Plandose_Print_Charges::write_charge( $uid, md5( 'pdt-privacy' ) );

$export = Plandose_Privacy::export( $email, 1 );
$groups = array_unique( wp_list_pluck( $export['data'], 'group_id' ) );
pdt_check( in_array( 'plandose-print-charges', $groups, true ), 'live account export includes the print-charge ledger' );
pdt_check( in_array( 'plandose-subscription', $groups, true ) && in_array( 'plandose-print-history', $groups, true ), 'live account export includes subscription and history' );

$result = Plandose_Privacy::erase( $email, 1 );
$row    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $uid ) );

pdt_check( is_object( $row ), 'live account with prints this month: the row is kept' );
pdt_check( $row && 7 === (int) $row->print_count && $today === $row->count_reset_at, 'monthly counter kept (7, this month) — no fresh allowance' );
pdt_check( $row && 'free' === $row->status && null === $row->sub_end_date && null === $row->last_print_at, 'everything else cleared (status free, no end date, no last print)' );
pdt_same( true, $result['items_retained'], 'reported as retained' );
pdt_check( (bool) preg_grep( '/μετρητής εκτυπώσεων/u', $result['messages'] ), 'the retained-data message explains the counter' );
pdt_same( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Plandose_Subscriptions::history_table_name() . ' WHERE user_id = %d', $uid ) ), 'history deleted' );
pdt_same( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Plandose_Print_Charges::table_name() . ' WHERE user_id = %d', $uid ) ), 'charges deleted' );

// The next print counts on from 7, not from 0.
pdt_same( true, Plandose_Subscriptions::try_increment_print_count( $uid, 450, $today ), 'next print increments' );
pdt_same( 8, (int) $wpdb->get_var( $wpdb->prepare( "SELECT print_count FROM {$table} WHERE user_id = %d", $uid ) ), 'counter continues at 8' );

// ---- Live account without prints this month: row deleted as before.
$uid2   = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
$email2 = get_userdata( $uid2 )->user_email;
$wpdb->insert( $table, array( 'user_id' => $uid2, 'status' => 'free', 'print_count' => 5, 'count_reset_at' => '2020-01-01' ) );
Plandose_Privacy::erase( $email2, 1 );
pdt_same( null, $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $uid2 ) ), 'no prints this month: the row is deleted' );

// ---- Deleted account: export covers subscription, invoices, history, ledger.
$uid3   = pdt_user( array( 'account_type' => 'Φαρμακείο', 'pharmacy_name' => 'PDT Φαρμακείο' ) );
$email3 = get_userdata( $uid3 )->user_email;
$wpdb->insert( $table, array( 'user_id' => $uid3, 'status' => 'free', 'print_count' => 2, 'count_reset_at' => $today, 'invoices' => '["pdt-inv-1.pdf"]' ) );
wp_delete_user( $uid3 ); // snapshot + retire_row (invoices kept)

pdt_check( is_array( Plandose_Admin::deleted_account_snapshot( $uid3 ) ), 'precondition: deleted-account snapshot exists' );

// Rows the eraser would delete, present for the deleted account.
Plandose_Subscriptions::record_month_history( $uid3, '2020-02', 3 );
Plandose_Print_Charges::write_charge( $uid3, md5( 'pdt-privacy-3' ) );

$export3 = Plandose_Privacy::export( $email3, 1 );
$groups3 = array_unique( wp_list_pluck( $export3['data'], 'group_id' ) );
foreach ( array( 'plandose-deleted-account', 'plandose-subscription', 'plandose-invoices', 'plandose-print-history', 'plandose-print-charges' ) as $g ) {
	pdt_check( in_array( $g, $groups3, true ), "deleted account export includes $g" );
}

pdt_done();
