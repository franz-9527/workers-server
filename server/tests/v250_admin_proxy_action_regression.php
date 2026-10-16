<?php

declare(strict_types=1);

use WorkerAdmin\Application\Admin\AdminOrderProxyAction;
use WorkerAdmin\Contracts\AdminOrderProxyActionRepository;
use WorkerAdmin\Domain\Order\OrderOperationException;

$root = dirname(__DIR__, 2);
require_once $root.'/server/app/Contracts/AdminOrderProxyActionRepository.php';
require_once $root.'/server/app/Domain/Order/OrderOperationException.php';
require_once $root.'/server/app/Application/Admin/AdminOrderProxyAction.php';

function adminProxySame($actual, $expected, string $label): void
{
    if ($actual !== $expected) {
        fwrite(STDERR, '[FAIL] '.$label.' expected='.var_export($expected,true).' actual='.var_export($actual,true)."\n");
        exit(1);
    }
    fwrite(STDOUT, '[PASS] '.$label."\n");
}

final class FakeAdminOrderProxyActionRepository implements AdminOrderProxyActionRepository
{
    public array $calls = [];

    public function acceptAssignment(array $operator, string $orderNo, string $note): array
    {
        $this->calls[] = [$operator,$orderNo,$note];
        return ['order_id'=>$orderNo,'assignment_id'=>'ASG-CI','worker_id'=>66,'status'=>'accepted'];
    }
}

$repo = new FakeAdminOrderProxyActionRepository();
$app = new AdminOrderProxyAction($repo);

$acceptRemoved = false;
try {
    $app->execute(['channel'=>'mini_admin','user_id'=>901], [
        'order_id'=>'OD-PROXY-CI',
        'action'=>'assignment_accept',
        'note'=>'旧版管理员代师傅确认接单',
    ]);
} catch (OrderOperationException $exception) {
    $acceptRemoved = $exception->apiCode() === 'ADMIN_ASSIGNMENT_PROXY_REMOVED';
}
adminProxySame($acceptRemoved, true, 'assignment_accept 在 Application 层永久拒绝');
adminProxySame(count($repo->calls), 0, '代接单拒绝不会触碰持久化层');

$repairRemoved = false;
try {
    $app->execute(['channel'=>'mini_admin','user_id'=>901], [
        'order_id'=>'OD-PROXY-CI',
        'action'=>'repair_complete',
        'after_sale_id'=>'AS-CI',
        'note'=>'旧版管理员代师傅完成维修',
    ]);
} catch (OrderOperationException $exception) {
    $repairRemoved = $exception->apiCode() === 'ADMIN_REPAIR_PROXY_REMOVED';
}
adminProxySame($repairRemoved, true, 'repair_complete 在 Application 层永久拒绝');
adminProxySame(count($repo->calls), 0, '维修代理拒绝不会触碰持久化层');

$unknownRemoved = false;
try {
    $app->execute(['channel'=>'mini_admin','user_id'=>901], [
        'order_id'=>'OD-PROXY-CI',
        'action'=>'anything_else',
    ]);
} catch (OrderOperationException $exception) {
    $unknownRemoved = $exception->apiCode() === 'PROXY_ACTION_NOT_ALLOWED';
}
adminProxySame($unknownRemoved, true, '兼容代操作入口不再保留业务动作白名单');

$badAdmin = false;
try {
    $app->execute(['channel'=>'user','user_id'=>901], [
        'order_id'=>'OD-PROXY-CI',
        'action'=>'assignment_accept',
    ]);
} catch (OrderOperationException $exception) {
    $badAdmin = $exception->apiCode() === 'ADMIN_REQUIRED';
}
adminProxySame($badAdmin, true, '非 mini_admin 不能调用管理员代操作兼容入口');

$module = (string)file_get_contents($root.'/server/app/Http/Api/Modules/AdminOrderProxyApiModule.php');
$application = (string)file_get_contents($root.'/server/app/Application/Admin/AdminOrderProxyAction.php');
$repository = (string)file_get_contents($root.'/server/app/Infrastructure/Persistence/DatabaseAdminOrderProxyActionRepository.php');
$legacy = (string)file_get_contents($root.'/server/app/Http/Api/Modules/LegacyApiModule.php');
$kernel = (string)file_get_contents($root.'/server/app/Http/Api/ApiKernel.php');
$contentGate = (string)file_get_contents($root.'/server/app/Infrastructure/External/WeChatRequestContentSecurityGate.php');

adminProxySame(strpos($module, "\$route === 'admin.proxy.action'") !== false, true, '兼容模块完整接管 admin.proxy.action，避免回退旧入口');
adminProxySame(strpos($module, 'requireWorkbenchAdmin') !== false, true, '兼容模块继续执行小程序管理员鉴权');
adminProxySame(strpos($module, "return \$route !== 'admin.order.assistance.context';") !== false, true, '兼容模块和新协助写接口仍受内容安全门禁约束');
adminProxySame(strpos($contentGate, "'admin.proxy.action'=>2") !== false, true, '请求内容安全门禁仍登记 admin.proxy.action');
adminProxySame(strpos($application, 'ADMIN_ASSIGNMENT_PROXY_REMOVED') !== false, true, 'Application 明确废弃代确认接单');
adminProxySame(strpos($application, 'ADMIN_REPAIR_PROXY_REMOVED') !== false, true, 'Application 明确废弃维修代理完成');
adminProxySame(strpos($repository, 'after_sales') === false, true, '兼容 Repository 不得修改任何售后表');
adminProxySame(strpos($legacy, "'admin.proxy.action'") !== false, true, 'admin.proxy.action 已从 Legacy 回退排除');
adminProxySame(strpos($kernel, 'new AdminOrderProxyApiModule($db)') !== false, true, '兼容代操作模块已接入 ApiKernel');

