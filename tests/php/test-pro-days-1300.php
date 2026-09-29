<?php
/**
 * Pro-days and print-counter rules of Plandose_Subscriptions against the
 * real database: pro_end_date_for(), grant_pro_days_if_unchanged(),
 * increment_print_count_unconditionally(), archive_idle_months(),
 * cleanup_stale_print_locks().
 */
require __DIR__ . '/lib.php';

global $wpdb;

$tz    = wp_timezone();
$table = Plandose_Subscriptions::table_name();
$day   = static function ( $ymd ) use ( $tz ) {
	return new DateTimeImmutable( $ymd, $tz );
};
$row   = static function ( $status, $end ) {
	return (object) array(
		'status'       => $status,
		'sub_end_date' => $end,
	);
};

// ---- pro_end_date_for(): sub_end_date is the last day, inclusive -------------------------
$today = $day( '2026-05-10' );
pdt_same( '2026-06-08', Plandose_Subscriptions::pro_end_date_for( null, 30, $today ), 'no row: 30 days = today + 29' );
pdt_same( '2026-05-10', Plandose_Subscriptions::pro_end_date_for( $row( 'free', null ), 1, $today ), 'new activation, 1 day = today only' );
pdt_same( '2026-07-19', Plandose_Subscriptions::pro_end_date_for( $row( 'pro', '2026-06-19' ), 30, $today ), 'Pro still active: the days are appended after its last day' );
pdt_same( '2026-05-11', Plandose_Subscriptions::pro_end_date_for( $row( 'pro', '2026-05-10' ), 1, $today ), 'Pro ending today is still active: 1 day more = tomorrow' );
pdt_same( '2026-06-08', Plandose_Subscriptions::pro_end_date_for( $row( 'pro', '2026-05-09' ), 30, $today ), 'Pro expired yesterday: counted from today, nothing appended' );
pdt_same( '2026-06-08', Plandose_Subscriptions::pro_end_date_for( $row( 'free', '2026-12-31' ), 30, $today ), 'a Free row with a leftover future end date is not active Pro' );
pdt_same( '2026-05-10', Plandose_Subscriptions::pro_end_date_for( null, 0, $today ), 'add_days 0 is clamped to 1' );
pdt_same( '2026-05-10', Plandose_Subscriptions::pro_end_date_for( null, -5, $today ), 'negative add_days is clamped to 1' );
pdt_same( '2026-06-08', Plandose_Subscriptions::pro_end_date_for( null, 30, $day( '2026-05-10 17:45:00' ) ), 'a "today" with a time of day counts from midnight' );

// Month ends.
pdt_same( '2026-03-01', Plandose_Subscriptions::pro_end_date_for( null, 30, $day( '2026-01-31' ) ), 'month-end: 31 Jan + 29 days = 1 Mar (no month-overflow arithmetic)' );
pdt_same( '2026-02-01', Plandose_Subscriptions::pro_end_date_for( $row( 'pro', '2026-01-31' ), 1, $day( '2026-01-20' ) ), 'month-end: extending a Pro ending 31 Jan by 1 day = 1 Feb' );
pdt_same( '2027-01-01', Plandose_Subscriptions::pro_end_date_for( $row( 'pro', '2026-12-31' ), 1, $day( '2026-12-31' ) ), 'year-end: ending today (31 Dec) + 1 day = 1 Jan' );

// Leap years.
pdt_same( '2028-02-29', Plandose_Subscriptions::pro_end_date_for( $row( 'pro', '2028-02-28' ), 1, $day( '2028-02-01' ) ), 'leap year: 28 Feb 2028 + 1 = 29 Feb' );
pdt_same( '2029-02-27', Plandose_Subscriptions::pro_end_date_for( $row( 'pro', '2028-02-29' ), 364, $day( '2028-02-10' ) ), 'leap day + 364 = 27 Feb 2029 (the documented example)' );
pdt_same( '2029-02-27', Plandose_Subscriptions::pro_end_date_for( null, 365, $day( '2028-02-29' ) ), 'new activation on the leap day, 365 days: last day 27 Feb 2029' );
pdt_same( '2027-03-01', Plandose_Subscriptions::pro_end_date_for( $row( 'pro', '2026-02-28' ), 366, $day( '2026-02-01' ) ), 'non-leap: 28 Feb 2026 + 366 = 1 Mar 2027' );

