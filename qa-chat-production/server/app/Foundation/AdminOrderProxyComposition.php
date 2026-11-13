<?php

declare(strict_types=1);

namespace WorkerAdmin\Foundation;

use WorkerAdmin\Application\Admin\AdminOrderProxyAction;
use WorkerAdmin\Application\Admin\AdminOrderAssistance;
use WorkerAdmin\Application\Admin\AdminOrderAssistanceMedia;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Persistence\DatabaseAdminOrderProxyActionRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseAdminOrderAssistanceRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseMediaRepository;
use WorkerAdmin\Infrastructure\Media\LegacyImageOptimizer;
use WorkerAdmin\Infrastructure\Security\HmacOrderMediaUrlSigner;

/** 管理员兼容拒绝路由与事实补录用例组合根。 */
final class AdminOrderProxyComposition
{
    private Database $db;
    private ?AdminOrderProxyAction $action = null;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function action(): AdminOrderProxyAction
    {
        if (!$this->action) {
            $this->action = new AdminOrderProxyAction(new DatabaseAdminOrderProxyActionRepository($this->db));
        }
        return $this->action;
    }

    public function assistance(OrderApplicationContainer $container): AdminOrderAssistance
    {
        return new AdminOrderAssistance(
            $this->assistanceRepository($container),
            (string)\app_config('app.timezone', 'Asia/Shanghai')
        );
    }

    public function assistanceMedia(OrderApplicationContainer $container): AdminOrderAssistanceMedia
    {
        $repository=$this->assistanceRepository($container);
        return new AdminOrderAssistanceMedia(new AdminOrderAssistance($repository,(string)\app_config('app.timezone','Asia/Shanghai')),
            $repository,new LegacyImageOptimizer(),(new ApplicationContainer($this->db))->objectStorage());
    }

    public function signMedia(array $row,array $actor,int $orderId): string
    {
        $actor['is_admin']=true; // 仅在 requireWorkbenchAdmin 成功后调用，使用真实用户 ID 签名。
        return (new HmacOrderMediaUrlSigner())->sign((string)$row['id'],$actor,$orderId,(string)$row['entity_type'],(int)$row['entity_id']);
    }

    private function assistanceRepository(OrderApplicationContainer $container): DatabaseAdminOrderAssistanceRepository
    {
        return new DatabaseAdminOrderAssistanceRepository($this->db,$container->appointment(),$container->fulfillment(),
            OrderFulfillmentAuxiliaryComposition::build($this->db),$container->negotiation(),new DatabaseMediaRepository($this->db,$container->settings()));
    }
}
