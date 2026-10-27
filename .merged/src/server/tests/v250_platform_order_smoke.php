<?php

declare(strict_types=1);

$root=dirname(__DIR__,2);
function readRepoFile(string $root,string $relative):string{$path=$root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);$c=@file_get_contents($path);if(!is_string($c)){fwrite(STDERR,"[FAIL] 无法读取 {$relative}\n");exit(1);}return $c;}
function pass(string $label):void{fwrite(STDOUT,"[PASS] {$label}\n");}
function failSmoke(string $label,string $detail=''):void{fwrite(STDERR,"[FAIL] {$label}".($detail!==''?"\n{$detail}":'')."\n");exit(1);}
function expectContains(string $haystack,string $needle,string $label):void{if(strpos($haystack,$needle)===false)failSmoke($label,"缺少：{$needle}");pass($label);}
function expectNotContains(string $haystack,string $needle,string $label):void{if(strpos($haystack,$needle)!==false)failSmoke($label,"不应存在：{$needle}");pass($label);}
function expectSame($actual,$expected,string $label):void{if($actual!==$expected)failSmoke($label,'expected='.var_export($expected,true).' actual='.var_export($actual,true));pass($label);}

$migration=readRepoFile($root,'server/database/upgrades/2.5.0.sql');
$adminApp=readRepoFile($root,'server/app/Application/Order/PlatformOrderAdmin.php');
$adminRepo=readRepoFile($root,'server/app/Infrastructure/Persistence/DatabasePlatformOrderAdminRepository.php');
$repairGuard=readRepoFile($root,'server/app/Infrastructure/Persistence/RepairGuardedPlatformOrderAdminRepository.php');
$adminModule=readRepoFile($root,'server/app/Http/Admin/Modules/PlatformOrderAdminModule.php');
$adminView=readRepoFile($root,'server/views/admin/platform-orders.php');
$platformCommon=readRepoFile($root,'server/app/Infrastructure/Persistence/PlatformOrderCommonFactsWriter.php');
$platformMeasure=readRepoFile($root,'server/app/Infrastructure/Persistence/MeasurementAwarePlatformOrderAdminRepository.php');
$mediaRules=readRepoFile($root,'server/app/Infrastructure/Persistence/OrderFormMediaRules.php');
$orderSubmissionLifecycle=readRepoFile($root,'server/app/Infrastructure/Persistence/OrderSubmissionLifecycleOperations.php');
$verifier=readRepoFile($root,'server/app/Infrastructure/Persistence/PlatformOrderBookingPaymentVerifier.php');
$settlementWriter=readRepoFile($root,'server/app/Infrastructure/Persistence/PlatformOrderSettlementWriter.php');
$pricingReview=readRepoFile($root,'server/app/Infrastructure/Persistence/DatabasePlatformOrderPricingReviewRepository.php');
$pricingReadiness=readRepoFile($root,'server/app/Infrastructure/Persistence/PlatformOrderPricingReviewReadiness.php');
$autoPublish=readRepoFile($root,'server/app/Infrastructure/Scheduling/PlatformOrderAutoPublishTask.php');
$platformPricing=readRepoFile($root,'miniprogram/components/platform-order-pricing/index.js');
$platformSettlement=readRepoFile($root,'miniprogram/components/platform-order-settlement/index.js');
$measurementComponent=readRepoFile($root,'miniprogram/components/measurement-order-detail/index.js');
$measurementView=readRepoFile($root,'miniprogram/components/measurement-order-detail/index.wxml');
$pricingApi=readRepoFile($root,'server/app/Http/Api/Modules/PlatformOrderPricingReviewApiModule.php');
$legacyApi=readRepoFile($root,'server/app/Http/Api/Modules/LegacyApiModule.php');
$paymentApi=readRepoFile($root,'server/app/Http/Api/Modules/PaymentCreationApiModule.php');
$webAdminOrder=readRepoFile($root,'server/app/Application/Order/WebAdminOrder.php');
$webComposition=readRepoFile($root,'server/app/Foundation/WebAdminOrderComposition.php');
$orderAdminRepo=readRepoFile($root,'server/app/Infrastructure/Persistence/DatabaseAdminOrderRepository.php');
$orderAdminModule=readRepoFile($root,'server/app/Http/Admin/Modules/OrderAdminModule.php');
$proxyView=readRepoFile($root,'server/views/admin/orders/proxy-create.php');
$orderApi=readRepoFile($root,'miniprogram/utils/OrderApi.js');
$adminWrite=readRepoFile($root,'server/app/Infrastructure/Persistence/DatabaseAdminOrderWriteStore.php');
$quotePolicy=readRepoFile($root,'server/app/Domain/Order/QuotePolicy.php');
$dealRepo=readRepoFile($root,'server/app/Infrastructure/Persistence/DatabaseOrderDealRepository.php');
$dealQuote=readRepoFile($root,'server/app/Infrastructure/Persistence/OrderDealQuoteOperations.php');
$dealAssignment=readRepoFile($root,'server/app/Infrastructure/Persistence/OrderDealAssignmentOperations.php');
$invitationRepo=readRepoFile($root,'server/app/Infrastructure/Persistence/DatabaseOrderInvitationRepository.php');
$pricingRepo=readRepoFile($root,'server/app/Infrastructure/Persistence/DatabaseOrderPricingRepository.php');
$settlementPolicy=readRepoFile($root,'server/app/Domain/Order/PlatformOrderSettlementPolicy.php');

