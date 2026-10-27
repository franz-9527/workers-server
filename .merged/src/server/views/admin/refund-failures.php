<div class="admin-page-head">
  <div>
    <h1>失败退款处理</h1>
    <p>这里只处理取消订单的原路退款异常；不会重新发起普通退款，也不收集银行卡等额外敏感信息。</p>
  </div>
  <div class="admin-page-actions">
    <a class="admin-btn" href="<?= e(admin_url('refunds')) ?>"><i class="ri-arrow-left-line"></i> 返回退款管理</a>
    <span class="admin-badge admin-badge--warning">待处理 <?= count($cases) ?> 笔</span>
  </div>
</div>

<div class="admin-alert admin-alert--warning admin-mb-3">
  <strong>先确认微信真实状态，不要重复退款</strong>
  <div><code>ABNORMAL</code> 与 <code>CLOSED</code> 都不会在本系统直接“重试普通退款”。请进入详情按微信真实状态处理；若微信后来恢复为处理中/成功，系统只会恢复查单链，由原有退款任务继续收口。</div>
</div>

<section class="admin-card">
  <div class="admin-card__header">
    <div>
      <strong>取消订单失败退款（<?= count($cases) ?>）</strong>
      <small>钱包充值纠错、测量迟到退款等特殊退款不进入本页，继续走各自专用流程。</small>
    </div>
  </div>
  <div class="admin-table-wrap">
    <table class="admin-table admin-table--responsive">
      <thead><tr><th>退款单 / 订单</th><th>金额</th><th>失败状态</th><th>处理提示</th><th>更新时间</th><th>操作</th></tr></thead>
      <tbody>
      <?php if (empty($cases)): ?>
        <tr><td colspan="6" class="admin-empty">暂无取消订单退款失败记录</td></tr>
      <?php endif; ?>
      <?php foreach ($cases as $row): ?>
        <tr>
          <td data-label="退款单 / 订单">
            <strong class="admin-mono"><?= e($row['refund_no']) ?></strong>
            <small class="admin-table__secondary">订单 <?= e($row['order_no'] ?: '—') ?></small>
            <?php if (!empty($row['wechat_refund_id'])): ?><small class="admin-table__secondary">微信退款号 <?= e($row['wechat_refund_id']) ?></small><?php endif; ?>
          </td>
          <td data-label="金额" class="admin-money"><?= e(money((int)$row['amount_cents'])) ?></td>
          <td data-label="失败状态">
            <span class="admin-badge admin-badge--warning"><?= e($row['failure_code'] ?: '待确认') ?></span>
            <?php if (!empty($row['failure_message'])): ?><small class="admin-table__secondary"><?= e($row['failure_message']) ?></small><?php endif; ?>
          </td>
          <td data-label="处理提示"><small><?= e($row['recovery_hint']) ?></small></td>
          <td data-label="更新时间" class="admin-nowrap"><?= e(local_datetime($row['updated_at'])) ?></td>
          <td data-label="操作"><a class="admin-btn admin-btn--sm admin-btn--primary" href="<?= e(admin_url('refunds.failure',['id'=>$row['id']])) ?>"><i class="ri-search-eye-line"></i> 查看并核对</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
