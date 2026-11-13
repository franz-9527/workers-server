<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Order;

use WorkerAdmin\Contracts\OrderNegotiationRepository;
use WorkerAdmin\Domain\Order\OrderOperationException;

// 2026-10-08：统一协商动作入口，业务事实仍归原有加价、取消、争议、维修和测量记录。
final class OrderNegotiation
{
    private OrderNegotiationRepository $repository;

    public function __construct(OrderNegotiationRepository $repository) { $this->repository = $repository; }

    public function execute(string $route, array $actor, array $body): array
    {
        $orderNo = trim((string)($body['order_id'] ?? ''));
        $kind = (string)($body['kind'] ?? ($route === 'after_sales.repair.respond' ? 'repair' : ''));
        $reference = trim((string)($body['reference'] ?? $body['after_sale_id'] ?? ''));
        $note = mb_substr(trim((string)($body['note'] ?? '')), 0, 1000);
        if ($orderNo === '' || strlen($orderNo) > 80 || $reference === '' || strlen($reference) > 80
            || !in_array($kind, ['refund','change','dispute','repair','measurement'], true)) {
            throw new OrderOperationException('协商事项无效，请刷新订单', 'NEGOTIATION_INVALID', 422);
        }
        if ($route === 'exceptions.escalate') {
            // 保修维修允许发生在已完成订单，必须走专用维修介入用例；
            // 不允许通用协商仓储先套 active 生命周期条件而误拒绝已完成订单。
            if ($kind === 'repair') {
                throw new OrderOperationException(
                    '维修平台介入请使用维修专用入口',
                    'REPAIR_INTERVENTION_ROUTE_REQUIRED',
                    422
                );
            }
            if (mb_strlen($note) < 4) throw new OrderOperationException('请说明协商未解决的情况', 'NEGOTIATION_NOTE_REQUIRED', 422);
            $data = $this->repository->escalate($actor, $orderNo, $kind, $reference, $note);
            return ['message'=>'已申请平台介入','data'=>$data];
        }
        if ($route === 'refunds.propose') {
            $amount = $body['amount_cents'] ?? null;
            if ($kind !== 'refund' || !is_int($amount) || $amount < 0 || $amount > 100000000 || mb_strlen($note) < 4) {
                throw new OrderOperationException('请填写整数分退款金额和方案说明', 'REFUND_PROPOSAL_INVALID', 422);
            }
            return ['message'=>'新退款方案已提交，等待对方接受或拒绝','data'=>$this->repository->proposeRefund($actor, $orderNo, $reference, $amount, $note)];
        }
        if ($route === 'refunds.withdraw') {
            if ($kind !== 'refund') {
                throw new OrderOperationException('只能撤回退款协商方案', 'REFUND_WITHDRAW_INVALID', 422);
            }
            return [
                'message'=>'退款方案已撤回，订单恢复继续履约',
                'data'=>$this->repository->withdrawRefund($actor, $orderNo, $reference, $note),
            ];
        }
        $decision = (string)($body['decision'] ?? $body['action'] ?? '');
        $decision = ['accept'=>'accepted','reject'=>'rejected','complete'=>'complete'][$decision] ?? $decision;
        if (!in_array($decision, ['accepted','rejected','complete'], true) || ($decision === 'rejected' && mb_strlen($note) < 4)) {
            throw new OrderOperationException('请选择接受或拒绝；拒绝时请说明原因', 'NEGOTIATION_RESPONSE_INVALID', 422);
        }
        $data = $this->repository->respond($actor, $orderNo, $kind, $reference, $decision, $note);
        return ['message'=>!empty($data['execution_requires_platform']) ? '双方已同意，资金需平台核账后执行'
            : ($kind === 'refund' && $decision === 'accepted' ? '方案已接受，已进入原路退款执行' : ($decision === 'rejected' ? '已拒绝，双方仍可继续协商或申请平台介入' : ($decision === 'complete' ? '维修已完成' : '已接受处理'))),'data'=>$data];
    }
}
