<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Scheduling;

use RuntimeException;
use Throwable;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Persistence\OrderFormMediaRules;
use WorkerAdmin\Infrastructure\Persistence\PlatformOrderBookingPaymentVerifier;

/** V2.5.0 平台承接单自动发布。 */
final class PlatformOrderAutoPublishTask
{
    private const JOB = 'platform_auto_publish';

    private Database $db;
    private int $limit;
    private PlatformOrderBookingPaymentVerifier $prepaymentVerifier;

    public function __construct(Database $db, int $limit = 100)
    {
        $this->db = $db;
        $this->limit = max(1, min(500, $limit));
        $this->prepaymentVerifier = new PlatformOrderBookingPaymentVerifier();
    }

    public function run(): array
    {
        $runUuid = $this->uuidV4();
        $runId = $this->db->insert(
            "INSERT INTO scheduler_runs(run_uuid,job_name,status,host_name,process_id,scanned_count,success_count,failure_count,started_at)
             VALUES(:uuid,:job,'running',:host,:pid,0,0,0,UTC_TIMESTAMP())",
            ['uuid'=>$runUuid,'job'=>self::JOB,'host'=>substr((string)gethostname(),0,128),'pid'=>function_exists('getmypid')?(int)getmypid():null]
        );
        $startedAt = microtime(true);
        $stats = ['scanned'=>0,'success'=>0,'failure'=>0];
        $error = null;

        try {
            $cursorUpdatedAt = null;
            $cursorId = 0;
            do {
                $cursorSql = '';
                $params = [];
                if ($cursorUpdatedAt !== null) {
                    $cursorSql = ' AND (o.updated_at>:cursor_updated OR (o.updated_at=:cursor_updated_eq AND o.id>:cursor_id))';
                    $params = ['cursor_updated'=>$cursorUpdatedAt,'cursor_updated_eq'=>$cursorUpdatedAt,'cursor_id'=>$cursorId];
                }
                $rows = $this->db->fetchAll(
                    "SELECT o.id,o.version,o.updated_at
                     FROM orders o
                     INNER JOIN order_platform_terms t ON t.order_id=o.id
                     WHERE o.order_source_type='platform'
                       AND t.customer_flow_mode='platform_managed'
                       AND t.settlement_status='pending'
                       AND t.customer_contract_total_cents>0
                       AND t.booking_paid_cents<=t.customer_contract_total_cents
                       AND o.booking_fee_cents=0
                       AND COALESCE(o.is_demo,0)=0
                       AND o.lifecycle_status='active'
                       AND o.publish_status<>'published'
                       AND o.audit_status IN ('pending','approved')
                       AND COALESCE(o.refund_status,'none') IN ('','none')
                       AND o.selected_quote_id IS NULL
                       AND o.assigned_worker_id IS NULL
                       AND o.current_assignment_id IS NULL
                       AND o.quote_status IN ('waiting','quoting','collecting')
                       AND NOT EXISTS(
                           SELECT 1 FROM payment_orders p
                           WHERE p.order_id=o.id AND p.business_type IN ('order_booking','booking_fee') AND p.status='paid'
                       )
                       AND NOT EXISTS(
                           SELECT 1 FROM scheduled_job_failures f
                           WHERE f.job_name='platform_auto_publish'
                             AND f.business_key=CONCAT('order:',o.id)
                             AND f.business_version=o.version
                             AND f.resolved_at IS NULL
                             AND f.next_retry_at>UTC_TIMESTAMP()
                       )
                       {$cursorSql}
                     ORDER BY o.updated_at,o.id
                     LIMIT {$this->limit}",
                    $params
                );
                if (!$rows) break;
                $stats['scanned'] += count($rows);
                foreach ($rows as $row) {
                    $orderId = (int)$row['id'];
                    $version = max(1,(int)$row['version']);
                    try {
                        if ($this->publishOne($orderId)) $stats['success']++;
                    } catch (Throwable $exception) {
                        $stats['failure']++;
                        $this->recordFailure($runId,$orderId,$version,$exception);
                    }
                }
                $last = $rows[count($rows)-1];
                $cursorUpdatedAt = (string)$last['updated_at'];
                $cursorId = (int)$last['id'];
            } while (count($rows) === $this->limit);
        } catch (Throwable $exception) {
            $stats['failure']++;
            $error = mb_substr($exception->getMessage(),0,4000);
            $this->recordFailure($runId,0,1,$exception,'task:'.self::JOB);
        }

        if ($error === null && $stats['failure'] > 0) $error = '本批次有 '.$stats['failure'].' 笔平台承接单自动发布失败，请查看异常工作台。';
        $status = $stats['failure'] > 0 ? 'failed' : 'success';
        $this->db->execute(
            "UPDATE scheduler_runs SET status=:status,scanned_count=:scanned,success_count=:success,failure_count=:failure,finished_at=UTC_TIMESTAMP(),error_message=:error WHERE id=:id",
            ['status'=>$status,'scanned'=>$stats['scanned'],'success'=>$stats['success'],'failure'=>$stats['failure'],'error'=>$error,'id'=>$runId]
        );
        if ($status === 'success') {
            $this->db->execute("UPDATE scheduled_job_failures SET resolved_at=UTC_TIMESTAMP() WHERE job_name=:job AND business_key=:key AND resolved_at IS NULL",['job'=>self::JOB,'key'=>'task:'.self::JOB]);
        }
        if ($stats['success'] === 0 && $stats['failure'] === 0) {
            $this->db->execute("DELETE FROM scheduler_runs WHERE job_name=:job AND id<>:id AND status='success' AND success_count=0 AND failure_count=0",['job'=>self::JOB,'id'=>$runId]);
        }

        return ['run_id'=>$runId,'run_uuid'=>$runUuid,'task'=>self::JOB,'status'=>$status,'scanned'=>$stats['scanned'],'success'=>$stats['success'],'failure'=>$stats['failure'],'duration_ms'=>(int)round((microtime(true)-$startedAt)*1000),'error'=>$error];
    }

