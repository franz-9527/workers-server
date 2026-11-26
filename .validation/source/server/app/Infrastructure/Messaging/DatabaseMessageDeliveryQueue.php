<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Messaging;

use RuntimeException;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Contracts\MessageDeliveryQueue;
use WorkerAdmin\Contracts\MessageDeliveryReceiptQueue;

final class DatabaseMessageDeliveryQueue implements MessageDeliveryQueue, MessageDeliveryReceiptQueue
{
    private Database $db;
    public function __construct(Database $db) { $this->db=$db; }
    public function assertSendBoundary():void { if($this->db->pdo()->inTransaction())throw new RuntimeException('外部消息发送不能在数据库事务中执行。'); }
    public function recoverExpiredClaims():void {
        $testError='测试请求中断，送达未确认，请人工核对后重新测试';
        $testPayload=$this->payloadWithAttempt("JSON_OBJECT('attempt',attempt_count,'at',DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-%dT%H:%i:%sZ'),'outcome','unknown','error',:history_error)");
        $testPayload="JSON_SET({$testPayload},'$.delivery_outcome','unknown')";
        $this->db->execute("UPDATE message_outbox SET payload_json={$testPayload},status='failed',next_attempt_at=NULL,last_error=:last_error WHERE JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.is_test'))='true' AND status='processing' AND created_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE)",['history_error'=>$testError,'last_error'=>$testError]);
        $timeoutError='发送进程超时，系统已自动回收';
        $timeoutPayload=$this->payloadWithAttempt("JSON_OBJECT('attempt',attempt_count,'at',DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-%dT%H:%i:%sZ'),'outcome','unknown','error',:history_error)");
        $timeoutPayload="JSON_SET({$timeoutPayload},'$.delivery_outcome','unknown')";
        $this->db->execute("UPDATE message_outbox SET payload_json={$timeoutPayload},status='failed',next_attempt_at=NULL,last_error=CONCAT(COALESCE(NULLIF(last_error,''),''),IF(COALESCE(NULLIF(last_error,''),'')='','','；'),:last_error,'；渠道受理结果未知，已停止自动重试') WHERE COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.is_test')),'false')<>'true' AND topic IN ('aliyun_sms','wecom_webhook','wechat_subscribe') AND status='processing' AND ((next_attempt_at IS NOT NULL AND next_attempt_at<=UTC_TIMESTAMP()) OR (next_attempt_at IS NULL AND created_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE)))",['history_error'=>$timeoutError,'last_error'=>$timeoutError]);
    }
    public function pending(int $limit):array { return $this->db->fetchAll("SELECT id,topic,business_key,payload_json,attempt_count FROM message_outbox WHERE COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.is_test')),'false')<>'true' AND topic IN ('aliyun_sms','wecom_webhook','wechat_subscribe') AND status IN ('pending','failed') AND ((status='pending' AND (next_attempt_at IS NULL OR next_attempt_at<=UTC_TIMESTAMP())) OR (status='failed' AND next_attempt_at IS NOT NULL AND next_attempt_at<=UTC_TIMESTAMP())) AND attempt_count<5 ORDER BY id LIMIT ".$limit); }
    public function claim(int $id):bool { return $this->db->execute("UPDATE message_outbox SET status='processing',attempt_count=attempt_count+1,next_attempt_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE) WHERE id=:id AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.is_test')),'false')<>'true' AND attempt_count<5 AND ((status='pending' AND (next_attempt_at IS NULL OR next_attempt_at<=UTC_TIMESTAMP())) OR (status='failed' AND next_attempt_at IS NOT NULL AND next_attempt_at<=UTC_TIMESTAMP()))",['id'=>$id])===1; }
    public function complete(int $id):void {
        $payload=$this->payloadWithAttempt("JSON_OBJECT('attempt',attempt_count,'at',DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-%dT%H:%i:%sZ'),'outcome','accepted')");
        $payload="JSON_SET({$payload},'$.delivery_outcome','accepted')";
        $updated=$this->db->execute("UPDATE message_outbox SET payload_json={$payload},status='processed',processed_at=UTC_TIMESTAMP(),next_attempt_at=NULL,last_error=NULL WHERE id=:id AND status='processing'",['id'=>$id]);
        if($updated!==1 && (string)$this->db->fetchValue('SELECT status FROM message_outbox WHERE id=:id',['id'=>$id])!=='processed')throw new RuntimeException('消息队列完成状态未能保存。');
    }
    public function completeWithReceipt(int $id,array $receipt):void{$this->persistReceipt($id,$receipt,false);}
    public function completeWithReceiptAndConsumeSubscription(int $id,array $receipt,int $userId,string $scene):void
    {
        $this->db->transaction(function(Database $db)use($id,$receipt,$userId,$scene):void{
            $row=$db->fetch('SELECT status,payload_json FROM message_outbox WHERE id=:id FOR UPDATE',['id'=>$id]);
            if(!$row)throw new RuntimeException('消息发件箱记录不存在。');
            if((string)$row['status']==='processed')return;
            if((string)$row['status']!=='processing')throw new RuntimeException('消息发件箱状态已变化，不能确认外部受理。');
            if($userId<1||trim($scene)==='')throw new RuntimeException('缺少订阅账号或场景，无法同步订阅次数。');
            $payload=json_decode((string)$row['payload_json'],true);
            $template=(string)($payload['template_id']??'');
            $consumed=SubscriptionQuotaStore::markSubscriptionConsumed($db,$userId,$scene,$template);
            $this->persistReceipt($id,$receipt,$consumed);
            if(!$consumed)$this->recordAcceptedWarning($id,'微信已受理；原模板授权记录不存在或已变更，未扣减当前模板额度。');
        });
    }
    private function persistReceipt(int $id,array $receipt,bool $subscriptionConsumed):void
    {
        $json=json_encode($receipt,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(!is_string($json))throw new RuntimeException('消息受理回执编码失败。');
        $payload=$this->payloadWithAttempt("JSON_OBJECT('attempt',attempt_count,'at',DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-%dT%H:%i:%sZ'),'outcome','accepted')","JSON_EXTRACT(:receipt,'$')");
        $payload="JSON_SET({$payload},'$.delivery_outcome','accepted')";
        if($subscriptionConsumed)$payload="JSON_SET({$payload},'$.subscription_consumed',true)";
        $updated=$this->db->execute("UPDATE message_outbox SET payload_json={$payload},status='processed',processed_at=UTC_TIMESTAMP(),next_attempt_at=NULL,last_error=NULL WHERE id=:id AND status='processing'",['receipt'=>$json,'id'=>$id]);
        if($updated!==1 && (string)$this->db->fetchValue('SELECT status FROM message_outbox WHERE id=:id',['id'=>$id])!=='processed')throw new RuntimeException('消息受理回执未能保存。');
    }
    public function acquireReviewSendLock(string $cloudId):?string
    {return \WorkerAdmin\Infrastructure\Persistence\WorkerReviewLock::acquire($this->db,$cloudId);}
    public function releaseReviewSendLock(string $lock):void
    {\WorkerAdmin\Infrastructure\Persistence\WorkerReviewLock::release($this->db,$lock);}
    public function reviewSubscriptionSkipReason(array $payload,?int &$authorizationCount=null):string
    {
        $scene=SubscriptionCatalog::subscriptionSceneDefinitions()['reviewResult']??[];
        $template=(string)($payload['template_id']??'');
        if(empty($scene['available'])||$template===''||$template!==(string)($scene['template_id']??''))return '入驻通知模板已停用、变更或配置不完整';
        $uid=(int)($payload['user_id']??0);
        $pref=SubscriptionQuotaStore::subscriptionPreferences($this->db,$uid)['reviewResult']??[];
        $authorizationCount=(int)($pref['total_authorized_count']??0);
        if(empty($pref['enabled'])||($pref['template_id']??'')!==$template
            ||!in_array($pref['authorization_status']??'',['accept','acceptWithAudio','acceptWithAlert'],true)
            ||(($scene['subscription_type']??'one_time')==='one_time'&&(int)($pref['remaining_count']??0)<1))return '申请人当前已无有效入驻通知授权';
        $appId=trim((string)\setting('wechat.miniapp_appid',''));
        $openid=trim((string)($payload['openid']??''));
        if($appId===''||$openid===''||!$this->db->fetchValue("SELECT id FROM wechat_identities WHERE user_id=:uid AND BINARY app_id=BINARY :app AND BINARY openid=BINARY :openid AND status='active' LIMIT 1",['uid'=>$uid,'app'=>$appId,'openid'=>$openid]))return '申请人的当前小程序身份已变化';
        $cloudId=trim((string)($payload['cloud_worker_id']??''));
        $reviewId=(int)($payload['review_id']??0);
        $decision=(string)($payload['review_status']??'');
        if($cloudId===''||$reviewId<1||!in_array($decision,['approved','rejected','suspended'],true))return '审核事件身份不完整，停止历史通知补发';
        $latest=$this->db->fetch("SELECT w.verification_status,v.id,v.decision FROM worker_profiles w LEFT JOIN worker_verifications v ON v.worker_id=w.id WHERE BINARY w.cloud_worker_id=BINARY :cloud AND w.user_id=:uid ORDER BY v.id DESC LIMIT 1",['cloud'=>$cloudId,'uid'=>$uid]);
        if(!$latest||(int)($latest['id']??0)!==$reviewId||($latest['decision']??'')!==$decision||($latest['verification_status']??'')!==$decision)return '审核状态已变化，不再发送旧审核结果';
        return '';
    }
    public function skipReviewSubscription(int $id,string $reason):void
    {
        $this->db->execute("UPDATE message_outbox SET status='skipped',next_attempt_at=NULL,attempt_count=GREATEST(0,attempt_count-1),last_error=:reason,payload_json=JSON_SET(payload_json,'$.delivery_skipped',true) WHERE id=:id AND status='processing'",['id'=>$id,'reason'=>$reason]);
    }
    public function invalidateSubscriptionForTemplate(int $userId,string $scene,string $templateId,?int $authorizationCount=null):bool
    {return SubscriptionQuotaStore::markSubscriptionPermissionUnavailable($this->db,$userId,$scene,$templateId,$authorizationCount);}
    public function markUnknown(int $id,string $error):void
    {
        $error=mb_substr(trim($error),0,1000,'UTF-8');
        $payload=$this->payloadWithAttempt("JSON_OBJECT('attempt',attempt_count,'at',DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-%dT%H:%i:%sZ'),'outcome','unknown','error',:history_error)");
        $payload="JSON_SET({$payload},'$.delivery_outcome','unknown')";
        $this->db->execute("UPDATE message_outbox SET payload_json={$payload},status='failed',next_attempt_at=NULL,last_error=:last_error WHERE id=:id AND status='processing'",['history_error'=>$error,'last_error'=>$error,'id'=>$id]);
    }
    public function recordAcceptedWarning(int $id,string $warning):void
    {
        $warning=mb_substr(trim($warning),0,1000,'UTF-8');
        $payload="JSON_SET(IF(JSON_VALID(payload_json),payload_json,JSON_OBJECT()),'$.post_acceptance_warning',:warning)";
        $this->db->execute("UPDATE message_outbox SET payload_json={$payload},last_error=:last_error WHERE id=:id AND status='processed' AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.subscription_consumed')),'false')<>'true'",['warning'=>$warning,'last_error'=>$warning,'id'=>$id]);
    }
    public function fail(int $id,string $error,?int $delayMinutes):void
    {
        $error=mb_substr(trim($error),0,1000,'UTF-8');
        $payload=$this->payloadWithAttempt("JSON_OBJECT('attempt',attempt_count,'at',DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-%dT%H:%i:%sZ'),'outcome','failed','error',:history_error)");
        $nextAttempt=$delayMinutes===null?'NULL':'DATE_ADD(UTC_TIMESTAMP(),INTERVAL '.max(1,min(60,$delayMinutes)).' MINUTE)';
        $this->db->execute("UPDATE message_outbox SET payload_json={$payload},status='failed',next_attempt_at={$nextAttempt},last_error=:last_error WHERE id=:id AND status='processing'",['history_error'=>$error,'last_error'=>$error,'id'=>$id]);
    }

    /** 最近保留 20 次尝试；非法历史 payload 保持原样。 */
    private function payloadWithAttempt(string $attemptExpression,string $receiptExpression=''):string
    {
        $base="IF(JSON_VALID(payload_json),payload_json,JSON_OBJECT())";
        $history="CASE WHEN JSON_TYPE(JSON_EXTRACT({$base},'$.delivery_attempts'))='ARRAY' THEN JSON_EXTRACT({$base},'$.delivery_attempts') ELSE JSON_ARRAY() END";
        $appended="JSON_ARRAY_APPEND({$history},'$',{$attemptExpression})";
        $bounded="IF(JSON_LENGTH({$appended})>20,JSON_REMOVE({$appended},'$[0]'),{$appended})";
        $sets="'$.delivery_attempts',{$bounded}";
        if($receiptExpression!=='')$sets="'$.delivery_receipt',{$receiptExpression},".$sets;
        return "CASE WHEN payload_json IS NOT NULL AND NOT JSON_VALID(payload_json) THEN payload_json ELSE JSON_SET({$base},{$sets}) END";
    }
    public function consumeSubscription(int $userId,string $scene):void { SubscriptionQuotaStore::markSubscriptionConsumed($this->db,$userId,$scene); }
    public function invalidateSubscription(int $userId,string $scene):void { SubscriptionQuotaStore::markSubscriptionPermissionUnavailable($this->db,$userId,$scene); }
    public function enqueueFallback(array $payload):bool { return OrderSubscriptionQueue::enqueueSmsFallback($this->db,$payload); }
}