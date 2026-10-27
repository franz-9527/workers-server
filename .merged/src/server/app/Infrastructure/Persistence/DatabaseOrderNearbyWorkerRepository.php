<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use RuntimeException;
use Throwable;
use WorkerAdmin\Contracts\OrderNearbyWorkerRepository;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Services\MobileNumberService;
use WorkerAdmin\Services\NearbyWorkerCandidateService;
use WorkerAdmin\Services\UserWalletService;

/**
 * 附近师傅短信触达的持久化适配器。
 *
 * 候选算法暂复用既有 NearbyWorkerCandidateService；API 编排、幂等批次、钱包预占和 outbox
 * 已从巨型 ApiController 移出。外部阿里云发送仍只由 scheduler 消费 outbox，不在请求内直连。
 */
final class DatabaseOrderNearbyWorkerRepository implements OrderNearbyWorkerRepository
{
    private Database $db;
    private SettingsProvider $settings;

    public function __construct(Database $db, SettingsProvider $settings)
    {
        $this->db = $db;
        $this->settings = $settings;
    }

    public function candidates(array $actor, string $orderPublicNo): array
    {
        $order = $this->ownerOrder($actor, $orderPublicNo);
        return (new NearbyWorkerCandidateService($this->db))->listForOwner($order, (int)$actor['user_id']);
    }

    public function notify(array $actor, array $request): array
    {
        $order = $this->ownerOrder($actor, (string)$request['order_id']);
        $userId = (int)$actor['user_id'];
        $candidateKeys = (array)$request['candidate_keys'];
        $clientRequestId = (string)$request['client_request_id'];
        $priceToken = (string)$request['price_quote_token'];
        $confirmedMax = (int)$request['confirmed_max_total_cents'];
        $service = new NearbyWorkerCandidateService($this->db);

        try {
            $summary = $service->candidateRequestSummary((int)$order['id'], $userId, $candidateKeys, true);
        } catch (RuntimeException $exception) {
            throw new OrderOperationException($exception->getMessage(), 'NEARBY_SMS_CANDIDATE_INVALID', 422, [], $exception);
        }
        $fingerprint = (string)$summary['fingerprint'];
        $requestedCount = (int)$summary['requested_count'];

        // 网络重试必须先回放同一幂等批次。首次成功后候选会被标记“已通知”，
        // 因此不能先重新跑实时可发送校验，否则会把成功重试误报为失败。
        $existing = $this->db->fetch(
            "SELECT * FROM order_worker_sms_batches
             WHERE user_id=:user_id AND order_id=:order_id AND client_request_id=:request_id LIMIT 1",
            ['user_id'=>$userId,'order_id'=>$order['id'],'request_id'=>$clientRequestId]
        );
        if ($existing) {
            if (!hash_equals((string)$existing['request_fingerprint'], $fingerprint)) {
                throw new OrderOperationException('同一请求编号不能修改通知对象', 'NEARBY_SMS_IDEMPOTENCY_CONFLICT', 409);
            }
            return ['message'=>'该批短信已创建','data'=>$this->batchView($existing, $userId)];
        }

        $capability = $service->capability($order, $userId);
        if (empty($capability['can_notify_nearby_workers'])) {
            throw new OrderOperationException((string)$capability['unavailable_reason'], 'NEARBY_SMS_UNAVAILABLE', 409);
        }

        try {
            $candidates = $service->resolveCandidateTokens($order, $userId, $candidateKeys);
        } catch (RuntimeException $exception) {
            throw new OrderOperationException($exception->getMessage(), 'NEARBY_SMS_CANDIDATE_INVALID', 422, [], $exception);
        }
        if (!$candidates) {
            throw new OrderOperationException('所选师傅已失效或已通知，请刷新候选列表', 'NEARBY_SMS_NO_VALID_CANDIDATE', 409);
        }

        try {
            $pricing = $service->validatePriceToken($priceToken, $userId, (int)$order['id'], $confirmedMax, count($candidates));
        } catch (RuntimeException $exception) {
            $code = strpos($exception->getMessage(), '价格已调整') !== false ? 'PRICE_CHANGED' : 'PRICE_CONFIRM_INVALID';
            throw new OrderOperationException(
                $exception->getMessage(),
                $code,
                409,
                $service->priceTokenFor($userId, (int)$order['id']),
                $exception
            );
        }

        $hourly = max(1, min(50, $this->settings->int('sms.user_worker_invite_user_hourly_batches', 5)));
        $recent = (int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM order_worker_sms_batches
             WHERE user_id=:user_id AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR)",
            ['user_id'=>$userId]
        );
        if ($recent >= $hourly) {
            throw new OrderOperationException('短信通知操作过于频繁，请稍后再试', 'NEARBY_SMS_RATE_LIMIT', 429);
        }

