<?php
$facts = is_array($task['facts'] ?? null) ? $task['facts'] : [];
$refundCents = max(0, (int)($facts['refund_amount_cents'] ?? 0));
$runEmptyCents = max(0, (int)($facts['run_empty_fee_cents'] ?? 0));
?>
<div class="admin-page-head">
  <div>
    <h1>争议裁定</h1>
    <p>双方协商未解决后才进入这里。责任、退款和跑空费一次完成；提交时服务端会重新锁定订单、争议、原支付及已分配资金。</p>
  </div>
  <div class="admin-page-actions"><a class="admin-btn" href="<?= e(admin_url('dashboard',['scope'=>'refund_dispute'])) ?>">返回异常工作台</a></div>
</div>

<section class="admin-card">
  <div class="admin-card__header">
    <div><strong><?= e((string)($task['title'] ?? '订单争议待处理')) ?></strong><small>订单 <?= e($orderNo) ?> · 争议 <?= e($disputeNo) ?></small></div>
    <span class="admin-badge admin-badge--danger"><?= e((string)($task['priority'] ?? 'P0')) ?></span>
  </div>
  <div class="admin-card__body">
    <div class="admin-alert admin-alert--warning"><div class="admin-alert__body"><strong>争议说明</strong><p><?= e((string)($task['summary'] ?? '请结合双方记录和证据裁定。')) ?></p></div></div>
    <div class="admin-alert admin-alert--info admin-mt-2"><div class="admin-alert__body"><strong>资金边界</strong><p>退款 + 跑空费不能超过本单实际支付且尚未占用的预约费。任一金额大于 0 都代表本次履约终止；未分配且尚未占用的预约费会自动补入退款，避免资金悬空。</p></div></div>
  </div>
</section>

<section class="admin-card admin-mt-3">
  <div class="admin-card__header"><div><strong>平台裁定</strong><small>只做责任和金额决策，不手工修改订单状态；订单、退款、余额和风控由同一事务自动处理。</small></div></div>
  <div class="admin-card__body">
    <form method="post" action="<?= e(admin_url('dispute-review.resolve')) ?>" class="admin-form-grid" data-loading>
      <?= csrf()->input() ?>
      <input type="hidden" name="order_id" value="<?= e($orderNo) ?>">
      <input type="hidden" name="dispute_id" value="<?= e($disputeNo) ?>">

      <div class="admin-field">
        <label class="admin-label">责任归属</label>
        <select class="admin-control" name="responsibility" required>
          <option value="user">用户责任</option>
          <option value="worker">师傅责任</option>
          <option value="platform">平台责任</option>
          <option value="shared">双方责任</option>
          <option value="unclear" selected>责任不清</option>
        </select>
      </div>
      <div class="admin-field">
        <label class="admin-label">预约费退款（元）</label>
        <input class="admin-control" name="refund_amount" type="number" min="0" step="0.01" value="<?= e(number_format($refundCents/100,2,'.','')) ?>">
        <p class="admin-help">填 0 表示本次不主动判退款；若同时判跑空费，剩余未分配预约费仍会自动退回用户。</p>
      </div>
      <div class="admin-field">
        <label class="admin-label">师傅跑空费（元）</label>
        <input class="admin-control" name="runner_amount" type="number" min="0" step="0.01" value="<?= e(number_format($runEmptyCents/100,2,'.','')) ?>">
        <p class="admin-help">裁定成功后直接计入当前成交师傅可用余额；服务端会校验师傅关系和预约费剩余额度。</p>
      </div>
      <div class="admin-field admin-field--full">
        <label class="admin-label">裁定说明 <span class="admin-required">*</span></label>
        <textarea class="admin-control" name="resolution" rows="5" minlength="2" maxlength="1000" required placeholder="记录双方证据、沟通结论、责任判断及金额依据"></textarea>
      </div>
      <div class="admin-field admin-field--full">
        <button class="admin-btn admin-btn--primary" type="submit" data-confirm="确认提交本次平台裁定？金额大于 0 时将终止本次履约并按资金规则处理。" data-confirm-type="primary" data-loading-text="正在裁定…">确认裁定</button>
      </div>
    </form>
  </div>
</section>