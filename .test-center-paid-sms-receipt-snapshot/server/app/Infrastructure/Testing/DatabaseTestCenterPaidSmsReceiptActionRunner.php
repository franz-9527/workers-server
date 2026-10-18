<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Contracts\TestCenterActionRunner;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Messaging\PaidSmsPolicy;
use WorkerAdmin\Domain\Messaging\PaidSmsReceiptPolicy;
use WorkerAdmin\Domain\Testing\TestCenterException;

/**
 * 测试中心付费短信 SendDetails/BizId 回执探针。
 *
 * 使用纯策略构造非敏感模拟 invite/detail，只写 test_message_outbox；
 * 不创建 order_worker_sms_invites、不修改钱包、不调用阿里云 QuerySendDetails。
 */
final class DatabaseTestCenterPaidSmsReceiptActionRunner implements TestCenterActionRunner
{
    private const ACTION='system_paid_sms_receipt_probe';
    private const SCENARIOS=[
        'delivered_outid',
        'delivered_bizid',
        'delivery_failed',
        'missing_until_timeout',
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
        if(!$order||!$this->eligibleOrder($order))return$result;

        $result['items'][self::ACTION]=[
            'key'=>self::ACTION,
            'label'=>'模拟付费短信异步回执',
            'description'=>'使用与正式短信一致的 OutId/BizId 回执匹配、送达/失败计费分类和回执退避策略。只记录测试证据，不创建真实短信邀请、不扣/退真实钱包。',
            'scenarios'=>[
                ['key'=>'delivered_outid','label'=>'OutId 精确匹配：送达，模拟扣费一次'],
                ['key'=>'delivered_bizid','label'=>'BizId 查询：回执无 OutId 仍可送达，模拟扣费一次'],
                ['key'=>'delivery_failed','label'=>'运营商失败回执：模拟释放预占一次'],
                ['key'=>'missing_until_timeout','label'=>'始终无匹配回执：30/120/600/1800/3600 秒轮询，最终 unknown + 释放'],
            ],
        ];
        return$result;
    }

    public function run(string $instancePublicId,string $actionKey,array $input,int $adminId,string $requestKey): array
    {
        if($actionKey!==self::ACTION)return$this->inner->run($instancePublicId,$actionKey,$input,$adminId,$requestKey);

        $scenario=trim((string)($input['receipt_scenario']??''));
        if(!in_array($scenario,self::SCENARIOS,true)){
            throw new TestCenterException('付费短信回执模拟参数无效，请刷新页面后重试。','TEST_PAID_SMS_RECEIPT_INPUT_INVALID',422);
        }

        return$this->execution->run(
            $instancePublicId,
            self::ACTION,
            ['receipt_scenario'=>$scenario],
            $adminId,
            $requestKey,
            'system',
            'simulated_external',
            function(array $instance,array $order,array $command)use($requestKey):array{
                if(!$this->eligibleOrder($order)){
                    throw new TestCenterException('当前订单已不处于可测试附近师傅短信回执的待报价阶段。','TEST_PAID_SMS_RECEIPT_ORDER_CHANGED',409);
                }

                $orderId=(int)$order['id'];
                $orderNo=(string)$order['public_no'];
                $beforeOutbox=$this->realOutboxCount($orderId,$orderNo);
                $beforeInvites=$this->realInviteCount($orderId);

                $scenario=(string)$command['receipt_scenario'];
                $probeNo='TEST-PAID-SMS-'.(int)$instance['id'];
                $probeMobile='TEST_MOBILE_'.(int)$instance['id'];
                $expectedOutId='nearby-invite:'.$probeNo;
                $invite=[
                    'public_no'=>$probeNo,
                    'aliyun_biz_id'=>$scenario==='delivered_bizid'?'TEST_BIZ_PRESENT':'',
                ];

                if($scenario==='missing_until_timeout'){
                    $result=$this->simulateMissingUntilTimeout((int)$instance['id'],$requestKey,$orderId,$probeNo,$invite,$probeMobile);
                }else{
                    $sendStatus=$scenario==='delivery_failed'?2:3;
                    $detail=[
                        'out_id'=>$scenario==='delivered_bizid'?'':$expectedOutId,
                        'phone_num'=>$probeMobile,
                        'send_status'=>$sendStatus,
                        'err_code'=>$sendStatus===3?'DELIVERED':'OPERATOR_REJECTED',
                    ];
                    $result=$this->simulateTerminalReceipt((int)$instance['id'],$requestKey,$orderId,$probeNo,$scenario,$invite,$probeMobile,$detail);
                }

                $afterOutbox=$this->realOutboxCount($orderId,$orderNo);
                $afterInvites=$this->realInviteCount($orderId);
                if($afterOutbox!==$beforeOutbox||$afterInvites!==$beforeInvites){
                    throw new TestCenterException(
                        '付费短信回执模拟期间检测到真实短信 outbox/invite 发生变化，已拒绝把本次结果标记为安全通过。',
                        'TEST_PAID_SMS_RECEIPT_REAL_SIDE_EFFECT',
                        500,
                        [
                            'outbox_before'=>$beforeOutbox,'outbox_after'=>$afterOutbox,
                            'invite_before'=>$beforeInvites,'invite_after'=>$afterInvites,
                        ]
                    );
                }

                $result['receipt_scenario']=$scenario;
                $result['real_message_outbox_before']=$beforeOutbox;
                $result['real_message_outbox_after']=$afterOutbox;
                $result['real_paid_sms_invites_before']=$beforeInvites;
                $result['real_paid_sms_invites_after']=$afterInvites;
                $result['wallet_mutated']=false;
                $result['provider_query_executed']=false;
                $result['external_delivery_executed']=false;
                return$result;
            }
        );
    }