        try {
            $created = $this->db->transaction(function (Database $db) use ($actor,$order,$candidates,$pricing,$confirmedMax,$clientRequestId,$fingerprint,$requestedCount): array {
                $userId = (int)$actor['user_id'];
                $locked = $db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE', ['id'=>$order['id']]);
                if (!$locked || (int)$locked['user_id'] !== $userId) throw new RuntimeException('订单状态已变化，请刷新后重试');

                $capability = (new NearbyWorkerCandidateService($db))->capability($locked, $userId);
                if (empty($capability['can_notify_nearby_workers'])) throw new RuntimeException((string)$capability['unavailable_reason']);

                $dailyLimit = max(1, min(50, $this->settings->int('sms.user_worker_invite_daily_mobile_limit', 5)));
                $cooldown = max(0, min(1440, $this->settings->int('sms.user_worker_invite_retry_cooldown_minutes', 60)));
                $receiptHours = max(1, min(168, $this->settings->int('sms.user_worker_invite_receipt_wait_hours', 24)));

                // 候选确认后到事务真正落库之间仍可能发生并发通知，因此锁单后再次筛选。
                $sendable = [];
                foreach ($candidates as $candidate) {
                    $identity = (string)$candidate['worker_identity_key'];
                    $mobileHmac = (string)$candidate['mobile_hmac'];
                    $dedupe = hash('sha256', (int)$locked['id'].'|'.$identity.'|'.$mobileHmac);
                    if ($db->fetchValue('SELECT id FROM order_worker_sms_invites WHERE active_dedupe_key=:key LIMIT 1', ['key'=>$dedupe])) continue;

                    $today = (int)$db->fetchValue(
                        "SELECT COUNT(*) FROM order_worker_sms_invites
                         WHERE mobile_hmac_snapshot=:hmac AND created_at>=UTC_DATE()
                           AND delivery_status IN ('prepared','queued','dispatch_claimed','sending','provider_accepted','uncertain','delivered','unknown')",
                        ['hmac'=>$mobileHmac]
                    );
                    if ($today >= $dailyLimit) continue;

                    if ($cooldown > 0 && $db->fetchValue(
                        "SELECT id FROM order_worker_sms_invites
                         WHERE order_id=:order_id AND mobile_hmac_snapshot=:hmac
                           AND delivery_status IN ('rejected','delivery_failed','cancelled_before_send')
                           AND billing_status='released'
                           AND updated_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$cooldown} MINUTE)
                         ORDER BY id DESC LIMIT 1",
                        ['order_id'=>$locked['id'],'hmac'=>$mobileHmac]
                    )) continue;

                    $retryOf = $db->fetchValue(
                        "SELECT id FROM order_worker_sms_invites
                         WHERE order_id=:order_id
                           AND (worker_identity_key=:identity OR mobile_hmac_snapshot=:hmac)
                           AND delivery_status IN ('rejected','delivery_failed','cancelled_before_send')
                           AND billing_status='released'
                         ORDER BY id DESC LIMIT 1",
                        ['order_id'=>$locked['id'],'identity'=>$identity,'hmac'=>$mobileHmac]
                    );
                    $candidate['_sms_dedupe'] = $dedupe;
                    $candidate['_retry_of'] = $retryOf ? (int)$retryOf : null;
                    $sendable[] = $candidate;
                }
                if (!$sendable) throw new RuntimeException('所选师傅当前均不可发送，请刷新候选列表');

                $walletService = new UserWalletService($db);
                $wallet = $walletService->lockWallet($db, $userId);
                $potential = (int)$pricing['fee_cents'] * count($sendable);
                if ((int)$wallet['available_cents'] < $potential) throw new RuntimeException('余额不足，请先充值');

                $batchNo = \public_id();
                $batchId = $db->insert(
                    "INSERT INTO order_worker_sms_batches(
                        public_no,order_id,user_id,client_request_id,request_fingerprint,pricing_version,unit_fee_cents_snapshot,
                        confirmed_max_total_cents,requested_count,valid_count,initial_held_cents,charged_cents,released_cents,status,created_at,updated_at
                     ) VALUES(
                        :public_no,:order_id,:user_id,:request_id,:fingerprint,:pricing_version,:fee,
                        :confirmed_max,:requested,0,0,0,0,'prepared',UTC_TIMESTAMP(),UTC_TIMESTAMP()
                     )",
                    [
                        'public_no'=>$batchNo,'order_id'=>$locked['id'],'user_id'=>$userId,'request_id'=>$clientRequestId,
                        'fingerprint'=>$fingerprint,'pricing_version'=>$pricing['pricing_version'],'fee'=>$pricing['fee_cents'],
                        'confirmed_max'=>$confirmedMax,'requested'=>$requestedCount,
                    ]
                );

