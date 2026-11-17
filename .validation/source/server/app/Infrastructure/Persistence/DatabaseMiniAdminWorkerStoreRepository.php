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
use WorkerAdmin\Infrastructure\Messaging\OrderNotificationProjector;

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
        $worker = $this->ensureReviewedWorkerOpenid($worker,$cloudId);

        $localId = $this->projectReviewedCloudWorker($worker);
        $reviewTime = is_scalar($worker['reviewTime'] ?? null) ? trim((string)$worker['reviewTime']) : '';
        $reviewId = 0;
        if ($localId > 0) {
            $reviewId = $this->db->insert(
                "INSERT INTO worker_verifications(worker_id,submitted_snapshot_json,decision,decision_reason,reviewed_by,submitted_at,reviewed_at) VALUES(:worker_id,:snapshot,:decision,:reason,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                [
                    'worker_id'=>$localId,
                    'snapshot'=>json_encode([
                        'cloud_worker_id'=>$cloudId,'status'=>$decision,'cloud_review_time'=>$reviewTime,
                    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                    'decision'=>$decision,
                    'reason'=>$reason,
                ]
            );
        }
        if (in_array($decision, ['approved','rejected','suspended'], true)) {
            $workerName=(string)($worker['workerName']??'');
            if($localId>0){
                try{
                    OrderNotificationService::queueWorkerReviewResultForCloudReview($this->db,$localId,$cloudId,$decision,$reason,$workerName,$reviewId,$reviewTime);
                }catch(Throwable $exception){
                    $requestId=\request_id();
                    \write_log('worker-review-notification','['.date('c').'] request_id='.$requestId.' worker_id='.$localId.' cloud_id='.$cloudId.' '.basename($exception->getFile()).':'.$exception->getLine().' '.mb_substr($exception->getMessage(),0,500,'UTF-8')."\n");
                    try{
                        OrderNotificationProjector::recordWorkerReviewNotificationFailure($this->db,$localId,$cloudId,$decision,$workerName,'审核已完成，但通知流程异常，请联系管理员并提供请求编号 '.$requestId,$reviewId,$reviewTime);
                    }catch(Throwable $fallbackException){
                        \write_log('worker-review-notification','['.date('c').'] request_id='.$requestId.' fallback_failed cloud_id='.$cloudId.' '.mb_substr($fallbackException->getMessage(),0,400,'UTF-8')."\n");
                    }
                }
            }else{
                try{
                    OrderNotificationProjector::recordWorkerReviewNotificationFailure($this->db,0,$cloudId,$decision,$workerName,'审核已完成，但未能建立本地师傅账号映射，微信通知未发送',0,$reviewTime);
                }catch(Throwable $exception){
                    \write_log('worker-review-notification','['.date('c').'] request_id='.\request_id().' fallback_without_identity_failed cloud_id='.$cloudId.' '.mb_substr($exception->getMessage(),0,400,'UTF-8')."\n");
                }
            }
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
                'reviewTime'=>$safeTime($row['reviewTime']??null),
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
    /** Resolve the local verification row for this exact cloud review event. */
    private function reviewIdForCloudReview(string $cloudId,string $decision,string $reviewTime,string $reviewAfter): int
    {
        if ($reviewTime !== '') {
            $reviewId = (int)$this->db->fetchValue(
                "SELECT v.id FROM worker_verifications v JOIN worker_profiles w ON w.id=v.worker_id
                 WHERE BINARY w.cloud_worker_id=BINARY :cloud_id
                   AND JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(v.submitted_snapshot_json),v.submitted_snapshot_json,'{}'),'$.cloud_review_time'))=:cloud_review_time
                   AND JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(v.submitted_snapshot_json),v.submitted_snapshot_json,'{}'),'$.status'))=:decision
                 ORDER BY v.id DESC LIMIT 1",
                ['cloud_id'=>$cloudId,'cloud_review_time'=>$reviewTime,'decision'=>$decision]
            );
            if ($reviewId > 0) return $reviewId;
        }
        if ($reviewAfter !== '1970-01-01 00:00:00') {
            return (int)$this->db->fetchValue(
                "SELECT v.id FROM worker_verifications v JOIN worker_profiles w ON w.id=v.worker_id
                 WHERE BINARY w.cloud_worker_id=BINARY :cloud_id AND v.decision=:decision AND v.reviewed_at>=:review_after
                 ORDER BY v.id DESC LIMIT 1",
                ['cloud_id'=>$cloudId,'decision'=>$decision,'review_after'=>$reviewAfter]
            );
        }
        return (int)$this->db->fetchValue(
            "SELECT v.id FROM worker_verifications v JOIN worker_profiles w ON w.id=v.worker_id
             WHERE BINARY w.cloud_worker_id=BINARY :cloud_id AND v.decision=:decision
             ORDER BY v.id DESC LIMIT 1",
            ['cloud_id'=>$cloudId,'decision'=>$decision]
        );
    }

    /** 精确按本次审核 review_id + review_time 对账；旧发件箱记录才使用时间窗口兼容查询。 */
    private function mergeServerReviewNoticeStatus(array $items): array
    {
        foreach ($items as $index=>$item) {
            $notice = is_array($item['reviewNotice'] ?? null) ? $item['reviewNotice'] : [];
            if (in_array(trim((string)($notice['status'] ?? '')), ['sent','failed','skipped','queued'], true)) continue;
            $cloudId = trim((string)($item['_id'] ?? ''));
            $decision = strtolower(trim((string)($item['reviewStatus'] ?? $item['status'] ?? '')));
            if ($cloudId === '' || !in_array($decision, ['approved','rejected','suspended'], true)) continue;
            $reviewTime = is_scalar($item['reviewTime'] ?? null) ? trim((string)$item['reviewTime']) : '';
            $reviewTimestamp = is_numeric($reviewTime) ? (float)$reviewTime : 0;
            $reviewAfter = $reviewTimestamp > 0 ? gmdate('Y-m-d H:i:s',(int)floor($reviewTimestamp/1000)-5) : '1970-01-01 00:00:00';
            try {
                $reviewId = $this->reviewIdForCloudReview($cloudId,$decision,$reviewTime,$reviewAfter);
                $businessKey = OrderNotificationProjector::reviewSubscriptionBusinessKey($cloudId,$decision,$reviewId,$reviewTime);
                $skippedBusinessKey = substr($businessKey.':skipped',0,128);
                $outbox = $this->db->fetch(
                    "SELECT status,attempt_count,last_error,next_attempt_at,payload_json FROM message_outbox
                     WHERE topic='wechat_subscribe'
                       AND (BINARY business_key=BINARY :business_key OR BINARY business_key=BINARY :skipped_business_key
                         OR (BINARY JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(payload_json),payload_json,'{}'),'$.cloud_worker_id'))=BINARY :payload_cloud_id
                           AND JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(payload_json),payload_json,'{}'),'$.review_status'))=:payload_decision
                           AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(payload_json),payload_json,'{}'),'$.review_id')),'0')=:payload_review_id
                           AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(payload_json),payload_json,'{}'),'$.review_time')),'')=:payload_review_time))
                     ORDER BY id DESC LIMIT 1",
                    ['business_key'=>$businessKey,'skipped_business_key'=>$skippedBusinessKey,'payload_cloud_id'=>$cloudId,
                     'payload_decision'=>$decision,'payload_review_id'=>(string)$reviewId,'payload_review_time'=>$reviewTime]
                );
                if (!$outbox && $reviewAfter !== '1970-01-01 00:00:00') {
                    $outbox=$this->db->fetch(
                        "SELECT status,attempt_count,last_error,next_attempt_at,payload_json FROM message_outbox
                         WHERE topic='wechat_subscribe' AND created_at>=:review_after
                           AND BINARY JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(payload_json),payload_json,'{}'),'$.cloud_worker_id'))=BINARY :cloud_id
                           AND JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(payload_json),payload_json,'{}'),'$.review_status'))=:decision
                           AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(payload_json),payload_json,'{}'),'$.review_id')),'0')=:review_id
                           AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(payload_json),payload_json,'{}'),'$.review_time')),'')=''
                         ORDER BY id DESC LIMIT 1",
                        ['review_after'=>$reviewAfter,'cloud_id'=>$cloudId,'decision'=>$decision,'review_id'=>(string)$reviewId]
                    );
                }
                if ($outbox) {
                    $status=(string)($outbox['status']??'');
                    $attempts=max(0,(int)($outbox['attempt_count']??0));
                    $error=mb_substr(trim((string)($outbox['last_error']??'')),0,120);
                    $outboxPayload=json_decode((string)($outbox['payload_json']??''),true);
                    $deliveryOutcome=is_array($outboxPayload)?(string)($outboxPayload['delivery_outcome']??''):'';
                    if($status==='failed'&&$deliveryOutcome==='unknown'){
                        $items[$index]['reviewNotice']=['status'=>'unknown','message'=>'渠道受理结果未知，已停止自动重试','statusCode'=>'SERVER_OUTBOX_UNKNOWN','errorStatus'=>$error!==''?$error:'渠道受理结果未知，请核对后再决定是否人工处理'];
                    }elseif($status==='processed'){
                        $items[$index]['reviewNotice']=['status'=>'sent','message'=>'后台通知已发送','statusCode'=>'0','errorStatus'=>'发送成功'];
                    }elseif($status==='pending'||$status==='processing'){
                        $items[$index]['reviewNotice']=['status'=>'queued','message'=>$status==='processing'?'后台通知正在发送':'后台通知等待发送','statusCode'=>'SERVER_OUTBOX_QUEUED','errorStatus'=>$status==='processing'?'正在发送':'等待发送'];
                    }elseif($status==='failed'&&$attempts<5&&!empty($outbox['next_attempt_at'])){
                        $items[$index]['reviewNotice']=['status'=>'queued','message'=>'后台通知发送失败，系统将自动重试','statusCode'=>'SERVER_OUTBOX_RETRY','errorStatus'=>$error!==''?$error:'等待自动重试'];
                    }elseif($status==='skipped'){
                        $items[$index]['reviewNotice']=['status'=>'skipped','message'=>'微信订阅消息未入队，未调用微信接口','statusCode'=>'SERVER_OUTBOX_SKIPPED','errorStatus'=>$error!==''?$error:'未入队原因未记录'];
                    }elseif($status==='failed'||$status==='closed'){
                        $items[$index]['reviewNotice']=['status'=>'failed','message'=>'后台通知发送失败','statusCode'=>$status==='closed'?'SERVER_OUTBOX_CLOSED':'SERVER_OUTBOX_FAILED','errorStatus'=>$error!==''?$error:($status==='closed'?'已停止发送':'发送已停止重试')];
                    }
                    continue;
                }
                $localNotice=$this->db->fetchValue(
                    "SELECT n.data_json FROM notifications n JOIN worker_profiles w ON w.id=n.recipient_id
                     WHERE n.recipient_type='worker' AND n.channel='in_app' AND n.template_key='worker_profile_review_result'
                       AND BINARY w.cloud_worker_id=BINARY :cloud_id
                       AND JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(n.data_json),n.data_json,'{}'),'$.cloud_id'))=:notice_cloud_id
                       AND JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(n.data_json),n.data_json,'{}'),'$.review_status'))=:notice_decision
                       AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(n.data_json),n.data_json,'{}'),'$.review_id')),'0')=:notice_review_id
                       AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(n.data_json),n.data_json,'{}'),'$.review_time')),'') IN (:notice_review_time,'')
                     ORDER BY n.id DESC LIMIT 1",
                    ['cloud_id'=>$cloudId,'notice_cloud_id'=>$cloudId,'notice_decision'=>$decision,'notice_review_id'=>(string)$reviewId,'notice_review_time'=>$reviewTime]
                );