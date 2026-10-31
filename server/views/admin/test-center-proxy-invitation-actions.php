<?php
$items=(array)($actionItems??[]);$keys=['admin_proxy_ready','customer_proxy_confirm_terms','customer_proxy_pay_booking','admin_proxy_approve','admin_proxy_supplement','admin_invitation_quote','customer_select_invitation_quote','admin_create_worker_invitation','worker_probe_unregistered_invite','fixture_invitee_onboarding','worker_claim_invitation','worker_accept_invitation','worker_probe_invitation_scope','fixture_invitee_approval'];
$visible=array_intersect_key($items,array_fill_keys($keys,true));if(!$visible)return;
?>
<section class="admin-card admin-mb-3">
  <div class="admin-card__header"><strong>普通代下单 / 定向邀请</strong></div>
  <div class="admin-card__body">
    <p class="admin-text-muted">代录后由测试客户独立确认协议，预约费仅使用 内部模拟支付。邀请师傅的入驻与审核是独占本地夹具，不代表真实微信注册或云端审核已验证。</p>
    <?php foreach($visible as$key=>$action): ?>
    <form method="post" action="<?= e(admin_url('test-center.action')) ?>" class="admin-mb-3" data-loading>
      <?= csrf()->input() ?>
      <input type="hidden" name="id" value="<?=e($instance['public_id']??'')?>">
      <input type="hidden" name="action_key" value="<?=e($key)?>">
      <input type="hidden" name="request_key" value="<?=e($actionRequestKeys[$key]??'')?>">
      <button class="admin-btn admin-btn--primary" type="submit"><?=e($action['label']??$key)?></button>
    </form>
    <?php endforeach; ?>
  </div>
</section>
