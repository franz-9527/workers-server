from pathlib import Path
root=Path(__file__).parent/'source'
view=(root/'server/views/admin/settings.php').read_text(encoding='utf-8')
css=(root/'server/assets/admin.css').read_text(encoding='utf-8')

# 公告页根容器必须脱离通用两列表单网格。
needle="admin-card__body <?= $groupKey==='announcement'?'admin-announcement-settings':'admin-form-grid' ?>"
assert needle in view, 'announcement settings still inherits generic admin-form-grid'

# 独立布局：外层纵向，区内桌面两列，窄屏单列。
assert '.admin-announcement-settings {' in css
assert 'grid-template-columns: minmax(0, 1fr);' in css
assert '.admin-announcement-grid {' in css
assert 'grid-template-columns: repeat(2, minmax(0, 1fr));' in css
assert '@media(max-width:900px)' in css and '.admin-announcement-grid{grid-template-columns:1fr}' in css

# 卡片内容不得被长标题/操作区撑破，手机端操作区必须落到独立一行。
assert '.admin-announcement-module > header > div:nth-child(2)' in css
assert 'overflow-wrap: anywhere;' in css
assert '.admin-announcement-module > header > .admin-inline-actions' in css
assert 'grid-column:1 / -1;' in css
assert 'width:100%;' in css

# 新增首页公告区域仍在独立根容器内，且现有交互标记保持不变。
assert 'data-home-announcement-editor' in view
assert 'data-home-announcement-list' in view
assert 'data-home-announcement-add' in view
assert 'data-home-announcement-remove' in view

# 粗略防止 CSS 编辑引入括号不平衡。
assert css.count('{') == css.count('}'), 'admin.css brace mismatch'
print('Announcement admin layout contract: OK')
