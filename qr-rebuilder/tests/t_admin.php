<?php
/*
 * 2.15.4 — admin: μήνυμα για άκυρα domains, απόκρυψη προειδοποίησης φαρμακοποιών.
 * php t_admin.php
 */
require __DIR__ . '/boot.php';
$PD = getenv( 'PDIR' ) ?: dirname( __DIR__ ) . '/qr-rebuilder-pro';

$GLOBALS['__errors'] = array();
$GLOBALS['__umeta']  = array();
function add_action( ...$a ) {}
if ( ! function_exists( 'add_filter' ) ) { function add_filter( ...$a ) {} }
function add_settings_error( $s, $c, $m, $t = 'error' ) { $GLOBALS['__errors'][] = array( $c, $m, $t ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $k ) ); }
function esc_html_e( $s, $d = null ) { echo htmlspecialchars( $s ); }
function esc_html__( $s, $d = null ) { return htmlspecialchars( $s ); }
function esc_url( $u ) { return $u; }
function admin_url( $p = '' ) { return 'https://example.gr/wp-admin/' . $p; }
function wp_nonce_url( $u, $a ) { return $u . '&_wpnonce=n'; }
function is_multisite() { return false; }
function has_filter( $t ) { return isset( $GLOBALS['__filters'][ $t ] ); }
function get_current_user_id() { return 7; }
function get_user_meta( $u, $k, $single = false ) { return $GLOBALS['__umeta'][ $u ][ $k ] ?? ''; }
function is_user_logged_in() { return true; }
function get_bloginfo( $k = '' ) { return 'Site'; }
class Halt extends Exception {}
$GLOBALS['__cap'] = true; $GLOBALS['__nonce_ok'] = true; $GLOBALS['__redirect'] = null; $GLOBALS['__screen'] = 'dashboard';
function current_user_can( $c ) { return $GLOBALS['__cap']; }
function check_admin_referer( $a ) { if ( ! $GLOBALS['__nonce_ok'] ) { throw new Halt( 'nonce' ); } return 1; }
function update_user_meta( $u, $k, $v ) { $GLOBALS['__umeta'][ $u ][ $k ] = $v; return true; }
function wp_safe_redirect( $u ) { $GLOBALS['__redirect'] = $u; throw new Halt( 'redirect' ); }
function wp_die( $m = '', $t = '', $a = array() ) { throw new Halt( 'die:' . ( $a['response'] ?? '' ) ); }
function get_current_screen() { return (object) array( 'id' => $GLOBALS['__screen'] ); }
require $PD . '/includes/functions-access.php';
require $PD . '/includes/class-qrrp-admin.php';

$fails = 0;
function check( $label, $ok ) { global $fails; if ( ! $ok ) { $fails++; } echo ( $ok ? 'PASS' : 'FAIL' ), ' ', $label, "\n"; }

/* Άκυρα domains: αποθηκεύονται μόνο τα έγκυρα, και ο διαχειριστής ενημερώνεται μία φορά. */
$out = QRRP_Admin::sanitize_guest_email_domains( "PharmacyNeeds.gr\nnot a domain\n@example.org\nκαφέ.gr\nfoo" );
check( 'valid domains kept, normalised', "pharmacyneeds.gr\nexample.org" === $out );
check( 'one settings warning listing the rejected entries', 1 === count( $GLOBALS['__errors'] ) && 'warning' === $GLOBALS['__errors'][0][2] && false !== strpos( $GLOBALS['__errors'][0][1], 'καφέ.gr' ) && false !== strpos( $GLOBALS['__errors'][0][1], 'foo' ) );
QRRP_Admin::sanitize_guest_email_domains( 'bad' );
check( 'second sanitize call in the same request does not duplicate the warning', 1 === count( $GLOBALS['__errors'] ) );
$many = implode( "\n", array_map( static fn( $i ) => "d$i.gr", range( 1, 55 ) ) );
$rej  = array();
$kept = qrrp_parse_email_domains( $many, $rej );
check( 'more than 50 domains: 50 kept, 5 reported', 50 === count( $kept ) && 5 === count( $rej ) );
$rej = array();
qrrp_parse_email_domains( "a.gr\n\n  b.gr ", $rej );
check( 'blank lines are not reported as rejected', array() === $rej );

