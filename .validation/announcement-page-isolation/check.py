from pathlib import Path
root=Path(__file__).parent/'source'
def read(path): return (root/path).read_text(encoding='utf-8')

query=read('server/app/Application/Messaging/AnnouncementQuery.php')
assert "['home','store','order_submit','order_hall','merchant_auth','quote_orders']" in query
assert "$scene==='platform_agreement'" in query
assert "'announcement.enabled'" not in query

settings_read=read('server/app/Application/Messaging/SettingsRead.php')
assert "['home','store','order_submit','order_hall','merchant_auth','quote_orders']" in settings_read
assert "announcement.platform_agreement." in settings_read

repo=read('server/app/Infrastructure/Settings/DatabaseAdminConfigurationRepository.php')
assert "'platform_agreement'=>['首页平台协议入口','1','平台协议与规则','/subpackages/support/agreements/index']" in repo
assert "if($group==='announcement')$this->ensureAnnouncementDefaults()" in repo

view=read('server/views/admin/settings.php')
scene_line=next(line for line in view.splitlines() if '$announcementScenes=' in line)
assert 'platform_agreement' not in scene_line
assert '首页特殊入口 · 与页面运营公告隔离' in view
assert 'announcement.platform_agreement.enabled' in view
assert '平台协议与规则' in view

home_js=read('miniprogram/pages/index/index.js')
assert "Announcements.load('home')" in home_js
assert "Announcements.load('platform_agreement')" in home_js
assert "text: '平台协议与规则'" in home_js
assert "link: '/subpackages/support/agreements/index'" in home_js

home_wxml=read('miniprogram/pages/index/index.wxml')
loop=home_wxml.index('<block wx:for="{{Headtips}}"')
agreement=home_wxml.index('PlatformAgreement.text')
assert loop>=0 and agreement>loop
assert 'wx:if="{{PlatformAgreement.enabled && PlatformAgreement.text}}"' in home_wxml
assert 'class="radius-lg bg-orange light"' in home_wxml
assert 'cuIcon-safe text-orange' in home_wxml

expected={
 'miniprogram/pages/orderCenter/index.wxml':'<order-announcement scene="quote_orders"></order-announcement>',
 'miniprogram/pages/orderHall/orderHall.wxml':'<order-announcement scene="order_hall"></order-announcement>',
 'miniprogram/pages/orderSubmit/index.wxml':'<order-announcement scene="order_submit"></order-announcement>',
 'miniprogram/subpackages/account/merchantAuth/index.wxml':'<order-announcement scene="merchant_auth"></order-announcement>',
}
for path,token in expected.items():
    assert token in read(path), path+' lost page-specific announcement scene'

print('Static page-specific announcement and isolated platform agreement contracts: OK')
