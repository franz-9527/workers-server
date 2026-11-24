<?php
declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Security\GlobalPrivateValueCipher;

/** Optional, environment-scoped acceleration. Never stores authoritative business facts. */
final class RedisRuntime implements \WorkerAdmin\Contracts\RuntimeAcceleration
{
    public const ENABLED='chat.go_redis_enabled';
    public const ADDRESS='chat.go_redis_address';
    public const PASSWORD='chat.go_redis_password_ciphertext';
    public const PASSWORD_CONTEXT='chat-go-redis-password-v1';
    private ?\Redis $client=null;
    private bool $attempted=false;
    private string $prefix='';

    public function __construct(private Database $db){}

    public static function settings(Database $db):array
    {
        $rows=$db->fetchAll("SELECT setting_key,setting_value FROM system_settings WHERE setting_key IN ('".self::ENABLED."','".self::ADDRESS."','".self::PASSWORD."')");
        $values=[];foreach($rows as $row)$values[(string)$row['setting_key']]=(string)$row['setting_value'];
        $address=(string)($values[self::ADDRESS]??'127.0.0.1:6379');
        return ['enabled'=>($values[self::ENABLED]??'1')!=='0','address'=>$address,'password_ciphertext'=>(string)($values[self::PASSWORD]??'')];
    }

    private function connection():?\Redis
    {
        if($this->attempted)return $this->client;
        $this->attempted=true;
        if(!class_exists(\Redis::class))return null;
        try{
            $env=['testing'=>'test','development'=>'development','production'=>'production'][(string)\app_config('app.env','')]??'';
            $s=self::settings($this->db);
            if($env===''||!$s['enabled']||!preg_match('/^(127\.0\.0\.1|\[::1\]):([0-9]{1,5})$/D',$s['address'],$m)||(int)$m[2]<1||(int)$m[2]>65535)return null;
            $r=new \Redis();
            if(!@$r->connect(trim($m[1],'[]'),(int)$m[2],0.15,null,0,0.15))return null;
            if($s['password_ciphertext']!==''){
                $password=(new GlobalPrivateValueCipher())->decrypt($s['password_ciphertext'],self::PASSWORD_CONTEXT);
                if($password===''||!@$r->auth($password))return null;
            }
            // Force the same logical DB as Go; do not rely on a client default.
            if(!@$r->select(0))return null;
            $this->prefix='bxchat:'.$env.':php:';
            $this->client=$r;
            return $r;
        }catch(\Throwable $e){return null;}
    }

    /** Instance-level totals are explicitly not site-specific. No keys or secrets are exposed. */
    public function diagnostics():array
    {
        if(!class_exists(\Redis::class))return ['state'=>'extension_missing'];
        $r=$this->connection();
        if($r===null)return ['state'=>'unavailable_or_disabled'];
        try{if($r->ping()===false)return ['state'=>'unavailable_or_disabled'];}catch(\Throwable $e){return ['state'=>'unavailable_or_disabled'];}
        $out=['state'=>'ready'];
        try{
            $raw=$r->get(substr($this->prefix,0,-4).'worker:metrics');
            if(is_string($raw)&&strlen($raw)<=2048){
                $worker=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
                if(is_array($worker))foreach(['wake_checks','wake_hits','wake_writes','presence_reads','errors'] as $key){
                    if(isset($worker[$key])&&is_int($worker[$key])&&$worker[$key]>=0)$out['worker'][$key]=$worker[$key];
                }
            }
        }catch(\Throwable $e){}
        try{
            foreach(['server'=>['redis_version'],'memory'=>['used_memory','maxmemory','maxmemory_policy'],'clients'=>['connected_clients'],'stats'=>['total_commands_processed','keyspace_hits','keyspace_misses']] as $section=>$fields){
                $info=$r->info($section);
                if(!is_array($info)){$out['state']='limited';continue;}
                foreach($fields as $field)if(isset($info[$field])&&is_scalar($info[$field]))$out[$field]=$info[$field];
            }
        }catch(\Throwable $e){$out['state']='limited';}
        return $out;
    }

    private function key(string $scope,string $id):string
    {
        if(!preg_match('/^[a-z][a-z0-9_-]{0,31}$/D',$scope)||strlen($id)>256)throw new \InvalidArgumentException('Invalid cache key');
        return $this->prefix.$scope.':'.hash('sha256',$id);
    }

    /** Only public, bounded JSON read models; errors fall back to the supplied loader. */
    public function rememberPublic(string $scope,string $id,int $ttl,callable $loader):array
    {
        if($ttl<1||$ttl>60)throw new \InvalidArgumentException('Invalid cache TTL');
        $r=$this->connection();
        if($r===null)return $loader();
        $key=$this->key($scope,$id);
        try{
            $raw=$r->get($key);
            if(is_string($raw)&&strlen($raw)<=65536){
                $value=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
                if(is_array($value))return $value;
            }
        }catch(\Throwable $e){}
        $value=$loader();
        try{
            $raw=json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            if(strlen($raw)<=65536)$r->setex($key,$ttl,$raw);
        }catch(\Throwable $e){}
        return $value;
    }

    /** Supplementary fixed-window limiter: null means unavailable, never replaces business limits. */
    public function allow(string $scope,string $id,int $limit,int $seconds):?bool
    {
        if($limit<1||$limit>10000||$seconds<1||$seconds>300)throw new \InvalidArgumentException('Invalid rate limit');
        $r=$this->connection();
        if($r===null)return null;
        try{
            $key=$this->key($scope,$id.':'.intdiv(time(),$seconds));
            $count=$r->eval("local n=tonumber(redis.call('GET',KEYS[1]) or '0'); if n>=tonumber(ARGV[2]) then return n+1 end; n=redis.call('INCR',KEYS[1]); if redis.call('TTL',KEYS[1])<0 then redis.call('EXPIRE',KEYS[1],ARGV[1]) end; return n",[$key,$seconds+1,$limit],1);
            return is_int($count)?$count<=$limit:null;
        }catch(\Throwable $e){return null;}
    }
}
