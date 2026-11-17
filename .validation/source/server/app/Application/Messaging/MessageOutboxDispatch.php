<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Messaging;

use Throwable;
use RuntimeException;
use WorkerAdmin\Contracts\MessageDeliveryQueue;
use WorkerAdmin\Contracts\MessageDeliveryReceiptQueue;
use WorkerAdmin\Contracts\SmsGateway;
use WorkerAdmin\Contracts\SubscribeMessageGateway;
use WorkerAdmin\Contracts\AdminAlertGateway;
use WorkerAdmin\Domain\Messaging\MessageDeliveryFailurePolicy;
use WorkerAdmin\Domain\Messaging\MessageEventCatalog;

final class MessageOutboxDispatch
{
    private MessageDeliveryQueue $queue;private SmsGateway $sms;private SubscribeMessageGateway $subscribe;private AdminAlertGateway $wecom;
    public function __construct(MessageDeliveryQueue $queue,SmsGateway $sms,SubscribeMessageGateway $subscribe,AdminAlertGateway $wecom)
    { $this->queue=$queue;$this->sms=$sms;$this->subscribe=$subscribe;$this->wecom=$wecom; }
    public function dispatch(int $limit=100):array
    {
        $limit=max(1,min(500,$limit));
        $this->queue->assertSendBoundary();
        // 上一轮 PHP/网络异常若卡死在 processing，租约到期后回收到 failed，再按既有重试次数继续处理。
        $this->queue->recoverExpiredClaims();
        $rows=$this->queue->pending($limit);
        $stats=['scanned'=>count($rows),'success'=>0,'failure'=>0];$sms=$this->sms;$wecom=$this->wecom;$wechatSubscribe=$this->subscribe;
        foreach($rows as $row){
            $payload=[];$receipt=[];$providerAccepted=false;
            try{
                if(!$this->queue->claim((int)$row['id']))continue;
                $payload=json_decode((string)$row['payload_json'],true);if(!is_array($payload))throw new RuntimeException('消息发件箱内容不是有效 JSON。');
                $meta=MessageEventCatalog::get((string)($payload['event_type']??''));
                if(!empty($meta['notification_retired']) || ((string)$row['topic']==='wechat_subscribe' && ($payload['scene_key']??'')==='payment_success')){
                    $this->queue->fail((int)$row['id'],'该重复或非必要通知已停用，不再发送。',null);continue;
                }
                if((string)$row['topic']==='wecom_webhook'){
                    $result=$wecom->send((string)($payload['content']??''),isset($payload['action_path'])&&is_string($payload['action_path'])?$payload['action_path']:null);
                    if(!in_array($result['errcode']??null,[0,'0'],true))throw new RuntimeException('企业微信未返回有效的成功受理结果。');
                    $providerAccepted=true;$meta=is_array($result['delivery_receipt']??null)?$result['delivery_receipt']:[];
                    $receipt=['provider'=>'wecom','errcode'=>0,'accepted_at'=>gmdate('c'),'delivery_status'=>'unconfirmed'];
                    if(in_array($meta['message_type']??'',['text','markdown','template_card'],true))$receipt['message_type']=$meta['message_type'];
                    if(($meta['card_type']??'')==='text_notice')$receipt['card_type']='text_notice';
                    if(preg_match('/^[a-f0-9]{12}$/',(string)($meta['target_fingerprint']??''))===1)$receipt['target_fingerprint']=$meta['target_fingerprint'];
                }elseif((string)$row['topic']==='wechat_subscribe'){
                    $result=$wechatSubscribe->send($payload);
                    if(!in_array($result['errcode']??null,[0,'0'],true))throw new RuntimeException('微信订阅未返回有效的成功结果。');
                    $providerAccepted=true;$receipt=['provider'=>'wechat','errcode'=>0,'accepted_at'=>gmdate('c'),'delivery_status'=>'unconfirmed'];
                }else{
                    $result=$sms->send((string)($payload['mobile']??''),(string)($payload['template_code']??''),(array)($payload['parameters']??[]),(string)$row['business_key']);
                    if(($result['Code']??'')!=='OK')throw new RuntimeException('短信服务未返回有效的成功受理结果。');
                    $providerAccepted=true;$receipt=['provider'=>'aliyun','provider_code'=>'OK','biz_id'=>(string)($result['BizId']??''),'request_id'=>(string)($result['RequestId']??''),'accepted_at'=>gmdate('c'),'delivery_status'=>'unconfirmed'];
                }
                if((string)$row['topic']==='wechat_subscribe'){
                    if($this->queue instanceof MessageDeliveryReceiptQueue){
                        try{
                            $this->queue->completeWithReceiptAndConsumeSubscription((int)$row['id'],$receipt,(int)($payload['user_id']??0),(string)($payload['scene_key']??''));
                        }catch(Throwable $quotaOrPersistenceException){
                            $warning='微信已受理，但本地订阅次数或受理状态同步失败；系统不会重新发送。'.mb_substr($quotaOrPersistenceException->getMessage(),0,180,'UTF-8');
                            try{
                                $this->queue->completeWithReceipt((int)$row['id'],$receipt);
                                $this->queue->recordAcceptedWarning((int)$row['id'],$warning);
                            }catch(Throwable $receiptException){
                                try{$this->queue->markUnknown((int)$row['id'],'微信已受理，但本地无法保存受理回执；结果未知，已停止自动重试。');}
                                catch(Throwable $unknownException){if(function_exists('write_log'))\write_log('app','['.date('c').'] message_outbox='.(int)$row['id'].' accepted_receipt_persist_failed '.mb_substr($unknownException->getMessage(),0,180,'UTF-8')."\n");}
                            }
                            if(function_exists('write_log'))\write_log('app','['.date('c').'] message_outbox='.(int)$row['id'].' post_acceptance_reconciliation_failed '.mb_substr($warning,0,260,'UTF-8')."\n");
                        }
                    }else{
                        $this->queue->complete((int)$row['id']);
                        try{$this->queue->consumeSubscription((int)($payload['user_id']??0),(string)($payload['scene_key']??''));}
                        catch(Throwable $quotaException){if(function_exists('write_log'))\write_log('app','['.date('c').'] message_outbox='.(int)$row['id'].' subscription_quota_sync_failed '.mb_substr($quotaException->getMessage(),0,180,'UTF-8')."\n");}
                    }
                }elseif($receipt&&$this->queue instanceof MessageDeliveryReceiptQueue)$this->queue->completeWithReceipt((int)$row['id'],$receipt);
                else $this->queue->complete((int)$row['id']);
                $stats['success']++;
            }catch(Throwable $exception){
                if($providerAccepted){
                    $unknown='渠道已明确受理，但本地受理回执未能确认；发送结果未知，已停止自动重试。';
                    try{if($this->queue instanceof MessageDeliveryReceiptQueue)$this->queue->markUnknown((int)$row['id'],$unknown);else $this->queue->fail((int)$row['id'],$unknown,null);}
                    catch(Throwable $stateException){if(function_exists('write_log'))\write_log('app','['.date('c').'] message_outbox='.(int)$row['id'].' accepted_state_persist_failed '.mb_substr($stateException->getMessage(),0,180,'UTF-8')."\n");}
                    $stats['failure']++;continue;
                }
                $attempt=(int)$row['attempt_count']+1;$error=mb_substr($exception->getMessage(),0,900);
                if($exception instanceof \ParseError){
                    $requestId=\request_id();
                    \write_log('app',sprintf("[%s] request_id=%s message_outbox=%d PHP=%s %s:%d %s\n",date('c'),$requestId,(int)$row['id'],PHP_VERSION,$exception->getFile(),$exception->getLine(),$error));
                    $location=basename($exception->getFile()).':'.$exception->getLine();
                    $error='PHP 语法错误：'.$location.'；'.mb_substr($exception->getMessage(),0,260,'UTF-8').'（请求编号 '.$requestId.'）';
                }
                $decision=MessageDeliveryFailurePolicy::decide((string)$row['topic'],$attempt,$error);
                $terminal=!empty($decision['terminal']);$wechatPermanentCode=(int)($decision['permanent_code']??0);
                if($wechatPermanentCode>0)$error.='；识别为微信不可重试错误';
                if(!empty($decision['invalidate_subscription'])){
                    try{$this->queue->invalidateSubscription((int)($payload['user_id']??0),(string)($payload['scene_key']??''));$error.='；已同步订阅次数为 0';}
                    catch(Throwable $quotaException){$error.='；订阅次数同步失败：'.mb_substr($quotaException->getMessage(),0,160,'UTF-8');if(function_exists('write_log'))\write_log('app','['.date('c').'] message_outbox='.(int)$row['id'].' subscription_invalidation_failed '.mb_substr($quotaException->getMessage(),0,180,'UTF-8')."\n");}
                }
                if($terminal&&!empty($decision['fallback_reason'])&&(string)$row['topic']==='wechat_subscribe'&&isset($payload['sms_fallback'])&&is_array($payload['sms_fallback'])){
                    $fallback=$payload['sms_fallback'];$fallback['fallback_reason']=(string)($decision['fallback_reason']??'wechat_retry_exhausted');
                    try{
                        if($this->queue->enqueueFallback($fallback))$error.=$wechatPermanentCode>0?'；微信订阅不可重试，已立即转短信兜底':'；微信订阅重试已耗尽，已转短信兜底';
                        else $error.=$wechatPermanentCode>0?'；微信订阅不可重试，但短信兜底当前未启用或模板未配置':'；微信订阅重试已耗尽，但短信兜底当前未启用或模板未配置';
                    }catch(Throwable $fallbackException){$error.='；短信兜底入队失败：'.mb_substr($fallbackException->getMessage(),0,160,'UTF-8');}
                }
                $this->queue->fail((int)$row['id'],mb_substr($error,0,1000),$terminal?null:(int)$decision['retry_delay_minutes']);
                $stats['failure']++;
            }
        }
        return $stats;

    }
}
