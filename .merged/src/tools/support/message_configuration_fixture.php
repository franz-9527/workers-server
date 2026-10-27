<?php
declare(strict_types=1);
$fixtureRoot=dirname(__DIR__,2);
spl_autoload_register(static function(string $class)use($fixtureRoot):void{if(strpos($class,'WorkerAdmin\\')!==0)return;$f=$fixtureRoot.'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';if(is_file($f))require_once $f;});
$checks=0;
function configAssert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);++$GLOBALS['checks'];}
function configReject(callable $run,string $part):void{try{$run();throw new LogicException('missing rejection');}catch(RuntimeException $e){configAssert(strpos($e->getMessage(),$part)!==false,$part);}}
if(!function_exists('setting')){function setting($key,$default=null){return $default;}}
if(!function_exists('app_config')){function app_config($key,$default=null){return $default;}}
function request_id(){return 'config-test';}
function admin_url($route,$params=[]){return '/admin/?route='.$route.'&'.http_build_query($params);}
function flash($type,$text){$GLOBALS['flashes'][]=[$type,$text];}
function old_input($input){$GLOBALS['old']=$input;}
function redirect($url){$GLOBALS['redirects'][]=$url;}
function view($name,$data){$GLOBALS['views'][]=[$name,$data];return 'view';}
function json_response($data,$status=200){$GLOBALS['json']=[$data,$status];}
function write_log(...$args){$GLOBALS['logs'][]=$args;}
final class ConfigSettings implements \WorkerAdmin\Contracts\SettingsProvider {
 public array $values=['wechat.admin_openids'=>['admin-openid-123'],'sms.template_codes'=>['order_status'=>'SMS_12345678']];
 public function get(string $key,$default=null){return $this->values[$key]??$default;}
 public function bool(string $key,bool $default=false):bool{return filter_var($this->get($key,$default),FILTER_VALIDATE_BOOLEAN);}
 public function int(string $key,int $default=0):int{return (int)$this->get($key,$default);}
}
final class ConfigRepo implements \WorkerAdmin\Contracts\AdminConfigurationRepository {
 public ?ConfigSettings $settings=null;public array $rows=[];public array $writes=[];public bool $blocked=false;public int $seeds=0;public int $identityReads=0;
 public function pageRows(array $groups,bool $messages=false):array{ $all=[];foreach($groups as $group)foreach($this->rows[$group]??[] as $row){if(!$messages||$row['setting_key']!=='sms.admin_mobile')$all[]=$row;}return $all?:[['setting_key'=>'announcement.order_submit.text','setting_value'=>'Notice']];}
 public function ensureInternalDefaults():void{++$this->seeds;}
 public function categories():array{return [['id'=>1,'name'=>'墙布'],['id'=>2,'name'=>'窗帘']];}
 public function fieldDictionary():array{return [['table_name'=>'users','field_name'=>'id','chinese_name'=>'自增ID']];}
 public function settingsForGroup(string $group):array{return $this->rows[$group]??[];}
 public function saveMany(array $updates,int $adminId):void{$this->writes[]=['many',$updates,$adminId];}
 public function set(string $key,string $value,int $adminId):void{$this->writes[]=['set',$key,json_decode($value,true),$adminId];if($this->settings)$this->settings->values[$key]=json_decode($value,true);}
 public function userForOpenid(string $openid):int{++$this->identityReads;return 42;}
 public function runtimeConfigRows():array{return [['setting_key'=>'wechat.admin_openids','setting_value'=>'["admin-openid-123"]','value_type'=>'json']];}
 public function assertSendBoundary():void{if($this->blocked)throw new RuntimeException('事务内禁止发送');}
}
final class ConfigMetadata implements \WorkerAdmin\Contracts\SubscriptionMetadata {
 public function definitions():array{return ['reviewResult'=>['title'=>'审核','name'=>'审核','template_id'=>'template_123','description'=>'desc','recipient'=>'worker','available'=>true,'configuration_complete'=>true,'global_enabled'=>true,'field_semantics'=>[],'important'=>true,'keywords'=>[],'field_map'=>[],'subscription_type'=>'one_time'],'user_order_update'=>['title'=>'进度','template_id'=>'template_456','recipient'=>'user','available'=>true,'field_semantics'=>['order_no','status','time'],'global_enabled'=>true,'important'=>true]];}
 public function semanticLabels():array{return ['order_no'=>'订单号','status'=>'状态','time'=>'时间'];}
 public function fixedReviewFields():array{return ['name1','phone_number2','phrase3','thing5'];}
 public function infer(array $scene,array $actual,array $previous=[]):array{return ['complete'=>true,'field_map'=>[],'field_defaults'=>[],'unresolved_fields'=>[],'missing_semantics'=>[]];}
 public function recommend(array $scene,array $keywords):array{return [1,2];}
}
final class ConfigSubscribe implements \WorkerAdmin\Contracts\SubscribeMessageGateway {
 public array $sent=[];public array $scans=[];public string $failure='';public array $templates=['template_123'=>['title'=>'审核通知','type'=>'2','fields'=>['name1','phone_number2','phrase3','thing5'],'field_labels'=>[]]];public int $adds=0;
 public function categoryList():array{return [['id'=>1,'name'=>'家居']];}
 public function publicTemplateTitles(array $ids,int $start=0,int $limit=30):array{$this->scans[]=$start;return ['count'=>3000,'data'=>array_fill(0,30,['tid'=>'tid','title'=>'无匹配标题'])];}
 public function publicTemplateKeywords(string $tid):array{return [['kid'=>1],['kid'=>2]];}
 public function addTemplate(string $tid,array $kids,string $description=''):string{++$this->adds;return 'template_123';}
 public function templateList():array{return $this->templates;}
 public function send(array $payload):array{$this->sent[]=$payload;if($this->failure!=='')throw new RuntimeException($this->failure);return ['errcode'=>0];}
}
final class ConfigSms implements \WorkerAdmin\Contracts\SmsGateway {
 public array $sent=[];
 public function enabled():bool{return false;}
 public function send(string $mobile,string $code,array $params,string $out='',bool $test=false):array{$this->sent[]=[$mobile,$code,$params,$out,$test];return ['BizId'=>'BIZ'];}
 public function sendWithOutcome(string $m,string $c,array $p,string $o='',bool $t=false):array{return [];}
 public function querySendDetails(string $m,string $d,?string $b=null):array{return [];}
}
final class ConfigQuota implements \WorkerAdmin\Contracts\SubscriptionQuota {
 public int $remaining=2;public int $consumed=0;public int $invalidated=0;
 public function preferences(int $id):array{return ['reviewResult'=>['remaining_count'=>$this->remaining,'authorization_status'=>'accept']];}
 public function consume(int $id,string $scene):void{--$this->remaining;++$this->consumed;}
 public function invalidate(int $id,string $scene):void{$this->remaining=0;++$this->invalidated;}
}
final class ConfigAudit implements \WorkerAdmin\Contracts\AdminConfigurationAudit {public array $rows=[];public function record(array $args):void{$this->rows[]=$args;}}
final class ConfigGuard implements \WorkerAdmin\Contracts\ConfigurationWriteGuard {public int $runs=0;public bool $held=false;public function run(callable $call){++$this->runs;$this->held=true;try{return $call();}finally{$this->held=false;}}}
final class ConfigRuntime implements \WorkerAdmin\Contracts\WorkerRuntimeConfigurationGateway {
 public bool $fail=false;public ConfigAudit $audit;
 public function sync(array $values):array{configAssert(end($this->audit->rows)[0]==='settings.update','settings audit precedes cloud request');if($this->fail)throw new RuntimeException('cloud unavailable');return ['admin_count'=>1,'version'=>'test'];}
}

