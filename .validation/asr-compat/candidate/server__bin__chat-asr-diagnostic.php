<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('WORKER_BASE_PATH',dirname(__DIR__));
require WORKER_BASE_PATH.'/bootstrap.php';

use WorkerAdmin\Application\Chat\ChatAsrOpenApiProbe;
use WorkerAdmin\Infrastructure\External\WeChatAccessTokenProvider;

$root=dirname(WORKER_BASE_PATH);$stateDir=$root.'/chat/bin/state';$stateFile=$stateDir.'/asr-openapi-diagnostic.json';
$write=static function(array $data)use($stateDir,$stateFile):void{
    if(!is_dir($stateDir)||is_link($stateDir))return;
    $raw=json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
    $tmp=$stateFile.'.'.bin2hex(random_bytes(6)).'.tmp';
    if(file_put_contents($tmp,$raw,LOCK_EX)!==strlen($raw)){@unlink($tmp);return;}
    @chmod($tmp,0640);if(!@rename($tmp,$stateFile)){@unlink($tmp);return;}@chmod($stateFile,0640);
};
$public=static function(array $data):array{
    $out=['status'=>(string)($data['status']??''),'revision'=>(string)($data['revision']??''),'tested_at'=>(string)($data['tested_at']??''),'recommended'=>(string)($data['recommended']??'none'),'variants'=>[]];
    if(isset($data['intent_id']))$out['intent_id']=(string)$data['intent_id'];
    foreach(is_array($data['variants']??null)?$data['variants']:[] as $row)if(is_array($row))$out['variants'][]=['name'=>(string)($row['name']??''),'success'=>($row['success']??false)===true,'http_status'=>(int)($row['http_status']??0),'errcode'=>(string)($row['errcode']??''),'inner_code'=>(string)($row['inner_code']??'')];
    return$out;
};
$skip=static function(string $code,?string $intentId=null)use($write,$public):void{
    $data=['schema_version'=>1,'status'=>'skipped','revision'=>ChatAsrOpenApiProbe::REVISION,'tested_at'=>gmdate('c'),'recommended'=>'none','variants'=>[],'code'=>$code];
    if($intentId!==null&&preg_match('/^[1-9][0-9]{0,19}$/D',$intentId)===1)$data['intent_id']=$intentId;
    $write($data);fwrite(STDOUT,'CHAT_ASR_OPENAPI_PROBE_SKIPPED '.json_encode($public($data),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");exit(0);
};
$base64Url=static fn(string $value):string=>strtr(base64_encode($value),'+/','-_');
$providerUrl=static function(string $key)use($base64Url):string{
    $private=filter_var(setting('qiniu.private_bucket',false),FILTER_VALIDATE_BOOLEAN);
    $raw=trim((string)setting('qiniu.domain',''));if($raw==='')throw new RuntimeException('qiniu domain');
    if(!preg_match('#^https?://#i',$raw))$raw=($private?'https':'http').'://'.$raw;
    $parsed=parse_url($raw);if(!$parsed||empty($parsed['host'])||isset($parsed['user'])||isset($parsed['pass'])||isset($parsed['query'])||isset($parsed['fragment']))throw new RuntimeException('qiniu origin');
    $path=(string)($parsed['path']??'');if($path!==''&&$path!=='/')throw new RuntimeException('qiniu path');
    $scheme=$private?'https':(string)($parsed['scheme']??'');
    if(!in_array($scheme,['http','https'],true))throw new RuntimeException('qiniu scheme');
    $port=isset($parsed['port'])?':'.(int)$parsed['port']:'';
    $url=$scheme.'://'.$parsed['host'].$port.'/'.str_replace('%2F','/',rawurlencode(ltrim($key,'/')));
    if(!$private)return$url;
    $ak=trim((string)setting('qiniu.access_key',''));$sk=trim((string)setting('qiniu.secret_key',''));
    if($ak===''||$sk==='')throw new RuntimeException('qiniu credential');
    $signed=$url.'?e='.(time()+600);
    return$signed.'&token='.$ak.':'.$base64Url(hash_hmac('sha1',$signed,$sk,true));
};
$download=static function(string $url):string{
    if(!function_exists('curl_init'))return'';$body='';$limit=3*1024*1024;
    $curl=curl_init($url);if($curl===false)return'';
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body,$limit):int{if(strlen($body)+strlen($chunk)>$limit)return 0;$body.=$chunk;return strlen($chunk);}]);
    $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
    return$ok!==false&&in_array($status,[200,206],true)&&$body!==''?$body:'';
};