// 平台托管边界与资金口径。
expectContains($migration,'CREATE TABLE IF NOT EXISTS `order_platform_terms`','平台承接单继续复用统一 orders + 专属条款表');
expectContains($migration,'CREATE TABLE IF NOT EXISTS `order_pricing_items`','平台结构化计价明细存在');
expectContains($migration,"('platform_order.worker_balance_freeze_days','0'",'平台余额冻结天数默认 0');
expectContains($adminApp,"'customer_flow_mode'=>'platform_managed'",'平台创建用例固定托管模式');
expectContains($adminApp,"'external_prepayment_cents'=>",'平台创建命令使用外部预付款字段');
expectNotContains($adminApp,"['platform_managed','miniapp_booking']",'平台创建用例不再接受 miniapp_booking 枚举');
expectContains($adminRepo,"BINARY s.schema_key=BINARY 'order_submit'",'平台承接单只绑定普通 order_submit 发布版本');
expectContains($adminRepo,"VALUES(:order_id,'platform_managed'",'平台条款固定写 platform_managed');
expectContains($adminRepo,'booking_paid_cents','平台条款保存外部已收预付款');
expectContains($repairGuard,"payment_status='not_required'",'平台托管新单不再伪装普通线上预约费已支付');
expectNotContains($autoPublish,"o.payment_status = 'paid'",'平台自动发布不再以 payment_status 作为资金证明');
expectContains($verifier,"business_type IN ('order_booking','booking_fee')",'平台资金核验检测普通微信预约费串线');
expectContains($verifier,'PLATFORM_BOOKING_UNEXPECTED_PAYMENT','平台微信预约费串线有明确错误码');
expectContains($settlementWriter,"(int)(\$terms['booking_paid_cents'] ?? 0)",'平台结算只读取平台专属外部预付款');
expectNotContains($settlementWriter,"(int)\$order['booking_fee_cents']",'平台结算不再读取普通预约费字段');
expectContains($quotePolicy,"order_source_type'] ?? 'consumer') !== 'platform'",'普通/商家订单才依赖线上 paid 作为报价门槛');

