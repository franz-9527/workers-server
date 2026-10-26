<?php
$scopeLabels = [
    'exception'=>'全部异常',
    'publish'=>'自动发布',
    'refund_dispute'=>'退款/争议',
    'finance'=>'资金异常',
    'income_verification'=>'线下收入核实',
    'privacy'=>'电话公开',
];
$scopeCounts = [
    'exception'=>(int)($counts['exception_orders'] ?? 0),
    'publish'=>(int)($counts['auto_publish_orders'] ?? 0),
    'refund_dispute'=>(int)($counts['refund_dispute_orders'] ?? 0),
    'finance'=>(int)($counts['finance_orders'] ?? 0),
    'income_verification'=>(int)($counts['income_verification_orders'] ?? 0),
    'privacy'=>(int)($counts['privacy_orders'] ?? 0),
];
?>
<div class="admin-page-head">
  <div>
    <h1>异常工作台</h1>
    <p>正常订单由系统自动审核、自动发布并按业务流程推进；这里只显示真正需要管理员介入的异常。</p>
  </div>
  <div class="admin-page-actions">
    <a class="admin-btn" href="<?= e(admin_url('orders')) ?>"><i class="ri-file-list-3-line"></i> 全部订单</a>
    <a class="admin-btn" href="<?= e(admin_url('refunds')) ?>"><i class="ri-refund-2-line"></i> 退款管理</a>
  </div>
</div>

<?php if (!empty($demoDataEnabled)): ?>
<section class="admin-alert admin-alert--warning admin-mb-3">
  <div class="admin-alert__body">
    <strong>当前异常工作台包含演示业务</strong>
    <p>关闭 <span class="admin-mono">demo.data_enabled</span> 后，统一异常读模型会自动排除演示订单。</p>
  </div>
</section>
<?php endif; ?>

<div class="admin-stats">
  <?php foreach ($scopeLabels as $scopeKey=>$label): ?>
    <?php $active = $scope === $scopeKey; ?>
    <a class="admin-stat<?= $active?' admin-stat--active':'' ?>" href="<?= e(admin_url('dashboard',['scope'=>$scopeKey])) ?>">
      <div class="admin-stat__head"><span><?= e($label) ?></span><span class="admin-stat__icon"><i class="<?= $scopeKey==='publish'?'ri-send-plane-line':($scopeKey==='refund_dispute'?'ri-scales-3-line':($scopeKey==='finance'?'ri-money-cny-circle-line':($scopeKey==='income_verification'?'ri-bank-card-line':($scopeKey==='privacy'?'ri-phone-line':'ri-alarm-warning-line')))) ?>"></i></span></div>
      <div class="admin-stat__value"><?= number_format($scopeCounts[$scopeKey]) ?></div>
      <div class="admin-stat__meta"><?= $scopeKey==='exception'?'所有需管理员介入的订单':($scopeKey==='publish'?'自动化发布失败或超时':($scopeKey==='refund_dispute'?'退款申请与未结争议':($scopeKey==='finance'?'退款执行、资金状态等风险':($scopeKey==='income_verification'?'仅核对真实线下到账':'满足条件后的隐私例外审批')))) ?></div>
    </a>
  <?php endforeach; ?>
</div>

