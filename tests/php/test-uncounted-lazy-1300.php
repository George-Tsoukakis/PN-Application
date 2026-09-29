<?php
/**
 * PlanDose — «Χρήστες που δεν μετράνε ως φαρμακεία» loaded on demand, and
 * the subscriber caches no longer dropped by every profile save. TEST-ONLY.
 *
 * - rendering a list screen does not run the uncounted-users detail query
 *   (the per-user SELECT … ORDER BY u.ID DESC LIMIT) nor print the users,
 *   with a cold or a warm cache; ?pd_uncounted=1 (the no-JS link) does;
 * - the admin-ajax endpoint refuses without a nonce, with a nonce of
 *   another action, and without list_users; with them it returns the
 *   right users as escaped HTML, and the detail IDs are cached with the
 *   counts;
 * - the caches are still dropped by an account_type change and a role
 *   change, but not by a profile save (wp_update_user → profile_update)
 *   or an unrelated meta key.
 */

require __DIR__ . '/lib.php';

$cache_key = Plandose_Subscriber_Query::UNCOUNTED_CACHE_KEY;
$stats_key = Plandose_Subscriber_Query::STATS_CACHE_KEY;

$pdt_admin = pdt_user( array(), 'administrator' );
$type_key  = Plandose_Access::account_type_meta_keys()[0];
$pharmacy  = Plandose_Access::pharmacy_account_type_sql_variants()[0];
$other_a   = pdt_user( array( $type_key => 'Πελάτης <b>1300</b>' ), 'subscriber' );
$other_b   = pdt_user( array(), 'subscriber' );
$email_a   = get_userdata( $other_a )->user_email;
$email_b   = get_userdata( $other_b )->user_email;
$email_adm = get_userdata( $pdt_admin )->user_email;

add_filter( 'send_email_change_email', '__return_false' );
wp_set_current_user( $pdt_admin );

/** Queries run while $fn executes. */
function pdt_queries_during( $fn ) {
	$seen   = array();
	$filter = static function ( $sql ) use ( &$seen ) {
		$seen[] = $sql;
		return $sql;
	};
	add_filter( 'query', $filter );
	try {
		$fn();
	} finally {
		remove_filter( 'query', $filter );
	}
	return $seen;
}

/** Output of one list screen, and the queries it ran. */
function pdt_render_list( $get = array() ) {
	$_GET                   = $get;
	$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?' . http_build_query( array_merge( array( 'page' => 'plandose-subscriptions' ), $get ) );
	$html                   = '';
	$queries                = pdt_queries_during(
		static function () use ( &$html ) {
			ob_start();
			Plandose_Admin_Subscriptions::render_subscriptions_page();
			$html = ob_get_clean();
		}
	);
	$_GET = array();
	return array( $html, $queries );
}

function pdt_is_detail_query( $sql ) {
	return (bool) preg_match( '/ORDER BY u\.ID DESC LIMIT/i', $sql );
}

/** The uncounted box (up to the bottom grid), so the list cards do not interfere. */
function pdt_uncounted_box( $html ) {
	$start = strpos( $html, 'id="pd-uncounted"' );
	if ( false === $start ) {
		return '';
	}
	$end = strpos( $html, '</details>', $start );
	return substr( $html, $start, false === $end ? null : $end - $start );
}

// ---- the list page renders only the summary --------------------------------------------
foreach ( array( 'cold cache', 'warm cache' ) as $round ) {
	if ( 'cold cache' === $round ) {
		Plandose_Subscriber_Query::invalidate_subscriber_cache();
	}

	list( $html, $queries ) = pdt_render_list();
	$box                    = pdt_uncounted_box( $html );
	$detail                 = array_filter( $queries, 'pdt_is_detail_query' );

	pdt_check( '' !== $box, "$round: the uncounted box is rendered" );
	pdt_same( array(), array_values( $detail ), "$round: no uncounted-users detail query on the list page" );
	pdt_check( false !== strpos( $box, 'data-pd-lazy-url=' ) && false !== strpos( $box, 'action=' . Plandose_Admin_Subscriptions::UNCOUNTED_AJAX_ACTION ), "$round: box carries the AJAX URL" );
	pdt_check( (bool) preg_match( '/[?&](amp;|#038;)?nonce=[0-9a-f]{10}/', $box ), "$round: … with a nonce" );
	pdt_check( false !== strpos( $box, 'pd_uncounted=1' ), "$round: no-JS link to the inline details" );
	pdt_check( false === strpos( $box, 'pd-uncounted-users' ) && false === strpos( $box, $email_a ), "$round: no user rows in the page" );
	pdt_check( false !== strpos( $box, '&lt;b&gt;1300&lt;/b&gt;' ), "$round: the cached per-«Κατηγορία» counts are shown, escaped" );
}

