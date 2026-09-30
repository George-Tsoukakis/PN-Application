<?php
/**
 * 1.30.2: subscriptions / settings review fixes, against the real test
 * WordPress + MariaDB. TEST-ONLY.
 *
 * - update() never INSERTs a row, and stores only clean invoice filenames;
 * - the «unarchived months» list survives a concurrent writer (lock +
 *   fresh read), and a retry keeps a month added meanwhile;
 * - deny_access is refused for a non-pharmacy;
 * - the account-type profile field is unslashed once;
 * - a refused «selected pages» save does not also say «αποθηκεύτηκαν»;
 * - small ones: date filter delegate, array text setting, keyset export,
 *   uncounted cache lifetime.
 */
require __DIR__ . '/lib.php';

global $wpdb;

$table = Plandose_Subscriptions::table_name();
$opt   = Plandose_Subscriptions::UNARCHIVED_OPTION;
$lock  = Plandose_Subscriptions::UNARCHIVED_LOCK;

/** Run an admin-post handler; its redirect / wp_die come back as a string. */
function pdt1302_run( $callback ) {
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

ini_set( 'error_log', '/dev/null' ); // phpcs:ignore -- expected log lines.

$admin = pdt_user( array(), 'administrator' );

// ---- update(): read-only existence check ----------------------------------------
$no_row = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
pdt_same( false, Plandose_Subscriptions::update( $no_row, array( 'print_count' => 3 ) ), 'update() on a user with no row → false' );
pdt_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $no_row ) ), '… and no row was INSERTed' );

// ---- update(): invoice entries ---------------------------------------------------
$inv = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
Plandose_Subscriptions::get_row( $inv );
pdt_same(
	true,
	Plandose_Subscriptions::update( $inv, array( 'invoices' => wp_json_encode( array( 'good-1.pdf', '../../evil.pdf', 'sub/dir.pdf', 42, '17', 'with space.pdf', '' ) ) ) ),
	'update() with mixed invoice entries succeeds'
);
$stored = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT invoices FROM {$table} WHERE user_id = %d", $inv ) ), true );
pdt_same( array( 'good-1.pdf', 42, '17' ), $stored, 'only clean bare filenames and numeric legacy IDs are stored (no ../, no dirs, not «cleaned»)' );

// ---- unarchived months: fresh read + lock ----------------------------------------
$saved_opt = get_option( $opt, null );
pdt_defer(
	static function () use ( $opt, $saved_opt, $lock ) {
		delete_option( $lock );
		null === $saved_opt ? delete_option( $opt ) : update_option( $opt, $saved_opt, false );
	}
);
delete_option( $opt );
delete_option( $lock );

$ua = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
$ub = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );

pdt_call( 'Plandose_Subscriptions', 'remember_unarchived', $ua, '2020-01', 5 );
Plandose_Subscriptions::unarchived_months(); // This request now holds a cached copy.

// Another request adds a month behind this one's back (straight SQL).
$other = get_option( $opt );
$other[] = array( 'user_id' => $ub, 'ym' => '2020-02', 'prints' => 9, 'at' => time() );
$wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $other ) ), array( 'option_name' => $opt ) );

pdt_call( 'Plandose_Subscriptions', 'remember_unarchived', $ua, '2020-03', 4 );
wp_cache_delete( $opt, 'options' );
$months = wp_list_pluck( Plandose_Subscriptions::unarchived_months(), 'ym' );
pdt_same( array( '2020-01', '2020-02', '2020-03' ), $months, 'a month written by another request is kept (read fresh, not from the cached copy)' );
pdt_same( false, get_option( $lock, false ), 'the lock is released' );

// A dead holder's lock is reclaimed after the TTL.
Plandose_Lock::claim( $lock, time() - Plandose_Subscriptions::UNARCHIVED_LOCK_TTL - 5 );
$t0 = microtime( true );
pdt_call( 'Plandose_Subscriptions', 'remember_unarchived', $ub, '2020-04', 1 );
wp_cache_delete( $opt, 'options' );
pdt_check( in_array( '2020-04', wp_list_pluck( Plandose_Subscriptions::unarchived_months(), 'ym' ), true ), 'stale lock reclaimed: the month is written' );
pdt_check( microtime( true ) - $t0 < 2, '… without waiting out the retries' );
wp_cache_delete( $lock, 'options' );
pdt_same( false, get_option( $lock, false ), '… and the lock released' );

