<?php
$selectedName = (string)($channel ?? '');
$formatTimestamp = static function ($timestamp): string {
    $timestamp = (int)$timestamp;
    if ($timestamp < 1) return '—';
    try {
        return (new DateTimeImmutable('@'.$timestamp))->setTimezone(new DateTimeZone((string)app_config('app.timezone', 'Asia/Shanghai')))->format('Y-m-d H:i');
    } catch (Throwable $e) { return date('Y-m-d H:i', $timestamp); }
};
$logDirectoryInfo = is_array($logDirectory ?? null) ? $logDirectory : [];
$isTesting = strtolower((string)app_config('app.env', 'production')) === 'testing';
$logDirectoryHealthy = !empty($logDirectoryInfo['exists'])
    && empty($logDirectoryInfo['is_link'])
    && !empty($logDirectoryInfo['readable'])
    && !empty($logDirectoryInfo['writable']);
?>
<div class="admin-page-head">
    <div><h1>运行日志</h1><p>查看 APP、API、支付回调、消息、后台和计划任务等运行日志。仅显示日志末尾内容，并自动隐藏常见敏感字段。</p></div>
    <div class="admin-page-actions"><span class="admin-badge admin-badge--info"><?= (int)($logCount ?? 0) ?> 个文件 · <?= e($totalSizeDisplay ?? '0 B') ?></span></div>
</div>

<div class="admin-alert admin-alert--warning admin-mb-3">
    <i class="ri-shield-check-line"></i>
    <div class="admin-alert__body"><strong>日志保留说明</strong>高频计划任务日志约 4 MB 自动滚动，其他活跃日志约 8 MB 自动滚动；每个通道最多保留 3 份归档，并同时受归档总量限制。超过 30 天未再写入的活跃日志会自动清空，旧归档也会自动删除，避免历史故障和计划任务日志长期占用磁盘。</div>
</div>

<?php if (!$logDirectoryHealthy): ?>
<div class="admin-alert admin-alert--danger admin-mb-3">
    <i class="ri-error-warning-line"></i>
    <div class="admin-alert__body"><strong>日志目录不可用</strong>
        当前目录：<span class="admin-mono"><?= e((string)($logDirectoryInfo['path'] ?? base_path('storage/logs'))) ?></span>。
        <?php if (!empty($logDirectoryInfo['is_link'])): ?>日志目录不能使用符号链接。
        <?php elseif (empty($logDirectoryInfo['exists'])): ?>目录不存在且 PHP 进程无法自动创建，请检查 <span class="admin-mono">storage</span> 目录权限。
        <?php elseif (empty($logDirectoryInfo['readable'])): ?>PHP 进程无法读取该目录。
        <?php elseif (empty($logDirectoryInfo['writable'])): ?>目录可读但不可写，新日志无法生成；请检查 PHP-FPM 用户对 <span class="admin-mono">storage/logs</span> 的属主和写权限。
        <?php endif; ?>
    </div>
</div>
<?php elseif ($isTesting && empty($files)): ?>
<div class="admin-alert admin-alert--info admin-mb-3">
    <i class="ri-information-line"></i>
    <div class="admin-alert__body"><strong>测试环境当前没有日志</strong>日志目录已经可读写。测试站默认不配置自动计划任务，且没有新异常或手动执行任务时，日志为空属于正常情况；测试站只读取自己的 <span class="admin-mono">server/storage/logs</span>，不会读取正式站日志。</div>
</div>
<?php endif; ?>

<section class="admin-card admin-mb-3">
    <div class="admin-card__header"><div class="admin-card__title"><h3>清理旧日志</h3><p>只处理 storage/logs 下的 .log 文件，不会触碰上传文件、安装锁、配置或数据库。</p></div></div>
    <div class="admin-card__body">
        <form method="post" action="<?= e(admin_url('runtime-logs.purge')) ?>" class="admin-filter">
            <?= csrf()->input() ?>
            <div class="admin-field"><label class="admin-label">删除多久以前的日志</label><select class="admin-control" name="days"><option value="7">7 天</option><option value="15">15 天</option><option value="30" selected>30 天</option><option value="60">60 天</option><option value="90">90 天</option><option value="180">180 天</option></select></div>
            <div class="admin-filter__actions"><button class="admin-btn admin-btn--danger" data-confirm="将删除超过所选时间的日志文件，操作不可恢复。确认继续？">清理旧日志</button></div>
        </form>
    </div>
