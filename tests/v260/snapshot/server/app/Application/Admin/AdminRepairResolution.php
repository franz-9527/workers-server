<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Admin;

use WorkerAdmin\Contracts\AdminRepairResolutionRepository;
use WorkerAdmin\Domain\Order\OrderOperationException;

/** 平台介入后的维修裁定：继续维修、确认解决或关闭售后。 */
final class AdminRepairResolution
{
    private AdminRepairResolutionRepository $repository;

    public function __construct(AdminRepairResolutionRepository $repository)
    {
        $this->repository = $repository;
    }

    public function execute(array $operator, array $body): array
    {
        $channel = trim((string)($operator['channel'] ?? ''));
        $userId = (int)($operator['user_id'] ?? 0);
        if (!in_array($channel, ['mini_admin','web_admin'], true) || $userId < 1) {
            throw new OrderOperationException('管理员身份无效', 'ADMIN_REQUIRED', 403);
        }
        $orderNo = trim((string)($body['order_id'] ?? ''));
        $afterSaleNo = trim((string)($body['after_sale_id'] ?? ''));
        if ($orderNo === '' || strlen($orderNo) > 80) throw new OrderOperationException('订单编号无效', 'ORDER_ID_REQUIRED', 422);
        if ($afterSaleNo === '' || strlen($afterSaleNo) > 80) throw new OrderOperationException('售后编号无效', 'AFTER_SALE_ID_REQUIRED', 422);
        $decision = trim((string)($body['decision'] ?? ''));
        if (!in_array($decision, ['continue','resolved','closed'], true)) throw new OrderOperationException('平台售后处理动作无效', 'REPAIR_PLATFORM_DECISION_INVALID', 422);
        $note = mb_substr(trim((string)($body['note'] ?? '')), 0, 1000);
        if (mb_strlen($note) < 4) throw new OrderOperationException('请填写平台处理说明', 'REPAIR_PLATFORM_NOTE_REQUIRED', 422);
        $data = $this->repository->resolve(['channel'=>$channel,'user_id'=>$userId],$orderNo,$afterSaleNo,$decision,$note);
        $messages = ['continue'=>'已要求继续维修，平台介入事项保持处理中','resolved'=>'已由平台确认售后问题解决','closed'=>'已由平台关闭本次售后'];
        return ['message'=>$messages[$decision],'data'=>$data];
    }
}
