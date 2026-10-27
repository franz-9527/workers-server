<?php
// 2026-09-29：计划任务概览与手动执行合并为紧凑列表；历史运行统一折叠，避免页面过长。
$runGroups = isset($runGroups) && is_array($runGroups) ? $runGroups : [];
$runRecordCount = (int) ($runRecordCount ?? 0);
$failures = isset($failures) && is_array($failures) ? $failures : [];
$failureCount = (int) ($failureCount ?? 0);
$taskCatalog = isset($taskCatalog) && is_array($taskCatalog) ? $taskCatalog : [];
$jobLabels = [
    'assignment_timeout' => '派单超时',
    'supplement_timeout' => '补资料超时',
    'repair_escalation' => '售后平台介入提醒',
    'auto_accept' => '自动验收',
    'auto_accept_completion' => '自动验收',
    'commission' => '平台服务费',
    'generate_commission_receivable' => '生成服务费应收',
    'auto_review' => '自动评价',
    'auto_review_order' => '自动评价',
    'auto_cancel' => '订单自动取消',
    'auto_cancel_unaccepted' => '未接单自动取消',
    'draft_cleanup' => '过期草稿清理',
    'media_cleanup' => '无引用图片清理',
    'sms_dispatch' => '短信 / 企业微信发送',
    'paid_sms_dispatch' => '付费短信发送',
    'paid_sms_receipt' => '付费短信回执结算',
    'wallet_reconcile' => '用户钱包台账对账',
    'measurement_accept' => '测量单自动确认',
    'measurement_release' => '测量费冻结解冻',
    'platform_balance_release' => '平台单工费解冻',
];
foreach ($taskCatalog as $taskKey => $taskMeta) {
    if (!empty($taskMeta['label'])) $jobLabels[$taskKey] = (string) $taskMeta['label'];
}
$runStatus = [
    'success' => ['正常', 'success'],
    'failed' => ['失败', 'danger'],
    'running' => ['运行中', 'warning'],
];

$runGroupMap = [];
$recentRuns = [];
foreach ($runGroups as $group) {
    $group = (array) $group;
    $jobName = (string) ($group['job_name'] ?? '');
    if ($jobName !== '') $runGroupMap[$jobName] = $group;
    if (!empty($group['latest']) && is_array($group['latest'])) $recentRuns[] = $group['latest'];
    if (!empty($group['history']) && is_array($group['history'])) {
        foreach ($group['history'] as $row) {
            if (is_array($row)) $recentRuns[] = $row;
        }
    }
}
usort($recentRuns, static function (array $a, array $b): int {
    return (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0);
});
$recentRuns = array_slice($recentRuns, 0, 50);
?>

<div class="admin-page-head">
    <div>
        <h1>计划任务</h1>
        <p>自动任务由服务器持续运行；需要立即检查时，可直接在对应任务右侧执行。</p>
    </div>
    <div class="admin-page-actions">
        <span class="admin-badge admin-badge--<?= $failureCount > 0 ? 'danger' : 'success' ?>">未解决失败 <?= number_format($failureCount) ?> 条</span>
        <form method="post" action="<?= e(admin_url('tasks.run')) ?>">
            <?= csrf()->input() ?>
            <input type="hidden" name="task" value="all">
            <button class="admin-btn admin-btn--primary" data-confirm="确认立即执行全部计划任务？系统只会处理已经达到业务条件的记录，并使用互斥锁避免与服务器自动任务重复执行。">
                <i class="ri-play-circle-line"></i> 执行全部
            </button>
        </form>
    </div>
</div>

