#!/usr/bin/env bash
set -euo pipefail
SOURCE_COMMIT="$(git rev-parse HEAD)"
VERSION="$(git show "$SOURCE_COMMIT:server/VERSION" | tr -d '\r\n ')"
MODE="upgrade"
BASE_REF=""
BASE_LABEL=""

if [[ "${1:-}" == "--server-hotfix" ]]; then
  MODE="server_hotfix"
  OUT="${2:-release-dist}"
else
  if [[ $# -lt 1 || -z "${1:-}" ]]; then
    echo "Usage: $0 <base-ref> [output-dir]" >&2
    echo "       $0 --server-hotfix [output-dir]" >&2
    echo "Example: $0 origin/dev/2.4.1 release-dist" >&2
    echo "Example: $0 --server-hotfix release-dist" >&2
    exit 2
  fi
  BASE_REF="$1"
  BASE_LABEL="${BASE_REF##*/}"
  BASE_LABEL="${BASE_LABEL#v}"
  OUT="${2:-release-dist}"
fi

ROOT="$(pwd)"
if [[ "$OUT" = /* ]]; then OUT_ABS="$OUT"; else OUT_ABS="$ROOT/$OUT"; fi
rm -rf "$OUT_ABS"
mkdir -p "$OUT_ABS"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

normalize_tree_permissions() {
  local root="$1"
  local symlink
  symlink="$(find "$root" -type l -print -quit)"
  if [[ -n "$symlink" ]]; then
    echo "ERROR: 后端 staging 不允许符号链接：$symlink" >&2
    exit 1
  fi
  find "$root" -type d -exec chmod 0755 {} +
  find "$root" -type f -exec chmod 0644 {} +
}

is_server_runtime_file() {
  case "$1" in
    .env.example) return 0 ;;
    tests/*|tools/*|storage/*|uploads/*|backups/*|logs/*|cache/*|.env|.env.*|.user.ini|config/installed.php|*.log|*.bak|*.tmp|*.lock) return 1 ;;
    *) return 0 ;;
  esac
}

stage_server_runtime() {
  local destination="$1"
  local path
  local inventory="$TMP/server-runtime-files.txt"
  if git ls-tree -r "$SOURCE_COMMIT:server" | grep '^120000 ' >/dev/null; then
    echo 'ERROR: 后端源码不允许符号链接' >&2
    exit 1
  fi
  # 所有正式代码取自同一提交。前置版本只决定迁移范围，不能用开发分支的
  # 未变更文件代替线上文件存在的证据，否则新增调用会遇到 class not found。
  while IFS= read -r path; do
    is_server_runtime_file "$path" || continue
    printf '%s\n' "$path"
  done < <(git -c core.quotepath=false ls-tree -r --name-only "$SOURCE_COMMIT:server") > "$inventory"
  git -c core.autocrlf=false archive --format=tar "$SOURCE_COMMIT:server" \
    | tar -xf - -C "$destination" --verbatim-files-from -T "$inventory"
}

assert_server_zip_permissions() {
  local archive="$1"
  local bad
  bad="$(zipinfo -l "$archive" | awk '
    $1 ~ /^d/ && $1 != "drwxr-xr-x" { print $1 " " $NF }
    $1 ~ /^-/ && $1 != "-rw-r--r--" { print $1 " " $NF }
    $1 ~ /^l/ { print $1 " " $NF }
  ')"
  if [[ -n "$bad" ]]; then
    echo 'ERROR: 后端 ZIP 含非标准 Unix 权限或符号链接；目录必须 0755、普通文件必须 0644' >&2
    printf '%s\n' "$bad" >&2
    exit 1
  fi
}

assert_server_package_contents() {
  local archive="$1"
  local list="$2"
  local path
  unzip -Z1 "$archive" > "$list"
  for required in 'VERSION' 'api/index.php' 'app/Support/functions.php' 'UPGRADE.md'; do
    grep -Fxq "$required" "$list" || { echo "ERROR: 后端 ZIP 根缺少 $required" >&2; exit 1; }
  done
  while IFS= read -r path; do
    [[ "$path" == */ ]] && continue
    is_server_runtime_file "$path" || { echo "ERROR: 后端 ZIP 含非发行文件 $path" >&2; exit 1; }
  done < "$list"
  # 必须包含全部正式运行文件，包括在上一开发分支中已存在的依赖类。
  while IFS= read -r path; do
    is_server_runtime_file "$path" || continue
    grep -Fxq "$path" "$list" || { echo "ERROR: 后端 ZIP 漏正式运行文件 $path" >&2; exit 1; }
  done < <(git -c core.quotepath=false ls-tree -r --name-only "$SOURCE_COMMIT:server")
  if grep -Eq '^workers-server-[^/]+/' "$list"; then
    echo 'ERROR: 后端 ZIP 检测到外层目录' >&2
    exit 1
  fi
}

# V2.5.0 起，同版本全量后台热更新必须走该模式，不再使用临时 cp/zip 流程。
if [[ "$MODE" == "server_hotfix" ]]; then
  SERVER="$TMP/server-hotfix"
  mkdir -p "$SERVER"
  stage_server_runtime "$SERVER"
  printf '%s\n' "$(git rev-parse HEAD)" > "$SERVER/SOURCE-COMMIT.txt"
  cat > "$SERVER/升级说明-先看.txt" <<EOF
壁选后端 V${VERSION} 同版本热更新包
源码提交：$(git rev-parse HEAD)
本 ZIP 根目录直接对应服务器站点根目录，不含外层版本文件夹。
本包只覆盖正式代码；不会携带 .env、config/installed.php、.user.ini、storage/ 或 tests/。
包内目录统一为 0755、普通文件统一为 0644。若使用 root 解压，新文件属主可能为 root，但 PHP-FPM 必须仍可读取；不要使用 chmod -R 777。
同版本热更新不重跑已经成功的数据库迁移；覆盖后清 OPcache/重载 PHP，再验证后台/API。
EOF
  normalize_tree_permissions "$SERVER"
  SHORT_SHA="$(git rev-parse --short=8 HEAD)"
  SERVER_ZIP="$OUT_ABS/workers-server-v${VERSION}-hotfix-${SHORT_SHA}.zip"
  (cd "$SERVER" && zip -qr "$SERVER_ZIP" .)
  unzip -t "$SERVER_ZIP" >/dev/null
  assert_server_zip_permissions "$SERVER_ZIP"
  assert_server_package_contents "$SERVER_ZIP" "$TMP/server-hotfix-list.txt"
  (cd "$OUT_ABS" && sha256sum "$(basename "$SERVER_ZIP")" > "$(basename "$SERVER_ZIP").sha256")
  echo "V${VERSION} same-version server hotfix created: $SERVER_ZIP"
  echo "SHA-256: $SERVER_ZIP.sha256"
  exit 0
fi

# 小程序提交源码：ZIP 根直接是 project.config.json / miniprogram / cloudfunctions。
MINI="$TMP/mini"
mkdir -p "$MINI"
git -c core.autocrlf=false archive --format=tar "$SOURCE_COMMIT" project.config.json cloudbaserc.json miniprogram cloudfunctions | tar -xf - -C "$MINI"
cat > "$MINI/提交说明.txt" <<EOF
壁选微信小程序 V${VERSION} 提交源码
解压后直接选择当前目录导入微信开发者工具；不要再进入第二层同名目录。
版本号：${VERSION}
源码提交：$(git rev-parse HEAD)
EOF
(cd "$MINI" && zip -qr "$OUT_ABS/workers-miniapp-v${VERSION}.zip" .)

# 后端版本升级携带全部正式运行文件，不能依赖未知线上 2.5.0 代码与开发分支完全一致。
# .user.ini 属于服务器现场安全配置，宝塔环境可能设置不可变属性，发行包不得覆盖或删除。
SERVER="$TMP/server"
mkdir -p "$SERVER"
stage_server_runtime "$SERVER"
git -c core.quotepath=false diff --name-status --no-renames "$BASE_REF" "$SOURCE_COMMIT" -- server > "$SERVER/CHANGED-FILES.txt"
: > "$SERVER/DELETED-FILES.txt"
while IFS=$'\t' read -r status path1 path2; do
  [[ -n "${status:-}" ]] || continue
  case "$status" in
    D*)
      is_server_runtime_file "${path1#server/}" && echo "${path1#server/}" >> "$SERVER/DELETED-FILES.txt"
      continue
      ;;
    *) continue ;;
  esac
