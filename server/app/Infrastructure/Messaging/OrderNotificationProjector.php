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
        $parameters = [
            'order_no'=>(string)$order['public_no'],
            'status'=>$definition['status'],
            'amount'=>number_format(((int)($definition['amount_cents'] ?? $order['final_amount_cents']))/100,2,'.',''),
            'time'=>date('Y-m-d H:i'),
            'notice_text'=>trim((string)($payload['notice_text']??$payload['message']??$payload['remark']??$payload['reason']??'')),
        ];
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
        // 同一订单事件可能同时需要通知用户和师傅；主发送选择用户时，再为师傅补投同一根订阅模板。
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
            'chat_report_submitted'=>'chat.governance&section=cases',
            'chat_call_quota_low'=>'chat.calls&section=resources',
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
        if(!$duplicate)$db->insert("INSERT INTO notifications(public_id,recipient_type,recipient_id,channel,template_key,title,content,data_json,status,scheduled_at,created_at) VALUES(:public_id,'worker',:worker_id,'in_app','withdrawal_result',:title,:content,:data,'pending',UTC_TIMESTAMP(),UTC_TIMESTAMP())",[
            'public_id'=>\public_id(),'worker_id'=>$workerId,'title'=>$title,'content'=>$content,'data'=>$data,
        ]);
        $accountReasons=[
            'approved'=>'提现审核','rejected'=>'提现退回','paid'=>'提现付款','payment_failed'=>'提现失败',
        ];
        OrderSubscriptionQueue::queueWorkerAccountFunds(
            $db,$workerId,'withdrawal:'.$withdrawalNo.':'.$status.':account',
            $accountReasons[$status]??'提现变动',$amountCents,$content
        );
    }

    public static function queueWorkerReviewResult(Database $db, int $workerId, string $cloudId, string $decision, string $reason, string $workerName=''): void
    {
        if ($workerId < 1) return;
        $row = $db->fetch(
            "SELECT w.user_id,w.display_name,u.mobile_ciphertext FROM worker_profiles w JOIN users u ON u.id=w.user_id WHERE w.id=:id LIMIT 1",
            ['id'=>$workerId]
        );
        if (!$row) return;
        $labels=['approved'=>'审核通过','rejected'=>'审核未通过','suspended'=>'接单已暂停','pending'=>'等待审核'];
        $status=$labels[$decision]??'审核状态已更新';
        $reviewId=(int)$db->fetchValue('SELECT id FROM worker_verifications WHERE worker_id=:id ORDER BY id DESC LIMIT 1',['id'=>$workerId]);
        $data=['cloud_id'=>$cloudId,'review_status'=>$decision,'review_id'=>$reviewId];
        $dataJson=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $noticeId=(int)$db->fetchValue(
            "SELECT id FROM notifications WHERE recipient_type='worker' AND recipient_id=:id AND channel='in_app'
             AND template_key='worker_profile_review_result'
             AND JSON_REMOVE(IF(JSON_VALID(data_json),data_json,'{}'),'$.wechat_skip_reason')=CAST(:data AS JSON) LIMIT 1",
            ['id'=>$workerId,'data'=>$dataJson]
        );
        if (!$noticeId) {
            $noticeId=$db->insert(
                "INSERT INTO notifications(public_id,recipient_type,recipient_id,channel,template_key,title,content,data_json,status,scheduled_at,created_at)
                 VALUES(:public_id,'worker',:recipient_id,'in_app','worker_profile_review_result',:title,:content,:data,'pending',UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                ['public_id'=>\public_id(),'recipient_id'=>$workerId,'title'=>'师傅入驻'.$status,
                    'content'=>$reason!==''?$status.'：'.mb_substr($reason,0,80):$status,'data'=>$dataJson]
            );
        }
        if (!in_array($decision,['approved','rejected','suspended'],true)) return;
        $skip=self::enqueueReviewSubscription($db,$row,$cloudId,$decision,$reviewId,$status,$reason,$workerName);
        if ($skip!=='') $data['wechat_skip_reason']=$skip;
        $db->execute('UPDATE notifications SET data_json=:data WHERE id=:id',[
            'data'=>json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),'id'=>$noticeId,
        ]);
    }

    private static function enqueueReviewSubscription(Database $db, array $row, string $cloudId, string $decision, int $reviewId, string $status, string $reason, string $workerName): string
    {
        $scene=SubscriptionCatalog::subscriptionSceneDefinitions()['reviewResult'];
        $availability=(string)($scene['availability_reason']??'');
        if (empty($scene['available'])) {
            $messages=[
                'global_disabled'=>'微信订阅总开关未开启',
                'template_missing'=>'入驻通知未配置模板',
                'template_sync_required'=>'入驻通知模板需要先同步微信字段',
                'field_map_missing'=>'入驻通知模板字段映射未配置完整',
            ];
            return $messages[$availability]??'入驻通知模板暂不可用';
        }
        $userId=(int)$row['user_id'];
        $pref=SubscriptionQuotaStore::subscriptionPreferences($db,$userId)['reviewResult']??[];
        $authorization=(string)($pref['authorization_status']??'');
        if ($authorization==='template_changed') return '模板已更换，申请人需重新订阅';
        if (in_array($authorization,['ban','disabled'],true)) return '申请人已关闭入驻通知';
        if ($authorization==='') return '未记录申请人对当前模板的订阅授权';
        if (($scene['subscription_type']??'one_time')==='one_time') {
            if ((int)($pref['remaining_count']??0)<1) return '申请人入驻通知次数已用完或未获授权';
        } elseif (empty($pref['enabled']) || !in_array($authorization,['accept','acceptWithAudio','acceptWithAlert'],true)) {
            return '申请人未授权入驻通知';
        }
        $appId=trim((string)\setting('wechat.miniapp_appid',''));
        if ($appId==='') return '后台未配置小程序 AppID';
        $openid=trim((string)$db->fetchValue(
            "SELECT openid FROM wechat_identities WHERE user_id=:user_id AND BINARY app_id=BINARY :app_id AND status='active' ORDER BY id DESC LIMIT 1",
            ['user_id'=>$userId,'app_id'=>$appId]
        ));
        if ($openid==='') return '未找到申请人在当前小程序的账号';
        $mobile='';
        if (!empty($scene['field_map']['applicant_mobile'])) {
            $mobile=\decrypt_private_value((string)$row['mobile_ciphertext'],'user-mobile');
            if ($mobile==='') return '入驻模板需要申请人手机号，但该账号未绑定手机号';
        }
        $description=trim($reason);
        if ($description==='') $description=$decision==='approved'?'入驻资料审核通过':($decision==='rejected'?'请按处理说明修改资料':'接单状态已暂停');
        try {
            $data=SubscriptionCatalog::reviewResultData($scene,[
                'worker_name'=>trim($workerName)?:((string)$row['display_name']?:'入驻师傅'),
                'applicant_mobile'=>$mobile,'status'=>$status,'notice_text'=>$description,'audit_note'=>$description,
                'time'=>date('Y-m-d H:i'),
            ]);
        } catch (\RuntimeException $error) {
            return '入驻模板数据不符合要求，'.$error->getMessage();
        }
        $key='worker-review:'.$cloudId.':'.$decision.':'.($reviewId>0?'review-'.$reviewId:gmdate('YmdHis'));
        OrderSubscriptionQueue::enqueueWechatSubscribe(
            $db,$key,$userId,'reviewResult',$openid,(string)$scene['template_id'],
            'pages/record/detail?id='.rawurlencode($cloudId),$data
        );
        return '';
    }

    public static function eventEnabled(string $settingKey, string $eventType): bool
    {
        $channel = strpos($settingKey, 'wecom.') === 0 ? 'wecom' : (strpos($settingKey, 'notification.') === 0 ? 'inapp' : 'sms');
        $configured = \setting($settingKey, []);
        return MessageEventCatalog::enabled($channel, $eventType, is_array($configured) ? $configured : []);
    }
}
