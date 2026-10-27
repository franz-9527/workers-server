<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Scheduling;

use RuntimeException;
use Throwable;
use WorkerAdmin\Core\Database;

/**
 * 测量冻结余额释放的独立扫描任务。
 *
 * 候选查询在 LIMIT 之前排除争议/退款/取消/加价/售后阻断项，避免最早一批长期阻断订单
 * 占满窗口后让后续已经到期且可释放的订单永久饥饿；事务内仍再次校验全部阻断条件。
 */
final class MeasurementFrozenBalanceReleaseTask
{
    private const JOB = 'measurement_release';

    private Database $db;
    private int $limit;

    public function __construct(Database $db, int $limit = 100)
    {
        $this->db = $db;
        $this->limit = max(1, min(500, $limit));
    }

    public function run(): array
    {
        $runUuid = $this->uuidV4();
        $runId = $this->db->insert(
            "INSERT INTO scheduler_runs(run_uuid,job_name,status,host_name,process_id,scanned_count,success_count,failure_count,started_at)
             VALUES(:uuid,:job,'running',:host,:pid,0,0,0,UTC_TIMESTAMP())",
            [
                'uuid'=>$runUuid,
                'job'=>self::JOB,
                'host'=>substr((string)gethostname(),0,128),
                'pid'=>function_exists('getmypid') ? (int)getmypid() : null,
            ]
        );
        $startedAt = microtime(true);
        $stats = ['scanned'=>0,'success'=>0,'failure'=>0];
        $error = null;

        try {
            $rows = $this->db->fetchAll(
                "SELECT o.id,o.version
                 FROM orders o
                 JOIN order_measurement_states ms ON ms.order_id=o.id
                 WHERE o.workflow_profile='measurement'
                   AND COALESCE(o.is_demo,0)=0
                   AND ms.settlement_status='frozen'
                   AND ms.hold_until IS NOT NULL
                   AND ms.hold_until<=UTC_TIMESTAMP()
                   AND COALESCE(o.dispute_status,'none') IN ('none','resolved')
                   AND COALESCE(o.refund_status,'none') IN ('none','failed')
                   AND NOT EXISTS(SELECT 1 FROM disputes d WHERE d.order_id=o.id AND d.status IN ('open','pending','processing'))
                   AND NOT EXISTS(SELECT 1 FROM refunds r WHERE r.order_id=o.id AND r.status IN ('created','requested','pending_review','approved','processing'))
                   AND NOT EXISTS(SELECT 1 FROM order_cancel_requests c WHERE c.order_id=o.id AND c.status IN ('pending','processing'))
                   AND NOT EXISTS(SELECT 1 FROM change_requests cr WHERE cr.order_id=o.id AND cr.status IN ('pending','processing','negotiating'))
                   AND NOT EXISTS(SELECT 1 FROM after_sales a WHERE a.order_id=o.id AND a.status IN ('open','processing'))
                 ORDER BY ms.hold_until,o.id
                 LIMIT {$this->limit}"
            );
            $stats['scanned'] = count($rows);
            foreach ($rows as $row) {
                try {
                    if ($this->releaseOne((int)$row['id'], (int)$row['version'])) $stats['success']++;
                } catch (Throwable $exception) {
                    $stats['failure']++;
                    $this->recordFailure($runId,(int)$row['id'],max(1,(int)$row['version']),$exception);
                }
            }
        } catch (Throwable $exception) {
            $stats['failure']++;
            $error = mb_substr($exception->getMessage(),0,4000);
            $this->recordFailure($runId,0,1,$exception,'task:'.self::JOB);
        }

        if ($error === null && $stats['failure'] > 0) $error = '本批次有 '.$stats['failure'].' 笔测量冻结余额释放失败，请查看异常工作台。';
        $status = $stats['failure'] > 0 ? 'failed' : 'success';
        $this->db->execute(
            "UPDATE scheduler_runs SET status=:status,scanned_count=:scanned,success_count=:success,failure_count=:failure,finished_at=UTC_TIMESTAMP(),error_message=:error WHERE id=:id",
            ['status'=>$status,'scanned'=>$stats['scanned'],'success'=>$stats['success'],'failure'=>$stats['failure'],'error'=>$error,'id'=>$runId]
        );
        if ($status === 'success') {
            $this->db->execute(
                "UPDATE scheduled_job_failures SET resolved_at=UTC_TIMESTAMP()
                 WHERE job_name=:job AND business_key=:key AND resolved_at IS NULL",
                ['job'=>self::JOB,'key'=>'task:'.self::JOB]
            );
        }
        return [
            'run_id'=>$runId,'run_uuid'=>$runUuid,'task'=>self::JOB,'status'=>$status,
            'scanned'=>$stats['scanned'],'success'=>$stats['success'],'failure'=>$stats['failure'],
            'duration_ms'=>(int)round((microtime(true)-$startedAt)*1000),'error'=>$error,
        ];
    }

