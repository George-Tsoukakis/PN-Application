<?php
/*
 * 2.15.5 — admin: δημόσιες υπηρεσίες email (gmail.com κ.λπ.) στη λίστα domains επισκεπτών.
 * Χωριστό αρχείο: ο sanitizer αναφέρει μία φορά ανά αίτημα (static), άρα θέλει νέα διεργασία.
 * php t_admin_webmail.php
 */
require __DIR__ . '/boot.php';
$PD = getenv( 'PDIR' ) ?: dirname( __DIR__ ) . '/qr-rebuilder-pro';

$GLOBALS['__errors'] = array();
$GLOBALS['__umeta']  = array();
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function add_settings_error( $s, $c, $m, $t = 'error' ) { $GLOBALS['__errors'][] = array( $c, $m, $t ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $k ) ); }
function esc_html_e( $s, $d = null ) { echo htmlspecialchars( $s ); }
function esc_html__( $s, $d = null ) { return htmlspecialchars( $s ); }
function esc_url( $u ) { return $u; }
function esc_textarea( $s ) { return htmlspecialchars( (string) $s ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s ); }
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

$out = QRRP_Admin::sanitize_guest_email_domains( "pharmacyneeds.gr\nGmail.com\n@outlook.com\nnot a domain" );
check( 'webmail removed, own domain kept', 'pharmacyneeds.gr' === $out );
$codes = array_column( $GLOBALS['__errors'], 0 );
check( 'two warnings: invalid entries and webmail', array( 'qrrp_guest_email_domains_rejected', 'qrrp_guest_email_domains_webmail' ) === $codes );
$msg = $GLOBALS['__errors'][1][1] ?? '';
check( '  ...webmail warning names gmail.com and outlook.com', false !== strpos( $msg, 'gmail.com' ) && false !== strpos( $msg, 'outlook.com' ) );

/* Αποθηκευμένη λίστα από παλαιότερη έκδοση: αγνοείται στη λειτουργία. */
$GLOBALS['__options']['qrrp_guest_email_domains'] = "gmail.com\nhotmail.gr";
check( 'stored webmail-only list → no guest domains at runtime', array() === qrrp_guest_email_domains() );
check( '  ...and guest email has no recipients', false === qrrp_guest_email_has_recipients() );
check( '  ...gmail recipient refused', false === qrrp_guest_email_recipient_allowed( 'someone@gmail.com' ) );

ob_start();
QRRP_Admin::field_guest_email_domains();
$html = ob_get_clean();
check( 'settings field warns about the ignored stored domains', false !== strpos( $html, 'gmail.com, hotmail.gr' ) && false !== strpos( $html, 'qrrp-field-warning' ) );

$GLOBALS['__options']['qrrp_guest_email_domains'] = "pharmacyneeds.gr";
ob_start();
QRRP_Admin::field_guest_email_domains();
$html = ob_get_clean();
check( 'no warning for a clean list', false === strpos( $html, 'qrrp-field-warning' ) );

/* Ρητή εξαίρεση με φίλτρο. */
$GLOBALS['__options']['qrrp_guest_email_domains'] = "gmail.com";
$GLOBALS['__filters']['qrrp_guest_email_allow_webmail'] = static fn( $v ) => true;
check( 'qrrp_guest_email_allow_webmail filter re-allows webmail', array( 'gmail.com' ) === qrrp_guest_email_domains() && true === qrrp_guest_email_recipient_allowed( 'a@gmail.com' ) );
unset( $GLOBALS['__filters']['qrrp_guest_email_allow_webmail'] );

/* Επέκταση της λίστας με φίλτρο. */
$GLOBALS['__options']['qrrp_guest_email_domains'] = "freemail.example";
$GLOBALS['__filters']['qrrp_webmail_domains'] = static fn( $d ) => array_merge( $d, array( 'FreeMail.example' ) );
check( 'qrrp_webmail_domains filter extends the list (case-insensitive)', array() === qrrp_guest_email_domains() );
$GLOBALS['__filters']['qrrp_webmail_domains'] = static fn( $d ) => array( ' @FreeMail.example. ' );
check( 'filter entries normalised like the admin list (@, spaces, dots)', array() === qrrp_without_webmail_domains( array( 'freemail.example' ) ) );
$GLOBALS['__filters']['qrrp_webmail_domains'] = static fn( $d ) => 'garbage';
check( 'non-array filter result falls back to the built-in list', array() === qrrp_without_webmail_domains( array( 'gmail.com' ) ) );
unset( $GLOBALS['__filters']['qrrp_webmail_domains'] );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
