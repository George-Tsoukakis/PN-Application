<?php
/**
 * 1.27.0: plandose_maybe_upgrade() lock and back-off; get_row() creating a
 * row that a concurrent request inserted first (INSERT IGNORE).
 */
require __DIR__ . '/lib.php';

global $wpdb;

// ---- Upgrade lock / back-off. The version option is restored at the end.
$real_version = get_option( 'plandose_version' );
pdt_defer(
	static function () use ( $real_version ) {
		update_option( 'plandose_version', $real_version );
		delete_option( PLANDOSE_UPGRADE_LOCK );
		delete_transient( PLANDOSE_UPGRADE_BACKOFF );
	}
);

delete_option( PLANDOSE_UPGRADE_LOCK );
delete_transient( PLANDOSE_UPGRADE_BACKOFF );

update_option( 'plandose_version', PLANDOSE_VERSION );
pdt_same( 'current', plandose_maybe_upgrade(), 'same version: nothing to do' );

update_option( 'plandose_version', '0.0.1-pdt' );

// Another request holds the lock: this one does not run dbDelta.
$dbdelta_ran = false;
$spy         = static function ( $q ) use ( &$dbdelta_ran ) {
	if ( is_string( $q ) && preg_match( '/^\s*(CREATE TABLE|SHOW TABLES LIKE)/i', $q ) && false !== stripos( $q, 'plandose' ) ) {
		$dbdelta_ran = true;
	}
	return $q;
};
add_filter( 'query', $spy );

pdt_check( Plandose_Lock::claim( PLANDOSE_UPGRADE_LOCK, time() ), 'test takes the upgrade lock (as a concurrent request would)' );
pdt_same( 'busy', plandose_maybe_upgrade(), 'lock held by another request: busy' );
pdt_same( false, $dbdelta_ran, 'no dbDelta()/table check while another request upgrades' );
pdt_same( '0.0.1-pdt', get_option( 'plandose_version' ), 'version not bumped by the waiting request' );

// A dead holder (lock older than PLANDOSE_UPGRADE_LOCK_TTL) is taken over.
$wpdb->update( $wpdb->options, array( 'option_value' => (string) ( time() - PLANDOSE_UPGRADE_LOCK_TTL - 60 ) ), array( 'option_name' => PLANDOSE_UPGRADE_LOCK ) );
wp_cache_delete( PLANDOSE_UPGRADE_LOCK, 'options' );
pdt_same( 'done', plandose_maybe_upgrade(), 'stale lock is reclaimed and the upgrade runs' );
pdt_same( PLANDOSE_VERSION, get_option( 'plandose_version' ), 'version bumped after the upgrade' );
pdt_same( null, $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", PLANDOSE_UPGRADE_LOCK ) ), 'lock released' );

// A failed upgrade backs off for 10 minutes.
update_option( 'plandose_version', '0.0.1-pdt' );
$break = static function ( $q ) {
	if ( is_string( $q ) && preg_match( '/^\s*SHOW TABLES LIKE/i', $q ) && false !== stripos( $q, 'plandose' ) ) {
		return "SHOW TABLES LIKE 'pdt\\_no\\_such\\_table'";
	}
	return $q;
};
add_filter( 'query', $break );
pdt_same( 'failed', plandose_maybe_upgrade(), 'tables missing after dbDelta: failed' );
remove_filter( 'query', $break );

$timeout = (int) get_option( '_transient_timeout_' . PLANDOSE_UPGRADE_BACKOFF );
pdt_check( false !== get_transient( PLANDOSE_UPGRADE_BACKOFF ) && $timeout > time() + 500 && $timeout <= time() + 600, 'back-off transient set for ~10 minutes' );

$dbdelta_ran = false;
pdt_same( 'failed', plandose_maybe_upgrade(), 'during the back-off: failed without retrying' );
pdt_same( false, $dbdelta_ran, 'no dbDelta() during the back-off' );

// plandose_init() turns that into the admin notice code.
unset( $GLOBALS['plandose_bootstrap_error'] );
plandose_init();
pdt_same( 'db_upgrade_failed', isset( $GLOBALS['plandose_bootstrap_error'] ) ? $GLOBALS['plandose_bootstrap_error'] : null, 'plandose_init() flags db_upgrade_failed → admin notice' );
unset( $GLOBALS['plandose_bootstrap_error'] );

delete_transient( PLANDOSE_UPGRADE_BACKOFF );
pdt_same( 'done', plandose_maybe_upgrade(), 'after the back-off the upgrade is retried and succeeds' );
remove_filter( 'query', $spy );

// ---- get_row(): a concurrent request inserts the row between our SELECT and INSERT.
$uid = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
$wpdb->delete( Plandose_Subscriptions::table_name(), array( 'user_id' => $uid ) );

$other = new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
$race  = static function ( $q ) use ( $other, $uid ) {
	static $done = false;
	if ( ! $done && is_string( $q ) && preg_match( '/^\s*INSERT\s+(IGNORE\s+)?INTO\s+\S*plandose_subscriptions\b/i', $q ) ) {
		$done = true;
		// The "other request" wins the insert, with a counter of 7.
		$other->query( 'INSERT INTO ' . Plandose_Subscriptions::table_name() . " (user_id, status, print_count, count_reset_at) VALUES ({$uid}, 'free', 7, CURDATE())" );
	}
	return $q;
};
add_filter( 'query', $race );

$wpdb->last_error = '';
$row = Plandose_Subscriptions::get_row( $uid );
remove_filter( 'query', $race );
$other->close();

pdt_same( '', $wpdb->last_error, 'no duplicate-key database error' );
pdt_check( is_object( $row ) && 7 === (int) $row->print_count, 'get_row() returns the row the other request created (print_count 7)' );
pdt_same( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Plandose_Subscriptions::table_name() . ' WHERE user_id = %d', $uid ) ), 'exactly one row' );

// And the normal create path still works.
$uid2 = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
$row2 = Plandose_Subscriptions::get_row( $uid2 );
pdt_check( is_object( $row2 ) && 'free' === $row2->status && 0 === (int) $row2->print_count && current_time( 'Y-m-d' ) === $row2->count_reset_at, 'new row: free, 0 prints, reset today' );

pdt_done();
