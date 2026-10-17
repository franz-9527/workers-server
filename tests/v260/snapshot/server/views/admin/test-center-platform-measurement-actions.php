<?php
$items=(array)($actionItems??[]);
$instance=(array)($instance??[]);
$actionRequestKeys=is_array($actionRequestKeys??null)?$actionRequestKeys:[];
$keys=['worker_platform_grab','worker_platform_measurement_appointment','worker_platform_submit_measurement','customer_platform_measurement_rework','admin_platform_pricing_review','worker_platform_construction_appointment'];
$platformItems=array_intersect_key($items,array_fill_keys($keys,true));
if(!$platformItems)return;
?>
<section class="admin-card admin-mb-3">
  <div class="admin-card__header"><div><strong>平台测量 → 同单施工</strong><small>正式抢单、测量成果、平台核价和施工预约；核价后自动生效</small></div></div>
  <div class="admin-card__body">
    <p class="admin-text-muted">测量与施工使用同一订单、同一师傅。测量成果使用测试图片，上传与量房工具仍需单独验收。</p>
    <?php foreach($platformItems as$key=>$action): $action=(array)$action; ?>
      <form class="admin-mb-3" method="post" action="<?= e(admin_url('test-center.action')) ?>" data-loading>
        <?= csrf()->input() ?>
        <input type="hidden" name="id" value="<?= e($instance['public_id']??'') ?>">
        <input type="hidden" name="action_key" value="<?= e($key) ?>">
        <input type="hidden" name="request_key" value="<?= e($actionRequestKeys[$key]??'') ?>">
        <?php if($key==='worker_platform_grab'): ?>
          <div class="admin-field admin-mb-2"><label class="admin-label">测试师傅</label><select class="admin-control" name="worker_slot" required><?php foreach((array)($action['workers']??[]) as$worker): ?><option value="<?= e($worker['slot_key']??'') ?>"><?= e(($worker['display_name']??'测试师傅').' · '.($worker['slot_key']??'')) ?></option><?php endforeach; ?></select></div>
        <?php elseif(in_array($key,['worker_platform_measurement_appointment','worker_platform_construction_appointment'],true)): ?>
          <div class="admin-field admin-mb-2"><label class="admin-label">预约时间</label><input class="admin-control" type="datetime-local" name="appointment_at" required></div>
        <?php elseif($key==='admin_platform_pricing_review'): ?>
          <div class="admin-field admin-mb-2"><label class="admin-label">客户核定总价（元）</label><input class="admin-control" type="number" name="customer_total" min="0.01" max="1000000" step="0.01" value="<?= e(number_format((int)($action['customer_total_cents']??0)/100,2,'.','')) ?>" required></div>
          <div class="admin-field admin-mb-2"><label class="admin-label">师傅施工工费（元）</label><input class="admin-control" type="number" name="worker_labor" min="0.01" max="1000000" step="0.01" value="<?= e(number_format((int)($action['worker_labor_cents']??0)/100,2,'.','')) ?>" required></div>
          <div class="admin-field admin-mb-2"><label class="admin-label">核价说明</label><input class="admin-control" name="note" maxlength="500" value="测试平台测量核价"></div>
        <?php elseif($key==='customer_platform_measurement_rework'): ?>
          <div class="admin-field admin-mb-2"><label class="admin-label">补测原因</label><input class="admin-control" name="reason" minlength="4" maxlength="500" value="测试平台测量退回一次补测" required></div>
        <?php endif; ?>
        <button class="admin-btn admin-btn--primary" type="submit" data-confirm="确认执行此测试动作？将通过正式业务规则推进当前测试订单。即使请求超时也请先刷新核对运行记录。"><?= e($action['label']??$key) ?></button>
      </form>
    <?php endforeach; ?>
  </div>
</section>
