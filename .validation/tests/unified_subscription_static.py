from pathlib import Path

root=Path(".validation/source")
queue=(root/"server/app/Infrastructure/Messaging/OrderSubscriptionQueue.php").read_text()
chat=(root/"server/app/Infrastructure/Messaging/ChatReminderTemplate.php").read_text()
prefs=(root/"server/app/Application/Messaging/SubscriptionPreferences.php").read_text()
policy=(root/"server/app/Domain/Messaging/SettingsValuePolicy.php").read_text()
mini=(root/"miniprogram/utils/SubscribeMessageUtils.js").read_text()
editor=(root/"server/views/admin/settings-subscription-editor.php").read_text()
settings=(root/"server/views/admin/settings.php").read_text()

assert "o.workflow_profile" in queue and "普通" in queue and "测量单" in queue
assert "subscriptionStatusForRecipient" in queue
assert "subscriptionSceneForEvent" in queue and "SubscriptionCatalog::subscriptionRoot" in queue
assert "m.message_type,m.created_at,cm.role,u.public_id" in chat
assert "text_body" not in chat
for token in ["sender_name","notice_text","message_type","chat_info"]:
    assert token in chat
assert "subscriptionVisibleKeys()" in prefs
assert "$normalized = [];$usedTemplateIds=[];" in policy
assert "array_intersect_key(array_diff_key($decoded" not in policy
for title in ["订单进度提醒","聊天消息提醒","资金变动提醒"]:
    assert title in mini
assert "user_order_update: true" not in mini
assert "用户和师傅共用" in editor
assert "统一管理订单进度、聊天、资金变动及师傅入驻通知" in settings
print("unified subscription static contracts passed")
