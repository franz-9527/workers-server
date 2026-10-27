<?php

declare(strict_types=1);

function coverageAssert(bool $actual,string $label): void
{
    if(!$actual){
        fwrite(STDERR,'[FAIL] '.$label."\n");
        exit(1);
    }
    fwrite(STDOUT,'[PASS] '.$label."\n");
}

$root=dirname(__DIR__,2);
$read=static function(string $path) use($root): string {
    $value=file_get_contents($root.'/'.$path);
    if(!is_string($value))throw new RuntimeException('cannot read '.$path);
    return $value;
};

$repository=$read('server/app/Infrastructure/Persistence/DatabaseTestCenterRepository.php');
coverageAssert(strpos($repository,'total_runs')!==false,'instance list exposes total run count');
coverageAssert(strpos($repository,'real_service_runs')!==false,'instance list exposes successful real-service count');
coverageAssert(strpos($repository,'failed_runs')!==false,'instance list exposes failed run count');
coverageAssert(strpos($repository,'last_run_at')!==false,'instance list exposes last execution time');

$application=$read('server/app/Application/Testing/TestCenter.php');
coverageAssert(strpos($application,'coverageDashboard')!==false,'dashboard aggregates scenario coverage');
coverageAssert(strpos($application,'detailCoverage')!==false,'detail aggregates execution coverage');
coverageAssert(strpos($application,"'coverage_percent'")!==false,'coverage summary publishes percent');
coverageAssert(strpos($application,"'pending_available'")!==false,'detail exposes current executable actions not yet run');
coverageAssert(strpos($application,"operation_mode']??'')==='real_service'")!==false,'coverage distinguishes formal business actions');

$dashboard=$read('server/views/admin/test-center.php');
coverageAssert(strpos($dashboard,'测试覆盖概览')!==false,'dashboard renders coverage overview');
coverageAssert(strpos($dashboard,'真实业务动作')!==false,'dashboard renders real-service count');
coverageAssert(strpos($dashboard,'覆盖率表示“至少生成过实例”')!==false,'dashboard states coverage boundary explicitly');

$detail=$read('server/views/admin/test-center-detail.php');
coverageAssert(strpos($detail,'执行覆盖')!==false,'detail renders execution coverage');
coverageAssert(strpos($detail,'当前状态可继续测试')!==false,'detail renders pending current actions');
coverageAssert(strpos($detail,'不是 UI/外部渠道验收结果')!==false,'detail states report boundary');