// No-JS fallback: the same page with ?pd_uncounted=1 renders the rows inline.
list( $html ) = pdt_render_list( array( 'pd_uncounted' => '1' ) );
$box          = pdt_uncounted_box( $html );
pdt_check( false !== strpos( $box, 'pd-uncounted-users' ) && false !== strpos( $box, $email_a ) && false !== strpos( $box, $email_b ), 'pd_uncounted=1: users rendered inline' );
pdt_check( false === strpos( $box, 'data-pd-lazy-url=' ) && (bool) preg_match( '/id="pd-uncounted"[^>]*\sopen/', $html ), 'pd_uncounted=1: box open, nothing left to fetch' );

// ---- the AJAX endpoint --------------------------------------------------------------------
class PDT_Ajax_Die_1300 extends Exception {}

add_filter( 'wp_doing_ajax', '__return_true' );
add_filter(
	'wp_die_ajax_handler',
	static function () {
		return static function () {
			throw new PDT_Ajax_Die_1300();
		};
	}
);

/** Decoded JSON of one call to the endpoint, and the queries it ran. */
function pdt_ajax( $nonce ) {
	$_REQUEST = null === $nonce ? array() : array( 'nonce' => $nonce );
	$_GET     = $_REQUEST;
	$out      = '';
	$queries  = pdt_queries_during(
		static function () use ( &$out ) {
			ob_start();
			try {
				Plandose_Admin_Subscriptions::ajax_uncounted_users();
			} catch ( PDT_Ajax_Die_1300 $e ) {
				unset( $e );
			}
			$out = ob_get_clean();
		}
	);
	$_REQUEST = array();
	$_GET     = array();
	return array( json_decode( $out, true ), $queries );
}

$action = Plandose_Admin_Subscriptions::UNCOUNTED_AJAX_ACTION;

list( $r ) = pdt_ajax( null );
pdt_check( is_array( $r ) && false === $r['success'] && empty( $r['data']['html'] ), 'AJAX: refused without a nonce' );

list( $r ) = pdt_ajax( wp_create_nonce( 'plandose_export_csv' ) );
pdt_check( is_array( $r ) && false === $r['success'], 'AJAX: refused with a nonce of another action' );

// A delegated PlanDose manager without list_users: may open PlanDose, not the accounts.
$editor  = pdt_user( array(), 'editor' );
$cap_fix = static function () {
	return 'edit_posts';
};
add_filter( 'plandose_manage_capability', $cap_fix );
wp_set_current_user( $editor );
list( $r ) = pdt_ajax( wp_create_nonce( $action ) );
pdt_check( is_array( $r ) && false === $r['success'] && empty( $r['data']['html'] ), 'AJAX: refused without list_users (valid nonce)' );
remove_filter( 'plandose_manage_capability', $cap_fix );

// A logged-in account with no PlanDose capability at all.
wp_set_current_user( $other_b );
list( $r ) = pdt_ajax( wp_create_nonce( $action ) );
pdt_check( is_array( $r ) && false === $r['success'], 'AJAX: refused for a non-manager (valid nonce)' );

