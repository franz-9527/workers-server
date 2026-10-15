<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Admin;

use WorkerAdmin\Contracts\AdminWorkbenchOperationsRepository;
use WorkerAdmin\Domain\Order\OrderOperationException;

final class AdminWorkbenchOperations
{
    private AdminWorkbenchOperationsRepository $repository;

    public function __construct(AdminWorkbenchOperationsRepository $repository)
    {
        $this->repository = $repository;
    }

    public function reassignWorker(array $actor, array $input): array
    {
        $orderNo = trim((string)($input['order_id'] ?? ''));
        $workerId = (int)($input['worker_id'] ?? 0);
        $reason = mb_substr(trim((string)($input['reason'] ?? '')), 0, 500);
        if ($orderNo === '' || $workerId < 1) throw new OrderOperationException('请选择订单和新师傅', 'ADMIN_REASSIGN_INPUT_REQUIRED', 422);
        if (mb_strlen($reason) < 2) throw new OrderOperationException('请填写改派原因', 'ADMIN_REASSIGN_REASON_REQUIRED', 422);
        return $this->repository->reassignWorker($actor, $orderNo, $workerId, $reason);
    }

    public function interveneRepair(array $actor, array $input): array
    {
        $orderNo = trim((string)($input['order_id'] ?? ''));
        $afterSaleNo = trim((string)($input['after_sale_id'] ?? ''));
        $reason = mb_substr(trim((string)($input['reason'] ?? '')), 0, 500);
        if ($orderNo === '' || $afterSaleNo === '') throw new OrderOperationException('缺少订单或售后编号', 'ADMIN_REPAIR_INTERVENTION_INPUT_REQUIRED', 422);
        if (mb_strlen($reason) < 2) throw new OrderOperationException('请填写平台介入原因', 'ADMIN_REPAIR_INTERVENTION_REASON_REQUIRED', 422);
        return $this->repository->interveneRepair($actor, $orderNo, $afterSaleNo, $reason);
    }

    public function retryPlatformAutoPublish(array $actor, array $input): array
    {
        $orderNo = trim((string)($input['order_id'] ?? ''));
        $sourceId = trim((string)($input['source_id'] ?? ''));
        if ($orderNo === '') throw new OrderOperationException('缺少订单编号', 'ORDER_ID_REQUIRED', 422);
        return $this->repository->retryPlatformAutoPublish($actor, $orderNo, $sourceId);
    }

    public function addInternalFollowup(array $actor, array $input): array
    {
        $orderNo = trim((string)($input['order_id'] ?? ''));
        $note = mb_substr(trim((string)($input['note'] ?? '')), 0, 1000);
        $followUpAt = trim((string)($input['follow_up_at'] ?? ''));
        if ($orderNo === '') throw new OrderOperationException('缺少订单编号', 'ORDER_ID_REQUIRED', 422);
        if ($note === '') throw new OrderOperationException('请填写内部跟进内容', 'ADMIN_FOLLOWUP_NOTE_REQUIRED', 422);
        return $this->repository->addInternalFollowup($actor, $orderNo, $note, $followUpAt !== '' ? $followUpAt : null);
    }

    public function completeInternalFollowup(array $actor, array $input): array
    {
        $orderNo = trim((string)($input['order_id'] ?? ''));
        $eventId = (int)($input['followup_event_id'] ?? 0);
        $note = mb_substr(trim((string)($input['note'] ?? '')), 0, 500);
        if ($orderNo === '' || $eventId < 1) throw new OrderOperationException('缺少待完成的跟进事项', 'ADMIN_FOLLOWUP_REQUIRED', 422);
        return $this->repository->completeInternalFollowup($actor, $orderNo, $eventId, $note);
    }
}
