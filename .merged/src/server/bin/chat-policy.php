<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$after=0;$limit=100;$apply=false;$check=false;
foreach(array_slice($argv,1) as $argument){
    if($argument==='--help'){
        fwrite(STDOUT,"用法：php bin/chat-policy.php [--check] [--after=0] [--limit=100] [--apply]\n--check只核验依赖与撤权触发器；默认预览回填，每批最多100笔。用next_after继续，has_more=false时结束。\n");exit(0);
    }
    if($argument==='--apply'){$apply=true;continue;}
    if($argument==='--check'){$check=true;continue;}
    if(preg_match('/^--(after|limit)=([0-9]+)$/D',$argument,$m)!==1 || strlen($m[2])>18){fwrite(STDERR,"参数无效\n");exit(2);}
    if($m[1]==='after')$after=(int)$m[2];else $limit=(int)$m[2];
}
if($limit<1||$limit>100){fwrite(STDERR,"每批必须为1至100笔\n");exit(2);}
if($check && $apply){fwrite(STDERR,"--check不能同时执行写入\n");exit(2);}
define('WORKER_BASE_PATH',dirname(__DIR__));
if(is_file(WORKER_BASE_PATH.'/storage/maintenance.lock')){fwrite(STDERR,"维护中，不能检查或回填授权\n");exit(3);}
require WORKER_BASE_PATH.'/bootstrap.php';
if(!is_installed()||upgrade_required()){fwrite(STDERR,"未安装或数据库待升级\n");exit(3);}
try{
    $maintenance=new \WorkerAdmin\Infrastructure\Persistence\DatabaseChatPolicyMaintenance(db());
    $result=$check?$maintenance->preflight():$maintenance->backfill($after,$limit,$apply);
    fwrite(STDOUT,json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");
}catch(Throwable $error){fwrite(STDERR,"授权检查/回填失败；当前订单事务已回滚，请修复后从上次成功游标重试\n");exit(1);}
