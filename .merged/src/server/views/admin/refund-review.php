<?php
$allowed = array_values(array_map('strval', is_array($task['allowed_actions'] ?? null) ? $task['allowed_actions'] : []));
$canApprove = in_array('refund_approve', $allowed, true);
$canReject = in_array('refund_reject', $allowed, true);
$facts = is_array($task['facts'] ?? null) ? $task['facts'] : [];
$requestedCents = max(0, (int)($facts['requested_refund_cents'] ?? 0));
$requestedYuan = number_format($requestedCents / 100, 2, '.', '');
?>
<div class="admin-page-head">
  <div>
    <h1>取消退款审核</h1>
    <p>这里只处理系统无法自动判定的普通取消退款。双方已接受的退款协议、平台资金核账、退款执行失败分别走各自专项流程。</p>
  </div>
  <div class="admin-page-actions"><a class="admin-btn" href="<?= e(admin_url('dashboard',['scope'=>'refund_dispute'])) ?>">返回异常工作台</a></div>
</div>

<section class="admin-card">
  <div class="admin-card__header">
    <div><strong><?= e((string)($task['title'] ?? '退款申请待审核')) ?></strong><small>订单 <?= e($orderNo) ?> · 申请 <?= e($requestNo) ?></small></div>
    <span class="admin-badge admin-badge--danger"><?= e((string)($task['priority'] ?? 'P0')) ?></span>
  </div>
  <div class="admin-card__body">
    <div class="admin-alert admin-alert--warning"><div class="admin-alert__body"><strong>申请情况</strong><p><?= e((string)($task['summary'] ?? '用户申请取消并退款')) ?></p></div></div>
    <div class="admin-alert admin-alert--info admin-mt-2"><div class="admin-alert__body"><strong>资金边界</strong><p>页面金额只是申请时的默认值。提交后服务端会重新锁定订单、取消申请和原支付，扣除已有退款及已生效跑空费，再计算当前真正可退金额；超额不会通过。</p></div></div>
  </div>
</section>

<section class="admin-card admin-mt-3">
  <div class="admin-card__header"><div><strong>审核处理</strong><small>退款通过后只生成待审核退款单，不在本页直接调用微信退款。</small></div></div>
  <div class="admin-card__body">
    <?php if ($canApprove || $canReject): ?>
    <form method="post" action="<?= e(admin_url('refund-review.resolve')) ?>" class="admin-form-grid" data-loading>
      <?= csrf()->input() ?>
      <input type="hidden" name="order_id" value="<?= e($orderNo) ?>">
      <input type="hidden" name="request_id" value="<?= e($requestNo) ?>">

      <div class="admin-field">
        <label class="admin-label">退款金额（元）</label>
        <input class="admin-control" type="number" name="refund_amount" min="0" step="0.01" max="<?= e($requestedYuan) ?>" value="<?= e($requestedYuan) ?>">
        <small class="admin-help">申请金额 <?= e($requestedYuan) ?> 元；实际可退上限以提交时服务端原支付核算为准。拒绝退款时该金额会被忽略。</small>
      </div>

      <div class="admin-field">
        <label class="admin-label">师傅是否已经上门</label>
        <select class="admin-control" name="worker_arrived">
          <option value="no">未上门 / 不适用</option>
          <option value="yes">已经上门</option>
        </select>
        <small class="admin-help">如果订单实际上没有成交师傅或未进入履约，服务端会自动按“未上门”处理。</small>
      </div>

      <div class="admin-field">
        <label class="admin-label">责任归属</label>
        <select class="admin-control" name="responsibility">
          <option value="unclear">待定</option>
          <option value="user">用户</option>
          <option value="worker">师傅</option>
          <option value="platform">平台</option>
          <option value="shared">双方</option>
        </select>
        <small class="admin-help">师傅已上门且并非师傅责任时，不能直接批准退款，应拒绝并记录原因。</small>
      </div>

      <div class="admin-field admin-field--full">
        <label class="admin-label">审核说明 <span class="admin-required">*</span></label>
        <textarea class="admin-control" name="reason" rows="4" minlength="2" maxlength="1000" required placeholder="说明退款/拒绝依据，至少 2 个字"></textarea>
      </div>

      <div class="admin-field admin-field--full">
        <div class="admin-page-actions">
          <?php if ($canApprove): ?><button class="admin-btn admin-btn--primary" type="submit" name="decision" value="approve" data-confirm="确认通过本次取消退款申请？服务端将按当前真实原支付重新计算并拆分退款。">通过退款</button><?php endif; ?>
          <?php if ($canReject): ?><button class="admin-btn admin-btn--danger" type="submit" name="decision" value="reject" data-confirm="确认拒绝本次取消退款申请？订单将按申请前快照恢复可恢复的发布/退款状态。">拒绝退款</button><?php endif; ?>
        </div>
      </div>
    </form>
    <?php else: ?>
    <div class="admin-empty">当前没有可执行的退款审核动作，请返回异常工作台刷新。</div>
    <?php endif; ?>
  </div>
</section>
