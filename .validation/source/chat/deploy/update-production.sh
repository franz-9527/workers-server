#!/bin/bash
# Root installation of the Go part of a complete, same-SHA PHP + Go release.
set -euo pipefail
umask 022
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.."
package=$(pwd -P)
site=/www/wwwroot/worker
chat=$site/chat
state=$chat/bin/state
fail(){ printf '%s\n' "$1" >&2; exit 2; }
[[ $# -le 1 && ( $# == 0 || "${1:-}" =~ ^--(init|check)-recovery$ ) ]] || fail '用法：update-production.sh [--init-recovery|--check-recovery]'
[[ $EUID == 0 ]] || fail '请在宝塔 root 终端运行。'
[[ "$package" == "$chat/update" && $(stat -c '%U:%a' -- "$package") == root:700 ]] || fail '请将可信发行包解压到 /www/wwwroot/worker/chat/update（root:0700）。'
[[ $(realpath -e -- "$chat") == "$chat" && $(stat -c '%U:%G:%a' -- "$chat") =~ ^(root|www):www:750$ ]] || fail '正式聊天目录应为 root:www 或 www:www，权限 0750。'
[[ -z $(find "$package" -type l -print -quit) ]] || fail '发行包不能包含符号链接。'
sha256sum --strict -c SHA256SUMS.txt
files=(deploy/bt-run.sh deploy/bt-site.sh deploy/production-runtime.php deploy/web-update.php deploy/init-production-recovery.php deploy/nginx.conf.example chat.production.example.json helpers/preflight.py helpers/images/inspect.py helpers/media/inspect.py)
for f in "${files[@]}" deploy/update-production.sh payload/chatd payload/version.json PHP-VERSION SOURCE-COMMIT.txt; do
  [[ -f "$f" ]] && awk -v path="./$f" '$2==path || $2=="*"path {found=1} END {exit !found}' SHA256SUMS.txt || fail "发行包缺少校验项：$f"
done
source deploy/bt-site.sh
chat_php_cli || exit $?
php=$PHP_BIN
"$php" -r '$c=require $argv[1];exit(($c["app"]["env"]??"")==="production"?0:2);' "$site/config/installed.php" || fail '目标站点不是正式环境。'
sha=$(tr -d '\r\n' < SOURCE-COMMIT.txt)
[[ "$sha" =~ ^[a-f0-9]{40}$ ]] || fail '发行包源码 SHA 无效。'
[[ $(cat "$site/SOURCE-COMMIT.txt" 2>/dev/null || true) == "$sha" && $(cat "$site/VERSION") == $(cat PHP-VERSION) ]] || fail '请先安装同一源码 SHA 的完整 PHP 发行包并完成数据库升级，不要局部覆盖 PHP 文件。'
runuser -u www -- "$php" -r 'define("WORKER_BASE_PATH",$argv[1]);require $argv[1]."/bootstrap.php";exit(is_installed()&&!upgrade_required()?0:2);' "$site" || fail '请先完成 PHP 数据库升级。'
if [[ "${1:-}" =~ ^--(init|check)-recovery$ ]]; then
  control=/www/backup-control/bixuan-chat/production
  [[ -t 0 ]] || fail '恢复检查须在交互终端运行。'
  mysqlbinlog=$(command -v mysqlbinlog || true)
  [[ -n "$mysqlbinlog" ]] || mysqlbinlog=/www/server/mysql/bin/mysqlbinlog
  [[ -x "$mysqlbinlog" ]] || fail '找不到 mysqlbinlog。'
  read -r -p 'MySQL 维护账号 [root]：' recovery_user
  read -rs -p 'MySQL 维护密码：' recovery_password
  printf '\n'
  if [[ "$1" == --check-recovery ]]; then
    CHAT_RECOVERY_DB_USER="${recovery_user:-root}" CHAT_RECOVERY_DB_PASSWORD="$recovery_password" "$php" deploy/init-production-recovery.php "$mysqlbinlog" --check "$site/app/Infrastructure/Chat/ChatRecoveryCheckpoint.php"
    exit $?
  fi
fi
[[ ! -e "$chat/chat.json" && ! -L "$chat/chat.json" ]] || fail '发现手动 chat.json，请先备份并确认切换为后台托管模式。'
for dir in "$chat/bin" "$chat/bin/releases" "$state" "$chat/deploy" "$chat/helpers" "$chat/helpers/images" "$chat/helpers/media"; do
  [[ ! -L "$dir" && ( ! -e "$dir" || -d "$dir" ) ]] || fail "Go 部署目录不是实际目录：$dir"
done
install -d -m 0750 -o root -g www "$chat/bin"
install -d -m 0755 -o root -g root "$chat/bin/releases"
if [[ ! -d "$state" ]]; then install -d -m 0700 -o www -g www "$state"; fi
[[ $(stat -c '%U:%G:%a' -- "$state") == www:www:700 ]] || fail 'Go 状态目录应为 www:www:0700。'
if [[ -e /var/lib/bixuan-chat-prod ]]; then echo '发现旧状态目录，原样保留；新托管模式不自动迁移旧配置。'; fi
[[ ! -L "$state/run.lock" && ( ! -e "$state/run.lock" || -f "$state/run.lock" ) ]] || fail '运行锁路径无效。'
runuser -u www -- bash -c 'umask 077; : >> "$1"' _ "$state/run.lock"
exec 9>>"$state/run.lock"
flock -n 9 || fail '请先在宝塔停止正式 Go 项目 chat。'
old=$(readlink -- "$chat/bin/current" 2>/dev/null || true)
if [[ -e "$chat/bin/current" || -L "$chat/bin/current" ]]; then
  [[ "$old" =~ ^releases/[a-f0-9]{40}$ && -x "$chat/bin/$old/chatd" ]] || fail '现有 bin/current 无效，请先核对。'
fi
if [[ -e "$chat/bin/chatd" || -L "$chat/bin/chatd" ]]; then
  [[ $(readlink -- "$chat/bin/chatd" || true) == current/chatd ]] || fail '现有 bin/chatd 不是托管入口，请先备份并移走旧程序。'
fi
"$php" -l deploy/production-runtime.php
"$php" -l deploy/web-update.php
bash -n deploy/bt-run.sh
bash -n deploy/bt-site.sh
backups="$chat/update-backup"
[[ ! -L "$backups" ]] || fail '备份目录不能是符号链接。'
install -d -m 0700 -o root -g root "$backups"
backup=$(mktemp -d "$backups/.pending.XXXXXXXX")
replace(){
  local src=$1 dst=$2 mode=$3 owner=$4 group=$5 tmp
  tmp=$(mktemp -- "$(dirname -- "$dst")/.chat-update.XXXXXXXX")
  install -m "$mode" -o "$owner" -g "$group" -- "$src" "$tmp"
  mv -fT -- "$tmp" "$dst"
}
for f in "${files[@]}" bin/deployed-sha; do
  [[ ! -L "$chat/$f" ]] || fail "目标不是普通文件：$f"
  if [[ -f "$chat/$f" ]]; then cp -a --parents -- "$chat/$f" "$backup"; fi
done
for f in go-web.active go-web.previous go-business.active; do
  if [[ -f "$state/$f" && ! -L "$state/$f" ]]; then cp -a --parents -- "$state/$f" "$backup"; fi
done
pending="$site/storage/private/chat-go-update/pending.chatgo"
[[ ! -L "$pending" ]] || fail '后台升级队列路径无效。'
if [[ -f "$pending" ]]; then cp -a --parents -- "$pending" "$backup"; fi
rollback(){
  local code=$? f target
  (( code != 0 )) || return
  trap - EXIT
  set +e
  for f in "${files[@]}" bin/deployed-sha; do
    target="$chat/$f"
    if [[ -f "$backup$target" ]]; then cp -aT -- "$backup$target" "$target"; else rm -f -- "$target"; fi
  done
  for f in go-web.active go-web.previous go-business.active; do
    target="$state/$f"
    if [[ -f "$backup$target" ]]; then cp -aT -- "$backup$target" "$target"; fi
  done
  if [[ -f "$backup$pending" && ! -e "$pending" ]]; then cp -aT -- "$backup$pending" "$pending"; fi
  if [[ -n "$old" ]]; then ln -sfnT -- "$old" "$chat/bin/current"; else rm -f -- "$chat/bin/current" "$chat/bin/chatd"; fi
  rm -rf -- "$backups/failed"
  mv -T -- "$backup" "$backups/failed"
  echo "升级未完成，已尝试恢复 Go 文件，备份 $backups/failed" >&2
  exit "$code"
}
trap rollback EXIT
for f in "${files[@]}"; do
  dir=$(dirname -- "$chat/$f")
  [[ ! -L "$dir" ]] || fail '部署目录不能是符号链接。'
  install -d -m 0750 -o root -g www "$dir"
  replace "$f" "$chat/$f" 0640 root www
done
# A same-SHA release can be reinstalled, but only if its binary is identical.
release="$chat/bin/releases/$sha"
[[ ! -L "$release" ]] || fail '发行目录不能是符号链接。'
install -d -m 0755 -o root -g root "$release"
for f in chatd version.json; do [[ ! -L "$release/$f" ]] || fail '发行文件不能使用符号链接。'; done
if [[ -e "$release/chatd" ]]; then cmp -s payload/chatd "$release/chatd" || fail '同一 SHA 的现有二进制与发行包不一致。'; fi
replace payload/chatd "$release/chatd" 0755 root root
replace payload/version.json "$release/version.json" 0644 root root
actual=$("$release/chatd" version)
"$php" -r '$a=json_decode($argv[1],true,32,JSON_THROW_ON_ERROR);$b=json_decode(file_get_contents("payload/version.json"),true,32,JSON_THROW_ON_ERROR);exit($a===$b&&($a["source_revision"]??"")===$argv[2]&&($a["source_dirty"]??"")==="false"&&($a["goos"]??"")==="linux"&&($a["goarch"]??"")==="amd64"?0:2);' "$actual" "$sha" || fail 'Go 内置版本不匹配。'
runtime_status=0
runuser -u www -- "$php" "$chat/deploy/production-runtime.php" "$site" "$state" "$sha" || runtime_status=$?
(( runtime_status == 0 || runtime_status == 4 )) || fail '正式 Go 运行配置生成失败。'
check_config(){
  runuser -u www -- bash -c 'source "$1" && { [[ ! -s "$2" ]] || source "$2"; } && exec "$3" check-config --config "$4"' _ "$state/production.runtime.env" "$1" "$release/chatd" "$2"
}
if [[ -s "$state/production.text.env" && $runtime_status == 0 ]]; then
  if ! check_config "$state/production.text.env" "$state/production.text.json"; then
    check_config "$state/production.base.env" "$state/production.base.json" || fail '基础聊天配置校验失败。'
    echo 'CHAT_PRODUCTION_OPTIONAL_PREFLIGHT: 基础聊天配置有效。'
  fi
else
  check_config '' "$state/production.runtime.json" || fail 'Go 基础配置校验失败。'
  echo 'CHAT_DEPLOY_CONFIG_PENDING: 安装后请在后台应用聊天配置。'
fi
if [[ "${1:-}" == --init-recovery ]]; then
  for dir in /www/backup-control /www/backup-control/bixuan-chat "$control"; do [[ ! -L "$dir" ]] || fail '恢复目录不能使用符号链接。'; done
  if [[ ! -d "$control" ]]; then install -d -m 2750 -o root -g www "$control"; fi
  [[ $(stat -c '%U:%G:%a' -- "$control") == root:www:2750 ]] || fail '恢复目录权限不正确。'
  CHAT_RECOVERY_DB_USER="${recovery_user:-root}" CHAT_RECOVERY_DB_PASSWORD="$recovery_password" "$php" deploy/init-production-recovery.php "$mysqlbinlog"
fi
# Explicit root deployment supersedes a previously uploaded Go-only package.
# Save the markers and pending package in the rollback backup; keep the old binary.
rm -f -- "$state/go-web.active" "$state/go-web.previous" "$state/go-business.active" "$pending"
switch=$(mktemp -d "$chat/bin/.chat-current.XXXXXXXX")
ln -s -- "releases/$sha" "$switch/current"
mv -fT -- "$switch/current" "$chat/bin/current"
rmdir -- "$switch"
ln -sfnT -- current/chatd "$chat/bin/chatd"
printf '%s\n' "$sha" > "$backup/deployed-sha.new"
replace "$backup/deployed-sha.new" "$chat/bin/deployed-sha" 0644 root root
trap - EXIT
rm -rf -- "$backups/previous" "$backups/failed"
mv -T -- "$backup" "$backups/previous"
echo "CHAT_DEPLOY_BUILT: $sha"
echo '请在宝塔启动正式 Go 项目 chat，再应用聊天配置。安装完成不代表业务已就绪。'
