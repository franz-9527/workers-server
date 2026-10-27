<?php
declare(strict_types=1);
namespace WorkerAdmin\Infrastructure\Chat;

use RuntimeException;
use WorkerAdmin\Contracts\ChatRecoveryReadiness;

/** Independent control marker; never stored inside a DB backup or release tree. */
final class ChatRecoveryGate implements ChatRecoveryReadiness
{
    public function ensureReady(array $config,string $environment):void
    {self::assertReady($config,$environment);}
    public static function ready(array $config,string $environment): bool
    {
        $path=(string)($config['recovery_gate_file']??'');$epoch=(string)($config['recovery_epoch']??'');
        if($path===''&&$epoch==='')return in_array($environment,['test','development'],true);
        if(!self::path($path)||preg_match('/^[a-f0-9]{64}$/D',$epoch)!==1||!is_file($path)||is_link($path)||filesize($path)>1024||(fileperms($path)&0022)!==0)return false;
        $raw=file_get_contents($path);if(!is_string($raw))return false;
        $m=json_decode($raw,true);
        if(!is_array($m)||array_keys($m)!==['schema','environment','epoch','state'])return false;
        if(json_encode($m,JSON_UNESCAPED_SLASHES)!==preg_replace('/\s+(?=(?:[^"]*"[^"]*")*[^"]*$)/','',$raw))return false;
        return$m===['schema'=>1,'environment'=>$environment,'epoch'=>$epoch,'state'=>'ready'];
    }
    public static function assertReady(array $config,string $environment): void
    {if(!self::ready($config,$environment))throw new RuntimeException('聊天安全恢复尚未核验',503);}
    public static function path(string $path): bool
    {return$path!==''&&($path[0]==='/'||preg_match('/^[A-Za-z]:[\\\\\/]/D',$path)===1)&&strpos($path,"\0")===false;}
}
