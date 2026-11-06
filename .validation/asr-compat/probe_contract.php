<?php
declare(strict_types=1);
require __DIR__.'/ChatAsrOpenApiProbe.php';

use WorkerAdmin\Application\Chat\ChatAsrOpenApiProbe;

$calls=[];
$probe=new ChatAsrOpenApiProbe(static function(string $token,array $body)use(&$calls):array{
    $calls[]=$body;
    if(count($calls)<3)return ['status'=>200,'body'=>'{"errcode":9301003,"errmsg":"internal exception"}'];
    return ['status'=>200,'body'=>'{"errcode":0,"errmsg":"ok","data":"{\\"Response\\":{\\"Result\\":\\"测试识别\\",\\"RequestId\\":\\"req-1\\"}}"}'];
});
$audio="ID3".str_repeat("\0",100);
$result=$probe->run(str_repeat('a',32),'https://qn.example.invalid/main.mp3?e=1&token=x:y',$audio);
if(count($calls)!==3||$result['recommended']!=='compat_data_object')throw new RuntimeException('probe variants');
foreach($calls as $index=>$body){
    if(($body['service']??'')!==ChatAsrOpenApiProbe::SERVICE||($body['api']??'')!==ChatAsrOpenApiProbe::API)throw new RuntimeException('outer');
    $data=$body['data'];
    if($index===1){if(!is_string($data))throw new RuntimeException('string mode');$data=json_decode($data,true,32,JSON_THROW_ON_ERROR);}
    elseif(!is_array($data))throw new RuntimeException('object mode');
    foreach(['Action','ProjectId','SubServiceType','EngSerViceType','SourceType','VoiceFormat','UsrAudioKey'] as $key)if(!array_key_exists($key,$data))throw new RuntimeException('missing '.$key);
    if($data['ProjectId']!==0||$data['SubServiceType']!==2||$data['Action']!=='SentenceRecognitionWX')throw new RuntimeException('compat');
    if(strlen((string)$data['UsrAudioKey'])!==32||($data['UsrAudioKey']??'')!==($body['client_msg_id']??''))throw new RuntimeException('key');
    if($index<2){
        if($data['SourceType']!==0||!isset($data['Url'])||isset($data['Data'])||isset($data['DataLen']))throw new RuntimeException('url mode');
    }else{
        if($data['SourceType']!==1||isset($data['Url'])||base64_decode((string)($data['Data']??''),true)!==$audio||($data['DataLen']??0)!==strlen($audio))throw new RuntimeException('data mode');
    }
}
if(count(array_unique(array_column($calls,'client_msg_id')))!==3)throw new RuntimeException('unique key');
if(($result['variants'][0]['errcode']??'')!=='9301003'||($result['variants'][1]['errcode']??'')!=='9301003'||empty($result['variants'][2]['success']))throw new RuntimeException('summary');
echo "ASR_OPENAPI_PROBE_CONTRACT_PASSED\n";