// 后台代下单与平台承接单共享施工事实：Form Schema、多类目、地图、联系人。
expectContains($adminApp,"'category_ids'=>\$categoryIds",'平台承接单接收普通订单同款多类目');
expectContains($adminApp,"'answers'=>\$answers",'平台承接单携带共享动态表单答案');
expectContains($adminApp,"preg_match('/^1\\d{10}(?:(?:-|转)\\d{1,10})?$/u'",'平台联系人支持普通订单同款虚拟号格式');
expectContains($repairGuard,'DELETE FROM order_categories','平台创建后重建完整多类目关系');
expectContains($repairGuard,'category_names_snapshot=:names','平台 orders 保存全部类目名称快照');
expectContains($repairGuard,'PlatformOrderCommonFactsWriter','平台动态施工事实实际接入共享写入器');
expectContains($repairGuard,'$this->db->transaction','平台共享事实校验与基础建单处于同一外层事务');
expectContains($platformCommon,'INSERT INTO order_answers','平台承接单实际写入动态 order_answers');
expectContains($platformCommon,'quantity_text=:quantity','平台承接单同步数量/面积普通快照');
expectContains($platformCommon,'expected_visit_at=:expected','平台承接单同步期望上门普通快照');
expectContains($adminModule,"\$data['categories'] = \$data['entryForm']['categories']",'平台后台类目直接采用普通 order_submit 返回集合');
expectContains($adminModule,'submission()->form','平台后台读取与代下单相同的 order_submit 表单');
expectContains($adminModule,'sharedFormFieldsScript','平台后台渲染共享动态业务字段');
expectContains($adminView,'name="category_ids[]"','平台页面支持最多六项多类目');
expectContains($adminView,'id="platform-map-lat"','平台页面提供腾讯地图纬度');
expectContains($adminView,'id="platform-map-lng"','平台页面提供腾讯地图经度');
expectContains($adminView,'name="province"','平台页面拆分省市区地址');
expectContains($adminView,'locationPicker','平台后台使用腾讯 locpicker');
expectNotContains($adminView,'miniapp_booking','平台后台 UI 不再暴露 miniapp_booking');
expectContains($adminRepo,"'GCJ-02'",'平台地址表明确保存 GCJ-02 坐标系');
expectContains($adminRepo,"\$this->cipher->encrypt(\$fullAddress, 'order-address')",'平台完整地址按省市区+详情统一加密');

// 普通代下单必须走正常 OrderSubmission，不允许旧后台旁路。
expectContains($webAdminOrder,'$this->submission->create($actor,$body)','普通代下单创建直接复用 OrderSubmission');
expectNotContains($webAdminOrder,'$this->orders->create($input','普通代下单不再调用旧固定字段 create 旁路');
expectContains($webComposition,'$container->submission()','Web 代下单组合根复用订单域 submission');
expectContains($orderAdminRepo,'wechat_identities wi','代下单只允许有有效微信身份的客户');
expectContains($orderAdminRepo,"empty(\$row['mobile_masked'])",'代下单客户必须已授权手机号');
expectContains($orderAdminModule,'orders.proxy-create','后台订单管理提供独立统一代下单入口');
expectContains($proxyView,'name="address[lat]"','代下单保存地图纬度');
expectContains($proxyView,'name="address[lng]"','代下单保存地图经度');
expectContains($proxyView,'locationPicker','代下单同样使用腾讯 locpicker');
expectContains($proxyView,'name="answers[category_ids][]"','代下单沿用普通多类目数据结构');
expectContains($proxyView,'当前已发布 order_submit','代下单页面明确复用当前普通表单');
expectContains($proxyView,"in_array(\$type,['wechat_address','address','map','image_upload','image'],true)",'代下单动态表单避免重复地址/图片组件');
expectContains($webAdminOrder,"unset(\$input['approve_now'], \$input['publish_now'])",'普通代下单应用层丢弃旧立即审核/发布参数');
expectContains($webAdminOrder,"payment_status'] ?? '') !== 'paid'",'普通代下单未真实支付禁止审核/公开');
expectNotContains($adminWrite,'approve_now','旧写仓储没有创建即审核参数');
expectNotContains($adminWrite,'publish_now','旧写仓储没有创建即公开参数');

// 客户本人确认协议 -> normal finalize -> 微信预约费，不能由管理员代确认。
expectContains($paymentApi,"'ORDER_FINALIZE_REQUIRED'",'预约费准入输出稳定 finalize-required 业务码');
expectContains($orderApi,'finalizeProxyDraftBeforePayment','代下单客户首次支付前本人完成确认与 finalize');
expectContains($orderApi,"request('forms.order'",'客户确认前重新读取当前平台规则');
expectContains($orderApi,"request('orders.finalize'",'客户确认复用普通 finalize API');
expectContains($orderApi,"error.code === 'ORDER_FINALIZE_REQUIRED'",'前端只依据稳定业务码触发代下单确认链');
expectNotContains($orderApi,"indexOf('请先完整提交订单资料和必传图片')",'前端不依赖中文错误文案判断业务流程');

