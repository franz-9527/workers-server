<?php

declare(strict_types=1);

$base = dirname(__DIR__) . '/server/app';
spl_autoload_register(static function (string $class) use ($base): void {
    $prefix = 'WorkerAdmin\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $file = $base . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require_once $file;
});

use WorkerAdmin\Domain\Admin\WorkbenchTaskProjection;
use WorkerAdmin\Domain\Form\ComponentTypeCatalog;
use WorkerAdmin\Domain\Form\FormRuleRuntime;
use WorkerAdmin\Domain\Identity\MobileNumber;
use WorkerAdmin\Domain\Messaging\SmsTemplateCatalog;
use WorkerAdmin\Domain\Money\WalletPolicy;
use WorkerAdmin\Domain\Order\ContactRevealReviewPolicy;
use WorkerAdmin\Domain\Order\QuotePolicy;
use WorkerAdmin\Domain\Scheduling\SchedulerTaskCatalog;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assertThrows = static function (callable $callback, string $expectedMessage, string $message) use ($assert): void {
    try {
        $callback();
    } catch (RuntimeException $exception) {
        $assert($exception->getMessage() === $expectedMessage, $message . '（异常文案不一致）');
        return;
    }
    $assert(false, $message . '（未抛出异常）');
};

$policy = new QuotePolicy();
$now = 1700000000;
$order = [
    'id'=>1,'user_id'=>100,'audit_status'=>'approved','payment_status'=>'paid','publish_status'=>'published',
    'lifecycle_status'=>'active','quote_status'=>'waiting','refund_status'=>'none',
    'quote_deadline_at'=>gmdate('Y-m-d H:i:s', $now + 3600),'is_demo'=>0,
];
$assert($policy->orderCanCollectQuotes($order, true, false, $now), '正常已发布订单应可收集报价');
$assert(!$policy->orderCanCollectQuotes($order, true, true, $now), '暂停报价时必须禁止报价');
$hidden=$order;$hidden['publish_status']='hidden';
$assert(!$policy->orderCanCollectQuotes($hidden, true, false, $now), '要求公开时 hidden 订单不可报价');
$assert($policy->orderCanCollectQuotes($hidden, false, false, $now), '后台允许 hidden 时应继续收集报价');
$expired=$order;$expired['quote_deadline_at']=gmdate('Y-m-d H:i:s', $now - 1);
$assert(!$policy->orderCanCollectQuotes($expired, true, false, $now), '过期订单不可报价');

$worker=['id'=>7,'user_id'=>200,'verification_status'=>'approved','user_status'=>'active','cloud_record_mode'=>'formal'];
$assert($policy->workerPassesBaseRules($worker, $order), '正常已审核师傅应通过基础资格');
$worker['user_id']=100;
$assert(!$policy->workerPassesBaseRules($worker, $order), '订单本人不可给自己报价');

$assert(MobileNumber::normalize('+86 138-0013-8000') === '13800138000', '手机号 +86 规范化失败');
$assert(MobileNumber::normalize('0086 13800138000') === '13800138000', '手机号 0086 规范化失败');
$assert(MobileNumber::normalize('12345') === '', '非法手机号必须拒绝');

$assert(ComponentTypeCatalog::canonical('single') === 'radio', '旧 single 类型转换失败');
$assert(ComponentTypeCatalog::canonical('boolean', ['是','否']) === 'radio', '带选项 boolean 应转换为 radio');
$assert(ComponentTypeCatalog::canonical('boolean') === 'switch', '无选项 boolean 应转换为 switch');
$assert(ComponentTypeCatalog::isSupported('wechat_address'), '标准组件类型识别失败');