    /** 只执行一个已登记的 testing 实例；时钟夹具不修改数据库到期时间。 */
    public function releaseTestInstance(int $instanceId, bool $advanceClock = false): array
    {
        if (!\WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy::active()) throw new RuntimeException('测量释放测试只允许 testing 环境');
        $i=$this->db->fetch("SELECT ti.*,o.version order_version,o.is_demo,o.public_no,ms.hold_until FROM test_instances ti JOIN orders o ON o.id=ti.order_id JOIN order_measurement_states ms ON ms.order_id=o.id WHERE ti.id=:id",['id'=>$instanceId]);
        if (!$i || $i['scenario_key']!=='ordinary.measurement.multi_quote.waiting.zero_quotes' || $i['build_status']!=='built' || !empty($i['archived_at']) || !empty($i['deleted_at']) || (int)$i['is_demo']!==1 || $i['public_no']!==$i['order_no_snapshot'] || strpos($i['public_no'],'BXDEMOHALL')===0 || empty($i['hold_until'])) throw new RuntimeException('测量释放测试实例边界失败');
        $asOf=$advanceClock ? max(time(),(int)strtotime($i['hold_until'].' UTC')+1) : time();
        return ['released'=>$this->releaseOne((int)$i['order_id'],(int)$i['order_version'],$instanceId,$asOf),'clock_fixture'=>$advanceClock,'as_of'=>gmdate('Y-m-d H:i:s',$asOf),'hold_until'=>$i['hold_until'],'global_scan_verified'=>false];
    }

