<?php
$scheduler=(array)($detail['scheduler']??[]);
$schedulerItems=(array)($scheduler['items']??[]);
$actionRequestKeys=is_array($actionRequestKeys??null)?$actionRequestKeys:[];
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
          $actionKey=(string)($item['action_key']??'');
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
                <small class="admin-table__secondary"><?= e($item['note']??'') ?></small>
                <?php if(in_array($actionKey,['system_assignment_timeout','system_auto_accept_completion','system_platform_auto_accept','system_measurement_accept','system_auto_review','system_auto_cancel'],true)&&!empty($actionRequestKeys[$actionKey])): ?>
                  <?php
                    $isAssignmentTimeout=$actionKey==='system_assignment_timeout';
                    $isMeasurementAccept=$actionKey==='system_measurement_accept';
                    $isAutoReview=$actionKey==='system_auto_review';
                    $isAutoCancel=$actionKey==='system_auto_cancel';
                    $isAutoAccept=in_array($actionKey,['system_auto_accept_completion','system_platform_auto_accept'],true);
                    $buttonText=$isAssignmentTimeout?'推进到超时并执行':($isMeasurementAccept?'推进到测量自动确认':($isAutoReview?'推进到自动评价':($isAutoCancel?'推进到自动取消':'推进到自动验收')));
                    $confirmText=$isAssignmentTimeout
                      ?'确认把当前测试 assignment 的时钟推进到截止时间后一秒，并执行正式单笔超时处理？不会扫描其它订单。'
                      :($isMeasurementAccept
                        ?'确认把当前测试测量成果时钟推进到 auto_accept_at 后一秒，并执行正式单订单自动确认？只处理当前实例，不会扫描其它订单。'
                        :($isAutoReview
                          ?'确认把当前测试订单时钟推进到 auto_review_at 后一秒，并生成系统默认五星评价？只处理当前实例，不会扫描其它订单。'
                          :($isAutoCancel
                            ?'确认把当前测试订单时钟推进到 quote_deadline_at 后一秒并自动取消？TEST_INTERNAL 预约费只登记 pending_review 退款，不会向微信提交，也不会执行全库报价预占释放。'
                            :'确认补齐 testing 收款凭证夹具，并把当前测试订单时钟推进到自动验收期限后一秒？只处理当前实例，不会扫描其它订单。')));
                  ?>
                  <form class="admin-mt-2" method="post" action="<?= e(admin_url('test-center.action')) ?>" data-loading>
                    <?= csrf()->input() ?>
                    <input type="hidden" name="id" value="<?= e($instance['public_id']??'') ?>">
                    <input type="hidden" name="action_key" value="<?= e($actionKey) ?>">
                    <input type="hidden" name="request_key" value="<?= e($actionRequestKeys[$actionKey]) ?>">
                    <button class="admin-btn admin-btn--primary" type="submit" data-confirm="<?= e($confirmText) ?>"><i class="<?= ($isAutoAccept||$isMeasurementAccept||$isAutoReview||$isAutoCancel)?'ri-checkbox-circle-line':'ri-timer-line' ?>"></i> <?= e($buttonText) ?></button>
                  </form>
                <?php elseif($actionKey==='release_measurement_income'): ?>
                  <small class="admin-table__secondary">执行入口使用上方普通测量真实流程中的“测量费冻结解冻”，这里不重复提供按钮。</small>
                <?php endif; ?>
              <?php else: ?>
                <span class="admin-badge admin-badge--warning">仍为全库扫描</span>
                <small class="admin-table__secondary"><?= e($item['note']??'') ?></small>
              <?php endif; ?>
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
