<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use RuntimeException;
use WorkerAdmin\Contracts\ReviewRepository;
use WorkerAdmin\Core\Database;

/** MySQL 评价仓储：所有评价 SQL 都收口在 Infrastructure，HTTP/Application 不接触数据库语句。 */
final class DatabaseReviewRepository implements ReviewRepository
{
    private Database $db;

    public function __construct(Database $db) { $this->db = $db; }

    public function publicWorkerPage(string $workerReference, int $page, int $limit): array
    {
        $worker = $this->db->fetch(
            "SELECT id FROM worker_profiles WHERE BINARY cloud_worker_id=BINARY :cloud_id OR BINARY public_id=BINARY :public_id ORDER BY (BINARY cloud_worker_id=BINARY :cloud_order) DESC,id DESC LIMIT 1",
            ['cloud_id'=>$workerReference,'public_id'=>$workerReference,'cloud_order'=>$workerReference]
        );
        if (!$worker) throw new RuntimeException('师傅资料不存在', 404);
        $workerId = (int)$worker['id'];
        $offset = (max(1, $page) - 1) * $limit;
        $summary = $this->db->fetch(
            "SELECT COUNT(*) review_count,COALESCE(AVG(attitude_rating),0) attitude_average,COALESCE(AVG(craft_rating),0) craft_average,COALESCE(AVG((attitude_rating+craft_rating)/2),0) overall_average FROM reviews WHERE worker_id=:worker_id AND status='visible'",
            ['worker_id'=>$workerId]
        ) ?: [];
        $rows = $this->db->fetchAll(
            "SELECT r.public_id,r.attitude_rating,r.craft_rating,r.content,r.is_auto,r.reviewed_at,r.created_at,u.nickname,o.public_no,o.title FROM reviews r LEFT JOIN users u ON u.id=r.user_id LEFT JOIN orders o ON o.id=r.order_id WHERE r.worker_id=:worker_id AND r.status='visible' ORDER BY COALESCE(r.reviewed_at,r.created_at) DESC,r.id DESC LIMIT ".($limit + 1)." OFFSET ".$offset,
            ['worker_id'=>$workerId]
        );
        $hasMore = count($rows) > $limit;
        return ['summary'=>$summary,'rows'=>array_slice($rows,0,$limit),'has_more'=>$hasMore];
    }

