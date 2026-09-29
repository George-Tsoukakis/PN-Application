<?php require 'bootp.php';
// 2.15.3 Fix 7: στο HRI, επιπλέον AI μέσα σε πιθανή τιμή μεταβλητού μήκους -> επιβεβαίωση.
$fail=0; $PC='05012345678900';
function ok($c,$m){ global $fail; if(!$c) $fail++; echo ($c?"PASS":"FAIL")," $m\n"; }
function has_warn($p,$needle){ foreach($p['warnings'] as $w) if(strpos($w,$needle)!==false) return true; return false; }

// 1. Το παράδειγμα του ticket
$raw="(01){$PC}(17)280331(10)LOT1(21)AB(90)CD";
$p=QRRP_GS1_Parser::parse($raw);
ok($p['fields']['SN']==='AB', 'default split still SN=AB');
ok($p['requires_confirmation']===true && $p['confidence']!=='high' && $p['ambiguous']===true, '(21)AB(90)CD: requires confirmation, not high');
ok(($p['contested_fields']['SN']??null)===array('AB','AB(90)CD'), 'contested SN = [AB, AB(90)CD]');
ok(has_warn($p,'«(90)» ακολουθεί πεδίο μεταβλητού μήκους (SN)'), 'warning names AI 90 and SN');
$adm=QRRP_GS1_Parser::admissible_readings($raw);
ok(count($adm['readings'])===2, 'both readings admissible');
$pt=QRRP_GS1_Parser::passthrough_for_reading($raw,F($PC,'AB','LOT1','280331'));
ok($pt['proven'] && $pt['extras']===array(array('ai'=>'90','value'=>'CD')), 'passthrough SN=AB -> extras [90=CD]');
$pt=QRRP_GS1_Parser::passthrough_for_reading($raw,F($PC,'AB(90)CD','LOT1','280331'));
ok($pt['proven'] && $pt['extras']===array(), 'passthrough SN=AB(90)CD -> no extras');
$v=ev($raw,F($PC,'AB','LOT1','2028-03-31'));
ok(!$v['allowed'] && $v['error_code']==='ambiguity_not_confirmed', 'provenance: no ack -> ambiguity_not_confirmed');
$v=ev($raw,F($PC,'AB(90)CD','LOT1','2028-03-31'),array('acknowledged'=>true));
ok($v['allowed'] && $v['provenance']==='scan', 'provenance: pick AB(90)CD + ack -> scan');

// 2. Extra μετά από LOT
$raw2="(01){$PC}(17)280331(10)LOT1(240)XYZ(21)ABC";
$p=QRRP_GS1_Parser::parse($raw2);
ok($p['requires_confirmation'] && ($p['contested_fields']['LOT']??null)===array('LOT1','LOT1(240)XYZ'), '(10)LOT1(240)XYZ: LOT contested');

// 3. Extra μέσα σε extra: ίδια τέσσερα πεδία, extras αναπόδεικτα
$raw3="(01){$PC}(17)280331(21)ABC(10)LOT1(240)X(241)Y";
$p=QRRP_GS1_Parser::parse($raw3);
ok($p['requires_confirmation'] && has_warn($p,'«(241)» ακολουθεί πεδίο μεταβλητού μήκους (AI 240)'), '(240)X(241)Y: confirmation + warning');
$pt=QRRP_GS1_Parser::passthrough_for_reading($raw3,F($PC,'ABC','LOT1','280331'));
ok(!$pt['proven'] && ($pt['unproven_ais']??null)===array('240','241'), '(240)X(241)Y: passthrough unproven');

// 4. Δεν απορροφάται: σταθερό μήκος πριν, αριθμητικό πεδίο, υπέρβαση μήκους
$p=QRRP_GS1_Parser::parse("(01){$PC}(17)280331(240)XYZ(10)LOT1(21)ABC");
ok(!$p['requires_confirmation'] && $p['confidence']==='high', '(17)...(240): after fixed-length AI -> no confirmation');
$p=QRRP_GS1_Parser::parse("(01){$PC}(17)280331(10)LOT1(30)12(21)ABC(90)ABCDEFGHIJKLMNOP");
ok($p['requires_confirmation'] && !isset($p['contested_fields']['SN']), 'SN "ABC(90)ABCDEFGHIJKLMNOP" > 20 chars: SN not contested');
$p=QRRP_GS1_Parser::parse("(01){$PC}(17)280331(10)LOT1(21)ABC(30)12(91)X");
ok(!has_warn($p,'«(91)»'), '(30)12(91): numeric AI 30 cannot absorb -> no (91) warning');

// 5. Κανονικό HRI αμετάβλητο
foreach(array("(01){$PC}(17)280331(10)LOT1(21)ABC","(01){$PC}(21)ABC(10)LOT1(17)280331","(01){$PC}(10)A(B)C(17)280331(21)X(Y)") as $r){
  $p=QRRP_GS1_Parser::parse($r);
  ok($p['confidence']==='high' && $p['requires_confirmation']===false && empty($p['contested_fields']) && empty($p['normalization']['hri_split_alternatives']), "standard HRI high confidence, no confirmation: $r");
}
echo $fail? "FAILURES: $fail\n" : "ALL PASS\n";