// A live holder: the write waits, gives up, and still writes (best effort).
Plandose_Lock::claim( $lock, time() );
pdt_call( 'Plandose_Subscriptions', 'remember_unarchived', $ub, '2020-05', 2 );
wp_cache_delete( $opt, 'options' );
pdt_check( in_array( '2020-05', wp_list_pluck( Plandose_Subscriptions::unarchived_months(), 'ym' ), true ), 'live lock held elsewhere: the figure is still kept' );
wp_cache_delete( $lock, 'options' );
pdt_check( false !== get_option( $lock, false ), '… and the other holder\'s lock is not removed' );
delete_option( $lock );

// retry_unarchived(): a month added while the archive runs stays.
delete_option( $opt );
pdt_call( 'Plandose_Subscriptions', 'remember_unarchived', $ua, '2021-01', 3 );
$added  = false;
$inject = static function ( $query ) use ( &$added, $wpdb, $opt, $ub ) {
	if ( ! $added && false !== stripos( $query, Plandose_Subscriptions::history_table_name() ) && 0 === stripos( ltrim( $query ), 'INSERT' ) ) {
		$added = true;
		$list  = maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $opt ) ) );
		$list[] = array( 'user_id' => $ub, 'ym' => '2021-02', 'prints' => 6, 'at' => time() );
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", maybe_serialize( $list ), $opt ) );
	}
	return $query;
};
add_filter( 'query', $inject );
$res = Plandose_Subscriptions::retry_unarchived();
remove_filter( 'query', $inject );
pdt_check( $added, 'a concurrent month was injected during the archive' );
pdt_same( 1, $res['archived'], 'retry archived the waiting month' );
wp_cache_delete( $opt, 'options' );
pdt_same( array( '2021-02' ), wp_list_pluck( Plandose_Subscriptions::unarchived_months(), 'ym' ), '… and kept the month added meanwhile' );

// ---- deny_access refused for a non-pharmacy -------------------------------------
$customer = pdt_user( array( 'account_type' => 'Πελάτης' ) );
$pharmacy = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
wp_set_current_user( $admin );
$_SERVER['REQUEST_METHOD'] = 'POST';

$post_action = static function ( $user_id, $action ) {
	$_POST = $_REQUEST = array(
		'user_id'   => (string) $user_id,
		'pd_action' => $action,
		'_wpnonce'  => wp_create_nonce( 'plandose_subscription_action' ),
	);
	return pdt1302_run( array( 'Plandose_Admin_Subscriptions', 'handle_subscription_action' ) );
};

$out = $post_action( $customer, 'deny_access' );
pdt_check( false !== strpos( $out, 'plandose_result=not_pharmacy' ), 'deny_access on a non-pharmacy → not_pharmacy (' . $out . ')' );
pdt_same( '', Plandose_Access::get_manual_access( $customer ), '… and nothing stored' );

$out = $post_action( $pharmacy, 'deny_access' );
pdt_check( false !== strpos( $out, 'plandose_result=success' ), 'deny_access on a pharmacy still works (' . $out . ')' );
pdt_same( 'deny', Plandose_Access::get_manual_access( $pharmacy ), '… stored' );

update_user_meta( $customer, Plandose_Access::MANUAL_ACCESS_META_KEY, 'allow' );
$out = $post_action( $customer, 'reset_access' );
pdt_check( false !== strpos( $out, 'plandose_result=success' ), 'reset_access stays open for a non-pharmacy (' . $out . ')' );
pdt_same( '', Plandose_Access::get_manual_access( $customer ), '… override cleared' );

// ---- account-type field unslashed once -------------------------------------------
$target  = pdt_user( array( 'account_type' => 'Πελάτης' ) );
$pending = new ReflectionProperty( 'Plandose_Account_Type', 'pending' );
$pending->setAccessible( true );

$submit_type = static function ( $raw ) use ( $target, $pending ) {
	$list = $pending->getValue();
	unset( $list[ $target ] );
	$pending->setValue( null, $list );

	$_POST = wp_slash(
		array(
			'plandose_account_type'             => $raw,
			Plandose_Account_Type::NONCE_FIELD => wp_create_nonce( Plandose_Account_Type::NONCE_ACTION . '_' . $target ),
		)
	);
	Plandose_Account_Type::save_profile_field( $target );
	$list = $pending->getValue();

	return isset( $list[ $target ] ) ? $list[ $target ] : null;
};

