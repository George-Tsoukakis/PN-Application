<?php
/**
 * Free monthly limit: default 30, one-time move of a saved 450. TEST-ONLY.
 */
require __DIR__ . '/lib.php';

global $wpdb;

$pdt_saved_settings = get_option( 'plandose_settings', false );
$pdt_saved_flag     = get_option( Plandose_Settings::FREE_LIMIT_MIGRATION_OPTION, false );
pdt_defer(
	static function () use ( $pdt_saved_settings, $pdt_saved_flag ) {
		if ( false === $pdt_saved_settings ) {
			delete_option( 'plandose_settings' );
		} else {
			update_option( 'plandose_settings', $pdt_saved_settings );
		}
		if ( false === $pdt_saved_flag ) {
			delete_option( Plandose_Settings::FREE_LIMIT_MIGRATION_OPTION );
		} else {
			update_option( Plandose_Settings::FREE_LIMIT_MIGRATION_OPTION, $pdt_saved_flag );
		}
	}
);

$audit_table = Plandose_Subscriptions::audit_table_name();
$audit_count = static function () use ( $wpdb, $audit_table ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$audit_table}` WHERE event = %s", 'free_limit_migrated' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
};

pdt_same( 30, Plandose_Settings::DEFAULT_FREE_MONTHLY_LIMIT, 'class default is 30' );
pdt_same( 30, (int) Plandose_Settings::default_settings()['free_monthly_limit'], 'fresh settings get 30' );

$set = static function ( $limit ) {
	$s                       = get_option( 'plandose_settings', array() );
	$s                       = is_array( $s ) ? $s : array();
	$s['free_monthly_limit'] = $limit;
	update_option( 'plandose_settings', $s );
	delete_option( Plandose_Settings::FREE_LIMIT_MIGRATION_OPTION );
};

// A site still on the old default moves to 30, once, with an audit row —
// even when the first request after the upgrade comes from a pharmacy.
$pdt_prev_user = get_current_user_id();
$pdt_pharmacy  = get_user_by( 'login', 'pharm1' );
if ( $pdt_pharmacy ) {
	wp_set_current_user( $pdt_pharmacy->ID );
}
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
pdt_defer(
	static function () use ( $pdt_prev_user ) {
		wp_set_current_user( $pdt_prev_user );
	}
);
$set( 450 );
$before = $audit_count();
Plandose_Settings::maybe_migrate_free_limit();
pdt_same( 30, (int) get_option( 'plandose_settings' )['free_monthly_limit'], 'saved 450 → 30' );
pdt_same( 30, Plandose_Settings::free_monthly_limit(), 'effective limit is 30' );
pdt_same( $before + 1, $audit_count(), 'the change is in the audit log' );
$pdt_row = $wpdb->get_row( $wpdb->prepare( "SELECT admin_user_id, ip FROM `{$audit_table}` WHERE event = %s ORDER BY id DESC LIMIT 1", 'free_limit_migrated' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
pdt_same( 0, (int) $pdt_row->admin_user_id, 'recorded as a system change, not credited to the requesting user' );
pdt_same( '', (string) $pdt_row->ip, 'no IP for a system change' );
pdt_check( (bool) get_option( Plandose_Settings::FREE_LIMIT_MIGRATION_OPTION ), 'flag set' );

// An admin who sets 450 again afterwards keeps it.
$s                       = get_option( 'plandose_settings' );
$s['free_monthly_limit'] = 450;
update_option( 'plandose_settings', $s );
Plandose_Settings::maybe_migrate_free_limit();
pdt_same( 450, Plandose_Settings::free_monthly_limit(), 'runs once: a later deliberate 450 stays' );
pdt_same( $before + 1, $audit_count(), '… and nothing new is audited' );

// A custom figure is never touched.
$set( 200 );
Plandose_Settings::maybe_migrate_free_limit();
pdt_same( 200, Plandose_Settings::free_monthly_limit(), 'custom 200 stays' );
pdt_check( (bool) get_option( Plandose_Settings::FREE_LIMIT_MIGRATION_OPTION ), '… and the check is flagged done' );

// No settings row yet (fresh install): nothing to change, flag set.
// Put back at once: other tests save and restore the row, and would store
// an empty string if a crash here left it missing.
$row = get_option( 'plandose_settings' );
delete_option( 'plandose_settings' );
delete_option( Plandose_Settings::FREE_LIMIT_MIGRATION_OPTION );
Plandose_Settings::maybe_migrate_free_limit();
$fresh_row   = get_option( 'plandose_settings', false );
$fresh_limit = Plandose_Settings::free_monthly_limit();
update_option( 'plandose_settings', $row );
pdt_check( false === $fresh_row, 'fresh install: no settings row written' );
pdt_same( 30, $fresh_limit, 'fresh install: effective limit is 30' );

// The limit is enforced at 30 by the atomic counter.
$set( Plandose_Settings::DEFAULT_FREE_MONTHLY_LIMIT );
$uid   = pdt_user( array( 'account_type' => 'Φαρμακείο' ) );
$today = current_time( 'Y-m-d' );
Plandose_Subscriptions::get_row( $uid, true );
$ok = 0;
for ( $i = 0; $i < 31; $i++ ) {
	if ( true === Plandose_Subscriptions::try_increment_print_count( $uid, Plandose_Settings::free_monthly_limit(), $today ) ) {
		++$ok;
	}
}
pdt_same( 30, $ok, 'a Free account gets exactly 30 prints in a month' );

pdt_done();
