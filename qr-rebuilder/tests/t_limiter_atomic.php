<?php
/*
 * 2.16.1 — ατομική αύξηση του rate limiter, cron καθαρισμός και
 * trusted_proxy_remote_addr(). Πραγματικός QRRP_Rate_Limiter πάνω σε SQLite.
 *
 * Ταυτόχρονα αιτήματα = Fibers: πριν από ΚΑΘΕ query κάθε αίτημα παραχωρεί
 * σειρά (round-robin), άρα τα statements όλων πλέκονται με τον χειρότερο
 * τρόπο (όλοι διαβάζουν, μετά όλοι γράφουν). Με το CAS του 2.16.0 εδώ
 * περνούσαν ~CAS_ATTEMPTS από τα N· τώρα πρέπει να περνούν όλα κάτω από το
 * όριο και ακριβώς `limit` πάνω από αυτό.
 * php t_limiter_atomic.php   (PDIR=/path/to/qr-rebuilder-pro)
 */
if ( ! extension_loaded( 'pdo_sqlite' ) ) {
	echo "SKIP t_limiter_atomic: pdo_sqlite missing\n";
	exit( 0 );
}
if ( ! class_exists( 'Fiber' ) ) {
	echo "SKIP t_limiter_atomic: PHP < 8.1 (no Fiber)\n";
	exit( 0 );
}
require_once __DIR__ . '/lib/pdir.php';
$PD = qrrp_test_pdir();

define( 'ABSPATH', '/tmp/' );
$GLOBALS['__filters'] = array();
$GLOBALS['__events']  = array();
$GLOBALS['__actions'] = array();
$GLOBALS['__cron']    = array();
function apply_filters( $tag, $v, ...$a ) { return isset( $GLOBALS['__filters'][ $tag ] ) ? $GLOBALS['__filters'][ $tag ]( $v, ...$a ) : $v; }
function do_action( $tag, ...$a ) { if ( 'qrrp_rate_limit_failed' === $tag ) { $GLOBALS['__events'][] = $a[0]; } }
function add_action( $tag, $cb, ...$a ) { $GLOBALS['__actions'][ $tag ][] = $cb; }
function wp_next_scheduled( $hook ) { return $GLOBALS['__cron'][ $hook ] ?? false; }
function wp_schedule_event( $ts, $rec, $hook ) { $GLOBALS['__cron'][ $hook ] = $ts; return true; }

require __DIR__ . '/lib/sqlite_wpdb.php';
require $PD . '/includes/class-qrrp-rate-limiter.php';

use QRRP_Rate_Limiter as RL;

$fails = 0;
function check( $label, $ok ) {
	global $fails;
	if ( ! $ok ) { $fails++; }
	echo ( $ok ? 'PASS' : 'FAIL' ), ' ', $label, "\n";
	return $ok;
}

/** N αιτήματα με statement-level interleaving. @return bool[] */
function concurrent( QRRP_SQLite_WPDB $db, $n, callable $fn ) {
	$db->before_query = static function () {
		if ( null !== Fiber::getCurrent() ) {
			Fiber::suspend();
		}
	};
	$fibers = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$fibers[ $i ] = new Fiber( $fn );
		$fibers[ $i ]->start();
	}
	do {
		$running = false;
		foreach ( $fibers as $f ) {
			if ( $f->isSuspended() ) {
				$f->resume();
				$running = true;
			}
		}
	} while ( $running );
	$db->before_query = null;
	return array_map( static fn( $f ) => $f->getReturn(), $fibers );
}
function allowed( array $r ) { return count( array_filter( $r ) ); }
function stored( QRRP_SQLite_WPDB $db, $key ) {
	$raw = $db->raw_value( '_transient_' . $key );
	return null === $raw ? null : array( (int) substr( $raw, 0, 10 ), (int) substr( $raw, 10 ), $raw );
}

$G   = 'qrrp_rl_global_guest_parse_global';
$hit = static fn( $limit ) => static fn() => RL::hit( 'guest_parse_global', $limit, 600, 'global' );

/* --- 1. Μορφή τιμής --- */
check( 'encode_window: 16 digits, start + zero-padded count', '1790000000000042' === RL::encode_window( 1790000000, 42 ) );
check( 'action hooks registered by the class file (cron handler + init scheduler)',
	isset( $GLOBALS['__actions'][ RL::CRON_HOOK ], $GLOBALS['__actions']['init'] ) );

