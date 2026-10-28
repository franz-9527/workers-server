<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use RuntimeException;
use Throwable;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Foundation\OrderApplicationContainer;

trait OrderApiModuleSupport
{
    /** @return array<string,mixed> */
    private function orderActor(OrderApplicationContainer $container): array
    {
        $header = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        if ($header === '' && function_exists('getallheaders')) {
            $headers = getallheaders();
            $header = trim((string)($headers['Authorization'] ?? $headers['authorization'] ?? ''));
        }
        if (preg_match('/^Bearer\s+([A-Fa-f0-9]{64})$/', $header, $matches) !== 1) {
            $this->orderFail('请重新登录', 'UNAUTHORIZED', 401);
        }
        try {
            return $container->actorResolver()->resolve($matches[1]);
        } catch (RuntimeException $exception) {
            $status = (int)$exception->getCode();
            if (!in_array($status, [401, 403, 503], true)) $status = 401;
            $this->orderFail($exception->getMessage(), $status === 503 ? 'MINIAPP_CONFIG_MISSING' : 'UNAUTHORIZED', $status);
        }
        return [];
    }

    /** @return array<string,mixed> */
    private function orderJsonBody(): array
    {
        $raw = (string)file_get_contents('php://input');
        if ($raw === '') return [];
        $body = json_decode($raw, true);
        if (!is_array($body)) $this->orderFail('请求数据格式不正确', 'INVALID_JSON', 422);
        return $body;
    }

    private function orderRequireMethod(string $expected): void
    {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== strtoupper($expected)) {
            $this->orderFail('请求方式不正确', 'METHOD_NOT_ALLOWED', 405);
        }
    }

    private function orderHandleException(Throwable $exception): void
    {
        if ($exception instanceof OrderOperationException) {
            $status = (int)$exception->getCode();
            if ($status < 400 || $status > 599) $status = 422;
            $payload = [
                'ok' => false,
                'code' => $exception->apiCode(),
                'message' => $exception->getMessage(),
                'request_id' => \request_id(),
            ];
            if ($exception->data()) $payload['data'] = $exception->data();
            \json_response($payload, $status);
        }
        if ($exception instanceof RuntimeException) {
            $status = (int)$exception->getCode();
            if ($status < 400 || $status > 599) $status = 422;
            $this->orderFail($exception->getMessage(), 'ORDER_OPERATION_FAILED', $status);
        }
        throw $exception;
    }

    private function orderFail(string $message, string $code, int $status): void
    {
        \json_response([
            'ok' => false,
            'code' => $code,
            'message' => $message,
            'request_id' => \request_id(),
        ], $status);
    }
}