$formFields = [
    ['field_key'=>'finish_type','component_type'=>'radio','ui_config_json'=>'{}'],
    ['field_key'=>'finish_type__other_text','component_type'=>'text','is_required'=>1,'ui_config_json'=>'{"system_role":"other_text"}'],
    ['field_key'=>'need_protect','component_type'=>'radio','ui_config_json'=>'{}'],
    ['field_key'=>'protect_note','component_type'=>'text','is_required'=>0,'ui_config_json'=>'{}'],
    ['field_key'=>'wall_note','component_type'=>'text','is_required'=>0,'ui_config_json'=>'{"category_ids":[9]}'],
];
$formRules = [
    ['trigger_key'=>'finish_type','target_key'=>'finish_type__other_text','operator'=>'equals','compare_value_json'=>'"__other__"','action_type'=>'show'],
    ['trigger_key'=>'need_protect','target_key'=>'protect_note','operator'=>'equals','compare_value_json'=>'"yes"','action_type'=>'show'],
    ['trigger_key'=>'need_protect','target_key'=>'protect_note','operator'=>'equals','compare_value_json'=>'"yes"','action_type'=>'require'],
];
$formRuntime = FormRuleRuntime::resolve($formFields, $formRules, [2], [
    'finish_type'=>'normal','finish_type__other_text'=>'旧说明','need_protect'=>'no','protect_note'=>'旧保护说明','wall_note'=>'旧类目说明',
]);
$assert($formRuntime['visible']['finish_type__other_text'] === false && !array_key_exists('finish_type__other_text', $formRuntime['answers']), '未选择其他时系统伴生说明必须隐藏并清理旧答案');
$assert($formRuntime['visible']['protect_note'] === false && !array_key_exists('protect_note', $formRuntime['answers']), 'show 规则未命中时目标字段必须隐藏并清理旧答案');
$assert($formRuntime['visible']['wall_note'] === false && !array_key_exists('wall_note', $formRuntime['answers']), '类目不匹配时字段必须隐藏并清理旧答案');
$formRuntime = FormRuleRuntime::resolve($formFields, $formRules, [9], ['finish_type'=>'__other__','finish_type__other_text'=>'异形收口','need_protect'=>'yes','protect_note'=>'保护地面']);
$assert($formRuntime['visible']['finish_type__other_text'] === true && ($formRuntime['answers']['finish_type__other_text'] ?? '') === '异形收口', '选择其他后只应显示并保留对应伴生说明');
$assert($formRuntime['visible']['protect_note'] === true && !empty($formRuntime['states']['protect_note']['required']), '动态 show/require 规则必须同时生效');
$assert($formRuntime['visible']['wall_note'] === true, '匹配类目后类目字段应显示');
$assert(FormRuleRuntime::matches('in', 'b', ['a','b']) && FormRuleRuntime::matches('not_in', 'c', ['a','b']), '动态表单集合操作符语义发生变化');

$contactPolicy=new ContactRevealReviewPolicy();
$assert($contactPolicy->normalizeDecision('approve') === 'approve', '电话公开审批 decision 规则失败');
$assertThrows(static function () use ($contactPolicy): void { $contactPolicy->normalizeNote('reject', ''); }, '拒绝时请填写原因。', '电话公开拒绝必须填写原因');
$reviewer=$contactPolicy->normalizeOperator(['channel'=>'web_admin','admin_user_id'=>9]);
$assert($reviewer['reviewed_by'] === 9 && $reviewer['actor_id'] === 9, '网页管理员审批身份转换失败');
$miniReviewer=$contactPolicy->normalizeOperator(['channel'=>'mini_admin','user_id'=>12]);
$assert($miniReviewer['reviewed_by'] === null && $miniReviewer['actor_id'] === 12, '小程序管理员审批身份转换失败');
$assert($contactPolicy->isMerchantOrder(['order_source_type'=>'merchant']), '商家订单电话公开限制识别失败');
$assert($contactPolicy->assignedWorkerChanged(['assigned_worker_id'=>2], ['worker_id'=>3]), '成交师傅变更识别失败');
$assert($contactPolicy->isServiceFinished(['lifecycle_status'=>'completed']), '施工完成状态识别失败');
$assert($contactPolicy->repairIsActive(['status'=>'processing']), '维修处理中状态识别失败');
$assert(!$contactPolicy->repairIsActive(['status'=>'closed']), '已结束维修不应视为有效');
$assert($contactPolicy->reviewNote('approved', '') === '核实已超过2小时且师傅尚未提交预约/联系响应，同意当前场景公开电话', '电话公开通过默认审核说明发生变化');

$walletPolicy=new WalletPolicy();
$mutation=$walletPolicy->calculateMutation(1000, 200, 300, -300, 300, 'hold:test');
$assert($mutation['available_after'] === 700 && $mutation['reserved_after'] === 500, '钱包冻结余额计算失败');
$assertThrows(static function () use ($walletPolicy): void { $walletPolicy->calculateMutation(100,0,200,-200,0,'debit:test'); }, '用户可用余额不足', '钱包不得透支');
$assertThrows(static function () use ($walletPolicy): void { $walletPolicy->calculateMutation(100,0,1,0,0,''); }, '钱包流水缺少幂等键', '钱包流水必须有幂等键');
$walletPolicy->assertExistingRefundReservationMatches(['user_id'=>1,'business_id'=>2,'amount_cents'=>300],1,2,300);

