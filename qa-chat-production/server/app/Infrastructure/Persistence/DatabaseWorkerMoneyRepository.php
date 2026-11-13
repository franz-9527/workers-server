<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;
use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Contracts\WorkerMoneyRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Services\OrderNotificationService;

/** 师傅侧 Money 读模型、收款账户和线下收款持久化。 */
final class DatabaseWorkerMoneyRepository implements WorkerMoneyRepository
{
    private Database $db;
    private PrivateValueCipher $cipher;
    private SettingsProvider $settings;

    public function __construct(Database $db, PrivateValueCipher $cipher, SettingsProvider $settings)
    {
        $this->db = $db;
        $this->cipher = $cipher;
        $this->settings = $settings;
    }

    public function financeSummary(array $actor, string $section, int $page, int $limit): array
    {
        $workerId = (int)($actor['worker_id'] ?? 0);
        $page = max(1, $page);
        $limit = max(1, min(50, $limit));
        $offset = ($page - 1) * $limit;
        $receivables = [];
        $balanceEntries = [];
        $withdrawals = [];
        $pagination = [
            'receivables'=>['page'=>$page,'has_more'=>false],
            'balance'=>['page'=>$page,'has_more'=>false],
            'withdrawals'=>['page'=>$page,'has_more'=>false],
        ];

        if (in_array($section, ['all','receivables'], true)) {
            $receivables = $this->db->fetchAll(
                "SELECT c.public_no,o.public_no order_no,c.final_amount_cents_snapshot,c.rate_bp_snapshot,
                        c.min_fee_cents_snapshot,c.gross_commission_cents,c.booking_fee_offset_cents,
                        c.amount_due_cents,c.amount_paid_cents,c.is_provisional,c.finalized_at,c.overpaid_cents,
                        c.worker_credit_cents,c.status,c.due_at,c.created_at
                 FROM commission_receivables c
                 JOIN orders o ON o.id=c.order_id
                 WHERE c.worker_id=:worker_id
                 ORDER BY IF(c.amount_paid_cents<c.amount_due_cents AND c.status NOT IN ('paid','settled'),0,1),
                          c.created_at DESC,c.id DESC
                 LIMIT " . ($limit + 1) . ' OFFSET ' . $offset,
                ['worker_id'=>$workerId]
            );
            $pagination['receivables']['has_more'] = count($receivables) > $limit;
            $receivables = array_slice($receivables, 0, $limit);
            foreach ($receivables as &$row) {
                $row['is_provisional'] = (bool)$row['is_provisional'];
                $row['finalized_at'] = $this->localTime($row['finalized_at'] ?? null);
                $row['unpaid_cents'] = max(0, (int)$row['amount_due_cents'] - (int)$row['amount_paid_cents']);
                $row['due_at'] = $this->localTime($row['due_at'] ?? null);
                $row['stage_text'] = $row['is_provisional'] ? '预计服务费（验收前可调整）' : '正式平台服务费';
            }
            unset($row);
        }

        $balance = $this->db->fetch(
            "SELECT COALESCE(SUM(available_delta_cents),0) available_cents,
                    COALESCE(SUM(frozen_delta_cents),0) frozen_cents
             FROM worker_balance_ledger
             WHERE worker_id=:worker_id AND status='effective'",
            ['worker_id'=>$workerId]
        );
        if (in_array($section, ['all','balance'], true)) {
            $balanceEntries = $this->db->fetchAll(
                "SELECT entry_type,amount_cents,available_delta_cents,frozen_delta_cents,description,created_at
                 FROM worker_balance_ledger
                 WHERE worker_id=:worker_id AND status='effective'
                 ORDER BY id DESC LIMIT " . ($limit + 1) . ' OFFSET ' . $offset,
                ['worker_id'=>$workerId]
            );
            $pagination['balance']['has_more'] = count($balanceEntries) > $limit;
            $balanceEntries = array_slice($balanceEntries, 0, $limit);
            foreach ($balanceEntries as &$entry) {
                $entry['created_at'] = $this->localTime($entry['created_at'] ?? null);
            }
            unset($entry);
        }

        $account = $this->db->fetch(
            "SELECT id,account_type,holder_name_masked,account_masked,status
             FROM worker_payout_accounts
             WHERE worker_id=:worker_id AND status='active'
             ORDER BY is_default DESC,id DESC LIMIT 1",
            ['worker_id'=>$workerId]
        );
        if ($account) $account['id'] = (int)$account['id'];

        if (in_array($section, ['all','withdrawals'], true)) {
            $withdrawals = $this->db->fetchAll(
                'SELECT public_no,amount_cents,status,review_note,submitted_at,reviewed_at,paid_at
                 FROM withdrawal_requests WHERE worker_id=:worker_id
                 ORDER BY id DESC LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset,
                ['worker_id'=>$workerId]
            );
            $pagination['withdrawals']['has_more'] = count($withdrawals) > $limit;
            $withdrawals = array_slice($withdrawals, 0, $limit);
            foreach ($withdrawals as &$row) {
                $row['submitted_at'] = $this->localTime($row['submitted_at'] ?? null);
                $row['reviewed_at'] = $this->localTime($row['reviewed_at'] ?? null);
                $row['paid_at'] = $this->localTime($row['paid_at'] ?? null);
            }
            unset($row);
        }

        $realName = $this->db->fetch(
            'SELECT id,real_name_masked,id_number_masked,status,review_reason,submitted_at,reviewed_at,ocr_payload_ciphertext
             FROM worker_real_name_verifications WHERE worker_id=:worker_id LIMIT 1',
            ['worker_id'=>$workerId]
        );
        if ($realName) {
            $realName['submitted_at'] = $this->localTime($realName['submitted_at'] ?? null);
            $realName['reviewed_at'] = $this->localTime($realName['reviewed_at'] ?? null);
            $realName['media'] = [];
            $mediaRows = $this->db->fetchAll(
                "SELECT l.purpose,m.public_id
                 FROM media_links l
                 JOIN media_files m ON m.id=l.media_file_id AND m.status='active'
                 WHERE l.entity_type='worker_verification' AND l.entity_id=:id ORDER BY l.id",
                ['id'=>$realName['id']]
            );
            foreach ($mediaRows as $mediaRow) {
                $realName['media'][$mediaRow['purpose']] = [
                    'id'=>$mediaRow['public_id'],
                    'url'=>$this->signedMediaUrl(
                        (string)$mediaRow['public_id'],
                        $actor,
                        'worker_verification',
                        (int)$realName['id']
                    ),
                ];
            }
            $payload = [];
            if (!empty($realName['ocr_payload_ciphertext'])) {
                try {
                    $decoded = json_decode($this->cipher->decrypt((string)$realName['ocr_payload_ciphertext'], 'worker-realname-ocr'), true);
                    if (is_array($decoded)) $payload = $decoded;
                } catch (Throwable $exception) { /* 当前认证继续展示，无法读取的草稿不覆盖已通过资料。 */ }
            }
            $isReauth = $realName['status'] === 'approved';
            $draftOcr = $isReauth ? ($payload['reauth_draft'] ?? []) : $payload;
            $draftMedia = [];
            foreach (['id_front','id_back'] as $purpose) {
                $source = ($isReauth ? 'draft_' : '') . $purpose;
                if (isset($realName['media'][$source])) $draftMedia[$purpose] = $realName['media'][$source];
            }
            if ($realName['status'] !== 'pending' && ($draftOcr || $draftMedia)) {
                $realName['draft'] = [
                    'status' => 'draft',
                    'media' => $draftMedia,
                    'real_name' => (string)($draftOcr['draft_fields']['real_name'] ?? $draftOcr['front']['normalized']['name'] ?? ''),
                    'id_number' => (string)($draftOcr['draft_fields']['id_number'] ?? $draftOcr['front']['normalized']['id'] ?? ''),
                    'ocr_front_ok' => isset($draftOcr['front'], $draftMedia['id_front']),
                    'ocr_back_ok' => isset($draftOcr['back'], $draftMedia['id_back']),
                ];
            }
            // 当前资料与草稿照片分开；历史和 OCR 原始回包不出现在本人表单响应。
            $realName['media'] = array_intersect_key($realName['media'], array_flip(['id_front','id_back']));
            unset($realName['id'], $realName['ocr_payload_ciphertext']);
        }

        $collectionCode = $this->db->fetch(
            "SELECT c.id code_id,c.status,c.review_reason,c.submitted_at,c.reviewed_at,m.public_id
             FROM worker_collection_code_items c
             LEFT JOIN media_files m ON m.id=c.media_file_id AND m.status='active'
             WHERE c.worker_id=:worker_id
             ORDER BY c.updated_at DESC,c.id DESC LIMIT 1",
            ['worker_id'=>$workerId]
        );
        if ($collectionCode) {
            $collectionCode['submitted_at'] = $this->localTime($collectionCode['submitted_at'] ?? null);
            $collectionCode['reviewed_at'] = $this->localTime($collectionCode['reviewed_at'] ?? null);
            $collectionCode['url'] = !empty($collectionCode['public_id'])
                ? $this->signedMediaUrl(
                    (string)$collectionCode['public_id'],
                    $actor,
                    'worker_collection_code',
                    (int)$collectionCode['code_id']
                )
                : '';
            $collectionCode['status_text'] = [
                'draft'=>'待上传','pending'=>'处理中','auto_approved'=>'已启用',
                'approved'=>'已启用','rejected'=>'需重新上传',
            ][$collectionCode['status']] ?? '处理中';
            if ($collectionCode['status'] !== 'rejected') $collectionCode['review_reason'] = '';
            unset($collectionCode['public_id'], $collectionCode['code_id']);
        } else {
            $collectionCode = ['status'=>'none','status_text'=>'未上传','review_reason'=>''];
        }

        return [
            'receivables'=>$receivables,
            'balance'=>[
                'available_cents'=>(int)($balance['available_cents'] ?? 0),
                'frozen_cents'=>(int)($balance['frozen_cents'] ?? 0),
            ],
            'balance_entries'=>$balanceEntries,
            'account'=>$account ?: null,
            'withdrawals'=>$withdrawals,
            'pagination'=>$pagination,
            'real_name'=>$realName ?: ['status'=>'none'],
            'collection_codes'=>['default'=>$collectionCode],
            'collection_code'=>$collectionCode,
        ];
    }

