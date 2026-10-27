<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Admin;

use WorkerAdmin\Contracts\AdminOrderProxyActionRepository;
use WorkerAdmin\Domain\Order\OrderOperationException;

/**
 * 兼容保留的管理员代操作入口。
 *
 * V2.5.0 起接单、报价、客户确认等明确表达本人意愿的动作一律不得由管理员代办；
 * 管理员协助预约、补图、提交履约成果等场景必须走各自专用业务命令并记录真实经办人，
 * 不能通过本兼容入口冒充师傅或客户推进状态。
 */
final class AdminOrderProxyAction
{
    private AdminOrderProxyActionRepository $repository;

    public function __construct(AdminOrderProxyActionRepository $repository)
    {
        // 暂保留依赖以维持组合根/API 路由兼容；本入口不再调用任何代操作仓储写能力。
        $this->repository = $repository;
    }

    /** @param array<string,mixed> $operator @param array<string,mixed> $body @return array{message:string,data:array<string,mixed>} */
    public function execute(array $operator, array $body): array
    {
        $channel = trim((string)($operator['channel'] ?? ''));
        $userId = (int)($operator['user_id'] ?? 0);
        if ($channel !== 'mini_admin' || $userId < 1) {
            throw new OrderOperationException('管理员身份无效', 'ADMIN_REQUIRED', 403);
        }

        $orderNo = trim((string)($body['order_id'] ?? ''));
        if ($orderNo === '' || strlen($orderNo) > 80) {
            throw new OrderOperationException('订单编号无效', 'ORDER_ID_REQUIRED', 422);
        }

        $action = trim((string)($body['action'] ?? ''));
        if ($action === 'repair_complete') {
            throw new OrderOperationException(
                '旧代操作入口已停用；请通过协助办理提交维修结果，平台介入案件请走专用裁定流程',
                'ADMIN_REPAIR_PROXY_REMOVED',
                422
            );
        }
        if ($action === 'assignment_accept') {
            throw new OrderOperationException(
                '接单必须由师傅本人确认；管理员可安排师傅，但不能代替师傅表达接单意愿',
                'ADMIN_ASSIGNMENT_PROXY_REMOVED',
                422
            );
        }

        throw new OrderOperationException(
            '该动作不允许管理员代操作；请使用对应的管理员专用业务入口',
            'PROXY_ACTION_NOT_ALLOWED',
            422
        );
    }
}
