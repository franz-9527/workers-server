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
        'announcement.current'=>'GET',
        'subscriptions.status'=>'GET', 'subscriptions.diagnose'=>'GET', 'subscriptions.preference'=>'POST',
        'notifications.summary'=>'GET', 'notifications.list'=>'GET', 'notifications.read'=>'POST',
        'notifications.read_batch'=>'POST', 'notifications.read_all'=>'POST',
    ];
    private \WorkerAdmin\Application\Messaging\AnnouncementQuery $announcement;
    private ApiActorResolver $actors;
    private NotificationInbox $inbox;
    private SubscriptionPreferences $subscriptions;
    public function __construct(Database $db, ?ApiActorResolver $actors=null, ?NotificationInbox $inbox=null, ?SubscriptionPreferences $subscriptions=null)
    {
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
            if ($route === 'announcement.current') $data=$this->announcement->current((string)($_GET['scene']??''));
            elseif ($route === 'subscriptions.status') $data = $this->subscriptions->status($actor, (string)($_GET['include_application'] ?? '') === '1');
            elseif ($route === 'subscriptions.diagnose') $data = $this->subscriptions->diagnose($actor);
            elseif ($route === 'notifications.summary') $data = $this->inbox->summary($actor);
            elseif ($route === 'notifications.list') $data = $this->inbox->list($actor, (int)($_GET['page'] ?? 1));
            elseif ($route === 'notifications.read_batch') $data = $this->inbox->readBatch($actor, is_array($body['ids'] ?? null) ? $body['ids'] : []);
            elseif ($route === 'notifications.read') {
                $this->inbox->read($actor, (string)($body['id'] ?? ''));
                \json_response(['ok'=>true,'message'=>'已标记为已读']); return;
            } else {
                $this->inbox->readAll($actor);
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
    private function fail(string $message, string $code, int $status): void
    { \json_response(['ok'=>false,'code'=>$code,'message'=>$message,'request_id'=>\request_id()], $status); }
}
