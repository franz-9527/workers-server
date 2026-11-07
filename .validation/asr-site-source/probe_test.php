<?php
declare(strict_types=1);
require __DIR__.'/candidate/server/app/Application/Chat/ChatAsrOpenApiProbe.php';
use WorkerAdmin\Application\Chat\ChatAsrOpenApiProbe;
$seen=null;
$transport=static function(string $token,array $body)use(&$seen):array{
    $seen=$body;
    return ['status'=>200,'body'=>json_encode([
        'errcode'=>0,'errmsg'=>'ok',
        'data'=>['Response'=>['Result'=>'测试识别成功','RequestId'=>'req_1234567890']]
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];
};
$p=new ChatAsrOpenApiProbe($transport);
$url='https://test.example.com/uploads/chat-asr/'.str_repeat('a',64).'.mp3';
$r=$p->run(str_repeat('A',32),$url,'ID3fixture');
if(($r['status']??'')!=='completed'||empty($r['variants'][0]['success']))throw new RuntimeException('probe not successful');
if(!is_array($seen)||!is_array($seen['data']??null))throw new RuntimeException('data not object');
if(($seen['service']??'')!==ChatAsrOpenApiProbe::SERVICE||($seen['api']??'')!==ChatAsrOpenApiProbe::API)throw new RuntimeException('service mismatch');
$d=$seen['data'];
if(($d['Action']??'')!=='SentenceRecognitionWX'||($d['SourceType']??null)!==0||($d['VoiceFormat']??'')!=='mp3'||($d['EngSerViceType']??'')!=='16k_zh')throw new RuntimeException('payload mismatch');
if(urldecode((string)($d['Url']??''))!==$url)throw new RuntimeException('url not encoded exactly once');
if(!preg_match('/^[a-f0-9]{32}$/D',(string)($d['UsrAudioKey']??'')))throw new RuntimeException('audio key');
if(!preg_match('/^[a-f0-9]{32}$/D',(string)($seen['client_msg_id']??'')))throw new RuntimeException('client key');
if(($r['variants'][0]['request_data_encoding']??'')!=='object')throw new RuntimeException('encoding');
echo "ASR_OBJECT_PROBE_OK\n";
