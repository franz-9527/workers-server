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
            $payload=[];$receipt=[];
            try{
                if(!$this->queue->claim((int)$row['id']))continue;
                $payload=json_decode((string)$row['payload_json'],true);if(!is_array($payload))throw new RuntimeException('消息发件箱内容不是有效 JSON。');
                $meta=MessageEventCatalog::get((string)($payload['event_type']??''));
                if(!empty($meta['notification_retired']) || ((string)$row['topic']==='wechat_subscribe' && ($payload['scene_key']??'')==='payment_success')){
                    $this->queue->fail((int)$row['id'],'该重复或非必要通知已停用，不再发送。',null);
                    continue;
                }
                if((string)$row['topic']==='wecom_webhook'){
                    $result=$wecom->send((string)($payload['content']??''),isset($payload['action_path'])&&is_string($payload['action_path'])?$payload['action_path']:null);
                    if(!in_array($result['errcode']??null,[0,'0'],true))throw new RuntimeException('企业微信未返回有效的成功受理结果。');
                    $meta=is_array($result['delivery_receipt']??null)?$result['delivery_receipt']:[];
                    $receipt=['provider'=>'wecom','errcode'=>0,'accepted_at'=>gmdate('c')];
                    if(in_array($meta['message_type']??'',['text','markdown','template_card'],true))$receipt['message_type']=$meta['message_type'];
                    if(preg_match('/^[a-f0-9]{12}$/',(string)($meta['target_fingerprint']??''))===1)$receipt['target_fingerprint']=$meta['target_fingerprint'];
                }
                elseif((string)$row['topic']==='wechat_subscribe'){$wechatSubscribe->send($payload);$this->queue->consumeSubscription((int)($payload['user_id']??0),(string)($payload['scene_key']??''));}
                else $sms->send((string)($payload['mobile']??''),(string)($payload['template_code']??''),(array)($payload['parameters']??[]),(string)$row['business_key']);
                if($receipt&&$this->queue instanceof MessageDeliveryReceiptQueue)$this->queue->completeWithReceipt((int)$row['id'],$receipt);
                else $this->queue->complete((int)$row['id']);
                $stats['success']++;
            }catch(Throwable $exception){
                $attempt=(int)$row['attempt_count']+1;
                $error=mb_substr($exception->getMessage(),0,900);
                $decision=MessageDeliveryFailurePolicy::decide((string)$row['topic'],$attempt,$error);
                $terminal=!empty($decision['terminal']);
                $wechatPermanentCode=(int)($decision['permanent_code']??0);
                if($wechatPermanentCode>0)$error.='；识别为微信不可重试错误';

                // 微信 43101 代表当前没有可用订阅权限；同步归零，避免继续显示虚假额度。
                if(!empty($decision['invalidate_subscription'])){
                    $this->queue->invalidateSubscription((int)($payload['user_id']??0),(string)($payload['scene_key']??''));
                    $error.='；已同步订阅次数为 0';
                }
                // “订阅补充短信”在微信最终失败、权限失效或明确不可重试错误时立即转短信；业务键独立且 INSERT IGNORE，人工重试也不会重复补发。
                if($terminal&&(string)$row['topic']==='wechat_subscribe'&&isset($payload['sms_fallback'])&&is_array($payload['sms_fallback'])){
                    $fallback=$payload['sms_fallback'];
                    $fallback['fallback_reason']=(string)($decision['fallback_reason']??'wechat_retry_exhausted');
                    try{
                        if($this->queue->enqueueFallback($fallback))$error.=$wechatPermanentCode>0?'；微信订阅不可重试，已立即转短信兜底':'；微信订阅重试已耗尽，已转短信兜底';
                        else $error.=$wechatPermanentCode>0?'；微信订阅不可重试，但短信兜底当前未启用或模板未配置':'；微信订阅重试已耗尽，但短信兜底当前未启用或模板未配置';
                    }catch(Throwable $fallbackException){
                        $error.='；短信兜底入队失败：'.mb_substr($fallbackException->getMessage(),0,160);
                    }
                }
                $this->queue->fail(
                    (int)$row['id'],
                    mb_substr($error,0,1000),
                    $terminal?null:(int)$decision['retry_delay_minutes']
                );
                $stats['failure']++;
            }
        }
        return $stats;

    }
}
