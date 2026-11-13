<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Admin;

use WorkerAdmin\Contracts\AgreedRefundExecutionRepository;
use WorkerAdmin\Domain\Order\OrderOperationException;

/** 双方已接受退款方案后的 Web 财务执行用例；不允许重新否定双方协议。 */
final class AgreedRefundExecution
{
    private AgreedRefundExecutionRepository $repository;

    public function __construct(AgreedRefundExecutionRepository $repository)
    {
        $this->repository = $repository;
    }

    /** @return array<int,array<string,mixed>> */
    public function cases(): array
    {
        return $this->repository->cases();
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function prepare(array $input, int $adminId): array
    {
        if ($adminId < 1) throw new OrderOperationException('管理员身份无效', 'ADMIN_REQUIRED', 403);
        $requestNo = trim((string)($input['request_id'] ?? ''));
        if ($requestNo === '') throw new OrderOperationException('退款协议记录不存在', 'AGREED_REFUND_REQUEST_REQUIRED', 422);
        $note = mb_substr(trim((string)($input['note'] ?? '')), 0, 1000);
        if (mb_strlen($note) < 4) {
            throw new OrderOperationException('请填写本次资金核账说明（至少4字）', 'AGREED_REFUND_EXECUTION_NOTE_REQUIRED', 422);
        }
        $result = $this->repository->prepare($requestNo, $note, $adminId);
        // Repository 只负责原子核账并生成 created 退款；真正调用微信由可靠退款桥在事务外执行。
        // 因此这一步已经是最后一次人工确认，不再要求管理员去退款管理重复点击。
        $result['requires_manual_submit'] = false;
        $result['auto_submit_queued'] = true;
        return $result;
    }
}
