<?php
/*
 * 2.15.4 — μακροχρόνια αποθήκευση: rate limiter (30 ημέρες κίνησης επισκεπτών)
 * και QRRP_Tokens, πάνω σε πραγματική SQL (SQLite/PDO) πίσω από ψεύτικο $wpdb.
 * Ελέγχονται πλήθη γραμμών στη βάση, όχι μόνο τιμές επιστροφής.
 * php t_storage_longrun.php   (PDIR=/path/to/qr-rebuilder-pro)
 */
if ( ! extension_loaded( 'pdo_sqlite' ) ) {
	echo "SKIP t_storage_longrun: pdo_sqlite missing\n";
	exit( 0 );
}
require_once __DIR__ . '/lib/pdir.php';
$PD = qrrp_test_pdir();

require __DIR__ . '/boot.php';
require __DIR__ . '/lib/sqlite_wpdb.php';
require $PD . '/includes/class-qrrp-tokens.php';
require $PD . '/includes/class-qrrp-rate-limiter.php';

$T_START = microtime( true );
$fails   = 0;
function check( $label, $ok ) {
	global $fails;
	if ( ! $ok ) { $fails++; }
	echo ( $ok ? 'PASS' : 'FAIL' ), ' ', $label, "\n";
	return $ok;
}
function info( $s ) { echo 'INFO ', $s, "\n"; }

use QRRP_Rate_Limiter as RL;

const TO_PFX  = '_transient_timeout_qrrp_rl_';
const VAL_PFX = '_transient_qrrp_rl_';

/* Γραμμές παραθύρου όπως τις γράφουν create_window()/reset_window()/write_timeout(). */
function rl_write( QRRP_SQLite_WPDB $db, $key, $count, $start, $window, $with_timeout = true ) {
	$db->raw_set( '_transient_' . $key, serialize( array( 'count' => $count, 'start' => $start ) ) );
	if ( $with_timeout ) {
		$db->raw_set( '_transient_timeout_' . $key, (string) ( $start + $window + MINUTE_IN_SECONDS ) );
	}
}
function rl_total( QRRP_SQLite_WPDB $db ) { return $db->count_prefix( VAL_PFX ) + $db->count_prefix( TO_PFX ); }
function subj_key( $subject ) {
	return 'qrrp_rl_' . md5( 'guest_email_recipient|s:' . hash_hmac( 'sha256', $subject, wp_salt( 'nonce' ) ) );
}
function guest_key( $action, $addr ) {
	$_SERVER['REMOTE_ADDR'] = $addr;
	return RL::actor_key( $action );
}
function rand_v6() {
	return sprintf( '2001:db8:%x:%x:%x:%x::1', mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ) );
}

/* ================================================================== */
/* 0. Ο emulator                                                       */
/* ================================================================== */
$db = qrrp_sqlite_install();
$q  = $db->prepare( 'SELECT %s, %d, %f, %%, %s', "O'Re\\illy", '12abc', 1.5, array( 'x' ) );
check( 'emu: prepare quotes/escapes %s, casts %d/%f, keeps %%', "SELECT 'O''Re\\illy', 12, 1.500000, %, ''" === $q );
check( 'emu: prepare accepts args as single array', "SELECT 'a', 'b'" === $db->prepare( 'SELECT %s, %s', array( 'a', 'b' ) ) );
check( 'emu: round-trip of quote/backslash/serialized value', "O'R\\x\"y;" === $db->get_var( $db->prepare( 'SELECT %s', "O'R\\x\"y;" ) ) );
check( 'emu: INSERT IGNORE → 1 then 0', 1 === $db->query( "INSERT IGNORE INTO wp_options (option_name, option_value, autoload) VALUES ('k', 'v1', 'no')" )
	&& 0 === $db->query( "INSERT IGNORE INTO wp_options (option_name, option_value, autoload) VALUES ('k', 'v2', 'no')" ) );
$db->query( "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('k', 'v3', 'no') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)" );
check( 'emu: ON DUPLICATE KEY UPDATE upserts', 'v3' === $db->raw_value( 'k' ) );
check( 'emu: UPDATE with unchanged value → 0 affected (MySQL semantics)', 0 === $db->query( "UPDATE wp_options SET option_value = 'v3' WHERE option_name = 'k'" ) );
check( 'emu: CAS UPDATE on exact value → 1', 1 === $db->query( "UPDATE wp_options SET option_value = 'v4' WHERE option_name = 'k' AND option_value = 'v3'" ) );
$db->raw_set( 'xtransient_qrrp_tok_decoy', '1' );
$db->raw_set( '_transient_qrrp_tok_real', '1' );
check( 'emu: esc_like + LIKE ESCAPE: "_" is literal (decoy not matched)',
	array( '_transient_qrrp_tok_real' ) === $db->get_col( $db->prepare( 'SELECT option_name FROM wp_options WHERE option_name LIKE %s', $db->esc_like( '_transient_qrrp_tok_' ) . '%' ) ) );
check( 'emu: CONCAT/SUBSTRING/CAST AS UNSIGNED translate', '_transient_timeout_qrrp_tok_real|7' === $db->get_var( "SELECT CONCAT( '_transient_timeout_', SUBSTRING( option_name, 12 ), '|', CAST('7x' AS UNSIGNED) ) FROM wp_options WHERE option_name = '_transient_qrrp_tok_real'" ) );
check( 'emu: SQL error → query() false, get_results() array() (as wpdb)', false === $db->query( 'SELECT nope FROM missing' ) && array() === $db->get_results( 'SELECT nope FROM missing' ) );
set_transient( 'emu_t', array( 'a' => 1 ), 60 );
$db->raw_set( '_transient_timeout_emu_t', (string) ( time() - 1 ) );
check( 'emu: get_transient deletes expired on read', false === get_transient( 'emu_t' ) && null === $db->raw_value( '_transient_emu_t' ) && null === $db->raw_value( '_transient_timeout_emu_t' ) );

