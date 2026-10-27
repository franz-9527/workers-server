<?php

declare(strict_types=1);

use WorkerAdmin\Application\Order\OrderContactRevealRequest;
use WorkerAdmin\Contracts\OrderContactRevealRequestRepository;

$root = dirname(__DIR__, 2);
require_once $root.'/server/app/Contracts/OrderContactRevealRequestRepository.php';
require_once $root.'/server/app/Domain/Order/OrderOperationException.php';
require_once $root.'/server/app/Application/Order/OrderContactRevealRequest.php';

function contactAssert(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, '[FAIL] '.$label."\n");
        exit(1);
    }
    fwrite(STDOUT, '[PASS] '.$label."\n");
}

final class FakeContactRevealRequestRepository implements OrderContactRevealRequestRepository
{
    public array $result = [];
    public array $call = [];
    public function request(array $actor, string $orderNo, string $reason): array
    {
        $this->call = [$actor,$orderNo,$reason];
        return $this->result;
    }
}

$repo = new FakeContactRevealRequestRepository();
$app = new OrderContactRevealRequest($repo);
$repo->result = ['id'=>'CR1','status'=>'approved','auto_approved'=>true,'message'=>'联系电话已自动公开'];
$result = $app->request(['user_id'=>7], ['order_id'=>'OD-1','reason'=>'一直联系不上师傅']);
contactAssert(($repo->call[1] ?? '') === 'OD-1', '电话公开用例传递统一订单号');
contactAssert(($result['data']['auto_approved'] ?? false) === true, '正常超时场景支持自动批准');

$repo->result = ['id'=>'CR2','status'=>'pending','auto_approved'=>false,'message'=>'异常状态转平台审核'];
$result = $app->request(['user_id'=>7], ['order_id'=>'OD-2','reason'=>'订单存在争议']);
contactAssert(($result['data']['auto_approved'] ?? true) === false, '异常订单保持人工审核');

$repository = (string)file_get_contents($root.'/server/app/Infrastructure/Persistence/DatabaseOrderContactRevealRequestRepository.php');
$kernel = (string)file_get_contents($root.'/server/app/Http/Api/ApiKernel.php');
$legacy = (string)file_get_contents($root.'/server/app/Http/Api/Modules/LegacyApiModule.php');
$webModule = (string)file_get_contents($root.'/server/app/Http/Admin/Modules/ContactRevealReviewAdminModule.php');
$webView = (string)file_get_contents($root.'/server/views/admin/contact-reveal-review.php');
$adminKernel = (string)file_get_contents($root.'/server/app/Http/Admin/AdminKernel.php');
$workbenchView = (string)file_get_contents($root.'/server/views/admin/exception-dashboard.php');
$reviewPolicy = (string)file_get_contents($root.'/server/app/Domain/Order/ContactRevealReviewPolicy.php');

contactAssert(strpos($kernel, 'new OrderContactRevealApiModule($db)') !== false, '电话公开申请进入模块化 ApiKernel');
contactAssert(strpos($legacy, "'orders.worker-contact.request'") !== false, '电话公开申请从 Legacy 回退排除');
contactAssert(strpos($repository, "order_source_type'] ?? 'consumer') === 'merchant'") !== false, '商家订单保持电话隐私保护');
contactAssert(strpos($repository, 'hasAppointmentResponse') !== false, '师傅已有预约或联系响应时禁止自动公开');
contactAssert(strpos($repository, "['negotiating','open','processing','pending']") !== false, '争议状态进入人工审核');
contactAssert(strpos($repository, 'refund_status') !== false && strpos($repository, 'order_cancel_requests') !== false, '退款和取消状态进入人工审核');
contactAssert(strpos($repository, "status='approved'") !== false && strpos($repository, '自动批准') !== false, '正常场景直接写入自动批准事实');
contactAssert(strpos($repository, 'queueAdminTask') !== false, '只有异常分支保留管理员待办能力');

contactAssert(strpos($reviewPolicy, "['web_admin', 'mini_admin']") !== false, '电话公开审批继续使用显式 Web/小程序管理员 ID 域');
contactAssert(strpos($webModule, 'AdminWorkbenchReadComposition') !== false, 'Web 电话公开页从统一异常读模型确认任务仍有效');
contactAssert(strpos($webModule, 'AdminWorkbenchActionComposition') !== false, 'Web 电话公开审批复用统一 Application 写用例');
contactAssert(strpos($webModule, '[\'channel\'=>\'web_admin\',\'admin_user_id\'=>$adminId]') !== false, 'Web 电话公开审批传入真实 admin_users.id 域');
foreach (['SELECT ','INSERT ','UPDATE ','DELETE '] as $sqlToken) {
    contactAssert(strpos($webModule, $sqlToken) === false, 'Web 电话公开审批模块不得包含 SQL: '.trim($sqlToken));
}
contactAssert(strpos($webView, 'contact_reveal_approve') !== false && strpos($webView, 'contact_reveal_reject') !== false, 'Web 页面只提供统一异常任务允许的批准/拒绝动作');
contactAssert(strpos($webView, '服务端会再次校验成交师傅、履约状态、维修状态和师傅是否已经响应') !== false, 'Web 页面明确提交时仍执行服务端资格复核');
$contactModulePos = strpos($adminKernel, 'new ContactRevealReviewAdminModule(');
$legacyModulePos = strpos($adminKernel, 'new LegacyAdminModule(');
contactAssert($contactModulePos !== false && $legacyModulePos !== false && $contactModulePos < $legacyModulePos, 'Web 电话公开审批模块在 Legacy 前接管');
contactAssert(strpos($workbenchView, '$taskType === \'contact_reveal_review\'') !== false, '异常工作台识别电话公开人工审核任务');
contactAssert(strpos($workbenchView, "admin_url('contact-reveal-review'") !== false, '电话公开异常卡片直接进入专项审核页');

echo "\nV2.5.0 contact reveal automation regression: OK\n";
