<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use RuntimeException;
use WorkerAdmin\Contracts\WithdrawalCancellationRepository;
use WorkerAdmin\Core\Database;

final class DatabaseWithdrawalCancellationRepository implements WithdrawalCancellationRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function cancelPending(int $workerId, string $withdrawalNo): void
    {
        $request = $this->db->fetch(
            "SELECT * FROM withdrawal_requests
             WHERE public_no=:no AND worker_id=:worker_id
             FOR UPDATE",
            ['no'=>$withdrawalNo,'worker_id'=>$workerId]
        );
        if (!$request || (string)$request['status'] !== 'pending') {
            throw new RuntimeException('该提现申请已不能撤销');
        }

        $requestId = (int)$request['id'];
        $amount = (int)$request['amount_cents'];
        $this->db->execute(
            "UPDATE withdrawal_requests
             SET status='cancelled',updated_at=UTC_TIMESTAMP()
             WHERE id=:id",
            ['id'=>$requestId]
        );
        $this->db->insert(
            "INSERT INTO worker_balance_ledger(
                worker_id,entry_type,direction,amount_cents,available_delta_cents,frozen_delta_cents,
                business_type,business_id,idempotency_key,description,status,created_at
             ) VALUES(
                :worker_id,'withdrawal_release','transfer',:amount,:available,:frozen,
                'withdrawal_request',:request_id,:key,'撤销提现释放余额','effective',UTC_TIMESTAMP()
             )",
            [
                'worker_id'=>$workerId,
                'amount'=>$amount,
                'available'=>$amount,
                'frozen'=>-$amount,
                'request_id'=>$requestId,
                'key'=>'withdrawal:release:'.$requestId,
            ]
        );
        $this->db->insert(
            "INSERT INTO withdrawal_events(
                withdrawal_request_id,event_type,from_status,to_status,note,operator_type,operator_id,created_at
             ) VALUES(
                :id,'cancelled','pending','cancelled','师傅撤销申请','worker',:worker_id,UTC_TIMESTAMP()
             )",
            ['id'=>$requestId,'worker_id'=>$workerId]
        );
    }
}
