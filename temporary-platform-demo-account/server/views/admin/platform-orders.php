<?php
$pricingLabels=['fixed_total'=>'固定总价','itemized'=>'按项目/实际用量'];
$settlementLabels=['worker_collect'=>'师傅代收','platform_balance'=>'平台余额'];
$dispatchLabels=['multi_quote'=>'师傅报价','single_grab'=>'一口价抢单'];
$fulfillmentLabels=['direct_install'=>'直接施工','measure_then_install'=>'先测量后施工'];
$fixedIncomeOffset=1000000000;
?>
<div class="admin-page-head">
  <div>
    <h1>平台承接单</h1>
    <p>仅用于平台在微信、电话、门店或其他渠道已承接并托管客户交易的业务；普通客户代录请到“订单管理 → 代下单”。</p>
  </div>
  <div class="admin-page-actions">
    <button class="admin-btn admin-btn--primary" type="button" data-modal-open="platform-order-create"><i class="ri-add-circle-line"></i> 新增平台承接单</button>
  </div>
</div>

<div class="admin-alert admin-alert--info admin-mb-3">
  <strong>统一录单口径</strong>
  <div>服务类目、地图选点、地址拆分、联系人和师傅成交方式与普通代下单使用同一组施工事实；这里只额外填写平台托管价格、外部已收预付款和工费结算。</div>
</div>

