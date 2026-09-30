<?php
/**
 * Job Listings — unit tests without WordPress.
 *
 * Minimal WordPress stubs plus a loader that pulls single functions out of
 * the plugin files, so each test runs exactly the shipped code.
 *
 * Run: php tests/job-listings/run.php
 */

date_default_timezone_set( 'UTC' ); // Same as WordPress at runtime.

define( 'ABSPATH', __DIR__ . '/' );
define( 'JBLI_CPT', 'job_listing' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'JBLI_META_EXPIRED', 'jbli_expired' );
define( 'JBLI_META_ADMIN_HIDDEN', '_jbli_admin_hidden' );

$GLOBALS['JBLI_PLUGIN'] = dirname( __DIR__, 2 ) . '/job-listings/';

/* ---- Test state ---- */
$GLOBALS['T'] = array( 'meta' => array(), 'status' => array(), 'author' => array(), 'admin' => false, 'active' => 0 );

/* ---- WordPress stubs ---- */
function __( $s ) { return $s; }
function esc_html__( $s ) { return $s; }
function _n( $a, $b, $n ) { return 1 === (int) $n ? $a : $b; }
function apply_filters( $h, $v ) { return $v; }
function get_option( $k, $d = false ) { return $d; }
function absint( $v ) { return abs( (int) $v ); }
function wp_unslash( $s ) { return $s; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_email( $s ) { return (string) filter_var( trim( (string) $s ), FILTER_SANITIZE_EMAIL ); }
function is_email( $s ) { return (bool) filter_var( $s, FILTER_VALIDATE_EMAIL ); }
function wp_kses( $s ) { return $s; }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function wp_list_pluck( $a, $k ) { return array_column( $a, $k ); }
function term_exists() { return true; }
function wp_timezone() { return new DateTimeZone( 'Europe/Athens' ); }
function wp_date( $f, $ts = null ) { $d = new DateTime( '@' . ( null === $ts ? time() : $ts ) ); $d->setTimezone( wp_timezone() ); return $d->format( $f ); }
function get_post_meta( $id, $k = '', $single = true ) { return $GLOBALS['T']['meta'][ $id ][ $k ] ?? ''; }
function get_post_status( $id ) { return $GLOBALS['T']['status'][ $id ] ?? false; }
function get_post_field( $f, $id ) { return $GLOBALS['T']['author'][ $id ] ?? 0; }

/* ---- Plugin stubs (helpers the tested functions call) ---- */
function jbli_allowed_html() { return array(); }
function jbli_strlen( $s ) { return mb_strlen( (string) $s ); }
function jbli_salary_options() { return array( '2200+' => 'x', 'negotiable' => 'y' ); }
function jbli_type_options() { return array( 'plires-apasxolisi' => 'x' ); }
function jbli_sanitize_phone( $s ) { return $s; }
function jbli_sanitize_lat( $s ) { return $s; }
function jbli_sanitize_lng( $s ) { return $s; }
function jbli_notice( $m ) { return 'ERR:' . $m; }
function jbli_is_admin() { return $GLOBALS['T']['admin']; }
function jbli_resolve_active_count( $u ) { return $GLOBALS['T']['active']; }

/**
 * Define one function from a plugin file (the file itself is not executed).
 *
 * @param string $file Path relative to the plugin root.
 * @param string $name Function name.
 */
function jbli_test_load( $file, $name ) {

	if ( function_exists( $name ) ) { return; }

	$src = file_get_contents( $GLOBALS['JBLI_PLUGIN'] . $file );
	$pos = strpos( $src, 'function ' . $name . '(' );

	if ( false === $pos ) { throw new RuntimeException( "$name not found in $file" ); }

	$depth = 0;
	$open  = strpos( $src, '{', $pos );

	for ( $i = $open, $n = strlen( $src ); $i < $n; $i++ ) {
		if ( '{' === $src[ $i ] ) { $depth++; }
		if ( '}' === $src[ $i ] && 0 === --$depth ) { break; }
	}

	eval( substr( $src, $pos, $i - $pos + 1 ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test loader.
}

/* ---- Assertions ---- */
$GLOBALS['FAILS'] = 0;
$GLOBALS['PASSES'] = 0;

function ok( $cond, $msg ) {
	if ( $cond ) { $GLOBALS['PASSES']++; return; }
	$GLOBALS['FAILS']++;
	fwrite( STDERR, "FAIL: $msg\n" );
}
