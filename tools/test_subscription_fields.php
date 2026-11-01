<?php
declare(strict_types=1);
namespace WorkerAdmin\Infrastructure\External {
    final class WeChatAccessTokenProvider {
        public static function fromSettings(): self { $GLOBALS['token_reads']++;return new self(); }
        public function get(): string { return 'FIXTURE-NOT-A-TOKEN'; }
        public function reject(string $token): void { $GLOBALS['token_rejections']++; }
    }
    function curl_init($url) { return (object)['url'=>$url,'options'=>[],'response'=>null]; }
    function curl_setopt_array($handle,$options) { $handle->options=$options;return true; }
    function curl_setopt($handle,$key,$value) { $handle->options[$key]=$value;return true; }
    function curl_exec($handle) {
        if(!$GLOBALS['responses'])throw new \RuntimeException('Unexpected real request prevented');
        $handle->response=array_shift($GLOBALS['responses']);
        $GLOBALS['requests'][]=json_decode($handle->options[CURLOPT_POSTFIELDS],true);
        return json_encode($handle->response);
    }
    function curl_error($handle) { return ''; }
    function curl_getinfo($handle,$option) { return 200; }
    function curl_close($handle) {}
}
namespace {
    set_error_handler(function($level,$message,$file,$line){throw new \ErrorException($message,0,$level,$file,$line);});
    spl_autoload_register(function($class){if(strpos($class,'WorkerAdmin\\')===0){$f=dirname(__DIR__).'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';if(is_file($f))require_once $f;}});
    function setting($key,$default=null){return $GLOBALS['settings'][$key]??$default;}
    function app_config($key,$default=null){return $key==='app.env'?'production':$default;}
    function write_log(...$args){}
    function check($ok,$label){if(!$ok)throw new \RuntimeException($label);echo "[PASS] $label\n";}
    function fake(array $response=['errcode'=>0]):void{$GLOBALS['requests']=[];$GLOBALS['responses']=[$response];$GLOBALS['token_reads']=0;$GLOBALS['token_rejections']=0;}
    use WorkerAdmin\Domain\Messaging\WechatTemplateValue as Value;
    use WorkerAdmin\Domain\Messaging\MessageDeliveryFailurePolicy as Policy;
    use WorkerAdmin\Infrastructure\Messaging\SubscriptionCatalog;
    use WorkerAdmin\Application\Messaging\SubscriptionTestData;
    use WorkerAdmin\Infrastructure\External\WeChatSubscribeGateway;
    $scene=['template_id'=>'fixture-construction-progress','synced_template_id'=>'fixture-construction-progress',
        'template_title'=>'施工进度提醒','template_fields'=>['character_string1','thing2','date3','phrase4','thing5'],
        'template_field_labels'=>['character_string1'=>'订单号','thing2'=>'备注','date3'=>'更新时间','phrase4'=>'订单状态','thing5'=>'项目名称'],
        'field_map'=>['order_no'=>'character_string1','notice_text'=>'thing2','time'=>'date3','status'=>'phrase4','service_category'=>'thing5']];
    $GLOBALS['settings']=['wechat.subscribe_enabled'=>true,'wechat.subscribe_template_ids'=>['worker_order_update'=>$scene]];
    $provider=new class implements \WorkerAdmin\Contracts\SettingsProvider {
        public function get(string $k,$default=null){return setting($k,$default);}
        public function bool(string $k,bool $default=false):bool{return (bool)$this->get($k,$default);}
        public function int(string $k,int $default=0):int{return (int)$this->get($k,$default);}
    };
    check(Value::format('phrase1','已安排施工师傅')==='已安排施工','phrase field obeys official five Han limit');
    check(Value::format('phrase1','退款中')==='退款中'&&Value::format('phrase1','待处理2')===null,'short phrase accepts Han only');
    check(mb_strlen(Value::format('name1',str_repeat('张',30)))===10,'Chinese name max ten');
    check(strlen(Value::format('name1',str_repeat('A',30)))===20,'Latin name max twenty');
    check(mb_strlen(Value::format('name1','Alex'.str_repeat('张',20)))===10,'mixed Chinese and Latin uses ten');
    check(strlen(Value::format('character_string1',str_repeat('A',64)))===32,'character string max thirty two');
    check(mb_strlen(Value::format('car_number1','粤A123456挂多余'))===8,'car number max eight');
    check(Value::format('date1','2026-02-30 12:00')===null&&Value::format('date1','2024-02-29 12:00')==='2024-02-29','calendar rejects impossible day');
    check(Value::format('time1','29:00')===null&&Value::format('time1','2026-11-01 23:59')==='23:59','time rejects hours beyond twenty three');
    check(Value::format('number1','1.2.3')===null&&Value::format('number1','-12')===null,'malformed numbers do not pass');
    check(Value::formatBusiness('status','phrase4','师傅已接受维修，请及时处理')==='进度更新','long business state uses safe summary instead of incomplete sentence');
    check(Value::formatBusiness('status','phrase4','维修超过48小时仍未完成')==='进度更新','numeric status is summarized without illegal phrase characters');
    check(Value::formatBusiness('status','phrase4','已完成')==='已完成'&&Value::formatBusiness('status','phrase4','')===null,'valid short state remains and empty state is not fabricated');
    $builder=new SubscriptionTestData($provider);$gateway=new WeChatSubscribeGateway();
    $actual=SubscriptionCatalog::subscriptionSceneDefinitions()['worker_order_update'];
    check($actual['available']===true,'five field role template is complete');
    $options=$builder->subscriptionTestEventOptions('worker_order_update');
    check(count($options)>0,'actual worker events available');
    foreach($options as $event=>$meta){
        $data=$builder->buildSubscriptionTestData('worker_order_update',$actual,['test_event_type'=>['worker_order_update'=>$event]]);
        check(count($data)===5&&preg_match('/^\p{Han}{1,5}$/u',$data['phrase4']['value'])===1,'five-field worker test data meets state rule: '.$event);
        check($data['thing2']['value']===mb_substr($meta['status'],0,20),'full event remark retained up to official text limit: '.$event);
        fake();$gateway->send(['openid'=>'fixture-admin-openid','template_id'=>$scene['template_id'],'scene_key'=>'worker_order_update','data'=>$data]);
        check(count($GLOBALS['requests'])===1&&$GLOBALS['requests'][0]['data']===$data,'actual gateway preserves all five fields: '.$event);
    }
    $valid=$builder->buildSubscriptionTestData('worker_order_update',$actual);
    fake(['errcode'=>47003,'errmsg'=>'argument invalid! data.phrase4.value invalid rid: fixture-rid-123 OPENID-PRIVATE phone=13800138000 access_token=TOKEN-PRIVATE']);
    $message='';try{$gateway->send(['openid'=>'fixture-admin-openid','template_id'=>$scene['template_id'],'scene_key'=>'worker_order_update','data'=>$valid]);}catch(\RuntimeException $e){$message=$e->getMessage();check($e->getCode()===47003,'exception code retained');}
    check(strpos($message,'订单状态（phrase4）')!==false&&strpos($message,'最多5字')!==false&&strpos($message,'fixture-rid-123')!==false,'error identifies real field, Chinese label, rule and request id');
    check(strpos($message,'OPENID-PRIVATE')===false&&strpos($message,'13800138000')===false&&strpos($message,'TOKEN-PRIVATE')===false,'diagnostic never includes recipient, value or token');
    $decision=Policy::decide('wechat_subscribe',1,$message);
    check($decision['terminal']&&$decision['permanent_code']===47003&&!$decision['invalidate_subscription'],'parameter rejection stops retry without invalidating subscription quota');
    fake(['errcode'=>43101,'errmsg'=>'user did not subscribe rid: quota-fixture-123']);
    $message='';try{$gateway->send(['openid'=>'fixture','template_id'=>'fixture','data'=>['thing1'=>['value'=>'提醒']]]);}catch(\RuntimeException $e){$message=$e->getMessage();}
    check(Policy::decide('wechat_subscribe',1,$message)['invalidate_subscription']===true,'real permission error still invalidates quota');
    fake(['errcode'=>47003,'errmsg'=>'invalid data OPENID-PRIVATE TOKEN-PRIVATE']);
    try{$gateway->send(['openid'=>'fixture','template_id'=>'fixture','data'=>['thing1'=>['value'=>'提醒']]]);}catch(\RuntimeException $e){$message=$e->getMessage();}
    check(strpos($message,'请同步微信模板')!==false&&strpos($message,'PRIVATE')===false,'missing provider field still gives safe action guidance');
    foreach([['phrase4'=>['value'=>'NOT-CHINESE']],['thing1'=>'bad'],['thing1'=>['value'=>[]]],['thing1'=>['value'=>true]],['badfield'=>['value'=>'x']]] as $bad){
        fake();$failed=false;try{$gateway->send(['openid'=>'fixture','template_id'=>'fixture','data'=>$bad]);}catch(\RuntimeException $e){$failed=$e->getCode()===47003;}
        check($failed&&count($GLOBALS['requests'])===0&&$GLOBALS['token_reads']===0,'invalid field stopped before access token or provider request');
    }
    fake();$missing=$valid;unset($missing['phrase4']);$failed=false;
    try{$gateway->send(['openid'=>'fixture','template_id'=>$scene['template_id'],'scene_key'=>'worker_order_update','data'=>$missing]);}catch(\RuntimeException $e){$failed=$e->getCode()===47003;}
    check($failed&&count($GLOBALS['requests'])===0,'missing synced template field rejected locally');
    fake();$old=['phrase4'=>['value'=>'已安排施工师傅'],'character_string1'=>['value'=>str_repeat('X',64)]];
    $gateway->send(['openid'=>'fixture','template_id'=>'fixture','data'=>$old]);
    check(mb_strlen($GLOBALS['requests'][0]['data']['phrase4']['value'])===5&&strlen($GLOBALS['requests'][0]['data']['character_string1']['value'])===32,'old queued values also pass shared formatting');
    echo "Subscription field limits, five field worker templates and safe provider diagnostics passed.\n";
}