done < "$SERVER/CHANGED-FILES.txt"
for required in VERSION api/index.php database/upgrades/manifest.json "database/upgrades/${VERSION}.sql" "UPGRADE.md"; do
  [[ -f "$SERVER/$required" ]] || { echo "ERROR: 源码缺少发行必需文件 server/$required" >&2; exit 1; }
done
rm -f "$SERVER/.user.ini"
[[ -s "$SERVER/DELETED-FILES.txt" ]] || echo '无删除文件' > "$SERVER/DELETED-FILES.txt"
cat > "$SERVER/升级说明-先看.txt" <<EOF
壁选后端升级包：${BASE_LABEL} → V${VERSION}
本 ZIP 根目录直接对应服务器站点根目录，不含外层版本文件夹。
本包携带目标提交的全部正式后端代码，用于补齐不同 2.5.0 现场的依赖文件；CHANGED-FILES.txt 仅用于审计，不作为选文件依据。
先备份数据库和代码；覆盖时不要删除 config/installed.php、.env、.user.ini、storage/、storage/install.lock、storage/private/。
发行包内目录统一为 0755、普通文件统一为 0644；如果使用 root 解压，新文件属主可能仍是 root，应在上线前确认 PHP-FPM 用户可以读取代码，不要使用 chmod -R 777。
覆盖后重启 PHP/清 OPcache，再进入 /adminapi/upgrade/ 重新预检并使用升级器执行；不要在 phpMyAdmin 手工执行 SQL。
现场已成功执行 2.6.0 且校验一致时，只覆盖代码并清 OPcache，不重复执行成功迁移。
EOF
printf '%s\n' "$(git rev-parse HEAD)" > "$SERVER/SOURCE-COMMIT.txt"