/* ================================================================== */
/* a. Rate limiter: 30 ημέρες κίνησης επισκεπτών                       */
/* ================================================================== */
mt_srand( 20264 );
$db   = qrrp_sqlite_install();
$T0   = 1790000000;
$DAYS = 30;

/* Model: key => [start, window, count]. Καθρέφτης αυτού που ΠΡΕΠΕΙ να υπάρχει στη βάση. */
$model   = array();
$pool    = array(); // επαναλαμβανόμενοι actors
$emails  = array();
for ( $i = 0; $i < 400; $i++ ) { $emails[] = "user{$i}@example.test"; }
$globals = array(
	'parse'      => array( 'qrrp_rl_global_guest_parse_global', 600 ),
	'rebuild'    => array( 'qrrp_rl_global_guest_rebuild_global', 600 ),
	'send_email' => array( 'qrrp_rl_global_guest_email_global', 900 ),
);
$sweeps      = 0;
$last_sweep  = 0;
$distinct    = array();
$day_stats   = array();
$bound_ok    = true;
$exact_ok    = true;
$first_bad   = '';

/* Ένα hit_key(), με ρητό $now (ο limiter χρησιμοποιεί time()). Ίδιες γραμμές, ίδια σειρά. */
$sim_hit = static function ( $key, $window, $now ) use ( $db, &$model, &$sweeps, &$last_sweep ) {
	$raw = $db->raw_value( '_transient_' . $key );
	$new = false;
	if ( null === $raw ) {
		rl_write( $db, $key, 1, $now, $window );
		$model[ $key ] = array( $now, $window, 1 );
		$new           = true;
	} else {
		$s = unserialize( $raw );
		if ( $s['start'] <= $now && ( $now - $s['start'] ) < $window ) {
			$db->raw_set( '_transient_' . $key, serialize( array( 'count' => $s['count'] + 1, 'start' => $s['start'] ) ) );
			$model[ $key ] = array( $s['start'], $window, $s['count'] + 1 );
		} else {
			rl_write( $db, $key, 1, $now, $window );
			$model[ $key ] = array( $now, $window, 1 );
			$new           = true;
		}
	}
	if ( $new && RL::maybe_sweep( $now ) ) { // όπως το create_window()/reset_window()
		++$sweeps;
		$last_sweep = $now;
		foreach ( $model as $k => $m ) {
			if ( $m[0] + $m[1] + MINUTE_IN_SECONDS < $now ) { unset( $model[ $k ] ); }
		}
	}
};

$t      = $T0;
$events = 0;
for ( $day = 1; $day <= $DAYS; $day++ ) {
	$day_end = $T0 + $day * DAY_IN_SECONDS;
	while ( true ) {
		$t += mt_rand( 10, 110 ); // ~60 s μέσος όρος
		if ( $t >= $day_end ) { $t = $day_end; break; }
		++$events;
		$r = mt_rand( 1, 100 );
		if ( $r <= 60 ) { $action = 'parse'; $w = 600; } elseif ( $r <= 85 ) { $action = 'send_email'; $w = 900; } else { $action = 'recipient'; $w = DAY_IN_SECONDS; }

		if ( 'recipient' === $action ) {
			$key = subj_key( $emails[ mt_rand( 0, count( $emails ) - 1 ) ] );
			$sim_hit( $key, $w, $t );
			continue;
		}
		if ( $pool && mt_rand( 1, 100 ) <= 25 ) {
			$addr = $pool[ mt_rand( 0, count( $pool ) - 1 ) ];
		} else {
			$addr   = rand_v6();
			$pool[] = $addr;
			if ( count( $pool ) > 300 ) { array_shift( $pool ); }
		}
		$key              = guest_key( $action, $addr );
		$distinct[ $key ] = true;
		$sim_hit( $key, $w, $t );
		$sim_hit( $globals[ $action ][0], $globals[ $action ][1], $t );
	}

	/* Τέλος ημέρας: βάση == model (ακριβώς), τίποτα ληγμένο πριν το τελευταίο πέρασμα. */
	$rows     = $db->rows_prefix( VAL_PFX ) + $db->rows_prefix( TO_PFX );
	$expected = array();
	$live     = 0;
	foreach ( $model as $k => $m ) {
		$expected[ '_transient_' . $k ]         = serialize( array( 'count' => $m[2], 'start' => $m[0] ) );
		$expected[ '_transient_timeout_' . $k ] = (string) ( $m[0] + $m[1] + MINUTE_IN_SECONDS );
		if ( $day_end - $m[0] < $m[1] ) { ++$live; }
	}
	ksort( $rows );
	ksort( $expected );
	if ( $rows !== $expected && $exact_ok ) {
		$exact_ok  = false;
		$first_bad = "day $day: db=" . count( $rows ) . ' model=' . count( $expected ) . ' missing=' . count( array_diff_key( $expected, $rows ) ) . ' extra=' . count( array_diff_key( $rows, $expected ) );
	}
	$stale = (int) $db->raw( 'SELECT COUNT(*) FROM wp_options WHERE substr(option_name,1,27) = ? AND CAST(option_value AS INTEGER) < ?', array( TO_PFX, $last_sweep ) )->fetchColumn();
	/* Όριο: 2 γραμμές ανά ζωντανό παράθυρο + όσα έληξαν μέσα στο τελευταίο SWEEP_INTERVAL (+1 ώρα ανοχή). */
	$recent = (int) $db->raw( 'SELECT COUNT(*) FROM wp_options WHERE substr(option_name,1,27) = ? AND CAST(option_value AS INTEGER) >= ?', array( TO_PFX, $day_end - 2 * HOUR_IN_SECONDS ) )->fetchColumn();
	if ( $stale > 0 || count( $rows ) > 2 * $recent + 4 ) {
		$bound_ok = false;
		$first_bad .= " day $day: stale=$stale rows=" . count( $rows ) . " recent=$recent";
	}
	$day_stats[ $day ] = array( count( $rows ), $live, $sweeps );
}
$max_rows = max( array_column( $day_stats, 0 ) );
check( "a: 30 days, $events events, " . count( $distinct ) . ' distinct guest actor windows: DB rows == expected live/grace set every day' . ( $exact_ok ? '' : " ($first_bad)" ), $exact_ok );
check( 'a: no expired window survives the last sweep; rows ≤ 2×(windows expiring ≥ now−2h)+4 every day' . ( $bound_ok ? '' : " ($first_bad)" ), $bound_ok );
check( "a: bounded — max rl rows over 30 days = $max_rows, day30 ≤ 1.5×day10 (" . $day_stats[30][0] . ' vs ' . $day_stats[10][0] . ')', $day_stats[30][0] <= 1.5 * $day_stats[10][0] + 20 );
check( "a: maybe_sweep throttled to ≤ 1/hour ($sweeps passes in $DAYS days)", $sweeps <= $DAYS * 24 + 1 && $sweeps >= $DAYS * 20 );
foreach ( array( 1, 5, 10, 20, 30 ) as $d ) {
	info( sprintf( 'a: day %2d: rl rows=%5d live windows=%4d sweeps so far=%d', $d, $day_stats[ $d ][0], $day_stats[ $d ][1], $day_stats[ $d ][2] ) );
}
/* Όλα τελικά σβήνονται: ένα πέρασμα 25 ώρες μετά την τελευταία κίνηση. */
RL::sweep( $t + DAY_IN_SECONDS + 2 * HOUR_IN_SECONDS );
check( 'a: 25h after last traffic every window and timeout row is gone', 0 === rl_total( $db ) );

