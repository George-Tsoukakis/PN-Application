<?php require 'bootp.php';
// 2.15.3 Fix 5: «Σ» = S ή W, «΅» = W, «;» (U+037E) = q.
$P="0105012345678900"; $GS="\x1d"; $fail=0;
function ok($c,$m){ global $fail; if(!$c) $fail++; echo ($c?"PASS":"FAIL")," $m\n"; }
function has_warn($p,$needle){ foreach($p['warnings'] as $w) if(strpos($w,$needle)!==false) return true; return false; }
function sig_in($adm,$f){ foreach($adm['readings'] as $r) if($r==$f) return true; return false; }

// 1. Σ στο SN
$raw="{$P}17280331"."10LOT1{$GS}21ΑΒΣ12";
$p=QRRP_GS1_Parser::parse($raw);
ok($p['fields']['SN']==='ABS12', 'Σ default reading is S (SN=ABS12)');
ok($p['requires_confirmation']===true && $p['confidence']!=='high' && $p['ambiguous']===true, 'Σ: requires confirmation, not high confidence, ambiguous');
ok(($p['contested_fields']['SN']??null)===array('ABS12','ABW12'), 'Σ: contested SN = [ABS12, ABW12] (picker offers both)');
ok(!isset($p['contested_fields']['LOT']), 'Σ: LOT not contested');
ok(has_warn($p,'SN θέση 3'), 'Σ: warning names field and position (SN θέση 3)');
$adm=QRRP_GS1_Parser::admissible_readings($raw);
ok(sig_in($adm,array('PC'=>'05012345678900','SN'=>'ABS12','LOT'=>'LOT1','EXP'=>'280331')) && sig_in($adm,array('PC'=>'05012345678900','SN'=>'ABW12','LOT'=>'LOT1','EXP'=>'280331')), 'Σ: both S and W readings admissible');
$v=ev($raw,F('05012345678900','ABW12','LOT1','2028-03-31'));
ok(!$v['allowed'] && $v['error_code']==='ambiguity_not_confirmed' && isset($v['contested_fields']['SN']), 'provenance: W pick without ack -> 409 ambiguity_not_confirmed with contested SN');
$v=ev($raw,F('05012345678900','ABW12','LOT1','2028-03-31'),array('acknowledged'=>true));
ok($v['allowed'] && $v['provenance']==='scan', 'provenance: W pick + ack -> scan (no manual reconstruction)');
$v=ev($raw,F('05012345678900','ABS12','LOT1','2028-03-31'),array('acknowledged'=>true));
ok($v['allowed'] && $v['provenance']==='scan', 'provenance: S pick + ack -> scan');

// 2. Δύο Σ σε SN και LOT
$raw2="{$P}17280331"."10ΛΣ1{$GS}21ΑΣΣ";
$p=QRRP_GS1_Parser::parse($raw2);
ok(count($p['contested_fields']['SN']??array())===4 && count($p['contested_fields']['LOT']??array())===2, '3x Σ: SN has 4 candidates, LOT 2');
ok(has_warn($p,'LOT θέση 2') && has_warn($p,'SN θέση 2, 3'), '3x Σ: warning lists LOT θέση 2 and SN θέση 2, 3');
ok(count(QRRP_GS1_Parser::admissible_readings($raw2)['readings'])===8, '3x Σ: 8 admissible readings');

// 3. Πολλά Σ (> MAX_SIGMA_ENUMERATED): όλα S, όλα W, μία αλλαγή
$raw3="{$P}17280331"."10LOT1{$GS}21ΣΣΣΣΣ";
$p=QRRP_GS1_Parser::parse($raw3);
ok($p['requires_confirmation'] && in_array('WWWWW',$p['contested_fields']['SN'],true) && count($p['contested_fields']['SN'])===2 && has_warn($p,'SN θέση 1, 2, 3, 4, 5'), '5x Σ: only all-S/all-W offered, all positions named');

// 4. «΅» (Windows Shift+W) και U+037E
$p=QRRP_GS1_Parser::parse("{$P}17280331"."10LOT1{$GS}21ΑΒ\u{0385}12");
ok($p['fields']['SN']==='ABW12' && empty($p['contested_fields']), 'U+0385 -> W, not contested');
$p=QRRP_GS1_Parser::parse("{$P}17280331"."10LOT1{$GS}21ΑΒ\u{037E}12");
ok($p['fields']['SN']==='ABq12', 'U+037E (Greek question mark, Q key) -> q, same as ";"');
$p=QRRP_GS1_Parser::parse("{$P}17280331"."10LOT1{$GS}21ΑΒ;12");
ok($p['fields']['SN']==='ABq12', '";" with Greek evidence -> q (unchanged)');

// 5. Σ σε επιπλέον AI: τα extras δεν αποδεικνύονται
$raw5="{$P}17280331"."10LOT1{$GS}240ΑΣ{$GS}21ABC";
$p=QRRP_GS1_Parser::parse($raw5);
ok($p['requires_confirmation'] && $p['confidence']!=='high' && has_warn($p,'δεν αλλάζει τα PC/SN/LOT/EXP'), 'Σ in extra AI: confirmation + specific warning');
$pt=QRRP_GS1_Parser::passthrough_for_reading($raw5,F('05012345678900','ABC','LOT1','280331'));
ok(!$pt['proven'] && ($pt['unproven_ais']??null)===array('240'), 'Σ in extra AI: passthrough unproven (240)');
$raw6="{$P}17280331"."10LOT1{$GS}240XYZ{$GS}21ΑΒΣ";
$pt=QRRP_GS1_Parser::passthrough_for_reading($raw6,F('05012345678900','ABW','LOT1','280331'));
ok($pt['proven'] && $pt['extras']===array(array('ai'=>'240','value'=>'XYZ')), 'Σ in SN, clean extra: W anchor proves extras');

// 6. Αμετάβλητα
$p=QRRP_GS1_Parser::parse("{$P}17280331"."10ΛΟΤ{$GS}21ΑΒΓσς");
ok($p['fields']['SN']==='ABGsw' && empty($p['contested_fields']) && empty($p['normalization']['ambiguous_sigma']), 'lowercase σ/ς unambiguous (s/w), no contest');
$p=QRRP_GS1_Parser::parse("{$P}17280331"."10LOT1{$GS}21ABS12");
ok($p['confidence']==='high' && !$p['requires_confirmation'] && empty($p['contested_fields']), 'Latin S unaffected: high confidence, no confirmation');
// 7. 2.15.7: «ΐ»/«ΰ» = νεκρό πλήκτρο «΅» (Shift+W στα Windows) + i/y → W κρατιέται
$p=QRRP_GS1_Parser::parse("{$P}17280331"."10LOT1{$GS}21ΑΒΐ9");
ok($p['fields']['SN']==='ABWi9' && $p['requires_confirmation'], 'ΐ → Wi (not i), confirmation required');
$p=QRRP_GS1_Parser::parse("{$P}17280331"."10LOT1{$GS}21ΑΒΰ9");
ok($p['fields']['SN']==='ABWy9' && $p['requires_confirmation'], 'ΰ → Wy (not y), confirmation required');
echo $fail? "FAILURES: $fail\n" : "ALL PASS\n";
