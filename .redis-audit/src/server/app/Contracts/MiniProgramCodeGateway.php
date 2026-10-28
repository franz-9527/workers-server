<?php
declare(strict_types=1);
namespace WorkerAdmin\Contracts;
interface MiniProgramCodeGateway
{
    public function generate(int $orderId,string $shareType,string $path):array;
}
