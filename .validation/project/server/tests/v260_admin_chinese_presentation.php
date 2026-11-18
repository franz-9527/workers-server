<?php
declare(strict_types=1);
spl_autoload_register(static function(string $class):void {
    $prefix='WorkerAdmin\\';
    if(strpos($class,$prefix)!==0)return;
    $path=dirname(__DIR__).'/app/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
    if(is_file($path))require_once $path;
});
require_once dirname(__DIR__).'/app/Support/functions.php';
use WorkerAdmin\Infrastructure\Testing\TestingPresentation;
use WorkerAdmin\Support\AdminPresentation;
function cnAssert(bool $result,string $label):void {
    if(!$result)throw new RuntimeException($label);
    echo "[PASS] $label\n";
}
$reported=['financial_disputes_count'=>0,'financial_dispute_unowned'=>1,'financial_refunds_count'=>0,'run_empty_ledger_count'=>0,'financial_change_unowned'=>1,'financial_change_confirmations_count'=>0,'financial_change_confirmation_unowned'=>1];
foreach($reported as $key=>$count)cnAssert(preg_match('/[\x{4e00}-\x{9fff}]/u',TestingPresentation::label($key))===1 && strpos(TestingPresentation::label($key),$key)===false,'reported blocking field has Chinese label: '.$key);
$paths=glob(dirname(__DIR__).'/app/Infrastructure/Testing/*Cleanup*.php');
foreach(['DatabaseTestCenterM1Repository.php','DatabaseTestCenterMeasurementRepository.php','DatabaseTestCenterPlatformMeasurementRepository.php'] as $file)$paths[]=dirname(__DIR__).'/app/Infrastructure/Testing/'.$file;
$checked=[];
foreach($paths as $file){
    $source=file_get_contents($file);
    preg_match_all('/\$(?:blocking|ownership|counts)\[[\'"]([a-z_0-9]+)[\'"]\]/',$source,$matches);
    foreach(array_unique($matches[1]) as $key){
        if(isset($checked[$key]))continue;
        $checked[$key]=true;
        cnAssert(preg_match('/[\x{4e00}-\x{9fff}]/u',TestingPresentation::label($key))===1,'cleanup display key has Chinese business meaning: '.$key);
    }
}
cnAssert(count($checked)>100,'all cleanup layers were inspected');
cnAssert(strpos(TestingPresentation::fact('financial_disputes_count 0；financial_dispute_unowned 1'),'financial_')===false,'fact summary translates embedded keys');
$preview=['batch_public_id'=>'SYNTHETIC-BATCH','allowed'=>false,'items'=>[['public_id'=>'SYNTHETIC-INSTANCE','order_no'=>'SYNTHETIC-ORDER','status'=>'blocked','reason'=>'数据不符','blocking_relations'=>$reported+['<script>unsafe</script>'=>1]]]];
ob_start();include dirname(__DIR__).'/views/admin/test-center-batch-cleanup.php';$html=(string)ob_get_clean();
$primary=preg_replace('/<details\b[^>]*>.*?<\/details>/s','',$html);
foreach($reported as $key=>$count)cnAssert(strpos($primary,$key)===false,'ordinary cleanup display does not expose technical field: '.$key);
cnAssert(strpos($primary,'资金争议记录数量（应为 1）')!==false && preg_match('/资金争议记录数量（应为 1）.*? 0/s',$primary)===1,'zero is retained as a blocking count mismatch');
cnAssert(strpos($html,'financial_disputes_count')!==false,'original blocking keys remain available in folded diagnostics');
cnAssert(strpos($html,'<script>unsafe</script>')===false && strpos($html,'&lt;script&gt;')!==false,'unrecognized diagnostics remain escaped');
cnAssert(AdminPresentation::code('order.cancel')==='订单 · 取消','audit operation uses a Chinese summary');
cnAssert(AdminPresentation::code('worker.realname.review')==='师傅实名认证审核','audit known tokens are translated');
cnAssert(AdminPresentation::code('future.unregistered')==='其他管理操作','unknown audit identifiers do not become visible English');
cnAssert(status_text('fee_negotiation')==='费用协商争议','resource dispute type is translated');
$result=['rows'=>[['created_at'=>'2026-01-01 00:00:00','action'=>'order.cancel','actor_type'=>'system','entity_type'=>'order','entity_id'=>10,'reason'=>'人工核实违规内容','result'=>'success']], 'total'=>1,'page'=>1,'pages'=>1];
ob_start();include dirname(__DIR__).'/views/admin/audit.php';$audit=(string)ob_get_clean();
$primaryAudit=preg_replace('/<details\b[^>]*>.*?<\/details>/s','',$audit);
cnAssert(strpos($primaryAudit,'订单 · 取消')!==false,'actual audit table renders translated action');
cnAssert(strpos($primaryAudit,'order.cancel')===false && strpos($audit,'order.cancel')!==false,'audit keeps raw action only in folded technical detail');