</section>

<section class="admin-card admin-mb-3">
    <div class="admin-card__header"><div class="admin-card__title"><h3>日志文件</h3><p>优先关注 APP / 全局异常、API / 支付回调及后台管理类日志。</p></div></div>
    <div class="admin-table-wrap">
        <table class="admin-table admin-table--responsive">
            <thead><tr><th>类型</th><th>文件</th><th>大小</th><th>最后写入</th><th>查看</th></tr></thead>
            <tbody>
            <?php foreach (($files ?? []) as $file): ?>
                <tr>
                    <td data-label="类型"><span class="admin-badge admin-badge--<?= (string)$file['name']==='app.log' ? 'danger' : 'info' ?>"><?= e($file['category_label']) ?></span></td>
                    <td data-label="文件"><strong class="admin-mono"><?= e($file['name']) ?></strong></td>
                    <td data-label="大小"><?= e($file['size_display']) ?></td>
                    <td data-label="最后写入"><?= e($formatTimestamp($file['modified_at'])) ?></td>
                    <td data-label="查看"><a class="admin-btn admin-btn--sm <?= $selectedName===(string)$file['name'] ? 'admin-btn--primary' : '' ?>" href="<?= e(admin_url('runtime-logs',['channel'=>$file['name']])) ?>">查看</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($files)): ?><tr><td colspan="5"><div class="admin-empty"><h3>暂无运行日志</h3><p><?= $logDirectoryHealthy ? '当前 storage/logs 下没有非空 .log 文件。' : '日志目录当前不可正常读取或写入，请先处理上方目录状态提示。' ?></p></div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if (!empty($selected)): ?>
<section class="admin-card">
    <div class="admin-card__header">
        <div class="admin-card__title"><h3><?= e($selected['name']) ?></h3><p>只读取文件末尾最多 256 KB；较新的记录显示在前。</p></div>
        <div class="admin-inline-actions">
            <form method="post" action="<?= e(admin_url('runtime-logs.clear')) ?>"><?= csrf()->input() ?><input type="hidden" name="channel" value="<?= e($selected['name']) ?>"><button class="admin-btn admin-btn--sm admin-btn--danger" data-confirm="确认清空当前日志？该操作不可恢复，但不会影响程序运行。">清空当前日志</button></form>
        </div>
    </div>
    <div class="admin-card__body">
        <form class="admin-filter" method="get" action="<?= e(app_url('admin/')) ?>">
            <input type="hidden" name="route" value="runtime-logs"><input type="hidden" name="channel" value="<?= e($selectedName) ?>">
            <div class="admin-field admin-field--search"><label class="admin-label">在最近日志中搜索</label><input class="admin-control" name="q" value="<?= e($q ?? '') ?>" placeholder="request_id、错误码、route 或关键字"></div>
            <div class="admin-filter__actions"><button class="admin-btn admin-btn--primary">搜索</button><a class="admin-btn" href="<?= e(admin_url('runtime-logs',['channel'=>$selectedName])) ?>">重置</a></div>
        </form>
    </div>
    <div class="admin-table-wrap">
        <table class="admin-table admin-table--responsive"><thead><tr><th style="width:72px">序号</th><th>日志内容</th></tr></thead><tbody>
        <?php foreach (($lines ?? []) as $index=>$line): ?><tr><td data-label="序号" class="admin-text-muted"><?= (int)((($result['page'] ?? 1)-1)*($result['per_page'] ?? 100)+$index+1) ?></td><td data-label="日志内容"><span class="admin-break admin-mono"><?= e($line) ?></span></td></tr><?php endforeach; ?>
        <?php if (empty($lines)): ?><tr><td colspan="2"><div class="admin-empty"><p><?= trim((string)($q ?? ''))!=='' ? '最近日志中没有匹配内容。' : '当前日志为空。' ?></p></div></td></tr><?php endif; ?>
        </tbody></table>
    </div>
    <div class="admin-card__footer"><?php include base_path('views/partials/pagination.php'); ?></div>
</section>
<?php endif; ?>