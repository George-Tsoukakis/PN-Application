<?php
/**
 * 1.30.1: the monthly reset at the rollover race, against the real test
 * WordPress + MariaDB. TEST-ONLY.
 *
 * A request whose «today» was fixed on the last day of a month runs after
 * another one has already rolled the counter over to the next month. That
 * counter is the running month: it must not be archived as closed and reset
 * back (its prints would drop out of its own month). A date far ahead is a
 * clock that was wrong: reset as before, so the counter cannot stay stuck.
 */
require __DIR__ . '/lib.php';

global $wpdb;

$table   = Plandose_Subscriptions::table_name();
$history = Plandose_Subscriptions::history_table_name();

$count_of = static function ( $uid ) use ( $wpdb, $table ) {
	return $wpdb->get_row( $wpdb->prepare( "SELECT print_count, count_reset_at FROM {$table} WHERE user_id = %d", $uid ) );
};
$history_of = static function ( $uid ) use ( $wpdb, $history ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$history} WHERE user_id = %d", $uid ) );
};

// ---- the late request: counter already in the next month -----------------------
$uid = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
$wpdb->insert( $table, array( 'user_id' => $uid, 'status' => 'free', 'print_count' => 3, 'count_reset_at' => '2026-10-01' ) );

pdt_same( false, Plandose_Subscriptions::maybe_reset_month( $uid, '2026-09-30' ), 'late request (30/9), counter already in October → no reset' );
$row = $count_of( $uid );
pdt_same( 3, (int) $row->print_count, '… October keeps its 3 prints' );
pdt_same( '2026-10-01', substr( (string) $row->count_reset_at, 0, 10 ), '… and stays dated October' );
pdt_same( 0, $history_of( $uid ), '… nothing archived as a closed month' );
pdt_same( false, Plandose_Subscriptions::try_increment_print_count( $uid, 30, '2026-09-30' ), '… the late increment matches no row (answered «try again»)' );
pdt_same( 3, (int) $count_of( $uid )->print_count, '… and October is untouched' );

// A timezone moved back a day: still the running month.
$wpdb->update( $table, array( 'count_reset_at' => '2026-10-02' ), array( 'user_id' => $uid ) );
pdt_same( false, Plandose_Subscriptions::maybe_reset_month( $uid, '2026-09-30' ), 'counter dated 2/10 seen from 30/9 → no reset either' );
pdt_same( 3, (int) $count_of( $uid )->print_count, '… count kept' );

// The next request, on 1/10, is in the counter's own month: nothing to do.
$wpdb->update( $table, array( 'count_reset_at' => '2026-10-01' ), array( 'user_id' => $uid ) );
pdt_same( false, Plandose_Subscriptions::maybe_reset_month( $uid, '2026-10-01' ), 'on 1/10 → running month, nothing to close' );
pdt_same( true, Plandose_Subscriptions::try_increment_print_count( $uid, 30, '2026-10-01' ), '… and the retried print is counted' );
pdt_same( 4, (int) $count_of( $uid )->print_count, '… in October (4)' );

// ---- a clock that was wrong: far ahead is reset as before -------------------------
$uid_b = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
$wpdb->insert( $table, array( 'user_id' => $uid_b, 'status' => 'free', 'print_count' => 30, 'count_reset_at' => '2030-01-15' ) );

pdt_same( true, Plandose_Subscriptions::maybe_reset_month( $uid_b, '2026-09-30' ), 'counter dated 2030 → reset (not stuck at the limit)' );
$row = $count_of( $uid_b );
pdt_same( 0, (int) $row->print_count, '… zeroed' );
pdt_same( '2026-09-30', substr( (string) $row->count_reset_at, 0, 10 ), '… and dated today' );

// ---- a normal closed month still closes -------------------------------------------
$uid_c = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
$wpdb->insert( $table, array( 'user_id' => $uid_c, 'status' => 'free', 'print_count' => 7, 'count_reset_at' => '2026-08-10' ) );

pdt_same( true, Plandose_Subscriptions::maybe_reset_month( $uid_c, '2026-09-30' ), 'August counter seen in September → archived and reset' );
pdt_same( 7, (int) $wpdb->get_var( $wpdb->prepare( "SELECT prints FROM {$history} WHERE user_id = %d AND ym = '2026-08'", $uid_c ) ), '… August archived with 7 prints' );
pdt_same( 0, (int) $count_of( $uid_c )->print_count, '… counter zeroed' );

pdt_done();
