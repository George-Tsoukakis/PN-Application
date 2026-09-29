<?php
// 2.15.7: σταθερό «σήμερα», ώστε exp_in_past / παράθυρο αιώνα να μη μεταβάλλονται με τον χρόνο.
if ( false === getenv('QRRP_TEST_NOW') ) { putenv('QRRP_TEST_NOW=1790683200'); }
require 'boot.php';
$GS="\x1d"; $P="0105012345678900";
$corpus=array("]d20105012345678901211ABC{$GS}10LOT1{$GS}17260131","0105012345678901211ABC10LOT117260131","{$P}17280331"."10LOT1{$GS}21ABC","{$P}17280300"."10LOT1{$GS}21ABC","(01){$P}(17)280331(10)LOT1(21)ABC","(01){$P}(17)280331(10)LOT1(240)XYZ(21)ABC","{$P}172803311 0LOT1<GS>21ABC","{$P}17280331"."10LOT1<GS>21ABC",
"{$P}21X1728033110Y17290331","{$P}1728033110LOT{$GS}70032603011230"."21ABC","{$P}17280331"."10LOT{$GS}21ABC24012","{$P}17280331"."10LOT{$GS}8010AB#1{$GS}21ABC","{$P}17280331"."21AAA{$GS}10LOT{$GS}21BBB","(01){$P}(17)280331(10)LOT(21)A(240)B","{$P}1728033110ABCDEF21XYZ",
"]d2{$P}17280331"."10NK4032{$GS}21YN68XRRFDZP","{$P}17280331"."10LOT{$GS}240ABC{$GS}21SN1","ΑΒΓ","","]C1{$P}17280331"."10ABC[GS]21XYZ", "{$P}17280331"."10LOT\x1d21ABC{$GS}");
$parts=array('17280331','10AB','10A21','2112','21X10Y','1799','10','21','17280315','101728','2110','240','91','\x1d',"\x1d",'7003','8005','3103','ABC','0');
mt_srand(7);
for($i=0;$i<4000;$i++){ $raw=(mt_rand(0,3)?$P:''); $n=mt_rand(1,6); for($j=0;$j<$n;$j++) $raw.=$parts[array_rand($parts)]; $corpus[]=$raw; }
$out=array(); $t=microtime(true);
foreach($corpus as $raw){
  $p=QRRP_GS1_Parser::parse($raw); $a=QRRP_GS1_Parser::admissible_readings($raw);
  $pt=array(); foreach(array_slice($a['readings'],0,3) as $r) $pt[]=QRRP_GS1_Parser::passthrough_for_reading($raw,$r);
  $out[]=array($raw,$p,$a,$pt);
}
fwrite(STDERR, sprintf("%d inputs %.1fs\n",count($corpus),microtime(true)-$t));
echo json_encode($out);