<section class="admin-card admin-mb-3">
    <div class="admin-card__header">
        <div class="admin-card__title">
            <h3>任务运行与手动执行</h3>
            <p>每项只显示最新状态；没有符合条件的数据时显示“无待处理”，不再重复展示扫描 0 / 成功 0 / 失败 0。</p>
        </div>
    </div>

    <div class="admin-table-wrap">
        <table class="admin-table admin-table--responsive">
            <thead>
            <tr>
                <th>任务</th>
                <th>最新状态</th>
                <th>处理结果</th>
                <th>操作</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($taskCatalog as $taskKey => $taskMeta): ?>
                <?php
                $group = isset($runGroupMap[$taskKey]) ? (array) $runGroupMap[$taskKey] : [];
                $latest = isset($group['latest']) && is_array($group['latest']) ? $group['latest'] : [];
                $status = (string) ($latest['status'] ?? '');
                $meta = $runStatus[$status] ?? ['未运行', 'muted'];
                $scanned = (int) ($latest['scanned_count'] ?? 0);
                $success = (int) ($latest['success_count'] ?? 0);
                $failure = (int) ($latest['failure_count'] ?? 0);

                if (!$latest) {
                    $resultMain = '尚无运行记录';
                    $resultSecondary = '';
                } elseif ($status === 'running') {
                    $resultMain = '正在执行';
                    $resultSecondary = '';
                } elseif ($status === 'failed') {
                    $resultMain = $failure > 0 ? '失败 ' . number_format($failure) . ' 项' : '执行失败';
                    $resultSecondary = $scanned > 0 ? '扫描 ' . number_format($scanned) . ' · 已处理 ' . number_format($success) : '';
                } elseif ($success > 0 || $failure > 0) {
                    $resultMain = '已处理 ' . number_format($success) . ' 项';
                    $resultSecondary = '扫描 ' . number_format($scanned) . ($failure > 0 ? ' · 失败 ' . number_format($failure) : '');
                } elseif ($scanned > 0) {
                    $resultMain = '已检查 ' . number_format($scanned) . ' 项';
                    $resultSecondary = '无需处理';
                } else {
                    $resultMain = '无待处理';
                    $resultSecondary = '';
                }
                ?>
                <tr>
                    <td data-label="任务">
                        <strong><?= e($taskMeta['label'] ?? $taskKey) ?></strong>
                        <?php if (!empty($taskMeta['note'])): ?><small class="admin-table__secondary"><?= e($taskMeta['note']) ?></small><?php endif; ?>
                    </td>
                    <td data-label="最新状态">
                        <span class="admin-badge admin-badge--<?= e($meta[1]) ?>"><?= e($meta[0]) ?></span>
                        <?php if ($latest): ?><small class="admin-table__secondary"><?= e(local_datetime($latest['started_at'] ?? null)) ?></small><?php endif; ?>
                    </td>
                    <td data-label="处理结果">
                        <strong class="<?= $status === 'failed' ? 'admin-text-danger' : '' ?>"><?= e($resultMain) ?></strong>
                        <?php if ($resultSecondary !== ''): ?><small class="admin-table__secondary"><?= e($resultSecondary) ?></small><?php endif; ?>
                        <?php if (!empty($latest['error_message'])): ?><small class="admin-table__secondary admin-text-danger admin-break"><?= e($latest['error_message']) ?></small><?php endif; ?>
                    </td>
                    <td data-label="操作">
                        <form method="post" action="<?= e(admin_url('tasks.run')) ?>">
                            <?= csrf()->input() ?>
                            <input type="hidden" name="task" value="<?= e($taskKey) ?>">
                            <button class="admin-btn admin-btn--sm" data-confirm="确认立即执行“<?= e($taskMeta['label'] ?? $taskKey) ?>”？系统会重新检查当前业务条件。">
                                <i class="ri-play-line"></i> 立即执行
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($recentRuns): ?>
        <div class="admin-card__body">
            <details class="admin-task-history">
                <summary>
                    <span><i class="ri-history-line"></i> 最近运行记录</span>
                    <small>保留 <?= number_format($runRecordCount) ?> 条摘要，这里最多显示最近 <?= number_format(count($recentRuns)) ?> 条</small>
                </summary>
                <div class="admin-table-wrap">
                    <table class="admin-table admin-table--responsive">
                        <thead><tr><th>时间</th><th>任务</th><th>状态</th><th>结果</th><th>错误说明</th></tr></thead>
                        <tbody>
                        <?php foreach ($recentRuns as $row): ?>
                            <?php
                            $historyStatus = (string) ($row['status'] ?? '');
                            $historyMeta = $runStatus[$historyStatus] ?? ['未知', 'muted'];
                            $rowScanned = (int) ($row['scanned_count'] ?? 0);
                            $rowSuccess = (int) ($row['success_count'] ?? 0);
                            $rowFailure = (int) ($row['failure_count'] ?? 0);
                            if ($historyStatus === 'success' && $rowScanned === 0 && $rowSuccess === 0 && $rowFailure === 0) {
                                $historyResult = '无待处理';
                            } else {
                                $historyResult = '扫描 ' . number_format($rowScanned) . ' · 处理 ' . number_format($rowSuccess) . ' · 失败 ' . number_format($rowFailure);
                            }
                            ?>
                            <tr>
                                <td data-label="时间"><?= e(local_datetime($row['started_at'] ?? null)) ?><small class="admin-table__secondary">#<?= (int) ($row['id'] ?? 0) ?></small></td>
                                <td data-label="任务"><strong><?= e($jobLabels[(string) ($row['job_name'] ?? '')] ?? ($row['job_name'] ?? '未知任务')) ?></strong><small class="admin-table__secondary admin-mono"><?= e($row['job_name'] ?? '') ?></small></td>
                                <td data-label="状态"><span class="admin-badge admin-badge--<?= e($historyMeta[1]) ?>"><?= e($historyMeta[0]) ?></span></td>
                                <td data-label="结果"><?= e($historyResult) ?></td>
                                <td data-label="错误说明"><span class="admin-break"><?= e($row['error_message'] ?: '—') ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
        </div>
    <?php endif; ?>
