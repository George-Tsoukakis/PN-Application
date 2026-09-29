<?php require 'bootp.php';
$parts=array('17280331','10AB','10A21','2112','21X10Y','1799','10','21','17280315','101728','2110');
mt_srand(3); $found=0;
for($i=0;$i<3000&&$found<1;$i++){ $raw='0105012345678900'; $n=mt_rand(2,4); for($j=0;$j<$n;$j++) $raw.=$parts[array_rand($parts)];
 $p=QRRP_GS1_Parser::parse($raw); if(empty($p['requires_confirmation'])) continue;
 $adm=QRRP_GS1_Parser::admissible_readings($raw); if(count($adm['readings'])<2) continue;
 $found++; echo "RC raw=$raw parser=",json_encode($p['fields']),"\n";
 foreach($adm['readings'] as $r){ $exp=$r['EXP']===''?'':'20'.substr($r['EXP'],0,2).'-'.substr($r['EXP'],2,2).'-'.substr($r['EXP'],4,2); $f=F($r['PC'],$r['SN'],$r['LOT'],$exp); show(' no-ack SN='.$r['SN'].' LOT='.$r['LOT'].' EXP='.$r['EXP'], ev($raw,$f)); show('   ack', ev($raw,$f,array('acknowledged'=>true))); }
}
