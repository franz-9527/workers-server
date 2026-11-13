<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Order;

use WorkerAdmin\Contracts\PlatformOrderPricingReviewRepository;
use WorkerAdmin\Domain\Order\OrderOperationException;

/**
 * 平台测量完成后的核价用例。
 *
 * 平台托管单由管理员核价后自动确认，同一订单进入施工预约；
 * 核价和自动确认保留独立事件，不创建第二套施工订单。
 */
final class PlatformOrderPricingReview
{
    private PlatformOrderPricingReviewRepository $repository;

    public function __construct(PlatformOrderPricingReviewRepository $repository)
    {
        $this->repository = $repository;
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $body @return array<string,mixed> */
    public function review(array $actor, array $body): array
    {
        if (empty($actor['is_admin'])) {
            throw new OrderOperationException('仅管理员可完成平台单测量后核价。', 'ADMIN_REQUIRED', 403);
        }
        $orderKey = trim((string)($body['order_id'] ?? ''));
        if ($orderKey === '') throw new OrderOperationException('平台订单不存在。', 'PLATFORM_ORDER_NOT_FOUND', 404);

        $workerLabor = $this->moneyToCents($body['worker_labor'] ?? null);
        if ($workerLabor < 1 || $workerLabor > 100000000) {
            throw new OrderOperationException('请填写正确的师傅施工工费。', 'PLATFORM_LABOR_INVALID', 422);
        }

        $note = mb_substr(trim((string)($body['note'] ?? '')), 0, 500);
        $command = [
            'worker_labor_cents'=>$workerLabor,
            'note'=>$note,
        ];

        if (array_key_exists('customer_total', $body) && trim((string)$body['customer_total']) !== '') {
            $customerTotal = $this->moneyToCents($body['customer_total']);
            if ($customerTotal < 1 || $customerTotal > 100000000) {
                throw new OrderOperationException('请填写正确的客户最终金额。', 'PLATFORM_CUSTOMER_TOTAL_INVALID', 422);
            }
            $command['customer_total_cents'] = $customerTotal;
        }

        $rawItems = is_array($body['items'] ?? null) ? $body['items'] : [];
        if (count($rawItems) > 50) {
            throw new OrderOperationException('一次最多核定 50 个计价项目。', 'PLATFORM_PRICING_ITEMS_INVALID', 422);
        }
        $items = [];
        $seen = [];
        foreach ($rawItems as $raw) {
            if (!is_array($raw)) throw new OrderOperationException('核价项目格式不正确。', 'PLATFORM_PRICING_ITEM_INVALID', 422);
            $sortOrder = (int)($raw['sort_order'] ?? 0);
            $quantityMilli = $this->quantityToMilli($raw['quantity'] ?? null);
            if ($sortOrder < 1 || isset($seen[$sortOrder]) || $quantityMilli < 1) {
                throw new OrderOperationException('核价项目编号或实际数量不正确；数量最多保留 3 位小数。', 'PLATFORM_PRICING_ITEM_INVALID', 422);
            }
            $seen[$sortOrder] = true;
            $items[] = ['sort_order'=>$sortOrder,'quantity_milli'=>$quantityMilli];
        }
        $command['items'] = $items;

        return $this->repository->review(
            $orderKey,
            $command,
            (int)($actor['real_user_id'] ?? $actor['user_id'] ?? 0)
        );
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $body @return array<string,mixed> */
    public function confirm(array $actor, array $body): array
    {
        $orderKey = trim((string)($body['order_id'] ?? ''));
        if ($orderKey === '') throw new OrderOperationException('平台订单不存在。', 'PLATFORM_ORDER_NOT_FOUND', 404);
        $userId = (int)($actor['user_id'] ?? 0);
        if ($userId < 1) throw new OrderOperationException('请先登录后确认核价结果。', 'AUTH_REQUIRED', 401);
        return $this->repository->confirm($orderKey, $userId);
    }

    /** @param mixed $value */
    private function quantityToMilli($value): int
    {
        if (!is_scalar($value)) return 0;
        $text = trim((string)$value);
        if (!preg_match('/^(\d{1,9})(?:\.(\d{1,3}))?$/D', $text, $parts)) return 0;
        $milli = ((int)$parts[1]) * 1000 + (int)str_pad($parts[2] ?? '', 3, '0');
        return $milli > 999999999999 ? 0 : $milli;
    }

    /** @param mixed $value */
    private function moneyToCents($value): int
    {
        if (!is_scalar($value)) return 0;
        $text = trim(str_replace([',','￥','¥','元'], '', (string)$value));
        if (!preg_match('/^(\d{1,8})(?:\.(\d{1,2}))?$/D', $text, $parts)) return 0;
        return ((int)$parts[1]) * 100 + (int)str_pad($parts[2] ?? '', 2, '0');
    }
}
