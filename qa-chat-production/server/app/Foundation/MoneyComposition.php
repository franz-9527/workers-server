<?php

declare(strict_types=1);

namespace WorkerAdmin\Foundation;

use WorkerAdmin\Application\Money\CommissionPaymentCreation;
use WorkerAdmin\Application\Money\PaymentStatusLookup;
use WorkerAdmin\Application\Money\WalletRechargeCreation;
use WorkerAdmin\Application\Money\WithdrawalCancellation;
use WorkerAdmin\Application\Money\WithdrawalCreation;
use WorkerAdmin\Application\Money\WorkerMoneyOperations;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\External\WeChatPaymentGatewayFactory;
use WorkerAdmin\Infrastructure\Messaging\LegacyWithdrawalSubmittedNotifier;
use WorkerAdmin\Infrastructure\Persistence\DatabaseCommissionPaymentCreationRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabasePaidPaymentSettlement;
use WorkerAdmin\Infrastructure\Persistence\DatabasePaymentStatusRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseWalletRechargeCreationRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseWithdrawalCancellationRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseWithdrawalCreationRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseWorkerMoneyRepository;

/** Money 用户侧统一组合根；只负责对象装配。 */
final class MoneyComposition
{
    /** @return array<string,object> */
    public static function build(Database $db): array
    {
        $container = new ApplicationContainer($db);
        $settings = $container->settings();
        $transactions = $container->transaction();
        $gateways = new WeChatPaymentGatewayFactory();

        return [
            'actors'=>$container->apiActorResolver(),
            'wallet'=>$container->wallet(),
            'wallet_recharge_lifecycle'=>$container->walletRechargeLifecycle(),
            'payment_status'=>new PaymentStatusLookup(
                new DatabasePaymentStatusRepository($db),
                new DatabasePaidPaymentSettlement($db, $settings),
                $gateways,
                $transactions
            ),
            'wallet_recharge_creation'=>new WalletRechargeCreation(
                new DatabaseWalletRechargeCreationRepository($db),
                $gateways,
                $settings,
                $transactions
            ),
            'withdrawal_creation'=>new WithdrawalCreation(
                new DatabaseWithdrawalCreationRepository($db),
                new LegacyWithdrawalSubmittedNotifier($db),
                $settings,
                $transactions
            ),
            'withdrawal_cancellation'=>new WithdrawalCancellation(
                new DatabaseWithdrawalCancellationRepository($db),
                $transactions
            ),
            'commission_payment_creation'=>new CommissionPaymentCreation(
                new DatabaseCommissionPaymentCreationRepository($db),
                $gateways,
                $settings,
                $transactions
            ),
            'worker_money'=>new WorkerMoneyOperations(
                new DatabaseWorkerMoneyRepository($db, $container->privateCipher(), $settings),
                $settings,
                $transactions
            ),
        ];
    }
}
