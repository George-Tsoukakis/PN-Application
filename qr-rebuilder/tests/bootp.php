<?php
require __DIR__.'/boot.php';
function qrrp_fingerprint($p,$v,$k=1){return hash_hmac('sha256',$v,$p);}
final class QRRP_Tokens {
  public static $s=array();
  static function issue($type,$payload,$ttl=0){ $h=bin2hex(random_bytes(16)); self::$s[$h]=array('type'=>$type)+$payload; return $h; }
  static function verify_token_for_request($h,$type,$ctx=null){ if(!isset(self::$s[$h])||self::$s[$h]['type']!==$type) return false; $p=self::$s[$h]; if(null!==$ctx){ foreach($ctx as $k=>$v){ if(!array_key_exists($k,$p)||$p[$k]!==$v) return false;} foreach($p as $k=>$v){ if($k!=='type'&&!array_key_exists($k,$ctx)) return false; } } return $p; }
  static function random_token($n){ return bin2hex(random_bytes($n)); }
  static function consume($h,$type){ if(!isset(self::$s[$h])) return false; unset(self::$s[$h]); return true; }
}
require (qrrp_test_pdir()).'/includes/class-qrrp-provenance.php';
function F($pc,$sn,$lot,$exp){return array('PC'=>$pc,'SN'=>$sn,'LOT'=>$lot,'EXP'=>$exp);}
function ev($raw,$fields,$extra=array()){ $v=QRRP_Provenance::evaluate(array('source_raw'=>$raw,'fields'=>$fields)+$extra); return $v; }
function show($label,$v){ printf("%-50s allowed=%d prov=%s err=%s changed=%s baseline=%s\n",$label,$v['allowed'],var_export($v['provenance'],1),var_export($v['error_code'],1),json_encode($v['changed_fields']),json_encode($v['verified_baseline'])); }
