<?php
declare(strict_types=1);
namespace WorkerAdmin\Infrastructure\Messaging;
use RuntimeException;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Contracts\PaidSmsDeliveryRepository;
use WorkerAdmin\Application\Chat\ChatSmsReminders;
use WorkerAdmin\Application\Money\UserWalletManager;
use WorkerAdmin\Domain\Messaging\PaidSmsReceiptPolicy;

/** The existing paid-SMS engine drives chat jobs; only business storage differs. */
final class DatabaseChatSmsDeliveryRepository implements PaidSmsDeliveryRepository
{
 public function __construct(private Database $db,private UserWalletManager $wallet,private ChatSmsReminders $nudges) {}
 public function assertSendBoundary():void {if($this->db->pdo()->inTransaction())throw new RuntimeException('CHAT_SMS_TRANSACTION');}
 public function templateCode():string {return $this->nudges->template();}
 public function pending(int $limit):array {
  $recovered=$this->db->execute("UPDATE message_outbox o JOIN chat_sms_nudges n ON n.outbox_id=o.id SET o.status='pending',n.delivery_status='queued',n.updated_at=UTC_TIMESTAMP() WHERE o.topic='chat_paid_sms' AND o.status='processing' AND n.delivery_status IN ('queued','dispatch_claimed') AND n.attempted_at IS NULL AND n.updated_at<UTC_TIMESTAMP()-INTERVAL 2 MINUTE");
  return ['rows'=>$this->db->fetchAll("SELECT id,payload_json FROM message_outbox WHERE topic='chat_paid_sms' AND status='pending' ORDER BY id LIMIT ".max(1,min(200,$limit))),'recovered'=>$recovered];
 }
 public function receiptPending(int $limit):array {
  return ['rows'=>$this->db->fetchAll("SELECT id FROM chat_sms_nudges WHERE delivery_status IN ('sending','provider_accepted','uncertain') AND billing_status='held' AND (next_receipt_check_at IS NULL OR next_receipt_check_at<=UTC_TIMESTAMP()) AND (delivery_status<>'sending' OR attempted_at<UTC_TIMESTAMP()-INTERVAL 2 MINUTE) ORDER BY id LIMIT ".max(1,min(300,$limit))),'reconciled'=>0];
 }
 public function claimOutbox(int $outboxId,int $inviteId):bool {
  if($this->db->execute("UPDATE message_outbox SET status='processing',attempt_count=attempt_count+1 WHERE id=:id AND topic='chat_paid_sms' AND status='pending'",['id'=>$outboxId])!==1)return false;
  $n=$this->db->execute("UPDATE chat_sms_nudges SET delivery_status='dispatch_claimed',updated_at=UTC_TIMESTAMP() WHERE id=:id AND outbox_id=:outbox AND delivery_status IN ('queued','dispatch_claimed') AND attempted_at IS NULL",['id'=>$inviteId,'outbox'=>$outboxId]);
  if($n!==1)$this->markOutboxProcessed($outboxId);return $n===1;
 }
 public function inviteForSend(int $inviteId):?array {
  $r=$this->db->fetch('SELECT * FROM chat_sms_nudges WHERE id=:id',['id'=>$inviteId]);
  return $r?array_merge($r,['purpose'=>'chat_nudge','batch_id'=>0]):null;
 }
 public function preSendValid(array $invite):bool {return $this->nudges->preSend($invite);}
 public function claimSendAttempt(array $invite,int $receiptHours):string {
  return $this->db->transaction(function()use($invite):string{
   $r=$this->db->fetch('SELECT * FROM chat_sms_nudges WHERE id=:id FOR UPDATE',['id'=>$invite['id']]);
   if(!$r||!in_array($r['delivery_status'],['queued','dispatch_claimed'],true)||$r['attempted_at']!==null)return 'stale';
   if(!$this->preSendValid($r))return 'hidden';
   $n=$this->db->execute("UPDATE chat_sms_nudges SET delivery_status='sending',attempted_at=UTC_TIMESTAMP(),receipt_deadline_at=UTC_TIMESTAMP()+INTERVAL 24 HOUR,updated_at=UTC_TIMESTAMP() WHERE id=:id AND attempted_at IS NULL",['id'=>$r['id']]);
   return $n===1?'claimed':'stale';
  });
 }
 public function markUncertain(int $inviteId):void {
  $this->db->execute("UPDATE chat_sms_nudges SET delivery_status='uncertain',next_receipt_check_at=UTC_TIMESTAMP()+INTERVAL 30 SECOND,updated_at=UTC_TIMESTAMP() WHERE id=:id AND delivery_status='sending'",['id'=>$inviteId]);
 }
 public function markOutboxProcessed(int $outboxId):void {$this->db->execute("UPDATE message_outbox SET status='processed',processed_at=UTC_TIMESTAMP() WHERE id=:id AND topic='chat_paid_sms'",['id'=>$outboxId]);}
 public function recordOutcome(array $invite,array $outcome,?int $outboxId):void {
  $type=$outcome['outcome']??'uncertain';
  if(in_array($type,['accepted','uncertain'],true)){
   $this->db->execute("UPDATE chat_sms_nudges SET delivery_status=:state,aliyun_biz_id=:biz,aliyun_request_id=:request,next_receipt_check_at=UTC_TIMESTAMP()+INTERVAL 30 SECOND,updated_at=UTC_TIMESTAMP() WHERE id=:id AND delivery_status IN ('sending','uncertain') AND billing_status='held'",
    ['state'=>$type==='accepted'?'provider_accepted':'uncertain','biz'=>$outcome['biz_id']??null,'request'=>$outcome['request_id']??null,'id'=>$invite['id']]);
  }else $this->settle((int)$invite['id'],$type==='rejected'?'rejected':'delivery_failed');
  if($outboxId)$this->markOutboxProcessed($outboxId);
 }
 public function failDispatch(int $outboxId,int $inviteId,\Throwable $exception):void {
  $r=$this->inviteForSend($inviteId);
  if($r&&$r['attempted_at']===null)$this->settle($inviteId,'cancelled_before_send',true);
  elseif($r)$this->markUncertain($inviteId);
  $this->markOutboxProcessed($outboxId);
 }
 public function cancelBeforeSend(array $invite,string $reason):void {$this->settle((int)$invite['id'],'cancelled_before_send',true);}
 private function settle(int $id,string $state,bool $beforeSend=false):void {
  $this->db->transaction(function()use($id,$state,$beforeSend):void{
   $owner=$this->db->fetchValue('SELECT user_id FROM chat_sms_nudges WHERE id=:id',['id'=>$id]);if(!$owner)return;
   $this->wallet->lockWallet((int)$owner);
   $r=$this->db->fetch('SELECT * FROM chat_sms_nudges WHERE id=:id FOR UPDATE',['id'=>$id]);
   if(!$r||$r['billing_status']!=='held'||($beforeSend&&$r['attempted_at']!==null))return;
   if($state==='delivered')$this->wallet->consumeReservationLocked((int)$r['wallet_reservation_id'],(string)$r['public_no']);
   else $this->wallet->releaseReservationLocked((int)$r['wallet_reservation_id'],(string)$r['public_no'],'聊天短信未确认送达，释放预占');
   $this->db->execute("UPDATE chat_sms_nudges SET delivery_status=:state,billing_status=:billing,active_dedupe_key=IF(:keep=1,active_dedupe_key,NULL),next_receipt_check_at=NULL,updated_at=UTC_TIMESTAMP() WHERE id=:id",
    ['state'=>$state,'billing'=>$state==='delivered'?'charged':'released','keep'=>in_array($state,['delivered','unknown'],true)?1:0,'id'=>$id]);
  });
 }
 public function prepareReceipt(int $inviteId):?array {
  $r=$this->inviteForSend($inviteId);
  if(!$r||$r['billing_status']!=='held')return null;
  if($r['outbox_id'])$this->markOutboxProcessed((int)$r['outbox_id']);
  if($r['delivery_status']==='sending'){$this->markUncertain($inviteId);$r['delivery_status']='uncertain';}
  if($r['receipt_deadline_at']!==null&&strtotime($r['receipt_deadline_at'].' UTC')<=time()){$this->settle($inviteId,'unknown');return null;}
  return $r;
 }
 public function applyReceipt(array $invite,array $matched):void {
  $decision=PaidSmsReceiptPolicy::classify($matched);
  if($decision['should_charge'])$this->settle((int)$invite['id'],'delivered');
  elseif($decision['should_release'])$this->settle((int)$invite['id'],'delivery_failed');
  else $this->scheduleReceipt((int)$invite['id'],(int)$invite['receipt_attempt_count']+1,'');
 }
 public function scheduleReceipt(int $inviteId,int $attempt,string $message):void {
  $delay=PaidSmsReceiptPolicy::retryDelaySeconds($attempt);
  $this->db->execute("UPDATE chat_sms_nudges SET receipt_attempt_count=:attempt,next_receipt_check_at=TIMESTAMPADD(SECOND,:delay,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP() WHERE id=:id AND billing_status='held'",
   ['attempt'=>$attempt,'delay'=>$delay,'id'=>$inviteId]);
 }
 public function cleanupTerminalMobileCiphertexts():int {
  return $this->db->execute("UPDATE chat_sms_nudges SET mobile_ciphertext_snapshot=NULL WHERE mobile_ciphertext_snapshot IS NOT NULL AND billing_status IN ('charged','released') AND updated_at<UTC_TIMESTAMP()-INTERVAL 72 HOUR");
 }
 public function refreshBatch(int $batchId):void {}
}
