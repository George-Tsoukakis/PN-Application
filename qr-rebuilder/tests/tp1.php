<?php require 'bootp.php';
$raw="0105012345678900"."17280331"."10LOT\x1d21ABC24012";
show('clean scan, parser reading', ev($raw,F('05012345678900','ABC24012','LOT','2028-03-31')));
$v=ev($raw,F('05012345678900','ABC','LOT','2028-03-31'));
show('SN=ABC (alt admissible), no challenge', $v);
$v2=ev($raw,F('05012345678900','ABC','LOT','2028-03-31'),array('challenge'=>$v['challenge']??''));
show('SN=ABC with challenge', $v2);
$v3=ev($raw,F('05012345678900','ABC','LOT','2028-03-31'),array('acknowledged'=>true,'baseline'=>F('05012345678900','ABC','LOT','280331')));
show('SN=ABC ack + client baseline ABC', $v3);
$v4=ev($raw,F('05012345678900','ABC','LOT','2028-03-31'),array('challenge'=>$v3['challenge']??'','baseline'=>F('05012345678900','ABC','LOT','280331')));
show('  ...with challenge', $v4);
// ambiguous raw (no GS): picker path still works
$raw2="0105012345678900"."17280331"."10LOT21ABC";
$p=QRRP_GS1_Parser::parse($raw2); echo "amb parse rc=",(int)$p['requires_confirmation']," ",json_encode($p['fields']),"\n";
$adm=QRRP_GS1_Parser::admissible_readings($raw2); echo json_encode($adm['readings']),"\n";
foreach($adm['readings'] as $r){ $f=F($r['PC'],$r['SN'],$r['LOT'],$r['EXP']); show(' amb no-ack '.$r['SN'].'/'.$r['LOT'], ev($raw2,$f)); show(' amb ack', ev($raw2,$f,array('acknowledged'=>true))); }
// NO_MATCH edit
show('edited SN=XYZ', ev($raw,F('05012345678900','XYZ','LOT','2028-03-31')));
