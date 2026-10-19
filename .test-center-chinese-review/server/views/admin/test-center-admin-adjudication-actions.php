<?php
use WorkerAdmin\Infrastructure\Testing\TestingPresentation;
$adminDetail=is_array($detail??null)?$detail:[];$adminInstance=(array)($adminDetail['instance']??[]);$adminActions=(array)($adminDetail['actions']??[]);$adminItems=(array)($adminActions['items']??[]);$adminRequestKeys=is_array($actionRequestKeys??null)?$actionRequestKeys:[];
$adminKeys=['admin_refund_approve','admin_refund_reject','admin_dispute_resolve','admin_repair_continue','admin_repair_resolved','admin_repair_closed'];$hasAdminAdjudication=false;foreach($adminKeys as$key){if(isset($adminItems[$key])){$hasAdminAdjudication=true;break;}}if(!$hasAdminAdjudication)return;
?>
<section class="admin-card admin-mb-3">
  <div class="admin-card__header"><div><strong>平台管理员裁决（M2）</strong><small>只调用正式管理员用例；不提供任意改状态，也不会把退款登记伪装成微信退款成功</small></div></div>
  <div class="admin-card__body">
    <div class="admin-alert admin-alert--warning admin-mb-2"><strong>管理员权限边界</strong><div><?= e($adminActions['message']??'') ?> 本区动作代表平台管理员自己的裁决权，不代替客户接受方案，也不代替师傅报价、接单或确认维修完成。</div></div>
    <div class="admin-grid admin-grid--2">
      <?php if(isset($adminItems['admin_refund_approve'])): $action=(array)$adminItems['admin_refund_approve']; ?>
      <article class="admin-card" style="margin:0"><div class="admin-card__body"><strong><?= e($action['label']??'管理员通过退款') ?></strong><p class="admin-text-muted"><?= e(TestingPresentation::fact($action['description']??'')) ?></p>
        <div class="admin-alert admin-alert--info admin-mb-2"><small>本次测试审核金额：<?= e(number_format(((int)($action['amount_cents']??0))/100,2,'.','')) ?> 元。成功只应生成 <span class="admin-mono">待审核的退款记录</span>，微信退款执行仍被测试环境硬阻断。</small></div>
        <form method="post" action="<?= e(admin_url('test-center.action')) ?>" data-loading><?= csrf()->input() ?><input type="hidden" name="id" value="<?= e($adminInstance['public_id']??'') ?>"><input type="hidden" name="action_key" value="admin_refund_approve"><input type="hidden" name="request_key" value="<?= e($adminRequestKeys['admin_refund_approve']??'') ?>"><textarea class="admin-control admin-mb-2" name="note" rows="3" required>测试中心：平台核对后同意按当前可退预约费执行退款登记</textarea><button class="admin-btn admin-btn--danger" type="submit" data-confirm="确认以 Web 管理员身份调用正式退款审核通过？这里只登记内部待退款事实，不会向微信提交退款。">执行正式退款通过</button></form>
      </div></article><?php endif; ?>

      <?php if(isset($adminItems['admin_refund_reject'])): $action=(array)$adminItems['admin_refund_reject']; ?>
      <article class="admin-card" style="margin:0"><div class="admin-card__body"><strong><?= e($action['label']??'管理员拒绝退款') ?></strong><p class="admin-text-muted"><?= e(TestingPresentation::fact($action['description']??'')) ?></p>
        <form method="post" action="<?= e(admin_url('test-center.action')) ?>" data-loading><?= csrf()->input() ?><input type="hidden" name="id" value="<?= e($adminInstance['public_id']??'') ?>"><input type="hidden" name="action_key" value="admin_refund_reject"><input type="hidden" name="request_key" value="<?= e($adminRequestKeys['admin_refund_reject']??'') ?>"><textarea class="admin-control admin-mb-2" name="note" rows="3" required>测试中心：平台核对后认为当前退款理由不足，拒绝本次退款申请</textarea><button class="admin-btn" type="submit" data-confirm="确认正式拒绝本次退款？服务端会按原快照恢复允许恢复的订单状态。">执行正式退款拒绝</button></form>
      </div></article><?php endif; ?>

      <?php if(isset($adminItems['admin_dispute_resolve'])): $action=(array)$adminItems['admin_dispute_resolve']; ?>
      <article class="admin-card" style="margin:0"><div class="admin-card__body"><strong><?= e($action['label']??'管理员 0 元结案争议') ?></strong><p class="admin-text-muted"><?= e(TestingPresentation::fact($action['description']??'')) ?></p>
        <div class="admin-alert admin-alert--info admin-mb-2"><small>固定 <span class="admin-mono">refund=0 / run_empty_fee=0</span>。金额裁定会涉及退款分配与师傅余额账本，留到独立资金阶段验证。</small></div>
        <form method="post" action="<?= e(admin_url('test-center.action')) ?>" data-loading><?= csrf()->input() ?><input type="hidden" name="id" value="<?= e($adminInstance['public_id']??'') ?>"><input type="hidden" name="action_key" value="admin_dispute_resolve"><input type="hidden" name="request_key" value="<?= e($adminRequestKeys['admin_dispute_resolve']??'') ?>"><textarea class="admin-control admin-mb-2" name="note" rows="3" required>测试中心：平台已核对双方记录，本次费用争议按双方责任结案，不作资金分配</textarea><button class="admin-btn admin-btn--primary" type="submit" data-confirm="确认以正式 Web 管理员入口结案当前费用争议？本次固定为 0 元资金裁定。">执行正式争议结案</button></form>
      </div></article><?php endif; ?>

      <?php if(isset($adminItems['admin_repair_continue'])): $action=(array)$adminItems['admin_repair_continue']; ?>
      <article class="admin-card" style="margin:0"><div class="admin-card__body"><strong><?= e($action['label']??'平台要求继续维修') ?></strong><p class="admin-text-muted"><?= e(TestingPresentation::fact($action['description']??'')) ?></p>
        <form method="post" action="<?= e(admin_url('test-center.action')) ?>" data-loading><?= csrf()->input() ?><input type="hidden" name="id" value="<?= e($adminInstance['public_id']??'') ?>"><input type="hidden" name="action_key" value="admin_repair_continue"><input type="hidden" name="request_key" value="<?= e($adminRequestKeys['admin_repair_continue']??'') ?>"><textarea class="admin-control admin-mb-2" name="note" rows="3" required>测试中心：平台核对后要求原成交师傅继续履行本次保修维修</textarea><button class="admin-btn admin-btn--primary" type="submit" data-confirm="确认要求继续维修？售后保持处理中，不会代替师傅提交完成。">执行正式继续维修裁决</button></form>
      </div></article><?php endif; ?>

      <?php if(isset($adminItems['admin_repair_resolved'])): $action=(array)$adminItems['admin_repair_resolved']; ?>
      <article class="admin-card" style="margin:0"><div class="admin-card__body"><strong><?= e($action['label']??'平台确认维修问题已解决') ?></strong><p class="admin-text-muted"><?= e(TestingPresentation::fact($action['description']??'')) ?></p>
        <form method="post" action="<?= e(admin_url('test-center.action')) ?>" data-loading><?= csrf()->input() ?><input type="hidden" name="id" value="<?= e($adminInstance['public_id']??'') ?>"><input type="hidden" name="action_key" value="admin_repair_resolved"><input type="hidden" name="request_key" value="<?= e($adminRequestKeys['admin_repair_resolved']??'') ?>"><textarea class="admin-control admin-mb-2" name="note" rows="3" required>测试中心：平台核对双方反馈，确认本次维修问题已经解决</textarea><button class="admin-btn admin-btn--primary" type="submit" data-confirm="确认由平台结案为已解决？此操作不代表退款或赔付。">执行正式维修解决裁决</button></form>
      </div></article><?php endif; ?>

      <?php if(isset($adminItems['admin_repair_closed'])): $action=(array)$adminItems['admin_repair_closed']; ?>
      <article class="admin-card" style="margin:0"><div class="admin-card__body"><strong><?= e($action['label']??'平台关闭本次维修售后') ?></strong><p class="admin-text-muted"><?= e(TestingPresentation::fact($action['description']??'')) ?></p>
        <form method="post" action="<?= e(admin_url('test-center.action')) ?>" data-loading><?= csrf()->input() ?><input type="hidden" name="id" value="<?= e($adminInstance['public_id']??'') ?>"><input type="hidden" name="action_key" value="admin_repair_closed"><input type="hidden" name="request_key" value="<?= e($adminRequestKeys['admin_repair_closed']??'') ?>"><textarea class="admin-control admin-mb-2" name="note" rows="3" required>测试中心：平台核对后关闭本次维修售后，资金事项如有需要另行处理</textarea><button class="admin-btn" type="submit" data-confirm="确认关闭本次维修售后？资金事项不会在本动作中处理。">执行正式关闭售后裁决</button></form>
      </div></article><?php endif; ?>
    </div>
  </div>
</section>