$stage='preflight';
try{
    if((string)app_config('app.env','production')!=='testing')exit(0);
    if(!is_installed())$skip('CHAT_ASR_PROBE_SITE_NOT_INSTALLED');
    if(upgrade_required())$skip('CHAT_ASR_PROBE_UPGRADE_REQUIRED');
    $row=db()->fetch("SELECT CAST(r.intent_id AS CHAR) intent_id,CAST(j.upload_id AS CHAR) upload_id,r.ordinal,r.metadata_json,r.created_at,j.segments_json FROM chat_av_asr_requests r JOIN chat_av_jobs j ON j.intent_id=r.intent_id WHERE r.state='failed' AND r.created_at>=UTC_TIMESTAMP()-INTERVAL 2 DAY ORDER BY r.created_at DESC LIMIT 1");
    if(!$row)$skip('CHAT_ASR_PROBE_NO_FAILED_REQUEST');
    $intentId=(string)$row['intent_id'];
    $meta=json_decode((string)($row['metadata_json']??''),true,32);
    if(!is_array($meta)||(string)($meta['provider_code']??'')!=='9301003'||(string)($meta['service']??'')!==ChatAsrOpenApiProbe::SERVICE||(string)($meta['api']??'')!==ChatAsrOpenApiProbe::API)$skip('CHAT_ASR_PROBE_LATEST_NOT_9301003',$intentId);
    $segments=json_decode((string)($row['segments_json']??''),true,32);$ordinal=(int)$row['ordinal'];
    if(!is_array($segments)||!isset($segments[$ordinal])||!is_array($segments[$ordinal]))$skip('CHAT_ASR_PROBE_SEGMENT_METADATA_MISSING',$intentId);
    $key=(string)($segments[$ordinal]['key']??'');
    if(preg_match('#^(?:chatav|(?:[A-Za-z0-9_-]{1,40}/){1,4}av)/test/[1-9][0-9]{0,19}/[0-9a-f]{64}/[0-9a-f]{64}/(?:main|check-[01])\.mp3$#D',$key)!==1)$skip('CHAT_ASR_PROBE_SEGMENT_KEY_UNSUPPORTED',$intentId);
    $bound=(int)db()->fetchValue("SELECT COUNT(*) FROM chat_av_objects WHERE object_key=:key AND upload_id=:upload AND role IN ('main','check-0','check-1')",['key'=>$key,'upload'=>(string)$row['upload_id']]);
    if($bound!==1)$skip('CHAT_ASR_PROBE_SEGMENT_OBJECT_UNBOUND',$intentId);
    $fingerprint=hash('sha256',ChatAsrOpenApiProbe::REVISION."\n".(string)$row['intent_id']."\n".$ordinal."\n".$key);
    if(!is_link($stateFile)&&is_file($stateFile)&&($existingRaw=@file_get_contents($stateFile))!==false){
        $existing=json_decode($existingRaw,true,32);
        if(is_array($existing)&&hash_equals((string)($existing['fingerprint']??''),$fingerprint)){fwrite(STDOUT,'CHAT_ASR_OPENAPI_PROBE_CACHED '.json_encode($public($existing),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");exit(0);}
    }
    $stage='source_url';$url=$providerUrl($key);
    $stage='download';$audio=$download($url);
    $stage='token';$token=WeChatAccessTokenProvider::fromSettings()->get();
    $stage='provider';$result=(new ChatAsrOpenApiProbe())->run($token,$url,$audio);
    $stage='write';
    $result['schema_version']=1;$result['intent_id']=(string)$row['intent_id'];$result['fingerprint']=$fingerprint;
    $parsed=parse_url($url);$result['source_host']=(string)($parsed['host']??'');$result['source_bytes']=strlen($audio);
    $write($result);fwrite(STDOUT,'CHAT_ASR_OPENAPI_PROBE '.json_encode($public($result),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
}catch(Throwable $error){
    $codes=['preflight'=>'CHAT_ASR_PROBE_FAILED_PREFLIGHT','source_url'=>'CHAT_ASR_PROBE_FAILED_SOURCE_URL','download'=>'CHAT_ASR_PROBE_FAILED_DOWNLOAD','token'=>'CHAT_ASR_PROBE_FAILED_TOKEN','provider'=>'CHAT_ASR_PROBE_FAILED_PROVIDER','write'=>'CHAT_ASR_PROBE_FAILED_WRITE'];
    $code=$codes[$stage]??'CHAT_ASR_OPENAPI_PROBE_FAILED';
    $failure=['schema_version'=>1,'status'=>'failed','revision'=>ChatAsrOpenApiProbe::REVISION,'tested_at'=>gmdate('c'),'recommended'=>'none','variants'=>[],'code'=>$code];
    $write($failure);fwrite(STDERR,$code."\n");
}
