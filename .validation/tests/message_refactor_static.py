from pathlib import Path

root=Path(".validation/source")
def read(path):
    return (root/path).read_text(encoding="utf-8")

defs=read("server/app/Infrastructure/Messaging/OrderSubscriptionDefinitionOperations.php")
queue=read("server/app/Infrastructure/Messaging/OrderSubscriptionQueue.php")
events=read("server/app/Domain/Messaging/MessageEventCatalog.php")
projector=read("server/app/Infrastructure/Messaging/OrderNotificationProjector.php")
paid=read("server/app/Infrastructure/Persistence/DatabasePaidPaymentSettlement.php")
refund=read("server/app/Infrastructure/Persistence/DatabaseRefundResultSettlement.php")
dispute=read("server/app/Infrastructure/Persistence/DatabaseOrderDisputeRepository.php")
measurement=read("server/app/Infrastructure/Scheduling/MeasurementFrozenBalanceReleaseTask.php")
platform=read("server/app/Infrastructure/Persistence/PlatformOrderFrozenBalanceReleaser.php")
scheduler=read("server/app/Services/SchedulerService.php")
paid_sms=read("server/app/Infrastructure/Messaging/DatabasePaidSmsDeliveryRepository.php")
chat_sms=read("server/app/Infrastructure/Messaging/DatabaseChatSmsDeliveryRepository.php")
projection=read("server/app/Infrastructure/Testing/TestMessageProjectionRecorder.php")
policy=read("server/app/Domain/Messaging/SettingsValuePolicy.php")
mini=read("miniprogram/utils/SubscribeMessageUtils.js")

for title in ["订单进度提醒","订单费用变更提醒","账户资金变动提醒","聊天消息提醒"]:
    assert title in defs
for legacy in ["'financial_progress'=>","'user_order_update'=>","'worker_order_update'=>","'repair_progress'=>","'fee_adjustment'=>"]:
    assert legacy not in defs, legacy

assert "'fee_note'=>'费用说明'" in defs
assert "'notice_text'=>'温馨提示'" in defs
assert "请查看小程序详情" not in defs
assert "请进入小程序查看订单详情" not in queue
assert "请进入小程序查看" not in queue
assert "请进入小程序查看" not in read("server/app/Application/Messaging/SubscriptionTestData.php")

assert "queueAccountFunds(" in paid
assert "queueAccountFunds(" in refund
assert "queueWorkerAccountFunds(" in projector
assert "queueWorkerAccountFunds(" in dispute
assert "queueAccountFunds(" in paid_sms
assert "queueAccountFunds(" in chat_sms
assert "OrderNotificationService::queue" in measurement
assert "OrderNotificationService::queue" in platform
assert "queueWorkerAccountFunds(" in scheduler
assert "'platform_worker_income_released'" in events
assert "'account_funds'" in events

assert "OrderSubscriptionQueue::subscriptionSceneForEvent" in projection
assert "worker_order_update" not in projection
assert "array_intersect_key($decoded,array_flip($publicKeys))" in policy
assert "financial_progress: true" not in mini
assert "order_fee_change: true" in mini and "account_funds: true" in mini

print("message refactor static coverage contract: OK")