$workbenchGroups=[
    1=>['tasks'=>[['task_type'=>'audit_pending','mode'=>'decision']]],
    2=>['tasks'=>[['task_type'=>'platform_auto_publish_failed','mode'=>'attention']]],
    3=>['tasks'=>[['task_type'=>'platform_income_verification','mode'=>'decision']]],
];
$workbenchSummary=WorkbenchTaskProjection::summary($workbenchGroups);
$assert($workbenchSummary['todo_orders']===3 && $workbenchSummary['exception_orders']===2 && $workbenchSummary['operation_orders']===1, '工作台正常待办与异常待办统计规则发生变化');
$assert($workbenchSummary['auto_publish_orders']===1 && $workbenchSummary['income_verification_orders']===1 && $workbenchSummary['settlement_orders']===1, '工作台平台分类统计失败');
$assert($workbenchSummary['audit_orders']===1 && $workbenchSummary['attention_orders']===1, '工作台审核或 attention 统计失败');
$assert(WorkbenchTaskProjection::matchesScope(['task_type'=>'audit_pending','mode'=>'decision'],'todo'), '正常审核应进入全部待处理');
$assert(WorkbenchTaskProjection::matchesScope(['task_type'=>'audit_pending','mode'=>'decision'],'operation'), '正常审核应进入正常运营待办');
$assert(!WorkbenchTaskProjection::matchesScope(['task_type'=>'audit_pending','mode'=>'decision'],'exception'), '正常审核不应被标记为异常');
$assert(WorkbenchTaskProjection::matchesScope(['task_type'=>'platform_pricing_review','mode'=>'decision'],'pricing'), '平台测量后核价应进入正常核价范围');
$assert(WorkbenchTaskProjection::matchesScope(['task_type'=>'platform_auto_publish_failed','mode'=>'attention'],'publish'), '自动发布异常应进入发布异常范围');
$assert(WorkbenchTaskProjection::matchesScope(['task_type'=>'refund_execution_attention','mode'=>'attention'],'finance'), '退款执行异常应进入财务范围');

$assert(SchedulerTaskCatalog::normalize('auto-accept') === 'auto_accept', '计划任务别名转换失败');
$assert(SchedulerTaskCatalog::normalize('wallet-reconcile') === 'wallet_reconcile', '钱包计划任务别名转换失败');
$assert(SchedulerTaskCatalog::normalize('media-promote') === 'media_promote', '媒体后台迁移任务别名转换失败');
$assert(SchedulerTaskCatalog::normalize('platform-auto-publish') === 'platform_auto_publish', '平台单自动发布任务别名转换失败');
$assert(in_array('all', SchedulerTaskCatalog::supported(), true), '计划任务目录缺少 all');
$sequence=SchedulerTaskCatalog::allSequence();
$assert(count($sequence) === 18, 'all 计划任务顺序数量意外变化');
$assert(count($sequence) === count(array_unique($sequence)), 'all 计划任务不能重复');
$assert(in_array('platform_balance_release', $sequence, true), '平台师傅冻结余额释放任务丢失');
$assert(array_search('media_promote', $sequence, true) < array_search('media_cleanup', $sequence, true), '媒体迁移必须先于无引用媒体清理');
$assert(SchedulerTaskCatalog::isGatewayOnly('media_promote'), '媒体迁移必须由防腐网关独立执行');
$assert(end($sequence) === 'platform_auto_publish', '平台单自动发布任务目录顺序必须与防腐网关真实执行顺序一致');

$smsCatalog=SmsTemplateCatalog::all();
$assert(isset($smsCatalog['order_status'],$smsCatalog['quote_received'],$smsCatalog['payment_success'],$smsCatalog['nearby_worker_invite']), '短信领域模板目录缺项');
$assert($smsCatalog['nearby_worker_invite']['variables'] === ['category','district','distance'], '附近师傅短信变量契约发生变化');
$testSms=SmsTemplateCatalog::testParameters('order_status','10-04 09:30');
$assert(($testSms['time'] ?? '') === '10-04 09:30', '短信领域目录不应自行读取系统时间');

echo "模块化领域规则回归通过。\n";
