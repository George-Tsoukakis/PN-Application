<?php
/*
 * 2.15.6 — κουμπί «Έλεγχος για νέα έκδοση» της βιβλιοθήκης DataMatrix.
 * Χωρίς δίκτυο: το wp_safe_remote_get επιστρέφει έτοιμες απαντήσεις.
 * php t_vendor_check.php
 */
require __DIR__ . '/boot.php';
$PD = qrrp_test_pdir();
define( 'QRRP_VERSION', '2.15.6' );

$GLOBALS['__http']     = array();
$GLOBALS['__requests'] = array();
$GLOBALS['__cap']      = true;
$GLOBALS['__nonce_ok'] = true;
$GLOBALS['__autoload'] = array();

function add_action( ...$a ) {}
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); }
function esc_html__( $s, $d = null ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function esc_html_e( $s, $d = null ) { echo htmlspecialchars( $s, ENT_QUOTES ); }
function admin_url( $p = '' ) { return 'https://example.gr/wp-admin/' . $p; }
function wp_nonce_field( $a ) { echo '<input type="hidden" name="_wpnonce" value="n" />'; }
function submit_button( $t, $type = '', $name = '', $wrap = true ) { echo '<button>' . htmlspecialchars( $t ) . '</button>'; }
function current_user_can( $c ) { return $GLOBALS['__cap']; }
class Halt extends Exception {}
function wp_die( $m = '', $t = '', $a = array() ) { throw new Halt( 'die:' . ( $a['response'] ?? '' ) ); }
function check_admin_referer( $a ) { if ( ! $GLOBALS['__nonce_ok'] ) { throw new Halt( 'nonce' ); } return 1; }
function wp_safe_redirect( $u ) { $GLOBALS['__redirect'] = $u; throw new Halt( 'redirect' ); }
function add_option( $o, $v, $d = '', $autoload = true ) { $GLOBALS['__options'][ $o ] = $v; $GLOBALS['__autoload'][ $o ] = $autoload; return true; }
function update_option( $o, $v, $autoload = null ) { $GLOBALS['__options'][ $o ] = $v; if ( null !== $autoload ) { $GLOBALS['__autoload'][ $o ] = $autoload; } return true; }
function wp_safe_remote_get( $url, $args ) { $GLOBALS['__requests'][] = array( $url, $args ); $r = $GLOBALS['__http'][ $url ] ?? new WP_Error( 'http_request_failed', 'x' ); return $r; }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
class QRRP_Admin { const PAGE_SLUG = 'qrrp-settings'; }
require $PD . '/includes/class-qrrp-vendor-check.php';

$fails = 0;
function check( $label, $ok ) { global $fails; if ( ! $ok ) { $fails++; } echo ( $ok ? 'PASS' : 'FAIL' ), ' ', $label, "\n"; }
function packagist( $pkg, array $entries ) { return array( 'code' => 200, 'body' => json_encode( array( 'minified' => 'composer/2.0', 'packages' => array( $pkg => $entries ) ) ) ); }

$B = 'tecnickcom/tc-lib-barcode';
$C = 'tecnickcom/tc-lib-color';
$UB = 'https://repo.packagist.org/p2/tecnickcom/tc-lib-barcode.json';
$UC = 'https://repo.packagist.org/p2/tecnickcom/tc-lib-color.json';

/* --- latest_stable: μορφή p2 (minified), χωρίς dev/RC, χωρίς εμπιστοσύνη στη σειρά --- */
$entries = array(
	array( 'name' => $B, 'version' => 'dev-main', 'time' => '2026-09-28T00:00:00+00:00' ),
	array( 'version' => '2.17.0-RC1', 'time' => '2026-09-27T00:00:00+00:00' ),
	array( 'version' => '2.16.4', 'time' => '2026-09-26T11:00:50+00:00' ),
	array( 'version' => '2.16.10', 'time' => '2026-09-29T08:00:00+00:00' ),
	array( 'version' => 'v2.9.0' ),
	'garbage',
	array( 'version' => array( 'x' ) ),
);
$l = QRRP_Vendor_Check::latest_stable( json_decode( packagist( $B, $entries )['body'], true ), $B );
check( 'latest stable = 2.16.10 (numeric compare, dev/RC ignored, order not trusted)', array( 'version' => '2.16.10', 'time' => '2026-09-29' ) === $l );
check( 'wrong package key → null', null === QRRP_Vendor_Check::latest_stable( json_decode( packagist( $B, $entries )['body'], true ), $C ) );
check( 'non-array JSON → null', null === QRRP_Vendor_Check::latest_stable( 'x', $B ) );
check( 'only pre-releases → null', null === QRRP_Vendor_Check::latest_stable( array( 'packages' => array( $B => array( array( 'version' => '3.0.0-beta' ) ) ) ), $B ) );

/* --- check_package --- */
$GLOBALS['__http'][ $UB ] = packagist( $B, array( array( 'version' => '2.16.4', 'time' => '2026-09-26T11:00:50+00:00' ) ) );
$r = QRRP_Vendor_Check::check_package( $B, '2.16.4' );
check( 'same version → current', 'current' === $r['status'] && '2.16.4' === $r['latest'] && '2026-09-26' === $r['released'] );

$GLOBALS['__http'][ $UB ] = packagist( $B, array( array( 'version' => '2.16.5' ), array( 'version' => '2.16.4' ) ) );
check( 'patch release → update', 'update' === QRRP_Vendor_Check::check_package( $B, '2.16.4' )['status'] );

$GLOBALS['__http'][ $UB ] = packagist( $B, array( array( 'version' => '3.0.0' ) ) );
check( 'new major → major', 'major' === QRRP_Vendor_Check::check_package( $B, '2.16.4' )['status'] );

$GLOBALS['__http'][ $UB ] = packagist( $B, array( array( 'version' => '2.16.3' ) ) );
check( 'repo older than installed → current (never a downgrade prompt)', 'current' === QRRP_Vendor_Check::check_package( $B, '2.16.4' )['status'] );

$GLOBALS['__http'][ $UB ] = new WP_Error( 'http_request_failed', 'timeout' );
$r = QRRP_Vendor_Check::check_package( $B, '2.16.4' );
check( 'network error → error/network', 'error' === $r['status'] && 'network' === $r['error'] );

$GLOBALS['__http'][ $UB ] = array( 'code' => 503, 'body' => '' );
check( 'HTTP 503 → error/http_503', 'http_503' === QRRP_Vendor_Check::check_package( $B, '2.16.4' )['error'] );

$GLOBALS['__http'][ $UB ] = array( 'code' => 200, 'body' => '<html>' );
check( 'bad JSON → error/format', 'format' === QRRP_Vendor_Check::check_package( $B, '2.16.4' )['error'] );

/* Το αίτημα: σταθερή HTTPS διεύθυνση, timeout, όριο μεγέθους, χωρίς URL του site στο User-Agent. */
$GLOBALS['__requests'] = array();
QRRP_Vendor_Check::check_package( $B, '2.16.4' );
list( $url, $args ) = $GLOBALS['__requests'][0];
check( 'request goes to fixed https://repo.packagist.org URL', $UB === $url );
check( 'request has timeout ≤ 10 s and a response size limit', $args['timeout'] <= 10 && $args['limit_response_size'] > 0 );
check( 'User-Agent carries no site URL', 'QR-ReBuilder-Pro/2.15.6' === $args['user-agent'] );

/* --- handler: δικαίωμα, μέθοδος, nonce, αποθήκευση non-autoload --- */
$GLOBALS['__http'][ $UB ] = packagist( $B, array( array( 'version' => '2.16.5', 'time' => '2026-10-01T00:00:00+00:00' ) ) );
$GLOBALS['__http'][ $UC ] = packagist( $C, array( array( 'version' => '3.0.7' ) ) );
$_SERVER['REQUEST_METHOD'] = 'POST';

$GLOBALS['__cap'] = false;
try { QRRP_Vendor_Check::handle_check(); $h = 'none'; } catch ( Halt $e ) { $h = $e->getMessage(); }
check( 'handler: non-admin → 403', 'die:403' === $h && ! isset( $GLOBALS['__options']['qrrp_vendor_check'] ) );
$GLOBALS['__cap'] = true;

$_SERVER['REQUEST_METHOD'] = 'GET';
try { QRRP_Vendor_Check::handle_check(); $h = 'none'; } catch ( Halt $e ) { $h = $e->getMessage(); }
check( 'handler: GET → 405', 'die:405' === $h );
$_SERVER['REQUEST_METHOD'] = 'POST';

$GLOBALS['__nonce_ok'] = false;
try { QRRP_Vendor_Check::handle_check(); $h = 'none'; } catch ( Halt $e ) { $h = $e->getMessage(); }
check( 'handler: bad nonce → stops, nothing stored', 'nonce' === $h && ! isset( $GLOBALS['__options']['qrrp_vendor_check'] ) );
$GLOBALS['__nonce_ok'] = true;

try { QRRP_Vendor_Check::handle_check(); $h = 'none'; } catch ( Halt $e ) { $h = $e->getMessage(); }
$stored = $GLOBALS['__options']['qrrp_vendor_check'] ?? null;
check( 'handler: stores result and redirects to the settings card', 'redirect' === $h && false !== strpos( $GLOBALS['__redirect'], 'page=qrrp-settings' ) && is_array( $stored ) );
check( '  ...barcode = update 2.16.5, color = current', 'update' === $stored['packages'][ $B ]['status'] && '2.16.5' === $stored['packages'][ $B ]['latest'] && 'current' === $stored['packages'][ $C ]['status'] );
check( '  ...option is not autoloaded', false === $GLOBALS['__autoload']['qrrp_vendor_check'] );

/* --- κάρτα --- */
ob_start();
QRRP_Vendor_Check::render_card();
$html = ob_get_clean();
check( 'card shows installed, latest and "newer version" status', false !== strpos( $html, '2.16.4' ) && false !== strpos( $html, '2.16.5' ) && false !== strpos( $html, 'Υπάρχει νεότερη έκδοση' ) );
check( 'card links to the GitHub compare page', false !== strpos( $html, 'https://github.com/tecnickcom/tc-lib-barcode/compare/2.16.4...2.16.5' ) );
check( 'card form posts to admin-post with the action and a nonce', false !== strpos( $html, 'admin-post.php' ) && false !== strpos( $html, 'value="qrrp_check_vendor_updates"' ) && false !== strpos( $html, '_wpnonce' ) );

/* Αλλοιωμένο option: τίποτα δεν βγαίνει χωρίς escape. */
$GLOBALS['__options']['qrrp_vendor_check'] = array(
	'checked_at' => time(),
	'packages'   => array( $B => array( 'status' => 'update', 'latest' => '<script>x</script>', 'released' => '"><img>', 'error' => '' ) ),
);
ob_start();
QRRP_Vendor_Check::render_card();
$html = ob_get_clean();
check( 'tampered stored values are escaped', false === strpos( $html, '<script>' ) && false === strpos( $html, '"><img>' ) );
check( '  ...and no compare link for a non-version string', false === strpos( $html, '/compare/' ) );

$GLOBALS['__options']['qrrp_vendor_check'] = 'junk';
ob_start();
QRRP_Vendor_Check::render_card();
$html = ob_get_clean();
check( 'no/invalid stored result → "not checked yet"', false !== strpos( $html, 'Δεν έχει γίνει ακόμη έλεγχος' ) );

/* --- συνέπεια με το vendor --- */
$notes = file_get_contents( $PD . '/vendor/QRRP-VENDOR-NOTES.md' );
check( 'INSTALLED matches QRRP-VENDOR-NOTES.md (barcode)', 1 === preg_match( '/`tc-lib-barcode` \| \*\*' . preg_quote( QRRP_Vendor_Check::INSTALLED[ $B ], '/' ) . '\*\*/', $notes ) );
check( 'INSTALLED matches QRRP-VENDOR-NOTES.md (color)', 1 === preg_match( '/`tc-lib-color`\s+\| \*\*' . preg_quote( QRRP_Vendor_Check::INSTALLED[ $C ], '/' ) . '\*\*/', $notes ) );
check( 'uninstall removes qrrp_vendor_check', false !== strpos( file_get_contents( $PD . '/uninstall.php' ), "'qrrp_vendor_check'" ) );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
