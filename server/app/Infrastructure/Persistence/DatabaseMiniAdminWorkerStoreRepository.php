<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use RuntimeException;
use Throwable;
use WorkerAdmin\Contracts\MiniAdminWorkerStoreRepository;
use WorkerAdmin\Contracts\WorkerCloudGateway;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Admin\MiniAdminOperationException;
use WorkerAdmin\Services\MobileNumberService;
use WorkerAdmin\Services\NearbyWorkerCandidateService;
use WorkerAdmin\Services\OrderNotificationService;

/** Mini Admin Worker / Store 的云端适配、MySQL 投影与统计持久化。 */
final class DatabaseMiniAdminWorkerStoreRepository implements MiniAdminWorkerStoreRepository
{
    private Database $db;
    private WorkerCloudGateway $cloud;

    public function __construct(Database $db, WorkerCloudGateway $cloud)
    {
        $this->db = $db;
        $this->cloud = $cloud;
    }

    public function updateWorker(string $cloudId, array $patch): array
    {
        $data = $this->cloud->invoke('admin.update', ['id'=>$cloudId,'patch'=>$patch]);
        $worker = is_array($data['worker'] ?? null) ? $data['worker'] : [];
        if (!$worker) throw new MiniAdminOperationException('云端资料已返回，但没有师傅记录', 'WORKER_ADMIN_UPDATE_SYNC_EMPTY', 502);
        $localId = $this->projectReviewedCloudWorker($worker);
        \audit('worker.mini.profile.update', 'worker', $localId ?: null, '管理员修改师傅资料', null, [
            'cloud_worker_id'=>$cloudId,
            'fields'=>array_values(array_keys($patch)),
        ]);
        return ['worker'=>$worker,'local_worker_id'=>$localId];
    }

    public function workerSmsStats(array $actor, string $cloudId): array
    {
        try {
            $phone = $this->cloud->invoke('server.phoneReveal', [
                'viewer_openid'=>(string)($actor['payer_openid'] ?? ''),
                'viewer_role'=>'admin',
                'cloud_worker_id'=>$cloudId,
                'membership_card_id'=>'',
            ]);
        } catch (Throwable $exception) {
            if (strpos((string)$exception->getMessage(), '手机号') !== false) return $this->emptySmsStats();
            throw new MiniAdminOperationException('短信推送统计暂不可用，请稍后重试', 'WORKER_SMS_STATS_UNAVAILABLE', 502);
        }

        $mobile = MobileNumberService::normalize((string)($phone['mobile'] ?? ''));
        if ($mobile === '') return $this->emptySmsStats();

        $candidates = new NearbyWorkerCandidateService($this->db);
        $attemptCount = $candidates->unregisteredMarkedMobileAttemptCount(MobileNumberService::hmac($mobile));
        $threshold = $candidates->unregisteredMarkedMobileHideThreshold();
        return [
            'loaded'=>true,
            'mobile_available'=>true,
            'attempt_count'=>$attemptCount,
            'hide_threshold'=>$threshold,
            'reached_threshold'=>$threshold > 0 && $attemptCount >= $threshold,
            'message'=>'',
        ];
    }

