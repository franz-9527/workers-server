<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Messaging;

use WorkerAdmin\Contracts\SubscriptionRepository;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Application\Identity\DemoActorGuard;
use WorkerAdmin\Domain\Messaging\MessageOperationException;

final class SubscriptionPreferences
{
    private SubscriptionRepository $repository;
    private SettingsProvider $settings;
    public function __construct(SubscriptionRepository $repository, SettingsProvider $settings)
    { $this->repository = $repository; $this->settings = $settings; }
    public function status(array $actor, bool $includeApplication): array
    {
        $scenes=$this->repository->definitions();$preferences=$this->repository->preferences((int)$actor['user_id']);$items=[];
        foreach($scenes as $key=>$scene){
            if(!in_array($key,['user_order_update','user_financial_update','worker_order_update','worker_financial_update','chat_unread','reviewResult','appointment_update','worker_review'],true))continue;
            if($key==='reviewResult'&&!$includeApplication)continue;
            $recipient=(string)($scene['recipient']??'user');
            // 普通用户不展示师傅通知，避免“一键续收”占用在永远不会发送给他的模板。
            // 首次入驻提交通过 include_application=1 单独读取 reviewResult，不污染普通消息设置和健康度。
            if($recipient==='worker'&&empty($actor['worker_id'])&&!($key==='reviewResult'&&$includeApplication))continue;
            $pref=is_array($preferences[$key]??null)?$preferences[$key]:[];$type=(string)($scene['subscription_type']??'one_time');
            $items[]=['key'=>$key,'scene_keys'=>$scene['scene_keys']??[$key],'audience'=>(string)($scene['audience']??$scene['recipient']??'user'),'title'=>$scene['title'],'description'=>$scene['description'],'template_id'=>$scene['template_id'],'template_title'=>(string)($scene['template_title']??''),'available'=>(bool)$scene['available'],'configuration_complete'=>(bool)($scene['configuration_complete']??false),'availability_reason'=>(string)($scene['availability_reason']??''),'global_enabled'=>(bool)$scene['global_enabled'],'important'=>(bool)($scene['important']??false),'subscription_type'=>$type,'enabled'=>(bool)($pref['enabled']??false),'authorization_status'=>(string)($pref['authorization_status']??''),'last_request_status'=>(string)($pref['last_request_status']??''),'remaining_count'=>$type==='long_term'?null:max(0,(int)($pref['remaining_count']??0)),'total_authorized_count'=>max(0,(int)($pref['total_authorized_count']??0))];
        }
        return ['schema'=>3,'global_enabled'=>$this->settings->bool('wechat.subscribe_enabled', true),'items'=>$items];

    }
    public function diagnose(array $actor): array { return $this->repository->diagnose((int)$actor['user_id']); }
    public function save(array $actor, array $body): array
    {
        DemoActorGuard::requireExternalAction($actor);
        $key=trim((string)($body['key']??''));$enabled=filter_var($body['enabled']??false,FILTER_VALIDATE_BOOLEAN);$status=mb_substr(trim((string)($body['authorization_status']??'')),0,32);
        $definition=$this->repository->definitions()[$key]??null;
        if(is_array($definition)&&(string)($definition['recipient']??'user')==='worker'&&$key!=='reviewResult'&&empty($actor['worker_id']))throw new MessageOperationException('当前账号没有师傅身份，不能订阅师傅订单通知','WORKER_SUBSCRIPTION_FORBIDDEN',403);
        if(is_array($definition)&&!empty($definition['inherited_from']))$key=(string)$definition['inherited_from'];
        $saved=$this->repository->save((int)$actor['user_id'],$key,$enabled,$status);
        $type=(string)($saved['subscription_type']??'one_time');$accepted=in_array((string)($saved['authorization_status']??''),['accept','acceptWithAudio','acceptWithAlert'],true);
        return ['message'=>$accepted?($type==='long_term'?'长期订阅授权已记录':'订阅可提醒次数已增加'):'订阅授权状态已更新','data'=>$saved];

    }
}
