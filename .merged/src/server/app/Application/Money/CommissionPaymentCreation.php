<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Money;

use RuntimeException;
use WorkerAdmin\Contracts\CommissionPaymentCreationRepository;
use WorkerAdmin\Contracts\PaymentGatewayFactory;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Contracts\TransactionManager;
use WorkerAdmin\Domain\Money\CommissionPaymentCreationException;

/** 平台服务费支付创建：服务端金额、活动支付复用、过期重建以及事务后微信预支付。 */
final class CommissionPaymentCreation
{
    private CommissionPaymentCreationRepository $repository;
    private PaymentGatewayFactory $gateways;
    private SettingsProvider $settings;
    private TransactionManager $transactions;

    public function __construct(
        CommissionPaymentCreationRepository $repository,
        PaymentGatewayFactory $gateways,
        SettingsProvider $settings,
        TransactionManager $transactions
    ) {
        $this->repository = $repository;
        $this->gateways = $gateways;
        $this->settings = $settings;
        $this->transactions = $transactions;
    }

    /** @return array{message:?string,data:array<string,mixed>} */
    public function create(array $actor, array $input, string $clientIp): array
    {
        $workerId = (int)($actor['worker_id']??0);
        if ($workerId < 1) {
            throw new CommissionPaymentCreationException('你还不是师傅用户','WORKER_REQUIRED',403);
        }
        $openid = trim((string)($actor['payer_openid']??''));
        if ($openid === '') {
            throw new CommissionPaymentCreationException('请重新登录后支付','OPENID_REQUIRED',409);
        }

        $receivableNo = trim((string)($input['receivable_no']??''));
        $defaultGateway = $this->gateways->defaultGateway();
        $apiVersion = $defaultGateway->apiVersion();
        $appId = trim((string)$this->settings->get('wechat.miniapp_appid',''));
        $merchantId = trim((string)$this->settings->get('wechat.pay.mchid',''));

        $payment = $this->transactions->run(function () use (
            $actor,$receivableNo,$apiVersion,$appId,$merchantId,$clientIp
        ): array {
            return $this->repository->prepare(
                $actor,$receivableNo,$apiVersion,$appId,$merchantId,$clientIp
            );
        });

        if ((string)($payment['status']??'') === 'paid') {
            return [
                'message'=>'服务费已支付',
                'data'=>[
                    'status'=>'paid',
                    'payment_no'=>(string)($payment['public_no']??''),
                ],
            ];
        }

        // 复用中的历史支付单必须按自身保存的 API 版本生成客户端参数。
        $gateway = $this->gateways->forVersion((string)($payment['api_version']??'v3'));
        $prepayId = trim((string)($payment['wechat_prepay_id']??''));
        if ($prepayId !== '') {
            $client = $gateway->clientPaymentParameters($prepayId);
        } else {
            // 微信网络请求明确发生在支付单/commission_payments 事务提交之后。
            $created = $gateway->createJsapiPayment(
                (string)$payment['public_no'],
                (string)$payment['description'],
                (int)$payment['amount_cents'],
                (string)$payment['payer_openid']
            );
            $client = is_array($created['payment']??null) ? $created['payment'] : [];
            $prepayId = trim((string)($created['prepay_id']??''));
            if ($prepayId === '') throw new RuntimeException('微信支付未返回预支付编号');
            $this->repository->markPending((int)$payment['id'],$prepayId);
        }

        return [
            'message'=>null,
            'data'=>[
                'status'=>'pending',
                'payment_no'=>(string)$payment['public_no'],
                'payment'=>$client,
            ],
        ];
    }
}
