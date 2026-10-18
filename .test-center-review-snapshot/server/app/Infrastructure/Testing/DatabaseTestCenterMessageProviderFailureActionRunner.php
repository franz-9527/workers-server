<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Contracts\TestCenterActionRunner;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Messaging\MessageDeliveryFailurePolicy;
use WorkerAdmin\Domain\Testing\TestCenterException;

/**
 * 测试中心 provider 失败/重试/降级探针。
 *
 * 复用正式 MessageDeliveryFailurePolicy，只向 test_message_outbox 写模拟尝试；
 * 不调用真实 dispatcher/provider，不读真实收件地址，也不写 message_outbox。
 */
final class DatabaseTestCenterMessageProviderFailureActionRunner implements TestCenterActionRunner
{
    private const ACTION='system_message_provider_failure_probe';
    private const SCENARIOS=[
        'wechat_permanent_43101',
        'wechat_transient_exhausted',
        'sms_transient_exhausted',
    ];

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
            'label'=>'模拟 provider 失败、重试与降级',
            'description'=>'复用正式消息失败策略，验证微信永久错误立即短信兜底、网络错误按 1/2/4/8 分钟重试后兜底，以及兜底短信自身 5 次失败后终止且不再递归降级。',
            'source_message_id'=>(int)$candidate['subscription_id'],
            'event_type'=>(string)$candidate['event_type'],
            'recipient_type'=>(string)$candidate['recipient_type'],
            'scene_key'=>(string)$candidate['scene_key'],
            'scenarios'=>[
                ['key'=>'wechat_permanent_43101','label'=>'微信 43101：立即终止并短信兜底一次'],
                ['key'=>'wechat_transient_exhausted','label'=>'微信网络错误：1/2/4/8 分钟重试，5 次后短信兜底一次'],
                ['key'=>'sms_transient_exhausted','label'=>'兜底短信网络错误：1/2/4/8 分钟重试，5 次后终止'],
            ],
        ];
        return$result;
    }

    public function run(string $instancePublicId,string $actionKey,array $input,int $adminId,string $requestKey): array
    {
        if($actionKey!==self::ACTION)return$this->inner->run($instancePublicId,$actionKey,$input,$adminId,$requestKey);

        $sourceId=(int)($input['source_message_id']??0);
        $scenario=trim((string)($input['provider_scenario']??''));
        if($sourceId<1||!in_array($scenario,self::SCENARIOS,true)){
            throw new TestCenterException('provider 失败模拟参数无效，请刷新页面后重试。','TEST_MESSAGE_PROVIDER_PROBE_INPUT_INVALID',422);
        }

        return$this->execution->run(
            $instancePublicId,
            self::ACTION,
            ['source_message_id'=>$sourceId,'provider_scenario'=>$scenario],
            $adminId,
            $requestKey,
            'system',
            'simulated_external',
            function(array $instance,array $order,array $command)use($requestKey):array{
                $candidate=$this->candidate((int)$instance['id'],(int)$command['source_message_id']);
                if(!$candidate){
                    throw new TestCenterException('原消息投影已变化，当前不再具备 provider 失败模拟条件。','TEST_MESSAGE_PROVIDER_PROBE_SOURCE_CHANGED',409);
                }

                $orderId=(int)$candidate['order_id'];
                $orderNo=(string)$this->db->fetchValue('SELECT public_no FROM orders WHERE id=:id',['id'=>$orderId]);
                $before=$this->realOutboxCount($orderId,$orderNo);
                $scenario=(string)$command['provider_scenario'];

                if($scenario==='wechat_permanent_43101'){
                    $result=$this->simulateWechatPermanent((int)$instance['id'],$candidate,$requestKey);
                }elseif($scenario==='wechat_transient_exhausted'){
                    $result=$this->simulateTransientExhaustion((int)$instance['id'],$candidate,$requestKey,'wechat_subscribe');
                }else{
                    $result=$this->simulateTransientExhaustion((int)$instance['id'],$candidate,$requestKey,'aliyun_sms');
                }

                $after=$this->realOutboxCount($orderId,$orderNo);
                if($after!==$before){
                    throw new TestCenterException('provider 模拟期间检测到真实 message_outbox 发生变化，已拒绝把本次结果标记为安全通过。','TEST_MESSAGE_PROVIDER_REAL_OUTBOX_CHANGED',500,['before'=>$before,'after'=>$after]);
                }

                $result['source_message_id']=(int)$candidate['subscription_id'];
                $result['event_type']=(string)$candidate['event_type'];
                $result['scene_key']=(string)$candidate['scene_key'];
                $result['provider_scenario']=$scenario;
                $result['real_message_outbox_before']=$before;
                $result['real_message_outbox_after']=$after;
                $result['external_delivery_executed']=false;
                return$result;
            }
        );
    }

    private function simulateWechatPermanent(int $instanceId,array $source,string $requestKey): array
    {
        $decision=MessageDeliveryFailurePolicy::decide('wechat_subscribe',1,'微信订阅消息发送失败（43101）：用户拒绝或无可用订阅权限');
        if(empty($decision['terminal'])
            ||(int)($decision['permanent_code']??0)!==43101
            ||empty($decision['invalidate_subscription'])
            ||(string)($decision['fallback_reason']??'')!=='wechat_permanent_43101'
            ||$decision['retry_delay_minutes']!==null){
            throw new TestCenterException('正式失败策略没有返回预期的 43101 立即降级结果。','TEST_MESSAGE_PROVIDER_POLICY_DRIFT',500,['decision'=>$decision]);
        }

        $payload=['provider_probe'=>true,'provider_error'=>'wechat_43101','decision'=>$decision,'external_delivery_executed'=>false];
        $this->db->transaction(function(Database $db)use($instanceId,$source,$requestKey,$payload):void{
            $this->insertAttempt($db,$instanceId,$source,'wechat_subscribe','simulated_failed','wechat_permanent_43101',$requestKey,1,$payload);
            // 同一 provider 结果重复回调：业务键相同，只能留下一个终态尝试事实。
            $this->insertAttempt($db,$instanceId,$source,'wechat_subscribe','simulated_failed','wechat_permanent_43101',$requestKey,1,$payload);
            $this->insertFallback($db,$instanceId,$source,$requestKey,'wechat_permanent_43101',$payload);
            $this->insertFallback($db,$instanceId,$source,$requestKey,'wechat_permanent_43101',$payload);
        });

        $attempts=$this->probeCount($instanceId,$requestKey,'wechat_subscribe');
        $fallbacks=$this->probeCount($instanceId,$requestKey,'aliyun_sms');
        if($attempts!==1||$fallbacks!==1){
            throw new TestCenterException('43101 重复回执未保持一次失败事实和一次短信兜底。','TEST_MESSAGE_PROVIDER_DUPLICATE_FALLBACK',500,['attempts'=>$attempts,'fallbacks'=>$fallbacks]);
        }

        return[
            'attempts'=>[['attempt'=>1,'terminal'=>true,'retry_delay_minutes'=>null,'reason'=>'wechat_permanent_43101']],
            'fallback_count'=>$fallbacks,
            'subscription_invalidated_simulated'=>true,
            'duplicate_provider_result_deduplicated'=>true,
        ];
    }

    private function simulateTransientExhaustion(int $instanceId,array $source,string $requestKey,string $topic): array
    {
        $attempts=[];
        $fallbackReason='';
        $this->db->transaction(function(Database $db)use($instanceId,$source,$requestKey,$topic,&$attempts,&$fallbackReason):void{
            for($attempt=1;$attempt<=5;$attempt++){
                $decision=MessageDeliveryFailurePolicy::decide($topic,$attempt,'network timeout');
                $expectedDelay=$attempt<5?(int)pow(2,$attempt-1):null;
                if((bool)$decision['terminal']!==($attempt===5)
                    ||$decision['retry_delay_minutes']!==$expectedDelay){
                    throw new TestCenterException('正式消息重试退避策略与预期不一致。','TEST_MESSAGE_PROVIDER_POLICY_DRIFT',500,['topic'=>$topic,'attempt'=>$attempt,'decision'=>$decision]);
                }
                if($topic==='aliyun_sms'&&$decision['fallback_reason']!==null){
                    throw new TestCenterException('短信失败不应继续递归降级到其它渠道。','TEST_MESSAGE_PROVIDER_RECURSIVE_FALLBACK',500,['decision'=>$decision]);
                }

                $status=$attempt<5?'simulated_retry':'simulated_failed';
                $reason=$attempt<5?'provider_transient_retry':'provider_retry_exhausted';
                $payload=['provider_probe'=>true,'provider_error'=>'network_timeout','decision'=>$decision,'external_delivery_executed'=>false];
                $this->insertAttempt($db,$instanceId,$source,$topic,$status,$reason,$requestKey,$attempt,$payload);
                $attempts[]=[
                    'attempt'=>$attempt,
                    'terminal'=>(bool)$decision['terminal'],
                    'retry_delay_minutes'=>$decision['retry_delay_minutes'],
                    'fallback_reason'=>$decision['fallback_reason'],
                ];
                if($attempt===5&&$topic==='wechat_subscribe'){
                    $fallbackReason=(string)$decision['fallback_reason'];
                    if($fallbackReason!=='wechat_retry_exhausted'){
                        throw new TestCenterException('微信第 5 次临时失败没有进入预期短信兜底。','TEST_MESSAGE_PROVIDER_FALLBACK_REASON',500,['decision'=>$decision]);
                    }
                    $this->insertFallback($db,$instanceId,$source,$requestKey,$fallbackReason,$payload);
                    $this->insertFallback($db,$instanceId,$source,$requestKey,$fallbackReason,$payload);
                }
                if($attempt===5){
                    // 模拟 provider 重复返回同一终态结果，必须被 business_key 幂等吞掉。
                    $this->insertAttempt($db,$instanceId,$source,$topic,$status,$reason,$requestKey,$attempt,$payload);
                }
            }
        });

        $channelAttempts=$this->probeCount($instanceId,$requestKey,$topic);
        $fallbacks=$this->probeCount($instanceId,$requestKey,$topic==='wechat_subscribe'?'aliyun_sms':'wechat_subscribe');
        $expectedFallbacks=$topic==='wechat_subscribe'?1:0;
        if($channelAttempts!==5||$fallbacks!==$expectedFallbacks){
            throw new TestCenterException('provider 重试或降级次数不符合一次性边界。','TEST_MESSAGE_PROVIDER_RETRY_COUNT_INVALID',500,[
                'topic'=>$topic,'attempts'=>$channelAttempts,'fallbacks'=>$fallbacks,'expected_fallbacks'=>$expectedFallbacks,
            ]);
        }

        return[
            'topic'=>$topic,
            'attempts'=>$attempts,
            'fallback_count'=>$expectedFallbacks,
            'fallback_reason'=>$fallbackReason,
            'duplicate_provider_result_deduplicated'=>true,
            'recursive_fallback'=>false,
        ];
    }

    private function candidate(int $instanceId,int $subscriptionId): ?array
    {
        $where=$subscriptionId>0?' AND sub.id=:subscription_id':'';
        $params=['instance'=>$instanceId];
        if($subscriptionId>0)$params['subscription_id']=$subscriptionId;
        $row=$this->db->fetch(
            "SELECT sub.id subscription_id,sub.order_id,sub.event_key,sub.event_type,sub.recipient_type,sub.recipient_id,sub.scene_key,
                    sms.id sms_id
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

    private function insertAttempt(Database $db,int $instanceId,array $source,string $channel,string $status,string $reason,string $requestKey,int $attempt,array $payload): void
    {
        $identity='provider-probe:'.$instanceId.':'.(int)$source['subscription_id'].':'.$requestKey.':'.$channel.':attempt:'.$attempt;
        $this->insertProjection($db,$instanceId,$source,$channel,$status,$reason,$identity,array_merge($payload,['attempt'=>$attempt]));
    }

    private function insertFallback(Database $db,int $instanceId,array $source,string $requestKey,string $reason,array $payload): void
    {
        $identity='provider-probe:'.$instanceId.':'.(int)$source['subscription_id'].':'.$requestKey.':aliyun_sms:fallback';
        $this->insertProjection($db,$instanceId,$source,'aliyun_sms','simulated_queued',$reason,$identity,array_merge($payload,['fallback'=>true]));
    }

    private function insertProjection(Database $db,int $instanceId,array $source,string $channel,string $status,string $reason,string $identity,array $payload): void
    {
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

    private function probeCount(int $instanceId,string $requestKey,string $channel): int
    {
        return(int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM test_message_outbox
             WHERE instance_id=:instance
               AND channel=:channel
               AND business_key LIKE :prefix",
            ['instance'=>$instanceId,'channel'=>$channel,'prefix'=>'%:'.$requestKey.':%']
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