<?php if(!empty($generatedInvite)): ?>
<section class="admin-alert admin-alert--warning admin-mb-3">
  <div class="admin-alert__body">
    <strong>平台单 <?= e($generatedInvite['order_no']??'') ?> 邀请已生成</strong>
    <p class="admin-mt-1"><?= ($generatedInvite['dispatch_mode']??'')==='multi_quote'?'师傅领取后进入“受邀待报价”，仍需提交自己的工费报价；平台选中后直接成交。':'师傅领取后按后台既定一口价工费直接成交，不再二次确认。' ?> 未入驻用户会先进入正常师傅入驻，提交资料后自动继续领取这张邀请。</p>
    <textarea class="admin-control admin-mono admin-mt-2" id="platform-invite-payload" rows="3" readonly><?= e($generatedInvite['payload']??'') ?></textarea>
    <div class="admin-inline-actions admin-mt-2">
      <button class="admin-btn admin-btn--primary" type="button" data-copy-target="#platform-invite-payload" data-copy-success="邀请路径已复制">复制邀请路径</button>
      <span class="admin-text-muted">有效期至 <?= e(local_datetime($generatedInvite['expires_at']??'')) ?></span>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="admin-card">
  <div class="admin-card__header"><div><strong>平台托管订单</strong><small>最多显示最近 100 单</small></div></div>
  <div class="admin-table-wrap">
    <table class="admin-table admin-table--responsive">
      <thead><tr><th>订单/客户</th><th>客户计价</th><th>师傅成交</th><th>工费结算</th><th>托管/发布</th><th>报价/师傅</th><th>操作</th></tr></thead>
      <tbody>
      <?php if(!$rows): ?><tr><td colspan="7" class="admin-empty">暂无平台承接单</td></tr><?php endif; ?>
      <?php foreach($rows as $row):
        $quotes=$quotesByOrder[(int)$row['id']]??[];
        $invites=$invitesByOrder[(int)$row['id']]??[];
        $activeQuotes=array_values(array_filter($quotes,static fn($q)=>($q['status']??'')==='active'));
        $activeInvites=array_values(array_filter($invites,static fn($i)=>($i['status']??'')==='active'));
        $customerTotal=(int)$row['customer_contract_total_cents'];
        $prepayment=(int)($row['external_prepayment_cents']??0);
        $labor=(int)$row['worker_labor_cents'];
        $extraRule=(int)$row['extra_income_rate_bp_snapshot'];
        $extraRuleText=$extraRule >= $fixedIncomeOffset
          ? '加项平台收入固定 '.money($extraRule-$fixedIncomeOffset).'/次'
          : '加项平台收入 '.number_format($extraRule/100,2).'%';
        $deadlineActive=!empty($row['quote_deadline_at'])&&strtotime((string)$row['quote_deadline_at'].' UTC')>time();
        $managedClean=($row['customer_flow_mode']??'')==='platform_managed'&&(int)($row['booking_fee_cents']??0)===0;
        $canInvite=$managedClean&&$row['audit_status']==='approved'&&$row['publish_status']==='published'&&$row['lifecycle_status']==='active'&&empty($row['selected_quote_id'])&&empty($row['assigned_worker_id'])&&empty($row['current_assignment_id'])&&$deadlineActive;
        $canEditPrepayment=$managedClean&&$row['lifecycle_status']==='active'&&$row['settlement_status']==='pending'&&empty($row['selected_quote_id'])&&empty($row['assigned_worker_id'])&&empty($row['current_assignment_id']);
        $updatedTs=!empty($row['updated_at'])?strtotime((string)$row['updated_at'].' UTC'):false;
        $autoPublishRepair=$managedClean
          &&empty($row['is_demo'])
          &&$row['publish_status']!=='published'
          &&$row['lifecycle_status']==='active'
          &&in_array((string)$row['audit_status'],['pending','approved'],true)
          &&empty($row['selected_quote_id'])&&empty($row['assigned_worker_id'])&&empty($row['current_assignment_id'])
          &&$updatedTs!==false&&$updatedTs<=time()-300;
      ?>
        <tr>
          <td data-label="订单/客户">
            <strong class="admin-mono"><?= e($row['public_no']) ?></strong><span class="admin-badge admin-badge--info admin-ml-1">平台单</span><?php if(!empty($row['is_demo'])): ?><span class="admin-badge admin-badge--warning admin-ml-1">演示域</span><?php endif; ?>
            <small class="admin-table__secondary"><?= e($row['title']) ?></small>
            <small class="admin-table__secondary"><?= e($row['category_names_snapshot']??$row['category_name_snapshot']??'') ?></small>
            <small class="admin-table__secondary"><?= !empty($row['customer_bound'])?e(($row['nickname']?:'微信用户').' '.($row['mobile_masked']?:'')):'未绑定客户' ?></small>
            <small class="admin-table__secondary">平台托管 · <?= e($fulfillmentLabels[$row['fulfillment_mode']]??$row['fulfillment_mode']) ?></small>
            <small class="admin-table__secondary"><?= e(trim(($row['city']??'').' '.($row['district']??'').' '.($row['address_summary']??''))) ?><?= $row['lat']!==null&&$row['lng']!==null?' · 已定位':'' ?></small>
          </td>
          <td data-label="客户计价">
            <strong><?= e($pricingLabels[$row['customer_pricing_mode']]??$row['customer_pricing_mode']) ?></strong>
            <small class="admin-table__secondary admin-money">客户成交 <?= e(money($customerTotal)) ?></small>
            <small class="admin-table__secondary admin-money">外部已收预付款 <?= e(money($prepayment)) ?></small>
            <small class="admin-table__secondary admin-money">现场剩余 <?= e(money(max(0,$customerTotal-$prepayment))) ?></small>
          </td>
          <td data-label="师傅成交"><strong><?= e($dispatchLabels[$row['dispatch_mode']]??$row['dispatch_mode']) ?></strong><?php if($row['dispatch_mode']==='single_grab'): ?><small class="admin-table__secondary admin-money">一口价工费 <?= e(money((int)$row['expected_fee_cents'])) ?></small><?php else: ?><small class="admin-table__secondary">有效报价 <?= count($activeQuotes) ?> 份</small><?php endif; ?><?php if($activeInvites): ?><small class="admin-table__secondary">有效邀请 <?= count($activeInvites) ?> 个</small><?php endif; ?></td>
          <td data-label="工费结算"><strong><?= e($settlementLabels[$row['worker_settlement_mode']]??$row['worker_settlement_mode']) ?></strong><small class="admin-table__secondary admin-money">师傅工费 <?= $labor>0?e(money($labor)):'待确定' ?></small><small class="admin-table__secondary"><?= e($extraRuleText) ?></small></td>
          <td data-label="托管/发布">
            <?php if($managedClean): ?><span class="admin-badge admin-badge--success">托管条款完整</span><?php else: ?><span class="admin-badge admin-badge--danger">旧资金口径待清理</span><?php endif; ?>
            <small class="admin-table__secondary">外部已收 <?= e(money($prepayment)) ?> · 不生成微信预约费</small>
            <small class="admin-table__secondary"><?= $row['publish_status']==='published'?'接单大厅展示中':(!empty($row['is_demo'])?'演示域订单 · 不进入真实自动发布':($autoPublishRepair?'自动发布异常':'等待系统自动发布')) ?> · <?= e($row['lifecycle_status']) ?></small>
            <?php if($row['publish_status']==='published'&&!empty($row['quote_deadline_at'])): ?><small class="admin-table__secondary">截止 <?= e(local_datetime($row['quote_deadline_at'])) ?></small><?php endif; ?>
          </td>
          <td data-label="报价/师傅">
            <?php if(!empty($row['worker_name'])): ?><strong><?= e($row['worker_name']) ?></strong><small class="admin-table__secondary admin-money">成交工费 <?= e(money($labor)) ?></small>
            <?php elseif($row['dispatch_mode']==='multi_quote'&&$activeQuotes): foreach($activeQuotes as $quote): ?>
              <form method="post" action="<?= e(admin_url('platform-orders.select-quote')) ?>" class="admin-inline-actions admin-mb-1" onsubmit="return confirm('确认选择该师傅？师傅主动报价已表达接单意愿，选中后将直接成交。')"><?= csrf()->input() ?><input type="hidden" name="order_id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="quote_id" value="<?= (int)$quote['id'] ?>"><button class="admin-btn admin-btn--sm" type="submit"><?= e($quote['display_name']) ?> · <?= e(money((int)$quote['amount_cents'])) ?></button></form>
            <?php endforeach; else: ?><span class="admin-text-muted">等待师傅</span><?php endif; ?>
          </td>
          <td data-label="操作"><div class="admin-inline-actions">
            <a class="admin-btn admin-btn--sm" href="<?= e(admin_url('orders.view',['id'=>(int)$row['id']])) ?>"><i class="ri-file-search-line"></i> 详情</a>
            <?php if($canEditPrepayment): ?><details><summary class="admin-btn admin-btn--sm">外部预付款</summary><form method="post" action="<?= e(admin_url('platform-orders.booking-fee')) ?>" class="admin-inline-actions admin-mt-1" onsubmit="return confirm('确认修改外部已收预付款？该金额只记录外部真实收款，不会生成微信支付。')"><?= csrf()->input() ?><input type="hidden" name="order_id" value="<?= (int)$row['id'] ?>"><input class="admin-control" style="width:120px" name="external_prepayment" type="number" min="0" max="<?= e(number_format($customerTotal/100,2,'.','')) ?>" step="0.01" value="<?= e(number_format($prepayment/100,2,'.','')) ?>" required><button class="admin-btn admin-btn--sm admin-btn--primary" type="submit">保存</button></form></details><?php endif; ?>
            <?php if(!empty($row['can_review_platform_pricing'])): ?><a class="admin-btn admin-btn--sm admin-btn--primary" href="<?= e(admin_url('platform-orders.pricing-review',['order_no'=>$row['public_no']])) ?>">测量后核价</a><?php endif; ?>
            <?php if($autoPublishRepair): ?><form method="post" action="<?= e(admin_url('platform-orders.publish')) ?>" onsubmit="return confirm('系统自动发布已超过 5 分钟未完成。确认使用人工修复发布？系统仍会重新校验托管资金和业务状态。')"><?= csrf()->input() ?><input type="hidden" name="order_id" value="<?= (int)$row['id'] ?>"><button class="admin-btn admin-btn--sm admin-btn--warning" type="submit"><i class="ri-tools-line"></i> 异常修复发布</button></form><?php endif; ?>
            <?php if($canInvite): ?><form method="post" action="<?= e(admin_url('platform-orders.invite')) ?>"><?= csrf()->input() ?><input type="hidden" name="order_id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="valid_days" value="7"><button class="admin-btn admin-btn--sm" type="submit" data-confirm="生成新的指定师傅邀请？同一平台单原有未领取邀请会失效。"><i class="ri-user-add-line"></i> 邀请师傅</button></form><?php endif; ?>
            <?php foreach($activeInvites as $invite): ?><form method="post" action="<?= e(admin_url('platform-orders.invite.revoke')) ?>"><?= csrf()->input() ?><input type="hidden" name="order_id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="invite_id" value="<?= (int)$invite['id'] ?>"><button class="admin-btn admin-btn--sm admin-btn--danger" type="submit" data-confirm="确认撤销当前未领取邀请？">撤销邀请</button></form><?php endforeach; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<div class="admin-modal admin-modal--xl" data-modal="platform-order-create" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="platform-order-create-title">
  <div class="admin-modal__backdrop" data-modal-close></div>
  <div class="admin-modal__dialog">
    <div class="admin-modal__header"><div><h3 id="platform-order-create-title">新增平台承接单</h3><small class="admin-text-muted">平台已在外部承接客户业务；施工事实与代下单保持同一录入口径</small></div><button class="admin-modal__close" type="button" data-modal-close aria-label="关闭"><i class="ri-close-line"></i></button></div>
    <form class="admin-form" method="post" action="<?= e(admin_url('platform-orders.create')) ?>" id="platform-order-form">
      <?= csrf()->input() ?>
      <div class="admin-modal__body">
        <div class="admin-alert admin-alert--info admin-mb-3"><strong>固定模式：平台托管</strong><div>客户无需在小程序支付预约费；若只是管理员替真实客户填写普通订单，请改用“订单管理 → 代下单”。</div></div>
        <div class="admin-grid admin-grid--2">
          <div class="admin-field">
            <label>关联客户账号（可选）</label>
            <div class="admin-search-picker" id="platform-customer-picker" data-search-url="<?= e(admin_url('platform-orders.customers')) ?>">
              <input class="admin-control" id="platform-customer-search" type="search" autocomplete="off" inputmode="search" placeholder="搜索昵称 / 11位完整手机号 / 客户编号" aria-controls="platform-customer-results" aria-autocomplete="list">
              <input type="hidden" name="customer_ref" id="platform-customer-ref" value="">
              <div class="admin-search-picker__results" id="platform-customer-results" role="listbox" hidden></div>
              <div class="admin-search-picker__selected" id="platform-customer-selected" hidden>
                <div><strong id="platform-customer-selected-name"></strong><small id="platform-customer-selected-meta"></small></div>
                <button class="admin-btn admin-btn--sm" type="button" id="platform-customer-clear">取消关联</button>
              </div>
            </div>
            <small id="platform-customer-help">可不关联账号；真实账号需有效微信身份，系统演示用户/商家/师傅主档也可用于演示域测试。未注册客户留空，仍可用联系人手机号履约及后续安全认领。</small>
          </div>
          <div class="admin-field"><label>履约方式</label><select class="admin-control" name="fulfillment_mode"><option value="direct_install">直接施工</option><option value="measure_then_install">先测量后施工</option></select><small>先测量后施工在同一平台单内完成测量、核价和施工。</small></div>
        </div>
        <div class="admin-grid admin-grid--2">
          <div class="admin-field"><label>服务类目（最多 6 项）</label><div class="admin-check-grid" id="platform-category-list"><?php foreach($categories as $category): ?><label><input type="checkbox" name="category_ids[]" value="<?= (int)$category['id'] ?>"> <?= e($category['name']) ?></label><?php endforeach; ?></div><small id="platform-category-help">与普通/代下单使用同一多类目口径，至少选择 1 项。</small></div>
          <div class="admin-field"><label>订单标题</label><input class="admin-control" name="title" maxlength="160" required placeholder="例如：客厅墙布安装"></div>
        </div>
        <div class="admin-field"><label>施工说明</label><textarea class="admin-control" name="description" rows="3" maxlength="2000" placeholder="材料情况、施工范围、特殊约定等"></textarea></div>
        <div class="admin-grid admin-grid--3"><div class="admin-field"><label>省/直辖市</label><input class="admin-control" name="province" maxlength="64"></div><div class="admin-field"><label>城市 *</label><input class="admin-control" name="city" id="platform-city" maxlength="64" required></div><div class="admin-field"><label>区/县 *</label><input class="admin-control" name="district" id="platform-district" maxlength="64" required></div></div>
        <div class="admin-field"><label>详细施工地址 *</label><input class="admin-control" name="address" id="platform-address" maxlength="255" required placeholder="地图选点后自动带入，可继续修改楼栋、单元和门牌"><small>修改文字地址不会移动地图点；地址密文按省市区 + 详细地址统一保存。</small></div>
        <div class="admin-field">
          <label>地图定位（必填）</label>
          <div class="admin-inline-actions"><button class="admin-btn admin-btn--sm" type="button" id="platform-map-open"><i class="ri-map-pin-line"></i> 地图选点</button><button class="admin-btn admin-btn--sm" type="button" id="platform-map-clear" hidden>重新选点</button><span class="admin-text-muted" id="platform-map-summary">未选点，创建前必须选择地图位置</span></div>
          <input type="hidden" name="map_lat" id="platform-map-lat"><input type="hidden" name="map_lng" id="platform-map-lng"><input type="hidden" name="map_point_name" id="platform-map-name"><input type="hidden" name="map_point_address" id="platform-map-address">
          <div id="platform-map-panel" class="admin-card admin-card--soft admin-mt-2" hidden><div class="admin-card__header"><div><strong>选择大概位置</strong><small>用于距离排序和接单范围；具体门牌仍以上方文字地址为准</small></div><button class="admin-btn admin-btn--sm" type="button" id="platform-map-close">收起地图</button></div><div class="admin-card__body" style="padding:0;overflow:hidden"><iframe id="platform-map-frame" title="腾讯地图选点" style="width:100%;height:430px;border:0" data-src="https://apis.map.qq.com/tools/locpicker?search=1&type=1&key=<?= e(rawurlencode((string)$mapKey)) ?>&referer=worker-admin"></iframe></div></div>
        </div>
        <div class="admin-grid admin-grid--2"><div class="admin-field"><label>联系人 *</label><input class="admin-control" name="contact_name" maxlength="80" required></div><div class="admin-field"><label>手机号 *</label><input class="admin-control" name="contact_mobile" maxlength="24" required placeholder="11位手机号；虚拟号可加 - 或 转拨号"></div></div>
        <div class="admin-grid admin-grid--2"><div class="admin-field"><label>客户计价</label><select class="admin-control" name="customer_pricing_mode" id="platform-pricing-mode"><option value="fixed_total">固定总价</option><option value="itemized">按项目 / 实际用量</option></select></div><div class="admin-field"><label>外部已收预付款（元）</label><input class="admin-control" name="external_prepayment" type="number" min="0" step="0.01" value="0.00" required><small>只填写外部渠道实际已收到的金额；未收填 0。系统不会因此生成微信支付记录。</small></div></div>
        <div class="admin-field" id="platform-fixed-total"><label>客户成交总价（元）</label><input class="admin-control" name="customer_total" inputmode="decimal" placeholder="例如 804.80"></div>
        <div id="platform-itemized" hidden><div class="admin-card admin-card--soft admin-mb-2"><div class="admin-card__header"><div><strong>开单明细</strong><small>实际施工数量变化按原单价重算，不属于加价</small></div><button class="admin-btn admin-btn--sm" type="button" id="platform-add-item"><i class="ri-add-line"></i> 增加项目</button></div><div class="admin-card__body" id="platform-items"></div></div></div>
        <div class="admin-grid admin-grid--2"><div class="admin-field"><label>师傅成交方式</label><select class="admin-control" name="dispatch_mode" id="platform-dispatch-mode"><option value="multi_quote">师傅报价 · 平台选择</option><option value="single_grab">一口价 · 抢单即成交</option></select></div><div class="admin-field" id="platform-worker-labor" hidden><label>一口价师傅工费（元）</label><input class="admin-control" name="worker_labor" inputmode="decimal" placeholder="师傅抢单后按此工费成交"></div></div>
        <div class="admin-grid admin-grid--2"><div class="admin-field"><label>工费结算方式</label><select class="admin-control" name="worker_settlement_mode"><option value="worker_collect">师傅代收 · 留工费后付平台收入款</option><option value="platform_balance">平台余额 · 客户尾款全部归平台，工费进师傅余额</option></select></div><div class="admin-field"><label>订单外加项平台收入</label><select class="admin-control" name="extra_income_mode" id="platform-extra-income-mode"><option value="rate">按比例</option><option value="fixed">固定金额</option></select><small>仅用于订单外新增项目，不影响原客户成交价和师傅基础工费。</small></div></div>
        <div class="admin-grid admin-grid--2"><div class="admin-field" id="platform-extra-income-rate"><label>平台收入比例（%）</label><input class="admin-control" name="extra_income_rate_percent" type="number" min="0" max="100" step="0.01" value="<?= e(number_format($defaultExtraRate/100,2,'.','')) ?>"></div><div class="admin-field" id="platform-extra-income-fixed" hidden><label>每次加项固定平台收入（元）</label><input class="admin-control" name="extra_income_fixed" type="number" min="0" max="1000000" step="0.01" placeholder="例如 50.00"><small>若本次加项金额低于固定平台收入，将不能确认该加项。</small></div></div>
      </div>
      <div class="admin-modal__footer"><button class="admin-btn" type="button" data-modal-close>取消</button><button class="admin-btn admin-btn--primary" type="submit"><i class="ri-save-line"></i> 创建平台托管单</button></div>
    </form>
  </div>
