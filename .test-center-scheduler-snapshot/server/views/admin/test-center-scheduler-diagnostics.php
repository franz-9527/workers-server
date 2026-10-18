<?php
$scheduler=(array)($detail['scheduler']??[]);
$schedulerItems=(array)($scheduler['items']??[]);
if(!$schedulerItems)return;
?>
<section class="admin-card admin-mb-3">
  <div class="admin-card__header">
    <div>
      <strong>计划任务诊断</strong>
      <small>按当前测试实例读取期限；全局 scheduler 最近运行只作运维参考，不等于当前订单已被扫描或处理</small>
    </div>
  </div>
  <div class="admin-card__body">
    <div class="admin-alert admin-alert--info admin-mb-2">
      <strong>实例时钟</strong>
      <div><?= e($scheduler['message']??'') ?></div>
      <small>当前 UTC：<?= e($scheduler['as_of_utc']??'') ?> · 测试中心不提供 scheduler all / force 全库执行按钮。</small>
    </div>
    <div class="admin-table-wrap">
      <table class="admin-table admin-table--responsive">
        <thead><tr><th>任务</th><th>本实例期限</th><th>执行边界</th><th>最近全局运行</th></tr></thead>
        <tbody>
        <?php foreach($schedulerItems as $item):
          $deadlineStatus=(string)($item['deadline_status']??'unknown');
          $latest=is_array($item['latest_global_run']??null)?(array)$item['latest_global_run']:null;
          $mode=(string)($item['mode']??'global_scan_only');
        ?>
          <tr>
            <td data-label="任务">
              <strong><?= e($item['label']??$item['task']??'') ?></strong>
              <small class="admin-table__secondary admin-mono"><?= e($item['task']??'') ?></small>
            </td>
            <td data-label="本实例期限">
              <span class="admin-badge <?= $deadlineStatus==='due'?'admin-badge--warning':($deadlineStatus==='pending'?'admin-badge--success':'') ?>">
                <?= e($deadlineStatus==='due'?'已到期':($deadlineStatus==='pending'?'未到期':'期限未知')) ?>
              </span>
              <small class="admin-table__secondary"><?= e($item['due_at']??'未记录') ?> UTC</small>
            </td>
            <td data-label="执行边界">
              <?php if($mode==='scoped_test_action'): ?>
                <span class="admin-badge admin-badge--success">实例级已支持</span>
                <small class="admin-table__secondary">复用真实流程中的“测量费冻结解冻”动作；只处理当前 test_instance。</small>
              <?php else: ?>
                <span class="admin-badge admin-badge--warning">仍为全库扫描</span>
              <?php endif; ?>
              <small class="admin-table__secondary"><?= e($item['note']??'') ?></small>
            </td>
            <td data-label="最近全局运行">
              <?php if($latest): ?>
                <span class="admin-badge <?= ($latest['status']??'')==='success'?'admin-badge--success':'admin-badge--warning' ?>"><?= e($latest['status']??'') ?></span>
                <small class="admin-table__secondary"><?= e($latest['started_at']??'') ?> UTC · 扫描 <?= (int)($latest['scanned']??0) ?> / 成功 <?= (int)($latest['success']??0) ?> / 失败 <?= (int)($latest['failure']??0) ?></small>
                <small class="admin-table__secondary">scope=global，不能作为当前实例通过证据。</small>
              <?php else: ?>
                <span class="admin-text-muted">暂无运行记录</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>
