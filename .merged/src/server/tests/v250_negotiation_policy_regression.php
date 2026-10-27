<?php

declare(strict_types=1);

use WorkerAdmin\Application\Order\OrderNegotiation;
use WorkerAdmin\Contracts\OrderNegotiationRepository;
use WorkerAdmin\Domain\Order\NegotiationPolicy;
use WorkerAdmin\Domain\Order\OrderOperationException;

require_once dirname(__DIR__).'/app/Contracts/OrderNegotiationRepository.php';
require_once dirname(__DIR__).'/app/Domain/Order/NegotiationPolicy.php';
require_once dirname(__DIR__).'/app/Domain/Order/OrderOperationException.php';
require_once dirname(__DIR__).'/app/Application/Order/OrderNegotiation.php';

function negotiationSame($actual, $expected, string $label): void
{
    if ($actual !== $expected) { fwrite(STDERR, '[FAIL] '.$label."\n"); exit(1); }
    echo '[PASS] '.$label."\n";
}

$now = new DateTimeImmutable('2026-10-08 12:00:00', new DateTimeZone('UTC'));
negotiationSame(NegotiationPolicy::evaluate('pending','2026-10-06 12:00:01','UTC',$now)['eligible'], false, '48小时前一秒不开放介入');
negotiationSame(NegotiationPolicy::evaluate('pending','2026-10-06 12:00:00','UTC',$now)['eligible'], true, '恰满48小时开放介入');
negotiationSame(NegotiationPolicy::evaluate('rejected','2026-10-08 12:00:00','UTC',$now)['eligible'], true, '对方拒绝立即开放申请');
foreach (['accepted','resolved','escalated','closed'] as $status) negotiationSame(NegotiationPolicy::evaluate($status,'2026-10-01 00:00:00','UTC',$now)['eligible'], false, $status.'不重复介入');
negotiationSame(NegotiationPolicy::evaluate('pending',null,'UTC',$now)['eligible'], false, '缺失期限不能猜测已超时');
negotiationSame(NegotiationPolicy::evaluate('pending','invalid','UTC',$now)['eligible'], false, '无效日期不开放介入');
negotiationSame(NegotiationPolicy::evaluate('pending','2026-10-06 20:00:00','Asia/Shanghai',$now)['eligible'], true, '当地时间与UTC同一边界');
negotiationSame(NegotiationPolicy::disputeStatus('师傅拒绝异议：无法处理'), 'rejected', '拒绝异议属于结构化拒绝');
negotiationSame(NegotiationPolicy::refundStatus('bilateral_refund_rejected'), 'rejected', '退款原生记录统一投影拒绝状态');

final class FakeNegotiationRepository implements OrderNegotiationRepository
{
    public int $escalateCalls = 0;

    public function respond(array $actor, string $orderNo, string $kind, string $reference, string $decision, string $note): array
    {
        return ['reference'=>$reference,'decision'=>$decision];
    }

    public function escalate(array $actor, string $orderNo, string $kind, string $reference, string $note): array
    {
        $this->escalateCalls++;
        return ['reference'=>$reference,'status'=>'escalated'];
    }

    public function proposeRefund(array $actor, string $orderNo, string $reference, int $amount, string $note): array
    {
        return ['reference'=>$reference,'status'=>'pending'];
    }

    public function withdrawRefund(array $actor, string $orderNo, string $reference, string $note): array
    {
        return ['reference'=>$reference,'status'=>'withdrawn'];
    }
}

$fake = new FakeNegotiationRepository();
$app = new OrderNegotiation($fake);
$repairRouteRejected = false;
try {
    $app->execute('exceptions.escalate', ['user_id'=>101], [
        'order_id'=>'OD-REPAIR-CI',
        'kind'=>'repair',
        'reference'=>'AS-REPAIR-CI',
        'note'=>'师傅已经拒绝维修，申请平台介入',
    ]);
} catch (OrderOperationException $exception) {
    $repairRouteRejected = $exception->apiCode() === 'REPAIR_INTERVENTION_ROUTE_REQUIRED';
}
negotiationSame($repairRouteRejected, true, '维修升级不能误走通用 active 生命周期协商入口');
negotiationSame($fake->escalateCalls, 0, '维修升级在 Application 层即转向专用维修介入语义');

$generic = $app->execute('exceptions.escalate', ['user_id'=>101], [
    'order_id'=>'OD-CHANGE-CI',
    'kind'=>'change',
    'reference'=>'CHG-CI',
    'note'=>'双方协商超过限制仍未达成一致',
]);
negotiationSame(($generic['data']['status'] ?? ''), 'escalated', '非维修异常仍复用通用协商升级入口');
negotiationSame($fake->escalateCalls, 1, '通用升级仓储调用次数正确');

echo "V2.5.0 bilateral negotiation policy: OK\n";