/* --- a2. Ζωντανά / ορφανά / 24ωρο παράθυρο --- */
$db  = qrrp_sqlite_install();
$now = $T0 + 10 * DAY_IN_SECONDS;
$k24 = subj_key( 'live24@example.test' );
rl_write( $db, $k24, 3, $now - 23 * HOUR_IN_SECONDS, DAY_IN_SECONDS );
$k10 = guest_key( 'parse', '2001:db8:1::1' );
rl_write( $db, $k10, 5, $now - 599, 600 );             // ζωντανό, 1 s πριν τη λήξη
$kx  = guest_key( 'parse', '2001:db8:2::1' );
rl_write( $db, $kx, 5, $now - 700, 600 );              // start+660 < now → ληγμένο
$kg  = 'qrrp_rl_global_guest_parse_global';
rl_write( $db, $kg, 77, $now - 30, 600 );
$orph = array(
	'o1d'  => array( guest_key( 'parse', '2001:db8:3::1' ), $now - DAY_IN_SECONDS ),
	'o47h' => array( guest_key( 'parse', '2001:db8:4::1' ), $now - 47 * HOUR_IN_SECONDS ),
	'o23r' => array( subj_key( 'orph23@example.test' ), $now - 23 * HOUR_IN_SECONDS ),
	'o49h' => array( guest_key( 'parse', '2001:db8:5::1' ), $now - 49 * HOUR_IN_SECONDS ),
	'o30d' => array( guest_key( 'parse', '2001:db8:6::1' ), $now - 30 * DAY_IN_SECONDS ),
);
foreach ( $orph as $o ) { rl_write( $db, $o[0], 1, $o[1], 600, false ); }
RL::sweep( $now );
check( 'a2: 24h recipient window at 23h age kept (value+timeout)', null !== $db->raw_value( '_transient_' . $k24 ) && null !== $db->raw_value( '_transient_timeout_' . $k24 ) );
check( 'a2: 600s window at 599s age kept; global window kept', null !== $db->raw_value( '_transient_' . $k10 ) && null !== $db->raw_value( '_transient_' . $kg ) );
check( 'a2: expired window + timeout deleted', null === $db->raw_value( '_transient_' . $kx ) && null === $db->raw_value( '_transient_timeout_' . $kx ) );
check( 'a2: orphans younger than SWEEP_ORPHAN_AGE (1d, 23h, 47h) kept',
	null !== $db->raw_value( '_transient_' . $orph['o1d'][0] ) && null !== $db->raw_value( '_transient_' . $orph['o47h'][0] ) && null !== $db->raw_value( '_transient_' . $orph['o23r'][0] ) );
check( 'a2: orphans older than 2 days (49h, 30d) deleted', null === $db->raw_value( '_transient_' . $orph['o49h'][0] ) && null === $db->raw_value( '_transient_' . $orph['o30d'][0] ) );
RL::sweep( $now + 2 * HOUR_IN_SECONDS );
check( 'a2: 47h orphan deleted once it passes 2 days; 24h window (now 25h) + timeout deleted',
	null === $db->raw_value( '_transient_' . $orph['o47h'][0] ) && null === $db->raw_value( '_transient_' . $k24 ) && null === $db->raw_value( '_transient_timeout_' . $k24 ) );
