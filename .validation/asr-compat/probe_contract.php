<?php
declare(strict_types=1);
require __DIR__.'/ChatAsrOpenApiProbe.php';

use WorkerAdmin\Application\Chat\ChatAsrOpenApiProbe;

$source='https://qn.example.invalid/main.mp3?e=1&token=x:y';
$calls=[];
$probe=new ChatAsrOpenApiProbe(static function(string $token,array $body)use(&$calls):array{
    $calls[]=$body;
    $api=(string)($body['api']??'');
    if($api==='SentenceRecognitionWX')return ['status'=>200,'body'=>'{"errcode":0,"errmsg":"ok","data":"{\\"Response\\":{\\"Result\\":\\"测试识别\\",\\"RequestId\\":\\"req-1\\"}}"}'];
    if($api==='SentenceASR')return ['status'=>200,'body'=>'{"errcode":9301002,"errmsg":"call api service failed"}'];
    return ['status'=>200,'body'=>'{"errcode":9301007,"errmsg":"invalid api config"}'];
});
$result=$probe->run(str_repeat('a',32),$source);
if(count($calls)!==4||$result['recommended']!=='api_sentence_recognition_wx')throw new RuntimeException('probe variants');

$expectedApis=['SentenceASR','SentenceRecognition','SentenceRecognitionWX','sentenceRecognition'];
foreach($calls as $index=>$body){
    if(($body['service']??'')!==ChatAsrOpenApiProbe::SERVICE||($body['api']??'')!==$expectedApis[$index])throw new RuntimeException('outer api');
    if(!is_array($body['data']??null))throw new RuntimeException('object mode');
    if(strlen((string)($body['client_msg_id']??''))!==32)throw new RuntimeException('client msg id');
    $data=$body['data'];
    foreach(['Action','EngSerViceType','SourceType','VoiceFormat','Url','UsrAudioKey'] as $key)if(!array_key_exists($key,$data))throw new RuntimeException('missing '.$key);
    if($data['Action']!=='SentenceRecognitionWX'||$data['EngSerViceType']!=='16k_zh'||$data['SourceType']!==0||$data['VoiceFormat']!=='mp3'||$data['Url']!==$source||strlen((string)$data['UsrAudioKey'])!==8)throw new RuntimeException('fixed service payload');
    if(isset($data['Version'])||isset($data['ProjectId'])||isset($data['SubServiceType'])||isset($data['Data'])||isset($data['DataLen']))throw new RuntimeException('payload drift');
}
if(count(array_unique(array_column($calls,'client_msg_id')))!==4)throw new RuntimeException('unique client ids');
$names=array_column($result['variants'],'name');
if($names!==['api_sentence_asr','api_sentence_recognition','api_sentence_recognition_wx','api_sentence_recognition_lower'])throw new RuntimeException('variant order');
if(($result['variants'][0]['errcode']??'')!=='9301002'||($result['variants'][1]['errcode']??'')!=='9301007'||empty($result['variants'][2]['success'])||($result['variants'][3]['errcode']??'')!=='9301007')throw new RuntimeException('summary');
echo "ASR_OPENAPI_PROBE_CONTRACT_PASSED\n";

$failCalls=0;
$staged=new ChatAsrOpenApiProbe(static function(string $token,array $body)use(&$failCalls):array{
    $failCalls++;
    if($failCalls===1)return ['status'=>200,'body'=>'{"errcode":9301002,"errmsg":"call api service failed"}'];
    throw new RuntimeException('fixture transport failure');
});
$stagedResult=$staged->run(str_repeat('a',32),$source);
if(($stagedResult['status']??'')!=='failed'||($stagedResult['code']??'')!=='CHAT_ASR_PROBE_INTERNAL_REQUEST_API_SENTENCE_RECOGNITION')throw new RuntimeException('internal stage code');
if(count($stagedResult['variants']??[])!==1||($stagedResult['variants'][0]['name']??'')!=='api_sentence_asr')throw new RuntimeException('partial variants');
echo "ASR_OPENAPI_PROBE_STAGE_CONTRACT_PASSED\n";

$httpCalls=0;
$httpProbe=new ChatAsrOpenApiProbe(static function(string $token,array $body)use(&$httpCalls):array{
    $httpCalls++;return ['status'=>200,'body'=>'{"errcode":9301002,"errmsg":"call api service failed"}'];
});
$httpResult=$httpProbe->run(str_repeat('a',32),'http://qn.example.invalid/main.mp3?e=1&token=x:y');
if(($httpResult['status']??'')!=='completed'||$httpCalls!==4)throw new RuntimeException('http source compatibility');
echo "ASR_OPENAPI_PROBE_HTTP_SOURCE_PASSED\n";
