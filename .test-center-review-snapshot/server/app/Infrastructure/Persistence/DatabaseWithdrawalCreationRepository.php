<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use RuntimeException;
use WorkerAdmin\Contracts\WithdrawalCreationRepository;
use WorkerAdmin\Core\Database;

final class DatabaseWithdrawalCreationRepository implements WithdrawalCreationRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function isRealNameApproved(int $workerId): bool
    {
        return (int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM worker_real_name_verifications WHERE worker_id=:worker_id AND status='approved'",
            ['worker_id'=>$workerId]
        ) > 0;
    }

    public function createPending(int $workerId, int $amountCents): array
    {
        // 与旧 Controller 保持相同锁顺序：worker -> payout account -> balance facts。
        $this->db->fetch('SELECT id FROM worker_profiles WHERE id=:id FOR UPDATE', ['id'=>$workerId]);
        $account = $this->db->fetch(
            "SELECT * FROM worker_payout_accounts
             WHERE worker_id=:worker_id AND status='active'
             ORDER BY is_default DESC,id DESC LIMIT 1 FOR UPDATE",
            ['worker_id'=>$workerId]
        );
        if (!$account) throw new RuntimeException('请先设置支付宝收款账号');

        $available = (int)$this->db->fetchValue(
            "SELECT COALESCE(SUM(available_delta_cents),0)
             FROM worker_balance_ledger
             WHERE worker_id=:worker_id AND status='effective'",
            ['worker_id'=>$workerId]
        );
        if ($amountCents > $available) throw new RuntimeException('可提现余额不足');

        $publicNo = 'WT' . gmdate('ymdHis') . strtoupper(bin2hex(random_bytes(4)));
        $requestId = $this->db->insert(
            "INSERT INTO withdrawal_requests(
                public_no,worker_id,payout_account_id,amount_cents,
                account_type_snapshot,holder_name_masked_snapshot,holder_name_ciphertext_snapshot,
                account_masked_snapshot,account_ciphertext_snapshot,status,submitted_at,created_at,updated_at
             ) VALUES(
                :public_no,:worker_id,:account_id,:amount,
                :type,:name_masked,:name_cipher,:account_masked,:account_cipher,
                'pending',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP()
             )",
            [
                'public_no'=>$publicNo,
                'worker_id'=>$workerId,
                'account_id'=>$account['id'],
                'amount'=>$amountCents,
                'type'=>$account['account_type'],
                'name_masked'=>$account['holder_name_masked'],
                'name_cipher'=>$account['holder_name_ciphertext'],
                'account_masked'=>$account['account_masked'],
                'account_cipher'=>$account['account_ciphertext'],
            ]
        );

        $ledgerId = $this->db->insert(
            "INSERT INTO worker_balance_ledger(
                worker_id,entry_type,direction,amount_cents,available_delta_cents,frozen_delta_cents,
                business_type,business_id,idempotency_key,description,status,created_at
             ) VALUES(
                :worker_id,'withdrawal_freeze','transfer',:amount,:available,:frozen,
                'withdrawal_request',:request_id,:key,'提现申请冻结余额','effective',UTC_TIMESTAMP()
             )",
            [
                'worker_id'=>$workerId,
                'amount'=>$amountCents,
                'available'=>-$amountCents,
                'frozen'=>$amountCents,
                'request_id'=>$requestId,
                'key'=>'withdrawal:freeze:'.$requestId,
            ]
        );

        $this->db->insert(
            "INSERT INTO withdrawal_allocations(withdrawal_request_id,ledger_entry_id,amount_cents,created_at)
             VALUES(:request_id,:ledger_id,:amount,UTC_TIMESTAMP())",
            ['request_id'=>$requestId,'ledger_id'=>$ledgerId,'amount'=>$amountCents]
        );
        $this->db->insert(
            "INSERT INTO withdrawal_events(
                withdrawal_request_id,event_type,to_status,note,operator_type,operator_id,created_at
             ) VALUES(
                :request_id,'submitted','pending','师傅提交提现申请','worker',:worker_id,UTC_TIMESTAMP()
             )",
            ['request_id'=>$requestId,'worker_id'=>$workerId]
        );

        return ['id'=>$requestId,'public_no'=>$publicNo,'amount_cents'=>$amountCents];
    }
}