$runtimeModuleSource=(string)file_get_contents(dirname(__DIR__).'/app/Http/Admin/Modules/RuntimeLogAdminModule.php');
$runtimeViewSource=(string)file_get_contents(dirname(__DIR__).'/views/admin/runtime-logs.php');
$tasksViewSource=(string)file_get_contents(dirname(__DIR__).'/views/admin/tasks.php');
$adminLayoutSource=(string)file_get_contents(dirname(__DIR__).'/views/layouts/admin.php');
$authSource=(string)file_get_contents(dirname(__DIR__).'/app/Core/Auth.php');
$runtimeCssSource=(string)file_get_contents(dirname(__DIR__).'/assets/admin.css');
$runMaintenanceSource=(string)file_get_contents(dirname(__DIR__).'/app/Infrastructure/Persistence/DatabaseSchedulerRunMaintenance.php');
$adminKernelSource=(string)file_get_contents(dirname(__DIR__).'/app/Http/Admin/AdminKernel.php');
$requestGuardSource=(string)file_get_contents(dirname(__DIR__).'/app/Http/Admin/AdminRequestGuard.php');
cnAssert(strpos($adminLayoutSource,"['runtime-logs', '运行日志', 'settings.manage'")!==false,'运行日志菜单沿用 settings.manage 权限');
cnAssert(strpos($adminKernelSource,'new RuntimeLogAdminModule($db, $auth, $csrf)')!==false,'运行日志模块已注册到后台路由内核');
cnAssert(strpos($requestGuardSource,'$this->auth->requireLogin()')!==false && strpos($requestGuardSource,'$this->csrf->verifyRequest()')!==false,'后台入口统一执行登录和 POST CSRF 门禁');
cnAssert(strpos($runtimeModuleSource,"requirePermission('settings.manage')")!==false,'运行日志所有路由在模块内再次检查权限');
cnAssert(strpos($runtimeModuleSource,"\$this->csrf->verifyRequest()")!==false,'运行日志写操作再次校验 CSRF');
cnAssert(strpos($runtimeModuleSource,"header('Allow: POST')")!==false,'拒绝非 POST 写请求时返回 Allow 头');
cnAssert(strpos($authSource,"if (\$user['role'] === 'super_admin')")!==false && strpos($authSource,"'settings.manage'")===false,'运行日志管理权限不授予运营、财务和只读角色');
cnAssert(strpos($runtimeViewSource,'data-confirm=')!==false && strpos($runtimeViewSource,'data-loading')!==false,'危险日志操作具备表单级确认和提交中状态');
cnAssert(strpos($runtimeViewSource,"\$job['job_label']")!==false && strpos($runtimeViewSource,"\$job['job_name']")!==false,'任务筛选使用中文标签但提交稳定内部任务键');
cnAssert(strpos($tasksViewSource,'SchedulerTaskCatalog::label')!==false && strpos($tasksViewSource,"e(\$row['job_name']")===false,'计划任务历史与失败记录只显示中文名称，不直接展示内部英文键');
cnAssert(strpos($tasksViewSource,'$jobLabels')===false,'任务失败重试确认文案也不再依赖旧英文任务标签映射');
cnAssert(strpos($runMaintenanceSource,'SchedulerTaskCatalog::label($jobName)')!==false,'数据库运行摘要统一通过计划任务目录获取中文名称');
cnAssert(strpos($runtimeCssSource,'.admin-runtime-log-stats')!==false && strpos($runtimeCssSource,'@media (max-width: 640px)')!==false,'运行日志统计和移动端布局有专属样式');

echo "V2.6.0 admin Chinese presentation regression OK\n";
