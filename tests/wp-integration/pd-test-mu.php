<?php
/**
 * PlanDose — TEST-ONLY mu-plugin (never ship, never install on a real site).
 *
 * Copy (or symlink) into wp-content/mu-plugins/ of a throwaway WordPress.
 * Inert unless wp-config.php has BOTH
 *
 *     define( 'WP_ENVIRONMENT_TYPE', 'local' );
 *     define( 'PLANDOSE_TESTS', true );
 *
 * and then only for a logged-in user listed in PLANDOSE_TEST_USERS
 * (comma-separated logins; a trailing * matches a prefix, e.g.
 * define( 'PLANDOSE_TEST_USERS', 'pharm1,pharmpro,pdt_*' );)
 * or one with manage_options.
 *
 * Read-only (GET):
 *   ?pd_test=nonce    { nonce: plandose AJAX nonce, test_nonce: nonce for the POST actions below }
 *   ?pd_test=state    counter + charge/request rows of the logged-in user
 *
 * State-changing (POST to /?pd_test=<action> with _pd_test_nonce=<test_nonce>):
 *   reset                      clears the user's PlanDose rows, locks and the fault
 *   fault      value=F         arms fault F (see below) for the NEXT request(s); value='' clears
 *   hold_lock  token=T [request=R]  takes the print lock for T (as a running copy of request R would)
 *   charge_later token=T ms=N  writes T's charge row N ms later (N ≤ 5000; a concurrent charge)
 *   age        seconds=S       moves the user's charge and request rows S seconds into the past
 *
 * Faults (option pd_fault = "<fault>|<user id>": fire only in requests of the
 * user who armed them; each "kill" is a real KILL of the request's own
 * MariaDB connection through a second connection — wpdb then reconnects and
 * re-runs the statement in autocommit, as on a real server restart):
 *   kill_before_increment              before the print_count UPDATE
 *   kill_before_increment_fail_once    same, and the next charge-row INSERT fails once
 *   kill_before_increment_fail_always  same, and every charge-row INSERT after it fails
 *   kill_before_charge_insert          before the charge-row write inside the print transaction
 *   kill_after_charge_insert           right after the charge-row write (before the next statement)
 *   kill_before_commit                 before COMMIT of the print transaction
 *   stall_after_commit[:ms]            after COMMIT, the next statement waits ms (default 3000,
 *                                      max 5000) — the client gives up before the answer arrives
 * A kill_* / stall_* fault disarms itself when it fires (one request only);
 * the kill_before_increment* faults stay armed until cleared (older tests).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! ( defined( 'PLANDOSE_TESTS' ) && true === PLANDOSE_TESTS && function_exists( 'wp_get_environment_type' ) && 'local' === wp_get_environment_type() ) ) {
	return;
}

const PD_TEST_MAX_MS = 5000;

$GLOBALS['pd_test'] = array(
	'killed'       => false,
	'failed_once'  => false,
	'in_tx'        => false,
	'after_insert' => false,
	'committed'    => false,
	'fired'        => false,
);

/** A second connection: KILL, and writes that must not go through wpdb. */
function pd_test_side_connection() {
	$host = DB_HOST;
	$port = null;
	if ( preg_match( '/^(.+):(\d+)$/', $host, $m ) ) {
		$host = $m[1];
		$port = (int) $m[2];
	}
	return new mysqli( $host, DB_USER, DB_PASSWORD, DB_NAME, $port );
}

function pd_test_kill_own_connection( $why ) {
	global $wpdb;
	$thread = $wpdb->dbh instanceof mysqli ? (int) $wpdb->dbh->thread_id : 0;
	$side   = pd_test_side_connection();
	$side->query( 'KILL ' . $thread );
	$side->close();
	error_log( 'pd-test: killed connection ' . $thread . ' ' . $why );
	usleep( 100000 );
}

/** One-shot faults disarm themselves (through the side connection). */
function pd_test_disarm() {
	global $wpdb;
	$GLOBALS['pd_test']['fired'] = true;
	$side = pd_test_side_connection();
	$side->query( "DELETE FROM {$wpdb->options} WHERE option_name = 'pd_fault'" );
	$side->close();
}

function pd_test_user_allowed() {
	if ( ! is_user_logged_in() ) {
		return false;
	}
	if ( current_user_can( 'manage_options' ) ) {
		return true;
	}
	$list = defined( 'PLANDOSE_TEST_USERS' ) ? array_filter( array_map( 'trim', explode( ',', (string) PLANDOSE_TEST_USERS ) ) ) : array();
	$login = wp_get_current_user()->user_login;
	foreach ( $list as $entry ) {
		/* 'pdt_*' admits every login with that prefix (users a test creates). */
		if ( $entry === $login || ( '*' === substr( $entry, -1 ) && 0 === strpos( $login, substr( $entry, 0, -1 ) ) ) ) {
			return true;
		}
	}
	return false;
}

