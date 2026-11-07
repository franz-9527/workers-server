<?php
declare(strict_types=1);
namespace WorkerAdmin\Application\Chat {
 final class ChatWechatSIPluginProbe { public const APP_ID='wx069ba97219f66d99'; }
 final class ChatAsrOpenApiProbe { public const SERVICE='wxa8386175898e12c9'; public const API='SentenceASR'; }
}
namespace {
 require __DIR__.'/candidate/server/app/Application/Chat/ChatAsrSelfDiagnostic.php';
 use WorkerAdmin\Application\Chat\ChatAsrSelfDiagnostic;

 function ok(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
 function baseContext():array{
   return[
    'app_id'=>'wx1234567890abcdef','app_secret_configured'=>true,'site_host'=>'test.20m.cc',
    'runtime'=>['asr_profile'=>'sentence_asr','asr_api_name'=>'SentenceASR','asr_data_encoding'=>'object'],
    'go_status'=>['versions_match'=>true,'running_sha'=>str_repeat('a',40)],
    'plugin'=>['plugin_state'=>'approved','approved'=>true],
    'asr'=>[
      'intent_state'=>'accepted','job_phase'=>'published','asr_intent_id'=>'35',
      'openapi_probe'=>[
        'status'=>'completed','intent_id'=>'35','tested_at'=>'2026-11-07T04:28:40+00:00',
        'source_mode'=>'site_https_mirror','source_host'=>'test.20m.cc',
        'variants'=>[[
          'name'=>'sentence_asr_object_site_https','success'=>false,'http_status'=>200,
          'errcode'=>'9301003','request_id_present'=>false,'request_data_encoding'=>'object','input_mode'=>'url_encoded'
        ]]
      ]
    ],
   ];
 }
 $d=new ChatAsrSelfDiagnostic();

 $r=$d->build(baseContext());
 ok($r['status']==='provider_binding_high','9301003 should point to provider/binding');
 ok($r['tone']==='danger','provider/binding tone');
 ok(str_contains($r['summary'],'七牛 URL 已不再是主要嫌疑'),'qiniu should be deprioritized');
 $checks=[];foreach($r['checks'] as $row)$checks[$row['key']]=$row;
 ok(($checks['source']['status']??'')==='pass','site source pass');
 ok(($checks['contract']['status']??'')==='pass','contract pass');
 ok(($checks['provider_request_id']['status']??'')==='fail','missing provider request id fail');
 ok(($checks['service_binding']['status']??'')==='fail','binding layer high suspicion');
 ok(str_contains((string)$checks['service_binding']['detail'],'不能直接枚举'),'must disclose inference boundary');
 ok(count($r['actions'])===3,'provider actions');

 $healthy=baseContext();
 $healthy['asr']['openapi_probe']['variants'][0]['success']=true;
 $healthy['asr']['openapi_probe']['variants'][0]['errcode']='0';
 $healthy['asr']['openapi_probe']['variants'][0]['request_id_present']=true;
 $r=$d->build($healthy);
 ok($r['status']==='healthy'&&$r['tone']==='success','healthy classification');

 $quota=baseContext();
 $quota['asr']['openapi_probe']['variants'][0]['errcode']='9301010';
 $r=$d->build($quota);
 ok($r['status']==='quota','quota classification');

 $local=baseContext();
 $local['runtime']['asr_data_encoding']='json_string';
 $r=$d->build($local);
 ok($r['status']==='local_configuration','local contract must take priority');

 $source=baseContext();
 $source['asr']['openapi_probe']['source_mode']='';
 $r=$d->build($source);
 ok($r['status']==='source_unconfirmed','source classification');

 $tmp=tempnam(sys_get_temp_dir(),'asr-plugin-');
 file_put_contents($tmp,json_encode(['schema_version'=>1,'status'=>'completed','tested_at'=>'2026-11-07T04:00:00Z','plugin_appid'=>'wx069ba97219f66d99','plugin_state'=>'approved','approved'=>true],JSON_THROW_ON_ERROR));
 $plugin=$d->readPluginState($tmp);@unlink($tmp);
 ok(($plugin['approved']??false)===true&&($plugin['plugin_state']??'')==='approved','plugin state read');

 $bad=tempnam(sys_get_temp_dir(),'asr-plugin-');
 file_put_contents($bad,json_encode(['schema_version'=>1,'plugin_appid'=>'wrong','plugin_state'=>'approved','approved'=>true],JSON_THROW_ON_ERROR));
 ok($d->readPluginState($bad)===[],'wrong plugin appid rejected');@unlink($bad);

 echo "ASR_SELF_DIAGNOSTIC_OK\n";
}
