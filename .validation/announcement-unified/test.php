<?php
declare(strict_types=1);

namespace WorkerAdmin\Contracts {
    interface SettingsProvider {
        public function get(string $key, $default = null);
        public function bool(string $key, bool $default = false): bool;
        public function int(string $key, int $default = 0): int;
    }
}
namespace WorkerAdmin\Infrastructure\Safety {
    final class TestingEnvironmentPolicy { public static function active(): bool { return false; } }
}
namespace {
    require __DIR__.'/source/server/app/Application/Messaging/AnnouncementQuery.php';
    require __DIR__.'/source/server/app/Domain/Messaging/SettingsValuePolicy.php';

    final class ValidationSettings implements \WorkerAdmin\Contracts\SettingsProvider {
        public array $values;
        public function __construct(array $values) { $this->values=$values; }
        public function get(string $key, $default = null) { return array_key_exists($key,$this->values)?$this->values[$key]:$default; }
        public function bool(string $key, bool $default = false): bool {
            $value=$this->get($key,$default);
            if(is_bool($value))return $value;
            $filtered=filter_var($value,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);
            return $filtered===null?$default:$filtered;
        }
        public function int(string $key, int $default = 0): int { $value=$this->get($key,$default); return is_numeric($value)?(int)$value:$default; }
    }
    function ok(bool $value,string $message):void { if(!$value)throw new RuntimeException($message); }

    $settings=new ValidationSettings([
        'announcement.enabled'=>true,
        'announcement.text'=>'  统一公告  ',
        'announcement.link'=>'/subpackages/measure/imageAnnotator/index',
        'announcement.home.enabled'=>false,
        'announcement.home.text'=>'旧首页公告',
    ]);
    $query=new \WorkerAdmin\Application\Messaging\AnnouncementQuery($settings);
    $current=$query->current();
    ok($current['enabled']===true,'global announcement should be enabled');
    ok($current['text']==='统一公告','global announcement text should be trimmed');
    ok($current['link']==='/subpackages/measure/imageAnnotator/index','global announcement link should be returned');
    ok($current['environment']==='production','environment marker should be retained');

    $known=[
        'announcement.enabled'=>['value_type'=>'bool','setting_value'=>'1'],
        'announcement.text'=>['value_type'=>'string','setting_value'=>''],
        'announcement.link'=>['value_type'=>'string','setting_value'=>''],
        'announcement.order_submit.text'=>['value_type'=>'string','setting_value'=>'旧页面公告'],
    ];
    $updates=\WorkerAdmin\Domain\Messaging\SettingsValuePolicy::validate(
        [
            'announcement.enabled'=>'1',
            'announcement.text'=>'新的统一公告',
            'announcement.link'=>'/pages/index/index',
            'announcement.order_submit.text'=>'不得再保存',
        ],
        $known,'announcement','',[],[],[]
    );
    ok(array_keys($updates)===['announcement.enabled','announcement.text','announcement.link'],'legacy scene key must be ignored');
    $rejected=false;
    try {
        \WorkerAdmin\Domain\Messaging\SettingsValuePolicy::validate(
            ['announcement.link'=>'http://unsafe.example'],
            $known,'announcement','',[],[],[]
        );
    } catch (RuntimeException $e) { $rejected=str_contains($e->getMessage(),'HTTPS'); }
    ok($rejected,'unsafe announcement link must be rejected');
    echo "PHP unified announcement contract: OK\n";
}
