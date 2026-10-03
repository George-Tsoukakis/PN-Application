<?php
/*
 * 2.16.1 — backend: μεταπτώσεις πρόσβασης στο init, έγκριση φαρμακοποιού ανά
 * site χωρίς αυτο-έγκριση, email αποστολέα, τελείες Gmail, ειδοποίηση ανοιχτού
 * email, Site Health για χαλαρωμένους ελέγχους, όριο φίλτρου αιώνα.
 * php t_backend_2161.php
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
function admin_url( $p = '' ) { return 'https://example.gr/wp-admin/' . $p; }
function wp_nonce_url( $u, $a ) { return $u . '&_wpnonce=n'; }
$GLOBALS['__ms'] = false;
function is_multisite() { return $GLOBALS['__ms']; }
function has_filter( $t ) { return isset( $GLOBALS['__filters'][ $t ] ); }
function get_current_user_id() { return $GLOBALS['__uid'] ?? 7; }
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
if ( ! defined( 'QRRP_VERSION' ) ) { define( 'QRRP_VERSION', '2.16.1' ); }
if ( ! defined( 'QRRP_DEFAULT_EMAIL_CAPABILITY' ) ) { define( 'QRRP_DEFAULT_EMAIL_CAPABILITY', 'qrrp_verified_pharmacist' ); }
function update_option( $o, $v ) { $old = $GLOBALS['__options'][ $o ] ?? null; $GLOBALS['__options'][ $o ] = $v; return $old !== $v; }
function delete_user_meta( $u, $k ) { unset( $GLOBALS['__umeta'][ $u ][ $k ] ); return true; }
function wp_unslash( $v ) { return $v; }
function wp_verify_nonce( $n, $a ) { return $GLOBALS['__nonce_ok'] ? 1 : false; }
function sanitize_email( $e ) { return trim( (string) $e ); }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function user_can( $u, $c ) { return false; }
class Fake_WPDB { public $options = 'wp_options'; public $usermeta = 'wp_usermeta'; public $blogid = 3; function get_blog_prefix() { return 'wp_' . $this->blogid . '_'; } }
$GLOBALS['wpdb'] = new Fake_WPDB();
require $PD . '/includes/functions-upgrade.php';
require $PD . '/includes/class-qrrp-admin.php';
require $PD . '/includes/class-qrrp-site-health.php';

$fails = 0;
function check( $label, $ok ) { global $fails; if ( ! $ok ) { $fails++; } echo ( $ok ? 'PASS' : 'FAIL' ), ' ', $label, "\n"; }

/* 1. Μεταπτώσεις πρόσβασης στο init, χωρίς διαχειριστή. */
$GLOBALS['__options'] = array( 'qrrp_version' => '2.15.7', 'qrrp_capability' => 'read', 'qrrp_email_capability' => 'qrrp_pharmacist' );
qrrp_run_access_migrations();
check( 'init: email qrrp_pharmacist → qrrp_verified_pharmacist after auto-update (no admin visit)', 'qrrp_verified_pharmacist' === $GLOBALS['__options']['qrrp_email_capability'] );
check( 'init: version option is left for the admin upgrade path', '2.15.7' === $GLOBALS['__options']['qrrp_version'] );
$GLOBALS['__options'] = array( 'qrrp_version' => '2.16.1', 'qrrp_capability' => 'read', 'qrrp_email_capability' => 'qrrp_pharmacist' );
qrrp_run_access_migrations();
check( 'init: up-to-date install is never touched (explicit admin choice)', 'qrrp_pharmacist' === $GLOBALS['__options']['qrrp_email_capability'] );
$main = file_get_contents( $PD . '/qr-rebuilder-pro.php' );
check( 'main file hooks qrrp_run_access_migrations on init', false !== strpos( $main, "add_action( 'init', 'qrrp_run_access_migrations'" ) );

