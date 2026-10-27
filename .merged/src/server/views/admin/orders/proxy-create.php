<?php
$form=is_array($form??null)?$form:[];
$schema=is_array($form['schema']??null)?$form['schema']:[];
$fields=is_array($form['fields']??null)?$form['fields']:[];
$categories=is_array($form['categories']??null)?$form['categories']:[];
$users=is_array($users??null)?$users:[];
$mapKey=(string)($mapKey??setting('map.tencent_key','32CBZ-FEPK6-POMSA-MCDE2-M2MIK-ZOBK2'));
$skip=['workflow_profile','category_id','category_ids','category_mode','category_other','material_images','product_images','wall_images','expected_fee','expected_fee_type','contact_name','contact_mobile'];
?>
<div class="admin-page-head">
  <div><h1>代下单</h1><p>替真实客户录入普通订单。施工资料与小程序下单共用当前已发布 动态下单表单；客户最终仍需本人确认并完成真实微信预约费支付。</p></div>
  <div class="admin-page-actions"><a class="admin-btn" href="<?= e(admin_url('orders')) ?>"><i class="ri-arrow-left-line"></i> 返回订单管理</a></div>
</div>
<div class="admin-alert admin-alert--info admin-mb-3"><strong>普通订单，不是平台承接单</strong><div>这里不填写平台合同价、外部预付款或平台工费结算。若平台已在外部承接客户交易，请使用“平台承接单”。</div></div>
<form class="admin-card admin-form" method="post" action="<?= e(admin_url('orders.proxy-create')) ?>" id="proxy-order-form">
  <?= csrf()->input() ?>
  <input type="hidden" name="schema_version_id" value="<?= (int)($schema['id']??0) ?>">
  <input type="hidden" name="client_request_id" value="ADMINPROXY_<?= e(bin2hex(random_bytes(12))) ?>">
  <div class="admin-card__header"><div><strong>客户与订单方式</strong><small>表单版本 V<?= e((string)($schema['version_no']??'')) ?></small></div></div>
  <div class="admin-card__body">
    <div class="admin-grid admin-grid--2">
      <div class="admin-field"><label>下单客户 *</label><select class="admin-control" name="user_id" required><option value="">请选择真实客户账号</option><?php foreach($users as $user): ?><option value="<?= (int)$user['id'] ?>"><?= e(($user['nickname']?:'微信用户').' · '.($user['mobile_masked']?:$user['public_id'])) ?></option><?php endforeach; ?></select><small>客户需能够正常登录小程序；管理员不能代替客户付款。</small></div>
      <div class="admin-field"><label>服务方式 *</label><select class="admin-control" name="answers[workflow_profile]" id="proxy-workflow"><option value="construction">施工</option><option value="measurement">上门测量</option></select></div>
    </div>
    <div class="admin-field"><label>服务类目（最多 6 项）</label><div class="admin-check-grid" id="proxy-category-list"><?php foreach($categories as $category): ?><label><input type="checkbox" name="answers[category_ids][]" value="<?= (int)$category['id'] ?>"> <?= e($category['name']) ?></label><?php endforeach; ?></div><div class="admin-grid admin-grid--2 admin-mt-2"><div><select class="admin-control" name="answers[category_mode]" id="proxy-category-mode"><option value="listed">使用上方类目</option><option value="other">其他类目</option><option value="unknown">暂不确定</option></select></div><div><input class="admin-control" name="answers[category_other]" id="proxy-category-other" maxlength="50" placeholder="选择“其他类目”时填写"></div></div><small id="proxy-category-help">使用上方类目时至少选择 1 项；其他/暂不确定与已选类目互斥。</small></div>
    <div class="admin-grid admin-grid--2"><div class="admin-field"><label>师傅成交方式</label><select class="admin-control" name="dispatch_mode" id="proxy-dispatch"><option value="multi_quote">多师傅报价</option><option value="single_grab">单师傅抢单</option></select><small>测量单自动使用多师傅报价。</small></div><div class="admin-field"><label>期望费用（选填）</label><div class="admin-inline-actions"><select class="admin-control" name="answers[expected_fee_type]" style="max-width:130px"><option value="total">总价</option><option value="unit">单价说明</option></select><input class="admin-control" name="answers[expected_fee]" maxlength="500" placeholder="总价填整数元；单价可写 12 元/㎡"></div></div></div>
  </div>

  <div class="admin-card__header"><div><strong>服务资料</strong><small>来自当前已发布 下单表单；图片创建后在订单详情继续补充</small></div></div>
  <div class="admin-card__body admin-form-grid">
    <?php foreach($fields as $field): $key=(string)($field['field_key']??'');$type=(string)($field['component_type']??'text');if($key===''||in_array($key,$skip,true)||in_array($type,['wechat_address','address','map','image_upload','image'],true))continue;$required=!empty($field['is_required']);$options=is_array($field['options']??null)?$field['options']:[]; ?>
      <div class="admin-field <?= in_array($type,['textarea','checkbox','multiple'],true)?'admin-field--full':'' ?>">
        <label><?= e((string)($field['label']??$key)) ?><?= $required?' *':'' ?></label>
        <?php if(in_array($type,['radio','single','select','boolean'],true)&&$options): ?>
          <select class="admin-control" name="answers[<?= e($key) ?>]" <?= $required?'required':'' ?>><option value="">请选择</option><?php foreach($options as $option): ?><option value="<?= e((string)($option['value_text']??$option['option_key']??'')) ?>"><?= e((string)($option['label']??'')) ?></option><?php endforeach; ?></select>
        <?php elseif(in_array($type,['checkbox','multiple'],true)&&$options): ?>
          <div class="admin-check-grid"><?php foreach($options as $option): ?><label><input type="checkbox" name="answers[<?= e($key) ?>][]" value="<?= e((string)($option['value_text']??$option['option_key']??'')) ?>"> <?= e((string)($option['label']??'')) ?></label><?php endforeach; ?></div>
        <?php elseif($type==='textarea'): ?>
          <textarea class="admin-control" name="answers[<?= e($key) ?>]" rows="3" <?= $required?'required':'' ?> placeholder="<?= e((string)($field['placeholder']??'')) ?>"></textarea>
        <?php elseif($type==='date'): ?>
          <input class="admin-control" type="date" name="answers[<?= e($key) ?>]" <?= $required?'required':'' ?>>
        <?php elseif($type==='number'): ?>
          <input class="admin-control" type="number" step="any" name="answers[<?= e($key) ?>]" <?= $required?'required':'' ?> placeholder="<?= e((string)($field['placeholder']??'')) ?>">
        <?php else: ?>
          <input class="admin-control" name="answers[<?= e($key) ?>]" <?= $required?'required':'' ?> placeholder="<?= e((string)($field['placeholder']??'')) ?>">
        <?php endif; ?>
        <?php if(!empty($field['help_text'])): ?><small><?= e((string)$field['help_text']) ?></small><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="admin-card__header"><div><strong>地址与联系人</strong><small>与平台承接单一致：文字地址和 腾讯地图坐标分开保存</small></div></div>
  <div class="admin-card__body">
    <div class="admin-grid admin-grid--3"><div class="admin-field"><label>省/直辖市</label><input class="admin-control" name="address[province]" maxlength="64"></div><div class="admin-field"><label>城市 *</label><input class="admin-control" name="address[city]" id="proxy-city" maxlength="64" required></div><div class="admin-field"><label>区/县 *</label><input class="admin-control" name="address[district]" id="proxy-district" maxlength="64" required></div></div>
    <div class="admin-field"><label>详细服务地址 *</label><input class="admin-control" name="address[detail]" id="proxy-address" maxlength="255" required placeholder="地图选点后自动带入，可继续补充楼栋、单元和门牌"><small>修改文字地址不会移动地图点。</small></div>
    <div class="admin-field"><label>地图定位（必填）</label><div class="admin-inline-actions"><button class="admin-btn admin-btn--sm" type="button" id="proxy-map-open"><i class="ri-map-pin-line"></i> 地图选点</button><button class="admin-btn admin-btn--sm" type="button" id="proxy-map-reselect" hidden>重新选点</button><span class="admin-text-muted" id="proxy-map-summary">未选点</span></div><input type="hidden" name="address[lat]" id="proxy-map-lat"><input type="hidden" name="address[lng]" id="proxy-map-lng"><div id="proxy-map-panel" class="admin-card admin-card--soft admin-mt-2" hidden><div class="admin-card__header"><div><strong>选择大概位置</strong><small>用于距离排序与接单范围；门牌以上方文字地址为准</small></div><button class="admin-btn admin-btn--sm" type="button" id="proxy-map-close">收起</button></div><div class="admin-card__body" style="padding:0;overflow:hidden"><iframe id="proxy-map-frame" title="腾讯地图选点" style="width:100%;height:430px;border:0" data-src="https://apis.map.qq.com/tools/locpicker?search=1&type=1&key=<?= e(rawurlencode($mapKey)) ?>&referer=worker-admin"></iframe></div></div></div>
    <div class="admin-grid admin-grid--2"><div class="admin-field"><label>对接人 *</label><input class="admin-control" name="address[contact_name]" maxlength="80" required></div><div class="admin-field"><label>手机号 *</label><input class="admin-control" name="address[contact_mobile]" maxlength="24" required placeholder="11位手机号；虚拟号可加 - 或 转拨号"></div></div>
    <div class="admin-alert admin-alert--warning admin-mt-3"><strong>创建后仍是普通订单草稿</strong><div>必填图片可在订单管理详情补充。客户本人核对订单、确认平台协议并完成微信预约费支付后，订单才进入普通审核/报价流程。</div></div>
  </div>
  <div class="admin-card__footer"><a class="admin-btn" href="<?= e(admin_url('orders')) ?>">取消</a><button class="admin-btn admin-btn--primary" type="submit"><i class="ri-save-line"></i> 创建代下单草稿</button></div>
