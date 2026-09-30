<?php require 'bootp.php';
// 2.16.0: χωρίς separators, πεδίο που λείπει από τον κωδικό δεν «επινοείται»
// σιωπηλά: απίθανα κοντό SN/LOT → επιβεβαίωση, ποτέ αυτόματη αποδοχή.
$fail=0; $PC='05201234567894';
function ok($c,$m){ global $fail; if(!$c) $fail++; echo ($c?"PASS":"FAIL")," $m\n"; }
function has_warn($p,$needle){ foreach($p['warnings'] as $w) if(strpos($w,$needle)!==false) return true; return false; }
$NEEDLE='ασυνήθιστα κοντές τιμές';

// 1. Κωδικός χωρίς LOT, SN «AB10CD»: η ανάγνωση SN «AB» + LOT «CD» θέλει επιβεβαίωση.
$p=QRRP_GS1_Parser::parse("01{$PC}17261231"."21AB10CD");
ok($p['requires_confirmation']===true && $p['inference_auto_accepted']===false, 'no LOT, SN AB10CD: requires confirmation');
ok(has_warn($p,$NEEDLE) && has_warn($p,'LOT «CD»'), 'no LOT: warning names the short values');

// 2. Κωδικός χωρίς EXP: επινοημένη λήξη από «17» μέσα στο SN.
$p=QRRP_GS1_Parser::parse("01{$PC}10LOT1"."21AB17270131");
ok($p['requires_confirmation']===true && $p['inference_auto_accepted']===false, 'no EXP, SN AB17270131: requires confirmation');

// 3. Αριθμητικό SN με «10» μέσα: LOT «987» (3 χαρακτήρες).
$p=QRRP_GS1_Parser::parse("01{$PC}17261231"."211234510987");
ok($p['requires_confirmation']===true && $p['inference_auto_accepted']===false, 'numeric SN 1234510987: requires confirmation');

// 4. Κανονική σάρωση χωρίς GS με όλα τα πεδία: η αυτόματη αποδοχή μένει.
$p=QRRP_GS1_Parser::parse("01{$PC}21ABC123"."10LOT1"."17261231");
ok($p['inference_auto_accepted']===true && $p['requires_confirmation']===false, 'normal soup (SN ABC123, LOT LOT1): still auto-accepted');
ok(!has_warn($p,$NEEDLE), 'normal soup: no short-value warning');

// 5. Με GS δεν συνάγεται τίποτα: κοντές τιμές είναι αυτές που γράφει ο κωδικός.
$p=QRRP_GS1_Parser::parse("01{$PC}17261231"."21AB\x1d10CD");
ok($p['requires_confirmation']===false && !has_warn($p,$NEEDLE), 'explicit GS with short values: accepted as written');

// 6. Φίλτρο: ελάχιστο μήκος 2 → η ανάγνωση «AB»/«CD» γίνεται ξανά αυτόματη.
$GLOBALS['__filters']['qrrp_auto_inference_min_length']=function(){ return 2; };
$p=QRRP_GS1_Parser::parse("01{$PC}17261231"."21AB10CD");
ok($p['inference_auto_accepted']===true, 'filter qrrp_auto_inference_min_length=2: auto-accepted');
unset($GLOBALS['__filters']['qrrp_auto_inference_min_length']);

echo $fail ? "FAIL $fail\n" : "";
exit($fail?1:0);
