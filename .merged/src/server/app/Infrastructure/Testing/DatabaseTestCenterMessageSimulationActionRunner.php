<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Contracts\TestCenterActionRunner;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;

/**
 * 测试中心消息兜底决策探针。
 *
 * 只消费当前实例已记录的 test_message_outbox 投影，不读取真实 OpenID/手机号，
 * 不调用 dispatcher/provider，也不写 message_outbox。
 */
final class DatabaseTestCenterMessageSimulationActionRunner implements TestCenterActionRunner
{
    private const ACTION='system_message_delivery_probe';
    private const OUTCOMES=['subscription_delivered','authorization_denied','quota_exhausted'];

    private Database $db;
    private TestCenterActionRunner $inner;
    private TestingActionExecution $execution;

    public function __construct(Database $db,TestCenterActionRunner $inner)
    {
        $this->db=$db;
        $this->inner=$inner;
        $this->execution=new TestingActionExecution($db);
    }

    public function available(string $instancePublicId): array
    {
        $result=$this->inner->available($instancePublicId);
        if(!$this->db->tableExists('test_message_outbox'))return$result;
        [$instance,$order]=$this->execution->context($instancePublicId);
        if(!$order)return$result;
        $candidate=$this->candidate((int)$instance['id'],0);
        if(!$candidate)return$result;

        $result['items'][self::ACTION]=[
            'key'=>self::ACTION,
            'label'=>'模拟订阅消息与短信兜底',
            'description'=>'基于当前实例已经产生的订阅消息 + subscription_fallback 短信投影，模拟授权成功、未授权或次数不足后的渠道决策。只写测试收件箱，不调用微信/阿里云。',
            'source_message_id'=>(int)$candidate['subscription_id'],
            'event_type'=>(string)$candidate['event_type'],
            'recipient_type'=>(string)$candidate['recipient_type'],
            'scene_key'=>(string)$candidate['scene_key'],
            'outcomes'=>[
                ['key'=>'subscription_delivered','label'=>'订阅可用：模拟发送成功，短信不触发'],
                ['key'=>'authorization_denied','label'=>'未授权：模拟订阅失败，触发短信兜底'],
                ['key'=>'quota_exhausted','label'=>'次数不足：模拟订阅失败，触发短信兜底'],
            ],
        ];
        return$result;
    }

    public function run(string $instancePublicId,string $actionKey,array $input,int $adminId,string $requestKey): array
    {
        if($actionKey!==self::ACTION)return$this->inner->run($instancePublicId,$actionKey,$input,$adminId,$requestKey);

        $sourceId=(int)($input['source_message_id']??0);
        $outcome=trim((string)($input['delivery_outcome']??''));
        if($sourceId<1||!in_array($outcome,self::OUTCOMES,true)){
            throw new TestCenterException('消息模拟参数无效，请刷新页面后重试。','TEST_MESSAGE_PROBE_INPUT_INVALID',422);
        }

        return$this->execution->run(
            $instancePublicId,
            self::ACTION,
            ['source_message_id'=>$sourceId,'delivery_outcome'=>$outcome],
            $adminId,
            $requestKey,
            'system',
            'simulated_external',
            function(array $instance,array $order,array $command)use($requestKey):array{
                $candidate=$this->candidate((int)$instance['id'],(int)$command['source_message_id']);
                if(!$candidate){
                    throw new TestCenterException('原消息投影已变化，当前不再具备订阅转短信兜底条件。','TEST_MESSAGE_PROBE_SOURCE_CHANGED',409);
                }
                $orderId=(int)$candidate['order_id'];
                $orderNo=(string)$this->db->fetchValue('SELECT public_no FROM orders WHERE id=:id',['id'=>$orderId]);
                $before=$this->realOutboxCount($orderId,$orderNo);
                $outcome=(string)$command['delivery_outcome'];

                $subscriptionStatus=$outcome==='subscription_delivered'?'simulated_delivered':'simulated_failed';
                $subscriptionReason=$outcome==='subscription_delivered'?'subscription_delivery_simulated':$outcome;
                $smsStatus=$outcome==='subscription_delivered'?'not_run':'simulated_delivered';
                $smsReason=$outcome==='subscription_delivered'?'subscription_delivered_no_fallback':'subscription_fallback_simulated';

                $this->db->transaction(function(Database $db)use($instance,$candidate,$outcome,$requestKey,$subscriptionStatus,$subscriptionReason,$smsStatus,$smsReason):void{
                    $payloadBase=[
                        'simulation_probe'=>true,
                        'source_subscription_message_id'=>(int)$candidate['subscription_id'],
                        'source_sms_message_id'=>(int)$candidate['sms_id'],
                        'delivery_outcome'=>$outcome,
                        'external_delivery_executed'=>false,
                    ];
                    $this->insertProbe($db,(int)$instance['id'],$candidate,'wechat_subscribe',$subscriptionStatus,$subscriptionReason,$requestKey,$payloadBase);
                    $this->insertProbe($db,(int)$instance['id'],$candidate,'aliyun_sms',$smsStatus,$smsReason,$requestKey,$payloadBase);
                });

                $after=$this->realOutboxCount($orderId,$orderNo);
                if($after!==$before){
                    throw new TestCenterException('消息模拟期间检测到真实 message_outbox 发生变化，已拒绝把本次结果标记为安全通过。','TEST_MESSAGE_PROBE_REAL_OUTBOX_CHANGED',500,['before'=>$before,'after'=>$after]);
                }

                return[
                    'source_message_id'=>(int)$candidate['subscription_id'],
                    'event_type'=>(string)$candidate['event_type'],
                    'scene_key'=>(string)$candidate['scene_key'],
                    'delivery_outcome'=>$outcome,
                    'subscription'=>['status'=>$subscriptionStatus,'reason'=>$subscriptionReason],
                    'sms_fallback'=>['status'=>$smsStatus,'reason'=>$smsReason],
                    'real_message_outbox_before'=>$before,
                    'real_message_outbox_after'=>$after,
                    'external_delivery_executed'=>false,
                ];
            }
        );
    }

