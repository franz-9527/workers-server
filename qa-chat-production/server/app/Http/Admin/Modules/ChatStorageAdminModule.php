<?php
declare(strict_types=1);
namespace WorkerAdmin\Http\Admin\Modules;

use RuntimeException;
use Throwable;
use WorkerAdmin\Application\Chat\ChatStorageSettings;
use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Http\Admin\AdminModule;

final class ChatStorageAdminModule implements AdminModule
{
    private Database $db; private Auth $auth;private Csrf $csrf;private ChatStorageSettings $settings;
    public function __construct(Database $db,Auth $auth,Csrf $csrf)
    { $this->db=$db;$this->auth=$auth;$this->csrf=$csrf;$this->settings=new ChatStorageSettings($db,(string)\app_config('app.key','')); }
    public function supports(string $route):bool { return $route==='chat.storage'; }
    public function dispatch(string $route):void
    {
        $this->auth->requirePermission('settings.manage');
        $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
        if(!in_array($method,['GET','POST'],true)){http_response_code(405);throw new RuntimeException('请求方式不正确');}
        $section=is_string($_GET['section']??null)?$_GET['section']:'policy';
        if(!in_array($section,['policy','groups','history'],true))$section='policy';
        $page=max(1,min(10000,(int)($_GET['page']??1)));
        $preview=null;$notice='';
        try{
            if($method==='POST'){
                $this->csrf->verifyRequest();$admin=$this->auth->user();$action=$_POST['_action']??'';
                if(in_array($action,['group_remove','group_intervene','group_end_intervention'],true)){
                    $this->auth->requirePermission('users.manage');((new \WorkerAdmin\Foundation\ChatApplicationContainer($this->db))->collaborationAdmin())->change($_POST,(int)$admin['id'],(string)$admin['role']);
                    \flash('success','协作群成员已更新');\redirect(\admin_url('chat.storage',['section'=>$section]));return;
                }
                elseif($action==='group_limit'){$this->settings->saveGroupLimit($_POST,(int)$admin['id'],(string)$admin['role']);\flash('success','协作群人数上限已更新；已有成员保留');\redirect(\admin_url('chat.storage',['section'=>$section]));return;}
                elseif($action==='preview')$preview=$this->settings->preview($_POST,(int)$admin['id']);
                elseif($action==='save'){
                    $this->settings->save($_POST,(int)$admin['id'],(string)$admin['role']);
                    \flash('success','聊天保存设置已更新；历史应用按预览范围分批处理');\redirect(\admin_url('chat.storage',['section'=>$section]));return;
                }else throw new RuntimeException('请先预览设置');
            }
        }catch(RuntimeException $error){$notice=$error->getMessage();}
        catch(Throwable $error){$notice='暂时无法读取或保存聊天设置，请稍后重试';}
        $current=$this->settings->current($page);
        echo \view('admin/chat-storage',['pageTitle'=>'聊天存储与保存期','activeMenu'=>'chat.storage','section'=>$section,'current'=>$current,'policy'=>$preview['policy']??$current['policy'],'preview'=>$preview,'notice'=>$notice,'canManageGroups'=>$this->auth->can('users.manage'),'groupOrderNo'=>is_string($_GET['group_order_no']??null)?$_GET['group_order_no']:'','groupMembers'=>((new \WorkerAdmin\Foundation\ChatApplicationContainer($this->db))->collaborationAdmin())->members(is_string($_GET['group_order_no']??null)?$_GET['group_order_no']:'')]);
    }
}
