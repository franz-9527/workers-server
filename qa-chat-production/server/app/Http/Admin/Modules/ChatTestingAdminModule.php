<?php
declare(strict_types=1);
namespace WorkerAdmin\Http\Admin\Modules;
use RuntimeException;
use Throwable;
use WorkerAdmin\Application\Chat\ChatSandbox;
use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Http\Admin\AdminModule;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

final class ChatTestingAdminModule implements AdminModule
{
    public function __construct(private Database $db,private Auth $auth,private Csrf $csrf){}
    public function supports(string $route):bool{return $route==='chat.testing';}
    public function dispatch(string $route):void
    {
        $this->auth->requirePermission('settings.manage');
        if(($this->auth->user()['role']??'')!=='super_admin'||!TestingEnvironmentPolicy::active())throw new RuntimeException('会话测试仅供测试站超级管理员使用',403);
        header('Cache-Control: private, no-store');$service=new ChatSandbox($this->db);$ready=$service->ready();$notice='';$values=[];$room='';
        $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
        if(!in_array($method,['GET','POST'],true))throw new RuntimeException('请求方式不正确',405);
        $page=max(1,min(10000,(int)($_GET['page']??1)));
        if($method==='POST'){
            $this->csrf->verifyRequest();
            try{
                if(!$ready)throw new RuntimeException('请先执行后台数据库升级至2.7.2');
                $admin=(int)$this->auth->user()['id'];$action=$_POST['_action']??'';$room=is_string($_POST['room']??null)?$_POST['room']:'';
                if($action==='live_credentials'){
                    if(!$service->liveReady())throw new RuntimeException('请先升级后台数据库至2.7.3',503);
                    $sender=is_string($_POST['sender']??null)?$_POST['sender']:'';
                    $state=$service->dashboard(1,$room);$member=false;foreach($state['members'] as $person)if($person['actor_key']===$sender)$member=true;
                    if(!$member)throw new RuntimeException('请选择当前测试会话的成员',403);
                    $issuer=(new \WorkerAdmin\Foundation\ChatApplicationContainer($this->db))->credentials();
                    \json_response(['ok'=>true,'data'=>$issuer->exchangeSandboxAdmin($this->auth,$sender)+['room_id'=>$room,'members'=>$state['members']]]);return;
                }
                if($action==='create'){$result=$service->create($_POST,$admin);$room=$result['id'];\audit('chat.test_create','chat_sandbox',(int)$room,'创建隔离测试会话');\flash('success','会话已创建，可开启双端真实联调');}
                elseif($action==='add_mini_admin'){
                    $key=is_string($_POST['mini_admin']??null)?$_POST['mini_admin']:'';
                    $service->addMiniAdmin($room,$key,$admin);
                    \audit('chat.test_add_mini_admin','chat_sandbox',(int)$room,'添加小程序管理员到测试会话');
                    \flash('success','小程序管理员已加入测试会话');
                }
                elseif($action==='send'){
                    foreach(['sender','text','request_key'] as $field)if(!is_string($_POST[$field]??null))throw new RuntimeException('发送参数不完整',400);
                    $result=$service->send($room,$_POST['sender'],$_POST['text'],$_POST['request_key'],$admin);
                    \audit('chat.test_send','chat_sandbox',(int)$room,'发送隔离测试消息 '.$result['message_id']);\flash('success','测试消息已保存');
                }elseif(in_array($action,['delete','clear_all'],true)){
                    $counts=$service->clear($admin,$action==='delete'?$room:null);
                    \audit($action==='delete'?'chat.test_delete':'chat.test_clear_all','chat_sandbox',$action==='delete'?(int)$room:0,'清理隔离测试会话和消息，共'.array_sum($counts).'条记录');
                    $room='';\flash('success','会话入口已清理，聊天正文与媒体文件将由Go后台继续清理。');
                }else throw new RuntimeException('操作无效',400);
                \redirect(\admin_url('chat.testing',array_filter(['room'=>$room],static fn($v)=>$v!=='')));return;
            }catch(RuntimeException $e){if(($_POST['_action']??'')==='live_credentials'){\json_response(['ok'=>false,'code'=>str_starts_with($e->getMessage(),'CHAT_')?$e->getMessage():'CHAT_TEST_UNAVAILABLE','message'=>['CHAT_GO_NOT_READY'=>'Go服务尚未更新或未就绪，请检查聊天设置','CHAT_SIGNING_NOT_CONFIGURED'=>'聊天签名未配置','CHAT_RECOVERY_NOT_READY'=>'聊天恢复检查未通过'][$e->getMessage()]??$e->getMessage()],in_array($e->getCode(),[400,401,403,404,409],true)?$e->getCode():503);return;}$notice=$e->getMessage();$values=$_POST;}
            catch(Throwable $e){if(($_POST['_action']??'')==='live_credentials'){\json_response(['ok'=>false,'message'=>'聊天运行配置暂不可用，请检查聊天设置与Go服务状态'],503);return;}$notice='操作未完成，请刷新后核对会话状态';$values=$_POST;}
        }
        $room=$room!==''?$room:(is_string($_GET['room']??null)?$_GET['room']:'');$chatState=['rooms'=>[],'members'=>[],'messages'=>[],'room'=>null,'page'=>$page,'more'=>false,'older'=>false,'before'=>''];$actors=[];
        if($ready)try{
            $actors=$service->actors();$chatState=$service->dashboard($page,$room,is_string($_GET['before']??null)?$_GET['before']:'');
        }catch(RuntimeException $e){
            if(($_GET['poll']??'')==='1'){\json_response(['ok'=>false,'message'=>$e->getMessage()],in_array($e->getCode(),[400,403,404],true)?$e->getCode():503);return;}
            $notice=$notice?:$e->getMessage();
        }
        if(($_GET['poll']??'')==='1'){
            foreach($chatState['messages'] as &$message)$message['created_label']=\local_datetime($message['created_at']);unset($message);
            \json_response(['ok'=>true,'data'=>['messages'=>$chatState['messages'],'members'=>$chatState['members']]]);return;
        }
        echo \view('admin/chat-testing',['pageTitle'=>'会话测试','activeMenu'=>'chat.testing','ready'=>$ready,'liveReady'=>$service->liveReady(),'notice'=>$notice,'actors'=>$actors,'chatState'=>$chatState,'values'=>$values,'requestKey'=>is_string($values['request_key']??null)&&preg_match('/^[a-f0-9]{32}$/D',$values['request_key'])===1?$values['request_key']:bin2hex(random_bytes(16))]);
    }
}
