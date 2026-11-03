<?php
$action=(array)($actionItems['chat_fixture_export']??[]);
if(!$action)return;
?>
<section class="admin-card admin-mb-3">
  <div class="admin-card__header"><div><strong>隔离聊天回归</strong><small>沿用当前测试实例及订单类型，真实通知渠道保持隔离。</small></div></div>
  <div class="admin-card__body">
    <p><?= e($action['description']??'') ?></p>
    <form method="post" action="<?= e(admin_url('test-center.action')) ?>" data-loading>
      <?= csrf()->input() ?>
      <input type="hidden" name="id" value="<?= e($instance['public_id']??'') ?>">
      <input type="hidden" name="action_key" value="chat_fixture_export">
      <input type="hidden" name="request_key" value="<?= e($actionRequestKeys['chat_fixture_export']??'') ?>">
      <label class="admin-label" for="chat-suite">回归场景</label>
      <select id="chat-suite" name="chat_suite" class="admin-control" required>
        <?php foreach((array)$action['suites'] as $key=>$label): ?><option value="<?= e($key) ?>"><?= e($label) ?></option><?php endforeach; ?>
      </select>
      <p>执行记录中的 fixture 可交给隔离回归工具。夹具准备、自动回归、真机与主机验收分别记录。</p>
      <button type="submit" class="admin-btn admin-btn--primary">准备回归夹具</button>
    </form>
  </div>
</section>

<?php $import=(array)($actionItems['chat_result_import']??[]);if($import): ?>
<section class="admin-card admin-mb-3">
 <div class="admin-card__header"><strong>登记聊天回归结果</strong></div>
 <div class="admin-card__body">
  <p><?= e($import['description']??'') ?></p>
  <form method="post" action="<?= e(admin_url('test-center.action')) ?>" data-loading>
   <?= csrf()->input() ?>
   <input type="hidden" name="id" value="<?= e($instance['public_id']??'') ?>">
   <input type="hidden" name="action_key" value="chat_result_import">
   <input type="hidden" name="request_key" value="<?= e($actionRequestKeys['chat_result_import']??'') ?>">
   <label class="admin-label" for="chat-report">回归工具生成的签名结果</label>
   <textarea id="chat-report" name="chat_report" class="admin-control" rows="5" maxlength="65536" required></textarea>
   <button class="admin-btn admin-btn--primary" type="submit">校验并登记结果</button>
  </form>
 </div>
</section>
<?php endif; ?>
