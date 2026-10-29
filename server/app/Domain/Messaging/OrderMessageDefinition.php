<?php

declare(strict_types=1);

namespace WorkerAdmin\Domain\Messaging;

final class OrderMessageDefinition
{
    public static function definition(string $type, array $payload): ?array
    {
        $meta = MessageEventCatalog::get($type);
        // 管理员恢复类事件只需要订单进度触达，不自动扩入短信/模板配置目录。
        if (!$meta && $type === 'worker_replaced_by_admin') {
            $meta = ['status'=>'平台已改派师傅，等待新师傅确认接单','recipients'=>['user','worker'],'scene'=>'order_status'];
        }
        if (!$meta && $type === 'repair_platform_intervention_started') {
            $meta = ['status'=>'平台已主动介入维修售后','recipients'=>['user','worker'],'scene'=>'order_status'];
        }
        if (!$meta || trim((string)($meta['status'] ?? '')) === '' || empty($meta['recipients'])) return null;
        if (!empty($payload['assisted']) && in_array($type,['completion_submitted','measurement_result_submitted','platform_measurement_result_submitted','platform_measurement_auto_confirmed','repair_worker_completed'],true)) {
            $meta['status']=$type==='completion_submitted' ? '管理员已补录完工，等待客户验收'
                : ($type==='repair_worker_completed' ? '管理员已补录维修处理结果' : '管理员已补录测量成果');
            $meta['recipients']=['user','worker'];
        }
        if (!empty($payload['assisted']) && in_array($type, ['appointment_recorded','appointment_waiting_customer_notice','repair_appointment_recorded','repair_waiting_customer_notice'], true)) {
            $meta['status'] = !empty($payload['wait_customer_notice']) ? '管理员补录联系结果：等待客户另行通知' : '管理员已补录预约';
            $meta['recipients'] = ['user','worker'];
        }
        $amount = null;
        foreach (['amount_cents','requested_tail_cents','new_amount_cents','added_amount_cents','total_amount_cents','refund_amount_cents'] as $amountKey) {
            if (isset($payload[$amountKey]) && is_numeric($payload[$amountKey])) { $amount = (int)$payload[$amountKey]; break; }
        }
        $recipients = (array)$meta['recipients'];
        if (isset($payload['notify_recipients']) && is_array($payload['notify_recipients'])) {
            $requested = array_values(array_unique(array_map('strval', $payload['notify_recipients'])));
            $recipients = array_values(array_intersect($recipients, $requested));
        }
        return [
            'scene'=>(string)($meta['scene'] ?? 'order_status'),
            'status'=>(string)$meta['status'],
            'recipients'=>$recipients,
            'amount_cents'=>$amount,
        ];
    }
}
