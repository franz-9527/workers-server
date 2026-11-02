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
    public function list(array $actor, int $page, string $throughId = ''): array
    {
        if(strlen($throughId)>80)throw new MessageOperationException('通知范围无效','NOTIFICATION_RANGE_INVALID');
        return $this->repository->list($actor,max(1,min(10000,$page)),trim($throughId));
    }
    private function threadRepository(string $cursor): \WorkerAdmin\Contracts\NotificationThreadRepository
    {
        if(strlen($cursor)>2048)throw new MessageOperationException('通知浏览范围无效','NOTIFICATION_RESYNC_REQUIRED',409);
        if(!$this->repository instanceof \WorkerAdmin\Contracts\NotificationThreadRepository)throw new MessageOperationException('通知分页暂不可用','NOTIFICATION_CURSOR_UNAVAILABLE',503);
        return $this->repository;
    }
    public function threads(array $actor,string $cursor=''): array
    {return $this->threadRepository($cursor)->threads($actor,$cursor);}
    public function threadMessages(array $actor,string $cursor): array
    {
        if($cursor==='')throw new MessageOperationException('请刷新通知列表','NOTIFICATION_RESYNC_REQUIRED',409);
        return $this->threadRepository($cursor)->threadMessages($actor,$cursor);
    }
    public function readRange(array $actor,string $cursor): void
    {
        if($cursor==='')throw new MessageOperationException('请刷新通知列表','NOTIFICATION_RESYNC_REQUIRED',409);
        $this->threadRepository($cursor)->readRange($actor,$cursor);
    }
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
    public function readAll(array $actor, string $throughId = ''): void
    {
        if (strlen($throughId)>80) throw new MessageOperationException('通知范围无效','NOTIFICATION_RANGE_INVALID');
        $this->repository->readAll($actor,trim($throughId));
    }
}
