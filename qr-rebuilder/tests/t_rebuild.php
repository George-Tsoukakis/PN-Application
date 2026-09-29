<?php require 'bootajax.php';
$PD=qrrp_test_pdir();
if(!defined('QRRP_PLUGIN_DIR')) define('QRRP_PLUGIN_DIR',$PD.'/');
function qrrp_asset_version($p){return '1';}

require_once __DIR__.'/lib/renderer.php'; qrrp_test_renderer('real'); /* 2.15.4: integration, SKIP χωρίς GD */
function call_rebuild($post){ $_SERVER['REQUEST_METHOD']='POST'; $_POST=$post; try{ QRRP_Ajax::rebuild(); }catch(JsonExit $e){ return $e; } }
$raw="0105012345678900"."17280331"."10LOT\x1d21ABC24012";
$base=array('source_raw'=>$raw,'pc'=>'05012345678900','lot'=>'LOT','exp'=>'2028-03-31');
$e0=call_rebuild($base+array('sn'=>'ABC24012'));
printf("clean reading SN=ABC24012: %d %s prov=%s raw=%s\n",$e0->status,$e0->data['code']??'ok',$e0->data['provenance']??'-',json_encode($e0->data['raw']??''));
$e=call_rebuild($base+array('sn'=>'ABC'));
printf("SN=ABC, no challenge:      %d %s\n",$e->status,$e->data['code']??'ok');
$ch=$e->data['challenge']??'';
$e2=call_rebuild($base+array('sn'=>'ABC','provenance_challenge'=>$ch));
$out=$e2->data['raw']??'';
printf("SN=ABC with challenge:     %d %s prov=%s changed=%s raw=%s\n",$e2->status,$e2->data['code']??'ok',$e2->data['provenance']??'-',json_encode($e2->data['changed_fields']??null),json_encode($out));
$e3=call_rebuild($base+array('sn'=>'ABC','provenance_challenge'=>'bogus'));
printf("SN=ABC with bogus challenge: %d %s\n",$e3->status,$e3->data['code']??'ok');

/*
 * 2.15.5: το παλιό assertion έλεγχε μόνο ότι η έξοδος ΔΕΝ περιέχει AI 240 και
 * περνούσε και όταν η ανακατασκευή αποτύγχανε (κενό raw). Τώρα κάθε βήμα
 * ελέγχεται ρητά, και η αποτυχία δίνει κωδικό εξόδου ≠ 0.
 */
$fails=0;
function check($label,$ok){ global $fails; if(!$ok) $fails++; echo ($ok?'PASS':'FAIL'),' ',$label,"\n"; }
check('clean reading → 200, provenance scan', 200===$e0->status && 'scan'===($e0->data['provenance']??''));
check('changed SN without challenge → 409 manual_override_required + challenge', 409===$e->status && 'manual_override_required'===($e->data['code']??'') && ''!==$ch);
check('with the issued challenge → 200 manual_reconstruction', 200===$e2->status && 'manual_reconstruction'===($e2->data['provenance']??''));
check('  ...output raw is exactly the rebuilt SN=ABC string', "0105012345678900"."17280331"."10LOT\x1d21ABC"===$out);
check('  ...no injected AI 240', false===strpos($out,"\x1d240") && false===strpos($out,'24012'));
check('bogus challenge is refused (not 200, no raw)', 200!==$e3->status && ''===($e3->data['raw']??''));
echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit($fails?1:0);
