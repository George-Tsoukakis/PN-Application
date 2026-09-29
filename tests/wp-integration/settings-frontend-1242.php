<?php
/**
 * PlanDose 1.24.2 — integration checks for fixes 7 (page picker) and 8
 * (plandose_defer_stylesheet read late). TEST-ONLY.
 * Run: php settings-frontend-1242.php /path/to/wp-load.php
 */
$_SERVER['HTTP_HOST'] = '127.0.0.1:8899';
require $argv[1];

$fails = 0;
function check( $ok, $what ) {
	global $fails;
	echo ( $ok ? 'ok     ' : 'NOT OK ' ) . $what . "\n";
	if ( ! $ok ) {
		++$fails;
	}
}

// ---- Fix 7: a selected page that is not among the listed published pages.
$draft   = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'PD draft page' ) );
$private = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'private', 'post_title' => 'PD private page' ) );
$public  = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'PD public page' ) );

$m = new ReflectionMethod( 'Plandose_Admin_Settings', 'display_scope_field' );
$m->setAccessible( true );
ob_start();
$m->invoke( null, array( 'display_scope' => 'selected', 'display_pages' => array( $draft, $private, $public ) ) );
$html = ob_get_clean();

foreach ( array( $draft => 'draft', $private => 'private', $public => 'published' ) as $pid => $label ) {
	check(
		(bool) preg_match( '/<option value="' . $pid . '"\s+selected>/', $html ),
		"fix 7: selected $label page #$pid has a selected <option> (kept on the next save)"
	);
}
check( false !== strpos( $html, 'PD draft page — ' ), 'fix 7: non-published page is labelled with its status' );

wp_delete_post( $draft, true );
wp_delete_post( $private, true );
wp_delete_post( $public, true );

// ---- Fix 8: opt-out added AFTER plugins_loaded (as a theme's functions.php does).
$tag = '<link rel="stylesheet" id="plandose-css" href="https://x.test/p.css" media="all" />';
$out = apply_filters( 'style_loader_tag', $tag, 'plandose', 'https://x.test/p.css', 'all' );
check( false !== strpos( $out, 'rel="preload"' ), 'fix 8: deferred by default' );

add_filter( 'plandose_defer_stylesheet', '__return_false' );
$out = apply_filters( 'style_loader_tag', $tag, 'plandose', 'https://x.test/p.css', 'all' );
check( $out === $tag, 'fix 8: theme-level opt-out keeps the plain <link> (no inline onload)' );

echo $fails ? "FAILED: $fails\n" : "ALL OK\n";
exit( $fails ? 1 : 0 );
