<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\WorkerOrderDiscountRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use RuntimeException;

/** Separate customer-side eligibility, application review and order fee records. */
final class DatabaseWorkerOrderDiscountRepository implements WorkerOrderDiscountRepository
{
    public function __construct(private Database $db)
    {
    }

    public function status(array $actor): ?array
    {
        $worker = $this->approvedWorker($actor);
        if (!$worker) return null;
        $row = $this->db->fetch(
            'SELECT status,wechat_nickname,review_reason FROM worker_order_discount_applications WHERE worker_id=:worker',
            ['worker'=>$worker]
        );
        return $row ?: ['status'=>'none','wechat_nickname'=>'','review_reason'=>''];
    }

    public function submit(array $actor, array $body): array
    {
        $nickname = is_string($body['wechat_nickname'] ?? null) ? trim($body['wechat_nickname']) : '';
        if ($nickname === '' || mb_strlen($nickname) > 64 || preg_match('/[\x00-\x1f\x7f]/u', $nickname) !== 0
            || ($body['conditions_confirmed'] ?? null) !== true) {
            throw new OrderOperationException('请填写实际微信昵称，并确认符合全部申请条件', 'WORKER_ORDER_DISCOUNT_INVALID', 422);
        }
        return $this->db->transaction(function () use ($actor, $nickname): array {
            $worker = $this->approvedWorker($actor, true);
            if (!$worker) throw new OrderOperationException('仅已审核通过的入驻师傅可申请', 'WORKER_ORDER_DISCOUNT_INELIGIBLE', 403);
            $row = $this->db->fetch(
                'SELECT status FROM worker_order_discount_applications WHERE worker_id=:worker FOR UPDATE',
                ['worker'=>$worker]
            );
            $status = $row['status'] ?? 'none';
            if ($status === 'pending' || $status === 'approved') return ['status'=>$status];
            if ($status === 'suspended') {
                throw new OrderOperationException('优惠资格已暂停，请联系平台处理', 'WORKER_ORDER_DISCOUNT_SUSPENDED', 409);
            }
            $this->db->execute(
                "INSERT INTO worker_order_discount_applications(worker_id,status,wechat_nickname,submitted_at)
                 VALUES(:worker,'pending',:nickname,UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE status='pending',wechat_nickname=VALUES(wechat_nickname),
                   submitted_at=VALUES(submitted_at),reviewed_at=NULL,reviewed_by=NULL,review_reason=NULL",
                ['worker'=>$worker,'nickname'=>$nickname]
            );
            return ['status'=>'pending'];
        });
    }

    public function page(string $status, int $page): array
    {
        $where = in_array($status, ['pending','approved','rejected','suspended'], true) ? 'WHERE a.status=:status' : '';
        $params = $where === '' ? [] : ['status'=>$status];
        $total = (int)$this->db->fetchValue("SELECT COUNT(*) FROM worker_order_discount_applications a $where", $params);
        $pages = max(1, (int)ceil($total / 20));
        $page = min($pages, max(1, $page));
        $offset = ($page - 1) * 20;
        $rows = $this->db->fetchAll(
            "SELECT a.*,w.display_name,w.public_id,w.verification_status
             FROM worker_order_discount_applications a
             JOIN worker_profiles w ON w.id=a.worker_id
             $where ORDER BY a.submitted_at DESC,a.worker_id DESC LIMIT 20 OFFSET $offset",
            $params
        );
        return ['rows'=>$rows,'page'=>$page,'pages'=>$pages,'total'=>$total];
    }

    public function review(int $worker, string $decision, string $reason, array $checks, int $admin): void
    {
        if ($worker < 1 || $admin < 1 || !in_array($decision, ['approved','rejected','suspended'], true)) {
            throw new RuntimeException('无效的师傅下单优惠审核操作。');
        }
        if ($decision === 'approved') {
            foreach (['wechat_added','nickname_valid','moments_verified'] as $key) {
                if (($checks[$key] ?? null) !== '1') throw new RuntimeException('请先核验微信好友、昵称及朋友圈三项条件。');
            }
        } elseif (trim($reason) === '') {
            throw new RuntimeException('请填写驳回或暂停原因。');
        }
        $this->db->transaction(function () use ($worker, $decision, $reason, $admin): void {
            $profile = $this->db->fetch('SELECT verification_status FROM worker_profiles WHERE id=:worker FOR UPDATE', ['worker'=>$worker]);
            $row = $this->db->fetch('SELECT status FROM worker_order_discount_applications WHERE worker_id=:worker FOR UPDATE', ['worker'=>$worker]);
            $current = $row['status'] ?? '';
            if ($current === $decision) return;
            $from = ['approved'=>['pending','suspended'],'rejected'=>['pending'],'suspended'=>['approved']];
            if (!in_array($current, $from[$decision], true) || ($decision === 'approved' && ($profile['verification_status'] ?? '') !== 'approved')) {
                throw new RuntimeException('申请状态或师傅入驻状态已变化，请刷新后重新处理。');
            }
            $approvedAt = $decision === 'approved' ? ', approved_at=COALESCE(approved_at,UTC_TIMESTAMP())' : '';
            $this->db->execute(
                "UPDATE worker_order_discount_applications SET status=:status,review_reason=:reason,
                   reviewed_at=UTC_TIMESTAMP(),reviewed_by=:admin $approvedAt WHERE worker_id=:worker",
                ['status'=>$decision,'reason'=>$reason,'admin'=>$admin,'worker'=>$worker]
            );
        });
    }

    /** An approved worker can use the merchant order workflow without changing their license status. */
    public function approvedAt(array $actor, bool $lock = false): ?string
    {
        $worker = $this->approvedWorker($actor, $lock);
        if (!$worker) return null;
        $date = $this->db->fetchValue(
            "SELECT approved_at FROM worker_order_discount_applications WHERE worker_id=:worker AND status='approved'".($lock ? ' FOR UPDATE' : ''),
            ['worker'=>$worker]
        );
        return is_string($date) && $date !== '' ? $date : null;
    }

    private function approvedWorker(array $actor, bool $lock = false): int
    {
        if (empty($actor['user_id']) || !empty($actor['is_demo_actor'])) return 0;
        return (int)$this->db->fetchValue(
            "SELECT id FROM worker_profiles WHERE user_id=:user AND verification_status='approved'
               AND cloud_record_mode<>'demo_local'".($lock ? ' FOR UPDATE' : ''),
            ['user'=>$actor['user_id']]
        );
    }
}
