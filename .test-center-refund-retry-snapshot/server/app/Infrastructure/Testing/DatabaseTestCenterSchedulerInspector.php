<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Contracts\TestCenterSchedulerInspector;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;
use WorkerAdmin\Infrastructure\Scheduling\ApprovedOrderRefundRetryProcessor;

/**
 * 测试实例计划任务诊断。
 *
 * 本类只读：展示当前订单有哪些时间型任务、期限是否已到，以及最近一次全局 scheduler 运行。
 * 已适配任务只暴露实例级 scoped 动作；未适配任务不把全库 scheduler 暴露成实例按钮，
 * 也不把 scheduler_runs 的全局 success_count 当作当前实例已经验证。
 */
final class DatabaseTestCenterSchedulerInspector implements TestCenterSchedulerInspector
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db=$db;
    }

    public function inspect(string $instancePublicId): array
    {
        if(!TestingEnvironmentPolicy::active()){
            return['items'=>[],'message'=>'计划任务实例诊断只在 testing 环境可用。','as_of_utc'=>gmdate('Y-m-d H:i:s')];
        }

        $instance=$this->db->fetch(
            'SELECT id,scenario_key,order_id,order_no_snapshot,build_status,archived_at,deleted_at
             FROM test_instances WHERE BINARY public_id=BINARY :id LIMIT 1',
            ['id'=>trim($instancePublicId)]
        );
        if(!$instance||empty($instance['order_id'])){
            return['items'=>[],'message'=>'当前实例没有可诊断的业务订单。','as_of_utc'=>gmdate('Y-m-d H:i:s')];
        }

        $order=$this->db->fetch(
            'SELECT id,public_no,is_demo,workflow_profile,order_source_type,lifecycle_status,publish_status,quote_status,
                    assignment_status,fulfillment_status,completion_submitted_at,auto_accept_at,accepted_at,auto_review_at,
                    assigned_worker_id,published_at,quote_deadline_at
             FROM orders WHERE id=:id LIMIT 1',
            ['id'=>(int)$instance['order_id']]
        );
        if(!$order||!$this->owned($instance,$order)){
            return['items'=>[],'message'=>'当前实例不属于可诊断的 testing 订单域。','as_of_utc'=>gmdate('Y-m-d H:i:s')];
        }

        $assignment=$this->db->fetch(
            "SELECT id,status,assignment_type,offered_at,accept_deadline_at
             FROM assignments
             WHERE order_id=:order_id AND status='offered'
             ORDER BY id DESC LIMIT 1",
            ['order_id'=>(int)$order['id']]
        );

        $measurement=null;
        if($this->db->tableExists('order_measurement_states')){
            $measurement=$this->db->fetch(
                'SELECT result_status,auto_accept_at,settlement_status,hold_until
                 FROM order_measurement_states WHERE order_id=:order_id LIMIT 1',
                ['order_id'=>(int)$order['id']]
            );
        }

        $items=[];
        $assignmentScoped=(bool)$assignment
            &&(string)($instance['scenario_key']??'')==='ordinary.construction.single_grab.waiting.negotiated'
            &&(string)($assignment['assignment_type']??'')==='single_grab';
        $items[]=$this->item(
            'assignment_timeout','派单/抢单占位超时',
            (bool)$assignment,
            $assignment?$this->deadline((string)($assignment['accept_deadline_at']??''),(string)($assignment['offered_at']??''),24,'hour'):null,
            $assignmentScoped?'scoped_test_action':'global_scan_only',
            $assignmentScoped?'system_assignment_timeout':'',
            $assignmentScoped
                ?'该非固定价抢单场景已接入实例级超时处理：使用接受截止时间后一秒的测试时钟，只锁定当前 assignment。'
                :'当前生产任务仍是全库扫描；这里只读取本实例 offered assignment 的到期事实。'
        );

        $autoAcceptApplicable=(string)($order['lifecycle_status']??'')==='active'
            &&(string)($order['fulfillment_status']??'')==='submitted'
            &&!empty($order['completion_submitted_at'])&&empty($order['accepted_at']);
        $autoAcceptAction='';
        if($autoAcceptApplicable){
            if((string)($order['order_source_type']??'consumer')==='platform')$autoAcceptAction='system_platform_auto_accept';
            elseif((string)($order['workflow_profile']??'construction')==='construction')$autoAcceptAction='system_auto_accept_completion';
        }
        $items[]=$this->item(
            'auto_accept','订单自动验收',
            $autoAcceptApplicable,
            $autoAcceptApplicable?$this->deadline((string)($order['auto_accept_at']??''),(string)($order['completion_submitted_at']??''),$this->settingInt('order.auto_accept_hours',72,1,720),'hour'):null,
            $autoAcceptAction!==''?'scoped_test_action':'global_scan_only',
            $autoAcceptAction,
            $autoAcceptAction!==''
                ?'该完工待验收实例已接入正式单订单自动验收处理器；使用 auto_accept_at 后一秒的测试时钟，只处理当前 test_instance。'
                :'读取订单 auto_accept_at；当前实例未接入受控执行时只展示期限，不触发全库扫描。'
        );

        $reviewExists=(bool)$this->db->fetchValue(
            'SELECT id FROM reviews WHERE order_id=:id LIMIT 1',
            ['id'=>(int)$order['id']]
        );
        $autoReviewApplicable=!empty($order['accepted_at'])
            &&!empty($order['assigned_worker_id'])
            &&(string)($order['lifecycle_status']??'')==='completed'
            &&(string)($order['fulfillment_status']??'')==='accepted'
            &&!$reviewExists;
        $items[]=$this->item(
            'auto_review','订单自动评价',
            $autoReviewApplicable,
            $autoReviewApplicable?$this->deadline((string)($order['auto_review_at']??''),(string)($order['accepted_at']??''),$this->settingInt('order.auto_review_days',5,1,30),'day'):null,
            $autoReviewApplicable?'scoped_test_action':'global_scan_only',
            $autoReviewApplicable?'system_auto_review':'',
            $autoReviewApplicable
                ?'该已验收且尚未评价实例已接入正式单订单自动评价处理器；使用 auto_review_at 后一秒的测试时钟，只处理当前 test_instance。'
                :'当前实例没有可执行的自动评价；已有评价时不再提供重复执行。'
        );

        $autoCancelApplicable=(string)($order['order_source_type']??'consumer')==='consumer'
            &&(string)($order['workflow_profile']??'construction')==='construction'
            &&(string)($order['lifecycle_status']??'')==='active'
            &&(string)($order['publish_status']??'')==='published'
            &&in_array((string)($order['quote_status']??''),['waiting','quoting'],true)
            &&(string)($order['assignment_status']??'')==='unassigned'
            &&!empty($order['published_at']);
        $autoCancelScoped=$autoCancelApplicable&&in_array(
            (string)($instance['scenario_key']??''),
            [
                'ordinary.construction.multi_quote.waiting.zero_quotes',
                'ordinary.construction.multi_quote.waiting.one_quote',
                'ordinary.construction.multi_quote.waiting.three_quotes',
                'ordinary.construction.multi_quote.waiting.far_distance',
                'ordinary.construction.multi_quote.waiting.pending_worker',
                'ordinary.construction.single_grab.waiting.open',
            ],
            true
        );
        $items[]=$this->item(
            'auto_cancel','无人成交自动取消',
            $autoCancelApplicable,
            $autoCancelApplicable?$this->deadline((string)($order['quote_deadline_at']??''),(string)($order['published_at']??''),7,'day'):null,
            $autoCancelScoped?'scoped_test_action':'global_scan_only',
            $autoCancelScoped?'system_auto_cancel':'',
            $autoCancelScoped
                ?'当前普通施工未成交实例已接入正式单订单自动取消处理器；使用 quote_deadline_at 后一秒测试时钟，只登记 TEST_INTERNAL 退款事实，不调用微信退款或全库预占释放。'
                :'当前实例只展示自动取消期限；全局 auto_cancel 的报价预占释放与真实退款桥仍不从测试中心触发。'
        );

        $refundRetry=(new ApprovedOrderRefundRetryProcessor($this->db))->inspectTestInstance((int)$instance['id']);
        $items[]=$this->item(
            'order_refund_retry','已批准退款自动重试',
            $refundRetry!==null,
            null,
            $refundRetry!==null?'scoped_test_action':'global_scan_only',
            $refundRetry!==null?'system_refund_retry_probe':'',
            $refundRetry!==null
                ?'当前实例存在唯一、已通过生产桥资格判定且归属 TEST_INTERNAL 的退款；探针只验证抢占和 provider 失败恢复，不调用微信、不写 processing / wechat_refund_id。'
                :'当前实例没有唯一可安全探测的已批准退款；生产 order_refund_retry 仍由全局桥扫描并提交真实微信。'
        );

        $measurementAcceptApplicable=is_array($measurement)
            &&(string)($measurement['result_status']??'')==='submitted'
            &&!empty($measurement['auto_accept_at']);
        $measurementAcceptScoped=$measurementAcceptApplicable
            &&(string)($instance['scenario_key']??'')==='ordinary.measurement.multi_quote.waiting.zero_quotes'
            &&(string)($order['order_source_type']??'consumer')==='consumer'
            &&(string)($order['workflow_profile']??'')==='measurement'
            &&(string)($order['lifecycle_status']??'')==='active'
            &&(string)($order['fulfillment_status']??'')==='submitted';
        $items[]=$this->item(
            'measurement_accept','测量成果自动确认',
            $measurementAcceptApplicable,
            $measurementAcceptApplicable?(string)$measurement['auto_accept_at']:null,
            $measurementAcceptScoped?'scoped_test_action':'global_scan_only',
            $measurementAcceptScoped?'system_measurement_accept':'',
            $measurementAcceptScoped
                ?'该普通测量实例已接入正式单订单自动确认处理器；使用成果 auto_accept_at 后一秒的测试时钟，只处理当前 test_instance。'
                :'当前实例只展示测量成果确认期限，不触发全库 measurement_accept。'
        );

        $measurementReleaseApplicable=is_array($measurement)
            &&(string)($measurement['settlement_status']??'')==='frozen'
            &&!empty($measurement['hold_until']);
        $scopedRelease=$measurementReleaseApplicable
            &&(string)($instance['scenario_key']??'')==='ordinary.measurement.multi_quote.waiting.zero_quotes';
        $items[]=$this->item(
            'measurement_release','测量费冻结解冻',
            $measurementReleaseApplicable,
            $measurementReleaseApplicable?(string)$measurement['hold_until']:null,
            $scopedRelease?'scoped_test_action':'global_scan_only',
            $scopedRelease?'release_measurement_income':'',
            $scopedRelease
                ?'该场景已有只处理当前 test_instance 的 releaseTestInstance；不会扫描其它测试/正式订单。'
                :'当前实例不满足既有 scoped 测量释放边界，只展示期限，不提供执行按钮。'
        );

        $items=array_values(array_filter($items,static function(array $item): bool {
            return !empty($item['applicable']);
        }));

        return[
            'items'=>$items,
            'as_of_utc'=>gmdate('Y-m-d H:i:s'),
            'message'=>$items
                ?'期限状态只代表当前实例；最近 scheduler 运行是全局诊断信息，不能据此判定当前实例已被处理。'
                :'当前实例没有识别到适用的时间型计划任务。',
            'global_execution_exposed'=>false,
        ];
    }

    private function item(string $task,string $label,bool $applicable,?string $dueAt,string $mode,string $actionKey,string $note): array
    {
        $dueAt=$this->normalizeDate($dueAt);
        $status='not_applicable';
        if($applicable){
            $timestamp=$dueAt!==null?strtotime($dueAt.' UTC'):false;
            $status=$timestamp===false?'unknown':($timestamp<=time()?'due':'pending');
        }
        return[
            'task'=>$task,
            'label'=>$label,
            'applicable'=>$applicable,
            'due_at'=>$dueAt,
            'deadline_status'=>$status,
            'mode'=>$mode,
            'action_key'=>$actionKey,
            'note'=>$note,
            'latest_global_run'=>$this->latestRun($task),
            'instance_verified'=>false,
        ];
    }

    private function latestRun(string $task): ?array
    {
        if(!$this->db->tableExists('scheduler_runs'))return null;
        $row=$this->db->fetch(
            'SELECT run_uuid,status,scanned_count,success_count,failure_count,started_at,finished_at,error_message
             FROM scheduler_runs WHERE job_name=:job ORDER BY id DESC LIMIT 1',
            ['job'=>$task]
        );
        if(!$row)return null;
        return[
            'run_uuid'=>(string)($row['run_uuid']??''),
            'status'=>(string)($row['status']??''),
            'scanned'=>(int)($row['scanned_count']??0),
            'success'=>(int)($row['success_count']??0),
            'failure'=>(int)($row['failure_count']??0),
            'started_at'=>(string)($row['started_at']??''),
            'finished_at'=>(string)($row['finished_at']??''),
            'error_message'=>(string)($row['error_message']??''),
            'scope'=>'global',
        ];
    }

    private function owned(array $instance,array $order): bool
    {
        $orderNo=(string)($order['public_no']??'');
        return(string)($instance['build_status']??'')==='built'
            &&empty($instance['archived_at'])
            &&empty($instance['deleted_at'])
            &&(int)($order['is_demo']??0)===1
            &&$orderNo!==''
            &&$orderNo===(string)($instance['order_no_snapshot']??'')
            &&strpos($orderNo,'BXDEMOHALL')!==0;
    }

    private function deadline(string $explicit,string $base,int $amount,string $unit): ?string
    {
        $explicit=$this->normalizeDate($explicit);
        if($explicit!==null)return$explicit;
        $base=$this->normalizeDate($base);
        if($base===null)return null;
        $timestamp=strtotime($base.' UTC');
        if($timestamp===false)return null;
        $seconds=$unit==='day'?$amount*86400:$amount*3600;
        return gmdate('Y-m-d H:i:s',$timestamp+$seconds);
    }

    private function normalizeDate(?string $value): ?string
    {
        $value=trim((string)$value);
        if($value==='')return null;
        $timestamp=strtotime($value.' UTC');
        return$timestamp===false?null:gmdate('Y-m-d H:i:s',$timestamp);
    }

    private function settingInt(string $key,int $default,int $min,int $max): int
    {
        try{$value=(int)\setting($key,$default);}catch(\Throwable $ignored){$value=$default;}
        return max($min,min($max,$value));
    }
}