</div>

<script>
(function(){
  var form=document.getElementById('platform-order-form'),pricing=document.getElementById('platform-pricing-mode'),fixed=document.getElementById('platform-fixed-total'),itemized=document.getElementById('platform-itemized'),items=document.getElementById('platform-items'),add=document.getElementById('platform-add-item'),dispatch=document.getElementById('platform-dispatch-mode'),labor=document.getElementById('platform-worker-labor');
  var extraIncomeMode=document.getElementById('platform-extra-income-mode'),extraIncomeRate=document.getElementById('platform-extra-income-rate'),extraIncomeFixed=document.getElementById('platform-extra-income-fixed');
  var customerPicker=document.getElementById('platform-customer-picker'),customerSearch=document.getElementById('platform-customer-search'),customerRef=document.getElementById('platform-customer-ref'),customerResults=document.getElementById('platform-customer-results'),customerSelected=document.getElementById('platform-customer-selected'),customerSelectedName=document.getElementById('platform-customer-selected-name'),customerSelectedMeta=document.getElementById('platform-customer-selected-meta'),customerClear=document.getElementById('platform-customer-clear'),customerHelp=document.getElementById('platform-customer-help'),customerSearchTimer=0,customerSearchSerial=0;
  var city=document.getElementById('platform-city'),district=document.getElementById('platform-district'),addressInput=document.getElementById('platform-address'),mapFrame=document.getElementById('platform-map-frame'),mapPanel=document.getElementById('platform-map-panel'),mapOpen=document.getElementById('platform-map-open'),mapClose=document.getElementById('platform-map-close'),mapClear=document.getElementById('platform-map-clear'),mapLat=document.getElementById('platform-map-lat'),mapLng=document.getElementById('platform-map-lng'),mapName=document.getElementById('platform-map-name'),mapAddress=document.getElementById('platform-map-address'),mapSummary=document.getElementById('platform-map-summary'),categoryList=document.getElementById('platform-category-list'),categoryHelp=document.getElementById('platform-category-help');
  function customerStatus(message,isError){if(customerHelp){customerHelp.textContent=message;customerHelp.classList.toggle('admin-error',!!isError);}}
  function hideCustomerResults(){if(customerResults)customerResults.hidden=true;}
  function renderCustomerResults(list){
    if(!customerResults)return;customerResults.innerHTML='';
    if(!Array.isArray(list)||!list.length){hideCustomerResults();customerStatus('未找到匹配客户；可继续使用未绑定客户开单。',false);return;}
    list.forEach(function(customer){
      var button=document.createElement('button');button.type='button';button.className='admin-search-picker__option';button.setAttribute('role','option');button.dataset.customerRef=String(customer.public_id||'');
      var title=document.createElement('strong');title.textContent=String(customer.nickname||'微信用户');
      var meta=document.createElement('small');var roleMap={user:'用户',merchant:'商家',worker:'师傅'};meta.textContent=[customer.is_demo?'演示账号':'真实账号',roleMap[String(customer.account_role||'user')]||'用户',String(customer.mobile_masked||'未授权手机号'),String(customer.public_id||'')].filter(Boolean).join(' · ');
      button.appendChild(title);button.appendChild(meta);customerResults.appendChild(button);
    });
    customerResults.hidden=false;customerStatus('请选择一个搜索结果；不选择则按未绑定客户开单。',false);
  }
  function searchCustomers(){
    if(!customerPicker||!customerSearch)return;var q=String(customerSearch.value||'').trim();var serial=++customerSearchSerial;
    if(q.length<2){hideCustomerResults();customerStatus(q?'至少输入 2 个字；手机号需输入完整 11 位。':'可不关联账号；搜索昵称 / 11位完整手机号 / 客户编号。',false);return;}
    customerStatus('正在搜索客户…',false);var base=String(customerPicker.dataset.searchUrl||'');var url=base+(base.indexOf('?')>=0?'&':'?')+'q='+encodeURIComponent(q);
    fetch(url,{headers:{'Accept':'application/json'},credentials:'same-origin'}).then(function(response){return response.json().then(function(payload){if(!response.ok||!payload||payload.ok!==true)throw new Error(payload&&payload.message?payload.message:'客户搜索失败');return payload;});}).then(function(payload){if(serial!==customerSearchSerial)return;renderCustomerResults(payload.data&&payload.data.items?payload.data.items:[]);}).catch(function(error){if(serial!==customerSearchSerial)return;hideCustomerResults();customerStatus(error&&error.message?error.message:'客户搜索失败，请重试。',true);});
  }
  function selectCustomer(button){
    if(!button||!customerRef)return;var ref=String(button.dataset.customerRef||'');if(!ref)return;var strong=button.querySelector('strong'),small=button.querySelector('small');customerRef.value=ref;
    if(customerSelectedName)customerSelectedName.textContent=strong?strong.textContent:'已关联客户';if(customerSelectedMeta)customerSelectedMeta.textContent=small?small.textContent:ref;if(customerSelected)customerSelected.hidden=false;if(customerSearch)customerSearch.value='';hideCustomerResults();customerStatus('已关联客户；可继续填写履约联系人，二者无需完全相同。',false);
  }
  function clearCustomer(){if(customerRef)customerRef.value='';if(customerSelected)customerSelected.hidden=true;if(customerSelectedName)customerSelectedName.textContent='';if(customerSelectedMeta)customerSelectedMeta.textContent='';if(customerSearch){customerSearch.value='';customerSearch.focus();}hideCustomerResults();customerStatus('已取消关联，将按未绑定客户创建。',false);}
  function setGroupDisabled(node,disabled){if(!node)return;Array.prototype.slice.call(node.querySelectorAll('input,select,textarea,button[data-remove-item]')).forEach(function(control){control.disabled=!!disabled;});}
  function syncItemRows(){var yes=pricing&&pricing.value==='itemized';if(!items)return;Array.prototype.slice.call(items.querySelectorAll('.platform-item-row')).forEach(function(row){Array.prototype.slice.call(row.querySelectorAll('input')).forEach(function(input){input.disabled=!yes;input.required=yes&&(/item_description|item_quantity|item_unit_price/.test(String(input.name||'')));});});if(add)add.disabled=!!yes&&items.children.length>=50;}
  function syncPricing(){var yes=pricing&&pricing.value==='itemized';if(fixed)fixed.hidden=yes;if(itemized)itemized.hidden=!yes;if(yes&&items&&!items.children.length)addItem();setGroupDisabled(fixed,yes);if(fixed){var total=fixed.querySelector('[name="customer_total"]');if(total)total.required=!yes;}syncItemRows();}
  function syncDispatch(){var yes=dispatch&&dispatch.value==='single_grab';if(labor){labor.hidden=!yes;var input=labor.querySelector('[name="worker_labor"]');if(input){input.disabled=!yes;input.required=!!yes;}}}
  function syncExtraIncome(){var fixedMode=extraIncomeMode&&extraIncomeMode.value==='fixed';if(extraIncomeRate){extraIncomeRate.hidden=!!fixedMode;var rate=extraIncomeRate.querySelector('[name="extra_income_rate_percent"]');if(rate){rate.disabled=!!fixedMode;rate.required=!fixedMode;}}if(extraIncomeFixed){extraIncomeFixed.hidden=!fixedMode;var amount=extraIncomeFixed.querySelector('[name="extra_income_fixed"]');if(amount){amount.disabled=!fixedMode;amount.required=!!fixedMode;}}}
  function selectedCategories(){return categoryList?Array.prototype.slice.call(categoryList.querySelectorAll('input[name="category_ids[]"]:checked')):[];}
  function syncCategories(e){var selected=selectedCategories();if(selected.length>6&&e&&e.target){e.target.checked=false;selected=selectedCategories();}if(categoryHelp)categoryHelp.textContent='已选 '+selected.length+' / 6 项；至少选择 1 项。';}
  function addItem(){if(!items||items.children.length>=50)return;var row=document.createElement('div');row.className='admin-grid admin-grid--5 admin-mb-2 platform-item-row';row.innerHTML='<div class="admin-field"><label>项目</label><input class="admin-control" name="item_description[]" maxlength="160" placeholder="贴墙布"></div><div class="admin-field"><label>数量</label><input class="admin-control" name="item_quantity[]" type="number" min="0" step="0.001" placeholder="50.4"></div><div class="admin-field"><label>单位</label><input class="admin-control" name="item_unit[]" maxlength="24" placeholder="㎡"></div><div class="admin-field"><label>单价（元）</label><input class="admin-control" name="item_unit_price[]" type="number" min="0" step="0.01" placeholder="12"></div><div class="admin-field"><label>说明</label><div class="admin-inline-actions"><input class="admin-control" name="item_note[]" maxlength="500" placeholder="可选"><button class="admin-btn admin-btn--sm" type="button" data-remove-item>删除</button></div></div>';items.appendChild(row);syncItemRows();}
  function openMap(){if(!mapPanel)return;mapPanel.hidden=false;if(mapFrame&&!mapFrame.src)mapFrame.src=mapFrame.getAttribute('data-src')||'';}
  function closeMap(){if(mapPanel)mapPanel.hidden=true;}
  function clearMap(){if(mapLat)mapLat.value='';if(mapLng)mapLng.value='';if(mapName)mapName.value='';if(mapAddress)mapAddress.value='';if(mapSummary)mapSummary.textContent='未选点，创建前必须选择地图位置';if(mapClear)mapClear.hidden=true;}
  function clearMapAfterRegionChange(){if(mapLat&&mapLat.value){clearMap();if(mapSummary)mapSummary.textContent='城市或区县已修改，请重新地图选点';}}
  if(customerSearch)customerSearch.addEventListener('input',function(){clearTimeout(customerSearchTimer);customerSearchTimer=setTimeout(searchCustomers,260);});if(customerResults)customerResults.addEventListener('click',function(e){var button=e.target.closest('[data-customer-ref]');if(button)selectCustomer(button);});if(customerClear)customerClear.addEventListener('click',clearCustomer);document.addEventListener('click',function(e){if(customerPicker&&!customerPicker.contains(e.target))hideCustomerResults();});
  if(pricing){pricing.addEventListener('change',syncPricing);syncPricing();}if(dispatch){dispatch.addEventListener('change',syncDispatch);syncDispatch();}if(extraIncomeMode){extraIncomeMode.addEventListener('change',syncExtraIncome);syncExtraIncome();}if(categoryList){categoryList.addEventListener('change',syncCategories);syncCategories();}if(add)add.addEventListener('click',addItem);if(items)items.addEventListener('click',function(e){var target=e.target.closest('[data-remove-item]');if(!target)return;var row=target.closest('.platform-item-row');if(row)row.remove();if(pricing&&pricing.value==='itemized'&&items&&!items.children.length)addItem();syncItemRows();});if(mapOpen)mapOpen.addEventListener('click',openMap);if(mapClose)mapClose.addEventListener('click',closeMap);if(mapClear)mapClear.addEventListener('click',openMap);if(city)city.addEventListener('change',clearMapAfterRegionChange);if(district)district.addEventListener('change',clearMapAfterRegionChange);
  if(form)form.addEventListener('submit',function(event){var selected=selectedCategories();if(customerSearch&&String(customerSearch.value||'').trim()&&customerRef&&!customerRef.value){event.preventDefault();customerStatus('请从搜索结果中选择客户，或清空搜索框后按未绑定客户开单。',true);customerSearch.focus();return;}if(selected.length<1||selected.length>6){event.preventDefault();if(categoryHelp)categoryHelp.textContent=selected.length<1?'请至少选择 1 个服务类目':'服务类目最多选择 6 个';return;}if(!mapLat||!mapLng||!mapLat.value||!mapLng.value){event.preventDefault();openMap();if(mapSummary)mapSummary.textContent='请先完成地图选点后再创建';}});
  window.addEventListener('message',function(event){if(!event||!event.data||event.data.module!=='locationPicker')return;if(event.origin&&event.origin.indexOf('apis.map.qq.com')===-1)return;var loc=event.data.latlng||{},lat=Number(loc.lat),lng=Number(loc.lng);if(!Number.isFinite(lat)||!Number.isFinite(lng))return;var poiName=String(event.data.poiname||''),poiAddress=String(event.data.poiaddress||'');if(mapLat)mapLat.value=String(lat);if(mapLng)mapLng.value=String(lng);if(mapName)mapName.value=poiName;if(mapAddress)mapAddress.value=poiAddress;if(addressInput&&!String(addressInput.value||'').trim())addressInput.value=[poiAddress,poiName].filter(Boolean).join(' ').trim();if(mapSummary)mapSummary.textContent='已选：'+(poiName||poiAddress||lat+','+lng)+' · '+lat.toFixed(6)+','+lng.toFixed(6);if(mapClear)mapClear.hidden=false;closeMap();});
})();
</script>
