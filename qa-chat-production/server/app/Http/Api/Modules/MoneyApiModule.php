<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;
use WorkerAdmin\Application\Identity\ApiActorResolver;
use WorkerAdmin\Application\Money\CommissionPaymentCreation;
use WorkerAdmin\Application\Money\PaymentStatusLookup;
use WorkerAdmin\Application\Money\UserWalletManager;
use WorkerAdmin\Application\Money\WalletRechargeCreation;
use WorkerAdmin\Application\Money\WalletRechargeLifecycle;
use WorkerAdmin\Application\Money\WithdrawalCancellation;
use WorkerAdmin\Application\Money\WithdrawalCreation;
use WorkerAdmin\Application\Money\WorkerMoneyOperations;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Money\CommissionPaymentCreationException;
use WorkerAdmin\Domain\Money\MoneyOperationException;
use WorkerAdmin\Domain\Money\WalletRechargeCreationException;
use WorkerAdmin\Domain\Money\WithdrawalCancellationException;
use WorkerAdmin\Domain\Money\WithdrawalCreationException;
use WorkerAdmin\Foundation\MoneyComposition;
use WorkerAdmin\Http\Api\ApiModule;

/**
 * Money 用户侧统一 HTTP 边界。
 *
 * 路由集中，资金状态机仍按 Application 用例拆分；避免继续“一条 route 一个 Module/Composition”。
 */
final class MoneyApiModule implements ApiModule
{
    private ApiActorResolver $actors;
    private UserWalletManager $wallet;
    private WalletRechargeLifecycle $walletRechargeLifecycle;
    private PaymentStatusLookup $paymentStatus;
    private WalletRechargeCreation $walletRechargeCreation;
    private WithdrawalCreation $withdrawalCreation;
    private WithdrawalCancellation $withdrawalCancellation;
    private CommissionPaymentCreation $commissionPaymentCreation;
    private WorkerMoneyOperations $workerMoney;

    /** @var array<string,string> */
    private const ROUTES = [
        'payments.status'=>'GET',
        'wallet.summary'=>'GET',
        'wallet.ledger'=>'GET',
        'wallet.recharge.create'=>'POST',
        'wallet.recharge.cancel'=>'POST',
        'finance.summary'=>'GET',
        'finance.account.save'=>'POST',
        'finance.withdraw'=>'POST',
        'finance.withdraw.cancel'=>'POST',
        'commission.payment.create'=>'POST',
        'offline.delay.create'=>'POST',
        'offline.collection.adjustment.submit'=>'POST',
    ];

    /** @var string[] */
    private const DEMO_EXTERNAL_BLOCKED = [
        'payments.status',
        'wallet.recharge.create',
        'finance.account.save',
        'finance.withdraw',
        'finance.withdraw.cancel',
        'commission.payment.create',
    ];

    public function __construct(Database $db)
    {
        $services = MoneyComposition::build($db);
        $this->actors = $services['actors'];
        $this->wallet = $services['wallet'];
        $this->walletRechargeLifecycle = $services['wallet_recharge_lifecycle'];
        $this->paymentStatus = $services['payment_status'];
        $this->walletRechargeCreation = $services['wallet_recharge_creation'];
        $this->withdrawalCreation = $services['withdrawal_creation'];
        $this->withdrawalCancellation = $services['withdrawal_cancellation'];
        $this->commissionPaymentCreation = $services['commission_payment_creation'];
        $this->workerMoney = $services['worker_money'];
    }

    public function supports(string $route): bool
    {
        return isset(self::ROUTES[$route]);
    }

    public function requiresContentSecurity(string $route): bool
    {
        // 既有 wallet.recharge.cancel 正式模块不走内容安全；其余 Money 入口保持原 Legacy/迁移模块策略。
        return $route !== 'wallet.recharge.cancel';
    }

    public function dispatch(string $route): void
    {
        if (!$this->supports($route)) throw new RuntimeException('接口不存在');
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== self::ROUTES[$route]) {
            $this->fail('请求方式不正确', 'METHOD_NOT_ALLOWED', 405);
        }

        $token = $this->bearerToken();
        if ($route === 'wallet.recharge.cancel') {
            $this->cancelWalletRecharge($token);
            return;
        }

        $actor = $this->resolveActor($token);
        if (!empty($actor['is_demo_actor']) && in_array($route, self::DEMO_EXTERNAL_BLOCKED, true)) {
            $this->fail(
                '演示身份禁止真实支付、提现、实名、云资料或文件上传操作',
                'DEMO_EXTERNAL_ACTION_BLOCKED',
                403
            );
        }

