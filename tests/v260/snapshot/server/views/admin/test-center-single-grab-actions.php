<?php
$actionItems=(array)($actionItems??[]);
$instance=(array)($instance??[]);
$actionRequestKeys=is_array($actionRequestKeys??null)?$actionRequestKeys:[];
$hasGrab=isset($actionItems['worker_grab']);
$hasCompetitor=isset($actionItems['worker_grab_competitor']);
if(!$hasGrab&&!$hasCompetitor)return;
?>
<section class="admin-card admin-mb-3">
  <div class="admin-card__header"><div><strong>单师傅抢单测试</strong><small>固定期望总价：第一位师傅抢单即成交；第二位师傅必须由正式服务返回 ORDER_ALREADY_GRABBED</small></div></div>
  <div class="admin-card__body">
    <div class="admin-alert admin-alert--warning admin-mb-2"><strong>竞争规则</strong><div>不直接写 assignment，不伪造“抢单失败”。所有动作都使用当前实例的 approved testing 师傅调用正式 <span class="admin-mono">orders.grab</span>。</div></div>
    <?php if($hasGrab): $action=(array)$actionItems['worker_grab']; ?>
      <form method="post" action="<?= e(admin_url('test-center.action')) ?>" data-loading>
        <?= csrf()->input() ?>
        <input type="hidden" name="id" value="<?= e($instance['public_id']??'') ?>">
        <input type="hidden" name="action_key" value="worker_grab">
        <input type="hidden" name="request_key" value="<?= e($actionRequestKeys['worker_grab']??'') ?>">
        <div class="admin-field admin-mb-2"><label class="admin-label">第一位抢单师傅</label><select class="admin-control" name="worker_slot" required><?php foreach((array)($action['workers']??[]) as $worker): ?><option value="<?= e($worker['slot_key']??'') ?>" <?= ($worker['slot_key']??'')===($action['default_worker_slot']??'')?'selected':'' ?>><?= e(($worker['display_name']??'测试师傅').' · '.($worker['slot_key']??'')) ?></option><?php endforeach; ?></select></div>
        <p class="admin-text-muted">期望总价：<?= e(money((int)($action['amount_cents']??0))) ?>。成功后会直接生成 selected quote + accepted single_grab assignment。</p>
        <button class="admin-btn admin-btn--primary" type="submit" data-confirm="确认调用正式 orders.grab？成功后该测试实例将产生唯一成交师傅，不能在原实例倒退。">执行真实抢单</button>
      </form>
    <?php endif; ?>
    <?php if($hasCompetitor): $action=(array)$actionItems['worker_grab_competitor']; ?>
      <form method="post" action="<?= e(admin_url('test-center.action')) ?>" data-loading>
        <?= csrf()->input() ?>
        <input type="hidden" name="id" value="<?= e($instance['public_id']??'') ?>">
        <input type="hidden" name="action_key" value="worker_grab_competitor">
        <input type="hidden" name="request_key" value="<?= e($actionRequestKeys['worker_grab_competitor']??'') ?>">
        <div class="admin-field admin-mb-2"><label class="admin-label">第二位竞争师傅</label><select class="admin-control" name="worker_slot" required><?php foreach((array)($action['workers']??[]) as $worker): ?><option value="<?= e($worker['slot_key']??'') ?>" <?= ($worker['slot_key']??'')===($action['default_worker_slot']??'')?'selected':'' ?>><?= e(($worker['display_name']??'测试师傅').' · '.($worker['slot_key']??'')) ?></option><?php endforeach; ?></select></div>
        <div class="admin-alert admin-alert--info admin-mb-2"><small>预期正式错误码：<span class="admin-mono"><?= e($action['expected_error_code']??'ORDER_ALREADY_GRABBED') ?></span>。若第二位师傅再次成交，测试会直接失败。</small></div>
        <button class="admin-btn admin-btn--primary" type="submit" data-confirm="确认使用另一名测试师傅再次调用正式 orders.grab，并验证其被明确拒绝？">验证竞争失败</button>
      </form>
    <?php endif; ?>
  </div>
</section>
