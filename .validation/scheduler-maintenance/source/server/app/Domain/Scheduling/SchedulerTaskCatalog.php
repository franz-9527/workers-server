<?php

declare(strict_types=1);

namespace WorkerAdmin\Domain\Scheduling;

use InvalidArgumentException;

/**
 * 计划任务稳定目录：名称、别名和 all 执行顺序集中管理。
 *
 * 旧 SchedulerService 仍负责历史任务的锁、审计和逐笔失败记录；
 * 仅由防腐网关执行的新任务必须显式列入 GATEWAY_ONLY_TASKS，避免两套目录静默漂移。
 */
final class SchedulerTaskCatalog
{
    private const ALL_SEQUENCE = [
        'assignment_timeout',
        'supplement_timeout',
        'repair_escalation',
        'auto_accept',
        'commission',
        'auto_review',
        'auto_cancel',
        'measurement_accept',
        'measurement_release',
        'platform_balance_release',
        'draft_cleanup',
        // V2.5.0：已提交订单的本地媒体先后台迁七牛，再执行无引用媒体清理。
        'media_promote',
        'media_cleanup',
        'sms_dispatch',
        'paid_sms_dispatch',
        'paid_sms_receipt',
        'wallet_reconcile',
        // V2.5.0：旧调度器完成后由防腐网关追加执行，目录顺序必须与真实执行一致。
        'platform_auto_publish',
    ];

    /** @var string[] 只由 LegacySchedulerGateway 执行、不能下沉给旧 SchedulerService 的任务。 */
    private const GATEWAY_ONLY_TASKS = [
        'media_promote',
        'platform_auto_publish',
    ];

    private const ALIASES = [
        'accept' => 'auto_accept',
        'auto-accept' => 'auto_accept',
        'auto_accept' => 'auto_accept',
        'commission' => 'commission',
        'commissions' => 'commission',
        'review' => 'auto_review',
        'auto-review' => 'auto_review',
        'auto_review' => 'auto_review',
        'cancel' => 'auto_cancel',
        'auto-cancel' => 'auto_cancel',
        'auto_cancel' => 'auto_cancel',
        'draft-cleanup' => 'draft_cleanup',
        'draft_cleanup' => 'draft_cleanup',
        'media-promote' => 'media_promote',
        'media_promote' => 'media_promote',
        'media-cleanup' => 'media_cleanup',
        'media_cleanup' => 'media_cleanup',
        'assignment-timeout' => 'assignment_timeout',
        'assignment_timeout' => 'assignment_timeout',
        'assignments-timeout' => 'assignment_timeout',
        'supplement-timeout' => 'supplement_timeout',
        'supplement_timeout' => 'supplement_timeout',
        'repair-escalation' => 'repair_escalation',
        'repair_escalation' => 'repair_escalation',
        'sms' => 'sms_dispatch',
        'sms-dispatch' => 'sms_dispatch',
        'sms_dispatch' => 'sms_dispatch',
        'paid-sms-dispatch' => 'paid_sms_dispatch',
        'paid_sms_dispatch' => 'paid_sms_dispatch',
        'paid-sms-receipt' => 'paid_sms_receipt',
        'paid_sms_receipt' => 'paid_sms_receipt',
        'wallet-reconcile' => 'wallet_reconcile',
        'wallet_reconcile' => 'wallet_reconcile',
        'measurement-accept' => 'measurement_accept',
        'measurement_accept' => 'measurement_accept',
        'measurement-release' => 'measurement_release',
        'measurement_release' => 'measurement_release',
        'platform-auto-publish' => 'platform_auto_publish',
        'platform_auto_publish' => 'platform_auto_publish',
        'platform-balance-release' => 'platform_balance_release',
        'platform_balance_release' => 'platform_balance_release',
        'all' => 'all',
    ];

    public static function normalize(string $task): string
    {
        $task = strtolower(trim($task));
        if (!isset(self::ALIASES[$task])) {
            throw new InvalidArgumentException('未知计划任务：' . $task);
        }
        return self::ALIASES[$task];
    }

    /** @return string[] 对 CLI / 后台公开的完整任务目录。 */
    public static function supported(): array
    {
        return array_merge(self::ALL_SEQUENCE, ['all']);
    }

    /** @return string[] 完整 all 执行顺序（含网关独立任务）。 */
    public static function allSequence(): array
    {
        return self::ALL_SEQUENCE;
    }

    /** @return string[] 旧 SchedulerService 自己必须支持的 all 顺序。 */
    public static function legacyAllSequence(): array
    {
        return array_values(array_diff(self::ALL_SEQUENCE, self::GATEWAY_ONLY_TASKS));
    }

    /** @return string[] 旧 SchedulerService 必须支持的任务集合；顺序不作为契约。 */
    public static function legacySupported(): array
    {
        return array_merge(self::legacyAllSequence(), ['all']);
    }

    /** 判断规范任务是否必须由防腐网关直接处理。 */
    public static function isGatewayOnly(string $task): bool
    {
        return in_array($task, self::GATEWAY_ONLY_TASKS, true);
    }

    private function __construct()
    {
    }
}