        try {
            switch ($route) {
                case 'payments.status':
                    $this->paymentStatus($actor);
                    return;
                case 'wallet.summary':
                    $this->walletSummary($actor);
                    return;
                case 'wallet.ledger':
                    $this->walletLedger($actor);
                    return;
                case 'wallet.recharge.create':
                    $this->createWalletRecharge($actor);
                    return;
                case 'finance.summary':
                    \json_response(['ok'=>true,'data'=>$this->workerMoney->summary($actor, $_GET)]);
                    return;
                case 'finance.account.save':
                    $result = $this->workerMoney->savePayoutAccount($actor, $this->jsonBody());
                    \json_response(['ok'=>true,'message'=>$result['message']]);
                    return;
                case 'finance.withdraw':
                    $result = $this->withdrawalCreation->create($actor, $this->jsonBody());
                    \json_response(['ok'=>true,'message'=>$result['message'],'data'=>$result['data']]);
                    return;
                case 'finance.withdraw.cancel':
                    $result = $this->withdrawalCancellation->cancel($actor, $this->jsonBody());
                    \json_response(['ok'=>true,'message'=>$result['message']]);
                    return;
                case 'commission.payment.create':
                    $this->createCommissionPayment($actor);
                    return;
                case 'offline.delay.create':
                    $result = $this->workerMoney->createOfflineDelay($actor, $this->jsonBody());
                    \json_response(['ok'=>true,'message'=>$result['message']]);
                    return;
                case 'offline.collection.adjustment.submit':
                    $result = $this->workerMoney->submitOfflineAdjustment($actor, $this->jsonBody());
                    \json_response(['ok'=>true,'message'=>$result['message'],'data'=>$result['data']]);
                    return;
            }
        } catch (MoneyOperationException $exception) {
            $this->fail($exception->getMessage(), $exception->apiCode(), (int)$exception->getCode());
        } catch (WalletRechargeCreationException $exception) {
            $this->fail($exception->getMessage(), $exception->apiCode(), (int)$exception->getCode());
        } catch (WithdrawalCreationException $exception) {
            $this->fail($exception->getMessage(), $exception->apiCode(), (int)$exception->getCode());
        } catch (WithdrawalCancellationException $exception) {
            $this->fail($exception->getMessage(), $exception->apiCode(), (int)$exception->getCode());
        } catch (CommissionPaymentCreationException $exception) {
            $this->fail($exception->getMessage(), $exception->apiCode(), (int)$exception->getCode());
        }
    }

    private function paymentStatus(array $actor): void
    {
        try {
            $result = $this->paymentStatus->lookup($actor, (string)($_GET['payment_no'] ?? ''));
        } catch (RuntimeException $exception) {
            if ((int)$exception->getCode() === 404) {
                $this->fail($exception->getMessage(), 'PAYMENT_NOT_FOUND', 404);
            }
            throw $exception;
        }
        if (!empty($result['query_error'])) {
            \write_log('payment-query', '[' . date('c') . '] ' . (string)$result['payment_no'] . ' ' . (string)$result['query_error'] . "\n");
        }
        if (!empty($result['auto_refund_error'])) {
            \write_log('auto-refund', '[' . date('c') . '] ' . (string)$result['payment_no'] . ' ' . (string)$result['auto_refund_error'] . "\n");
        }
        \json_response(['ok'=>true,'data'=>[
            'payment_no'=>(string)$result['payment_no'],
            'status'=>(string)$result['status'],
            'paid_at'=>$this->localTime($result['paid_at'] ?? null),
        ]]);
    }

    private function walletSummary(array $actor): void
    {
        if (!empty($actor['is_demo_actor'])) {
            \json_response(['ok'=>true,'data'=>[
                'balance_cents'=>0,'balance_text'=>'¥0.00','reserved_cents'=>0,'reserved_text'=>'¥0.00',
                'can_withdraw'=>false,'withdraw_notice'=>'演示身份不产生真实充值或余额消费',
                'recharge_min_cents'=>10,'recharge_max_cents'=>500000,'demo'=>true,
            ]]);
        }
        \json_response(['ok'=>true,'data'=>$this->wallet->summary((int)$actor['user_id'])]);
    }

    private function walletLedger(array $actor): void
    {
        if (!empty($actor['is_demo_actor'])) {
            \json_response(['ok'=>true,'data'=>['items'=>[],'page'=>1,'has_more'=>false,'total'=>0,'demo'=>true]]);
        }
        $page = max(1, (int)($_GET['page'] ?? 1));
        \json_response(['ok'=>true,'data'=>$this->wallet->ledger((int)$actor['user_id'], $page, 20)]);
    }

    private function createWalletRecharge(array $actor): void
    {
        $result = $this->walletRechargeCreation->create($actor, $this->jsonBody(), \request_ip());
        if ((string)($result['data']['status'] ?? '') === 'paid') {
            $result['data']['wallet'] = $this->wallet->summary((int)$actor['user_id']);
        }
        \json_response(['ok'=>true,'message'=>$result['message'],'data'=>$result['data']]);
    }

    private function cancelWalletRecharge(string $token): void
    {
        $body = $this->jsonBody();
        try {
            $data = $this->walletRechargeLifecycle->cancelByUser($token, (string)($body['payment_no'] ?? ''));
            \json_response(['ok'=>true,'message'=>'充值支付状态已更新','data'=>$data]);
        } catch (RuntimeException $exception) {
            $status = (int)$exception->getCode();
            if (!in_array($status, [401,404,422], true)) $status = 422;
            $this->fail(
                $exception->getMessage(),
                $status === 401 ? 'UNAUTHORIZED' : ($status === 404 ? 'PAYMENT_NOT_FOUND' : 'WALLET_RECHARGE_INVALID'),
                $status
            );
        }
    }

    private function createCommissionPayment(array $actor): void
    {
        $result = $this->commissionPaymentCreation->create($actor, $this->jsonBody(), \request_ip());
        $response = ['ok'=>true,'data'=>$result['data']];
        if ($result['message'] !== null) $response['message'] = $result['message'];
        \json_response($response);
    }

    private function resolveActor(string $token): array
    {
        try {
            return $this->actors->resolve($token);
        } catch (RuntimeException $exception) {
            $status = (int)$exception->getCode();
            if (!in_array($status, [401,403,503], true)) $status = 401;
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

    private function localTime($value): ?string
    {
        if (!$value) return null;
        try {
            return (new DateTimeImmutable((string)$value, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone((string)\app_config('app.timezone', 'Asia/Shanghai')))
                ->format('Y-m-d H:i');
        } catch (Throwable $exception) {
            return (string)$value;
        }
    }

    private function fail(string $message, string $code, int $status): void
    {
        \json_response(['ok'=>false,'code'=>$code,'message'=>$message,'request_id'=>\request_id()], $status);
    }
}
