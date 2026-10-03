<?php
/*
 * 2.15.3 — fix 4 (γεμάτο ευρετήριο tokens: απορρίπτεται το νέο, δεν σβήνεται
 * ζωντανό) και fix 3 (has_capacity / hit_subject του πραγματικού limiter).
 * Πραγματικές QRRP_Tokens και QRRP_Rate_Limiter πάνω σε in-memory $wpdb.
 * php t_tokens_limiter.php
 */
require __DIR__ . '/boot.php';
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
$PD = qrrp_test_pdir();

/* Ελάχιστο in-memory wp_options για τα queries των δύο κλάσεων. */
final class Fake_WPDB {
	public $options = 'wp_options';
	public $rows    = array();
	public function prepare( $q, ...$a ) { return array( $q, $a ); }
	public function get_var( $p ) {
		list( $q, $a ) = $p;
		if ( 0 === strpos( $q, 'SELECT option_value' ) ) { return $this->rows[ $a[0] ] ?? null; }
		throw new Exception( 'unexpected get_var: ' . $q );
	}
	public function get_results( $p ) { return array(); }
	public function query( $p ) {
		list( $q, $a ) = $p;
		if ( 0 === strpos( $q, 'INSERT IGNORE' ) ) {
			if ( isset( $this->rows[ $a[0] ] ) ) { return 0; }
			$this->rows[ $a[0] ] = $a[1];
			return 1;
		}
		if ( false !== strpos( $q, 'CAST(option_value AS UNSIGNED) + 1' ) ) {
			/* 2.16.1: ατομική αύξηση υπό συνθήκη — args: name, lower, upper, cap (strings ίδιου μήκους). */
			$v = $this->rows[ $a[0] ] ?? null;
			if ( null === $v || ! preg_match( '/^\d{16}$/', $v ) || $v < $a[1] || $v >= $a[2] || substr( $v, 10 ) >= $a[3] ) { return 0; }
			$this->rows[ $a[0] ] = (string) ( (int) $v + 1 );
			return 1;
		}
		if ( 0 === strpos( $q, 'UPDATE' ) ) {
			if ( ( $this->rows[ $a[1] ] ?? null ) !== $a[2] ) { return 0; }
			$this->rows[ $a[1] ] = $a[0];
			return 1;
		}
		if ( 0 === strpos( $q, 'INSERT INTO' ) ) { $this->rows[ $a[0] ] = $a[1]; return 1; }
		if ( 0 === strpos( $q, 'DELETE' ) ) { return 0; }
		throw new Exception( 'unexpected query: ' . $q );
	}
}
$GLOBALS['wpdb'] = new Fake_WPDB();

$GLOBALS['__transients'] = array();
function set_transient( $k, $v, $ttl ) { $GLOBALS['__transients'][ $k ] = $v; $GLOBALS['wpdb']->rows[ '_transient_timeout_' . $k ] = (string) ( time() + $ttl ); return true; }
function get_transient( $k ) { return $GLOBALS['__transients'][ $k ] ?? false; }
function delete_transient( $k ) { if ( ! isset( $GLOBALS['__transients'][ $k ] ) ) { return false; } unset( $GLOBALS['__transients'][ $k ] ); return true; }
function maybe_serialize( $v ) { return ( is_array( $v ) || is_object( $v ) ) ? serialize( $v ) : $v; }
function maybe_unserialize( $v ) { $u = @unserialize( $v ); return ( false === $u && 'b:0;' !== $v ) ? $v : $u; }
function wp_cache_delete( ...$a ) { return true; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $k ) ); }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function wp_unslash( $v ) { return $v; }
function is_user_logged_in() { return false; }
function get_current_user_id() { return 0; }
function update_option( $o, $v, ...$a ) { $GLOBALS['__options'][ $o ] = $v; return true; }
function delete_option( ...$a ) { return true; }
function add_action( ...$a ) {}

require $PD . '/includes/class-qrrp-tokens.php';
require $PD . '/includes/class-qrrp-rate-limiter.php';

$fails = 0;
function check( $label, $ok ) { global $fails; if ( ! $ok ) { $fails++; } echo ( $ok ? 'PASS' : 'FAIL' ), ' ', $label, "\n"; }

