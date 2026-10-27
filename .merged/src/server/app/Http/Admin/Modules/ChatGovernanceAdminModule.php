<?php
declare(strict_types=1);
namespace WorkerAdmin\Http\Admin\Modules;
use RuntimeException;
use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Http\Admin\AdminModule;
/** PHP owns page/identity grants only; every chat-governance mutation uses Go. */
final class ChatGovernanceAdminModule implements AdminModule
{
    private Database $db;private Auth $auth;
    public function __construct(Database $db,Auth $auth,Csrf $csrf){$this->db=$db;$this->auth=$auth;}
    public function supports(string $route):bool{return in_array($route,['chat.governance','chat.auditors'],true);}
    public function dispatch(string $route):void
    {
        $this->auth->requirePermission($route==='chat.auditors'?'chat.audit.grants':'audit.view');
        $service=(new \WorkerAdmin\Foundation\ChatApplicationContainer($this->db))->auditors();
        $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
        if($route==='chat.auditors'&&$method==='POST'){
            $service->change($_POST,(string)$this->auth->user()['id']);
            \flash('success','会话抽检授权已更新');\redirect(\admin_url('chat.auditors'));return;
        }
        if($method!=='GET'){http_response_code(405);throw new RuntimeException('请求方式不正确');}
        $grants=$route==='chat.auditors'?$service->recent():[];
        echo \view('admin/chat-governance',['pageTitle'=>$route==='chat.auditors'?'小程序抽检授权':'聊天抽检与治理','activeMenu'=>$route,'grants'=>$grants,'grantPage'=>$route==='chat.auditors',
            'canModerate'=>$this->auth->can('users.manage'),'canEvidence'=>$this->auth->can('chat.evidence.view')]);
    }
}
