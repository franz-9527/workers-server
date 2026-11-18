<?php
$selectedName = (string)($channel ?? '');
$files = is_array($files ?? null) ? $files : [];
$categories = is_array($categories ?? null) ? $categories : [];
$formatTimestamp = static function ($timestamp): string {
    $timestamp = (int)$timestamp;
    if ($timestamp < 1) return '—';
    try {
        return (new DateTimeImmutable('@'.$timestamp))
            ->setTimezone(new DateTimeZone((string)app_config('app.timezone', 'Asia/Shanghai')))
            ->format('Y-m-d H:i');
    } catch (Throwable $e) {
        return date('Y-m-d H:i', $timestamp);
    }
};
$logDirectoryInfo = is_array($logDirectory ?? null) ? $logDirectory : [];
$isTesting = strtolower((string)app_config('app.env', 'production')) === 'testing';
$logDirectoryHealthy = !empty($logDirectoryInfo['exists'])
    && empty($logDirectoryInfo['is_link'])
    && !empty($logDirectoryInfo['readable'])
    && !empty($logDirectoryInfo['writable']);
$schedulerRunData = is_array($schedulerRuns ?? null) ? $schedulerRuns : [];
?>
<div class="admin-runtime-log-page">
    <div class="admin-page-head">
        <div>
            <h1>运行日志</h1>
            <p>查看 APP、API、支付回调、消息、后台和计划任务等运行日志。只读取日志末尾内容，并自动隐藏常见敏感字段；仅超级管理员可查看和清理。</p>
        </div>
        <div class="admin-page-actions">
            <span class="admin-badge admin-badge--info"><?= (int)($logCount ?? 0) ?> 个文件 · <?= e($totalSizeDisplay ?? '0 B') ?></span>
        </div>
    </div>

    <div class="admin-alert admin-alert--warning admin-mb-3">
        <i class="ri-shield-check-line"></i>
        <div class="admin-alert__body">
            <strong>日志保留说明</strong>
            高频计划任务日志约 4 MB 自动滚动，其他活跃日志约 8 MB 自动滚动；每个通道最多保留 3 份归档，并同时受归档总量限制。超过 30 天未再写入的活跃日志会自动清空，旧归档也会自动删除。订单、资金和消息业务事实以数据库记录为准，不以文件日志代替。
        </div>
    </div>

    <?php if (!$logDirectoryHealthy): ?>
        <div class="admin-alert admin-alert--danger admin-mb-3">
            <i class="ri-error-warning-line"></i>
            <div class="admin-alert__body">
                <strong>日志目录不可用，清理操作已停用</strong>
                当前目录：<span class="admin-mono"><?= e((string)($logDirectoryInfo['path'] ?? base_path('storage/logs'))) ?></span>。
                <?php if (!empty($logDirectoryInfo['is_link'])): ?>
                    日志目录不能使用符号链接。
                <?php elseif (empty($logDirectoryInfo['exists'])): ?>
                    目录不存在且 PHP 进程无法自动创建，请检查 storage 目录权限。
                <?php elseif (empty($logDirectoryInfo['readable'])): ?>
                    PHP 进程无法读取该目录。
                <?php elseif (empty($logDirectoryInfo['writable'])): ?>
                    目录可读但不可写，新日志无法生成；请检查 PHP-FPM 用户对 storage/logs 的属主和写权限。
                <?php endif; ?>
            </div>
        </div>
    <?php elseif ($isTesting && !$files): ?>
        <div class="admin-alert admin-alert--info admin-mb-3">
            <i class="ri-information-line"></i>
            <div class="admin-alert__body">
                <strong>测试环境当前没有日志</strong>
                日志目录已经可读写。测试站默认不配置自动计划任务，且没有新异常或手动执行任务时，日志为空属于正常情况；测试站只读取自己的 server/storage/logs，不会读取正式站日志。
            </div>
        </div>
    <?php endif; ?>

    <section class="admin-card admin-mb-3">
        <div class="admin-card__header">
            <div class="admin-card__title">
                <h3>清理旧日志</h3>
                <p>只处理 storage/logs 下的 .log 文件，不会触碰上传文件、安装锁、配置或数据库。</p>
            </div>
        </div>
        <div class="admin-card__body">
            <form method="post"
                  action="<?= e(admin_url('runtime-logs.purge')) ?>"
                  class="admin-filter"
                  data-confirm="将清理所选日志类型中超过指定保留时间的旧日志，操作不可恢复。确认继续？"
                  data-loading>
                <?= csrf()->input() ?>
                <div class="admin-field">
                    <label class="admin-label" for="runtime-log-purge-days">日志保留时间</label>
                    <select class="admin-control" id="runtime-log-purge-days" name="days" required>
                        <option value="7">7 天</option>
                        <option value="15">15 天</option>
                        <option value="30" selected>30 天</option>
                        <option value="60">60 天</option>
                        <option value="90">90 天</option>
                        <option value="180">180 天</option>
                    </select>
                </div>
                <div class="admin-field">
                    <label class="admin-label" for="runtime-log-purge-category">日志类型</label>
                    <select class="admin-control" id="runtime-log-purge-category" name="category">
                        <option value="">全部类型</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= e($category['key']) ?>"><?= e($category['label']) ?>（<?= (int)$category['count'] ?> 个 / <?= e($category['size_display']) ?>）</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="admin-filter__actions">
                    <button class="admin-btn admin-btn--danger" type="submit" data-loading-text="正在清理…" <?= !$logDirectoryHealthy ? 'disabled' : '' ?>>
                        <i class="ri-delete-bin-line"></i> 清理旧日志
                    </button>
                </div>
            </form>
        </div>
    </section>

    <section class="admin-card admin-mb-3">
        <div class="admin-card__header">
            <div class="admin-card__title">
                <h3>按类型清空日志</h3>
                <p>清空所选类型的活跃日志并删除归档，不影响其他日志类别。</p>
            </div>
        </div>
        <div class="admin-card__body">
            <form method="post"
                  action="<?= e(admin_url('runtime-logs.clear-category')) ?>"
                  class="admin-filter"
                  data-confirm="将清空所选类型的全部日志，包括活跃文件和历史归档。此操作不可恢复，确认继续？"
                  data-loading>
                <?= csrf()->input() ?>
                <div class="admin-field">
                    <label class="admin-label" for="runtime-log-clear-category">日志类型</label>
                    <select class="admin-control" id="runtime-log-clear-category" name="category" required <?= !$categories ? 'disabled' : '' ?>>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= e($category['key']) ?>"><?= e($category['label']) ?>（<?= (int)$category['count'] ?> 个 / <?= e($category['size_display']) ?>）</option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$categories): ?><small class="admin-help">当前没有可清空的非空日志类别。</small><?php endif; ?>
                </div>
                <div class="admin-filter__actions">
                    <button class="admin-btn admin-btn--danger" type="submit" data-loading-text="正在清空…" <?= !$logDirectoryHealthy || !$categories ? 'disabled' : '' ?>>
                        <i class="ri-delete-bin-line"></i> 清空该类型
                    </button>
                </div>
            </form>
        </div>
    </section>

    <?php if (!empty($schedulerRunData['available'])): ?>
        <section class="admin-card admin-mb-3">
            <div class="admin-card__header">
                <div class="admin-card__title">
                    <h3>数据库计划任务运行摘要</h3>
                    <p>只清理已结束的诊断摘要；不会删除业务失败事实或任务幂等记录。</p>
                </div>
                <span class="admin-badge admin-badge--info"><?= number_format((int)$schedulerRunData['total_count']) ?> 条</span>
            </div>
            <div class="admin-card__body">
                <div class="admin-grid admin-grid--4 admin-runtime-log-stats">
                    <div class="admin-stat">
                        <div class="admin-stat__value"><?= number_format((int)$schedulerRunData['running_count']) ?></div>
                        <div class="admin-stat__meta">运行中</div>
                    </div>
                    <div class="admin-stat">
                        <div class="admin-stat__value"><?= number_format((int)$schedulerRunData['completed_count']) ?></div>
                        <div class="admin-stat__meta">已结束</div>
                    </div>
                    <div class="admin-stat">
                        <div class="admin-stat__value"><?= number_format((int)$schedulerRunData['unresolved_failure_count']) ?></div>
                        <div class="admin-stat__meta">未解决失败</div>
                    </div>
                    <div class="admin-stat">
                        <div class="admin-stat__value"><?= number_format((int)$schedulerRunData['claim_count']) ?></div>
                        <div class="admin-stat__meta">幂等记录总数</div>
                    </div>
                </div>
                <p class="admin-runtime-log-meta">
                    记录时间范围：<?= e(local_datetime($schedulerRunData['oldest_at'] ?? null)) ?>
                    至 <?= e(local_datetime($schedulerRunData['latest_at'] ?? null)) ?>
                </p>
                <form method="post"
                      action="<?= e(admin_url('runtime-logs.purge-runs')) ?>"
                      class="admin-filter admin-mt-3"
                      data-confirm="将清理所选任务中超过保留时间的已结束运行摘要。运行中记录和未完成任务认领会保留，业务失败记录与幂等记录不会删除。确认继续？"
                      data-loading>
                    <?= csrf()->input() ?>
                    <div class="admin-field">
                        <label class="admin-label" for="runtime-run-purge-days">摘要保留时间</label>
                        <select class="admin-control" id="runtime-run-purge-days" name="days" required>
                            <option value="7">7 天</option>
                            <option value="15">15 天</option>
                            <option value="30" selected>30 天</option>
                            <option value="60">60 天</option>
                            <option value="90">90 天</option>
                            <option value="180">180 天</option>
                        </select>
                    </div>
                    <div class="admin-field">
                        <label class="admin-label" for="runtime-run-purge-job">任务类型</label>
                        <select class="admin-control" id="runtime-run-purge-job" name="job_name">
                            <option value="">全部任务</option>
                            <?php foreach (($schedulerRunData['jobs'] ?? []) as $job): ?>
                                <option value="<?= e($job['job_name']) ?>"><?= e($job['job_label'] ?? '未登记计划任务') ?>（<?= (int)$job['record_count'] ?> 条）</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="admin-filter__actions">
                        <button class="admin-btn admin-btn--danger" type="submit" data-loading-text="正在清理…" <?= (int)$schedulerRunData['total_count'] < 1 ? 'disabled' : '' ?>>
                            <i class="ri-delete-bin-line"></i> 清理旧运行摘要
                        </button>
                    </div>
                </form>
            </div>
        </section>
    <?php else: ?>
        <div class="admin-alert admin-alert--warning admin-mb-3">
            <i class="ri-database-2-line"></i>
            <div class="admin-alert__body">
                <strong>数据库计划任务运行摘要暂不可用</strong>
                当前数据库没有可读取的运行摘要表。请核对版本升级和数据库迁移状态；此提示不影响下方文件日志查看。
            </div>
        </div>
    <?php endif; ?>

    <section class="admin-card admin-mb-3">
        <div class="admin-card__header">
            <div class="admin-card__title">
                <h3>日志文件</h3>
                <p>优先关注 APP / 全局异常、API / 支付回调及后台管理类日志。</p>
            </div>
        </div>
        <div class="admin-table-wrap">
            <table class="admin-table admin-table--responsive">
                <thead>
                    <tr><th>类型</th><th>文件</th><th>大小</th><th>最后写入</th><th>查看</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($files as $file): ?>
                        <tr>
                            <td data-label="类型">
                                <span class="admin-badge admin-badge--<?= (string)$file['name'] === 'app.log' ? 'danger' : 'info' ?>"><?= e($file['category_label']) ?></span>
                            </td>
                            <td data-label="文件"><strong class="admin-mono admin-break"><?= e($file['name']) ?></strong></td>
                            <td data-label="大小"><?= e($file['size_display']) ?></td>
                            <td data-label="最后写入"><?= e($formatTimestamp($file['modified_at'])) ?></td>
                            <td data-label="查看">
                                <a class="admin-btn admin-btn--sm <?= $selectedName === (string)$file['name'] ? 'admin-btn--primary' : '' ?>"
                                   href="<?= e(admin_url('runtime-logs', ['channel'=>$file['name']])) ?>">查看</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$files): ?>
                        <tr>
                            <td colspan="5">
                                <div class="admin-empty">
                                    <h3>暂无运行日志</h3>
                                    <p><?= $logDirectoryHealthy ? '当前 storage/logs 下没有非空 .log 文件。' : '日志目录当前不可正常读取或写入，请先处理上方目录状态提示。' ?></p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if (!empty($selected)): ?>
        <section class="admin-card">
            <div class="admin-card__header">
                <div class="admin-card__title">
                    <h3 class="admin-mono admin-break"><?= e($selected['name']) ?></h3>
                    <p>只读取文件末尾最多 256 KB；较新的记录显示在前，每页最多 100 条。</p>
                </div>
                <div class="admin-inline-actions">
                    <form method="post"
                          action="<?= e(admin_url('runtime-logs.clear')) ?>"
                          data-confirm="确认清空当前日志文件 <?= e($selected['name']) ?>？该操作不可恢复，但不会影响程序运行。"
                          data-loading>
                        <?= csrf()->input() ?>
                        <input type="hidden" name="channel" value="<?= e($selected['name']) ?>">
                        <button class="admin-btn admin-btn--sm admin-btn--danger"
                                type="submit"
                                data-loading-text="正在清空…"
                                <?= !$logDirectoryHealthy ? 'disabled' : '' ?>>
                            <i class="ri-delete-bin-line"></i> 清空当前日志
                        </button>
                    </form>
                </div>
            </div>
            <div class="admin-card__body">
                <form class="admin-filter" method="get" action="<?= e(app_url('admin/')) ?>" data-loading>
                    <input type="hidden" name="route" value="runtime-logs">
                    <input type="hidden" name="channel" value="<?= e($selectedName) ?>">
                    <div class="admin-field admin-field--search">
                        <label class="admin-label" for="runtime-log-search">在最近日志中搜索</label>
                        <input class="admin-control" id="runtime-log-search" name="q" value="<?= e($q ?? '') ?>" maxlength="100" placeholder="request_id、错误码或关键字">
                    </div>
                    <div class="admin-filter__actions">
                        <button class="admin-btn admin-btn--primary" type="submit" data-loading-text="正在搜索…">搜索</button>
                        <a class="admin-btn" href="<?= e(admin_url('runtime-logs', ['channel'=>$selectedName])) ?>">重置</a>
                    </div>
                </form>
            </div>
            <div class="admin-table-wrap">
                <table class="admin-table admin-table--responsive">
                    <thead><tr><th style="width:72px">序号</th><th>日志内容</th></tr></thead>
                    <tbody>
                        <?php foreach (($lines ?? []) as $index=>$line): ?>
                            <tr>
                                <td data-label="序号" class="admin-text-muted"><?= (int)((($result['page'] ?? 1) - 1) * ($result['per_page'] ?? 100) + $index + 1) ?></td>
                                <td data-label="日志内容"><span class="admin-runtime-log-line admin-mono"><?= e($line) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($lines)): ?>
                            <tr>
                                <td colspan="2">
                                    <div class="admin-empty admin-empty--compact">
                                        <p><?= trim((string)($q ?? '')) !== '' ? '最近日志中没有匹配内容。' : '当前日志为空。' ?></p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="admin-card__footer"><?php include base_path('views/partials/pagination.php'); ?></div>
        </section>
    <?php endif; ?>
</div>
