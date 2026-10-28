<?php

declare(strict_types=1);

$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void{$prefix='WorkerAdmin\\';if(strpos($class,$prefix)!==0)return;$file=$root.'/server/app/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($file))require_once $file;});

use WorkerAdmin\Application\Admin\WorkerAdmin;
use WorkerAdmin\Application\Configuration\WorkerRuntimeConfigSync;
use WorkerAdmin\Contracts\AdminConfigurationRepository;
use WorkerAdmin\Contracts\AdminWorkerRepository;
use WorkerAdmin\Contracts\WorkerCloudGateway;
use WorkerAdmin\Contracts\WorkerRuntimeConfigurationGateway;

final class FakeAdminWorkerRepository implements AdminWorkerRepository
{
    public array $projected=[];public array $reviews=[];
    public function localEnrichment(string $cloudId,string $openid):array{return ['id'=>12,'cloud'=>$cloudId,'openid'=>$openid];}
    public function projectCloudWorker(array $cloudWorker,int $adminId):int{$this->projected[]=[$cloudWorker,$adminId];return 42;}
    public function markDeleted(string $cloudId,string $reason):int{return 42;}
    public function recordReview(int $workerId,string $cloudId,string $decision,string $reason,int $adminId,string $workerName):void{$this->reviews[]=func_get_args();}
}
final class FakeWorkerCloudGateway implements WorkerCloudGateway
{
    public array $calls=[];public bool $failList=false;
    public function invoke(string $action,array $payload=[]):array{$this->calls[]=[$action,$payload];if($action==='admin.list'){if($this->failList)throw new RuntimeException('cloud unavailable');return ['items'=>[['_id'=>'cw1','_openid'=>'oyAoh12345678901234567890','workerName'=>'张师傅']],'total'=>1,'page'=>1,'pages'=>1,'page_size'=>20,'stats'=>['total'=>1,'pending'=>1]];}if($action==='admin.create'||$action==='admin.update'||$action==='admin.review'){$id=(string)($payload['id']??'cw1');return ['worker'=>['_id'=>$id,'_openid'=>'oyAoh12345678901234567890','workerName'=>'张师傅','status'=>(string)($payload['decision']??'pending')]];}if($action==='admin.delete')return ['deleted'=>true];if($action==='admin.health')return ['stats'=>['total'=>3,'approved'=>2,'pending'=>1]];return [];}
}
final class FakeAdminConfigurationRepositoryForWorker implements AdminConfigurationRepository
{
    public function pageRows(array $groups,bool $messages=false):array{return [];}public function ensureInternalDefaults():void{}public function categories():array{return [];}public function fieldDictionary():array{return [];}public function settingsForGroup(string $group):array{return [];}public function saveMany(array $updates,int $adminId):void{}public function set(string $key,string $value,int $adminId):void{}public function userForOpenid(string $openid):int{return 0;}public function runtimeConfigRows():array{return [['setting_key'=>'wechat.admin_openids','setting_value'=>'["oyAoh123"]','value_type'=>'json'],['setting_key'=>'worker.phone_reveal_enabled','setting_value'=>'1','value_type'=>'bool']];}public function assertSendBoundary():void{}
}
final class FakeWorkerRuntimeConfigurationGatewayForWorker implements WorkerRuntimeConfigurationGateway{public array $last=[];public function sync(array $values):array{$this->last=$values;return ['version'=>'test-v1'];}}

$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException('Admin worker migration: '.$message);};
$expectFailure=static function(callable $callback,string $message)use($assert):void{try{$callback();}catch(RuntimeException $exception){$assert(strpos($exception->getMessage(),$message)!==false,'unexpected error: '.$exception->getMessage());return;}throw new RuntimeException('Admin worker migration: expected failure containing '.$message);};
$repo=new FakeAdminWorkerRepository();$cloud=new FakeWorkerCloudGateway();$runtimeGateway=new FakeWorkerRuntimeConfigurationGatewayForWorker();$runtime=new WorkerRuntimeConfigSync(new FakeAdminConfigurationRepositoryForWorker(),$runtimeGateway);$app=new WorkerAdmin($repo,$cloud,$runtime);
$valid=['display_name'=>'张师傅','worker_type'=>'墙布','mobile'=>'13800138000','lat'=>'30.1','lng'=>'120.2','img_list'=>"https://example.com/a.jpg\ncloud://bucket/b.jpg",'doEVA'=>'1','city'=>'杭州','district'=>'西湖','location_name'=>'小区','address'=>'1号楼'];
$expectFailure(static function()use($app,$valid):void{$app->create('bad-openid',$valid,7);},'真实 OpenID');$invalid=$valid;$invalid['lat']='100';$expectFailure(static function()use($app,$invalid):void{$app->create('oyAoh12345678901234567890',$invalid,7);},'地图纬度');$created=$app->create('oyAoh12345678901234567890',$valid,7);$assert($created['local_worker_id']===42&&$repo->projected[0][1]===7,'create must project with admin actor');$createPayload=$cloud->calls[count($cloud->calls)-1][1]['profile'];$assert(count($createPayload['imgList'])===2&&!empty($createPayload['skills']['doEVA']),'profile normalization changed');$expectFailure(static function()use($app):void{$app->review('cw1','rejected','a',7);},'必须填写原因');$app->review('cw1','approved','',7);$assert(count($repo->reviews)===1&&$repo->reviews[0][4]===7,'review history/actor must be retained');$expectFailure(static function()use($app):void{$app->delete('cw1','a');},'删除原因');$health=$app->cloudHealth();$assert(($health['runtime']['version']??'')==='test-v1'&&($health['stats']['approved']??0)===2,'cloud health must sync runtime config before health check');$list=$app->listing(1,'pending','张');$assert(count($list['result']['rows'])===1&&($list['result']['rows'][0]['local']['id']??0)===12,'cloud list must retain local enrichment');$cloud->failList=true;$list=$app->listing(1,'','');$assert($list['result']['rows']===[]&&$list['cloud_error']==='cloud unavailable','cloud list failure must degrade to readable empty page');$expectFailure(static function()use($app):void{$app->rejectLegacyMediaRoute();},'旧照片入口已停用');

