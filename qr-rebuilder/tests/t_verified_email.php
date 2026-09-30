<?php
/*
 * 2.16.0 — email μόνο από εγκεκριμένους φαρμακοποιούς (κλείνει το spam relay
 * των αυτο-δηλωμένων λογαριασμών): δικαίωμα, έγκριση στο προφίλ, μετάπτωση.
 * php t_verified_email.php
 */
require __DIR__ . '/boot.php';
$PD = getenv( 'PDIR' ) ?: dirname( __DIR__ ) . '/qr-rebuilder-pro';
if ( ! defined( 'QRRP_VERSION' ) ) { define( 'QRRP_VERSION', '2.16.0' ); }
if ( ! defined( 'QRRP_DEFAULT_EMAIL_CAPABILITY' ) ) { define( 'QRRP_DEFAULT_EMAIL_CAPABILITY', 'qrrp_verified_pharmacist' ); }

$GLOBALS['__umeta']  = array();
$GLOBALS['__admins'] = array( 1 );
$GLOBALS['__cap']    = true;
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $k ) ); }
function wp_unslash( $v ) { return $v; }
function esc_html( $s ) { return htmlspecialchars( $s ); }
function esc_html__( $s, $d = null ) { return htmlspecialchars( $s ); }
function esc_html_e( $s, $d = null ) { echo htmlspecialchars( $s ); }
function esc_url( $u ) { return $u; }
function checked( $a, $b = true ) { echo $a == $b ? ' checked' : ''; }
function wp_nonce_field( $a, $n ) { echo '<input name="' . $n . '" value="nonce:' . $a . '">'; }
function wp_verify_nonce( $n, $a ) { return 'nonce:' . $a === $n; }
function get_edit_user_link( $u ) { return 'https://example.gr/wp-admin/user-edit.php?user_id=' . $u; }
function user_can( $u, $c ) { return 'manage_options' === $c && in_array( (int) $u, $GLOBALS['__admins'], true ); }
function current_user_can( $c, ...$a ) { return $GLOBALS['__cap']; }
function get_user_meta( $u, $k, $single = false ) { return $GLOBALS['__umeta'][ $u ][ $k ] ?? ''; }
function update_user_meta( $u, $k, $v ) { $GLOBALS['__umeta'][ $u ][ $k ] = $v; return true; }
function delete_user_meta( $u, $k ) { unset( $GLOBALS['__umeta'][ $u ][ $k ] ); return true; }
function update_option( $o, $v ) { $old = $GLOBALS['__options'][ $o ] ?? null; $GLOBALS['__options'][ $o ] = $v; return $old !== $v; }
require $PD . '/includes/functions-access.php';
require $PD . '/includes/functions-upgrade.php';
require $PD . '/includes/class-qrrp-admin.php';

$fails = 0;
function check( $label, $ok ) { global $fails; if ( ! $ok ) { $fails++; } echo ( $ok ? 'PASS' : 'FAIL' ), ' ', $label, "\n"; }
function caps_for( $uid, $cap, $allcaps = array() ) { return qrrp_grant_pharmacist_cap( $allcaps, array( $cap ), array(), (object) array( 'ID' => $uid ) ); }

/* Αυτο-δηλωμένο «Φαρμακείο» (χρήστης 5), χωρίς έγκριση. */
$GLOBALS['__umeta'][5]['account_type'] = 'Φαρμακείο';

check( 'default email capability is qrrp_verified_pharmacist', 'qrrp_verified_pharmacist' === qrrp_default_email_capability() );
check( 'qrrp_verified_pharmacist is an allowed email capability', in_array( 'qrrp_verified_pharmacist', qrrp_allowed_email_capabilities(), true ) );
check( 'self-declared pharmacy keeps qrrp_pharmacist', ! empty( caps_for( 5, 'qrrp_pharmacist' )['qrrp_pharmacist'] ) );
check( 'self-declared pharmacy is NOT verified', empty( caps_for( 5, 'qrrp_verified_pharmacist' )['qrrp_verified_pharmacist'] ) );
check( 'administrator is always verified', ! empty( caps_for( 1, 'qrrp_verified_pharmacist', array( 'manage_options' => true ) )['qrrp_verified_pharmacist'] ) );
check( 'cap only computed when requested', ! isset( caps_for( 5, 'read' )['qrrp_verified_pharmacist'] ) );

