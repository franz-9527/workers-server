<?php

declare(strict_types=1);

namespace WorkerAdmin\Domain\Messaging;

/** 新订单持久化事件的纯消息目录；渠道是否启用仍由既有配置决定。 */
final class OrderProgressMessageCatalog
{
    public static function all(): array
    {
        $labels = [
            'platform_worker_quoted'=>'平台单工费报价待选择','platform_worker_quote_selected'=>'平台已选定成交师傅',
            'platform_single_grab_accepted'=>'平台单抢单成交','platform_invite_fixed_price_accepted'=>'受邀师傅按工费成交','invite_claimed'=>'受邀师傅待接单',
            'platform_measurement_auto_confirmed'=>'平台测量成果已确认，待核价','platform_measurement_confirmed'=>'客户确认平台测量成果',
            'platform_measurement_pricing_reviewed'=>'平台施工核价已完成','platform_measurement_pricing_confirmed'=>'施工价格已确认，可预约',
            'platform_change_requested'=>'平台单工费/加项待处理','platform_change_accepted'=>'客户已同意平台单加项','platform_change_rejected'=>'客户已拒绝平台单加项',
            'platform_balance_change_approved'=>'平台工费增项已批准','platform_balance_change_rejected'=>'平台工费增项已驳回',
            'platform_completion_accepted'=>'平台单已验收','platform_income_received'=>'平台收入款已核实','platform_pricing_quantity_adjusted'=>'实际用量与金额已更新',
            'assignment_auto_accepted'=>'已报价师傅自动成交',
        ];
        $definitions = [
            'platform_worker_quoted'=>['收到师傅工费报价，请选择成交师傅',['admin'],'order_status',''],
            'platform_worker_quote_selected'=>['平台已选定成交师傅，请联系预约',['user','worker'],'order_status','worker_assigned'],
            'platform_single_grab_accepted'=>['师傅已按约定工费成交',['user'],'order_status','worker_assigned'],
            'platform_invite_fixed_price_accepted'=>['受邀师傅已按约定工费成交',['user'],'order_status','worker_assigned'],
            'invite_claimed'=>['受邀师傅已领取订单，请确认接单',['worker'],'order_status','worker_assigned'],
            'platform_measurement_auto_confirmed'=>['测量成果已确认，等待平台核价',['user','admin'],'order_status',''],
            'platform_measurement_confirmed'=>['测量成果已确认，等待平台核价',['admin'],'order_status',''],
            // 托管单核价由平台完成后立即生效；reviewed 仅保留内部审计，不再要求客户二次确认。
            'platform_measurement_pricing_reviewed'=>['平台已完成测量后施工核价',['admin'],'order_status',''],
            'platform_measurement_pricing_confirmed'=>['施工价格已确认，可以联系客户预约',['worker'],'order_status','worker_order_update'],
            'platform_change_requested'=>['平台单费用调整待处理',['user','admin'],'order_status','fee_adjustment'],
            'platform_change_accepted'=>['客户已同意订单外加项',['worker'],'order_status','worker_order_update'],
            'platform_change_rejected'=>['客户已拒绝订单外加项',['worker'],'order_status','worker_order_update'],
            'platform_balance_change_approved'=>['平台已批准工费增项',['worker'],'order_status','worker_order_update'],
            'platform_balance_change_rejected'=>['平台已驳回工费增项',['worker'],'order_status','worker_order_update'],
            'platform_completion_accepted'=>['平台单已验收，结算金额已生成',['worker'],'order_status',''],
            'platform_income_received'=>['平台收入款已核实结清',['worker'],'order_status',''],
            'platform_pricing_quantity_adjusted'=>['实际用量和客户金额已更新',['user'],'order_status',''],
            'assignment_auto_accepted'=>['客户已选定您的报价，请联系预约',['worker'],'order_status','worker_order_update'],
        ];
        $normal = array('platform_worker_quote_selected', 'platform_single_grab_accepted', 'platform_invite_fixed_price_accepted', 'invite_claimed', 'platform_measurement_auto_confirmed', 'platform_measurement_confirmed', 'platform_completion_accepted', 'platform_income_received', 'platform_pricing_quantity_adjusted', 'assignment_auto_accepted');
        $internal = array('platform_completion_accepted', 'platform_income_received', 'platform_pricing_quantity_adjusted', 'platform_measurement_auto_confirmed', 'platform_measurement_pricing_reviewed');
        $catalog = [];
        foreach ($definitions as $key=>$definition) {
            $recipients=$definition[1]; $isInternal=in_array($key,$internal,true);
            $fallback=$definition[3]!=='';
            $catalog[$key]=[
                'key'=>$key,'label'=>$labels[$key],
                'priority'=>in_array($key,$normal,true)?'normal':'important',
                'audience_label'=>in_array('user',$recipients,true)&&in_array('worker',$recipients,true)?'用户、师傅':(in_array('worker',$recipients,true)?'师傅':(in_array('user',$recipients,true)?'用户':'管理员')),
                'status'=>$definition[0],'recipients'=>$recipients,'scene'=>$definition[2],'subscription_scene'=>$definition[3],
                'inapp_visible'=>in_array('user',$recipients,true)||in_array('worker',$recipients,true),
                'internal_only'=>$isInternal,
                'sms_visible'=>!$isInternal&&(in_array('user',$recipients,true)||in_array('worker',$recipients,true)),
                'sms_default'=>false,'sms_recommended'=>false,'sms_delivery_mode'=>$fallback?'subscription_fallback':'independent',
                'sms_subscription_fallback'=>$fallback,'sms_priority'=>'normal',
                'wecom_visible'=>in_array('admin',$recipients,true),'wecom_default'=>false,
            ];
        }
        return $catalog;
    }
}