echo "\nV2.5.0 admin proxy action regression: OK\n";

require_once $root.'/server/app/Contracts/AdminOrderAssistanceRepository.php';
require_once $root.'/server/app/Application/Admin/AdminOrderAssistance.php';
final class FakeAdminAssistanceRepository implements \WorkerAdmin\Contracts\AdminOrderAssistanceRepository
{
    public array $saved = [];
    public function context(string $orderNo): array { return ['assignment_id'=>'AS-1']; }
    public function save(array $operator, array $command): array { $this->saved[] = [$operator,$command]; return ['saved'=>true]; }
}
$facts = new FakeAdminAssistanceRepository();
$assistance = new \WorkerAdmin\Application\Admin\AdminOrderAssistance($facts);
$operator = ['channel'=>'mini_admin','user_id'=>901];
$input = ['order_id'=>'OD-1','assignment_id'=>'AS-1','action'=>'appointment','note'=>'微信沟通确认上门时间',
    'request_id'=>'assistance_test_000001','occurred_at'=>'2026-01-01 10:00:00','appointment_at'=>'2026-01-03 09:00:00'];
$assistance->execute('admin.order.assistance.save', $operator, $input + ['worker_id'=>999,'is_admin'=>true,'warranty_months'=>120,'amount_cents'=>1]);
adminProxySame($facts->saved[0][1]['occurred_at'], '2026-01-01 02:00:00', '实际沟通时间独立转换为 UTC');
adminProxySame($facts->saved[0][1]['appointment_at'], '2026-01-03 09:00:00', '约定上门时间独立传给原预约用例');
foreach (['worker_id','is_admin','warranty_months','amount_cents'] as $key) {
    adminProxySame(array_key_exists($key,$facts->saved[0][1]), false, '协助命令丢弃客户端字段 '.$key);
}
foreach (['assignment_accept','quote','completion_accept','repair_complete','change_accept','refund_accept','payment','warranty'] as $action) {
    $rejected = false;
    try { $assistance->execute('admin.order.assistance.save',$operator,array_replace($input,['action'=>$action])); }
    catch (OrderOperationException $exception) { $rejected = $exception->apiCode()==='ASSISTANCE_NOT_ALLOWED'; }
    adminProxySame($rejected,true,'协助入口不接受 '.$action);
}
foreach ([['occurred_at'=>'2026-02-30 10:00:00'],['occurred_at'=>'2999-01-01 10:00:00'],['appointment_at'=>'2026-02-30 10:00:00'],['context_type'=>'money'],['assignment_id'=>''],['request_id'=>'short'],['note'=>'']] as $invalid) {
    $rejected = false;
    try { $assistance->execute('admin.order.assistance.save',$operator,array_replace($input,$invalid)); }
    catch (OrderOperationException $exception) { $rejected = true; }
    adminProxySame($rejected,true,'协助补录拒绝无效输入 '.json_encode($invalid));
}
$assistance->execute('admin.order.assistance.save',$operator,array_replace($input,['wait_customer_notice'=>true,'appointment_at'=>'']));
adminProxySame($facts->saved[1][1]['wait_customer_notice'],true,'支持等待客户通知，不要求虚构上门时间');
$badAdmin = false;
try { $assistance->execute('admin.order.assistance.context',['channel'=>'worker','user_id'=>901],$input); }
catch (OrderOperationException $exception) { $badAdmin = $exception->apiCode()==='ADMIN_REQUIRED'; }
adminProxySame($badAdmin,true,'协助读接口同样要求管理员身份');
adminProxySame(count($facts->saved),2,'被拒绝的动作没有进入持久化层');
adminProxySame(strpos($repository,'UPDATE assignments')===false,true,'旧 Repository 删除接单推进 SQL');
adminProxySame(strpos($contentGate,"'admin.order.assistance.save'=>2")!==false,true,'协助说明进入内容安全门禁');

foreach (['Core/Database.php','Http/Api/ApiModule.php','Http/Api/Modules/OrderApiModuleSupport.php',
    'Http/Api/Modules/AdminWorkbenchModuleSupport.php','Foundation/OrderApplicationContainer.php',
    'Foundation/AdminOrderProxyComposition.php','Http/Api/Modules/AdminOrderProxyApiModule.php',
    'Http/Api/Modules/LegacyApiModule.php'] as $file) require_once $root.'/server/app/'.$file;
$httpModule = new \WorkerAdmin\Http\Api\Modules\AdminOrderProxyApiModule(new \WorkerAdmin\Core\Database([]));
$legacyModule = new \WorkerAdmin\Http\Api\Modules\LegacyApiModule(new \WorkerAdmin\Core\Database([]));
foreach (['admin.proxy.action','admin.order.assistance.context','admin.order.assistance.save'] as $route) {
    adminProxySame($httpModule->supports($route),true,'模块路由真实接管 '.$route);
    adminProxySame($legacyModule->supports($route),false,'路由不回退旧模块 '.$route);
}
adminProxySame($httpModule->requiresContentSecurity('admin.order.assistance.save'),true,'补录写接口实际启用内容安全');
adminProxySame($httpModule->requiresContentSecurity('admin.order.assistance.context'),false,'补录只读接口不运行提交检测');

echo "V2.5.0 admin assistance application regression: OK\n";
