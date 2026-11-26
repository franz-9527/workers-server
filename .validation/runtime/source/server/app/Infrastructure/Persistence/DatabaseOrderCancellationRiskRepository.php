<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Core\Database;
use WorkerAdmin\Contracts\OrderCancellationRiskRepository;
use WorkerAdmin\Domain\Order\CancellationRiskPolicy;
use WorkerAdmin\Domain\Order\OrderOperationException;

/** 2026-10-08：取消事实、原预约/取证信号与下单限制共用账号锁；不改变原退款执行器。 */
final class DatabaseOrderCancellationRiskRepository implements OrderCancellationRiskRepository
{
    private Database $db;

    public function __construct(Database $db) { $this->db = $db; }

    public function configuration(): array
    {
        $raw = $this->db->fetchValue('SELECT setting_value FROM system_settings WHERE setting_key=:key', ['key'=>'order.cancellation_risk']);
        $input = json_decode((string)$raw, true);
        return CancellationRiskPolicy::config(is_array($input) ? $input : []);
    }

    public function user(int $userId): ?array
    { return $this->db->fetch('SELECT id,public_id,nickname FROM users WHERE id=:id', ['id'=>$userId]); }

    public function saveConfiguration(array $rules): void
    {
        $this->db->execute("INSERT INTO system_settings(setting_key,setting_value,value_type,group_name,description,is_public,created_at,updated_at)
            VALUES('order.cancellation_risk',:value,'json','order','频繁取消下单风控',0,UTC_TIMESTAMP(),UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=UTC_TIMESTAMP()",
            ['value'=>json_encode(CancellationRiskPolicy::config($rules),JSON_UNESCAPED_UNICODE)]);
    }

    public function state(int $userId): array
    {
        $row = $this->db->fetch('SELECT blocked_until,last_restriction_id,UTC_TIMESTAMP() now_utc FROM order_cancellation_risk_accounts WHERE user_id=:id FOR UPDATE', ['id'=>$userId]);
        $active = $row && !empty($row['blocked_until']) && $row['blocked_until'] > $row['now_utc'];
        $until = $active ? (string)$row['blocked_until'] : null;
        $local = $until ? (new \DateTimeImmutable($until, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i') : null;
        return ['restricted'=>(bool)$active, 'expires_at_utc'=>$until, 'expires_at'=>$local,
            'message'=>$active ? '当前已暂停新下单，将于'.$local.'自动恢复。如有误判，请联系客服核实解除。' : '',
            'restriction_id'=>$active ? (int)$row['last_restriction_id'] : null];
    }

    // 调用方已有订单锁时按“订单→账号”顺序；草稿创建只锁账号，不锁其它已有订单。
    public function assertCanSubmit(int $userId, ?array $order = null): void
    {
        if ($order !== null && !$this->eligible($order)) return;
        $this->db->transaction(function () use ($userId): void {
            $this->lockAccount($userId);
            $state = $this->state($userId);
            if ($state['restricted']) throw new OrderOperationException($state['message'], 'ORDER_SUBMISSION_RESTRICTED', 403, $state);
        });
    }

    // 2026-10-08：支付回调只判断准入而不抛业务拒绝，确保真实到账仍然入账及原路退款。
    public function canPublish(array $order): bool
    {
        if (!$this->eligible($order)) return true;
        return $this->db->transaction(function () use ($order): bool {
            $this->lockAccount((int)$order['user_id']);
            return !$this->state((int)$order['user_id'])['restricted'];
        });
    }

    /** 必须在订单失效报价/指派前、同一取消事务内调用；仅取消成立后该事务才会提交。 */
    public function captureCancellation(array $order, string $responsibility = 'unclear', int $runEmptyCents = 0): void
    {
        if (!$this->eligible($order)) return;
        $this->db->transaction(function () use ($order,$responsibility,$runEmptyCents): void {
            $userId = (int)$order['user_id'];
            $this->lockAccount($userId);
            if (in_array($responsibility, ['worker','platform'], true)) {
                $this->db->execute('UPDATE order_cancellation_risk_scores SET excluded_at=UTC_TIMESTAMP(),exclusion_reason=:reason,updated_at=UTC_TIMESTAMP() WHERE order_id=:id', ['reason'=>$responsibility.'责任免计分','id'=>$order['id']]);
                return;
            }
            $quoted = (bool)$this->db->fetchValue("SELECT id FROM quotes WHERE order_id=:id AND worker_id>0 AND status IN ('active','selected') AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP()) LIMIT 1", ['id'=>$order['id']]);
            $dealt = !empty($order['selected_quote_id']) || (string)($order['assignment_status'] ?? '') === 'accepted';
            $config = $this->configuration();
            $base = CancellationRiskPolicy::score($quoted, $dealt, [], $runEmptyCents > 0, $config);
            $snapshot = json_encode(['quoted'=>$quoted,'dealt'=>$dealt,'run_empty_cents'=>$runEmptyCents,
                'assignment_id'=>$order['current_assignment_id'] ?? null,'worker_id'=>$order['assigned_worker_id'] ?? null,
                'responsibility'=>$responsibility,'config'=>$config], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $this->db->execute("INSERT INTO order_cancellation_risk_scores(order_id,user_id,base_score,score,cancelled_at,snapshot_json,created_at,updated_at)
                VALUES(:order_id,:user_id,:base,:base,COALESCE(:cancelled_at,UTC_TIMESTAMP()),:snapshot,UTC_TIMESTAMP(),UTC_TIMESTAMP())
                ON DUPLICATE KEY UPDATE base_score=GREATEST(base_score,VALUES(base_score)),updated_at=UTC_TIMESTAMP()",
                ['order_id'=>$order['id'],'user_id'=>$userId,'base'=>$base,'cancelled_at'=>$order['cancelled_at'] ?? null,'snapshot'=>$snapshot]);
            $this->recalculateOrder((int)$order['id'], $config);
            $this->evaluate($userId, $config);
        });
    }

    /** 原电话、预约和异常入口写入；没有实际取消则只记录信号，不计分。 */
    public function recordExistingSignal(int $orderId, int $workerId, string $kind, string $note = ''): void
    {
        if (!in_array($kind, ['dialed','contacted','complaint'], true)) throw new OrderOperationException('联系行为无效', 'RISK_SIGNAL_INVALID');
        $this->db->transaction(function () use ($orderId,$workerId,$kind,$note): void {
            $order = $this->db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE', ['id'=>$orderId]);
            if (!$order || !$this->eligible($order)) return;
            $workerUser = (int)$this->db->fetchValue('SELECT user_id FROM worker_profiles WHERE id=:id', ['id'=>$workerId]);
            $participated = $this->db->fetchValue('SELECT id FROM quotes WHERE order_id=:id AND worker_id=:worker LIMIT 1', ['id'=>$orderId,'worker'=>$workerId])
                || $this->db->fetchValue('SELECT id FROM assignments WHERE order_id=:id AND worker_id=:worker LIMIT 1', ['id'=>$orderId,'worker'=>$workerId]);
            if ($workerId < 1 || !$participated || $workerUser === (int)$order['user_id']) throw new OrderOperationException('无权记录本单联系行为', 'FORBIDDEN', 403);
            if ($kind !== 'complaint' && (string)$order['lifecycle_status'] !== 'active') throw new OrderOperationException('订单已结束，不能补记联系行为', 'ORDER_NOT_ACTIVE', 409);
            $userId = (int)$order['user_id'];
            $this->lockAccount($userId);
            $this->db->execute("INSERT IGNORE INTO order_cancellation_risk_signals(order_id,worker_id,signal_type,note,created_at)
                VALUES(:id,:worker,:kind,:note,UTC_TIMESTAMP())", ['id'=>$orderId,'worker'=>$workerId,'kind'=>$kind,'note'=>mb_substr($note,0,500)]);
            $config = $this->configuration();
            $this->recalculateOrder($orderId, $config);
            $this->evaluate($userId, $config);
        });
    }

    public function recordDial(array $actor, string $publicNo): void
    {
        $this->db->transaction(function () use ($actor,$publicNo): void {
            $order = $this->db->fetch('SELECT * FROM orders WHERE BINARY public_no=BINARY :no FOR UPDATE', ['no'=>$publicNo]);
            $worker = (int)($actor['worker_id'] ?? 0);
            if (!$order || $worker < 1 || (int)($order['assigned_worker_id'] ?? 0) !== $worker
                || !$this->db->fetchValue("SELECT id FROM assignments WHERE id=:assignment AND order_id=:id AND worker_id=:worker AND status IN ('accepted','completed') LIMIT 1", ['assignment'=>$order['current_assignment_id'] ?? 0,'id'=>$order['id'],'worker'=>$worker])) {
                throw new OrderOperationException('接受本单后才能拨打业主电话', 'FORBIDDEN', 403);
            }
            // 已完成后的正常售后拨号不属于取消主订单的风险，不因限单阻止联系。
            if ((string)$order['lifecycle_status'] === 'active') $this->recordExistingSignal((int)$order['id'], $worker, 'dialed');
        });
    }

    public function reviewData(int $userId): array
    {
        $scores=$this->db->fetchAll('SELECT s.*,o.public_no FROM order_cancellation_risk_scores s JOIN orders o ON o.id=s.order_id WHERE s.user_id=:id ORDER BY s.cancelled_at DESC LIMIT 50', ['id'=>$userId]);
        foreach ($scores as &$score) $score['signals']=$this->db->fetchAll('SELECT worker_id,signal_type,note,created_at,revoked_at FROM order_cancellation_risk_signals WHERE order_id=:id ORDER BY created_at,id', ['id'=>$score['order_id']]);
        unset($score);
        return ['state'=>$this->state($userId), 'scores'=>$scores,
            'restrictions'=>$this->db->fetchAll('SELECT * FROM order_cancellation_risk_restrictions WHERE user_id=:id ORDER BY id DESC LIMIT 20', ['id'=>$userId])];
    }

    public function restrictManually(int $userId, int $adminId, int $days, string $reason): void
    {
        $days=max(1,min(30,$days));
        $reason=trim($reason);
        if ($userId<1 || $adminId<1) throw new OrderOperationException('用户或管理员无效', 'MANUAL_RESTRICTION_INVALID');
        if (mb_strlen($reason)<2) throw new OrderOperationException('请填写人工限制原因', 'MANUAL_RESTRICTION_REASON_REQUIRED');
        $this->db->transaction(function () use ($userId,$adminId,$days,$reason): void {
            $account=$this->lockAccount($userId);
            if (!empty($account['blocked_until']) && $account['blocked_until']>$account['now_utc']) {
                throw new OrderOperationException('该用户当前已有生效中的下单限制，请先核实或解除', 'RESTRICTION_ALREADY_ACTIVE', 409);
            }
            $snapshot=json_encode(['rule_version'=>'manual-v1','source'=>'admin','admin_id'=>$adminId,'days'=>$days,'reason'=>mb_substr($reason,0,500)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $id=$this->db->insert('INSERT INTO order_cancellation_risk_restrictions(user_id,starts_at,expires_at,snapshot_json) VALUES(:user,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL '.(int)$days.' DAY),:snapshot)', ['user'=>$userId,'snapshot'=>$snapshot]);
            $this->db->execute('UPDATE order_cancellation_risk_accounts SET blocked_until=(SELECT expires_at FROM order_cancellation_risk_restrictions WHERE id=:restriction),last_restriction_id=:restriction,updated_at=UTC_TIMESTAMP() WHERE user_id=:user', ['restriction'=>$id,'user'=>$userId]);
        });
    }

    public function release(int $userId, int $adminId, string $reason, array $excludedOrderIds = [], ?int $expectedId = null): void
    {
        if ($adminId < 1 || mb_strlen(trim($reason)) < 2) throw new OrderOperationException('请填写解除原因', 'RELEASE_REASON_REQUIRED');
        $this->db->transaction(function () use ($userId,$adminId,$reason,$excludedOrderIds,$expectedId): void {
            $account = $this->lockAccount($userId);
            $id = (int)($account['last_restriction_id'] ?? 0);
            if ($id < 1 || ($expectedId !== null && $id !== $expectedId)) throw new OrderOperationException('限制记录已变化，请刷新重试', 'RESTRICTION_CHANGED', 409);
            $restriction = $this->db->fetch('SELECT * FROM order_cancellation_risk_restrictions WHERE id=:id FOR UPDATE', ['id'=>$id]);
            if (!empty($restriction['released_at'])) return;
            foreach (array_unique(array_map('intval', $excludedOrderIds)) as $orderId) {
                $this->db->execute('UPDATE order_cancellation_risk_scores SET excluded_at=UTC_TIMESTAMP(),exclusion_reason=:reason,updated_at=UTC_TIMESTAMP() WHERE order_id=:order AND user_id=:user', ['reason'=>mb_substr($reason,0,500),'order'=>$orderId,'user'=>$userId]);
            }
            if (!$this->manualRestriction($restriction)) $this->consume($userId,$id);
            $this->db->execute('UPDATE order_cancellation_risk_restrictions SET released_at=UTC_TIMESTAMP(),released_by_admin_id=:admin,release_reason=:reason WHERE id=:id', ['admin'=>$adminId,'reason'=>mb_substr($reason,0,500),'id'=>$id]);
            $this->db->execute('UPDATE order_cancellation_risk_accounts SET blocked_until=NULL,updated_at=UTC_TIMESTAMP() WHERE user_id=:id', ['id'=>$userId]);
        });
    }

    private function eligible(array $order): bool
    {
        return empty($order['is_demo']) && (string)($order['order_source_type'] ?? 'consumer') !== 'platform' && (int)($order['user_id'] ?? 0) > 0;
    }

    private function lockAccount(int $userId): array
    {
        $this->db->execute('INSERT INTO order_cancellation_risk_accounts(user_id,updated_at) VALUES(:id,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id)', ['id'=>$userId]);
        return $this->db->fetch('SELECT *,UTC_TIMESTAMP() now_utc FROM order_cancellation_risk_accounts WHERE user_id=:id FOR UPDATE', ['id'=>$userId]);
    }

    private function recalculateOrder(int $orderId, array $config): void
    {
        $score = $this->db->fetch('SELECT base_score,cancelled_at FROM order_cancellation_risk_scores WHERE order_id=:id FOR UPDATE', ['id'=>$orderId]);
        if (!$score) return;
        $rows = $this->db->fetchAll("SELECT signal_type FROM order_cancellation_risk_signals WHERE order_id=:id AND revoked_at IS NULL
            AND (signal_type='complaint' OR created_at<=:cancelled_at) FOR UPDATE", ['id'=>$orderId,'cancelled_at'=>$score['cancelled_at']]);
        $points = max((int)$score['base_score'], CancellationRiskPolicy::score(false,false,array_column($rows,'signal_type'),false,$config));
        $this->db->execute('UPDATE order_cancellation_risk_scores SET score=:score,updated_at=UTC_TIMESTAMP() WHERE order_id=:id', ['score'=>$points,'id'=>$orderId]);
    }

    private function evaluate(int $userId, array $config): void
    {
        $account = $this->db->fetch('SELECT *,UTC_TIMESTAMP() now_utc FROM order_cancellation_risk_accounts WHERE user_id=:id FOR UPDATE', ['id'=>$userId]);
        if (!empty($account['blocked_until']) && $account['blocked_until'] > $account['now_utc']) {
            $restriction=$this->db->fetch('SELECT snapshot_json FROM order_cancellation_risk_restrictions WHERE id=:id FOR UPDATE',['id'=>(int)$account['last_restriction_id']]);
            if (!$this->manualRestriction($restriction ?: [])) $this->consume($userId,(int)$account['last_restriction_id']);
            return;
        }
        // 使用当前读：取消事务此前可能已建立 RR 快照，账号锁等待后仍须看见另一单的最新提交。
        $rows = $this->db->fetchAll('SELECT order_id,score,cancelled_at,snapshot_json FROM order_cancellation_risk_scores WHERE user_id=:id AND excluded_at IS NULL AND consumed_restriction_id IS NULL AND score>0 AND cancelled_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL '.$config['long_days'].' DAY) FOR UPDATE', ['id'=>$userId]);
        $match = CancellationRiskPolicy::threshold($rows, (int)strtotime($account['now_utc'].' UTC'), $config);
        if (!$match) return;
        $facts=[];
        foreach ($rows as $row) $facts[]=['order_id'=>$row['order_id'],
            'cancellation'=>json_decode($row['snapshot_json'],true),
            'signals'=>$this->db->fetchAll('SELECT worker_id,signal_type,note,created_at,revoked_at FROM order_cancellation_risk_signals WHERE order_id=:id FOR UPDATE',['id'=>$row['order_id']])];
        $snapshot = json_encode(['rule_version'=>'2026-10-08','config'=>$config,'match'=>$match,'scores'=>$rows,'facts'=>$facts], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $id = $this->db->insert('INSERT INTO order_cancellation_risk_restrictions(user_id,starts_at,expires_at,snapshot_json) VALUES(:user,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 DAY),:snapshot)', ['user'=>$userId,'snapshot'=>$snapshot]);
        $this->db->execute('UPDATE order_cancellation_risk_accounts SET blocked_until=(SELECT expires_at FROM order_cancellation_risk_restrictions WHERE id=:restriction),last_restriction_id=:restriction,updated_at=UTC_TIMESTAMP() WHERE user_id=:user', ['restriction'=>$id,'user'=>$userId]);
        $this->consume($userId,$id);
    }

    private function manualRestriction(array $restriction): bool
    {
        $snapshot=json_decode((string)($restriction['snapshot_json'] ?? ''),true);
        return is_array($snapshot) && ($snapshot['source'] ?? '')==='admin';
    }

    private function consume(int $userId, int $restrictionId): void
    {
        if ($restrictionId < 1) return;
        $this->db->execute('UPDATE order_cancellation_risk_scores SET consumed_restriction_id=:restriction,updated_at=UTC_TIMESTAMP() WHERE user_id=:user AND consumed_restriction_id IS NULL AND excluded_at IS NULL AND score>0', ['restriction'=>$restrictionId,'user'=>$userId]);
    }
}
