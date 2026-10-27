<?php
declare(strict_types=1);
define('PAYMENT_HTTP_FIXTURE_LIBRARY',true);
require __DIR__.'/payment_http_router.php';
final class AuxiliaryHttpGate implements \WorkerAdmin\Contracts\RequestContentSecurityGate {
 public function inspect(string $route):void { header('X-Test-Safety-Gate: inspected'); }
}
final class AuxiliaryHttpRepository implements \WorkerAdmin\Contracts\OrderFulfillmentAuxiliaryRepository {
 public function reportArrival(array $actor,array $command):array {return [];}
 public function saveMeasurementSpace(array $actor,array $command):array {return ['id'=>'SPACE-1','name'=>$command['name'],'sort_order'=>$command['sort_order']];}
 public function deleteMeasurementSpace(array $actor,string $order,string $space):void {}
 public function eligibleMeasurementLinks(array $actor):array {return [['id'=>'ORDER-1','hold_until'=>'2026-10-09 12:00:00']];}
 public function createRepair(array $actor,array $command):array {return ['id'=>'REPAIR-1','evidence'=>$command['evidence_ids']];}
 public function withdrawRepair(array $actor,string $order,string $repair):void {}
}
final class MiniAdminHttpRepository implements \WorkerAdmin\Contracts\MiniAdminWorkerStoreRepository {
 public function updateWorker(string $id,array $patch):array {return ['worker'=>['status'=>'approved'],'local_worker_id'=>9];}
 public function workerSmsStats(array $actor,string $id):array {return ['loaded'=>true,'mobile_available'=>false,'attempt_count'=>0];}
 public function reviewWorker(string $id,string $decision,string $reason):array {return ['worker'=>['status'=>$decision],'local_worker_id'=>9];}
 public function reviewStore(string $id,string $decision):array {return ['published'=>$decision==='approved'];}
 public function reviewRecords(string $source,int $page,int $size,string $keyword,array $cloudIds=[]):array {return ['items'=>[],'page'=>$page,'page_size'=>$size,'total'=>0,'has_more'=>false];}
}
function auxiliarySet(object $object,string $name,$value):void { $p=(new ReflectionClass($object))->getProperty($name);$p->setAccessible(true);$p->setValue($object,$value); }
$db=new \WorkerAdmin\Core\Database(['database'=>'offline']);
$actors=new \WorkerAdmin\Application\Identity\ApiActorResolver(new HttpPaymentActorRepository(),new HttpPaymentSettings());
$kernel=new \WorkerAdmin\Http\Api\ApiKernel($db,null,new AuxiliaryHttpGate());
$p=(new ReflectionClass($kernel))->getProperty('modules');$p->setAccessible(true);
foreach($p->getValue($kernel)as$module){
 if($module instanceof \WorkerAdmin\Http\Api\Modules\MiniAdminWorkerStoreApiModule){auxiliarySet($module,'actors',$actors);auxiliarySet($module,'operations',new \WorkerAdmin\Application\Admin\MiniAdminWorkerStoreOperations(new MiniAdminHttpRepository()));}
 if($module instanceof \WorkerAdmin\Http\Api\Modules\OrderFulfillmentApiModule){
  $container=new \WorkerAdmin\Foundation\OrderApplicationContainer($db);auxiliarySet($container,'actorResolver',$actors);auxiliarySet($module,'container',$container);
  auxiliarySet($module,'auxiliary',new \WorkerAdmin\Application\Order\OrderFulfillmentAuxiliary(new AuxiliaryHttpRepository()));
 }
}
try{$kernel->dispatch((string)($_GET['route']??''));}
catch(Throwable $error){json_response(['code'=>'TEST_FAILURE','message'=>$error->getMessage()],500);}
