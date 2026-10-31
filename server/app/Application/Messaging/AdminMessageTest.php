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
use WorkerAdmin\Contracts\AdminAlertGateway;
use WorkerAdmin\Contracts\SubscriptionQuota;
use WorkerAdmin\Domain\Messaging\MessageEventCatalog;
use WorkerAdmin\Domain\Messaging\SmsTemplateCatalog;

final class AdminMessageTest
{
    use ConfigurationInput;
    private \WorkerAdmin\Contracts\AdminConfigurationAudit $audit;
    private ?AdminAlertGateway $adminAlerts;
    private ?\WorkerAdmin\Contracts\MessageTestJournal $journal;
    private AdminConfigurationRepository $repo;private SubscriptionMetadata $catalog;private SettingsProvider $settings;private SubscribeMessageGateway $subscribe;private SmsGateway $sms;private SubscriptionQuota $quota;private SubscriptionTestData $samples;
    public function __construct(AdminConfigurationRepository $repo,SubscriptionMetadata $catalog,SettingsProvider $settings,SubscribeMessageGateway $subscribe,SmsGateway $sms,SubscriptionQuota $quota,SubscriptionTestData $samples,\WorkerAdmin\Contracts\AdminConfigurationAudit $audit,?AdminAlertGateway $adminAlerts=null,?\WorkerAdmin\Contracts\MessageTestJournal $journal=null)
    { $this->journal=$journal; $this->adminAlerts=$adminAlerts; $this->audit=$audit; $this->repo=$repo;$this->catalog=$catalog;$this->settings=$settings;$this->subscribe=$subscribe;$this->sms=$sms;$this->quota=$quota;$this->samples=$samples; }
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
            $this->track('wechat_subscribe',['scene_key'=>$sceneKey,'recipient_type'=>'admin','recipient_id'=>$adminId,'user_id'=>$userId],fn()=>($this->subscribe)->send([
                'openid'=>$openid,
                'template_id'=>(string)$scene['template_id'],
                'page'=>'pages/notificationCenter/index',
                'data'=>$data,
            ]));
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


    public function wecomSettingsTest(array $input,int $adminId=0):array
    {
        if($adminId<1)throw new RuntimeException('请以后台管理员身份测试通知。');
        if(!$this->settings->bool('wecom.webhook_enabled',false))throw new RuntimeException('请先保存并启用管理员企业微信通知。');
        if(trim((string)$this->settings->get('wecom.webhook_url',''))==='')throw new RuntimeException('请先保存群机器人 Webhook 地址。');
        if($this->adminAlerts===null)throw new RuntimeException('管理员通知测试服务未配置。');
        $scene=trim((string)$this->inputValue($input,'test_scene'));
        $targets=['order_submitted'=>'/admin/?route=orders.view','worker_profile_submitted'=>'/admin/?route=workers','merchant_auth_submitted'=>'/admin/?route=merchants','withdrawal_submitted'=>'/admin/?route=withdrawals'];
        if(!isset($targets[$scene]))throw new RuntimeException('请选择有效的管理员通知测试场景。');
        $meta=MessageEventCatalog::get($scene);
        $content='【管理员通知测试：'.(string)$meta['label'].'】'
            ."\n接收：管理员\n触发来源（示例）：".(string)$meta['source_label']
            ."\n发送人：后台管理员 #".$adminId."\n时间：".date('Y-m-d H:i')
            ."\n这是一条测试消息，请核对卡片内容及处理入口。";
        // 只使用已保存的机器人配置；测试不创建订单、审核待办或用户通知。
        $this->repo->assertSendBoundary();
        $result=new AdminConfigurationResult($this->audit);
        try{
            $response=$this->track('wecom_webhook',['event_type'=>$scene,'recipient_type'=>'admin','recipient_id'=>$adminId],fn()=>$this->adminAlerts->send($content,$targets[$scene]));
            if(!in_array($response['errcode']??null,[0,'0'],true))throw new RuntimeException('企业微信未返回有效的成功受理结果。');
            $result->audit('settings.wecom.test','system_settings',null,'管理员通知测试已受理：'.$scene,null,['scene_key'=>$scene,'admin_id'=>$adminId]);
            $result->notice('success','测试通知已由企业微信受理，请到已配置的管理员群核对内容和处理入口。');
        }catch(Throwable $exception){
            // 供应商异常可能包含机器人地址，页面与审计不回显原始异常或密钥。
            $result->audit('settings.wecom.test_failed','system_settings',null,'管理员通知测试失败：'.$scene,null,['scene_key'=>$scene,'admin_id'=>$adminId,'exception_type'=>get_class($exception)]);
            $safe=preg_replace('#https?://\\S+#i','[地址已隐藏]',$exception->getMessage());
            $safe=preg_replace('/key=[A-Za-z0-9_-]+/i','key=[已隐藏]',(string)$safe);
            $result->notice('warning','管理员通知失败：'.mb_substr((string)$safe,0,180).'。请在发送记录中查看本次结果。');
        }
        return $result->navigate('settings.messages',['channel'=>'wecom'],'#settings-group-wecom');
    }

    private function track(string $topic,array $metadata,callable $send):array
    { return $this->journal ? $this->journal->track($topic,$metadata,$send) : $send(); }

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
        $sendResult=$this->track('aliyun_sms',['scene_key'=>$scene,'recipient_type'=>'admin','recipient_id'=>$adminId],fn()=>($this->sms)->send($mobile,$templateCode,SmsTemplateCatalog::testParameters($scene,date('Y-m-d H:i')),'admin-sms-test-'.date('YmdHis'),true));
        $bizId=trim((string)($sendResult['BizId']??''));
        $result->audit('settings.sms.test','system_settings',null,'发送阿里云测试短信：'.$scene.'，接收号码 '.(substr($mobile,0,3).'****'.substr($mobile,-4)));
        $result->notice('success','测试短信已提交阿里云'.($bizId!==''?'，流水号：'.$bizId:'。'));
        return $result->navigate('settings.messages',['channel'=>'sms'],'#settings-group-sms');

    }
}
