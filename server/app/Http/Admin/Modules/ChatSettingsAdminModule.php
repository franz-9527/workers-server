<?php
declare(strict_types=1);
namespace WorkerAdmin\Http\Admin\Modules;

use RuntimeException;
use WorkerAdmin\Application\Chat\ChatGoRuntimeConfig;
use WorkerAdmin\Application\Chat\ChatGoRuntimeStatus;
use WorkerAdmin\Application\Chat\ChatReleaseSettings;
use WorkerAdmin\Application\Chat\ChatSigningKeyManager;
use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\ApplicationContainer;
use WorkerAdmin\Foundation\ChatApplicationContainer;
use WorkerAdmin\Http\Admin\AdminModule;

final class ChatSettingsAdminModule implements AdminModule
{
    public function __construct(private Database $db,private Auth $auth,private Csrf $csrf) {}
    public function supports(string $route):bool{return $route==='chat.settings';}

    public function dispatch(string $route):void
    {
        $this->auth->requirePermission('settings.manage');
        $service=new ChatReleaseSettings($this->db);
        $config=(array)\app_config('chat',[]);
        $shared=new ApplicationContainer($this->db);
        $signing=new ChatSigningKeyManager($this->db,$shared->privateCipher());
        $goRuntime=new ChatGoRuntimeConfig($this->db,$shared->privateCipher());
        $notice='';
        $control=new \WorkerAdmin\Application\Chat\ChatRuntimeControl();
        $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
        if(!in_array($method,['GET','POST'],true))throw new RuntimeException('请求方式不正确');
        $admin=$this->auth->user();
        $canManage=($admin['role']??'')==='super_admin';

        if($method==='POST'){
            $this->csrf->verifyRequest();
            try{
                $action=(string)($_POST['_action']??'save');
                if($action==='prepare_test'){
                    if(!$canManage)throw new RuntimeException('仅超级管理员可初始化测试聊天。');
                    if((string)\app_config('app.env','production')!=='testing')throw new RuntimeException('该操作仅用于测试站。');
                    $state=$signing->status($config);
                    if(empty($state['configured']))$signing->generate($config,(int)$admin['id'],(string)$admin['role']);
                    $goRuntime->prepareTesting((int)$admin['id'],(string)$admin['role']);
                    \audit('chat.test_prepare','system_setting',0,'初始化测试聊天签名和基础开关，未修改供应商凭据、资源池或正式站');
                    \flash('success',$control->request()['message']);
                }elseif($action==='apply_runtime'){
                    if(!$canManage)throw new RuntimeException('仅超级管理员可应用聊天配置。');
                    \flash('success',$control->request()['message']);
                }elseif($action==='generate_signing'||$action==='rotate_signing'){
                    if(!$canManage)throw new RuntimeException('仅超级管理员可生成或轮换聊天签名密钥。');
                    $state=$signing->generate($config,(int)$admin['id'],(string)$admin['role'],$action==='rotate_signing');
                    \audit('chat.signing_key','system_setting',0,($action==='rotate_signing'?'轮换':'生成').'聊天签名密钥 '.$state['kid']);
                    \flash('success',$action==='rotate_signing'?'聊天签名密钥已轮换；重启 Go 项目后自动同步新公钥。':'聊天签名密钥已生成；重启 Go 项目后会自动同步，无需再手改 chat.json。');
                }elseif($action==='save_go_runtime'){
                    if(!$canManage)throw new RuntimeException('仅超级管理员可修改 Go 服务配置。');
                    $goRuntime->save($_POST,(int)$admin['id'],(string)$admin['role']);
                    \audit('chat.go_runtime','system_setting',0,'更新 Go 聊天运行配置');
                    \flash('success',$control->request()['message']);
                }elseif($action==='save'){
                    $service->save($_POST,(int)$admin['id'],(string)$admin['role']);
                    \audit('chat.settings','system_setting',0,$_POST['enabled']==='1'?'开放订单聊天':'暂停订单聊天');
                    \flash('success','聊天开放状态已保存');
                }else{
                    throw new RuntimeException('不支持的聊天设置操作。');
                }
                \redirect(\admin_url('chat.settings'));
                return;
            }catch(RuntimeException $e){
                $notice=$e->getMessage();
            }
        }

        $signingState=$signing->status($config);
        $readiness=(new ChatApplicationContainer($this->db))->credentials()->readiness();
        try{
            $goRuntimeState=$goRuntime->status($signingState);
        }catch(\Throwable $e){
            $goRuntimeState=[
                'managed'=>true,
                'environment'=>(string)\app_config('app.env','production'),
                'database_address'=>'',
                'database_name'=>'',
                'database_user'=>'',
                'database_source'=>'PHP 站点已安装配置',
                'redis_enabled'=>false,
                'redis_address'=>'127.0.0.1:6379',
                'redis_password_configured'=>false,
                'auth_managed'=>!empty($signingState['configured']),
                'core_managed'=>false,
                'manual_mode_env'=>'CHAT_CONFIG_MANUAL',
                'diagnostic_error'=>true,
            ];
        }
        try{
            $goStatus=(new ChatGoRuntimeStatus())->status();
        }catch(\Throwable $e){
            $goStatus=['ready'=>false,'reason'=>'CHAT_GO_NOT_READY','environment'=>'','public_address'=>'','listening'=>false,'probe_supported'=>false,'current_sha'=>'','deployed_sha'=>'','running_sha'=>'','versions_match'=>false,'runtime_config_exists'=>false,'last_error'=>''];
        }
        echo \view('admin/chat-settings',[
            'pageTitle'=>'聊天设置',
            'activeMenu'=>'chat.settings',
            'enabled'=>$service->enabled(),
            'canManage'=>$canManage,
            'notice'=>$notice,
            'readiness'=>$readiness,
            'signing'=>$signingState,
            'goRuntime'=>$goRuntimeState,
            'goStatus'=>$goStatus,'runtimeControl'=>$control->status(),
        ]);
    }
}
