<?php

declare(strict_types=1);

// Credential maintenance exports no token to stdout, argv, URLs or logs.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$reject=null;$check=false;
foreach(array_slice($argv,1) as $argument){
    if($argument==='--help'){fwrite(STDOUT,"用法：php bin/chat-credentials.php [--check] [--reject-generation=64位hex]\n每分钟执行一次；自动处理已配置拒绝目录的同代次提示，再导出当前AppID/环境的短期凭证。拒绝代次参数只在可信维护时使用，不传token。\n");exit;}
    if($argument==='--check'){$check=true;continue;}
    if(preg_match('/^--reject-generation=([a-f0-9]{64})$/D',$argument,$match)===1&&$reject===null){$reject=$match[1];continue;}
    fwrite(STDERR,"参数无效\n");exit(2);
}
if($check&&$reject!==null){fwrite(STDERR,"检查不能撤销凭证\n");exit(2);}
define('WORKER_BASE_PATH',dirname(__DIR__));
if(is_file(WORKER_BASE_PATH.'/storage/maintenance.lock')){fwrite(STDERR,"维护中\n");exit(3);}
require WORKER_BASE_PATH.'/bootstrap.php';
try{
    if(!is_installed()||upgrade_required()||app_config('chat.safety_credential_export_enabled',false)!==true)throw new RuntimeException('not ready');
    $provider=\WorkerAdmin\Infrastructure\External\WeChatAccessTokenProvider::fromSettings();
    if(!$check){if($reject!==null)$provider->rejectGeneration($reject);$provider->get();}
    fwrite(STDOUT,json_encode($provider->metadata(),JSON_THROW_ON_ERROR)."\n");
}catch(Throwable $error){fwrite(STDERR,"聊天可信凭证暂不可用；未输出凭证或底层诊断\n");exit(1);}

