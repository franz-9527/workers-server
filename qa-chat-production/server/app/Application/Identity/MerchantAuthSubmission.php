<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Identity;

use RuntimeException;
use WorkerAdmin\Contracts\MerchantAuthSubmissionNotifier;
use WorkerAdmin\Contracts\MerchantAuthSubmissionRepository;
use WorkerAdmin\Contracts\TransactionManager;

/** 商家认证提交：核心状态写入与管理员通知解耦，重复提交保持幂等。 */
final class MerchantAuthSubmission
{
    private MerchantAuthSubmissionRepository $repository;
    private MerchantAuthSubmissionNotifier $notifier;
    private TransactionManager $transaction;

    public function __construct(
        MerchantAuthSubmissionRepository $repository,
        MerchantAuthSubmissionNotifier $notifier,
        TransactionManager $transaction
    ) {
        $this->repository = $repository;
        $this->notifier = $notifier;
        $this->transaction = $transaction;
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function submit(string $token, array $body): array
    {
        if (preg_match('/^[A-Fa-f0-9]{64}$/', $token) !== 1) throw new RuntimeException('请重新登录', 401);
        $actor = $this->repository->findActorByTokenHash(hash('sha256', $token));
        if (!$actor) throw new RuntimeException('登录已过期，请重新进入小程序', 401);
        DemoActorGuard::requireExternalAction($actor);
        $this->repository->touchSession((int)$actor['session_id']);

        $name = mb_substr(trim((string)($body['business_name'] ?? '')), 0, 160);
        $address = mb_substr(trim((string)($body['business_address'] ?? '')), 0, 500);
        if ($name === '' || $address === '') throw new RuntimeException('请核对商家名称和经营地址', 422);

        $result = $this->transaction->run(function () use ($actor, $name, $address): array {
            $profile = $this->repository->findProfileForUpdate((int)$actor['user_id']);
            if (!$profile || empty($profile['license_media_file_id'])) {
                throw new RuntimeException('请先上传营业执照', 422);
            }
            $status = (string)($profile['verification_status'] ?? 'draft');
            if ($status === 'approved') {
                throw new RuntimeException('商家认证已通过，如需变更请联系平台', 409);
            }
            if ($status === 'pending') {
                $sameName = trim((string)($profile['business_name'] ?? '')) === $name;
                $sameAddress = trim((string)($profile['business_address'] ?? '')) === $address;
                if (!$sameName || !$sameAddress) throw new RuntimeException('商家认证正在审核中，暂不能修改资料', 409);
                return ['merchant_id' => (int)$profile['id'], 'status' => 'pending', 'notify' => false, 'already_submitted' => true];
            }

            $this->repository->markPending((int)$profile['id'], $name, $address);
            return ['merchant_id' => (int)$profile['id'], 'status' => 'pending', 'notify' => true, 'already_submitted' => false];
        });

        if (!empty($result['notify'])) {
            // 通知失败不能反向把已经成功提交的认证判为失败；Infrastructure 适配器负责记录异常。
            $this->notifier->notifySubmitted((int)$result['merchant_id'], $name, $address);
        }
        unset($result['notify']);
        return $result;
    }
}