// ---- grant_pro_days_if_unchanged() ---------------------------------------------------------
$real_today = new DateTimeImmutable( 'today', $tz );
$pharmacy   = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
$fresh_row  = static function ( $uid ) use ( $wpdb, $table ) {
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $uid ) ); // phpcs:ignore
};

Plandose_Subscriptions::get_row( $pharmacy );
$state = Plandose_Subscriptions::state_token( $fresh_row( $pharmacy ) );
pdt_same( 'free|', $state, 'a new pharmacy starts Free with no end date' );

pdt_same( 'stale', Plandose_Subscriptions::grant_pro_days_if_unchanged( $pharmacy, 30, 'pro|2020-01-01' ), 'expected state mismatch: refused as stale' );
pdt_same( 'free|', Plandose_Subscriptions::state_token( $fresh_row( $pharmacy ) ), '… and nothing was granted' );

pdt_same( 'updated', Plandose_Subscriptions::grant_pro_days_if_unchanged( $pharmacy, 30, $state ), 'grant with the state the form showed' );
$after = $fresh_row( $pharmacy );
pdt_same( 'pro', $after->status, '… status is Pro' );
pdt_same( $real_today->modify( '+29 days' )->format( 'Y-m-d' ), $after->sub_end_date, '… 30 days, today included' );
pdt_check( Plandose_Subscriptions::user_is_pro( $pharmacy ), '… user_is_pro()' );

pdt_same( 'stale', Plandose_Subscriptions::grant_pro_days_if_unchanged( $pharmacy, 30, $state ), 'double submit of the same form: stale' );
pdt_same( $after->sub_end_date, $fresh_row( $pharmacy )->sub_end_date, '… the days were NOT added twice' );

$state2 = Plandose_Subscriptions::state_token( $fresh_row( $pharmacy ) );
pdt_same( 'updated', Plandose_Subscriptions::grant_pro_days_if_unchanged( $pharmacy, 10, $state2 ), 'extension from the current state' );
pdt_same( $real_today->modify( '+39 days' )->format( 'Y-m-d' ), $fresh_row( $pharmacy )->sub_end_date, '… appended after the current last day' );

// A row changed after the state was read (another admin) — the conditional UPDATE refuses it.
$state3 = Plandose_Subscriptions::state_token( $fresh_row( $pharmacy ) );
$wpdb->update( $table, array( 'sub_end_date' => $real_today->modify( '+100 days' )->format( 'Y-m-d' ) ), array( 'user_id' => $pharmacy ) ); // phpcs:ignore
pdt_same( 'stale', Plandose_Subscriptions::grant_pro_days_if_unchanged( $pharmacy, 10, $state3 ), 'row changed by someone else since the form: stale' );
pdt_same( $real_today->modify( '+100 days' )->format( 'Y-m-d' ), $fresh_row( $pharmacy )->sub_end_date, '… their change is kept' );

// Clamp: 1 … max_add_days().
$max = Plandose_Settings::max_add_days();
$wpdb->update( $table, array( 'status' => 'free', 'sub_end_date' => null ), array( 'user_id' => $pharmacy ) ); // phpcs:ignore
pdt_same( 'updated', Plandose_Subscriptions::grant_pro_days_if_unchanged( $pharmacy, $max + 500, 'free|' ), 'more than max_add_days requested' );
pdt_same( $real_today->modify( '+' . ( $max - 1 ) . ' days' )->format( 'Y-m-d' ), $fresh_row( $pharmacy )->sub_end_date, '… clamped to max_add_days (' . $max . ')' );
$wpdb->update( $table, array( 'status' => 'free', 'sub_end_date' => null ), array( 'user_id' => $pharmacy ) ); // phpcs:ignore
pdt_same( 'updated', Plandose_Subscriptions::grant_pro_days_if_unchanged( $pharmacy, 0, 'free|' ), '0 days requested' );
pdt_same( $real_today->format( 'Y-m-d' ), $fresh_row( $pharmacy )->sub_end_date, '… clamped to 1 day (today)' );