    private function simulateTerminalReceipt(
        int $instanceId,
        string $requestKey,
        int $orderId,
        string $probeNo,
        string $scenario,
        array $invite,
        string $mobile,
        array $detail
    ): array {
        $matched=PaidSmsPolicy::receipt($invite,$mobile,[$detail]);
        if(!$matched){
            throw new TestCenterException('正式付费短信回执匹配策略没有命中本次模拟回执。','TEST_PAID_SMS_RECEIPT_POLICY_DRIFT',500,['scenario'=>$scenario]);
        }
        $decision=PaidSmsReceiptPolicy::classify($matched);
        $expectedState=$scenario==='delivery_failed'?'delivery_failed':'delivered';
        if((string)$decision['state']!==$expectedState){
            throw new TestCenterException('正式付费短信回执分类与预期不一致。','TEST_PAID_SMS_RECEIPT_POLICY_DRIFT',500,['scenario'=>$scenario,'decision'=>$decision]);
        }

        $payload=[
            'paid_sms_receipt_probe'=>true,
            'receipt_scenario'=>$scenario,
            'match_mode'=>$scenario==='delivered_bizid'?'biz_id_query_without_out_id':'exact_out_id',
            'provider_status'=>(int)$decision['provider_status'],
            'delivery_status'=>(string)$decision['delivery_status'],
            'billing_status'=>(string)$decision['billing_status'],
            'wallet_effect'=>!empty($decision['should_charge'])?'simulated_charge':'simulated_release',
            'duplicate_receipt'=>false,
            'provider_query_executed'=>false,
            'external_delivery_executed'=>false,
        ];

        $this->db->transaction(function(Database $db)use($instanceId,$requestKey,$orderId,$probeNo,$scenario,$decision,$payload):void{
            $reason=!empty($decision['should_charge'])?'paid_sms_receipt_delivered':'paid_sms_receipt_failed';
            $status=!empty($decision['should_charge'])?'simulated_delivered':'simulated_failed';
            $this->insertProjection($db,$instanceId,$requestKey,$orderId,$probeNo,$scenario,'terminal',$status,$reason,$payload);
            // 同一 SendDetails 回执重复出现时只能留下同一条终态测试事实。
            $this->insertProjection($db,$instanceId,$requestKey,$orderId,$probeNo,$scenario,'terminal',$status,$reason,array_merge($payload,['duplicate_receipt'=>true]));
        });

        $facts=$this->probeCount($instanceId,$requestKey);
        if($facts!==1){
            throw new TestCenterException('重复 SendDetails 回执未保持幂等。','TEST_PAID_SMS_RECEIPT_DUPLICATE',500,['facts'=>$facts]);
        }

        return[
            'match_mode'=>$scenario==='delivered_bizid'?'biz_id_query_without_out_id':'exact_out_id',
            'matched'=>true,
            'decision'=>$decision,
            'simulated_wallet_effect'=>!empty($decision['should_charge'])?'charge_once':'release_once',
            'duplicate_receipt_deduplicated'=>true,
            'test_fact_count'=>$facts,
        ];
    }

