#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
test "$(cat SOURCE_SHA)" = "cc8aa636f50a9fb1a306df720677b9e8e2a9ddb8"
php -l server/app/Infrastructure/Persistence/PlatformOrderCustomerAccountScope.php
php -l server/app/Infrastructure/Persistence/DatabaseMiniPlatformOrderCreationRepository.php
php -l server/app/Infrastructure/Persistence/DatabasePlatformOrderAdminRepository.php
php -l server/views/admin/platform-orders.php
php demo-search-mysql.php
python3 - <<'PY'
from pathlib import Path
db=Path('server/app/Infrastructure/Persistence/DatabasePlatformOrderAdminRepository.php').read_text()
view=Path('server/views/admin/platform-orders.php').read_text()
checks={
 'admin create reuses linkable scope':'PlatformOrderCustomerAccountScope::linkableSql' in db,
 'admin create classifies demo scope':'PlatformOrderCustomerAccountScope::demoSql' in db,
 'order insert writes demo domain':'order_source_type,is_demo,audit_status' in db and "':is_demo'" not in db,
 'is_demo bind exists':"'is_demo'=>$isDemo" in db,
 'dashboard reads demo domain':'o.lng,o.is_demo' in db,
 'search UI labels demo accounts':"customer.is_demo?'演示账号':'真实账号'" in view,
 'demo orders skip production auto publish repair':"&&empty($row['is_demo'])" in view,
}
# Exact SQL placeholder exists as raw :is_demo (not quoted in source)
checks['order insert has is_demo placeholder']=":is_demo,'pending','hidden'" in db
bad=[]
for k,v in checks.items():
 print(('[PASS] ' if v else '[FAIL] ')+k)
 if not v: bad.append(k)
if bad: raise SystemExit(', '.join(bad))
PY
python3 - <<'PY'
from pathlib import Path
import re
view=Path('server/views/admin/platform-orders.php').read_text()
scripts=re.findall(r'<script>(.*?)</script>',view,re.S)
Path('/tmp/platform-demo-search.js').write_text(scripts[-1])
PY
node --check /tmp/platform-demo-search.js
echo "[PASS] inline JavaScript syntax"
