<?php

declare(strict_types=1);

namespace WorkerAdmin\Domain\Messaging;

/** 已审核短信模板契约；后台只允许配置 CODE，不允许改变必需变量名。 */
final class SmsTemplateCatalog
{
    public static function all(): array
    {
        return [
            'order_status'=>[
                'name'=>'订单状态提醒','template_code'=>'','variables'=>['order_no','status','time'],
                'content'=>'您的订单${order_no}状态更新为${status}，时间${time}，请进入壁选小程序查看。','flow'=>'order',
            ],
            'quote_received'=>[
                'name'=>'报价提醒','template_code'=>'','variables'=>['order_no','amount','time'],
                'content'=>'您的订单${order_no}收到报价，金额${amount}元，时间${time}，请进入壁选小程序查看。','flow'=>'quote',
            ],
            'payment_success'=>[
                'name'=>'支付结果（普通，可选）','template_code'=>'','variables'=>['order_no','amount','time'],
                'content'=>'您的订单${order_no}支付成功，金额${amount}元，时间${time}。','flow'=>'other',
            ],
            'nearby_worker_invite'=>[
                'name'=>'附近师傅接单邀请（独立付费）','template_code'=>'','variables'=>['category','district','distance'],
                'content'=>'您附近有新的${category}订单，位置约在${district}，距离约${distance}。有意接单请登录壁选小程序查看详情。','flow'=>'invite',
            ],
        ];
    }

    public static function testParameters(string $scene, string $timeText): array
    {
        $examples = [
            'order_status'=>['order_no'=>'BXCS12345678','status'=>'待确认','time'=>$timeText],
            'quote_received'=>['order_no'=>'BXCS12345678','amount'=>'100.00','time'=>$timeText],
            'payment_success'=>['order_no'=>'BXCS12345678','amount'=>'50.00','time'=>$timeText],
            'nearby_worker_invite'=>['category'=>'墙布施工','district'=>'西湖区','distance'=>'3.2公里'],
        ];
        return $examples[$scene] ?? [];
    }

    private function __construct() {}
}
