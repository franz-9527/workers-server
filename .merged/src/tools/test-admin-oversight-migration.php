<?php

declare(strict_types=1);

$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'WorkerAdmin\\';
    if (strpos($class, $prefix) !== 0) return;
    $file = $root.'/server/app/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    if (is_file($file)) require_once $file;
});

final class FakeAdminOversightRepository implements \WorkerAdmin\Contracts\AdminOversightRepository
{
    public array $calls = [];
    public function disputes(int $page, bool $includeDemo): array{$this->calls[]=['disputes',$page,$includeDemo];return ['rows'=>[['id'=>1,'dispute_no'=>'DP1']],'total'=>1,'page'=>$page,'pages'=>1,'per_page'=>20];}
    public function audit(int $page, string $keyword, bool $includeDemo): array{$this->calls[]=['audit',$page,$keyword,$includeDemo];return ['rows'=>[['id'=>2,'action'=>'x']],'total'=>1,'page'=>$page,'pages'=>1,'per_page'=>20];}
}

$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException('Admin oversight migration: '.$message);};
$repo=new FakeAdminOversightRepository();$app=new \WorkerAdmin\Application\Admin\AdminOversightQuery($repo);$disputes=$app->disputes(0,false);$assert($disputes['page']===1&&$repo->calls[0]===['disputes',1,false],'dispute page normalization changed');$audit=$app->audit(2,'  退款  ',true);$assert($audit['page']===2&&$repo->calls[1]===['audit',2,'退款',true],'audit query normalization changed');
$disputeModule=(string)file_get_contents($root.'/server/app/Http/Admin/Modules/DisputeReviewAdminModule.php');$auditModule=(string)file_get_contents($root.'/server/app/Http/Admin/Modules/AuditAdminModule.php');$legacy=(string)file_get_contents($root.'/server/app/Http/Admin/Modules/LegacyAdminModule.php');$kernel=(string)file_get_contents($root.'/server/app/Http/Admin/AdminKernel.php');$application=(string)file_get_contents($root.'/server/app/Application/Admin/AdminOversightQuery.php');$persistence=(string)file_get_contents($root.'/server/app/Infrastructure/Persistence/DatabaseAdminOversightRepository.php');
$assert(strpos($disputeModule,"'disputes'")!==false,'DisputeReviewAdminModule does not own disputes list');$assert(strpos($auditModule,"return \$route === 'audit';")!==false,'AuditAdminModule does not own audit route');foreach(['disputes','disputes.resolve','dispute-review','dispute-review.resolve','audit'] as $route)$assert(strpos($legacy,"'{$route}'")!==false,'anti-fallback missing '.$route);$assert(strpos($kernel,'new DisputeReviewAdminModule($db, $auth, $csrf)')!==false,'dispute module not registered');$assert(strpos($kernel,'new AuditAdminModule($db, $auth, $csrf)')!==false,'audit module not registered');foreach([$disputeModule,$auditModule] as $source){$assert(strpos($source,'SELECT ')===false&&strpos($source,'UPDATE ')===false&&strpos($source,'INSERT ')===false&&strpos($source,'DELETE FROM')===false,'oversight Http module contains SQL');$assert(strpos($source,'WorkerAdmin\\Controllers\\')===false&&strpos($source,'WorkerAdmin\\Services\\')===false,'oversight Http module depends on legacy layer');}$assert(strpos($application,'WorkerAdmin\\Infrastructure\\')===false&&strpos($application,'WorkerAdmin\\Services\\')===false,'oversight Application depends on implementation layer');$assert(strpos($persistence,'FROM disputes d LEFT JOIN orders o')!==false,'dispute list query changed');$assert(strpos($persistence,"o.public_no NOT LIKE :demo_public_no")!==false,'dispute demo filter changed');$assert(strpos($persistence,"LEFT JOIN admin_users a ON l.actor_type='admin'")!==false,'audit actor join changed');$assert(strpos($persistence,"l.request_id NOT LIKE 'DEMO-%'")!==false,'audit demo filter changed');foreach([':q_action',':q_entity',':q_reason',':q_request',':q_actor'] as $parameter)$assert(strpos($persistence,$parameter)!==false,'audit native PDO search parameter missing '.$parameter);$assert(strpos($disputeModule,"'admin.dispute.resolve'")!==false,'existing OrderDispute resolution path was replaced');$assert(!is_file($root.'/server/app/Controllers/AdminController.php'),'retired AdminController was restored');
echo "Admin oversight migration OK: disputes/audit formally owned; OrderDispute resolution retained; retired AdminController absent.\n";
