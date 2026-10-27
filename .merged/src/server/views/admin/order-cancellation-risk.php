<div class="admin-page-head"><div><h1>下单风控</h1><p><?= e(($user['nickname']?:'微信用户').' · '.$user['public_id']) ?></p></div><div class="admin-page-actions"><a class="admin-btn" href="<?= e(admin_url('users.order-risk.rules')) ?>">全局风控规则</a><a class="admin-btn" href="<?= e(admin_url('users')) ?>">返回用户管理</a></div></div>
<section class="admin-card"><div class="admin-card__body">
  <h2><?= $risk['state']['restricted'] ? '已暂停新下单' : '当前可下单' ?></h2>
  <p><?= e($risk['state']['message'] ?: '已有订单的付款、履约、退款和售后不受下单限制影响。') ?></p>
  <p class="admin-help">单用户不设置独立评分规则。人工处理只用于核实、限制新下单、解除限制或排除误判订单，所有操作保留审计记录。</p>
  <?php if($canManage && !$risk['state']['restricted']): ?>
  <form method="post" action="<?= e(admin_url('users.order-risk.restrict',['id'=>$user['id']])) ?>" class="admin-form-grid">
    <?= csrf()->input() ?>
    <div class="admin-field"><label class="admin-label">人工限制天数</label><input class="admin-control" type="number" name="days" value="7" min="1" max="30" required><p class="admin-help">仅暂停新下单，不影响已有订单、退款和售后。</p></div>
    <div class="admin-field admin-field--full"><label class="admin-label">限制原因</label><input class="admin-control" name="reason" required minlength="2" maxlength="500" placeholder="例如：多次恶意询价取消，经客服核实需临时限制"></div>
    <div class="admin-field admin-field--full"><button class="admin-btn admin-btn--danger" type="submit" data-confirm="确认人工限制该用户新下单？">人工限制新下单</button></div>
  </form>
  <?php endif; ?>
  <?php if($canManage && !empty($risk['restrictions'][0]) && empty($risk['restrictions'][0]['released_at']) && $risk['state']['restricted']): ?>
  <form method="post" action="<?= e(admin_url('users.order-risk.release',['id'=>$user['id']])) ?>">
    <?= csrf()->input() ?><input type="hidden" name="restriction_id" value="<?= (int)$risk['restrictions'][0]['id'] ?>">
    <label class="admin-label">客服解除说明</label><input class="admin-control" name="reason" required minlength="2" maxlength="500" placeholder="核实结果、误判原因或特殊情况">
    <p class="admin-help">勾选错误订单可同时排除该单计分；解除限制不会直接篡改历史分数。</p>
    <?php foreach($risk['scores'] as $item): ?>
      <label class="admin-help"><input type="checkbox" name="exclude_orders[]" value="<?= (int)$item['order_id'] ?>" <?= !empty($item['excluded_at'])?'disabled':'' ?>> 排除 <?= e($item['public_no']) ?>（<?= (int)$item['score'] ?> 分）</label>
    <?php endforeach; ?>
    <button class="admin-btn admin-btn--primary" type="submit" data-confirm="确认解除该用户的下单限制，并保留客服处理记录？">解除下单限制</button>
  </form><?php endif; ?>
</div></section>
<section class="admin-card"><div class="admin-card__body"><h2>取消计分记录</h2>
<div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>订单</th><th>取消时间</th><th>分数</th><th>处理状态</th><th>原始事实</th></tr></thead><tbody>
<?php foreach($risk['scores'] as $item): ?><tr><td><a href="<?= e(admin_url('orders.view',['id'=>$item['order_id']])) ?>"><?= e($item['public_no']) ?></a></td><td><?= e(local_datetime($item['cancelled_at'])) ?></td><td><?= (int)$item['score'] ?></td><td><?= !empty($item['excluded_at'])?'已排除':(!empty($item['consumed_restriction_id'])?'已处理':'待累计') ?> <?= e($item['exclusion_reason']??'') ?></td><td><details><summary>查看</summary><pre><?= e(json_encode(['取消事实'=>json_decode($item['snapshot_json'],true),'联系与投诉记录'=>$item['signals']],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)) ?></pre></details></td></tr><?php endforeach; ?>
<?php if(!$risk['scores']): ?><tr><td colspan="5">暂无计分记录</td></tr><?php endif; ?>
</tbody></table></div></div></section>
<section class="admin-card"><div class="admin-card__body"><h2>限制与解除记录</h2>
<?php foreach($risk['restrictions'] as $item): ?><?php $snapshot=json_decode((string)($item['snapshot_json']??''),true); $manual=is_array($snapshot)&&($snapshot['source']??'')==='admin'; ?><p><span class="admin-badge admin-badge--<?= $manual?'warning':'info' ?>"><?= $manual?'人工限制':'自动风控' ?></span> <?= e(local_datetime($item['starts_at'])) ?> 至 <?= e(local_datetime($item['expires_at'])) ?><?= !empty($item['released_at'])?'；已于 '.e(local_datetime($item['released_at'])).' 解除：'.e($item['release_reason']):'' ?><?php if($manual&&!empty($snapshot['reason'])): ?>；原因：<?= e($snapshot['reason']) ?><?php endif; ?></p><?php endforeach; ?>
<?php if(!$risk['restrictions']): ?><p class="admin-text-muted">暂无限制记录。</p><?php endif; ?>
</div></section>
