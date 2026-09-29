<?php require 'bootp.php';
$P="0105012345678900";
$soup = str_repeat('91A92B93C94D95E96F97G98H99I',140);
$cases=array('soup'=>substr($P.'17280331'.'10A21B'.$soup,0,4096),'soup<GS>'=>substr($P.'17280331'.'10A<GS>21B'.$soup,0,4096),'real'=>"]d2{$P}17280331"."10NK4032\x1d21YN68XRRFDZP");
foreach($cases as $k=>$raw){
 $f=array('PC'=>$P,'SN'=>'B','LOT'=>'A','EXP'=>'2028-03-31');
 $t=microtime(true);
 $v=QRRP_Provenance::evaluate(array('source_raw'=>$raw,'fields'=>$f));
 $pt=QRRP_GS1_Parser::passthrough_for_reading($raw,$f);
 printf("%-10s rebuild-equivalent %.0f ms  verdict=%s\n",$k,(microtime(true)-$t)*1000,$v['error_code']??$v['provenance']);
}
// 2.15.3: περιπτώσεις «Σ» (ελληνική διάταξη). parse + admissible + evaluate + passthrough.
$unit=array('91Α','92Β','93Γ','94Δ','95Ε','96Ζ','97Η','98Θ','99Ι'); $mix=''; for($i=0;$i<15;$i++) $mix.=$unit[$i%9];
$gsoup=str_repeat('91Α92Β93Γ94Δ95Ε96Ζ97Η98Θ99Ι',120);
$sigma=array(
 'Σ-SN real'   => array("]d2{$P}17280331"."10ΝΚ4032\x1d21ΥΝΣ8ΧΣΣΦΔΣΠ", array('PC'=>'05012345678900','SN'=>'YNS8XSSFDSP','LOT'=>'NK4032','EXP'=>'2028-03-31')),
 'Σ-in-AI91'   => array($P.'17280331'.'10Α<GS>21Β<GS>91ΣΣΣΣ'.$mix, array('PC'=>'05012345678900','SN'=>'B','LOT'=>'A','EXP'=>'2028-03-31')),
 'soup4Σ<GS>'  => array(substr($P.'17280331'.'10ΣΣ<GS>21ΣΣ'.$gsoup,0,4096), array('PC'=>'05012345678900','SN'=>'SS','LOT'=>'SS','EXP'=>'2028-03-31')),
);
foreach($sigma as $k=>$c){
 list($raw,$f)=$c; $t=microtime(true);
 QRRP_GS1_Parser::parse($raw); $a=QRRP_GS1_Parser::admissible_readings($raw);
 $v=QRRP_Provenance::evaluate(array('source_raw'=>$raw,'fields'=>$f,'acknowledged'=>true));
 QRRP_GS1_Parser::passthrough_for_reading($raw,$f);
 printf("%-12s parse+adm+rebuild %.0f ms  readings=%d trunc=%d verdict=%s\n",$k,(microtime(true)-$t)*1000,count($a['readings']),!empty($a['truncated']),$v['error_code']??$v['provenance']);
}
