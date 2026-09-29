<?php require 'bootp.php';
// 2.15.3 review: οι παραλλαγές «Σ» μοιράζονται έναν προϋπολογισμό αναζήτησης και
// απαριθμούνται μόνο για «Σ» μέσα σε PC/SN/LOT/EXP της βασικής ανάγνωσης.
$P="0105012345678900"; $PC='05012345678900'; $fail=0;
function ok($c,$m){ global $fail; if(!$c) $fail++; echo ($c?"PASS":"FAIL")," $m\n"; }
function has_warn($p,$needle){ foreach($p['warnings'] as $w) if(strpos($w,$needle)!==false) return true; return false; }
function timed($fn){ $t=microtime(true); $r=$fn(); return array($r,(microtime(true)-$t)*1000); }

// 1. Ρεαλιστικό SN με 4 «Σ»: όλες οι 16 παραλλαγές admissible, όχι truncated, provenance scan.
$raw="]d2{$P}17280331"."10ΝΚ4032\x1d21ΥΝΣ8ΧΣΣΦΔΣΠ";
list($a,$ms)=timed(fn()=>QRRP_GS1_Parser::admissible_readings($raw));
$sns=array(); foreach($a['readings'] as $r) if($r['LOT']==='NK4032') $sns[$r['SN']]=true;
ok(!$a['truncated'] && count($a['readings'])<=QRRP_GS1_Parser::MAX_ADMISSIBLE_READINGS && count($sns)===16, "4-Σ SN: 16 SN variants admissible, not truncated (".count($a['readings'])." readings, ".round($ms)." ms)");
$p=QRRP_GS1_Parser::parse($raw);
ok(count($p['contested_fields']['SN']??array())===16 && has_warn($p,'SN θέση 3, 6, 7, 10'), '4-Σ SN: picker offers 16 values, positions 3,6,7,10');
$v=ev($raw,F($PC,'YNW8XWSFDWP','NK4032','2028-03-31'),array('acknowledged'=>true));
ok($v['allowed'] && $v['provenance']==='scan', '4-Σ SN: W pick + ack -> scan (not scan_unverified)');
ok($ms<250, '4-Σ SN: admissible under 250 ms');

// 2. «Σ» μέσα σε AI 91 (review repro): δεν πολλαπλασιάζει αναγνώσεις, κόστος φραγμένο.
$unit=array('91Α','92Β','93Γ','94Δ','95Ε','96Ζ','97Η','98Θ','99Ι'); $worst=0;
for($k=6;$k<=20;$k++){
  $soup=''; for($i=0;$i<$k;$i++) $soup.=$unit[$i%9];
  $rs=$P.'17280331'.'10Α<GS>21Β<GS>91ΣΣΣΣ'.$soup; $rp=$P.'17280331'.'10Α<GS>21Β<GS>91ΑΑΑΑ'.$soup;
  $f=F($PC,'B','A','280331');
  list($as,$t1)=timed(fn()=>QRRP_GS1_Parser::admissible_readings($rs)); list(,$t2)=timed(fn()=>QRRP_GS1_Parser::parse($rs)); list($pts,$t3)=timed(fn()=>QRRP_GS1_Parser::passthrough_for_reading($rs,$f));
  list($ap,$u1)=timed(fn()=>QRRP_GS1_Parser::admissible_readings($rp)); list(,$u2)=timed(fn()=>QRRP_GS1_Parser::parse($rp)); list(,$u3)=timed(fn()=>QRRP_GS1_Parser::passthrough_for_reading($rp,$f));
  $same = count($as['readings'])===count($ap['readings']) && $as['truncated']===$ap['truncated'];
  $ratio = ($t1+$t2+$t3)/max(1,($u1+$u2+$u3));
  $worst=max($worst,$ratio);
  if(!$same || ($t1+$t2+$t3) > 2.5*($u1+$u2+$u3)+15) ok(false,"k=$k Σ-in-AI91: readings ".count($as['readings'])."/".count($ap['readings'])." trunc ".(int)$as['truncated']."/".(int)$ap['truncated']." time ".round($t1+$t2+$t3)."/".round($u1+$u2+$u3)." ms");
  if($k===8){
    $p=QRRP_GS1_Parser::parse($rs);
    ok(!has_warn($p,' θέση ') && count($p['contested_fields']['SN']??array())<=2 && $p['requires_confirmation'] && has_warn($p,'δεν αλλάζει τα PC/SN/LOT/EXP'), 'k=8 Σ-in-AI91: no S/W candidates (only the pre-existing literal-<GS> one), confirmation + generic warning');
    ok(!$pts['proven'] && in_array('91',$pts['unproven_ais']??array(),true), 'k=8 Σ-in-AI91: extras (91) unproven');
  }
}
ok(true, sprintf('Σ-in-AI91 k=6..20: same reading count/truncation as without Σ; worst time ratio %.2f', $worst));

// 3. Κατασκευασμένη σούπα με 4 «Σ» και <GS>: φραγμένο κόστος.
$gs=str_repeat('91Α92Β93Γ94Δ95Ε96Ζ97Η98Θ99Ι',120);
list(,$ms)=timed(function() use($P,$PC,$gs){ $r=substr($P.'17280331'.'10ΣΣ<GS>21ΣΣ'.$gs,0,4096); QRRP_GS1_Parser::parse($r); QRRP_GS1_Parser::admissible_readings($r); return QRRP_GS1_Parser::passthrough_for_reading($r,F($PC,'SS','SS','280331')); });
ok($ms<600, 'soup 4Σ<GS>: parse+admissible+passthrough under 600 ms ('.round($ms).' ms)');
echo $fail? "FAILURES: $fail\n" : "ALL PASS\n";
