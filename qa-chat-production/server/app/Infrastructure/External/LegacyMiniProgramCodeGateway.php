<?php
declare(strict_types=1);
namespace WorkerAdmin\Infrastructure\External;
use WorkerAdmin\Contracts\MiniProgramCodeGateway;
use WorkerAdmin\Services\MiniProgramCodeService;
/** Preserve the existing WeChat endpoint, validation and private cache. */
final class LegacyMiniProgramCodeGateway implements MiniProgramCodeGateway
{
    public function generate(int $orderId,string $shareType,string $path):array
    {return (new MiniProgramCodeService())->generate($orderId,$shareType,$path);}
}
