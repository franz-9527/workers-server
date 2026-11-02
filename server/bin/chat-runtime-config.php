<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('WORKER_BASE_PATH',dirname(__DIR__));
require WORKER_BASE_PATH.'/bootstrap.php';

use WorkerAdmin\Application\Chat\ChatGoRuntimeConfig;
use WorkerAdmin\Application\Chat\ChatSigningKeyManager;
use WorkerAdmin\Foundation\ApplicationContainer;

try{
    if(!is_installed()||upgrade_required())throw new RuntimeException('not ready');
    $root=dirname(WORKER_BASE_PATH);
    $state=$root.'/chat/bin/state';
    if(!is_dir($state)&&!mkdir($state,0750,true)&&!is_dir($state))throw new RuntimeException('state unavailable');

    $shared=new ApplicationContainer(db());
    $cipher=$shared->privateCipher();
    $signing=(new ChatSigningKeyManager(db(),$cipher))->status((array)app_config('chat',[]));
    $runtime=(new ChatGoRuntimeConfig(db(),$cipher))->render($signing);

    $configPath=$state.'/chat.runtime.json';
    $envPath=$state.'/runtime.env';
    $config=json_encode($runtime['config'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    $env=ChatGoRuntimeConfig::environmentFile($runtime['environment']);

    $write=static function(string $path,string $content,int $mode):void{
        $tmp=$path.'.'.bin2hex(random_bytes(6)).'.tmp';
        if(file_put_contents($tmp,$content,LOCK_EX)!==strlen($content)){@unlink($tmp);throw new RuntimeException('write failed');}
        @chmod($tmp,$mode);
        if(!@rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('replace failed');}
        @chmod($path,$mode);
    };
    $write($configPath,$config,0640);
    $write($envPath,$env,0600);
    fwrite(STDOUT,'CHAT_RUNTIME_CONFIG_READY '.json_encode($runtime['summary'],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
}catch(Throwable $error){
    fwrite(STDERR,"CHAT_RUNTIME_CONFIG_FAILED\n");
    exit(2);
}