RL::sweep( $now + 3 * DAY_IN_SECONDS );
check( 'a2: eventually only nothing is left (all orphans ≥ 2d gone)', 0 === rl_total( $db ) );

/* Ορφανή λήξη (timeout χωρίς value) → σβήνεται όταν λήξει. */
$ko = guest_key( 'parse', '2001:db8:7::1' );
$db->raw_set( '_transient_timeout_' . $ko, (string) ( $now - 5 ) );
RL::sweep( $now );
check( 'a2: expired timeout row without value deleted', null === $db->raw_value( '_transient_timeout_' . $ko ) );

/* --- a3. Κούρσες: η τιμή άλλαξε ανάμεσα σε SELECT και DELETE --- */
$db  = qrrp_sqlite_install();
$kr  = guest_key( 'parse', '2001:db8:10::1' );
$kr2 = guest_key( 'parse', '2001:db8:11::1' );
$kr3 = guest_key( 'parse', '2001:db8:12::1' );
rl_write( $db, $kr, 9, $now - 1000, 600 );
rl_write( $db, $kr2, 9, $now - 1000, 600 );
rl_write( $db, $kr3, 9, $now - 1000, 600 );
$fired        = 0;
$db->before_query = static function ( $sql, $d ) use ( $kr, $kr3, $now, &$fired ) {
	if ( false !== strpos( $sql, 'DELETE timeout_opt' ) ) {
		$d->before_query = null;
		++$fired;
		// Παράλληλο reset_window(): νέα τιμή, η λήξη δεν έχει γραφτεί ακόμη.
		$d->raw_set( '_transient_' . $kr, serialize( array( 'count' => 1, 'start' => $now ) ) );
		// Παράλληλο write_timeout() σε άλλο κλειδί: μόνο η λήξη άλλαξε.
		$d->raw_set( '_transient_timeout_' . $kr3, (string) ( $now + 660 ) );
	}
};
RL::sweep( $now );
check( 'a3: race (join DELETE): window reset concurrently → value+timeout NOT deleted', 1 === $fired && null !== $db->raw_value( '_transient_' . $kr ) && null !== $db->raw_value( '_transient_timeout_' . $kr ) );
check( 'a3: race (join DELETE): timeout rewritten concurrently → pair NOT deleted', null !== $db->raw_value( '_transient_' . $kr3 ) && null !== $db->raw_value( '_transient_timeout_' . $kr3 ) );
check( 'a3: untouched expired window in same batch deleted', null === $db->raw_value( '_transient_' . $kr2 ) && null === $db->raw_value( '_transient_timeout_' . $kr2 ) );
$db->raw_set( '_transient_timeout_' . $kr, (string) ( $now + 660 ) ); // το write_timeout ολοκληρώνεται

$ko2 = guest_key( 'parse', '2001:db8:13::1' );
rl_write( $db, $ko2, 2, $now - 3 * DAY_IN_SECONDS, 600, false );
$fired = 0;
$db->before_query = static function ( $sql, $d ) use ( $ko2, $now, &$fired ) {
	if ( 0 === strpos( ltrim( $sql ), 'DELETE FROM' ) && false !== strpos( $sql, '( option_name =' ) ) {
		$d->before_query = null;
		++$fired;
		$d->raw_set( '_transient_' . $ko2, serialize( array( 'count' => 1, 'start' => $now ) ) );
	}
};
RL::sweep( $now );
check( 'a3: race (delete_exact_rows, orphan pass): changed value NOT deleted', 1 === $fired && null !== $db->raw_value( '_transient_' . $ko2 ) );

/* --- a5. Reset χωρίς εγγραφή λήξης (write_timeout() απέτυχε): start ≥ παλιά λήξη. Μόνο καταγραφή. --- */
$db = qrrp_sqlite_install();
$ks = guest_key( 'parse', '2001:db8:20::1' );
$db->raw_set( '_transient_' . $ks, serialize( array( 'count' => 1, 'start' => $now - 3 * DAY_IN_SECONDS ) ) );
$db->raw_set( '_transient_timeout_' . $ks, (string) ( $now - 3 * DAY_IN_SECONDS - 1 ) );
RL::sweep( $now );
RL::sweep( $now + 30 * DAY_IN_SECONDS );
info( 'a5: window reset whose timeout upsert failed (start ≥ stale expiry): ' . ( 2 === rl_total( $db ) ? 'NEVER swept by either pass (leaks until the same key is hit again)' : 'swept' ) );

