<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Agreements;

use WorkerAdmin\Contracts\OrderAgreementProvider;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Application\Agreements\PlatformAgreements;
use WorkerAdmin\Infrastructure\Persistence\DatabaseAgreementDocumentRepository;

/**
 * 订单域协议适配器。
 *
 * V2.5.0 起下单不再保留旧技术版本兼容：前端看到、提交并留证的 version
 * 就是后台当前实际生效的发布版本。
 */
final class PlatformOrderAgreementProvider implements OrderAgreementProvider
{
    private PlatformAgreements $service;

    public function __construct(Database $db)
    {
        $this->service = new PlatformAgreements(new DatabaseAgreementDocumentRepository($db));
    }

    public function orderSubmitAgreements(): array
    {
        return $this->service->publicDocuments('order_submit', 'user');
    }

    public function validatePublishedVersions(array $versions): array
    {
        return $this->service->validatePublishedVersions($versions, 'order_submit', 'user');
    }
}
