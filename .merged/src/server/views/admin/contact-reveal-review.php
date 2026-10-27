<?php
$allowed = array_values(array_map('strval', is_array($task['allowed_actions'] ?? null) ? $task['allowed_actions'] : []));
$canApprove = in_array('contact_reveal_approve', $allowed, true);
$canReject = in_array('contact_reveal_reject', $allowed, true);
?>
<div class="admin-page-head">
  <div>
    <h1>电话公开审核</h1>
    <p>仅处理自动化无法安全判定的隐私例外。批准前服务端会再次校验成交师傅、履约状态、维修状态和师傅是否已经响应。</p>
  </div>
  <div class="admin-page-actions"><a class="admin-btn" href="<?= e(admin_url('dashboard',['scope'=>'privacy'])) ?>">返回异常工作台</a></div>
</div>

<section class="admin-card">
  <div class="admin-card__header">
    <div><strong><?= e((string)($task['title'] ?? '师傅电话公开申请')) ?></strong><small>订单 <?= e($orderNo) ?> · 申请 <?= e($requestNo) ?></small></div>
    <span class="admin-badge admin-badge--danger"><?= e((string)($task['priority'] ?? 'P0')) ?></span>
  </div>
  <div class="admin-card__body">
    <div class="admin-alert admin-alert--warning"><div class="admin-alert__body"><strong>当前原因</strong><p><?= e((string)($task['summary'] ?? '请核实当前履约场景后处理。')) ?></p></div></div>
    <div class="admin-alert admin-alert--info admin-mt-2"><div class="admin-alert__body"><strong>隐私边界</strong><p>手机号仅用于当前履约或维修联系。商家订单、师傅已响应、成交师傅已变更、施工或维修已结束等情况会由服务端拒绝或自动取消本申请。</p></div></div>
  </div>
</section>

<section class="admin-card admin-mt-3">
  <div class="admin-card__header"><div><strong>审核处理</strong><small>正常客观场景由系统自动通过；进入这里的只应是异常或边界场景。</small></div></div>
  <div class="admin-card__body admin-grid admin-grid--2">
    <?php if ($canApprove): ?>
    <form method="post" action="<?= e(admin_url('contact-reveal-review.resolve')) ?>" class="admin-card admin-card--soft" data-loading>
      <?= csrf()->input() ?>
      <input type="hidden" name="request_id" value="<?= e($requestNo) ?>">
      <input type="hidden" name="decision" value="approve">
      <div class="admin-card__body">
        <div class="admin-field"><label class="admin-label">通过说明</label><textarea class="admin-control" name="note" rows="4" maxlength="500" placeholder="选填；服务端仍会在提交时重新校验资格"></textarea></div>
        <button class="admin-btn admin-btn--primary admin-mt-2" type="submit" data-confirm="确认公开当前成交师傅电话？仅当前履约场景生效。">批准公开</button>
      </div>
    </form>
    <?php endif; ?>

    <?php if ($canReject): ?>
    <form method="post" action="<?= e(admin_url('contact-reveal-review.resolve')) ?>" class="admin-card admin-card--soft" data-loading>
      <?= csrf()->input() ?>
      <input type="hidden" name="request_id" value="<?= e($requestNo) ?>">
      <input type="hidden" name="decision" value="reject">
      <div class="admin-card__body">
        <div class="admin-field"><label class="admin-label">拒绝原因 <span class="admin-required">*</span></label><textarea class="admin-control" name="note" rows="4" minlength="2" maxlength="500" required placeholder="至少 2 个字，说明为什么不能公开"></textarea></div>
        <button class="admin-btn admin-btn--danger admin-mt-2" type="submit" data-confirm="确认拒绝本次电话公开申请？">拒绝公开</button>
      </div>
    </form>
    <?php endif; ?>

    <?php if (!$canApprove && !$canReject): ?><div class="admin-empty">当前没有可执行的电话公开审核动作，请返回异常工作台刷新。</div><?php endif; ?>
  </div>
</section>
