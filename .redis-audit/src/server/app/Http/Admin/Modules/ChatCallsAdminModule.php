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
        $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
        if(!in_array($method,['GET','POST'],true))throw new RuntimeException('请求方式不正确');
        if($method==='POST'){
            $this->csrf->verifyRequest();
            $this->auth->requirePermission(($_POST['_action']??'')==='settings'?'settings.manage':'chat.calls.quota.manage');
            try{$user=$this->auth->user();$service->change($_POST,(int)$user['id'],(string)$user['role']);\flash('success','通话设置已保存');\redirect(\admin_url('chat.calls'));return;}
            catch(RuntimeException $e){$notice=$e->getMessage();}
            catch(Throwable $e){$notice='操作未完成，请刷新后核对状态';}
        }
        echo \view('admin/chat-calls',['pageTitle'=>'音视频通话','activeMenu'=>'chat.calls','notice'=>$notice,'current'=>$service->current(),
            'canManage'=>$this->auth->can('chat.calls.quota.manage')&&$this->auth->can('settings.manage')]);
    }
}
