<?php

declare(strict_types=1);
if(!function_exists('setting')){function setting($key,$default=null){return $default;}}
if(!function_exists('app_config')){function app_config($key,$default=null){return $default;}}

(static function (): void {
$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void{
    $prefix='WorkerAdmin\\';if(strpos($class,$prefix)!==0)return;
    $file=$root.'/server/app/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($file))require_once $file;
});
$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException('Media module migration: '.$message);};

$db=new \WorkerAdmin\Core\Database(['database'=>'offline_media','username'=>'none','password'=>'none']);
$write=new \WorkerAdmin\Http\Api\Modules\MediaApiModule($db);
$delivery=new \WorkerAdmin\Http\Api\Modules\MediaDeliveryApiModule($db);
$legacy=new \WorkerAdmin\Http\Api\Modules\LegacyApiModule($db);

$writeRoutes=[
    'media.upload','media.delete','orders.measurement.media.upload','orders.measurement.media.delete',
    'reviews.media.upload','finance.realname.upload','finance.collection-code.upload','merchant.license.upload',
];
foreach($writeRoutes as $route){$assert($write->supports($route),'MediaApiModule must own '.$route);$assert(!$legacy->supports($route),'Legacy must anti-fallback '.$route);}
foreach(['media.order','media.worker'] as $route){$assert($delivery->supports($route),'MediaDeliveryApiModule must own '.$route);$assert(!$legacy->supports($route),'Legacy must anti-fallback '.$route);}
$assert(!$write->supports('orders.measurement.space.save')&&!$write->supports('orders.measurement.space.delete'),'measurement space structure stays in Order board');
$assert(!$delivery->supports('media.upload'),'delivery module must not own mutation routes');

$kernel=new \WorkerAdmin\Http\Api\ApiKernel($db);
$property=(new ReflectionClass($kernel))->getProperty('modules');$property->setAccessible(true);$modules=$property->getValue($kernel);
foreach(array_merge($writeRoutes,['media.order','media.worker']) as $route){
    $owners=array_values(array_filter($modules,static fn($module):bool=>$module instanceof \WorkerAdmin\Http\Api\ApiModule&&$module->supports($route)));
    $assert(count($owners)===1,'route must have one formal owner: '.$route);
}

$controller=(string)file_get_contents($root.'/server/app/Controllers/ApiController.php');
$oldMap=[
    "'media.upload' => ['POST', 'uploadMedia']"=>'private function uploadMedia(',
    "'media.delete' => ['POST', 'deleteOrderMedia']"=>'private function deleteOrderMedia(',
    "'orders.measurement.media.upload' => ['POST', 'uploadMeasurementMedia']"=>'private function uploadMeasurementMedia(',
    "'orders.measurement.media.delete' => ['POST', 'deleteMeasurementMedia']"=>'private function deleteMeasurementMedia(',
    "'reviews.media.upload' => ['POST', 'uploadReviewMedia']"=>'private function uploadReviewMedia(',
    "'finance.realname.upload' => ['POST', 'uploadRealNameMedia']"=>'private function uploadRealNameMedia(',
    "'finance.collection-code.upload' => ['POST', 'uploadCollectionCode']"=>'private function uploadCollectionCode(',
    "'merchant.license.upload' => ['POST', 'uploadMerchantLicense']"=>'private function uploadMerchantLicense(',
];
foreach($oldMap as $routeLiteral=>$handler){$assert(strpos($controller,$routeLiteral)===false,'retired Controller implementation must stay absent: '.$routeLiteral);$assert(strpos($controller,$handler)===false,'retired Controller implementation must stay absent: '.$handler);}
$assert(strpos($controller,"\$route === 'media.order'")===false&&strpos($controller,'private function showMedia(')===false,'retired Controller implementation must stay absent: ');
$assert(strpos($controller,"\$route === 'media.worker'")===false&&strpos($controller,'private function showWorkerMedia(')===false,'retired Controller implementation must stay absent: ');

$writeSource=(string)file_get_contents($root.'/server/app/Http/Api/Modules/MediaApiModule.php');
foreach(['media.upload','reviews.media.upload','finance.realname.upload','finance.collection-code.upload','merchant.license.upload','orders.measurement.media.upload'] as $route){
    $assert(strpos($writeSource,"'".$route."'")!==false,'demo-protected upload route retained: '.$route);
}
$demoBlock=substr($writeSource,(int)strpos($writeSource,'private const DEMO_BLOCKED'),700);
$assert(strpos($demoBlock,"'media.delete'")===false&&strpos($demoBlock,"'orders.measurement.media.delete'")===false,'legacy demo policy still permits delete routes');

$app=(string)file_get_contents($root.'/server/app/Application/Media/MediaOperations.php');
$assert(strpos($app,'$this->transactions->run')!==false&&strpos($app,'$this->publish(')!==false,'uploads keep DB transaction and post-commit object storage phases');
$assert(strpos($app,"'图片已无损优化并保存，订单正式提交后同步七牛云'")!==false,'draft order media keeps deferred Qiniu behavior');
$assert(strpos($app,'fn()=>$this->repository->store')===false,'void repository writes must not be returned from arrow functions');

$repo=(string)file_get_contents($root.'/server/app/Infrastructure/Persistence/DatabaseMediaRepository.php');
foreach([
    'share_contexts','assigned_worker_id','worker_real_name_verifications','worker_collection_code_items',
    "purpose='collection_receipt'",'offline_collection_adjustment_requests','amount_paid_cents',
    "status='auto_approved'",'OrderNotificationService::queueAdminTask','ID_WATERMARK_REQUIRED',
] as $needle)$assert(strpos($repo,$needle)!==false,'media persistence must retain '.$needle);
$assert(strpos($repo,"result_status']!=='editing'")!==false,'measurement media remains locked after result submission');
$assert(strpos($repo,'COUNT(*) FROM review_media')!==false,'review image limit remains server-side');

$deliverySource=(string)file_get_contents($root.'/server/app/Http/Api/Modules/MediaDeliveryApiModule.php');
$assert(strpos($deliverySource,"'private, no-store, max-age=0'")!==false,'private media remains no-store');
$assert(strpos($deliverySource,'public, max-age=31536000, immutable')!==false,'public worker media retains immutable local caching');
$assert(strpos($deliverySource,'$this->storage->read(')!==false,'HTTP Qiniu domain remains proxied through application HTTPS endpoint');

$storage=(string)file_get_contents($root.'/server/app/Infrastructure/External/QiniuObjectStorage.php');
$contract=(string)file_get_contents($root.'/server/app/Contracts/ObjectStorage.php');
$assert(strpos($contract,'public function read(')!==false&&strpos($storage,'public function read(')!==false,'ObjectStorage read port supports HTTPS proxy delivery');

echo "Media board migration checks passed.\n";
})();
