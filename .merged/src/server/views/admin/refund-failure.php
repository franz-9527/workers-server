<div class="admin-page-head">
  <div>
    <h1>失败退款详情</h1>
    <p>核对原退款单的微信真实状态；ABNORMAL 处理原单，只有 CLOSED 终态才允许按原金额生成新退款单。</p>
  </div>
  <div class="admin-page-actions">
    <a class="admin-btn" href="<?= e(admin_url('refunds.failures')) ?>"><i class="ri-arrow-left-line"></i> 返回失败退款</a>
    <a class="admin-btn" href="<?= e(admin_url('refunds')) ?>">退款管理</a>
  </div>
</div>

<?php if (($refund['recovery_kind'] ?? '') === 'abnormal'): ?>
<div class="admin-alert admin-alert--warning admin-mb-3">
  <strong>微信原路退款异常（ABNORMAL）</strong>
  <div>不要生成新的普通退款单。请先在微信支付商户平台按异常退款流程处理原退款单；平台后台不收集银行卡信息。处理后回到本页点击“重新查询微信状态”。</div>
</div>
<?php elseif (($refund['recovery_kind'] ?? '') === 'closed'): ?>
<div class="admin-alert admin-alert--warning admin-mb-3">
  <strong>微信退款已关闭（CLOSED）</strong>
  <div>CLOSED 是原退款单终态。若仍需退款，请先补足微信支付出资账户余额，再由本页重新核对微信仍为 CLOSED，并按原支付、原金额生成一个新的商户退款单号。新单仍需回“退款管理”人工确认后提交微信。</div>
</div>
<?php else: ?>
<div class="admin-alert admin-alert--info admin-mb-3">
  <strong>先重新查询微信状态</strong>
  <div>当前失败原因未归类。在微信状态确认前，不要手工制造第二笔普通退款。</div>
</div>
<?php endif; ?>

<section class="admin-card admin-mb-3">
  <div class="admin-card__header"><strong>退款事实</strong></div>
  <div class="admin-card__body">
    <div class="admin-form-grid">
      <div class="admin-field"><label>退款单号</label><div class="admin-control admin-control--readonly admin-mono"><?= e($refund['refund_no']) ?></div></div>
      <div class="admin-field"><label>订单号</label><div class="admin-control admin-control--readonly admin-mono"><?= e($refund['order_no'] ?: '—') ?></div></div>
      <div class="admin-field"><label>退款金额</label><div class="admin-control admin-control--readonly"><?= e(money((int)$refund['amount_cents'])) ?></div></div>
      <div class="admin-field"><label>支付接口</label><div class="admin-control admin-control--readonly"><?= e(strtoupper((string)$refund['api_version'])) ?></div></div>
      <div class="admin-field"><label>微信退款号</label><div class="admin-control admin-control--readonly admin-mono"><?= e($refund['wechat_refund_id'] ?: '—') ?></div></div>
      <div class="admin-field"><label>失败代码</label><div class="admin-control admin-control--readonly"><?= e($refund['failure_code'] ?: '待确认') ?></div></div>
      <div class="admin-field admin-field--full"><label>退款原因</label><div class="admin-control admin-control--readonly"><?= e($refund['reason'] ?: '—') ?></div></div>
      <div class="admin-field admin-field--full"><label>失败说明</label><div class="admin-control admin-control--readonly"><?= e($refund['failure_message'] ?: '尚无详细失败说明，请重新查询微信真实状态。') ?></div></div>
    </div>
  </div>
</section>

<section class="admin-card">
  <div class="admin-card__header">
    <div><strong>恢复核对</strong><small><?= e($refund['recovery_hint']) ?></small></div>
  </div>
  <div class="admin-card__body">
    <form method="post" action="<?= e(admin_url('refunds.failure.recheck')) ?>">
      <?= csrf()->input() ?>
      <input type="hidden" name="id" value="<?= (int)$refund['id'] ?>">
      <p class="admin-text-muted">查询结果为 SUCCESS / PROCESSING 时，仅恢复为“退款中”，再交给原有统一退款查单任务完成最终核销；ABNORMAL / CLOSED 会继续保持失败。</p>
      <button class="admin-btn admin-btn--primary" type="submit" data-confirm="确认只查询原退款单的微信真实状态？本操作不会重新发起退款。"><i class="ri-refresh-line"></i> 重新查询微信状态</button>
    </form>

    <?php if (($refund['recovery_kind'] ?? '') === 'closed'): ?>
      <hr class="admin-divider">
      <form method="post" action="<?= e(admin_url('refunds.failure.replace-closed')) ?>">
        <?= csrf()->input() ?>
        <input type="hidden" name="id" value="<?= (int)$refund['id'] ?>">
        <p class="admin-text-muted">仅当微信再次确认原退款单仍为 CLOSED 时执行。系统会再次核对商户号、原支付单、原支付总额、当前可退余额及是否已有替代退款单；新单金额固定为 <?= e(money((int)$refund['amount_cents'])) ?>，不可修改。</p>
        <button class="admin-btn admin-btn--warning" type="submit" data-confirm="请确认已补足微信支付出资账户余额。系统将重新查询微信 CLOSED 状态，并按原金额生成新的待提交退款单；确认继续？"><i class="ri-file-add-line"></i> 已补足余额，生成新的退款单</button>
      </form>
    <?php endif; ?>
  </div>
</section>