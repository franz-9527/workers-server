<?php

declare(strict_types=1);

namespace WorkerAdmin\Domain\Messaging;
require_once __DIR__.'/OrderProgressMessageCatalog.php';

// 2026-10-08：统一协商事件仅通知另一方，升级后才通知管理员。
final class MessageEventCatalog
{
    public static function all(): array
    {
        static $catalog = null;
        if (is_array($catalog)) return $catalog;

        $labels = [
            'negotiation_responded'=>'对方响应协商','repair_worker_accepted'=>'师傅接受维修','measurement_rework_rejected'=>'师傅无法补测',
            'order_ready_for_prepayment'=>'下单待支付预约费','order_submitted'=>'新订单待管理员审核','order_approved'=>'订单审核通过','order_supplement_required'=>'要求用户补充资料','order_supplemented'=>'用户已补充资料','order_rejected'=>'订单审核未通过',
            'worker_quoted'=>'师傅首次报价','worker_quote_updated'=>'师傅修改报价','platform_quoted'=>'平台报价','quote_selected_pending_payment'=>'用户选择报价','payment_reservation_released'=>'支付超时释放报价','booking_fee_paid'=>'预约费支付成功','booking_fee_paid_upfront'=>'下单预约费支付成功',
            'worker_assigned'=>'安排施工师傅','assignment_accepted'=>'师傅接单','assignment_rejected'=>'师傅拒绝派单','assignment_expired'=>'派单超时释放','appointment_recorded'=>'师傅登记预约','appointment_waiting_customer_notice'=>'师傅已响应预约，等待客户确认',
            'change_requested'=>'师傅申请费用调整','change_accepted'=>'用户同意费用调整','change_rejected'=>'用户不同意费用调整','completion_submitted'=>'师傅提交完工','completion_accepted_by_user'=>'用户确认验收','auto_completion_accepted'=>'系统自动验收','auto_accepted_72h'=>'72小时自动验收',
            'exceptional_arrival_reported'=>'师傅登记到场异常','offline_collection_delayed'=>'尾款延期登记','offline_collection_bad_debt'=>'尾款坏账','cancel_requested'=>'用户申请取消退款','cancel_approved'=>'取消退款通过','admin_cancelled'=>'平台强制取消','cancel_rejected'=>'取消退款驳回','auto_cancelled_7d'=>'七天自动取消','refund_succeeded'=>'退款成功',
            'order_auto_preapproved'=>'新订单自动预审公开','offline_collection_adjustment_requested'=>'师傅提交实际尾款','offline_collection_adjustment_auto_approved'=>'实际尾款自动预审生效','offline_collection_adjustment_approved'=>'实际尾款复核通过','offline_collection_adjustment_rejected'=>'实际尾款驳回',
            'commission_estimate_created'=>'预计服务费已生成','commission_receivable_finalized'=>'正式平台服务费已生成','commission_receivable_created'=>'平台服务费待缴','commission_paid'=>'平台服务费结清','commission_overdue'=>'平台服务费逾期',
            'dispute_opened'=>'订单争议发起','completion_dispute_opened'=>'用户提交完工争议','dispute_resolved'=>'订单争议结案','review_submitted'=>'用户提交评价','auto_reviewed_5d'=>'系统自动评价',
            'run_empty_claim_submitted'=>'商家跑空费待确认','run_empty_claim_confirmed'=>'商家已确认跑空费','run_empty_claim_disputed'=>'跑空费进入争议','run_empty_fee_paid'=>'商家已支付跑空费',
            'worker_profile_submitted'=>'师傅发布/更新资料待审核','worker_realname_submitted'=>'师傅实名待审核','worker_collection_code_submitted'=>'旧版收款码待审核','worker_collection_code_auto_approved'=>'收款码自动预审后待抽查','merchant_auth_submitted'=>'商家认证待审核','withdrawal_submitted'=>'师傅提交提现申请',
            'warranty_repair_requested'=>'用户提交维修申请','warranty_repair_withdrawn'=>'用户撤回维修申请','repair_worker_rejected'=>'师傅拒绝维修','repair_worker_completed'=>'师傅完成维修','repair_intervention_available'=>'维修超时可申请平台介入','repair_platform_intervention_requested'=>'用户申请平台介入维修','repair_platform_continue'=>'平台要求继续维修','repair_platform_resolved'=>'平台确认售后解决','repair_platform_closed'=>'平台关闭售后','repair_appointment_recorded'=>'师傅登记维修预约','repair_waiting_customer_notice'=>'师傅已响应维修，等待客户确认','worker_replaced_by_user'=>'用户更换成交师傅',
            'supplement_deadline_reminder'=>'补资料期限提醒','supplement_timeout_refunded'=>'补资料超时退款',
            'measurement_service_paid'=>'测量服务费支付成功','measurement_change_paid'=>'测量加费支付成功','measurement_change_accepted_pending_payment'=>'测量加费待付款','measurement_result_submitted'=>'测量成果待确认','measurement_rework_requested'=>'用户申请补测','measurement_result_accepted'=>'测量成果已确认','measurement_auto_accepted'=>'测量成果自动确认',
            'measurement_hold_extension_requested'=>'测量冻结延期申请','measurement_hold_extension_approved'=>'测量冻结延期已通过','measurement_hold_extension_rejected'=>'测量冻结延期未通过','measurement_income_released'=>'测量费用已结算','measurement_construction_linked'=>'测量单已关联施工单',
            'measurement_change_refund_created'=>'测量加费退款处理中','measurement_change_refunded'=>'测量加费退款成功','measurement_late_balance_refund_created'=>'测量尾款退款处理中','measurement_late_balance_refunded'=>'测量尾款退款成功',
            'worker_contact_reveal_requested'=>'用户申请公开师傅电话','worker_contact_reveal_approved'=>'师傅电话公开申请已批准','worker_contact_reveal_rejected'=>'师傅电话公开申请未批准','worker_contact_reveal_cancelled'=>'师傅电话公开申请已取消',
        ];

        $normal = array_flip([
            'order_approved','worker_assigned','assignment_accepted','appointment_recorded','booking_fee_paid','booking_fee_paid_upfront','measurement_service_paid','measurement_change_paid','refund_succeeded','measurement_change_refunded','measurement_late_balance_refunded','worker_quote_updated','payment_reservation_released','commission_estimate_created','run_empty_fee_paid','auto_cancelled_7d','repair_worker_completed','completion_accepted_by_user','commission_paid','review_submitted','auto_reviewed_5d','run_empty_fee_paid',
            'order_auto_preapproved','offline_collection_adjustment_auto_approved','worker_collection_code_auto_approved',
            'measurement_result_accepted','measurement_auto_accepted','measurement_income_released','measurement_construction_linked','worker_contact_reveal_cancelled','appointment_waiting_customer_notice','repair_waiting_customer_notice',
            'warranty_repair_withdrawn','repair_worker_completed','repair_appointment_recorded','repair_platform_resolved','repair_platform_closed','measurement_hold_extension_approved','measurement_hold_extension_rejected','offline_collection_adjustment_approved','offline_collection_adjustment_rejected',
        ]);
        $definitions = [
            'negotiation_responded'=>['对方已响应协商',['user','worker'],'order_status','user_order_update'],
            'repair_worker_accepted'=>['师傅已接受维修',['user'],'order_status','repair_progress'],
            'measurement_rework_rejected'=>['师傅无法补测，可继续协商',['user'],'order_status','user_order_update'],
            'order_ready_for_prepayment'=>['订单待支付预约费',['user'],'order_status',''],
            'order_submitted'=>['订单待审核',['admin'],'order_status',''],
            'order_approved'=>['审核通过，开始报价',['user'],'order_status','order_audit'],
            'order_supplement_required'=>['请补充订单资料',['user'],'order_status','order_audit'],
            'order_supplemented'=>['用户已补充订单资料',['admin'],'order_status',''],
            'order_rejected'=>['订单审核未通过',['user'],'order_status','order_audit'],
            'worker_quoted'=>['收到新报价',['user'],'quote_received','quote_received'],
            'worker_quote_updated'=>['收到新报价',['user'],'quote_received','quote_received'],
            'platform_quoted'=>['收到新报价',['user'],'quote_received','quote_received'],
            'quote_selected_pending_payment'=>['已选报价，待支付预约费',['user'],'order_status','payment_pending'],
            'payment_reservation_released'=>['支付超时，订单恢复报价',['user'],'order_status',''],
            'booking_fee_paid'=>['预约费支付成功',['user'],'payment_success','payment_success'],
            'booking_fee_paid_upfront'=>['预约费支付成功',['user'],'payment_success','payment_success'],
            'worker_assigned'=>['已安排施工师傅',['worker','user'],'order_status','worker_assigned'],
            'assignment_accepted'=>['师傅已接单',['user'],'order_status','worker_assigned'],
            'assignment_rejected'=>['师傅已拒绝派单',['user','admin'],'order_status','user_order_update'],
            'assignment_expired'=>['派单超时已释放',['user','worker','admin'],'order_status','user_order_update'],
            'appointment_recorded'=>['已预约上门',['user'],'order_status',''],
            'appointment_waiting_customer_notice'=>['师傅已联系，请确认预约',['user'],'order_status',''],
            'change_requested'=>['费用调整待确认',['user'],'order_status','fee_adjustment'],
            'change_accepted'=>['费用调整已同意',['worker'],'order_status','worker_order_update'],
            'change_rejected'=>['费用调整未同意',['worker'],'order_status','worker_order_update'],
            'completion_submitted'=>['完工待验收',['user'],'order_status','completion_submitted'],
            'completion_accepted_by_user'=>['用户已验收',['worker'],'order_status',''],
            'auto_completion_accepted'=>['订单已自动验收',['user'],'order_status',''],
            'auto_accepted_72h'=>['订单已自动验收',['user'],'order_status',''],
            'auto_reviewed_5d'=>['订单收到自动好评',['worker'],'order_status',''],
            'warranty_repair_requested'=>['用户已提交维修申请，请及时联系处理',['worker'],'order_status','worker_repair'],
            'warranty_repair_withdrawn'=>['用户已撤回维修申请',['worker'],'order_status',''],
            'repair_worker_rejected'=>['师傅已拒绝维修，可申请平台介入',['user'],'order_status','repair_progress'],
            'repair_worker_completed'=>['维修已完成',['user'],'order_status',''],
            'repair_intervention_available'=>['维修超过48小时仍未完成，可申请平台介入',['user'],'order_status','repair_progress'],
            'repair_platform_intervention_requested'=>['用户已申请平台介入维修',['worker'],'order_status','worker_order_update'],
            'repair_platform_continue'=>['平台要求继续维修',['user','worker'],'order_status','repair_progress'],
            'repair_platform_resolved'=>['平台确认售后问题已解决',['user','worker'],'order_status','repair_progress'],
            'repair_platform_closed'=>['平台已关闭本次售后',['user','worker'],'order_status','repair_progress'],
            'repair_appointment_recorded'=>['师傅已登记维修预约',['user'],'order_status','repair_progress'],
            'repair_waiting_customer_notice'=>['师傅已联系，请确认维修预约',['user'],'order_status',''],
            'worker_replaced_by_user'=>['订单已更换成交师傅',['worker','user'],'order_status',''],
            'supplement_deadline_reminder'=>['补资料期限即将到期',['user'],'order_status','supplement_reminder'],
            'supplement_timeout_refunded'=>['补资料超时已取消，退款处理中',['user','admin'],'order_status','refund_result'],
            'commission_estimate_created'=>['预计服务费已生成',['worker'],'order_status',''],
            'commission_receivable_finalized'=>['平台服务费已正式生成',['worker'],'order_status',''],
            'exceptional_arrival_reported'=>['师傅已登记到场异常',['admin'],'order_status',''],
            'offline_collection_delayed'=>['师傅已登记尾款延期',['admin'],'order_status',''],
            'offline_collection_bad_debt'=>['线下尾款已标记坏账',['worker'],'order_status',''],
            'cancel_requested'=>['订单申请退款',['user','worker','admin'],'order_status','worker_order_update'],
            'cancel_approved'=>['取消退款已通过',['user','worker'],'order_status','refund_result'],
            'admin_cancelled'=>['平台已取消订单',['user','worker'],'order_status','user_order_update'],
            'cancel_rejected'=>['取消退款未通过',['user','worker'],'order_status','refund_result'],
            'auto_cancelled_7d'=>['订单超期自动取消',['user','admin'],'order_status',''],
            'refund_succeeded'=>['预约费退款成功',['user'],'order_status','refund_result'],
            'worker_contact_reveal_approved'=>['师傅电话公开申请已批准',['user','worker'],'order_status','user_order_update'],
            'worker_contact_reveal_rejected'=>['师傅电话公开申请未批准',['user'],'order_status','user_order_update'],
            'worker_contact_reveal_cancelled'=>['师傅电话公开申请已取消',['user'],'order_status',''],
            'offline_collection_adjustment_requested'=>['实际尾款待审批',['admin'],'order_status',''],
            'offline_collection_adjustment_approved'=>['实际尾款已核准',['worker'],'order_status',''],
            'offline_collection_adjustment_rejected'=>['实际尾款未核准',['worker'],'order_status',''],
            'commission_receivable_created'=>['平台服务费待缴',['worker'],'order_status','worker_commission_due'],
            'commission_paid'=>['平台服务费已结清',['worker'],'order_status',''],
            'dispute_opened'=>['订单进入争议处理',['user','worker','admin'],'order_status','worker_order_update'],
            'dispute_resolved'=>['订单争议已处理',['user','worker'],'order_status','dispute_progress'],
            'review_submitted'=>['订单收到用户评价',['worker'],'order_status',''],
            'run_empty_claim_submitted'=>['商家跑空费待确认',['user'],'order_status','user_order_update'],
            'run_empty_claim_confirmed'=>['商家已确认跑空费',['worker'],'order_status','worker_order_update'],
            'run_empty_claim_disputed'=>['跑空费进入争议处理',['user','worker','admin'],'order_status','user_order_update'],
            'run_empty_fee_paid'=>['商家跑空费已支付',['worker'],'order_status',''],
            'measurement_service_paid'=>['测量服务费支付成功',['user','worker'],'order_status',''],
            'measurement_change_paid'=>['测量加费支付成功',['user','worker'],'order_status',''],
            'measurement_change_accepted_pending_payment'=>['测量加费待付款',['user'],'order_status','payment_pending'],
            'measurement_result_submitted'=>['测量成果待确认',['user'],'order_status','measurement_result'],
            'measurement_rework_requested'=>['用户申请补测',['worker'],'order_status','worker_rework'],
            'measurement_result_accepted'=>['测量成果已确认',['user','worker'],'order_status',''],
            'measurement_auto_accepted'=>['测量成果已自动确认',['user','worker'],'order_status',''],
            'measurement_hold_extension_approved'=>['测量冻结延期已通过',['user'],'order_status','measurement_extension'],
            'measurement_hold_extension_rejected'=>['测量冻结延期未通过',['user'],'order_status','measurement_extension'],
            'measurement_income_released'=>['测量费用已结算',['worker'],'order_status',''],
            'measurement_construction_linked'=>['测量单已关联施工单',['user','worker'],'order_status',''],
            'measurement_change_refund_created'=>['测量加费退款处理中',['user'],'order_status','user_order_update'],
            'measurement_change_refunded'=>['测量加费退款成功',['user'],'order_status',''],
            'measurement_late_balance_refund_created'=>['测量尾款退款处理中',['user'],'order_status','user_order_update'],
            'measurement_late_balance_refunded'=>['测量尾款退款成功',['user'],'order_status',''],
        ];
        $smsRecommended = array_flip([
            'order_supplement_required','worker_quoted','platform_quoted','change_requested',
            'completion_submitted','measurement_result_submitted','measurement_rework_requested',
            'warranty_repair_requested','repair_worker_rejected','supplement_deadline_reminder',
            'commission_receivable_created','run_empty_claim_submitted',
        ]);
        $internalOnly = array_flip([
            'booking_fee_paid','booking_fee_paid_upfront','measurement_service_paid','measurement_change_paid',
            'completion_accepted_by_user','commission_estimate_created','commission_paid',
            'repair_worker_completed','review_submitted','auto_reviewed_5d',
            'measurement_result_accepted','measurement_auto_accepted','measurement_income_released',
            'measurement_construction_linked','worker_contact_reveal_cancelled',
        ]);
        $wecomDefaults = array_flip([
            'order_submitted','order_supplemented','assignment_rejected','assignment_expired','exceptional_arrival_reported','offline_collection_delayed','cancel_requested','auto_cancelled_7d',
            'offline_collection_adjustment_requested','dispute_opened','run_empty_claim_disputed','worker_profile_submitted','worker_realname_submitted','merchant_auth_submitted','commission_overdue',
            'withdrawal_submitted','worker_contact_reveal_requested','measurement_hold_extension_requested','completion_dispute_opened','repair_platform_intervention_requested','supplement_timeout_refunded',
        ]);
        $wecomNormalVisible = array_flip(['order_auto_preapproved','offline_collection_adjustment_auto_approved','worker_collection_code_auto_approved']);
        $explicitWecom = array_flip(['worker_profile_submitted','worker_realname_submitted','worker_collection_code_submitted','worker_collection_code_auto_approved','merchant_auth_submitted','commission_overdue','withdrawal_submitted','worker_contact_reveal_requested','measurement_hold_extension_requested','completion_dispute_opened','repair_platform_intervention_requested','order_auto_preapproved','offline_collection_adjustment_auto_approved']);

        $catalog = [];
        foreach ($labels as $key => $label) {
            $definition = $definitions[$key] ?? null;
            $recipients = $definition ? $definition[1] : [];
            $audienceLabel = in_array('user',$recipients,true) && in_array('worker',$recipients,true) ? '用户、师傅' : (in_array('worker',$recipients,true) ? '师傅' : (in_array('user',$recipients,true) ? '用户' : '管理员'));
            $catalog[$key] = [
                'key'=>$key,
                'label'=>$label,
                'priority'=>isset($normal[$key]) ? 'normal' : 'important',
                'audience_label'=>$audienceLabel,
                'status'=>$definition ? $definition[0] : '',
                'recipients'=>$recipients,
                'scene'=>$definition ? $definition[2] : '',
                'subscription_scene'=>$definition ? $definition[3] : '',
                'inapp_visible'=>$definition && (in_array('user',$recipients,true) || in_array('worker',$recipients,true)),
                'internal_only'=>isset($internalOnly[$key]),
                'sms_visible'=>$definition && (in_array('user',$recipients,true) || in_array('worker',$recipients,true)) && !isset($internalOnly[$key]),
                'sms_default'=>isset($smsRecommended[$key]),
                'sms_recommended'=>isset($smsRecommended[$key]),
                'sms_delivery_mode'=>(!isset($smsRecommended[$key]) && $definition && trim((string)$definition[3])!=='')?'subscription_fallback':'independent',
                'sms_subscription_fallback'=>!isset($smsRecommended[$key]) && $definition && trim((string)$definition[3])!=='',
                'sms_priority'=>isset($smsRecommended[$key])?'important':'normal',
                'wecom_visible'=>isset($explicitWecom[$key]) || in_array('admin',$recipients,true) || isset($wecomNormalVisible[$key]),
                'wecom_default'=>isset($wecomDefaults[$key]),
            ];
        }

        return $catalog += \WorkerAdmin\Domain\Messaging\OrderProgressMessageCatalog::all();
    }

