<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/bootstrap.php';
try {
    if(!is_installed()||upgrade_required()||count($argv)!==2||!in_array($argv[1],['block','capture','verify','release','prune-audit'],true))throw new RuntimeException('CHAT_RECOVERY_USAGE');
    $config=(array)app_config('chat',[]);
    $env=['testing'=>'test','development'=>'development','production'=>'production'][(string)app_config('app.env','production')]??'';
    $directory=(string)($config['recovery_directory']??'');
    if((string)($config['recovery_gate_file']??'')!==rtrim($directory,'/\\').'/gate.json')throw new RuntimeException('CHAT_RECOVERY_GATE_PATH');
    $name=(string)($config['recovery_key_env']??'');
    if(preg_match('/^CHAT_[A-Z0-9_]{1,60}$/D',$name)!==1)throw new RuntimeException('CHAT_RECOVERY_KEY');
    $encoded=getenv($name);$key=is_string($encoded)?base64_decode($encoded,true):false;
    if(!is_string($key)||strlen($key)!==32||base64_encode($key)!==$encoded)throw new RuntimeException('CHAT_RECOVERY_KEY');
    $ops=new \WorkerAdmin\Infrastructure\Chat\ChatRecoveryCheckpoint(db()->pdo(),$env,$key,$directory,(string)($config['recovery_epoch']??''));
    if($argv[1]==='block'){$ops->block();$result=['status'=>'blocked'];}
    elseif($argv[1]==='prune-audit')$result=$ops->pruneAudit();
    elseif($argv[1]==='capture')$result=$ops->capture();
    else $result=$ops->verify($argv[1]==='release');
    fwrite(STDOUT,json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n");
    exit(($result['status']??'')==='blocked'&&$argv[1]!=='block'?1:0);
}catch(Throwable $e){fwrite(STDERR,"聊天恢复核验未通过，保持维护状态\n");exit(1);}
