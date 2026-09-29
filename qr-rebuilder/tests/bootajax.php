<?php
require __DIR__.'/bootp.php';
define('MINUTE_IN_SECONDS',60); define('HOUR_IN_SECONDS',3600); define('DAY_IN_SECONDS',86400);
define('QRRP_DEFAULT_EMAIL_CAPABILITY','qrrp_pharmacist');
class JsonExit extends Exception { public $ok,$data,$status; function __construct($ok,$d,$s){$this->ok=$ok;$this->data=$d;$this->status=$s;parent::__construct('json');} }
function add_action(...$a){} function qrrp_gs1_minimum_print_width_mm(...$a){return false;} function qrrp_gs1_minimum_x_dimension_mm(...$a){return false;} function qrrp_x_dimension_mm(...$a){return false;} function qrrp_print_barcode_mm(...$a){return false;} function add_filter(...$a){}
function sanitize_text_field($s){return trim(preg_replace('/[\r\n\t ]+/',' ',strip_tags((string)$s)));}
function wp_unslash($v){return $v;} function sanitize_key($k){return preg_replace('/[^a-z0-9_\-]/','',strtolower($k));} function user_can(...$a){return true;} function get_userdata($i){return false;} function get_current_user_id(){return $GLOBALS['__uid'] ?? 1;} function get_user_meta(...$a){return '';}
function check_ajax_referer(...$a){return true;}
function current_user_can($c){return true;}
function is_user_logged_in(){return $GLOBALS['__logged_in'] ?? true;}
function has_filter($t){return isset($GLOBALS['__filters'][$t]);} function nocache_headers(){} function wp_create_nonce($a){return 'abcdef1234';}
function sanitize_email($e){return trim($e);} function is_email($e){return (bool)filter_var($e,FILTER_VALIDATE_EMAIL);}
function wp_send_json_error($d,$s=400){ throw new JsonExit(false,$d,$s);} function wp_send_json_success($d){ throw new JsonExit(true,$d,200);}

function esc_html($s){return htmlspecialchars($s);}
final class QRRP_Rate_Limiter {
  public static $log=array(); public static $full=array(); public static $subjects=array();
  public static $deny=array();
  static function hit($action,$limit,$window,$scope='actor'){ self::$log[]="hit:$scope:$action"; return empty(self::$deny[$action]); }
  static function has_capacity($action,$limit,$window,$scope='global'){ self::$log[]="peek:$action"; return empty(self::$full[$action]); }
  public static $windows=array();
  static function hit_subject($action,$subject,$limit,$window){ self::$log[]="subject:$action"; self::$windows[]=$window; $k=$action.'|'.$subject; self::$subjects[$k]=(self::$subjects[$k]??0)+1; return self::$subjects[$k] <= $limit; }
}
final class QRRP_Stats { static function record_rejection(){} static function record_success(){} static function record_scan($r){} }
final class QRRP_Mailer {
  public static $sent=0; public static $during=null; public static $fail=false;
  static function send(...$a){ if(self::$during){ $f=self::$during; self::$during=null; $f(); } if(self::$fail) return new WP_Error('qrrp_mail_failed','Η αποστολή email απέτυχε.'); self::$sent++; return true; }
}
$PD=qrrp_test_pdir();
require $PD.'/includes/class-qrrp-text.php';
require $PD.'/includes/functions-access.php';
$code=file_get_contents($PD.'/includes/class-qrrp-ajax.php'); $code=preg_replace('/^require_once .*rate-limiter.*$/m','',$code); eval('?>'.$code);
function call_send($post){ $_SERVER['REQUEST_METHOD']='POST'; $_POST=$post; try{ QRRP_Ajax::send_email(); }catch(JsonExit $e){ return array($e->ok,$e->status,$e->data['code']??'ok'); } return array('noexit'); }