    public static function get(string $eventType): ?array
    {
        $all = self::all();
        return $all[$eventType] ?? null;
    }

    public static function switches(string $channel, array $configured): array
    {
        $all = self::all();
        $visibleKey = $channel === 'wecom' ? 'wecom_visible' : ($channel === 'inapp' ? 'inapp_visible' : 'sms_visible');
        $result = [];

        foreach ($all as $eventKey => $meta) {
            if (empty($meta[$visibleKey])) continue;
            $saved = $configured[$eventKey] ?? null;
            $enabled = $saved === null ? $channel === 'inapp' : filter_var(is_array($saved) ? ($saved['enabled'] ?? false) : $saved, FILTER_VALIDATE_BOOLEAN);
            $extra = is_array($saved) ? $saved : [];
            $extra['enabled'] = $enabled;
            $extra['priority'] = (string) ($channel==='sms'?($meta['sms_priority']??'normal'):$meta['priority']);
            $result[$eventKey] = $extra;
        }
        foreach ($configured as $eventKey => $saved) {
            if (isset($result[$eventKey]) || isset($all[$eventKey])) continue;
            $extra = is_array($saved) ? $saved : [];
            $extra['enabled'] = filter_var(is_array($saved) ? ($saved['enabled'] ?? false) : $saved, FILTER_VALIDATE_BOOLEAN);
            $extra['priority'] = isset($extra['priority']) && $extra['priority'] === 'normal' ? 'normal' : 'important';
            $result[(string)$eventKey] = $extra;
        }

        return $result;
    }

    public static function enabled(string $channel, string $eventType, array $configured): bool
    {
        $switches = self::switches($channel, $configured);
        return isset($switches[$eventType]) && !empty($switches[$eventType]['enabled']);
    }
}
