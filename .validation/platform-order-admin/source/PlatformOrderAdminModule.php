<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Admin\Modules;

use Throwable;
use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Foundation\OrderApplicationContainer;
use WorkerAdmin\Http\Admin\AdminModule;

final class PlatformOrderAdminModule implements AdminModule
{
    private Auth $auth;
    private Csrf $csrf;
    private OrderApplicationContainer $container;
    private object $prepaymentManager;

    public function __construct(Database $db, Auth $auth, Csrf $csrf)
    {
        $this->auth = $auth;
        $this->csrf = $csrf;
        $this->container = new OrderApplicationContainer($db);
        $this->prepaymentManager = $this->container->platformOrderPrepaymentManager();
    }

    public function supports(string $route): bool{return $route === 'platform-orders' || strpos($route, 'platform-orders.') === 0;}

    public function dispatch(string $route): void
    {
        $this->auth->requireLogin();
        $admin = $this->auth->user();
        if (!empty($admin['must_change_password'])) {\flash('warning', '首次登录必须先修改演示密码。');\redirect(\admin_url('profile'));}
        $this->auth->requirePermission('orders.manage');

        $map = [
            'platform-orders'=>'index','platform-orders.create'=>'create','platform-orders.booking-fee'=>'externalPrepayment',
            'platform-orders.publish'=>'publish','platform-orders.select-quote'=>'selectQuote','platform-orders.invite'=>'createInvite',
            'platform-orders.invite.revoke'=>'revokeInvite','platform-orders.pricing-review'=>'pricingReview',
        ];
        if (!isset($map[$route])) {http_response_code(404);echo \view('errors/404', ['pageTitle'=>'页面不存在']);return;}

        try {
            $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            if ($method === 'POST') $this->csrf->verifyRequest();
            $adminId = (int)($admin['id'] ?? 0);

            if ($route === 'platform-orders.pricing-review') {
                $orderNo = trim((string)($method === 'POST' ? ($_POST['order_no'] ?? '') : ($_GET['order_no'] ?? '')));
                $actor = ['is_admin'=>true,'user_id'=>0,'real_user_id'=>$adminId];
                if ($method === 'POST') {
                    $body = $_POST;$body['order_id'] = $orderNo;
                    $this->container->platformOrderPricingReview()->review($actor, $body);
                    \flash('success', '核价已完成并生效，师傅可继续预约施工。');\redirect(\admin_url('platform-orders'));return;
                }
                if ($method !== 'GET') throw new OrderOperationException('请求方式不正确', 'METHOD_NOT_ALLOWED', 405);
                $detail = $this->container->detail()->query($actor, $orderNo);
                if (empty($detail['order']['is_platform_order'])) throw new OrderOperationException('当前订单不是平台承接单', 'PLATFORM_ORDER_REQUIRED', 409);
                echo \view('admin/platform-order-pricing-review', ['pageTitle'=>'平台单测量后核价','activeMenu'=>'platform-orders','detail'=>$detail]);return;
            }

            if ($route === 'platform-orders') {
                if ($method !== 'GET') throw new OrderOperationException('请求方式不正确', 'METHOD_NOT_ALLOWED', 405);
                $data = $this->container->platformOrderAdmin()->dashboard();
                $data['entryForm'] = $this->container->submission()->form([
                    'user_id'=>0,'user_public_id'=>'admin-platform-preview','mobile_masked'=>'138******00','is_demo_actor'=>false,
                ]);
                // 类目和动态字段直接以普通/代下单的当前 order_submit 为准，避免后台平台页再维护第二套可选范围。
                if (!empty($data['entryForm']['categories']) && is_array($data['entryForm']['categories'])) {
                    $data['categories'] = $data['entryForm']['categories'];
                }
                $generatedInvite = $_SESSION['_generated_platform_invite'] ?? null;unset($_SESSION['_generated_platform_invite']);
                echo \view('admin/platform-orders', array_merge(['pageTitle'=>'平台承接单','activeMenu'=>'platform-orders','generatedInvite'=>$generatedInvite], $data));
                echo $this->sharedFormFieldsScript($data['entryForm']);
                return;
            }
            if ($method !== 'POST') throw new OrderOperationException('请求方式不正确', 'METHOD_NOT_ALLOWED', 405);

            if ($route === 'platform-orders.create') {
                $orderId = $this->container->platformOrderAdmin()->create($_POST, $adminId);
                \flash('success', '平台托管单已创建；施工事实使用当前普通下单表单，外部预付款按平台条款记录，系统核验通过后自动发布。');
                \redirect(\admin_url('platform-orders', ['created'=>$orderId]));
            }
            if ($route === 'platform-orders.booking-fee') {
                $orderId = (int)($_POST['order_id'] ?? 0);
                $raw = (string)($_POST['external_prepayment'] ?? ($_POST['booking_fee'] ?? ''));
                $prepayment = $this->moneyToCents($raw);
                $change = $this->prepaymentManager->update($orderId, $prepayment, $adminId);
                \audit('platform_order.external_prepayment','order',$orderId,'管理员修改平台托管单外部已收预付款',$change['before'], $change['after']);
                \flash('success', '外部已收预付款已更新；不会生成微信支付记录。');\redirect(\admin_url('platform-orders'));
            }
            if ($route === 'platform-orders.publish') {
                $this->container->platformOrderAdmin()->publish((int)($_POST['order_id'] ?? 0), $adminId);
                \flash('success', '自动发布异常已人工修复，平台承接单已发布到接单大厅。');\redirect(\admin_url('platform-orders'));
            }
            if ($route === 'platform-orders.select-quote') {
                $this->container->platformOrderAdmin()->selectQuote((int)($_POST['order_id'] ?? 0),(int)($_POST['quote_id'] ?? 0),$adminId);
                \flash('success', '已选择师傅报价并自动成交，无需师傅再次确认接单。');\redirect(\admin_url('platform-orders'));
            }
            if ($route === 'platform-orders.invite') {
                $result = $this->container->platformOrderAdmin()->createInvite((int)($_POST['order_id'] ?? 0),$adminId,(int)($_POST['valid_days'] ?? 7));
                $payload = '/pages/workerOrder/detail?invite=' . rawurlencode((string)$result['token']);
                $_SESSION['_generated_platform_invite'] = [
                    'order_id'=>(int)$result['order_id'],'order_no'=>(string)$result['order_no'],'invite_id'=>(int)$result['invite_id'],
                    'dispatch_mode'=>(string)$result['dispatch_mode'],'token'=>(string)$result['token'],'payload'=>$payload,'expires_at'=>(string)$result['expires_at'],
                ];
                \flash('success', (string)$result['dispatch_mode'] === 'multi_quote' ? '邀请已生成：师傅领取后仍需提交工费报价。' : '邀请已生成：师傅领取后按一口价工费直接成交。');
                \redirect(\admin_url('platform-orders'));
            }
            if ($route === 'platform-orders.invite.revoke') {
                $this->container->platformOrderAdmin()->revokeInvite((int)($_POST['order_id'] ?? 0),(int)($_POST['invite_id'] ?? 0),$adminId);
                \flash('success', '平台单邀请已撤销。');\redirect(\admin_url('platform-orders'));
            }
        } catch (OrderOperationException $exception) {
            \flash('danger', $exception->getMessage());\redirect(\admin_url('platform-orders'));
        } catch (Throwable $exception) {
            $requestId = \request_id();\write_log('platform-order-admin', '['.date('c').'] request_id='.$requestId.' '.$exception."\n");
            \flash('danger', '平台承接单处理失败，请稍后重试。请求编号：'.$requestId);\redirect(\admin_url('platform-orders'));
        }
    }