/* --- a4. Backlog 25.000 ληγμένων παραθύρων + 3.000 ορφανών + 500 ζωντανά --- */
$db  = qrrp_sqlite_install();
$now = $T0 + 40 * DAY_IN_SECONDS;
$ins = $db->pdo->prepare( 'INSERT INTO wp_options (option_name, option_value, autoload) VALUES (?, ?, ?)' );
$db->pdo->beginTransaction();
$wins = array( 600, 900, DAY_IN_SECONDS );
for ( $i = 0; $i < 25000; $i++ ) {
	$k = 'qrrp_rl_' . md5( 'backlog' . $i );
	$w = $wins[ $i % 3 ];
	$s = $now - $w - 120 - mt_rand( 0, 20 * DAY_IN_SECONDS );
	$ins->execute( array( '_transient_' . $k, serialize( array( 'count' => mt_rand( 1, 99 ), 'start' => $s ) ), 'no' ) );
	$ins->execute( array( '_transient_timeout_' . $k, (string) ( $s + $w + 60 ), 'no' ) );
	if ( 0 === $i % 50 ) { // ζωντανά ανάμεσα στα ληγμένα (keyset)
		$lk = 'qrrp_rl_' . md5( 'live' . $i );
		$ls = $now - mt_rand( 0, HOUR_IN_SECONDS ); // 24ωρα παράθυρα: ζωντανά σε όλη τη διάρκεια του drain
		$ins->execute( array( '_transient_' . $lk, serialize( array( 'count' => 1, 'start' => $ls ) ), 'no' ) );
		$ins->execute( array( '_transient_timeout_' . $lk, (string) ( $ls + DAY_IN_SECONDS + 60 ), 'no' ) );
	}
	if ( 0 === $i % 8 && $i < 24000 ) {
		$ok_ = 'qrrp_rl_' . md5( 'orphan' . $i );
		$ins->execute( array( '_transient_' . $ok_, serialize( array( 'count' => 1, 'start' => $now - 3 * DAY_IN_SECONDS ) ), 'no' ) );
	}
}
$db->pdo->commit();
$live_rows = 1000;
$start_rows = rl_total( $db );
$passes = 0;
$log    = array();
while ( $passes < 20 ) {
	$left_exp = (int) $db->raw( 'SELECT COUNT(*) FROM wp_options WHERE substr(option_name,1,27) = ? AND CAST(option_value AS INTEGER) < ?', array( TO_PFX, $now ) )->fetchColumn();
	if ( 0 === $left_exp && rl_total( $db ) === $live_rows ) { break; }
	$h  = $now + $passes * HOUR_IN_SECONDS; // ένα πέρασμα ανά ώρα (SWEEP_INTERVAL)
	$t0 = microtime( true );
	$ran = RL::maybe_sweep( $h );
	$log[] = sprintf( '%s%d rows left, %.2fs', $ran ? '' : '(throttled) ', rl_total( $db ), microtime( true ) - $t0 );
	++$passes;
}
info( "a4: backlog $start_rows rl rows (25000 expired windows + 3000 old orphans + 500 live); per hourly pass: " . implode( ' | ', $log ) );
check( "a4: backlog drained in $passes hourly passes (≤ 5)", $passes <= 5 && rl_total( $db ) === $live_rows );
check( 'a4: all 500 live windows interleaved with the backlog survived', $live_rows === rl_total( $db ) );

/* ================================================================== */
/* b. Tokens                                                           */
/* ================================================================== */
$db   = qrrp_sqlite_install();
$TOK  = '_transient_qrrp_tok_';
$TOKT = '_transient_timeout_qrrp_tok_';
function tok_rows( $db ) { return $db->count_prefix( '_transient_qrrp_tok_' ) + $db->count_prefix( '_transient_timeout_qrrp_tok_' ); }
function tok_index( $db ) { $r = $db->raw_value( 'qrrp_token_index' ); return null === $r ? array() : unserialize( $r ); }
/* Γραμμές email_rebuild στη βάση (κλειδί χωρίς '_transient_'). */
function email_keys( $db ) {
	$out = array();
	foreach ( $db->rows_prefix( '_transient_qrrp_tok_' ) as $n => $v ) {
		$p = unserialize( $v );
		if ( is_array( $p ) && 'email_rebuild' === ( $p['type'] ?? '' ) ) { $out[] = substr( $n, 11 ); }
	}
	sort( $out );
	return $out;
}
function age_token( $db, $key, $ago = 10 ) {
	$db->raw_set( '_transient_timeout_' . $key, (string) ( time() - $ago ) );
}

$email = array();
$short = array();
for ( $i = 0; $i < 300; $i++ ) { $email[] = QRRP_Tokens::issue( 'email_rebuild', array( 'pc' => "0$i", 'extras' => array() ), 7 * DAY_IN_SECONDS ); }
for ( $i = 0; $i < 200; $i++ ) { $short[] = QRRP_Tokens::issue( 'provenance_challenge', array( 'n' => $i ), 900 ); }
for ( $i = 0; $i < 200; $i++ ) { $short[] = QRRP_Tokens::issue( 'validated_output', array( 'n' => $i ), 900 ); }
check( 'b: 300 email + 400 unindexed tokens issued → 1400 rows, index 300', 700 === count( array_filter( array_merge( $email, $short ) ) ) && 1400 === tok_rows( $db ) && 300 === count( tok_index( $db ) ) );
check( 'b: token rows are hashed (no bearer token in DB)', null === $db->raw_value( '_transient_' . $email[0] ) && null !== $db->raw_value( '_transient_' . QRRP_Tokens::transient_key( $email[0] ) ) );

/* Γήρανση 200 email: λήξη στο ευρετήριο και γραμμή timeout στο παρελθόν. */
$idx  = tok_index( $db );
$aged = array();
foreach ( array_slice( $email, 0, 200 ) as $tk ) {
	$k          = QRRP_Tokens::transient_key( $tk );
	$idx[ $k ]  = time() - 10;
	$aged[]     = $k;
	age_token( $db, $k );
}
$db->raw_set( 'qrrp_token_index', serialize( $idx ) );
$new1 = QRRP_Tokens::issue( 'email_rebuild', array( 'pc' => 'new1' ), 7 * DAY_IN_SECONDS );
$idx  = tok_index( $db );
$gone = 0;
foreach ( $aged as $k ) { if ( null === $db->raw_value( '_transient_' . $k ) && null === $db->raw_value( '_transient_timeout_' . $k ) ) { ++$gone; } }
check( 'b: next issue() prunes index to live only (101 entries)', '' !== $new1 && 101 === count( $idx ) && min( $idx ) > time() );
check( "b: pruned expired tokens' value+timeout rows deleted ($gone/200)", 200 === $gone );
check( 'b: email rows == 2×index (202), unindexed untouched (800)', 202 === 2 * count( email_keys( $db ) ) && 800 === tok_rows( $db ) - 202 );

