<?php
declare(strict_types=1);
namespace WorkerAdmin\Infrastructure\Messaging;

use WorkerAdmin\Domain\Messaging\MessageOperationException;

/** Ten-minute, actor/environment-bound pagination; the cursor never grants ownership. */
final class NotificationInboxCursor
{
    private function key(): string
    {
        $key=(string)\app_config('app.key','');
        if(strlen($key)<32)throw new MessageOperationException('通知分页暂不可用，请稍后重试','NOTIFICATION_CURSOR_UNAVAILABLE',503);
        return hash_hmac('sha256','notification-inbox:v1:'.(string)\app_config('app.env','production'),$key,true);
    }
    private function scope(array $actor): string
    {
        $values=[];
        foreach(['user_id','worker_id','is_demo_actor','test_instance_id','demo_actor_id','login_user_id'] as $name)$values[$name]=(string)($actor[$name]??'');
        return hash('sha256',json_encode($values,JSON_THROW_ON_ERROR));
    }
    public function begin(array $actor,string $max,string $count): array
    {
        return ['v'=>1,'scope'=>$this->scope($actor),'max'=>$max,'count'=>$count,'exp'=>time()+600,'kind'=>'range','thread'=>'','before'=>''];
    }
    public function seal(array $state,string $kind,string $thread='',string $before=''): string
    {
        $state['kind']=$kind;$state['thread']=$thread;$state['before']=$before;
        $raw=json_encode($state,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $encoded=rtrim(strtr(base64_encode($raw),'+/','-_'),'=');
        return $encoded.'.'.hash_hmac('sha256',$encoded,$this->key());
    }
    public function open(array $actor,string $cursor,string $kind): array
    {
        $this->key();
        if(strlen($cursor)>2048||preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D',$cursor,$m)!==1
            ||!hash_equals(hash_hmac('sha256',$m[1],$this->key()),$m[2]))$this->invalid();
        $raw=base64_decode(strtr($m[1],'-_','+/'),true);
        $p=is_string($raw)?json_decode($raw,true):null;
        $fields=['v','scope','max','count','exp','kind','thread','before'];
        if(!is_array($p)||array_keys($p)!==$fields||$p['v']!==1||$p['scope']!==$this->scope($actor)||$p['kind']!==$kind
            ||!is_int($p['exp'])||$p['exp']<=time()||$p['exp']>time()+600
            ||!is_string($p['max'])||!is_string($p['count'])||!is_string($p['thread'])||!is_string($p['before'])
            ||preg_match('/^(0|[1-9][0-9]{0,19})$/D',$p['max'])!==1
            ||preg_match('/^(0|[1-9][0-9]{0,19})$/D',$p['count'])!==1
            ||($p['thread']!==''&&preg_match('/^(?:(?:user|worker):[1-9][0-9]{0,19}|single:[A-Za-z0-9_-]{1,80})$/D',$p['thread'])!==1)
            ||($p['before']!==''&&preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}[0-9]{20}$/D',$p['before'])!==1))$this->invalid();
        if(($kind==='messages'&&$p['thread']==='')||($kind!=='messages'&&$p['thread']!==''))$this->invalid();
        return $p;
    }
    private function invalid(): void
    {throw new MessageOperationException('通知浏览范围已失效，请下拉刷新','NOTIFICATION_RESYNC_REQUIRED',409);}
}
