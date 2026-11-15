<?php
declare(strict_types=1);

$GLOBALS['platform_env']='production';
function app_config(string $key,$default=null){
    if($key==='app.env') return $GLOBALS['platform_env'];
    return $default;
}
function setting(string $key,$default=null){return $default;}

require __DIR__.'/server/app/Infrastructure/Safety/TestingEnvironmentPolicy.php';
require __DIR__.'/server/app/Infrastructure/Persistence/PlatformOrderCustomerAccountScope.php';

use WorkerAdmin\Infrastructure\Persistence\PlatformOrderCustomerAccountScope;

function ok(bool $v,string $m):void{if(!$v){fwrite(STDERR,"[FAIL] $m\n");exit(1);}echo "[PASS] $m\n";}

$GLOBALS['platform_env']='production';
$p=PlatformOrderCustomerAccountScope::linkableSql('u');
ok(strpos($p,"wi.status='active'")!==false,'production still requires active WeChat identity');
ok(strpos($p,'AND NOT')!==false,'production explicitly excludes demo identities');
ok(strpos($p,' OR ')===false,'production does not OR demo actors into customer scope');
ok(PlatformOrderCustomerAccountScope::testingAllowsDemo()===false,'production reports demo disabled');

$GLOBALS['platform_env']='testing';
$t=PlatformOrderCustomerAccountScope::linkableSql('u');
ok(strpos($t,"wi.status='active'")!==false,'testing still accepts real active WeChat identities');
ok(strpos($t,' OR ')!==false,'testing adds demo actors to customer scope');
ok(strpos($t,'AND NOT')===false,'testing does not exclude demo actors');
ok(PlatformOrderCustomerAccountScope::testingAllowsDemo()===true,'testing reports demo enabled');

$demo=PlatformOrderCustomerAccountScope::demoSql('u');
ok(strpos($demo,'demo.actor_user_public_id')!==false,'configured demo user remains recognized');
ok(strpos($demo,'01K2DEMOV%WORKER%')!==false,'stable demo worker namespace remains recognized');
echo "Platform environment account scope regression: OK\n";
