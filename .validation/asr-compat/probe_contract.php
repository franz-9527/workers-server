<?php
declare(strict_types=1);
require __DIR__.'/ChatAsrOpenApiProbe.php';

use WorkerAdmin\Application\Chat\ChatAsrOpenApiProbe;

$source='https://qn.example.invalid/main.mp3?e=1&token=x:y';
$audio="ID3".str_repeat("\0",100);
$calls=[];
$probe=new ChatAsrOpenApiProbe(static function(string $token,array $body)use(&$calls):array{
    $calls[]=$body;
    if(count($calls)<4)return ['status'=>200,'body'=>'{"errcode":9301002,"errmsg":"call api service failed"}'];
    return ['status'=>200,'body'=>'{"errcode":0,"errmsg":"ok","data":"{\\"Response\\":{\\"Result\\":\\"测试识别\\",\\"RequestId\\":\\"req-1\\"}}"}'];
});
$result=$probe->run(str_repeat('a',32),$source,$audio);
if(count($calls)!==4||$result['recommended']!=='service_16k_data_key8')throw new RuntimeException('probe variants');

foreach($calls as $body){
    if(($body['service']??'')!==ChatAsrOpenApiProbe::SERVICE||($body['api']??'')!==ChatAsrOpenApiProbe::API)throw new RuntimeException('outer');
    if(!is_array($body['data']??null))throw new RuntimeException('object mode');
    if(strlen((string)($body['client_msg_id']??''))!==32)throw new RuntimeException('client msg id');
}
if(count(array_unique(array_column($calls,'client_msg_id')))!==4)throw new RuntimeException('unique client ids');

$raw32=$calls[0]['data'];
$encoded32=$calls[1]['data'];
$raw8=$calls[2]['data'];
$data8=$calls[3]['data'];

foreach([$raw32,$encoded32,$raw8,$data8] as $row){
    foreach(['Action','EngSerViceType','SourceType','VoiceFormat','UsrAudioKey'] as $key)if(!array_key_exists($key,$row))throw new RuntimeException('missing '.$key);
    if($row['Action']!=='SentenceRecognitionWX'||$row['EngSerViceType']!=='16k'||$row['VoiceFormat']!=='mp3')throw new RuntimeException('service contract');
    if(isset($row['Version'])||isset($row['ProjectId'])||isset($row['SubServiceType']))throw new RuntimeException('unexpected direct-api fields');
}
if($raw32['SourceType']!==0||$raw32['Url']!==$source||strlen((string)$raw32['UsrAudioKey'])!==32)throw new RuntimeException('raw32');
if($encoded32['SourceType']!==0||$encoded32['Url']!==urlencode($source)||strlen((string)$encoded32['UsrAudioKey'])!==32)throw new RuntimeException('encoded32');
if($raw8['SourceType']!==0||$raw8['Url']!==$source||strlen((string)$raw8['UsrAudioKey'])!==8)throw new RuntimeException('raw8');
if($data8['SourceType']!==1||strlen((string)$data8['UsrAudioKey'])!==8||base64_decode((string)($data8['Data']??''),true)!==$audio||($data8['DataLen']??0)!==strlen($audio)||isset($data8['Url']))throw new RuntimeException('data8');

$names=array_column($result['variants'],'name');
if($names!==['service_16k_raw_url_key32','service_16k_encoded_url_key32','service_16k_raw_url_key8','service_16k_data_key8'])throw new RuntimeException('variant order');
if(($result['variants'][0]['errcode']??'')!=='9301002'||($result['variants'][1]['errcode']??'')!=='9301002'||($result['variants'][2]['errcode']??'')!=='9301002'||empty($result['variants'][3]['success']))throw new RuntimeException('summary');
echo "ASR_OPENAPI_PROBE_CONTRACT_PASSED\n";

$failCalls=0;
$staged=new ChatAsrOpenApiProbe(static function(string $token,array $body)use(&$failCalls):array{
    $failCalls++;
    if($failCalls===1)return ['status'=>200,'body'=>'{"errcode":9301002,"errmsg":"call api service failed"}'];
    throw new RuntimeException('fixture transport failure');
});
$stagedResult=$staged->run(str_repeat('a',32),$source,$audio);
if(($stagedResult['status']??'')!=='failed'||($stagedResult['code']??'')!=='CHAT_ASR_PROBE_INTERNAL_REQUEST_SERVICE_16K_ENCODED_URL_KEY32')throw new RuntimeException('internal stage code');
if(count($stagedResult['variants']??[])!==1||($stagedResult['variants'][0]['name']??'')!=='service_16k_raw_url_key32')throw new RuntimeException('partial variants');
echo "ASR_OPENAPI_PROBE_STAGE_CONTRACT_PASSED\n";

$httpCalls=0;
$httpProbe=new ChatAsrOpenApiProbe(static function(string $token,array $body)use(&$httpCalls):array{
    $httpCalls++;return ['status'=>200,'body'=>'{"errcode":9301002,"errmsg":"call api service failed"}'];
});
$httpResult=$httpProbe->run(str_repeat('a',32),'http://qn.example.invalid/main.mp3?e=1&token=x:y');
if(($httpResult['status']??'')!=='completed'||$httpCalls!==3)throw new RuntimeException('http source compatibility');
echo "ASR_OPENAPI_PROBE_HTTP_SOURCE_PASSED\n";