/* --- 2. Κρύα εκκίνηση, κάτω από το όριο: όλοι περνούν --- */
$db = qrrp_sqlite_install();
$GLOBALS['__events'] = array();
$r  = concurrent( $db, 60, $hit( 100 ) );
$s  = stored( $db, $G );
check( 'cold start: 60 interleaved hits under limit 100 → all 60 allowed', 60 === allowed( $r ) );
check( 'cold start: stored count == 60, exactly one window/timeout row', 60 === $s[1] && 1 === $db->count_prefix( '_transient_qrrp_rl_' ) && 1 === $db->count_prefix( '_transient_timeout_qrrp_rl_' ) );
check( 'cold start: no cas_exhausted / db_error events', array() === $GLOBALS['__events'] );

/* --- 3. Ζεστό παράθυρο, κάτω από το όριο --- */
$r = concurrent( $db, 40, $hit( 100 ) );
check( 'warm: 40 more interleaved hits (60→100) → all allowed, count 100', 40 === allowed( $r ) && 100 === stored( $db, $G )[1] );
$r = concurrent( $db, 10, $hit( 100 ) );
check( 'warm: window full → 10 more all refused, count stays 100', 0 === allowed( $r ) && 100 === stored( $db, $G )[1] );

/* --- 4. Πάνω από το όριο: ακριβώς limit --- */
$db = qrrp_sqlite_install();
$r  = concurrent( $db, 50, $hit( 20 ) );
check( 'over limit (cold): 50 interleaved hits, limit 20 → exactly 20 allowed, count 20', 20 === allowed( $r ) && 20 === stored( $db, $G )[1] );
$db->raw_set( '_transient_' . $G, RL::encode_window( time() - 10, 5 ) );
$r = concurrent( $db, 50, $hit( 20 ) );
check( 'over limit (warm, count 5): 50 interleaved hits → exactly 15 allowed, count 20', 15 === allowed( $r ) && 20 === stored( $db, $G )[1] );

/* --- 5. Reset ληγμένου παραθύρου υπό ανταγωνισμό --- */
$db = qrrp_sqlite_install();
$db->raw_set( '_transient_' . $G, RL::encode_window( time() - 700, 10 ) );
$db->raw_set( '_transient_timeout_' . $G, (string) ( time() - 40 ) );
$r = concurrent( $db, 30, $hit( 10 ) );
$s = stored( $db, $G );
check( 'expired window, 30 interleaved hits, limit 10 → exactly 10 allowed, new start, count 10',
	10 === allowed( $r ) && 10 === $s[1] && $s[0] >= time() - 2 );
check( 'expired window: timeout row rewritten to start + window + 60', (int) $db->raw_value( '_transient_timeout_' . $G ) === $s[0] + 660 );

/* --- 6. Παλιά serialized τιμή (≤ 2.16.0) --- */
$db = qrrp_sqlite_install();
$db->raw_set( '_transient_' . $G, serialize( array( 'count' => 3, 'start' => time() - 30 ) ) );
check( 'legacy live value: has_capacity reads it', true === RL::has_capacity( 'guest_parse_global', 4, 600 ) && false === RL::has_capacity( 'guest_parse_global', 3, 600 ) );
$r = concurrent( $db, 30, $hit( 10 ) );
$s = stored( $db, $G );
check( 'legacy live value (count 3): 30 interleaved hits, limit 10 → exactly 7 allowed', 7 === allowed( $r ) );
check( 'legacy value converted in place (same start, count 10, 16-digit format)', 10 === $s[1] && time() - 30 === $s[0] && 1 === preg_match( '/^\d{16}$/', $s[2] ) );
$db->raw_set( '_transient_' . $G, serialize( array( 'count' => 99, 'start' => time() - 601 ) ) );
check( 'legacy expired value → reset, allowed, count 1', RL::hit( 'guest_parse_global', 10, 600, 'global' ) && 1 === stored( $db, $G )[1] );
$db->raw_set( '_transient_' . $G, 'garbage' );
check( 'corrupt value → reset, allowed, count 1', RL::hit( 'guest_parse_global', 10, 600, 'global' ) && 1 === stored( $db, $G )[1] );
$db->raw_set( '_transient_' . $G, RL::encode_window( time() + 3600, 1 ) );
check( 'future start (clock change) → reset to now', RL::hit( 'guest_parse_global', 10, 600, 'global' ) && stored( $db, $G )[0] <= time() );

