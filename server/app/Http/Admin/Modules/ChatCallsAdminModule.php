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
        $this->auth->requirePermission('chat.calls.view');$service=(new ChatApplicationContainer($this->db))->calls();$notice='';
        $section=is_string($_GET['section']??null)?$_GET['section']:'resources';
        if(!in_array($section,['settings','credentials','resources','register','active','history','audit'],true))$section='resources';
        $page=max(1,min(10000,(int)($_GET['page']??1)));
        $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
        if(!in_array($method,['GET','POST'],true))throw new RuntimeException('请求方式不正确');
        if($method==='POST'){
            $this->csrf->verifyRequest();
            $this->auth->requirePermission(in_array(($_POST['_action']??''),['settings','credentials'],true)?'settings.manage':'chat.calls.quota.manage');
            try{$user=$this->auth->user();$service->change($_POST,(int)$user['id'],(string)$user['role']);\flash('success','通话设置已保存');\redirect(\admin_url('chat.calls',['section'=>$section]));return;}
            catch(RuntimeException $e){$notice=$e->getMessage();}
            catch(Throwable $e){$notice='操作未完成，请刷新后核对状态';}
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
        echo \view('admin/chat-calls',['pageTitle'=>'音视频通话','activeMenu'=>'chat.calls','notice'=>$notice,'current'=>$service->current($page),'section'=>$section,'credentials'=>$credentials,
            'canManage'=>$canManage]);
    }
}
