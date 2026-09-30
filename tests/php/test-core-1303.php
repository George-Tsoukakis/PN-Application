<?php
/**
 * 1.30.3: core review fixes, against the real test WordPress + MariaDB.
 * TEST-ONLY.
 *
 * - the upgrade lock's stale check reads the database, so a stale
 *   'notoptions' entry cannot keep a dead lock alive; a lock that cannot
 *   be taken over past its TTL shows the failure notice;
 * - deleting a user whose subscription row could not be read retires the
 *   row instead of deleting it (and its invoice list with it);
 * - a lapsed Pro card is not shown as «expiring»;
 * - an explicit 0 / invalid «Ημέρες» value is refused, not turned into
 *   the default 365 days.
 */
require __DIR__ . '/lib.php';

global $wpdb;

ini_set( 'error_log', '/dev/null' ); // phpcs:ignore -- expected log lines.

$table = Plandose_Subscriptions::table_name();

/** Run an admin-post handler; its redirect / wp_die come back as a string. */
function pdt1303_run( $callback ) {
	$redirect = static function ( $location ) {
		throw new RuntimeException( 'redirect:' . $location );
	};
	$die      = static function () {
		return static function ( $message, $title = '', $args = array() ) {
			throw new RuntimeException( 'die:' . ( is_array( $args ) && isset( $args['response'] ) ? $args['response'] : '' ) . ':' . wp_strip_all_tags( (string) $message ) );
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

/* ---- 1. upgrade lock: DB-authoritative stale check ------------------------- */

$version_before = get_option( 'plandose_version' );
pdt_defer(
	static function () use ( $version_before ) {
		delete_option( PLANDOSE_UPGRADE_LOCK );
		delete_transient( PLANDOSE_UPGRADE_BACKOFF );
		update_option( 'plandose_version', false === $version_before ? PLANDOSE_VERSION : $version_before );
		unset( $GLOBALS['plandose_bootstrap_error'] );
	}
);

$lock_row = static function () use ( $wpdb ) {
	return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", PLANDOSE_UPGRADE_LOCK ) ); // phpcs:ignore
};

/** Plant a lock row as a dead holder left it, plus a stale 'notoptions' entry saying it does not exist. */
$plant_stale_lock = static function ( $since ) use ( $wpdb ) {
	delete_option( PLANDOSE_UPGRADE_LOCK );
	$wpdb->insert( $wpdb->options, array( 'option_name' => PLANDOSE_UPGRADE_LOCK, 'option_value' => (string) $since, 'autoload' => 'off' ) ); // phpcs:ignore
	wp_cache_delete( PLANDOSE_UPGRADE_LOCK, 'options' );
	$notoptions = wp_cache_get( 'notoptions', 'options' );
	$notoptions = is_array( $notoptions ) ? $notoptions : array();
	$notoptions[ PLANDOSE_UPGRADE_LOCK ] = true;
	wp_cache_set( 'notoptions', $notoptions, 'options' );
};

pdt_check( method_exists( 'Plandose_Lock', 'held_value' ), 'Plandose_Lock::held_value() exists' );

$stale = time() - PLANDOSE_UPGRADE_LOCK_TTL - 60;
$plant_stale_lock( $stale );
pdt_same( false, get_option( PLANDOSE_UPGRADE_LOCK ), 'precondition: get_option() believes the lock row is absent (stale notoptions)' );
pdt_same( $stale, (int) Plandose_Lock::held_value( PLANDOSE_UPGRADE_LOCK ), 'held_value() reads the row from the database anyway' );
$plant_stale_lock( $stale );
unset( $GLOBALS['plandose_bootstrap_error'] );
pdt_same( 'done', plandose_maybe_upgrade( true ), 'a dead lock hidden by a stale notoptions entry is still taken over' );
pdt_same( null, $lock_row(), '… and released afterwards' );
pdt_check( empty( $GLOBALS['plandose_bootstrap_error'] ), '… with no failure notice' );

// A live lock (inside the TTL) stays 'busy', silently.
$plant_stale_lock( time() - 60 );
pdt_same( 'busy', plandose_maybe_upgrade( true ), 'a live lock: busy' );
pdt_check( empty( $GLOBALS['plandose_bootstrap_error'] ), '… and no failure notice' );
pdt_check( null !== $lock_row(), '… the holder keeps its lock' );

// A dead lock that cannot be taken over (the UPDATE fails): still 'busy',
// but the admin now gets the failure notice.
$plant_stale_lock( $stale );
$break_cas = static function ( $q ) {
	if ( is_string( $q ) && 0 === stripos( ltrim( $q ), 'UPDATE' ) && false !== strpos( $q, PLANDOSE_UPGRADE_LOCK ) ) {
		return 'UPDATE pdt_no_such_table_1303 SET x = 1';
	}
	return $q;
};
add_filter( 'query', $break_cas );
$wpdb->suppress_errors( true );
$stuck = plandose_maybe_upgrade( true );
$wpdb->suppress_errors( false );
remove_filter( 'query', $break_cas );
pdt_same( 'busy', $stuck, 'lock past its TTL that cannot be taken over: busy (existing tables stay in use)' );
pdt_same( 'db_upgrade_failed', isset( $GLOBALS['plandose_bootstrap_error'] ) ? $GLOBALS['plandose_bootstrap_error'] : null, '… and the failure notice is raised' );
wp_set_current_user( pdt_user( array(), 'administrator' ) );
ob_start();
plandose_render_bootstrap_notice();
$notice = ob_get_clean();
pdt_check( false !== strpos( $notice, 'notice-error' ), '… which the admin notice renders' );
unset( $GLOBALS['plandose_bootstrap_error'] );
wp_set_current_user( 0 );
delete_option( PLANDOSE_UPGRADE_LOCK );
wp_cache_delete( PLANDOSE_UPGRADE_LOCK, 'options' );

/* ---- 2. user deletion: a failed read never deletes the row ----------------- */

$victim = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
Plandose_Subscriptions::get_row( $victim );
$wpdb->update( $table, array( 'invoices' => wp_json_encode( array( 'kept-1303.pdf' ) ) ), array( 'user_id' => $victim ) ); // phpcs:ignore

$break_read = static function ( $q ) use ( $table ) {
	if ( is_string( $q ) && 0 === stripos( ltrim( $q ), 'SELECT' ) && false !== strpos( $q, $table ) ) {
		return 'SELECT * FROM pdt_no_such_table_1303';
	}
	return $q;
};
add_filter( 'query', $break_read );
$wpdb->suppress_errors( true );
Plandose_Admin::handle_user_deleted( $victim );
$wpdb->suppress_errors( false );
remove_filter( 'query', $break_read );

$kept = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $victim ) ); // phpcs:ignore
pdt_check( null !== $kept, 'failed read on user deletion: the subscription row is NOT deleted' );
pdt_same( array( 'kept-1303.pdf' ), $kept ? json_decode( (string) $kept->invoices, true ) : null, '… and its invoice list is intact' );

// Unchanged: a readable row without invoices is removed completely.
$plain = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
Plandose_Subscriptions::get_row( $plain );
Plandose_Admin::handle_user_deleted( $plain );
pdt_same( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $plain ) ), 'readable row without invoices: still deleted' ); // phpcs:ignore

