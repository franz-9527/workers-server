<?php
$allowed = array_values(array_map('strval', is_array($task['allowed_actions'] ?? null) ? $task['allowed_actions'] : []));
$actions = [
    'repair_platform_continue'=>['decision'=>'continue','label'=>'要求继续维修','tone'=>'primary','confirm'=>'确认要求师傅继续维修？售后仍保持处理中，不涉及资金处理。'],
    'repair_platform_resolve'=>['decision'=>'resolved','label'=>'确认已解决','tone'=>'primary','confirm'=>'确认由平台判定本次售后已解决？如涉及退款或赔付，请先走独立退款/争议流程。'],
    'repair_platform_close'=>['decision'=>'closed','label'=>'关闭售后','tone'=>'danger','confirm'=>'确认关闭本次售后？此操作只结束售后状态，不处理退款或赔付。'],
];
?>
<div class="admin-page-head">
  <div>
    <h1>售后平台介入</h1>
    <p>双方先自行处理；只有已经进入平台介入的维修售后才允许在此裁定。资金事项继续走退款/争议流程。</p>
  </div>
  <div class="admin-page-actions"><a class="admin-btn" href="<?= e(admin_url('dashboard')) ?>">返回异常工作台</a></div>
</div>

<section class="admin-card">
  <div class="admin-card__header"><div><strong><?= e((string)($task['title'] ?? '售后平台介入待处理')) ?></strong><small>订单 <?= e($orderNo) ?> · 售后 <?= e($afterSaleNo) ?></small></div><span class="admin-badge admin-badge--warning"><?= e((string)($task['priority'] ?? 'P1')) ?></span></div>
  <div class="admin-card__body">
    <div class="admin-alert admin-alert--info"><div class="admin-alert__body"><strong>当前异常</strong><p><?= e((string)($task['summary'] ?? '请根据双方材料处理本次维修售后。')) ?></p></div></div>
    <div class="admin-alert admin-alert--warning admin-mt-2"><div class="admin-alert__body"><strong>处理边界</strong><p>这里只决定继续维修、确认解决或关闭售后。退款、赔付、跑空费、师傅余额等资金事项必须继续使用退款/争议/财务核账流程。</p></div></div>
  </div>
</section>

<section class="admin-card admin-mt-3">
  <div class="admin-card__header"><div><strong>平台处理</strong><small>请填写可审计的处理说明；处理结果会通知用户和师傅。</small></div></div>
  <div class="admin-card__body admin-grid admin-grid--3">
    <?php foreach ($actions as $actionKey=>$meta): ?>
      <?php if (!in_array($actionKey, $allowed, true)) continue; ?>
      <form method="post" action="<?= e(admin_url('repair-intervention.resolve')) ?>" class="admin-card admin-card--soft">
        <?= csrf()->input() ?>
        <input type="hidden" name="order_id" value="<?= e($orderNo) ?>">
        <input type="hidden" name="after_sale_id" value="<?= e($afterSaleNo) ?>">
        <input type="hidden" name="decision" value="<?= e($meta['decision']) ?>">
        <div class="admin-card__body">
          <div class="admin-field">
            <label class="admin-label"><?= e($meta['label']) ?>说明</label>
            <textarea class="admin-control" name="note" rows="5" maxlength="1000" required placeholder="至少 4 个字，说明依据和处理结果"></textarea>
          </div>
          <button class="admin-btn admin-btn--<?= e($meta['tone']) ?> admin-mt-2" type="submit" data-confirm="<?= e($meta['confirm']) ?>"><?= e($meta['label']) ?></button>
        </div>
      </form>
    <?php endforeach; ?>
    <?php if (!$allowed): ?><div class="admin-empty">当前没有可执行的平台售后动作，请返回异常工作台刷新。</div><?php endif; ?>
  </div>
</section>
