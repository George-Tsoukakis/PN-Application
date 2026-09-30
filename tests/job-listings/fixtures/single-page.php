<?php
/* Renders the view beacon (jbli_print_view_beacon) for listing 42. TEST-ONLY. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'JBLI_CPT', 'job_listing' );
function is_admin() { return false; }
function is_singular() { return true; }
function get_queried_object_id() { return 42; }
function absint( $v ) { return abs( (int) $v ); }
function admin_url( $p ) { return 'http://t.local/wp-admin/' . $p; }
function wp_json_encode( $v ) { return json_encode( $v ); }
$src   = file_get_contents( dirname( __DIR__, 3 ) . '/job-listings/includes/jbli-view-counter.php' );
$start = strpos( $src, 'function jbli_print_view_beacon' );
eval( '?><?php ' . substr( $src, $start, strpos( $src, "add_action( 'wp_footer'", $start ) - $start ) );
?><!doctype html><html><head><meta charset="utf-8"></head><body>
<span data-jbli_views_count="42">5</span> προβολές
<?php jbli_print_view_beacon(); ?></body></html>
