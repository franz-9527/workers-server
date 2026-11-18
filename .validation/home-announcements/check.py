from pathlib import Path
root=Path(__file__).parent/'source'
def read(path): return (root/path).read_text(encoding='utf-8')

query=read('server/app/Application/Messaging/AnnouncementQuery.php')
assert 'public function home():array' in query
assert "'announcement.home.items'" in query
assert "if($scene==='home')" in query
assert "$pageScenes=['store','order_submit','order_hall','merchant_auth','quote_orders']" in query

settings_read=read('server/app/Application/Messaging/SettingsRead.php')
assert "$keys=['announcement.home.items']" in settings_read
assert "foreach (['store','order_submit','order_hall','merchant_auth','quote_orders'] as $scene)" in settings_read
assert "['home','store'" not in settings_read

policy=read('server/app/Domain/Messaging/SettingsValuePolicy.php')
assert "$announcementKeys=['announcement.home.items'=>true]" in policy
assert "['store','order_submit','order_hall','merchant_auth','quote_orders','platform_agreement']" in policy
assert '首页公告最多配置 10 条' in policy

api=read('server/app/Http/Api/Modules/MessagingApiModule.php')
assert "'announcement.home'=>'GET'" in api
assert "if($route==='announcement.home')$data=$this->announcement->home();" in api

repo=read('server/app/Infrastructure/Settings/DatabaseAdminConfigurationRepository.php')
assert "'announcement.home.items'" in repo
assert '旧首页单条公告' in repo
assert "'home'=>['首页公告'" not in repo

view=read('server/views/admin/settings.php')
assert 'data-home-announcement-editor' in view
assert 'data-home-announcement-add' in view
assert 'settings[announcement.home.items]' in view
scene_line=next(line for line in view.splitlines() if '$announcementScenes=' in line)
assert "'home'=>" not in scene_line
assert '其他页面公告 · 单条模式' in view
assert '首页滚动公告 · 独立多条列表' in view

admin_js=read('server/assets/admin.js')
assert 'function initHomeAnnouncementEditors()' in admin_js
assert '首页公告最多配置 10 条' in admin_js

home_js=read('miniprogram/pages/index/index.js')
assert "require('../../utils/HomeAnnouncementLoader.js')" in home_js
assert 'HomeAnnouncements.load()' in home_js
assert "Announcements.load('home')" not in home_js
assert 'order-announcement' in home_js  # comment explicitly guards against reuse

home_wxml=read('miniprogram/pages/index/index.wxml')
assert '<swiper class="tips-swiperitem"' in home_wxml
assert 'radius-lg bg-orange light' in home_wxml
assert 'Headtips.length > 1' in home_wxml
assert '<order-announcement' not in home_wxml

home_wxss=read('miniprogram/pages/index/index.wxss')
other_wxml=read('miniprogram/components/order-announcement/index.wxml')
other_wxss=read('miniprogram/components/order-announcement/index.wxss')
assert '.notice{' not in home_wxss
assert 'tips-swiperitem' not in other_wxml
assert 'tips-swiperitem' not in other_wxss
assert 'class="notice"' in other_wxml and '.notice{' in other_wxss

print('Static home announcement separation and original rolling UI contract: OK')
