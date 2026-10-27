<?php

declare(strict_types=1);

use WorkerAdmin\Domain\Admin\WorkbenchTaskProjection;

$root=dirname(__DIR__);
require_once $root.'/app/Domain/Admin/WorkbenchTaskProjection.php';

function adminOpsAssert(bool $condition,string $label):void
{
    if(!$condition){fwrite(STDERR,"[FAIL] {$label}\n");exit(1);}
    fwrite(STDOUT,"[PASS] {$label}\n");
}

$repo=file_get_contents($root.'/app/Infrastructure/Persistence/DatabaseAdminWorkbenchOperationsRepository.php')?:'';
$app=file_get_contents($root.'/app/Application/Admin/AdminWorkbenchOperations.php')?:'';
$api=file_get_contents($root.'/app/Http/Api/Modules/AdminWorkbenchActionApiModule.php')?:'';
$readApi=file_get_contents($root.'/app/Http/Api/Modules/AdminWorkbenchApiModule.php')?:'';
$cap=file_get_contents($root.'/app/Infrastructure/Persistence/AdminWorkbenchCapabilityReader.php')?:'';
$publish=file_get_contents($root.'/app/Infrastructure/Scheduling/PlatformOrderAutoPublishTask.php')?:'';
$platformTasks=file_get_contents($root.'/app/Infrastructure/Persistence/PlatformOrderWorkbenchTaskSource.php')?:'';
$exceptionTasks=file_get_contents($root.'/app/Infrastructure/Persistence/OrderExceptionWorkbenchTaskSource.php')?:'';
$followupTasks=file_get_contents($root.'/app/Infrastructure/Persistence/AdminFollowupWorkbenchTaskSource.php')?:'';
$messageDefinition=file_get_contents($root.'/app/Domain/Messaging/OrderMessageDefinition.php')?:'';

foreach([
    "'admin.order.worker.reassign'"=>'管理员真正改派使用独立 mini-admin API',
    "'admin.after_sales.repair.intervene'"=>'管理员主动售后介入使用独立 API',
    "'admin.task.retry-platform-publish'"=>'自动发布恢复使用定向 API',
    "'admin.order.followup.add'"=>'内部跟进使用管理员专用 API',
    "'admin.order.followup.complete'"=>'内部跟进完成使用管理员专用 API',
] as $needle=>$label)adminOpsAssert(strpos($api,$needle)!==false,$label);

adminOpsAssert(strpos($repo,"assignment_status='offered'")!==false,'改派后新 assignment 仍为 offered，等待师傅本人接受');
adminOpsAssert(strpos($repo,"'requires_worker_acceptance'=>true")!==false,'改派响应明确仍需师傅本人确认');
adminOpsAssert(strpos($repo,'assignment_type')!==false&&strpos($repo,"'admin'")!==false,'管理员改派使用 admin assignment 类型');
adminOpsAssert(strpos($repo,'created_by_admin_id')!==false&&strpos($repo,'NULL,UTC_TIMESTAMP()')!==false,'mini users.id 不混写 Web admin_users.id');
adminOpsAssert(strpos($repo,"UPDATE order_role_grants SET status='revoked'")!==false,'改派立即回收旧师傅订单访问授权');
adminOpsAssert(strpos($repo,'order_worker_contact_reveal_requests')!==false&&strpos($repo,"status='cancelled'")!==false,'改派关闭旧师傅待审核电话公开申请');
adminOpsAssert(strpos($repo,"UPDATE invite_tokens SET status='revoked'")!==false,'改派作废旧邀请');
adminOpsAssert(strpos($repo,"template_key='worker_replaced_by_admin_old'")!==false,'旧师傅收到独立幂等站内改派通知');
adminOpsAssert(strpos($repo,'quoteSelected')===false&&strpos($repo,'acceptAssignment')===false,'管理员改派不复用客户选价或师傅接单命令');

adminOpsAssert(strpos($repo,"'平台主动介入：'.\$reason")!==false,'主动介入记录真实平台发起身份');
adminOpsAssert(strpos($repo,"'repair_platform_intervention_started'")!==false,'主动介入写独立事件');
adminOpsAssert(strpos($exceptionTasks,"af.resolution LIKE '平台主动介入：%'")!==false,'主动介入后进入既有售后平台处理待办');

adminOpsAssert(strpos($publish,'public function retryOrder(int $orderId)')!==false,'scheduler 提供单订单定向重检入口');
adminOpsAssert(strpos($publish,'$this->publishOne($orderId)')!==false,'定向重试复用原自动发布完整校验');
adminOpsAssert(strpos($platformTasks,"['retry_platform_auto_publish']")!==false,'平台自动发布失败/卡住开放安全重检动作');
adminOpsAssert(strpos($exceptionTasks,"'refund_execution_attention'")!==false,'退款失败继续作为财务异常任务');
adminOpsAssert(strpos($exceptionTasks,"'refund_execution_attention','refund'")!==false&&strpos($exceptionTasks,"'attention',[]")!==false,'退款执行异常没有通用重试动作');

adminOpsAssert(strpos($repo,'INSERT INTO order_revisions')!==false,'内部备注与恢复记录落管理员私有 revisions');
adminOpsAssert(strpos($repo,"'admin_internal_followup'")!==false,'内部跟进使用专用 kind');
adminOpsAssert(strpos($followupTasks,'FROM order_revisions r')!==false,'到期跟进从私有 revisions 派生');
adminOpsAssert(strpos($followupTasks,'FROM order_events e')===false,'内部跟进不进入客户/师傅订单事件时间线');
adminOpsAssert(strpos($repo,"CAST(JSON_UNQUOTE(JSON_EXTRACT(changes_json,'$.completed_of')) AS UNSIGNED)")!==false,'跟进完成查询使用 MySQL 5.7 明确数字转换');
adminOpsAssert(strpos($followupTasks,"CAST(JSON_UNQUOTE(JSON_EXTRACT(c.changes_json,'$.completed_of')) AS UNSIGNED)")!==false,'到期任务排除已完成项使用 MySQL 5.7 明确数字转换');
adminOpsAssert(WorkbenchTaskProjection::taskKind(['task_type'=>'admin_followup_due'])==='operation','到期内部跟进属于正常运营待办');
adminOpsAssert(WorkbenchTaskProjection::matchesScope(['task_type'=>'admin_followup_due'],'followup'),'工作台支持独立 followup 筛选');

adminOpsAssert(strpos($cap,"'can_reassign_worker'")!==false&&strpos($cap,"'can_intervene_repair'")!==false,'按钮能力由服务端工作台投影提供');
adminOpsAssert(strpos($readApi,"'admin.workbench.workers'")!==false,'改派师傅搜索为管理员鉴权读接口');
adminOpsAssert(strpos($readApi,"'internal_followups'")!==false&&strpos($readApi,"'admin_capabilities'")!==false,'管理员详情读取内部跟进与实时能力');

adminOpsAssert(strpos($messageDefinition,"\$type === 'worker_replaced_by_admin'")!==false,'管理员改派有窄范围订单进度通知定义');
adminOpsAssert(strpos($messageDefinition,"\$type === 'repair_platform_intervention_started'")!==false,'主动介入有窄范围订单进度通知定义');

adminOpsAssert(strpos($app,'ADMIN_REASSIGN_REASON_REQUIRED')!==false,'真正改派必须填写原因');
adminOpsAssert(strpos($app,'ADMIN_REPAIR_INTERVENTION_REASON_REQUIRED')!==false,'管理员主动介入必须填写原因');

fwrite(STDOUT,"V2.5.0 admin workbench operations regression OK\n");
