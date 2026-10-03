<?php
/*
 * 2.16.1 — τα πρόσθετα AI της πηγής δεν χάνονται σιωπηλά όταν δεν υπάρχει
 * άγκυρα (scan_unverified ή χειροκίνητη αλλαγή χωρίς επαληθευμένη βάση).
 * php t_passthrough_unanchored.php
 */
require 'bootajax.php';
$PD=qrrp_test_pdir();
if(!defined('QRRP_PLUGIN_DIR')) define('QRRP_PLUGIN_DIR',$PD.'/');
function qrrp_asset_version($p){return '1';}
require_once __DIR__.'/lib/renderer.php'; qrrp_test_renderer('fake');
function call_rebuild($post){ $_SERVER['REQUEST_METHOD']='POST'; $_POST=$post; try{ QRRP_Ajax::rebuild(); }catch(JsonExit $e){ return $e; } }
$fails=0;
function check($label,$ok){ global $fails; if(!$ok) $fails++; echo ($ok?'PASS':'FAIL'),' ',$label,"\n"; }
$GS="\x1d";
$m=new ReflectionMethod('QRRP_Ajax','passthrough_without_anchor'); $m->setAccessible(true);
function pw($m,$raw,$f,$prov){ $_SERVER['REQUEST_METHOD']='POST'; try{ return array('ok',$m->invoke(null,$raw,$f,$prov)); }catch(JsonExit $e){ return array($e->data['code']??'?',$e->status); } }

/* Πηγή με AI 240 και πραγματικούς separators. */
$raw="0105012345678900"."17280331"."10LOT{$GS}240XYZ{$GS}21SN1";
$f=array('PC'=>'05012345678900','SN'=>'SN1','LOT'=>'LOT','EXP'=>'280331');
$r=pw($m,$raw,$f,'scan_unverified');
check('scan_unverified: proven AI 240 is carried, not dropped', 'ok'===$r[0] && array(array('ai'=>'240','value'=>'XYZ'))===$r[1]);
$f2=$f; $f2['SN']='OTHER';
$r=pw($m,$raw,$f2,'manual_reconstruction');
check('manual without baseline + source extras → 409 extras_unprovable (was: silent drop)', 'extras_unprovable'===$r[0] && 409===$r[1]);

/* Πηγή χωρίς extras: καμία αλλαγή. */
$raw2="0105012345678900"."17280331"."10LOT{$GS}21SN1";
$r=pw($m,$raw2,$f2,'manual_reconstruction');
check('manual without baseline, source without extras → empty passthrough as before', 'ok'===$r[0] && array()===$r[1]);
$r=pw($m,$raw2,$f,'scan_unverified');
check('scan_unverified, source without extras → empty passthrough', 'ok'===$r[0] && array()===$r[1]);

/* Εναλλακτική ανάγνωση με AI δεν είναι extra της πηγής (βλ. t_rebuild). */
$raw3="0105012345678900"."17280331"."10LOT{$GS}21ABC24012";
$r=pw($m,$raw3,array('PC'=>'05012345678900','SN'=>'ABC','LOT'=>'LOT','EXP'=>'280331'),'manual_reconstruction');
check('alternative AI reading (21ABC24012) is not a source extra', 'ok'===$r[0] && array()===$r[1]);
echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit($fails?1:0);
