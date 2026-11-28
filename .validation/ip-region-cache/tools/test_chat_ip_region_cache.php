<?php
declare(strict_types=1);
// Isolated CLI fixture: php -n tools/test_chat_ip_region_cache.php
if(extension_loaded('redis'))throw new RuntimeException('Run with php -n for the isolated Redis double');
class Redis {
    public array $values=[];
    public array $ttls=[];
    public function get(string $key):string|false{return $this->values[$key]??false;}
    public function setex(string $key,int $ttl,string $value):bool{$this->values[$key]=$value;$this->ttls[$key]=$ttl;return true;}
}
require_once dirname(__DIR__).'/server/app/Contracts/RuntimeAcceleration.php';
require_once dirname(__DIR__).'/server/app/Infrastructure/Persistence/RedisRuntime.php';
use WorkerAdmin\Contracts\RuntimeAcceleration;
use WorkerAdmin\Infrastructure\Persistence\RedisRuntime;
function ensure(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$reflect=new ReflectionClass(RedisRuntime::class);
$runtime=$reflect->newInstanceWithoutConstructor();
$redis=new Redis();
$reflect->getProperty('attempted')->setValue($runtime,true);
$reflect->getProperty('client')->setValue($runtime,$redis);
$reflect->getProperty('prefix')->setValue($runtime,'bxchat:test:php:');
$scope=RuntimeAcceleration::IP_REGION_SCOPE;
$ttl=RuntimeAcceleration::IP_REGION_TTL;
$calls=0;
$load=static function()use(&$calls):array{$calls++;return ['display'=>'浙江省 杭州市 西湖区'];};
$ip='192.0.2.10';
$key='bxchat:test:php:'.$scope.':'.hash('sha256',$ip);
ensure($runtime->rememberPublic($scope,$ip,$ttl,$load)['display']==='浙江省 杭州市 西湖区','valid region');
ensure($redis->ttls[$key]===604800,'seven day positive TTL');
ensure($runtime->rememberPublic($scope,$ip,$ttl,$load)['display']==='浙江省 杭州市 西湖区'&&$calls===1,'cache hit avoids repeated provider call');
$runtime->rememberPublic($scope,'192.0.2.11',$ttl,$load);
ensure($calls===2,'new IP uses separate key');
$emptyCalls=0;
$empty=static function()use(&$emptyCalls):array{$emptyCalls++;return [];};
$emptyIp='192.0.2.12';
$emptyKey='bxchat:test:php:'.$scope.':'.hash('sha256',$emptyIp);
$runtime->rememberPublic($scope,$emptyIp,$ttl,$empty);
$runtime->rememberPublic($scope,$emptyIp,$ttl,$empty);
ensure($redis->ttls[$emptyKey]===3600&&$emptyCalls===1,'one hour negative cache');
$runtime->rememberPublic('announcement','home',10,$load);
ensure($redis->ttls['bxchat:test:php:announcement:'.hash('sha256','home')]===10,'other cache TTL unchanged');
foreach([['announcement',61],[$scope,604801],[$scope,0]] as [$s,$t]){
    try{$runtime->rememberPublic($s,'x',$t,$load);throw new RuntimeException('invalid TTL accepted');}
    catch(InvalidArgumentException $expected){}
}
$reflect->getProperty('client')->setValue($runtime,null);
$before=$calls;$runtime->rememberPublic($scope,$ip,$ttl,$load);
ensure($calls===$before+1,'Redis unavailable preserves fallback');
echo 'IP region cache: positive, negative, hit, IP isolation, TTL bounds, fallback passed'.PHP_EOL;
