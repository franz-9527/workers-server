<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\AdminFinanceRepository;
use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Core\Database;

final class DatabaseAdminFinanceRepository implements AdminFinanceRepository
{
    private DatabaseAdminFinanceReadStore $read;
    private DatabaseAdminFinanceWriteStore $write;

    public function __construct(Database $db, PrivateValueCipher $cipher)
    {
        $this->read=new DatabaseAdminFinanceReadStore($db,$cipher);
        $this->write=new DatabaseAdminFinanceWriteStore($db);
    }

    public function guardDemoWrite(string $route,int $id): void { $this->write->guardDemoWrite($route,$id); }
    public function resourcePage(string $resource,int $page,string $status,string $search,string $businessType,bool $includeDemo): array
    { return $this->read->resourcePage($resource,$page,$status,$search,$businessType,$includeDemo); }
    public function paymentStats(bool $includeDemo): array { return $this->read->paymentStats($includeDemo); }
    public function wallets(int $page,int $walletId,string $search,bool $includeDemo): array { return $this->read->wallets($page,$walletId,$search,$includeDemo); }
    public function tailSettlement(string $search,bool $includeDemo): array { return $this->read->tailSettlement($search,$includeDemo); }
    public function walletRechargeSnapshot(int $rechargeId): ?array { return $this->write->walletRechargeSnapshot($rechargeId); }
    public function lockWalletRecharge(int $rechargeId): ?array { return $this->write->lockWalletRecharge($rechargeId); }
    public function lockPayment(int $paymentId): ?array { return $this->write->lockPayment($paymentId); }
    public function activeRefundForPayment(int $paymentId): ?array { return $this->write->activeRefundForPayment($paymentId); }
    public function markRechargeRefunded(int $rechargeId): void { $this->write->markRechargeRefunded($rechargeId); }
    public function markRechargeRefundProcessing(int $rechargeId): void { $this->write->markRechargeRefundProcessing($rechargeId); }
    public function createRechargeRefund(int $paymentId,int $amountCents,string $reason,int $adminId): array { return $this->write->createRechargeRefund($paymentId,$amountCents,$reason,$adminId); }
    public function lockRefund(int $refundId): ?array { return $this->write->lockRefund($refundId); }
    public function updateRefundAmount(int $refundId,int $amountCents): void { $this->write->updateRefundAmount($refundId,$amountCents); }
    public function bookingFeeAllocations(int $paymentId,int $orderId,int $excludeRefundId): array { return $this->write->bookingFeeAllocations($paymentId,$orderId,$excludeRefundId); }
    public function approveRefundForSubmission(array $refund,array $payment,int $adminId): void { $this->write->approveRefundForSubmission($refund,$payment,$adminId); }
    public function markRefundSubmitted(int $refundId,?int $orderId,string $wechatRefundId): void { $this->write->markRefundSubmitted($refundId,$orderId,$wechatRefundId); }
    public function markRefundSubmitFailed(int $refundId,string $message): void { $this->write->markRefundSubmitFailed($refundId,$message); }
    public function markRechargeRefundSubmitted(int $refundId,string $wechatRefundId): void { $this->write->markRechargeRefundSubmitted($refundId,$wechatRefundId); }
    public function markRechargeRefundUncertain(int $refundId,string $message): void { $this->write->markRechargeRefundUncertain($refundId,$message); }
    public function transitionWithdrawalLocked(int $id,string $target,string $externalTradeNo,string $note,int $adminId): array { return $this->write->transitionWithdrawalLocked($id,$target,$externalTradeNo,$note,$adminId); }
    public function markTailBadDebtLocked(int $id,string $note,int $adminId): array { return $this->write->markTailBadDebtLocked($id,$note,$adminId); }
    public function reviewTailSettlementLocked(int $id,string $target,string $note,int $adminId): array { return $this->write->reviewTailSettlementLocked($id,$target,$note,$adminId); }
}