    public function createUserReview(string $token, string $orderPublicNo, int $attitudeRating, int $craftRating, string $content): string
    {
        if (preg_match('/^[A-Fa-f0-9]{64}$/', $token) !== 1) throw new RuntimeException('请重新登录', 401);
        return $this->db->transaction(function (Database $db) use ($token, $orderPublicNo, $attitudeRating, $craftRating, $content): string {
            $actor = $db->fetch("SELECT u.id AS user_id FROM login_sessions s INNER JOIN users u ON u.id=s.user_id WHERE s.token_hash=:token_hash AND s.status='active' AND s.expires_at>UTC_TIMESTAMP() AND u.status='active' LIMIT 1", ['token_hash'=>hash('sha256',$token)]);
            if (!$actor) throw new RuntimeException('请重新登录', 401);
            $order = $db->fetch('SELECT id,user_id,assigned_worker_id,fulfillment_status FROM orders WHERE public_no=:public_no LIMIT 1 FOR UPDATE', ['public_no'=>$orderPublicNo]);
            if (!$order) throw new RuntimeException('订单不存在', 404);
            if ((int)$order['user_id'] !== (int)$actor['user_id']) throw new RuntimeException('只能评价自己的订单', 403);
            if ((string)$order['fulfillment_status'] !== 'accepted' || empty($order['assigned_worker_id'])) throw new RuntimeException('订单验收后才能评价', 409);
            if ($db->fetchValue('SELECT id FROM reviews WHERE order_id=:id LIMIT 1', ['id'=>$order['id']])) throw new RuntimeException('该订单已经评价过', 409);
            $publicId = \public_id();
            $db->insert("INSERT INTO reviews(public_id,order_id,user_id,worker_id,attitude_rating,craft_rating,content,is_auto,status,reviewed_at,created_at,updated_at) VALUES(:public_id,:order_id,:user_id,:worker_id,:attitude_rating,:craft_rating,:content,0,'visible',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())", [
                'public_id'=>$publicId,'order_id'=>$order['id'],'user_id'=>$actor['user_id'],'worker_id'=>$order['assigned_worker_id'],
                'attitude_rating'=>$attitudeRating,'craft_rating'=>$craftRating,'content'=>$content !== '' ? $content : null,
            ]);
            $this->refreshWorkerSummary((int)$order['assigned_worker_id']);
            $payload = json_encode(['attitude_rating'=>$attitudeRating,'craft_rating'=>$craftRating,'overall_rating'=>($attitudeRating+$craftRating)/2], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $db->insert('INSERT INTO order_events(order_id,event_type,event_key,actor_type,actor_id,payload_json,created_at) VALUES(:order_id,\'review_submitted\',:event_key,\'user\',:actor_id,:payload,UTC_TIMESTAMP())', [
                'order_id'=>$order['id'],'event_key'=>'review_submitted:'.$order['id'].':'.bin2hex(random_bytes(6)),'actor_id'=>$actor['user_id'],'payload'=>$payload,
            ]);
            return $publicId;
        });
    }

    public function createAutoForOrder(int $orderId, string $content): bool
    {
        $order = $this->db->fetch('SELECT id,user_id,assigned_worker_id FROM orders WHERE id=:id AND assigned_worker_id IS NOT NULL LIMIT 1', ['id'=>$orderId]);
        if (!$order || empty($order['assigned_worker_id'])) return false;
        if ($this->db->fetchValue('SELECT id FROM reviews WHERE order_id=:id LIMIT 1', ['id'=>$orderId])) return false;
        $this->db->insert("INSERT INTO reviews(public_id,order_id,user_id,worker_id,attitude_rating,craft_rating,content,is_auto,status,reviewed_at,created_at,updated_at) VALUES(:public_id,:order_id,:user_id,:worker_id,5,5,:content,1,'visible',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())", [
            'public_id'=>\public_id(),'order_id'=>$orderId,'user_id'=>$order['user_id'],'worker_id'=>$order['assigned_worker_id'],'content'=>$content !== '' ? $content : null,
        ]);
        $this->refreshWorkerSummary((int)$order['assigned_worker_id']);
        return true;
    }

    public function refreshWorkerSummary(int $workerId): void
    {
        if ($workerId < 1) return;
        $summary = $this->db->fetch(
            "SELECT COUNT(*) review_count,COALESCE(SUM(ROUND((attitude_rating+craft_rating)/2,0)),0) rating_sum,COALESCE(AVG((attitude_rating+craft_rating)/2),5) rating_average,SUM(IF(attitude_rating=5 AND craft_rating=5,1,0)) five_star_count FROM reviews WHERE worker_id=:worker_id AND status='visible'",
            ['worker_id'=>$workerId]
        ) ?: [];
        $this->db->execute("INSERT INTO rating_summaries(worker_id,review_count,rating_sum,rating_average,five_star_count,updated_at) VALUES(:worker_id,:review_count,:rating_sum,:rating_average,:five_star_count,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE review_count=VALUES(review_count),rating_sum=VALUES(rating_sum),rating_average=VALUES(rating_average),five_star_count=VALUES(five_star_count),updated_at=UTC_TIMESTAMP()", [
            'worker_id'=>$workerId,'review_count'=>(int)($summary['review_count']??0),'rating_sum'=>(int)($summary['rating_sum']??0),
            'rating_average'=>number_format((float)($summary['rating_average']??5),2,'.',''),'five_star_count'=>(int)($summary['five_star_count']??0),
        ]);
        $this->db->execute('UPDATE worker_profiles SET rating=:rating,updated_at=UTC_TIMESTAMP() WHERE id=:id', ['rating'=>number_format((float)($summary['rating_average']??5),2,'.',''),'id'=>$workerId]);
    }
}
