<?php

declare(strict_types=1);

namespace WorkerAdmin\Domain\Order;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/** 售后维修先由双方处理；师傅拒绝或超时后才允许升级平台。 */
final class RepairInterventionPolicy
{
    public const WAIT_HOURS = 48;

    /** @return array{eligible:bool,reason:string,available_at:?string,wait_hours:int} */
    public static function evaluate(
        string $status,
        ?string $resolution,
        ?string $openedAt,
        string $timezone = 'UTC',
        ?DateTimeImmutable $now = null
    ): array {
        $status = trim($status);
        $resolution = trim((string)$resolution);
        $openedAt = trim((string)$openedAt);
        $result = ['eligible'=>false,'reason'=>'not_active','available_at'=>null,'wait_hours'=>self::WAIT_HOURS];

        if (!in_array($status, ['open','processing'], true)) return $result;
        if (strpos($resolution, '用户申请平台介入') === 0) {
            $result['reason'] = 'already_escalated';
            return $result;
        }
        if (strpos($resolution, '师傅拒绝维修') === 0) {
            $result['eligible'] = true;
            $result['reason'] = 'worker_rejected';
            return $result;
        }
        if ($openedAt === '') {
            $result['reason'] = 'opened_at_missing';
            return $result;
        }

        try {
            $zone = new DateTimeZone($timezone !== '' ? $timezone : 'UTC');
            $opened = new DateTimeImmutable($openedAt, $zone);
            $available = $opened->modify('+'.self::WAIT_HOURS.' hours');
            $current = $now ?: new DateTimeImmutable('now', $zone);
            $result['available_at'] = $available->format('Y-m-d H:i');
            $result['eligible'] = $current >= $available;
            $result['reason'] = $result['eligible'] ? 'response_timeout' : 'waiting_worker';
            return $result;
        } catch (Throwable $exception) {
            $result['reason'] = 'opened_at_invalid';
            return $result;
        }
    }
}