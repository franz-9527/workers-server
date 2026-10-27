<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use RuntimeException;
use WorkerAdmin\Application\Admin\MiniAdminWorkerStoreOperations;
use WorkerAdmin\Application\Identity\ApiActorResolver;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Admin\MiniAdminOperationException;
use WorkerAdmin\Foundation\MiniAdminWorkerStoreComposition;
use WorkerAdmin\Http\Api\ApiModule;

/** 小程序管理员师傅/门店审核 HTTP 边界。 */
final class MiniAdminWorkerStoreApiModule implements ApiModule
{
    private ApiActorResolver $actors;
    private MiniAdminWorkerStoreOperations $operations;

    /** @var array<string,string> */
    private const ROUTES = [
        'admin.worker.update'=>'POST',
        'admin.worker.review'=>'POST',
        'admin.worker.sms-stats'=>'GET',
        'admin.store.review'=>'POST',
        'admin.review.records'=>'GET',
    ];

    public function __construct(Database $db)
    {
        $services = MiniAdminWorkerStoreComposition::build($db);
        $this->actors = $services['actors'];
        $this->operations = $services['operations'];
    }

    public function supports(string $route): bool
    {
        return isset(self::ROUTES[$route]);
    }

    public function requiresContentSecurity(string $route): bool
    {
        return true;
    }

    public function dispatch(string $route): void
    {
        if (!$this->supports($route)) throw new RuntimeException('接口不存在');
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== self::ROUTES[$route]) $this->fail('请求方式不正确', 'METHOD_NOT_ALLOWED', 405);

        try {
            $actor = $this->actors->resolve($this->bearerToken());
            if (!empty($actor['is_demo_actor'])) $this->fail('当前为演示身份，请先退出演示身份后处理真实管理员业务', 'DEMO_ADMIN_ACTION_BLOCKED', 403);
            if (empty($actor['is_admin'])) $this->fail('仅管理员可使用工作台', 'ADMIN_REQUIRED', 403);
            $input = $method === 'GET' ? $_GET : $this->jsonBody();
            $result = $this->operations->execute($route, $actor, is_array($input) ? $input : []);
            if (in_array($route, ['admin.worker.sms-stats','admin.review.records'], true)) $this->noStore();
            $response = ['ok'=>true];
            if ((string)($result['message'] ?? '') !== '') $response['message'] = $result['message'];
            if (array_key_exists('data', $result)) $response['data'] = $result['data'];
            \json_response($response);
        } catch (MiniAdminOperationException $exception) {
            $response = [
                'ok'=>false,
                'code'=>$exception->apiCode(),
                'message'=>$exception->getMessage(),
                'request_id'=>\request_id(),
            ];
            if ($exception->data()) $response['data'] = $exception->data();
            \json_response($response, (int)$exception->getCode());
        } catch (RuntimeException $exception) {
            $status = (int)$exception->getCode();
            if (!in_array($status, [401,403,503], true)) throw $exception;
            $this->fail(
                $exception->getMessage(),
                $status === 503 ? 'MINIAPP_APPID_MISSING' : 'UNAUTHORIZED',
                $status
            );
        }
    }

    private function bearerToken(): string
    {
        $header = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        if ($header === '' && function_exists('getallheaders')) {
            $headers = getallheaders();
            $header = trim((string)($headers['Authorization'] ?? $headers['authorization'] ?? ''));
        }
        if (preg_match('/^Bearer\s+([A-Fa-f0-9]{64})$/', $header, $matches) !== 1) {
            $this->fail('请重新登录', 'UNAUTHORIZED', 401);
        }
        return $matches[1];
    }

    /** @return array<string,mixed> */
    private function jsonBody(): array
    {
        $raw = (string)file_get_contents('php://input');
        if ($raw === '') return [];
        $body = json_decode($raw, true);
        if (!is_array($body)) $this->fail('请求数据格式不正确', 'INVALID_JSON', 422);
        return $body;
    }

    private function noStore(): void
    {
        header('Cache-Control: no-store, private, max-age=0');
        header('Pragma: no-cache');
    }

    private function fail(string $message, string $code, int $status): void
    {
        \json_response(['ok'=>false,'code'=>$code,'message'=>$message,'request_id'=>\request_id()], $status);
    }
}