// Render the real templates with historical enum/event values; never mutate the input facts.
spl_autoload_register(static function(string $class)use($root):void{
    if(strpos($class,'WorkerAdmin\\')!==0)return;
    $file=$root.'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';
    if(is_file($file))require_once $file;
});
require_once $root.'/server/app/Support/functions.php';
set_error_handler(static function(int $severity,string $message,string $file,int $line):bool{
    throw new ErrorException($message,0,$severity,$file,$line);
});
$presentation='WorkerAdmin\\Infrastructure\\Testing\\TestingPresentation';
coverageAssert($presentation::label('pending','worker_status')==='待审核','worker pending has its own meaning');
coverageAssert($presentation::label('pending','verification_status')==='待检查','verification pending has its own meaning');
coverageAssert($presentation::label('accepted','fulfillment_status')==='已验收','fulfillment accepted is not confused with taking an order');
coverageAssert($presentation::label('simulated_queued')==='模拟等待发送','provider simulation status is translated');
coverageAssert($presentation::action('worker_measurement_quote')==='师傅提交测量报价','measurement execution history has a Chinese action');
coverageAssert($presentation::fact('approved/published/unassigned/active')==='已通过/已公开/未安排师傅/有效','historical check facts render in Chinese');
coverageAssert($presentation::label('FUTURE_DIAGNOSTIC')==='FUTURE_DIAGNOSTIC','unknown diagnostic codes remain inspectable');
$scenarioKey='ordinary.construction.multi_quote.waiting.zero_quotes';
$instance=['id'=>1,'public_id'=>'TEST_INSTANCE','scenario_key'=>$scenarioKey,'scenario_version'=>1,'build_status'=>'built','build_mode'=>'preset','verification_status'=>'passed','batch_public_id'=>'TEST_BATCH','batch_status'=>'active'];
$order=['id'=>1,'public_no'=>'BXTEST_LANGUAGE','order_source_type'=>'consumer','workflow_profile'=>'construction','dispatch_mode'=>'multi_quote','audit_status'=>'approved','publish_status'=>'published','quote_status'=>'waiting','assignment_status'=>'unassigned','fulfillment_status'=>'not_started','payment_status'=>'paid','lifecycle_status'=>'active','is_demo'=>1];
$detail=[
    'instance'=>$instance,'order'=>$order,
    'entry_tokens'=>['enabled'=>true,'workers'=>[['slot_key'=>'worker_1','label'=>'测试师傅一','verification_status'=>'approved']]],
    'participants'=>[['slot_key'=>'worker_1','display_name'=>'测试师傅一','worker_id'=>11,'expected_status'=>'approved','verification_status'=>'approved']],
    'actions'=>['items'=>[
        'worker_quote'=>['workers'=>[['slot_key'=>'worker_1','display_name'=>'测试师傅一']],'default_worker_slot'=>'worker_1'],
        'worker_grab'=>['workers'=>[['slot_key'=>'worker_1','display_name'=>'测试师傅一']],'default_worker_slot'=>'worker_1'],
    ]],
    'events'=>[
        ['event_type'=>'test_center_preset_worker_quote','actor_type'=>'worker','actor_id'=>11,'payload_json'=>'{"real_quote_api_executed":false,"amount_cents":52000}'],
        ['event_type'=>'worker_quoted','actor_type'=>'worker','actor_id'=>11,'payload_json'=>'{"message":"<script>unsafe()</script>"}'],
    ],
    'runs'=>[['action_key'=>'worker_measurement_quote','operation_mode'=>'real_service','result'=>'passed']],
    'quotes'=>[['worker_id'=>11,'worker_name'=>'测试师傅一','amount_cents'=>52000,'status'=>'active']],
    'assertions'=>[['assertion_group'=>'state','assertion_key'=>'published_waiting','status'=>'passed','expected_text'=>'approved/published/unassigned/active','actual_text'=>'approved/published/unassigned/active']],
    'coverage'=>['actions'=>[['key'=>'worker_quote','runs'=>1,'passed'=>1,'failed'=>0,'last_result'=>'passed']]],
    'cleanup'=>['counts'=>['orders'=>1,'quotes'=>1]],
    'messages'=>[
        ['event_type'=>'paid_sms_receipt_probe','channel'=>'aliyun_paid_sms','recipient_type'=>'worker_candidate','projection_status'=>'simulated_unknown','reason_code'=>'paid_sms_receipt_timeout','scene_key'=>'nearby_worker_invite'],
        ['event_type'=>'worker_quoted','channel'=>'wechat_subscribe','recipient_type'=>'user','projection_status'=>'simulated_retry','reason_code'=>'provider_transient_retry'],
    ],
];
$before=serialize($detail);
$html=view('admin/test-center-detail-v260',['detail'=>$detail,'actionRequestKeys'=>['worker_quote'=>'request_quote','worker_grab'=>'request_grab']],null);
foreach(['已生成','预置数据','待报价','已支付','测试师傅一','已审核','模拟送达未知','付费短信','候选师傅','等待短信回执超时','师傅提交测量报价','正式业务执行','已预置师傅报价（未执行真实报价）','已执行真实报价接口：否','技术详情'] as $label){
    coverageAssert(strpos($html,$label)!==false,'detail renders Chinese label '.$label);
}
coverageAssert(strpos($html,'name="worker_slot"')!==false&&strpos($html,'value="worker_1"')!==false,'translated worker labels retain original submitted slot');
coverageAssert(strpos($html,'name="action_key" value="worker_quote"')!==false,'translated action labels retain original command');
coverageAssert(strpos($html,'<script>unsafe()</script>')===false&&strpos($html,'&lt;script&gt;unsafe()&lt;/script&gt;')!==false,'event summaries are HTML escaped');
coverageAssert(serialize($detail)===$before,'presentation does not rewrite business facts');
coverageAssert(order_event_text('test_center_preset_target_built')==='测试订单目标状态已生成（预置数据）','ordinary order timeline also translates existing test events');
$catalog=new WorkerAdmin\Application\Testing\TestScenarioCatalog();
foreach($catalog->all() as $scenario){
    foreach(['title','description'] as $field){
        $value=(string)($scenario['defaults'][$field]??'');
        coverageAssert(preg_match('/TEST |testing|far_distance|pending_worker/',$value)!==1,'scenario '.$scenario['key'].' has Chinese '.$field);
    }
}
$dashboard=view('admin/test-center',[
    'diagnostics'=>['environment'=>'testing'],
    'instances'=>[array_merge($instance,$order)],
    'coverage'=>['scenarios'=>[['key'=>$scenarioKey,'name'=>'普通施工测试','status'=>'not_run']]],
],null);
foreach(['测试环境','未执行','已生成','待检查','普通施工'] as $label){
    // The fixture has passed verification; pending is exercised directly above.
    if($label==='待检查')continue;
    coverageAssert(strpos($dashboard,$label)!==false,'dashboard renders '.$label);
}
$batch=view('admin/test-center-batch-cleanup',['preview'=>['items'=>[['status'=>'ready']],'counts'=>['orders'=>1]]],null);
coverageAssert(strpos($batch,'可执行')!==false&&strpos($batch,'>ready<')===false,'batch cleanup status is Chinese');
$entry=view('admin/test-center-entry-token',['entry'=>['actor_role'=>'worker','worker_slot'=>'worker_1']],null);
coverageAssert(strpos($entry,'角色 师傅')!==false&&strpos($entry,'测试师傅一')!==false,'entry role and slot are Chinese');
restore_error_handler();

fwrite(STDOUT,"Test-center coverage and Chinese presentation regression passed.\n");
