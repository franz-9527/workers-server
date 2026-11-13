<?php
declare(strict_types=1);
namespace WorkerAdmin\Application\Order;

use WorkerAdmin\Contracts\MiniOrdinaryOrderCreationRepository;
use WorkerAdmin\Domain\Order\OrderOperationException;

/** 管理员只录入普通订单，客户协议、支付和成交仍由本人完成。 */
final class MiniOrdinaryOrderCreation
{
    private MiniOrdinaryOrderCreationRepository $repository;
    public function __construct(MiniOrdinaryOrderCreationRepository $repository) { $this->repository = $repository; }

    public function customers(array $actor, string $query): array
    {
        $this->requireAdmin($actor);
        $query = mb_substr(trim($query), 0, 80);
        return ['items'=>mb_strlen($query) >= 2 ? $this->repository->customers($query) : []];
    }

    public function form(array $actor, string $ref): array
    {
        $this->requireAdmin($actor);
        return $this->repository->form($ref) + ['operator_ref'=>(string)($actor['user_public_id'] ?? '')];
    }

    public function create(array $actor, array $body): array
    {
        $id = $this->requireAdmin($actor);
        $request = trim((string)($body['client_request_id'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $request)) throw new OrderOperationException('下单请求编号无效，请重新进入', 'INVALID_REQUEST_ID', 422);
        $keys = ['customer_ref','schema_version_id','answers','address','dispatch_mode','phone_role_confirm'];
        $input = array_intersect_key($body, array_flip($keys));
        foreach (['customer_ref','schema_version_id','dispatch_mode','phone_role_confirm'] as $key) if (isset($input[$key]) && !is_scalar($input[$key])) throw new OrderOperationException('下单资料格式不正确', 'INVALID_INPUT', 422);
        foreach (['answers','address'] as $key) if (!is_array($input[$key] ?? null)) throw new OrderOperationException('下单资料格式不正确', 'INVALID_INPUT', 422);
        return $this->repository->create($input, $id, $request);
    }

    public function ready(array $actor, array $body): array
    {
        return $this->repository->ready(trim((string)($body['order_id'] ?? '')), $this->requireAdmin($actor));
    }

    private function requireAdmin(array $actor): int
    {
        if (!empty($actor['is_demo_actor'])) throw new OrderOperationException('请退出演示身份后代下单', 'DEMO_ADMIN_ACTION_BLOCKED', 403);
        $id = (int)($actor['real_user_id'] ?? $actor['user_id'] ?? 0);
        if (empty($actor['is_admin']) || $id < 1) throw new OrderOperationException('仅真实管理员可代下单', 'ADMIN_REQUIRED', 403);
        return $id;
    }
}