    /** @param array<string,mixed> $form */
    private function sharedFormFieldsScript(array $form): string
    {
        $fields = is_array($form['fields'] ?? null) ? $form['fields'] : [];
        $rules = is_array($form['rules'] ?? null) ? $form['rules'] : [];
        $fieldsJson = json_encode($fields, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
        $rulesJson = json_encode($rules, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
        if (!is_string($fieldsJson)) $fieldsJson = '[]';
        if (!is_string($rulesJson)) $rulesJson = '[]';

        $script = <<<'HTML'
<script>
(function(){
  var fields=__FIELDS_JSON__,rules=__RULES_JSON__;
  var form=document.getElementById("platform-order-form");
  var anchor=form&&form.querySelector('textarea[name="description"]');
  if(!form||!anchor||!fields.length)return;

  var skip={workflow_profile:1,category_id:1,category_ids:1,category_mode:1,category_other:1,material_images:1,product_images:1,wall_images:1,expected_fee:1,expected_fee_type:1,contact_name:1,contact_mobile:1,requirement_note:1};
  var blocked={image_upload:1,wechat_address:1,category_picker:1};
  var aliases={text_input:"text",number_input:"number",single:"radio",multiple:"checkbox",date:"date_picker",image:"image_upload",link:"url_input",address:"wechat_address",map:"wechat_address"};
  var rows={},dynamicFields=[];

  function optionsOf(field){return Array.isArray(field.options)?field.options:[];}
  function canonicalType(field){
    var type=String(field.component_type||"text").toLowerCase();
    type=aliases[type]||type;
    if(type==="boolean")return optionsOf(field).length?"radio":"switch";
    return type;
  }
  function answerName(key){return "answers["+key+"]";}
  function optionValue(option){
    return String(option.value_text!==undefined&&option.value_text!==null?option.value_text:(option.option_key||""));
  }
  function applyDefault(control,value){
    if(value===undefined||value===null||Array.isArray(value))return;
    control.value=String(value);
  }
  function applyValidation(control,field,type){
    var validation=field&&field.validation&&typeof field.validation==="object"?field.validation:{};
    if((type==="text"||type==="textarea"||type==="phone"||type==="url_input")&&validation.max_length){
      control.maxLength=Math.max(1,Number(validation.max_length)||1);
    }
    if(type==="number"){
      if(validation.min!==undefined&&validation.min!==null&&validation.min!=="")control.min=String(validation.min);
      if(validation.max!==undefined&&validation.max!==null&&validation.max!=="")control.max=String(validation.max);
    }
  }
  function appendSelect(row,field,key){
    var select=document.createElement("select");
    select.className="admin-control";
    select.name=answerName(key);
    var empty=document.createElement("option");
    empty.value="";empty.textContent="请选择";
    select.appendChild(empty);
    optionsOf(field).forEach(function(option){
      var item=document.createElement("option");
      item.value=optionValue(option);
      item.textContent=String(option.label||item.value);
      select.appendChild(item);
    });
    applyDefault(select,field.default_value);
    row.appendChild(select);
  }
  function appendCheckboxes(row,field,key){
    var grid=document.createElement("div");
    grid.className="admin-check-grid";
    var defaults=Array.isArray(field.default_value)?field.default_value.map(String):[];
    optionsOf(field).forEach(function(option){
      var label=document.createElement("label"),input=document.createElement("input");
      input.type="checkbox";
      input.name=answerName(key)+"[]";
      input.value=optionValue(option);
      input.checked=defaults.indexOf(String(input.value))>=0;
      label.appendChild(input);
      label.appendChild(document.createTextNode(" "+String(option.label||input.value)));
      grid.appendChild(label);
    });
    row.appendChild(grid);
  }
  function appendSwitch(row,field,key){
    var select=document.createElement("select");
    select.className="admin-control";
    select.name=answerName(key);
    [["","请选择"],["0","否"],["1","是"]].forEach(function(pair){
      var option=document.createElement("option");
      option.value=pair[0];option.textContent=pair[1];select.appendChild(option);
    });
    var value=field.default_value;
    if(value===true)value="1";
    else if(value===false)value="0";
    applyDefault(select,value);
    row.appendChild(select);
  }
  function appendDateRange(row,field,key){
    var wrap=document.createElement("div");
    wrap.className="admin-inline-actions";
    var defaults=Array.isArray(field.default_value)?field.default_value:[];
    ["开始日期","结束日期"].forEach(function(placeholder,index){
      var input=document.createElement("input");
      input.className="admin-control";
      input.type="date";
      input.name=answerName(key)+"[]";
      input.setAttribute("aria-label",String(field.label||key)+" "+placeholder);
      if(defaults[index])input.value=String(defaults[index]);
      wrap.appendChild(input);
    });
    row.appendChild(wrap);
  }
  function appendTextControl(row,field,key,type){
    var control;
    if(type==="textarea"){
      control=document.createElement("textarea");
      control.rows=3;
    }else{
      control=document.createElement("input");
      if(type==="date_picker")control.type="date";
      else if(type==="number"){
        control.type="number";
        control.step=String(field.data_type||"")==="integer"?"1":"any";
      }else if(type==="url_input")control.type="url";
      else if(type==="phone")control.type="tel";
      else control.type="text";
    }
    control.className="admin-control";
    control.name=answerName(key);
    control.placeholder=String(field.placeholder||"");
    applyValidation(control,field,type);
    applyDefault(control,field.default_value);
    row.appendChild(control);
    if(key==="expected_visit_date"&&type==="date_picker"){
      var period=document.createElement("select");
      period.className="admin-control";
      period.name="answers[expected_visit_period]";
      [["","请选择时段"],["morning","上午"],["afternoon","下午"],["evening","晚上"]].forEach(function(pair){
        var option=document.createElement("option");
        option.value=pair[0];option.textContent=pair[1];period.appendChild(option);
      });
      row.appendChild(period);
    }
  }

  var box=document.createElement("div");
  box.className="admin-card admin-card--soft admin-mb-3";
  var head=document.createElement("div");
  head.className="admin-card__header";
  head.innerHTML="<div><strong>服务资料</strong><small>与代下单共用当前 order_submit 动态表单；仅显示当前条件实际需要填写的字段</small></div>";
  var body=document.createElement("div");
  body.className="admin-card__body admin-form-grid";
  dynamicFields.length=0;
  rows={};

  fields.forEach(function(field){
    var key=String(field.field_key||""),type=canonicalType(field);
    if(!key||skip[key]||blocked[type])return;
    var row=document.createElement("div");
    row.className="admin-field"+(type==="textarea"||type==="checkbox"||type==="date_range"?" admin-field--full":"");
    row.dataset.platformFieldKey=key;
    row.dataset.platformFieldType=type;
    var label=document.createElement("label");
    label.dataset.baseLabel=String(field.label||key);
    label.textContent=label.dataset.baseLabel;
    row.appendChild(label);
    if(type==="radio")appendSelect(row,field,key);
    else if(type==="checkbox")appendCheckboxes(row,field,key);
    else if(type==="switch")appendSwitch(row,field,key);
    else if(type==="date_range")appendDateRange(row,field,key);
    else appendTextControl(row,field,key,type);
    if(field.help_text){
      var small=document.createElement("small");
      small.textContent=String(field.help_text);
      row.appendChild(small);
    }
    rows[key]=row;
    dynamicFields.push(field);
    body.appendChild(row);
  });
  if(!dynamicFields.length)return;
  box.appendChild(head);box.appendChild(body);
  anchor.closest(".admin-field").insertAdjacentElement("afterend",box);

  function fieldValue(key){
    var row=rows[key];
    if(!row)return undefined;
    var type=String(row.dataset.platformFieldType||"");
    var controls=Array.prototype.slice.call(row.querySelectorAll("input,select,textarea"));
    if(type==="checkbox")return controls.filter(function(control){return control.checked;}).map(function(control){return control.value;});
    if(type==="date_range")return controls.map(function(control){return control.value;});
    return controls.length?controls[0].value:undefined;
  }
  function selectedCategoryIds(){
    var categoryList=document.getElementById("platform-category-list");
    if(!categoryList)return [];
    return Array.prototype.slice.call(categoryList.querySelectorAll('input[name="category_ids[]"]:checked')).map(function(input){return Number(input.value);}).filter(Boolean);
  }
  function fixedAnswers(){
    var categoryIds=selectedCategoryIds();
    var fulfillment=form.querySelector('[name="fulfillment_mode"]');
    var description=form.querySelector('[name="description"]');
    var contactName=form.querySelector('[name="contact_name"]');
    var contactMobile=form.querySelector('[name="contact_mobile"]');
    var visitPeriod=form.querySelector('[name="answers[expected_visit_period]"]');
    return {
      workflow_profile:fulfillment&&fulfillment.value==="measure_then_install"?"measurement":"construction",
      category_ids:categoryIds,
      category_id:categoryIds.length?categoryIds[0]:null,
      category_mode:"listed",
      requirement_note:description?description.value:"",
      contact_name:contactName?contactName.value:"",
      contact_mobile:contactMobile?contactMobile.value:"",
      expected_visit_period:visitPeriod?visitPeriod.value:""
    };
  }
  function ruleMatches(operator,actual,compare){
    var actualValues=Array.isArray(actual)?actual.map(String):(actual===undefined||actual===null?[]:[String(actual)]);
    var compareValues=Array.isArray(compare)?compare.map(String):(compare===undefined||compare===null?[]:[String(compare)]);
    var equals=compare===null||compare===undefined
      ? actual===null||actual===undefined
      : Array.isArray(actual)
        ? actualValues.length>0&&compareValues.length===1&&actualValues.indexOf(compareValues[0])>=0
        : !Array.isArray(compare)&&actualValues.length===1&&compareValues.length===1&&actualValues[0]===compareValues[0];
    var intersects=actualValues.some(function(value){return compareValues.indexOf(value)>=0;});
    var truthy=Array.isArray(actual)?actual.length>0:!(actual===undefined||actual===null||actual===false||actual===""||actual===0);
    if(operator==="not_equals")return !equals;
    if(operator==="in")return Array.isArray(compare)&&actualValues.length>0&&intersects;
    if(operator==="not_in")return Array.isArray(compare)&&!intersects;
    if(operator==="truthy")return truthy;
    if(operator==="falsy")return !truthy;
    return equals;
  }
  function buildStates(answers){
    var states={};
    function stateFor(key){
      if(!states[key])states[key]={visible:true,required:false,disabled:false};
      return states[key];
    }
    rules.forEach(function(rule){
      if(rule.target_key&&rule.action_type==="show")stateFor(String(rule.target_key)).visible=false;
    });
    rules.forEach(function(rule){
      var key=String(rule.target_key||"");
      if(!key)return;
      var actual=answers[String(rule.trigger_key||"")];
      if(!ruleMatches(String(rule.operator||"equals"),actual,rule.compare_value))return;
      var state=stateFor(key),action=String(rule.action_type||"");
      if(action==="show")state.visible=true;
      else if(action==="hide")state.visible=false;
      else if(action==="require")state.required=true;
      else if(action==="disable")state.disabled=true;
    });
    return states;
  }
  function categoryLimited(field,answers){
    var ui=field.ui&&typeof field.ui==="object"?field.ui:{};
    var limited=Array.isArray(field.category_ids)?field.category_ids:(Array.isArray(ui.category_ids)?ui.category_ids:[]);
    if(!limited.length)return false;
    var selected=Array.isArray(answers.category_ids)?answers.category_ids.map(Number):[];
    return !limited.some(function(id){return selected.indexOf(Number(id))>=0;});
  }
  function visibleWhenMatches(field,answers){
    var ui=field.ui&&typeof field.ui==="object"?field.ui:{};
    if(!ui.visible_when)return true;
    var parts=String(ui.visible_when).split("=");
    var actual=answers[parts[0]];
    return String(actual===undefined||actual===null?"":actual)===String(parts[1]||"");
  }
  function isVisible(field,states,answers){
    var key=String(field.field_key||"");
    if(categoryLimited(field,answers))return false;
    if(states[key]&&states[key].visible===false)return false;
    return visibleWhenMatches(field,answers);
  }
  function resolveRuntime(){
    var answers=fixedAnswers();
    dynamicFields.forEach(function(field){answers[String(field.field_key||"")]=fieldValue(String(field.field_key||""));});
    var states={};
    for(var pass=0;pass<=dynamicFields.length;pass+=1){
      if(!answers.expected_visit_date)delete answers.expected_visit_period;
      states=buildStates(answers);
      var changed=false;
      dynamicFields.forEach(function(field){
        var key=String(field.field_key||"");
        if(isVisible(field,states,answers))return;
        if(Object.prototype.hasOwnProperty.call(answers,key)){delete answers[key];changed=true;}
      });
      if(!changed)break;
    }
    return {answers:answers,states:buildStates(answers)};
  }
  function applyRuntime(){
    var runtime=resolveRuntime();
    dynamicFields.forEach(function(field){
      var key=String(field.field_key||""),row=rows[key];
      if(!row)return;
      var state=runtime.states[key]||{visible:true,required:false,disabled:false};
      var visible=isVisible(field,runtime.states,runtime.answers);
      var required=visible&&!state.disabled&&(!!field.is_required||!!state.required);
      row.hidden=!visible;
      var label=row.querySelector("label");
      if(label)label.textContent=String(label.dataset.baseLabel||field.label||key)+(required?" *":"");
      var controls=Array.prototype.slice.call(row.querySelectorAll("input,select,textarea"));
      controls.forEach(function(control){
        control.disabled=!visible||!!state.disabled;
        control.required=required&&String(row.dataset.platformFieldType||"")!=="checkbox";
      });
      if(key==="expected_visit_date"){
        var period=row.querySelector('[name="answers[expected_visit_period]"]');
        if(period)period.required=visible&&!state.disabled&&!!fieldValue(key);
      }
    });
  }

  form.addEventListener("change",applyRuntime);
  form.addEventListener("input",applyRuntime);
  applyRuntime();
})();
</script>
HTML;
        return strtr($script, ['__FIELDS_JSON__'=>$fieldsJson, '__RULES_JSON__'=>$rulesJson]);
    }

    private function moneyToCents(string $value): int
    {
        $value = trim(str_replace([',','￥','¥','元'], '', $value));
        if (!preg_match('/^(\d{1,8})(?:\.(\d{1,2}))?$/D', $value, $parts)) {
            throw new OrderOperationException('请输入有效外部已收预付款金额，最多保留两位小数。', 'PLATFORM_BOOKING_AMOUNT_INVALID', 422);
        }
        return ((int)$parts[1]) * 100 + (int)str_pad($parts[2] ?? '', 2, '0');
    }
}
