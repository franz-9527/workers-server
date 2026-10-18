<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Messaging;

use RuntimeException;
use Throwable;
use WorkerAdmin\Contracts\PaidSmsDeliveryRepository;
use WorkerAdmin\Contracts\SmsGateway;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Contracts\MessageDiagnostics;
use WorkerAdmin\Application\Identity\MobileNumberProtection;
use WorkerAdmin\Domain\Messaging\PaidSmsPolicy;

final class PaidSmsDelivery
{
    private PaidSmsDeliveryRepository $repo;private SmsGateway $sms;private MobileNumberProtection $mobile;private SettingsProvider $settings;private MessageDiagnostics $diagnostics;
    public function __construct(PaidSmsDeliveryRepository $repo,SmsGateway $sms,MobileNumberProtection $mobile,SettingsProvider $settings,MessageDiagnostics $diagnostics)
    { $this->repo=$repo;$this->sms=$sms;$this->mobile=$mobile;$this->settings=$settings;$this->diagnostics=$diagnostics; }
    public function dispatchPending(int $limit=50):array
    {
        $scan=$this->repo->pending(max(1,min(200,$limit)));$rows=$scan['rows'];
        $stats=['scanned'=>count($rows),'success'=>0,'failure'=>0,'recovered'=>$scan['recovered']];
        foreach($rows as $row){try{
            $payload=json_decode((string)$row['payload_json'],true);$invite=(int)($payload['invite_id']??0);
            if($invite<1)throw new RuntimeException('付费短信发件箱缺少 invite_id');
            if($this->dispatchOutbox((int)$row['id'],$invite))$stats['success']++;
        }catch(Throwable $e){$stats['failure']++;$this->diagnostics->write('paid-sms-dispatch','outbox='.(int)$row['id'],$e);}}
        return $stats;
    }
    public function dispatchOutbox(int $outboxId,int $inviteId):bool
    {
        $this->repo->assertSendBoundary();
        if(!$this->repo->claimOutbox($outboxId,$inviteId))return false;
        try{$this->dispatchInvite($inviteId,$outboxId);return true;}
        catch(Throwable $e){$this->repo->failDispatch($outboxId,$inviteId,$e);throw $e;}
    }
    public function dispatchInvite(int $inviteId,?int $outboxId=null):void
    {
        $this->repo->assertSendBoundary();$invite=$this->repo->inviteForSend($inviteId);
        if(!$invite)throw new RuntimeException('付费短信邀请不存在');
        if(in_array((string)$invite['delivery_status'],['provider_accepted','uncertain','delivered','rejected','delivery_failed','cancelled_before_send','unknown'],true)){
            if($outboxId)$this->repo->markOutboxProcessed($outboxId);return;
        }
        if($invite['delivery_status']==='sending'){
            $this->repo->markUncertain($inviteId);if($outboxId)$this->repo->markOutboxProcessed($outboxId);return;
        }
        if(!$this->repo->preSendValid($invite)){$this->repo->cancelBeforeSend($invite,'订单或候选状态已变化，短信未发送');if($outboxId)$this->repo->markOutboxProcessed($outboxId);return;}
        $template=$this->repo->templateCode();
        if($template===''){$this->repo->cancelBeforeSend($invite,'短信模板未配置');if($outboxId)$this->repo->markOutboxProcessed($outboxId);return;}
        $mobile=$this->mobile->decrypt((string)$invite['mobile_ciphertext_snapshot']);
        if($mobile===''){$this->repo->cancelBeforeSend($invite,'短信手机号快照不可用');if($outboxId)$this->repo->markOutboxProcessed($outboxId);return;}
        $params=PaidSmsPolicy::parameters($invite);
        $hours=max(1,min(168,$this->settings->int('sms.user_worker_invite_receipt_wait_hours',24)));
        $claim=$this->repo->claimSendAttempt($invite,$hours);
        if($claim==='hidden')$this->repo->cancelBeforeSend($invite,'该未入驻手机号已达到累计短信触达上限，短信未发送');
        if($claim!=='claimed'){if($outboxId)$this->repo->markOutboxProcessed($outboxId);return;}
        $outcome=$this->sms->sendWithOutcome($mobile,$template,$params,'nearby-invite:'.(string)$invite['public_no']);
        $this->repo->recordOutcome($invite,$outcome,$outboxId);
    }
    public function processReceipts(int $limit=100):array
    {
        $scan=$this->repo->receiptPending(max(1,min(300,$limit)));$rows=$scan['rows'];
        $stats=['scanned'=>count($rows),'success'=>0,'failure'=>0,'reconciled'=>$scan['reconciled'],'ciphertexts_cleared'=>0];
        foreach($rows as $row){try{$this->checkReceipt((int)$row['id']);$stats['success']++;}
            catch(Throwable $e){$stats['failure']++;$this->diagnostics->write('paid-sms-receipt','invite='.(int)$row['id'],$e);}}
        $stats['ciphertexts_cleared']=$this->repo->cleanupTerminalMobileCiphertexts();return $stats;
    }
    public function checkReceipt(int $inviteId):void
    {
        $this->repo->assertSendBoundary();$invite=$this->repo->prepareReceipt($inviteId);if(!$invite)return;
        $attempt=(int)$invite['receipt_attempt_count']+1;$mobile=$this->mobile->decrypt((string)$invite['mobile_ciphertext_snapshot']);
        if($mobile===''){$this->repo->scheduleReceipt($inviteId,$attempt,'手机号快照不可用');return;}
        $at=(string)($invite['attempted_at']??'');$ts=$at!==''?strtotime($at.' UTC'):false;
        if(!$ts){$this->repo->scheduleReceipt($inviteId,$attempt,'缺少短信尝试时间');return;}
        $result=$this->sms->querySendDetails($mobile,gmdate('Ymd',$ts+8*3600),(string)($invite['aliyun_biz_id']??''));
        if(($result['outcome']??'')!=='ok'){$this->repo->scheduleReceipt($inviteId,$attempt,mb_substr((string)($result['message']??'回执查询中'),0,300));return;}
        $matched=PaidSmsPolicy::receipt($invite,$mobile,(array)($result['details']??[]));
        if(!$matched){$this->repo->scheduleReceipt($inviteId,$attempt,'暂未查询到匹配回执');return;}
        $this->repo->applyReceipt($invite,$matched);
    }
    public function refreshBatch(int $id):void { $this->repo->refreshBatch($id); }

}
