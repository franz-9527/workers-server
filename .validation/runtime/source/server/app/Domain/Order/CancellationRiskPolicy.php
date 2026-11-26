<?php

declare(strict_types=1);

namespace WorkerAdmin\Domain\Order;

/** 2026-10-08：同单最高分、不同订单数门槛与固定七天限制；不依赖数据库或通知。 */
final class CancellationRiskPolicy
{
    public const DEFAULTS = ['quote'=>2, 'contact'=>4, 'deal'=>5, 'complaint'=>6, 'run_empty'=>6,
        'short_days'=>7, 'short_orders'=>2, 'short_score'=>6, 'long_days'=>30, 'long_orders'=>3, 'long_score'=>10];

    public static function config(array $input): array
    {
        $result = self::DEFAULTS;
        foreach ($result as $key=>$default) {
            if (isset($input[$key]) && is_numeric($input[$key])) {
                $max = strpos($key, 'days') !== false ? 90 : 100;
                $minimum = strpos($key, 'orders') !== false ? 2 : 1;
                $result[$key] = max($minimum, min($max, (int)$input[$key]));
            }
        }
        $result['long_days'] = max($result['short_days'], $result['long_days']);
        return $result;
    }

    public static function score(bool $quoted, bool $dealt, array $signals, bool $runEmpty, array $config): int
    {
        $points = max($quoted ? $config['quote'] : 0, $dealt ? $config['deal'] : 0, $runEmpty ? $config['run_empty'] : 0);
        if (in_array('dialed', $signals, true) || in_array('contacted', $signals, true)) $points = max($points, $config['contact']);
        if (in_array('complaint', $signals, true)) $points = max($points, $config['complaint']);
        return $points;
    }

    public static function threshold(array $rows, int $now, array $config): ?array
    {
        foreach (['short','long'] as $window) {
            $cutoff = $now - $config[$window.'_days'] * 86400;
            $orders = [];
            foreach ($rows as $row) {
                $at = strtotime((string)$row['cancelled_at'].' UTC');
                if ($at !== false && $at >= $cutoff && $at <= $now && (int)$row['score'] > 0) {
                    $id = (int)$row['order_id'];
                    $orders[$id] = max($orders[$id] ?? 0, (int)$row['score']);
                }
            }
            if (count($orders) >= $config[$window.'_orders'] && array_sum($orders) >= $config[$window.'_score']) {
                return ['window'=>$window, 'days'=>$config[$window.'_days'], 'order_count'=>count($orders),
                    'score'=>array_sum($orders), 'order_ids'=>array_keys($orders)];
            }
        }
        return null;
    }
}