/* ---- 3. lapsed Pro is not «expiring» --------------------------------------- */

$n = Plandose_Settings::expiring_days();
pdt_same( '', pdt_call( 'Plandose_Admin_Subscriptions', 'expiry_state', null ), 'no end date: no state' );
pdt_same( 'expired', pdt_call( 'Plandose_Admin_Subscriptions', 'expiry_state', -3 ), 'lapsed (days_left < 0): expired, not expiring' );
pdt_same( 'expiring', pdt_call( 'Plandose_Admin_Subscriptions', 'expiry_state', 0 ), 'expires today: expiring' );
pdt_same( 'expiring', pdt_call( 'Plandose_Admin_Subscriptions', 'expiry_state', $n ), 'last day of the window: expiring' );
pdt_same( '', pdt_call( 'Plandose_Admin_Subscriptions', 'expiry_state', $n + 1 ), 'past the window: no state' );

// The rendered card agrees.
$admin  = pdt_user( array(), 'administrator' );
$lapsed = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
Plandose_Subscriptions::get_row( $lapsed );
$wpdb->update( $table, array( 'status' => 'pro', 'sub_end_date' => current_datetime()->modify( '-3 days' )->format( 'Y-m-d' ) ), array( 'user_id' => $lapsed ) ); // phpcs:ignore
Plandose_Subscriber_Query::invalidate_subscriber_cache();
wp_set_current_user( $admin );
$_GET = array(
	'page' => 'plandose-subscriptions',
	's'    => get_userdata( $lapsed )->user_email,
);
ob_start();
$render = pdt1303_run( array( 'Plandose_Admin_Subscriptions', 'render_subscriptions_page' ) );
$html   = ob_get_clean();
$_GET   = array();
pdt_same( 'returned', $render, 'subscriptions page renders' );
pdt_check( false !== strpos( $html, get_userdata( $lapsed )->user_email ), '… with the lapsed pharmacy card' );
pdt_check( 1 === preg_match( '/<dd class="pd-muted">\s*έληξε πριν 3 ημέρες/u', $html ), '… its end date is muted and reads «έληξε πριν 3 ημέρες»' );
pdt_check( 0 === preg_match( '/<dd class="pd-danger">\s*έληξε/u', $html ), '… and is not red as «expiring»' );

