<?php
use WorkerAdmin\Infrastructure\Testing\TestingPresentation;
$actionItems=(array)($detail['actions']['items']??[]);
$action=(array)($actionItems['system_message_delivery_probe']??[]);
if(!$action)return;
$outcomes=(array)($action['outcomes']??[]);
?>
<section class="admin-card admin-mb-3">
  <div class="admin-card__header">
    <div>
      <strong>消息兜底模拟</strong>
      <small>只读取当前实例已有消息投影；不会读取真实 OpenID/手机号，不调用微信订阅消息或阿里云短信。</small>
    </div>
  </div>
  <div class="admin-card__body">
    <div class="admin-alert admin-alert--info admin-mb-2">
      <strong><?= e($action['label']??'模拟订阅消息与短信兜底') ?></strong>
      <div><?= e(TestingPresentation::fact($action['description']??'')) ?></div>
      <small>
        事件 <?= e(TestingPresentation::event($action['event_type']??'')) ?> · 场景 <?= e(TestingPresentation::label($action['scene_key']??'')) ?> · 收件角色 <?= e(TestingPresentation::label($action['recipient_type']??'')) ?>
      </small>
    </div>
    <form method="post" action="<?= e(admin_url('test-center.action')) ?>" data-loading>
      <?= csrf()->input() ?>
      <input type="hidden" name="id" value="<?= e($instance['public_id']??'') ?>">
      <input type="hidden" name="action_key" value="system_message_delivery_probe">
      <input type="hidden" name="request_key" value="<?= e($actionRequestKeys['system_message_delivery_probe']??'') ?>">
      <input type="hidden" name="source_message_id" value="<?= (int)($action['source_message_id']??0) ?>">
      <div class="admin-field admin-mb-2">
        <label class="admin-label">模拟结果</label>
        <select class="admin-control" name="delivery_outcome" required>
          <?php foreach($outcomes as $row): ?>
            <option value="<?= e($row['key']??'') ?>"><?= e($row['label']??'') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-alert admin-alert--warning admin-mb-2">
        <small>这是 外部渠道模拟检查：只验证“订阅可用 / 未授权 / 次数不足”时的渠道决策和短信兜底，不代表真实渠道已送达，也不会消耗订阅次数或短信余额。</small>
      </div>
      <button class="admin-btn admin-btn--primary" type="submit" data-confirm="确认运行消息兜底模拟？本动作只写测试收件箱，不会真实发送消息。">运行消息兜底模拟</button>
    </form>
  </div>
</section>