/* --- 7. Queries ανά αίτημα --- */
$db = qrrp_sqlite_install();
RL::hit( 'guest_parse_global', 100, 600, 'global' );
$q0 = $db->num_queries;
RL::hit( 'guest_parse_global', 100, 600, 'global' );
check( 'warm hit = 1 query (atomic UPDATE, no SELECT)', 1 === $db->num_queries - $q0 );
$q0 = $db->num_queries;
RL::has_capacity( 'guest_parse_global', 100, 600 );
check( 'has_capacity = 1 query', 1 === $db->num_queries - $q0 );
$db->raw_set( '_transient_' . $G, RL::encode_window( time() - 10, 100 ) );
$q0 = $db->num_queries;
check( 'full window: refused in 2 queries (UPDATE 0 rows + SELECT)', false === RL::hit( 'guest_parse_global', 100, 600, 'global' ) && 2 === $db->num_queries - $q0 );

/* --- 8. Fail-closed σε σφάλμα βάσης --- */
$db = qrrp_sqlite_install();
$GLOBALS['__events'] = array();
$db->before_query = static function ( $sql, $d ) {
	if ( false !== strpos( $sql, 'CAST(option_value AS UNSIGNED) + 1' ) ) {
		$d->before_query = null;
		$d->pdo->exec( 'ALTER TABLE wp_options RENAME TO wp_options_gone' );
	}
};
$res = RL::hit( 'guest_parse_global', 100, 600, 'global' );
$db->pdo->exec( 'ALTER TABLE wp_options_gone RENAME TO wp_options' );
check( 'DB error on increment → refused immediately, logged db_error', false === $res && array( 'db_error' ) === $GLOBALS['__events'] && 0 === $db->total_rows() );

/* --- 9. Το SQL της αύξησης είναι έγκυρη MySQL χωρίς CAST στο WHERE --- */
$db   = qrrp_sqlite_install();
$seen = '';
$db->before_query = static function ( $sql ) use ( &$seen ) { if ( '' === $seen && 0 === strpos( $sql, 'UPDATE' ) ) { $seen = $sql; } };
RL::hit( 'guest_parse_global', 100, 600, 'global' );
$where = substr( $seen, strpos( $seen, 'WHERE' ) );
check( 'increment SQL: string comparisons in WHERE (no CAST → no 1292 in strict mode), CAST only in SET',
	false !== strpos( $seen, 'SET option_value = CAST(option_value AS UNSIGNED) + 1 WHERE' ) && false === stripos( $where, 'CAST' ) && 1 === preg_match( "/SUBSTRING\\(option_value, 11\\) < '000100'/", $where ) );

/* --- 10. Cron καθαρισμός --- */
$db = qrrp_sqlite_install();
RL::schedule_sweep();
check( 'schedule_sweep: hourly event scheduled once', isset( $GLOBALS['__cron'][ RL::CRON_HOOK ] ) );
$ts = $GLOBALS['__cron'][ RL::CRON_HOOK ];
RL::schedule_sweep();
check( 'schedule_sweep: idempotent', $ts === $GLOBALS['__cron'][ RL::CRON_HOOK ] );

$ex = 'qrrp_rl_' . md5( 'expired-x' );
$db->raw_set( '_transient_' . $ex, RL::encode_window( time() - 5000, 3 ) );
$db->raw_set( '_transient_timeout_' . $ex, (string) ( time() - 4000 ) );
$db->raw_set( RL::SWEEP_OPTION, (string) ( time() - 40 * MINUTE_IN_SECONDS ) );
RL::hit( 'fresh_a', 5, 600 ); // γέννηση παραθύρου, cron φρέσκος (40′) → όχι inline
check( 'inline: no sweep in the visitor request while cron is fresh', null !== $db->raw_value( '_transient_' . $ex ) );
RL::run_scheduled_sweep(); // 40′ ≥ SWEEP_INTERVAL/2
check( 'cron handler sweeps expired window (threshold SWEEP_INTERVAL/2)', null === $db->raw_value( '_transient_' . $ex ) && (int) $db->raw_value( RL::SWEEP_OPTION ) >= time() - 2 );
$db->raw_set( '_transient_' . $ex, RL::encode_window( time() - 5000, 3 ) );
$db->raw_set( '_transient_timeout_' . $ex, (string) ( time() - 4000 ) );
RL::run_scheduled_sweep();
check( 'cron handler throttled right after a pass', null !== $db->raw_value( '_transient_' . $ex ) );

$db->raw_set( RL::SWEEP_OPTION, (string) ( time() - 3 * HOUR_IN_SECONDS ) );
RL::hit( 'fresh_b', 5, 600 );
check( 'inline: 3h overdue with cron enabled → still no inline sweep (< SWEEP_OVERDUE)', null !== $db->raw_value( '_transient_' . $ex ) );
$db->raw_set( RL::SWEEP_OPTION, (string) ( time() - RL::SWEEP_OVERDUE - 60 ) );
RL::hit( 'fresh_c', 5, 600 );
check( 'inline fallback: overdue > SWEEP_OVERDUE → swept in the request', null === $db->raw_value( '_transient_' . $ex ) );

