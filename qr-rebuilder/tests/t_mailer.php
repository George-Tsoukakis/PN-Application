<?php require 'bootp.php';
$PD=qrrp_test_pdir();
define('MINUTE_IN_SECONDS',60); define('DAY_IN_SECONDS',86400);
$GLOBALS['__options']['admin_email']='shop@example.gr'; $GLOBALS['mail_ok']=true; $GLOBALS['sched']=array(); $GLOBALS['last_body']=''; $GLOBALS['att']=array();
function wp_mail($to,$s,$b,$h,$a){ $GLOBALS['last_body']=$b; $GLOBALS['att']=$a; return $GLOBALS['mail_ok']; }
function get_temp_dir(){ return __DIR__.'/tmpmail/'; }
function wp_is_writable($d){return is_writable($d);}
function wp_unique_filename($d,$f){return $f;}
function trailingslashit($s){return rtrim($s,'/').'/';}
function wp_delete_file($f){ @unlink($f); }
function wp_schedule_single_event($t,$h,$a=array()){ $GLOBALS['sched'][]=array($t,$h); return true; }
function sanitize_email($e){return trim($e);} function is_email($e){return (bool)filter_var($e,FILTER_VALIDATE_EMAIL);}
function is_user_logged_in(){return $GLOBALS['__logged_in'] ?? true;} function qrrp_guest_email_recipient_allowed($e){return true;} function sanitize_text_field($s){return trim($s);}
function wp_specialchars_decode($s,$q=null){return $s;} function get_bloginfo($k=''){return $k==='version'?'6.8':'Φαρμακείο';}
function esc_html($s){return htmlspecialchars((string)$s,ENT_QUOTES);} function esc_html__($s,$d=null){return esc_html($s);} function esc_url($u){return $u;} function esc_attr($s){return esc_html($s);}
function add_filter(...$a){} function remove_filter(...$a){}
function qrrp_asset_version($p){return '1';} function qrrp_tool_page_id(){return 0;} function esc_url_raw($u){return $u;} function get_permalink($i=0){return false;} function home_url($p=''){return 'https://example.gr'.$p;}
foreach(array('qrrp_print_barcode_mm','qrrp_x_dimension_mm','qrrp_gs1_minimum_x_dimension_mm','qrrp_gs1_minimum_print_width_mm') as $fn) eval("function $fn(...\$a){return false;}");
require $PD.'/includes/class-qrrp-text.php';
require_once __DIR__.'/lib/renderer.php'; qrrp_test_renderer('fake'); /* 2.15.4: κύκλος ζωής αρχείων, χωρίς GD */

