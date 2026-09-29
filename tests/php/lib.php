<?php
/**
 * PlanDose 1.27.0 — tiny PHP test harness against the real test WordPress.
 * TEST-ONLY. Loaded by every tests/php/test-*.php; see run.sh.
 *
 * WordPress is loaded with wp-load.php directly (NOT wp eval-file): WP-CLI
 * defines WP_CLI, which PlanDose treats as a trusted context, and some
 * tests need a plain web-like request with no user.
 *
 * WP_LOAD (env) = path to wp-load.php, default /home/claude/wpenv/wp-load.php.
 */

if ( ! isset( $_SERVER['HTTP_HOST'] ) ) {
	$_SERVER['HTTP_HOST'] = '127.0.0.1:8899';
}

$pdt_wp_load = getenv( 'WP_LOAD' ) ? getenv( 'WP_LOAD' ) : '/home/claude/wpenv/wp-load.php';

if ( ! is_readable( $pdt_wp_load ) ) {
	fwrite( STDERR, "SKIP: no WordPress at $pdt_wp_load (set WP_LOAD)\n" );
	exit( 2 );
}

require $pdt_wp_load;
require_once ABSPATH . 'wp-admin/includes/user.php';

$GLOBALS['pdt_fails']   = 0;
$GLOBALS['pdt_passes']  = 0;
$GLOBALS['pdt_cleanup'] = array();

function pdt_check( $ok, $what ) {
	if ( $ok ) {
		++$GLOBALS['pdt_passes'];
		echo 'ok     ' . $what . "\n";
	} else {
		++$GLOBALS['pdt_fails'];
		echo 'NOT OK ' . $what . "\n";
	}
}

function pdt_same( $expected, $actual, $what ) {
	$ok = $expected === $actual;
	pdt_check( $ok, $what . ( $ok ? '' : ' — expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) ) );
}

/** Run a callback at the end (reverse order), even after a failed check. */
function pdt_defer( $fn ) {
	$GLOBALS['pdt_cleanup'][] = $fn;
}

/** Call a private/protected static method. */
function pdt_call( $class, $method, ...$args ) {
	$m = new ReflectionMethod( $class, $method );
	$m->setAccessible( true );
	return $m->invoke( null, ...$args );
}

/**
 * A throwaway user, deleted at the end.
 *
 * @param array $meta Meta to set (raw values).
 * @param string $role Role.
 * @return int
 */
function pdt_user( $meta = array(), $role = 'subscriber' ) {
	$login = 'pdt_' . wp_generate_password( 10, false, false );
	$id    = wp_insert_user(
		array(
			'user_login'   => $login,
			'user_pass'    => wp_generate_password( 20 ),
			'user_email'   => $login . '@example.test',
			'display_name' => 'PDT ' . $login,
			'role'         => $role,
		)
	);

	if ( is_wp_error( $id ) ) {
		fwrite( STDERR, 'could not create user: ' . $id->get_error_message() . "\n" );
		exit( 1 );
	}

	foreach ( $meta as $key => $value ) {
		add_user_meta( $id, $key, $value );
	}

	pdt_defer(
		static function () use ( $id ) {
			global $wpdb;
			wp_set_current_user( 0 );
			$wpdb->delete( Plandose_Subscriptions::table_name(), array( 'user_id' => $id ) );
			$wpdb->delete( Plandose_Subscriptions::history_table_name(), array( 'user_id' => $id ) );
			$wpdb->delete( Plandose_Print_Charges::table_name(), array( 'user_id' => $id ) );
			$wpdb->delete( Plandose_Print_Charges::requests_table_name(), array( 'user_id' => $id ) );
			if ( get_userdata( $id ) ) {
				wp_delete_user( $id );
			}
			if ( class_exists( 'Plandose_Admin' ) && method_exists( 'Plandose_Admin', 'forget_deleted_account' ) ) {
				Plandose_Admin::forget_deleted_account( $id );
			}
			$wpdb->delete( Plandose_Subscriptions::table_name(), array( 'user_id' => $id ) );
		}
	);

	return (int) $id;
}

function pdt_done() {
	foreach ( array_reverse( $GLOBALS['pdt_cleanup'] ) as $fn ) {
		try {
			$fn();
		} catch ( Throwable $e ) {
			echo 'cleanup error: ' . $e->getMessage() . "\n";
		}
	}

	echo sprintf( "\n%d passed, %d failed\n", $GLOBALS['pdt_passes'], $GLOBALS['pdt_fails'] );
	exit( $GLOBALS['pdt_fails'] > 0 ? 1 : 0 );
}

register_shutdown_function(
	static function () {
		$e = error_get_last();
		if ( $e && in_array( $e['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
			echo 'FATAL: ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line'] . "\n";
		}
	}
);