/* 2. Έγκριση ανά site στο multisite, χωρίς αυτο-έγκριση. */
check( 'single site: meta key unchanged (2.16.0 approvals stay)', 'qrrp_verified_pharmacist' === qrrp_verified_pharmacist_meta_key() );
$GLOBALS['__ms'] = true;
check( 'multisite: per-site meta key', 'wp_3_qrrp_verified_pharmacist' === qrrp_verified_pharmacist_meta_key() );
$GLOBALS['__umeta'][20]['qrrp_verified_pharmacist'] = '1';
check( 'multisite: a network-wide (other site) approval does not count here', false === qrrp_user_has_verified_flag( 20 ) );
$GLOBALS['__cap'] = true; $GLOBALS['__nonce_ok'] = true; $GLOBALS['__uid'] = 20;
$_POST = array( 'qrrp_verified_pharmacist_nonce' => 'n', 'qrrp_verified_pharmacist' => '1' );
QRRP_Admin::save_verified_pharmacist_field( 20 );
check( 'self-approval refused', ! isset( $GLOBALS['__umeta'][20]['wp_3_qrrp_verified_pharmacist'] ) );
$GLOBALS['__uid'] = 7;
QRRP_Admin::save_verified_pharmacist_field( 20 );
check( 'admin approves another user on this site only', '1' === ( $GLOBALS['__umeta'][20]['wp_3_qrrp_verified_pharmacist'] ?? '' ) && true === qrrp_user_has_verified_flag( 20 ) );
$GLOBALS['wpdb']->blogid = 4;
check( '  ...and the approval does not carry over to another site', false === qrrp_user_has_verified_flag( 20 ) );
$GLOBALS['__ms'] = false;
$un = file_get_contents( $PD . '/uninstall.php' );
check( 'uninstall deletes per-site approval keys', false !== strpos( $un, "esc_like( '_qrrp_verified_pharmacist' )" ) );

/* 3. Email αποστολέα: άκυρη τιμή κρατά την προηγούμενη. */
$GLOBALS['__errors'] = array();
$GLOBALS['__options'] = array( 'qrrp_email_from_address' => 'noreply@pharmacy.gr', 'admin_email' => 'admin@pharmacy.gr' );
check( 'invalid sender email keeps the previous valid value', 'noreply@pharmacy.gr' === QRRP_Admin::sanitize_sender_email( 'noreply@pharmacy' ) );
$GLOBALS['__options'] = array( 'admin_email' => 'admin@pharmacy.gr' );
check( 'no previous value → admin email as before', 'admin@pharmacy.gr' === QRRP_Admin::sanitize_sender_email( 'bad' ) );

/* 4. Τελείες Gmail στο όριο ανά παραλήπτη. */
check( 'gmail dots fold to one recipient', qrrp_normalize_email_for_limit( 'J.Ohn+x@Gmail.com' ) === qrrp_normalize_email_for_limit( 'john@googlemail.com' ) );
check( 'dots kept for other domains', 'j.ohn@pharmacy.gr' === qrrp_normalize_email_for_limit( 'j.ohn+tag@pharmacy.gr' ) );

/* 5. Ειδοποίηση: email «ίδιο με το εργαλείο» με εργαλείο ανοιχτό σε όλους. */
$GLOBALS['__screen'] = 'dashboard';
$notice = static function () { ob_start(); QRRP_Admin::maybe_show_open_email_notice(); return ob_get_clean(); };
$GLOBALS['__options'] = array( 'qrrp_capability' => 'read', 'qrrp_email_capability' => '' );
check( 'open email notice shown for email = tool = read', false !== strpos( $notice(), 'notice-warning' ) );
$GLOBALS['__options'] = array( 'qrrp_capability' => 'read', 'qrrp_email_capability' => 'qrrp_verified_pharmacist' );
check( 'no notice with the recommended setting', '' === $notice() );

/* 6. Site Health: χαλαρωμένοι έλεγχοι γίνονται ορατοί. */
check( 'no overrides by default', array() === QRRP_Site_Health::active_safety_overrides() );
$GLOBALS['__filters']['qrrp_strict_ambiguity'] = static fn( $v ) => false;
$GLOBALS['__filters']['qrrp_auto_inference_min_length'] = static fn( $v ) => 1;
check( 'strict_ambiguity=false and min_length=1 are reported', 2 === count( QRRP_Site_Health::active_safety_overrides() ) );
unset( $GLOBALS['__filters']['qrrp_strict_ambiguity'], $GLOBALS['__filters']['qrrp_auto_inference_min_length'] );

/* 7. Φίλτρο αιώνα: το πολύ ±1 έτος. */
require_once $PD . '/includes/class-qrrp-gs1-parser.php';
$GLOBALS['__filters']['qrrp_century_reference_year'] = static fn( $y ) => 1950;
$yy = new ReflectionMethod( 'QRRP_GS1_Parser', 'yy_to_year' ); $yy->setAccessible( true );
check( 'century filter cannot move «28» to 1928', 2028 === $yy->invoke( null, 28 ) );
unset( $GLOBALS['__filters']['qrrp_century_reference_year'] );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
