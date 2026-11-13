<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Admin;

use RuntimeException;
use WorkerAdmin\Contracts\AdminAccountRepository;

final class AdminAccountSecurity
{
    private AdminAccountRepository $repository;

    public function __construct(AdminAccountRepository $repository)
    {
        $this->repository = $repository;
    }

    public function changePassword(int $adminId, string $currentPassword, string $newPassword, string $confirmation): void
    {
        if ($adminId < 1) throw new RuntimeException('管理员账号不存在。');
        $currentHash = $this->repository->passwordHash($adminId);
        if ($currentHash === null || !password_verify($currentPassword, $currentHash)) {
            throw new RuntimeException('当前密码不正确。');
        }
        if (
            strlen($newPassword) < 10
            || $newPassword !== $confirmation
            || preg_match('/[A-Za-z]/', $newPassword) !== 1
            || preg_match('/\d/', $newPassword) !== 1
        ) {
            throw new RuntimeException('新密码至少 10 位，需同时包含字母和数字，且两次输入一致。');
        }
        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost'=>12]);
        if (!is_string($hash) || $hash === '') throw new RuntimeException('新密码加密失败，请稍后重试。');
        $this->repository->replacePassword($adminId, $hash);
    }
}
