<?php
declare(strict_types=1);
namespace WorkerAdmin\Infrastructure\Messaging {
    function curl_init($url) { return (object)['url'=>$url,'options'=>[],'response'=>null]; }
    function curl_setopt_array($handle,$options) { $handle->options=$options;return true; }
    function curl_exec($handle) {
        if (!$GLOBALS['responses']) throw new \RuntimeException('Unexpected external request');
        $handle->response=array_shift($GLOBALS['responses']);
        $GLOBALS['requests'][]=json_decode($handle->options[CURLOPT_POSTFIELDS],true);
        return $handle->response['body'];
    }
    function curl_error($handle) { return ''; }
    function curl_getinfo($handle,$option) { return $handle->response['http']??200; }
    function curl_close($handle) {}
}
namespace {
    $root=dirname(__DIR__,2);
    spl_autoload_register(static function($class)use($root){if(strpos($class,'WorkerAdmin\\')===0){$p=$root.'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';if(is_file($p))require_once $p;}});
    function setting($key,$default=null){return $GLOBALS['settings'][$key]??$default;}
    function app_config($key,$default=null){return $key==='app.env'?'production':($key==='app.url'?'https://example.test':$default);}
    function write_log(...$args){}
    function verify($condition,$label){if(!$condition)throw new \RuntimeException($label);echo "[PASS] $label\n";}
    function responses(array $bodies){$GLOBALS['requests']=[];$GLOBALS['responses']=array_map(static fn($b)=>['body'=>$b],$bodies);}
    $GLOBALS['settings']=['wecom.webhook_enabled'=>true,'wecom.webhook_url'=>'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=TEST-NOT-A-REAL-WEBHOOK-KEY','wechat.miniapp_appid'=>'wx1234567890abcdef','site.url'=>'https://example.test'];
    $notifier=new \WorkerAdmin\Infrastructure\Messaging\WeComAdminNotifier();
    responses(['{"errcode":0,"errmsg":"ok"}']);
    $result=$notifier->send('【新的师傅入驻资料待审核】'."\n资料编号：CW-1",'/admin/?route=workers');
    verify($GLOBALS['requests'][0]['msgtype']==='text','all admin notices use the standard text payload');
    verify(strpos($GLOBALS['requests'][0]['text']['content'],'管理员工作台')!==false&&strpos($GLOBALS['requests'][0]['text']['content'],'https://example.test/admin/?route=workers')!==false,'text retains app guidance and web action');
    verify($result['delivery_receipt']['message_type']==='text'&&strlen($result['delivery_receipt']['target_fingerprint'])===12,'accepted receipt contains type and safe target fingerprint');
    verify(strpos(json_encode($result['delivery_receipt']),'TEST-NOT-A-REAL')===false,'receipt never contains webhook secret');
    $GLOBALS['settings']['wecom.webhook_mention_mobile']='13800138000';
    responses(['{"errcode":0,"errmsg":"ok"}']);
    $result=$notifier->send('【新的师傅入驻资料待审核】', '/admin/?route=workers');
    verify(count($GLOBALS['requests'])===1&&$GLOBALS['requests'][0]['msgtype']==='text','configured mention uses one text notification');
    verify($GLOBALS['requests'][0]['text']['mentioned_mobile_list']===['13800138000'],'configured administrator mention is retained');
    responses(['{"errcode":0}']);
    $notifier->send(str_repeat('师傅入驻资料',700));
    verify(strlen($GLOBALS['requests'][0]['text']['content'])<=2048&&mb_check_encoding($GLOBALS['requests'][0]['text']['content'],'UTF-8'),'long Chinese text respects byte limit and UTF-8');
    foreach(['{}','{"errcode":false}','{"errcode":""}','{"errcode":"oops"}','{"errcode":93000,"errmsg":"invalid webhook"}','not-json']as $body){
        responses([$body]);$failed=false;
        try{$notifier->send('测试');}catch(\RuntimeException $e){$failed=true;}
        verify($failed,'malformed or rejected provider response is never accepted');
    }
    responses(['{"errcode":"0","errmsg":"ok"}']);
    verify($notifier->send('测试')['delivery_receipt']['errcode']===0,'numeric-string zero is accepted');
    unset($GLOBALS['settings']['wecom.webhook_mention_mobile']);
    responses(['{"errcode":40058,"errmsg":"invalid payload"}']);
    $failed=false;try{$notifier->send('【入驻】','/admin/?route=workers');}catch(\RuntimeException $e){$failed=true;}
    verify($failed&&count($GLOBALS['requests'])===1,'explicit rejection is retained and never silently downgraded');
    responses(['{"errcode":0,"errmsg":"ok"}']);
    $notifier->send('【入驻】','/admin/?route=workers');
    verify(count($GLOBALS['requests'])===1,'one event sends exactly one standard message');
    responses([json_encode(['errcode'=>0])]);
    $result=$notifier->send(str_repeat('师傅',3000),'/admin/?route=workers');
    verify(strlen($GLOBALS['requests'][0]['text']['content'])<=2048,'Chinese text and action URL together respect official byte limit');
    verify($result['delivery_receipt']['delivery_status']==='unconfirmed','API acceptance never fabricates group delivery');
    echo "WeCom standard payload and receipt regression passed.\n";
}
