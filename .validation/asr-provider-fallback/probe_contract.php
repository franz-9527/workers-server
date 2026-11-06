<?php
declare(strict_types=1);

require __DIR__.'/ChatAsrOpenApiProbe.php';

use WorkerAdmin\Application\Chat\ChatAsrOpenApiProbe;

$calls=0;
$source='https://qn.worker.20m.cc/test/chat/av/test/31/'.str_repeat('a',64).'/'.str_repeat('b',64).'/main.mp3?e=1890000000&token=abc:def';
$probe=new ChatAsrOpenApiProbe(static function(string $token,array $body)use(&$calls,$source):array{
    $calls++;
    if($token!=='test_token_1234567890')throw new RuntimeException('token');
    if(($body['service']??'')!==ChatAsrOpenApiProbe::SERVICE||($body['api']??'')!==ChatAsrOpenApiProbe::API)throw new RuntimeException('service/api');
    if(!is_string($body['data']??null))throw new RuntimeException('data not string');
    $data=json_decode($body['data'],true,16,JSON_THROW_ON_ERROR);
    $keys=array_keys($data);sort($keys);
    $expected=['Action','EngSerViceType','SourceType','Url','UsrAudioKey','VoiceFormat'];sort($expected);
    if($keys!==$expected)throw new RuntimeException('six-field contract');
    if(($data['Action']??'')!=='SentenceRecognitionWX'||($data['EngSerViceType']??'')!=='16k_zh'||($data['SourceType']??null)!==0||($data['VoiceFormat']??'')!=='mp3')throw new RuntimeException('contract values');
    if(($data['Url']??'')!==urlencode($source))throw new RuntimeException('url not encoded');
    if(strlen((string)($data['UsrAudioKey']??''))!==32)throw new RuntimeException('key length');
    return ['status'=>200,'body'=>json_encode(['errcode'=>9301002,'errmsg'=>'call api service failed'],JSON_THROW_ON_ERROR)];
});
$result=$probe->run('test_token_1234567890',$source);
if($calls!==1)throw new RuntimeException('must make exactly one controlled call');
if(($result['status']??'')!=='completed'||($result['revision']??'')!=='sentence-asr-data-shape-v7')throw new RuntimeException('revision');
$v=$result['variants'][0]??[];
if(($v['name']??'')!=='sentence_asr_json_string_url_encoded'||($v['errcode']??'')!=='9301002'||($v['request_data_encoding']??'')!=='json_string'||($v['input_mode']??'')!=='url_encoded'||($v['request_key_length']??0)!==32)throw new RuntimeException('summary');
echo "ASR_DATA_SHAPE_V7_CONTRACT_PASSED\n";