// 图片、自动发布与平台成交仍保持既有安全边界。
expectContains($mediaRules,"'product'=>'material_images'",'普通/平台图片规则统一使用 material_images');
expectContains($orderSubmissionLifecycle,'OrderFormMediaRules::rule','普通订单 finalize 复用统一图片规则');
expectContains($autoPublish,"customer_flow_mode='platform_managed'",'自动发布只扫描托管平台单');
expectContains($autoPublish,'o.booking_fee_cents=0','自动发布拒绝残留普通预约费字段');
expectContains($autoPublish,'PlatformOrderBookingPaymentVerifier','自动发布仍执行托管资金复核');
expectContains($dealRepo,'PlatformOrderBookingPaymentVerifier','平台报价/抢单最终资格重新核验托管资金');
expectContains($dealQuote,'$externalPrepayment','平台工费报价按外部预付款计算负差额');
expectContains($dealAssignment,'$externalPrepayment','平台一口价抢单按外部预付款计算结算边界');
expectContains($invitationRepo,'PlatformOrderBookingPaymentVerifier','平台邀请领取事务内重新核验托管资金');
expectContains($pricingRepo,'PlatformOrderBookingPaymentVerifier','平台实际用量重算事务内核验托管资金');
expectContains($adminRepo,'PLATFORM_ALREADY_PUBLISHED','重复发布不能刷新报价期限');
expectContains($adminRepo,'PLATFORM_QUOTE_DEADLINE_PASSED','报价与邀请受截止时间保护');

// 平台测量后核价只保留托管自动生效链。
expectContains($pricingReview,"customer_flow_mode='platform_managed'",'平台测量核价固定托管模式');
expectContains($pricingReview,"confirmation_mode'=>'platform_managed_auto'",'平台核价完成自动形成生效事实');
expectContains($pricingReview,"requires_customer_confirmation'=>false",'平台核价不再等待客户小程序确认');
expectContains($pricingReadiness,"business_type IN ('order_booking','booking_fee')",'核价 readiness 同样拦截微信预约费串线');
expectNotContains($pricingApi,'platform.pricing.confirm','API 不再暴露客户核价确认路由');
expectNotContains($legacyApi,"'platform.pricing.confirm'",'退役客户确认路由回归 NOT_FOUND');
expectNotContains($measurementComponent,'platform.pricing.confirm','小程序测量组件不再调用客户核价确认');
expectNotContains($measurementView,'awaiting_customer_confirmation','小程序测量详情不再展示等待客户确认状态');
expectNotContains($platformPricing,'miniapp_booking','平台客户价格组件不再分支到小程序预约模式');
expectNotContains($platformSettlement,'miniapp_booking','平台内部结算组件不再分支到小程序预约模式');

expectSame(is_file($root.'/server/app/Controllers/PlatformOrderAdminController.php'),false,'平台承接单未回退旧 Controller');
expectSame(is_file($root.'/server/app/Controllers/PlatformOrderFinanceAdminController.php'),false,'平台财务未新增旧 Controller');
expectNotContains($adminModule,'WorkerAdmin\\Controllers\\','平台 Http 模块不依赖旧 Controller');
expectNotContains($adminModule,'SELECT ','平台 Http 模块不内嵌 SQL');

require_once $root.'/server/app/Domain/Order/OrderOperationException.php';
require_once $root.'/server/app/Domain/Order/PlatformOrderSettlementPolicy.php';
$policy=new \WorkerAdmin\Domain\Order\PlatformOrderSettlementPolicy();
$workerCollect=$policy->settlement(100000,70000,5000,\WorkerAdmin\Domain\Order\PlatformOrderSettlementPolicy::MODE_WORKER_COLLECT);
expectSame($workerCollect['booking_paid_cents'],5000,'外部预付款 50 元进入平台结算快照');
expectSame($workerCollect['customer_tail_cents'],95000,'客户现场剩余 950 元');
expectSame($workerCollect['platform_receivable_cents'],25000,'师傅代收模式平台收入款 250 元');
$balance=$policy->settlement(100000,70000,5000,\WorkerAdmin\Domain\Order\PlatformOrderSettlementPolicy::MODE_PLATFORM_BALANCE);
expectSame($balance['platform_receivable_cents'],95000,'平台余额模式现场剩余全部归平台');
expectSame($balance['worker_balance_credit_cents'],70000,'平台余额模式工费全额进入师傅余额');

echo "\nV2.5.0 proxy/platform unified entry smoke: OK\n";
