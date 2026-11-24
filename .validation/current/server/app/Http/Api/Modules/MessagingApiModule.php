<?php
declare(strict_types=1);
namespace WorkerAdmin\Http\Api\Modules;
use RuntimeException;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Http\Api\ApiModule;
use WorkerAdmin\Foundation\ApplicationContainer;
use WorkerAdmin\Foundation\MessagingApplicationContainer;
use WorkerAdmin\Application\Identity\ApiActorResolver;
use WorkerAdmin\Application\Messaging\NotificationInbox;
use WorkerAdmin\Application\Messaging\SubscriptionPreferences;
use WorkerAdmin\Domain\Messaging\MessageOperationException;
use WorkerAdmin\Domain\Order\OrderOperationException;
/** Subscription settings and the signed-in actor's notification inbox. */
final class MessagingApiModule implements ApiModule
{
    private const ROUTES = [
        'announcement.current'=>'GET', 'announcement.home'=>'GET',
        'subscriptions.status'=>'GET', 'subscriptions.diagnose'=>'GET', 'subscriptions.preference'=>'POST',
        'notifications.threads'=>'GET','notifications.thread_messages'=>'GET','notifications.summary'=>'GET', 'notifications.list'=>'GET', 'notifications.read'=>'POST',
        'notifications.read_batch'=>'POST', 'notifications.read_all'=>'POST',
    ];
    private \WorkerAdmin\Application\Messaging\AnnouncementQuery $announcement;
    private \WorkerAdmin\Infrastructure\Persistence\RedisRuntime $redis;
    private ApiActorResolver $actors;
    private NotificationInbox $inbox;
    private SubscriptionPreferences $subscriptions;
    public function __construct(Database $db, ?ApiActorResolver $actors=null, ?NotificationInbox $inbox=null, ?SubscriptionPreferences $subscriptions=null)
    {
        $this->redis=new \WorkerAdmin\Infrastructure\Persistence\RedisRuntime($db);
        $container = new MessagingApplicationContainer($db);
        $this->announcement=new \WorkerAdmin\Application\Messaging\AnnouncementQuery((new ApplicationContainer($db))->settings());
        $this->actors = $actors ?: (new ApplicationContainer($db))->apiActorResolver();
        $this->inbox = $inbox ?: $container->inbox();
        $this->subscriptions = $subscriptions ?: $container->subscriptions();
    }
    public function supports(string $route): bool { return isset(self::ROUTES[$route]); }
    public function requiresContentSecurity(string $route): bool { return false; }
    public function dispatch(string $route): void
    {
        if (!$this->supports($route)) { $this->fail('接口不存在', 'NOT_FOUND', 404); return; }
        // 公告不含身份资料，首页未登录也可读取；首页多条列表和其他页面单条公告使用独立公开路由。
        if(in_array($route,['announcement.current','announcement.home'],true)){
            if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='GET'){$this->fail('请求方式不正确','METHOD_NOT_ALLOWED',405);return;}
            header('Cache-Control: no-store');
            if($route==='announcement.home')$data=$this->redis->rememberPublic('announcement','home-list',10,fn():array=>$this->announcement->home());
            else{
                $scene=isset($_GET['scene'])&&is_string($_GET['scene'])?$_GET['scene']:'';
                $data=in_array($scene,['home','store','order_submit','order_hall','merchant_auth','quote_orders','platform_agreement'],true)
                    ?$this->redis->rememberPublic('announcement','scene:'.$scene,10,fn():array=>$this->announcement->current($scene))
                    :$this->announcement->current($scene);
            }
            \json_response(['ok'=>true,'data'=>$data]);return;
        }
        $header = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        if ($header === '' && function_exists('getallheaders')) {
            $headers = getallheaders();
            $header = trim((string)($headers['Authorization'] ?? $headers['authorization'] ?? ''));
        }
        if (preg_match('/^Bearer\s+([A-Fa-f0-9]{64})$/', $header, $matches) !== 1) { $this->fail('请重新登录','UNAUTHORIZED',401); return; }
        try { $actor = $this->actors->resolve($matches[1]); }
        catch (RuntimeException $exception) {
            $status = in_array($exception->getCode(), [401,403,503], true) ? (int)$exception->getCode() : 401;
            $this->fail($exception->getMessage(), $status === 503 ? 'MINIAPP_APPID_MISSING' : 'UNAUTHORIZED', $status);
            return;
        }
        try {
            // The demo boundary precedes method/body validation, as in the old dispatch.
            if ($route === 'subscriptions.preference' && !empty($actor['is_demo_actor'])) {
                $this->subscriptions->save($actor, []);
            }
            if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== self::ROUTES[$route]) {
                $this->fail('请求方式不正确','METHOD_NOT_ALLOWED',405); return;
            }
            $raw = self::ROUTES[$route] === 'POST' ? file_get_contents('php://input') : '';
            // Retain the legacy empty/malformed-body fallback for these existing endpoints.
            $body = json_decode(is_string($raw) ? $raw : '', true);
            $body = is_array($body) ? $body : [];
            if ($route === 'subscriptions.preference') { \json_response(['ok'=>true] + $this->subscriptions->save($actor, $body)); return; }
            if ($route === 'subscriptions.status') $data = $this->subscriptions->status($actor, (string)($_GET['include_application'] ?? '') === '1');
            elseif ($route === 'subscriptions.diagnose') $data = $this->subscriptions->diagnose($actor);
            elseif ($route === 'notifications.summary') $data = $this->inbox->summary($actor);
            elseif ($route === 'notifications.threads') $data=$this->inbox->threads($actor,$this->cursorInput($_GET['cursor']??''));
            elseif ($route === 'notifications.thread_messages') $data=$this->inbox->threadMessages($actor,(string)($_GET['cursor']??''));
            elseif ($route === 'notifications.list') $data = (string)($_GET['schema']??'')==='2'
                ? $this->inbox->threads($actor,$this->cursorInput($_GET['cursor']??''))
                : $this->inbox->list($actor, (int)($_GET['page'] ?? 1), (string)($_GET['through_id'] ?? ''));
            elseif ($route === 'notifications.read_batch') $data = $this->inbox->readBatch($actor, is_array($body['ids'] ?? null) ? $body['ids'] : []);
            elseif ($route === 'notifications.read') {
                $this->inbox->read($actor, (string)($body['id'] ?? ''));
                \json_response(['ok'=>true,'message'=>'已标记为已读']); return;
            } else {
                if(array_key_exists('range_cursor',$body))$this->inbox->readRange($actor,$this->cursorInput($body['range_cursor']));
                else $this->inbox->readAll($actor, (string)($body['through_id'] ?? ''));
                \json_response(['ok'=>true,'message'=>'未读消息已清除']); return;
            }
            $result = ['ok'=>true,'data'=>$data];
            if ($route === 'notifications.read_batch') $result['message'] = '消息已标记为已读';
            \json_response($result);
        } catch (MessageOperationException $exception) {
            $this->fail($exception->getMessage(), $exception->apiCode(), (int)$exception->getCode());
        } catch (OrderOperationException $exception) {
            $this->fail($exception->getMessage(), $exception->apiCode(), (int)$exception->getCode());
        }
    }
    private function cursorInput($value): string
    {
        if(!is_string($value))throw new MessageOperationException('通知浏览范围无效','NOTIFICATION_RESYNC_REQUIRED',409);
        return $value;
    }
    private function fail(string $message, string $code, int $status): void
    { \json_response(['ok'=>false,'code'=>$code,'message'=>$message,'request_id'=>\request_id()], $status); }
}
