<?php require 'bootajax.php';
$f=array('PC'=>'05012345678900','SN'=>'SN1','LOT'=>'LOT','EXP'=>'2028-03-31');
$built=QRRP_GS1_Parser::validate_and_build($f);
$tok=QRRP_Tokens::issue('email_rebuild',array('pc'=>$f['PC'],'sn'=>$f['SN'],'lot'=>$f['LOT'],'exp'=>$f['EXP'],'extras'=>array()));
$post=array('email'=>'a@b.gr','raw'=>$built['raw'],'pc'=>$f['PC'],'sn'=>$f['SN'],'lot'=>$f['LOT'],'exp'=>$f['EXP'],'rebuild_token'=>$tok);
$inner=null;
QRRP_Mailer::$during=function() use ($post,&$inner){ $inner=call_send($post); };
$outer=call_send($post);
echo "Race (B runs while A is inside wp_mail): A=",json_encode($outer)," B=",json_encode($inner)," emails_sent=",QRRP_Mailer::$sent,"\n";
echo (QRRP_Mailer::$sent===1?"PASS":"FAIL")," one email per single-use link\n";
// SMTP failure message
QRRP_Mailer::$sent=0; QRRP_Mailer::$fail=true;
$tok2=QRRP_Tokens::issue('email_rebuild',array('pc'=>$f['PC'],'sn'=>$f['SN'],'lot'=>$f['LOT'],'exp'=>$f['EXP'],'extras'=>array()));
$post['rebuild_token']=$tok2; $_SERVER['REQUEST_METHOD']='POST'; $_POST=$post;
try{ QRRP_Ajax::send_email(); }catch(JsonExit $e){ echo "SMTP fail: status=",$e->status," code=",$e->data['code']," msg=",$e->data['message'],"\n"; }
