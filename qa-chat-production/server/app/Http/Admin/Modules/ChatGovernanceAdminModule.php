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
        $this->auth->requirePermission('audit.view');
        $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
        if($method!=='GET'){http_response_code(405);throw new RuntimeException('此入口仅用于查询，请在聊天审核页面处理内容');}
        if($route==='chat.auditors'){\redirect(\admin_url('chat.governance'));return;}
        $section=is_string($_GET['section']??null)?$_GET['section']:'records';
        if(!in_array($section,['records','pending','cases'],true))$section='records';
        if($section==='cases'&&!$this->auth->can('users.manage'))$section='records';
        echo \view('admin/chat-governance',['pageTitle'=>'聊天审核','activeMenu'=>'chat.governance','section'=>$section,
            'canModerate'=>$this->auth->can('users.manage'),'canEvidence'=>$this->auth->can('chat.evidence.view'),
            'canImages'=>$this->auth->can('chat.images.review'),'canMedia'=>$this->auth->can('chat.media.review')]);
    }
}
