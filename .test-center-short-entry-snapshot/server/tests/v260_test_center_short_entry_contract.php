<?php

declare(strict_types=1);

function entryContractAssert(bool $condition,string $label): void
{
    if(!$condition){fwrite(STDERR,'[FAIL] '.$label."\n");exit(1);}
    fwrite(STDOUT,'[PASS] '.$label."\n");
}
function entryContractFile(string $root,string $path): string
{
    $value=file_get_contents($root.'/'.$path);
    if(!is_string($value))throw new RuntimeException('cannot read '.$path);
    return$value;
}

$root=dirname(__DIR__,2);
$migration=entryContractFile($root,'server/database/upgrades/2.6.0.sql');
$service=entryContractFile($root,'server/app/Infrastructure/Testing/TestCenterEntrySession.php');
$actorRepo=entryContractFile($root,'server/app/Infrastructure/Persistence/DatabaseApiActorRepository.php');
$resolver=entryContractFile($root,'server/app/Application/Identity/ApiActorResolver.php');
$demoRepo=entryContractFile($root,'server/app/Infrastructure/Persistence/DatabaseMiniAdminDemoRepository.php');
$api=entryContractFile($root,'server/app/Http/Api/Modules/MiniAdminDemoApiModule.php');
$admin=entryContractFile($root,'server/app/Http/Admin/Modules/TestCenterAdminModule.php');
$detail=entryContractFile($root,'server/views/admin/test-center-detail.php');
$page=entryContractFile($root,'miniprogram/pages/testEntry/index.js');
$app=entryContractFile($root,'miniprogram/app.json');

entryContractAssert(strpos($migration,'test_entry_tokens')!==false&&strpos($migration,'CREATE TABLE IF NOT EXISTS')!==false,'2.6.0 migration creates test entry token table');
entryContractAssert(strpos($migration,'token_hash')!==false&&strpos($migration,'`token` varchar')===false&&strpos($migration,'`token` char')===false,'entry table persists token hash rather than plaintext token column');
entryContractAssert(strpos($migration,'test_actor_user_id')!==false&&strpos($migration,'test_instance_id')!==false&&strpos($migration,'test_actor_expires_at')!==false,'login sessions gain bounded test actor fields');
entryContractAssert(strpos($migration,'information_schema.columns')!==false,'login session columns use idempotent MySQL 5.7 migration guards');

entryContractAssert(strpos($service,'private const TTL_OPTIONS=[5,10,20,30]')!==false,'entry service bounds TTL choices');
entryContractAssert(strpos($service,"in_array(\$role,['user','worker'],true)")!==false,'entry service supports only user and worker roles');
entryContractAssert(strpos($service,"w.cloud_record_mode='demo_local'")!==false,'worker entry requires demo_local participant');
entryContractAssert(strpos($service,"tp.actor_type='worker'")!==false,'worker entry requires registered test participant role');
entryContractAssert(strpos($service,"hash('sha256',\$plain)")!==false,'entry service hashes plaintext token before persistence');
entryContractAssert(strpos($service,"preg_match('/^[a-f0-9]{48}$/iD'")!==false,'redeem accepts only fixed-format short token');
entryContractAssert(strpos($service,"status='used',used_at=UTC_TIMESTAMP(),used_by_session_id=:session")!==false,'redeem atomically consumes one-time token');
entryContractAssert(strpos($service,'test_actor_expires_at=:expires')!==false,'redeem binds login session to token expiry');
entryContractAssert(strpos($service,"BXDEMOHALL")!==false,'entry service blocks review samples');
entryContractAssert(strpos($service,"if(\$pageKey==='user_order_detail')")!==false&&strpos($service,"if(\$pageKey==='worker_order_detail')")!==false,'target page is server whitelist only');
entryContractAssert(strpos($service,'pages/adminWorkbench')===false,'entry service never emits arbitrary admin route');

entryContractAssert(strpos($actorRepo,'findTestActor')!==false&&strpos($actorRepo,"w.cloud_record_mode='demo_local'")!==false,'actor repository revalidates test participant ownership');
entryContractAssert(strpos($resolver,'$this->testingEnvironment&&$validWindow&&$this->isRealMiniAdmin($actor)')!==false,'test actor requires testing runtime, unexpired window and real mini admin');
entryContractAssert(strpos($resolver,"\$actor['is_test_actor']=true")!==false&&strpos($resolver,"\$actor['is_demo_actor']=true")!==false,'resolved test actor enters demo safety domain');
entryContractAssert(strpos($resolver,'clearTestActor')!==false,'invalid or expired test actor is cleared');
entryContractAssert(strpos($demoRepo,'test_actor_user_id=NULL,test_instance_id=NULL,test_actor_expires_at=NULL')!==false,'returning to real/demo identity clears short test actor');

entryContractAssert(strpos($api,"'admin.test-entry.redeem'")!==false,'mini admin API exposes dedicated redeem route');
entryContractAssert(strpos($api,'isRealMiniAdmin($actor)')!==false,'redeem route requires real mini admin');
entryContractAssert(strpos($admin,"'test-center.entry-token'")!==false&&strpos($admin,'$this->testEntry->issue(')!==false,'web test center exposes controlled token issue route');
entryContractAssert(strpos($detail,'生成客户短期入口')!==false&&strpos($detail,'生成师傅短期入口')!==false,'instance detail exposes role-specific issue controls');

entryContractAssert(strpos($page,"ApiEnvironment.switchTo('testing')")!==false,'miniapp entry confirms or switches to testing API first');
entryContractAssert(strpos($page,"OrderApi.request('admin.test-entry.redeem'")!==false,'miniapp redeems token through authenticated PHP API');
entryContractAssert(strpos($page,'userOrder|workerOrder')!==false&&strpos($page,'detail\\?id=')!==false,'miniapp accepts only user or worker detail destination shape');
entryContractAssert(strpos($app,'pages/testEntry/index')!==false,'miniapp registers test entry page');

fwrite(STDOUT,"Test-center short miniapp entry contract passed.\n");
