<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$args=array_slice($argv,1);
if($args===['--help']){fwrite(STDOUT,"用法：php bin/chat-reminders.php [--loop]\n单次处理20项；--loop每5秒启动独立子进程，失败退避15秒。由Supervisor按进程组托管。\n");exit;}
if($args===['--loop']){
    // Each pass has fresh settings/token metadata. The parent never opens a DB
    // or channel, so a long-running process cannot retain an old template map.
    if(!function_exists('proc_open')){fwrite(STDERR,"提醒调度需要CLI proc_open\n");exit(1);}
    while(true){
        $started=microtime(true);$pipes=[];
        $child=proc_open([PHP_BINARY,__FILE__],[0=>['file','/dev/null','r'],1=>STDOUT,2=>STDERR],$pipes,dirname(__DIR__));
        $status=is_resource($child)?proc_close($child):1;
        $wait=max(0,($status===0?5:15)-(microtime(true)-$started));
        if($wait>0)usleep((int)($wait*1000000));
    }
}
if($args!==[]){fwrite(STDERR,"参数无效\n");exit(2);}
require dirname(__DIR__).'/bootstrap.php';
try{
    $recoveryEnvironment=['testing'=>'test','development'=>'development','production'=>'production'][(string)app_config('app.env','production')]??'';
    \WorkerAdmin\Infrastructure\Chat\ChatRecoveryGate::assertReady((array)app_config('chat',[]),$recoveryEnvironment);
    if(!is_installed()||upgrade_required()||app_config('chat.reminders_enabled',false)!==true)throw new RuntimeException('closed');
    $auth=\WorkerAdmin\Infrastructure\Messaging\ChatInternalAuth::configured(db());
    $shared=new \WorkerAdmin\Foundation\ApplicationContainer(db());
    $dispatcher=(new \WorkerAdmin\Foundation\ChatApplicationContainer(db()))->reminders($shared->subscribeMessages(),[$auth,'eligible']);
    $stats=$dispatcher->dispatch(20);
    db()->execute('DELETE FROM chat_internal_nonces WHERE expires_at<=UTC_TIMESTAMP() ORDER BY expires_at LIMIT 1000');
    fwrite(STDOUT,json_encode($stats,JSON_THROW_ON_ERROR)."\n");
}catch(Throwable $error){fwrite(STDERR,"聊天提醒处理暂不可用\n");exit(1);}

