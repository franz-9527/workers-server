#!/bin/bash
set -euo pipefail
umask 022
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.."
package=$(pwd -P)
site=/www/wwwroot/worker
chat=$site/chat
state=$chat/bin/state
legacy_state=/var/lib/bixuan-chat-prod
php=/www/server/php/83/bin/php
fail() { printf '%s\n' "$1" >&2; exit 2; }

[[ $# -le 1 && ( $# == 0 || "${1:-}" =~ ^--(init|check)-recovery$ ) ]] || fail '用法：update-production.sh [--init-recovery|--check-recovery]'
[[ $EUID == 0 ]] || fail '请在宝塔 root 终端运行升级命令。'
[[ "$package" == "$chat/update" && $(stat -c '%U:%a' -- "$package") == root:700 ]] ||
  fail '请将升级包解压到 /www/wwwroot/worker/chat/update，目录属主 root、权限 0700。'
[[ $(realpath -e -- "$chat") == "$chat" && -x "$php" ]] || fail '正式站聊天目录或 PHP 8.3 CLI 不存在。'
[[ $(stat -c '%U:%G:%a' -- "$chat") =~ ^(root|www):www:750$ ]] || fail '正式聊天目录应为 root:www 或 www:www，权限 0750。'
[[ ! -L "$chat/bin" && -d "$chat/bin" && ! -L "$chat/bin/releases" && -d "$chat/bin/releases" ]] ||
  fail '正式 Go bin 与 releases 必须是实际目录，不能使用符号链接。'
sha256sum -c SHA256SUMS.txt
[[ -f deploy/release-public.key && ! -L deploy/release-public.key ]] || fail '升级包缺少正式发布公钥。'
key_dir=/var/lib/bixuan-chat-release-key
[[ ! -L "$key_dir" ]] || fail '正式发布公钥目录不能使用符号链接。'
if [[ -f "$key_dir/public.key" ]]; then
  cmp -s deploy/release-public.key "$key_dir/public.key" || fail '正式发布公钥已登记，需按独立流程轮换。'
fi
if [[ -f "$chat/deploy/release-public.key" ]]; then
  cmp -s deploy/release-public.key "$chat/deploy/release-public.key" || fail '正式发布公钥已登记，需按独立流程轮换。'
fi
sha=$("$php" -r '$v=json_decode(file_get_contents("payload/version.json"),true,32,JSON_THROW_ON_ERROR);echo $v["source_revision"]??"";')
[[ "$sha" =~ ^[a-f0-9]{40}$ ]] || fail '升级包版本无效。'
old=$(readlink -- "$chat/bin/current")
[[ "$old" =~ ^releases/[a-f0-9]{40}$ && -x "$chat/bin/$old/chatd" ]] ||
  fail '找不到已安装的正式版，请检查 bin/current。'
[[ $(readlink -- "$chat/bin/chatd") == current/chatd ]] || fail '宝塔入口 bin/chatd 未指向 current/chatd。'
[[ ! -e "$chat/chat.json" ]] || fail '发现手动 chat.json。保留现有配置，请先核对手动配置的升级方式。'
"$php" -r '$c=require $argv[1];exit(($c["app"]["env"]??"")==="production"?0:2);' "$site/config/installed.php" ||
  fail '目标站点未确认为正式环境。'
for f in deploy/production-runtime.php deploy/init-production-recovery.php deploy/web-update.php site/bootstrap.php site/app/Application/Chat/ChatGoUpdate.php site/app/Application/Chat/ChatRuntimePaths.php site/app/Application/Chat/ChatRuntimeControl.php site/app/Http/Admin/Modules/ChatSettingsAdminModule.php site/views/admin/chat-settings.php site/app/Infrastructure/Chat/ChatRecoveryGate.php site/app/Infrastructure/Chat/ChatRecoveryCheckpoint.php site/app/Application/Chat/ChatGoRuntimeConfig.php site/app/Application/Chat/ChatGoRuntimeStatus.php site/bin/chat-recovery.php site/app/Infrastructure/Messaging/ChatInternalAuth.php site/chat-internal.php site/bin/chat-reminders.php site/bin/chat-images.php; do
  "$php" -l "$f"
done
/bin/bash -n deploy/bt-run.sh
/bin/bash -n deploy/bt-site.sh
python3 -m py_compile helpers/preflight.py helpers/images/inspect.py
# Initialize the recovery baseline only on explicit first-deployment requests.
if [[ "${1:-}" =~ ^--(init|check)-recovery$ ]]; then
  control=/www/backup-control/bixuan-chat/production
  [[ -t 0 ]] || fail '首次恢复基线需在宝塔交互终端运行。'
  mysqlbinlog=$(command -v mysqlbinlog || true)
  [[ -n "$mysqlbinlog" ]] || mysqlbinlog=/www/server/mysql/bin/mysqlbinlog
  [[ -x "$mysqlbinlog" ]] || fail '找不到 mysqlbinlog。'
  read -r -p 'MySQL 维护账号 [root]：' recovery_user
  read -rs -p 'MySQL 维护密码：' recovery_password
  printf '\n'
fi

if [[ "${1:-}" == --check-recovery ]]; then
  CHAT_RECOVERY_DB_USER="${recovery_user:-root}" CHAT_RECOVERY_DB_PASSWORD="$recovery_password" \
    "$php" deploy/init-production-recovery.php "$mysqlbinlog" --check "$package/site/app/Infrastructure/Chat/ChatRecoveryCheckpoint.php"
  exit $?
fi

# Migrate the old runtime directory only after the signed package and PHP source checks pass.
[[ ! -L "$legacy_state" ]] || fail '旧正式 Go 状态路径不能是符号链接。'
[[ ! -L "$state" ]] || fail '正式 Go 状态目录不能是符号链接。'
if [[ -e "$legacy_state" ]]; then
  [[ -d "$legacy_state" && ! -L "$legacy_state" ]] || fail '旧正式 Go 状态路径不是安全目录，请人工核对。'
  [[ ! -e "$state" ]] || fail '新旧 Go 状态目录同时存在，请先核对数据，禁止自动覆盖。'
  [[ $(stat -c '%U:%G:%a' -- "$legacy_state") == www:www:700 ]] || fail '旧 Go 状态目录权限不符合迁移要求，未移动任何数据。'
  [[ -z "$(find "$legacy_state" -type l -print -quit)" ]] || fail '旧 Go 状态目录含符号链接，拒绝自动迁移。'
  [[ -z "$(find "$legacy_state" -mindepth 1 ! -path "$legacy_state/run.lock" -perm /0077 -print -quit)" ]] || fail '旧 Go 状态目录含对组/其他用户开放的文件，拒绝自动迁移。'
  [[ ! -e "$legacy_state.migrated" ]] || fail '旧 Go 状态迁移备份已存在，请先人工核对。'
  [[ ! -e "$legacy_state/run.lock" || -f "$legacy_state/run.lock" ]] || fail '旧 Go 运行锁不是普通文件，拒绝迁移。'
  if [[ ! -e "$legacy_state/run.lock" ]]; then
    runuser -u www -- bash -c 'umask 077; : >> "$1"' _ "$legacy_state/run.lock" || fail '无法创建旧 Go 运行锁。'
  fi
  chown www:www "$legacy_state/run.lock" || fail '无法规范旧 Go 运行锁属主。'
  chmod 0600 "$legacy_state/run.lock" || fail '无法规范旧 Go 运行锁权限。'
  { exec 9>>"$legacy_state/run.lock"; } || fail '无法打开旧 Go 运行锁。'
  flock -n 9 || fail '旧 Go 进程仍在运行，请先在宝塔停止聊天项目后再迁移。'
  install -d -m 0700 -o www -g www "$state"
  if ! cp -a -- "$legacy_state/." "$state/"; then rm -rf -- "$state"; fail '旧 Go 状态迁移复制失败，原目录保持不变。'; fi
  if ! chown -R www:www "$state"; then rm -rf -- "$state"; fail '迁移后的 Go 状态属主修复失败，原目录保持不变。'; fi
  [[ -z "$(find "$state" -type l -print -quit)" && -z "$(find "$state" -perm /0077 -print -quit)" ]] || {
    rm -rf -- "$state"; fail '迁移后的 Go 状态权限复核失败，原目录保持不变。';
  }
  if ! mv -- "$legacy_state" "$legacy_state.migrated"; then
    rm -rf -- "$state"
    fail '旧 Go 状态目录改名失败，已撤销新目录复制，原目录保持不变。'
  fi
  exec 9>&-
  echo "CHAT_STATE_MIGRATED: $legacy_state -> $state"
elif [[ ! -d "$state" ]]; then
  install -d -m 0700 -o www -g www "$state"
fi
[[ -d "$state" && ! -L "$state" && $(stat -c '%U:%G:%a' -- "$state") == www:www:700 ]] ||
  fail '正式 Go 状态目录应为 /www/wwwroot/worker/chat/bin/state，www:www，权限 0700。'


# The panel launcher owns the state lock throughout its run.
[[ ! -L "$state/run.lock" ]] || fail '正式运行锁不能是符号链接。'
runuser -u www -- touch "$state/run.lock"
{ exec 9>>"$state/run.lock"; } || fail '无法打开正式服务运行锁。'
flock -n 9 || fail '请先在宝塔停止正式 Go 项目 chat，再执行升级。'


backups="$chat/update-backup"
[[ ! -L "$backups" ]] || fail '备份目录不能使用符号链接。'
install -d -m 0700 -o root -g root "$backups"
backup=$(mktemp -d "$backups/.pending.XXXXXXXX")
cleanup_backup(){ rm -rf -- "$backup"; }
trap cleanup_backup EXIT
printf '%s\n' "$old" > "$backup/current"
replace() {
  local source=$1 target=$2 mode=$3 owner=$4 group=$5
  local staged
  staged=$(mktemp -- "$(dirname -- "$target")/.chat-update.XXXXXXXX")
  install -m "$mode" -o "$owner" -g "$group" -- "$source" "$staged"
  mv -fT -- "$staged" "$target"
}
for f in deploy/bt-run.sh deploy/bt-site.sh deploy/production-runtime.php deploy/web-update.php deploy/release-public.key chat.production.example.json helpers/preflight.py helpers/images/inspect.py; do
  if [[ -f "$chat/$f" ]]; then cp -a --parents -- "$chat/$f" "$backup"; fi
done
for f in bootstrap.php app/Application/Chat/ChatGoUpdate.php app/Application/Chat/ChatRuntimePaths.php app/Application/Chat/ChatRuntimeControl.php app/Http/Admin/Modules/ChatSettingsAdminModule.php views/admin/chat-settings.php app/Infrastructure/Chat/ChatRecoveryGate.php app/Infrastructure/Chat/ChatRecoveryCheckpoint.php app/Application/Chat/ChatGoRuntimeConfig.php app/Application/Chat/ChatGoRuntimeStatus.php bin/chat-recovery.php app/Infrastructure/Messaging/ChatInternalAuth.php chat-internal.php bin/chat-reminders.php bin/chat-images.php; do
  if [[ -f "$site/$f" ]]; then cp -a --parents -- "$site/$f" "$backup"; fi
done
if [[ -f "$chat/bin/deployed-sha" ]]; then cp -a --parents -- "$chat/bin/deployed-sha" "$backup"; fi
install -d -m 0755 -- "$chat/bin/releases/$sha"
replace payload/chatd "$chat/bin/releases/$sha/chatd" 0755 root root
replace payload/version.json "$chat/bin/releases/$sha/version.json" 0644 root root
awk '$2 == "payload/chatd" {print $1}' SHA256SUMS.txt > "$chat/bin/releases/$sha/sha256"
actual_version=$("$chat/bin/releases/$sha/chatd" version)
"$php" -r '$actual=json_decode($argv[1],true,32,JSON_THROW_ON_ERROR);$expected=json_decode(file_get_contents("payload/version.json"),true,32,JSON_THROW_ON_ERROR);exit($actual===$expected?0:2);' "$actual_version" ||
  fail '二进制内置版本与升级包不符。'
rollback() {
  local status=$?
  if (( status == 0 )); then return; fi
  trap - EXIT
  set +e
  local f target
  for f in bootstrap.php app/Application/Chat/ChatGoUpdate.php app/Application/Chat/ChatRuntimePaths.php app/Application/Chat/ChatRuntimeControl.php app/Http/Admin/Modules/ChatSettingsAdminModule.php views/admin/chat-settings.php app/Infrastructure/Chat/ChatRecoveryGate.php app/Infrastructure/Chat/ChatRecoveryCheckpoint.php app/Application/Chat/ChatGoRuntimeConfig.php app/Application/Chat/ChatGoRuntimeStatus.php bin/chat-recovery.php app/Infrastructure/Messaging/ChatInternalAuth.php chat-internal.php bin/chat-reminders.php bin/chat-images.php; do
    target="$site/$f"
    if [[ -f "$backup$target" ]]; then cp -aT -- "$backup$target" "$target"; else rm -f -- "$target"; fi
  done
  for f in deploy/bt-run.sh deploy/bt-site.sh deploy/production-runtime.php deploy/web-update.php deploy/release-public.key chat.production.example.json helpers/preflight.py helpers/images/inspect.py; do
    target="$chat/$f"
    if [[ -f "$backup$target" ]]; then cp -aT -- "$backup$target" "$target"; else rm -f -- "$target"; fi
  done
  if [[ -f "$backup$chat/bin/deployed-sha" ]]; then cp -aT -- "$backup$chat/bin/deployed-sha" "$chat/bin/deployed-sha"; fi
  if [[ $(readlink -- "$chat/bin/current") != "$old" ]]; then
    local previous
    previous=$(mktemp -d "$chat/bin/.chat-previous.XXXXXXXX")
    ln -s -- "$old" "$previous/current"
    mv -fT -- "$previous/current" "$chat/bin/current"
    rmdir -- "$previous"
  fi
  rm -rf -- "$backups/failed"
  mv -T -- "$backup" "$backups/failed"
  echo "升级未完成，已尝试恢复旧文件，备份 $backups/failed" >&2
  exit "$status"
}
trap rollback EXIT
for f in bootstrap.php app/Application/Chat/ChatGoUpdate.php app/Application/Chat/ChatRuntimePaths.php app/Application/Chat/ChatRuntimeControl.php app/Http/Admin/Modules/ChatSettingsAdminModule.php views/admin/chat-settings.php app/Infrastructure/Chat/ChatRecoveryGate.php app/Infrastructure/Chat/ChatRecoveryCheckpoint.php app/Application/Chat/ChatGoRuntimeConfig.php app/Application/Chat/ChatGoRuntimeStatus.php bin/chat-recovery.php app/Infrastructure/Messaging/ChatInternalAuth.php chat-internal.php bin/chat-reminders.php bin/chat-images.php; do
  if [[ -f "$site/$f" ]]; then
    read -r mode owner group < <(stat -c '%a %U %G' -- "$site/$f")
  else
    mode=0640 owner=root group=www
  fi
  replace "site/$f" "$site/$f" "$mode" "$owner" "$group"
done
for f in deploy/bt-run.sh deploy/bt-site.sh deploy/production-runtime.php deploy/web-update.php deploy/release-public.key chat.production.example.json helpers/preflight.py helpers/images/inspect.py; do
  replace "$f" "$chat/$f" 0640 root www
done
runtime_status=0
runuser -u www -- "$php" "$chat/deploy/production-runtime.php" "$site" "$state" "$sha" || runtime_status=$?
(( runtime_status == 0 || runtime_status == 4 )) || fail '正式 Go 运行配置生成失败。'
preflight_config="$state/production.runtime.json"
preflight_text_env=''
if (( runtime_status == 0 )) && [[ -s "$state/production.text.env" && -s "$state/production.text.json" ]]; then
  preflight_config="$state/production.text.json"
  preflight_text_env="$state/production.text.env"
fi
runuser -u www -- /bin/bash -c 'source "$1"; [[ ! -s "$2" ]] || source "$2"; exec "$3" check-config --config "$4"' \
  _ "$state/production.runtime.env" "$preflight_text_env" "$chat/bin/releases/$sha/chatd" "$preflight_config"
if [[ "${1:-}" == --init-recovery ]]; then
  for dir in /www/backup-control /www/backup-control/bixuan-chat "$control"; do
    [[ ! -L "$dir" ]] || fail '恢复控制目录不能使用符号链接。'
  done
  if [[ ! -d "$control" ]]; then
    install -d -m 2750 -o root -g www "$control"
  fi
  [[ $(stat -c '%U:%G:%a' -- "$control") == root:www:2750 ]] ||
    fail '恢复目录应为 root:www，权限 2750。'
  CHAT_RECOVERY_DB_USER="${recovery_user:-root}" CHAT_RECOVERY_DB_PASSWORD="$recovery_password" \
    "$php" deploy/init-production-recovery.php "$mysqlbinlog"
  unset recovery_user recovery_password
fi
install -d -m 0755 -o root -g root "$key_dir"
if [[ ! -f "$key_dir/public.key" ]]; then
  replace deploy/release-public.key "$key_dir/public.key" 0644 root root
fi
switch=$(mktemp -d "$chat/bin/.chat-current.XXXXXXXX")
ln -s -- "releases/$sha" "$switch/current"
mv -fT -- "$switch/current" "$chat/bin/current"
rmdir -- "$switch"
printf '%s\n' "$sha" > "$backup/deployed-sha.new"
replace "$backup/deployed-sha.new" "$chat/bin/deployed-sha" 0644 root root
trap - EXIT
rm -rf -- "$backups/previous" "$backups/failed"
mv -T -- "$backup" "$backups/previous"
echo "CHAT_DEPLOY_BUILT: $sha"
echo "旧文件备份 $backups/previous"
# Legacy installers wrote one root-owned, private backup per attempt.
shopt -s nullglob
for legacy in /var/lib/bixuan-chat-update.????????; do
  [[ -d "$legacy" && ! -L "$legacy" && $(stat -c '%U:%a' -- "$legacy") == root:700 && -f "$legacy/current" ]] || continue
  previous=$(cat -- "$legacy/current")
  [[ "$previous" =~ ^releases/[a-f0-9]{40}$ ]] || continue
  rm -rf -- "$legacy"
done
echo '请在宝塔启动正式 Go 项目 chat。'
