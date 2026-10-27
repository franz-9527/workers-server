<?php
$allowed = array_values(array_map('strval', is_array($task['allowed_actions'] ?? null) ? $task['allowed_actions'] : []));
$canApprove = in_array('measurement_hold_approve', $allowed, true);
$canReject = in_array('measurement_hold_reject', $allowed, true);
?>
<div class="admin-page-head">
  <div>
    <h1>测量冻结延期审核</h1>
    <p>首次低风险小范围延期由系统自动批准；这里只处理重复、超出自动范围或存在退款/取消/争议风险的异常申请。</p>
  </div>
  <div class="admin-page-actions"><a class="admin-btn" href="<?= e(admin_url('dashboard',['scope'=>'exception'])) ?>">返回异常工作台</a></div>
</div>

<section class="admin-card">
  <div class="admin-card__header">
    <div><strong><?= e((string)($task['title'] ?? '测量冻结延期待审核')) ?></strong><small>订单 <?= e($orderNo) ?> · 申请 <?= e($requestNo) ?></small></div>
    <span class="admin-badge admin-badge--warning"><?= e((string)($task['priority'] ?? 'P1')) ?></span>
  </div>
  <div class="admin-card__body">
    <div class="admin-alert admin-alert--warning"><div class="admin-alert__body"><strong>申请内容</strong><p><?= e((string)($task['summary'] ?? '用户申请延长测量费冻结期。')) ?></p></div></div>
    <div class="admin-alert admin-alert--info admin-mt-2"><div class="admin-alert__body"><strong>服务端最终校验</strong><p>提交后会重新锁定延期申请和测量冻结状态；已解冻、已过期、状态变化或批准时间不能晚于当前截止时间时都会拒绝处理，批准时间也不会突破本单最长冻结期限。</p></div></div>
  </div>
</section>

<section class="admin-card admin-mt-3">
  <div class="admin-card__header"><div><strong>审核处理</strong><small>这里只决定本次冻结延期是否成立，不修改施工关联、退款或争议结果。</small></div></div>
  <div class="admin-card__body admin-grid admin-grid--2">
    <?php if ($canApprove): ?>
    <form method="post" action="<?= e(admin_url('measurement-hold-review.resolve')) ?>" class="admin-card admin-card--soft" data-loading>
      <?= csrf()->input() ?>
      <input type="hidden" name="order_id" value="<?= e($orderNo) ?>">
      <input type="hidden" name="request_id" value="<?= e($requestNo) ?>">
      <input type="hidden" name="decision" value="approved">
      <div class="admin-card__body">
        <div class="admin-field"><label class="admin-label">通过说明</label><textarea class="admin-control" name="note" rows="4" maxlength="500" placeholder="选填；系统会按申请时间与最长冻结期计算最终批准截止时间"></textarea></div>
        <button class="admin-btn admin-btn--primary admin-mt-2" type="submit" data-confirm="确认批准本次测量冻结延期？服务端会再次复核冻结状态和最长期限。">批准延期</button>
      </div>
    </form>
    <?php endif; ?>

    <?php if ($canReject): ?>
    <form method="post" action="<?= e(admin_url('measurement-hold-review.resolve')) ?>" class="admin-card admin-card--soft" data-loading>
      <?= csrf()->input() ?>
      <input type="hidden" name="order_id" value="<?= e($orderNo) ?>">
      <input type="hidden" name="request_id" value="<?= e($requestNo) ?>">
      <input type="hidden" name="decision" value="rejected">
      <div class="admin-card__body">
        <div class="admin-field"><label class="admin-label">驳回原因 <span class="admin-required">*</span></label><textarea class="admin-control" name="note" rows="4" minlength="2" maxlength="500" required placeholder="至少 2 个字，说明本次延期不予批准的原因"></textarea></div>
        <button class="admin-btn admin-btn--danger admin-mt-2" type="submit" data-confirm="确认驳回本次测量冻结延期申请？">驳回延期</button>
      </div>
    </form>
    <?php endif; ?>

    <?php if (!$canApprove && !$canReject): ?><div class="admin-empty">当前没有可执行的延期审核动作，请返回异常工作台刷新。</div><?php endif; ?>
  </div>
</section>