wp_set_current_user( $pdt_admin );
Plandose_Subscriber_Query::invalidate_subscriber_cache();
list( $r, $queries ) = pdt_ajax( wp_create_nonce( $action ) );
$html                = is_array( $r ) && isset( $r['data']['html'] ) ? (string) $r['data']['html'] : '';
pdt_check( is_array( $r ) && true === $r['success'], 'AJAX: allowed with nonce + capability' );
pdt_check( false !== strpos( $html, $email_a ) && false !== strpos( $html, $email_b ), 'AJAX: returns the uncounted users' );
pdt_check( false === strpos( $html, $email_adm ), 'AJAX: administrators are left out' );
pdt_check( false !== strpos( $html, '&lt;b&gt;1300&lt;/b&gt;' ) && false === strpos( $html, '<b>1300</b>' ), 'AJAX: stored values are escaped' );
pdt_same( 1, count( array_filter( $queries, 'pdt_is_detail_query' ) ), 'AJAX: the detail query runs once' );

$cached = get_transient( $cache_key );
pdt_check( is_array( $cached ) && isset( $cached['ids'], $cached['total'] ) && in_array( $other_a, $cached['ids'], true ) && in_array( $other_b, $cached['ids'], true ), 'detail IDs cached together with the counts' );

list( $r, $queries ) = pdt_ajax( wp_create_nonce( $action ) );
pdt_check( is_array( $r ) && true === $r['success'] && false !== strpos( (string) $r['data']['html'], $email_a ), 'AJAX again: same users' );
pdt_same( 0, count( array_filter( $queries, 'pdt_is_detail_query' ) ), 'AJAX again: IDs come from the cache' );

// uncounted_users() keeps its old contract.
$u = Plandose_Subscriber_Query::uncounted_users( 1000 );
pdt_check( $u['total'] >= 2 && in_array( $other_a, wp_list_pluck( $u['rows'], 'id' ), true ), 'uncounted_users(): total and rows as before' );

// ---- cache invalidation ---------------------------------------------------------------------
/** Fill both caches (counts + IDs, and the KPIs). */
function pdt_warm() {
	Plandose_Subscriber_Query::uncounted_users( 300 );
	Plandose_Subscriber_Query::stats();
}

pdt_warm();
$before = Plandose_Subscriber_Query::uncounted_summary()['total'];
wp_update_user( array( 'ID' => $other_a, 'display_name' => 'PDT renamed 1300', 'user_email' => 'renamed-' . $email_a ) );
pdt_check( false !== get_transient( $cache_key ) && false !== get_transient( $stats_key ), 'profile save (wp_update_user) keeps the caches' );

update_user_meta( $other_a, 'billing_first_name', 'Γιώργος' );
pdt_check( false !== get_transient( $cache_key ), 'unrelated meta keeps the caches' );

update_user_meta( $other_a, $type_key, $pharmacy );
pdt_check( false === get_transient( $cache_key ) && false === get_transient( $stats_key ), 'account_type change drops the caches' );
pdt_same( $before - 1, Plandose_Subscriber_Query::uncounted_summary()['total'], '… and the user is no longer uncounted' );

pdt_warm();
( new WP_User( $other_b ) )->set_role( 'editor' );
pdt_check( false === get_transient( $cache_key ) && false === get_transient( $stats_key ), 'role change (set_role) drops the caches' );

pdt_warm();
wp_update_user( array( 'ID' => $other_b, 'role' => 'administrator' ) );
pdt_check( false === get_transient( $cache_key ), 'role change through wp_update_user drops the caches' );
pdt_same( $before - 2, Plandose_Subscriber_Query::uncounted_summary()['total'], '… and the new administrator is left out' );

pdt_warm();
$new_user = pdt_user( array(), 'subscriber' );
pdt_check( false === get_transient( $cache_key ), 'new account drops the caches' );
pdt_same( $before - 1, Plandose_Subscriber_Query::uncounted_summary()['total'], '… and counts as uncounted' );

pdt_check( false === has_action( 'profile_update', array( 'Plandose_Subscriber_Query', 'invalidate_subscriber_cache_hook' ) ), 'no profile_update invalidation hook' );

wp_set_current_user( 0 );
Plandose_Subscriber_Query::invalidate_subscriber_cache();
pdt_done();