<section class="admin-card admin-mt-3">
  <div class="admin-card__header">
    <div><strong><?= e($scopeLabels[$scope] ?? '异常订单') ?></strong><small>按优先级与等待时间排序；正常审核、正常派单、测量核价不进入这里。</small></div>
    <span class="admin-badge admin-badge--info">共 <?= number_format((int)($result['pagination']['total'] ?? 0)) ?> 笔</span>
  </div>
  <div class="admin-card__body">
    <form class="admin-filter" method="get" action="<?= e(app_url('admin/')) ?>">
      <input type="hidden" name="route" value="dashboard">
      <input type="hidden" name="scope" value="<?= e($scope) ?>">
      <div class="admin-field admin-field--search"><label class="admin-label">搜索异常订单</label><input class="admin-control" name="q" value="<?= e($search) ?>" placeholder="订单号 / 标题 / 类目"></div>
      <div class="admin-filter__actions"><button class="admin-btn admin-btn--primary">查询</button><a class="admin-btn" href="<?= e(admin_url('dashboard',['scope'=>$scope])) ?>">重置</a></div>
    </form>
  </div>
  <div class="admin-table-wrap">
    <table class="admin-table admin-table--responsive">
      <thead><tr><th>优先级</th><th>订单</th><th>异常</th><th>区域</th><th>等待</th><th>操作</th></tr></thead>
      <tbody>
      <?php foreach (($result['items'] ?? []) as $item): ?>
        <?php
          $primary = is_array($item['primary_task'] ?? null) ? $item['primary_task'] : [];
          $priority = (string)($item['highest_priority'] ?? 'P2');
          $priorityTone = $priority === 'P0' ? 'danger' : ($priority === 'P1' ? 'warning' : 'info');
          $waiting = (int)($item['waiting_seconds'] ?? 0);
          if ($waiting >= 86400) $waitingText = floor($waiting/86400).' 天';
          elseif ($waiting >= 3600) $waitingText = floor($waiting/3600).' 小时';
          elseif ($waiting >= 60) $waitingText = floor($waiting/60).' 分钟';
          else $waitingText = '刚刚';
          $taskType = (string)($primary['task_type'] ?? '');
          $sourceType = (string)($primary['source_type'] ?? '');
          $actionLabel = '查看订单';
          if (in_array($taskType, ['platform_auto_publish_failed','platform_auto_publish_stuck'], true)) {
              $actionUrl = admin_url('platform-orders');
              $actionLabel = '修复发布';
          } elseif (in_array($taskType, ['platform_finance_risk','platform_refund_finance_review','platform_refund_recovery_verification','platform_income_verification'], true)) {
              $actionUrl = admin_url('platform-order-finance');
              $actionLabel = '财务核账';
          } elseif ($taskType === 'refund_execution_attention' && $sourceType === 'cancel_request') {
              $actionUrl = admin_url('refunds.execution');
              $actionLabel = '执行核账';
          } elseif ($taskType === 'refund_execution_attention' && $sourceType === 'refund') {
              // failed 退款并不都属于取消退款；专项恢复页只支持有 cancel_request_id 的原路退款。
              // 通用退款页后端会真正按订单号 q 过滤，可直接定位当前订单的退款记录。
              $actionUrl = admin_url('refunds',['q'=>$item['public_no']]);
              $actionLabel = '查看退款';
          } elseif ($taskType === 'after_sale_intervention') {
              $actionUrl = admin_url('repair-intervention', [
                  'order_id'=>$item['public_no'],
                  'after_sale_id'=>(string)($primary['source_id'] ?? ''),
              ]);
              $actionLabel = '平台裁定';
          } elseif ($taskType === 'dispute_open') {
              $actionUrl = admin_url('dispute-review', [
                  'order_id'=>$item['public_no'],
                  'dispute_id'=>(string)($primary['source_id'] ?? ''),
              ]);
              $actionLabel = '裁定争议';
          } elseif ($taskType === 'refund_review') {
              $actionUrl = admin_url('refund-review', [
                  'order_id'=>$item['public_no'],
                  'request_id'=>(string)($primary['source_id'] ?? ''),
              ]);
              $actionLabel = '审核退款';
          } elseif ($taskType === 'contact_reveal_review') {
              $actionUrl = admin_url('contact-reveal-review', [
                  'order_id'=>$item['public_no'],
                  'request_id'=>(string)($primary['source_id'] ?? ''),
              ]);
              $actionLabel = '审核电话公开';
          } elseif ($taskType === 'measurement_hold_review') {
              $actionUrl = admin_url('measurement-hold-review', [
                  'order_id'=>$item['public_no'],
                  'request_id'=>(string)($primary['source_id'] ?? ''),
              ]);
              $actionLabel = '审核延期';
          } else {
              $actionUrl = admin_url('orders',['q'=>$item['public_no']]);
          }
        ?>
        <tr>
          <td data-label="优先级"><span class="admin-badge admin-badge--<?= e($priorityTone) ?>"><?= e($priority) ?></span></td>
          <td data-label="订单"><a class="admin-table__primary" href="<?= e(admin_url('orders',['q'=>$item['public_no']])) ?>"><?= e($item['public_no']) ?></a><small class="admin-table__secondary"><?= e(($item['party_name'] ?: '用户').' · '.($item['category_name'] ?: '未确定类目')) ?></small></td>
          <td data-label="异常"><strong><?= e($item['phase_text'] ?: '异常待处理') ?></strong><small class="admin-table__secondary"><?= e((string)($primary['summary'] ?? '')) ?></small><?php if ((int)($item['task_count'] ?? 0) > 1): ?><span class="admin-badge admin-badge--muted">同单 <?= (int)$item['task_count'] ?> 项</span><?php endif; ?></td>
          <td data-label="区域"><?= e(trim((string)$item['city'].' '.(string)$item['district']) ?: '—') ?></td>
          <td data-label="等待" class="admin-nowrap"><?= e($waitingText) ?></td>
          <td data-label="操作"><a class="admin-btn admin-btn--sm admin-btn--primary" href="<?= e($actionUrl) ?>"><?= e($actionLabel) ?></a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($result['items'])): ?><tr><td colspan="6"><div class="admin-empty"><div class="admin-empty__icon"><i class="ri-checkbox-circle-line"></i></div><h3>当前没有需要管理员处理的异常</h3><p>正常订单会继续由自动化流程推进。</p></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if (!empty($result['pagination']['total_pages']) && (int)$result['pagination']['total_pages'] > 1): ?>
  <div class="admin-card__footer">
    <?php
      $pagination = $result['pagination'];
      $currentPage = (int)$pagination['page'];
      $totalPages = (int)$pagination['total_pages'];
    ?>
    <div class="admin-pagination">
      <?php if ($currentPage > 1): ?><a class="admin-btn admin-btn--sm" href="<?= e(admin_url('dashboard',['scope'=>$scope,'q'=>$search,'page'=>$currentPage-1])) ?>">上一页</a><?php endif; ?>
      <span class="admin-text-muted">第 <?= $currentPage ?> / <?= $totalPages ?> 页</span>
      <?php if ($currentPage < $totalPages): ?><a class="admin-btn admin-btn--sm" href="<?= e(admin_url('dashboard',['scope'=>$scope,'q'=>$search,'page'=>$currentPage+1])) ?>">下一页</a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</section>
