<?php
/*
 * 2.16.1 — χωρίς separators, ένας κωδικός που διαβάζεται ολόκληρος και ως
 * κωδικός χωρίς κάποιο πεδίο δεν γίνεται ποτέ αυτόματα δεκτός.
 * php t_absent_field.php
 */
if ( false === getenv('QRRP_TEST_NOW') ) { putenv('QRRP_TEST_NOW=1790683200'); }
require 'boot.php';
$fails=0;
function check($label,$ok){ global $fails; if(!$ok) $fails++; echo ($ok?'PASS':'FAIL'),' ',$label,"\n"; }
$P='0108006540718100';
$cases=array(
	/* Λείπει LOT· το «10» μέσα στο SN. */
	array($P.'17280331'.'21ABCD10EFGH', 'LOT', 'SN «ABCD10EFGH» χωρίς LOT'),
	/* Λείπει SN· το «21» μέσα στο LOT. */
	array($P.'17280331'.'1012345621ABCDEFGH', 'SN', 'LOT «12345621ABCDEFGH» χωρίς SN'),
	/* Λείπει EXP· το «17» + ημερομηνία μέσα στο SN. */
	array($P.'10LOT123'.'21ABCD17280131', 'EXP', 'χωρίς EXP'),
);
foreach($cases as $c){
	$r=QRRP_GS1_Parser::parse($c[0]);
	$w=implode(' | ',$r['warnings']);
	check($c[1].' may be absent → requires_confirmation', true===$r['requires_confirmation'] && false===$r['inference_auto_accepted']);
	check('  ...warning names the absent-field reading ('.$c[2].')', false!==strpos($w,$c[2]));
}
/* Κανονικοί κωδικοί με separators: αμετάβλητοι. */
$r=QRRP_GS1_Parser::parse($P.'17280331'."10NK4032\x1d21YN68XRRFDZP");
check('real GS-delimited code still auto-accepted, no confirmation', false===$r['requires_confirmation'] && 'NK4032'===$r['fields']['LOT'] && 'YN68XRRFDZP'===$r['fields']['SN']);
echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit($fails?1:0);
