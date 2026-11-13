<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use RuntimeException;
use WorkerAdmin\Contracts\WorkerCredentialAdminRepository;
use WorkerAdmin\Core\Database;

final class DatabaseWorkerCredentialAdminRepository implements WorkerCredentialAdminRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function page(string $search, string $status, int $page, int $perPage): array
    {
        $where = ["(rv.id IS NOT NULL OR pa.id IS NOT NULL OR EXISTS(SELECT 1 FROM worker_collection_code_items submitted_code WHERE submitted_code.worker_id=w.id))"];
        $params = [];
        if ($search !== '') {
            $where[] = '(w.display_name LIKE :q_name OR u.nickname LIKE :q_nickname OR CAST(u.public_id AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci LIKE :q_public OR CAST(w.public_id AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci LIKE :q_worker)';
            $like = '%'.$search.'%';
            $params = ['q_name'=>$like,'q_nickname'=>$like,'q_public'=>$like,'q_worker'=>$like];
        }
        if ($status === 'realname_pending') {
            $where[] = "rv.status='pending'";
        } elseif ($status === 'code_pending') {
            $where[] = "(SELECT ccp.status FROM worker_collection_code_items ccp WHERE ccp.worker_id=w.id ORDER BY ccp.updated_at DESC,ccp.id DESC LIMIT 1) IN ('pending','auto_approved')";
        } elseif ($status === 'payout_missing') {
            $where[] = 'pa.id IS NULL';
        }

        $join = " FROM worker_profiles w
            JOIN users u ON u.id=w.user_id
            LEFT JOIN worker_real_name_verifications rv ON rv.worker_id=w.id
            LEFT JOIN worker_payout_accounts pa ON pa.id=(
                SELECT pa2.id FROM worker_payout_accounts pa2
                WHERE pa2.worker_id=w.id AND pa2.status='active'
                ORDER BY pa2.is_default DESC,pa2.id DESC LIMIT 1
            )
            WHERE ".implode(' AND ', $where);
        $select = "SELECT w.id,w.public_id,w.display_name,w.mobile_ciphertext,w.verification_status,w.updated_at,
                u.public_id user_public_id,u.nickname,
                rv.id real_name_id,rv.real_name_masked,rv.real_name_ciphertext,rv.id_number_masked,rv.id_number_ciphertext,
                rv.status real_name_status,rv.review_reason real_name_reason,rv.submitted_at real_name_submitted_at,
                pa.account_type,pa.holder_name_ciphertext,pa.account_ciphertext,pa.updated_at payout_updated_at".$join;
        $count = 'SELECT COUNT(*)'.$join;
        $result = $this->pageRows(
            $select." ORDER BY GREATEST(w.updated_at,COALESCE(rv.updated_at,w.updated_at),COALESCE((SELECT MAX(cci.updated_at) FROM worker_collection_code_items cci WHERE cci.worker_id=w.id),w.updated_at)) DESC,w.id DESC",
            $count,
            $params,
            $page,
            $perPage
        );

        foreach ($result['rows'] as &$row) {
            $row['mobile_full'] = \decrypt_private_value((string)($row['mobile_ciphertext'] ?? ''), 'worker-mobile');
            $row['real_name_full'] = \decrypt_private_value((string)($row['real_name_ciphertext'] ?? ''), 'worker-real-name');
            $row['id_number_full'] = \decrypt_private_value((string)($row['id_number_ciphertext'] ?? ''), 'worker-id-number');
            $row['payout_holder_full'] = \decrypt_private_value((string)($row['holder_name_ciphertext'] ?? ''), 'worker-payout-name');
            $row['payout_account_full'] = \decrypt_private_value((string)($row['account_ciphertext'] ?? ''), 'worker-payout-account');
            $row['real_name_media'] = empty($row['real_name_id']) ? [] : $this->db->fetchAll(
                "SELECT m.id,l.purpose FROM media_links l
                 JOIN media_files m ON m.id=l.media_file_id AND m.status='active'
                 WHERE l.entity_type='worker_verification' AND l.entity_id=:id ORDER BY l.id",
                ['id'=>$row['real_name_id']]
            );
            $row['collection_code'] = $this->db->fetch(
                "SELECT cc.id,cc.status,cc.review_reason,cc.submitted_at,m.id collection_media_id
                 FROM worker_collection_code_items cc
                 LEFT JOIN media_files m ON m.id=cc.media_file_id AND m.status='active'
                 WHERE cc.worker_id=:worker_id ORDER BY cc.updated_at DESC,cc.id DESC LIMIT 1",
                ['worker_id'=>$row['id']]
            ) ?: null;
            unset(
                $row['mobile_ciphertext'], $row['real_name_ciphertext'], $row['id_number_ciphertext'],
                $row['holder_name_ciphertext'], $row['account_ciphertext']
            );
        }
        unset($row);

        $result['stats'] = [
            'total'=>(int)$this->db->fetchValue('SELECT COUNT(*) FROM worker_profiles'),
            'realname_pending'=>(int)$this->db->fetchValue("SELECT COUNT(*) FROM worker_real_name_verifications WHERE status='pending'"),
            'code_pending'=>(int)$this->db->fetchValue("SELECT COUNT(*) FROM worker_profiles w WHERE (SELECT ccp.status FROM worker_collection_code_items ccp WHERE ccp.worker_id=w.id ORDER BY ccp.updated_at DESC,ccp.id DESC LIMIT 1) IN ('pending','auto_approved')"),
            'payout_bound'=>(int)$this->db->fetchValue("SELECT COUNT(DISTINCT worker_id) FROM worker_payout_accounts WHERE status='active'"),
        ];
        return $result;
    }

    public function reviewRealName(int $workerId, string $decision, string $reason, int $adminId): void
    {
        $changed = $this->db->execute(
            "UPDATE worker_real_name_verifications
             SET status=:status,review_reason=:reason,reviewed_by=:admin,reviewed_at=NOW(),updated_at=NOW()
             WHERE worker_id=:worker_id AND status='pending'",
            ['status'=>$decision,'reason'=>$reason,'admin'=>$adminId,'worker_id'=>$workerId]
        );
        if ($changed < 1) throw new RuntimeException('该师傅没有待审核的实名认证。');
    }

    public function updateRealName(int $workerId, string $realName, string $idNumber): void
    {
        $changed = $this->db->execute(
            "UPDATE worker_real_name_verifications
             SET real_name_masked=:name_masked,real_name_ciphertext=:name_cipher,
                 id_number_masked=:id_masked,id_number_ciphertext=:id_cipher,id_number_hmac=:id_hmac,updated_at=UTC_TIMESTAMP()
             WHERE worker_id=:worker_id",
            [
                'name_masked'=>mb_substr($realName, 0, 1).'**',
                'name_cipher'=>\encrypt_private_value($realName, 'worker-real-name'),
                'id_masked'=>substr($idNumber, 0, 3).'***********'.substr($idNumber, -4),
                'id_cipher'=>\encrypt_private_value($idNumber, 'worker-id-number'),
                'id_hmac'=>\private_value_hmac($idNumber, 'worker-id-number'),
                'worker_id'=>$workerId,
            ]
        );
        if ($changed < 1 && !$this->db->fetchValue('SELECT id FROM worker_real_name_verifications WHERE worker_id=:id', ['id'=>$workerId])) {
            throw new RuntimeException('该师傅尚未提交实名认证资料。');
        }
    }

    public function reviewCollectionCode(int $id, string $decision, string $reason, int $adminId): void
    {
        $changed = $this->db->execute(
            "UPDATE worker_collection_code_items
             SET status=:status,review_reason=:reason,reviewed_at=UTC_TIMESTAMP(),reviewed_by=:admin_id,updated_at=UTC_TIMESTAMP()
             WHERE id=:id AND status IN ('pending','auto_approved','approved','rejected')",
            ['status'=>$decision,'reason'=>$reason !== '' ? $reason : null,'admin_id'=>$adminId,'id'=>$id]
        );
        if ($changed < 1) throw new RuntimeException('收款码记录不存在，或当前状态已经是所选结果。');
    }

    /** @return array<string,mixed> */
    private function pageRows(string $sql, string $countSql, array $params, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $total = (int)$this->db->fetchValue($countSql, $params);
        $pages = max(1, (int)ceil($total / $perPage));
        if ($page > $pages) $page = $pages;
        $offset = ($page - 1) * $perPage;
        $rows = $this->db->fetchAll($sql.' LIMIT '.(int)$perPage.' OFFSET '.(int)$offset, $params);
        return ['rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>$pages,'per_page'=>$perPage];
    }
}