/* Φίλτρο παραληπτών: μόνο το ρητό φίλτρο «ανοίγει» το email χωρίς λίστα. */
$GLOBALS['__options']['qrrp_guest_email_domains'] = '';
$GLOBALS['__filters']['qrrp_guest_email_recipient_allowed'] = static fn( $a, $e ) => false;
check( 'restrictive recipient filter alone → no recipients', false === qrrp_guest_email_has_recipients() );
$GLOBALS['__filters']['qrrp_guest_email_open_recipients'] = static fn( $v ) => true;
check( 'explicit open-recipients filter → recipients', true === qrrp_guest_email_has_recipients() );
unset( $GLOBALS['__filters']['qrrp_guest_email_recipient_allowed'], $GLOBALS['__filters']['qrrp_guest_email_open_recipients'] );

/* Προειδοποίηση φαρμακοποιών: φαίνεται με ανοιχτή εγγραφή, κρύβεται ανά διαχειριστή. */
$render = new ReflectionMethod( 'QRRP_Admin', 'render_pharmacist_registration_warning' );
$render->setAccessible( true );
$show = static function () use ( $render ) { ob_start(); $render->invoke( null, 'qrrp_pharmacist' ); return ob_get_clean(); };

$GLOBALS['__options']['users_can_register'] = '1';
$html = $show();
check( 'warning shown (open registration, pharmacists only)', false !== strpos( $html, 'Προσοχή' ) );
check( 'warning has a nonce-protected «Απόκρυψη» link', false !== strpos( $html, 'action=qrrp_hide_pharmacist_warning' ) && false !== strpos( $html, '_wpnonce=' ) );
$GLOBALS['__umeta'][7]['qrrp_hide_pharmacist_warning'] = '1';
check( 'hidden for the admin who dismissed it', '' === $show() );
$GLOBALS['__umeta'] = array();
$GLOBALS['__options']['users_can_register'] = '0';
check( 'not shown when registration is closed', '' === $show() );

/* Handler απόκρυψης, πραγματική κλήση. */
$run = static function () { try { QRRP_Admin::hide_pharmacist_warning(); return 'no-exit'; } catch ( Halt $h ) { return $h->getMessage(); } };
$GLOBALS['__umeta'] = array();
$GLOBALS['__cap'] = false;
check( 'handler: non-admin → 403, nothing stored', 'die:403' === $run() && empty( $GLOBALS['__umeta'] ) );
$GLOBALS['__cap'] = true; $GLOBALS['__nonce_ok'] = false;
check( 'handler: bad nonce → stops, nothing stored', 'nonce' === $run() && empty( $GLOBALS['__umeta'] ) );
$GLOBALS['__nonce_ok'] = true;
check( 'handler: admin + nonce → stored and redirected to the settings page only', 'redirect' === $run() && '1' === ( $GLOBALS['__umeta'][7]['qrrp_hide_pharmacist_warning'] ?? '' ) && 'https://example.gr/wp-admin/admin.php?page=qrrp-settings' === $GLOBALS['__redirect'] );
$GLOBALS['__umeta'] = array();

/* Ειδοποίηση email επισκεπτών μετά την αναβάθμιση. */
$notice = static function () { ob_start(); QRRP_Admin::maybe_show_guest_email_notice(); return ob_get_clean(); };
$GLOBALS['__options']['qrrp_allow_guest_email']   = '1';
$GLOBALS['__options']['qrrp_guest_email_domains'] = '';
check( 'guest email on + empty list → admin notice', false !== strpos( $notice(), 'notice-warning' ) );
$GLOBALS['__filters']['qrrp_guest_email_recipient_allowed'] = static fn( $a, $e ) => true;
check( '2.15.4: recipient filter alone does not silence the notice', false !== strpos( $notice(), 'notice-warning' ) );
$GLOBALS['__filters']['qrrp_guest_email_open_recipients'] = static fn( $v ) => true;
check( '...explicit open-recipients filter silences it', '' === $notice() );
unset( $GLOBALS['__filters']['qrrp_guest_email_recipient_allowed'], $GLOBALS['__filters']['qrrp_guest_email_open_recipients'] );
$GLOBALS['__options']['qrrp_guest_email_domains'] = 'pharmacyneeds.gr';
check( 'domains set → no notice', '' === $notice() );
$GLOBALS['__options']['qrrp_guest_email_domains'] = '';
$GLOBALS['__screen'] = 'edit-post';
check( 'notice only on dashboard / plugins / settings', '' === $notice() );

