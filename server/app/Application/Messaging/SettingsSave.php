<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Messaging;

use RuntimeException;
use Throwable;
use WorkerAdmin\Contracts\AdminConfigurationRepository;
use WorkerAdmin\Contracts\SubscriptionMetadata;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Contracts\ConfigurationWriteGuard;
use WorkerAdmin\Contracts\WorkerRuntimeConfigurationGateway;
use WorkerAdmin\Domain\Messaging\SettingsValuePolicy;

final class SettingsSave
{
    use ConfigurationInput;
    private \WorkerAdmin\Contracts\AdminConfigurationAudit $audit;
    private AdminConfigurationRepository $repo;private SubscriptionMetadata $catalog;private SettingsProvider $settings;private ConfigurationWriteGuard $guard;private WorkerRuntimeConfigurationGateway $runtime;
    public function __construct(AdminConfigurationRepository $repo,SubscriptionMetadata $catalog,SettingsProvider $settings,ConfigurationWriteGuard $guard,WorkerRuntimeConfigurationGateway $runtime,\WorkerAdmin\Contracts\AdminConfigurationAudit $audit)
    { $this->audit=$audit; $this->repo=$repo;$this->catalog=$catalog;$this->settings=$settings;$this->guard=$guard;$this->runtime=$runtime; }
    public function save(array $input,int $adminId):array
    {
        $result=new AdminConfigurationResult($this->audit);
          $values=isset($input['settings'])&&is_array($input['settings'])?$input['settings']:[];
        // 2026-08-24：每个设置块独立提交，后端同步限定分组，防止跨组覆盖和忘记拉到页底保存。
        $requestedScope = $this->inputValue($input,'_settings_scope');
        $scope = in_array($requestedScope, ['developer','messages','announcement'], true) ? $requestedScope : 'basic';
        $group = $this->inputValue($input,'_settings_group');
        $messageChannel = $scope === 'messages' ? trim($this->inputValue($input,'_message_channel')) : '';
        if ($scope === 'messages' && !in_array($messageChannel, ['internal','subscribe','sms','wecom'], true)) {
            $messageChannel = $group === 'sms' ? 'sms' : ($group === 'wecom' ? 'wecom' : 'internal');
        }
        $notificationScene=$scope==='messages'&&$messageChannel==='subscribe'?trim($this->inputValue($input,'_notification_scene')):'';
        $activeNotificationScene=$scope==='messages'&&$messageChannel==='subscribe'?trim($this->inputValue($input,'_active_notification_scene')):'';
        $subscribeGlobalOnly=$scope==='messages'&&$messageChannel==='subscribe'&&$this->inputValue($input,'_subscribe_global_only')==='1';
        $sceneDefinitions=$scope==='messages'&&$messageChannel==='subscribe'?$this->catalog->definitions():[];
        if($notificationScene!==''&&!isset($sceneDefinitions[$notificationScene]))throw new RuntimeException('当前订阅通知不存在，请刷新页面后重试。');
        if($activeNotificationScene!==''&&!isset($sceneDefinitions[$activeNotificationScene]))$activeNotificationScene='';
        if($notificationScene!=='')$values=array_intersect_key($values,['wechat.subscribe_template_ids'=>true]);
        elseif($subscribeGlobalOnly)$values=array_intersect_key($values,['wechat.subscribe_enabled'=>true]);
        $returnNotificationScene=$notificationScene!==''?$notificationScene:$activeNotificationScene;
        $redirectParams = ['saved_group'=>$group];
        if ($scope === 'messages') $redirectParams['channel'] = $messageChannel;
        if($returnNotificationScene!=='')$redirectParams['scene']=$returnNotificationScene;
        $redirectAnchorGroup = $scope === 'messages' && $group === 'notification'
            ? ($messageChannel === 'subscribe' ? 'message_subscribe' : 'message_internal')
            : $group;
        $scopeGroups = [
            'basic'=>['general','site','order','quote','commission','measurement','worker','wallet','automation','payment','wechat','finance','hall','feature'],
            'messages'=>['notification','sms','wecom'],
            'announcement'=>['announcement'],
            'developer'=>['storage','media','security','system','server','development'],
        ];
        if (!in_array($group, $scopeGroups[$scope], true)) { throw new RuntimeException('设置分组不正确，请刷新页面后重试。'); }
        // 2026-08-14：设置保存与演示业务写请求共用数据库锁，确保关闭开关时不存在检查后继续写入的竞态窗口。
        return $this->guard->run(function()use($input,$adminId,$result,$values,$scope,$group,$messageChannel,$notificationScene,$activeNotificationScene,$subscribeGlobalOnly,$sceneDefinitions,$returnNotificationScene,$redirectParams,$redirectAnchorGroup):array{

        $admin=['id'=>$adminId];
        $rows = $this->repo->settingsForGroup($group);
        $known = [];
        foreach ($rows as $row) { $known[$row['setting_key']] = $row; }
        $updates=SettingsValuePolicy::validate($values,$known,$group,$notificationScene,$this->catalog->definitions(),$this->catalog->semanticLabels(),$this->catalog->fixedReviewFields());
        $updates=$this->normalizeTestingUpdates($updates,$group);
        if (isset($updates['order.auto_preapprove_category_ids'])) {
            $allowed = array_fill_keys(array_column($this->repo->categories(), 'id'), true);
            foreach (json_decode($updates['order.auto_preapprove_category_ids'], true) as $categoryId) {
                if (!isset($allowed[$categoryId])) {
                    throw new RuntimeException('自动预审类目 ID '.$categoryId.' 不存在、已停用或不是一级类目。');
                }
            }
        }
        $this->repo->saveMany($updates,$adminId);
        $result->audit('settings.update','system_settings',null,'更新系统设置：' . implode('、', array_keys($updates)));

        $syncMessage = '';
        if (in_array($group, ['wechat','worker'], true)) {
            try {
                $sync = (new \WorkerAdmin\Application\Configuration\WorkerRuntimeConfigSync($this->repo,$this->runtime))->execute();
                $syncMessage = '；师傅云端运行配置已同步（' . (int)($sync['admin_count'] ?? 0) . ' 位管理员）';
                $result->audit('worker.runtime-config.sync','system_settings',null,'同步小程序管理员与师傅手机号查看策略到云端',null,['version'=>(string)($sync['version']??'')]);
            } catch (Throwable $exception) {
                $result->audit('worker.runtime-config.sync_failed','system_settings',null,mb_substr($exception->getMessage(),0,500));
                $result->notice('warning','本项设置已保存，但师傅云端运行配置同步失败：'.mb_substr($exception->getMessage(),0,180).'。云端仍使用上一版有效配置，请检查云函数/密钥后再次保存本项。');
                $scopeRoutes=['developer'=>'settings.developer','messages'=>'settings.messages','announcement'=>'announcement','basic'=>'settings'];
                $redirectHash=$returnNotificationScene!==''?'#subscribe-scene-'.rawurlencode($returnNotificationScene):'#settings-group-'.rawurlencode($redirectAnchorGroup);
                return $result->navigate($scopeRoutes[$scope], $redirectParams,$redirectHash);
            }
        }
        $savedLabel=$notificationScene!==''?(string)($sceneDefinitions[$notificationScene]['title']??'当前通知'):($subscribeGlobalOnly?'微信订阅总开关':'本项设置');
        $result->notice('success',$savedLabel.'已校验并保存'.$syncMessage.'。');
        $scopeRoutes=['developer'=>'settings.developer','messages'=>'settings.messages','announcement'=>'announcement','basic'=>'settings'];
        $redirectHash=$returnNotificationScene!==''?'#subscribe-scene-'.rawurlencode($returnNotificationScene):'#settings-group-'.rawurlencode($redirectAnchorGroup);
        return $result->navigate($scopeRoutes[$scope], $redirectParams,$redirectHash);

        });
    }