add_filter(
	'query',
	static function ( $query ) {
		static $busy = false;
		if ( $busy || ! is_string( $query ) ) {
			return $query;
		}
		$busy  = true;
		$fault = (string) get_option( 'pd_fault', '' );
		/* A fault is armed for one user ("name|user_id"), so parallel test
		   runs with other users are not hit by it. */
		$owner = 0;
		if ( false !== strpos( $fault, '|' ) ) {
			list( $fault, $owner ) = explode( '|', $fault, 2 );
			$owner = (int) $owner;
		}
		$me   = ( '' !== $fault && $owner && did_action( 'set_current_user' ) ) ? get_current_user_id() : 0;
		$busy = false;

		if ( '' === $fault || ( $owner && $me !== $owner ) ) {
			return $query;
		}

		$st = &$GLOBALS['pd_test'];
		list( $name, $arg ) = array_pad( explode( ':', $fault, 2 ), 2, '' );

		$is_charge_write = (bool) preg_match( '/^\s*(INSERT\s+INTO|UPDATE)\s+\S*plandose_print_charges\b/is', $query );

		if ( preg_match( '/^\s*START\s+TRANSACTION/i', $query ) ) {
			$st['in_tx'] = true;
		}

		/* Older faults: around the counter UPDATE. */
		if ( 0 === strpos( $name, 'kill_before_increment' ) ) {
			if ( ! $st['killed'] && preg_match( '/^\s*UPDATE\s+\S*plandose_subscriptions\b.*print_count\s*=\s*print_count\s*\+/is', $query ) ) {
				$st['killed'] = true;
				pd_test_kill_own_connection( 'before increment' );
				return $query;
			}
			if ( $st['killed'] && preg_match( '/^\s*INSERT\s+INTO\s+\S*plandose_print_charges\b/is', $query ) ) {
				if ( 'kill_before_increment_fail_always' === $name || ( 'kill_before_increment_fail_once' === $name && ! $st['failed_once'] ) ) {
					$st['failed_once'] = true;
					error_log( 'pd-test: failed a charge INSERT' );
					return 'INSERT INTO pd_test_no_such_table (x) VALUES (1)';
				}
			}
			return $query;
		}

		if ( $st['fired'] ) {
			return $query;
		}

		if ( 'kill_before_charge_insert' === $name && $st['in_tx'] && $is_charge_write ) {
			pd_test_disarm();
			pd_test_kill_own_connection( 'before charge write' );
			return $query;
		}

		if ( 'kill_after_charge_insert' === $name && $st['in_tx'] ) {
			if ( $st['after_insert'] ) {
				pd_test_disarm();
				pd_test_kill_own_connection( 'after charge write' );
				return $query;
			}
			if ( $is_charge_write ) {
				$st['after_insert'] = true;
			}
			return $query;
		}

		if ( 'kill_before_commit' === $name && $st['in_tx'] && preg_match( '/^\s*COMMIT\b/i', $query ) ) {
			pd_test_disarm();
			pd_test_kill_own_connection( 'before COMMIT' );
			return $query;
		}

		if ( 'stall_after_commit' === $name && $st['in_tx'] ) {
			if ( $st['committed'] ) {
				pd_test_disarm();
				$ms = '' === $arg ? 3000 : max( 0, min( PD_TEST_MAX_MS, (int) $arg ) );
				error_log( 'pd-test: stalling ' . $ms . ' ms after COMMIT' );
				usleep( $ms * 1000 );
				return $query;
			}
			if ( preg_match( '/^\s*COMMIT\b/i', $query ) ) {
				$st['committed'] = true;
			}
		}

		return $query;
	},
	1
);