    public function reviewWorker(string $cloudId, string $decision, string $reason): array
    {
        $data = $this->cloud->invoke('admin.review', ['id'=>$cloudId,'decision'=>$decision,'reason'=>$reason]);
        $worker = is_array($data['worker'] ?? null) ? $data['worker'] : [];
        if (!$worker) throw new MiniAdminOperationException('云端审核已返回，但没有师傅资料，未能同步接单状态', 'WORKER_REVIEW_SYNC_EMPTY', 502);

        $localId = $this->projectReviewedCloudWorker($worker);
        if ($localId > 0) {
            $this->db->insert(
                "INSERT INTO worker_verifications(worker_id,submitted_snapshot_json,decision,decision_reason,reviewed_by,submitted_at,reviewed_at) VALUES(:worker_id,:snapshot,:decision,:reason,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                [
                    'worker_id'=>$localId,
                    'snapshot'=>json_encode(['cloud_worker_id'=>$cloudId,'status'=>$decision], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                    'decision'=>$decision,
                    'reason'=>$reason,
                ]
            );
        }
        if ($localId > 0 && in_array($decision, ['approved','rejected','suspended'], true)) {
            OrderNotificationService::queueWorkerReviewResult(
                $this->db,
                $localId,
                $cloudId,
                $decision,
                $reason,
                (string)($worker['workerName'] ?? '')
            );
        }
        \audit('worker.mini.review', 'worker', $localId ?: null, $decision.($reason !== '' ? '：'.$reason : ''), null, ['cloud_worker_id'=>$cloudId]);
        return ['worker'=>$worker,'local_worker_id'=>$localId];
    }

    public function reviewStore(string $cloudId, string $decision): array
    {
        $data = $this->cloud->invoke('server.storeReview', ['cloud_store_id'=>$cloudId,'decision'=>$decision]);
        \audit('store.mini.review', 'store', null, $decision, null, ['cloud_store_id'=>$cloudId]);
        return $data;
    }

    public function reviewRecords(string $source, int $page, int $pageSize, string $keyword, array $cloudIds = []): array
    {
        $items = [];
        $total = 0;
        $hasMore = false;
        $safeText = static function ($value, int $max): string {
            if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) return '';
            return mb_substr((string)$value, 0, $max);
        };
        $safeTime = static function ($value) {
            if (is_int($value) || is_float($value)) return $value;
            if (is_string($value)) return mb_substr($value, 0, 64);
            return null;
        };
        $appendWorker = static function (array $row) use (&$items, $safeText, $safeTime): void {
            $notice = is_array($row['reviewNotice'] ?? null) ? $row['reviewNotice'] : [];
            $items[] = [
                '_id'=>$safeText($row['cloud_id'] ?? $row['_id'] ?? '', 128),
                'workerName'=>$safeText($row['workerName'] ?? '', 80),
                'workerType'=>$safeText($row['workerType'] ?? '', 160),
                'status'=>$safeText($row['status'] ?? 'pending', 24) ?: 'pending',
                'reviewStatus'=>$safeText($row['reviewStatus'] ?? $row['status'] ?? 'pending', 24) ?: 'pending',
                'published'=>!empty($row['published']),
                'publishTime'=>$safeTime($row['publishTime'] ?? null),
                'reviewTime'=>$safeTime($row['reviewTime']??0),
                'updatedTime'=>$safeTime($row['updatedTime'] ?? $row['updated_timestamp'] ?? null),
                'createTime'=>$safeTime($row['createTime'] ?? null),
                'reviewNotice'=>[
                    'status'=>$safeText($notice['status'] ?? '', 24),
                    'message'=>$safeText($notice['message'] ?? '', 120),
                    'statusCode'=>$safeText($notice['statusCode'] ?? '', 32),
                    'errorStatus'=>$safeText($notice['errorStatus'] ?? '', 120),
                ],
            ];
        };

