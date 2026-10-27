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
        $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
        if($route==='chat.auditors'&&$method==='POST'){
            $id=trim((string)($_POST['user_id']??''));$reason=trim((string)($_POST['reason']??''));$enabled=(string)($_POST['enabled']??'')==='1';
            if(preg_match('/^[1-9][0-9]{0,19}$/D',$id)!==1||mb_strlen($reason)<2||mb_strlen($reason)>200||!$this->db->fetchValue("SELECT id FROM users WHERE id=:id AND status='active'",['id'=>$id]))throw new RuntimeException('请填写真实用户编号和授权理由');
            $admin=(string)$this->auth->user()['id'];$version=bin2hex(random_bytes(32));
            $this->db->transaction(function()use($id,$reason,$enabled,$admin,$version):void{
                $this->db->execute('INSERT INTO chat_mini_auditors(user_id,enabled,authority_version,granted_by,reason,updated_at) VALUES(:id,:enabled,:version,:admin,:reason,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),authority_version=VALUES(authority_version),granted_by=VALUES(granted_by),reason=VALUES(reason),updated_at=VALUES(updated_at)',['id'=>$id,'enabled'=>(int)$enabled,'version'=>$version,'admin'=>$admin,'reason'=>$reason]);
                $this->db->execute('INSERT INTO chat_auditor_grant_audit(user_id,enabled,authority_version,admin_id,reason,created_at) VALUES(:id,:enabled,:version,:admin,:reason,UTC_TIMESTAMP(6))',['id'=>$id,'enabled'=>(int)$enabled,'version'=>$version,'admin'=>$admin,'reason'=>$reason]);
            });
            \flash('success','会话抽检授权已更新');\redirect(\admin_url('chat.auditors'));return;
        }
        if($method!=='GET'){http_response_code(405);throw new RuntimeException('请求方式不正确');}
        $grants=$route==='chat.auditors'?$this->db->fetchAll('SELECT user_id,enabled,granted_by,reason,updated_at FROM chat_mini_auditors ORDER BY updated_at DESC,user_id DESC LIMIT 50'):[];
        echo \view('admin/chat-governance',['pageTitle'=>$route==='chat.auditors'?'小程序抽检授权':'聊天抽检与治理','activeMenu'=>$route,'grants'=>$grants,'grantPage'=>$route==='chat.auditors',
            'canModerate'=>$this->auth->can('users.manage'),'canEvidence'=>$this->auth->can('chat.evidence.view')]);
    }
}
