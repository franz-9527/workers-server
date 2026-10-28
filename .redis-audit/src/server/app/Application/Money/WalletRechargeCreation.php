<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Money;

use Throwable;
use WorkerAdmin\Contracts\PaymentGatewayFactory;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Contracts\TransactionManager;
use WorkerAdmin\Contracts\WalletRechargeCreationRepository;
use WorkerAdmin\Domain\Money\WalletRechargeCreationException;

/** 用户余额充值创建：金额/幂等/频控、业务单与支付单原子创建、事务后微信预支付。 */
final class WalletRechargeCreation
{
    private WalletRechargeCreationRepository $repository;
    private PaymentGatewayFactory $gateways;
    private SettingsProvider $settings;
    private TransactionManager $transactions;

    public function __construct(
        WalletRechargeCreationRepository $repository,
        PaymentGatewayFactory $gateways,
        SettingsProvider $settings,
        TransactionManager $transactions
    ) {
        $this->repository = $repository;
        $this->gateways = $gateways;
        $this->settings = $settings;
        $this->transactions = $transactions;
    }

    /** @return array{message:string,data:array<string,mixed>} */
    public function create(array $actor, array $input, string $clientIp): array
    {
        $userId = (int)($actor['user_id']??0);
        $openid = trim((string)($actor['payer_openid']??''));
        if ($userId < 1 || $openid === '') {
            throw new WalletRechargeCreationException('当前微信身份缺少 OpenID，请重新登录','OPENID_REQUIRED',409);
        }

        $amount = (int)($input['amount_cents']??0);
        $requestId = trim((string)($input['client_request_id']??''));
        $min = max(10,$this->settings->int('wallet.recharge_min_cents',10));
        $max = max($min,$this->settings->int('wallet.recharge_max_cents',500000));
        if ($amount < $min || $amount > $max) {
            throw new WalletRechargeCreationException('充值金额超出允许范围','WALLET_RECHARGE_AMOUNT_INVALID',422);
        }
        if (preg_match('/^[A-Za-z0-9_-]{12,64}$/',$requestId) !== 1) {
            throw new WalletRechargeCreationException('充值请求编号格式不正确','CLIENT_REQUEST_ID_INVALID',422);
        }

        $existing = $this->repository->findRequest($userId,$requestId);
        if ($existing && (int)$existing['amount_cents'] !== $amount) {
            throw new WalletRechargeCreationException('同一充值请求编号不能修改金额','WALLET_RECHARGE_IDEMPOTENCY_CONFLICT',409);
        }
        if (!$existing) {
            $hourlyLimit = max(1,min(50,$this->settings->int('wallet.recharge_hourly_limit',10)));
            if ($this->repository->createdInLastHour($userId) >= $hourlyLimit) {
                throw new WalletRechargeCreationException('充值请求过于频繁，请稍后再试','WALLET_RECHARGE_RATE_LIMIT',429);
            }
        }

        $defaultGateway = $this->gateways->defaultGateway();
        $apiVersion = $defaultGateway->apiVersion();
        $expireMinutes = max(5,min(120,$this->settings->int('wallet.recharge_expire_minutes',30)));
        try {
            $payment = $this->transactions->run(function () use(
                $actor,$amount,$requestId,$apiVersion,$clientIp,$expireMinutes
            ): array {
                return $this->repository->preparePayment(
                    $actor,
                    $amount,
                    $requestId,
                    $apiVersion,
                    trim((string)$this->settings->get('wechat.miniapp_appid','')),
                    trim((string)$this->settings->get('wechat.pay.mchid','')),
                    $clientIp,
                    $expireMinutes
                );
            });
        } catch (Throwable $exception) {
            // 与旧实现一致：唯一键竞争导致后到请求回滚后，重新读取同一请求并复用已完整创建的支付单。
            $concurrent = $this->repository->recoverConcurrentPayment($userId,$requestId);
            if ($concurrent && (int)$concurrent['amount_cents'] === $amount) {
                $payment = $concurrent;
            } else {
                throw new WalletRechargeCreationException(
                    $exception->getMessage(),
                    'WALLET_RECHARGE_CREATE_FAILED',
                    409,
                    $exception
                );
            }
        }

        $status = (string)($payment['status']??'');
        if ($status === 'paid') {
            return [
                'message'=>'充值已到账',
                'data'=>[
                    'payment_no'=>(string)$payment['public_no'],
                    'status'=>'paid',
                ],
            ];
        }
        if (in_array($status,['closed','refunded'],true)) {
            throw new WalletRechargeCreationException(
                '该充值支付单已经关闭，请重新点击充值',
                'WALLET_RECHARGE_CLOSED',
                409
            );
        }

        $gateway = $this->gateways->forVersion((string)($payment['api_version']??'v3'));
        if (!empty($payment['wechat_prepay_id'])) {
            $client = $gateway->clientPaymentParameters((string)$payment['wechat_prepay_id']);
        } else {
            // 外部微信请求明确发生在数据库事务提交之后。
            $created = $gateway->createJsapiPayment(
                (string)$payment['public_no'],
                (string)$payment['description'],
                (int)$payment['amount_cents'],
                (string)$payment['payer_openid']
            );
            $client = is_array($created['payment']??null) ? $created['payment'] : [];
            $this->repository->markPending((int)$payment['id'],(string)($created['prepay_id']??''));
        }

        return [
            'message'=>'微信充值参数已生成',
            'data'=>[
                'payment_no'=>(string)$payment['public_no'],
                'status'=>'pending',
                'payment'=>$client,
            ],
        ];
    }
}
