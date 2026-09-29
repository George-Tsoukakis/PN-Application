<?php require 'bootajax.php';
// 2.15.3 Fix 6: AI 17 DD=00 διατηρείται στον νέο κωδικό· η λήξη κρίνεται με το τέλος του μήνα.
$PD=qrrp_test_pdir();
if(!defined('QRRP_PLUGIN_DIR')) define('QRRP_PLUGIN_DIR',$PD.'/');
if(!function_exists('qrrp_asset_version')){ function qrrp_asset_version($p){return '1';} }
require_once __DIR__.'/lib/renderer.php'; qrrp_test_renderer('fake'); /* 2.15.4: λογική, χωρίς GD */
$P="0105012345678900"; $GS="\x1d"; $fail=0;
function ok($c,$m){ global $fail; if(!$c) $fail++; echo ($c?"PASS":"FAIL")," $m\n"; }
function has_warn($p,$needle){ foreach($p['warnings'] as $w) if(strpos($w,$needle)!==false) return true; return false; }
function call_rebuild($post){ $_SERVER['REQUEST_METHOD']='POST'; $_POST=$post; try{ QRRP_Ajax::rebuild(); }catch(JsonExit $e){ return $e; } }

$raw="{$P}17280200"."10LOT1{$GS}21ABC";
$p=QRRP_GS1_Parser::parse($raw);
ok($p['fields']['EXP']==='2028-02-00', 'parse: EXP kept as 2028-02-00 (not 2028-02-29)');
ok($p['exp_day_unspecified']===true && $p['requires_confirmation']===false && $p['confidence']==='high', 'parse: exp_day_unspecified flag, no forced confirmation');
ok(has_warn($p,'έως το τέλος του μήνα 02/2028 (2028-02-29)'), 'parse: neutral "until end of month" note');
$all=implode(' ',$p['warnings']);
ok(strpos($all,'1/1/2025')===false && strpos($all,'απαιτεί συγκεκριμένη ημέρα')===false, 'parse: no unverified "GS1 requires a day from 1/1/2025" claim');

$b=QRRP_GS1_Parser::validate_and_build(array('PC'=>'05012345678900','SN'=>'ABC','LOT'=>'LOT1','EXP'=>'2028-02-00'));
ok(empty($b['errors']) && $b['raw']==="{$P}17280200"."10LOT1{$GS}21ABC", 'validate_and_build: 2028-02-00 -> ...17280200... (byte-identical to source)');
foreach(array('2028-13-00','2028-00-00','2028-02-30','2028-2-00') as $bad){ $b=QRRP_GS1_Parser::validate_and_build(array('PC'=>'05012345678900','SN'=>'ABC','LOT'=>'LOT1','EXP'=>$bad)); ok(!empty($b['errors']), "validate_and_build rejects $bad"); }
ok(QRRP_GS1_Parser::to_yymmdd('2028-02-00')==='280200' && QRRP_GS1_Parser::to_yymmdd('2028-02-29')==='280229', 'to_yymmdd: -00 -> YYMM00, normal dates unchanged');

// provenance: υποβολή με 00 = scan· με τελευταία ημέρα = αλλαγή EXP
$v=ev($raw,F('05012345678900','ABC','LOT1','2028-02-00'));
ok($v['allowed'] && $v['provenance']==='scan', 'provenance: EXP 2028-02-00 -> scan MATCH');
$v=ev($raw,F('05012345678900','ABC','LOT1','2028-02-29'));
ok(!$v['allowed'] && $v['error_code']==='manual_override_required' && $v['changed_fields']===array('EXP'), 'provenance: EXP 2028-02-29 -> manual reconstruction, changed EXP');

// λήξη: τέλος μήνα
$now=new DateTimeImmutable(wp_date('Y-m-d'));
$cur=$now->format('Y-m').'-00'; $prev=$now->modify('first day of last month')->format('Y-m').'-00';
ok(!QRRP_GS1_Parser::date_is_in_past($cur), "date_is_in_past($cur) = false (valid until month end)");
ok(QRRP_GS1_Parser::date_is_in_past($prev), "date_is_in_past($prev) = true");
ok(QRRP_GS1_Parser::expiry_effective_date('2028-02-00')==='2028-02-29' && QRRP_GS1_Parser::expiry_effective_date('2027-02-00')==='2027-02-28', 'expiry_effective_date: leap-year aware');
ok(QRRP_GS1_Parser::date_is_in_past('2020-01-15') && !QRRP_GS1_Parser::date_is_in_past('2099-01-15'), 'date_is_in_past: normal dates unchanged');
$pp=QRRP_GS1_Parser::parse("{$P}17".substr(str_replace('-','',$prev),2,4)."00"."10LOT1{$GS}21ABC");
ok($pp['exp_in_past']===true, 'parse: previous-month DD=00 flagged exp_in_past');
$pc=QRRP_GS1_Parser::parse("{$P}17".substr(str_replace('-','',$cur),2,4)."00"."10LOT1{$GS}21ABC");
ok($pc['exp_in_past']===false, 'parse: current-month DD=00 not expired');

// end-to-end rebuild
$e=call_rebuild(array('source_raw'=>$raw,'pc'=>'05012345678900','sn'=>'ABC','lot'=>'LOT1','exp'=>'2028-02-00'));
ok($e && $e->status===200 && ($e->data['provenance']??'')==='scan' && ($e->data['raw']??'')===$raw, 'ajax rebuild: 200 scan, output raw keeps 17280200');

// κανονική ημερομηνία αμετάβλητη
$p=QRRP_GS1_Parser::parse("{$P}17280331"."10LOT1{$GS}21ABC");
ok($p['fields']['EXP']==='2028-03-31' && !$p['exp_day_unspecified'] && !$p['requires_confirmation'], 'normal DD unaffected');

// παλιό μοντέλο με φίλτρο
$GLOBALS['__filters']['qrrp_preserve_expiry_day_zero']=function($v){return false;};
$p=QRRP_GS1_Parser::parse($raw);
ok($p['fields']['EXP']==='2028-02-29' && $p['requires_confirmation']===true, 'filter=false: legacy last-day proposal + confirmation');
$b=QRRP_GS1_Parser::validate_and_build(array('PC'=>'05012345678900','SN'=>'ABC','LOT'=>'LOT1','EXP'=>'2028-02-00'));
ok(!empty($b['errors']), 'filter=false: validate_and_build rejects -00');
unset($GLOBALS['__filters']['qrrp_preserve_expiry_day_zero']);
echo $fail? "FAILURES: $fail\n" : "ALL PASS\n";
