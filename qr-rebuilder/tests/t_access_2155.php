<?php
/*
 * 2.15.5 — αυστηρότερες προεπιλογές πρόσβασης:
 *   1: όρια email για συνδεδεμένους (ημερήσιο ανά χρήστη, ανά αποστολέα+παραλήπτη), εξαίρεση διαχειριστών.
 *   2: χειροκίνητη δημιουργία από επισκέπτες κλειστή από προεπιλογή, και η
 *      μετάπτωση που την κλείνει μόνο όπου οι επισκέπτες είναι ήδη κλειστοί.
 *   3: δημόσιες υπηρεσίες email αγνοούνται στο email επισκεπτών (endpoint).
 * php t_access_2155.php
 */
require __DIR__ . '/bootajax.php';
$PD = qrrp_test_pdir();
if ( ! defined( 'QRRP_PLUGIN_DIR' ) ) { define( 'QRRP_PLUGIN_DIR', $PD . '/' ); }
if ( ! defined( 'QRRP_VERSION' ) ) { define( 'QRRP_VERSION', '2.15.5' ); }
function qrrp_asset_version( $p ) { return '1'; }
function update_option( $o, $v ) { $old = $GLOBALS['__options'][ $o ] ?? null; $GLOBALS['__options'][ $o ] = $v; return $old !== $v; }
require_once __DIR__ . '/lib/renderer.php';
qrrp_test_renderer( 'fake' );
require $PD . '/includes/functions-upgrade.php';

$fails = 0;
function check( $label, $ok ) { global $fails; if ( ! $ok ) { $fails++; } echo ( $ok ? 'PASS' : 'FAIL' ), ' ', $label, "\n"; }
function call( $method, $post ) { $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = $post; try { QRRP_Ajax::$method(); } catch ( JsonExit $e ) { return $e; } return null; }
function as_guest( $on ) { $GLOBALS['__logged_in'] = ! $on; }

$f    = array( 'PC' => '05012345678900', 'SN' => 'SN1', 'LOT' => 'LOT', 'EXP' => '2028-03-31' );
$raw  = QRRP_GS1_Parser::validate_and_build( $f )['raw'];
$mail = array( 'raw' => $raw, 'pc' => $f['PC'], 'sn' => $f['SN'], 'lot' => $f['LOT'], 'exp' => $f['EXP'], 'source_raw' => $raw );

/* ---------- 1: όρια συνδεδεμένων ---------- */
$GLOBALS['__options'] = array( 'qrrp_capability' => 'read', 'qrrp_email_capability' => 'qrrp_pharmacist' );
as_guest( false );

/* Διαχειριστής (το stub current_user_can επιστρέφει true): εξαιρείται. */
QRRP_Rate_Limiter::$log = array();
$e = call( 'send_email', $mail + array( 'email' => 'a@anywhere.gr' ) );
check( 'admin: email sent', 200 === $e->status );
check( '  ...admin exempt: no daily or per-recipient counter', ! in_array( 'hit:actor:send_email_daily', QRRP_Rate_Limiter::$log, true ) && ! in_array( 'subject:user_email_recipient', QRRP_Rate_Limiter::$log, true ) );

/* Μη διαχειριστής (π.χ. αυτο-δηλωμένος φαρμακοποιός). */
$GLOBALS['__filters']['qrrp_user_email_limits_exempt'] = static fn( $v, $uid ) => false;
QRRP_Rate_Limiter::$log      = array();
QRRP_Rate_Limiter::$subjects = array();
QRRP_Rate_Limiter::$windows  = array();
QRRP_Mailer::$sent           = 0;
$e = call( 'send_email', $mail + array( 'email' => 'Victim@Example.org' ) );
check( 'user: first email sent', 200 === $e->status && 1 === QRRP_Mailer::$sent );
$log  = QRRP_Rate_Limiter::$log;
$pq   = array_search( 'hit:global:authenticated_email_global', $log, true );
$pd   = array_search( 'hit:actor:send_email_daily', $log, true );
$pr   = array_search( 'subject:user_email_recipient', $log, true );
check( 'user: order = site quota → per recipient → daily per user', false !== $pq && false !== $pd && false !== $pr && $pq < $pr && $pr < $pd );
check( 'user: per-recipient window is 24 h', array( 86400 ) === QRRP_Rate_Limiter::$windows );

