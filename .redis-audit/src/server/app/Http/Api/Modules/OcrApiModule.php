<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use RuntimeException;
use WorkerAdmin\Application\Identity\OcrResultRecorder;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\ApplicationContainer;
use WorkerAdmin\Http\Api\ApiModule;

/** 微信 OCR HTTP 适配：只处理协议输入输出，业务与持久化下沉 Application/Infrastructure。 */
final class OcrApiModule implements ApiModule
{
    private OcrResultRecorder $recorder;

    public function __construct(Database $db)
    {
        $this->recorder = (new ApplicationContainer($db))->ocrResults();
    }

    public function supports(string $route): bool
    {
        return $route === 'ocr.result.save';
    }

    public function requiresContentSecurity(string $route): bool
    {
        return false;
    }

    public function dispatch(string $route): void
    {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            $this->fail('请求方式不正确', 'METHOD_NOT_ALLOWED', 405);
        }

        $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Bearer\s+([A-Fa-f0-9]{64})$/', $header, $matches) !== 1) {
            $this->fail('请重新登录', 'UNAUTHORIZED', 401);
        }

        try {
            $data = $this->recorder->record($matches[1], $this->jsonBody());
            \json_response([
                'ok' => true,
                'message' => 'OCR 结果已安全保存',
                'data' => $data,
            ]);
        } catch (RuntimeException $exception) {
            $status = (int)$exception->getCode();
            if (!in_array($status, [401, 409, 422, 500], true)) $status = 422;
            $code = $status === 401
                ? 'UNAUTHORIZED'
                : ($status === 409 ? 'OCR_CONFLICT' : ($status === 422 ? 'OCR_INVALID' : 'OCR_SAVE_FAILED'));
            $this->fail($exception->getMessage(), $code, $status);
        }
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
        \json_response([
            'ok' => false,
            'code' => $code,
            'message' => $message,
            'request_id' => \request_id(),
        ], $status);
    }
}
