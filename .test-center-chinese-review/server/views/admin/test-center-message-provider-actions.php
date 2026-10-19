<?php
use WorkerAdmin\Infrastructure\Testing\TestingPresentation;
$actionItems=(array)($detail['actions']['items']??[]);
$action=(array)($actionItems['system_message_provider_failure_probe']??[]);
if(!$action)return;
$scenarios=(array)($action['scenarios']??[]);
?>
<section class="admin-card admin-mb-3">
  <div class="admin-card__header">
    <div>
      <strong>消息渠道失败 / 重试 / 降级模拟</strong>
      <small>复用正式消息失败策略；验证重试退避、终态和短信兜底一次性，不调用真实微信或阿里云。</small>
    </div>
  </div>
  <div class="admin-card__body">
    <div class="admin-alert admin-alert--info admin-mb-2">
      <strong><?= e($action['label']??'模拟消息渠道失败、重试与降级') ?></strong>
      <div><?= e(TestingPresentation::fact($action['description']??'')) ?></div>
      <small>事件 <?= e($action['event_type']??'') ?> · 场景 <?= e($action['scene_key']??'') ?> · 收件角色 <?= e($action['recipient_type']??'') ?></small>
    </div>
    <form method="post" action="<?= e(admin_url('test-center.action')) ?>" data-loading>
      <?= csrf()->input() ?>
      <input type="hidden" name="id" value="<?= e($instance['public_id']??'') ?>">
      <input type="hidden" name="action_key" value="system_message_provider_failure_probe">
      <input type="hidden" name="request_key" value="<?= e($actionRequestKeys['system_message_provider_failure_probe']??'') ?>">
      <input type="hidden" name="source_message_id" value="<?= (int)($action['source_message_id']??0) ?>">
      <div class="admin-field admin-mb-2">
        <label class="admin-label">失败链</label>
        <select class="admin-control" name="provider_scenario" required>
          <?php foreach($scenarios as $row): ?>
            <option value="<?= e($row['key']??'') ?>"><?= e($row['label']??'') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-alert admin-alert--warning admin-mb-2">
        <small>
          本动作只在 test_message_outbox 中记录模拟 attempt / fallback。微信 43101 只模拟“额度应失效”，不会修改真实订阅授权；
          短信失败链到第 5 次后必须停止，不允许继续递归降级。
        </small>
      </div>
      <button class="admin-btn admin-btn--primary" type="submit" data-confirm="确认运行消息渠道失败链模拟？不会真实发送消息，也不会修改真实订阅次数。">运行消息渠道失败模拟</button>
    </form>
  </div>
</section>