/* --- 2.15.7: ταβάνι 2000 από προεπιλογή, φίλτρο με όρια 100–10000 --- */
check( '2.15.7: default index cap is 2000', 2000 === QRRP_Tokens::token_index_max() );
$GLOBALS['__filters']['qrrp_token_index_max'] = function () { return 5; };
check( '2.15.7: filter clamped to ≥100', 100 === QRRP_Tokens::token_index_max() );
$GLOBALS['__filters']['qrrp_token_index_max'] = function () { return 999999; };
check( '2.15.7: filter clamped to ≤10000', 10000 === QRRP_Tokens::token_index_max() );
/* Ο μηχανισμός του ταβανιού ελέγχεται στο 500 (όπως πριν το 2.15.7). */
$GLOBALS['__filters']['qrrp_token_index_max'] = function () { return 500; };
check( '2.15.7: no refusal recorded yet', 0 === QRRP_Tokens::index_usage()['full_at'] );

/* --- Fix 4 --- */
$issued = array();
for ( $i = 0; $i < 500; $i++ ) {
	$issued[] = QRRP_Tokens::issue( 'email_rebuild', array( 'pc' => (string) $i ), 7 * 86400 );
}
check( '500 email tokens issued', 500 === count( array_filter( $issued ) ) );

$extra = QRRP_Tokens::issue( 'email_rebuild', array( 'pc' => 'new' ), 7 * 86400 );
check( 'index full → 501st token refused (returns "")', '' === $extra );

$alive = 0;
foreach ( $issued as $t ) { if ( is_array( QRRP_Tokens::verify_token_for_request( $t, 'email_rebuild' ) ) ) { $alive++; } }
check( 'all 500 existing links still valid (none evicted)', 500 === $alive );
check( 'refused token left no orphan transient', 500 === count( $GLOBALS['__transients'] ) );
$usage = QRRP_Tokens::index_usage();
/* Το fake $wpdb κρατά το ευρετήριο εκτός get_option, οπότε εδώ ελέγχονται full_at και max. */
check( '2.15.7: refusal recorded for Site Health (full_at set, max 500)', $usage['full_at'] >= time() - 5 && 500 === $usage['max'] );

/* Μια λήξη ελευθερώνει θέση. */
$index = unserialize( $GLOBALS['wpdb']->rows['qrrp_token_index'] );
$first = array_key_first( $index );
$index[ $first ] = time() - 1;
$GLOBALS['wpdb']->rows['qrrp_token_index'] = serialize( $index );
$again = QRRP_Tokens::issue( 'email_rebuild', array( 'pc' => 'after-expiry' ), 7 * 86400 );
check( 'after one entry expires, a new token is accepted', '' !== $again );

/* Τα μη ευρετηριασμένα (challenge, proof) δεν επηρεάζονται από το γεμάτο ευρετήριο. */
check( 'short-lived tokens still issue while index is full', '' !== QRRP_Tokens::issue( 'provenance_challenge', array( 'x' => 1 ), 900 ) );

/* --- Fix 3: limiter --- */
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
check( 'has_capacity on empty counter → true', QRRP_Rate_Limiter::has_capacity( 'guest_parse_global', 2, 600 ) );
QRRP_Rate_Limiter::hit( 'guest_parse_global', 2, 600, 'global' );
QRRP_Rate_Limiter::hit( 'guest_parse_global', 2, 600, 'global' );
check( 'has_capacity when window is full → false', false === QRRP_Rate_Limiter::has_capacity( 'guest_parse_global', 2, 600 ) );
$rows_before = count( $GLOBALS['wpdb']->rows );
QRRP_Rate_Limiter::has_capacity( 'guest_parse_global', 2, 600 );
check( 'has_capacity writes nothing', count( $GLOBALS['wpdb']->rows ) === $rows_before );

$GLOBALS['wpdb']->rows['_transient_qrrp_rl_global_guest_parse_global'] = serialize( array( 'count' => 99, 'start' => time() - 601 ) );
check( 'has_capacity on expired window → true', QRRP_Rate_Limiter::has_capacity( 'guest_parse_global', 2, 600 ) );

$ok = array();
for ( $i = 0; $i < 4; $i++ ) { $ok[] = QRRP_Rate_Limiter::hit_subject( 'guest_email_recipient', 'info@pharmacyneeds.gr', 3, 3600 ); }
check( 'hit_subject: 3 allowed, 4th refused', array( true, true, true, false ) === $ok );
check( 'hit_subject: other recipient has its own bucket', QRRP_Rate_Limiter::hit_subject( 'guest_email_recipient', 'other@pharmacyneeds.gr', 3, 3600 ) );
$dump = serialize( $GLOBALS['wpdb']->rows );
check( 'no email address stored in the database', false === strpos( $dump, 'pharmacyneeds' ) );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
