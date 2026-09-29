<?php
/*
 * 2.15.3 — fixes 2 & 3: επισκέπτες.
 *   2: provenance «user_declared» για επισκέπτη (όχι «scan»), NO_MATCH χωρίς
 *      χειροκίνητη δημιουργία → 403 manual_edit_not_allowed.
 *   3: email επισκεπτών μόνο σε domains της λίστας, όριο ανά παραλήπτη,
 *      συνολικό ταβάνι ελέγχεται πριν από το όριο ανά IP.
 * php t_guest.php
 */
require __DIR__ . '/bootajax.php';
$PD = qrrp_test_pdir();
if ( ! defined( 'QRRP_PLUGIN_DIR' ) ) { define( 'QRRP_PLUGIN_DIR', $PD . '/' ); }
function qrrp_asset_version( $p ) { return '1'; }
require_once __DIR__ . '/lib/renderer.php';
qrrp_test_renderer( 'fake' ); /* 2.15.4: λογική, χωρίς GD */

$fails = 0;
function check( $label, $ok ) { global $fails; if ( ! $ok ) { $fails++; } echo ( $ok ? 'PASS' : 'FAIL' ), ' ', $label, "\n"; }
function call( $method, $post ) { $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = $post; try { QRRP_Ajax::$method(); } catch ( JsonExit $e ) { return $e; } return null; }
function as_guest( $on ) { $GLOBALS['__logged_in'] = ! $on; }

/* Εργαλείο ανοιχτό σε επισκέπτες, email επισκεπτών ενεργό. */
$GLOBALS['__options'] = array(
	'qrrp_capability'               => 'read',
	'qrrp_allow_guests'             => '1',
	'qrrp_allow_guest_email'        => '1',
	'qrrp_allow_guest_manual_entry' => '1',
	'qrrp_guest_email_domains'      => "pharmacyneeds.gr\nexample.org",
);

$f   = array( 'PC' => '05012345678900', 'SN' => 'SN1', 'LOT' => 'LOT', 'EXP' => '2028-03-31' );
$raw = QRRP_GS1_Parser::validate_and_build( $f )['raw'];
$crafted = '0105012345678900' . '17280331' . "10LOT\x1d" . '21ANYSERIAL99';
$base = array( 'pc' => $f['PC'], 'lot' => $f['LOT'], 'exp' => $f['EXP'] );

/* --- Fix 2: provenance --- */
as_guest( true );
$e = call( 'rebuild', $base + array( 'sn' => 'ANYSERIAL99', 'source_raw' => $crafted ) );
check( 'guest crafted source_raw → 200', $e && 200 === $e->status );
check( 'guest provenance is user_declared (not scan)', 'user_declared' === ( $e->data['provenance'] ?? '' ) );
check( 'guest keeps internal source_method=scan', 'scan' === ( $e->data['source_method'] ?? '' ) );

as_guest( false );
$e = call( 'rebuild', $base + array( 'sn' => 'ANYSERIAL99', 'source_raw' => $crafted ) );
check( 'logged-in provenance stays scan', 'scan' === ( $e->data['provenance'] ?? '' ) && ! isset( $e->data['source_method'] ) );

/* NO_MATCH (τιμή που δεν προκύπτει από τη σάρωση). */
$scan = '0105012345678900' . '17280331' . "10LOT\x1d" . '21SN1';
as_guest( true );
$GLOBALS['__options']['qrrp_allow_guest_manual_entry'] = '0';
$e = call( 'rebuild', $base + array( 'sn' => 'OTHER', 'source_raw' => $scan ) );
check( 'guest, manual off, edited SN → 403 manual_edit_not_allowed', 403 === $e->status && 'manual_edit_not_allowed' === $e->data['code'] );
$e = call( 'rebuild', $base + array( 'sn' => 'SN1', 'source_raw' => $scan ) );
check( 'guest, manual off, unchanged scan still works', 200 === $e->status );

/* Άλλη admissible ανάγνωση από την καθαρή του parser (SN=ABC αντί ABC24012): κι αυτή ανακατασκευή. */
$clean = '0105012345678900' . '17280331' . "10LOT\x1d" . '21ABC24012';
$e = call( 'rebuild', $base + array( 'sn' => 'ABC', 'source_raw' => $clean ) );
check( 'guest, manual off, deviating admissible reading → 403 (no challenge issued)', 403 === $e->status && 'manual_edit_not_allowed' === $e->data['code'] && empty( $e->data['challenge'] ) );

