<?php
$actionItems=(array)($detail['actions']['items']??[]);
$action=(array)($actionItems['system_paid_sms_receipt_probe']??[]);
if(!$action)return;
$scenarios=(array)($action['scenarios']??[]);
?>
<section class="admin-card admin-mb-3">
  <div class="admin-card__header">
    <div>
      <strong>付费短信异步回执模拟</strong>
      <small>模拟 Aliyun SendDetails/BizId 回执、计费终态和长期无回执收口；不调用真实阿里云，不扣/退真实钱包。</small>
    </div>
  </div>
  <div class="admin-card__body">
    <div class="admin-alert admin-alert--info admin-mb-2">
      <strong><?= e($action['label']??'模拟付费短信异步回执') ?></strong>
      <div><?= e($action['description']??'') ?></div>
    </div>
    <form method="post" action="<?= e(admin_url('test-center.action')) ?>" data-loading>
      <?= csrf()->input() ?>
      <input type="hidden" name="id" value="<?= e($instance['public_id']??'') ?>">
      <input type="hidden" name="action_key" value="system_paid_sms_receipt_probe">
      <input type="hidden" name="request_key" value="<?= e($actionRequestKeys['system_paid_sms_receipt_probe']??'') ?>">
      <div class="admin-field admin-mb-2">
        <label class="admin-label">回执场景</label>
        <select class="admin-control" name="receipt_scenario" required>
          <?php foreach($scenarios as $row): ?>
            <option value="<?= e($row['key']??'') ?>"><?= e($row['label']??'') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-alert admin-alert--warning admin-mb-2">
        <small>
          本动作使用服务端生成的非敏感模拟手机号/OutId/BizId，仅写测试证据。
          “模拟扣费/释放”只表示正式规则应得到的账务结果，不会写 user_wallets、reservation、order_worker_sms_invites 或真实 message_outbox。
        </small>
      </div>
      <button class="admin-btn admin-btn--primary" type="submit" data-confirm="确认运行付费短信异步回执模拟？不会发送真实短信，也不会修改真实钱包。">运行回执模拟</button>
    </form>
  </div>
</section>
