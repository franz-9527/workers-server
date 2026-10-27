<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use RuntimeException;
use WorkerAdmin\Contracts\AdminReviewRepository;
use WorkerAdmin\Core\Database;

final class DatabaseAdminReviewRepository implements AdminReviewRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function listing(int $page, bool $includeDemo): array
    {
        $perPage = 20;
        $where = ['1=1'];
        $params = [];
        if (!$includeDemo) {
            $where[] = 'o.public_no NOT LIKE :demo_public_no';
            $params['demo_public_no'] = '%DEMO%';
        }
        $countSql = 'SELECT COUNT(*) FROM reviews r LEFT JOIN orders o ON o.id=r.order_id WHERE '.implode(' AND ', $where);
        $total = (int)$this->db->fetchValue($countSql, $params);
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $offset = ($page - 1) * $perPage;
        $rows = $this->db->fetchAll(
            'SELECT r.id,r.order_id,r.user_id,r.worker_id,o.public_no,w.display_name AS worker_name,r.attitude_rating,r.craft_rating,ROUND((r.attitude_rating+r.craft_rating)/2,1) AS overall_rating,r.content,r.is_auto,r.status,r.reviewed_at,r.created_at '
            .'FROM reviews r LEFT JOIN orders o ON o.id=r.order_id LEFT JOIN worker_profiles w ON w.id=r.worker_id '
            .'WHERE '.implode(' AND ', $where).' ORDER BY r.id DESC LIMIT '.$perPage.' OFFSET '.$offset,
            $params
        );
        $mediaByReview = [];
        foreach ($rows as $row) {
            $reviewId = (int)$row['id'];
            $mediaByReview[$reviewId] = $this->db->fetchAll(
                "SELECT m.id,m.mime_type FROM review_media rm INNER JOIN media_files m ON m.id=rm.media_file_id AND m.status='active' WHERE rm.review_id=:id ORDER BY rm.sort_order,rm.id",
                ['id'=>$reviewId]
            );
        }
        $reviewOrders = $this->db->fetchAll(
            "SELECT o.id,o.public_no,o.user_id,o.assigned_worker_id FROM orders o WHERE o.assigned_worker_id IS NOT NULL AND o.fulfillment_status='accepted' AND NOT EXISTS(SELECT 1 FROM reviews r0 WHERE r0.order_id=o.id) ORDER BY o.id DESC LIMIT 300"
        );
        return [
            'rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>$pages,'per_page'=>$perPage,
            'media_by_review'=>$mediaByReview,'review_orders'=>$reviewOrders,
        ];
    }

    public function create(int $orderId, int $attitudeRating, int $craftRating, string $content): array
    {
        return $this->db->transaction(function (Database $db) use ($orderId, $attitudeRating, $craftRating, $content): array {
            $order = $db->fetch('SELECT id,user_id,assigned_worker_id,fulfillment_status FROM orders WHERE id=:id FOR UPDATE', ['id'=>$orderId]);
            if (!$order || empty($order['assigned_worker_id']) || (string)$order['fulfillment_status'] !== 'accepted') {
                throw new RuntimeException('只能为已验收且已安排师傅的订单新增评价。');
            }
            if ($db->fetchValue('SELECT id FROM reviews WHERE order_id=:id', ['id'=>$orderId])) {
                throw new RuntimeException('该订单已经存在评价。');
            }
            $reviewId = $db->insert(
                "INSERT INTO reviews(public_id,order_id,user_id,worker_id,attitude_rating,craft_rating,content,is_auto,status,reviewed_at,created_at,updated_at) VALUES(:public_id,:order_id,:user_id,:worker_id,:attitude,:craft,:content,0,'visible',NOW(),NOW(),NOW())",
                [
                    'public_id'=>\public_id(),'order_id'=>$orderId,'user_id'=>$order['user_id'],'worker_id'=>$order['assigned_worker_id'],
                    'attitude'=>$attitudeRating,'craft'=>$craftRating,'content'=>$content === '' ? null : $content,
                ]
            );
            return ['review_id'=>(int)$reviewId,'worker_id'=>(int)$order['assigned_worker_id']];
        });
    }

    public function update(int $reviewId, int $attitudeRating, int $craftRating, string $content, string $status): int
    {
        $workerId = (int)$this->db->fetchValue('SELECT worker_id FROM reviews WHERE id=:id', ['id'=>$reviewId]);
        if ($workerId < 1) throw new RuntimeException('评价不存在。');
        $this->db->execute(
            'UPDATE reviews SET attitude_rating=:attitude,craft_rating=:craft,content=:content,status=:status,is_auto=0,updated_at=NOW() WHERE id=:id',
            ['attitude'=>$attitudeRating,'craft'=>$craftRating,'content'=>$content === '' ? null : $content,'status'=>$status,'id'=>$reviewId]
        );
        return $workerId;
    }

    public function delete(int $reviewId): int
    {
        return $this->db->transaction(function (Database $db) use ($reviewId): int {
            $review = $db->fetch('SELECT worker_id FROM reviews WHERE id=:id FOR UPDATE', ['id'=>$reviewId]);
            if (!$review) throw new RuntimeException('评价不存在。');
            $media = $db->fetchAll('SELECT media_file_id FROM review_media WHERE review_id=:id FOR UPDATE', ['id'=>$reviewId]);
            $db->execute("DELETE FROM media_links WHERE entity_type='review' AND entity_id=:id", ['id'=>$reviewId]);
            $db->execute('DELETE FROM review_media WHERE review_id=:id', ['id'=>$reviewId]);
            $db->execute('DELETE FROM review_replies WHERE review_id=:id', ['id'=>$reviewId]);
            $db->execute('DELETE FROM reviews WHERE id=:id', ['id'=>$reviewId]);
            foreach ($media as $row) {
                $db->execute("UPDATE media_files SET reference_count=IF(reference_count>0,reference_count-1,0),status=IF(reference_count<=1,'pending_delete',status),pending_delete_at=IF(reference_count<=1,DATE_ADD(NOW(),INTERVAL 1 DAY),pending_delete_at) WHERE id=:id", ['id'=>$row['media_file_id']]);
            }
            return (int)$review['worker_id'];
        });
    }

    public function toggle(int $reviewId): int
    {
        $workerId = (int)$this->db->fetchValue('SELECT worker_id FROM reviews WHERE id=:id', ['id'=>$reviewId]);
        if ($workerId < 1) throw new RuntimeException('评价不存在或状态已变化。');
        $changed = $this->db->execute("UPDATE reviews SET status=IF(status='visible','hidden','visible'),updated_at=NOW() WHERE id=:id", ['id'=>$reviewId]);
        if ($changed < 1) throw new RuntimeException('评价不存在或状态已变化。');
        return $workerId;
    }

    public function exists(int $reviewId): bool
    {
        return (bool)$this->db->fetchValue('SELECT id FROM reviews WHERE id=:id', ['id'=>$reviewId]);
    }

    public function mediaCount(int $reviewId): int
    {
        return (int)$this->db->fetchValue('SELECT COUNT(*) FROM review_media WHERE review_id=:id', ['id'=>$reviewId]);
    }

    public function attachMedia(int $reviewId, int $mediaId, int $sortOrder): void
    {
        $this->db->insert(
            'INSERT INTO review_media(review_id,media_file_id,sort_order,created_at) VALUES(:review_id,:media_id,:sort,NOW())',
            ['review_id'=>$reviewId,'media_id'=>$mediaId,'sort'=>$sortOrder]
        );
    }

    public function removeMedia(int $reviewId, int $mediaId): bool
    {
        return $this->db->transaction(function (Database $db) use ($reviewId, $mediaId): bool {
            $changed = $db->execute('DELETE FROM review_media WHERE review_id=:review_id AND media_file_id=:media_id', ['review_id'=>$reviewId,'media_id'=>$mediaId]);
            if ($changed < 1) return false;
            $db->execute("DELETE FROM media_links WHERE entity_type='review' AND entity_id=:review_id AND media_file_id=:media_id", ['review_id'=>$reviewId,'media_id'=>$mediaId]);
            $db->execute("UPDATE media_files SET reference_count=IF(reference_count>0,reference_count-1,0),status=IF(reference_count<=1,'pending_delete',status),pending_delete_at=IF(reference_count<=1,DATE_ADD(NOW(),INTERVAL 1 DAY),pending_delete_at) WHERE id=:id", ['id'=>$mediaId]);
            return true;
        });
    }
}
