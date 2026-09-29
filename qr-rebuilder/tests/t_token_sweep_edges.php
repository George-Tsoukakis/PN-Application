<?php
/*
 * 2.15.4 — ακραίες περιπτώσεις του QRRP_Tokens::sweep_expired() σε SQLite:
 * διακοσμητικές γραμμές LIKE (το «_» είναι wildcard χωρίς esc_like), όριο
 * λήξης ακριβώς «τώρα» (ο core το θεωρεί ακόμη ζωντανό), γραμμές τρίτων.
 * php t_token_sweep_edges.php
 */
if ( ! extension_loaded( 'pdo_sqlite' ) ) { echo "SKIP t_token_sweep_edges: pdo_sqlite missing\n"; exit( 0 ); }
require_once __DIR__ . '/lib/pdir.php';
$PD = qrrp_test_pdir();
require __DIR__ . '/boot.php';
require __DIR__ . '/lib/sqlite_wpdb.php';
require $PD . '/includes/class-qrrp-tokens.php';

$fails = 0;
function check( $label, $ok ) { global $fails; if ( ! $ok ) { $fails++; } echo ( $ok ? 'PASS' : 'FAIL' ), ' ', $label, "\n"; }

$db  = qrrp_sqlite_install();
$now = 2000000000;
function put( $db, $key, $timeout ) { $db->raw_set( '_transient_' . $key, 'x' ); $db->raw_set( '_transient_timeout_' . $key, (string) $timeout ); }

put( $db, 'qrrp_tok_' . str_repeat( 'a', 64 ), $now - 1 );   /* ληγμένο → σβήνεται */
put( $db, 'qrrp_tok_' . str_repeat( 'b', 64 ), $now );       /* λήγει ακριβώς τώρα → μένει */
put( $db, 'qrrp_tok_' . str_repeat( 'c', 64 ), $now + 60 );  /* ζωντανό → μένει */
put( $db, 'qrrpXtokX' . str_repeat( 'd', 64 ), $now - 999 ); /* decoy: ταιριάζει μόνο με wildcard «_» */
put( $db, 'qrrp_tokens_other_plugin', $now - 999 );           /* άλλου prefix → μένει */
put( $db, 'other_plugin_qrrp_tok_x', $now - 999 );            /* περιέχει, δεν αρχίζει → μένει */

$n = QRRP_Tokens::sweep_expired( $now );

check( 'expired qrrp_tok token swept (value + timeout)', null === $db->raw_value( '_transient_qrrp_tok_' . str_repeat( 'a', 64 ) ) && null === $db->raw_value( '_transient_timeout_qrrp_tok_' . str_repeat( 'a', 64 ) ) );
check( 'timeout exactly == now is kept (core: still live)', null !== $db->raw_value( '_transient_qrrp_tok_' . str_repeat( 'b', 64 ) ) );
check( 'live token kept', null !== $db->raw_value( '_transient_qrrp_tok_' . str_repeat( 'c', 64 ) ) );
check( 'LIKE decoy «qrrpXtokX…» kept (esc_like escapes «_»)', null !== $db->raw_value( '_transient_qrrpXtokX' . str_repeat( 'd', 64 ) ) );
check( 'other prefixes kept', null !== $db->raw_value( '_transient_qrrp_tokens_other_plugin' ) && null !== $db->raw_value( '_transient_other_plugin_qrrp_tok_x' ) );
check( 'return value counts only deleted tokens', 1 === $n );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
