<?php
declare(strict_types=1);

namespace WorkerAdmin\Contracts {
    interface SettingsProvider {
        public function get(string $key,$default=null);
        public function bool(string $key,bool $default=false):bool;
        public function int(string $key,int $default=0):int;
    }
}
namespace WorkerAdmin\Infrastructure\Safety {
    final class TestingEnvironmentPolicy { public static function active():bool{return false;} }
}
namespace {
    require __DIR__.'/source/server/app/Application/Messaging/AnnouncementQuery.php';
    require __DIR__.'/source/server/app/Domain/Messaging/SettingsValuePolicy.php';

    final class S implements \WorkerAdmin\Contracts\SettingsProvider {
        public array $v;
        public function __construct(array $v){$this->v=$v;}
        public function get(string $key,$default=null){return array_key_exists($key,$this->v)?$this->v[$key]:$default;}
        public function bool(string $key,bool $default=false):bool{
            $value=$this->get($key,$default);
            if(is_bool($value))return $value;
            $parsed=filter_var($value,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);
            return $parsed===null?$default:$parsed;
        }
        public function int(string $key,int $default=0):int{return (int)$this->get($key,$default);}
    }
    function ok(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}

    $settings=new S([
        'announcement.home.enabled'=>true,'announcement.home.text'=>'首页公告','announcement.home.link'=>'/pages/index/index',
        'announcement.store.enabled'=>true,'announcement.store.text'=>'门店公告','announcement.store.link'=>'/subpackages/store/index/index',
    ]);
    $query=new \WorkerAdmin\Application\Messaging\AnnouncementQuery($settings);
    ok($query->current('home')['text']==='首页公告','home announcement must remain page-specific');
    ok($query->current('store')['text']==='门店公告','store announcement must remain page-specific');

    $agreement=$query->current('platform_agreement');
    ok($agreement['enabled']===true,'platform agreement should default enabled');
    ok($agreement['text']==='平台协议与规则','platform agreement default text mismatch');
    ok($agreement['link']==='/subpackages/support/agreements/index','platform agreement default link mismatch');

    $settings->v['announcement.platform_agreement.enabled']=false;
    $disabled=(new \WorkerAdmin\Application\Messaging\AnnouncementQuery($settings))->current('platform_agreement');
    ok($disabled['enabled']===false&&$disabled['text']===''&&$disabled['link']==='','explicit platform agreement disable must win over defaults');

    $known=[
        'announcement.home.text'=>['value_type'=>'string','setting_value'=>''],
        'announcement.store.text'=>['value_type'=>'string','setting_value'=>''],
        'announcement.platform_agreement.enabled'=>['value_type'=>'bool','setting_value'=>'1'],
        'announcement.platform_agreement.text'=>['value_type'=>'string','setting_value'=>'平台协议与规则'],
        'announcement.platform_agreement.link'=>['value_type'=>'string','setting_value'=>'/subpackages/support/agreements/index'],
    ];
    $updates=\WorkerAdmin\Domain\Messaging\SettingsValuePolicy::validate([
        'announcement.home.text'=>'新首页公告',
        'announcement.store.text'=>'新门店公告',
        'announcement.platform_agreement.enabled'=>'1',
        'announcement.platform_agreement.text'=>'协议中心',
        'announcement.platform_agreement.link'=>'/subpackages/support/agreements/index',
    ],$known,'announcement','',[],[],[]);
    ok($updates['announcement.home.text']==='新首页公告'&&$updates['announcement.store.text']==='新门店公告','page-specific announcement keys must remain independently writable');
    ok($updates['announcement.platform_agreement.text']==='协议中心','isolated platform agreement key must be writable');

    $rejected=false;
    try {
        \WorkerAdmin\Domain\Messaging\SettingsValuePolicy::validate([
            'announcement.platform_agreement.link'=>'http://unsafe.example'
        ],$known,'announcement','',[],[],[]);
    } catch (RuntimeException $e) { $rejected=str_contains($e->getMessage(),'HTTPS'); }
    ok($rejected,'platform agreement link must keep the announcement HTTPS/path validation');
    echo "PHP page-specific announcement and platform agreement contract: OK\n";
}
