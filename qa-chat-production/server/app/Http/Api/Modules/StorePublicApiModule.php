<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use RuntimeException;
use WorkerAdmin\Application\Store\PublicStoreContactLookup;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\ApplicationContainer;
use WorkerAdmin\Http\Api\ApiModule;

final class StorePublicApiModule implements ApiModule
{
    private PublicStoreContactLookup $lookup;

    public function __construct(Database $db) { $this->lookup = (new ApplicationContainer($db))->publicStoreContactLookup(); }

    public function supports(string $route): bool { return $route === 'store.public-phone'; }
    public function requiresContentSecurity(string $route): bool { return false; }

    public function dispatch(string $route): void
    {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') $this->fail('请求方式不正确', 'METHOD_NOT_ALLOWED', 405);
        $authorization = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        if ($authorization === '' && function_exists('getallheaders')) {
            $headers = getallheaders();
            $authorization = trim((string)($headers['Authorization'] ?? $headers['authorization'] ?? ''));
        }
        if (preg_match('/^Bearer\s+([A-Fa-f0-9]{64})$/', $authorization, $matches) !== 1) $this->fail('登录状态已失效，请重新进入', 'UNAUTHORIZED', 401);
        try {
            $data = $this->lookup->lookup($matches[1], (string)($_GET['store_id'] ?? ''));
            \json_response(['ok' => true, 'data' => $data]);
        } catch (RuntimeException $exception) {
            $status = (int)$exception->getCode();
            if (!in_array($status, [401, 422, 500], true)) $status = 500;
            $this->fail($exception->getMessage(), $status === 401 ? 'UNAUTHORIZED' : ($status === 422 ? 'STORE_CONTACT_INVALID' : 'STORE_CONTACT_FAILED'), $status);
        }
    }

    private function fail(string $message, string $code, int $status): void
    {
        \json_response(['ok' => false, 'code' => $code, 'message' => $message, 'request_id' => \request_id()], $status);
    }
}
