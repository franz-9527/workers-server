<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use RuntimeException;
use Throwable;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/**
 * 平台承接单工费冻结期释放。只做钱包台账冻结 -> 可用转移；
 * 不修改订单验收/收入款状态，不创造支付记录，不生成普通平台服务费。
 * 候选查询和事务中双重校验到期及业务阻断，释放键保证重复调度只写一次。
 */
final class PlatformOrderFrozenBalanceReleaser
{
    /**
     * @param callable(int,int,Throwable):void $onFailure 由计划任务记录逐单失败与重试时间
     * @return array{scanned:int,success:int,failure:int}
     */
    public function run(Database $db,int $limit,callable $onFailure): array
    {
        $limit=max(1,min(500,$limit));
        $rows=$db->fetchAll(
            "SELECT o.id,o.version FROM orders o
             INNER JOIN order_platform_terms t ON t.order_id=o.id
             INNER JOIN worker_balance_ledger l
               ON l.business_type='platform_order' AND l.business_id=o.id
              AND l.entry_type='platform_order_income_frozen' AND l.status='effective'
              AND l.idempotency_key=CONCAT('platform-order:',o.id,':worker-income')
             WHERE o.order_source_type='platform' AND COALESCE(o.is_demo,0)=0
               AND o.lifecycle_status='completed' AND o.fulfillment_status='accepted'
               AND o.accepted_at IS NOT NULL
               AND o.dispute_status IN ('none','resolved') AND o.refund_status IN ('none','failed')
               AND t.worker_settlement_mode='platform_balance'
               AND t.settlement_status IN ('awaiting_platform_income','settled')
               AND t.worker_balance_freeze_days_snapshot>0 AND t.worker_balance_credit_cents>0
               AND o.accepted_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL t.worker_balance_freeze_days_snapshot DAY)
               AND NOT EXISTS(SELECT 1 FROM worker_balance_ledger r
                 WHERE r.idempotency_key=CONCAT('platform-order:',o.id,':worker-income-release'))
               AND NOT EXISTS(SELECT 1 FROM disputes d WHERE d.order_id=o.id AND d.status IN ('open','pending','processing'))
               AND NOT EXISTS(SELECT 1 FROM refunds rf WHERE rf.order_id=o.id
                 AND NOT (
                   rf.status IN ('closed','cancelled','rejected')
                   OR (rf.status='failed' AND COALESCE(rf.failure_code,'') IN ('CLOSED','CLOSED_REPLACED'))
                 ))
               AND NOT EXISTS(SELECT 1 FROM refunds br INNER JOIN payment_orders bp ON bp.id=br.payment_order_id
                 WHERE bp.order_id=o.id AND bp.business_type IN ('order_booking','booking_fee')
                   AND NOT (
                     br.status IN ('closed','cancelled','rejected')
                     OR (br.status='failed' AND COALESCE(br.failure_code,'') IN ('CLOSED','CLOSED_REPLACED'))
                   ))
               AND NOT EXISTS(SELECT 1 FROM order_cancel_requests cr WHERE cr.order_id=o.id AND cr.status IN ('pending','processing'))
               AND NOT EXISTS(SELECT 1 FROM change_requests ch WHERE ch.order_id=o.id AND ch.status IN ('pending','processing','negotiating'))
               AND NOT EXISTS(SELECT 1 FROM after_sales a WHERE a.order_id=o.id AND a.status IN ('open','processing'))
               AND NOT EXISTS(SELECT 1 FROM scheduled_job_failures f
                 WHERE f.job_name='platform_balance_release' AND f.business_key=CONCAT('order:',o.id)
                   AND f.business_version=o.version AND f.resolved_at IS NULL AND f.next_retry_at>UTC_TIMESTAMP())
             ORDER BY o.accepted_at,o.id LIMIT {$limit}"
        );
        $stats=['scanned'=>count($rows),'success'=>0,'failure'=>0];
        foreach($rows as$row){
            try{
                if($this->releaseOne($db,(int)$row['id']))$stats['success']++;
            }catch(Throwable $exception){
                $stats['failure']++;
                $onFailure((int)$row['id'],max(1,(int)$row['version']),$exception);
            }
        }
        return$stats;
    }

    /** 生产逐笔释放；只接受 non-demo 平台订单。 */
    public function releaseOne(Database $db,int $orderId): bool
    {
        return$this->releaseScoped($db,$orderId,0,null,false);
    }

    /**
     * 只执行一个 testing 实例；advanceClock 仅推进本次比较用 as_of，不修改 accepted_at 或系统时间。
     * @return array<string,mixed>
     */
    public function releaseTestInstance(Database $db,int $instanceId,bool $advanceClock=false): array
    {
        if(!TestingEnvironmentPolicy::active())throw new RuntimeException('平台余额释放测试只允许 testing 环境');
        if($instanceId<1)throw new RuntimeException('测试实例无效');

        $row=$db->fetch(
            "SELECT ti.id instance_id,ti.scenario_key,ti.order_id,ti.order_no_snapshot,ti.build_status,ti.archived_at,ti.deleted_at,
                    o.public_no,o.is_demo,o.order_source_type,o.lifecycle_status,o.fulfillment_status,o.accepted_at,
                    t.worker_settlement_mode,t.worker_balance_freeze_days_snapshot,t.worker_balance_credit_cents
             FROM test_instances ti
             JOIN orders o ON o.id=ti.order_id
             JOIN order_platform_terms t ON t.order_id=o.id
             WHERE ti.id=:id LIMIT 1",
            ['id'=>$instanceId]
        );
        if(!$row
            ||(string)$row['build_status']!=='built'
            ||!empty($row['archived_at'])
            ||!empty($row['deleted_at'])
            ||(int)$row['is_demo']!==1
            ||(string)$row['order_source_type']!=='platform'
            ||(string)$row['public_no']!==(string)$row['order_no_snapshot']
            ||strpos((string)$row['public_no'],'BXDEMOHALL')===0
            ||(string)$row['lifecycle_status']!=='completed'
            ||(string)$row['fulfillment_status']!=='accepted'
            ||empty($row['accepted_at'])
            ||(string)$row['worker_settlement_mode']!=='platform_balance'
            ||(int)$row['worker_balance_freeze_days_snapshot']<1
            ||(int)$row['worker_balance_credit_cents']<1){
            throw new RuntimeException('平台余额释放测试实例边界失败');
        }

        $acceptedTs=strtotime((string)$row['accepted_at'].' UTC');
        if($acceptedTs===false)throw new RuntimeException('平台订单验收时间无效');
        $dueTs=$acceptedTs+(int)$row['worker_balance_freeze_days_snapshot']*86400;
        $asOf=$advanceClock?max(time(),$dueTs+1):time();
        $released=$this->releaseScoped($db,(int)$row['order_id'],$instanceId,$asOf,true);

        return[
            'released'=>$released,
            'scope'=>'test_instance',
            'test_instance_id'=>$instanceId,
            'order_id'=>(int)$row['order_id'],
            'clock_fixture'=>$advanceClock,
            'as_of'=>gmdate('Y-m-d H:i:s',$asOf),
            'due_at'=>gmdate('Y-m-d H:i:s',$dueTs),
            'freeze_days'=>(int)$row['worker_balance_freeze_days_snapshot'],
            'worker_balance_credit_cents'=>(int)$row['worker_balance_credit_cents'],
            'global_scheduler_scan_verified'=>false,
        ];
    }

    private function releaseScoped(
        Database $db,
        int $orderId,
        int $testInstanceId,
        ?int $asOf,
        bool $clockFixture
    ): bool {
        if($orderId<1)return false;
        if($testInstanceId>0&&!TestingEnvironmentPolicy::active())throw new RuntimeException('禁止跨测试域释放平台冻结余额');

        return$db->transaction(function(Database $db)use($orderId,$testInstanceId,$asOf,$clockFixture):bool{
            if($testInstanceId>0){
                $instance=$db->fetch(
                    "SELECT * FROM test_instances WHERE id=:id FOR UPDATE",
                    ['id'=>$testInstanceId]
                );
                if(!$instance
                    ||(int)($instance['order_id']??0)!==$orderId
                    ||(string)($instance['build_status']??'')!=='built'
                    ||!empty($instance['archived_at'])
                    ||!empty($instance['deleted_at'])){
                    throw new RuntimeException('平台余额释放测试实例已变化');
                }
            }

            $order=$db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE',['id'=>$orderId]);
            $demoExpected=$testInstanceId>0?1:0;
            if(!$order
                ||(string)($order['order_source_type']??'')!=='platform'
                ||(int)($order['is_demo']??0)!==$demoExpected
                ||(string)($order['lifecycle_status']??'')!=='completed'
                ||(string)($order['fulfillment_status']??'')!=='accepted'
                ||empty($order['accepted_at'])
                ||!in_array((string)($order['dispute_status']??''),['none','resolved'],true)
                ||!in_array((string)($order['refund_status']??''),['none','failed'],true)){
                return false;
            }
            if($testInstanceId>0){
                $instanceNo=(string)$db->fetchValue('SELECT order_no_snapshot FROM test_instances WHERE id=:id',['id'=>$testInstanceId]);
                if($instanceNo===''||$instanceNo!==(string)$order['public_no']||strpos((string)$order['public_no'],'BXDEMOHALL')===0){
                    throw new RuntimeException('平台余额释放测试订单归属失败');
                }
            }

            $terms=$db->fetch('SELECT * FROM order_platform_terms WHERE order_id=:id FOR UPDATE',['id'=>$orderId]);
            if(!$terms
                ||(string)($terms['worker_settlement_mode']??'')!=='platform_balance'
                ||!in_array((string)($terms['settlement_status']??''),['awaiting_platform_income','settled'],true)
                ||(int)($terms['worker_balance_freeze_days_snapshot']??0)<1
                ||(int)($terms['worker_balance_credit_cents']??0)<1){
                return false;
            }

            $acceptedTs=strtotime((string)$order['accepted_at'].' UTC');
            if($acceptedTs===false)return false;
            $dueTs=$acceptedTs+(int)$terms['worker_balance_freeze_days_snapshot']*86400;
            if(($asOf??time())<$dueTs||$this->hasBlockingBusiness($db,$orderId))return false;

            $releaseKey='platform-order:'.$orderId.':worker-income-release';
            if($db->fetch('SELECT id FROM worker_balance_ledger WHERE idempotency_key=:key LIMIT 1 FOR UPDATE',['key'=>$releaseKey]))return false;

            $source=$db->fetch(
                "SELECT id,worker_id,amount_cents,available_delta_cents,frozen_delta_cents
                 FROM worker_balance_ledger
                 WHERE idempotency_key=:key AND business_type='platform_order'
                   AND business_id=:id AND entry_type='platform_order_income_frozen' AND status='effective'
                 LIMIT 1 FOR UPDATE",
                ['key'=>'platform-order:'.$orderId.':worker-income','id'=>$orderId]
            );
            $amount=(int)$terms['worker_balance_credit_cents'];
            if(!$source
                ||(int)$source['worker_id']!==(int)$order['assigned_worker_id']
                ||(int)$source['amount_cents']!==$amount
                ||(int)$source['available_delta_cents']!==0
                ||(int)$source['frozen_delta_cents']!==$amount){
                throw new RuntimeException('平台单冻结工费台账与验收快照不一致，已中止解冻，请人工核对');
            }

            $inserted=$db->execute(
                "INSERT IGNORE INTO worker_balance_ledger(worker_id,entry_type,direction,amount_cents,
                         available_delta_cents,frozen_delta_cents,business_type,business_id,idempotency_key,
                         description,status,created_at)
                 VALUES(:worker_id,'platform_order_income_release','transfer',:amount,:available,:frozen,
                        'platform_order',:order_id,:key,'平台承接单工费冻结期到期，转入可提现余额','effective',UTC_TIMESTAMP())",
                [
                    'worker_id'=>(int)$source['worker_id'],'amount'=>$amount,'available'=>$amount,
                    'frozen'=>-$amount,'order_id'=>$orderId,'key'=>$releaseKey,
                ]
            );
            if($inserted!==1)return false;

            $eventKey=$testInstanceId>0
                ?'test-platform-balance-release:'.$testInstanceId.':'.$orderId
                :$releaseKey;
            $payload=json_encode([
                'worker_id'=>(int)$source['worker_id'],
                'amount_cents'=>$amount,
                'freeze_days'=>(int)$terms['worker_balance_freeze_days_snapshot'],
                'test_instance_id'=>$testInstanceId>0?$testInstanceId:null,
                'clock_fixture'=>$testInstanceId>0&&$clockFixture,
                'as_of'=>$testInstanceId>0&&$asOf!==null?gmdate('Y-m-d H:i:s',$asOf):null,
                'message'=>'平台承接单工费冻结期结束，已转入师傅可用余额。',
            ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $db->execute(
                "INSERT INTO order_events(order_id,event_type,event_key,actor_type,actor_id,payload_json,created_at)
                 VALUES(:order_id,'platform_worker_income_released',:event_key,'system',NULL,:payload,UTC_TIMESTAMP())",
                [
                    'order_id'=>$orderId,
                    'event_key'=>substr($eventKey,0,128),
                    'payload'=>is_string($payload)?$payload:'{}',
                ]
            );

            if($testInstanceId<1){
                $db->execute(
                    "UPDATE scheduled_job_failures SET resolved_at=UTC_TIMESTAMP()
                     WHERE job_name='platform_balance_release' AND business_key=:key AND resolved_at IS NULL",
                    ['key'=>'order:'.$orderId]
                );
            }
            return true;
        });
    }

    private function hasBlockingBusiness(Database $db,int $orderId): bool
    {
        return(int)$db->fetchValue(
            "SELECT (
               EXISTS(SELECT 1 FROM disputes WHERE order_id=:id AND status IN ('open','pending','processing')) OR
               EXISTS(SELECT 1 FROM refunds WHERE order_id=:id
                 AND NOT (
                   status IN ('closed','cancelled','rejected')
                   OR (status='failed' AND COALESCE(failure_code,'') IN ('CLOSED','CLOSED_REPLACED'))
                 )) OR
               EXISTS(SELECT 1 FROM refunds br INNER JOIN payment_orders bp ON bp.id=br.payment_order_id
                 WHERE bp.order_id=:id AND bp.business_type IN ('order_booking','booking_fee')
                   AND NOT (
                     br.status IN ('closed','cancelled','rejected')
                     OR (br.status='failed' AND COALESCE(br.failure_code,'') IN ('CLOSED','CLOSED_REPLACED'))
                   )) OR
               EXISTS(SELECT 1 FROM order_cancel_requests WHERE order_id=:id AND status IN ('pending','processing')) OR
               EXISTS(SELECT 1 FROM change_requests WHERE order_id=:id AND status IN ('pending','processing','negotiating')) OR
               EXISTS(SELECT 1 FROM after_sales WHERE order_id=:id AND status IN ('open','processing'))
             ) AS blocked",
            ['id'=>$orderId]
        )===1;
    }
}
