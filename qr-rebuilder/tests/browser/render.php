<?php
/*
 * Renders the real [qr_rebuilder_pro] markup from includes/class-qrrp-shortcode.php
 * with minimal WordPress stubs, captures the wp_localize_script('QRRP') data and
 * writes fixture.html (markup + window.QRRP + plugin CSS/JS). No plugin file is modified.
 * Usage: php render.php [PDIR] > fixture.html
 */
$PD = rtrim( $argv[1] ?? getenv( 'PDIR' ) ?: __DIR__ . '/../../qr-rebuilder-pro', '/' );
$PD = realpath( $PD );
define( 'ABSPATH', '/tmp/' );
define( 'QRRP_PLUGIN_DIR', $PD . '/' );
define( 'QRRP_PLUGIN_URL', 'http://qrrp.test/wp-content/plugins/qr-rebuilder-pro/' );
define( 'QRRP_VERSION', 'test' );
define( 'QRRP_DEFAULT_EMAIL_CAPABILITY', 'qrrp_pharmacist' );

$GLOBALS['__localized'] = array();
$GLOBALS['__scripts']   = array();
$GLOBALS['__styles']    = array();

function __( $s, $d = null ) { return $s; }
function _n( $a, $b, $n, $d = null ) { return 1 == $n ? $a : $b; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s ) { return esc_attr( $s ); }
function esc_url_raw( $s ) { return (string) $s; }
function esc_html__( $s, $d = null ) { return esc_html( $s ); }
function esc_attr__( $s, $d = null ) { return esc_attr( $s ); }
function esc_html_e( $s, $d = null ) { echo esc_html( $s ); }
function esc_attr_e( $s, $d = null ) { echo esc_attr( $s ); }
function apply_filters( $tag, $v, ...$a ) { return $v; }
function has_filter( ...$a ) { return false; }
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function add_shortcode( ...$a ) {}
function do_action( ...$a ) {}
function doing_action( ...$a ) { return false; }
function doing_filter( ...$a ) { return false; }
function is_admin() { return false; }
function is_feed() { return false; }
function is_singular() { return false; }
function in_the_loop() { return true; }
function is_user_logged_in() { return true; }
function current_user_can( $c ) { return true; }
function get_option( $o, $d = false ) { return $d; }
function update_option( ...$a ) { return true; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function wp_unslash( $v ) { return $v; }
function nocache_headers() {}
function admin_url( $p = '' ) { return 'http://qrrp.test/wp-admin/' . $p; }
function wp_create_nonce( $a = -1 ) { return '0123abcd89'; }
function wp_date( $f, $ts = null ) { return ( new DateTimeImmutable( '@' . ( $ts ?? time() ) ) )->setTimezone( new DateTimeZone( 'Europe/Athens' ) )->format( $f ); }
function wp_enqueue_style( $h, $src = '', ...$a ) { $GLOBALS['__styles'][ $h ] = $src; }
function wp_enqueue_script( $h, $src = '', ...$a ) { $GLOBALS['__scripts'][ $h ] = $src; }
/* Same conversion as WP_Scripts::localize(): top-level scalars become strings. */
function wp_localize_script( $h, $name, $l10n ) {
	foreach ( $l10n as $k => $v ) {
		if ( is_scalar( $v ) ) {
			$l10n[ $k ] = html_entity_decode( (string) $v, ENT_QUOTES, 'UTF-8' );
		}
	}
	$GLOBALS['__localized'][ $name ] = $l10n;
	return true;
}
function qrrp_asset_version( $p ) { return 'test'; }
function qrrp_max_raw_bytes() { return 4096; }

final class QRRP_Tool_Page { const SHORTCODE_TAG = 'qr_rebuilder_pro'; }
final class QRRP_Ajax {
	const NONCE_ACTION = 'qrrp_nonce';
	static function can_send_email() { return true; }
}
final class QRRP_Stats { static function public_markup() { return ''; } }

require $PD . '/includes/functions-print.php';
require $PD . '/includes/functions-access.php';
require $PD . '/includes/class-qrrp-shortcode.php';

$markup = QRRP_Shortcode::render();
$qrrp   = $GLOBALS['__localized']['QRRP'] ?? null;
if ( ! $qrrp || false === strpos( $markup, 'id="qrrp-hw-input"' ) ) {
	fwrite( STDERR, "render failed\n" );
	exit( 1 );
}
/* Fixed "today" so expiry logic is deterministic. */
$qrrp['todayISO'] = '2026-09-29';
$json = json_encode( $qrrp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG );
$css  = $GLOBALS['__styles']['qrrp-style'];
$js   = $GLOBALS['__scripts']['qrrp-app'];
echo "<!doctype html>\n<html lang=\"el\"><head><meta charset=\"utf-8\"><title>QRRP fixture</title>\n";
echo '<link rel="stylesheet" href="' . esc_attr( $css ) . "\">\n</head><body>\n";
echo $markup;
echo "\n<script>window.QRRP = " . $json . ";</script>\n";
echo '<script src="' . esc_attr( $js ) . "\"></script>\n</body></html>\n";
