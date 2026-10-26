<?php
declare(strict_types=1);
namespace WorkerAdmin\Contracts;
interface AdminOrderEventRepository { public function record(int $orderId,string $type,int $adminId,string $message,array $extra=[]):void; }