    private function releaseOne(int $orderId, int $candidateVersion, int $testInstanceId = 0, ?int $asOf = null): bool
    {
        return $this->db->transaction(function(Database $db) use($orderId,$candidateVersion,$testInstanceId,$asOf): bool {
            $domain=$testInstanceId>0 ? "is_demo=1 AND EXISTS(SELECT 1 FROM test_instances ti WHERE ti.id=".(int)$testInstanceId." AND ti.order_id=orders.id AND BINARY ti.order_no_snapshot=BINARY orders.public_no AND ti.build_status='built' AND ti.archived_at IS NULL AND ti.deleted_at IS NULL)" : "COALESCE(is_demo,0)=0";
            if($testInstanceId>0&&!\WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy::active())throw new RuntimeException('禁止跨测试域释放');
            $order = $db->fetch(
                "SELECT * FROM orders WHERE id=:id AND workflow_profile='measurement' AND {$domain} FOR UPDATE",
                ['id'=>$orderId]
            );
            if (!$order || (int)$order['version'] !== $candidateVersion) return false;
            if($testInstanceId>0&&($order['lifecycle_status']!=='completed'||$order['fulfillment_status']!=='accepted'||strpos($order['public_no'],'BXDEMOHALL')===0))throw new RuntimeException('测量释放目标尚未完成');
            $state = $db->fetch('SELECT * FROM order_measurement_states WHERE order_id=:id FOR UPDATE',['id'=>$orderId]);
            if (!$state || (string)$state['settlement_status'] !== 'frozen' || empty($state['hold_until'])) return false;
            if (strtotime((string)$state['hold_until'].' UTC') > ($asOf ?? time()) || $this->hasBlockingBusiness($db,$order)) return false;

            $workerId = (int)($order['assigned_worker_id'] ?? 0);
            $assignment = $db->fetch(
                "SELECT * FROM assignments WHERE order_id=:order_id AND worker_id=:worker_id
                 AND status IN ('accepted','completed') ORDER BY id DESC LIMIT 1 FOR UPDATE",
                ['order_id'=>$orderId,'worker_id'=>$workerId]
            );
            if (!$assignment) throw new RuntimeException('测量订单缺少可释放资金的成交师傅');

            $net = max(0,(int)$state['worker_net_cents']);
            if ($net > 0) {
                $db->execute(
                    "INSERT IGNORE INTO worker_balance_ledger(worker_id,entry_type,direction,amount_cents,available_delta_cents,frozen_delta_cents,business_type,business_id,idempotency_key,description,status,created_at)
                     VALUES(:worker_id,'measurement_income_release','transfer',:amount,:available,:frozen,'order_measurement',:business_id,:key,'测量冻结期到期，净收入转入可用余额','effective',UTC_TIMESTAMP())",
                    ['worker_id'=>$assignment['worker_id'],'amount'=>$net,'available'=>$net,'frozen'=>-$net,'business_id'=>$orderId,'key'=>'order-measurement:'.$orderId.':released']
                );
            }
            $changed = $db->execute(
                "UPDATE order_measurement_states SET settlement_status='released',settled_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
                 WHERE order_id=:order_id AND settlement_status='frozen'",
                ['order_id'=>$orderId]
            );
            if ($changed !== 1) return false;
            $db->execute(
                "UPDATE order_measurement_hold_extensions
                 SET status='expired',review_note=COALESCE(review_note,'冻结期已到期，未审批申请自动失效'),reviewed_at=COALESCE(reviewed_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP()
                 WHERE order_id=:order_id AND status='pending'",
                ['order_id'=>$orderId]
            );
            $eventKey = 'scheduler:measurement_release:order:'.$orderId.':v'.$candidateVersion;
            $payload = json_encode([
                'worker_net_cents'=>$net,'test_instance_id'=>$testInstanceId ?: null,'clock_fixture'=>$testInstanceId>0 && $asOf!==null,'as_of'=>$asOf!==null?gmdate('Y-m-d H:i:s',$asOf):null,
                'message'=>'测量冻结期已到期，师傅净收入已转入可用余额；已建立的施工关联保持不变。',
            ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $db->execute(
                "INSERT IGNORE INTO order_events(order_id,event_type,event_key,actor_type,actor_id,payload_json,created_at)
                 VALUES(:order_id,'measurement_income_released',:event_key,'system',NULL,:payload,UTC_TIMESTAMP())",
                ['order_id'=>$orderId,'event_key'=>substr($eventKey,0,128),'payload'=>is_string($payload)?$payload:'{}']
            );
            $db->execute(
                "UPDATE scheduled_job_failures SET resolved_at=UTC_TIMESTAMP()
                 WHERE job_name=:job AND business_key=:key AND resolved_at IS NULL",
                ['job'=>self::JOB,'key'=>'order:'.$orderId]
            );
            return true;
        });
    }

    private function hasBlockingBusiness(Database $db, array $order): bool
    {
        if (!in_array((string)($order['dispute_status'] ?? 'none'),['none','resolved'],true)) return true;
        if (!in_array((string)($order['refund_status'] ?? 'none'),['none','failed'],true)) return true;
        return (int)$db->fetchValue(
            "SELECT (
                EXISTS(SELECT 1 FROM disputes WHERE order_id=:d AND status IN ('open','pending','processing'))
                OR EXISTS(SELECT 1 FROM refunds WHERE order_id=:r AND status IN ('created','requested','pending_review','approved','processing'))
                OR EXISTS(SELECT 1 FROM order_cancel_requests WHERE order_id=:c AND status IN ('pending','processing'))
                OR EXISTS(SELECT 1 FROM change_requests WHERE order_id=:cr AND status IN ('pending','processing','negotiating'))
                OR EXISTS(SELECT 1 FROM after_sales WHERE order_id=:a AND status IN ('open','processing'))
             ) AS blocked",
            ['d'=>$order['id'],'r'=>$order['id'],'c'=>$order['id'],'cr'=>$order['id'],'a'=>$order['id']]
        ) === 1;
    }

    private function recordFailure(int $runId, int $orderId, int $version, Throwable $exception, ?string $businessKey=null): void
    {
        try {
            $key = $businessKey ?? ('order:'.$orderId);
            $attempt = (int)$this->db->fetchValue(
                "SELECT COALESCE(MAX(attempt_no),0)+1 FROM scheduled_job_failures
                 WHERE job_name=:job AND business_key=:key AND business_version=:version",
                ['job'=>self::JOB,'key'=>$key,'version'=>$version]
            );
            $attempt = max(1,$attempt);
            $minutes = min(1440,max(1,1<<min(10,max(0,$attempt-1))));
            $this->db->execute(
                "INSERT INTO scheduled_job_failures(job_name,business_key,business_version,scheduler_run_id,attempt_no,error_class,error_message,next_retry_at,created_at)
                 VALUES(:job,:key,:version,:run_id,:attempt,:error_class,:error_message,DATE_ADD(UTC_TIMESTAMP(),INTERVAL {$minutes} MINUTE),UTC_TIMESTAMP())",
                [
                    'job'=>self::JOB,'key'=>substr($key,0,128),'version'=>$version,'run_id'=>$runId,'attempt'=>$attempt,
                    'error_class'=>substr(get_class($exception),0,160),'error_message'=>mb_substr($exception->getMessage(),0,2000),
                ]
            );
        } catch (Throwable $ignored) {}
    }

    private function uuidV4(): string
    {
        $data=random_bytes(16);
        $data[6]=chr((ord($data[6])&0x0f)|0x40);
        $data[8]=chr((ord($data[8])&0x3f)|0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($data),4));
    }
}
