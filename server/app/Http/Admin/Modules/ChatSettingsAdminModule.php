<?php
declare(strict_types=1);
namespace WorkerAdmin\Http\Admin\Modules;
use RuntimeException;
use WorkerAdmin\Application\Chat\ChatReleaseSettings;
use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Http\Admin\AdminModule;
final class ChatSettingsAdminModule implements AdminModule
{
    public function __construct(private Database $db,private Auth $auth,private Csrf $csrf) {}
    public function supports(string $route):bool{return $route==='chat.settings';}
    public function dispatch(string $route):void
    {
        $this->auth->requirePermission('settings.manage');$service=new ChatReleaseSettings($this->db);$notice='';
        $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
        if(!in_array($method,['GET','POST'],true))throw new RuntimeException('请求方式不正确');
        $admin=$this->auth->user();$canManage=($admin['role']??'')==='super_admin';
        if($method==='POST'){
            $this->csrf->verifyRequest();
            try{$service->save($_POST,(int)$admin['id'],(string)$admin['role']);\audit('chat.settings','system_setting',0,$_POST['enabled']==='1'?'开放订单聊天':'暂停订单聊天');\flash('success','聊天开放状态已保存');\redirect(\admin_url('chat.settings'));return;}
            catch(RuntimeException $e){$notice=$e->getMessage();}
        }
        echo \view('admin/chat-settings',['pageTitle'=>'聊天设置','activeMenu'=>'chat.settings','enabled'=>$service->enabled(),'canManage'=>$canManage,'notice'=>$notice]);
    }
}
