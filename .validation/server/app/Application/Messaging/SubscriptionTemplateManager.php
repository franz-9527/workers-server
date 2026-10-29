<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Messaging;

use RuntimeException;
use Throwable;
use WorkerAdmin\Contracts\AdminConfigurationAudit;
use WorkerAdmin\Contracts\AdminConfigurationRepository;
use WorkerAdmin\Contracts\SubscriptionMetadata;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Contracts\SubscribeMessageGateway;

final class SubscriptionTemplateManager
{
    use ConfigurationInput;
    private AdminConfigurationRepository $repo;
    private SubscriptionMetadata $catalog;
    private SettingsProvider $settings;
    private SubscribeMessageGateway $subscribe;
    private AdminConfigurationAudit $audit;

    public function __construct(AdminConfigurationRepository $repo, SubscriptionMetadata $catalog, SettingsProvider $settings, SubscribeMessageGateway $subscribe, AdminConfigurationAudit $audit)
    {
        $this->repo = $repo;
        $this->catalog = $catalog;
        $this->settings = $settings;
        $this->subscribe = $subscribe;
        $this->audit = $audit;
    }

    public function notificationSettingsBootstrap(array $input,int $adminId=0):array
    {
        $result=new AdminConfigurationResult($this->audit);
        $admin=['id'=>$adminId];
        $templates=[];$definitions=$this->catalog->definitions();
        $existing=$this->settings->get('wechat.subscribe_template_ids',[]);
        foreach($definitions as $key=>$scene){
            $templates[$key]=[
                'name'=>(string)($scene['title']??$key),
                'template_id'=>(string)($scene['template_id']??''),
                'template_title'=>(string)($scene['template_title']??''),
                'template_fields'=>(array)($scene['template_fields']??[]),
                'template_field_labels'=>(array)($scene['template_field_labels']??[]),
                'synced_template_id'=>(string)($scene['synced_template_id']??''),
                'subscription_type'=>(string)($scene['subscription_type']??'one_time'),
                'keywords'=>(array)($scene['keywords']??[]),
                'field_map'=>(array)($scene['field_map']??[]),
                'field_defaults'=>(array)($scene['field_defaults']??[]),
            ];
            if(isset($existing[$key])){
                $old=$existing[$key];
                if(is_string($old))$templates[$key]['template_id']=$old;
                elseif(is_array($old)){
                    $templates[$key]['template_id']=(string)($old['template_id']??$old['id']??$templates[$key]['template_id']);
                    $templates[$key]['template_title']=(string)($old['template_title']??$templates[$key]['template_title']);
                    $templates[$key]['template_fields']=is_array($old['template_fields']??null)?array_values($old['template_fields']):$templates[$key]['template_fields'];
                    $templates[$key]['template_field_labels']=is_array($old['template_field_labels']??null)?$old['template_field_labels']:$templates[$key]['template_field_labels'];
                    $templates[$key]['synced_template_id']=(string)($old['synced_template_id']??$templates[$key]['synced_template_id']);
                    $templates[$key]['subscription_type']=in_array((string)($old['subscription_type']??''),['one_time','long_term'],true)?(string)$old['subscription_type']:$templates[$key]['subscription_type'];
                    $templates[$key]['field_map']=is_array($old['field_map']??null)?$old['field_map']:[];
                    $templates[$key]['field_defaults']=is_array($old['field_defaults']??null)?$old['field_defaults']:[];
                }
            }
        }
        $this->repo->set('wechat.subscribe_template_ids',json_encode($templates,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$adminId);
        $result->audit('settings.notifications.bootstrap','system_settings',null,'一键添加订阅消息场景');$result->notice('success','已补齐订阅场景，已保存的模板 ID 与字段映射保持不变。');$returnScene=trim($this->inputValue($input,'_active_notification_scene'));$params=['channel'=>'subscribe'];if(isset($definitions[$returnScene]))$params['scene']=$returnScene;else $returnScene='';return $result->navigate('settings.messages',$params,($returnScene!==''?'#subscribe-scene-'.rawurlencode($returnScene):'#settings-group-message_subscribe'));

    }

    public function notificationSettingsSync(array $input,int $adminId=0):array
    {
        $result=new AdminConfigurationResult($this->audit);
        $admin=['id'=>$adminId];
        $remote=($this->subscribe)->templateList();
        $definitions=$this->catalog->definitions();
        $existing=$this->settings->get('wechat.subscribe_template_ids',[]);if(!is_array($existing))$existing=[];
        $templates=[];$matched=0;$missing=0;$unconfigured=0;
        foreach($definitions as $key=>$scene){
            $old=$existing[$key]??[];
            if(is_string($old))$old=['template_id'=>$old];
            if(!is_array($old))$old=[];
            $templateId=trim((string)($old['template_id']??$old['id']??$scene['template_id']??''));
            $row=[
                'name'=>(string)($scene['title']??$key),
                'template_id'=>$templateId,
                'template_title'=>(string)($old['template_title']??$scene['template_title']??''),
                'template_fields'=>is_array($old['template_fields']??null)?array_values($old['template_fields']):[],
                'template_field_labels'=>is_array($old['template_field_labels']??null)?$old['template_field_labels']:[],
                'synced_template_id'=>(string)($old['synced_template_id']??''),
                'subscription_type'=>in_array((string)($old['subscription_type']??''),['one_time','long_term'],true)?(string)$old['subscription_type']:(string)($scene['subscription_type']??'one_time'),
                'keywords'=>(array)($scene['keywords']??[]),
                'field_map'=>is_array($old['field_map']??null)?$old['field_map']:[],
                'field_defaults'=>is_array($old['field_defaults']??null)?$old['field_defaults']:[],
            ];
            if($templateId===''){$unconfigured++;}
            elseif(isset($remote[$templateId])){
                $actual=$remote[$templateId];$matched++;
                $row['template_title']=(string)($actual['title']??'');
                $row['template_fields']=is_array($actual['fields']??null)?array_values($actual['fields']):[];
                $row['template_field_labels']=is_array($actual['field_labels']??null)?$actual['field_labels']:[];
                $row['synced_template_id']=$templateId;
                $remoteType=(string)($actual['type']??'');
                if($remoteType==='2')$row['subscription_type']='one_time';
                elseif($remoteType==='3')$row['subscription_type']='long_term';
                $binding=$this->catalog->infer($scene,$actual,$row);
                $row['field_map']=$binding['field_map'];$row['field_defaults']=$binding['field_defaults'];
            }else{
                $missing++;
                $row['template_fields']=[];
                $row['template_field_labels']=[];
                $row['synced_template_id']='';
            }
            $templates[$key]=$row;
        }
        $this->repo->set('wechat.subscribe_template_ids',json_encode($templates,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$adminId);
        $result->audit('settings.notifications.sync','system_settings',null,'同步微信订阅模板元数据：匹配 '.$matched.'，未找到 '.$missing.'，未配置 '.$unconfigured);
        $message='已同步微信模板：匹配 '.$matched.' 个';
        if($missing>0)$message.='，'.$missing.' 个已填模板 ID 未在当前小程序“我的模板”中找到';
        if($unconfigured>0)$message.='，'.$unconfigured.' 个场景尚未填写模板 ID';
        $result->notice($missing>0?'warning':'success',$message.'。可识别字段已自动映射，无法识别的模板项会保留在高级设置中等待确认。');
        $returnScene=trim($this->inputValue($input,'_active_notification_scene'));$params=['channel'=>'subscribe'];if(isset($definitions[$returnScene]))$params['scene']=$returnScene;
        return $result->navigate('settings.messages',$params,($returnScene!==''?'#subscribe-scene-'.rawurlencode($returnScene):'#settings-group-message_subscribe'));

    }

    private function saveSubscriptionRemoteBinding(string $sceneKey,string $templateId,array $actual,int $adminId):array
    {
        $result=new AdminConfigurationResult($this->audit);
        $definitions=$this->catalog->definitions();
        $scene=$definitions[$sceneKey]??null;
        if(!is_array($scene))throw new RuntimeException('订阅场景不存在。');
        $existing=$this->settings->get('wechat.subscribe_template_ids',[]);if(!is_array($existing))$existing=[];
        foreach($existing as $otherKey=>$otherRow){
            if((string)$otherKey===$sceneKey)continue;
            $otherId=is_array($otherRow)?trim((string)($otherRow['template_id']??$otherRow['id']??'')):trim((string)$otherRow);
            if($otherId!==''&&hash_equals($otherId,$templateId))throw new RuntimeException('该微信模板 ID 已绑定到场景 '.$otherKey.'，不同场景不能共用同一个模板 ID。');
        }
        $old=$existing[$sceneKey]??[];if(is_string($old))$old=['template_id'=>$old];if(!is_array($old))$old=[];
        $binding=$this->catalog->infer($scene,$actual,$old);
        $remoteType=(string)($actual['type']??'');
        $subscriptionType=$remoteType==='3'?'long_term':'one_time';
        $existing[$sceneKey]=[
            'name'=>(string)($scene['title']??$sceneKey),
            'template_id'=>$templateId,
            'template_title'=>(string)($actual['title']??''),
            'template_fields'=>is_array($actual['fields']??null)?array_values($actual['fields']):[],
            'template_field_labels'=>is_array($actual['field_labels']??null)?$actual['field_labels']:[],
            'synced_template_id'=>$templateId,
            'subscription_type'=>$subscriptionType,
            'keywords'=>(array)($scene['keywords']??[]),
            'field_map'=>$binding['field_map'],
            'field_defaults'=>$binding['field_defaults'],
        ];
        $this->repo->set('wechat.subscribe_template_ids',json_encode($existing,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$adminId);
        return $binding;

    }

    public function notificationSettingsLibraryAdd(array $input,int $adminId=0):array
    {
        $result=new AdminConfigurationResult($this->audit);
        $admin=['id'=>$adminId];
        $sceneKey=trim((string)$this->inputValue($input,'library_scene'));
        $tid=trim((string)$this->inputValue($input,'library_tid'));
        $kidsText=trim((string)$this->inputValue($input,'library_kids'));
        $kids=array_values(array_unique(array_filter(array_map('intval',preg_split('/[,\s]+/',$kidsText)?:[]),static function($id){return $id>0;})));
        $definitions=$this->catalog->definitions();
        if(!isset($definitions[$sceneKey]))throw new RuntimeException('订阅场景不存在。');
        if(count($kids)<2||count($kids)>5)throw new RuntimeException('请选择 2～5 个微信模板关键词。');
        $wechat=$this->subscribe;
        $templateId=$wechat->addTemplate($tid,$kids,(string)($definitions[$sceneKey]['title']??'壁选通知'));
        $remote=$wechat->templateList();
        if(!isset($remote[$templateId]))throw new RuntimeException('微信已添加模板 '.$templateId.'，但暂未能从“我的模板”同步到详情。请稍后点击“同步微信模板”。');
        $binding=$this->saveSubscriptionRemoteBinding($sceneKey,$templateId,$remote[$templateId],(int)$admin['id']);
        $result->audit('settings.notifications.library_add','system_settings',null,'从微信公共模板库添加并绑定：'.$sceneKey,null,['scene_key'=>$sceneKey,'template_id'=>$templateId,'kids'=>$kids]);
        $message='已从微信模板库添加并绑定：'.(string)($remote[$templateId]['title']??$templateId);
        if(!$binding['complete'])$message.='；还有 '.(count($binding['unresolved_fields'])+count($binding['missing_semantics'])).' 项无法可靠自动判断，请在高级设置确认。';
        $result->notice($binding['complete']?'success':'warning',$message);
        return $result->navigate('settings.messages',['channel'=>'subscribe','scene'=>$sceneKey],'#subscribe-scene-'.rawurlencode($sceneKey));

    }

    public function notificationSettingsImport(array $input,int $adminId=0):array
    {
        $result=new AdminConfigurationResult($this->audit);
        $admin=['id'=>$adminId];
        $sceneKey=trim((string)$this->inputValue($input,'import_scene'));
        $ids=$this->inputArray($input,'import_template_id');
        $templateId=trim((string)($ids[$sceneKey]??''));
        if($templateId===''||preg_match('/^[A-Za-z0-9_-]{8,128}$/',$templateId)!==1)throw new RuntimeException('请输入有效的微信模板 ID。');
        $remote=($this->subscribe)->templateList();
        if(!isset($remote[$templateId]))throw new RuntimeException('当前小程序“我的订阅模板”中没有找到该模板 ID。请确认 AppID 与模板 ID 是否对应。');
        $binding=$this->saveSubscriptionRemoteBinding($sceneKey,$templateId,$remote[$templateId],(int)$admin['id']);
        $result->audit('settings.notifications.import','system_settings',null,'识别并绑定已有微信订阅模板：'.$sceneKey,null,['scene_key'=>$sceneKey,'template_id'=>$templateId]);
        $message='已识别并绑定模板：'.(string)($remote[$templateId]['title']??$templateId);
        if(!$binding['complete'])$message.='；仍有无法可靠识别的字段，请在高级设置确认。';
        $result->notice($binding['complete']?'success':'warning',$message);
        return $result->navigate('settings.messages',['channel'=>'subscribe','scene'=>$sceneKey],'#subscribe-scene-'.rawurlencode($sceneKey));

    }
}
