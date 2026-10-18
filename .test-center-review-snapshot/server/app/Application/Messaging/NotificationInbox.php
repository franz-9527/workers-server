<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Messaging;

use WorkerAdmin\Contracts\NotificationInboxRepository;
use WorkerAdmin\Domain\Messaging\MessageOperationException;

final class NotificationInbox
{
    private NotificationInboxRepository $repository;
    public function __construct(NotificationInboxRepository $repository) { $this->repository = $repository; }
    public function summary(array $actor): array { return $this->repository->summary($actor); }
    public function list(array $actor, int $page): array { return $this->repository->list($actor, max(1, min(10000, $page))); }
    public function read(array $actor, string $id): void
    {
        $id = trim($id);
        if ($id === '') throw new MessageOperationException('请选择要查看的消息', 'NOTIFICATION_REQUIRED');
        $this->repository->read($actor, $id);
    }
    public function readBatch(array $actor, array $rawIds): array
    {
        if (count($rawIds) > 50) throw new MessageOperationException('单次最多标记 50 条消息', 'NOTIFICATION_BATCH_LIMIT');
        $ids = [];
        foreach ($rawIds as $value) {
            if (!is_scalar($value)) continue;
            $value = trim((string)$value);
            if ($value !== '' && !in_array($value, $ids, true)) $ids[] = $value;
        }
        if (!$ids) throw new MessageOperationException('请选择要查看的消息', 'NOTIFICATION_REQUIRED');
        return ['updated_count' => $this->repository->readBatch($actor, $ids)];
    }
    public function readAll(array $actor): void { $this->repository->readAll($actor); }
}