/* Γέμισμα ως 500 και απόρριψη του 501ου. */
$fill = 0;
while ( count( tok_index( $db ) ) < 500 ) {
	if ( '' === QRRP_Tokens::issue( 'email_rebuild', array( 'pc' => 'f' . $fill ), 7 * DAY_IN_SECONDS ) ) { break; }
	++$fill;
}
$before = tok_rows( $db );
$refuse = QRRP_Tokens::issue( 'email_rebuild', array( 'pc' => 'over' ), 7 * DAY_IN_SECONDS );
check( "b: index filled to 500 ($fill more), 501st refused", 500 === count( tok_index( $db ) ) && '' === $refuse );
check( 'b: refused token left no orphan transient (rows unchanged)', $before === tok_rows( $db ) );

/* Churn: 12 γύροι, λήγουν 40, ζητούνται 55. Ευρετήριο ≤ 500 και ευρετήριο == γραμμές email. */
$churn_ok   = true;
$accepted   = 0;
$refused    = 0;
for ( $round = 0; $round < 12; $round++ ) {
	$idx  = tok_index( $db );
	$keys = array_keys( $idx );
	shuffle( $keys );
	foreach ( array_slice( $keys, 0, 40 ) as $k ) { $idx[ $k ] = time() - 5; age_token( $db, $k, 5 ); }
	$db->raw_set( 'qrrp_token_index', serialize( $idx ) );
	for ( $j = 0; $j < 55; $j++ ) {
		'' === QRRP_Tokens::issue( 'email_rebuild', array( 'pc' => "c$round-$j" ), 7 * DAY_IN_SECONDS ) ? ++$refused : ++$accepted;
	}
	$ik = array_keys( tok_index( $db ) );
	sort( $ik );
	if ( count( $ik ) > 500 || email_keys( $db ) !== $ik ) { $churn_ok = false; }
}
check( "b: churn 12 rounds ($accepted accepted, $refused refused): index ≤ 500 and index == email rows in DB", $churn_ok && 480 === $accepted && 180 === $refused );

/* Μη ευρετηριασμένα: λήγουν, αλλά μόνο ο core (cron) ή η ανάγνωση τα σβήνει. */
$un_keys = array();
foreach ( $short as $tk ) { $un_keys[] = QRRP_Tokens::transient_key( $tk ); age_token( $db, QRRP_Tokens::transient_key( $tk ) ); }
for ( $i = 0; $i < 30; $i++ ) { QRRP_Tokens::issue( 'email_rebuild', array( 'pc' => "x$i" ), 7 * DAY_IN_SECONDS ); }
foreach ( array_slice( $email, 250, 5 ) as $tk ) { QRRP_Tokens::consume( $tk, 'email_rebuild' ); }
RL::sweep( time() + 10 );
$un_left = 0;
foreach ( $un_keys as $k ) { if ( null !== $db->raw_value( '_transient_' . $k ) ) { ++$un_left; } }
check( "b: expired unindexed tokens not cleaned by issue/consume/RL::sweep() (only maybe_sweep hooks the token sweep): $un_left/400 remain", 400 === $un_left );
check( 'b: reading an expired challenge deletes it (core get_transient)', false === QRRP_Tokens::verify_token_for_request( $short[0], 'provenance_challenge' ) && null === $db->raw_value( '_transient_' . $un_keys[0] ) && null === $db->raw_value( '_transient_timeout_' . $un_keys[0] ) );
$email_before = email_keys( $db );
$del = qrrp_sq_delete_expired_transients();
$un_left = 0;
foreach ( $un_keys as $k ) { if ( null !== $db->raw_value( '_transient_' . $k ) || null !== $db->raw_value( '_transient_timeout_' . $k ) ) { ++$un_left; } }
check( "b: core delete_expired_transients() removes them ($del rows), live email tokens untouched", 0 === $un_left && 798 === $del && $email_before === email_keys( $db ) );
check( 'b: after cron, qrrp_tok rows == 2×index', tok_rows( $db ) === 2 * count( tok_index( $db ) ) );
/* ================================================================== */
/* b2. 2.15.4: QRRP_Tokens::sweep_expired() μέσω maybe_sweep(), χωρίς cron */
/* ================================================================== */
/* Token γραμμές όπως τις γράφει το set_transient(), με προσομοιωμένο χρόνο έκδοσης. */
function tok_craft( $db, $type, $issued, $ttl, $n ) {
	$k = 'qrrp_tok_' . hash( 'sha256', "$type|$issued|$n" );
	$db->raw_set( '_transient_timeout_' . $k, (string) ( $issued + $ttl ) );
	$db->raw_set( '_transient_' . $k, serialize( array( 'schema' => 2, 'type' => $type, 'n' => $n ) ) );
	return $k;
}
function tok_expired_left( $db, $now ) {
	return (int) $db->raw( 'SELECT COUNT(*) FROM wp_options WHERE substr(option_name,1,28) = ? AND CAST(option_value AS INTEGER) < ?', array( '_transient_timeout_qrrp_tok_', $now ) )->fetchColumn();
}
function tok_live_rows( $db, $now ) {
	return 2 * (int) $db->raw( 'SELECT COUNT(*) FROM wp_options WHERE substr(option_name,1,28) = ? AND CAST(option_value AS INTEGER) >= ?', array( '_transient_timeout_qrrp_tok_', $now ) )->fetchColumn();
}
/*
 * Μία ημέρα μέγιστης κίνησης επισκεπτών: 1.800 tokens/ώρα (ταβάνι rebuild 300/10'),
 * μισά challenge, μισά validated_output, TTL 900 s, κανένα δεν διαβάζεται.
 * Στο τέλος κάθε ώρας ένα maybe_sweep() (το προκαλεί η γέννηση παραθύρου).
 */
