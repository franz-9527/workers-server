<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Security;

use WorkerAdmin\Contracts\OrderMediaUrlSigner;

final class HmacOrderMediaUrlSigner implements OrderMediaUrlSigner
{
    public function sign(
        string $mediaPublicId,
        array $actor,
        int $orderId,
        string $entityType = 'order',
        ?int $entityId = null,
        ?string $shareHash = null
    ): string {
        if ($mediaPublicId === '' || $orderId < 1) return '';
        $userId = (int)($actor['user_id'] ?? 0);
        $workerId = (int)($actor['worker_id'] ?? 0);
        $isAdmin = !empty($actor['is_admin']) || !empty($actor['business_admin']);
        if ($isAdmin) {
            $actorType = 'admin';
            $actorId = $userId;
        } elseif ($workerId > 0) {
            $actorType = 'worker';
            $actorId = $workerId;
        } else {
            $actorType = 'user';
            $actorId = $userId;
        }
        if ($actorId < 1 || $userId < 1) return '';
        $resolvedEntityId = $entityId === null ? $orderId : $entityId;
        if ($resolvedEntityId < 1 || trim($entityType) === '') return '';
        $payload = [
            'v' => 2,
            'scope' => 'media',
            'media_id' => $mediaPublicId,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'user_id' => $userId,
            'entity_type' => $entityType,
            'entity_id' => $resolvedEntityId,
            'order_id' => $orderId,
            'expires' => time() + 600,
            'nonce' => bin2hex(random_bytes(8)),
        ];
        if ($shareHash !== null && $shareHash !== '') $payload['share_hash'] = $shareHash;
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) return '';
        $encoded = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $encoded, (string)\app_config('app.key', ''));
        return \app_url('api/?' . http_build_query([
            'route' => 'media.order',
            'id' => $mediaPublicId,
            'access_token' => $encoded . '.' . $signature,
        ]));
    }
}
