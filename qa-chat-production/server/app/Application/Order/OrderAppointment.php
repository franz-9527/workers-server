<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Order;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use WorkerAdmin\Contracts\OrderAppointmentRepository;
use WorkerAdmin\Domain\Order\OrderOperationException;

/** 师傅施工/维修预约；平台测量转施工的价格确认闸门由 Repository 强制校验。 */
final class OrderAppointment
{
    private OrderAppointmentRepository $repository;
    private string $timezone;

    public function __construct(OrderAppointmentRepository $repository, string $timezone = 'Asia/Shanghai')
    {
        $this->repository = $repository;
        $this->timezone = $timezone !== '' ? $timezone : 'Asia/Shanghai';
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $body @return array<string,mixed> */
    public function save(array $actor, array $body): array
    {
        $workerId = (int)($actor['worker_id'] ?? 0);
        if ($workerId < 1) throw new OrderOperationException('请先完成师傅身份后再操作', 'WORKER_REQUIRED', 403);

        $orderKey = trim((string)($body['order_id'] ?? ''));
        if ($orderKey === '') throw new OrderOperationException('订单编号缺失', 'ORDER_ID_REQUIRED', 422);
        $contextType = (string)($body['context_type'] ?? 'service');
        if (!in_array($contextType, ['service','repair'], true)) {
            throw new OrderOperationException('预约场景不正确', 'INVALID_APPOINTMENT_CONTEXT', 422);
        }
        $waitCustomerNotice = !empty($body['wait_customer_notice']);
        $appointmentAt = trim((string)($body['appointment_at'] ?? ''));
        if (!$waitCustomerNotice && $appointmentAt === '') {
            throw new OrderOperationException('请选择预约时间，或选择“等待客户另行通知”', 'APPOINTMENT_REQUIRED', 422);
        }

        $utc = null;
        if (!$waitCustomerNotice) {
            try {
                $utc = (new DateTimeImmutable($appointmentAt, new DateTimeZone($this->timezone)))
                    ->setTimezone(new DateTimeZone('UTC'))
                    ->format('Y-m-d H:i:s');
            } catch (Throwable $exception) {
                throw new OrderOperationException('预约时间格式不正确', 'APPOINTMENT_TIME_INVALID', 422);
            }
        }

        return $this->repository->save($actor, [
            'order_id'=>$orderKey,
            'context_type'=>$contextType,
            'appointment_at_utc'=>$utc,
            'wait_customer_notice'=>$waitCustomerNotice,
            'note'=>mb_substr(trim((string)($body['note'] ?? '')), 0, 500),
            'after_sale_id'=>trim((string)($body['after_sale_id'] ?? '')),
        ]);
    }
}