    private function normalizeTestingUpdates(array $updates,string $group):array
    {
        if($group!=='development')return $updates;
        if(isset($updates['testing.qiniu_path_prefix'])){
            $prefix=trim((string)$updates['testing.qiniu_path_prefix'],'/');
            if($prefix===''||preg_match('/^[A-Za-z0-9._-]{1,40}$/',$prefix)!==1)throw new RuntimeException('测试环境七牛根目录只能填写单层英文、数字、点、下划线或短横线。');
            $production=trim((string)$this->settings->get('qiniu.path_prefix','upload'),'/');
            if($production!==''&&strcasecmp($prefix,$production)===0)throw new RuntimeException('测试环境七牛根目录不能与正式根目录相同。');
            $updates['testing.qiniu_path_prefix']=$prefix;
        }
        if(isset($updates['testing.allowed_sms_mobiles'])){
            $items=preg_split('/[\s,;]+/',(string)$updates['testing.allowed_sms_mobiles'])?:[];$clean=[];
            foreach($items as $mobile){$mobile=preg_replace('/\D+/','',(string)$mobile)??'';if($mobile==='')continue;if(preg_match('/^1[3-9]\d{9}$/',$mobile)!==1)throw new RuntimeException('测试短信白名单包含无效手机号：'.$mobile);$clean[$mobile]=true;}
            if(count($clean)>20)throw new RuntimeException('测试短信白名单最多 20 个手机号。');
            $updates['testing.allowed_sms_mobiles']=implode("\n",array_keys($clean));
        }
        if(isset($updates['testing.allowed_wechat_openids'])){
            $items=preg_split('/[\s,;]+/',(string)$updates['testing.allowed_wechat_openids'])?:[];$clean=[];
            foreach($items as $openid){$openid=trim((string)$openid);if($openid==='')continue;if(preg_match('/^[A-Za-z0-9_-]{10,128}$/',$openid)!==1)throw new RuntimeException('测试订阅消息白名单包含无效 OpenID。');$clean[$openid]=true;}
            if(count($clean)>20)throw new RuntimeException('测试订阅消息白名单最多 20 个 OpenID。');
            $updates['testing.allowed_wechat_openids']=implode("\n",array_keys($clean));
        }
        return $updates;
    }

}
