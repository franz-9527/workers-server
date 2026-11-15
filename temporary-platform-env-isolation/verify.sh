#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
test "$(cat SOURCE_SHA)" = "0436fcd32b51a8e7154e4876d2bb9ba2ad86be70"
php -l server/app/Infrastructure/Safety/TestingEnvironmentPolicy.php
php -l server/app/Infrastructure/Persistence/PlatformOrderCustomerAccountScope.php
php -l server/app/Infrastructure/Persistence/DatabasePlatformOrderAdminRepository.php
php -l server/views/admin/platform-orders.php
php environment-scope.php
python3 - <<'PY'
from pathlib import Path
repo=Path('server/app/Infrastructure/Persistence/DatabasePlatformOrderAdminRepository.php').read_text()
view=Path('server/views/admin/platform-orders.php').read_text()
checks={
 'production dashboard filters demo orders':"' AND o.is_demo=0'" in repo,
 'legacy user id path uses shared scope':'WHERE u.id=:id AND " . $scope' in repo,
 'public ref path uses shared scope':'WHERE BINARY u.public_id=BINARY :ref AND " . $scope' in repo,
 'formal UI says real users only':'正式站仅允许关联真实、有效微信身份客户' in view,
 'testing UI mentions demo accounts':'测试站可关联真实有效微信客户，也可关联系统演示用户/商家/师傅' in view,
}
for k,v in checks.items(): print(('[PASS] ' if v else '[FAIL] ')+k)
bad=[k for k,v in checks.items() if not v]
if bad: raise SystemExit(', '.join(bad))
PY
echo "[PASS] production/testing account isolation regression complete"
