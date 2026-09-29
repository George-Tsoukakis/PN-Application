<?php
/*
 * 2.15.7 — διαδρομές άρνησης των AJAX endpoints: μέθοδος, nonce, δικαιώματα,
 * πρόσβαση επισκεπτών, καταχώριση hooks. Καμία άρνηση δεν αγγίζει μετρητές,
 * parser ή mailer.
 * php t_ajax_denials.php
 */
require __DIR__ . '/bootajax.php';
$PD = qrrp_test_pdir();
if ( ! defined( 'QRRP_PLUGIN_DIR' ) ) { define( 'QRRP_PLUGIN_DIR', $PD . '/' ); }
function qrrp_asset_version( $p ) { return '1'; }
require_once __DIR__ . '/lib/renderer.php';
qrrp_test_renderer( 'fake' );

$fails = 0;
function check( $label, $ok ) { global $fails; if ( ! $ok ) { $fails++; } echo ( $ok ? 'PASS' : 'FAIL' ), ' ', $label, "\n"; }
function call( $method, $post, $http = 'POST' ) { $_SERVER['REQUEST_METHOD'] = $http; $_POST = $post; try { QRRP_Ajax::$method(); } catch ( JsonExit $e ) { return $e; } return null; }
function reset_state() { $GLOBALS['__nonce_ok'] = true; $GLOBALS['__caps'] = array(); $GLOBALS['__logged_in'] = true; QRRP_Rate_Limiter::$log = array(); QRRP_Mailer::$sent = 0; }
function untouched() { return array() === QRRP_Rate_Limiter::$log && 0 === QRRP_Mailer::$sent; }

$GLOBALS['__options'] = array( 'qrrp_capability' => 'edit_posts', 'qrrp_email_capability' => 'qrrp_pharmacist' );
$PC   = '05012345678900';
$post = array( 'nonce' => 'x', 'raw_data' => "01{$PC}21ABC\x1d10LOT1\x1d17280331", 'pc' => $PC, 'sn' => 'ABC', 'lot' => 'LOT1', 'exp' => '2028-03-31', 'email' => 'a@example.org' );
$endpoints = array( 'parse', 'rebuild', 'send_email' );

foreach ( $endpoints as $ep ) {
	reset_state();
	$e = call( $ep, $post, 'GET' );
	check( "$ep: GET → 405 method_not_allowed, nothing counted", $e && 405 === $e->status && 'method_not_allowed' === $e->data['code'] && untouched() );

	reset_state();
	$GLOBALS['__nonce_ok'] = false;
	$e = call( $ep, $post );
	check( "$ep: bad nonce → 403 invalid_nonce, nothing counted", $e && 403 === $e->status && 'invalid_nonce' === $e->data['code'] && untouched() );

	reset_state();
	$GLOBALS['__caps'] = array( 'edit_posts' => false );
	$e = call( $ep, $post );
	check( "$ep: logged in without tool capability → 403 forbidden, nothing counted", $e && 403 === $e->status && 'forbidden' === $e->data['code'] && untouched() );

	reset_state();
	$GLOBALS['__logged_in'] = false;
	$e = call( $ep, $post );
	check( "$ep: guest while guest access is off → 403 guest_access_disabled", $e && 403 === $e->status && 'guest_access_disabled' === $e->data['code'] && untouched() );
}

/* Email: δικαίωμα εργαλείου αλλά όχι email. */
reset_state();
$GLOBALS['__caps'] = array( 'qrrp_pharmacist' => false );
$e = call( 'send_email', $post );
check( 'send_email: tool capability without email capability → 403 email_forbidden, no mail', $e && 403 === $e->status && 'email_forbidden' === $e->data['code'] && untouched() );

/* Επισκέπτες ανοιχτοί (read), email επισκεπτών κλειστό. */
$GLOBALS['__options'] = array( 'qrrp_capability' => 'read', 'qrrp_allow_guests' => '1', 'qrrp_allow_guest_email' => '0' );
reset_state();
$GLOBALS['__logged_in'] = false;
$e = call( 'send_email', $post );
check( 'send_email: guest with guest email off → 403 guest_email_disabled, no mail', $e && 403 === $e->status && 'guest_email_disabled' === $e->data['code'] && untouched() );

/* Ανοιχτό σε επισκέπτες μόνο με δικαίωμα 'read': με άλλο δικαίωμα η επιλογή δεν ισχύει. */
$GLOBALS['__options'] = array( 'qrrp_capability' => 'edit_posts', 'qrrp_allow_guests' => '1' );
reset_state();
$GLOBALS['__logged_in'] = false;
$e = call( 'parse', $post );
check( "parse: allow_guests=1 but capability ≠ read → still 403 guest_access_disabled", $e && 403 === $e->status && 'guest_access_disabled' === $e->data['code'] );

/* refresh_nonce: χωρίς nonce by design, αλλά POST και έλεγχος actor. */
reset_state();
$e = call( 'refresh_nonce', array(), 'GET' );
check( 'refresh_nonce: GET → 405', $e && 405 === $e->status );
reset_state();
$GLOBALS['__caps'] = array( 'edit_posts' => false );
$e = call( 'refresh_nonce', array() );
check( 'refresh_nonce: logged in without capability → 403 forbidden, no nonce handed out', $e && 403 === $e->status && 'forbidden' === $e->data['code'] );
reset_state();
$GLOBALS['__nonce_ok'] = false;
$e = call( 'refresh_nonce', array() );
check( 'refresh_nonce: works without a valid nonce (its purpose)', $e && 200 === $e->status && ! empty( $e->data['nonce'] ) );

/* Καταχώριση hooks: nopriv μόνο όταν το εργαλείο είναι πραγματικά ανοιχτό. */
$nopriv = static function () { return array_values( array_filter( $GLOBALS['__actions'] ?? array(), static fn( $h ) => 0 === strpos( $h, 'wp_ajax_nopriv_' ) ) ); };
$GLOBALS['__options'] = array( 'qrrp_capability' => 'edit_posts', 'qrrp_allow_guests' => '1' );
$GLOBALS['__actions'] = array(); QRRP_Ajax::init();
check( 'init: guests on but capability ≠ read → no nopriv hooks', array() === $nopriv() );
$GLOBALS['__options'] = array( 'qrrp_capability' => 'read', 'qrrp_allow_guests' => '1', 'qrrp_allow_guest_email' => '0' );
$GLOBALS['__actions'] = array(); QRRP_Ajax::init();
check( 'init: guests on, guest email off → nopriv parse/rebuild/refresh only', array( 'wp_ajax_nopriv_qrrp_parse', 'wp_ajax_nopriv_qrrp_rebuild', 'wp_ajax_nopriv_qrrp_refresh_nonce' ) === $nopriv() );
$GLOBALS['__options']['qrrp_allow_guest_email'] = '1';
$GLOBALS['__actions'] = array(); QRRP_Ajax::init();
check( 'init: guest email on → nopriv send_email added', in_array( 'wp_ajax_nopriv_qrrp_send_email', $nopriv(), true ) );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
