<?php
declare(strict_types=1);
require __DIR__.'/ChatAsrOpenApiProbe.php';

use WorkerAdmin\Application\Chat\ChatAsrOpenApiProbe;

$source='https://qn.example.invalid/main.mp3?e=1&token=x:y';
$audio="ID3".str_repeat("\0",100);
$calls=[];
$probe=new ChatAsrOpenApiProbe(static function(string $token,array $body)use(&$calls):array{
    $calls[]=$body;
    if(count($calls)<3)return ['status'=>200,'body'=>'{"errcode":9301003,"errmsg":"internal exception"}'];
    return ['status'=>200,'body'=>'{"errcode":0,"errmsg":"ok","data":"{\\"Response\\":{\\"Result\\":\\"测试识别\\",\\"RequestId\\":\\"req-1\\"}}"}'];
});
$result=$probe->run(str_repeat('a',32),$source,$audio);
if(count($calls)!==3||$result['recommended']!=='tencent_current_data_object')throw new RuntimeException('probe variants');

foreach($calls as $body){
    if(($body['service']??'')!==ChatAsrOpenApiProbe::SERVICE||($body['api']??'')!==ChatAsrOpenApiProbe::API)throw new RuntimeException('outer');
    if(!is_array($body['data']??null))throw new RuntimeException('object mode');
    if(strlen((string)($body['client_msg_id']??''))!==32)throw new RuntimeException('client key');
}
if(count(array_unique(array_column($calls,'client_msg_id')))!==3)throw new RuntimeException('unique key');

$service=$calls[0]['data'];
foreach(['Action','EngSerViceType','SourceType','VoiceFormat','Url','UsrAudioKey'] as $key)if(!array_key_exists($key,$service))throw new RuntimeException('service missing '.$key);
if($service['Action']!=='SentenceRecognitionWX'||$service['SourceType']!==0||$service['VoiceFormat']!=='mp3'||$service['Url']!==$source)throw new RuntimeException('service contract');
if(($service['UsrAudioKey']??'')!==($calls[0]['client_msg_id']??''))throw new RuntimeException('service key');
if(isset($service['Version'])||isset($service['ProjectId'])||isset($service['SubServiceType'])||isset($service['Data'])||isset($service['DataLen']))throw new RuntimeException('service extras');

$currentUrl=$calls[1]['data'];
foreach(['Action','Version','ProjectId','SubServiceType','EngSerViceType','SourceType','VoiceFormat','Url'] as $key)if(!array_key_exists($key,$currentUrl))throw new RuntimeException('current url missing '.$key);
if($currentUrl['Action']!=='SentenceRecognition'||$currentUrl['Version']!=='2019-06-14'||$currentUrl['ProjectId']!==0||$currentUrl['SubServiceType']!==2||$currentUrl['SourceType']!==0||$currentUrl['VoiceFormat']!=='mp3'||$currentUrl['Url']!==$source)throw new RuntimeException('current url contract');
if(isset($currentUrl['UsrAudioKey'])||isset($currentUrl['Data'])||isset($currentUrl['DataLen']))throw new RuntimeException('current url extras');

$currentData=$calls[2]['data'];
foreach(['Action','Version','ProjectId','SubServiceType','EngSerViceType','SourceType','VoiceFormat','Data','DataLen'] as $key)if(!array_key_exists($key,$currentData))throw new RuntimeException('current data missing '.$key);
if($currentData['Action']!=='SentenceRecognition'||$currentData['Version']!=='2019-06-14'||$currentData['ProjectId']!==0||$currentData['SubServiceType']!==2||$currentData['SourceType']!==1||$currentData['VoiceFormat']!=='mp3')throw new RuntimeException('current data contract');
if(base64_decode((string)$currentData['Data'],true)!==$audio||$currentData['DataLen']!==strlen($audio))throw new RuntimeException('current data bytes');
if(isset($currentData['UsrAudioKey'])||isset($currentData['Url']))throw new RuntimeException('current data extras');

if(($result['variants'][0]['name']??'')!=='service_raw_url_object'||($result['variants'][0]['errcode']??'')!=='9301003')throw new RuntimeException('service summary');
if(($result['variants'][1]['name']??'')!=='tencent_current_url_object'||($result['variants'][1]['errcode']??'')!=='9301003')throw new RuntimeException('current url summary');
if(($result['variants'][2]['name']??'')!=='tencent_current_data_object'||empty($result['variants'][2]['success']))throw new RuntimeException('current data summary');
echo "ASR_OPENAPI_PROBE_CONTRACT_PASSED\n";

$failCalls=0;
$staged=new ChatAsrOpenApiProbe(static function(string $token,array $body)use(&$failCalls):array{
    $failCalls++;
    if($failCalls===1)return ['status'=>200,'body'=>'{"errcode":9301003,"errmsg":"internal exception"}'];
    throw new RuntimeException('fixture transport failure');
});
$stagedResult=$staged->run(str_repeat('a',32),$source,"ID3".str_repeat("\0",20));
if(($stagedResult['status']??'')!=='failed'||($stagedResult['code']??'')!=='CHAT_ASR_PROBE_INTERNAL_REQUEST_TENCENT_CURRENT_URL_OBJECT')throw new RuntimeException('internal stage code');
if(count($stagedResult['variants']??[])!==1||($stagedResult['variants'][0]['name']??'')!=='service_raw_url_object'||($stagedResult['variants'][0]['errcode']??'')!=='9301003')throw new RuntimeException('partial variants');
echo "ASR_OPENAPI_PROBE_STAGE_CONTRACT_PASSED\n";

$httpCalls=0;
$httpProbe=new ChatAsrOpenApiProbe(static function(string $token,array $body)use(&$httpCalls):array{
    $httpCalls++;return ['status'=>200,'body'=>'{"errcode":9301003,"errmsg":"internal exception"}'];
});
$httpResult=$httpProbe->run(str_repeat('a',32),'http://qn.example.invalid/main.mp3?e=1&token=x:y');
if(($httpResult['status']??'')!=='completed'||$httpCalls!==2)throw new RuntimeException('http source compatibility');
echo "ASR_OPENAPI_PROBE_HTTP_SOURCE_PASSED\n";
