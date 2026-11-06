<?php
declare(strict_types=1);
require __DIR__.'/ChatAsrOpenApiProbe.php';

use WorkerAdmin\Application\Chat\ChatAsrOpenApiProbe;

$calls=[];
$probe=new ChatAsrOpenApiProbe(static function(string $token,array $body)use(&$calls):array{
    $calls[]=$body;
    if(count($calls)===1)return ['status'=>200,'body'=>'{"errcode":9301003,"errmsg":"internal exception"}'];
    return ['status'=>200,'body'=>'{"errcode":0,"errmsg":"ok","data":"{\\"Response\\":{\\"Result\\":\\"测试识别\\",\\"RequestId\\":\\"req-1\\"}}"}'];
});
$result=$probe->run(str_repeat('a',32),'https://qn.example.invalid/main.mp3?e=1&token=x:y');
if(count($calls)!==2||$result['recommended']!=='compat_json_string')throw new RuntimeException('probe variants');
foreach($calls as $index=>$body){
    if(($body['service']??'')!==ChatAsrOpenApiProbe::SERVICE||($body['api']??'')!==ChatAsrOpenApiProbe::API)throw new RuntimeException('outer');
    $data=$body['data'];
    if($index===1){if(!is_string($data))throw new RuntimeException('string mode');$data=json_decode($data,true,32,JSON_THROW_ON_ERROR);}
    elseif(!is_array($data))throw new RuntimeException('object mode');
    foreach(['Action','ProjectId','SubServiceType','EngSerViceType','SourceType','VoiceFormat','Url','UsrAudioKey'] as $key)if(!array_key_exists($key,$data))throw new RuntimeException('missing '.$key);
    if($data['ProjectId']!==0||$data['SubServiceType']!==2||$data['Action']!=='SentenceRecognitionWX')throw new RuntimeException('compat');
    if(strlen((string)$data['UsrAudioKey'])!==32||($data['UsrAudioKey']??'')!==($body['client_msg_id']??''))throw new RuntimeException('key');
}
if(($calls[0]['client_msg_id']??'')===($calls[1]['client_msg_id']??''))throw new RuntimeException('unique key');
if(($result['variants'][0]['errcode']??'')!=='9301003'||empty($result['variants'][1]['success']))throw new RuntimeException('summary');
echo "ASR_OPENAPI_PROBE_CONTRACT_PASSED\n";