                $inviteRows = [];
                $held = 0;
                foreach ($sendable as $candidate) {
                    $identity = (string)$candidate['worker_identity_key'];
                    $mobileHmac = (string)$candidate['mobile_hmac'];
                    $inviteNo = \public_id();
                    $inviteId = $db->insert(
                        "INSERT INTO order_worker_sms_invites(
                            public_no,batch_id,order_id,user_id,candidate_type,worker_id,cloud_worker_id,worker_identity_key,active_dedupe_key,
                            retry_of_invite_id,worker_name_snapshot,mobile_masked_snapshot,mobile_ciphertext_snapshot,mobile_hmac_snapshot,
                            distance_m_snapshot,fee_cents_snapshot,delivery_status,billing_status,receipt_deadline_at,created_at,updated_at
                         ) VALUES(
                            :public_no,:batch_id,:order_id,:user_id,:candidate_type,:worker_id,:cloud_id,:identity,:dedupe,
                            :retry_of,:name,:mobile_masked,:mobile_cipher,:mobile_hmac,:distance,:fee,'prepared','held',
                            NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()
                         )",
                        [
                            'public_no'=>$inviteNo,'batch_id'=>$batchId,'order_id'=>$locked['id'],'user_id'=>$userId,
                            'candidate_type'=>(string)$candidate['candidate_type'],'worker_id'=>$candidate['worker_id'] ?? null,
                            'cloud_id'=>($candidate['cloud_worker_id'] ?? null) ?: null,'identity'=>$identity,
                            'dedupe'=>(string)$candidate['_sms_dedupe'],'retry_of'=>$candidate['_retry_of'],
                            'name'=>mb_substr((string)$candidate['name'],0,80),
                            'mobile_masked'=>\mask_private_mobile((string)$candidate['mobile']),
                            'mobile_cipher'=>MobileNumberService::encrypt((string)$candidate['mobile']),
                            'mobile_hmac'=>$mobileHmac,'distance'=>(int)$candidate['distance_m'],'fee'=>(int)$pricing['fee_cents'],
                        ]
                    );

