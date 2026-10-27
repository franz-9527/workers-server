<?php
declare(strict_types=1);
define('PAYMENT_HTTP_FIXTURE_LIBRARY',true);
require __DIR__.'/payment_http_router.php';
final class BoardContentGate implements \WorkerAdmin\Contracts\RequestContentSecurityGate{
 public function inspect(string $route):void{header('X-Test-Safety-Gate: inspected');if(in_array($route,['media.order','media.worker'],true))throw new RuntimeException('delivery entered text content gate');}
}
$db=new \WorkerAdmin\Core\Database(['database'=>'offline']);$actors=new \WorkerAdmin\Application\Identity\ApiActorResolver(new HttpPaymentActorRepository(),new HttpPaymentSettings());
$kernel=new \WorkerAdmin\Http\Api\ApiKernel($db,null,new BoardContentGate());
$modules=(new ReflectionClass($kernel))->getProperty('modules');$modules->setAccessible(true);
foreach($modules->getValue($kernel)as$module){
 if(!in_array(get_class($module),[\WorkerAdmin\Http\Api\Modules\MoneyApiModule::class,\WorkerAdmin\Http\Api\Modules\MediaApiModule::class,\WorkerAdmin\Http\Api\Modules\IdentityWorkerApiModule::class],true))continue;
 $property=(new ReflectionClass($module))->getProperty('actors');$property->setAccessible(true);$property->setValue($module,$actors);
}
try{$kernel->dispatch((string)($_GET['route']??''));}
catch(Throwable $e){json_response(['code'=>'TEST_FAILURE','message'=>$e->getMessage()],500);}