define( 'DISABLE_WP_CRON', true );
$db->raw_set( '_transient_' . $ex, RL::encode_window( time() - 5000, 3 ) );
$db->raw_set( '_transient_timeout_' . $ex, (string) ( time() - 4000 ) );
$db->raw_set( RL::SWEEP_OPTION, (string) ( time() - 90 * MINUTE_IN_SECONDS ) );
RL::hit( 'fresh_d', 5, 600 );
check( 'DISABLE_WP_CRON: 90′ → no inline sweep yet (system cron may run)', null !== $db->raw_value( '_transient_' . $ex ) );
$db->raw_set( RL::SWEEP_OPTION, (string) ( time() - 3 * HOUR_IN_SECONDS ) );
RL::hit( 'fresh_e', 5, 600 );
check( 'DISABLE_WP_CRON: overdue > SWEEP_OVERDUE_NO_CRON → swept inline', null === $db->raw_value( '_transient_' . $ex ) );

/* --- 11. trusted_proxy_remote_addr() --- */
$tp = static fn( $remote, $xff ) => ( function () use ( $remote, $xff ) {
	$_SERVER['REMOTE_ADDR'] = $remote;
	if ( null === $xff ) { unset( $_SERVER['HTTP_X_FORWARDED_FOR'] ); } else { $_SERVER['HTTP_X_FORWARDED_FOR'] = $xff; }
	return RL::trusted_proxy_remote_addr( $remote );
} )();
check( 'proxy: no trusted list → REMOTE_ADDR even with XFF', '10.0.0.5' === $tp( '10.0.0.5', '198.51.100.7' ) );
$GLOBALS['__filters']['qrrp_rate_limit_trusted_proxies'] = static fn() => array( '10.0.0.0/8', '2400:cb00::/32', '192.0.2.1', 'bogus/99' );
check( 'proxy: REMOTE_ADDR not trusted → XFF ignored (spoof)', '203.0.113.9' === $tp( '203.0.113.9', '198.51.100.7' ) );
check( 'proxy: trusted REMOTE_ADDR → client from XFF', '198.51.100.7' === $tp( '10.1.2.3', '198.51.100.7' ) );
check( 'proxy: right-most untrusted wins over client-supplied left entries', '198.51.100.7' === $tp( '10.1.2.3', '1.1.1.1, 198.51.100.7, 10.9.9.9, 192.0.2.1' ) );
check( 'proxy: IPv6 proxy range + IPv6 client', '2001:db8::7' === $tp( '2400:cb00:1::1', '2001:db8::7' ) );
check( 'proxy: invalid hop → REMOTE_ADDR (no spoofed key)', '10.1.2.3' === $tp( '10.1.2.3', '198.51.100.7, not-an-ip' ) );
check( 'proxy: only proxies in chain → REMOTE_ADDR', '10.1.2.3' === $tp( '10.1.2.3', '10.4.4.4, 192.0.2.1' ) );
check( 'proxy: missing XFF → REMOTE_ADDR', '10.1.2.3' === $tp( '10.1.2.3', null ) );
check( 'proxy: "ip:port" and "[v6]:port" hops', '198.51.100.7' === $tp( '10.1.2.3', '198.51.100.7:4711' ) && '2001:db8::9' === $tp( '10.1.2.3', '[2001:db8::9]:443' ) );
check( 'ip_in_ranges: /20 boundary, no v4/v6 mixing', RL::ip_in_ranges( '173.245.63.255', array( '173.245.48.0/20' ) ) && ! RL::ip_in_ranges( '173.245.64.0', array( '173.245.48.0/20' ) ) && ! RL::ip_in_ranges( '::ffff:10.0.0.1', array( '10.0.0.0/8' ) ) );
$GLOBALS['__filters']['qrrp_rate_limit_remote_addr'] = array( 'QRRP_Rate_Limiter', 'trusted_proxy_remote_addr' );
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7';
$_SERVER['REMOTE_ADDR']          = '10.1.2.3';
$k1                              = RL::actor_key( 'parse' );
$_SERVER['REMOTE_ADDR']          = '10.200.0.1'; // άλλος κόμβος του CDN, ίδιος πελάτης
check( 'proxy: opted-in filter → actor key follows the client, not the CDN node', $k1 === RL::actor_key( 'parse' ) );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
