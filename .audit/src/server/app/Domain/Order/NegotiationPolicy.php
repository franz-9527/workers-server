<?php

declare(strict_types=1);

namespace WorkerAdmin\Domain\Order;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

// 2026-10-08：各类异常共用升级规则；拒绝和超时只开放申请，不自动生成平台待办。
final class NegotiationPolicy
{
    public const WAIT_HOURS = 48;

    public static function evaluate(string $status, ?string $openedAt, string $timezone = 'UTC', ?DateTimeImmutable $now = null): array
    {
        $result = ['eligible'=>false,'reason'=>'not_active','available_at'=>null,'wait_hours'=>self::WAIT_HOURS];
        if ($status === 'rejected') return array_merge($result, ['eligible'=>true,'reason'=>'counterparty_rejected']);
        if (!in_array($status, ['pending','accepted_processing'], true) || !$openedAt) return $result;
        try {
            $zone = new DateTimeZone($timezone ?: 'UTC');
            $available = (new DateTimeImmutable($openedAt, $zone))->modify('+'.self::WAIT_HOURS.' hours');
            $result['available_at'] = $available->format('Y-m-d H:i:s');
            $result['eligible'] = ($now ?: new DateTimeImmutable('now', $zone)) >= $available;
            $result['reason'] = $result['eligible'] ? 'response_timeout' : 'waiting_counterparty';
        } catch (Throwable $exception) {
            $result['reason'] = 'invalid_deadline';
        }
        return $result;
    }

    public static function refundStatus(string $reasonCode): string
    {
        return ['bilateral_refund_pending'=>'pending','bilateral_refund_rejected'=>'rejected',
            'bilateral_refund_escalated'=>'escalated','bilateral_refund_execution_required'=>'escalated'][$reasonCode] ?? '';
    }

    public static function disputeStatus(?string $resolution): string
    {
        return strpos((string)$resolution, '师傅拒绝异议') === 0 ? 'rejected' : 'pending';
    }
}
