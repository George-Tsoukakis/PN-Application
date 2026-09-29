<?php require 'bootp.php';
// 2.15.7: μικτοί separators / HRI — συναγόμενο όριο μέσα σε τιμή που ο κωδικός
// είχε κλείσει ρητά δεν γίνεται ποτέ αυτόματα αποδεκτό.
$fail=0; $PC='08006540718100'; $GS="\x1d";
function ok($c,$m){ global $fail; if(!$c) $fail++; echo ($c?"PASS":"FAIL")," $m\n"; }
function has_warn($p,$needle){ foreach($p['warnings'] as $w) if(strpos($w,$needle)!==false) return true; return false; }
$NEEDLE='χρειάστηκε να χωριστεί τιμή που ο κωδικός είχε ήδη κλείσει';

// 1. Το εύρημα: SN «AB17280331» έσπαγε σε SN «AB» + επινοημένο EXP, αυτόματα.
$raw="01{$PC}21AB17280331{$GS}10LOT1";
$p=QRRP_GS1_Parser::parse($raw);
ok($p['requires_confirmation']===true, 'raw 21AB17280331<GS>10LOT1: requires confirmation');
ok($p['inference_auto_accepted']===false, 'raw: not auto-accepted');
ok(has_warn($p,$NEEDLE), 'raw: warning about splitting a closed value');
ok(!has_warn($p,'δεν περιείχε Group Separator'), 'raw: no false "no GS" notice');
$v=ev($raw,F($PC,'AB','LOT1','2028-03-31'));
ok(!$v['allowed'], 'provenance: split reading without ack is refused');

// 2. Το ίδιο σε HRI: οι παρενθέσεις είναι ρητά όρια.
$hri="(01){$PC}(21)AB17280331(10)LOT1";
$p=QRRP_GS1_Parser::parse($hri);
ok($p['requires_confirmation']===true && $p['inference_auto_accepted']===false, 'HRI: requires confirmation, not auto-accepted');
ok(has_warn($p,$NEEDLE), 'HRI: warning about splitting a closed value');

// 3. LOT που «γεννά» EXP.
$p=QRRP_GS1_Parser::parse("01{$PC}10L17280331{$GS}21SERIAL1");
ok($p['requires_confirmation']===true && $p['inference_auto_accepted']===false, '10L17280331<GS>21SERIAL1: requires confirmation');

// 4. Χωρίς κανέναν GS (soup): η αυτόματη αποδοχή μένει όπως ήταν.
$soup="01{$PC}17280331"."10ABCDEF21XYZ";
$p=QRRP_GS1_Parser::parse($soup);
ok(!has_warn($p,$NEEDLE), 'soup without GS: no mixed-separator warning');
$p2=QRRP_GS1_Parser::parse("01{$PC}21AB1728033110LOT1");
ok(!has_warn($p2,$NEEDLE), 'soup 21AB1728033110LOT1: no mixed-separator warning');

// 5. GS μόνο μετά από fixed-length (01<GS>) δεν μετρά ως ρητό όριο.
$p=QRRP_GS1_Parser::parse("01{$PC}{$GS}21AB1728033110LOT1");
$p0=QRRP_GS1_Parser::parse("01{$PC}21AB1728033110LOT1");
ok(!has_warn($p,$NEEDLE), '01<GS> + soup: no mixed-separator warning');
ok($p['inference_auto_accepted']===$p0['inference_auto_accepted'] && $p['requires_confirmation']===$p0['requires_confirmation'], '01<GS> + soup: same verdict as soup without GS');

// 6. Πλήρεις separators: καμία συναγωγή, κανένα νέο μήνυμα.
$p=QRRP_GS1_Parser::parse("01{$PC}21AB17280331{$GS}10LOT1{$GS}17290131");
ok($p['fields']['SN']==='AB17280331' && $p['inference_used']===false && !has_warn($p,$NEEDLE), 'full separators: SN kept whole, no inference');
ok($p['requires_confirmation']===false, 'full separators: no confirmation needed');

exit($fail?1:0);
