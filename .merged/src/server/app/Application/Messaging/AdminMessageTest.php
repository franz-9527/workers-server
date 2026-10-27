<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Messaging;

use RuntimeException;
use Throwable;
use WorkerAdmin\Contracts\AdminConfigurationRepository;
use WorkerAdmin\Contracts\SubscriptionMetadata;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Contracts\SubscribeMessageGateway;
use WorkerAdmin\Contracts\SmsGateway;
use WorkerAdmin\Contracts\SubscriptionQuota;
use WorkerAdmin\Domain\Messaging\MessageEventCatalog;
use WorkerAdmin\Domain\Messaging\SmsTemplateCatalog;

final class AdminMessageTest
{
    use ConfigurationInput;
    private \WorkerAdmin\Contracts\AdminConfigurationAudit $audit;
    private AdminConfigurationRepository $repo;private SubscriptionMetadata $catalog;private SettingsProvider $settings;private SubscribeMessageGateway $subscribe;private SmsGateway $sms;private SubscriptionQuota $quota;private SubscriptionTestData $samples;
    public function __construct(AdminConfigurationRepository $repo,SubscriptionMetadata $catalog,SettingsProvider $settings,SubscribeMessageGateway $subscribe,SmsGateway $sms,SubscriptionQuota $quota,SubscriptionTestData $samples,\WorkerAdmin\Contracts\AdminConfigurationAudit $audit)
    { $this->audit=$audit; $this->repo=$repo;$this->catalog=$catalog;$this->settings=$settings;$this->subscribe=$subscribe;$this->sms=$sms;$this->quota=$quota;$this->samples=$samples; }
    public function notificationSettingsTest(array $input,int $adminId=0):array
    {
        $result=new AdminConfigurationResult($this->audit);
        $admin=['id'=>$adminId];
        $sceneKey=trim((string)$this->inputValue($input,'test_scene'));
        $targets=$this->inputArray($input,'test_openid');
        $openid=trim((string)($targets[$sceneKey]??''));
        $scenes=$this->catalog->definitions();
        $scene=$scenes[$sceneKey]??null;
        if(!is_array($scene))throw new RuntimeException('请选择有效的微信订阅场景。');
        if(empty($scene['available'])||empty($scene['configuration_complete']))throw new RuntimeException('该订阅场景尚未配置完整，请先保存模板 ID 和字段映射。');

        $adminOpenids=$this->settings->get('wechat.admin_openids',[]);
        if(!is_array($adminOpenids))$adminOpenids=[];
        $adminOpenids=array_values(array_unique(array_filter(array_map('strval',$adminOpenids))));
        if($openid===''||!in_array($openid,$adminOpenids,true))throw new RuntimeException('请选择后台已配置的小程序管理员 OpenID 作为测试接收人。');

        $userId=$this->repo->userForOpenid($openid);
        if($userId<1)throw new RuntimeException('该管理员 OpenID 尚未绑定本站用户，请先用该微信账号登录一次小程序。');

        $prefs=$this->quota->preferences($userId);
        $pref=is_array($prefs[$sceneKey]??null)?$prefs[$sceneKey]:[];
        $type=(string)($scene['subscription_type']??'one_time');
        $status=(string)($pref['authorization_status']??'');
        if($type==='one_time'&&max(0,(int)($pref['remaining_count']??0))<1){
            throw new RuntimeException('该测试账号没有可用订阅次数，请先在小程序“通知设置”中为此通知续收 +1。');
        }
        if($type==='long_term'&&!in_array($status,['accept','acceptWithAudio','acceptWithAlert'],true)){
            throw new RuntimeException('该测试账号尚未开启此长期订阅，请先在小程序完成授权。');
        }

        $data=$this->samples->buildSubscriptionTestData($sceneKey,$scene,$input);
        $this->repo->assertSendBoundary();
        try{
            ($this->subscribe)->send([
                'openid'=>$openid,
                'template_id'=>(string)$scene['template_id'],
                'page'=>'pages/notificationCenter/index',
                'data'=>$data,
            ]);
            $this->quota->consume($userId,$sceneKey);
            $result->audit('settings.notifications.test','system_settings',null,'微信订阅测试发送成功：'.$sceneKey,null,['scene_key'=>$sceneKey,'user_id'=>$userId,'subscription_type'=>$type]);
            $result->notice('success','微信订阅测试发送成功：'.(string)($scene['title']??$sceneKey).($type==='one_time'?'；已同步扣减 1 次可用额度。':'；长期订阅额度不扣减。'));
        }catch(Throwable $exception){
            $message=$exception->getMessage();
            if(strpos($message,'（43101）')!==false||strpos($message,'(43101)')!==false){
                $this->quota->invalidate($userId,$sceneKey);
            }
            $result->audit('settings.notifications.test_failed','system_settings',null,'微信订阅测试发送失败：'.$sceneKey.' '.mb_substr($message,0,300),null,['scene_key'=>$sceneKey,'user_id'=>$userId]);
            $result->notice('warning','微信订阅测试未发送：'.mb_substr($message,0,220));
        }
        return $result->navigate('settings.messages',['channel'=>'subscribe','scene'=>$sceneKey],'#subscribe-scene-'.rawurlencode($sceneKey));

    }

    public function smsSettingsTest(array $input,int $adminId=0):array
    {
        $result=new AdminConfigurationResult($this->audit);

        $scene=$this->inputValue($input,'test_scene');$mobiles=$this->inputArray($input,'test_mobile');$mobile=trim((string)($mobiles[$scene]??''));
        $samples=SmsTemplateCatalog::all();
        if(!isset($samples[$scene]))throw new RuntimeException('请选择有效的短信模板后再测试。');
        if(preg_match('/^1\d{10}$/',$mobile)!==1)throw new RuntimeException('请输入接收测试短信的 11 位手机号。');
        $templates=$this->settings->get('sms.template_codes',[]);$configured=is_array($templates)?($templates[$scene]??[]):[];
        $templateCode=is_array($configured)?(string)(($configured['template_code']??'')?:($configured['template_id']??'')?:($configured['code']??'')):(string)$configured;
        if(trim($templateCode)==='')throw new RuntimeException('该短信模板 CODE 尚未保存，请先填写并保存阿里云短信设置。');
        $this->repo->assertSendBoundary();
        $sendResult=($this->sms)->send($mobile,$templateCode,SmsTemplateCatalog::testParameters($scene,date('Y-m-d H:i')),'admin-sms-test-'.date('YmdHis'),true);
        $bizId=trim((string)($sendResult['BizId']??''));
        $result->audit('settings.sms.test','system_settings',null,'发送阿里云测试短信：'.$scene.'，接收号码 '.(substr($mobile,0,3).'****'.substr($mobile,-4)));
        $result->notice('success','测试短信已提交阿里云'.($bizId!==''?'，流水号：'.$bizId:'。'));
        return $result->navigate('settings.messages',['channel'=>'sms'],'#settings-group-sms');

    }
}
