<?php
/**
 * PlanDose 1.29.0 — the print log (Plandose_Print_Log) and its admin
 * screen (Plandose_Admin_Prints). TEST-ONLY.
 */

require __DIR__ . '/lib.php';

global $wpdb;

$table = Plandose_Print_Log::table_name();
pdt_check( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), 'print-log table exists after the upgrade' );

$a = pdt_user( array( 'account_type' => 'Φαρμακείο', 'pharmacy_name' => 'Φαρμακείο <script>alert(1)</script> Α' ) );
$b = pdt_user( array( 'account_type' => 'Φαρμακείο', 'pharmacy_name' => 'Φαρμακείο Β' ) );
pdt_defer(
	static function () use ( $a, $b ) {
		Plandose_Print_Log::delete_for_user( $a );
		Plandose_Print_Log::delete_for_user( $b );
	}
);

// ---- record() -----------------------------------------------------------------------------
pdt_check( ! Plandose_Print_Log::record( $a, 'bogus', false ), 'unknown kind refused' );
pdt_check( ! Plandose_Print_Log::record( 0, 'charge', false ), 'no user refused' );
pdt_check( Plandose_Print_Log::record( $a, 'charge', false, 7 ), 'charge recorded' );
pdt_check( Plandose_Print_Log::record( $a, 'reprint', false ), 'reprint recorded' );
pdt_check( Plandose_Print_Log::record( $b, 'charge', true, 1 ), 'Pro charge recorded' );

$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ); // phpcs:ignore
pdt_same( array( 'id', 'user_id', 'printed_ts', 'kind', 'is_pro', 'month_count' ), $columns, 'no column can hold plan or patient data' );

// Rows at known times for the filters: 2 days ago, and 400 days ago.
$tz          = wp_timezone();
$two_days    = ( new DateTimeImmutable( 'today', $tz ) )->modify( '-2 days' )->setTime( 10, 0 );
$old         = time() - 400 * DAY_IN_SECONDS;
$wpdb->insert( $table, array( 'user_id' => $b, 'printed_ts' => $two_days->getTimestamp(), 'kind' => 'charge', 'is_pro' => 1, 'month_count' => 1 ) ); // phpcs:ignore
$wpdb->insert( $table, array( 'user_id' => $b, 'printed_ts' => $old, 'kind' => 'charge', 'is_pro' => 0, 'month_count' => 3 ) ); // phpcs:ignore

// ---- query() ------------------------------------------------------------------------------
$r = Plandose_Print_Log::query( array( 'user_ids' => array( $a ) ) );
pdt_same( 2, $r['total'], 'one pharmacy: its two rows' );
pdt_same( 'reprint', $r['rows'][0]->kind, '… newest first' );

$r = Plandose_Print_Log::query( array( 'user_ids' => array( $a, $b ), 'kind' => 'charge' ) );
pdt_same( 4, $r['total'], 'kind filter' );

$day = $two_days->format( 'Y-m-d' );
$r   = Plandose_Print_Log::query( array( 'user_ids' => array( $b ), 'from' => $day, 'to' => $day ) );
pdt_same( 1, $r['total'], 'one day, in the site time zone, inclusive' );

$r = Plandose_Print_Log::query( array( 'user_ids' => array( 0 ) ) );
pdt_same( 0, $r['total'], 'a search with no match finds nothing (not everything)' );

$r = Plandose_Print_Log::query( array( 'user_ids' => array( $a, $b ), 'per_page' => 2, 'paged' => 2 ) );
pdt_same( 5, $r['total'], 'total over pages' );
pdt_same( 2, count( $r['rows'] ), 'page 2 of 2-row pages' );

pdt_same( null, Plandose_Print_Log::day_start_ts( '2026-02-30' ), 'impossible date refused' );
pdt_same( null, Plandose_Print_Log::day_start_ts( "2026-01-01' OR 1=1" ), 'garbage refused' );

// ---- retention ----------------------------------------------------------------------------
Plandose_Print_Log::cleanup_expired();
$r = Plandose_Print_Log::query( array( 'user_ids' => array( $b ) ) );
pdt_same( 2, $r['total'], 'the 400-day-old row is gone, the recent ones stay' );

// ---- daylight-saving days (Europe/Athens): «to» means the whole calendar day ---------------
$tz_before = get_option( 'timezone_string' );
update_option( 'timezone_string', 'Europe/Athens' );
$athens = new DateTimeZone( 'Europe/Athens' );
$late   = ( new DateTimeImmutable( '2026-10-25 23:30', $athens ) )->getTimestamp(); // 25-hour day
$early  = ( new DateTimeImmutable( '2026-03-30 00:30', $athens ) )->getTimestamp(); // after a 23-hour day
$wpdb->insert( $table, array( 'user_id' => $a, 'printed_ts' => $late, 'kind' => 'charge', 'is_pro' => 0, 'month_count' => 1 ) ); // phpcs:ignore
$wpdb->insert( $table, array( 'user_id' => $a, 'printed_ts' => $early, 'kind' => 'charge', 'is_pro' => 0, 'month_count' => 1 ) ); // phpcs:ignore
pdt_same( 1, Plandose_Print_Log::query( array( 'user_ids' => array( $a ), 'from' => '2026-10-25', 'to' => '2026-10-25' ) )['total'], '25-hour day: 23:30 is inside «to»' );
pdt_same( 0, Plandose_Print_Log::query( array( 'user_ids' => array( $a ), 'from' => '2026-03-29', 'to' => '2026-03-29' ) )['total'], '23-hour day: 00:30 of the next day is outside «to»' );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE user_id = %d AND printed_ts IN (%d, %d)", $a, $late, $early ) ); // phpcs:ignore
update_option( 'timezone_string', $tz_before );

