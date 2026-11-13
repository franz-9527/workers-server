<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use RuntimeException;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Application\Money\PaymentCreation;
use WorkerAdmin\Application\Identity\ApiActorResolver;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Foundation\ApplicationContainer;
use WorkerAdmin\Http\Api\ApiModule;

/** payments.create 的正式 HTTP 适配；保留支付准入业务码及解禁时间。 */
final class PaymentCreationApiModule implements ApiModule
{
    private PaymentCreation $creation;
    private ApiActorResolver $actors;

    public function __construct(Database $db, ?PaymentCreation $creation = null, ?ApiActorResolver $actors = null)
    {
        $container = new ApplicationContainer($db);
        $this->creation = $creation ?: $container->paymentCreation();
        $this->actors = $actors ?: $container->apiActorResolver();
    }

    public function supports(string $route): bool{return $route === 'payments.create';}
    public function requiresContentSecurity(string $route): bool{return true;}

    public function dispatch(string $route): void
    {
        if (!$this->supports($route)) throw new RuntimeException('接口不存在');
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') $this->fail('请求方式不正确', 'METHOD_NOT_ALLOWED', 405);

        $token = $this->bearerToken();
        try {
            $actor = $this->actors->resolve($token);
        } catch (RuntimeException $exception) {
            $status = (int)$exception->getCode();
            if (!in_array($status, [401,403,503], true)) $status = 401;
            $this->fail($exception->getMessage(), $status === 503 ? 'MINIAPP_APPID_MISSING' : 'UNAUTHORIZED', $status);
            return;
        }
        if (!empty($actor['is_demo_actor'])) $this->fail('演示身份禁止真实支付、提现、实名、云资料或文件上传操作', 'DEMO_EXTERNAL_ACTION_BLOCKED', 403);
        if (empty($actor['payer_openid'])) $this->fail('当前微信身份缺少 OpenID，请重新登录', 'OPENID_REQUIRED', 409);

        $body = $this->jsonBody();
        try {
            $result = $this->creation->create($actor, $body, \request_ip());
        } catch (OrderOperationException $exception) {
            \json_response(['ok'=>false,'code'=>$exception->apiCode(),'message'=>$exception->getMessage(),'data'=>$exception->data(),'request_id'=>\request_id()], (int)$exception->getCode());
            return;
        } catch (RuntimeException $exception) {
            // 普通用户自己下单会先 finalize；只有后台代填普通草稿首次点击预约费时会命中这一准入。
            // 输出稳定业务码，前端不能依赖中文提示文案判断是否需要客户本人确认订单与协议。
            if ($exception->getMessage() === '请先完整提交订单资料和必传图片') {
                $this->fail($exception->getMessage(), 'ORDER_FINALIZE_REQUIRED', 409);
                return;
            }
            throw $exception;
        }
        \json_response(['ok'=>true,'message'=>$result['message'],'data'=>$result['data']]);
    }

    private function bearerToken(): string
    {
        $header = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        if ($header === '' && function_exists('getallheaders')) {
            $headers = getallheaders();
            $header = trim((string)($headers['Authorization'] ?? $headers['authorization'] ?? ''));
        }
        if (preg_match('/^Bearer\s+([A-Fa-f0-9]{64})$/', $header, $matches) !== 1) $this->fail('请重新登录', 'UNAUTHORIZED', 401);
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

    private function fail(string $message, string $code, int $status): void
    {
        \json_response(['ok'=>false,'code'=>$code,'message'=>$message,'request_id'=>\request_id()], $status);
    }
}
