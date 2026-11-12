<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('WORKER_BASE_PATH',dirname(__DIR__));
require WORKER_BASE_PATH.'/bootstrap.php';

use WorkerAdmin\Application\Chat\ChatWechatSIPluginProbe;
use WorkerAdmin\Application\Chat\ChatRuntimePaths;
use WorkerAdmin\Infrastructure\External\WeChatAccessTokenProvider;

if ((string)app_config('app.env','production')!=='testing') exit(0);
$stateDir=ChatRuntimePaths::stateDirectory();
if ($stateDir===null) {
    fwrite(STDERR,"CHAT_WECHATSI_PLUGIN_LAYOUT_UNAVAILABLE\n");
    exit(2);
}
$stateFile=$stateDir.'/wechat-si-plugin-diagnostic.json';
$write=static function(array $data)use($stateDir,$stateFile):void{
    if(!is_dir($stateDir)||is_link($stateDir))return;
    $raw=json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
    $tmp=$stateFile.'.'.bin2hex(random_bytes(6)).'.tmp';
    if(file_put_contents($tmp,$raw,LOCK_EX)!==strlen($raw)){@unlink($tmp);return;}
    @chmod($tmp,0640);if(!@rename($tmp,$stateFile)){@unlink($tmp);return;}@chmod($stateFile,0640);
};
$public=static function(array $data):array{
    $out=[
        'status'=>(string)($data['status']??'failed'),
        'tested_at'=>(string)($data['tested_at']??''),
        'plugin_appid'=>ChatWechatSIPluginProbe::APP_ID,
        'plugin_state'=>(string)($data['plugin_state']??'unknown'),
        'approved'=>($data['approved']??false)===true,
    ];
    if(isset($data['code'])&&preg_match('/^[A-Z0-9_]{1,80}$/D',(string)$data['code'])===1)$out['code']=(string)$data['code'];
    if(isset($data['errcode'])&&preg_match('/^-?[0-9]{1,12}$/D',(string)$data['errcode'])===1)$out['errcode']=(string)$data['errcode'];
    return $out;
};

try{
    if(!is_installed()||upgrade_required()){
        $result=['schema_version'=>1,'status'=>'failed','tested_at'=>gmdate('c'),'plugin_appid'=>ChatWechatSIPluginProbe::APP_ID,'plugin_state'=>'unknown','approved'=>false,'code'=>'CHAT_WECHATSI_PLUGIN_SITE_NOT_READY'];
    }else{
        $token=WeChatAccessTokenProvider::fromSettings()->get();
        $result=(new ChatWechatSIPluginProbe())->run($token);
    }
    $write($result);fwrite(STDOUT,'CHAT_WECHATSI_PLUGIN_DIAGNOSTIC '.json_encode($public($result),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
}catch(Throwable $error){
    $failure=['schema_version'=>1,'status'=>'failed','tested_at'=>gmdate('c'),'plugin_appid'=>ChatWechatSIPluginProbe::APP_ID,'plugin_state'=>'unknown','approved'=>false,'code'=>'CHAT_WECHATSI_PLUGIN_DIAGNOSTIC_FAILED'];
    $write($failure);fwrite(STDERR,"CHAT_WECHATSI_PLUGIN_DIAGNOSTIC_FAILED\n");
}