add_action(
	'init',
	static function () {
		// phpcs:disable WordPress.Security.NonceVerification -- test-only; POST actions check their own nonce below.
		if ( empty( $_GET['pd_test'] ) ) {
			return;
		}
		if ( ! pd_test_user_allowed() ) {
			wp_send_json( array( 'error' => 'not a test user' ), 403 );
		}
		global $wpdb;
		$uid = get_current_user_id();
		$do  = sanitize_key( wp_unslash( $_GET['pd_test'] ) );

		if ( 'nonce' === $do ) {
			wp_send_json(
				array(
					'nonce'      => wp_create_nonce( 'plandose_nonce' ),
					'test_nonce' => wp_create_nonce( 'pd_test' ),
				)
			);
		}

		if ( 'state' === $do ) {
			$charges  = $wpdb->get_results( $wpdb->prepare( "SELECT token_hash, charged_ts, reprints FROM {$wpdb->prefix}plandose_print_charges WHERE user_id = %d ORDER BY id", $uid ), ARRAY_A );
			$requests = $wpdb->get_results( $wpdb->prepare( "SELECT request_hash, token_hash, kind, created_ts FROM {$wpdb->prefix}plandose_print_requests WHERE user_id = %d ORDER BY id", $uid ), ARRAY_A );
			wp_send_json(
				array(
					'print_count'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT print_count FROM {$wpdb->prefix}plandose_subscriptions WHERE user_id = %d", $uid ) ),
					'charges'      => count( $charges ),
					'requests'     => count( $requests ),
					'charge_rows'  => $charges,
					'request_rows' => $requests,
					'fault'        => (string) get_option( 'pd_fault', '' ),
					// 1.29.0: the admin print log.
					'log_rows'     => $wpdb->get_results( $wpdb->prepare( "SELECT kind, is_pro, month_count, printed_ts FROM {$wpdb->prefix}plandose_print_log WHERE user_id = %d ORDER BY id", $uid ), ARRAY_A ),
					'user_id'      => $uid,
					'now'          => time(),
				)
			);
		}

		/* Everything below changes state: POST with the test nonce only. */
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) {
			wp_send_json( array( 'error' => 'POST required' ), 405 );
		}
		$nonce = isset( $_POST['_pd_test_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_pd_test_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'pd_test' ) ) {
			wp_send_json( array( 'error' => 'bad nonce' ), 403 );
		}

		if ( 'reset' === $do ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}plandose_subscriptions SET print_count = 0 WHERE user_id = %d", $uid ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}plandose_print_charges WHERE user_id = %d", $uid ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}plandose_print_requests WHERE user_id = %d", $uid ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}plandose_print_log WHERE user_id = %d", $uid ) ); // 1.29.0
			$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%plandose%' OR option_name LIKE '\\_transient\\_timeout\\_%plandose%'" );
			delete_option( 'pd_fault' );
			wp_cache_flush();
			wp_send_json( array( 'ok' => true ) );
		}

		if ( 'fault' === $do ) {
			$value = isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : '';
			$known = array( 'kill_before_increment', 'kill_before_increment_fail_once', 'kill_before_increment_fail_always',
				'kill_before_charge_insert', 'kill_after_charge_insert', 'kill_before_commit', 'stall_after_commit' );
			if ( '' === $value ) {
				delete_option( 'pd_fault' );
				wp_send_json( array( 'ok' => true, 'fault' => '' ) );
			}
			$name = explode( ':', $value, 2 )[0];
			if ( ! in_array( $name, $known, true ) ) {
				wp_send_json( array( 'error' => 'unknown fault ' . $name ), 400 );
			}
			update_option( 'pd_fault', $value . '|' . $uid, false );
			wp_send_json( array( 'ok' => true, 'fault' => $value ) );
		}

		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';

		if ( 'hold_lock' === $do ) {
			$request = isset( $_POST['request'] ) ? sanitize_text_field( wp_unslash( $_POST['request'] ) ) : '';
			$hash    = '' !== $request ? md5( 'plandose_print_request|' . $request ) : '';
			wp_send_json( array( 'held' => (bool) Plandose_Print_Lock::acquire( $uid, $token, $hash ) ) );
		}

		if ( 'charge_later' === $do ) {
			$ms = isset( $_POST['ms'] ) ? (int) $_POST['ms'] : 1000;
			$ms = max( 0, min( PD_TEST_MAX_MS, $ms ) );
			usleep( $ms * 1000 );
			$ok = Plandose_Print_Charges::write_charge( $uid, Plandose_Print_Request::receipt_key( $token ), null );
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}plandose_subscriptions SET print_count = print_count + 1 WHERE user_id = %d", $uid ) );
			wp_send_json( array( 'charged' => $ok ) );
		}

		if ( 'age' === $do ) {
			$s = isset( $_POST['seconds'] ) ? max( 0, (int) $_POST['seconds'] ) : 0;
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}plandose_print_charges SET charged_ts = GREATEST(0, charged_ts - %d) WHERE user_id = %d", $s, $uid ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}plandose_print_requests SET created_ts = GREATEST(0, created_ts - %d) WHERE user_id = %d", $s, $uid ) );
			/* The print lock is a debounce for the same token: gone with time too. */
			$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%plandose%' OR option_name LIKE '\\_transient\\_timeout\\_%plandose%'" );
			wp_cache_flush();
			wp_send_json( array( 'ok' => true ) );
		}

		wp_send_json( array( 'error' => 'unknown action' ), 400 );
		// phpcs:enable WordPress.Security.NonceVerification
	},
	1
);