for ( $i = 2; $i <= 10; $i++ ) {
	call( 'send_email', $mail + array( 'email' => 'victim+' . $i . '@example.org' ) );
}
check( 'user: 10 emails to the same recipient (+tag / case normalised) all sent', 10 === QRRP_Mailer::$sent );
$e = call( 'send_email', $mail + array( 'email' => 'VICTIM@example.org' ) );
check( 'user: 11th email to the same recipient → 429 recipient_rate_limited', 429 === $e->status && 'recipient_rate_limited' === $e->data['code'] && 10 === QRRP_Mailer::$sent );
$e = call( 'send_email', $mail + array( 'email' => 'other@example.org' ) );
check( 'user: a different recipient is still allowed', 200 === $e->status );
$GLOBALS['__uid'] = 2;
$e = call( 'send_email', $mail + array( 'email' => 'victim@example.org' ) );
check( 'another user is NOT locked out of the same recipient (per-sender bucket)', 200 === $e->status );
$GLOBALS['__uid'] = 1;
QRRP_Rate_Limiter::$log = array();
$e = call( 'send_email', $mail + array( 'email' => 'victim@example.org' ) );
check( 'per-recipient refusal does not charge the daily counter', 429 === $e->status && ! in_array( 'hit:actor:send_email_daily', QRRP_Rate_Limiter::$log, true ) );

$GLOBALS['__filters']['qrrp_user_email_per_recipient_limit'] = static fn( $v ) => 0;
$e = call( 'send_email', $mail + array( 'email' => 'other@example.org' ) );
check( 'per-recipient filter floored at 1 (2nd to same address refused)', 429 === $e->status );
unset( $GLOBALS['__filters']['qrrp_user_email_per_recipient_limit'] );

/* Ημερήσιο όριο: η καταμέτρηση είναι του QRRP_Rate_Limiter::hit() (ίδιος μηχανισμός με όλα τα όρια, t_storage_longrun)· εδώ η προεπιλογή και ότι η άρνηση φτάνει στον χρήστη. */
$GLOBALS['__filters']['qrrp_user_email_daily_limit'] = static function ( $v ) { $GLOBALS['__daily_seen'] = $v; return $v; };
QRRP_Rate_Limiter::$deny = array( 'send_email_daily' => true );
$before = QRRP_Mailer::$sent;
$e = call( 'send_email', $mail + array( 'email' => 'third@example.org' ) );
check( 'daily limit default passed to filter is 50', 50 === ( $GLOBALS['__daily_seen'] ?? null ) );
check( 'daily limit reached → 429 daily_email_limit, nothing sent', 429 === $e->status && 'daily_email_limit' === $e->data['code'] && $before === QRRP_Mailer::$sent );
QRRP_Rate_Limiter::$deny = array();
unset( $GLOBALS['__filters']['qrrp_user_email_daily_limit'], $GLOBALS['__filters']['qrrp_user_email_limits_exempt'] );

/* Επισκέπτες: ίδια διαδρομή με πριν (κανένας μετρητής συνδεδεμένων). */
$GLOBALS['__options'] = array(
	'qrrp_capability'          => 'read',
	'qrrp_allow_guests'        => '1',
	'qrrp_allow_guest_email'   => '1',
	'qrrp_guest_email_domains' => "pharmacyneeds.gr\ngmail.com",
);
as_guest( true );
QRRP_Rate_Limiter::$log = array();
$e = call( 'send_email', $mail + array( 'email' => 'info@pharmacyneeds.gr' ) );
check( 'guest: own domain still works', 200 === $e->status );
check( '  ...guest path does not touch logged-in counters', ! in_array( 'hit:actor:send_email_daily', QRRP_Rate_Limiter::$log, true ) && ! in_array( 'subject:user_email_recipient', QRRP_Rate_Limiter::$log, true ) );