    private function candidate(int $instanceId,int $subscriptionId): ?array
    {
        $where=$subscriptionId>0?' AND sub.id=:subscription_id':'';
        $params=['instance'=>$instanceId];
        if($subscriptionId>0)$params['subscription_id']=$subscriptionId;
        $row=$this->db->fetch(
            "SELECT sub.id subscription_id,sub.order_id,sub.event_key,sub.event_type,sub.recipient_type,sub.recipient_id,sub.scene_key,
                    sub.projection_status subscription_status,sms.id sms_id,sms.projection_status sms_status,sms.reason_code sms_reason
             FROM test_message_outbox sub
             INNER JOIN test_message_outbox sms
               ON sms.instance_id=sub.instance_id
              AND sms.order_id=sub.order_id
              AND BINARY sms.event_key=BINARY sub.event_key
              AND BINARY sms.event_type=BINARY sub.event_type
              AND BINARY sms.recipient_type=BINARY sub.recipient_type
              AND COALESCE(sms.recipient_id,0)=COALESCE(sub.recipient_id,0)
              AND sms.channel='aliyun_sms'
             WHERE sub.instance_id=:instance
               AND sub.channel='wechat_subscribe'
               AND sms.projection_status='not_run'
               AND sms.reason_code='subscription_fallback_not_triggered_in_demo'".$where."
             ORDER BY sub.id DESC LIMIT 1",
            $params
        );
        return$row?:null;
    }

    private function insertProbe(Database $db,int $instanceId,array $source,string $channel,string $status,string $reason,string $requestKey,array $payload): void
    {
        $identity='message-probe:'.$instanceId.':'.(int)$source['subscription_id'].':'.$requestKey.':'.$channel;
        $businessKey=strlen($identity)<=190?$identity:substr($identity,0,125).':'.hash('sha256',$identity);
        $db->execute(
            "INSERT IGNORE INTO test_message_outbox(instance_id,order_id,business_key,event_key,event_type,channel,recipient_type,recipient_id,scene_key,projection_status,reason_code,payload_json,created_at)
             VALUES(:instance,:order_id,:business_key,:event_key,:event_type,:channel,:recipient_type,:recipient_id,:scene_key,:status,:reason,:payload,UTC_TIMESTAMP())",
            [
                'instance'=>$instanceId,
                'order_id'=>(int)$source['order_id'],
                'business_key'=>$businessKey,
                'event_key'=>(string)$source['event_key'],
                'event_type'=>(string)$source['event_type'],
                'channel'=>$channel,
                'recipient_type'=>(string)$source['recipient_type'],
                'recipient_id'=>empty($source['recipient_id'])?null:(int)$source['recipient_id'],
                'scene_key'=>(string)$source['scene_key'],
                'status'=>$status,
                'reason'=>$reason,
                'payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ]
        );
    }

    private function realOutboxCount(int $orderId,string $orderNo): int
    {
        if(!$this->db->tableExists('message_outbox'))return 0;
        return(int)$this->db->fetchValue(
            'SELECT COUNT(*) FROM message_outbox WHERE payload_json LIKE :id_needle OR payload_json LIKE :no_needle OR business_key LIKE :business_needle',
            [
                'id_needle'=>'%"order_id":'.$orderId.'%',
                'no_needle'=>'%'.$orderNo.'%',
                'business_needle'=>'%'.$orderNo.'%',
            ]
        );
    }
}
