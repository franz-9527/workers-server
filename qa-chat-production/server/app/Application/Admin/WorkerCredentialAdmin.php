<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Admin;

use RuntimeException;
use WorkerAdmin\Contracts\WorkerCredentialAdminRepository;

final class WorkerCredentialAdmin
{
    private WorkerCredentialAdminRepository $repository;

    public function __construct(WorkerCredentialAdminRepository $repository)
    {
        $this->repository = $repository;
    }

    /** @return array<string,mixed> */
    public function page(string $search, string $status, int $page): array
    {
        $search = mb_substr(trim($search), 0, 100);
        $status = trim($status);
        if ($status !== '' && !in_array($status, ['realname_pending','code_pending','payout_missing'], true)) $status = '';
        return $this->repository->page($search, $status, max(1, $page), 20);
    }

    public function reviewRealName(int $workerId, string $decision, string $reason, int $adminId): void
    {
        $decision = trim($decision);
        $reason = mb_substr(trim($reason), 0, 500);
        if ($workerId < 1 || !in_array($decision, ['approved','rejected'], true)) {
            throw new RuntimeException('实名认证审核结果无效。');
        }
        if ($decision === 'rejected' && mb_strlen($reason) < 2) {
            throw new RuntimeException('驳回实名认证时请填写原因。');
        }
        $this->repository->reviewRealName($workerId, $decision, $reason, $adminId);
    }

    public function updateRealName(int $workerId, string $realName, string $idNumber): void
    {
        $realName = mb_substr(trim($realName), 0, 80);
        $idNumber = strtoupper((string)preg_replace('/\s+/', '', $idNumber));
        if ($workerId < 1 || mb_strlen($realName) < 2 || preg_match('/^\d{17}[0-9X]$/', $idNumber) !== 1) {
            throw new RuntimeException('请填写真实姓名和有效的 18 位身份证号。');
        }
        $this->repository->updateRealName($workerId, $realName, $idNumber);
    }

    public function reviewCollectionCode(int $id, string $decision, string $reason, int $adminId): void
    {
        $decision = trim($decision);
        $reason = mb_substr(trim($reason), 0, 500);
        if ($id < 1 || !in_array($decision, ['approved','rejected'], true)) {
            throw new RuntimeException('请选择有效的收款码审核结果。');
        }
        if ($decision === 'rejected' && $reason === '') {
            throw new RuntimeException('驳回收款码时必须填写原因。');
        }
        $this->repository->reviewCollectionCode($id, $decision, $reason, $adminId);
    }
}