                    $reservationNo = \public_id();
                    $reservationId = $db->insert(
                        "INSERT INTO user_wallet_reservations(
                            public_no,user_id,amount_cents,business_type,business_id,business_key,status,expires_at,created_at,updated_at
                         ) VALUES(
                            :public_no,:user_id,:amount,'nearby_worker_sms_invite',:business_id,:business_key,'held',
                            DATE_ADD(UTC_TIMESTAMP(),INTERVAL ".($receiptHours + 1)." HOUR),UTC_TIMESTAMP(),UTC_TIMESTAMP()
                         )",
                        [
                            'public_no'=>$reservationNo,'user_id'=>$userId,'amount'=>$pricing['fee_cents'],
                            'business_id'=>$inviteId,'business_key'=>$inviteNo,
                        ]
                    );
                    $wallet = $walletService->applyLocked(
                        $db,$wallet,'sms_hold','transfer',(int)$pricing['fee_cents'],-(int)$pricing['fee_cents'],(int)$pricing['fee_cents'],
                        'nearby_worker_sms_invite',$inviteId,'sms_hold:'.$inviteNo,'附近师傅短信通知费预占'
                    );
                    $outboxId = $db->insert(
                        "INSERT INTO message_outbox(topic,business_key,payload_json,status,attempt_count,next_attempt_at,created_at)
                         VALUES('aliyun_paid_sms',:business_key,:payload,'pending',0,NULL,UTC_TIMESTAMP())",
                        ['business_key'=>$inviteNo,'payload'=>json_encode(['invite_id'=>$inviteId],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]
                    );
                    $db->execute(
                        "UPDATE order_worker_sms_invites
                         SET wallet_reservation_id=:reservation,outbox_id=:outbox,delivery_status='queued',updated_at=UTC_TIMESTAMP()
                         WHERE id=:id",
                        ['reservation'=>$reservationId,'outbox'=>$outboxId,'id'=>$inviteId]
                    );
                    $held += (int)$pricing['fee_cents'];
                    $inviteRows[] = ['id'=>$inviteId,'outbox_id'=>$outboxId];
                }
                if (!$inviteRows) throw new RuntimeException('所选师傅当前均不可发送，请刷新候选列表');

                $db->execute(
                    "UPDATE order_worker_sms_batches
                     SET valid_count=:valid,initial_held_cents=:held,status='dispatching',updated_at=UTC_TIMESTAMP()
                     WHERE id=:id",
                    ['valid'=>count($inviteRows),'held'=>$held,'id'=>$batchId]
                );
                return ['batch_id'=>$batchId,'batch_no'=>$batchNo];
            });
        } catch (Throwable $exception) {
            // 唯一键作为最终并发兜底；后到请求回读先到请求创建的相同批次。
            $concurrent = $this->db->fetch(
                "SELECT * FROM order_worker_sms_batches
                 WHERE user_id=:user_id AND order_id=:order_id AND client_request_id=:request_id LIMIT 1",
                ['user_id'=>$userId,'order_id'=>$order['id'],'request_id'=>$clientRequestId]
            );
            if ($concurrent) {
                if (!hash_equals((string)$concurrent['request_fingerprint'], $fingerprint)) {
                    throw new OrderOperationException('同一请求编号不能修改通知对象', 'NEARBY_SMS_IDEMPOTENCY_CONFLICT', 409, [], $exception);
                }
                return ['message'=>'该批短信已创建','data'=>$this->batchView($concurrent, $userId)];
            }
            if ($exception instanceof OrderOperationException) throw $exception;
            $code = strpos($exception->getMessage(), '余额不足') !== false
                ? 'WALLET_BALANCE_INSUFFICIENT'
                : 'NEARBY_SMS_CREATE_FAILED';
            throw new OrderOperationException($exception->getMessage(), $code, 409, [], $exception);
        }

        $batch = $this->db->fetch('SELECT * FROM order_worker_sms_batches WHERE id=:id', ['id'=>$created['batch_id']]);
        if (!$batch) throw new OrderOperationException('短信批次创建后读取失败，请稍后刷新', 'NEARBY_SMS_BATCH_NOT_FOUND', 409);
        return [
            'message'=>'短信任务已排队，费用先预占；最终确认送达后才扣费',
            'data'=>$this->batchView($batch, $userId),
        ];
    }

    public function notifyStatus(array $actor, string $batchPublicNo): array
    {
        $batch = $this->db->fetch(
            'SELECT * FROM order_worker_sms_batches WHERE BINARY public_no=BINARY :public_no AND user_id=:user_id LIMIT 1',
            ['public_no'=>$batchPublicNo,'user_id'=>(int)$actor['user_id']]
        );
        if (!$batch) throw new OrderOperationException('短信批次不存在', 'NEARBY_SMS_BATCH_NOT_FOUND', 404);
        return $this->batchView($batch, (int)$actor['user_id']);
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    private function ownerOrder(array $actor, string $publicNo): array
    {
        $order = $this->db->fetch('SELECT * FROM orders WHERE BINARY public_no=BINARY :public_no LIMIT 1', ['public_no'=>$publicNo]);
        if (!$order) throw new OrderOperationException('订单不存在', 'ORDER_NOT_FOUND', 404);
        if ((int)$order['user_id'] !== (int)($actor['user_id'] ?? 0)) {
            throw new OrderOperationException('只有订单本人可以查看或通知附近师傅', 'FORBIDDEN', 403);
        }
        return $order;
    }

    /** @param array<string,mixed> $batch @return array<string,mixed> */
    private function batchView(array $batch, int $userId): array
    {
        $items = $this->db->fetchAll(
            "SELECT public_no,worker_name_snapshot,distance_m_snapshot,fee_cents_snapshot,delivery_status,billing_status,
                    error_message_safe,provider_accepted_at,delivered_at,charged_at,updated_at
             FROM order_worker_sms_invites WHERE batch_id=:batch_id ORDER BY id",
            ['batch_id'=>$batch['id']]
        );
        foreach ($items as &$item) {
            $item['distance_m_snapshot'] = (int)$item['distance_m_snapshot'];
            $item['fee_cents_snapshot'] = (int)$item['fee_cents_snapshot'];
        }
        unset($item);
        return [
            'batch_id'=>(string)$batch['public_no'],
            'status'=>(string)$batch['status'],
            'unit_fee_cents'=>(int)$batch['unit_fee_cents_snapshot'],
            'requested_count'=>(int)$batch['requested_count'],
            'valid_count'=>(int)$batch['valid_count'],
            'invalid_candidate_count'=>max(0,(int)$batch['requested_count']-(int)$batch['valid_count']),
            'held_cents'=>(int)$batch['initial_held_cents'],
            'charged_cents'=>(int)$batch['charged_cents'],
            'released_cents'=>(int)$batch['released_cents'],
            'invites'=>$items,
            'wallet'=>(new UserWalletService($this->db))->summary($userId),
        ];
    }
}
