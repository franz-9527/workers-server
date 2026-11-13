<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Order;

use WorkerAdmin\Contracts\PlatformOrderCustomerShareRepository;
use WorkerAdmin\Domain\Order\OrderOperationException;

/**
 * 平台单客户查看/绑定用例。
 * 分享令牌只负责定位脱敏订单；绑定仍由仓储层校验预留手机号，不能凭分享卡片直接取得订单所有权。
 */
final class PlatformOrderCustomerShare
{
    private PlatformOrderCustomerShareRepository $repository;

    public function __construct(PlatformOrderCustomerShareRepository $repository)
    {
        $this->repository = $repository;
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function create(array $actor, string $orderKey, int $validDays = 30): array
    {
        if (empty($actor['is_admin'])) {
            throw new OrderOperationException('仅管理员可生成客户订单分享。', 'ADMIN_REQUIRED', 403);
        }
        $orderKey = trim($orderKey);
        if ($orderKey === '') {
            throw new OrderOperationException('平台订单不存在。', 'PLATFORM_ORDER_NOT_FOUND', 404);
        }
        return $this->repository->createShare(
            $orderKey,
            (int)($actor['real_user_id'] ?? $actor['user_id'] ?? 0),
            max(1, min(90, $validDays))
        );
    }

    /** @return array<string,mixed> */
    public function preview(string $token): array
    {
        $token = trim($token);
        if (preg_match('/^[A-Za-z0-9_-]{24,80}$/', $token) !== 1) {
            throw new OrderOperationException('订单分享已失效，请让平台重新分享。', 'PLATFORM_CUSTOMER_SHARE_INVALID', 404);
        }
        return $this->repository->preview($token);
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function claim(array $actor, string $token): array
    {
        if (!empty($actor['is_demo_actor'])) {
            throw new OrderOperationException('演示身份不能绑定真实平台订单。', 'DEMO_CLAIM_BLOCKED', 403);
        }
        $token = trim($token);
        if (preg_match('/^[A-Za-z0-9_-]{24,80}$/', $token) !== 1) {
            throw new OrderOperationException('订单分享已失效，请让平台重新分享。', 'PLATFORM_CUSTOMER_SHARE_INVALID', 404);
        }
        $userId = (int)($actor['user_id'] ?? 0);
        if ($userId < 1) throw new OrderOperationException('请先登录后再绑定订单。', 'UNAUTHORIZED', 401);
        return $this->repository->claim($token, $userId);
    }
}