    public function findOrderByPublicNo(string $publicNo): ?array
    {
        $row = $this->db->fetch('SELECT * FROM orders WHERE public_no=:id LIMIT 1', ['id'=>trim($publicNo)]);
        return $row ?: null;
    }

    public function savePayoutAccount(int $workerId, string $holderName, string $account): void
    {
        $hmac = $this->cipher->hmac($account, 'worker-payout-account');
        $this->db->execute(
            'UPDATE worker_payout_accounts SET is_default=0,updated_at=UTC_TIMESTAMP() WHERE worker_id=:worker_id',
            ['worker_id'=>$workerId]
        );
        $existing = $this->db->fetch(
            'SELECT id FROM worker_payout_accounts WHERE worker_id=:worker_id AND account_hmac=:hmac FOR UPDATE',
            ['worker_id'=>$workerId,'hmac'=>$hmac]
        );
        $values = [
            'worker_id'=>$workerId,
            'name_masked'=>mb_substr($holderName, 0, 1) . '**',
            'name_cipher'=>$this->cipher->encrypt($holderName, 'worker-payout-name'),
            'account_masked'=>substr($account, 0, 3) . '****' . substr($account, -4),
            'account_cipher'=>$this->cipher->encrypt($account, 'worker-payout-account'),
            'hmac'=>$hmac,
        ];
        if ($existing) {
            $this->db->execute(
                "UPDATE worker_payout_accounts
                 SET holder_name_masked=:name_masked,holder_name_ciphertext=:name_cipher,
                     account_masked=:account_masked,account_ciphertext=:account_cipher,
                     status='active',is_default=1,updated_at=UTC_TIMESTAMP()
                 WHERE id=:id",
                [
                    'name_masked'=>$values['name_masked'],
                    'name_cipher'=>$values['name_cipher'],
                    'account_masked'=>$values['account_masked'],
                    'account_cipher'=>$values['account_cipher'],
                    'id'=>(int)$existing['id'],
                ]
            );
            return;
        }
        $this->db->insert(
            "INSERT INTO worker_payout_accounts(
                worker_id,account_type,holder_name_masked,holder_name_ciphertext,
                account_masked,account_ciphertext,account_hmac,status,is_default,created_at,updated_at
             ) VALUES(
                :worker_id,'alipay',:name_masked,:name_cipher,:account_masked,:account_cipher,
                :hmac,'active',1,UTC_TIMESTAMP(),UTC_TIMESTAMP()
             )",
            $values
        );
    }

    public function createOfflineDelay(array $actor, array $order, string $reason, string $expectedCollectAt): void
    {
        $workerId = (int)($actor['worker_id'] ?? 0);
        $lockedOrder = $this->db->fetch(
            'SELECT lifecycle_status,fulfillment_status FROM orders WHERE id=:id FOR UPDATE',
            ['id'=>$order['id']]
        );
        $assignment = $this->db->fetch(
            "SELECT * FROM assignments
             WHERE order_id=:order_id AND worker_id=:worker_id AND status='accepted'
             ORDER BY id DESC LIMIT 1 FOR UPDATE",
            ['order_id'=>$order['id'],'worker_id'=>$workerId]
        );
        if (!$lockedOrder || !$assignment
            || !in_array((string)$lockedOrder['lifecycle_status'], ['active','completed'], true)
            || !in_array((string)$lockedOrder['fulfillment_status'], ['submitted','accepted'], true)) {
            throw new RuntimeException('请先提交完工，只有完工后的线下尾款才能登记延期');
        }
        if ($this->db->fetchValue(
            "SELECT id FROM change_requests WHERE order_id=:order_id AND status='pending' LIMIT 1",
            ['order_id'=>$order['id']]
        )) {
            throw new RuntimeException('仍有加价申请等待用户处理，请先完成费用协商');
        }
        $unresolved = (int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM offline_payment_delay_requests
             WHERE order_id=:order_id AND worker_id=:worker_id
               AND status IN ('pending','acknowledged','reported','bad_debt')",
            ['order_id'=>$order['id'],'worker_id'=>$workerId]
        );
        if ($unresolved > 0) throw new RuntimeException('该订单已登记过尾款延期或坏账，无需重复提交');

        $amount = max(0, (int)$assignment['final_amount_cents_snapshot'] - (int)$assignment['booking_fee_cents_snapshot']);
        if ($amount < 1) throw new RuntimeException('该订单没有需要线下收取的尾款，不能登记延期');
        $this->db->insert(
            "INSERT INTO offline_payment_delay_requests(
                order_id,worker_id,amount_cents_snapshot,reason,expected_collect_at,status,submitted_at,created_at,updated_at
             ) VALUES(
                :order_id,:worker_id,:amount,:reason,:expected,'reported',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP()
             )",
            [
                'order_id'=>$order['id'],
                'worker_id'=>$workerId,
                'amount'=>$amount,
                'reason'=>$reason,
                'expected'=>$expectedCollectAt === '' ? null : gmdate('Y-m-d H:i:s', (int)strtotime($expectedCollectAt)),
            ]
        );
        $this->event(
            (int)$order['id'],
            'offline_collection_delayed',
            'worker',
            $workerId,
            ['amount_cents'=>$amount,'expected_collect_at'=>$expectedCollectAt ?: null]
        );
    }

    public function submitOfflineAdjustment(array $actor, array $order, int $requestedTailCents, string $reason): string
    {
        $workerId = (int)($actor['worker_id'] ?? 0);
        $locked = $this->db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE', ['id'=>$order['id']]);
        $assignment = $this->db->fetch(
            "SELECT * FROM assignments
             WHERE order_id=:order_id AND worker_id=:worker_id AND status IN ('accepted','completed')
             ORDER BY id DESC LIMIT 1 FOR UPDATE",
            ['order_id'=>$order['id'],'worker_id'=>$workerId]
        );
        if (!$locked || !$assignment || !in_array((string)$locked['fulfillment_status'], ['submitted','accepted'], true)) {
            throw new RuntimeException('提交完工后才能申请调整实际尾款');
        }
        if ($this->db->fetchValue(
            "SELECT id FROM offline_collection_adjustment_requests
             WHERE order_id=:id AND status='auto_approved' LIMIT 1",
            ['id'=>$order['id']]
        )) {
            throw new RuntimeException('本次实际尾款已经生效，请勿重复提交');
        }

        $receivable = $this->db->fetch(
            'SELECT * FROM commission_receivables WHERE order_id=:id FOR UPDATE',
            ['id'=>$order['id']]
        );
        if ($receivable && (int)$receivable['amount_paid_cents'] > 0) {
            throw new RuntimeException('平台服务费已发生支付，请联系管理员人工核算，不能直接修改尾款');
        }
        $receipts = $this->db->fetchAll(
            "SELECT m.id FROM media_links l
             JOIN media_files m ON m.id=l.media_file_id AND m.status='active'
             WHERE l.entity_type='order' AND l.entity_id=:id AND l.purpose='collection_receipt'
             ORDER BY l.id",
            ['id'=>$order['id']]
        );
        if (!$receipts) throw new RuntimeException('请先上传实际收款凭证');

        $originalTail = max(
            0,
            (int)$assignment['final_amount_cents_snapshot'] - (int)$assignment['booking_fee_cents_snapshot']
        );
        if ($originalTail === $requestedTailCents) {
            throw new RuntimeException('实际尾款与当前订单一致，无需申请修改');
        }

        $requestedTotal = (int)$assignment['booking_fee_cents_snapshot'] + $requestedTailCents;
        $publicId = \public_id();
        $requestId = $this->db->insert(
            "INSERT INTO offline_collection_adjustment_requests(
                public_id,order_id,worker_id,assignment_id,original_total_cents,original_tail_cents,
                requested_total_cents,requested_tail_cents,reason,receipt_media_ids_json,status,review_note,
                submitted_at,reviewed_at,created_at,updated_at
             ) VALUES(
                :public_id,:order_id,:worker_id,:assignment_id,:original_total,:original_tail,
                :requested_total,:requested_tail,:reason,:receipts,'auto_approved',
                '系统预审自动通过，管理员可抽查驳回',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP()
             )",
            [
                'public_id'=>$publicId,
                'order_id'=>$order['id'],
                'worker_id'=>$workerId,
                'assignment_id'=>$assignment['id'],
                'original_total'=>$assignment['final_amount_cents_snapshot'],
                'original_tail'=>$originalTail,
                'requested_total'=>$requestedTotal,
                'requested_tail'=>$requestedTailCents,
                'reason'=>$reason,
                'receipts'=>json_encode(array_map('intval', array_column($receipts, 'id'))),
            ]
        );

        $booking = (int)$assignment['booking_fee_cents_snapshot'];
        $rate = (int)$assignment['commission_rate_bp_snapshot'];
        $minimum = (int)$assignment['commission_min_cents_snapshot'];
        $gross = min($requestedTotal, max((int)round($requestedTotal * $rate / 10000), $minimum));
        $offset = min($booking, $gross);
        $due = max(0, $gross - $offset);
        $net = $requestedTotal - $gross;

        $this->db->execute(
            'UPDATE orders SET final_amount_cents=:total,updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id',
            ['total'=>$requestedTotal,'id'=>$order['id']]
        );
        $this->db->execute(
            'UPDATE assignments
             SET final_amount_cents_snapshot=:total,estimated_commission_cents=:gross,
                 estimated_offline_collect_cents=:offline,estimated_net_income_cents=:net,updated_at=UTC_TIMESTAMP()
             WHERE id=:id',
            [
                'total'=>$requestedTotal,
                'gross'=>$gross,
                'offline'=>max(0, $requestedTotal - $booking),
                'net'=>$net,
                'id'=>$assignment['id'],
            ]
        );
        if ($receivable) {
            $this->db->execute(
                "UPDATE payment_orders SET status='closed',closed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
                 WHERE business_type='commission' AND business_id=:id AND status IN ('created','pending')",
                ['id'=>$receivable['id']]
            );
            $this->db->execute(
                "UPDATE commission_payments SET status='failed'
                 WHERE receivable_id=:id AND status='pending'",
                ['id'=>$receivable['id']]
            );
            $this->db->execute(
                "UPDATE commission_receivables
                 SET final_amount_cents_snapshot=:total,gross_commission_cents=:gross,
                     booking_fee_offset_cents=:offset,amount_due_cents=:due_amount,
                     status=IF(:due_status=0,'settled','pending'),
                     settled_at=IF(:due_settled=0,UTC_TIMESTAMP(),NULL),updated_at=UTC_TIMESTAMP()
                 WHERE id=:id",
                [
                    'total'=>$requestedTotal,'gross'=>$gross,'offset'=>$offset,'due_amount'=>$due,
                    'due_status'=>$due,'due_settled'=>$due,'id'=>$receivable['id'],
                ]
            );
        }

        $this->event(
            (int)$order['id'],
            'offline_collection_adjustment_requested',
            'worker',
            $workerId,
            ['original_tail_cents'=>$originalTail,'requested_tail_cents'=>$requestedTailCents,'amount_cents'=>$requestedTailCents]
        );
        // 旧 Controller 的 event() 参数声明为 int 却传 null，这里收口为可空系统 actor，避免真实路径 TypeError。
        $this->event(
            (int)$order['id'],
            'offline_collection_adjustment_auto_approved',
            'system',
            null,
            [
                'request_id'=>$requestId,
                'original_tail_cents'=>$originalTail,
                'requested_tail_cents'=>$requestedTailCents,
                'amount_cents'=>$requestedTailCents,
                'commission_due_cents'=>$due,
            ]
        );
        OrderNotificationService::queueAdminTask(
            $this->db,
            'offline-adjustment:' . $requestId,
            'offline_collection_adjustment_auto_approved',
            '实际尾款已自动预审，请抽查',
            [
                '订单号'=>(string)$locked['public_no'],
                '原尾款'=>number_format($originalTail / 100, 2, '.', '') . '元',
                '实际尾款'=>number_format($requestedTailCents / 100, 2, '.', '') . '元',
            ]
        );
        return $publicId;
    }

    private function event(int $orderId, string $type, string $actorType, ?int $actorId, array $payload): void
    {
        $eventKey = $type . ':' . $orderId . ':' . bin2hex(random_bytes(6));
        $this->db->insert(
            'INSERT INTO order_events(order_id,event_type,event_key,actor_type,actor_id,payload_json,created_at)
             VALUES(:order_id,:type,:event_key,:actor_type,:actor_id,:payload,UTC_TIMESTAMP())',
            [
                'order_id'=>$orderId,
                'type'=>$type,
                'event_key'=>$eventKey,
                'actor_type'=>$actorType,
                'actor_id'=>$actorId,
                'payload'=>json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );
        OrderNotificationService::queue($this->db, $orderId, $eventKey, $type, $payload);
    }

    private function localTime($value): ?string
    {
        if (!$value) return null;
        try {
            return (new DateTimeImmutable((string)$value, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone((string)\app_config('app.timezone', 'Asia/Shanghai')))
                ->format('Y-m-d H:i');
        } catch (Throwable $exception) {
            return (string)$value;
        }
    }

    private function signedMediaUrl(string $mediaPublicId, array $actor, string $entityType, int $entityId): string
    {
        if ($mediaPublicId === '' || $entityId < 1) return '';
        $userId = (int)($actor['user_id'] ?? 0);
        $workerId = (int)($actor['worker_id'] ?? 0);
        $isAdmin = $this->isMiniAdmin($actor);
        $actorType = $isAdmin ? 'admin' : ($workerId > 0 ? 'worker' : 'user');
        $actorId = $isAdmin ? $userId : ($workerId > 0 ? $workerId : $userId);
        if ($userId < 1 || $actorId < 1) return '';
        $payload = [
            'v'=>2,
            'scope'=>'media',
            'media_id'=>$mediaPublicId,
            'actor_type'=>$actorType,
            'actor_id'=>$actorId,
            'user_id'=>$userId,
            'entity_type'=>$entityType,
            'entity_id'=>$entityId,
            'order_id'=>0,
            'expires'=>time() + 600,
            'nonce'=>bin2hex(random_bytes(8)),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) return '';
        $encoded = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        $token = $encoded . '.' . hash_hmac('sha256', $encoded, (string)\app_config('app.key', ''));
        return \app_url('api/?' . http_build_query([
            'route'=>'media.order',
            'id'=>$mediaPublicId,
            'access_token'=>$token,
        ]));
    }

    private function isMiniAdmin(array $actor): bool
    {
        if (!empty($actor['is_demo_actor'])) return false;
        $openids = $this->settings->get('wechat.admin_openids', []);
        if (is_string($openids)) {
            $decoded = json_decode($openids, true);
            $openids = is_array($decoded)
                ? $decoded
                : preg_split('/[\s,，]+/', $openids, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($openids)) return false;
        $openids = array_values(array_filter(array_map(
            static function ($value): string { return trim((string)$value); },
            $openids
        ), static function (string $value): bool { return $value !== ''; }));
        return in_array(trim((string)($actor['payer_openid'] ?? '')), $openids, true);
    }
}
