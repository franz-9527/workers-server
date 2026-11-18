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
        'announcement.home.items'=>[
            ['enabled'=>true,'text'=>'首页公告一','link'=>'/pages/index/index'],
            ['enabled'=>false,'text'=>'暂停公告','link'=>''],
            ['enabled'=>true,'text'=>'首页公告二','link'=>'https://example.com/notice'],
        ],
        'announcement.store.enabled'=>true,
        'announcement.store.text'=>'门店公告',
        'announcement.store.link'=>'/subpackages/store/index/index',
    ]);
    $query=new \WorkerAdmin\Application\Messaging\AnnouncementQuery($settings);
    $home=$query->home();
    ok(count($home['items'])===2,'home must expose all enabled list items');
    ok($home['items'][0]['text']==='首页公告一'&&$home['items'][1]['text']==='首页公告二','home list order/content mismatch');
    ok($query->current('home')['text']==='首页公告一','legacy current(home) must project first active list item');
    ok($query->current('store')['text']==='门店公告','store must remain independent single-page announcement');

    $legacy=new \WorkerAdmin\Application\Messaging\AnnouncementQuery(new S([
        'announcement.home.enabled'=>true,
        'announcement.home.text'=>'旧首页公告',
        'announcement.home.link'=>'/pages/index/index',
    ]));
    ok($legacy->home()['items'][0]['text']==='旧首页公告','missing home.items must temporarily fall back to legacy single home notice');

    $agreement=(new \WorkerAdmin\Application\Messaging\AnnouncementQuery(new S([])))->current('platform_agreement');
    ok($agreement['enabled']===true&&$agreement['text']==='平台协议与规则','platform agreement defaults must remain isolated');

    $known=[
        'announcement.home.items'=>['value_type'=>'json','setting_value'=>'[]'],
        'announcement.home.enabled'=>['value_type'=>'bool','setting_value'=>'1'],
        'announcement.home.text'=>['value_type'=>'string','setting_value'=>'旧值'],
        'announcement.store.text'=>['value_type'=>'string','setting_value'=>''],
    ];
    $updates=\WorkerAdmin\Domain\Messaging\SettingsValuePolicy::validate([
        'announcement.home.items'=>[
            '__present'=>'1',
            ['enabled'=>'1','text'=>' 公告 A ','link'=>'/pages/index/index'],
            ['enabled'=>'0','text'=>'保留但停用','link'=>''],
            ['enabled'=>'1','text'=>'公告 B','link'=>'https://example.com/b'],
        ],
        'announcement.home.enabled'=>'0',
        'announcement.home.text'=>'不应再写入',
        'announcement.store.text'=>'门店新公告',
    ],$known,'announcement','',[],[],[]);
    $saved=json_decode($updates['announcement.home.items'],true);
    ok(count($saved)===3&&$saved[0]['text']==='公告 A'&&$saved[1]['enabled']===false&&$saved[2]['link']==='https://example.com/b','home list normalization failed');
    ok(!array_key_exists('announcement.home.enabled',$updates)&&!array_key_exists('announcement.home.text',$updates),'legacy home single keys must not be writable');
    ok($updates['announcement.store.text']==='门店新公告','other page single announcement must remain writable');

    $reject=function(array $items,string $contains)use($known):void{
        try{
            \WorkerAdmin\Domain\Messaging\SettingsValuePolicy::validate(['announcement.home.items'=>$items],$known,'announcement','',[],[],[]);
            throw new RuntimeException('expected rejection missing');
        }catch(RuntimeException $e){
            if($e->getMessage()==='expected rejection missing'||!str_contains($e->getMessage(),$contains))throw $e;
        }
    };
    $reject([['enabled'=>'1','text'=>'','link'=>'']],'必须填写公告文字');
    $reject([['enabled'=>'1','text'=>'公告','link'=>'http://unsafe.example']],'HTTPS');
    $tooMany=[];for($i=0;$i<11;$i++)$tooMany[]=['enabled'=>'0','text'=>'公告'.$i,'link'=>''];
    $reject($tooMany,'最多配置 10 条');

    echo "PHP home multi-announcement contract: OK\n";
}
