<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use RuntimeException;
use WorkerAdmin\Application\Identity\RealNameReauthentication;
use WorkerAdmin\Application\Identity\RealNameSubmission;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\ApplicationContainer;
use WorkerAdmin\Http\Api\ApiModule;

/** 实名认证接口：提交、重新认证与次数策略的 HTTP 协议适配。 */
final class RealNameApiModule implements ApiModule
{
    private RealNameReauthentication $reauthentication;
    private RealNameSubmission $submission;

    public function __construct(Database $db)
    {
        $container = new ApplicationContainer($db);
        $this->reauthentication = $container->realNameReauthentication();
        $this->submission = $container->realNameSubmission();
    }

    public function supports(string $route): bool
    {
        return in_array($route, ['finance.realname.submit', 'finance.realname.draft', 'finance.realname.reauth-policy', 'finance.realname.restart'], true);
    }

    public function requiresContentSecurity(string $route): bool
    {
        // 实名资料属于私密认证资料，不进入通用 UGC 内容安全检测；证件真实性仍由 OCR + 人工审核负责。
        return false;
    }

    public function dispatch(string $route): void
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($route === 'finance.realname.reauth-policy' && $method !== 'GET') {
            $this->fail('请求方式不正确', 'METHOD_NOT_ALLOWED', 405);
        }
        if (in_array($route, ['finance.realname.submit', 'finance.realname.draft', 'finance.realname.restart'], true) && $method !== 'POST') {
            $this->fail('请求方式不正确', 'METHOD_NOT_ALLOWED', 405);
        }

        $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Bearer\s+([A-Fa-f0-9]{64})$/', $header, $matches) !== 1) {
            $this->fail('请重新登录', 'UNAUTHORIZED', 401);
        }

        try {
            if ($route === 'finance.realname.reauth-policy') {
                \json_response(['ok' => true, 'data' => $this->reauthentication->policy($matches[1])]);
            }
            if ($route === 'finance.realname.draft') {
                \json_response(['ok' => true, 'data' => $this->submission->saveDraft($matches[1], $this->jsonBody())]);
            }
            if ($route === 'finance.realname.submit') {
                $data = $this->submission->submit($matches[1], $this->jsonBody());
                \json_response([
                    'ok' => true,
                    'message' => !empty($data['already_submitted']) ? '实名认证已提交，正在等待平台审核' : '实名认证已提交，等待平台审核',
                    'data' => $data,
                ]);
            }
            $data = $this->reauthentication->restart($matches[1]);
            \json_response([
                'ok' => true,
                'message' => '已进入重新认证，请重新上传身份证并提交审核',
                'data' => $data,
            ]);
        } catch (\WorkerAdmin\Domain\Order\OrderOperationException $exception) {
            $this->fail($exception->getMessage(), $exception->apiCode(), (int)$exception->getCode());
        } catch (RuntimeException $exception) {
            $status = (int)$exception->getCode();
            if ($status === 503) $this->fail($exception->getMessage(), 'MINIAPP_CONFIG_MISSING', 503);
            if (!in_array($status, [401, 409, 422, 500], true)) $status = 422;
            if ($route === 'finance.realname.submit') {
                $code = $status === 401 ? 'UNAUTHORIZED' : ($status === 409 ? 'REALNAME_SUBMIT_CONFLICT' : ($status === 422 ? 'REALNAME_SUBMIT_INVALID' : 'REALNAME_SUBMIT_FAILED'));
            } else {
                $code = $status === 401 ? 'UNAUTHORIZED' : ($status === 409 ? 'REALNAME_RESTART_CONFLICT' : ($status === 500 ? 'REALNAME_RESTART_FAILED' : 'REALNAME_RESTART_INVALID'));
            }
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
