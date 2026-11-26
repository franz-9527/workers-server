<?php
declare(strict_types=1);
namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Core\Database;

/** 同一数据库内审核、删除和审核通知发送共享的连接级互斥边界；不持有数据库事务。 */
final class WorkerReviewLock
{
    public static function acquire(Database $db,string $cloudId,int $wait=0):?string
    {
        $name='worker-review:'.substr(hash('sha256',(string)$db->fetchValue('SELECT DATABASE()').'|'.$cloudId),0,48);
        return (int)$db->fetchValue('SELECT GET_LOCK(:name,'.max(0,min(3,$wait)).')',['name'=>$name])===1?$name:null;
    }
    public static function release(Database $db,string $name):void
    {
        try{$db->fetchValue('SELECT RELEASE_LOCK(:name)',['name'=>$name]);}
        catch(\Throwable $ignored){}
    }
}
