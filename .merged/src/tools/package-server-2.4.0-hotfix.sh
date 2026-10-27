#!/usr/bin/env bash
# V2.4.1 开发分支仅打包 V2.4.0 后台覆盖包：不修改 VERSION、不执行数据库迁移。
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
BASE="$(git rev-parse refs/remotes/origin/dev/2.4.0 2>/dev/null || git rev-parse dev/2.4.0)"
if [[ -n "$(git diff --name-only "$BASE"..HEAD -- server/database server/VERSION)" ]]; then
  echo 'ERROR: 本次热修不允许修改数据库或 server/VERSION，请重新确认是否需要升级包' >&2
  exit 1
fi
DEST="${1:-dist}"
mkdir -p "$DEST"
DEST="$(cd "$DEST" && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
STAGE="$TMP/backend"
mkdir -p "$STAGE"
git archive --format=tar HEAD:server | tar -xf - -C "$STAGE"
[[ "$(cat "$STAGE/VERSION")" == "2.4.0" ]] || { echo 'ERROR: 后台代码版本必须为 2.4.0' >&2; exit 1; }

# 线上环境文件和运行数据只保留现场版本；.user.ini 在宝塔环境可能带不可变属性，不进入覆盖包。
rm -f "$STAGE/config/installed.php" "$STAGE/.env" "$STAGE/.user.ini"
rm -rf "$STAGE/storage" "$STAGE/tests"
printf '%s\n' "$(git rev-parse HEAD)" > "$STAGE/SOURCE-COMMIT.txt"

# 不信任打包机文件权限：目录固定 0755、普通文件固定 0644，避免 0600 权限被 ZIP 带入生产环境。
find "$STAGE" -type d -exec chmod 0755 {} +
find "$STAGE" -type f -exec chmod 0644 {} +

OUT="$DEST/workers-server-v2.4.0-hotfix.zip"
(cd "$STAGE" && zip -qr "$OUT" .)
unzip -t "$OUT" >/dev/null

BAD_PERMISSIONS="$(zipinfo -l "$OUT" | awk '
  $1 ~ /^d/ && $1 != "drwxr-xr-x" { print $1 " " $NF }
  $1 ~ /^-/ && $1 != "-rw-r--r--" { print $1 " " $NF }
  $1 ~ /^l/ { print $1 " " $NF }
')"
if [[ -n "$BAD_PERMISSIONS" ]]; then
  echo 'ERROR: ZIP 含非标准 Unix 权限或符号链接；目录必须 0755、普通文件必须 0644' >&2
  printf '%s\n' "$BAD_PERMISSIONS" >&2
  exit 1
fi

FILES="$TMP/package-files.txt"
unzip -Z1 "$OUT" > "$FILES"
for f in \
  VERSION \
  api/index.php \
  admin/index.php \
  adminapi/upgrade/index.php \
  app/Http/Api/ApiKernel.php \
  app/Http/Admin/AdminKernel.php \
  app/Foundation/ApplicationContainer.php \
  app/Domain/Money/WalletPolicy.php \
  app/Application/Money/UserWalletManager.php \
  app/Contracts/UserWalletRepository.php \
  app/Infrastructure/Persistence/DatabaseUserWalletRepository.php \
  app/Domain/Scheduling/SchedulerTaskCatalog.php \
  app/Application/Scheduling/SchedulerRunner.php \
  app/Contracts/SchedulerGateway.php \
  app/Infrastructure/Scheduling/LegacySchedulerGateway.php \
  app/Application/Messaging/ChannelDispatcher.php \
  app/Contracts/PaymentGateway.php \
  app/Contracts/SmsGateway.php \
  app/Contracts/ObjectStorage.php \
  app/Contracts/SubscribeMessageGateway.php \
  app/Contracts/AdminAlertGateway.php \
  app/Contracts/ContentModerationGateway.php \
  app/Services/WeComWebhookService.php \
  app/Services/FormComponentTypes.php \
  app/Services/MessageEventCatalog.php \
  app/Services/SmsTemplateCatalog.php \
  app/Services/UserWalletService.php \
  app/Services/PaidSmsDispatchService.php \
  app/Services/OrderNotificationService.php \
  app/Services/WeChatSubscribeMessageService.php \
  app/Services/WeChatContentSecurityService.php \
  app/Services/WeChatContentSecurityGateService.php \
  app/Services/SchedulerService.php \
  bin/scheduler.php \
  views/admin/form-designer.php \
  views/admin/settings.php \
  views/admin/settings-subscription-editor.php \
  views/admin/settings-sms-templates.php \
  views/admin/settings-event-switches.php \
  assets/admin.js \
  assets/admin.css
do
  grep -Fxq "$f" "$FILES" || { echo "ERROR: 缺少后台文件 $f" >&2; exit 1; }
done
! grep -Eq '^(server/|tests/|tools/|config/installed.php|storage/|\.env$|\.user\.ini$|database/upgrades/2\.4\.1\.sql$)' "$FILES" || { echo 'ERROR: ZIP 含测试资产、外层目录、现场配置或不允许的运行文件' >&2; exit 1; }
(cd "$DEST" && sha256sum "$(basename "$OUT")" > "$(basename "$OUT").sha256" && sha256sum -c "$(basename "$OUT").sha256")
echo "V2.4.0 后台覆盖包：$OUT"
echo "SHA-256 清单：$OUT.sha256"
