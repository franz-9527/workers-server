<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Messaging;

use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Messaging\MessageEventCatalog;
use WorkerAdmin\Domain\Messaging\OrderMessageDefinition;
use WorkerAdmin\Domain\Messaging\WechatTemplateValue;
use WorkerAdmin\Infrastructure\Testing\TestMessageProjectionRecorder;

final class OrderNotificationProjector
{

    public static function queueAdminTask(Database $db,string $eventKey,string $eventType,string $title,array $lines=[]):void
    {
        // 与订单事件的 demo 隔离保持一致：带订单号的演示/测试订单绝不能进入真实企业微信管理员待办。
        // 无订单号的入驻、提现等独立业务仍沿用原有通知逻辑。
        $orderNo=trim((string)($lines['订单号']??''));
        if($orderNo!==''){
            $isDemo=(int)$db->fetchValue('SELECT COALESCE(is_demo,0) FROM orders WHERE BINARY public_no=BINARY :public_no LIMIT 1',['public_no'=>$orderNo]);
            if($isDemo===1)return;
        }
        if(!self::eventEnabled('wecom.event_switches',$eventType)||!\filter_var(\setting('wecom.webhook_enabled',false),FILTER_VALIDATE_BOOLEAN))return;
        // 2026-09-22：机器人首行直接显示具体事项标题，不再套“壁选后台待办”等笼统标题。
        $title=trim((string)(preg_replace('/[\\r\\n【】]+/u',' ',$title)??''));
        if($title==='')$title=$eventType;
        $title=mb_substr($title,0,40);
        $content='【'.$title.'】'."\n接收：管理员\n触发来源：".MessageEventCatalog::sourceLabel($eventType);
        foreach($lines as $label=>$value){$value=trim((string)$value);if($value!=='')$content.="\n".$label.'：'.$value;}
        $orderId=0;
        if(isset($lines['订单号'])&&trim((string)$lines['订单号'])!==''){
            $orderId=(int)$db->fetchValue('SELECT id FROM orders WHERE BINARY public_no=BINARY :public_no LIMIT 1',['public_no'=>(string)$lines['订单号']]);
        }
        $data=['content'=>$content,'event_type'=>$eventType,'title'=>$title,'action_path'=>self::adminActionPath($eventType,$orderId)];
        $db->execute("INSERT IGNORE INTO message_outbox(topic,business_key,payload_json,status,attempt_count,next_attempt_at,created_at) VALUES('wecom_webhook',:business_key,:payload,'pending',0,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['business_key'=>substr($eventKey.':admin:wecom',0,128),'payload'=>json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    }

    public static function queue(Database $db, int $orderId, string $eventKey, string $eventType, array $payload = []): void
    {
        $definition = OrderMessageDefinition::definition($eventType, $payload); if (!$definition) return;
        $order = $db->fetch("SELECT o.public_no,o.user_id,o.assigned_worker_id,o.final_amount_cents,o.booking_fee_cents,o.is_demo,u.mobile_ciphertext user_mobile FROM orders o JOIN users u ON u.id=o.user_id WHERE o.id=:id", ['id'=>$orderId]);
        if (!$order) return;
        $parameters = ['order_no'=>(string)$order['public_no'],'status'=>$definition['status'],'amount'=>number_format(((int)($definition['amount_cents'] ?? $order['final_amount_cents']))/100,2,'.',''),'time'=>date('Y-m-d H:i')];
        // 内部通知是基础渠道，可单独关闭某类进度消息；不影响外部通知。
        if (self::eventEnabled('notification.internal_event_switches', $eventType)) {
            $noticeData = json_encode(['order_id' => (int) $orderId, 'event_key' => $eventKey], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            foreach ($definition['recipients'] as $recipientType) {
                if (!in_array($recipientType, ['user', 'worker'], true)) continue;
                $recipientId = $recipientType === 'user' ? (int) $order['user_id'] : (int) ($order['assigned_worker_id'] ?? 0);
                if ($recipientId < 1) continue;
                $content = '订单 ' . $parameters['order_no'] . '：' . $definition['status'];
                if (!empty($definition['amount_cents'])) $content .= '，金额 ' . $parameters['amount'] . ' 元';
                $duplicate = $db->fetchValue("SELECT id FROM notifications WHERE recipient_type=:recipient_type AND recipient_id=:recipient_id AND channel='in_app' AND template_key=:template_key AND BINARY data_json=BINARY :data_json LIMIT 1", [
                    'recipient_type' => $recipientType, 'recipient_id' => $recipientId, 'template_key' => $eventType, 'data_json' => $noticeData,
                ]);
                if (!$duplicate) {
                    $db->insert("INSERT INTO notifications(public_id,recipient_type,recipient_id,channel,template_key,title,content,data_json,status,scheduled_at,created_at) VALUES(:public_id,:recipient_type,:recipient_id,'in_app',:template_key,:title,:content,:data,'pending',UTC_TIMESTAMP(),UTC_TIMESTAMP())", [
                        'public_id' => \public_id(), 'recipient_type' => $recipientType, 'recipient_id' => $recipientId,
                        'template_key' => $eventType, 'title' => $definition['status'], 'content' => $content, 'data' => $noticeData,
                    ]);
                }
            }
        }
        // V2.2.7：演示订单只落站内消息，绝不进入企业微信、微信订阅或短信发件箱。
        // 测试中心订单额外记录“应发但未真实投递”的模拟投影，不复用真实 message_outbox。
        if(!empty($order['is_demo'])){
            try{
                TestMessageProjectionRecorder::capture($db,$orderId,$eventKey,$eventType,$definition,$order,$parameters);
            }catch(\Throwable $exception){
                \write_log('test-center','['.date('c').'] message_projection_failed order_id='.$orderId.' event='.str_replace(["\r","\n"],' ',$eventType).' '.$exception->getMessage()."\n");
            }
            return;
        }
        // 2026-09-02：管理员通知独立进入企业微信机器人，不读取或发送管理员短信号码。
        if (in_array('admin', $definition['recipients'], true) && self::eventEnabled('wecom.event_switches', $eventType)
            && \filter_var(\setting('wecom.webhook_enabled', false), FILTER_VALIDATE_BOOLEAN)) {
            $adminTitle=trim((string)($definition['admin_title']??$definition['status']??''));
            $adminTitle=trim((string)(preg_replace('/[\\r\\n【】]+/u',' ',$adminTitle)??''));
            if($adminTitle==='')$adminTitle=$eventType;
            $adminTitle=mb_substr($adminTitle,0,40);
            $content = "【{$adminTitle}】\n接收：管理员\n触发来源：".(!empty($payload['assisted'])?'管理员代办':MessageEventCatalog::sourceLabel($eventType))."\n订单号：{$parameters['order_no']}\n时间：{$parameters['time']}";
            if ((string)$definition['status'] !== $adminTitle) $content .= "\n状态：{$definition['status']}";
            if (!empty($definition['amount_cents'])) $content .= "\n金额：{$parameters['amount']} 元";
            $adminData=['content'=>$content,'event_type'=>$eventType,'order_id'=>$orderId,'title'=>$adminTitle,'action_path'=>self::adminActionPath($eventType,$orderId,(string)$order['public_no'])];
            $db->execute("INSERT IGNORE INTO message_outbox(topic,business_key,payload_json,status,attempt_count,next_attempt_at,created_at) VALUES('wecom_webhook',:business_key,:payload,'pending',0,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['business_key'=>substr($eventKey.':admin:wecom',0,128),'payload'=>json_encode($adminData,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        }
        // 2026-10-01：外部触达分两种模式：
        // 1) 建议/普通短信仍按开关独立发送；
        // 2) “订阅补充短信”改为微信订阅优先，只有订阅不可用或最终失败时才进入短信队列。
        $smsEnabled=\filter_var(\setting('sms.enabled',false),FILTER_VALIDATE_BOOLEAN)
            && self::eventEnabled('sms.event_switches',$eventType);
        $eventMeta=MessageEventCatalog::get($eventType)??[];
        $fallbackMode=$smsEnabled && (($eventMeta['sms_delivery_mode']??'independent')==='subscription_fallback');
        $fallbackPayload=$fallbackMode
            ? OrderSubscriptionQueue::buildSubscriptionFallbackSmsPayload($db,$orderId,$eventKey,$eventType,$definition,$order,$parameters)
            : null;
        $subscription=OrderSubscriptionQueue::queueOrderSubscription(
            $db,$orderId,$eventKey,$eventType,$definition,$order,$parameters,
            is_array($fallbackPayload)?$fallbackPayload:[]
        );
        // 同一事件对用户和师傅的外部提醒语义可能不同。若事件需要师傅及时知道、
        // 但主订阅场景并不是师傅场景，则额外使用通用“师傅订单重要进度”模板补一条微信提醒。
        OrderSubscriptionQueue::queueWorkerOrderUpdateSupplement($db,$orderId,$eventKey,$eventType,$definition,$order,$parameters,$fallbackMode);
        if($fallbackMode){
            if(empty($subscription['queued'])&&is_array($fallbackPayload)){
                $fallbackPayload['fallback_reason']=(string)($subscription['reason']??'subscription_unavailable');
                OrderSubscriptionQueue::enqueueSmsFallback($db,$fallbackPayload);
            }
            return;
        }

        if(!$smsEnabled)return;
        $templates=\setting('sms.template_codes',[]);if(!is_array($templates))return;
        $configured=$templates[$definition['scene']]??'';
        $templateCode=is_array($configured)?(string)(($configured['template_code']??'')?:($configured['template_id']??'')?:($configured['code']??'')):(string)$configured;
        if($templateCode==='')return;
        $recipients=[];
        foreach($definition['recipients'] as $recipient){
            if($recipient==='user'){$mobile=\decrypt_private_value((string)$order['user_mobile'],'user-mobile');if($mobile!=='')$recipients['user:'.(int)$order['user_id']]=[$mobile,'user',(int)$order['user_id']];}
            elseif($recipient==='worker'&&!empty($order['assigned_worker_id'])){$row=$db->fetch('SELECT mobile_ciphertext FROM worker_profiles WHERE id=:id',['id'=>$order['assigned_worker_id']]);$mobile=$row?\decrypt_private_value((string)$row['mobile_ciphertext'],'worker-mobile'):'';if($mobile!=='')$recipients['worker:'.(int)$order['assigned_worker_id']]=[$mobile,'worker',(int)$order['assigned_worker_id']];}
        }
        foreach($recipients as $key=>$recipient){
            $data=['mobile'=>$recipient[0],'template_code'=>$templateCode,'parameters'=>$parameters,'event_type'=>$eventType,'order_id'=>$orderId,'recipient_type'=>$recipient[1],'recipient_id'=>$recipient[2]];
            $db->execute("INSERT IGNORE INTO message_outbox(topic,business_key,payload_json,status,attempt_count,next_attempt_at,created_at) VALUES('aliyun_sms',:business_key,:payload,'pending',0,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['business_key'=>substr($eventKey.':'.$key,0,128),'payload'=>json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        }
    }

    public static function adminActionPath(string $eventType, int $orderId=0, string $orderNo=''): ?string
    {
        $routes=[
            'platform_worker_quoted'=>'platform-orders',
            'platform_change_requested'=>'platform-order-finance',
            'merchant_auth_submitted'=>'merchants',
            'worker_profile_submitted'=>'workers',
            'worker_realname_submitted'=>'worker-credentials',
            'worker_collection_code_submitted'=>'worker-credentials',
            'worker_collection_code_auto_approved'=>'worker-credentials',
            'withdrawal_submitted'=>'withdrawals',
            'commission_overdue'=>'commissions',
        ];
        if (in_array($eventType, ['platform_measurement_auto_confirmed','platform_measurement_confirmed'], true) && $orderId > 0) {
            return $orderNo !== '' ? '/admin/?route=platform-orders.pricing-review&order_no='.rawurlencode($orderNo) : '/admin/?route=platform-orders';
        }
        if(isset($routes[$eventType]))return '/admin/?route='.$routes[$eventType];
        if($orderId>0)return '/admin/?route=orders.view&id='.$orderId;
        return null;
    }

    public static function queueWorkerWithdrawalResult(Database $db,int $workerId,string $withdrawalNo,string $status,string $note='',int $amountCents=0):void
    {
        if($workerId<1||$withdrawalNo==='')return;
        $labels=[
            'approved'=>['提现审核通过','提现申请已审核通过，等待平台登记付款。'],
            'rejected'=>['提现申请未通过','提现申请未通过，冻结金额已退回可用余额。'],
            'paid'=>['提现已付款','平台已登记人工付款，请核对支付宝到账情况。'],
            'payment_failed'=>['提现付款失败','人工付款未完成，请查看处理说明或等待平台重新处理。'],
        ];
        if(!isset($labels[$status]))return;
        [$title,$content]=$labels[$status];
        if($amountCents>0)$content.=' 金额 '.number_format($amountCents/100,2,'.','').' 元。';
        $note=trim($note);if($note!=='')$content.=' 说明：'.mb_substr($note,0,120);
        $data=json_encode(['withdrawal_no'=>$withdrawalNo,'status'=>$status],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $duplicate=$db->fetchValue("SELECT id FROM notifications WHERE recipient_type='worker' AND recipient_id=:worker_id AND channel='in_app' AND template_key='withdrawal_result' AND BINARY data_json=BINARY :data LIMIT 1",['worker_id'=>$workerId,'data'=>$data]);
        if($duplicate)return;
        $db->insert("INSERT INTO notifications(public_id,recipient_type,recipient_id,channel,template_key,title,content,data_json,status,scheduled_at,created_at) VALUES(:public_id,'worker',:worker_id,'in_app','withdrawal_result',:title,:content,:data,'pending',UTC_TIMESTAMP(),UTC_TIMESTAMP())",[
            'public_id'=>\public_id(),'worker_id'=>$workerId,'title'=>$title,'content'=>$content,'data'=>$data,
        ]);
    }

    public static function queueWorkerReviewResult(Database $db, int $workerId, string $cloudId, string $decision, string $reason, string $workerName=''): void
    {
        if ($workerId < 1) return;
        $row = $db->fetch("SELECT w.user_id,w.display_name,u.mobile_ciphertext,(SELECT wi.openid FROM wechat_identities wi WHERE wi.user_id=w.user_id AND wi.status='active' ORDER BY wi.id DESC LIMIT 1) openid FROM worker_profiles w JOIN users u ON u.id=w.user_id WHERE w.id=:id LIMIT 1", ['id'=>$workerId]);
        if (!$row) return;
        $labels=['approved'=>'审核通过','rejected'=>'审核未通过','suspended'=>'接单已暂停','pending'=>'等待审核'];
        $status=$labels[$decision]??'审核状态已更新';
        $dataJson=json_encode(['cloud_id'=>$cloudId,'review_status'=>$decision],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $duplicate=$db->fetchValue("SELECT id FROM notifications WHERE recipient_type='worker' AND recipient_id=:id AND channel='in_app' AND template_key='worker_profile_review_result' AND BINARY data_json=BINARY :data LIMIT 1",['id'=>$workerId,'data'=>$dataJson]);
        if(!$duplicate)$db->insert("INSERT INTO notifications(public_id,recipient_type,recipient_id,channel,template_key,title,content,data_json,status,scheduled_at,created_at) VALUES(:public_id,'worker',:recipient_id,'in_app','worker_profile_review_result',:title,:content,:data,'pending',UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public_id'=>\public_id(),'recipient_id'=>$workerId,'title'=>'师傅入驻'.$status,'content'=>$reason!==''?$status.'：'.mb_substr($reason,0,80):$status,'data'=>$dataJson]);
        if(!in_array($decision,['approved','rejected','suspended'],true))return;
        $scenes=SubscriptionCatalog::subscriptionSceneDefinitions();$scene=$scenes['reviewResult'];$userId=(int)$row['user_id'];$pref=SubscriptionQuotaStore::subscriptionPreferences($db,$userId)['reviewResult']??[];
        if(empty($scene['available']))return;
        $authorizationStatus=(string)($pref['authorization_status']??'');
        $subscriptionType=(string)($scene['subscription_type']??'one_time');
        if($subscriptionType==='one_time'){
            if(in_array($authorizationStatus,['ban','disabled'],true))return;
            if(max(0,(int)($pref['remaining_count']??0))<1)return;
        }else{
            if(empty($pref['enabled'])||!in_array($authorizationStatus,['accept','acceptWithAudio','acceptWithAlert'],true))return;
        }
        $openid=trim((string)$row['openid']);$mobile=\decrypt_private_value((string)$row['mobile_ciphertext'],'user-mobile');if($openid===''||$mobile==='')return;
        $name=mb_substr(trim($workerName)!==''?trim($workerName):(string)$row['display_name'],0,10);
        $thing=$decision==='approved'?'入驻审核已通过，请进入小程序查看。':($decision==='rejected'?'入驻审核未通过，请按处理说明修改。':'师傅接单状态已暂停，请查看处理说明。');
        if($reason!=='')$thing=mb_substr($thing.' '.trim($reason),0,20);
        OrderSubscriptionQueue::enqueueWechatSubscribe($db,'worker-review:'.$cloudId.':'.$decision.':'.gmdate('YmdHi'),$userId,'reviewResult',$openid,(string)$scene['template_id'],'pages/record/detail?id='.rawurlencode($cloudId),[
            'name1'=>['value'=>$name?:'入驻师傅'],
            'phone_number2'=>['value'=>$mobile],
            'phrase3'=>['value'=>$status],
            'thing5'=>['value'=>$thing],
        ]);
    }

    public static function eventEnabled(string $settingKey, string $eventType): bool
    {
        $channel = strpos($settingKey, 'wecom.') === 0 ? 'wecom' : (strpos($settingKey, 'notification.') === 0 ? 'inapp' : 'sms');
        $configured = \setting($settingKey, []);
        return MessageEventCatalog::enabled($channel, $eventType, is_array($configured) ? $configured : []);
    }
}
