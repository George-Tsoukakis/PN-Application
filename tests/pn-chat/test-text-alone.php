<?php
/**
 * PN Chat text folding, loaded alone (no WordPress): Greek, Greeklish and
 * spelling slips fold to the same tokens. TEST-ONLY.
 */
define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__, 2 ) . '/pn-chat/includes/class-pnchat-text.php';

$fails = 0;
$cases = array(
	array( 'Τι είναι το PlanDose;', 'τι ειναι το plandose' ),
	array( 'Τι είναι το PlanDose;', 'ti einai to plandose' ),
	array( 'εκτύπωση', 'εκτιποσι' ),
	array( 'θέλω', 'thelo' ),
	array( 'φαρμακείο', 'farmakeio' ),
	array( 'μπορώ', 'mporw' ),
);
foreach ( $cases as $c ) {
	$ok = PNChat_Text::fold( $c[0] ) === PNChat_Text::fold( $c[1] );
	echo ( $ok ? 'ok     ' : 'NOT OK ' ) . 'fold «' . $c[0] . '» = «' . $c[1] . "»\n";
	$fails += $ok ? 0 : 1;
}
$parts = PNChat_Text::split_parts( 'Τι είναι το PlanDose και πόσο κοστίζει;' );
$ok    = 2 === count( $parts );
echo ( $ok ? 'ok     ' : 'NOT OK ' ) . "split «… και πόσο …» into 2 parts\n";
$fails += $ok ? 0 : 1;
$ok     = PNChat_Text::token_similarity( PNChat_Text::fold( 'εκτύπωση' ), PNChat_Text::fold( 'εκτυπώσεις' ) ) >= 0.75;
echo ( $ok ? 'ok     ' : 'NOT OK ' ) . "εκτύπωση ~ εκτυπώσεις\n";
$fails += $ok ? 0 : 1;
echo "\n" . ( $fails ? "$fails failed\n" : "all passed\n" );
exit( $fails ? 1 : 0 );
