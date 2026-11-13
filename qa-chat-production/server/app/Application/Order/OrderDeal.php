<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Order;

use RuntimeException;
use WorkerAdmin\Contracts\OrderDealRepository;
use WorkerAdmin\Contracts\WorkerAgreementVersionProvider;
use WorkerAdmin\Domain\Order\OrderOperationException;

/**
 * V2.5.0 报价 / 抢单 / 指派统一成交用例。
 *
 * 普通订单与 platform 来源只在金额语义和收费规则上分支，不再由两个 Http 模块互相转发。
 * quotes.select / quotes.switch 在下一批连同付款预占一起迁入后再加入这里。
 */
final class OrderDeal
{
    /** @var string[] */
    private const ROUTES = [
        'quotes.create',
        'orders.grab',
        'orders.grab.quote',
        'assignments.accept',
        'assignments.reject',
    ];

    private OrderDealRepository $repository;
    private WorkerAgreementVersionProvider $agreements;

    public function __construct(OrderDealRepository $repository, WorkerAgreementVersionProvider $agreements)
    {
        $this->repository = $repository;
        $this->agreements = $agreements;
    }

    /**
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $body
     * @return array{message:string,data:array<string,mixed>}
     */
    public function execute(string $route, array $actor, array $body): array
    {
        if (!in_array($route, self::ROUTES, true)) {
            throw new OrderOperationException('成交操作不存在', 'ORDER_DEAL_ROUTE_NOT_FOUND', 404);
        }
        $context = $this->repository->context($route, $actor, $body);
        $agreementVersions = [];
        if ($this->requiresWorkerAgreement($route, $body, $context)) {
            if (empty($body['rule_acknowledged'])) {
                throw new OrderOperationException(
                    '请先确认平台规则、师傅合作协议和本单收费规则',
                    'RULE_ACKNOWLEDGEMENT_REQUIRED',
                    422
                );
            }
            $agreementVersions = $this->normalizeVersions($body['agreement_versions'] ?? null);
            if (!$agreementVersions) {
                throw new OrderOperationException(
                    '协议版本信息缺失，请重新打开订单详情确认最新规则后再操作',
                    'AGREEMENT_VERSION_REQUIRED',
                    422
                );
            }
            $check = $this->agreements->validateVersions($agreementVersions);
            if (empty($check['ok'])) {
                throw new OrderOperationException(
                    '平台规则或师傅合作协议已更新，请重新打开订单详情确认后再操作',
                    'AGREEMENT_VERSION_CHANGED',
                    409,
                    ['missing'=>$check['missing'] ?? [], 'outdated'=>$check['outdated'] ?? []]
                );
            }
        }
        try {
            return $this->repository->execute($route, $actor, $body, $context, $agreementVersions);
        } catch (RuntimeException $exception) {
            if ($route === 'orders.grab' && in_array($exception->getMessage(), [
                '该订单已被其他师傅抢走或状态已变化',
                '订单已被其他师傅抢到或状态已变化',
            ], true)) {
                throw new OrderOperationException('该订单已被其他师傅抢走，请刷新查看', 'ORDER_ALREADY_GRABBED', 409);
            }
            throw $exception;
        }
    }

    /** @param array<string,mixed> $body @param array<string,mixed> $context */
    private function requiresWorkerAgreement(string $route, array $body, array $context): bool
    {
        if (in_array($route, ['quotes.create','orders.grab.quote'], true)) return true;
        if ($route === 'orders.grab') {
            if ((string)($context['order_source_type'] ?? '') === 'platform') return true;
            return !empty($context['fixed_grab']);
        }
        if ($route === 'assignments.accept') {
            // 主动报价已经在报价时确认过规则；平台派单/邀请才需要接单时再次确认本单规则。
            return (string)($context['assignment_type'] ?? '') !== 'worker_quote';
        }
        return false;
    }

    /** @param mixed $source @return array<string,string> */
    private function normalizeVersions($source): array
    {
        if (!is_array($source)) return [];
        $versions = [];
        foreach ($source as $key=>$value) {
            if (!is_string($key) || !is_scalar($value)) continue;
            $key = trim($key);
            $value = trim((string)$value);
            if ($key !== '' && $value !== '') $versions[$key] = $value;
        }
        return $versions;
    }
}
