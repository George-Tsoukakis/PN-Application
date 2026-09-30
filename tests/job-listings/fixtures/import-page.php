<?php
/* Renders the URL-import script (jbli_import_print_script) into a bare page. TEST-ONLY. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'JBLI_IMPORT_MAX_URLS', 3 );
function __( $s ) { return $s; }
function admin_url( $p ) { return 'http://t.local/wp-admin/' . $p; }
function wp_create_nonce() { return 'N'; }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
$src = file_get_contents( dirname( __DIR__, 3 ) . '/job-listings/modules/import/jbli-import.php' );
eval( substr( $src, strpos( $src, 'function jbli_import_print_script' ) ) );
?><!doctype html><html><head><meta charset="utf-8"></head><body>
<div id="jbli_import_results" hidden><h2>R <span id="jbli_import_progress"></span></h2><table><tbody id="jbli_import_rows"></tbody></table></div>
<form method="post" id="jbli_import_form"><textarea name="jbli_import_urls"></textarea><input name="jbli_import_email"><button type="submit" id="jbli_import_submit">Εισαγωγή</button></form>
<?php jbli_import_print_script(); ?></body></html>
