<?php
declare(strict_types=1);
namespace WorkerAdmin\Http\Admin\Modules;

use RuntimeException;
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
        $signing=new ChatSigningKeyManager($this->db,(new ApplicationContainer($this->db))->privateCipher());
        $notice='';
        $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
        if(!in_array($method,['GET','POST'],true))throw new RuntimeException('请求方式不正确');
        $admin=$this->auth->user();
        $canManage=($admin['role']??'')==='super_admin';

        if($method==='POST'){
            $this->csrf->verifyRequest();
            try{
                $action=(string)($_POST['_action']??'save');
                if($action==='generate_signing'||$action==='rotate_signing'){
                    if(!$canManage)throw new RuntimeException('仅超级管理员可生成或轮换聊天签名密钥。');
                    $state=$signing->generate($config,(int)$admin['id'],(string)$admin['role'],$action==='rotate_signing');
                    \audit('chat.signing_key','system_setting',0,($action==='rotate_signing'?'轮换':'生成').'聊天签名密钥 '.$state['kid']);
                    \flash('success',$action==='rotate_signing'?'聊天签名密钥已轮换，请立即同步新的 Go 公钥配置。':'聊天签名密钥已生成，请将 Go 公钥配置合并到聊天服务配置。');
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
        echo \view('admin/chat-settings',[
            'pageTitle'=>'聊天设置',
            'activeMenu'=>'chat.settings',
            'enabled'=>$service->enabled(),
            'canManage'=>$canManage,
            'notice'=>$notice,
            'readiness'=>$readiness,
            'signing'=>$signingState,
        ]);
    }
}
