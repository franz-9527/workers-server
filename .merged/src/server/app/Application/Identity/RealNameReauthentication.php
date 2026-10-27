<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Identity;

use RuntimeException;
use WorkerAdmin\Contracts\RealNameReauthenticationRepository;
use WorkerAdmin\Contracts\SettingsProvider;

/** 师傅重新实名认证：保留旧资料快照，并限制用户自主重新认证次数。 */
final class RealNameReauthentication
{
    private RealNameReauthenticationRepository $repository;
    private SettingsProvider $settings;

    public function __construct(RealNameReauthenticationRepository $repository, SettingsProvider $settings)
    {
        $this->repository = $repository;
        $this->settings = $settings;
    }

    public function policy(string $token): array
    {
        $actor = $this->actor($token);
        $limit = $this->limit();
        $policy = $this->repository->policy((int)$actor['worker_id']);
        $used = max(0, (int)($policy['self_reauth_used'] ?? 0));
        $status = (string)($policy['status'] ?? 'none');
        return [
            'status' => $status,
            'self_reauth_used' => $used,
            'self_reauth_limit' => $limit,
            'self_reauth_remaining' => max(0, $limit - $used),
            'can_self_reauth' => $status === 'approved' && $used < $limit,
            'requires_admin_revoke' => $status === 'approved' && $used >= $limit,
        ];
    }

    public function restart(string $token): array
    {
        $actor = $this->actor($token);
        return $this->repository->restartApproved((int)$actor['worker_id'], $this->limit());
    }

    public function revokeByAdmin(int $workerId, int $adminId, string $reason): array
    {
        $workerId = max(0, $workerId);
        $adminId = max(0, $adminId);
        $reason = mb_substr(trim($reason), 0, 500);
        if ($workerId < 1 || $adminId < 1) throw new RuntimeException('管理员或师傅身份不正确', 422);
        if (mb_strlen($reason) < 2) throw new RuntimeException('请填写取消实名的原因', 422);
        return $this->repository->revokeByAdmin($workerId, $adminId, $reason);
    }

    /** @return array<string,mixed> */
    private function actor(string $token): array
    {
        if (preg_match('/^[A-Fa-f0-9]{64}$/', $token) !== 1) throw new RuntimeException('请重新登录', 401);
        $actor = $this->repository->findActorByTokenHash(hash('sha256', $token));
        if (!$actor) throw new RuntimeException('登录已过期，请重新进入小程序', 401);
        $this->repository->touchSession((int)$actor['session_id']);
        if (empty($actor['worker_id'])) throw new RuntimeException('请先完成师傅身份建立', 422);
        return $actor;
    }

    private function limit(): int
    {
        return max(0, min(10, $this->settings->int('worker.realname_self_reauth_limit', 1)));
    }
}