    private function simulateMissingUntilTimeout(
        int $instanceId,
        string $requestKey,
        int $orderId,
        string $probeNo,
        array $invite,
        string $mobile
    ): array {
        $wrong=[
            'out_id'=>'nearby-invite:OTHER-ORDER',
            'phone_num'=>$mobile,
            'send_status'=>3,
            'err_code'=>'DELIVERED',
        ];
        $matched=PaidSmsPolicy::receipt($invite,$mobile,[$wrong]);
        if($matched!==null){
            throw new TestCenterException('不属于当前 OutId/BizId 的回执被错误匹配。','TEST_PAID_SMS_RECEIPT_POLICY_DRIFT',500);
        }
        $pending=PaidSmsReceiptPolicy::classify(null);
        if((string)$pending['state']!=='pending'||(string)$pending['billing_status']!=='held'){
            throw new TestCenterException('无匹配回执时正式策略没有保持 held。','TEST_PAID_SMS_RECEIPT_POLICY_DRIFT',500,['decision'=>$pending]);
        }

        $delays=[];
        $this->db->transaction(function(Database $db)use($instanceId,$requestKey,$orderId,$probeNo,&$delays):void{
            for($attempt=1;$attempt<=5;$attempt++){
                $seconds=PaidSmsReceiptPolicy::retryDelaySeconds($attempt);
                $delays[]=$seconds;
                $this->insertProjection(
                    $db,$instanceId,$requestKey,$orderId,$probeNo,'missing_until_timeout','attempt-'.$attempt,
                    'simulated_retry','paid_sms_receipt_not_found',
                    [
                        'paid_sms_receipt_probe'=>true,
                        'attempt'=>$attempt,
                        'retry_delay_seconds'=>$seconds,
                        'delivery_status'=>'provider_accepted',
                        'billing_status'=>'held',
                        'wallet_effect'=>'none',
                        'provider_query_executed'=>false,
                        'external_delivery_executed'=>false,
                    ]
                );
            }
            $timeout=PaidSmsReceiptPolicy::timeout();
            $this->insertProjection(
                $db,$instanceId,$requestKey,$orderId,$probeNo,'missing_until_timeout','timeout',
                'simulated_unknown','paid_sms_receipt_timeout',
                [
                    'paid_sms_receipt_probe'=>true,
                    'delivery_status'=>(string)$timeout['delivery_status'],
                    'billing_status'=>(string)$timeout['billing_status'],
                    'wallet_effect'=>'simulated_release',
                    'retain_active_dedupe_key'=>(bool)$timeout['retain_active_dedupe_key'],
                    'error_code'=>(string)$timeout['error_code'],
                    'provider_query_executed'=>false,
                    'external_delivery_executed'=>false,
                ]
            );
            // 超时终态重复处理也只能留下同一个 timeout 事实。
            $this->insertProjection(
                $db,$instanceId,$requestKey,$orderId,$probeNo,'missing_until_timeout','timeout',
                'simulated_unknown','paid_sms_receipt_timeout',
                [
                    'paid_sms_receipt_probe'=>true,
                    'delivery_status'=>(string)$timeout['delivery_status'],
                    'billing_status'=>(string)$timeout['billing_status'],
                    'wallet_effect'=>'simulated_release',
                    'retain_active_dedupe_key'=>(bool)$timeout['retain_active_dedupe_key'],
                    'error_code'=>(string)$timeout['error_code'],
                    'provider_query_executed'=>false,
                    'external_delivery_executed'=>false,
                ]
            );
        });

        $facts=$this->probeCount($instanceId,$requestKey);
        if($delays!==[30,120,600,1800,3600]||$facts!==6){
            throw new TestCenterException('回执轮询退避或超时终态与正式策略不一致。','TEST_PAID_SMS_RECEIPT_RETRY_DRIFT',500,['delays'=>$delays,'facts'=>$facts]);
        }
        $timeout=PaidSmsReceiptPolicy::timeout();
        return[
            'matched'=>false,
            'retry_delay_seconds'=>$delays,
            'timeout_decision'=>$timeout,
            'simulated_wallet_effect'=>'release_once_at_timeout',
            'duplicate_timeout_deduplicated'=>true,
            'test_fact_count'=>$facts,
        ];
    }

