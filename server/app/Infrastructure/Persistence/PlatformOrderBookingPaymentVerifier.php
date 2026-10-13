<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;

final class PlatformOrderBookingPaymentVerifier
{
    public function verifiedPaidCents(Database $db,int $orderId,int $customerTotalCents,?int $expectedCents=null,?string $customerFlowMode=null): int
    {
        if($orderId<=0||$customerTotalCents<=0)throw new OrderOperationException('平台承接订单金额或编号无效','PLATFORM_BOOKING_INVALID',409);
        $terms=null;
        if($expectedCents===null||trim((string)$customerFlowMode)===''){
            $terms=$db->fetch('SELECT customer_flow_mode,booking_paid_cents FROM order_platform_terms WHERE order_id=:order_id LIMIT 1 FOR UPDATE',['order_id'=>$orderId]);
            if(!$terms)throw new OrderOperationException('平台承接单缺少托管资金条款，请先修复订单数据','PLATFORM_TERMS_REQUIRED',409);
        }
        $flow=trim((string)($customerFlowMode??($terms['customer_flow_mode']??'')));
        if($flow!=='platform_managed')throw new OrderOperationException('平台承接单只允许平台托管模式；旧小程序预约模式必须先完成数据清理','PLATFORM_BOOKING_FLOW_INVALID',409);
        $externalPaid=$expectedCents!==null?$expectedCents:(int)($terms['booking_paid_cents']??0);
        if($externalPaid<0||$externalPaid>$customerTotalCents)throw new OrderOperationException('平台外部已收预付款金额异常，请先核对订单资金条件','PLATFORM_BOOKING_AMOUNT_MISMATCH',409);
        $payments=$db->fetchAll("SELECT id,amount_cents FROM payment_orders WHERE order_id=:order_id AND business_type IN ('order_booking','booking_fee') AND status='paid' ORDER BY id FOR UPDATE",['order_id'=>$orderId]);
        if($payments)throw new OrderOperationException('平台托管单检测到微信预约费付款，请先按资金异常核对；该笔款不能与外部预付款重复抵扣','PLATFORM_BOOKING_UNEXPECTED_PAYMENT',409);
        return $externalPaid;
    }
}
