from pathlib import Path
import json

root = Path(".validation/source")
app = json.loads((root/"miniprogram/app.json").read_text())
assert "scope.record" not in app.get("permission", {})

group = json.loads((root/"miniprogram/subpackages/chat/group/index.json").read_text())
assert "enableShareAppMessage" not in group
assert "onShareAppMessage" in (root/"miniprogram/subpackages/chat/group/index.js").read_text()

add_wxml = (root/"miniprogram/components/addTip/addTip.wxml").read_text()
add_wxss = (root/"miniprogram/components/addTip/addTip.wxss").read_text()
assert 'class="cancel-icon"' in add_wxml
assert ".cancle_btn image" not in add_wxss
assert ".cancel-icon" in add_wxss

for rel in ["miniprogram/app.js","miniprogram/pages/index/index.js","miniprogram/subpackages/store/index/index.js"]:
    text = (root/rel).read_text()
    assert "wx.getSystemInfo({" not in text
    assert "wx.getSystemInfoSync()" not in text

nav = (root/"miniprogram/components/fullscreen-nav/index.js").read_text()
conversation = (root/"miniprogram/subpackages/chat/conversation/index.js").read_text()
assert "nativeMode: true" in nav
assert "nativeMode:true" in conversation

runtime = (root/"miniprogram/utils/ChatRuntime.js").read_text()
inbox = (root/"miniprogram/pages/chatMessages/index.js").read_text()
assert "audit_allowed" in runtime
assert "auditAllowed" in runtime
assert "probeAudit(serial)" not in inbox
assert "async probeAudit" not in inbox
assert "chatManager.auditAllowed" in inbox

wxss = (root/"miniprogram/pages/chatMessages/index.wxss").read_text() if (root/"miniprogram/pages/chatMessages/index.wxss").exists() else ""
if wxss:
    assert "max-width:132rpx" in wxss

print("log-fix static checks passed")