    private function eligibleOrder(array $order): bool
    {
        return(int)($order['is_demo']??0)===1
            &&(string)($order['lifecycle_status']??'')==='active'
            &&in_array((string)($order['quote_status']??''),['waiting','open','quoting'],true)
            &&empty($order['selected_quote_id'])
            &&empty($order['assigned_worker_id']);
    }

    private function insertProjection(
        Database $db,
        int $instanceId,
        string $requestKey,
        int $orderId,
        string $probeNo,
        string $scenario,
        string $step,
        string $status,
        string $reason,
        array $payload
    ): void {
        $identity='paid-sms-receipt-probe:'.$instanceId.':'.$requestKey.':'.$scenario.':'.$step;
        $businessKey=strlen($identity)<=190?$identity:substr($identity,0,125).':'.hash('sha256',$identity);
        $eventKey='paid-sms-receipt:'.$instanceId.':'.$scenario;
        $db->execute(
            "INSERT IGNORE INTO test_message_outbox(
                instance_id,order_id,business_key,event_key,event_type,channel,recipient_type,recipient_id,scene_key,
                projection_status,reason_code,payload_json,created_at
             ) VALUES(
                :instance,:order_id,:business_key,:event_key,'paid_sms_receipt_probe','aliyun_paid_sms','worker_candidate',NULL,
                'nearby_worker_invite',:status,:reason,:payload,UTC_TIMESTAMP()
             )",
            [
                'instance'=>$instanceId,
                'order_id'=>$orderId,
                'business_key'=>$businessKey,
                'event_key'=>substr($eventKey,0,160),
                'status'=>$status,
                'reason'=>$reason,
                'payload'=>json_encode(array_merge($payload,['probe_public_no'=>$probeNo]),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ]
        );
    }

    private function probeCount(int $instanceId,string $requestKey): int
    {
        return(int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM test_message_outbox
             WHERE instance_id=:instance
               AND channel='aliyun_paid_sms'
               AND business_key LIKE :prefix",
            ['instance'=>$instanceId,'prefix'=>'%:'.$requestKey.':%']
        );
    }

    private function realOutboxCount(int $orderId,string $orderNo): int
    {
        if(!$this->db->tableExists('message_outbox'))return 0;
        return(int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM message_outbox
             WHERE topic='aliyun_paid_sms'
               AND (payload_json LIKE :id_needle OR payload_json LIKE :no_needle OR business_key LIKE :business_needle)",
            [
                'id_needle'=>'%"order_id":'.$orderId.'%',
                'no_needle'=>'%'.$orderNo.'%',
                'business_needle'=>'%'.$orderNo.'%',
            ]
        );
    }

    private function realInviteCount(int $orderId): int
    {
        if(!$this->db->tableExists('order_worker_sms_invites'))return 0;
        return(int)$this->db->fetchValue('SELECT COUNT(*) FROM order_worker_sms_invites WHERE order_id=:id',['id'=>$orderId]);
    }
}
