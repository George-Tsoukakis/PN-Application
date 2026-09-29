<?php
/**
 * 1.27.0: the restructured subscriber list (candidates from usermeta) gives
 * exactly the same ids, totals and KPIs as the 1.26.1 query, on a seeded
 * set of awkward account_type values; narrowed cache invalidation.
 */
require __DIR__ . '/lib.php';

global $wpdb;

// The 1.26.1 class, renamed.
$old = file_get_contents( __DIR__ . '/fixtures/subscriber-query-1261.php' );
$old = preg_replace( '/^<\?php/', '', $old );
$old = str_replace( 'class Plandose_Subscriber_Query {', 'class Plandose_Subscriber_Query_1261 {', $old );
eval( $old ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test only.

$today = current_time( 'Y-m-d' );
$in    = static function ( $days ) {
	return current_datetime()->modify( ( $days >= 0 ? '+' : '' ) . $days . ' days' )->format( 'Y-m-d' );
};
$nbsp  = "\u{00A0}";

$seed = array(
	// meta, subscription row (or null), label
	array( array( 'account_type' => 'Φαρμακείο' ), array( 'status' => 'pro', 'sub_end_date' => $in( 3 ), 'print_count' => 4, 'count_reset_at' => $today, 'invoices' => '["a.pdf"]' ) ),
	array( array( 'account_type' => 'φαρμακείο' ), array( 'status' => 'pro', 'sub_end_date' => $in( 200 ), 'print_count' => 2, 'count_reset_at' => $today ) ),
	array( array( 'account_type' => 'ΦΑΡΜΑΚΕΙΟ' ), array( 'status' => 'pro', 'sub_end_date' => $in( -5 ), 'print_count' => 9, 'count_reset_at' => $in( -40 ) ) ),
	array( array( 'account_type' => 'Φαρμακειο' ), null ),
	array( array( 'account_type' => 'Φαρμακείο' . $nbsp ), array( 'status' => 'free', 'print_count' => 1, 'count_reset_at' => $today, 'invoices' => '[]' ) ),
	array( array( 'account_type' => '  Φαρμακείο  ' ), null ),
	array( array( 'user_registration_account_type' => serialize( array( 'Φαρμακείο' ) ) ), array( 'status' => 'free', 'print_count' => 0, 'count_reset_at' => $today, 'invoices' => 'null' ) ),
	array( array( 'user_registration_Account_Type' => 'Φαρμακείο' ), null ),
	array( array( 'account_type' => 'Εταιρία', 'user_registration_account_type' => 'Φαρμακείο' ), array( 'status' => 'free', 'print_count' => 3, 'count_reset_at' => $today ) ),
	array( array( 'account_type' => 'Φαρμακείο', 'user_registration_account_type' => 'Φαρμακείο' ), array( 'status' => 'pro', 'sub_end_date' => $today, 'print_count' => 1, 'count_reset_at' => $today ) ),
	array( array( 'account_type' => 'Εταιρία' ), array( 'status' => 'pro', 'sub_end_date' => $in( 10 ), 'print_count' => 50, 'count_reset_at' => $today ) ),
	array( array( 'account_type' => '' ), null ),
	array( array( 'account_type' => ' ' ), null ),
	array( array( 'account_type' => 'Φαρμακείο Χ' ), null ),
	array( array( 'account_type' => serialize( array( 'Εταιρία', 'Φαρμακείο' ) ) ), null ),
	array( array( 'pharmacy_name' => 'Φαρμακείο Αλφα' ), null ),
	array( array(), null ),
);

$prefix_name = 'PDTSQ' . wp_generate_password( 4, false, false );
$ids         = array();

foreach ( $seed as $i => $case ) {
	$uid = pdt_user( array(), 'subscriber' );
	wp_update_user( array( 'ID' => $uid, 'display_name' => $prefix_name . ' ' . chr( 65 + ( $i * 7 ) % 26 ) . $i ) );

	foreach ( $case[0] as $key => $value ) {
		// Raw insert: the values above are exactly what a form could store.
		$wpdb->insert( $wpdb->usermeta, array( 'user_id' => $uid, 'meta_key' => $key, 'meta_value' => $value ) );
	}

	if ( $case[1] ) {
		$wpdb->insert( Plandose_Subscriptions::table_name(), array_merge( array( 'user_id' => $uid ), $case[1] ) );
	}

	$ids[] = $uid;
}

wp_cache_flush();

// Orphan meta (no wp_users row) must not be listed by either query.
$orphan = 900000000 + wp_rand( 1, 99999 );
$wpdb->insert( $wpdb->usermeta, array( 'user_id' => $orphan, 'meta_key' => 'account_type', 'meta_value' => 'Φαρμακείο' ) );
pdt_defer( static function () use ( $wpdb, $orphan ) { $wpdb->delete( $wpdb->usermeta, array( 'user_id' => $orphan ) ); } );

$flush = static function () {
	foreach ( array( 'plandose_subscriber_stats', 'plandose_uncounted_users', 'plandose_subscriber_rows_cache', 'plandose_subscriber_count_cache' ) as $t ) {
		delete_transient( $t );
	}
};

$combos = array();
foreach ( array( 'all', 'free', 'pro', 'expiring' ) as $status ) {
	foreach ( array( 'all', 'has', 'missing' ) as $inv ) {
		$combos[] = array( 'status' => $status, 'invoice_status' => $inv );
	}
}
$combos[] = array( 'search' => $prefix_name );
$combos[] = array( 'search' => 'Αλφα' );
$combos[] = array( 'status' => 'pro', 'search' => $prefix_name, 'expiry_from' => $in( 1 ), 'expiry_to' => $in( 300 ) );
$combos[] = array( 'expiry_from' => $in( -10 ) );
$combos[] = array( 'status' => 'free', 'per_page' => 3, 'paged' => 2 );
$combos[] = array( 'per_page' => 1000 );

$listed_ours = array();

foreach ( $combos as $combo ) {
	$args = array_merge( array( 'per_page' => 1000 ), $combo );
	$flush();
	$a = Plandose_Subscriber_Query_1261::query_subscribers( $args );
	$flush();
	$b = Plandose_Subscriber_Query::query_subscribers( $args );
	$ids_a = wp_list_pluck( wp_list_pluck( $a['items'], 'user' ), 'ID' );
	$ids_b = wp_list_pluck( wp_list_pluck( $b['items'], 'user' ), 'ID' );
	$label = wp_json_encode( $combo, JSON_UNESCAPED_UNICODE );
	pdt_same( $ids_a, $ids_b, "same ids, same order: $label" );
	pdt_same( $a['total'], $b['total'], "same total ({$a['total']}): $label" );

	if ( array( 'per_page' => 1000 ) === $combo ) {
		$listed_ours = array_values( array_intersect( array_map( 'intval', $ids_b ), $ids ) );
	}
}

// The seed really exercises the matching rules: exactly these of ours are members.
$expected_members = array( $ids[0], $ids[1], $ids[2], $ids[3], $ids[4], $ids[5], $ids[6], $ids[7], $ids[8], $ids[9], $ids[14] );
sort( $expected_members );
sort( $listed_ours );
pdt_same( $expected_members, $listed_ours, 'seeded members: case/accents, NBSP, spaces, serialized, every key; not Εταιρία, empty, «Φαρμακείο Χ»' );

// Cached total: a second call does not COUNT again, and gives the same number.
$flush();
$first   = Plandose_Subscriber_Query::query_subscribers( array( 'status' => 'pro' ) );
$counted = 0;
$spy     = static function ( $q ) use ( &$counted ) {
	if ( is_string( $q ) && 0 === strpos( ltrim( $q ), 'SELECT COUNT(*)' ) ) {
		++$counted;
	}
	return $q;
};
add_filter( 'query', $spy );
$second = Plandose_Subscriber_Query::query_subscribers( array( 'status' => 'pro', 'paged' => 2 ) );
$search = Plandose_Subscriber_Query::query_subscribers( array( 'search' => $prefix_name ) );
remove_filter( 'query', $spy );
pdt_same( $first['total'], $second['total'], 'cached total equals the counted one' );
pdt_same( 1, $counted, 'total cached per filter (no second COUNT); a search is always counted' );

// KPIs.
$flush();
$sa = Plandose_Subscriber_Query_1261::stats();
$flush();
$sb = Plandose_Subscriber_Query::stats();
foreach ( array( 'total', 'free', 'pro', 'expiring', 'prints', 'month' ) as $k ) {
	pdt_same( $sa[ $k ], $sb[ $k ], "stats[$k] unchanged" );
}
pdt_same( false, array_key_exists( 'invoices', $sb ), 'unused stats[invoices] removed' );

// uncounted_users() still works (uses the EXISTS form).
$flush();
$ua = Plandose_Subscriber_Query_1261::uncounted_users( 1000 );
$flush();
$ub = Plandose_Subscriber_Query::uncounted_users( 1000 );
pdt_same( $ua['total'], $ub['total'], 'uncounted total unchanged' );
pdt_same( $ua['groups'], $ub['groups'], 'uncounted groups unchanged' );

// ---- Narrowed invalidation.
$probe = static function () {
	set_transient( 'plandose_subscriber_stats', array( 'probe' => 1 ), 300 );
};
$uid = $ids[0];
$probe();
update_user_meta( $uid, 'billing_phone', '2100000000' );
update_user_meta( $uid, 'billing_city', 'Αθήνα' );
update_user_meta( $uid, 'pharmacy_name', 'X' );
pdt_same( array( 'probe' => 1 ), get_transient( 'plandose_subscriber_stats' ), 'billing/phone/name meta changes keep the KPI cache' );
update_user_meta( $uid, 'user_registration_Account_Type', 'Φαρμακείο' );
pdt_same( false, get_transient( 'plandose_subscriber_stats' ), 'an account_type key change drops it' );
$probe();
Plandose_Access::set_manual_access( $uid, 'deny' );
pdt_same( false, get_transient( 'plandose_subscriber_stats' ), 'a manual access change drops it' );
Plandose_Access::set_manual_access( $uid, '' );
$probe();
( new WP_User( $uid ) )->add_role( 'editor' );
pdt_same( false, get_transient( 'plandose_subscriber_stats' ), 'a role change drops it' );

// EXPLAIN: the candidate scan uses the meta_key index.
$params = array();
$m      = new ReflectionMethod( 'Plandose_Subscriber_Query', 'membership_candidates_sql' );
$m->setAccessible( true );
$sql     = 'SELECT pm.user_id FROM ' . $m->invokeArgs( null, array( &$params ) );
$explain = $wpdb->get_results( $wpdb->prepare( 'EXPLAIN ' . $sql, $params ) );
$keys    = implode( ',', array_filter( wp_list_pluck( (array) $explain, 'key' ) ) );
pdt_check( false !== strpos( $keys, 'meta_key' ), 'candidate query uses the meta_key index (EXPLAIN keys: ' . $keys . ')' );

$flush();
pdt_done();
