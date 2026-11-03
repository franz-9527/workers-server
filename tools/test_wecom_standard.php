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
    $root=dirname(__DIR__);
    spl_autoload_register(static function($class)use($root){if(strpos($class,'WorkerAdmin\\')===0){$p=$root.'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';if(is_file($p))require_once $p;}});
    function setting($key,$default=null){return $GLOBALS['settings'][$key]??$default;}
    function app_config($key,$default=null){return $GLOBALS['config'][$key]??$default;}
    function write_log(...$args){}
    function verify($condition,$label){if(!$condition)throw new \RuntimeException($label);echo "[PASS] $label\n";}
    function responses(array $bodies){$GLOBALS['requests']=[];$GLOBALS['responses']=array_map(static fn($b)=>['body'=>$b],$bodies);}
    $GLOBALS['settings']=['wecom.webhook_enabled'=>true,'wecom.webhook_url'=>'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=TEST-NOT-A-REAL-WEBHOOK-KEY','wechat.miniapp_appid'=>'wx1234567890abcdef'];
    $notifier=new \WorkerAdmin\Infrastructure\Messaging\WeComAdminNotifier();
    $GLOBALS['config']=['app.env'=>'production','app.url'=>'https://example.test'];
    responses(['{"errcode":0,"errmsg":"ok"}']);
    $result=$notifier->send('【新的师傅入驻资料待审核】'."\n资料编号：CW-1\n姓名：测试师傅",'/admin/?route=workers');
    $card=$GLOBALS['requests'][0]['template_card'];
    verify($GLOBALS['requests'][0]['msgtype']==='template_card'&&$card['card_type']==='text_notice','official text_notice envelope');
    verify($card['main_title']['title']==='新的师傅入驻资料待审核'&&$card['horizontal_content_list'][0]===['keyname'=>'资料编号','value'=>'CW-1'],'business title and fields retain Chinese text');
    verify($card['jump_list'][0]===['type'=>1,'url'=>'https://example.test/admin/?route=workers','title'=>'网页后台处理'],'web entry uses current site');
    verify($card['jump_list'][1]===['type'=>2,'appid'=>'wx1234567890abcdef','pagepath'=>'pages/mine/examine?source=worker','title'=>'小程序查看'],'worker review mini-program jump');
    verify($card['card_action']===['type'=>1,'url'=>'https://example.test/admin/?route=workers'],'whole card action is required and points to admin web');
    verify($result['delivery_receipt']['message_type']==='template_card'&&$result['delivery_receipt']['card_type']==='text_notice'&&strlen($result['delivery_receipt']['target_fingerprint'])===12,'receipt identifies actual card without secret');
    verify(strpos(json_encode($result['delivery_receipt']),'TEST-NOT-A-REAL')===false&&$result['delivery_receipt']['delivery_status']==='unconfirmed','receipt never invents delivery or stores webhook secret');
    $GLOBALS['settings']['wecom.webhook_mention_mobile']='13800138000';
    responses(['{"errcode":0}']);$notifier->send('【订单待审核】'."\n订单号：ORD_20261101",'/admin/?route=orders.view&id=19');
    $request=$GLOBALS['requests'][0];
    verify(count($GLOBALS['requests'])===1&&!isset($request['text'])&&!isset($request['template_card']['mentioned_mobile_list']),'card sends once without unsupported text mention fields');
    verify($request['template_card']['jump_list'][1]['pagepath']==='pages/adminWorkbench/detail?id=ORD_20261101','dotted order route accepts numeric admin id and keeps public mini order number');
    foreach([
        ['/admin/?route=orders','pages/adminWorkbench/index'],
        ['/admin/?route=orders.view&id=19','pages/adminWorkbench/index'],
        ['/admin/?route=platform-orders.pricing-review&order_no=ORD-1','pages/adminWorkbench/pricingReview?id=ORD-1'],
        ['/admin/?route=platform-orders','pages/adminWorkbench/index?scope=todo'],
        ['/admin/?route=platform-order-finance','pages/adminWorkbench/index?scope=finance'],
        ['/admin/?route=merchants','pages/mine/examine?source=store'],
        ['/admin/?route=withdrawals','pages/adminWorkbench/index'],
        ['/admin/?route=worker-credentials','pages/adminWorkbench/index'],
        [null,'pages/adminWorkbench/index'],
    ] as [$path,$page]) {
        responses(['{"errcode":0}']);$notifier->send('【待办】',$path);$card=$GLOBALS['requests'][0]['template_card'];
        verify($card['jump_list'][1]['pagepath']===$page,'scene mini-program destination: '.($path??'generic'));
        if(in_array($path,['/admin/?route=withdrawals','/admin/?route=worker-credentials',null],true))verify(strpos($card['sub_title_text'],'网页后台办理')!==false,'web-only action has explicit instruction');
    }
    foreach([
        '/admin/?route=orders.view&route=workers','/admin/?route=orders.view&id[]=1',
        '/admin/?route=orders.view&id=1&redirect=https://evil.test','/admin/?route=orders.view#fragment',
        '/admin/?route=orders..view','/admin/?route=../workers','https://evil.test/admin/?route=workers',
        '//evil.test/admin/?route=workers','x:/admin/?route=workers','/admin/../?route=workers',
        "/admin/?route=orders.view&id=1\n",'/admin/?route=workers&order_no=bad%0Avalue',
        '/admin/?route[]=workers','/admin/?route=workers&id='.str_repeat('x',81),
    ] as $path) {
        responses([]);$failed=false;
        try{$notifier->send('待办',$path);}catch(\RuntimeException $e){$failed=strpos($e->getMessage(),'路径不合法')!==false;}
        verify($failed&&count($GLOBALS['requests'])===0,'invalid action rejected before transport: '.json_encode($path));
    }
    $GLOBALS['config']['app.env']='testing';$GLOBALS['config']['app.url']='https://test.example.test';
    responses([]);$failed=false;try{$notifier->send('【入驻】','/admin/?route=workers');}catch(\RuntimeException $e){$failed=true;}
    verify($failed&&count($GLOBALS['requests'])===0,'testing environment sending gate retained');
    $GLOBALS['settings']['testing.allow_wecom']=true;
    responses(['{"errcode":0}']);$notifier->send('【入驻】', '/admin/?route=workers');$card=$GLOBALS['requests'][0]['template_card'];
    verify($card['source']['desc']==='壁选师傅·测试站'&&$card['main_title']['title']==='入驻'&&$card['jump_list'][0]['url']==='https://test.example.test/admin/?route=workers','test card visibly scoped and links only to test site');
    $GLOBALS['config']['app.env']='production';
    $GLOBALS['settings']['wechat.miniapp_appid']='';
    responses(['{"errcode":0}']);$notifier->send('待办','/admin/?route=workers');$card=$GLOBALS['requests'][0]['template_card'];
    verify($card['jump_list'][1]['type']===0&&!isset($card['jump_list'][1]['appid'])&&strpos($card['sub_title_text'],'AppID')!==false,'missing appid is explicit while web action remains usable');
    $GLOBALS['settings']['wechat.miniapp_appid']='wx1234567890abcdef';
    foreach(['http://example.test','https://user:pass@example.test','https://example.test?q=bad','https://example.test/#bad',''] as $base) {
        $GLOBALS['config']['app.url']=$base;responses([]);$failed=false;try{$notifier->send('待办');}catch(\RuntimeException $e){$failed=strpos($e->getMessage(),'APP_URL')!==false;}
        verify($failed&&count($GLOBALS['requests'])===0,'invalid site URL is actionable and never sent');
    }
    $GLOBALS['config']['app.url']='https://example.test';
    responses(['{"errcode":0}']);
    $notifier->send('【'.str_repeat('超长标题',20).'】'."\n".implode("\n",array_fill(0,12,str_repeat('超长键名',6).'：'.str_repeat('中文内容',100)))."\n".str_repeat('补充',500));
    $card=$GLOBALS['requests'][0]['template_card'];
    verify(mb_strlen($card['main_title']['title'])<=26&&count($card['horizontal_content_list'])===6&&mb_strlen($card['sub_title_text'])<=112,'long Chinese content respects title, list and summary bounds');
    foreach($card['horizontal_content_list'] as $field)verify(mb_strlen($field['keyname'])<=5&&mb_strlen($field['value'])<=26&&mb_check_encoding($field['value'],'UTF-8'),'field bounds preserve valid UTF-8');
    foreach(['{}','{"errcode":false}','{"errcode":""}','{"errcode":"oops"}','{"errcode":93000,"errmsg":"invalid webhook"}','not-json'] as $body) {
        responses([$body]);$failed=false;try{$notifier->send('测试');}catch(\RuntimeException $e){$failed=true;}
        verify($failed&&count($GLOBALS['requests'])===1,'invalid or rejected provider response never accepted or resent');
    }
    responses(['{"errcode":"0","errmsg":"ok"}']);
    verify($notifier->send('测试')['delivery_receipt']['errcode']===0,'numeric string zero accepted');
    responses(['{"errcode":40058,"errmsg":"invalid payload"}']);
    $failed=false;try{$notifier->send('【入驻】','/admin/?route=workers');}catch(\RuntimeException $e){$failed=strpos($e->getMessage(),'40058')!==false;}
    verify($failed&&count($GLOBALS['requests'])===1,'provider rejection retains code with no silent fallback');
    $GLOBALS['responses']=[['http'=>500,'body'=>'{"errcode":0}']];$GLOBALS['requests']=[];
    $failed=false;try{$notifier->send('待办');}catch(\RuntimeException $e){$failed=true;}
    verify($failed,'HTTP failure cannot become accepted');
    echo "WeCom text_notice and dual entry regression passed.\n";
}
