<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Messaging;

use WorkerAdmin\Contracts\SettingsProvider;

final class AnnouncementQuery
{
    private SettingsProvider $settings;
    public function __construct(SettingsProvider $settings) { $this->settings=$settings; }
    public function home():array
    {
        return ['items'=>$this->homeItems(),'environment'=>$this->environment()];
    }

    public function current(string $scene):array
    {
        // 首页从本轮起是独立的多条滚动公告。旧客户端仍可能请求 announcement.current?scene=home，
        // 因此只向旧接口投影第一条启用公告；新客户端使用 announcement.home 读取完整列表。
        if($scene==='home'){
            $first=$this->homeItems()[0]??null;
            return $first
                ? ['enabled'=>true,'text'=>$first['text'],'link'=>$first['link'],'environment'=>$this->environment()]
                : ['enabled'=>false,'text'=>'','link'=>'','environment'=>$this->environment()];
        }
        $pageScenes=['store','order_submit','order_hall','merchant_auth','quote_orders'];
        $specialScene=$scene==='platform_agreement';
        if(!in_array($scene,$pageScenes,true)&&!$specialScene)return ['enabled'=>false,'text'=>'','link'=>''];
        $prefix='announcement.'.$scene;
        // 平台协议是首页滚动区里的特殊入口，不属于首页运营公告列表，也不属于其他页面公告组件。
        $defaultEnabled=$specialScene;
        $defaultText=$specialScene?'平台协议与规则':'';
        $defaultLink=$specialScene?'/subpackages/support/agreements/index':'';
        $enabled=$this->settings->bool($prefix.'.enabled',$defaultEnabled);
        $text=$enabled?trim((string)$this->settings->get($prefix.'.text',$defaultText)):'';
        $link=$enabled?trim((string)$this->settings->get($prefix.'.link',$defaultLink)):'';
        return ['enabled'=>$enabled && $text!=='','text'=>$text,'link'=>$link,'environment'=>$this->environment()];
    }

    private function homeItems():array
    {
        $raw=$this->settings->get('announcement.home.items',null);
        if($raw===null){
            // 无数据库升级脚本：新字段尚未补齐时临时投影旧首页单条配置，避免部署窗口内首页公告消失。
            $enabled=$this->settings->bool('announcement.home.enabled',false);
            $text=$enabled?trim((string)$this->settings->get('announcement.home.text','')):'';
            $link=$enabled?trim((string)$this->settings->get('announcement.home.link','')):'';
            return $enabled&&$text!==''?[['id'=>'home-1','text'=>$text,'link'=>$link]]:[];
        }
        if(is_string($raw)){
            $decoded=json_decode($raw,true);
            $raw=json_last_error()===JSON_ERROR_NONE?$decoded:[];
        }
        if(!is_array($raw))return [];
        $items=[];
        foreach($raw as $row){
            if(!is_array($row))continue;
            $enabled=filter_var($row['enabled']??false,FILTER_VALIDATE_BOOLEAN);
            $text=trim((string)($row['text']??''));
            if(!$enabled||$text==='')continue;
            $items[]=['id'=>'home-'.(count($items)+1),'text'=>$text,'link'=>trim((string)($row['link']??''))];
            if(count($items)>=10)break;
        }
        return $items;
    }

    private function environment():string
    {
        return \WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy::active()?'testing':'production';
    }
}