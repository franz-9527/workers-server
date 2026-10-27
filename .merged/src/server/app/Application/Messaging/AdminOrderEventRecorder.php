<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Messaging;

use RuntimeException;
use WorkerAdmin\Contracts\AdminOrderEventRepository;

final class AdminOrderEventRecorder
{
    private AdminOrderEventRepository $repository;
    public function __construct(AdminOrderEventRepository $repository) { $this->repository=$repository; }
    public function record(int $orderId,string $type,int $adminId,string $message,array $extra=[]):void
    {
        if($orderId<1 || $adminId<1 || $type==='')throw new RuntimeException('订单事件缺少有效的业务或管理员身份。');
        $this->repository->record($orderId,$type,$adminId,$message,$extra);
    }
}
