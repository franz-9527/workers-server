<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Money;

use RuntimeException;
use WorkerAdmin\Contracts\PaymentCreationRepository;
use WorkerAdmin\Contracts\PaymentGateway;
use WorkerAdmin\Contracts\TransactionManager;

/**
 * 用户侧微信支付创建用例。
 *
 * 金额、业务类型和到期时间只来自服务器持久化快照；微信下单发生在数据库事务提交后。
 */
final class PaymentCreation
{
    private PaymentCreationRepository $repository;
    private PaymentGateway $payments;
    private TransactionManager $transaction;

    public function __construct(PaymentCreationRepository $repository, PaymentGateway $payments, TransactionManager $transaction)
    {
        $this->repository = $repository;
        $this->payments = $payments;
        $this->transaction = $transaction;
    }

    /**
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $input
     * @return array{message:string,data:array<string,mixed>}
     */
    public function create(array $actor, array $input, string $clientIp): array
    {
        $openid = trim((string)($actor['payer_openid'] ?? ''));
        if ($openid === '') throw new RuntimeException('当前微信身份缺少 OpenID，请重新登录', 409);

        $apiVersion = $this->payments->apiVersion();
        $payment = $this->transaction->run(function () use ($actor, $input, $apiVersion, $clientIp): array {
            return $this->repository->prepare($actor, $input, $apiVersion, $clientIp);
        });

        if ((int)($payment['amount_cents'] ?? 0) === 0 && (string)($payment['status'] ?? '') === 'paid') {
            return [
                'message'=>'无需补款，师傅已自动接单',
                'data'=>['payment_no'=>'','status'=>'paid'],
            ];
        }

        if ((string)($payment['status'] ?? '') === 'paid') {
            $messages = [
                'run_empty_fee'=>'跑空费已支付',
                'measurement_balance'=>'测量服务款已支付',
                'measurement_change'=>'测量加费已支付',
            ];
            $type = (string)($payment['business_type'] ?? '');
            return [
                'message'=>$messages[$type] ?? '预约费已支付',
                'data'=>['payment_no'=>(string)($payment['public_no'] ?? ''),'status'=>'paid'],
            ];
        }

        $prepayId = trim((string)($payment['wechat_prepay_id'] ?? ''));
        if ($prepayId !== '') {
            $client = $this->payments->clientPaymentParameters($prepayId);
        } else {
            $created = $this->payments->createJsapiPayment(
                (string)$payment['public_no'],
                (string)$payment['description'],
                (int)$payment['amount_cents'],
                (string)$payment['payer_openid']
            );
            $client = is_array($created['payment'] ?? null) ? $created['payment'] : [];
            $prepayId = trim((string)($created['prepay_id'] ?? ''));
            if ($prepayId === '') throw new RuntimeException('微信支付未返回预支付编号');
            $this->repository->markPending((int)$payment['id'], $prepayId);
        }

        return [
            'message'=>'微信支付参数已生成',
            'data'=>[
                'payment_no'=>(string)$payment['public_no'],
                'status'=>'pending',
                'payment'=>$client,
            ],
        ];
    }
}
