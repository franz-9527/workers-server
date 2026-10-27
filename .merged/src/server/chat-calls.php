<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
header('Content-Type: application/json');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
try{
    $env=['testing'=>'test','development'=>'development','production'=>'production'][(string)app_config('app.env','production')]??'';
    \WorkerAdmin\Infrastructure\Chat\ChatRecoveryGate::assertReady((array)app_config('chat',[]),$env);
    if(!is_installed()||upgrade_required()||app_config('chat.calls_enabled',false)!==true)throw new RuntimeException('CALL_DISABLED',503);
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||($_SERVER['REQUEST_URI']??'')!=='/chat-calls.php'||strtolower(trim(explode(';',(string)($_SERVER['CONTENT_TYPE']??''))[0]))!=='application/json')throw new RuntimeException('CALL_REQUEST_INVALID',400);
    $raw=file_get_contents('php://input',false,null,0,8193);if(!is_string($raw)||strlen($raw)>8192)throw new RuntimeException('CALL_REQUEST_INVALID',400);
    $headers=[];foreach(['environment','key-id','timestamp','nonce','signature'] as $key)$headers[$key]=(string)($_SERVER['HTTP_X_CHAT_'.strtoupper(str_replace('-','_',$key))]??'');
    \WorkerAdmin\Infrastructure\Messaging\ChatInternalAuth::configured(db())->verify('/chat-calls.php',$raw,$headers);
    $q=json_decode($raw,true,16,JSON_THROW_ON_ERROR);if(!is_array($q)||array_is_list($q))throw new RuntimeException('CALL_REQUEST_INVALID',400);
    echo json_encode((new \WorkerAdmin\Application\Chat\ChatCallResources(db()))->receive($q),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
}catch(Throwable $error){
    $code=$error instanceof RuntimeException&&preg_match('/^CALL_[A-Za-z0-9_]+$/D',$error->getMessage())===1?$error->getMessage():'CALL_RESOURCE_UNAVAILABLE';
    http_response_code($error instanceof RuntimeException&&in_array($error->getCode(),[400,409],true)?$error->getCode():503);
    echo json_encode(['code'=>$code],JSON_THROW_ON_ERROR);
}