</form>
<script>
(function(){
 var form=document.getElementById('proxy-order-form'),workflow=document.getElementById('proxy-workflow'),dispatch=document.getElementById('proxy-dispatch'),categoryList=document.getElementById('proxy-category-list'),categoryMode=document.getElementById('proxy-category-mode'),categoryOther=document.getElementById('proxy-category-other'),categoryHelp=document.getElementById('proxy-category-help');
 var city=document.getElementById('proxy-city'),district=document.getElementById('proxy-district'),detail=document.getElementById('proxy-address'),panel=document.getElementById('proxy-map-panel'),frame=document.getElementById('proxy-map-frame'),open=document.getElementById('proxy-map-open'),close=document.getElementById('proxy-map-close'),reselect=document.getElementById('proxy-map-reselect'),lat=document.getElementById('proxy-map-lat'),lng=document.getElementById('proxy-map-lng'),summary=document.getElementById('proxy-map-summary');
 function openMap(){panel.hidden=false;if(!frame.src)frame.src=frame.getAttribute('data-src')||'';}function closeMap(){panel.hidden=true;}function clearMap(){lat.value='';lng.value='';summary.textContent='城市或区县已修改，请重新地图选点';reselect.hidden=true;}
 function selectedCategories(){return categoryList?Array.prototype.slice.call(categoryList.querySelectorAll('input[name="answers[category_ids][]"]:checked')):[];}
 function syncCategories(e){var selected=selectedCategories();if(selected.length>6&&e&&e.target){e.target.checked=false;selected=selectedCategories();}if(selected.length&&categoryMode&&categoryMode.value!=='listed')categoryMode.value='listed';if(categoryHelp)categoryHelp.textContent='已选 '+selected.length+' / 6 项；选择其他/暂不确定会清空上方类目。';}
 function syncCategoryMode(){if(!categoryMode)return;var mode=categoryMode.value;if(mode!=='listed'&&categoryList)categoryList.querySelectorAll('input[type="checkbox"]').forEach(function(el){el.checked=false;});if(categoryOther)categoryOther.disabled=mode!=='other';syncCategories();}
 if(workflow)workflow.addEventListener('change',function(){if(workflow.value==='measurement'){dispatch.value='multi_quote';dispatch.disabled=true;}else dispatch.disabled=false;});
 if(categoryList)categoryList.addEventListener('change',syncCategories);if(categoryMode)categoryMode.addEventListener('change',syncCategoryMode);syncCategoryMode();
 if(open)open.addEventListener('click',openMap);if(close)close.addEventListener('click',closeMap);if(reselect)reselect.addEventListener('click',openMap);if(city)city.addEventListener('change',function(){if(lat.value)clearMap();});if(district)district.addEventListener('change',function(){if(lat.value)clearMap();});
 window.addEventListener('message',function(event){if(!event||!event.data||event.data.module!=='locationPicker')return;if(event.origin&&event.origin.indexOf('apis.map.qq.com')===-1)return;var loc=event.data.latlng||{},a=Number(loc.lat),b=Number(loc.lng);if(!Number.isFinite(a)||!Number.isFinite(b))return;lat.value=String(a);lng.value=String(b);var name=String(event.data.poiname||''),address=String(event.data.poiaddress||'');if(detail&&!String(detail.value||'').trim())detail.value=[address,name].filter(Boolean).join(' ').trim();summary.textContent='已选：'+(name||address||a+','+b)+' · '+a.toFixed(6)+','+b.toFixed(6);reselect.hidden=false;closeMap();});
 if(form)form.addEventListener('submit',function(e){if(workflow&&workflow.value==='measurement'&&dispatch)dispatch.disabled=false;var mode=categoryMode?categoryMode.value:'listed',selected=selectedCategories();if(mode==='listed'&&!selected.length){e.preventDefault();if(categoryHelp)categoryHelp.textContent='请选择至少一个服务类目，或改为“其他/暂不确定”';return;}if(mode==='other'&&(!categoryOther||!String(categoryOther.value||'').trim())){e.preventDefault();if(categoryHelp)categoryHelp.textContent='选择“其他类目”时请填写类目名称';return;}if(!lat.value||!lng.value){e.preventDefault();openMap();summary.textContent='请先完成地图选点后再创建';}});
})();
</script>
