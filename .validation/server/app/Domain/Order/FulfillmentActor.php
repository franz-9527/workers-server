<?php

declare(strict_types=1);

namespace WorkerAdmin\Domain\Order;

/** 领域事件的真实经办来源；协助字段只由已鉴权且重验指派的管理员用例构造。 */
final class FulfillmentActor
{
    public static function assisted(array $actor): bool
    {
        return (int)($actor['user_id'] ?? 0)>0 && (int)($actor['worker_id'] ?? 0)>0
            && (int)($actor['assistance_operator_id'] ?? 0)===(int)$actor['user_id']
            && trim((string)($actor['assistance_assignment_id'] ?? ''))!=='';
    }

    public static function type(array $actor): string { return self::assisted($actor) ? 'mini_admin' : 'worker'; }
    public static function id(array $actor): int { return (int)($actor[self::assisted($actor) ? 'user_id' : 'worker_id'] ?? 0); }

    public static function metadata(array $actor): array
    {
        return !self::assisted($actor) ? [] : [
            'assisted'=>true,'operator_channel'=>'mini_admin','operator_user_id'=>(int)$actor['user_id'],
            'worker_id'=>(int)$actor['worker_id'],'assignment_id'=>(string)$actor['assistance_assignment_id'],
            'occurred_at'=>(string)($actor['assistance_occurred_at'] ?? ''),
        ];
    }
}