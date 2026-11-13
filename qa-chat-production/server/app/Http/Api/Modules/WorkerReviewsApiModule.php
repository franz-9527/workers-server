<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use RuntimeException;
use WorkerAdmin\Application\Review\ReviewManager;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\ApplicationContainer;
use WorkerAdmin\Http\Api\ApiModule;

/** 评价 HTTP 适配器：只处理协议与状态码，业务规则和 SQL 分别位于 Application / Infrastructure。 */
final class WorkerReviewsApiModule implements ApiModule
{
    private ReviewManager $reviews;

    public function __construct(Database $db)
    {
        $this->reviews = (new ApplicationContainer($db))->reviews();
    }

    public function supports(string $route): bool
    {
        return in_array($route, ['workers.reviews','reviews.templates','reviews.create'], true);
    }

    public function requiresContentSecurity(string $route): bool
    {
        return $route === 'reviews.create';
    }

    public function dispatch(string $route): void
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        try {
            if ($route === 'workers.reviews') {
                if ($method !== 'GET') $this->fail('请求方式不正确', 'METHOD_NOT_ALLOWED', 405);
                $data = $this->reviews->publicWorkerPage((string)($_GET['id'] ?? ''), (int)($_GET['page'] ?? 1), (int)($_GET['limit'] ?? 10));
                \json_response(['ok'=>true,'data'=>$data]);
            }
            if ($route === 'reviews.templates') {
                if ($method !== 'GET') $this->fail('请求方式不正确', 'METHOD_NOT_ALLOWED', 405);
                \json_response(['ok'=>true,'data'=>['items'=>$this->reviews->quickPhrases()]]);
            }
            if ($method !== 'POST') $this->fail('请求方式不正确', 'METHOD_NOT_ALLOWED', 405);
            $token = $this->bearerToken();
            $data = $this->reviews->submit($token, $this->jsonBody());
            \json_response(['ok'=>true,'message'=>'评价已提交','data'=>$data]);
        } catch (RuntimeException $exception) {
            $status = (int)$exception->getCode();
            if (!in_array($status, [401,403,404,405,409,422], true)) $status = 422;
            $code = $status === 401 ? 'UNAUTHORIZED' : ($status === 403 ? 'FORBIDDEN' : ($status === 404 ? 'NOT_FOUND' : ($status === 409 ? 'REVIEW_CONFLICT' : ($status === 405 ? 'METHOD_NOT_ALLOWED' : 'REVIEW_INVALID'))));
            $this->fail($exception->getMessage(), $code, $status);
        }
    }

    private function bearerToken(): string
    {
        $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Bearer\s+([A-Fa-f0-9]{64})$/', $header, $matches) !== 1) $this->fail('请重新登录', 'UNAUTHORIZED', 401);
        return $matches[1];
    }

    /** @return array<string,mixed> */
    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        $body = json_decode(is_string($raw) ? $raw : '', true);
        if (!is_array($body)) $this->fail('请求数据格式不正确', 'INVALID_JSON', 422);
        return $body;
    }

    private function fail(string $message, string $code, int $status): void
    {
        \json_response(['ok'=>false,'code'=>$code,'message'=>$message,'request_id'=>\request_id()], $status);
    }
}
