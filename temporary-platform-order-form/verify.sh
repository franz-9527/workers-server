#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
test "$(cat SOURCE_SHA)" = "9c04180bef727d2c4bc3b008e1774471742b0fce"

php -l server/app/Application/Order/PlatformOrderAdmin.php
php -l server/app/Http/Admin/Modules/PlatformOrderAdminModule.php
php -l server/app/Infrastructure/Persistence/DatabasePlatformOrderAdminRepository.php
php -l server/views/admin/platform-orders.php
php server/tests/v250_platform_order_application.php

python3 - <<'PY'
from pathlib import Path
import re
module=Path("server/app/Http/Admin/Modules/PlatformOrderAdminModule.php").read_text()
app=Path("server/app/Application/Order/PlatformOrderAdmin.php").read_text()
repo=Path("server/app/Infrastructure/Persistence/DatabasePlatformOrderAdminRepository.php").read_text()
view=Path("server/views/admin/platform-orders.php").read_text()
css=Path("server/assets/admin.css").read_text()
checks={
"web customer search route":"'platform-orders.customers'=>'customers'" in module,
"search reuses safe customer service":"miniPlatformOrderCreation()->customers" in module,
"search returns JSON":"json_response(['ok'=>true,'data'=>$data])" in module,
"public customer ref accepted":"'customer_ref'=>$customerRef" in app,
"public ref server resolved":"BINARY u.public_id=BINARY :ref" in repo,
"only active wechat identity linked":"wi.status='active'" in repo,
"internal id not used by new picker":'name="user_id"' not in view[view.index('data-modal="platform-order-create"'):],
"picker submits public ref":'name="customer_ref"' in view,
"picker supports nickname mobile public id":"搜索昵称 / 11位完整手机号 / 客户编号" in view,
"modal uses xl system class":'admin-modal admin-modal--xl' in view and 'admin-modal__dialog--wide' not in view,
"five column item layout exists":"#platform-order-form .admin-grid--5" in css,
"dark mode compatible check cards":"background:var(--admin-surface-raised)" in css,
"hidden pricing inputs disabled":"setGroupDisabled(fixed,yes)" in view,
"hidden labor input disabled":"input.disabled=!yes" in view,
"customer unresolved query blocks submit":"请从搜索结果中选择客户" in view,
"item rows capped at fifty":"items.children.length>=50" in view,
}
for k,v in checks.items(): print(("[PASS] " if v else "[FAIL] ")+k)
bad=[k for k,v in checks.items() if not v]
if bad: raise SystemExit("failed: "+", ".join(bad))
scripts=re.findall(r'<script>(.*?)</script>',view,re.S)
assert scripts, "inline script missing"
Path("/tmp/platform-order-inline.js").write_text(scripts[-1])
print("[PASS] extracted platform order inline JS")
PY

node --check /tmp/platform-order-inline.js
echo "[PASS] platform order inline JavaScript syntax"
echo "[PASS] isolated platform order form regression complete"