/* Έγκριση από διαχειριστή στο προφίλ. */
$_POST = array( 'qrrp_verified_pharmacist_nonce' => 'nonce:qrrp_verified_pharmacist_5', 'qrrp_verified_pharmacist' => '1' );
QRRP_Admin::save_verified_pharmacist_field( 5 );
check( 'admin approves on profile: meta set', '1' === get_user_meta( 5, 'qrrp_verified_pharmacist', true ) );
check( 'approved pharmacy is verified', ! empty( caps_for( 5, 'qrrp_verified_pharmacist' )['qrrp_verified_pharmacist'] ) );

/* Λάθος nonce (π.χ. nonce άλλου χρήστη) ή μη διαχειριστής: καμία αλλαγή. */
$_POST = array( 'qrrp_verified_pharmacist_nonce' => 'nonce:qrrp_verified_pharmacist_6' );
QRRP_Admin::save_verified_pharmacist_field( 5 );
check( 'wrong nonce: approval unchanged', '1' === get_user_meta( 5, 'qrrp_verified_pharmacist', true ) );
$GLOBALS['__cap'] = false;
$_POST = array( 'qrrp_verified_pharmacist_nonce' => 'nonce:qrrp_verified_pharmacist_9', 'qrrp_verified_pharmacist' => '1' );
QRRP_Admin::save_verified_pharmacist_field( 9 );
check( 'non-admin (own profile) cannot self-approve', '' === get_user_meta( 9, 'qrrp_verified_pharmacist', true ) );
ob_start(); QRRP_Admin::render_verified_pharmacist_field( (object) array( 'ID' => 9 ) ); $html = ob_get_clean();
check( 'non-admin does not see the approval field', '' === $html );
$GLOBALS['__cap'] = true;
ob_start(); QRRP_Admin::render_verified_pharmacist_field( (object) array( 'ID' => 5 ) ); $html = ob_get_clean();
check( 'admin sees the field, checked, with nonce', false !== strpos( $html, 'name="qrrp_verified_pharmacist"' ) && false !== strpos( $html, ' checked' ) && false !== strpos( $html, 'nonce:qrrp_verified_pharmacist_5' ) );

/* Αφαίρεση έγκρισης: nonce χωρίς το checkbox. */
$_POST = array( 'qrrp_verified_pharmacist_nonce' => 'nonce:qrrp_verified_pharmacist_5' );
QRRP_Admin::save_verified_pharmacist_field( 5 );
check( 'unchecking revokes approval', '' === get_user_meta( 5, 'qrrp_verified_pharmacist', true ) );

/* Στήλη λίστας χρηστών. */
check( 'users column: pending for self-declared pharmacy', false !== strpos( QRRP_Admin::render_users_column( '', 'qrrp_email', 5 ), 'Αναμένει έγκριση' ) );
check( 'users column: approved for admin', false !== strpos( QRRP_Admin::render_users_column( '', 'qrrp_email', 1 ), 'Εγκεκριμένος' ) );
check( 'users column: dash for customer', '—' === QRRP_Admin::render_users_column( '', 'qrrp_email', 8 ) );
check( 'users column: other columns untouched', 'x' === QRRP_Admin::render_users_column( 'x', 'email', 5 ) );

/* Μετάπτωση 2.16.0: μόνο προς το αυστηρότερο. */
$cases = array(
	array( 'qrrp_pharmacist', 'read', '0', 'qrrp_verified_pharmacist', 'pharmacist → verified' ),
	array( '', 'read', '0', 'qrrp_verified_pharmacist', "'' + tool read, no guest email → verified" ),
	array( '', 'qrrp_pharmacist', '0', 'qrrp_verified_pharmacist', "'' + tool pharmacist → verified" ),
	array( '', 'read', '1', '', "'' + guest email on (explicit «free») stays" ),
	array( '', 'manage_options', '0', '', "'' + tool admins only stays" ),
	array( 'manage_options', 'read', '0', 'manage_options', 'admins only stays' ),
	array( 'edit_posts', 'read', '0', 'edit_posts', 'edit_posts stays' ),
);
foreach ( $cases as $c ) {
	$GLOBALS['__options'] = array( 'qrrp_email_capability' => $c[0], 'qrrp_capability' => $c[1], 'qrrp_allow_guest_email' => $c[2] );
	qrrp_maybe_migrate_verified_email( '2.15.7' );
	check( 'migration: ' . $c[4], $c[3] === $GLOBALS['__options']['qrrp_email_capability'] );
}
$GLOBALS['__options'] = array( 'qrrp_email_capability' => 'qrrp_pharmacist', 'qrrp_capability' => 'read' );
qrrp_maybe_migrate_verified_email( '2.16.0' );
check( 'migration runs once (not from 2.16.0)', 'qrrp_pharmacist' === $GLOBALS['__options']['qrrp_email_capability'] );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
