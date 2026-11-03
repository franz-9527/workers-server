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
        $notice='';$runtimeFormValues=[];
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
                if(($action??'')==='save_go_runtime'&&$canManage){
                    foreach(['redis_enabled','redis_address','python_binary','ffmpeg_binary','ffprobe_binary','asr_profile','asr_api_name','asr_data_encoding','calls_php_endpoint','redis_password','wechat_callback_token','wechat_callback_aes_key','wechat_callback_confirmed'] as $field)
                        if(isset($_POST[$field])&&is_string($_POST[$field])&&strlen($_POST[$field])<=512)$runtimeFormValues[$field]=$_POST[$field];
                }
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
        try{$goRuntimeState=array_replace($goRuntimeState,$goRuntime->configuration());}
        catch(\Throwable $e){$notice=$notice?:'已保存的 Go 配置读取失败，请刷新后重试，暂勿覆盖保存。';}
        foreach(['python_binary','ffmpeg_binary','ffprobe_binary','asr_profile','asr_api_name','asr_data_encoding','calls_php_endpoint','redis_address'] as $field)
            if(array_key_exists($field,$runtimeFormValues))$goRuntimeState[$field]=$runtimeFormValues[$field];
        if(isset($runtimeFormValues['redis_enabled']))$goRuntimeState['redis_enabled']=$runtimeFormValues['redis_enabled']==='1';
        try{
            $goStatus=(new ChatGoRuntimeStatus())->status();
        }catch(\Throwable $e){
            $goStatus=['ready'=>false,'reason'=>'CHAT_GO_NOT_READY','environment'=>'','public_address'=>'','listening'=>false,'probe_supported'=>false,'current_sha'=>'','deployed_sha'=>'','running_sha'=>'','versions_match'=>false,'runtime_config_exists'=>false,'last_error'=>''];
        }
        $secretValues=[];
        if($canManage){
            header('Cache-Control: no-store, private');
            try{$secretValues=$goRuntime->adminFormValues();}
            catch(\Throwable $e){$secretValues=['errors'=>['聊天密钥读取失败，请刷新或重新填写后保存。']];}
            if(!empty($signingState['configured'])){
                try{
                    $material=$signing->material($config);
                    $secretValues['signing_seed']=base64_encode($material['seed']);
                }catch(\Throwable $e){$secretValues['errors'][]='聊天签名种子读取失败。';}
                finally{if(isset($material['seed'])&&is_string($material['seed']))sodium_memzero($material['seed']);}
            }
        }
        foreach(['callback_verified','callback_configured','callback_url'] as $field)if(array_key_exists($field,$secretValues))$goRuntimeState[$field]=$secretValues[$field];
        foreach(['redis_password','wechat_callback_token','wechat_callback_aes_key'] as $field)if(array_key_exists($field,$runtimeFormValues))$secretValues[$field]=$runtimeFormValues[$field];
        if(array_key_exists('wechat_callback_confirmed',$runtimeFormValues))$goRuntimeState['callback_verified']=$runtimeFormValues['wechat_callback_confirmed']==='1';
        echo \view('admin/chat-settings',[
            'pageTitle'=>'聊天设置',
            'activeMenu'=>'chat.settings',
            'enabled'=>$service->enabled(),
            'canManage'=>$canManage,
            'notice'=>$notice,
            'readiness'=>$readiness,
            'signing'=>$signingState,
            'goRuntime'=>$goRuntimeState,
            'secretValues'=>$secretValues,
            'goStatus'=>$goStatus,'runtimeControl'=>$control->status(),
        ]);
    }
}