</section>

<section class="admin-card">
    <div class="admin-card__header">
        <div class="admin-card__title">
            <h3>未解决的业务失败</h3>
            <p>这里只保留需要关注的逐笔失败；任务下一次成功后，已解决记录会自动关闭。</p>
        </div>
    </div>

    <?php if ($failures): ?>
        <div class="admin-table-wrap">
            <table class="admin-table admin-table--responsive">
                <thead><tr><th>任务</th><th>业务记录</th><th>次数 / 下次重试</th><th>首次记录</th><th>错误说明</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($failures as $row): ?>
                    <tr>
                        <td data-label="任务"><strong><?= e($jobLabels[(string) ($row['job_name'] ?? '')] ?? ($row['job_name'] ?? '未知任务')) ?></strong><small class="admin-table__secondary admin-mono"><?= e($row['job_name'] ?? '') ?></small></td>
                        <td data-label="业务记录"><span class="admin-mono"><?= e($row['business_key'] ?? '—') ?></span><small class="admin-table__secondary">数据版本 <?= (int) ($row['business_version'] ?? 0) ?> · 运行 #<?= e($row['scheduler_run_id'] ?? '—') ?></small></td>
                        <td data-label="次数 / 下次重试"><?= number_format((int) ($row['attempt_no'] ?? 0)) ?> 次<small class="admin-table__secondary">下次重试 <?= e(local_datetime($row['next_retry_at'] ?? null)) ?></small></td>
                        <td data-label="首次记录"><?= e(local_datetime($row['created_at'] ?? null)) ?></td>
                        <td data-label="错误说明"><span class="admin-break"><?= e($row['error_message'] ?? '未提供错误说明') ?></span><?php if (!empty($row['error_class'])): ?><small class="admin-table__secondary admin-mono"><?= e($row['error_class']) ?></small><?php endif; ?></td>
                        <td data-label="操作">
                            <?php $failureJob=(string)($row['job_name']??''); ?>
                            <?php if (isset($taskCatalog[$failureJob])): ?>
                                <form method="post" action="<?= e(admin_url('tasks.failure.retry', ['id'=>$row['id']])) ?>">
                                    <?= csrf()->input() ?>
                                    <button class="admin-btn admin-btn--sm" data-confirm="确认立即重试“<?= e($jobLabels[$failureJob] ?? $failureJob) ?>”？系统会重新校验当前业务条件；若问题仍存在，会保留新的错误记录。">
                                        <i class="ri-restart-line"></i> 立即重试
                                    </button>
                                </form>
                            <?php else: ?><span class="admin-text-muted">—</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="admin-card__body">
            <span class="admin-text-muted"><i class="ri-checkbox-circle-line"></i> 当前没有未解决失败。</span>
        </div>
    <?php endif; ?>
</section>