$module=(string)file_get_contents($root.'/server/app/Http/Admin/Modules/WorkerAdminModule.php');$legacy=(string)file_get_contents($root.'/server/app/Http/Admin/Modules/LegacyAdminModule.php');$kernel=(string)file_get_contents($root.'/server/app/Http/Admin/AdminKernel.php');$persistence=(string)file_get_contents($root.'/server/app/Infrastructure/Persistence/DatabaseAdminWorkerRepository.php');$application=(string)file_get_contents($root.'/server/app/Application/Admin/WorkerAdmin.php');
$routes=['workers','workers.create','workers.update','workers.media.upload','workers.media.delete','workers.delete','workers.review','workers.cloud.sync'];foreach($routes as $route){$assert(strpos($module,"'{$route}'")!==false,'formal module missing '.$route);$assert(strpos($legacy,"'{$route}'")!==false,'anti-fallback missing '.$route);}
$assert(strpos($kernel,'new WorkerAdminModule($db,$auth,$csrf)')!==false,'AdminKernel did not register WorkerAdminModule');$assert(strpos($module,'SELECT ')===false&&strpos($module,'UPDATE ')===false&&strpos($module,'INSERT ')===false&&strpos($module,'DELETE FROM')===false,'Http worker module contains SQL');$assert(strpos($module,'WorkerAdmin\\Controllers\\')===false&&strpos($module,'WorkerAdmin\\Services\\')===false,'Http worker module depends on legacy layer');$assert(strpos($application,'WorkerAdmin\\Infrastructure\\')===false&&strpos($application,'WorkerAdmin\\Services\\')===false,'Application worker use case depends on concrete infrastructure/service');$assert(strpos($persistence,"mobile_hmac=:hmac")!==false&&strpos($persistence,"'user-mobile'")!==false,'admin projection lost mobile HMAC user reuse');$assert(strpos($persistence,'projectCloudWorker($userId, $openid, $projectionWorker)')!==false,'admin projection no longer reuses IdentityRepository');$assert(strpos($persistence,'verified_by=:verified_by')!==false&&strpos($persistence,"source='cloud_projection'")!==false,'admin reviewer/role provenance lost');$assert(strpos($persistence,'OrderNotificationService::queueWorkerReviewResult')!==false,'worker review reliable notification lost');$assert(strpos($persistence,"verification_status='deleted'")!==false&&strpos($persistence,"role_key IN ('worker','worker_pending')")!==false,'worker delete local deactivation changed');$assert(!is_file($root.'/server/app/Controllers/AdminController.php'),'retired AdminController was restored');

echo "Admin worker migration OK: 8 worker routes formally owned; cloud authority, projection provenance and review notification retained; retired AdminController absent.\n";
