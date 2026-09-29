<?php
require_once __DIR__.'/lib/pdir.php';
define('ABSPATH', '/tmp/');
define('QRRP_PLUGIN_DIR', rtrim(qrrp_test_pdir(),'/').'/');
$GLOBALS['__filters'] = array();
$GLOBALS['__wpdate_calls'] = 0;
function __($s,$d=null){return $s;}
function _n($a,$b,$n,$d=null){return $n==1?$a:$b;}
function apply_filters($tag,$v,...$a){ if(isset($GLOBALS['__filters'][$tag])) return $GLOBALS['__filters'][$tag]($v,...$a); return $v;}
function do_action(...$a){}
function get_option($o,$d=false){return $GLOBALS['__options'][$o] ?? $d;}
function absint($v){return abs((int)$v);}
function wp_date($f,$ts=null){ $GLOBALS['__wpdate_calls']++; $d=new DateTimeImmutable('@'.($ts??(getenv('QRRP_TEST_NOW')!==false?(int)getenv('QRRP_TEST_NOW'):time()))); $d=$d->setTimezone(new DateTimeZone('Europe/Athens')); return $d->format($f);}
function is_wp_error($x){return $x instanceof WP_Error;}
class WP_Error{ public $code,$msg; function __construct($c='',$m=''){$this->code=$c;$this->msg=$m;} function get_error_code(){return $this->code;} function get_error_message(){return $this->msg;}}
function qrrp_max_raw_bytes(){return 4096;}
function qrrp_total_print_modules($c,$q){return $c+2*$q;}
function wp_salt($s=''){return 'salt';}
require (qrrp_test_pdir()).'/includes/class-qrrp-gs1-parser.php';
function t($label,$fn){ $GLOBALS['__wpdate_calls']=0; $t=microtime(true); $r=$fn(); $ms=(microtime(true)-$t)*1000; printf("%-45s %8.1f ms  wp_date=%d\n",$label,$ms,$GLOBALS['__wpdate_calls']); return $r;}