# 不信任打包机工作区权限。统一 staging 权限后再压缩，避免 0600/root 文件被 ZIP 原样带到服务器。
normalize_tree_permissions "$SERVER"
(cd "$SERVER" && zip -qr "$OUT_ABS/workers-server-upgrade-${BASE_LABEL}-to-${VERSION}.zip" .)

MINI_ZIP="$OUT_ABS/workers-miniapp-v${VERSION}.zip"
SERVER_ZIP="$OUT_ABS/workers-server-upgrade-${BASE_LABEL}-to-${VERSION}.zip"
unzip -t "$MINI_ZIP" >/dev/null
unzip -t "$SERVER_ZIP" >/dev/null
assert_server_zip_permissions "$SERVER_ZIP"
MINI_LIST="$TMP/mini-zip-list.txt"
SERVER_LIST="$TMP/server-zip-list.txt"
unzip -Z1 "$MINI_ZIP" > "$MINI_LIST"
unzip -Z1 "$SERVER_ZIP" > "$SERVER_LIST"
assert_server_package_contents "$SERVER_ZIP" "$SERVER_LIST"
for required in 'project.config.json' 'miniprogram/app.js'; do
  grep -Fxq "$required" "$MINI_LIST" || { echo "ERROR: 小程序 ZIP 根缺少 $required" >&2; exit 1; }
done
for required in 'VERSION' 'api/index.php' 'database/upgrades/manifest.json' "database/upgrades/${VERSION}.sql" "UPGRADE.md"; do
  grep -Fxq "$required" "$SERVER_LIST" || { echo "ERROR: 后端 ZIP 根缺少 $required" >&2; exit 1; }
done
if grep -Eq '^(tests/|tools/)' "$SERVER_LIST"; then
  echo 'ERROR: 后端 ZIP 不得包含 tests/ 或 tools/ 开发测试资产' >&2
  exit 1
fi
if grep -Fxq '.user.ini' "$SERVER_LIST"; then
  echo 'ERROR: 后端 ZIP 不得覆盖服务器现场 .user.ini' >&2
  exit 1
fi
if grep -Eq '^workers-server-upgrade-[^/]+/' "$SERVER_LIST"; then
  echo 'ERROR: 后端 ZIP 检测到外层目录' >&2
  exit 1
fi
if grep -Eq '^workers-miniapp-[^/]+/' "$MINI_LIST"; then
  echo 'ERROR: 小程序 ZIP 检测到外层目录' >&2
  exit 1
fi
(cd "$OUT_ABS" && sha256sum "$(basename "$MINI_ZIP")" "$(basename "$SERVER_ZIP")" > SHA256SUMS.txt)
echo "V${VERSION} release archives created in $OUT_ABS"