/* Uninstall σβήνει το flag απόκρυψης. */
$un = file_get_contents( $PD . '/uninstall.php' );
$blk = substr( $un, (int) strpos( $un, 'WHERE meta_key IN (' ), 400 );
$blk = substr( $blk, 0, (int) strpos( $blk, ');' ) );
check( 'uninstall deletes qrrp_hide_pharmacist_warning + 2.16.0 qrrp_verified_pharmacist user meta (6 placeholders, 6 keys)', false !== strpos( $blk, "'qrrp_hide_pharmacist_warning'" ) && false !== strpos( $blk, "'qrrp_verified_pharmacist'" ) && 6 === substr_count( $blk, '%s' ) && 6 === preg_match_all( "/'qrrp_[a-z_]+'/", $blk ) );

/* Η απόκρυψη αλλάζει μόνο την εμφάνιση: ο έλεγχος πρόσβασης μένει ίδιος. */
$src = file_get_contents( $PD . '/includes/class-qrrp-admin.php' );
$fn  = substr( $src, strpos( $src, 'public static function hide_pharmacist_warning' ), 900 );
check( 'handler checks capability and nonce before writing', false !== strpos( $fn, "current_user_can( 'manage_options' )" ) && false !== strpos( $fn, 'check_admin_referer' ) && strpos( $fn, 'check_admin_referer' ) < strpos( $fn, 'update_user_meta' ) );
$access = file_get_contents( $PD . '/includes/functions-access.php' );
check( 'access code does not read the dismiss flag', false === strpos( $access, 'qrrp_hide_pharmacist_warning' ) );

/* 2.15.7: σελίδα εργαλείου — χωρίς δημοσιευμένες σελίδες το πεδίο λείπει (null). */
function get_post_type( $id ) { return $GLOBALS['__posts'][ $id ]['type'] ?? false; }
function get_post_status( $id ) { return $GLOBALS['__posts'][ $id ]['status'] ?? false; }
$GLOBALS['__options']['qrrp_tool_page_id'] = 12;
$GLOBALS['__posts'] = array( 12 => array( 'type' => 'page', 'status' => 'publish' ), 30 => array( 'type' => 'post', 'status' => 'publish' ) );
$GLOBALS['__errors'] = array();
check( '2.15.7: tool page field missing (no published pages) → keeps value silently', 12 === QRRP_Admin::sanitize_tool_page_id( null ) && array() === $GLOBALS['__errors'] );
check( '  ...a published page is still accepted', 12 === QRRP_Admin::sanitize_tool_page_id( '12' ) && array() === $GLOBALS['__errors'] );
$r = QRRP_Admin::sanitize_tool_page_id( '30' );
check( '  ...a non-page is still refused with a warning', 12 === $r && 1 === count( $GLOBALS['__errors'] ) && 'qrrp_invalid_tool_page' === $GLOBALS['__errors'][0][0] );

/* 2.15.7: pn_uf_get_category() με ανεκτική σύγκριση (τόνοι/κεφαλαία), όπως τα meta keys. */
function user_can( $u, $c ) { return false; }
function pn_uf_get_category( $u ) { return $GLOBALS['__pn_cat'][ $u ] ?? ''; }
$GLOBALS['__pn_cat'] = array( 1 => 'Φαρμακείο', 2 => 'ΦΑΡΜΑΚΕΙΟ', 3 => 'φαρμακειο ', 4 => 'Ιατρείο' );
check( '2.15.7: pn_uf category «Φαρμακείο» / «ΦΑΡΜΑΚΕΙΟ» → pharmacist', qrrp_user_is_pharmacist( 1 ) && qrrp_user_is_pharmacist( 2 ) );
check( '  ...other category → not pharmacist', ! qrrp_user_is_pharmacist( 4 ) );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
