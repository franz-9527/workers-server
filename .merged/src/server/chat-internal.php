<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
header('Content-Type: application/json');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
try{
    $recoveryEnvironment=['testing'=>'test','development'=>'development','production'=>'production'][(string)app_config('app.env','production')]??'';
    \WorkerAdmin\Infrastructure\Chat\ChatRecoveryGate::assertReady((array)app_config('chat',[]),$recoveryEnvironment);
    if(!is_installed()||upgrade_required()||app_config('chat.reminders_enabled',false)!==true)throw new RuntimeException('closed');
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||($_SERVER['REQUEST_URI']??'')!=='/chat-internal.php'||strtolower(trim(explode(';',(string)($_SERVER['CONTENT_TYPE']??''))[0]))!=='application/json')throw new RuntimeException('request');
    $raw=file_get_contents('php://input',false,null,0,8193);if(!is_string($raw)||strlen($raw)>8192)throw new RuntimeException('body');
    $headers=[];foreach(['environment','key-id','timestamp','nonce','signature'] as $key)$headers[$key]=(string)($_SERVER['HTTP_X_CHAT_'.strtoupper(str_replace('-','_',$key))]??'');
    $auth=\WorkerAdmin\Infrastructure\Messaging\ChatInternalAuth::configured(db());$auth->verify('/chat-internal.php',$raw,$headers);
    $result=(new \WorkerAdmin\Application\Chat\ChatReminderReceipts(db()))->receive($raw);
    echo json_encode($result,JSON_THROW_ON_ERROR);
}catch(Throwable $error){http_response_code(503);echo '{"code":"CHAT_REMINDER_UNAVAILABLE"}';}
