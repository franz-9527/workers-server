<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Order;

use WorkerAdmin\Contracts\MiniPlatformOrderCreationRepository;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Domain\Order\OrderOperationException;

/** 小程序开单入口；资金、计价和履约规则仍由 PlatformOrderAdmin 执行。 */
final class MiniPlatformOrderCreation
{
    private MiniPlatformOrderCreationRepository $repository;
    private SettingsProvider $settings;

    public function __construct(MiniPlatformOrderCreationRepository $repository, SettingsProvider $settings)
    {
        $this->repository = $repository;
        $this->settings = $settings;
    }

    public function options(array $actor): array
    {
        $this->requireAdmin($actor);
        return [
            'categories'=>$this->repository->categories(),
            'default_extra_rate_bp'=>max(0, min(10000, (int)$this->settings->get('platform_order.extra_income_rate_bp', 2000))),
            'freeze_days'=>max(0, min(365, (int)$this->settings->get('platform_order.worker_balance_freeze_days', 0))),
            'map_key'=>trim((string)$this->settings->get('map.tencent_key', '')),
            'operator_ref'=>(string)($actor['user_public_id'] ?? ''),
        ];
    }

    public function customers(array $actor, string $query): array
    {
        $this->requireAdmin($actor);
        $query = mb_substr(trim($query), 0, 80);
        return ['items'=>mb_strlen($query) >= 2 ? $this->repository->customers($query) : []];
    }

    public function create(array $actor, array $body): array
    {
        $operatorId = $this->requireAdmin($actor);
        $requestId = trim((string)($body['client_request_id'] ?? ''));
        if (preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $requestId) !== 1) {
            throw new OrderOperationException('开单请求编号无效，请重新进入开单页。', 'PLATFORM_CREATE_REQUEST_INVALID', 422);
        }
        // user_id 和操作者信息不信任客户端，关联客户只接受公开编号。
        $keys = ['customer_ref','category_id','category_ids','answers','schema_version_id','title','description','province','city','district','address','map_lat','map_lng',
            'map_point_name','map_point_address','contact_name','contact_mobile','fulfillment_mode',
            'customer_pricing_mode','customer_total','external_prepayment','dispatch_mode','worker_settlement_mode',
            'worker_labor','extra_income_mode','extra_income_rate_percent','extra_income_fixed',
            'item_description','item_quantity','item_unit','item_unit_price','item_note'];
        $input = array_intersect_key($body, array_flip($keys));
        ksort($input);
        foreach ($input as $key => $value) {
            if ($key === 'answers') {
                if (!is_array($value) || count($value) > 200) throw new OrderOperationException('施工资料格式不正确。', 'PLATFORM_CREATE_INPUT_INVALID', 422);
                foreach ($value as $answer) {
                    $parts = is_array($answer) ? $answer : [$answer];
                    if (count($parts) > 100) throw new OrderOperationException('施工资料选项过多。', 'PLATFORM_CREATE_INPUT_INVALID', 422);
                    foreach ($parts as $part) if (!is_scalar($part) && $part !== null) throw new OrderOperationException('施工资料格式不正确。', 'PLATFORM_CREATE_INPUT_INVALID', 422);
                }
            } elseif ($key === 'category_ids') {
                if (!is_array($value) || count($value) < 1 || count($value) > 6) throw new OrderOperationException('请选择 1 至 6 个服务类目。', 'PLATFORM_CATEGORY_REQUIRED', 422);
                foreach ($value as $part) if (!is_scalar($part)) throw new OrderOperationException('服务类目格式不正确。', 'PLATFORM_CATEGORY_REQUIRED', 422);
            } elseif (strpos($key, 'item_') === 0) {
                if (!is_array($value) || count($value) > 50) {
                    throw new OrderOperationException('计价明细最多 50 项。', 'PLATFORM_ITEMS_INVALID', 422);
                }
                foreach ($value as $part) {
                    if (!is_scalar($part)) throw new OrderOperationException('计价明细格式不正确。', 'PLATFORM_ITEMS_INVALID', 422);
                }
            } elseif (!is_scalar($value)) {
                throw new OrderOperationException('开单字段格式不正确。', 'PLATFORM_CREATE_INPUT_INVALID', 422);
            }
        }
        return $this->repository->create($input, $operatorId, $requestId);
    }

    private function requireAdmin(array $actor): int
    {
        if (!empty($actor['is_demo_actor'])) {
            throw new OrderOperationException('请退出演示身份后创建真实平台承接单。', 'DEMO_ADMIN_ACTION_BLOCKED', 403);
        }
        $id = (int)($actor['real_user_id'] ?? $actor['user_id'] ?? 0);
        if (empty($actor['is_admin']) || $id < 1) {
            throw new OrderOperationException('仅真实管理员可创建平台承接单。', 'ADMIN_REQUIRED', 403);
        }
        return $id;
    }
}