$GLOBALS['__options']['qrrp_allow_guest_manual_entry'] = '1';
$e = call( 'rebuild', $base + array( 'sn' => 'OTHER', 'source_raw' => $scan ) );
check( 'guest, manual on, edited SN → 409 challenge (as before)', 409 === $e->status && 'manual_override_required' === $e->data['code'] );

as_guest( false );
$GLOBALS['__options']['qrrp_allow_guest_manual_entry'] = '0';
$e = call( 'rebuild', $base + array( 'sn' => 'OTHER', 'source_raw' => $scan ) );
check( 'logged-in unaffected by guest toggle → 409 challenge', 409 === $e->status && 'manual_override_required' === $e->data['code'] );
$GLOBALS['__options']['qrrp_allow_guest_manual_entry'] = '1';

/* --- Fix 3: email επισκεπτών --- */
as_guest( true );
$mail = array( 'raw' => $raw, 'pc' => $f['PC'], 'sn' => $f['SN'], 'lot' => $f['LOT'], 'exp' => $f['EXP'], 'source_raw' => $raw );

QRRP_Mailer::$sent = 0;
$e = call( 'send_email', $mail + array( 'email' => 'someone@gmail.com' ) );
check( 'guest → domain not in list → 403 recipient_not_allowed', 403 === $e->status && 'recipient_not_allowed' === $e->data['code'] );
check( '  ...and nothing was sent', 0 === QRRP_Mailer::$sent );

$e = call( 'send_email', $mail + array( 'email' => 'Info@PharmacyNeeds.gr' ) );
check( 'guest → listed domain (case-insensitive) → sent', 200 === $e->status && 1 === QRRP_Mailer::$sent );

$e = call( 'send_email', $mail + array( 'email' => 'x@evil.pharmacyneeds.gr' ) );
check( 'guest → subdomain of listed domain is NOT allowed', 403 === $e->status );

/* Όριο ανά παραλήπτη: 3 την ώρα (1 ήδη πάνω). */
call( 'send_email', $mail + array( 'email' => 'info@pharmacyneeds.gr' ) );
call( 'send_email', $mail + array( 'email' => 'INFO@pharmacyneeds.gr' ) );
$e = call( 'send_email', $mail + array( 'email' => 'info@pharmacyneeds.gr' ) );
check( 'guest → 4th email to same recipient → 429 recipient_rate_limited', 429 === $e->status && 'recipient_rate_limited' === $e->data['code'] );
check( '  ...3 sent in total', 3 === QRRP_Mailer::$sent );
check( '2.15.4: recipient window defaults to 24 h', array( 86400 ) === array_unique( QRRP_Rate_Limiter::$windows ) );
$e = call( 'send_email', $mail + array( 'email' => 'info+tag7@pharmacyneeds.gr' ) );
check( 'guest → plus-address shares the same recipient bucket → 429', 429 === $e->status && 'recipient_rate_limited' === $e->data['code'] );
$stored = implode( '|', array_keys( QRRP_Rate_Limiter::$subjects ) );
check( '  ...recipient normalised (one bucket for Info@/INFO@/info@)', 1 === substr_count( $stored, 'pharmacyneeds.gr' ) );

$GLOBALS['__options']['qrrp_guest_email_domains'] = '';
check( 'empty domain list → guest cannot send (can_send_email false)', false === QRRP_Ajax::can_send_email() );
$e = call( 'send_email', $mail + array( 'email' => 'info@pharmacyneeds.gr' ) );
check( '  ...endpoint → 403 guest_email_disabled', 403 === $e->status && 'guest_email_disabled' === $e->data['code'] );
$GLOBALS['__filters']['qrrp_guest_email_recipient_allowed'] = static fn( $allowed, $email ) => 'ok@partner.gr' === $email;
check( '2.15.4: a recipient filter alone does NOT re-enable guest email', false === QRRP_Ajax::can_send_email() );
$GLOBALS['__filters']['qrrp_guest_email_open_recipients'] = static fn( $v ) => true;
check( '2.15.4: explicit qrrp_guest_email_open_recipients re-enables it', true === QRRP_Ajax::can_send_email() );
$e = call( 'send_email', $mail + array( 'email' => 'ok@partner.gr' ) );
check( '  ...and the recipient filter decides the recipient', 200 === $e->status );
$e = call( 'send_email', $mail + array( 'email' => 'no@partner.gr' ) );
check( '  ...a recipient the filter refuses → 403', 403 === $e->status && 'recipient_not_allowed' === $e->data['code'] );
unset( $GLOBALS['__filters']['qrrp_guest_email_recipient_allowed'], $GLOBALS['__filters']['qrrp_guest_email_open_recipients'] );
$GLOBALS['__options']['qrrp_guest_email_domains'] = 'pharmacyneeds.gr';