/* ---------- 3: webmail στο endpoint ---------- */
$e = call( 'send_email', $mail + array( 'email' => 'someone@gmail.com' ) );
check( 'guest: gmail.com stored in list is ignored → 403 recipient_not_allowed', 403 === $e->status && 'recipient_not_allowed' === $e->data['code'] );

/* ---------- 2: χειροκίνητη δημιουργία επισκεπτών ---------- */
$GLOBALS['__options'] = array( 'qrrp_capability' => 'read', 'qrrp_allow_guests' => '1' );
as_guest( true );
check( 'manual entry: option row missing → guests may NOT enter manually', false === qrrp_manual_entry_allowed() );
$GLOBALS['__options']['qrrp_allow_guest_manual_entry'] = '1';
check( 'manual entry: explicit opt-in → allowed', true === qrrp_manual_entry_allowed() );
as_guest( false );
$GLOBALS['__options']['qrrp_allow_guest_manual_entry'] = '0';
check( 'manual entry: logged-in users unaffected', true === qrrp_manual_entry_allowed() );

/* Μετάπτωση. */
$cases = array(
	array( 'guests off, seeded 1, from 2.15.4 → 0', array( 'qrrp_allow_guests' => '0', 'qrrp_allow_guest_manual_entry' => '1' ), '2.15.4', true, '0' ),
	array( 'guests ON, 1, from 2.15.4 → kept 1', array( 'qrrp_allow_guests' => '1', 'qrrp_allow_guest_manual_entry' => '1' ), '2.15.4', false, '1' ),
	array( 'guests off, already 0 → untouched', array( 'qrrp_allow_guests' => '0', 'qrrp_allow_guest_manual_entry' => '0' ), '2.15.4', false, '0' ),
	array( 'guests off, 1, already 2.15.5 → untouched', array( 'qrrp_allow_guests' => '0', 'qrrp_allow_guest_manual_entry' => '1' ), '2.15.5', false, '1' ),
	array( 'unknown version, guests row missing, 1 → 0', array( 'qrrp_allow_guest_manual_entry' => '1' ), '', true, '0' ),
	array( 'guests flag 1 but tool = pharmacists (not really open) → 0', array( 'qrrp_allow_guests' => '1', 'qrrp_capability' => 'qrrp_pharmacist', 'qrrp_allow_guest_manual_entry' => '1' ), '2.15.4', true, '0' ),
);
foreach ( $cases as $c ) {
	$GLOBALS['__options'] = $c[1];
	$changed = qrrp_maybe_migrate_guest_manual_entry( $c[2] );
	check( 'migration: ' . $c[0], $c[3] === $changed && $c[4] === $GLOBALS['__options']['qrrp_allow_guest_manual_entry'] );
}

/* Και τα δύο σημεία εισόδου (ενεργοποίηση, αναβάθμιση) καλούν τη μετάπτωση. */
$src = file_get_contents( $PD . '/includes/functions-upgrade.php' );
foreach ( array( 'qrrp_activate_plugin', 'qrrp_maybe_upgrade' ) as $fn ) {
	$body = ( new ReflectionFunction( $fn ) );
	$code = implode( '', array_slice( file( $body->getFileName() ), $body->getStartLine() - 1, $body->getEndLine() - $body->getStartLine() + 1 ) );
	check( "$fn() calls qrrp_maybe_migrate_guest_manual_entry()", false !== strpos( $code, 'qrrp_maybe_migrate_guest_manual_entry( $installed_version )' ) );
}

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