// Only a «Φαρμακείο» account, and never user 0.
$other = pdt_user( array( 'account_type' => 'Εταιρία' ) );
pdt_same( 'failed', Plandose_Subscriptions::grant_pro_days_if_unchanged( $other, 30, 'free|' ), 'non-pharmacy account: failed' );
pdt_same( null, $fresh_row( $other ), '… and no row was created for it' );
pdt_same( 'failed', Plandose_Subscriptions::grant_pro_days_if_unchanged( 0, 30, 'free|' ), 'user 0: failed' );

// ---- increment_print_count_unconditionally() ------------------------------------------------
$printer = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
Plandose_Subscriptions::get_row( $printer );
$today_ymd = current_time( 'Y-m-d' );
$wpdb->update( $table, array( 'print_count' => 7, 'count_reset_at' => $today_ymd, 'last_print_at' => null ), array( 'user_id' => $printer ) ); // phpcs:ignore

pdt_same( true, Plandose_Subscriptions::increment_print_count_unconditionally( $printer, $today_ymd ), 'counter of the running month: incremented' );
$r = $fresh_row( $printer );
pdt_same( 8, (int) $r->print_count, '… by exactly one' );
pdt_check( ! empty( $r->last_print_at ), '… last_print_at set' );
pdt_same( true, Plandose_Subscriptions::increment_print_count_unconditionally( $printer ), 'without an explicit "today" too' );
pdt_same( 9, (int) $fresh_row( $printer )->print_count, '… 9' );

$wpdb->update( $table, array( 'print_count' => 1000, 'count_reset_at' => '2020-06-15' ), array( 'user_id' => $printer ) ); // phpcs:ignore
pdt_same( false, Plandose_Subscriptions::increment_print_count_unconditionally( $printer, $today_ymd ), 'counter still holding a past month (reset failed): refused' );
pdt_same( 1000, (int) $fresh_row( $printer )->print_count, '… the old month is not incremented' );

$wpdb->update( $table, array( 'print_count' => 5, 'count_reset_at' => null ), array( 'user_id' => $printer ) ); // phpcs:ignore
pdt_same( false, Plandose_Subscriptions::increment_print_count_unconditionally( $printer, $today_ymd ), 'counter never reset (NULL month): refused' );

$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET print_count = 4294967295, count_reset_at = %s WHERE user_id = %d", $today_ymd, $printer ) ); // phpcs:ignore
pdt_same( false, Plandose_Subscriptions::increment_print_count_unconditionally( $printer, $today_ymd ), 'INT UNSIGNED ceiling: refused, no overflow error' );
pdt_same( '4294967295', (string) $fresh_row( $printer )->print_count, '… value unchanged' );

pdt_same( false, Plandose_Subscriptions::increment_print_count_unconditionally( 0 ), 'user 0: false' );

// The limited twin, for contrast: capped at the limit, same month rule.
$wpdb->update( $table, array( 'print_count' => 2, 'count_reset_at' => $today_ymd ), array( 'user_id' => $printer ) ); // phpcs:ignore
pdt_same( true, Plandose_Subscriptions::try_increment_print_count( $printer, 3, $today_ymd ), 'limited: 2 → 3 of 3' );
pdt_same( false, Plandose_Subscriptions::try_increment_print_count( $printer, 3, $today_ymd ), 'limited: at 3 of 3 refused' );
pdt_same( true, Plandose_Subscriptions::increment_print_count_unconditionally( $printer, $today_ymd ), 'unconditional: counts past any limit (unlimited plans)' );
pdt_same( 4, (int) $fresh_row( $printer )->print_count, '… 4' );

// ---- archive_idle_months() ------------------------------------------------------------------
$idle_a = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
$idle_b = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
$idle_0 = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
$busy   = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
foreach ( array( $idle_a, $idle_b, $idle_0, $busy ) as $u ) {
	Plandose_Subscriptions::get_row( $u );
}
$wpdb->update( $table, array( 'print_count' => 5, 'count_reset_at' => '2020-06-15' ), array( 'user_id' => $idle_a ) ); // phpcs:ignore
$wpdb->update( $table, array( 'print_count' => 3, 'count_reset_at' => '2020-07-01' ), array( 'user_id' => $idle_b ) ); // phpcs:ignore
$wpdb->update( $table, array( 'print_count' => 0, 'count_reset_at' => '2020-06-15' ), array( 'user_id' => $idle_0 ) ); // phpcs:ignore
$wpdb->update( $table, array( 'print_count' => 4, 'count_reset_at' => $today_ymd ), array( 'user_id' => $busy ) ); // phpcs:ignore
$history = Plandose_Subscriptions::history_table_name();
$hist    = static function ( $uid, $ym ) use ( $wpdb, $history ) {
	return $wpdb->get_var( $wpdb->prepare( "SELECT prints FROM {$history} WHERE user_id = %d AND ym = %s", $uid, $ym ) ); // phpcs:ignore
};

