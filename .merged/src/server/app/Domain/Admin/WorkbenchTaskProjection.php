<?php

declare(strict_types=1);

namespace WorkerAdmin\Domain\Admin;

/** 管理员订单工作台纯任务分类/统计规则。 */
final class WorkbenchTaskProjection
{
    public static function summary(array $groups): array
    {
        $counts = [
            'todo_orders'=>0,'operation_orders'=>0,'exception_orders'=>0,'auto_publish_orders'=>0,
            'finance_orders'=>0,'settlement_orders'=>0,'income_verification_orders'=>0,'audit_orders'=>0,
            'assignment_orders'=>0,'pricing_orders'=>0,'refund_dispute_orders'=>0,'refund_after_sale_orders'=>0,
            'privacy_orders'=>0,'measurement_orders'=>0,'after_sale_orders'=>0,'attention_orders'=>0,'followup_orders'=>0,
        ];
        foreach ($groups as $group) {
            $tasks = isset($group['tasks']) && is_array($group['tasks']) ? $group['tasks'] : [];
            if (!$tasks) continue;
            $counts['todo_orders']++;
            $types = array_fill_keys(array_map(static fn(array $task): string => (string)($task['task_type'] ?? ''), $tasks), true);
            $hasOperation=false;$hasException=false;$hasAttention=false;
            foreach($tasks as $task){if(self::isException($task))$hasException=true;else$hasOperation=true;if(($task['mode']??'')==='attention')$hasAttention=true;}
            if($hasOperation)$counts['operation_orders']++;if($hasException)$counts['exception_orders']++;if($hasAttention)$counts['attention_orders']++;
            if(isset($types['platform_auto_publish_failed'])||isset($types['platform_auto_publish_stuck']))$counts['auto_publish_orders']++;
            if(isset($types['platform_finance_risk'])||isset($types['platform_refund_finance_review'])||isset($types['platform_refund_recovery_verification'])||isset($types['refund_execution_attention']))$counts['finance_orders']++;
            if(isset($types['platform_income_verification']))$counts['income_verification_orders']++;
            if(isset($types['platform_income_verification'])||isset($types['platform_finance_risk'])||isset($types['platform_refund_finance_review'])||isset($types['platform_refund_recovery_verification'])||isset($types['refund_execution_attention']))$counts['settlement_orders']++;
            if(isset($types['audit_pending']))$counts['audit_orders']++;
            if(isset($types['assignment_required']))$counts['assignment_orders']++;
            if(isset($types['platform_pricing_review']))$counts['pricing_orders']++;
            if(isset($types['refund_review'])||isset($types['dispute_open']))$counts['refund_dispute_orders']++;
            if(isset($types['refund_review'])||isset($types['dispute_open'])||isset($types['after_sale_intervention'])||isset($types['refund_execution_attention']))$counts['refund_after_sale_orders']++;
            if(isset($types['contact_reveal_review']))$counts['privacy_orders']++;
            if(isset($types['measurement_hold_review']))$counts['measurement_orders']++;
            if(isset($types['after_sale_intervention']))$counts['after_sale_orders']++;
            if(isset($types['admin_followup_due']))$counts['followup_orders']++;
        }
        return $counts;
    }

    public static function exceptionTasks(array $tasks): array{return array_values(array_filter($tasks,static fn(array $task):bool=>self::isException($task)));}
    public static function operationTasks(array $tasks): array{return array_values(array_filter($tasks,static fn(array $task):bool=>!self::isException($task)));}
    public static function taskKind(array $task): string{return self::isException($task)?'exception':'operation';}

    public static function matchesScope(array $task, string $scope): bool
    {
        $type=(string)($task['task_type']??'');
        if($scope==='todo')return true;
        if($scope==='operation')return !self::isException($task);
        if($scope==='exception')return self::isException($task);
        if($scope==='publish')return in_array($type,['platform_auto_publish_failed','platform_auto_publish_stuck'],true);
        if($scope==='finance')return in_array($type,['platform_finance_risk','platform_refund_finance_review','platform_refund_recovery_verification','refund_execution_attention'],true);
        if($scope==='settlement')return in_array($type,['platform_income_verification','platform_finance_risk','platform_refund_finance_review','platform_refund_recovery_verification','refund_execution_attention'],true);
        if($scope==='income_verification')return $type==='platform_income_verification';
        if($scope==='audit'||$scope==='pending')return $type==='audit_pending';
        if($scope==='assignment')return $type==='assignment_required';
        if($scope==='pricing')return $type==='platform_pricing_review';
        if($scope==='refund_after_sale')return in_array($type,['refund_review','dispute_open','after_sale_intervention','refund_execution_attention'],true);
        if($scope==='refund_dispute')return in_array($type,['refund_review','dispute_open'],true);
        if($scope==='refund')return $type==='refund_review';
        if($scope==='dispute')return $type==='dispute_open';
        if($scope==='privacy')return $type==='contact_reveal_review';
        if($scope==='measurement')return $type==='measurement_hold_review';
        if($scope==='after_sale')return $type==='after_sale_intervention';
        if($scope==='followup')return $type==='admin_followup_due';
        if($scope==='attention')return ($task['mode']??'')==='attention';
        return false;
    }

    public static function isException(array $task): bool
    {
        return !in_array((string)($task['task_type']??''),[
            'audit_pending','assignment_required','platform_pricing_review','platform_income_verification','admin_followup_due',
        ],true);
    }

    private function __construct(){}
}
