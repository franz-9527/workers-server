<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Identity;

use RuntimeException;
use WorkerAdmin\Contracts\RealNameSubmissionNotifier;
use WorkerAdmin\Contracts\RealNameSubmissionRepository;
use WorkerAdmin\Contracts\TransactionManager;

/** 实名认证提交：核心状态写入与管理员通知解耦，重复提交保持幂等。 */
final class RealNameSubmission
{
    private RealNameSubmissionRepository $repository;
    private RealNameSubmissionNotifier $notifier;
    private TransactionManager $transaction;

    public function __construct(
        RealNameSubmissionRepository $repository,
        RealNameSubmissionNotifier $notifier,
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
        if (empty($actor['worker_id'])) throw new RuntimeException('请先完成师傅身份建立', 422);

        $name = mb_substr(trim((string)($body['real_name'] ?? '')), 0, 80);
        $idNumber = strtoupper(preg_replace('/\s+/', '', (string)($body['id_number'] ?? '')) ?: '');
        if (mb_strlen($name) < 2 || preg_match('/^\d{17}[0-9X]$/', $idNumber) !== 1) {
            throw new RuntimeException('请填写真实姓名和有效的 18 位身份证号', 422);
        }

        $result = $this->transaction->run(function () use ($actor, $name, $idNumber): array {
            $verification = $this->repository->findVerificationForUpdate((int)$actor['worker_id']);
            if (!$verification || !$this->repository->hasCurrentIdMedia((int)$verification['id'])) {
                throw new RuntimeException('请先上传身份证人像面和国徽面', 422);
            }
            $status = (string)($verification['status'] ?? 'draft');
            if ($status === 'approved') throw new RuntimeException('实名认证已通过', 409);
            if ($status === 'pending') {
                $sameName = (string)($verification['real_name'] ?? '') === $name;
                $sameId = strtoupper((string)($verification['id_number'] ?? '')) === $idNumber;
                if (!$sameName || !$sameId) throw new RuntimeException('实名认证正在审核中，暂不能修改资料', 409);
                return [
                    'verification_id' => (int)$verification['id'],
                    'worker_id' => (int)$actor['worker_id'],
                    'status' => 'pending',
                    'notify' => false,
                    'already_submitted' => true,
                ];
            }

            $this->repository->markPending((int)$verification['id'], $name, $idNumber);
            return [
                'verification_id' => (int)$verification['id'],
                'worker_id' => (int)$actor['worker_id'],
                'status' => 'pending',
                'notify' => true,
                'already_submitted' => false,
            ];
        });

        if (!empty($result['notify'])) {
            $this->notifier->notifySubmitted((int)$result['verification_id'], (int)$result['worker_id'], $name);
        }
        unset($result['notify']);
        return $result;
    }
}