// Batch size 1: the loop must go on past the first batch.
$closed = Plandose_Subscriptions::archive_idle_months( 1, 20 );
pdt_check( $closed >= 2, 'both idle pharmacies closed across batches of 1 (closed ' . $closed . ')' );
pdt_same( '5', (string) $hist( $idle_a, '2020-06' ), 'idle A: June 2020 archived with its 5 prints' );
pdt_same( '3', (string) $hist( $idle_b, '2020-07' ), 'idle B: July 2020 archived with its 3 prints' );
$ra = $fresh_row( $idle_a );
pdt_same( 0, (int) $ra->print_count, 'idle A: counter zeroed' );
pdt_same( $today_ymd, substr( (string) $ra->count_reset_at, 0, 10 ), 'idle A: now in the running month' );
pdt_same( '2020-06-15', substr( (string) $fresh_row( $idle_0 )->count_reset_at, 0, 10 ), 'a stale month with 0 prints is not touched (nothing to archive)' );
pdt_same( null, $hist( $idle_0, '2020-06' ), '… and gets no history row' );
$rb = $fresh_row( $busy );
pdt_same( 4, (int) $rb->print_count, 'the running month is left alone' );
pdt_same( null, $hist( $busy, substr( $today_ymd, 0, 7 ) ), '… and not archived' );

$wpdb->update( $table, array( 'print_count' => 2, 'count_reset_at' => '2020-06-20' ), array( 'user_id' => $idle_a ) ); // phpcs:ignore
Plandose_Subscriptions::archive_idle_months();
pdt_same( '5', (string) $hist( $idle_a, '2020-06' ), 'archiving the same month again with a smaller figure never lowers it (GREATEST)' );

// ---- cleanup_stale_print_locks() ------------------------------------------------------------
$now        = current_datetime();
$this_month = $now->format( 'Ym' );
$last_month = $now->modify( 'first day of last month' )->format( 'Ym' );
$lock_opts  = array(
	'old'     => 'plandose_print_lock_' . $printer . '_202001',
	'older'   => 'plandose_print_lock_9' . $printer . '_201912',
	'current' => 'plandose_print_lock_' . $printer . '_' . $this_month,
	'last'    => 'plandose_print_lock_' . $printer . '_' . $last_month,
	'modern'  => '_transient_plandose_print_lock_' . $printer . '_' . md5( 'pdt' ),
);
foreach ( $lock_opts as $name ) {
	$wpdb->replace( $wpdb->options, array( 'option_name' => $name, 'option_value' => '1', 'autoload' => 'no' ) ); // phpcs:ignore
}
pdt_defer(
	static function () use ( $wpdb, $lock_opts ) {
		foreach ( $lock_opts as $name ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
		}
		wp_cache_delete( 'alloptions', 'options' );
	}
);
wp_cache_delete( 'alloptions', 'options' );
Plandose_Subscriptions::cleanup_stale_print_locks();
$exists = static function ( $name ) use ( $wpdb ) {
	return null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name = %s", $name ) );
};
pdt_same( false, $exists( $lock_opts['old'] ), 'legacy lock from Jan 2020: deleted' );
pdt_same( false, $exists( $lock_opts['older'] ), 'legacy lock from Dec 2019: deleted' );
pdt_same( true, $exists( $lock_opts['current'] ), 'legacy lock of the current month: kept' );
pdt_same( true, $exists( $lock_opts['last'] ), 'legacy lock of last month: kept (boundary margin)' );
pdt_same( true, $exists( $lock_opts['modern'] ), 'a current transient-backed lock is never matched' );

pdt_done();