$simday = static function ( $per_hour, $hours ) {
	$db   = qrrp_sqlite_install();
	$S0   = 1800000000;
	$n    = 0;
	$ok   = true;
	$max  = 0;
	$runs = 0;
	$db->pdo->beginTransaction();
	for ( $h = 0; $h < $hours; $h++ ) {
		for ( $i = 0; $i < $per_hour; $i++ ) {
			$type = ( $i & 1 ) ? 'validated_output' : 'provenance_challenge';
			tok_craft( $db, $type, $S0 + $h * HOUR_IN_SECONDS + (int) ( $i * HOUR_IN_SECONDS / $per_hour ), 900, $n++ );
		}
		$now  = $S0 + ( $h + 1 ) * HOUR_IN_SECONDS;
		$max  = max( $max, tok_rows( $db ) );
		$runs += RL::maybe_sweep( $now ) ? 1 : 0;
		$exp  = tok_expired_left( $db, $now );
		if ( $exp > 0 || tok_rows( $db ) !== tok_live_rows( $db, $now ) + 2 * $exp ) { $ok = false; }
		$last = array( tok_rows( $db ), $exp );
	}
	$db->pdo->commit();
	return array( 'ok' => $ok, 'max' => $max, 'runs' => $runs, 'rows' => $last[0], 'expired_left' => $last[1] );
};
$r = $simday( 1800, 24 );
check( "b2: 24h of max guest traffic (1800 tokens/h, 43200 total, no cron): {$r['runs']} hourly passes, after each pass 0 expired rows left, rows == live rows",
	$r['ok'] && 24 === $r['runs'] );
check( "b2: bounded — peak {$r['max']} qrrp_tok rows before a pass (≤ 2×1800 + live), {$r['rows']} after last pass", $r['max'] <= 2 * 1800 + 2 * 450 && $r['rows'] <= 2 * 450 );
$r3 = $simday( 3000, 6 );
info( "b2: above per-pass cap (3000 tokens/h, cap 50×200 per pass): expired rows left after 6 passes = {$r3['expired_left']} (backlog grows ~" . (int) ( $r3['expired_left'] / 6 ) . '/h; core cron still cleans it)' );

/* Backlog πριν την αναβάθμιση (π.χ. εβδομάδα χωρίς cron): 20.000 ληγμένα. */
$db  = qrrp_sqlite_install();
$S0  = 1800000000;
$db->pdo->beginTransaction();
for ( $i = 0; $i < 20000; $i++ ) { tok_craft( $db, ( $i & 1 ) ? 'validated_output' : 'provenance_challenge', $S0 - mt_rand( 1000, 7 * DAY_IN_SECONDS ), 900, $i ); }
$db->pdo->commit();
$passes = 0;
$trace  = array();
while ( tok_rows( $db ) > 0 && $passes < 30 ) {
	RL::maybe_sweep( $S0 + $passes * HOUR_IN_SECONDS );
	++$passes;
	$trace[] = tok_rows( $db ) / 2;
}
/* 2.15.4: έως 50×200 = 10.000 ανά πέρασμα (και όριο χρόνου 0,5 s), άρα ≤ 3 περάσματα για 20.000. */
check( "b2: backlog of 20000 expired tokens drains in ≤ 3 hourly passes: $passes (" . implode( ',', array_slice( $trace, 0, 3 ) ) . ',…)', $passes <= 3 && 0 === tok_rows( $db ) && $trace[0] <= 18000 );

/* Ζωντανά tokens όλων των τύπων ανέγγιχτα· ληγμένα όλων των τύπων σβήνονται· ευρετήριο συνεπές. */
$db   = qrrp_sqlite_install();
$live = array();
$dead = array();
foreach ( array( 'email_rebuild' => 7 * DAY_IN_SECONDS, 'provenance_challenge' => 900, 'validated_output' => 900 ) as $type => $ttl ) {
	for ( $i = 0; $i < 60; $i++ ) {
		$tk = QRRP_Tokens::issue( $type, array( 'n' => $i ), $ttl );
		if ( $i < 20 ) { $dead[] = array( $tk, $type ); } else { $live[] = array( $tk, $type ); }
	}
}
$idx = tok_index( $db );
foreach ( $dead as $d ) {
	$k = QRRP_Tokens::transient_key( $d[0] );
	age_token( $db, $k, 30 );
	if ( 'email_rebuild' === $d[1] ) { $idx[ $k ] = time() - 30; }
}
$db->raw_set( 'qrrp_token_index', serialize( $idx ) );
$before_idx = count( tok_index( $db ) );
$ran = RL::maybe_sweep( time() );
$live_ok = 0;
foreach ( $live as $l ) { if ( is_array( QRRP_Tokens::verify_token_for_request( $l[0], $l[1] ) ) ) { ++$live_ok; } }
$dead_left = 0;
foreach ( $dead as $d ) { $k = QRRP_Tokens::transient_key( $d[0] ); if ( null !== $db->raw_value( '_transient_' . $k ) || null !== $db->raw_value( '_transient_timeout_' . $k ) ) { ++$dead_left; } }
check( "b2: maybe_sweep ran; 120 live tokens of all 3 types untouched ($live_ok/120)", $ran && 120 === $live_ok && 240 === tok_rows( $db ) );
check( "b2: 60 expired tokens of all 3 types (incl. 20 email_rebuild) swept ($dead_left left)", 0 === $dead_left );
check( "b2: index still lists the 20 swept email keys until next mutation ($before_idx entries)", 60 === count( tok_index( $db ) ) );
QRRP_Tokens::issue( 'email_rebuild', array( 'n' => 'after' ), 7 * DAY_IN_SECONDS );
$ik = array_keys( tok_index( $db ) );
sort( $ik );
check( 'b2: next issue() prunes index → 41 live entries == email rows in DB', 41 === count( $ik ) && email_keys( $db ) === $ik && 242 === tok_rows( $db ) );