function configFixture():array{
$repo=new ConfigRepo();$repo->settings=$settings=new ConfigSettings();$meta=new ConfigMetadata();$subscribe=new ConfigSubscribe();$sms=new ConfigSms();$quota=new ConfigQuota();$audit=new ConfigAudit();$guard=new ConfigGuard();$runtime=new ConfigRuntime();$runtime->audit=$audit;
$samples=new \WorkerAdmin\Application\Messaging\SubscriptionTestData($settings);$args=[$repo,$meta,$settings,$subscribe,$sms,$quota,$samples,$audit];
$read=new \WorkerAdmin\Application\Messaging\SettingsRead($repo);$save=new \WorkerAdmin\Application\Messaging\SettingsSave($repo,$meta,$settings,$guard,$runtime,$audit);
$templates=new \WorkerAdmin\Application\Messaging\SubscriptionTemplateManager($repo,$meta,$settings,$subscribe,$audit);$library=new \WorkerAdmin\Application\Messaging\SubscriptionLibrarySearch($meta,$subscribe);$test=new \WorkerAdmin\Application\Messaging\AdminMessageTest(...$args);$smsSettings=new \WorkerAdmin\Application\Messaging\SmsSettingsManager($repo,$meta,$settings,$audit);
return compact('repo','meta','settings','subscribe','sms','quota','audit','guard','runtime','read','save','templates','library','test','smsSettings');
}

function configRows(ConfigRepo $repo,string $group,array $items):void{
 foreach($items as $key=>$entry)$repo->rows[$group][]=['setting_key'=>$key,'value_type'=>$entry[0],'setting_value'=>$entry[1]];
}
function configSave($save,string $scope,string $group,array $values,array $state=[]):array{
 return $save->save($state+['_settings_scope'=>$scope,'_settings_group'=>$group,'settings'=>$values],51);
}
function configLastWrite($repo):array{return end($repo->writes)[1];}
function configInvalid($save,$repo,string $scope,string $group,array $values):void{
 $before=count($repo->writes);$rejected=false;
 try{configSave($save,$scope,$group,$values);}catch(RuntimeException $e){$rejected=true;}
 configAssert($rejected && count($repo->writes)===$before,'invalid '.$group.' values rejected before persistence');
}
