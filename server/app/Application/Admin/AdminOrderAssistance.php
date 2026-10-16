<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Admin;

use DateTimeImmutable;
use DateTimeZone;
use WorkerAdmin\Contracts\AdminOrderAssistanceRepository;
use WorkerAdmin\Domain\Order\OrderOperationException;

/** 管理员按真实沟通补录事实；不接受成交、费用或本人确认命令。 */
final class AdminOrderAssistance
{
    private AdminOrderAssistanceRepository $repository;
    private string $timezone;

    public function __construct(AdminOrderAssistanceRepository $repository, string $timezone = 'Asia/Shanghai')
    {
        $this->repository = $repository;
        $this->timezone = $timezone;
    }

    public function execute(string $route, array $operator, array $input): array
    {
        if (($operator['channel'] ?? '') !== 'mini_admin' || (int)($operator['user_id'] ?? 0) < 1) {
            throw new OrderOperationException('管理员身份无效', 'ADMIN_REQUIRED', 403);
        }
        $orderNo = trim((string)($input['order_id'] ?? ''));
        if ($orderNo === '' || strlen($orderNo) > 80) {
            throw new OrderOperationException('订单编号无效', 'ORDER_ID_REQUIRED', 422);
        }
        if ($route === 'admin.order.assistance.context') return $this->repository->context($orderNo);
        if ($route !== 'admin.order.assistance.save') {
            throw new OrderOperationException('协助事项不存在', 'ASSISTANCE_NOT_ALLOWED', 422);
        }
        $action = (string)($input['action'] ?? '');
        if (!in_array($action, ['contact_note','appointment'], true)) {
            throw new OrderOperationException('当前仅支持联系记录和预约补录', 'ASSISTANCE_NOT_ALLOWED', 422);
        }
        $context = (string)($input['context_type'] ?? 'service');
        if (!in_array($context, ['service','repair'], true)) {
            throw new OrderOperationException('履约场景无效', 'INVALID_APPOINTMENT_CONTEXT', 422);
        }
        $requestId = trim((string)($input['request_id'] ?? ''));
        $assignment = trim((string)($input['assignment_id'] ?? ''));
        $note = trim((string)($input['note'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $requestId) || $assignment === '' || strlen($assignment) > 80) {
            throw new OrderOperationException('请刷新后重试', 'ASSISTANCE_CONTEXT_REQUIRED', 422);
        }
        if (mb_strlen($note) < 2 || mb_strlen($note) > 500) {
            throw new OrderOperationException('请填写 2 至 500 字的实际沟通说明', 'ASSISTANCE_NOTE_REQUIRED', 422);
        }
        $occurredAt = trim((string)($input['occurred_at'] ?? ''));
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $occurredAt, new DateTimeZone($this->timezone));
        if (!$date || $date->format('Y-m-d H:i:s') !== $occurredAt || $date->getTimestamp() > time() + 300) {
            throw new OrderOperationException('请填写实际沟通时间，不能填写未来时间', 'ASSISTANCE_TIME_INVALID', 422);
        }
        $wait = !empty($input['wait_customer_notice']);
        $appointmentAt = $action === 'appointment' && !$wait ? trim((string)($input['appointment_at'] ?? '')) : '';
        if ($action === 'appointment' && !$wait) {
            $visit = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $appointmentAt, new DateTimeZone($this->timezone));
            if (!$visit || $visit->format('Y-m-d H:i:s') !== $appointmentAt) {
                throw new OrderOperationException('请选择有效的上门时间', 'APPOINTMENT_TIME_INVALID', 422);
            }
        }
        // 逐项白名单构造，客户端的 worker_id/is_admin/warranty/金额等字段不会进入领域命令。
        return $this->repository->save($operator, [
            'order_id'=>$orderNo, 'action'=>$action, 'context_type'=>$context,
            'request_id'=>$requestId, 'assignment_id'=>$assignment,
            'after_sale_id'=>trim((string)($input['after_sale_id'] ?? '')),
            'note'=>$note, 'occurred_at'=>$date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'wait_customer_notice'=>$action === 'appointment' && $wait,
            'appointment_at'=>$appointmentAt,
        ]);
    }
}
