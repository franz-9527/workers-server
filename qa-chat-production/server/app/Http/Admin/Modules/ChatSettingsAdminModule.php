<?php
declare(strict_types=1);
namespace WorkerAdmin\Http\Admin\Modules;

use RuntimeException;
use WorkerAdmin\Application\Chat\ChatAsrSelfDiagnostic;
use WorkerAdmin\Application\Chat\ChatRuntimePaths;
use WorkerAdmin\Application\Chat\ChatGoRuntimeConfig;
use WorkerAdmin\Application\Chat\ChatGoRuntimeStatus;
use WorkerAdmin\Application\Chat\ChatOperationalTelemetry;
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
        $chatContainer=new ChatApplicationContainer($this->db);
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
            $action=(string)($_POST['_action']??'save');
            if($action==='reveal_secret'){
                header('Cache-Control: no-store, private');header('Pragma: no-cache');header('Referrer-Policy: no-referrer');header('X-Content-Type-Options: nosniff');
                try{
                    if(!$canManage)throw new RuntimeException('仅超级管理员可查看聊天密钥。');
                    $labels=[
                        'redis_password'=>'Redis 密码','tencent_asr_secret_id'=>'腾讯云 SecretId','tencent_asr_secret_key'=>'腾讯云 SecretKey',
                        'wechat_callback_token'=>'微信消息推送 Token','wechat_callback_aes_key'=>'微信 EncodingAESKey','signing_seed'=>'PHP ↔ Go 签名种子',
                        'call_certificate'=>'声网 App Certificate','call_customer_id'=>'声网 REST Customer ID','call_customer_secret'=>'声网 REST Customer Secret',
                    ];
                    $name=is_string($_POST['secret']??null)?$_POST['secret']:'';
                    if(!isset($labels[$name]))throw new RuntimeException('不支持查看该密钥。');
                    $this->verifySecretRevealPassword($admin);
                    if($name==='signing_seed'){
                        $material=$signing->material($config);
                        try{$secret=base64_encode((string)$material['seed']);}
                        finally{if(isset($material['seed'])&&is_string($material['seed'])&&function_exists('sodium_memzero'))sodium_memzero($material['seed']);}
                    }elseif(str_starts_with($name,'call_')){
                        $secret=$chatContainer->callCredentials()->revealSecret(substr($name,5));
                    }else{
                        $secret=$goRuntime->revealSecret($name);
                    }
                    if(!is_string($secret)||$secret==='')throw new RuntimeException('该密钥当前未配置或不可读取。');
                    \audit('chat.secret_reveal','system_setting',0,'查看聊天密钥：'.$labels[$name]);
                    \json_response(['success'=>true,'data'=>['secret'=>$secret,'expires_in'=>20]]);
                    return;
                }catch(\Throwable $e){
                    \json_response(['success'=>false,'message'=>$e instanceof RuntimeException?$e->getMessage():'密钥读取失败，请刷新后重试。'],422);
                    return;
                }
            }
            try{
                if($action==='queue_go_update'){
                    if(!$canManage)throw new RuntimeException('仅超级管理员可提交 Go 升级。');
                    $this->verifySecretRevealPassword($admin);
                    $revision=(new \WorkerAdmin\Application\Chat\ChatGoUpdate())->queue($_FILES['go_package']??[]);
                    \audit('chat.go_update','system_setting',0,'提交 Go 升级 '.substr($revision,0,12));
                    \flash('success','已提交升级，刷新本页查看结果。');
                }elseif($action==='prepare_production'){
                    if(!$canManage||\app_config('app.env')!=='production')throw new RuntimeException('仅正式站超级管理员可初始化。');
                    if(empty($signing->status($config)['configured']))$signing->generate($config,(int)$admin['id'],(string)$admin['role']);
                    $goRuntime->prepareProduction((int)$admin['id'],(string)$admin['role']);
                    \audit('chat.production_prepare','system_setting',0,'初始化正式聊天签名并请求应用');
                    \flash('success',$control->request()['message']);
                }elseif($action==='prepare_test'){
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
                    foreach(['redis_enabled','redis_address','python_binary','ffmpeg_binary','ffprobe_binary','asr_profile','tencent_asr_engine','clear_redis_password','clear_tencent_asr_credentials','calls_php_endpoint','wechat_callback_confirmed'] as $field)
                        if(isset($_POST[$field])&&is_string($_POST[$field])&&strlen($_POST[$field])<=512)$runtimeFormValues[$field]=$_POST[$field];
                }
            }
        }

        $signingState=$signing->status($config);
        $readiness=$chatContainer->credentials()->readiness();
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
        foreach(['python_binary','ffmpeg_binary','ffprobe_binary','asr_profile','tencent_asr_engine','calls_php_endpoint','redis_address'] as $field)
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
        }
        foreach(['callback_verified','callback_configured','callback_url'] as $field)if(array_key_exists($field,$secretValues))$goRuntimeState[$field]=$secretValues[$field];
        if($canManage){
            try{$secretValues['operational_telemetry']=(new ChatOperationalTelemetry())->snapshot();}
            catch(\Throwable $e){$secretValues['operational_telemetry']=['status'=>'unavailable','updated_at'=>'','metrics'=>[]];}
        }
        if($canManage){
            try{
                $selfDiagnostic=new ChatAsrSelfDiagnostic();
                $stateDir=ChatRuntimePaths::stateDirectory();
                $siteUrl=parse_url((string)\app_config('app.url',''));
                $secretValues['asr_self_diagnostic']=$selfDiagnostic->build([
                    'app_id'=>trim((string)\setting('wechat.miniapp_appid','')),
                    'app_secret_configured'=>trim((string)\setting('wechat.miniapp_secret',''))!=='',
                    'runtime'=>$goRuntimeState,
                    'go_status'=>$goStatus,
                    'asr'=>is_array($secretValues['asr_diagnostic']??null)?$secretValues['asr_diagnostic']:[],
                    'plugin'=>$stateDir===null?[]:$selfDiagnostic->readPluginState($stateDir.'/wechat-si-plugin-diagnostic.json'),
                    'site_host'=>is_array($siteUrl)?(string)($siteUrl['host']??''):'',
                ]);
            }catch(\Throwable $e){
                $secretValues['asr_self_diagnostic']=['status'=>'read_failed','tone'=>'warning','label'=>'自诊断暂不可用','summary'=>'后台未能汇总 ASR 诊断证据，请查看下方原始调用诊断。','checks'=>[],'actions'=>[],'evidence'=>[]];
            }
        }
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
            'goUpdate'=>$canManage&&\app_config('app.env')==='production'?(new \WorkerAdmin\Application\Chat\ChatGoUpdate())->status():[],
        ]);
    }

    private function verifySecretRevealPassword(array $admin):void
    {
        $state=isset($_SESSION['_chat_secret_reauth'])&&is_array($_SESSION['_chat_secret_reauth'])?$_SESSION['_chat_secret_reauth']:['count'=>0,'locked_until'=>0];
        if((int)($state['locked_until']??0)>time())throw new RuntimeException('管理员密码验证失败次数过多，请十五分钟后再试。');
        $password=is_string($_POST['admin_password']??null)?$_POST['admin_password']:'';
        if($password===''||strlen($password)>1024)throw new RuntimeException('请输入当前后台登录密码。');
        $hash=$this->db->fetchValue("SELECT password_hash FROM admin_users WHERE id=:id AND status IN ('active','1') AND must_change_password=0 LIMIT 1",['id'=>(int)($admin['id']??0)]);
        if(!is_string($hash)||!password_verify($password,$hash)){
            $state['count']=(int)($state['count']??0)+1;
            if($state['count']>=5)$state=['count'=>0,'locked_until'=>time()+900];
            $_SESSION['_chat_secret_reauth']=$state;
            throw new RuntimeException('当前管理员密码验证失败。');
        }
        unset($_SESSION['_chat_secret_reauth']);
    }
}
