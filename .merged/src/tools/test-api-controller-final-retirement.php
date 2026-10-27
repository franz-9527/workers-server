<?php

declare(strict_types=1);

(static function (): void {
$root=dirname(__DIR__);
$assert=static function(bool $ok,string $message):void{
    if(!$ok)throw new RuntimeException('Final ApiController retirement: '.$message);
};

$assert(!is_file($root.'/server/app/Controllers/ApiController.php'),'retired ApiController.php must stay deleted');
$kernel=(string)file_get_contents($root.'/server/app/Http/Api/ApiKernel.php');
$legacy=(string)file_get_contents($root.'/server/app/Http/Api/Modules/LegacyApiModule.php');
foreach([
    'BootstrapApiModule','MerchantAuthApiModule','MiniAdminMoneyApiModule','MiniAdminOrderApiModule','MiniAdminDemoApiModule'
] as $module)$assert(strpos($kernel,'new '.$module.'($db)')!==false,'ApiKernel missing '.$module);
$assert(strpos($legacy,'WorkerAdmin\\Controllers\\ApiController')===false,'LegacyApiModule still references retired controller');
foreach(['bootstrap','merchant.profile','admin.withdrawals','admin.withdrawal.review','admin.order.action','admin.order.quote','admin.order.share','admin.demo.status','admin.demo.switch'] as $route){
    $assert(strpos($legacy,"'".$route."'")!==false,'anti-fallback registry missing '.$route);
}

$money=(string)file_get_contents($root.'/server/app/Infrastructure/Persistence/DatabaseMiniAdminMoneyRepository.php');
$assert(strpos($money,"'withdrawal_release'")!==false,'withdrawal reject must release frozen balance');
$assert(strpos($money,'queueWorkerWithdrawalResult')!==false,'withdrawal result notification lost');
$assert(strpos($money,'withdrawal_events')!==false,'withdrawal audit transition lost');

$order=(string)file_get_contents($root.'/server/app/Infrastructure/Persistence/DatabaseMiniAdminOrderRepository.php');
foreach(['order_cancel_requests','order_cancel_decisions','refunds','quote_versions','invite_tokens','commission_rule_versions'] as $token){
    $assert(strpos($order,$token)!==false,'mini admin order persistence lost '.$token);
}
$assert(strpos($order,'WeChatPayService')!==false,'audit reject automatic refund bridge lost');
$assert(strpos($order,'MiniProgramCodeService')!==false,'admin share mini-program code generation lost');
$assert(strpos($order,'OrderNotificationService::queue')!==false,'admin order event notification outbox lost');

$bootstrap=(string)file_get_contents($root.'/server/app/Application/Identity/BootstrapReadModel.php');
$bootstrapRepo=(string)file_get_contents($root.'/server/app/Infrastructure/Persistence/DatabaseBootstrapRepository.php');
$assert(strpos($bootstrap,"server.owner.get")!==false&&strpos($bootstrap,'projectCloudWorker')!==false,'bootstrap cloud worker projection refresh lost');
foreach(['customer_todo','worker_in_progress','commission_receivables'] as $token)$assert(strpos($bootstrapRepo,$token)!==false,'bootstrap read model lost '.$token);

$merchant=(string)file_get_contents($root.'/server/app/Infrastructure/Persistence/DatabaseMerchantProfileRepository.php');
$assert(strpos($merchant,"'entity_type'=>'merchant_profile'")!==false,'merchant license private-media scope lost');
$demo=(string)file_get_contents($root.'/server/app/Infrastructure/Persistence/DatabaseMiniAdminDemoRepository.php');
$assert(strpos($demo,'demo_actor_user_id')!==false,'demo session switch persistence lost');

$moneyModule=(string)file_get_contents($root.'/server/app/Http/Api/Modules/MiniAdminMoneyApiModule.php');
$orderModule=(string)file_get_contents($root.'/server/app/Http/Api/Modules/MiniAdminOrderApiModule.php');
$assert(strpos($moneyModule,'DEMO_ADMIN_ACTION_BLOCKED')!==false,'admin money demo guard lost');
$assert(strpos($orderModule,'DEMO_ADMIN_ACTION_BLOCKED')!==false,'admin order demo guard lost');

echo "Final ApiController retirement guards passed.\n";
})();
