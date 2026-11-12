#!/usr/bin/env bash
# One foreground BaoTa Go project owns the serve/worker pair.
set -uo pipefail
set +x
CHAT=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)
ROOT=$(cd -- "$CHAT/.." && pwd -P)
if [[ -f "$CHAT/deploy.local.env" ]]; then set -a; source "$CHAT/deploy.local.env"; set +a; fi
PHP_BIN=${CHAT_PHP_BIN:-/www/server/php/83/bin/php}
source "$CHAT/deploy/bt-site.sh"
chat_site "${1:-}" "$#" || exit $?
if [[ "$CHAT_SITE" == production && $EUID == 0 ]]; then echo 'CHAT_RUN_NON_ROOT_REQUIRED'; exit 2; fi
BIN="$CHAT/bin"
STATE="$BIN/state"
mkdir -p "$STATE" || exit 2
if [[ $(readlink "/proc/$$/fd/8" 2>/dev/null || true) != "$STATE/run.lock" ]]; then exec 8>"$STATE/run.lock"; fi
flock -n 8 || { echo 'CHAT_RUN_ALREADY_STARTED'; exit 2; }
STOP=0
SERVE=''
WORKER=''
CREDENTIAL=''
trap 'STOP=1' TERM INT HUP
set_error(){ printf '%s\n' "$1" > "$STATE/.last-error.$" 2>/dev/null && mv -f "$STATE/.last-error.$" "$STATE/last-error" 2>/dev/null || true; }
clear_error(){ rm -f -- "$STATE/last-error" 2>/dev/null || true; }
runtime_request(){
  local value
  value=$(cat "$STATE/runtime-reload.request" 2>/dev/null || true)
  if [[ "$value" =~ ^[a-f0-9]{64}$ ]]; then printf '%s' "$value"; fi
}
clear_status(){ rm -f -- "$STATE/running-sha" "$STATE/processes"; }
stop_pair(){
  clear_status
  local pid n alive
  for pid in "$SERVE" "$WORKER" "$CREDENTIAL"; do [[ -z "$pid" ]] || kill -TERM "$pid" 2>/dev/null || true; done
  for ((n=0;n<45;n++)); do
    alive=0
    for pid in "$SERVE" "$WORKER" "$CREDENTIAL"; do if [[ -n "$pid" ]] && kill -0 "$pid" 2>/dev/null; then alive=1; fi; done
    (( alive == 1 )) || break
    sleep 1
  done
  for pid in "$SERVE" "$WORKER" "$CREDENTIAL"; do
    if [[ -n "$pid" ]]; then kill -KILL "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; fi
  done
  SERVE=''; WORKER=''; CREDENTIAL=''
}
trap stop_pair EXIT
while (( STOP == 0 )); do
  TARGET=$(readlink "$BIN/current" 2>/dev/null || true)
  if [[ ! "$TARGET" =~ ^releases/[a-f0-9]{40}$ || ! -x "$BIN/$TARGET/chatd" ]]; then
    set_error 'CHAT_RUN_NO_COMPILED_RELEASE'; echo 'CHAT_RUN_NO_COMPILED_RELEASE'; sleep 3; continue
  fi
  if [[ -f "$CHAT/deploy.local.env" ]]; then set -a; source "$CHAT/deploy.local.env"; set +a; fi
  PHP_BIN=${CHAT_PHP_BIN:-/www/server/php/83/bin/php}
  REQUEST=$(runtime_request)
  CONFIG="$STATE/chat.runtime.json"
  if [[ "$CHAT_SITE" == production || ${CHAT_CONFIG_MANUAL:-0} == 1 ]]; then
    CONFIG="$CHAT/chat.json"
  else
    "$PHP_BIN" "$PHP_ROOT/bin/chat-credentials.php" >/dev/null 2>&1 || true
    if "$PHP_BIN" "$PHP_ROOT/bin/chat-runtime-config.php"; then
      :
    else
      runtime_status=$?
      if [[ "$runtime_status" == 3 ]]; then
        set_error 'CHAT_RUN_UPGRADE_REQUIRED'
        echo 'CHAT_RUN_UPGRADE_REQUIRED: 请先完成后台数据库升级，再重新执行Go部署；启动器继续等待。'
        sleep 15
      else
        set_error 'CHAT_RUN_RUNTIME_CONFIG_FAILED'
        echo 'CHAT_RUN_RUNTIME_CONFIG_FAILED'
        sleep 3
      fi
      continue
    fi
    if [[ -f "$STATE/runtime.env" ]]; then set -a; source "$STATE/runtime.env"; set +a; fi
  fi
  if ! chat_site_config "$CONFIG"; then
    set_error 'CHAT_RUN_SITE_CONFIG_MISMATCH'; echo 'CHAT_RUN_SITE_CONFIG_MISMATCH'; sleep 3; continue
  fi
  if ! "$BIN/$TARGET/chatd" check-config --config "$CONFIG"; then set_error 'CHAT_RUN_CONFIG_INVALID_OR_BINARY_STALE'; sleep 3; continue; fi
  (exec 8>&-; exec "$BIN/$TARGET/chatd" serve --config "$CONFIG") &
  SERVE=$!
  (exec 8>&-; exec "$BIN/$TARGET/chatd" worker --config "$CONFIG") &
  WORKER=$!
  NEEDS_CREDENTIAL=$("$PHP_BIN" -r '$c=json_decode(file_get_contents($argv[1]),true,64,JSON_THROW_ON_ERROR);echo !empty($c["safety"]["credential_file"])?"1":"0";' "$CONFIG" 2>/dev/null || echo 0)
  if [[ "$NEEDS_CREDENTIAL" == 1 ]]; then
    (
      exec 8>&-
      while true; do
        "$PHP_BIN" "$PHP_ROOT/bin/chat-credentials.php" >/dev/null 2>&1 || true
        sleep 60
      done
    ) &
    CREDENTIAL=$!
  fi
  printf '%s %s %s\n' "$SERVE" "$WORKER" "$CREDENTIAL" > "$STATE/processes"
  STABLE=0
  CHANGED=0
  while (( STOP == 0 )); do
    if ! kill -0 "$SERVE" 2>/dev/null || ! kill -0 "$WORKER" 2>/dev/null; then
      set_error 'CHAT_RUN_CHILD_EXITED'; echo 'CHAT_RUN_CHILD_EXITED: restarting pair'; break
    fi
    [[ $(readlink "$BIN/current" 2>/dev/null || true) == "$TARGET" ]] || { CHANGED=1; echo 'CHAT_RUN_RELEASE_CHANGED'; break; }
    if (( STABLE == 2 )); then
      printf '%s\n' "${TARGET#releases/}" > "$STATE/.running-sha.$$"
      mv -f "$STATE/.running-sha.$$" "$STATE/running-sha"
      printf '%s\n' "$REQUEST" > "$STATE/runtime-reload.applied"
      clear_error
      echo "CHAT_RUN_STARTED: ${TARGET#releases/}"
    fi
    if (( STABLE % 5 == 0 )); then
      LATEST_REQUEST=$(runtime_request)
      if [[ -n "$LATEST_REQUEST" && "$LATEST_REQUEST" != "$REQUEST" ]]; then
        CHANGED=2; echo 'CHAT_RUN_SETTINGS_CHANGED'; break
      fi
    fi
    STABLE=$((STABLE+1))
    sleep 1
  done
  stop_pair
  if (( STOP == 0 && CHANGED == 1 )); then
    exec /bin/bash "$CHAT/deploy/bt-run.sh" "$@"
  fi
  if (( STOP == 0 )); then sleep 2; fi
done
