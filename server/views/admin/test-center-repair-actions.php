<?php
use WorkerAdmin\Infrastructure\Testing\TestingPresentation;
$repairDetail=is_array($detail??null)?$detail:[];
$repairInstance=(array)($repairDetail['instance']??[]);
$repairActions=(array)($repairDetail['actions']??[]);
$repairItems=(array)($repairActions['items']??[]);
$repairRequestKeys=is_array($actionRequestKeys??null)?$actionRequestKeys:[];
$repairKeys=['prepare_repair_warranty','customer_repair_request','worker_accept_repair','worker_reject_repair','worker_repair_appointment','worker_complete_repair','repair_advance_timeout','customer_escalate_repair'];
$hasRepair=false;foreach($repairKeys as $key){if(isset($repairItems[$key])){$hasRepair=true;break;}}
if(!$hasRepair)return;
?>
<section class="admin-card admin-mb-3">
  <div class="admin-card__header"><div><strong>售后维修流程（M2）</strong><small>真实业务动作与测试预置条件分开记录；维修不倒退主订单完成状态，平台介入停在管理员裁决前</small></div></div>
  <div class="admin-card__body">
    <div class="admin-alert admin-alert--info admin-mb-2"><strong>售后规则</strong><div><?= e(TestingPresentation::fact($repairActions['message']??'')) ?> 师傅拒绝可立即申请平台介入；未响应需满 48 小时。保修条件和时间推进按钮仅是测试预置条件，不计入真实业务动作。</div></div>
    <div class="admin-grid admin-grid--2">
      <?php if(isset($repairItems['prepare_repair_warranty'])): $action=(array)$repairItems['prepare_repair_warranty']; ?>
      <article class="admin-card" style="margin:0"><div class="admin-card__body">
        <strong><?= e($action['label']??'补齐售后保修测试条件') ?></strong><p class="admin-text-muted"><?= e(TestingPresentation::fact($action['description']??'')) ?></p>
        <div class="admin-alert admin-alert--warning admin-mb-2"><small><strong>测试条件准备</strong>：只把当前测试订单补成 12 个月保修，不代表正式完工填写保修字段已经由此动作验证。</small></div>
        <form method="post" action="<?= e(admin_url('test-center.action')) ?>" data-loading><?= csrf()->input() ?><input type="hidden" name="id" value="<?= e($repairInstance['public_id']??'') ?>"><input type="hidden" name="action_key" value="prepare_repair_warranty"><input type="hidden" name="request_key" value="<?= e($repairRequestKeys['prepare_repair_warranty']??'') ?>"><button class="admin-btn" type="submit" data-confirm="确认仅为当前测试实例补齐 12 个月保修测试条件？">补齐保修测试条件</button></form>
      </div></article>
      <?php endif; ?>

      <?php if(isset($repairItems['customer_repair_request'])): $action=(array)$repairItems['customer_repair_request']; ?>
      <article class="admin-card" style="margin:0"><div class="admin-card__body">
        <strong><?= e($action['label']??'真实客户提交维修申请') ?></strong><p class="admin-text-muted"><?= e(TestingPresentation::fact($action['description']??'')) ?></p>
        <form method="post" action="<?= e(admin_url('test-center.action')) ?>" data-loading><?= csrf()->input() ?><input type="hidden" name="id" value="<?= e($repairInstance['public_id']??'') ?>"><input type="hidden" name="action_key" value="customer_repair_request"><input type="hidden" name="request_key" value="<?= e($repairRequestKeys['customer_repair_request']??'') ?>">
          <div class="admin-field admin-mb-2"><label class="admin-label">维修问题</label><textarea class="admin-control" name="note" rows="3" required>测试中心：验收后发现墙面局部翘边，需要原成交师傅返修处理</textarea></div>
          <button class="admin-btn admin-btn--primary" type="submit" data-confirm="确认以订单客户身份调用正式维修申请？会创建真实售后维修记录和系统自动评价。">执行真实维修申请</button>
        </form>
      </div></article>
      <?php endif; ?>

      <?php if(isset($repairItems['worker_accept_repair'])): $action=(array)$repairItems['worker_accept_repair']; ?>
      <article class="admin-card" style="margin:0"><div class="admin-card__body">
        <strong><?= e($action['label']??'真实师傅接受维修') ?></strong><p class="admin-text-muted"><?= e(TestingPresentation::fact($action['description']??'')) ?></p>
        <form method="post" action="<?= e(admin_url('test-center.action')) ?>" data-loading><?= csrf()->input() ?><input type="hidden" name="id" value="<?= e($repairInstance['public_id']??'') ?>"><input type="hidden" name="action_key" value="worker_accept_repair"><input type="hidden" name="request_key" value="<?= e($repairRequestKeys['worker_accept_repair']??'') ?>"><input type="hidden" name="note" value="测试中心：师傅接受本次保修维修并联系客户预约"><button class="admin-btn admin-btn--primary" type="submit" data-confirm="确认以原成交测试师傅身份接受维修？接受后仍需登记维修预约。">执行真实接受维修</button></form>
      </div></article>
      <?php endif; ?>

      <?php if(isset($repairItems['worker_reject_repair'])): $action=(array)$repairItems['worker_reject_repair']; ?>
      <article class="admin-card" style="margin:0"><div class="admin-card__body">
        <strong><?= e($action['label']??'真实师傅拒绝维修') ?></strong><p class="admin-text-muted"><?= e(TestingPresentation::fact($action['description']??'')) ?></p>
        <form method="post" action="<?= e(admin_url('test-center.action')) ?>" data-loading><?= csrf()->input() ?><input type="hidden" name="id" value="<?= e($repairInstance['public_id']??'') ?>"><input type="hidden" name="action_key" value="worker_reject_repair"><input type="hidden" name="request_key" value="<?= e($repairRequestKeys['worker_reject_repair']??'') ?>">
          <div class="admin-field admin-mb-2"><label class="admin-label">拒绝说明</label><textarea class="admin-control" name="note" rows="3" required>测试中心：师傅认为当前问题不属于本次施工责任，拒绝维修</textarea></div>
          <button class="admin-btn admin-btn--primary" type="submit" data-confirm="确认拒绝维修？正式规则会开放客户申请平台介入，但不会自动介入。">执行真实拒绝维修</button>
        </form>
      </div></article>
      <?php endif; ?>

      <?php if(isset($repairItems['worker_repair_appointment'])): $action=(array)$repairItems['worker_repair_appointment']; ?>
      <article class="admin-card" style="margin:0"><div class="admin-card__body">
        <strong><?= e($action['label']??'真实师傅预约维修') ?></strong><p class="admin-text-muted"><?= e(TestingPresentation::fact($action['description']??'')) ?></p>
        <form method="post" action="<?= e(admin_url('test-center.action')) ?>" data-loading><?= csrf()->input() ?><input type="hidden" name="id" value="<?= e($repairInstance['public_id']??'') ?>"><input type="hidden" name="action_key" value="worker_repair_appointment"><input type="hidden" name="request_key" value="<?= e($repairRequestKeys['worker_repair_appointment']??'') ?>">
          <div class="admin-field admin-mb-2"><label class="admin-label">维修预约时间</label><input class="admin-control" type="datetime-local" name="appointment_at" value="<?= e($action['default_appointment_at']??'') ?>" required></div>
          <input type="hidden" name="note" value="测试中心：师傅已与客户约定维修时间">
          <button class="admin-btn admin-btn--primary" type="submit" data-confirm="确认通过正式维修预约服务登记时间？主订单仍保持已完成。">执行真实维修预约</button>
        </form>
      </div></article>
      <?php endif; ?>

      <?php if(isset($repairItems['worker_complete_repair'])): $action=(array)$repairItems['worker_complete_repair']; ?>
      <article class="admin-card" style="margin:0"><div class="admin-card__body">
        <strong><?= e($action['label']??'真实师傅完成维修') ?></strong><p class="admin-text-muted"><?= e(TestingPresentation::fact($action['description']??'')) ?></p>
        <form method="post" action="<?= e(admin_url('test-center.action')) ?>" data-loading><?= csrf()->input() ?><input type="hidden" name="id" value="<?= e($repairInstance['public_id']??'') ?>"><input type="hidden" name="action_key" value="worker_complete_repair"><input type="hidden" name="request_key" value="<?= e($repairRequestKeys['worker_complete_repair']??'') ?>"><input type="hidden" name="note" value="测试中心：师傅已按预约完成本次保修维修"><button class="admin-btn admin-btn--primary" type="submit" data-confirm="确认提交正式维修完成？只关闭本次 after_sales，不改写原订单验收和结算。">执行真实完成维修</button></form>
      </div></article>
      <?php endif; ?>

      <?php if(isset($repairItems['repair_advance_timeout'])): $action=(array)$repairItems['repair_advance_timeout']; ?>
      <article class="admin-card" style="margin:0"><div class="admin-card__body">
        <strong><?= e($action['label']??'推进到维修响应超时') ?></strong><p class="admin-text-muted"><?= e(TestingPresentation::fact($action['description']??'')) ?></p>
        <div class="admin-alert admin-alert--warning admin-mb-2"><small><strong>测试时间推进</strong>：仅把当前测试维修申请的 申请时间推到 49 小时前，不修改服务器时间，也不代表真的等待了 48 小时。</small></div>
        <form method="post" action="<?= e(admin_url('test-center.action')) ?>" data-loading><?= csrf()->input() ?><input type="hidden" name="id" value="<?= e($repairInstance['public_id']??'') ?>"><input type="hidden" name="action_key" value="repair_advance_timeout"><input type="hidden" name="request_key" value="<?= e($repairRequestKeys['repair_advance_timeout']??'') ?>"><button class="admin-btn" type="submit" data-confirm="确认仅推进当前测试维修申请到 49 小时前，用于验证正式超时介入规则？">推进到 49 小时</button></form>
      </div></article>
      <?php endif; ?>

      <?php if(isset($repairItems['customer_escalate_repair'])): $action=(array)$repairItems['customer_escalate_repair']; ?>
      <article class="admin-card" style="margin:0"><div class="admin-card__body">
        <strong><?= e($action['label']??'真实客户申请维修平台介入') ?></strong><p class="admin-text-muted"><?= e(TestingPresentation::fact($action['description']??'')) ?></p>
        <div class="admin-alert admin-alert--warning admin-mb-2"><small>调用维修专用介入服务并再次执行 维修介入规则；本阶段停在平台管理员裁决前，不伪造维修责任和最终结果。</small></div>
        <form method="post" action="<?= e(admin_url('test-center.action')) ?>" data-loading><?= csrf()->input() ?><input type="hidden" name="id" value="<?= e($repairInstance['public_id']??'') ?>"><input type="hidden" name="action_key" value="customer_escalate_repair"><input type="hidden" name="request_key" value="<?= e($repairRequestKeys['customer_escalate_repair']??'') ?>">
          <div class="admin-field admin-mb-2"><label class="admin-label">介入说明</label><textarea class="admin-control" name="note" rows="3" required>测试中心：维修协商未解决，申请平台介入处理</textarea></div>
          <button class="admin-btn admin-btn--primary" type="submit" data-confirm="确认以订单客户身份申请维修平台介入？不会模拟管理员裁决。">执行真实维修介入申请</button>
        </form>
      </div></article>
      <?php endif; ?>
    </div>
  </div>
</section>