/* ---- 4. explicit custom_days is validated ---------------------------------- */

$_SERVER['REQUEST_METHOD'] = 'POST';
$pharmacy = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
Plandose_Subscriptions::get_row( $pharmacy );

$post_pro = static function ( $user_id, $days ) {
	$_POST = array(
		'user_id'        => (string) $user_id,
		'pd_action'      => 'make_pro',
		'expected_state' => Plandose_Subscriptions::state_token( Plandose_Subscriptions::get_row( $user_id, false ) ),
		'_wpnonce'       => wp_create_nonce( 'plandose_subscription_action' ),
	);
	if ( null !== $days ) {
		$_POST['custom_days'] = $days;
	}
	$_REQUEST = $_POST;

	return pdt1303_run( array( 'Plandose_Admin_Subscriptions', 'handle_subscription_action' ) );
};

foreach ( array( '0', '-5', 'abc', '', '1.5' ) as $bad ) {
	$out = $post_pro( $pharmacy, $bad );
	pdt_check( false !== strpos( $out, 'plandose_result=invalid_days' ), 'custom_days ' . var_export( $bad, true ) . ' → invalid_days (' . $out . ')' );
	pdt_same( 'free|', Plandose_Subscriptions::state_token( Plandose_Subscriptions::get_row( $pharmacy, false ) ), '… nothing granted' );
}

$out = $post_pro( $pharmacy, '10' );
pdt_check( false !== strpos( $out, 'plandose_result=success' ), 'custom_days 10 → success' );
pdt_same( current_datetime()->modify( '+9 days' )->format( 'Y-m-d' ), (string) Plandose_Subscriptions::get_row( $pharmacy, false )->sub_end_date, '… exactly 10 days granted' );

$fresh = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
Plandose_Subscriptions::get_row( $fresh );
$out = $post_pro( $fresh, null );
pdt_check( false !== strpos( $out, 'plandose_result=success' ), 'no custom_days field → success' );
$default = (int) pdt_call( 'Plandose_Admin_Subscriptions', 'effective_pro_days' );
pdt_same( current_datetime()->modify( '+' . ( $default - 1 ) . ' days' )->format( 'Y-m-d' ), (string) Plandose_Subscriptions::get_row( $fresh, false )->sub_end_date, '… the configured default is granted' );

$_GET = array( 'plandose_result' => 'invalid_days' );
ob_start();
Plandose_Admin_Subscriptions::render_action_notice();
$notice = ob_get_clean();
$_GET   = array();
pdt_check( false !== strpos( $notice, 'notice-error' ), 'invalid_days has an error notice' );

$_POST = $_REQUEST = array();
wp_set_current_user( 0 );

pdt_done();
