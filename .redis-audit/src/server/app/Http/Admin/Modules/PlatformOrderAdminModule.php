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
        $json = json_encode($fields, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
        if (!is_string($json)) $json = '[]';
        return '<script>(function(){var fields='.$json.',form=document.getElementById("platform-order-form"),anchor=form&&form.querySelector("textarea[name=description]");if(!form||!anchor||!fields.length)return;var skip={workflow_profile:1,category_id:1,category_ids:1,category_mode:1,category_other:1,material_images:1,product_images:1,wall_images:1,expected_fee:1,expected_fee_type:1,contact_name:1,contact_mobile:1,requirement_note:1};var blocked={image:1,image_upload:1,address:1,wechat_address:1,map:1};var box=document.createElement("div");box.className="admin-card admin-card--soft admin-mb-3";var head=document.createElement("div");head.className="admin-card__header";head.innerHTML="<div><strong>服务资料</strong><small>与代下单共用当前 order_submit 动态表单；平台价格与结算仍单独填写</small></div>";var body=document.createElement("div");body.className="admin-card__body admin-form-grid";box.appendChild(head);box.appendChild(body);var escName=function(k){return "answers["+k+"]"};fields.forEach(function(f){var key=String(f.field_key||""),type=String(f.component_type||"text");if(!key||skip[key]||blocked[type])return;var row=document.createElement("div");row.className="admin-field"+(type==="textarea"||type==="checkbox"||type==="multiple"?" admin-field--full":"");var limited=Array.isArray(f.category_ids)?f.category_ids.map(Number).filter(Boolean):[];if(limited.length)row.dataset.categoryIds=limited.join(",");var label=document.createElement("label");label.textContent=String(f.label||key)+(f.is_required?" *":"");row.appendChild(label);var options=Array.isArray(f.options)?f.options:[];if((type==="radio"||type==="single"||type==="select"||type==="boolean")&&options.length){var sel=document.createElement("select");sel.className="admin-control";sel.name=escName(key);if(f.is_required)sel.required=true;var empty=document.createElement("option");empty.value="";empty.textContent="请选择";sel.appendChild(empty);options.forEach(function(o){var opt=document.createElement("option");opt.value=String(o.value_text!==undefined?o.value_text:(o.option_key||""));opt.textContent=String(o.label||opt.value);sel.appendChild(opt);});row.appendChild(sel);}else if((type==="checkbox"||type==="multiple")&&options.length){var grid=document.createElement("div");grid.className="admin-check-grid";options.forEach(function(o){var lab=document.createElement("label"),input=document.createElement("input");input.type="checkbox";input.name=escName(key)+"[]";input.value=String(o.value_text!==undefined?o.value_text:(o.option_key||""));lab.appendChild(input);lab.appendChild(document.createTextNode(" "+String(o.label||input.value)));grid.appendChild(lab);});row.appendChild(grid);}else if(type==="textarea"){var ta=document.createElement("textarea");ta.className="admin-control";ta.name=escName(key);ta.rows=3;ta.placeholder=String(f.placeholder||"");if(f.is_required)ta.required=true;row.appendChild(ta);}else{var input=document.createElement("input");input.className="admin-control";input.name=escName(key);input.type=type==="date"?"date":(type==="number"?"number":"text");if(type==="number")input.step="any";input.placeholder=String(f.placeholder||"");if(f.is_required)input.required=true;row.appendChild(input);}if(f.help_text){var small=document.createElement("small");small.textContent=String(f.help_text);row.appendChild(small);}body.appendChild(row);});if(!body.children.length)return;anchor.closest(".admin-field").insertAdjacentElement("afterend",box);var categories=document.getElementById("platform-category-list");function sync(){var selected=[];if(categories)categories.querySelectorAll("input[name=\\"category_ids[]\\"]:checked").forEach(function(el){selected.push(Number(el.value));});body.querySelectorAll("[data-category-ids]").forEach(function(row){var ids=String(row.dataset.categoryIds||"").split(",").map(Number);var visible=!ids.length||ids.some(function(id){return selected.indexOf(id)>=0;});row.hidden=!visible;row.querySelectorAll("input,select,textarea").forEach(function(el){if(!visible){el.dataset.wasRequired=el.required?"1":"0";el.required=false;}else if(el.dataset.wasRequired==="1"){el.required=true;delete el.dataset.wasRequired;}});});}if(categories)categories.addEventListener("change",sync);sync();})();</script>';
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