    /**
     * 管理员恢复入口只重检这一张订单，复用自动发布的完整锁内校验，不运行整批 scheduler。
     * 已经发布视为幂等成功；当前业务条件不满足时只返回 not_ready，不伪造状态。
     * @return array{ok:bool,status:string,message:string}
     */
    public function retryOrder(int $orderId): array
    {
        $order = $this->db->fetch('SELECT id,publish_status FROM orders WHERE id=:id LIMIT 1', ['id'=>$orderId]);
        if (!$order) return ['ok'=>false,'status'=>'not_found','message'=>'订单不存在'];
        if ((string)$order['publish_status'] === 'published') {
            $this->db->execute(
                "UPDATE scheduled_job_failures SET resolved_at=COALESCE(resolved_at,UTC_TIMESTAMP())
                 WHERE job_name=:job AND business_key=:key AND resolved_at IS NULL",
                ['job'=>self::JOB,'key'=>'order:'.$orderId]
            );
            return ['ok'=>true,'status'=>'already_published','message'=>'订单已经发布，无需重复处理'];
        }
        try {
            $published = $this->publishOne($orderId);
        } catch (Throwable $exception) {
            return ['ok'=>false,'status'=>'failed','message'=>mb_substr($exception->getMessage(),0,500)];
        }
        if (!$published) return ['ok'=>false,'status'=>'not_ready','message'=>'订单当前条件仍不满足自动发布，请按最新状态处理'];
        return ['ok'=>true,'status'=>'published','message'=>'重新检查通过，订单已发布'];
    }

