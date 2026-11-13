<?php

declare(strict_types=1);

namespace WorkerAdmin\Domain\Messaging;

/**
 * 付费短信 SendDetails 回执的纯策略。
 *
 * 不访问数据库、不读手机号、不调用 provider；正式仓储与测试中心共同复用，
 * 防止回执分类/退避规则出现第二份实现。
 */
final class PaidSmsReceiptPolicy
{
    /** @return array<string,mixed> */
    public static function classify(?array $matched): array
    {
        if($matched===null){
            return[
                'state'=>'pending',
                'delivery_status'=>null,
                'billing_status'=>'held',
                'should_charge'=>false,
                'should_release'=>false,
                'provider_status'=>null,
            ];
        }

        $status=(int)($matched['send_status']??0);
        if($status===3){
            return[
                'state'=>'delivered',
                'delivery_status'=>'delivered',
                'billing_status'=>'charged',
                'should_charge'=>true,
                'should_release'=>false,
                'provider_status'=>3,
            ];
        }
        if($status===2){
            return[
                'state'=>'delivery_failed',
                'delivery_status'=>'delivery_failed',
                'billing_status'=>'released',
                'should_charge'=>false,
                'should_release'=>true,
                'provider_status'=>2,
            ];
        }

        return[
            'state'=>'pending',
            'delivery_status'=>null,
            'billing_status'=>'held',
            'should_charge'=>false,
            'should_release'=>false,
            'provider_status'=>$status,
        ];
    }

    public static function retryDelaySeconds(int $attempt): int
    {
        $attempt=max(1,$attempt);
        if($attempt===1)return 30;
        if($attempt===2)return 120;
        if($attempt===3)return 600;
        if($attempt===4)return 1800;
        return 3600;
    }

    /** @return array<string,mixed> */
    public static function timeout(): array
    {
        return[
            'state'=>'receipt_timeout',
            'delivery_status'=>'unknown',
            'billing_status'=>'released',
            'should_charge'=>false,
            'should_release'=>true,
            'retain_active_dedupe_key'=>true,
            'error_code'=>'RECEIPT_TIMEOUT',
        ];
    }
}
