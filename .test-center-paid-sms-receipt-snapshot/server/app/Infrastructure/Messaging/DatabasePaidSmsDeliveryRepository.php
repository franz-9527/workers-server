<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Messaging;

use RuntimeException;
use Throwable;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Application\Money\UserWalletManager;
use WorkerAdmin\Application\Order\WorkerQuoteEligibility;
use WorkerAdmin\Services\NearbyWorkerCandidateService;
use WorkerAdmin\Contracts\PaidSmsDeliveryRepository;
use WorkerAdmin\Domain\Messaging\PaidSmsReceiptPolicy;

final class DatabasePaidSmsDeliveryRepository implements PaidSmsDeliveryRepository
{
    private Database $db;private UserWalletManager $wallet;private NearbyWorkerCandidateService $candidates;private WorkerQuoteEligibility $eligibility;
    public function __construct(Database $db,UserWalletManager $wallet,WorkerQuoteEligibility $eligibility)
    { $this->db=$db;$this->wallet=$wallet;$this->eligibility=$eligibility;$this->candidates=new NearbyWorkerCandidateService($db); }
    public function pending(int $limit):array
    {
        $limit=max(1,min(200,$limit));
        // 只恢复明确停在“尚未进入外部 SendSms”的抢占任务；sending 及之后绝不重发。
        $recovered=$this->db->execute(
            "UPDATE message_outbox m
             JOIN order_worker_sms_invites i ON i.outbox_id=m.id
             SET m.status='pending',m.next_attempt_at=NULL,m.processed_at=NULL,
                 m.last_error='恢复发送前中断任务',i.delivery_status='queued',i.updated_at=UTC_TIMESTAMP()
             WHERE m.topic='aliyun_paid_sms' AND m.status='processing'
               AND i.delivery_status IN ('prepared','queued','dispatch_claimed')
               AND i.billing_status='held'
               AND i.attempted_at IS NULL
               AND i.updated_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 MINUTE)"
        );
        $rows=$this->db->fetchAll(
            "SELECT id,payload_json FROM message_outbox
             WHERE topic='aliyun_paid_sms' AND status='pending'
             ORDER BY id LIMIT ".$limit
        );
        return ['rows'=>$rows,'recovered'=>(int)$recovered];

    }

    public function receiptPending(int $limit):array
    {
        $limit=max(1,min(300,$limit));
        $reconciled=$this->reconcileTerminalBilling($limit);
        $rows=$this->db->fetchAll(
            "SELECT id FROM order_worker_sms_invites
             WHERE delivery_status IN ('provider_accepted','uncertain','sending')
               AND billing_status='held'
               AND (next_receipt_check_at IS NULL OR next_receipt_check_at<=UTC_TIMESTAMP())
             ORDER BY id LIMIT ".$limit
        );
        return ['rows'=>$rows,'reconciled'=>$reconciled];

    }

    public function claimOutbox(int $outboxId,int $inviteId):bool
    {
        $claimed=$this->db->execute(
            "UPDATE message_outbox
             SET status='processing',attempt_count=attempt_count+1,last_error=NULL
             WHERE id=:id AND topic='aliyun_paid_sms' AND status='pending'",
            ['id'=>$outboxId]
        );
        if($claimed!==1)return false;
        $inviteClaimed=$this->db->execute(
            "UPDATE order_worker_sms_invites
             SET delivery_status='dispatch_claimed',updated_at=UTC_TIMESTAMP()
             WHERE id=:id AND delivery_status IN ('prepared','queued','dispatch_claimed') AND attempted_at IS NULL",
            ['id'=>$inviteId]
        );
        if($inviteClaimed!==1){
            $this->db->execute(
                "UPDATE message_outbox SET status='processed',processed_at=UTC_TIMESTAMP(),last_error='邀请已由其他流程处理' WHERE id=:id AND status='processing'",
                ['id'=>$outboxId]
            );
            return false;
        }
        return true;
    }

    public function failDispatch(int $outboxId,int $inviteId,\Throwable $exception):void
    {

            $invite=$this->db->fetch('SELECT id,public_no,batch_id,delivery_status,billing_status,wallet_reservation_id FROM order_worker_sms_invites WHERE id=:id LIMIT 1',['id'=>$inviteId]);
            $status=(string)($invite['delivery_status']??'');
            // prepared/queued 时尚未进入外部 SendSms，可安全失败并释放；sending 及之后绝不重发。
            if($invite&&in_array($status,['prepared','queued','dispatch_claimed'],true)&&$invite['billing_status']==='held'){
                try{
                    $this->db->transaction(function(Database $db)use($invite,$exception):void{
                        $locked=$this->lockWalletThenInvite($db,(int)$invite['id']);
                        if(!$locked||$locked['billing_status']!=='held'||!in_array((string)$locked['delivery_status'],['prepared','queued','dispatch_claimed'],true))return;
                        $this->wallet->releaseReservationLocked((int)$locked['wallet_reservation_id'],(string)$locked['public_no'],'短信发送任务异常，发送前释放预占');
                        $db->execute(
                            "UPDATE order_worker_sms_invites
                             SET delivery_status='delivery_failed',billing_status='released',active_dedupe_key=NULL,
                                 error_code='DISPATCH_EXCEPTION',error_message_safe=:message,failed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
                             WHERE id=:id",
                            ['message'=>mb_substr($exception->getMessage(),0,500),'id'=>$locked['id']]
                        );
                    });
                    $this->refreshBatch((int)$invite['batch_id']);
                    $status='delivery_failed';
                }catch(Throwable $releaseError){
                    \write_log('paid-sms-release','['.date('c').'] invite='.$inviteId.' '.mb_substr($releaseError->getMessage(),0,500)."\n");
                }
            }
            $safeAfterSend=in_array($status,['sending','uncertain','provider_accepted','delivered'],true);
            $this->db->execute(
                "UPDATE message_outbox
                 SET status=:status,last_error=:error,processed_at=IF(:processed='processed',UTC_TIMESTAMP(),processed_at)
                 WHERE id=:id",
                [
                    'status'=>$safeAfterSend?'processed':'failed',
                    'error'=>mb_substr($exception->getMessage(),0,1000),
                    'processed'=>$safeAfterSend?'processed':'failed',
                    'id'=>$outboxId
                ]
            );

    }

    public function inviteForSend(int $inviteId):?array
    {
        $invite=$this->db->fetch(
            "SELECT i.*,o.public_no order_no,o.user_id order_user_id,o.workflow_profile,o.category_name_snapshot,
                    o.category_names_snapshot,o.district,o.lat order_lat,o.lng order_lng,o.coordinate_system,
                    o.audit_status,o.payment_status,o.publish_status,o.quote_status,o.lifecycle_status,
                    o.selected_quote_id,o.assigned_worker_id,o.current_assignment_id,o.refund_status,o.quote_deadline_at,o.is_demo
             FROM order_worker_sms_invites i JOIN orders o ON o.id=i.order_id
             WHERE i.id=:id LIMIT 1",
            ['id'=>$inviteId]
        );
        return $invite;
    }

    public function markUncertain(int $inviteId):void
    {
            $this->db->execute(
                "UPDATE order_worker_sms_invites
                 SET delivery_status='uncertain',next_receipt_check_at=COALESCE(next_receipt_check_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP()
                 WHERE id=:id AND delivery_status='sending'",
                ['id'=>$inviteId]
            );

    }

    public function recordOutcome(array $invite,array $outcome,?int $outboxId):void
    {
        $inviteId=(int)$invite['id'];
        $type=(string)($outcome['outcome']??'uncertain');
        if($type==='accepted'){
            $this->db->execute(
                "UPDATE order_worker_sms_invites
                 SET delivery_status='provider_accepted',aliyun_biz_id=:biz,aliyun_request_id=:request,
                     provider_code=:code,provider_accepted_at=UTC_TIMESTAMP(),next_receipt_check_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 SECOND),
                     error_code=NULL,error_message_safe=NULL,updated_at=UTC_TIMESTAMP()
                 WHERE id=:id AND delivery_status='sending'",
                ['biz'=>$outcome['biz_id'],'request'=>$outcome['request_id'],'code'=>$outcome['provider_code'],'id'=>$inviteId]
            );
        }elseif($type==='uncertain'){
            $this->db->execute(
                "UPDATE order_worker_sms_invites
                 SET delivery_status='uncertain',error_code='TRANSPORT_UNCERTAIN',error_message_safe=:message,
                     next_receipt_check_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 SECOND),updated_at=UTC_TIMESTAMP()
                 WHERE id=:id AND delivery_status='sending'",
                ['message'=>mb_substr((string)$outcome['message'],0,500),'id'=>$inviteId]
            );
        }else{
            $delivery=$type==='rejected'?'rejected':'delivery_failed';
            $this->releaseInvite(
                $inviteId,
                '短信未被服务商确认送达，不收费',
                $delivery,
                strtoupper($type),
                mb_substr((string)$outcome['message'],0,500),
                (string)($outcome['provider_code']??'')
            );
        }
        if($outboxId)$this->markOutboxProcessed($outboxId);
        $this->refreshBatch((int)$invite['batch_id']);
    
    }

    public function prepareReceipt(int $inviteId):?array
    {
        $invite=$this->db->fetch('SELECT * FROM order_worker_sms_invites WHERE id=:id LIMIT 1',['id'=>$inviteId]);
        if(!$invite||$invite['billing_status']!=='held')return null;
        if(!empty($invite['outbox_id']))$this->markOutboxProcessed((int)$invite['outbox_id']);

        if((string)$invite['delivery_status']==='sending'){
            $this->db->execute(
                "UPDATE order_worker_sms_invites
                 SET delivery_status='uncertain',next_receipt_check_at=COALESCE(next_receipt_check_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP()
                 WHERE id=:id AND delivery_status='sending'",
                ['id'=>$inviteId]
            );
            $invite['delivery_status']='uncertain';
        }

        // 回执窗口必须从真正进入 SendSms 的 attempted_at 起算；同时修复补丁前已排队记录的旧 deadline。
        $receiptHours=max(1,min(168,(int)\setting('sms.user_worker_invite_receipt_wait_hours',24)));
        if(!empty($invite['attempted_at'])){
            $deadlineFixed=$this->db->execute(
                "UPDATE order_worker_sms_invites
                 SET receipt_deadline_at=DATE_ADD(attempted_at,INTERVAL ".$receiptHours." HOUR),updated_at=UTC_TIMESTAMP()
                 WHERE id=:id AND billing_status='held'
                   AND (receipt_deadline_at IS NULL OR receipt_deadline_at<DATE_ADD(attempted_at,INTERVAL ".$receiptHours." HOUR))",
                ['id'=>$inviteId]
            );
            if($deadlineFixed>0)$invite=$this->db->fetch('SELECT * FROM order_worker_sms_invites WHERE id=:id LIMIT 1',['id'=>$inviteId]);
        }

        if(!empty($invite['receipt_deadline_at'])&&strtotime((string)$invite['receipt_deadline_at'].' UTC')<=time()){
            $this->db->transaction(function(Database $db)use($inviteId):void{
                $locked=$this->lockWalletThenInvite($db,$inviteId);
                if(!$locked||$locked['billing_status']!=='held')return;
                $this->wallet->releaseReservationLocked((int)$locked['wallet_reservation_id'],(string)$locked['public_no'],'短信送达结果超时未确认，释放预占');
                $db->execute(
                    "UPDATE order_worker_sms_invites
                     SET delivery_status='unknown',billing_status='released',error_code='RECEIPT_TIMEOUT',
                         error_message_safe='超过回执确认窗口，用户不收费',next_receipt_check_at=NULL,updated_at=UTC_TIMESTAMP()
                     WHERE id=:id",
                    ['id'=>$inviteId]
                );
            });
            $this->refreshBatch((int)$invite['batch_id']);
            return null;
        }

        return $invite;
    }

    public function applyReceipt(array $invite,array $matched):void
    {
        $inviteId=(int)$invite['id'];
        $decision=PaidSmsReceiptPolicy::classify($matched);
        if(!empty($decision['should_charge'])){
            $this->db->transaction(function(Database $db)use($inviteId,$matched):void{
                $locked=$this->lockWalletThenInvite($db,$inviteId);
                if(!$locked||$locked['billing_status']!=='held')return;
                $this->wallet->consumeReservationLocked((int)$locked['wallet_reservation_id'],(string)$locked['public_no']);
                $db->execute(
                    "UPDATE order_worker_sms_invites
                     SET delivery_status='delivered',billing_status='charged',delivered_at=COALESCE(delivered_at,UTC_TIMESTAMP()),
                         charged_at=COALESCE(charged_at,UTC_TIMESTAMP()),provider_code=:err,
                         receipt_attempt_count=receipt_attempt_count+1,next_receipt_check_at=NULL,updated_at=UTC_TIMESTAMP()
                     WHERE id=:id",
                    ['err'=>mb_substr((string)($matched['err_code']??'DELIVERED'),0,64),'id'=>$inviteId]
                );
            });
        }elseif(!empty($decision['should_release'])){
            $this->db->transaction(function(Database $db)use($inviteId,$matched):void{
                $locked=$this->lockWalletThenInvite($db,$inviteId);
                if(!$locked||$locked['billing_status']!=='held')return;
                $this->wallet->releaseReservationLocked((int)$locked['wallet_reservation_id'],(string)$locked['public_no'],'运营商回执确认短信失败，不收费');
                $db->execute(
                    "UPDATE order_worker_sms_invites
                     SET delivery_status='delivery_failed',billing_status='released',error_code=:err,
                         error_message_safe='运营商回执发送失败',failed_at=UTC_TIMESTAMP(),
                         active_dedupe_key=NULL,next_receipt_check_at=NULL,updated_at=UTC_TIMESTAMP()
                     WHERE id=:id",
                    ['err'=>mb_substr((string)($matched['err_code']??'DELIVERY_FAILED'),0,64),'id'=>$inviteId]
                );
            });
        }else{
            $this->scheduleReceipt($inviteId,(int)$invite['receipt_attempt_count']+1,'短信仍在等待运营商回执');
        }
        $this->refreshBatch((int)$invite['batch_id']);
    }

    public function reconcileTerminalBilling(int $limit): int
    {
        $rows=$this->db->fetchAll(
            "SELECT id,delivery_status FROM order_worker_sms_invites
             WHERE (
                 delivery_status='delivered' AND billing_status IN ('held','settlement_pending')
             ) OR (
                 delivery_status IN ('rejected','delivery_failed','cancelled_before_send','unknown')
                 AND billing_status IN ('held','settlement_pending')
             )
             ORDER BY id LIMIT ".max(1,min(300,$limit))
        );
        $count=0;
        foreach($rows as $row){
            try{
                $inviteId=(int)$row['id'];
                if((string)$row['delivery_status']==='delivered'){
                    $batchId=$this->db->transaction(function(Database $db)use($inviteId):int{
                        $locked=$this->lockWalletThenInvite($db,$inviteId);
                        if(!$locked||!in_array((string)$locked['billing_status'],['held','settlement_pending'],true)||(string)$locked['delivery_status']!=='delivered')return 0;
                        $this->wallet->consumeReservationLocked((int)$locked['wallet_reservation_id'],(string)$locked['public_no']);
                        $db->execute("UPDATE order_worker_sms_invites SET billing_status='charged',charged_at=COALESCE(charged_at,UTC_TIMESTAMP()),next_receipt_check_at=NULL,updated_at=UTC_TIMESTAMP() WHERE id=:id",['id'=>$inviteId]);
                        return (int)$locked['batch_id'];
                    });
                }else{
                    $batchId=$this->db->transaction(function(Database $db)use($inviteId):int{
                        $locked=$this->lockWalletThenInvite($db,$inviteId);
                        if(!$locked||!in_array((string)$locked['billing_status'],['held','settlement_pending'],true)||!in_array((string)$locked['delivery_status'],['rejected','delivery_failed','cancelled_before_send','unknown'],true))return 0;
                        $this->wallet->releaseReservationLocked((int)$locked['wallet_reservation_id'],(string)$locked['public_no'],'短信终态账务恢复：未确认送达，不收费');
                        $db->execute("UPDATE order_worker_sms_invites SET billing_status='released',active_dedupe_key=IF(delivery_status='unknown',active_dedupe_key,NULL),next_receipt_check_at=NULL,updated_at=UTC_TIMESTAMP() WHERE id=:id",['id'=>$inviteId]);
                        return (int)$locked['batch_id'];
                    });
                }
                if(!empty($batchId))$this->refreshBatch((int)$batchId);
                $count++;
            }catch(Throwable $exception){
                \write_log('paid-sms-reconcile','['.date('c').'] invite='.(int)$row['id'].' '.mb_substr($exception->getMessage(),0,500)."\n");
            }
        }
        return $count;
    }
    public function cleanupTerminalMobileCiphertexts(): int
    {
        // 终态保留 72 小时供短期排障；之后只保留脱敏号与 HMAC，减少敏感快照长期滞留。
        return $this->db->execute(
            "UPDATE order_worker_sms_invites
             SET mobile_ciphertext_snapshot=NULL,updated_at=UTC_TIMESTAMP()
             WHERE mobile_ciphertext_snapshot IS NOT NULL
               AND delivery_status IN ('delivered','rejected','delivery_failed','cancelled_before_send','unknown')
               AND billing_status IN ('charged','released','waived')
               AND updated_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 72 HOUR)"
        );
    }
    public function claimSendAttempt(array $invite,int $receiptHours): string
    {
        return $this->db->transaction(function(Database $db)use($invite,$receiptHours):string{
            if((string)($invite['candidate_type']??'')==='marked'){
                $mobileHmac=(string)($invite['mobile_hmac_snapshot']??'');
                if($mobileHmac==='')return 'stale';
                $threshold=$this->candidates->unregisteredMarkedMobileHideThreshold();
                if($threshold>0){
                    // 当前 invite 本身已经存在，因此一定能锁到同手机号的一行；所有同手机号发送任务锁同一最早行。
                    $db->fetchValue(
                        "SELECT id FROM order_worker_sms_invites
                         WHERE mobile_hmac_snapshot=:hmac
                         ORDER BY id ASC LIMIT 1 FOR UPDATE",
                        ['hmac'=>$mobileHmac]
                    );
                    if($this->candidates->unregisteredMarkedMobileAttemptCount($mobileHmac)>=$threshold)return 'hidden';
                }
            }

            $claimed=$db->execute(
                "UPDATE order_worker_sms_invites
                 SET delivery_status='sending',attempted_at=COALESCE(attempted_at,UTC_TIMESTAMP()),
                     receipt_deadline_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ".$receiptHours." HOUR),
                     updated_at=UTC_TIMESTAMP()
                 WHERE id=:id AND delivery_status IN ('prepared','queued','dispatch_claimed')",
                ['id'=>(int)$invite['id']]
            );
            return $claimed===1?'claimed':'stale';
        });
    }
    public function preSendValid(array $invite): bool
    {
        if(!empty($invite['is_demo']))return false;
        if((int)$invite['order_user_id']!==(int)$invite['user_id'])return false;
        $order=[
            'id'=>(int)$invite['order_id'],'user_id'=>(int)$invite['order_user_id'],
            'workflow_profile'=>(string)$invite['workflow_profile'],
            'category_name_snapshot'=>(string)$invite['category_name_snapshot'],
            'category_names_snapshot'=>(string)$invite['category_names_snapshot'],
            'district'=>(string)$invite['district'],
            'lat'=>$invite['order_lat'],'lng'=>$invite['order_lng'],'coordinate_system'=>(string)$invite['coordinate_system'],
            'audit_status'=>(string)$invite['audit_status'],'payment_status'=>(string)$invite['payment_status'],
            'publish_status'=>(string)$invite['publish_status'],'quote_status'=>(string)$invite['quote_status'],
            'lifecycle_status'=>(string)$invite['lifecycle_status'],'selected_quote_id'=>$invite['selected_quote_id'],
            'assigned_worker_id'=>$invite['assigned_worker_id'],'current_assignment_id'=>$invite['current_assignment_id'],
            'refund_status'=>(string)$invite['refund_status'],'quote_deadline_at'=>$invite['quote_deadline_at'],'is_demo'=>(int)$invite['is_demo'],
        ];
        if(!$this->eligibility->orderCanCollectQuotes($order,true))return false;
        if($this->eligibility->isRestrictedMeasurementConstruction((int)$order['id']))return false;
        if($this->eligibility->quoteSlotsRemaining((int)$order['id'])<1)return false;
        $candidate=$this->candidates->findFreshCandidateForInvite($order,$invite);
        if(!$candidate)return false;
        $daily=max(1,min(50,(int)\setting('sms.user_worker_invite_daily_mobile_limit',5)));
        $sentToday=(int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM order_worker_sms_invites
             WHERE mobile_hmac_snapshot=:hmac
               AND id<>:id
               AND created_at>=UTC_DATE()
               AND delivery_status IN ('prepared','queued','dispatch_claimed','sending','provider_accepted','uncertain','delivered','unknown')",
            ['hmac'=>$invite['mobile_hmac_snapshot'],'id'=>$invite['id']]
        );
        return $sentToday<$daily;
    }
    public function cancelBeforeSend(array $invite,string $reason): void
    {
        $this->releaseInvite((int)$invite['id'],$reason,'cancelled_before_send','CANCELLED_BEFORE_SEND',mb_substr($reason,0,500),'');
    }
    public function releaseInvite(
        int $inviteId,
        string $reason,
        string $deliveryStatus='delivery_failed',
        string $errorCode='DELIVERY_FAILED',
        string $safeMessage='',
        string $providerCode=''
    ): void {
        $batchId=$this->db->transaction(function(Database $db)use($inviteId,$reason,$deliveryStatus,$errorCode,$safeMessage,$providerCode):int{
            $invite=$this->lockWalletThenInvite($db,$inviteId);
            if(!$invite)return 0;
            if($invite['billing_status']==='charged')return (int)$invite['batch_id'];
            if($invite['billing_status']==='held'){
                $this->wallet->releaseReservationLocked((int)$invite['wallet_reservation_id'],(string)$invite['public_no'],$reason);
            }
            $db->execute(
                "UPDATE order_worker_sms_invites
                 SET delivery_status=:delivery,billing_status='released',provider_code=:provider_code,
                     error_code=:error_code,error_message_safe=:message,failed_at=COALESCE(failed_at,UTC_TIMESTAMP()),
                     active_dedupe_key=NULL,next_receipt_check_at=NULL,updated_at=UTC_TIMESTAMP()
                 WHERE id=:id AND billing_status<>'charged'",
                [
                    'delivery'=>$deliveryStatus,'provider_code'=>$providerCode!==''?$providerCode:null,
                    'error_code'=>$errorCode,'message'=>$safeMessage!==''?$safeMessage:mb_substr($reason,0,500),'id'=>$inviteId
                ]
            );
            return (int)$invite['batch_id'];
        });
        if($batchId>0)$this->refreshBatch($batchId);
    }
    public function lockWalletThenInvite(Database $db,int $inviteId): ?array
    {
        $snapshot=$db->fetch('SELECT user_id FROM order_worker_sms_invites WHERE id=:id LIMIT 1',['id'=>$inviteId]);
        if(!$snapshot)return null;
        $this->wallet->lockWallet((int)$snapshot['user_id']);
        $invite=$db->fetch('SELECT * FROM order_worker_sms_invites WHERE id=:id FOR UPDATE',['id'=>$inviteId]);
        if(!$invite||(int)$invite['user_id']!==(int)$snapshot['user_id'])return null;
        return $invite;
    }
    public function scheduleReceipt(int $inviteId,int $attempt,string $message): void
    {
        $seconds=PaidSmsReceiptPolicy::retryDelaySeconds($attempt);
        $this->db->execute(
            "UPDATE order_worker_sms_invites
             SET receipt_attempt_count=:attempt,next_receipt_check_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ".$seconds." SECOND),
                 error_message_safe=:message,updated_at=UTC_TIMESTAMP()
             WHERE id=:id AND billing_status='held'",
            ['attempt'=>$attempt,'message'=>mb_substr($message,0,500),'id'=>$inviteId]
        );
    }
    public function markOutboxProcessed(int $outboxId): void
    {
        $this->db->execute(
            "UPDATE message_outbox SET status='processed',processed_at=UTC_TIMESTAMP(),last_error=NULL WHERE id=:id",
            ['id'=>$outboxId]
        );
    }
    public function refreshBatch(int $batchId): void
    {
        if($batchId<1)return;
        $sum=$this->db->fetch(
            "SELECT COUNT(*) total,
                    SUM(CASE WHEN billing_status='charged' THEN fee_cents_snapshot ELSE 0 END) charged,
                    SUM(CASE WHEN billing_status IN ('released','waived') THEN fee_cents_snapshot ELSE 0 END) released,
                    SUM(CASE WHEN billing_status IN ('held','settlement_pending') THEN 1 ELSE 0 END) pending,
                    SUM(CASE WHEN delivery_status='delivered' THEN 1 ELSE 0 END) delivered,
                    SUM(CASE WHEN delivery_status IN ('rejected','delivery_failed','cancelled_before_send','unknown') THEN 1 ELSE 0 END) failed
             FROM order_worker_sms_invites WHERE batch_id=:batch_id",
            ['batch_id'=>$batchId]
        );
        $pending=(int)($sum['pending']??0);
        $delivered=(int)($sum['delivered']??0);
        $failed=(int)($sum['failed']??0);
        if($pending>0)$status='waiting_receipt';
        elseif($delivered>0&&$failed>0)$status='partially_completed';
        elseif($delivered>0)$status='completed';
        else $status='cancelled';
        $this->db->execute(
            "UPDATE order_worker_sms_batches
             SET charged_cents=:charged,released_cents=:released,status=:status,
                 completed_at=IF(:pending=0,COALESCE(completed_at,UTC_TIMESTAMP()),NULL),updated_at=UTC_TIMESTAMP()
             WHERE id=:id",
            ['charged'=>(int)($sum['charged']??0),'released'=>(int)($sum['released']??0),'status'=>$status,'pending'=>$pending,'id'=>$batchId]
        );
    }
    public function templateCode():string { return $this->candidates->templateCode(); }
    public function assertSendBoundary():void { if($this->db->pdo()->inTransaction())throw new RuntimeException('外部消息发送不能在数据库事务中执行。'); }

}