/* ================================================================== */
/* c. consume() μίας χρήσης                                            */
/* ================================================================== */
$db = qrrp_sqlite_install();
$tk = QRRP_Tokens::issue( 'email_rebuild', array( 'pc' => 'c1' ), DAY_IN_SECONDS );
$k  = QRRP_Tokens::transient_key( $tk );
$p1 = QRRP_Tokens::consume( $tk, 'email_rebuild' );
$p2 = QRRP_Tokens::consume( $tk, 'email_rebuild' );
check( 'c: first consume returns payload, second false', is_array( $p1 ) && 'c1' === $p1['pc'] && false === $p2 );
check( 'c: consumed token rows gone and key removed from index', 0 === tok_rows( $db ) && ! isset( tok_index( $db )[ $k ] ) );
$tk2 = QRRP_Tokens::issue( 'provenance_challenge', array( 'n' => 1 ), 900 );
check( 'c: wrong type does not consume', false === QRRP_Tokens::consume( $tk2, 'email_rebuild' ) && 2 === tok_rows( $db ) );
check( 'c: unindexed consume single-use', is_array( QRRP_Tokens::consume( $tk2, 'provenance_challenge' ) ) && false === QRRP_Tokens::consume( $tk2, 'provenance_challenge' ) && 0 === tok_rows( $db ) );
/* Κούρσα: το transient σβήστηκε από άλλο αίτημα μετά το verify → false. */
$tk3 = QRRP_Tokens::issue( 'email_rebuild', array( 'pc' => 'c3' ), DAY_IN_SECONDS );
$k3  = QRRP_Tokens::transient_key( $tk3 );
$db->before_query = static function ( $sql, $d ) use ( $k3 ) {
	if ( 0 === strpos( $sql, 'DELETE FROM' ) && false !== strpos( $sql, "'_transient_{$k3}'" ) ) {
		$d->before_query = null;
		$d->raw_delete( '_transient_' . $k3 ); // ο άλλος κέρδισε
	}
};
check( 'c: concurrent consumer deleted row between verify and delete → false', false === QRRP_Tokens::consume( $tk3, 'email_rebuild' ) );

/* ================================================================== */
/* d. purge_all() (uninstall)                                          */
/* ================================================================== */
$db = qrrp_sqlite_install();
for ( $i = 0; $i < 50; $i++ ) { QRRP_Tokens::issue( 'email_rebuild', array( 'pc' => "p$i" ), 7 * DAY_IN_SECONDS ); }
for ( $i = 0; $i < 50; $i++ ) { QRRP_Tokens::issue( 'validated_output', array( 'n' => $i ), 900 ); }
$db->raw_set( '_transient_timeout_qrrp_tok_' . str_repeat( 'a', 64 ), (string) ( time() - 99 ) ); // ορφανή λήξη
$db->raw_set( '_transient_qrrp_tok_' . str_repeat( 'b', 64 ), 'legacy' );                       // χωρίς λήξη
$idx = tok_index( $db );
$idx[ 'qrrp_tok_' . str_repeat( 'c', 64 ) ] = time() + 99; // εγγραφή χωρίς transient
$db->raw_set( 'qrrp_token_index', serialize( $idx ) );
$db->raw_set( 'xtransient_qrrp_tok_decoy', 'keep' );
$db->raw_set( '_transient_qrrp_rl_keep', 'keep' );
$db->raw_set( '_transient_other_plugin', 'keep' );
$n = QRRP_Tokens::purge_all();
$left = (int) $db->raw( "SELECT COUNT(*) FROM wp_options WHERE option_name LIKE '%qrrp\\_tok\\_%' ESCAPE '\\' AND option_name <> 'xtransient_qrrp_tok_decoy'" )->fetchColumn();
check( "d: purge_all() removed every qrrp_tok row (returned $n) and the index", 0 === $left && null === $db->raw_value( 'qrrp_token_index' ) );
check( 'd: purge_all() left unrelated rows (incl. LIKE decoy)', 'keep' === $db->raw_value( 'xtransient_qrrp_tok_decoy' ) && 'keep' === $db->raw_value( '_transient_qrrp_rl_keep' ) && 'keep' === $db->raw_value( '_transient_other_plugin' ) );
$db->raw_set( 'qrrp_token_index', 'garbage' );
QRRP_Tokens::issue( 'provenance_challenge', array( 'n' => 1 ), 900 );
QRRP_Tokens::purge_all();
check( 'd: purge_all() with corrupt index still removes rows and index', 0 === tok_rows( $db ) && null === $db->raw_value( 'qrrp_token_index' ) );

info( sprintf( 'runtime %.1fs', microtime( true ) - $T_START ) );
echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
