<?php
declare(strict_types=1);
namespace WorkerAdmin\Infrastructure\Testing;

/** 平台测量测试矩阵由服务端固定，不能通过请求切换资金或客户身份。 */
final class TestingPlatformScenario
{
    public static function variants():array
    {
        return[
            'platform.measurement.single_grab.waiting.open'=>['pricing'=>'fixed_total','settlement'=>'worker_collect','bound'=>true],
            'platform.measurement.single_grab.waiting.itemized'=>['pricing'=>'itemized','settlement'=>'worker_collect','bound'=>true],
            'platform.measurement.single_grab.waiting.balance'=>['pricing'=>'fixed_total','settlement'=>'platform_balance','bound'=>true],
            'platform.measurement.single_grab.waiting.unbound'=>['pricing'=>'itemized','settlement'=>'platform_balance','bound'=>false],
        ];
    }
    public static function supports(string $key):bool{return isset(self::variants()[$key]);}
}
