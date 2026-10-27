<div class="admin-page-head">
  <div>
    <h1>退款执行核账</h1>
    <p>这里只处理“双方已经接受退款方案、但自动执行被资金事实阻断”的订单；不重新判断是否同意退款。</p>
  </div>
  <div class="admin-page-actions">
    <a class="admin-btn" href="<?= e(admin_url('refunds')) ?>"><i class="ri-arrow-left-line"></i> 返回退款管理</a>
  </div>
</div>

<div class="admin-alert admin-alert--info admin-mb-3">
  <strong>协商结果与资金执行分开</strong>
  <div>本页只按双方已经同意的金额核对真实原支付。金额与当前可退余额完全一致、且争议/跑空费等事实已经处理后，系统按原支付生成退款单并进入自动原路退款执行；本次核账就是最后一次人工确认，无需再回“退款管理”重复审核。不会自动扣减师傅钱包，也不会伪造线下退款。</div>
</div>

<section class="admin-card">
  <div class="admin-card__header">
    <div>
      <strong>双方协议待执行（<?= count($cases) ?>）</strong>
      <small>若当前真实可退金额已变化，必须先查清历史退款、跑空费、争议或资金台账，平台不能单方面修改双方协议金额。</small>
    </div>
  </div>
  <div class="admin-table-wrap">
    <table class="admin-table admin-table--responsive">
      <thead><tr><th>订单 / 协议</th><th>协议退款</th><th>当前可原路退</th><th>核账状态</th><th>操作</th></tr></thead>
      <tbody>
      <?php if (empty($cases)): ?>
        <tr><td colspan="5" class="admin-empty">暂无双方已同意但仍需财务执行的退款</td></tr>
      <?php endif; ?>
      <?php foreach ($cases as $row): ?>
        <tr>
          <td data-label="订单">
            <strong class="admin-mono"><?= e($row['order_no']) ?></strong>
            <small class="admin-table__secondary"><?= e($row['title'] ?: '订单') ?> · <?= $row['order_source_type']==='platform'?'平台承接单':($row['order_source_type']==='merchant'?'商家单':'普通订单') ?></small>
            <small class="admin-table__secondary">协议 <?= e($row['request_id']) ?> · <?= e(local_datetime($row['updated_at'])) ?></small>
          </td>
          <td data-label="协议退款" class="admin-money"><?= e(money((int)$row['requested_refund_cents'])) ?></td>
          <td data-label="当前可退">
            <span class="admin-money"><?= e(money((int)$row['current_refundable_cents'])) ?></span>
            <small class="admin-table__secondary"><?= (int)$row['payment_count'] ?> 笔真实原支付可参与退款</small>
          </td>
          <td data-label="状态">
            <?php if (!empty($row['can_prepare'])): ?>
              <span class="admin-badge admin-badge--info">金额与事实一致，可执行退款</span>
              <small class="admin-table__secondary">确认核账后自动进入原路退款，无需二次审核。</small>
            <?php else: ?>
              <span class="admin-badge admin-badge--warning">需要先核清资金事实</span>
              <small class="admin-table__secondary"><?= e($row['blocking_reason'] ?: '当前事实不满足安全执行条件') ?></small>
            <?php endif; ?>
            <?php if (!empty($row['reason'])): ?><small class="admin-table__secondary">双方方案：<?= e($row['reason']) ?></small><?php endif; ?>
          </td>
          <td data-label="操作">
            <?php if (!empty($row['can_prepare'])): ?>
              <details class="admin-disclosure">
                <summary class="admin-btn admin-btn--sm admin-btn--primary">核账并执行退款</summary>
                <div class="admin-disclosure__panel">
                  <form method="post" class="admin-form" action="<?= e(admin_url('refunds.execution.prepare')) ?>">
                    <?= csrf()->input() ?>
                    <input type="hidden" name="request_id" value="<?= e($row['request_id']) ?>">
                    <div class="admin-field">
                      <label>核账说明</label>
                      <textarea class="admin-control" name="note" rows="3" minlength="4" maxlength="1000" required placeholder="例如：已核对微信原支付、历史退款和跑空费，当前可退金额与双方协议一致"></textarea>
                      <small>这里只记录资金核对依据，不允许修改协议退款金额。</small>
                    </div>
                    <button class="admin-btn admin-btn--primary" type="submit" data-confirm="确认当前真实可退金额与双方协议完全一致，并按原支付进入自动退款执行？">确认核账并执行退款</button>
                  </form>
                </div>
              </details>
            <?php else: ?>
              <a class="admin-btn admin-btn--sm" href="<?= e(admin_url('orders.view',['id'=>$row['order_id']])) ?>">查看订单事实</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
