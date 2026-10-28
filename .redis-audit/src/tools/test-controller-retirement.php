<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/server/app/Support/functions.php';

(static function (): void {
    $root=dirname(__DIR__);
    spl_autoload_register(static function(string $class)use($root):void{
        $prefix='WorkerAdmin\\';
        if(strpos($class,$prefix)!==0)return;
        $file=$root.'/server/app/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
        if(is_file($file))require_once $file;
    });
    $assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException('Controller retirement: '.$message);};

    $assert(!is_file($root.'/server/app/Controllers/ApiController.php'),'ApiController.php has been fully retired and must not be restored');
    $assert(!is_file($root.'/server/app/Controllers/AdminController.php'),'AdminController.php has been fully retired and must not be restored');

    $db=new \WorkerAdmin\Core\Database(['database'=>'offline_retirement','username'=>'none','password'=>'none']);

    $apiKernel=new \WorkerAdmin\Http\Api\ApiKernel($db);
    $apiProp=(new ReflectionClass($apiKernel))->getProperty('modules');$apiProp->setAccessible(true);$apiModules=$apiProp->getValue($apiKernel);
    $apiLegacy=end($apiModules);
    $assert($apiLegacy instanceof \WorkerAdmin\Http\Api\Modules\LegacyApiModule,'Api Legacy must remain last as unknown-route 404 only');
    $apiFormal=array_values(array_filter($apiModules,static fn($module):bool=>!$module instanceof \WorkerAdmin\Http\Api\Modules\LegacyApiModule));
    $apiLegacySource=(string)file_get_contents($root.'/server/app/Http/Api/Modules/LegacyApiModule.php');
    $assert(strpos($apiLegacySource,'WorkerAdmin\\Controllers\\ApiController')===false,'LegacyApiModule must not depend on retired ApiController');

    $apiFinalOwners=[
        'bootstrap'=>'BootstrapApiModule','merchant.profile'=>'MerchantAuthApiModule',
        'admin.withdrawals'=>'MiniAdminMoneyApiModule','admin.withdrawal.review'=>'MiniAdminMoneyApiModule',
        'admin.order.action'=>'MiniAdminOrderApiModule','admin.order.quote'=>'MiniAdminOrderApiModule','admin.order.share'=>'MiniAdminOrderApiModule',
        'admin.demo.status'=>'MiniAdminDemoApiModule','admin.demo.switch'=>'MiniAdminDemoApiModule',
    ];
    foreach($apiFinalOwners as $route=>$ownerName){
        $owners=array_values(array_filter($apiFormal,static fn($module):bool=>$module->supports($route)));
        $ownerClass='WorkerAdmin\\Http\\Api\\Modules\\'.$ownerName;
        $assert(count($owners)===1&&$owners[0] instanceof $ownerClass,'final Api route lost unique owner: '.$route);
        $assert(!$apiLegacy->supports($route),'final Api route must remain in anti-fallback registry: '.$route);
    }

    $auth=new \WorkerAdmin\Core\Auth($db);$csrf=new \WorkerAdmin\Core\Csrf();
    $adminKernel=new \WorkerAdmin\Http\Admin\AdminKernel($db,$auth,$csrf);
    $adminProp=(new ReflectionClass($adminKernel))->getProperty('modules');$adminProp->setAccessible(true);$adminModules=$adminProp->getValue($adminKernel);
    $adminLegacy=end($adminModules);
    $assert($adminLegacy instanceof \WorkerAdmin\Http\Admin\Modules\LegacyAdminModule,'Admin Legacy must remain last as unknown-route 404 only');
    $adminFormal=array_values(array_filter($adminModules,static fn($module):bool=>!$module instanceof \WorkerAdmin\Http\Admin\Modules\LegacyAdminModule));
    $adminLegacySource=(string)file_get_contents($root.'/server/app/Http/Admin/Modules/LegacyAdminModule.php');
    $assert(strpos($adminLegacySource,'WorkerAdmin\\Controllers\\AdminController')===false,'LegacyAdminModule must not depend on retired AdminController');

    $helpAdminSource=(string)file_get_contents($root.'/server/app/Controllers/HelpCenterAdminController.php');
    $helpReadSource=(string)file_get_contents($root.'/server/app/Infrastructure/Persistence/HelpCenterAdminReadModel.php');
    $assert(strpos($helpReadSource,'f.question LIKE :q_question')!==false,'Help search must use independent native-PDO placeholders');
    $assert(strpos($helpReadSource,'f.question LIKE :q OR')===false,'Help search must not restore repeated :q placeholders');
    $assert(strpos($helpReadSource,"preg_match('/^[A-Za-z0-9_-]+$/D',\$query)")!==false,'Help FAQ-ID search must remain ASCII-gated');
    $assert(substr_count($helpAdminSource,'$this->redirectAfterFailure($route);')===2,'Help GET failures must use bounded failure redirect handling');
    $assert(strpos($helpAdminSource,"if(\$route==='help'&&\$hasPageState)")!==false,'Help filtered GET failure must drop failing page state before retry');

    $legacyReflection=new ReflectionClass($adminLegacy);
    $migrated=$legacyReflection->getConstant('MIGRATED_ROUTES');
    $assert(is_array($migrated)&&count($migrated)>0,'Admin migrated anti-fallback registry must remain explicit');
    foreach($migrated as $route){
        $owners=array_values(array_filter($adminFormal,static fn($module):bool=>$module->supports($route)));
        $assert(count($owners)===1,'Admin migrated route must have exactly one formal owner: '.$route);
        $assert(!$adminLegacy->supports($route),'Admin migrated route must remain blocked from Legacy fallback: '.$route);
    }
    $assert($adminLegacy->supports('__unknown_admin_route__'),'Admin Legacy must retain unknown-route 404 fallback');

    $candidates=[];
    foreach($adminFormal as $module){
        $moduleSource=(string)file_get_contents((new ReflectionClass($module))->getFileName());
        foreach(token_get_all($moduleSource) as $token){
            if(!is_array($token)||$token[0]!==T_CONSTANT_ENCAPSED_STRING)continue;
            $value=substr($token[1],1,-1);
            if(preg_match('/^[a-z][a-z0-9_-]*(?:[.][a-z0-9_-]+)*$/',$value)===1)$candidates[$value]=true;
        }
    }
    foreach(array_keys($candidates) as $route){
        $owners=array_values(array_filter($adminFormal,static fn($module):bool=>$module->supports($route)));
        $assert(count($owners)<=1,'Admin conflicting formal owners: '.$route);
    }

    echo 'Controller retirement OK: ApiController and AdminController are physically removed; migrated routes have unique formal owners and explicit anti-fallback guards.\n';
})();

// 管理后台专项夹具也需要独立进程，避免同名 Fake 类互相影响。
$runRegressions = require __DIR__ . '/support/php-regression-runner.php';
$runRegressions([
    __DIR__ . '/test-admin-service-category-migration.php',
    __DIR__ . '/test-admin-order-form-designer-migration.php',
    __DIR__ . '/test-admin-account-migration.php',
    __DIR__ . '/test-admin-user-merchant-credential-migration.php',
    __DIR__ . '/test-admin-review-migration.php',
    __DIR__ . '/test-admin-worker-migration.php',
    __DIR__ . '/test-admin-oversight-migration.php',
    __DIR__ . '/test-admin-finance-migration.php',
    __DIR__ . '/test-admin-order-migration.php',
]);
