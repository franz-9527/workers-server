<?php
// 2026-08-23：审计视图兼容新版 actor/entity 字段，并统一使用本地第三方图标。
$result = isset($result) && is_array($result) ? $result : ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
$rows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : [];
$q = isset($q) ? (string) $q : '';
?>

<div class="admin-page-head">
    <div>
        <h1>操作审计</h1>
        <p>记录管理员及系统关键操作、目标数据和请求来源，仅用于追溯，不提供前台删除入口。</p>
    </div>
    <div class="admin-page-actions"><span class="admin-badge admin-badge--info">共 <?= number_format((int) ($result['total'] ?? 0)) ?> 条</span></div>
</div>

<section class="admin-card">
    <div class="admin-card__body">
        <form class="admin-filter" method="get" action="<?= e(app_url('admin/')) ?>">
            <input type="hidden" name="route" value="audit">
            <div class="admin-field admin-field--search">
                <label class="admin-label" for="audit-q">搜索日志</label>
                <input class="admin-control" id="audit-q" name="q" value="<?= e($q) ?>" placeholder="操作、目标类型、说明或请求编号">
            </div>
            <div class="admin-filter__actions">
                <button class="admin-btn admin-btn--primary" type="submit">查询</button>
                <a class="admin-btn" href="<?= e(admin_url('audit')) ?>">重置</a>
            </div>
        </form>
    </div>

    <div class="admin-table-wrap">
        <table class="admin-table admin-table--responsive">
            <thead>
            <tr><th>时间 / 结果</th><th>操作</th><th>操作者</th><th>目标</th><th>说明</th><th>请求来源</th><th>详情</th></tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php
                $actorType = (string) ($row['actor_type'] ?? 'admin');
                $actorId = $row['actor_id'] ?? $row['admin_id'] ?? null;
                $actorName = (string) ($row['actor_name_snapshot'] ?? $row['real_name'] ?? $row['username'] ?? '');
                if ($actorName === '') {
                    $actorName = $actorType === 'system' ? '系统任务' : ($actorId ? admin_code_text($actorType, '其他操作者') . ' #' . $actorId : '未知操作者');
                }
                $entityType = (string) ($row['entity_type'] ?? $row['resource_type'] ?? '');
                $entityId = $row['entity_id'] ?? $row['resource_id'] ?? null;
                $reason = (string) ($row['reason'] ?? $row['description'] ?? '');
                $resultValue = (string) ($row['result'] ?? 'success');
                $resultMeta = status_meta($resultValue);
                $before = (string) ($row['before_json'] ?? '');
                $after = (string) ($row['after_json'] ?? '');
                if ($before !== '') {
                    $decodedBefore = json_decode($before, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $before = (string) json_encode($decodedBefore, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                    }
                }
                if ($after !== '') {
                    $decodedAfter = json_decode($after, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $after = (string) json_encode($decodedAfter, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                    }
                }
                $hasDetails = $before !== '' || $after !== '' || !empty($row['user_agent']);
                ?>
                <tr>
                    <td data-label="时间 / 结果"><span class="admin-nowrap"><?= e(local_datetime($row['created_at'] ?? null)) ?></span><small class="admin-table__secondary"><span class="admin-badge admin-badge--<?= e($resultMeta[1]) ?>"><?= e($resultMeta[0]) ?></span></small></td>
                    <td data-label="操作"><strong><?= e(admin_code_text($row['action'] ?? '')) ?></strong><details><summary>技术标识</summary><span class="admin-mono"><?= e(($row['action']??'').' / '.$actorType.' / '.$entityType) ?></span></details></td>
                    <td data-label="操作者"><?= e($actorName) ?><small class="admin-table__secondary"><?= e(admin_code_text($actorType, '其他操作者')) ?><?= $actorId ? ' #' . (int) $actorId : '' ?></small></td>
                    <td data-label="目标"><?= e(admin_code_text($entityType, '其他业务数据')) ?><?php if ($entityId !== null && $entityId !== ''): ?><small class="admin-table__secondary admin-mono">#<?= e($entityId) ?></small><?php endif; ?></td>
                    <td data-label="说明"><span class="admin-break"><?= e($reason ?: '—') ?></span></td>
                    <td data-label="请求来源"><span class="admin-mono"><?= e($row['ip_address'] ?? '—') ?></span><small class="admin-table__secondary admin-mono"><?= e($row['request_id'] ?? '无请求编号') ?></small></td>
                    <td data-label="详情">
                        <?php if ($hasDetails): ?>
                            <details>
                                <summary class="admin-btn admin-btn--sm">查看</summary>
                                <div class="admin-mt-3">
                                    <?php if ($before !== ''): ?><label class="admin-label">变更前</label><textarea class="admin-control admin-mono" rows="6" readonly><?= e($before) ?></textarea><?php endif; ?>
                                    <?php if ($after !== ''): ?><label class="admin-label admin-mt-3">变更后</label><textarea class="admin-control admin-mono" rows="6" readonly><?= e($after) ?></textarea><?php endif; ?>
                                    <?php if (!empty($row['user_agent'])): ?><p class="admin-help admin-break">终端：<?= e($row['user_agent']) ?></p><?php endif; ?>
                                </div>
                            </details>
                        <?php else: ?>
                            <span class="admin-text-muted">无变更快照</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="7"><div class="admin-empty"><div class="admin-empty__icon"><i class="ri-history-line"></i></div><h3>暂无审计记录</h3><p><?= $q !== '' ? '没有匹配当前关键词的日志。' : '关键操作发生后会在这里留下记录。' ?></p></div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="admin-card__footer"><?php include base_path('views/partials/pagination.php'); ?></div>
</section>
