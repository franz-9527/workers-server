<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Order;

use WorkerAdmin\Contracts\OrderRunEmptyRepository;
use WorkerAdmin\Domain\Order\OrderOperationException;

/**
 * 跑空费只负责“师傅提出 + 用户确认/拒绝”的业务入口。
 * 普通用户确认后从真实预约费中结算；拒绝后才升级平台争议。
 */
final class OrderRunEmpty
{
    private OrderRunEmptyRepository $repository;

    public function __construct(OrderRunEmptyRepository $repository)
    {
        $this->repository = $repository;
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $body @return array{message:string,data:array<string,mixed>} */
    public function submit(array $actor, array $body): array
    {
        $orderNo = trim((string)($body['order_id'] ?? ''));
        if ($orderNo === '' || strlen($orderNo) > 80) {
            throw new OrderOperationException('订单编号无效', 'ORDER_ID_REQUIRED', 422);
        }
        $amount = (int)($body['amount_cents'] ?? 0);
        if ($amount < 1 || $amount > 100000000) {
            throw new OrderOperationException('跑空费金额不正确', 'RUN_EMPTY_AMOUNT_INVALID', 422);
        }
        $reason = mb_substr(trim((string)($body['reason'] ?? '')), 0, 1000);
        if (mb_strlen($reason) < 4) {
            throw new OrderOperationException('请说明到场情况和协商结果', 'RUN_EMPTY_REASON_REQUIRED', 422);
        }
        $lat = is_numeric($body['lat'] ?? null) ? (float)$body['lat'] : null;
        $lng = is_numeric($body['lng'] ?? null) ? (float)$body['lng'] : null;
        if (!$this->validCoordinate($lat, $lng)) {
            throw new OrderOperationException('提交跑空费必须取得当前现场定位', 'RUN_EMPTY_LOCATION_REQUIRED', 422);
        }

        $data = $this->repository->submit($actor, [
            'order_id'=>$orderNo,
            'amount_cents'=>$amount,
            'reason'=>$reason,
            'lat'=>$lat,
            'lng'=>$lng,
        ]);
        $source = (string)($data['order_source_type'] ?? 'consumer');
        $message = $source === 'merchant'
            ? '跑空费已提交，请等待商家确认；商家同意后在线支付'
            : ($source === 'consumer'
                ? '跑空费已提交，请等待用户确认；用户同意后从已付预约费中结算，无需管理员审核'
                : '跑空费已提交平台处理');
        return ['message'=>$message,'data'=>$data];
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $body @return array{message:string,data:array<string,mixed>} */
    public function decide(array $actor, array $body): array
    {
        $claimId = trim((string)($body['claim_id'] ?? ''));
        if ($claimId === '' || strlen($claimId) > 80) {
            throw new OrderOperationException('跑空费记录不存在', 'RUN_EMPTY_CLAIM_REQUIRED', 422);
        }
        $decision = trim((string)($body['decision'] ?? ''));
        if (!in_array($decision, ['accepted','rejected'], true)) {
            throw new OrderOperationException('处理结果不正确', 'RUN_EMPTY_DECISION_INVALID', 422);
        }
        $note = mb_substr(trim((string)($body['note'] ?? '')), 0, 500);
        if ($decision === 'rejected' && mb_strlen($note) < 4) {
            throw new OrderOperationException('拒绝时请说明原因，便于双方和平台核对', 'RUN_EMPTY_REJECT_REASON_REQUIRED', 422);
        }

        $data = $this->repository->decide($actor, $claimId, $decision, $note);
        if (!empty($data['already_processed'])) {
            return ['message'=>'该跑空费已经处理','data'=>$data];
        }
        if ($decision === 'rejected') {
            return ['message'=>'你不同意跑空费，已申请平台介入处理','data'=>$data];
        }
        if (!empty($data['payment_required'])) {
            return ['message'=>'已确认跑空费，请完成在线支付','data'=>$data];
        }
        return ['message'=>'跑空费已从预约费中结算，剩余预约费已进入原路退款处理','data'=>$data];
    }

    private function validCoordinate(?float $lat, ?float $lng): bool
    {
        return $lat !== null && $lng !== null && is_finite($lat) && is_finite($lng)
            && $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180;
    }
}
