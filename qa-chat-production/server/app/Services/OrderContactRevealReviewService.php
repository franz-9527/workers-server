<?php

declare(strict_types=1);

namespace WorkerAdmin\Services;

use WorkerAdmin\Application\Order\ContactRevealReview;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Messaging\LegacyOrderNotificationAdapter;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderContactRevealRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseTransactionManager;

/**
 * @deprecated 兼容网页后台与小程序管理员的旧调用入口。
 *
 * 公开方法签名保持不变；审批规则、事务编排、SQL 与消息投递已经分别迁移到
 * Domain / Application / Contracts / Infrastructure。
 */
final class OrderContactRevealReviewService
{
    private ContactRevealReview $review;

    public function __construct(Database $db)
    {
        $this->review = new ContactRevealReview(
            new DatabaseTransactionManager($db),
            new DatabaseOrderContactRevealRepository($db),
            new LegacyOrderNotificationAdapter($db)
        );
    }

    public function reviewById(int $id, string $decision, string $note, array $operator): array
    {
        return $this->review->reviewById($id, $decision, $note, $operator);
    }

    public function reviewByPublicNo(string $publicNo, string $decision, string $note, array $operator): array
    {
        return $this->review->reviewByPublicNo($publicNo, $decision, $note, $operator);
    }
}
