<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Admin\Modules;

use Throwable;
use WorkerAdmin\Application\Testing\TestCenter;
use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Foundation\TestCenterComposition;
use WorkerAdmin\Http\Admin\AdminModule;
use WorkerAdmin\Infrastructure\Testing\TestCenterEntrySession;

final class TestCenterAdminModule implements AdminModule
{
    private Auth $auth;
    private Csrf $csrf;
    private TestCenter $testCenter;
    private TestCenterEntrySession $testEntry;

    public function __construct(Database $db, Auth $auth, Csrf $csrf)
    {
        $this->auth=$auth;$this->csrf=$csrf;$this->testCenter=TestCenterComposition::build($db);$this->testEntry=new TestCenterEntrySession($db);
    }

    public function supports(string $route): bool{return$route==='test-center'||strpos($route,'test-center.')===0;}

    public function dispatch(string $route): void
    {
        $this->auth->requireLogin();$admin=$this->auth->user();
        if(!empty($admin['must_change_password'])){\flash('warning','首次登录必须先修改演示密码。');\redirect(\admin_url('profile'));}
        $this->auth->requirePermission('settings.manage');
        if(($admin['role']??'')!=='super_admin'){http_response_code(403);echo \view('errors/403',['pageTitle'=>'无权访问测试中心']);return;}
        $allowed=['test-center','test-center.detail','test-center.create','test-center.verify','test-center.action','test-center.replay-failure','test-center.copy','test-center.rebuild','test-center.cleanup','test-center.batch-cleanup','test-center.batch-cleanup.execute','test-center.entry-token'];
        if(!in_array($route,$allowed,true)){http_response_code(404);echo \view('errors/404',['pageTitle'=>'页面不存在']);return;}
        $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));if($method==='POST')$this->csrf->verifyRequest();$adminId=(int)($admin['id']??0);
        try{
            if($route==='test-center'){
                if($method!=='GET')throw new TestCenterException('请求方式不正确。','METHOD_NOT_ALLOWED',405);
                $filters=['q'=>trim((string)($_GET['q']??'')),'status'=>trim((string)($_GET['status']??'')),'scenario'=>trim((string)($_GET['scenario']??''))];
                $data=$this->testCenter->dashboard($filters);$requestKeys=[];
                foreach((array)($data['scenarios']??[])as$scenario){$key=(string)($scenario['key']??'');if($key!=='')$requestKeys[$key]='create:'.bin2hex(random_bytes(12));}
                echo \view('admin/test-center',array_merge($data,['pageTitle'=>'测试中心','activeMenu'=>'test-center','filters'=>$filters,'requestKeys'=>$requestKeys]));return;
            }
            if($route==='test-center.detail'){
                if($method!=='GET')throw new TestCenterException('请求方式不正确。','METHOD_NOT_ALLOWED',405);
                $id=trim((string)($_GET['id']??''));$detail=$this->testCenter->detail($id);$detail['entry_tokens']=$this->testEntry->options($id);$actionRequestKeys=[];$failureReplayRequestKeys=[];
                foreach((array)($detail['actions']['items']??[])as$key=>$action){$actionKey=(string)($action['key']??$key);if($actionKey!=='')$actionRequestKeys[$actionKey]='action:'.bin2hex(random_bytes(12));}
                foreach((array)($detail['failure_replays']['items']??[])as$item){$runId=(string)($item['run_public_id']??'');if($runId!==''&&!empty($item['replayable']))$failureReplayRequestKeys[$runId]='failure-replay:'.bin2hex(random_bytes(12));}
                echo \view('admin/test-center-detail-v260',['pageTitle'=>'测试订单详情','activeMenu'=>'test-center','detail'=>$detail,'copyRequestKey'=>'copy:'.bin2hex(random_bytes(12)),'rebuildRequestKey'=>'rebuild:'.bin2hex(random_bytes(12)),'actionRequestKeys'=>$actionRequestKeys,'failureReplayRequestKeys'=>$failureReplayRequestKeys]);return;
            }
            if($route==='test-center.batch-cleanup'){
                if($method!=='GET')throw new TestCenterException('请求方式不正确。','METHOD_NOT_ALLOWED',405);
                $batch=trim((string)($_GET['batch']??''));$preview=$this->testCenter->batchCleanupPreview($batch);
                echo \view('admin/test-center-batch-cleanup',['pageTitle'=>'测试批次清理','activeMenu'=>'test-center','preview'=>$preview]);return;
            }
            if($method!=='POST')throw new TestCenterException('请求方式不正确。','METHOD_NOT_ALLOWED',405);
            if($route==='test-center.create'){
                $result=$this->testCenter->create($_POST,$adminId);$id=(string)($result['instance']['public_id']??'');
                \audit('test_center.create','test_instance',(int)($result['instance']['id']??0),'创建测试场景 '.(string)($result['instance']['scenario_key']??''));\flash('success','测试场景已生成并完成结构检查。');\redirect(\admin_url('test-center.detail',['id'=>$id]));
            }
            if($route==='test-center.entry-token'){
                $id=trim((string)($_POST['id']??''));
                $entry=$this->testEntry->issue(
                    $id,
                    (string)($_POST['role']??''),
                    (string)($_POST['worker_slot']??''),
                    (int)($_POST['ttl_minutes']??10),
                    $adminId
                );
                \audit('test_center.entry_issue','test_instance',0,'生成测试中心短期小程序入口，实例 '.$id.'，角色 '.(string)$entry['actor_role'],[
                    'entry_public_id'=>(string)$entry['public_id'],'worker_slot'=>$entry['worker_slot'],'expires_at'=>(string)$entry['expires_at'],
                ]);
                echo \view('admin/test-center-entry-token',['pageTitle'=>'短期小程序测试入口','activeMenu'=>'test-center','entry'=>$entry]);
                return;
            }
            if($route==='test-center.batch-cleanup.execute'){
                $batch=trim((string)($_POST['batch']??''));$result=$this->testCenter->cleanupBatch($batch,$adminId,trim((string)($_POST['plan_digest']??'')));
                \audit('test_center.batch_cleanup','test_batch',0,'清理测试批次 '.$batch.'；成功 '.(int)$result['succeeded'].'，失败 '.(int)$result['failed']);
                \flash(!empty($result['complete'])?'success':'warning','批次清理完成：成功 '.(int)$result['succeeded'].'，失败 '.(int)$result['failed'].'。');
                \redirect(\admin_url('test-center'));
            }
            $id=trim((string)($_POST['id']??''));
            if($route==='test-center.action'){
                $action=trim((string)($_POST['action_key']??''));$result=$this->testCenter->runAction($id,$_POST,$adminId);$mode=(string)($result['operation_mode']??'real_service');
                \audit('test_center.action','test_instance',0,'执行测试动作 '.$action.'，mode='.$mode.'，实例 '.$id.'，run '.(string)($result['run_public_id']??''));
                if(!empty($result['replayed']))$message='该测试动作此前已成功执行，本次返回幂等结果。';
                elseif($mode==='real_service')$message='真实业务动作执行成功，订单/售后状态已按正式流程推进。';
                elseif($mode==='time_fixture')$message='测试时间条件已推进；该步骤不计作真实业务动作。';
                else $message='测试前置条件已补齐；该步骤不计作真实业务动作。';
                \flash('success',$message);\redirect(\admin_url('test-center.detail',['id'=>$id]));
            }
            if($route==='test-center.replay-failure'){
                $sourceRun=trim((string)($_POST['run_id']??''));$requestKey=trim((string)($_POST['request_key']??''));
                $result=$this->testCenter->replayFailure($id,$sourceRun,$adminId,$requestKey);
                \audit('test_center.failure_replay','test_instance',0,'重放失败测试动作，实例 '.$id.'，来源 run '.$sourceRun.'，新 run '.(string)($result['run_public_id']??''));
                \flash('success',!empty($result['replayed'])?'该失败动作已经按同一复现请求执行过，本次返回幂等结果。':'失败动作已使用原安全参数重新执行。');
                \redirect(\admin_url('test-center.detail',['id'=>$id]));
            }
            if($route==='test-center.verify'){$result=$this->testCenter->verify($id,$adminId);\flash($result['failed']>0?'danger':'success',$result['failed']>0?'检查发现失败断言，请查看详情。':'测试实例检查通过。');\redirect(\admin_url('test-center.detail',['id'=>$id]));}
            if($route==='test-center.copy'){$result=$this->testCenter->copy($id,$adminId,(string)($_POST['request_key']??''));$newId=(string)($result['instance']['public_id']??'');\audit('test_center.copy','test_instance',(int)($result['instance']['id']??0),'复制测试场景，来源 '.$id);\flash('success','已复制为新的测试实例。');\redirect(\admin_url('test-center.detail',['id'=>$newId]));}
            if($route==='test-center.rebuild'){$result=$this->testCenter->rebuild($id,$adminId,(string)($_POST['request_key']??''));$newId=(string)($result['instance']['public_id']??'');\audit('test_center.rebuild','test_instance',(int)($result['instance']['id']??0),'重建测试场景，来源 '.$id);\flash('success','已按原场景参数重建新实例，旧实例和报告保留。');\redirect(\admin_url('test-center.detail',['id'=>$newId]));}
            if($route==='test-center.cleanup'){$result=$this->testCenter->cleanup($id,$adminId,trim((string)($_POST['plan_digest']??'')));\flash('success','测试业务数据已清理，实例 tombstone 与检查记录继续保留。');\redirect(\admin_url('test-center.detail',['id'=>(string)($result['public_id']??$id)]));}
        }catch(TestCenterException $exception){
            \flash('danger',$exception->getMessage().'（'.$exception->businessCode().'）');
            if($method==='GET')\redirect(\admin_url('test-center'));
            $batch=trim((string)($_POST['batch']??''));if($batch!=='')\redirect(\admin_url('test-center.batch-cleanup',['batch'=>$batch]));
            $id=trim((string)($_POST['id']??''));\redirect($id!==''?\admin_url('test-center.detail',['id'=>$id]):\admin_url('test-center'));
        }catch(Throwable $exception){
            $requestId=\request_id();\write_log('test-center','['.date('c').'] request_id='.$requestId.' '.$exception."\n");\flash('danger','测试中心处理失败，请查看运行日志。请求编号：'.$requestId);
            if($method==='GET')\redirect(\admin_url('test-center'));
            $batch=trim((string)($_POST['batch']??''));if($batch!=='')\redirect(\admin_url('test-center.batch-cleanup',['batch'=>$batch]));
            $id=trim((string)($_POST['id']??''));\redirect($id!==''?\admin_url('test-center.detail',['id'=>$id]):\admin_url('test-center'));
        }
    }
}
