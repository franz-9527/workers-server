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
        $section=is_string($_GET['section']??null)?$_GET['section']:'settings';
        if($section==='credentials')$section='settings';
        if(!in_array($section,['settings','credentials','resources','register','active','history','audit'],true))$section='resources';
        $page=max(1,min(10000,(int)($_GET['page']??1)));
        $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
        if(!in_array($method,['GET','POST'],true))throw new RuntimeException('请求方式不正确');
        if($method==='POST'){
            $this->csrf->verifyRequest();
            $this->auth->requirePermission(in_array(($_POST['_action']??''),['settings','configuration','credentials','check_connection','check_control','read_provider_usage','apply_runtime'],true)?'settings.manage':'chat.calls.quota.manage');
            try{
                $user=$this->auth->user();
                if(($_POST['_action']??'')==='read_provider_usage'){
                    if(($user['role']??'')!=='super_admin')throw new RuntimeException('仅超级管理员可查询供应商用量。');
                    unset($_SESSION['chat_call_usage_report']);
                    \redirect(\admin_url('chat.calls',['section'=>'resources']).'#chat-provider-usage');return;
                }elseif(($_POST['_action']??'')==='apply_runtime'){
                    if(($user['role']??'')!=='super_admin')throw new RuntimeException('仅超级管理员可应用聊天配置。');
                    \flash('success',(new \WorkerAdmin\Application\Chat\ChatRuntimeControl())->request()['message']);
                }elseif(in_array(($_POST['_action']??''),['check_connection','check_control'],true)){
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
                    $applicationMessage='';
                    if(in_array(($_POST['_action']??''),['configuration','settings','credentials','pool','reverify','import','reconfirm_usage','budget','disable_pool'],true)){
                        try{$applicationMessage=(new \WorkerAdmin\Application\Chat\ChatRuntimeControl())->request()['message'];}
                        catch(\Throwable $e){$applicationMessage='设置已保存，自动应用暂未完成，请在本页重新应用配置。';}
                    }
                    if(is_array($changed)&&!empty($changed['pool_id'])){
                        $savedMessage=['pool'=>'资源已登记；确认选项已保存，尚缺事项会单独标注。','reverify'=>'资源设置已保存。','budget'=>'使用预算已保存。','import'=>'官方用量已更新并完成对账。','reconfirm_usage'=>'官方用量核对时间已刷新。','disable_pool'=>'资源池已停用。'][$_POST['_action']??'']??'资源已保存。';
                        \flash('success',$savedMessage.' '.$applicationMessage);
                        \redirect(\admin_url('chat.calls',['section'=>'resources','page'=>($_POST['_action']??'')==='pool'?1:$page]).'#chat-pool-'.$changed['pool_id']);return;
                    }
                    \flash('success','通话设置已保存。 '.$applicationMessage);
                }
                \redirect(\admin_url('chat.calls',['section'=>in_array(($_POST['_action']??''),['check_connection','check_control','apply_runtime'],true)?'settings':$section]).(in_array(($_POST['_action']??''),['check_connection','check_control','apply_runtime'],true)?'#chat-call-diagnostics':''));return;
            }
            catch(\InvalidArgumentException $e){$notice='总额、使用上限或余量设置不符合要求，请核对数值。';}
            catch(RuntimeException $e){$notice=$e->getMessage();}
            catch(Throwable $e){$notice='操作未完成，请刷新后核对状态';}
            if(in_array(($_POST['_action']??''),['pool','reverify','budget','import','reconfirm_usage','confirmed_stop','configuration','settings','credentials'],true))foreach($_POST as $key=>$value)if(is_string($key)&&is_string($value)&&strlen($value)<=512&&!in_array($key,['certificate','customer_id','customer_secret'],true))$formValues[$key]=$value;
        }
        $canManage=($this->auth->user()['role']??'')==='super_admin'&&$this->auth->can('chat.calls.quota.manage')&&$this->auth->can('settings.manage');
        $credentials=[];
        if($canManage&&$section==='settings'){
            header('Cache-Control: no-store, private');
            try{$credentials=(new ChatApplicationContainer($this->db))->callCredentials()->adminFormValues();}
            catch(Throwable $e){$notice='通话密钥读取失败，请刷新或重新填写后保存。';}
        }elseif($this->auth->can('settings.manage')){
            $credentials=(new ChatApplicationContainer($this->db))->callCredentials()->current();
        }
        $last=$_SESSION['chat_call_connection_report']??[];
        $connectionReport=$canManage&&($last['admin_id']??0)===(int)($this->auth->user()['id']??0)
            &&time()-(int)($last['timestamp']??0)<1800?($last['report']??null):null;
        $usageReport=null;
        if($canManage&&$section==='resources'){
            $previous=$_SESSION['chat_call_usage_report']??[];
            if(($previous['admin_id']??0)===(int)$this->auth->user()['id'] && time()-(int)($previous['timestamp']??0)<900){
                $usageReport=$previous['report']??null;
            }else{
                try{$usageReport=(new \WorkerAdmin\Application\Chat\ChatCallUsageLookup((new ChatApplicationContainer($this->db))->callCredentials()))->read();}
                catch(\Throwable $e){$usageReport=['error'=>$e->getMessage()];}
                $_SESSION['chat_call_usage_report']=['admin_id'=>(int)$this->auth->user()['id'],'timestamp'=>time(),'report'=>$usageReport];
            }
        }
        echo \view('admin/chat-calls',['pageTitle'=>'音视频通话','activeMenu'=>'chat.calls','notice'=>$notice,'current'=>$service->current($page,$section==='register'),'section'=>$section,'credentials'=>$credentials,
            'canManage'=>$canManage,'connectionReport'=>$connectionReport,'usageReport'=>$usageReport,'formValues'=>$formValues]);
    }
}
