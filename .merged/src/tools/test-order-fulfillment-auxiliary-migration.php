<?php

declare(strict_types=1);

(static function (): void {
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'WorkerAdmin\\';
    if (strpos($class, $prefix) !== 0) return;
    $file = $root.'/server/app/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
    if (is_file($file)) require_once $file;
});
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('Fulfillment auxiliary migration: '.$message);
};

final class FulfillmentAuxiliaryFakeRepository implements \WorkerAdmin\Contracts\OrderFulfillmentAuxiliaryRepository
{
    public array $calls = [];
    public function reportArrival(array $actor, array $command): array { $this->calls[]=['arrival',$actor,$command]; return ['evidence_count'=>2]; }
    public function saveMeasurementSpace(array $actor, array $command): array { $this->calls[]=['space_save',$actor,$command]; return ['id'=>'SPACE-1','name'=>$command['name'],'sort_order'=>$command['sort_order']]; }
    public function deleteMeasurementSpace(array $actor, string $orderPublicNo, string $spacePublicId): void { $this->calls[]=['space_delete',$actor,$orderPublicNo,$spacePublicId]; }
    public function eligibleMeasurementLinks(array $actor): array { $this->calls[]=['eligible',$actor]; return [['id'=>'M-1','hold_until'=>'2026-10-09 12:00:00']]; }
    public function createRepair(array $actor, array $command): array { $this->calls[]=['repair_create',$actor,$command]; return ['id'=>'R-1']; }
    public function withdrawRepair(array $actor, string $orderPublicNo, string $afterSalePublicNo): void { $this->calls[]=['repair_withdraw',$actor,$orderPublicNo,$afterSalePublicNo]; }
}

$repo = new FulfillmentAuxiliaryFakeRepository();
$app = new \WorkerAdmin\Application\Order\OrderFulfillmentAuxiliary($repo, 'Asia/Shanghai');
$actor = ['user_id'=>7,'worker_id'=>9];

$result = $app->execute('arrivals.report', $actor, [
    'order_id'=>'O-1','description'=>'现场无法施工','exception_type'=>'bad-value','lat'=>30.1,'lng'=>120.2,
]);
$assert($result['message']==='到场情况已提交，请联系客服处理', 'arrival message changed');
$assert($repo->calls[0][2]['exception_type']==='other', 'arrival exception type must normalize to other');

try {
    $app->execute('arrivals.report', $actor, ['order_id'=>'O-1','description'=>'现场无法施工','lat'=>999,'lng'=>120]);
    $assert(false, 'invalid arrival coordinate must fail');
} catch (\WorkerAdmin\Domain\Order\OrderOperationException $exception) {
    $assert($exception->apiCode()==='ARRIVAL_LOCATION_REQUIRED', 'arrival coordinate api code changed');
}

$result = $app->execute('orders.measurement.space.save', $actor, [
    'order_id'=>'O-2','name'=>' 客厅 ','sort_order'=>5000,
]);
$assert($result['message']==='空间已添加', 'space create message changed');
$spaceCall = $repo->calls[count($repo->calls)-1];
$assert($spaceCall[2]['name']==='客厅' && $spaceCall[2]['sort_order']===999, 'space normalization changed');

$result = $app->execute('orders.measurement.links.eligible', $actor, []);
$assert($result['data']['items'][0]['hold_until']==='2026-10-09 20:00', 'eligible measurement local time changed');

$result = $app->execute('after_sales.repair.create', $actor, [
    'order_id'=>'O-3','description'=>'墙布接缝处出现明显翘边','evidence_ids'=>['M1','M1','M2'],'non_human_confirmed'=>1,
]);
$assert($result['data']['id']==='R-1', 'repair create result changed');
$repairCall = $repo->calls[count($repo->calls)-1];
$assert($repairCall[2]['evidence_ids']===['M1','M2'], 'repair evidence must deduplicate');

$module = (string)file_get_contents($root.'/server/app/Http/Api/Modules/OrderFulfillmentApiModule.php');
$legacy = (string)file_get_contents($root.'/server/app/Http/Api/Modules/LegacyApiModule.php');
$controller = (is_file($root.'/server/app/Controllers/ApiController.php') ? (string)file_get_contents($root.'/server/app/Controllers/ApiController.php') : '');
$persistence = (string)file_get_contents($root.'/server/app/Infrastructure/Persistence/DatabaseOrderFulfillmentAuxiliaryRepository.php');
$routes = [
    'arrivals.report'=>'reportArrival',
    'orders.measurement.space.save'=>'saveMeasurementSpace',
    'orders.measurement.space.delete'=>'deleteMeasurementSpace',
    'orders.measurement.links.eligible'=>'eligibleMeasurementLinks',
    'after_sales.repair.create'=>'createRepairRequest',
    'after_sales.repair.withdraw'=>'withdrawRepairRequest',
];
foreach ($routes as $route=>$handler) {
    $assert(strpos($module, "'".$route."'") !== false, 'formal module missing '.$route);
    $assert(strpos($legacy, "'".$route."'") !== false, 'Legacy anti-fallback missing '.$route);
    $assert(strpos($controller, "'".$route."'") === false, 'retired old route reintroduced '.$route);
    $assert(strpos($controller, 'function '.$handler.'(') === false, 'retired old handler reintroduced '.$handler);
}
$assert(strpos($persistence, 'DatabaseOrderCancellationRiskRepository') !== false, 'arrival complaint risk signal lost');
$assert(strpos($persistence, 'DatabaseReviewRepository') !== false, 'repair auto review lost');
$assert(strpos($persistence, 'OrderNotificationService::queue') !== false, 'order event notification outbox lost');
$assert(strpos($persistence, 'order_worker_contact_reveal_requests') !== false, 'repair withdrawal contact request cancellation lost');
$assert(strpos($persistence, 'order_measurement_spaces') !== false && strpos($persistence, 'media_links') !== false, 'measurement space/media lifecycle lost');

echo "Order fulfillment auxiliary board migration tests passed.\n";
})();
