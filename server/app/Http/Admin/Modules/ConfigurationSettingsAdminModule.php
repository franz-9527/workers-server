<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Admin\Modules;

use RuntimeException;
use Throwable;
use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Http\Admin\AdminModule;
use WorkerAdmin\Foundation\ConfigurationApplicationContainer;

final class ConfigurationSettingsAdminModule implements AdminModule
{
    private const ROUTES=[
        'settings'=>['read','settings',false],
        'settings.messages'=>['read','settingsMessages',false],
        'announcement'=>['read','announcementSettings',false],
        'settings.developer'=>['read','settingsDeveloper',false],
        'settings.save'=>['save','save',true],
        'settings.notifications.bootstrap'=>['templates','notificationSettingsBootstrap',true],
        'settings.notifications.sync'=>['templates','notificationSettingsSync',true],
        'settings.notifications.library'=>['library','notificationSettingsLibrary',false],
        'settings.notifications.library.add'=>['templates','notificationSettingsLibraryAdd',true],
        'settings.notifications.import'=>['templates','notificationSettingsImport',true],
        'settings.notifications.test'=>['test','notificationSettingsTest',true],
        'settings.sms.bootstrap'=>['sms','smsSettingsBootstrap',true],
        'settings.sms.recommend'=>['sms','smsSettingsRecommend',true],
        'settings.sms.reliable'=>['sms','smsSettingsReliable',true],
        'settings.sms.test'=>['test','smsSettingsTest',true],
        'settings.wecom.test'=>['test','wecomSettingsTest',true],
    ];
    private Auth $auth;private Csrf $csrf;private array $services;
    public function __construct(Database $db,Auth $auth,Csrf $csrf,?array $services=null)
    { $this->auth=$auth;$this->csrf=$csrf;$this->services=$services ?: (new ConfigurationApplicationContainer($db))->services(); }
    public function supports(string $route):bool { return isset(self::ROUTES[$route]); }
    public function dispatch(string $route):void
    {
        $this->auth->requireLogin();$this->auth->requirePermission('settings.manage');
        if(!$this->supports($route))throw new RuntimeException('后台路由不存在');
        [$service,$method,$write]=self::ROUTES[$route];
        try{
            if(!$write && strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='GET'){http_response_code(405);throw new RuntimeException('该操作只接受 GET 请求。');}
            if($write && strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST'){http_response_code(405);throw new RuntimeException('该操作只接受 POST 请求。');}
            if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))==='POST')$this->csrf->verifyRequest();
            $user=$this->auth->user();
            $result=$this->services[$service]->{$method}($write?$_POST:$_GET,(int)($user['id']??0));
            if($service==='read'){echo \view('admin/settings',$result);return;}
            if(($result['kind']??'')==='json'){\json_response($result['payload'],(int)$result['status']);return;}
            foreach($result['notices']??[] as $notice)\flash($notice[0],$notice[1]);
            \redirect(\admin_url($result['route'],$result['params']).$result['fragment']);
        }catch(RuntimeException $e){
            if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST')throw $e;
            // Only page state is restored. Credential/config values never enter session old-input.
            $safe=[];foreach(['_settings_scope','_settings_group','_message_channel','_notification_scene','_active_notification_scene']as $key)if(isset($_POST[$key])&&!is_array($_POST[$key]))$safe[$key]=(string)$_POST[$key];
            \old_input($safe);\flash('danger',$e->getMessage());\redirect($this->returnPage($route));
        }catch(Throwable $e){
            $id=\request_id();\write_log('admin-error','['.date('c').'] request_id='.$id.' route='.$route.' '.$e->getMessage()."\n");
            $message='系统未能完成本次操作，请根据请求编号查看后台错误日志。 请求编号：'.$id;
            if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))==='POST'){\flash('danger',$message);\redirect($this->returnPage($route));return;}
            http_response_code(500);echo \view('errors/500',['pageTitle'=>'系统处理失败','message'=>$message]);
        }
    }
    private function returnPage(string $route):string
    {
        $scope=isset($_POST['_settings_scope'])&&!is_array($_POST['_settings_scope'])?(string)$_POST['_settings_scope']:'basic';
        if($scope==='messages'||$route!=='settings.save'){
            $channel=isset($_POST['_message_channel'])&&is_string($_POST['_message_channel'])?$_POST['_message_channel']:'';
            if(!in_array($channel,['internal','subscribe','sms','wecom'],true))$channel=strpos($route,'settings.sms.')===0?'sms':(strpos($route,'settings.wecom.')===0?'wecom':(strpos($route,'settings.notifications.')===0?'subscribe':'internal'));
            $params=['channel'=>$channel];$fragment='';
            foreach(['_active_notification_scene','_notification_scene','import_scene','library_scene','test_scene'] as $key){
                if($channel==='subscribe'&&isset($_POST[$key])&&is_string($_POST[$key])&&preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/',$_POST[$key])===1){$params['scene']=$_POST[$key];$fragment='#subscribe-scene-'.rawurlencode($_POST[$key]);break;}
            }
            return \admin_url('settings.messages',$params).$fragment;
        }
        $routes=['basic'=>'settings','announcement'=>'announcement','developer'=>'settings.developer'];
        return \admin_url($routes[$scope]??'settings');
    }
}
