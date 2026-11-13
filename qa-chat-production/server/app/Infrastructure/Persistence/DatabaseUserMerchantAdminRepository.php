<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use RuntimeException;
use WorkerAdmin\Contracts\UserMerchantAdminRepository;
use WorkerAdmin\Core\Database;

final class DatabaseUserMerchantAdminRepository implements UserMerchantAdminRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function usersPage(string $search, string $riskFilter, int $page, int $perPage, bool $includeDemo): array
    {
        $riskConfig = (new DatabaseOrderCancellationRiskRepository($this->db))->configuration();
        $riskWindowDays = max(1, min(90, (int)($riskConfig['long_days'] ?? 30)));
        $where = ['1=1'];
        $params = [];
        if (!$includeDemo) $where[] = "u.public_id NOT LIKE '%DEMO%'";
        if ($search !== '') {
            $like = '%'.$search.'%';
            $where[] = '(u.nickname LIKE :q_nickname OR u.mobile_masked LIKE :q_mobile OR u.public_id LIKE :q_public_id)';
            $params += ['q_nickname'=>$like,'q_mobile'=>$like,'q_public_id'=>$like];
        }
        if ($riskFilter === 'restricted') {
            $where[] = 'ra.blocked_until IS NOT NULL AND ra.blocked_until>UTC_TIMESTAMP()';
        } elseif ($riskFilter === 'attention') {
            $where[] = "EXISTS (SELECT 1 FROM order_cancellation_risk_scores rsf WHERE rsf.user_id=u.id AND rsf.excluded_at IS NULL AND rsf.consumed_restriction_id IS NULL AND rsf.score>0 AND rsf.cancelled_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL ".$riskWindowDays." DAY))";
        }

        $base = " FROM users u
            LEFT JOIN user_membership_grants mg ON mg.id=(SELECT mg2.id FROM user_membership_grants mg2 WHERE mg2.user_id=u.id ORDER BY mg2.id DESC LIMIT 1)
            LEFT JOIN admin_users a ON a.id=mg.granted_by
            LEFT JOIN order_cancellation_risk_accounts ra ON ra.user_id=u.id
            WHERE ".implode(' AND ', $where);
        $riskScoreSql = "COALESCE((SELECT SUM(rs.score) FROM order_cancellation_risk_scores rs WHERE rs.user_id=u.id AND rs.excluded_at IS NULL AND rs.consumed_restriction_id IS NULL AND rs.score>0 AND rs.cancelled_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL ".$riskWindowDays." DAY)),0)";
        $lastRiskSql = "(SELECT MAX(rs2.cancelled_at) FROM order_cancellation_risk_scores rs2 WHERE rs2.user_id=u.id AND rs2.excluded_at IS NULL AND rs2.score>0)";
        $orderBy = $riskFilter === 'attention'
            ? ' ORDER BY active_risk_score DESC,last_risk_at DESC,u.id DESC'
            : ($riskFilter === 'restricted' ? ' ORDER BY ra.blocked_until DESC,u.id DESC' : ' ORDER BY u.id DESC');
        $rows = $this->pageRows(
            "SELECT u.id,u.public_id,u.nickname,u.mobile_masked,u.mobile_ciphertext,u.status,u.cloud_user_id,u.last_synced_at,u.created_at,
                    mg.public_no membership_public_no,mg.status membership_status,mg.starts_at membership_starts_at,
                    mg.expires_at membership_expires_at,mg.cloud_card_id membership_card_id,a.real_name membership_granted_by,
                    ".$riskScoreSql." active_risk_score,".$lastRiskSql." last_risk_at,ra.blocked_until risk_blocked_until,ra.last_restriction_id risk_restriction_id".$base.$orderBy,
            'SELECT COUNT(*)'.$base,
            $params,
            $page,
            $perPage
        );
        $now = time();
        $shanghai = new \DateTimeZone('Asia/Shanghai');
        foreach ($rows['rows'] as &$row) {
            $full = \decrypt_private_value((string)($row['mobile_ciphertext'] ?? ''), 'user-mobile');
            if ($full !== '') $row['mobile_masked'] = $full;
            unset($row['mobile_ciphertext']);
            $blockedUntil = trim((string)($row['risk_blocked_until'] ?? ''));
            $blockedTimestamp = $blockedUntil !== '' ? strtotime($blockedUntil.' UTC') : false;
            $restricted = $blockedTimestamp !== false && $blockedTimestamp > $now;
            $expiresAt = null;
            if ($restricted) {
                $expiresAt = (new \DateTimeImmutable($blockedUntil, new \DateTimeZone('UTC')))->setTimezone($shanghai)->format('Y-m-d H:i');
            }
            $row['order_risk'] = [
                'restricted'=>$restricted,
                'expires_at_utc'=>$restricted ? $blockedUntil : null,
                'expires_at'=>$expiresAt,
                'message'=>$restricted ? '当前已暂停新下单，将于'.$expiresAt.'自动恢复。如有误判，请联系客服核实解除。' : '',
                'restriction_id'=>$restricted ? (int)($row['risk_restriction_id'] ?? 0) : null,
            ];
            $active = (string)($row['membership_status'] ?? '') === 'active'
                && !empty($row['membership_expires_at'])
                && strtotime((string)$row['membership_expires_at'].' UTC') > $now;
            $row['membership_active'] = $active;
            $row['membership_status_text'] = $active
                ? '有效会员'
                : ((string)($row['membership_status'] ?? '') === 'revoked'
                    ? '已撤销'
                    : ((string)($row['membership_status'] ?? '') === 'expired' ? '已过期' : '非会员'));
        }
        unset($row);

        $demoWhere = $includeDemo ? '1=1' : "u.public_id NOT LIKE '%DEMO%'";
        $rows['risk_stats'] = [
            'attention'=>(int)$this->db->fetchValue("SELECT COUNT(DISTINCT u.id) FROM users u WHERE ".$demoWhere." AND EXISTS (SELECT 1 FROM order_cancellation_risk_scores rs WHERE rs.user_id=u.id AND rs.excluded_at IS NULL AND rs.consumed_restriction_id IS NULL AND rs.score>0 AND rs.cancelled_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL ".$riskWindowDays." DAY))"),
            'restricted'=>(int)$this->db->fetchValue("SELECT COUNT(*) FROM order_cancellation_risk_accounts ra INNER JOIN users u ON u.id=ra.user_id WHERE ".$demoWhere." AND ra.blocked_until IS NOT NULL AND ra.blocked_until>UTC_TIMESTAMP()"),
        ];
        return $rows;
    }

    public function membershipTarget(int $userId, string $appId): ?array
    {
        $user = $this->db->fetch('SELECT id,public_id,nickname,status FROM users WHERE id=:id LIMIT 1', ['id'=>$userId]);
        if (!$user) return null;
        $identity = $this->db->fetch(
            "SELECT openid FROM wechat_identities
             WHERE user_id=:user_id AND status='active' AND BINARY app_id=BINARY :app_id
             ORDER BY id DESC LIMIT 1",
            ['user_id'=>$userId,'app_id'=>$appId]
        );
        $user['openid'] = (string)($identity['openid'] ?? '');
        return $user;
    }

    public function saveMembershipGrant(int $userId, int $adminId, string $cardId, string $proofHmac, string $expiresAt, string $activationCode): int
    {
        $codeHmac = \private_value_hmac($activationCode, 'membership-activation-code');
        return $this->db->transaction(function (Database $db) use ($userId, $adminId, $cardId, $proofHmac, $expiresAt, $codeHmac): int {
            $locked = $db->fetch('SELECT id FROM users WHERE id=:id FOR UPDATE', ['id'=>$userId]);
            if (!$locked) throw new RuntimeException('用户不存在。');
            $db->execute(
                "UPDATE user_membership_grants
                 SET status='revoked',revoked_by=:admin_id,revoked_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
                 WHERE user_id=:user_id AND status='active'",
                ['admin_id'=>$adminId,'user_id'=>$userId]
            );
            return $db->insert(
                "INSERT INTO user_membership_grants
                 (public_no,user_id,cloud_card_id,activation_code_hmac,cloud_proof_hmac,status,starts_at,expires_at,granted_by,revoked_by,revoked_at,created_at,updated_at)
                 VALUES(:public_no,:user_id,:card_id,:code_hmac,:proof,'active',UTC_TIMESTAMP(),:expires,:admin_id,NULL,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                [
                    'public_no'=>\public_id(),'user_id'=>$userId,'card_id'=>$cardId,'code_hmac'=>$codeHmac,
                    'proof'=>strtolower($proofHmac),'expires'=>$expiresAt,'admin_id'=>$adminId,
                ]
            );
        });
    }

    public function revokeMembership(int $userId, int $adminId): int
    {
        return $this->db->execute(
            "UPDATE user_membership_grants
             SET status='revoked',revoked_by=:admin_id,revoked_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
             WHERE user_id=:user_id AND status='active'",
            ['admin_id'=>$adminId,'user_id'=>$userId]
        );
    }

    public function merchantsPage(string $search, string $status, int $page, int $perPage): array
    {
        $where = ['1=1'];
        $params = [];
        if ($search !== '') {
            $like = '%'.$search.'%';
            $where[] = '(mp.business_name LIKE :q_name OR u.nickname LIKE :q_nickname OR u.public_id LIKE :q_public)';
            $params += ['q_name'=>$like,'q_nickname'=>$like,'q_public'=>$like];
        }
        if ($status !== '') {
            $where[] = 'mp.verification_status=:status';
            $params['status'] = $status;
        }
        $result = $this->pageRows(
            "SELECT mp.*,u.public_id AS user_public_id,u.nickname,u.mobile_ciphertext,m.id AS license_media_id,
                    (SELECT COUNT(*) FROM orders o WHERE o.merchant_profile_id=mp.id OR (o.user_id=mp.user_id AND o.order_source_type='merchant')) AS merchant_order_count,
                    (SELECT COUNT(*) FROM merchant_risk_events re WHERE re.user_id=mp.user_id AND re.status='new') AS pending_risk_count
             FROM merchant_profiles mp INNER JOIN users u ON u.id=mp.user_id
             LEFT JOIN media_files m ON m.id=mp.license_media_file_id AND m.status='active'
             WHERE ".implode(' AND ', $where)." ORDER BY FIELD(mp.verification_status,'pending','approved','rejected','suspended','draft','none'),mp.updated_at DESC",
            'SELECT COUNT(*) FROM merchant_profiles mp INNER JOIN users u ON u.id=mp.user_id WHERE '.implode(' AND ', $where),
            $params,
            $page,
            $perPage
        );
        foreach ($result['rows'] as &$row) {
            $row['mobile_full'] = \decrypt_private_value((string)($row['mobile_ciphertext'] ?? ''), 'user-mobile');
            $row['credit_code_full'] = \decrypt_private_value((string)($row['credit_code_ciphertext'] ?? ''), 'merchant-credit-code');
            unset($row['mobile_ciphertext'], $row['credit_code_ciphertext'], $row['credit_code_hmac']);
        }
        unset($row);
        $result['stats'] = [
            'total'=>(int)$this->db->fetchValue('SELECT COUNT(*) FROM merchant_profiles'),
            'pending'=>(int)$this->db->fetchValue("SELECT COUNT(*) FROM merchant_profiles WHERE verification_status='pending'"),
            'approved'=>(int)$this->db->fetchValue("SELECT COUNT(*) FROM merchant_profiles WHERE verification_status='approved'"),
            'suspected'=>(int)$this->db->fetchValue("SELECT COUNT(*) FROM merchant_profiles WHERE recognition_level IN ('suspected','confirmed') AND verification_status<>'approved'"),
        ];
        return $result;
    }

    public function reviewMerchant(int $merchantId, string $decision, string $reason, int $adminId): void
    {
        $profile = $this->db->fetch('SELECT id,verification_status,license_media_file_id FROM merchant_profiles WHERE id=:id', ['id'=>$merchantId]);
        if (!$profile) throw new RuntimeException('商家认证记录不存在。');
        if ($decision === 'approved' && empty($profile['license_media_file_id'])) {
            throw new RuntimeException('该商家尚未上传营业执照，不能审核通过。');
        }
        $recognition = $decision === 'approved' ? 'verified' : ($decision === 'suspended' ? 'suspected' : null);
        if ($recognition === null) {
            $this->db->execute(
                "UPDATE merchant_profiles SET verification_status='rejected',review_reason=:reason,reviewed_at=UTC_TIMESTAMP(),reviewed_by=:admin_id,updated_at=UTC_TIMESTAMP() WHERE id=:id",
                ['reason'=>$reason !== '' ? $reason : '资料未通过审核','admin_id'=>$adminId,'id'=>$merchantId]
            );
            return;
        }
        $this->db->execute(
            'UPDATE merchant_profiles SET verification_status=:status,recognition_level=:recognition,review_reason=:reason,reviewed_at=UTC_TIMESTAMP(),reviewed_by=:admin_id,updated_at=UTC_TIMESTAMP() WHERE id=:id',
            ['status'=>$decision,'recognition'=>$recognition,'reason'=>$reason !== '' ? $reason : null,'admin_id'=>$adminId,'id'=>$merchantId]
        );
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
