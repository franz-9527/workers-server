<?php
declare(strict_types=1);
namespace WorkerAdmin\Http\Admin\Modules;
use RuntimeException;
use Throwable;
use WorkerAdmin\Foundation\ChatApplicationContainer;
use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Http\Admin\AdminModule;

final class ChatCallsAdminModule implements AdminModule
{
    public function __construct(private Database $db,private Auth $auth,private Csrf $csrf) {}
    public function supports(string $route):bool{return $route==='chat.calls';}
    public function dispatch(string $route):void
    {
        $this->auth->requirePermission('chat.calls.view');$service=(new ChatApplicationContainer($this->db))->calls();$notice='';$formValues=[];
        $section=is_string($_GET['section']??null)?$_GET['section']:'resources';
        if(!in_array($section,['settings','credentials','resources','register','active','history','audit'],true))$section='resources';
        $page=max(1,min(10000,(int)($_GET['page']??1)));
        $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
        if(!in_array($method,['GET','POST'],true))throw new RuntimeException('请求方式不正确');
        if($method==='POST'){
            $this->csrf->verifyRequest();
            $this->auth->requirePermission(in_array(($_POST['_action']??''),['settings','credentials','check_connection','check_control'],true)?'settings.manage':'chat.calls.quota.manage');
            try{
                $user=$this->auth->user();
                if(in_array(($_POST['_action']??''),['check_connection','check_control'],true)){
                    $this->auth->requirePermission('settings.manage');
                    if(($user['role']??'')!=='super_admin')throw new RuntimeException('仅超级管理员可检测通话配置。');
                    $last=$_SESSION['chat_call_connection_report']??[];
                    if(($last['admin_id']??0)===(int)$user['id']&&time()-(int)($last['timestamp']??0)<10)
                        throw new RuntimeException('刚刚已检测，请稍候再试。');
                    $checker=new \WorkerAdmin\Application\Chat\ChatCallConnectionCheck((new ChatApplicationContainer($this->db))->callCredentials());
                    $report=$checker->run(($_POST['_action']??'')==='check_control');
                    $_SESSION['chat_call_connection_report']=['admin_id'=>(int)$user['id'],'timestamp'=>time(),'report'=>$report];
                    \audit(($_POST['_action']??'')==='check_control'?'chat.call_control_check':'chat.call_connection_check','system_setting',0,
                        !empty($report['control_checked'])?'声网空频道停止接口自检通过；真机通话停止未验证':($report['connected']?'声网官方查询接口连接通过；真机通话停止未验证':'声网配置或连接检查未通过'));
                    \flash('success','检测已完成，请查看检测结果。');
                }else{
                    $changed=$service->change($_POST,(int)$user['id'],(string)$user['role']);
                    unset($_SESSION['chat_call_connection_report']);
                                        if(($_POST['_action']??'')==='pool'&&is_array($changed)&&!empty($changed['pool_id'])){
                        \flash('success','资源已登记；下方显示刚保存的记录及用量，未确认事项会单独标注。');
                        \redirect(\admin_url('chat.calls',['section'=>'resources','page'=>1]).'#chat-pool-'.$changed['pool_id']);return;
                    }
                    \flash('success','通话设置已保存');
                }
                \redirect(\admin_url('chat.calls',['section'=>$section]));return;
            }
            catch(\InvalidArgumentException $e){$notice='总额、使用上限或余量设置不符合要求，请核对数值。';}
            catch(RuntimeException $e){$notice=$e->getMessage();}
            catch(Throwable $e){$notice='操作未完成，请刷新后核对状态';}
            if(($_POST['_action']??'')==='pool')foreach($_POST as $key=>$value)if(is_string($key)&&is_string($value)&&strlen($value)<=128)$formValues[$key]=$value;
        }
        $canManage=$this->auth->can('chat.calls.quota.manage')&&$this->auth->can('settings.manage');
        $credentials=[];
        if($canManage&&$section==='credentials'){
            header('Cache-Control: no-store, private');
            try{$credentials=(new ChatApplicationContainer($this->db))->callCredentials()->adminFormValues();}
            catch(Throwable $e){$notice='通话密钥读取失败，请刷新或重新填写后保存。';}
        }elseif($this->auth->can('settings.manage')){
            $credentials=(new ChatApplicationContainer($this->db))->callCredentials()->current();
        }
        $last=$_SESSION['chat_call_connection_report']??[];
        $connectionReport=$canManage&&($last['admin_id']??0)===(int)($this->auth->user()['id']??0)
            &&time()-(int)($last['timestamp']??0)<1800?($last['report']??null):null;
        echo \view('admin/chat-calls',['pageTitle'=>'音视频通话','activeMenu'=>'chat.calls','notice'=>$notice,'current'=>$service->current($page,$section==='register'),'section'=>$section,'credentials'=>$credentials,
            'canManage'=>$canManage,'connectionReport'=>$connectionReport,'formValues'=>$formValues]);
    }
}
