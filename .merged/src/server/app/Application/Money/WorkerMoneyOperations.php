<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Money;

use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Contracts\TransactionManager;
use WorkerAdmin\Contracts\WorkerMoneyRepository;
use WorkerAdmin\Domain\Money\MoneyOperationException;

/**
 * 师傅侧 Money 用例族。
 *
 * 同一板块集中承接：财务总览、支付宝收款账号、线下尾款延期、实际尾款调整。
 * 具体 SQL/锁/事件/outbox 均下沉 Repository；Application 只做输入、稳定错误码和事务编排。
 */
final class WorkerMoneyOperations
{
    private WorkerMoneyRepository $repository;
    private SettingsProvider $settings;
    private TransactionManager $transactions;

    public function __construct(
        WorkerMoneyRepository $repository,
        SettingsProvider $settings,
        TransactionManager $transactions
    ) {
        $this->repository = $repository;
        $this->settings = $settings;
        $this->transactions = $transactions;
    }

    /** @return array<string,mixed> */
    public function summary(array $actor, array $query): array
    {
        $this->workerId($actor);
        $requestedSection = (string)($query['section'] ?? 'all');
        $section = in_array($requestedSection, ['all','receivables','balance','withdrawals'], true)
            ? $requestedSection
            : 'all';
        $page = max(1, (int)($query['page'] ?? 1));

        $data = $this->repository->financeSummary($actor, $section, $page, 10);
        $data['real_name_watermark_text'] = (string)$this->settings->get(
            'order.id_watermark_text',
            '仅限办理壁选业务使用'
        );
        $data['minimum_withdraw_cents'] = (int)$this->settings->get('withdrawal.minimum_cents', 100);
        return $data;
    }

    /** @return array{message:string} */
    public function savePayoutAccount(array $actor, array $input): array
    {
        $workerId = $this->workerId($actor);
        $name = mb_substr(trim((string)($input['holder_name'] ?? '')), 0, 80);
        $account = preg_replace('/\s+/', '', (string)($input['account'] ?? ''));
        if ($name === '' || preg_match('/^1\d{10}$/', $account) !== 1) {
            throw new MoneyOperationException('请填写支付宝实名姓名和 11 位手机号', 'INVALID_PAYOUT_ACCOUNT', 422);
        }

        $this->transactions->run(function () use ($workerId, $name, $account): void {
            $this->repository->savePayoutAccount($workerId, $name, $account);
        });
        return ['message'=>'支付宝收款账号已保存'];
    }

    /** @return array{message:string} */
    public function createOfflineDelay(array $actor, array $input): array
    {
        $this->workerId($actor);
        $order = $this->order((string)($input['order_id'] ?? ''));
        $reason = mb_substr(trim((string)($input['reason'] ?? '')), 0, 1000);
        if (mb_strlen($reason) < 4) {
            throw new MoneyOperationException('请说明尾款延期原因', 'INVALID_REASON', 422);
        }
        $expected = trim((string)($input['expected_collect_at'] ?? ''));
        if ($expected !== '' && strtotime($expected) === false) {
            throw new MoneyOperationException('预计收款时间格式不正确', 'INVALID_DATE', 422);
        }

        $this->transactions->run(function () use ($actor, $order, $reason, $expected): void {
            $this->repository->createOfflineDelay($actor, $order, $reason, $expected);
        });
        return ['message'=>'尾款延期已登记，提交即代表平台已知悉，正常收款无需再次操作'];
    }

    /** @return array{message:string,data:array{id:string}} */
    public function submitOfflineAdjustment(array $actor, array $input): array
    {
        $this->workerId($actor);
        $order = $this->order((string)($input['order_id'] ?? ''));
        $requestedTail = (int)($input['requested_tail_cents'] ?? -1);
        $reason = mb_substr(trim((string)($input['reason'] ?? '')), 0, 500);
        if ($requestedTail < 0 || $requestedTail > 100000000) {
            throw new MoneyOperationException('实际尾款金额不正确', 'INVALID_TAIL_AMOUNT', 422);
        }
        if (mb_strlen($reason) < 4) {
            throw new MoneyOperationException('请说明实际金额发生变化的原因', 'INVALID_REASON', 422);
        }

        $publicId = $this->transactions->run(function () use ($actor, $order, $requestedTail, $reason): string {
            return $this->repository->submitOfflineAdjustment($actor, $order, $requestedTail, $reason);
        });
        return [
            'message'=>'实际尾款已自动预审生效，订单和平台服务费已重新计算',
            'data'=>['id'=>$publicId],
        ];
    }

    private function workerId(array $actor): int
    {
        $workerId = (int)($actor['worker_id'] ?? 0);
        if ($workerId < 1) {
            throw new MoneyOperationException('你还不是师傅用户', 'WORKER_REQUIRED', 403);
        }
        return $workerId;
    }

    /** @return array<string,mixed> */
    private function order(string $publicNo): array
    {
        $order = $this->repository->findOrderByPublicNo(trim($publicNo));
        if (!$order) {
            throw new MoneyOperationException('订单不存在', 'ORDER_NOT_FOUND', 404);
        }
        return $order;
    }
}
