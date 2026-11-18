from pathlib import Path

root = Path(__file__).parent / "source"
def read(path): return (root / path).read_text(encoding="utf-8")

repo = read("server/app/Infrastructure/Settings/DatabaseAdminConfigurationRepository.php")
assert "ensureAnnouncementDefaults" in repo
assert "announcement.home.enabled" in repo and "announcement.enabled" in repo
assert "foreach(['home'=>'首页'" not in repo

api = read("server/app/Http/Api/Modules/MessagingApiModule.php")
assert "$_GET['scene']" not in api
assert "->current()" in api

settings = read("server/app/Application/Messaging/SettingsRead.php")
assert "['announcement.enabled','announcement.text','announcement.link']" in settings
assert "foreach (['home','store'" not in settings

view = read("server/views/admin/settings.php")
assert "全站运营公告" in view
assert "公告按页面独立生效" not in view
assert "announcement.order_submit.enabled" not in view

loader = read("miniprogram/utils/AnnouncementLoader.js")
assert "publicRequest('announcement.current')" in loader
assert "load(scene)" not in loader
assert "worker_public_announcement_v2" in loader

component = read("miniprogram/components/order-announcement/index.js")
assert "properties: { scene:" not in component
assert "Announcements.load()" in component

for path in [
    "miniprogram/pages/orderCenter/index.wxml",
    "miniprogram/pages/orderHall/orderHall.wxml",
    "miniprogram/pages/orderSubmit/index.wxml",
    "miniprogram/subpackages/account/merchantAuth/index.wxml",
]:
    assert '<order-announcement scene=' not in read(path), path

home = read("miniprogram/pages/index/index.wxml")
for token in ['class="tips-swiperitem"', 'bg-orange light', '> 平台协议与规则</text>']:
    assert token in home, token
print("Static unified announcement contracts: OK")
