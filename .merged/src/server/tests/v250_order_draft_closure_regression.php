<?php

declare(strict_types=1);

use WorkerAdmin\Application\Order\OrderDraftClosure;
use WorkerAdmin\Contracts\OrderDraftClosureRepository;
use WorkerAdmin\Domain\Order\OrderOperationException;

$root = dirname(__DIR__);
require_once $root.'/app/Contracts/OrderDraftClosureRepository.php';
require_once $root.'/app/Domain/Order/OrderOperationException.php';
require_once $root.'/app/Application/Order/OrderDraftClosure.php';

function draftCloseAssert(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, '[FAIL] '.$label."\n");
        exit(1);
    }
    fwrite(STDOUT, '[PASS] '.$label."\n");
}

final class FakeDraftClosureRepository implements OrderDraftClosureRepository
{
    /** @var array<int,array{actor:array<string,mixed>,order_no:string}> */
    public array $calls = [];
    /** @var array<string,mixed> */
    public array $result = ['order_id' => 'BXTEST001', 'already_closed' => false];

    public function close(array $actor, string $orderNo): array
    {
        $this->calls[] = ['actor' => $actor, 'order_no' => $orderNo];
        return $this->result;
    }
}

$repo = new FakeDraftClosureRepository();
$app = new OrderDraftClosure($repo);
$actor = ['user_id' => 18];
$result = $app->close($actor, ['order_id' => '  BXTEST001  ']);
draftCloseAssert(count($repo->calls) === 1, 'Application 仅调用一次关闭 Repository');
draftCloseAssert($repo->calls[0]['order_no'] === 'BXTEST001', '订单编号会 trim 后提交');
draftCloseAssert(($result['message'] ?? '') === '未付款订单已关闭', '首次关闭返回明确成功文案');

$repo->result = ['order_id' => 'BXTEST001', 'already_closed' => true];
$result = $app->close($actor, ['order_id' => 'BXTEST001']);
draftCloseAssert(($result['message'] ?? '') === '该未付款订单已关闭', '重复关闭保持幂等文案');

$invalidRejected = false;
try {
    $app->close($actor, ['order_id' => '']);
} catch (OrderOperationException $exception) {
    $invalidRejected = $exception->apiCode() === 'ORDER_ID_REQUIRED';
}
draftCloseAssert($invalidRejected, '空订单号在 Application 层稳定拒绝');

$repoSource = file_get_contents($root.'/app/Infrastructure/Persistence/DatabaseOrderDraftClosureRepository.php') ?: '';
$paymentSource = file_get_contents($root.'/app/Infrastructure/Persistence/DatabasePaymentCreationRepository.php') ?: '';
$detailSource = file_get_contents($root.'/app/Application/Order/OrderDetailQuery.php') ?: '';
$centerSource = file_get_contents($root.'/app/Application/Order/OrderCenterQuery.php') ?: '';
$apiSource = file_get_contents($root.'/app/Http/Api/Modules/OrderDraftClosureApiModule.php') ?: '';
$kernelSource = file_get_contents($root.'/app/Http/Api/ApiKernel.php') ?: '';

$closeLock = strpos($repoSource, 'SELECT * FROM orders WHERE BINARY public_no=BINARY :public_no FOR UPDATE');
$payLock = strpos($paymentSource, 'SELECT * FROM orders WHERE public_no=:public_no AND user_id=:user_id LIMIT 1 FOR UPDATE');
draftCloseAssert($closeLock !== false, '关闭草稿先锁 orders 行');
draftCloseAssert($payLock !== false, '预约费创建同样先锁 orders 行，关闭/支付串行化');
draftCloseAssert(strpos($repoSource, "status IN ('paid','refunded')") !== false, '关闭前检查已到账/已退款支付事实');
draftCloseAssert(strpos($repoSource, "status IN ('created','pending')") !== false, '关闭前检查仍有效的在途支付单');
draftCloseAssert(strpos($repoSource, 'DRAFT_CLOSE_PAYMENT_PENDING') !== false, '在途支付返回稳定冲突码，不冒险关闭');
draftCloseAssert(strpos($repoSource, 'DRAFT_CLOSE_PAYMENT_ALREADY_PAID') !== false, '已到账返回稳定冲突码并要求转退款流程');
draftCloseAssert(strpos($repoSource, 'expires_at<=UTC_TIMESTAMP()') !== false, '仅已过期 created/pending 支付单允许先落 closed');
draftCloseAssert(strpos($repoSource, "lifecycle_status='cancelled'") !== false && strpos($repoSource, "publish_status='hidden'") !== false, '关闭只终止草稿订单并隐藏发布事实');
draftCloseAssert(strpos($repoSource, "event_type,event_key") !== false && strpos($repoSource, "'draft_closed'") !== false, '关闭草稿写入独立审计事件');
draftCloseAssert(stripos($repoSource, 'INSERT INTO refunds') === false, '未付款草稿关闭不得创建退款记录');
draftCloseAssert(strpos($repoSource, 'order_cancel_requests') === false, '未付款草稿关闭不得创建已付款取消申请');
draftCloseAssert(strpos($repoSource, 'captureCancellation(') === false && strpos($repoSource, 'OrderCancellationRisk') === false, '未付款草稿关闭不计入频繁取消风控');
draftCloseAssert(stripos($repoSource, 'DELETE FROM orders') === false, '关闭保留订单历史，不物理删除');
draftCloseAssert(strpos($repoSource, "order_source_type'] ?? 'consumer') === 'platform'") !== false, '平台承接单不混入普通未付款关闭');

draftCloseAssert(strpos($detailSource, "['can_close_draft']") !== false, 'owner 详情暴露独立 can_close_draft action');
draftCloseAssert(strpos($detailSource, '!$selection&&!$hasSelectedQuote&&!$assignedWorker') !== false, '详情 action 不对已有成交关系的订单开放');
draftCloseAssert(strpos($centerSource, "'can_close_draft' => \$canCloseDraft") !== false, '订单中心卡片暴露关闭资格');
draftCloseAssert(strpos($centerSource, "['unpaid','awaiting_payment']") !== false || strpos($centerSource, "['unpaid', 'awaiting_payment']") !== false, '管理员代填未 finalize 的 unpaid 草稿同样显示支付/关闭语义');
draftCloseAssert(strpos($apiSource, "return \$route === 'orders.draft.close';") !== false, '独立 orders.draft.close API 已注册');
draftCloseAssert(strpos($kernelSource, 'new OrderDraftClosureApiModule($db)') !== false, 'ApiKernel 已装配草稿关闭模块');

fwrite(STDOUT, "V2.5.0 unpaid draft closure regression OK\n");