pdt_same( 'Φαρμακείο', $submit_type( 'Φαρμακείο' ), 'a plain «Φαρμακείο» is accepted' );
pdt_same( null, $submit_type( '\\Φαρμακείο' ), 'a backslash-prefixed value is refused (not unslashed twice into «Φαρμακείο»)' );
$pending->setValue( null, array() );

// ---- refused «selected pages» save: no updated=1 ---------------------------------
$saved_settings = get_option( 'plandose_settings', null );
pdt_defer(
	static function () use ( $saved_settings ) {
		null === $saved_settings ? delete_option( 'plandose_settings' ) : update_option( 'plandose_settings', $saved_settings );
	}
);

$save = static function ( $extra ) {
	$current = Plandose_Settings::settings();
	$post    = array_merge(
		$current,
		array(
			'invoice_mimes' => $current['invoice_mimes'],
			'hidden_roles'  => $current['hidden_roles'],
			'_wpnonce'      => wp_create_nonce( 'plandose_save_settings' ),
		),
		$extra
	);
	$_POST = $_REQUEST = wp_slash( $post );

	return pdt1302_run( array( 'Plandose_Admin_Settings', 'handle_save_settings' ) );
};

$out = $save( array( 'display_scope' => 'everywhere', 'display_pages' => array() ) );
pdt_check( false !== strpos( $out, 'updated=1' ), 'a normal save redirects with updated=1 (' . $out . ')' );

$out = $save( array( 'display_scope' => 'selected', 'display_pages' => array() ) );
pdt_check( 0 === strpos( $out, 'redirect:' ) && false === strpos( $out, 'updated=1' ), 'a refused «selected pages» save redirects WITHOUT updated=1 (' . $out . ')' );
pdt_check( (bool) get_transient( Plandose_Settings::SCOPE_REFUSED_TRANSIENT . $admin ), '… and the refusal notice is queued' );
pdt_same( 'everywhere', Plandose_Settings::setting( 'display_scope' ), '… the previous scope kept' );
delete_transient( Plandose_Settings::SCOPE_REFUSED_TRANSIENT . $admin );

// ---- small ones --------------------------------------------------------------------
pdt_same( '2026-02-28', pdt_call( 'Plandose_Admin_Subscriptions', 'sanitize_ymd_date', '2026-02-28' ), 'date filter: valid date kept' );
pdt_same( '', pdt_call( 'Plandose_Admin_Subscriptions', 'sanitize_ymd_date', '2026-02-31' ), 'date filter: impossible date refused' );
pdt_same( '', pdt_call( 'Plandose_Admin_Subscriptions', 'sanitize_ymd_date', array( '2026-02-28' ) ), 'date filter: array refused' );

$notices = array();
set_error_handler(
	static function ( $no, $str ) use ( &$notices ) {
		$notices[] = $str;
		return true;
	}
);
$clean = Plandose_Settings::sanitize_settings( array( 'login_page' => array( 'x' ), 'button_text' => array( 'y' ) ), 'read' );
restore_error_handler();
pdt_same( array(), $notices, 'array text settings: no «Array to string» notice' );
pdt_same( '', $clean['login_page'], '… login_page falls back to its default' );

// Keyset batches of the export cover the same subscribers as the list.
$all = Plandose_Subscriber_Query::query_subscribers( array( 'per_page' => 1000, 'with_total' => true ) );
$ids = array();
$after = 0;
do {
	$batch = Plandose_Subscriber_Query::query_subscribers( array( 'after_id' => $after, 'per_page' => 2, 'with_total' => false ) );
	foreach ( $batch['items'] as $item ) {
		$ids[] = (int) $item->user->ID;
	}
	$more  = $batch['last_id'] > $after;
	$after = $batch['last_id'];
} while ( $more );
$list_ids = array_map( static function ( $item ) {
	return (int) $item->user->ID;
}, $all['items'] );
sort( $list_ids );
pdt_same( $list_ids, $ids, 'keyset export batches: every subscriber once, in ID order' );

delete_transient( Plandose_Subscriber_Query::UNCOUNTED_CACHE_KEY );
Plandose_Subscriber_Query::uncounted_summary();
$timeout = (int) get_option( '_transient_timeout_' . Plandose_Subscriber_Query::UNCOUNTED_CACHE_KEY );
pdt_check( ! wp_using_ext_object_cache() ? $timeout - time() > 30 * MINUTE_IN_SECONDS : true, 'uncounted cache lives about an hour' );

$_POST = $_REQUEST = array();
wp_set_current_user( 0 );

pdt_done();