require $PD.'/includes/class-qrrp-mailer.php';
array_map('unlink', glob(__DIR__.'/tmpmail/*'));
$f=array('PC'=>'05012345678900','SN'=>'SN1','LOT'=>'LOT','EXP'=>'2028-03-31');
$raw=QRRP_GS1_Parser::validate_and_build($f)['raw'];
$args=array('a@b.gr',$f,'','',$raw,'',array());
// success
$r=QRRP_Mailer::send(...array_merge($args,array(array('provenance'=>'manual_reconstruction','changed_fields'=>array('SN'),'changed_fields_unknown'=>false))));
$left=glob(__DIR__.'/tmpmail/GS1-DataMatrix-*.png');
printf("success: result=%s files_kept=%d scheduled=%d\n", $r===true?'true':'err', count($left), count($GLOBALS['sched']));
/* 2.15.7: σύγχρονο wp_mail() → το PNG σβήνεται αμέσως, χωρίς sweep. */
echo ($r===true && count($left)===0 && count($GLOBALS['sched'])===0 ? 'PASS':'FAIL')," 2.15.7: synchronous send deletes attachment immediately, no sweep scheduled\n";
$tag=(new ReflectionMethod('QRRP_Mailer','temp_site_tag'))->invoke(null);
echo (preg_match('/\A[a-f0-9]{8}\z/',$tag) && basename((string)(is_array($GLOBALS['att'])?reset($GLOBALS['att']):''))!=='' && strpos(basename(reset($GLOBALS['att'])),'GS1-DataMatrix-'.$tag.'-')===0 ? 'PASS':'FAIL')," 2.15.7: attachment file carries this site's tag\n";
/* Mailer με ουρά: το συνημμένο μένει για τον sweep. */
$GLOBALS['__filters']['qrrp_mail_attachment_deferred']=function(){return true;};
$r=QRRP_Mailer::send(...array_merge($args,array(array('provenance'=>'manual_reconstruction','changed_fields'=>array('SN'),'changed_fields_unknown'=>false))));
$left=glob(__DIR__.'/tmpmail/GS1-DataMatrix-*.png');
echo ($r===true && count($left)===1 && count($GLOBALS['sched'])===1 ? 'PASS':'FAIL')," deferred (queue) mailer: attachment kept + sweep scheduled\n";
unset($GLOBALS['__filters']['qrrp_mail_attachment_deferred']); $GLOBALS['sched']=array();
/* 2.16.0: καμία σήμανση προέλευσης στο email. */
echo (strpos($GLOBALS['last_body'],'Χειροκίνητη αλλαγή')===false ? 'PASS':'FAIL')," 2.16.0: email carries no manual-change note\n";
// failure
array_map('unlink', glob(__DIR__.'/tmpmail/*')); $GLOBALS['mail_ok']=false;
$r=QRRP_Mailer::send(...$args);
$left=glob(__DIR__.'/tmpmail/GS1-DataMatrix-*.png');
printf("failure: result=%s files_kept=%d\n", is_wp_error($r)?$r->code:'true', count($left));
echo (count($left)===0 ? 'PASS':'FAIL')," failed send deletes temp file immediately\n";
// scan: no note
$GLOBALS['__options']['admin_email']='shop@example.gr'; $GLOBALS['mail_ok']=true; QRRP_Mailer::send(...array_merge($args,array(array('provenance'=>'scan'))));
echo ((strpos($GLOBALS['last_body'],'Χειροκίνητη αλλαγή')===false && strpos($GLOBALS['last_body'],'Χειροκίνητη καταχώριση')===false) ? 'PASS':'FAIL')," scan email has no manual note\n";
// sweep: old files deleted, young kept, >50 fresh files don't hide old ones
array_map('unlink', glob(__DIR__.'/tmpmail/*'));
for($i=0;$i<60;$i++){ $n=__DIR__.'/tmpmail/GS1-DataMatrix-'.sprintf('%012x',$i).'.png'; touch($n); }
$old=__DIR__.'/tmpmail/GS1-DataMatrix-ffffffffffff.png'; touch($old, time()-7200);
QRRP_Mailer::run_scheduled_sweep();
printf("sweep: old_exists=%d fresh_left=%d\n", file_exists($old), count(glob(__DIR__.'/tmpmail/*'))-(file_exists($old)?1:0));
echo (!file_exists($old) && count(glob(__DIR__.'/tmpmail/*'))===60 ? 'PASS':'FAIL')," sweep removes old file even behind 60 fresh ones\n";
/* 2.15.7: purge = όλα τα δικά του + παλιάς μορφής > 1 ώρα· ποτέ αρχεία άλλου site. */
$own=__DIR__.'/tmpmail/GS1-DataMatrix-'.$tag.'-000000000001.png'; touch($own);
$foreign=__DIR__.'/tmpmail/GS1-DataMatrix-'.($tag==='00000000'?'11111111':'00000000').'-000000000002.png'; touch($foreign, time()-7200);
$legacy_old=__DIR__.'/tmpmail/GS1-DataMatrix-eeeeeeeeeeee.png'; touch($legacy_old, time()-7200);
QRRP_Mailer::purge_all_temp_files();
echo (!file_exists($own) && !file_exists($legacy_old) && file_exists($foreign) && count(glob(__DIR__.'/tmpmail/*'))===61 ? 'PASS':'FAIL')," 2.15.7: uninstall purge removes own + stale legacy, keeps other sites' and fresh legacy files\n";
touch($foreign, time()-2*86400); QRRP_Mailer::purge_all_temp_files();
echo (!file_exists($foreign) ? 'PASS':'FAIL')," 2.15.7: other-site/old-salt file older than a day is cleaned up\n";
array_map('unlink', glob(__DIR__.'/tmpmail/*'));
// reschedule when young files remain
$GLOBALS['sched']=array(); touch(__DIR__.'/tmpmail/GS1-DataMatrix-'.$tag.'-aaaaaaaaaaaa.png');
QRRP_Mailer::run_scheduled_sweep();
echo (count($GLOBALS['sched'])===1 && $GLOBALS['sched'][0][0] >= time()+3600+15*60-2 ? 'PASS':'FAIL')," sweep reschedules (+75 min) while files remain\n";
array_map('unlink', glob(__DIR__.'/tmpmail/*')); $GLOBALS['sched']=array();
QRRP_Mailer::run_scheduled_sweep();
echo (count($GLOBALS['sched'])===0 ? 'PASS':'FAIL')," no reschedule when directory is clean\n";
/* 2.15.7: αρχεία άλλου site (π.χ. άλλος χρήστης στον κοινό /tmp) δεν κρατούν τον sweep σε ατέρμονο επαναπρογραμματισμό. */
touch($foreign); QRRP_Mailer::run_scheduled_sweep();
echo (count($GLOBALS['sched'])===0 && file_exists($foreign) ? 'PASS':'FAIL')," 2.15.7: foreign-site files neither swept nor keep the sweep rescheduling\n";
array_map('unlink', glob(__DIR__.'/tmpmail/*'));
// 2.15.3: σύνδεσμος prefill μόνο για συνδεδεμένους, σήμανση user_declared, ΗΗ=00
$GLOBALS['mail_ok']=true;
$GLOBALS['__filters']['qrrp_email_tool_page_url']=null;
QRRP_Mailer::send('a@b.gr',$f,'','',$raw,'https://example.gr/tool/',array(),array('provenance'=>'scan'));
$logged_link = strpos($GLOBALS['last_body'],'qrrp_token=')!==false;
$GLOBALS['__logged_in']=false;
QRRP_Mailer::send('a@b.gr',$f,'','',$raw,'https://example.gr/tool/',array(),array('provenance'=>'user_declared','source_method'=>'scan'));
echo (strpos($GLOBALS['last_body'],'Δηλωμένο από τον χρήστη')===false ? 'PASS':'FAIL')," 2.16.0: guest email carries no user_declared note\n";
$GLOBALS['__logged_in']=true;
$f0=array('PC'=>'05012345678900','SN'=>'SN1','LOT'=>'LOT','EXP'=>'2028-02-00');
$b0=QRRP_GS1_Parser::validate_and_build($f0);
$r=QRRP_Mailer::send('a@b.gr',$f0,'','',$b0['raw']??'','',array(),array());
echo ($r===true && strpos($b0['raw'],'17280200')!==false && strpos($GLOBALS['last_body'],'02/2028')!==false ? 'PASS':'FAIL')," DD=00: raw keeps 280200, email shows 02/2028\n";
// build_html απευθείας με tool URL, για να φανεί ο σύνδεσμος
 if(!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS',3600);
if(!function_exists('add_query_arg')){ function add_query_arg($a,$u){ return $u.'?'.http_build_query($a); } }
$bh=new ReflectionMethod('QRRP_Mailer','build_html'); $bh->setAccessible(true);
$sf=new ReflectionMethod('QRRP_Mailer','sanitize_fields'); $sf->setAccessible(true); $df=$sf->invoke(null,$f);
$tok=null; $GLOBALS['__logged_in']=true;
$html=$bh->invokeArgs(null,array('Site',$df,'','',$raw,'x.png','https://example.gr/tool/',&$tok,array(),''));
echo (strpos($html,'qrrp_token=')!==false && ''!==$tok ? 'PASS':'FAIL')," logged-in email has prefill link\n";
$tok=null; $GLOBALS['__logged_in']=false;
$html=$bh->invokeArgs(null,array('Site',$df,'','',$raw,'x.png','https://example.gr/tool/',&$tok,array(),''));
echo (strpos($html,'qrrp_token=')===false && ''===$tok ? 'PASS':'FAIL')," guest email: no link, no token issued\n";
$GLOBALS['__logged_in']=true;
array_map('unlink', glob(__DIR__.'/tmpmail/*'));
