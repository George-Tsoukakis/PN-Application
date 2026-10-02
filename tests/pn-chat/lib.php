<?php
/**
 * PN Chat — tiny PHP test harness against a real test WordPress (MariaDB).
 * TEST-ONLY. Loaded by every tests/pn-chat/test-*.php; see run.sh.
 *
 * WP_LOAD (env) = path to wp-load.php, default /home/claude/wpenv/wp-load.php.
 * The tests change PN Chat data of that site: use a throw-away WordPress.
 */

if ( ! isset( $_SERVER['HTTP_HOST'] ) ) {
	$_SERVER['HTTP_HOST'] = '127.0.0.1';
}
$pnt_wp_load = getenv( 'WP_LOAD' ) ? getenv( 'WP_LOAD' ) : '/home/claude/wpenv/wp-load.php';
if ( ! is_readable( $pnt_wp_load ) ) {
	fwrite( STDERR, "SKIP: no WordPress at $pnt_wp_load (set WP_LOAD)\n" );
	exit( 2 );
}
require $pnt_wp_load;
if ( ! class_exists( 'PNChat_Store' ) ) {
	fwrite( STDERR, "SKIP: PN Chat is not active on that WordPress\n" );
	exit( 2 );
}
// Never send real mail or reach the network from a test.
add_filter( 'pre_wp_mail', '__return_true' );

$GLOBALS['pnt_fails']   = 0;
$GLOBALS['pnt_passes']  = 0;
$GLOBALS['pnt_cleanup'] = array();

function pnt_check( $ok, $what ) {
	if ( $ok ) {
		++$GLOBALS['pnt_passes'];
		echo 'ok     ' . $what . "\n";
	} else {
		++$GLOBALS['pnt_fails'];
		echo 'NOT OK ' . $what . "\n";
	}
}

function pnt_same( $expected, $actual, $what ) {
	$ok = $expected === $actual;
	pnt_check( $ok, $what . ( $ok ? '' : ' — expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) ) );
}

/** Run a callback at the end (reverse order), even after a failed check. */
function pnt_defer( $fn ) {
	$GLOBALS['pnt_cleanup'][] = $fn;
}

/** Call a private static method. */
function pnt_call( $class, $method, ...$args ) {
	$m = new ReflectionMethod( $class, $method );
	$m->setAccessible( true );
	return $m->invoke( null, ...$args );
}

/** Empties the question log (and restores nothing: tests own the site). */
function pnt_clear_questions() {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', PNChat_Store::questions_table() ) );
}

/** Settings changed for one test, restored at the end. */
function pnt_settings( array $changes ) {
	$before = get_option( PNChat_Settings::OPTION );
	pnt_defer(
		function () use ( $before ) {
			update_option( PNChat_Settings::OPTION, $before, false );
		}
	);
	PNChat_Settings::save( array_merge( PNChat_Settings::get(), $changes ) );
}

/** Snapshot of the entries, put back at the end. */
function pnt_keep_entries() {
	$before = PNChat_Brain::export( false );
	pnt_defer(
		function () use ( $before ) {
			PNChat_Store::replace_entries( array_values( array_filter( array_map( array( 'PNChat_Brain', 'clean_entry' ), $before['entries'] ) ) ) );
		}
	);
}

/** Calls a REST route as a guest from an IP; returns [status, data]. */
function pnt_rest( $route, array $body, $ip = '203.0.113.7' ) {
	$_SERVER['REMOTE_ADDR'] = $ip;
	$req = new WP_REST_Request( 'POST', '/' . PNChat_Rest::NS . $route );
	$req->set_header( 'content-type', 'application/json' );
	$req->set_body( wp_json_encode( $body ) );
	$res = rest_do_request( $req );
	return array( $res->get_status(), $res->get_data() );
}

/** A fake Claude response for pre_http_request. */
function pnt_claude_reply( array $json, $code = 200 ) {
	return array(
		'headers'  => array(),
		'body'     => wp_json_encode(
			200 === $code ? array(
				'stop_reason' => 'end_turn',
				'usage'       => array(
					'input_tokens'  => 1000,
					'output_tokens' => 100,
				),
				'content'     => array(
					array(
						'type' => 'text',
						'text' => wp_json_encode( $json ),
					),
				),
			) : array( 'error' => array( 'message' => 'fake error' ) )
		),
		'response' => array(
			'code'    => $code,
			'message' => 'x',
		),
		'cookies'  => array(),
	);
}

/** Runs the clean-ups once (also after a fatal error, so the site is left as it was). */
function pnt_cleanup() {
	$fns                    = array_reverse( $GLOBALS['pnt_cleanup'] );
	$GLOBALS['pnt_cleanup'] = array();
	foreach ( $fns as $fn ) {
		try {
			$fn();
		} catch ( Throwable $e ) {
			echo 'cleanup: ' . $e->getMessage() . "\n";
		}
	}
}
register_shutdown_function(
	function () {
		$err = error_get_last();
		if ( $GLOBALS['pnt_cleanup'] && $err && in_array( $err['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
			echo 'NOT OK fatal: ' . $err['message'] . ' (' . basename( $err['file'] ) . ':' . $err['line'] . ")\n";
			pnt_cleanup();
		}
	}
);

function pnt_done() {
	pnt_cleanup();
	echo "\n" . $GLOBALS['pnt_passes'] . ' passed, ' . $GLOBALS['pnt_fails'] . " failed\n";
	exit( $GLOBALS['pnt_fails'] ? 1 : 0 );
}
