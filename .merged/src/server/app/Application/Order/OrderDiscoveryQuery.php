<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Order;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use WorkerAdmin\Contracts\OrderDiscoveryRepository;
use WorkerAdmin\Contracts\OrderMediaUrlSigner;
use WorkerAdmin\Contracts\SettingsProvider;

/** V2.5.0 接单大厅查询；大厅只负责公开报价阶段，不再承担已报价/已接订单中心。 */
final class OrderDiscoveryQuery
{
    private OrderDiscoveryRepository $repository;
    private OrderMediaUrlSigner $mediaSigner;
    private SettingsProvider $settings;
    private string $timezone;

    public function __construct(
        OrderDiscoveryRepository $repository,
        OrderMediaUrlSigner $mediaSigner,
        SettingsProvider $settings,
        string $timezone = 'Asia/Shanghai'
    ) {
        $this->repository = $repository;
        $this->mediaSigner = $mediaSigner;
        $this->settings = $settings;
        $this->timezone = $timezone !== '' ? $timezone : 'Asia/Shanghai';
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function hall(
        array $actor,
        bool $isAdmin,
        int $categoryId,
        string $requestedSort,
        ?float $lat,
        ?float $lng,
        int $page
    ): array {
        $page = max(1, $page);
        if (!$isAdmin && $this->settings->bool('development.pause_hall', false)) {
            return [
                'message' => '系统维护中，暂时停止接单；进行中的订单仍可正常处理。',
                'items' => [], 'page' => $page, 'has_more' => false, 'paused' => true,
                'sort' => 'newest',
            ];
        }

        $defaultSort = $isAdmin ? 'newest' : 'nearest';
        $sort = in_array($requestedSort, ['newest', 'nearest', 'oldest'], true) ? $requestedSort : $defaultSort;
        if ($sort === 'nearest' && !$this->validCoordinate($lat, $lng)) {
            $sort = 'newest';
            $lat = null;
            $lng = null;
        }

        $result = $this->repository->hallPage($actor, $categoryId, $sort, $lat, $lng, $page, 20);
        $items = [];
        foreach ($result['rows'] as $order) {
            $items[] = $this->hallCard(
                $order,
                $actor,
                $lat,
                $lng,
                (int)$order['user_id'] === (int)$actor['user_id']
            );
        }
        return [
            'items' => $items,
            'page' => $page,
            'has_more' => (bool)$result['has_more'],
            'paused' => false,
            'sort' => $sort,
        ];
    }

    /** @param array<string,mixed> $order @param array<string,mixed> $actor @return array<string,mixed> */
    private function hallCard(array $order, array $actor, ?float $lat, ?float $lng, bool $isOwner): array
    {
        $workflow = (string)($order['workflow_profile'] ?? 'construction');
        $category = $workflow === 'measurement'
            ? '上门量尺'
            : (string)((($order['category_names_snapshot'] ?? '') !== '') ? $order['category_names_snapshot'] : ($order['category_name_snapshot'] ?? ''));
        $address = $this->maskAddress((string)($order['address_summary'] ?? ''));
        $distance = null;
        if ($this->validCoordinate($lat, $lng) && is_numeric($order['lat'] ?? null) && is_numeric($order['lng'] ?? null)) {
            $distance = $this->distanceKm((float)$lat, (float)$lng, (float)$order['lat'], (float)$order['lng']);
        }
        $workerStatus = $this->normalizedWorkerStatus((string)($actor['worker_status'] ?? ''));
        $canSeeOwnQuote = (int)($actor['worker_id'] ?? 0) > 0
            && $workerStatus === 'approved'
            && !$this->truthy($actor['invited_only'] ?? false);

        return [
            'id' => (string)$order['public_no'],
            'title' => $workflow === 'measurement' ? '上门量尺' : ($category !== '' ? $category . '施工订单' : '施工订单'),
            'workflow_profile' => $workflow,
            'business_type' => $workflow === 'measurement' ? 'measurement' : 'construction',
            'order_source_type' => (string)($order['order_source_type'] ?? 'consumer'),
            'dispatch_mode' => (string)($order['dispatch_mode'] ?? 'multi_quote'),
            'dispatch_mode_text' => (string)($order['dispatch_mode'] ?? 'multi_quote') === 'single_grab' ? '抢单模式' : '多师傅报价',
            'expected_fee_type' => (string)($order['expected_fee_type'] ?? ''),
            'expected_fee_cents' => $order['expected_fee_cents'] === null ? null : (int)$order['expected_fee_cents'],
            'expected_fee_text' => (string)($order['expected_fee_text'] ?? ''),
            'fixed_grab_total' => (string)($order['dispatch_mode'] ?? 'multi_quote') === 'single_grab'
                && (string)($order['expected_fee_type'] ?? '') === 'total'
                && (int)($order['expected_fee_cents'] ?? 0) > 0,
            'category_name' => $category,
            'district' => (string)($order['district'] ?? ''),
            'address' => $address,
            'display_address' => trim((string)($order['district'] ?? '') . ' ' . $address),
            'is_owner' => $isOwner,
            'quantity_text' => (string)($order['quantity_text'] ?? ''),
            'expected_visit_at' => $this->expectedVisitLabel($order['expected_visit_at'] ?? null) ?: '接单后与业主预约',
            'created_at' => $this->localTime($order['created_at'] ?? null),
            'published_at' => $this->localTime($order['published_at'] ?? null),
            'quote_count' => (int)($order['quote_count'] ?? 0),
            'own_quote_cents' => $canSeeOwnQuote && $order['own_quote_cents'] !== null ? (int)$order['own_quote_cents'] : null,
            'fulfillment_status' => 'not_started',
            'distance_km' => $distance,
            'distance_warning' => $distance !== null && $distance > (float)$this->settings->get('hall.distance_warning_km', 50),
            'cover_url' => !empty($order['cover_media_id'])
                ? $this->mediaSigner->sign((string)$order['cover_media_id'], $actor, (int)$order['id'])
                : '',
        ];
    }

    private function validCoordinate(?float $lat, ?float $lng): bool
    {
        return $lat !== null && $lng !== null && is_finite($lat) && is_finite($lng)
            && $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180;
    }

    private function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371.0;
        $latDelta = deg2rad($lat2 - $lat1);
        $lngDelta = deg2rad($lng2 - $lng1);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lngDelta / 2) ** 2;
        return round($earth * 2 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a))), 1);
    }

    private function maskAddress(string $address): string
    {
        $value = trim($address);
        if ($value === '') return '';
        $masked = preg_replace('/\d+[A-Za-z]?\s*(?=幢|栋|号楼)/u', '***', $value);
        $masked = preg_replace('/\d+\s*(?=单元|室|号|楼层|层)/u', '*', (string)$masked);
        $masked = preg_replace_callback('/(小区|村|社区|公寓|花园|苑|广场|大厦|园区)([^\r\n]*)/u', static function (array $matches): string {
            return $matches[1] . (preg_replace('/\d+/u', '*', $matches[2]) ?: $matches[2]);
        }, (string)$masked);
        return $masked ?: $value;
    }

    private function normalizedWorkerStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if ($status === '' || in_array($status, ['reviewing', 'submitted', 'waiting', '待审核', '审核中'], true)) return 'pending';
        if (in_array($status, ['通过', '已通过', 'verified'], true)) return 'approved';
        return $status;
    }

    private function truthy($value): bool
    {
        if (is_bool($value)) return $value;
        if (is_numeric($value)) return (int)$value > 0;
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function localTime($value): ?string
    {
        if (!$value) return null;
        try {
            return (new DateTimeImmutable((string)$value, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone($this->timezone))->format('Y-m-d H:i');
        } catch (Throwable $exception) {
            return (string)$value;
        }
    }

    private function expectedVisitLabel($value): ?string
    {
        if (!$value) return null;
        try {
            $date = (new DateTimeImmutable((string)$value, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone($this->timezone));
            $hour = (int)$date->format('H');
            $period = $hour < 12 ? '上午' : ($hour < 18 ? '下午' : '晚上');
            return $date->format('Y-m-d') . ' ' . $period;
        } catch (Throwable $exception) {
            return (string)$value;
        }
    }
}
