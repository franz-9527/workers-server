<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Order;

use WorkerAdmin\Contracts\OrderInvitationRepository;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Domain\Order\OrderOperationException;

final class OrderInvitationClaim
{
    private OrderInvitationRepository $repository;
    private SettingsProvider $settings;

    public function __construct(OrderInvitationRepository $repository, SettingsProvider $settings)
    {
        $this->repository = $repository;
        $this->settings = $settings;
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function claim(array $actor, array $body): array
    {
        if ($this->settings->bool('development.pause_hall', false)) {
            throw new OrderOperationException('系统维护中，暂时停止领取新订单；进行中的订单仍可正常处理', 'HALL_PAUSED', 503);
        }
        $token = trim((string)($body['token'] ?? ''));
        if ($token === '' || strlen($token) > 512) {
            throw new OrderOperationException('邀请链接无效', 'INVALID_INVITE', 422);
        }
        $hours = max(1, min(72, $this->settings->int('order.assignment_accept_hours', 24)));
        return $this->repository->claim($actor, $token, $hours);
    }
}