as_guest( false );
$before = QRRP_Mailer::$sent;
$e = call( 'send_email', $mail + array( 'email' => 'anyone@gmail.com' ) );
check( 'logged-in user unaffected by domain list', 200 === $e->status && QRRP_Mailer::$sent === $before + 1 );

/* Συνολικό ταβάνι πριν από το όριο ανά IP. */
as_guest( true );
QRRP_Rate_Limiter::$log  = array();
QRRP_Rate_Limiter::$full = array( 'guest_parse_global' => true );
$e = call( 'parse', array( 'raw' => $raw ) );
check( 'guest global parse cap full → 429', 429 === $e->status );
check( '  ...no per-IP counter touched', ! in_array( 'hit:actor:parse', QRRP_Rate_Limiter::$log, true ) );
QRRP_Rate_Limiter::$full = array();
QRRP_Rate_Limiter::$log  = array();
$e = call( 'parse', array( 'raw' => $raw ) );
check( 'guest parse normal: peek → actor → global', array( 'peek:guest_parse_global', 'hit:actor:parse', 'hit:global:guest_parse_global' ) === QRRP_Rate_Limiter::$log );
QRRP_Rate_Limiter::$full = array();
QRRP_Rate_Limiter::$log  = array();
call( 'send_email', $mail + array( 'email' => 'order@pharmacyneeds.gr' ) );
$pos_q = array_search( 'hit:global:guest_email_global', QRRP_Rate_Limiter::$log, true );
$pos_r = array_search( 'subject:guest_email_recipient', QRRP_Rate_Limiter::$log, true );
$pos_p = array_search( 'peek:guest_email_global', QRRP_Rate_Limiter::$log, true );
/* 2.15.7: peek του ταβανιού → όριο παραλήπτη → χρέωση του ταβανιού. */
check( 'global quota is peeked before and charged after the recipient limit', false !== $pos_p && false !== $pos_q && false !== $pos_r && $pos_p < $pos_r && $pos_r < $pos_q );
QRRP_Rate_Limiter::$full = array( 'guest_email_global' => true );
QRRP_Rate_Limiter::$log  = array();
$e = call( 'send_email', $mail + array( 'email' => 'info2@pharmacyneeds.gr' ) );
check( 'guest global email cap full → 429 before per-IP counter', 429 === $e->status && ! in_array( 'hit:actor:send_email', QRRP_Rate_Limiter::$log, true ) );
QRRP_Rate_Limiter::$full = array();

/* 2.15.4: φίλτρο παραθύρου, με όρια 1 ώρα – 24 ώρες. */
QRRP_Rate_Limiter::$windows = array();
$GLOBALS['__filters']['qrrp_guest_email_per_recipient_window'] = static fn( $w ) => 60;
call( 'send_email', $mail + array( 'email' => 'w1@pharmacyneeds.gr' ) );
$GLOBALS['__filters']['qrrp_guest_email_per_recipient_window'] = static fn( $w ) => 30 * 86400;
call( 'send_email', $mail + array( 'email' => 'w2@pharmacyneeds.gr' ) );
unset( $GLOBALS['__filters']['qrrp_guest_email_per_recipient_window'] );
check( '2.15.4: window filter clamped to [1 h, 24 h]', array( 3600, 86400 ) === QRRP_Rate_Limiter::$windows );

/* --- Fix 8 (server): refresh_nonce --- */
QRRP_Rate_Limiter::$log = array();
$e = call( 'refresh_nonce', array() );
check( 'refresh_nonce (guest, tool open) → fresh nonce', 200 === $e->status && 'abcdef1234' === $e->data['nonce'] );
$_SERVER['REQUEST_METHOD'] = 'GET';
try { QRRP_Ajax::refresh_nonce(); $e = null; } catch ( JsonExit $x ) { $e = $x; }
check( 'refresh_nonce requires POST', $e && 405 === $e->status );
$GLOBALS['__options']['qrrp_allow_guests'] = '0';
$e = call( 'refresh_nonce', array() );
check( 'refresh_nonce denied to guests when tool is closed', 403 === $e->status );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
