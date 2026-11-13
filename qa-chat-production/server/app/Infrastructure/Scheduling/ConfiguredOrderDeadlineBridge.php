<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Scheduling;

use WorkerAdmin\Core\Database;

/**
 * 旧 SchedulerService 仍承载大量历史任务；本类只负责把可配置的订单期限写成明确业务事实。
 *
 * 新订单原则上已经在履约用例中写入 auto_accept_at / auto_review_at，本桥接层主要解决：
 * 1. 历史或异常数据缺失期限时不再退回写死的 72 小时 / 5 天；
 * 2. 旧自动验收任务完成后，会把本次生成的评价期限校准为当前后台设置；
 * 3. order_deadlines 与 orders 上的真实期限保持一致。
 *
 * 它不负责验收、结算、评价本身，资金和状态推进仍复用既有原子事务。
 */
final class ConfiguredOrderDeadlineBridge
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function prepare(string $task): void
    {
        if (in_array($task, ['auto_accept','all'], true)) {
            $this->prepareAutoAcceptDeadline();
        }
        if (in_array($task, ['auto_review','all'], true)) {
            $this->prepareAutoReviewDeadline();
        }
    }

    public function after(string $task, string $startedAtUtc): void
    {
        if (!in_array($task, ['auto_accept','all'], true)) return;

        $reviewDays = $this->reviewDays();
        $acceptHours = $this->acceptHours();

        // 旧自动验收任务内部仍以 5 天作为写入兜底；只校准本次任务真正验收的订单，
        // 不追溯改变此前已形成的用户/师傅约定期限快照。
        $this->db->execute(
            "UPDATE orders
             SET auto_review_at=DATE_ADD(accepted_at,INTERVAL {$reviewDays} DAY),updated_at=UTC_TIMESTAMP()
             WHERE accepted_at IS NOT NULL
               AND completion_submitted_at IS NOT NULL
               AND accepted_at>=:started_at
               AND fulfillment_status='accepted'",
            ['started_at'=>$startedAtUtc]
        );
        $this->db->execute(
            "UPDATE order_deadlines d
             JOIN orders o ON o.id=d.order_id
             SET d.due_at=o.auto_review_at,d.updated_at=UTC_TIMESTAMP()
             WHERE o.accepted_at>=:started_at
               AND d.deadline_type IN ('auto_review','auto_review_order')
               AND d.status IN ('pending','blocked','claimed')",
            ['started_at'=>$startedAtUtc]
        );

        // 保留历史事件类型以兼容已有消息开关/统计，但不再向用户展示固定“72小时”的错误文案。
        $this->db->execute(
            "UPDATE order_events e
             JOIN orders o ON o.id=e.order_id
             SET e.payload_json=JSON_OBJECT(
                    'message','订单达到自动验收期限，系统已自动确认验收。',
                    'auto_accept_hours',{$acceptHours}
                 )
             WHERE e.event_type='auto_accepted_72h'
               AND e.created_at>=:started_at
               AND o.accepted_at>=:started_at",
            ['started_at'=>$startedAtUtc]
        );
    }

    private function prepareAutoAcceptDeadline(): void
    {
        $hours = $this->acceptHours();
        $this->db->execute(
            "UPDATE orders
             SET auto_accept_at=DATE_ADD(completion_submitted_at,INTERVAL {$hours} HOUR),updated_at=UTC_TIMESTAMP()
             WHERE lifecycle_status='active'
               AND fulfillment_status='submitted'
               AND completion_submitted_at IS NOT NULL
               AND accepted_at IS NULL
               AND auto_accept_at IS NULL"
        );
        $this->db->execute(
            "UPDATE order_deadlines d
             JOIN orders o ON o.id=d.order_id
             SET d.due_at=o.auto_accept_at,d.updated_at=UTC_TIMESTAMP()
             WHERE o.auto_accept_at IS NOT NULL
               AND d.deadline_type IN ('auto_accept','auto_accept_completion')
               AND d.status IN ('pending','blocked','claimed')"
        );
    }

    private function prepareAutoReviewDeadline(): void
    {
        $days = $this->reviewDays();
        $this->db->execute(
            "UPDATE orders
             SET auto_review_at=DATE_ADD(accepted_at,INTERVAL {$days} DAY),updated_at=UTC_TIMESTAMP()
             WHERE accepted_at IS NOT NULL
               AND auto_review_at IS NULL"
        );
        $this->db->execute(
            "UPDATE order_deadlines d
             JOIN orders o ON o.id=d.order_id
             SET d.due_at=o.auto_review_at,d.updated_at=UTC_TIMESTAMP()
             WHERE o.auto_review_at IS NOT NULL
               AND d.deadline_type IN ('auto_review','auto_review_order')
               AND d.status IN ('pending','blocked','claimed')"
        );
    }

    private function acceptHours(): int
    {
        return max(1, min(720, (int)\setting('order.auto_accept_hours', 72)));
    }

    private function reviewDays(): int
    {
        return max(1, min(30, (int)\setting('order.auto_review_days', 5)));
    }
}