// ---- the log table is not a condition for the upgrade -------------------------------------
$wpdb->query( "RENAME TABLE {$table} TO {$table}_pdt_away" ); // phpcs:ignore
$tables_ok = pdt_call( 'Plandose_Subscriptions', 'tables_exist' );
$wpdb->query( "RENAME TABLE {$table}_pdt_away TO {$table}" ); // phpcs:ignore
pdt_check( $tables_ok, 'a missing print-log table does not fail the upgrade (the plugin keeps working)' );

// ---- the admin screen ---------------------------------------------------------------------
add_filter(
	'wp_die_handler',
	static function () {
		return static function ( $message ) {
			throw new RuntimeException( 'wp_die: ' . ( is_string( $message ) ? $message : '' ) );
		};
	}
);

$render = static function ( $get ) {
	$_GET = $get;
	ob_start();
	try {
		Plandose_Admin_Prints::render_page();
		return array( 'out' => ob_get_clean(), 'died' => '' );
	} catch ( RuntimeException $e ) {
		ob_end_clean();
		return array( 'out' => '', 'died' => $e->getMessage() );
	}
};

wp_set_current_user( pdt_user( array(), 'editor' ) );
pdt_check( '' !== $render( array() )['died'], 'no PlanDose capability: refused' );

// A delegated manager without list_users: refused (the rows show emails).
$cap_filter = static function () {
	return 'edit_posts';
};
add_filter( 'plandose_manage_capability', $cap_filter );
$died = $render( array() )['died'];
pdt_check( false !== strpos( $died, 'list_users' ), 'PlanDose manager without list_users: refused' );
remove_filter( 'plandose_manage_capability', $cap_filter );

wp_set_current_user( 1 );
$page = $render( array( 'page' => 'plandose-prints', 'user' => (string) $a ) );
pdt_same( '', $page['died'], 'administrator: the screen renders' );
pdt_check( false === strpos( $page['out'], '<script>alert(1)</script>' ), 'pharmacy name escaped' );
pdt_check( false !== strpos( $page['out'], '&lt;script&gt;' ), '… and shown as text' );
pdt_check( false !== strpos( $page['out'], 'Δωρεάν επανεκτύπωση' ) && false !== strpos( $page['out'], 'Χρεώθηκε' ), 'both kinds labelled' );
pdt_check( false === strpos( $page['out'], 'Φαρμακείο Β' ), 'user filter: only that pharmacy' );

$page = $render( array( 'page' => 'plandose-prints', 's' => 'zzz-no-such-pharmacy-zzz' ) );
pdt_check( false !== strpos( $page['out'], 'Καμία εκτύπωση με αυτά τα φίλτρα' ), 'search with no match: the empty state' );

$page = $render( array( 'page' => 'plandose-prints', 'kind' => "charge'><script>", 'from' => '<b>', 'per_page' => '999999' ) );
pdt_same( '', $page['died'], 'hostile filter values do not break the screen' );
pdt_check( false === strpos( $page['out'], "'><script>" ) && false === strpos( $page['out'], '<b>' ), '… and are not echoed raw' );

$page = $render( array( 'page' => 'plandose-prints', 'user' => (string) $a, 'paged' => '999' ) );
pdt_check( false !== strpos( $page['out'], 'pd-prints-table' ), 'a page past the end shows the last page, not «no prints»' );
$page = $render( array( 'page' => 'plandose-prints', 'paged' => '99999999999999999999' ) );
pdt_check( false === strpos( $page['out'], 'σφάλμα βάσης' ), 'a huge page number is no database error' );

pdt_check( false !== strpos( Plandose_Admin_Prints::url( $a ), 'user=' . $a ), 'card link targets the pharmacy' );
pdt_check( in_array( 'plandose_page_plandose-prints', Plandose_Admin::admin_screen_ids(), true ), 'admin CSS/JS load on the screen' );
wp_set_current_user( 0 );
$_GET = array();

// ---- privacy ------------------------------------------------------------------------------
$user    = get_userdata( $a );
$export  = Plandose_Privacy::export( $user->user_email, 1 );
$groups  = wp_list_pluck( $export['data'], 'group_id' );
pdt_check( in_array( 'plandose-print-log', $groups, true ), 'exported with the personal data' );
Plandose_Privacy::erase( $user->user_email, 1 );
pdt_same( 0, Plandose_Print_Log::query( array( 'user_ids' => array( $a ) ) )['total'], 'erased with the personal data' );

// ---- deleting the account drops its log ---------------------------------------------------
Plandose_Print_Log::record( $b, 'charge', false, 1 );
wp_delete_user( $b );
pdt_same( 0, Plandose_Print_Log::query( array( 'user_ids' => array( $b ) ) )['total'], 'account deleted: its print log is gone' );

// ---- uninstall knows the table ------------------------------------------------------------
pdt_check( false !== strpos( file_get_contents( PLANDOSE_PATH . 'uninstall.php' ), "'plandose_print_log'" ), 'uninstall drops the table' );
pdt_check( array_key_exists( $table, Plandose_Diagnostics::plugin_tables() ), 'Diagnostics checks the table' );

pdt_done();
