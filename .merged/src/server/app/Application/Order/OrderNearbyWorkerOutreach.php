<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Order;

use WorkerAdmin\Application\Identity\DemoActorGuard;
use WorkerAdmin\Contracts\OrderNearbyWorkerRepository;
use WorkerAdmin\Domain\Order\OrderOperationException;

/** V2.5.0 订单报价阶段的附近师傅候选与付费短信触达。 */
final class OrderNearbyWorkerOutreach
{
    private OrderNearbyWorkerRepository $repository;

    public function __construct(OrderNearbyWorkerRepository $repository)
    {
        $this->repository = $repository;
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function candidates(array $actor, string $orderPublicNo): array
    {
        $orderPublicNo = trim($orderPublicNo);
        if ($orderPublicNo === '' || strlen($orderPublicNo) > 80) {
            throw new OrderOperationException('订单编号无效', 'ORDER_ID_REQUIRED', 422);
        }
        return $this->repository->candidates($actor, $orderPublicNo);
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $body @return array{message:string,data:array<string,mixed>} */
    public function notify(array $actor, array $body): array
    {
        DemoActorGuard::requireExternalAction($actor);
        $orderPublicNo = trim((string)($body['order_id'] ?? ''));
        $clientRequestId = trim((string)($body['client_request_id'] ?? ''));
        $priceToken = trim((string)($body['price_quote_token'] ?? ''));
        $candidateKeys = is_array($body['candidate_keys'] ?? null) ? array_values($body['candidate_keys']) : [];
        $confirmedMax = max(0, (int)($body['confirmed_max_total_cents'] ?? 0));

        if ($orderPublicNo === '' || strlen($orderPublicNo) > 80) {
            throw new OrderOperationException('订单编号无效', 'ORDER_ID_REQUIRED', 422);
        }
        if (preg_match('/^[A-Za-z0-9_-]{12,64}$/', $clientRequestId) !== 1) {
            throw new OrderOperationException('短信请求编号格式不正确', 'CLIENT_REQUEST_ID_INVALID', 422);
        }
        if ($priceToken === '') {
            throw new OrderOperationException('短信价格确认已过期，请刷新', 'PRICE_TOKEN_REQUIRED', 409);
        }
        if (count($candidateKeys) < 1 || count($candidateKeys) > 6) {
            throw new OrderOperationException('请选择 1 至 6 位师傅', 'NEARBY_SMS_CANDIDATE_INVALID', 422);
        }

        return $this->repository->notify($actor, [
            'order_id'=>$orderPublicNo,
            'candidate_keys'=>$candidateKeys,
            'client_request_id'=>$clientRequestId,
            'price_quote_token'=>$priceToken,
            'confirmed_max_total_cents'=>$confirmedMax,
        ]);
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function notifyStatus(array $actor, string $batchPublicNo): array
    {
        $batchPublicNo = trim($batchPublicNo);
        if ($batchPublicNo === '' || strlen($batchPublicNo) > 80) {
            throw new OrderOperationException('短信批次编号无效', 'NEARBY_SMS_BATCH_NOT_FOUND', 404);
        }
        return $this->repository->notifyStatus($actor, $batchPublicNo);
    }
}
