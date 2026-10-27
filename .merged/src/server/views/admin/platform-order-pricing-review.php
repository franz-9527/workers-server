<?php
$order=$detail['order']??[];
$platform=$detail['platform_order']??[];
$review=$platform['pricing_review']??[];
$canReview=!empty($detail['actions']['can_review_platform_pricing']);
$items=array_values(array_filter($platform['pricing_items']??[],static fn($item)=>($item['item_source']??'')==='base'&&($item['status']??'')==='active'));
?>
<div class="admin-page-header"><div><h1>测量后施工核价</h1><p><?= e($order['id']??'') ?> · <?= e($order['title']??'') ?></p></div><a class="admin-btn" href="<?= e(admin_url('platform-orders')) ?>">返回平台订单</a></div>
<section class="admin-card">
  <div class="admin-card__header"><strong><?= e($review['status_text']??'等待测量成果') ?></strong></div>
  <div class="admin-card__body">
    <?php if(!$canReview): ?><div class="admin-empty">当前不能核价：请检查测量成果、成交关系和未处理的退款或争议事项。</div>
    <?php else: ?>
    <form method="post" action="<?= e(admin_url('platform-orders.pricing-review')) ?>" class="admin-form">
      <?= csrf()->input() ?><input type="hidden" name="order_no" value="<?= e($order['id']??'') ?>">
      <p>原客户总价 <?= e(money((int)($platform['customer_contract_total_cents']??0))) ?>；原师傅工费 <?= e(money((int)($platform['worker_labor_cents']??0))) ?>。</p>
      <?php if(($platform['customer_pricing_mode']??'')==='itemized'): ?>
      <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>项目</th><th>实际数量</th><th>原约定单价</th></tr></thead><tbody>
        <?php foreach($items as $index=>$item): ?><tr><td><?= e($item['description']) ?>（<?= e($item['unit']??'') ?>）</td><td><input type="hidden" name="items[<?= $index ?>][sort_order]" value="<?= (int)$item['sort_order'] ?>"><input class="admin-control" name="items[<?= $index ?>][quantity]" type="number" min="0.001" step="0.001" required value="<?= e($item['quantity']??'') ?>"></td><td><?= e(money((int)($item['unit_price_cents']??0))) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <?php else: ?><div class="admin-field"><label>客户最终总价（元）</label><input class="admin-control" name="customer_total" type="number" min="0.01" step="0.01" required value="<?= e(number_format(((int)($platform['customer_contract_total_cents']??0))/100,2,'.','')) ?>"></div><?php endif; ?>
      <div class="admin-field"><label>师傅施工工费（元）</label><input class="admin-control" name="worker_labor" type="number" min="0.01" step="0.01" required value="<?= e(number_format(((int)($platform['worker_labor_cents']??0))/100,2,'.','')) ?>"></div>
      <div class="admin-field"><label>核价说明</label><textarea class="admin-control" name="note" maxlength="500" rows="3"></textarea></div>
      <p>实际数量沿用原单价重算；新增项目仍走增项协商。小程序预约客户需确认最终价格，平台托管客户自动确认。</p>
      <button class="admin-btn admin-btn--primary" type="submit" data-confirm="确认按测量成果核定最终施工价格？">确认核价</button>
    </form>
    <?php endif; ?>
  </div>
</section>