        if ($source === 'worker') {
            // 按云端当前页面的精确编号补查，不再假设两套列表的范围和分页一致。
            if ($cloudIds) {
                $data = $this->cloud->invoke('admin.list', [
                    'page'=>1,'page_size'=>50,'keyword'=>'','ids'=>array_slice($cloudIds,0,50),'sort_by'=>'profile_time',
                ]);
                foreach ((array)($data['items'] ?? []) as $row) if (is_array($row)) $appendWorker($row);
                $items = $this->mergeServerReviewNoticeStatus($items);
                return ['items'=>$items,'page'=>1,'page_size'=>50,'total'=>count($items),'has_more'=>false];
            }
            if (preg_match('/^1\d{10}$/', $keyword) === 1) {
                try {
                    $found = $this->cloud->invoke('server.phoneSearchIds', ['mobile'=>$keyword,'source'=>'worker']);
                } catch (Throwable $exception) {
                    \write_log('worker-phone-search', '['.date('c').'] request_id='.\request_id().' '.$exception->getMessage()."\n");
                    if ($exception instanceof RuntimeException) throw $exception;
                    throw new MiniAdminOperationException('手机号搜索处理失败，请提供请求编号：'.\request_id(), 'WORKER_PHONE_SEARCH_INTERNAL', 500);
                }
                $matched = is_array($found['items'] ?? null) ? $found['items'] : [];
                if ($matched) {
                    $total = count($matched);
                    $offset = ($page - 1) * $pageSize;
                    foreach (array_slice($matched, $offset, $pageSize) as $row) if (is_array($row)) $appendWorker($row);
                    $items = $this->mergeServerReviewNoticeStatus($items);
                    return ['items'=>$items,'page'=>$page,'page_size'=>$pageSize,'total'=>$total,'has_more'=>$offset + $pageSize < $total];
                }
                if (array_key_exists('items', $found)) return $this->emptyReviewRecords($page, $pageSize);
                $workerIds = $this->scalarIds((array)($found['ids'] ?? []));
                if (!$workerIds) return $this->emptyReviewRecords($page, $pageSize);
                $data = $this->cloud->invoke('admin.list', [
                    'page'=>$page,'page_size'=>$pageSize,'keyword'=>'','ids'=>$workerIds,'sort_by'=>'profile_time',
                ]);
            } else {
                $data = $this->cloud->invoke('admin.list', [
                    'page'=>$page,'page_size'=>$pageSize,'keyword'=>$keyword,'ids'=>[],'sort_by'=>'profile_time',
                ]);
            }
            foreach ((array)($data['items'] ?? []) as $row) if (is_array($row)) $appendWorker($row);
            $items = $this->mergeServerReviewNoticeStatus($items);
            $total = max(0, (int)($data['total'] ?? count($items)));
            $hasMore = $page * $pageSize < $total;
        } else {
            $ids = [];
            if (preg_match('/^1\d{10}$/', $keyword) === 1) {
                $found = $this->cloud->invoke('server.phoneSearchIds', ['mobile'=>$keyword,'source'=>'store']);
                $ids = $this->scalarIds((array)($found['ids'] ?? []));
                if (!$ids) return $this->emptyReviewRecords($page, $pageSize);
            }
            $data = $this->cloud->invoke('server.storeReviewList', [
                'page'=>$page,'page_size'=>$pageSize,'keyword'=>$ids ? '' : $keyword,'ids'=>$ids,
            ]);
            foreach ((array)($data['items'] ?? []) as $row) {
                if (!is_array($row)) continue;
                $items[] = [
                    '_id'=>$safeText($row['_id'] ?? '', 128),
                    'StoreName'=>$safeText($row['StoreName'] ?? '', 100),
                    'StoreType'=>$safeText($row['StoreType'] ?? '', 160),
                    'published'=>!empty($row['published']),
                    'createTime'=>$safeTime($row['createTime'] ?? null),
                ];
            }
            $total = max(0, (int)($data['total'] ?? count($items)));
            $hasMore = !empty($data['has_more']);
        }
        return ['items'=>$items,'page'=>$page,'page_size'=>$pageSize,'total'=>$total,'has_more'=>$hasMore];
    }

    /**
     * Web/PHP 审核的微信订阅结果存放在 MySQL 可靠发件箱，不会回写云端 worker.reviewNotice。
     * 发布审核页仍以云端资料为事实源；这里仅在云端通知状态为空时补齐服务器发送状态。
     *
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    private function mergeServerReviewNoticeStatus(array $items): array
    {
        foreach ($items as $index=>$item) {
            $notice = is_array($item['reviewNotice'] ?? null) ? $item['reviewNotice'] : [];
            if (in_array(trim((string)($notice['status'] ?? '')), ['sent','failed','skipped','queued'], true)) continue;
            $cloudId = trim((string)($item['_id'] ?? ''));
            $decision = strtolower(trim((string)($item['reviewStatus'] ?? $item['status'] ?? '')));
            if ($cloudId === '' || !in_array($decision, ['approved','rejected','suspended'], true)) continue;

            $prefix = 'worker-review:'.$cloudId.':'.$decision.':';
            $reviewTime=is_numeric($item['reviewTime']??null)?(float)$item['reviewTime']:0;
            $reviewAfter=$reviewTime>0?gmdate('Y-m-d H:i:s',(int)floor($reviewTime/1000)-5):'1970-01-01 00:00:00';
            $outbox = $this->db->fetch(
                "SELECT status,attempt_count,last_error,created_at,processed_at,next_attempt_at
                 FROM message_outbox
                 WHERE topic='wechat_subscribe'
                   AND BINARY LEFT(business_key,CHAR_LENGTH(:prefix))=BINARY :prefix
                   AND created_at>=:review_after
                 ORDER BY id DESC LIMIT 1",
                ['prefix'=>$prefix,'review_after'=>$reviewAfter]
            );
            if ($outbox) {
                $status = (string)($outbox['status'] ?? '');
                $attempts = max(0, (int)($outbox['attempt_count'] ?? 0));
                $error = mb_substr(trim((string)($outbox['last_error'] ?? '')), 0, 120);
                if ($status === 'processed') {
                    $items[$index]['reviewNotice'] = [
                        'status'=>'sent','message'=>'后台通知已发送','statusCode'=>'0','errorStatus'=>'发送成功',
                    ];
                } elseif ($status === 'pending' || $status === 'processing') {
                    $items[$index]['reviewNotice'] = [
                        'status'=>'queued','message'=>$status === 'processing' ? '后台通知正在发送' : '后台通知等待发送',
                        'statusCode'=>'SERVER_OUTBOX_QUEUED','errorStatus'=>$status === 'processing' ? '正在发送' : '等待发送',
                    ];
                } elseif ($status === 'failed' && $attempts < 5 && !empty($outbox['next_attempt_at'])) {
                    $items[$index]['reviewNotice'] = [
                        'status'=>'queued','message'=>'后台通知发送失败，系统将自动重试',
                        'statusCode'=>'SERVER_OUTBOX_RETRY','errorStatus'=>$error !== '' ? $error : '等待自动重试',
                    ];
                } elseif ($status === 'failed' || $status === 'closed') {
                    $items[$index]['reviewNotice'] = [
                        'status'=>'failed','message'=>'后台通知发送失败',
                        'statusCode'=>$status === 'closed' ? 'SERVER_OUTBOX_CLOSED' : 'SERVER_OUTBOX_FAILED','errorStatus'=>$error !== '' ? $error : ($status === 'closed' ? '已停止发送' : '发送已停止重试'),
                    ];
                }
                continue;
            }

            // queueWorkerReviewResult 一定先写站内审核结果，再根据订阅授权决定是否进入微信发件箱。
            // 有站内审核记录但没有 wechat_subscribe 记录，说明微信通知未入队，而不是“没有审核通知记录”。
            $dataJson = json_encode(['cloud_id'=>$cloudId,'review_status'=>$decision], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $localNotice = $this->db->fetchValue(
                "SELECT n.id
                 FROM notifications n
                 JOIN worker_profiles w ON w.id=n.recipient_id
                 WHERE n.recipient_type='worker' AND n.channel='in_app'
                   AND n.template_key='worker_profile_review_result'
                   AND BINARY w.cloud_worker_id=BINARY :cloud_id
                   AND BINARY n.data_json=BINARY :data
                   AND n.created_at>=:review_after
                 ORDER BY n.id DESC LIMIT 1",
                ['cloud_id'=>$cloudId,'data'=>$dataJson,'review_after'=>$reviewAfter]
            );
            if ($localNotice) {
                $items[$index]['reviewNotice'] = [
                    'status'=>'skipped','message'=>'站内审核通知已记录，微信订阅消息未入队',
                    'statusCode'=>'SERVER_OUTBOX_SKIPPED','errorStatus'=>'未进入微信订阅发送队列',
                ];
            } else {
                $items[$index]['reviewNotice'] = [
                    'status'=>'unrecorded','statusCode'=>'REVIEW_NOTICE_UNRECORDED',
                    'message'=>'暂无本次发送记录','errorStatus'=>'暂无发送记录',
                ];
            }
        }
        return $items;
    }

    /** @return array<string,mixed> */
    private function emptySmsStats(): array
    {
        $threshold = max(0, min(1000, (int)\setting('sms.user_worker_invite_unregistered_hide_after_count', 10)));
        return [
            'loaded'=>true,
            'mobile_available'=>false,
            'attempt_count'=>0,
            'hide_threshold'=>$threshold,
            'reached_threshold'=>false,
            'message'=>'该师傅暂无有效手机号，无法统计短信推送次数',
        ];
    }

    /** @param array<int,mixed> $values @return string[] */
    private function scalarIds(array $values): array
    {
        return array_values(array_filter(array_map(static function ($value): string {
            return is_string($value) || is_int($value) ? trim((string)$value) : '';
        }, $values)));
    }

    /** @return array{items:array<int,array<string,mixed>>,page:int,page_size:int,total:int,has_more:bool} */
    private function emptyReviewRecords(int $page, int $pageSize): array
    {
        return ['items'=>[],'page'=>$page,'page_size'=>$pageSize,'total'=>0,'has_more'=>false];
    }

    /** 云端审核结果落地为订单系统所需的最小本地身份投影。 */
    private function projectReviewedCloudWorker(array $cloudWorker): int
    {
        $cloudId = mb_substr(trim((string)($cloudWorker['cloud_id'] ?? $cloudWorker['_id'] ?? '')), 0, 128);
        $openid = mb_substr(trim((string)($cloudWorker['openid'] ?? $cloudWorker['_openid'] ?? '')), 0, 128);
        if ($cloudId === '' || preg_match('/^[A-Za-z0-9_-]{20,128}$/', $openid) !== 1) return 0;

        $appId = (string)\setting('wechat.miniapp_appid', '');
        $identity = $this->db->fetch(
            'SELECT id,user_id FROM wechat_identities WHERE BINARY app_id=BINARY :app_id AND BINARY openid=BINARY :openid LIMIT 1',
            ['app_id'=>$appId,'openid'=>$openid]
        );
        $userId = $identity ? (int)$identity['user_id'] : 0;
        if ($userId < 1) $userId = (int)$this->db->fetchValue(
            'SELECT user_id FROM worker_profiles WHERE BINARY cloud_worker_id=BINARY :cloud_id LIMIT 1',
            ['cloud_id'=>$cloudId]
        );
        if ($userId < 1) {
            $name = mb_substr(trim((string)($cloudWorker['workerName'] ?? '微信师傅')) ?: '微信师傅', 0, 80);
            $mobile = preg_replace('/\D+/', '', (string)($cloudWorker['mobile'] ?? $cloudWorker['phone'] ?? ''));
            if (!preg_match('/^1\d{10}$/', $mobile)) $mobile = '';
            $userId = $this->db->insert(
                "INSERT INTO users(public_id,nickname,mobile_masked,mobile_ciphertext,mobile_hmac,status,created_at,updated_at) VALUES(:public_id,:nickname,:masked,:cipher,:hmac,'active',UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                [
                    'public_id'=>\public_id(),
                    'nickname'=>$name,
                    'masked'=>\mask_private_mobile($mobile),
                    'cipher'=>$mobile === '' ? null : \encrypt_private_value($mobile, 'user-mobile'),
                    'hmac'=>$mobile === '' ? null : \private_value_hmac($mobile, 'user-mobile'),
                ]
            );
        }
        if (!$identity) {
            $this->db->execute(
                "INSERT IGNORE INTO wechat_identities(user_id,app_id,openid,status,created_at,updated_at) VALUES(:user_id,:app_id,:openid,'active',UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                ['user_id'=>$userId,'app_id'=>$appId,'openid'=>$openid]
            );
        }
        return $this->upsertCloudWorkerProjectionForUser($userId, $openid, $cloudWorker);
    }

    private function upsertCloudWorkerProjectionForUser(int $userId, string $openid, array $cloudWorker): int
    {
        $cloudId = mb_substr(trim((string)($cloudWorker['cloud_id'] ?? $cloudWorker['_id'] ?? '')), 0, 128);
        if ($cloudId === '') throw new RuntimeException('云端师傅记录缺少 _id。');
        $name = mb_substr(trim((string)($cloudWorker['workerName'] ?? '微信师傅')) ?: '微信师傅', 0, 80);
        $mobile = preg_replace('/\D+/', '', (string)($cloudWorker['mobile'] ?? $cloudWorker['phone'] ?? ''));
        if (!preg_match('/^1\d{10}$/', $mobile)) $mobile = '';
        $location = is_array($cloudWorker['location'] ?? null) ? $cloudWorker['location'] : [];
        $city = mb_substr((string)($location['city'] ?? ''), 0, 64);
        $district = mb_substr((string)($location['district'] ?? ''), 0, 64);
        $address = mb_substr(trim((string)($location['address'] ?? $location['name'] ?? '')), 0, 500);
        $lat = is_numeric($location['latitude'] ?? null) ? (float)$location['latitude'] : null;
        $lng = is_numeric($location['longitude'] ?? null) ? (float)$location['longitude'] : null;
        $status = (string)($cloudWorker['status'] ?? 'pending');
        if (!in_array($status, ['pending','approved','rejected','suspended'], true)) $status = 'pending';
        $reviewReason = mb_substr(trim((string)($cloudWorker['reviewReason'] ?? '')), 0, 500);

        return $this->db->transaction(function (Database $db) use ($userId,$openid,$cloudId,$name,$mobile,$city,$district,$address,$lat,$lng,$status,$reviewReason,$cloudWorker): int {
            $worker = $db->fetch(
                'SELECT id FROM worker_profiles WHERE user_id=:user_id OR BINARY cloud_worker_id=BINARY :cloud_id ORDER BY (user_id=:user_order) DESC,id DESC LIMIT 1 FOR UPDATE',
                ['user_id'=>$userId,'cloud_id'=>$cloudId,'user_order'=>$userId]
            );
            $values = [
                'user_id'=>$userId,'name'=>$name,'has_mobile'=>$mobile === '' ? 0 : 1,
                'masked'=>\mask_private_mobile($mobile),
                'cipher'=>$mobile === '' ? null : \encrypt_private_value($mobile, 'worker-mobile'),
                'hmac'=>$mobile === '' ? null : \private_value_hmac($mobile, 'worker-mobile'),
                'status'=>$status,
                'reason'=>in_array($status, ['rejected','suspended'], true) ? ($reviewReason ?: '管理员未填写处理说明') : null,
                'city'=>$city,'district'=>$district,
                'address_masked'=>$address === '' ? '' : mb_substr($address, 0, 12).'…',
                'address_cipher'=>$address === '' ? null : \encrypt_private_value($address, 'worker-address'),
                'lat'=>$lat,'lng'=>$lng,'cloud_id'=>$cloudId,
                'record_count'=>1 + (int)($cloudWorker['legacy_count'] ?? 0),
                'verified_at'=>$status === 'approved' ? gmdate('Y-m-d H:i:s') : null,
            ];
            if ($worker) {
                $workerId = (int)$worker['id'];
                $values['id'] = $workerId;
                $db->execute(
                    "UPDATE worker_profiles SET user_id=:user_id,display_name=:name,mobile_masked=IF(:has_mobile=1,:masked,mobile_masked),mobile_ciphertext=COALESCE(:cipher,mobile_ciphertext),mobile_hmac=COALESCE(:hmac,mobile_hmac),verification_status=:status,rejection_reason=:reason,city=:city,district=:district,address_masked=:address_masked,address_ciphertext=:address_cipher,lat=:lat,lng=:lng,profile_json=NULL,profile_source='cloud',cloud_worker_id=:cloud_id,cloud_snapshot_json=NULL,cloud_record_count=:record_count,cloud_record_mode='cloud_authoritative',cloud_last_synced_at=UTC_TIMESTAMP(),verified_at=:verified_at,updated_at=UTC_TIMESTAMP() WHERE id=:id",
                    $values
                );
            } else {
                unset($values['has_mobile']);
                $values['public_id'] = \public_id();
                $workerId = $db->insert(
                    "INSERT INTO worker_profiles(user_id,public_id,display_name,mobile_masked,mobile_ciphertext,mobile_hmac,verification_status,rejection_reason,invited_only,city,district,address_masked,address_ciphertext,lat,lng,coordinate_system,rating,completed_orders,profile_source,cloud_worker_id,cloud_record_count,cloud_record_mode,cloud_last_synced_at,verified_at,created_at,updated_at) VALUES(:user_id,:public_id,:name,:masked,:cipher,:hmac,:status,:reason,0,:city,:district,:address_masked,:address_cipher,:lat,:lng,'GCJ-02',5,0,'cloud',:cloud_id,:record_count,'cloud_authoritative',UTC_TIMESTAMP(),:verified_at,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                    $values
                );
            }
            $db->execute('UPDATE users SET nickname=:nickname,updated_at=UTC_TIMESTAMP() WHERE id=:user_id', ['nickname'=>$name,'user_id'=>$userId]);
            $appId = (string)\setting('wechat.miniapp_appid', '');
            $identityId = (int)$db->fetchValue(
                'SELECT id FROM wechat_identities WHERE user_id=:user_id AND BINARY app_id=BINARY :app_id AND BINARY openid=BINARY :openid LIMIT 1',
                ['user_id'=>$userId,'app_id'=>$appId,'openid'=>$openid]
            );
            if ($identityId) {
                $mapping = (int)$db->fetchValue('SELECT id FROM cloud_identity_mappings WHERE user_id=:user_id LIMIT 1', ['user_id'=>$userId]);
                if ($mapping) {
                    $db->execute(
                        "UPDATE cloud_identity_mappings SET wechat_identity_id=:identity,cloud_worker_id=:cloud_id,source_version='cloud-authoritative-v2',sync_status='success',last_synced_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id",
                        ['identity'=>$identityId,'cloud_id'=>$cloudId,'id'=>$mapping]
                    );
                } else {
                    $db->insert(
                        "INSERT INTO cloud_identity_mappings(user_id,wechat_identity_id,cloud_worker_id,source_version,sync_status,last_synced_at,created_at,updated_at) VALUES(:user_id,:identity,:cloud_id,'cloud-authoritative-v2','success',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                        ['user_id'=>$userId,'identity'=>$identityId,'cloud_id'=>$cloudId]
                    );
                }
            }
            $roleRows = $db->fetchAll("SELECT id,role_key FROM roles WHERE role_key IN ('worker','worker_pending')");
            foreach ($roleRows as $role) $db->execute('DELETE FROM user_roles WHERE user_id=:user_id AND role_id=:role_id', ['user_id'=>$userId,'role_id'=>$role['id']]);
            $roleKey = $status === 'approved' ? 'worker' : 'worker_pending';
            foreach ($roleRows as $role) {
                if ($role['role_key'] === $roleKey) {
                    $db->execute("INSERT IGNORE INTO user_roles(user_id,role_id,source,created_at) VALUES(:user_id,:role_id,'cloud_authoritative',UTC_TIMESTAMP())", ['user_id'=>$userId,'role_id'=>$role['id']]);
                }
            }
            return $workerId;
        });
    }
}
