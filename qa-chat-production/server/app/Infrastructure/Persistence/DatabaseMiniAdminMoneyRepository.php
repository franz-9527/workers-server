<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use WorkerAdmin\Contracts\MiniAdminMoneyRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Services\OrderNotificationService;

final class DatabaseMiniAdminMoneyRepository implements MiniAdminMoneyRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function listWithdrawals(int $page, int $pageSize, string $timezone): array
    {
        $offset = ($page - 1) * $pageSize;
        $where = "wr.status IN ('pending','approved','payment_failed')";
        $total = (int)$this->db->fetchValue("SELECT COUNT(*) FROM withdrawal_requests wr WHERE $where");
        $rows = $this->db->fetchAll(
            "SELECT wr.public_no,wr.amount_cents,wr.status,wr.review_note,wr.created_at,wp.display_name worker_name
             FROM withdrawal_requests wr
             LEFT JOIN worker_profiles wp ON wp.id=wr.worker_id
             WHERE $where ORDER BY wr.id DESC LIMIT ".(int)$pageSize." OFFSET ".(int)$offset
        );
        foreach ($rows as &$row) {
            $row['item_type'] = 'withdrawal';
            $row['created_at'] = $this->localTime($row['created_at'] ?? null, $timezone);
            $row['amount_text'] = '¥'.number_format(((int)$row['amount_cents']) / 100, 2);
            $row['status_text'] = $row['status'] === 'pending' ? '待审核' : ($row['status'] === 'approved' ? '已审核待付款' : '付款失败待处理');
        }
        unset($row);
        $pages = $total > 0 ? (int)ceil($total / $pageSize) : 0;
        return ['items'=>$rows,'pagination'=>['page'=>$page,'per_page'=>$pageSize,'total'=>$total,'total_pages'=>$pages,'has_more'=>$page<$pages]];
    }

    public function reviewWithdrawal(array $actor, string $publicNo, string $decision, string $note): array
    {
        if ($publicNo === '') throw new RuntimeException('提现申请不存在或已处理', 409);
        return $this->db->transaction(function(Database $db) use ($actor,$publicNo,$decision,$note): array {
            $request = $db->fetch(
                "SELECT * FROM withdrawal_requests WHERE BINARY public_no=BINARY :public_no AND status IN ('pending','approved','payment_failed') LIMIT 1 FOR UPDATE",
                ['public_no'=>$publicNo]
            );
            if (!$request) throw new RuntimeException('提现申请不存在或已处理', 409);
            if ($decision === 'approved') {
                if ($request['status'] !== 'pending') throw new RuntimeException('只有待审核提现可以审核通过', 409);
                $db->execute("UPDATE withdrawal_requests SET status='approved',reviewed_by=NULL,review_note=:note,reviewed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id", ['note'=>$note,'id'=>$request['id']]);
            } else {
                $freeze = $db->fetch(
                    "SELECT id,amount_cents FROM worker_balance_ledger WHERE worker_id=:worker_id AND business_type='withdrawal_request' AND business_id=:request_id AND entry_type='withdrawal_freeze' AND status='effective' LIMIT 1 FOR UPDATE",
                    ['worker_id'=>$request['worker_id'],'request_id'=>$request['id']]
                );
                if (!$freeze || (int)$freeze['amount_cents'] !== (int)$request['amount_cents']) {
                    throw new RuntimeException('提现冻结流水缺失或金额不一致，请到网页后台核对', 409);
                }
                $db->execute(
                    "INSERT IGNORE INTO worker_balance_ledger(worker_id,entry_type,direction,amount_cents,available_delta_cents,frozen_delta_cents,business_type,business_id,idempotency_key,description,status,created_by_admin_id,created_at)
                     VALUES(:worker_id,'withdrawal_release','transfer',:amount,:available_delta,:frozen_delta,'withdrawal_request',:request_id,:idempotency_key,:description,'effective',NULL,UTC_TIMESTAMP())",
                    [
                        'worker_id'=>$request['worker_id'],'amount'=>$request['amount_cents'],'available_delta'=>$request['amount_cents'],'frozen_delta'=>-((int)$request['amount_cents']),
                        'request_id'=>$request['id'],'idempotency_key'=>'withdrawal:'.$request['id'].':released','description'=>'小程序管理员驳回提现并解冻余额：'.$note,
                    ]
                );
                $db->execute("UPDATE withdrawal_requests SET status='rejected',reviewed_by=NULL,review_note=:note,reviewed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id", ['note'=>$note,'id'=>$request['id']]);
            }
            $db->execute(
                "INSERT INTO withdrawal_events(withdrawal_request_id,event_type,from_status,to_status,note,operator_type,operator_id,created_at)
                 VALUES(:id,'admin_transition',:from_status,:to_status,:note,'mini_admin',:operator,UTC_TIMESTAMP())",
                ['id'=>$request['id'],'from_status'=>$request['status'],'to_status'=>$decision,'note'=>$note,'operator'=>(int)($actor['user_id']??0)]
            );
            OrderNotificationService::queueWorkerWithdrawalResult($db,(int)$request['worker_id'],(string)$request['public_no'],$decision,$note,(int)$request['amount_cents']);
            return ['withdrawal_id'=>(string)$request['public_no'],'decision'=>$decision];
        });
    }

    private function localTime($value, string $timezone): ?string
    {
        if (!$value) return null;
        try {
            return (new DateTimeImmutable((string)$value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($timezone))->format('Y-m-d H:i');
        } catch (\Throwable $exception) {
            return (string)$value;
        }
    }
}
