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
  require __DIR__.'/source/AnnouncementQuery.php';
  final class S implements \WorkerAdmin\Contracts\SettingsProvider {
    public array $v;
    public function __construct(array $v){$this->v=$v;}
    public function get(string $key,$default=null){return array_key_exists($key,$this->v)?$this->v[$key]:$default;}
    public function bool(string $key,bool $default=false):bool{
      $x=$this->get($key,$default);if(is_bool($x))return $x;
      $f=filter_var($x,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);return $f===null?$default:$f;
    }
    public function int(string $key,int $default=0):int{return (int)$this->get($key,$default);}
  }
  function ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
  $legacy=new S(['announcement.home.enabled'=>true,'announcement.home.text'=>'旧首页公告','announcement.home.link'=>'/pages/index/index']);
  $a=(new \WorkerAdmin\Application\Messaging\AnnouncementQuery($legacy))->current();
  ok($a['enabled']===true&&$a['text']==='旧首页公告'&&$a['link']==='/pages/index/index','missing global key must inherit legacy home');
  $legacy->v['announcement.enabled']=false;
  $b=(new \WorkerAdmin\Application\Messaging\AnnouncementQuery($legacy))->current();
  ok($b['enabled']===false&&$b['text']===''&&$b['link']==='','explicit global disable must stop fallback');
  $global=new S(['announcement.enabled'=>true,'announcement.text'=>'统一公告','announcement.link'=>'/subpackages/support/agreements/index','announcement.home.enabled'=>true,'announcement.home.text'=>'旧公告']);
  $c=(new \WorkerAdmin\Application\Messaging\AnnouncementQuery($global))->current();
  ok($c['enabled']===true&&$c['text']==='统一公告','global keys must win over legacy home');
  echo "Announcement migration transition: OK\n";
}
