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
        return self::status($config,$environment)==='ready';
    }
    public static function status(array $config,string $environment):string
    {
        $path=(string)($config['recovery_gate_file']??'');$epoch=(string)($config['recovery_epoch']??'');
        if ($path===''&&$epoch===''&&in_array($environment,['test','development'],true)) return 'ready';
        if (!self::path($path)||preg_match('/^[a-f0-9]{64}$/D',$epoch)!==1) return 'unconfigured';
        if (!@is_file($path)||!@is_readable($path)) return 'unreadable';
        if (@is_link($path)||@filesize($path)>1024||(@fileperms($path)&0022)!==0) return 'invalid';
        $raw=@file_get_contents($path);
        if (!is_string($raw)) return 'unreadable';
        $marker=json_decode($raw,true);
        if (!is_array($marker)||array_keys($marker)!==['schema','environment','epoch','state']
            ||json_encode($marker,JSON_UNESCAPED_SLASHES)!==preg_replace('/\s+(?=(?:[^"]*"[^"]*")*[^"]*$)/','',$raw)
            ||$marker['schema']!==1||$marker['environment']!==$environment||$marker['epoch']!==$epoch) return 'invalid';
        return match ($marker['state']) {'ready'=>'ready','blocked'=>'blocked',default=>'invalid'};
    }
    public static function assertReady(array $config,string $environment): void
    {if(!self::ready($config,$environment))throw new RuntimeException('聊天安全恢复尚未核验',503);}
    public static function path(string $path): bool
    {return$path!==''&&($path[0]==='/'||preg_match('/^[A-Za-z]:[\\\\\/]/D',$path)===1)&&strpos($path,"\0")===false;}
}