    private function publishOne(int $orderId): bool
    {
        return $this->db->transaction(function (Database $db) use ($orderId): bool {
            $order = $db->fetch(
                "SELECT o.*,t.customer_flow_mode,t.customer_contract_total_cents,t.booking_paid_cents,t.settlement_status
                 FROM orders o INNER JOIN order_platform_terms t ON t.order_id=o.id
                 WHERE o.id=:id AND o.order_source_type='platform' FOR UPDATE",
                ['id'=>$orderId]
            );
            if (!$order
                || (int)($order['is_demo']??0)!==0
                || (string)$order['customer_flow_mode']!=='platform_managed'
                || (string)$order['settlement_status']!=='pending'
                || (int)$order['booking_fee_cents']!==0
                || (int)$order['customer_contract_total_cents']<=0
                || (int)$order['booking_paid_cents']>(int)$order['customer_contract_total_cents']
                || (string)$order['lifecycle_status']!=='active'
                || (string)$order['publish_status']==='published'
                || !in_array((string)$order['audit_status'],['pending','approved'],true)
                || !in_array((string)($order['refund_status']??'none'),['','none'],true)
                || !empty($order['selected_quote_id'])
                || !empty($order['assigned_worker_id'])
                || !empty($order['current_assignment_id'])
                || !in_array((string)$order['quote_status'],['waiting','quoting','collecting'],true)
                || $this->hasBlockingBusiness($db,$orderId)) return false;
            if ($order['lat']===null || $order['lng']===null) throw new RuntimeException('平台承接单缺少地图坐标，系统已停止自动发布，请补充地址后处理');
            $mediaReadiness = OrderFormMediaRules::readiness($db,$orderId,(int)($order['form_schema_version_id']??0),(string)($order['workflow_profile']??'construction'));
            if (empty($mediaReadiness['ready'])) return false;

            $this->prepaymentVerifier->verifiedPaidCents($db,$orderId,(int)$order['customer_contract_total_cents'],(int)$order['booking_paid_cents'],'platform_managed');
            $quoteDays = max(1,min(30,(int)\setting('order.auto_cancel_days',7)));
            $version = max(1,(int)$order['version']);
            $changed = $db->execute(
                "UPDATE orders SET audit_status='approved',publish_status='published',quote_status='waiting',audited_at=COALESCE(audited_at,UTC_TIMESTAMP()),published_at=COALESCE(published_at,UTC_TIMESTAMP()),quote_deadline_at=COALESCE(quote_deadline_at,DATE_ADD(UTC_TIMESTAMP(),INTERVAL {$quoteDays} DAY)),audit_note='平台托管条件核验通过后系统自动发布',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id AND order_source_type='platform' AND booking_fee_cents=0 AND lifecycle_status='active' AND publish_status<>'published' AND selected_quote_id IS NULL AND assigned_worker_id IS NULL AND current_assignment_id IS NULL",
                ['id'=>$orderId]
            );
            if ($changed!==1) return false;

            $eventKey='scheduler:'.self::JOB.':order:'.$orderId.':v'.$version;
            $payload=json_encode(['quote_days'=>$quoteDays,'external_prepayment_cents'=>(int)$order['booking_paid_cents'],'message'=>'平台承接单托管条件与公共下单资料完整，系统自动审核并发布，无需管理员二次确认。'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $db->execute("INSERT IGNORE INTO order_events(order_id,event_type,event_key,actor_type,actor_id,payload_json,created_at) VALUES(:order_id,'platform_order_auto_published',:event_key,'system',NULL,:payload,UTC_TIMESTAMP())",['order_id'=>$orderId,'event_key'=>substr($eventKey,0,128),'payload'=>is_string($payload)?$payload:'{}']);
            $db->execute("UPDATE scheduled_job_failures SET resolved_at=UTC_TIMESTAMP() WHERE job_name=:job AND business_key=:key AND resolved_at IS NULL",['job'=>self::JOB,'key'=>'order:'.$orderId]);
            return true;
        });
    }

    private function hasBlockingBusiness(Database $db, int $orderId): bool
    {
        return (int)$db->fetchValue(
            "SELECT (EXISTS(SELECT 1 FROM disputes WHERE order_id=:id1 AND status IN ('open','pending','processing')) OR EXISTS(SELECT 1 FROM refunds WHERE order_id=:id2 AND status NOT IN ('failed','closed','cancelled','rejected')) OR EXISTS(SELECT 1 FROM order_cancel_requests WHERE order_id=:id3 AND status IN ('pending','processing')) OR EXISTS(SELECT 1 FROM change_requests WHERE order_id=:id4 AND status IN ('pending','processing','negotiating')) OR EXISTS(SELECT 1 FROM after_sales WHERE order_id=:id5 AND status IN ('open','processing'))) AS blocked",
            ['id1'=>$orderId,'id2'=>$orderId,'id3'=>$orderId,'id4'=>$orderId,'id5'=>$orderId]
        )===1;
    }

    private function recordFailure(int $runId, int $orderId, int $version, Throwable $exception, ?string $businessKey=null): void
    {
        try {
            $key=$businessKey??('order:'.$orderId);
            $attempt=(int)$this->db->fetchValue("SELECT COALESCE(MAX(attempt_no),0)+1 FROM scheduled_job_failures WHERE job_name=:job AND business_key=:key AND business_version=:version",['job'=>self::JOB,'key'=>$key,'version'=>max(1,$version)]);
            $attempt=max(1,$attempt);
            $minutes=min(1440,max(1,1<<min(10,max(0,$attempt-1))));
            $this->db->execute(
                "INSERT INTO scheduled_job_failures(job_name,business_key,business_version,scheduler_run_id,attempt_no,error_class,error_message,next_retry_at,created_at) VALUES(:job,:key,:version,:run_id,:attempt,:error_class,:error_message,DATE_ADD(UTC_TIMESTAMP(),INTERVAL {$minutes} MINUTE),UTC_TIMESTAMP())",
                ['job'=>self::JOB,'key'=>substr($key,0,128),'version'=>max(1,$version),'run_id'=>$runId,'attempt'=>$attempt,'error_class'=>substr(get_class($exception),0,160),'error_message'=>mb_substr($exception->getMessage(),0,2000)]
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
