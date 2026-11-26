#!/usr/bin/env bash
# One foreground BaoTa Go project owns the serve/worker pair.
set -uo pipefail
umask 077
set +x
CHAT=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)
ROOT=$(cd -- "$CHAT/.." && pwd -P)
if [[ -f "$CHAT/deploy.local.env" ]]; then set -a; source "$CHAT/deploy.local.env"; set +a; fi
PHP_BIN=${CHAT_PHP_BIN:-/www/server/php/83/bin/php}
source "$CHAT/deploy/bt-site.sh"
chat_site "${1:-}" "$#" || exit $?
if [[ "$CHAT_SITE" == production && $EUID == 0 ]]; then echo 'CHAT_RUN_NON_ROOT_REQUIRED'; exit 2; fi
if [[ "$CHAT_SITE" == production && "$ROOT" == "$PHP_ROOT" && $(id -un) != www ]]; then
  echo 'CHAT_RUN_PRODUCTION_USER_REQUIRED: www'; exit 2
fi
BIN="$CHAT/bin"
STATE="$BIN/state"
RELOAD_STATE="$STATE"
WEB_UPDATE=0
if [[ "$CHAT_SITE" == production && "$ROOT" == "$PHP_ROOT" ]]; then
  RELOAD_STATE="$PHP_ROOT/storage/private/chat-runtime-control"
  if [[ -r "$CHAT/deploy/web-update.php" && ${CHAT_CONFIG_MANUAL:-0} != 1 && ! -f "$CHAT/chat.json" ]]; then WEB_UPDATE=1; fi
fi
if [[ "$CHAT_SITE" == production && "$ROOT" == "$PHP_ROOT" ]]; then
  [[ ! -L "$BIN" && ! -L "$STATE" ]] || { echo 'CHAT_RUN_PRODUCTION_STATE_SYMLINK'; exit 2; }
  if [[ ! -d "$STATE" ]]; then mkdir -m 0700 "$STATE" || exit 2; fi
  [[ -d "$STATE" && $(stat -c '%U:%G:%a' -- "$STATE") == www:www:700 ]] || {
    echo 'CHAT_RUN_PRODUCTION_STATE_PERMISSIONS'; exit 2;
  }
else
  mkdir -p "$STATE" || exit 2
fi
[[ ! -L "$STATE/run.lock" && ( ! -e "$STATE/run.lock" || -f "$STATE/run.lock" ) ]] || { echo 'CHAT_RUN_LOCK_PATH_INVALID'; exit 2; }
{ exec 8>>"$STATE/run.lock"; } || { echo 'CHAT_RUN_LOCK_OPEN_FAILED'; exit 2; }
flock -n 8 || { echo 'CHAT_RUN_ALREADY_STARTED'; exit 2; }
STOP=0
SERVE=''
WORKER=''
CREDENTIAL=''
REMINDER=''
trap 'STOP=1' TERM INT HUP
set_error(){ printf '%s\n' "$1" > "$STATE/.last-error.$$" 2>/dev/null && mv -f "$STATE/.last-error.$$" "$STATE/last-error" 2>/dev/null || true; }
clear_error(){ rm -f -- "$STATE/last-error" 2>/dev/null || true; }
runtime_request(){
  local value
  value=$(cat "$RELOAD_STATE/runtime-reload.request" 2>/dev/null || true)
  if [[ "$value" =~ ^[a-f0-9]{64}$ ]]; then printf '%s' "$value"; fi
}
clear_status(){ rm -f -- "$STATE/running-sha" "$STATE/processes"; }
stop_pair(){
  clear_status
  local pid n alive
  for pid in "$SERVE" "$WORKER" "$CREDENTIAL" "$REMINDER"; do [[ -z "$pid" ]] || kill -TERM "$pid" 2>/dev/null || true; done
  for ((n=0;n<45;n++)); do
    alive=0
    for pid in "$SERVE" "$WORKER" "$CREDENTIAL" "$REMINDER"; do if [[ -n "$pid" ]] && kill -0 "$pid" 2>/dev/null; then alive=1; fi; done
    (( alive == 1 )) || break
    sleep 1
  done
  for pid in "$SERVE" "$WORKER" "$CREDENTIAL" "$REMINDER"; do
    if [[ -n "$pid" ]]; then kill -KILL "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; fi
  done
  SERVE=''; WORKER=''; CREDENTIAL=''; REMINDER=''
}
web_active(){
  local sha
  sha=$(cat "$STATE/go-web.active" 2>/dev/null || true)
  if [[ "$sha" =~ ^[a-f0-9]{40}$ && -x "$STATE/go-web/$sha/chatd" ]]; then printf '%s' "$sha"; fi
}
web_finish(){
  "$PHP_BIN" "$CHAT/deploy/web-update.php" "$PHP_ROOT" finish "$1" "$2"
}
healthy(){
  local port=$1 path=$2 expected=$3 response
  response=$(curl -sS --max-time 2 -w '\n%{http_code}' "http://127.0.0.1:$port$path" 2>/dev/null) || return 1
  [[ "${response##*$'\n'}" == "$expected" ]]
}
trap stop_pair EXIT
while (( STOP == 0 )); do
  TARGET=$(readlink "$BIN/current" 2>/dev/null || true)
  if [[ ! "$TARGET" =~ ^releases/[a-f0-9]{40}$ || ! -x "$BIN/$TARGET/chatd" ]]; then
    set_error 'CHAT_RUN_NO_COMPILED_RELEASE'; echo 'CHAT_RUN_NO_COMPILED_RELEASE'; sleep 3; continue
  fi
  REVISION="${TARGET#releases/}"
  EXE="$BIN/$TARGET/chatd"
  CANDIDATE=''
  PREVIOUS=''
  if (( WEB_UPDATE == 1 )); then
    PREVIOUS=$(web_active)
    if [[ -n "$PREVIOUS" ]]; then
      if ! "$PHP_BIN" "$CHAT/deploy/web-update.php" "$PHP_ROOT" verify "$PREVIOUS"; then
        backup_sha=$(cat "$STATE/go-web.previous" 2>/dev/null || true)
        if [[ "$backup_sha" =~ ^[a-f0-9]{40}$ ]] && "$PHP_BIN" "$CHAT/deploy/web-update.php" "$PHP_ROOT" verify "$backup_sha"; then
          PREVIOUS="$backup_sha"
        else
          PREVIOUS=''
        fi
      fi
      if [[ -n "$PREVIOUS" ]]; then REVISION="$PREVIOUS"; EXE="$STATE/go-web/$PREVIOUS/chatd"; fi
    fi
    if [[ -f "$PHP_ROOT/storage/private/chat-go-update/pending.chatgo" ]]; then
      CANDIDATE=$("$PHP_BIN" "$CHAT/deploy/web-update.php" "$PHP_ROOT" stage) || CANDIDATE=''
      if [[ "$CANDIDATE" =~ ^[a-f0-9]{40}$ ]]; then
        if [[ "$CANDIDATE" == "$REVISION" ]]; then
          web_finish "$CANDIDATE" applied
          CANDIDATE=''
        else
          REVISION="$CANDIDATE"
          EXE="$STATE/go-web/$CANDIDATE/chatd"
          if ! "$PHP_BIN" "$CHAT/deploy/web-update.php" "$PHP_ROOT" verify "$CANDIDATE"; then
            web_finish "$CANDIDATE" CHAT_UPDATE_FAILED
            continue
          fi
        fi
      fi
    fi
  fi
  if [[ -f "$CHAT/deploy.local.env" ]]; then set -a; source "$CHAT/deploy.local.env"; set +a; fi
  if [[ "$CHAT_SITE" != production ]]; then PHP_BIN=${CHAT_PHP_BIN:-/www/server/php/83/bin/php}; fi
  REQUEST=$(runtime_request)
  CONFIG="$STATE/chat.runtime.json"
  PROD_PENDING=0
  if [[ "$CHAT_SITE" == production && "$ROOT" == "$PHP_ROOT" && ${CHAT_CONFIG_MANUAL:-0} != 1 && ! -f "$CHAT/chat.json" ]]; then
    CONFIG="$STATE/production.runtime.json"
    unset CHAT_PROD_OPTIONAL CHAT_PROD_MYSQL_USER CHAT_MYSQL_PASSWORD CHAT_PROD_RELEASE_FILE CHAT_RELEASE_KEY CHAT_REDIS_PASSWORD CHAT_HISTORY_CURSOR_KEY CHAT_INTERNAL_KEY CHAT_QINIU_ACCESS_KEY CHAT_QINIU_SECRET_KEY CHAT_MEDIA_PROXY_KEY CHAT_MEDIA_RELAY_KEY CHAT_WECHAT_CALLBACK_TOKEN CHAT_WECHAT_CALLBACK_AES_KEY CHAT_TENCENT_ASR_SECRET_ID CHAT_TENCENT_ASR_SECRET_KEY CHAT_SHENGWANG_CERTIFICATE CHAT_SHENGWANG_CUSTOMER_ID CHAT_SHENGWANG_CUSTOMER_SECRET
    if [[ -f "$STATE/production.local.env" ]]; then
      set -a
      source "$STATE/production.local.env"
      local_status=$?
      set +a
      if (( local_status != 0 )); then
        echo 'CHAT_PRODUCTION_TEXT_ENV_INVALID'
        unset CHAT_PROD_OPTIONAL CHAT_PROD_MYSQL_USER CHAT_MYSQL_PASSWORD
      fi
    fi
    rm -f -- "$STATE/production.text.env"
    wechat_credential_ready=0
    if "$PHP_BIN" "$PHP_ROOT/bin/chat-credentials.php" >/dev/null 2>&1; then
      wechat_credential_ready=1
    fi
    runtime_status=0
    "$PHP_BIN" "$CHAT/deploy/production-runtime.php" "$PHP_ROOT" "$STATE" "$REVISION" || runtime_status=$?
    if (( runtime_status != 0 && runtime_status != 4 )); then
      [[ -z "$CANDIDATE" ]] || web_finish "$CANDIDATE" CHAT_UPDATE_FAILED
      set_error 'CHAT_RUN_PRODUCTION_CONFIG_FAILED'; sleep 10; continue
    fi
    (( runtime_status != 4 )) || PROD_PENDING=1
    source "$STATE/production.runtime.env" || { sleep 10; continue; }
    if [[ -s "$STATE/production.text.env" ]] && (( runtime_status == 0 )); then
      if (( wechat_credential_ready == 0 )); then
        printf '%s\n' WECHAT_CREDENTIAL > "$RELOAD_STATE/production-text-status"
        echo 'CHAT_PRODUCTION_TEXT_PENDING: WECHAT_CREDENTIAL'
        PROD_PENDING=1
      elif (
        source "$STATE/production.text.env" &&
          "$EXE" check-config --config "$STATE/production.text.json" --probe
      ); then
        source "$STATE/production.text.env" || { sleep 10; continue; }
        CONFIG="$STATE/production.text.json"
        printf '%s\n' READY > "$RELOAD_STATE/production-text-status"
        if [[ ${CHAT_PROD_OPTIONAL:-0} == 1 ]]; then echo 'CHAT_PRODUCTION_FEATURES_READY'; else echo 'CHAT_PRODUCTION_TEXT_READY'; fi
      elif [[ -s "$STATE/production.base.env" && -s "$STATE/production.base.json" ]] && (
        source "$STATE/production.base.env" &&
          "$EXE" check-config --config "$STATE/production.base.json" --probe
      ); then
        # Use the already-probed baseline, without disabling authentication or content safety.
        # Keep the active filename stable for readiness, credential issuer and update handling.
        if ! cp -- "$STATE/production.base.json" "$STATE/production.text.json" ||
           ! cp -- "$STATE/production.base.env" "$STATE/production.text.env"; then
          set_error 'CHAT_RUN_PRODUCTION_CONFIG_FAILED'; sleep 10; continue
        fi
        source "$STATE/production.text.env" || { sleep 10; continue; }
        CONFIG="$STATE/production.text.json"
        printf '%s\n' READY > "$RELOAD_STATE/production-text-status"
        printf '%s\n' OPTIONAL_PREFLIGHT > "$RELOAD_STATE/production-feature-status"
        echo 'CHAT_PRODUCTION_TEXT_READY: OPTIONAL_PREFLIGHT'
      else
        printf '%s\n' GO_PREFLIGHT > "$RELOAD_STATE/production-text-status"
        echo 'CHAT_PRODUCTION_TEXT_PENDING: GO_PREFLIGHT'
        PROD_PENDING=1
      fi
    fi
  elif [[ "$CHAT_SITE" == production || ${CHAT_CONFIG_MANUAL:-0} == 1 ]]; then
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
  if [[ -n "$CANDIDATE" && -f "$STATE/go-business.active" && "$CONFIG" != "$STATE/production.text.json" ]]; then
    web_finish "$CANDIDATE" CHAT_UPDATE_FAILED
    continue
  fi
  if ! chat_site_config "$CONFIG"; then
    [[ -z "$CANDIDATE" ]] || web_finish "$CANDIDATE" CHAT_UPDATE_FAILED
    set_error 'CHAT_RUN_SITE_CONFIG_MISMATCH'; echo 'CHAT_RUN_SITE_CONFIG_MISMATCH'; sleep 3; continue
  fi
  if ! "$EXE" check-config --config "$CONFIG"; then [[ -z "$CANDIDATE" ]] || web_finish "$CANDIDATE" CHAT_UPDATE_FAILED; set_error 'CHAT_RUN_CONFIG_INVALID_OR_BINARY_STALE'; sleep 3; continue; fi
  (exec 8>&-; exec "$EXE" serve --config "$CONFIG") &
  SERVE=$!
  (exec 8>&-; exec "$EXE" worker --config "$CONFIG") &
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
  NEEDS_REMINDER=$("$PHP_BIN" -r '$c=json_decode(file_get_contents($argv[1]),true,64,JSON_THROW_ON_ERROR);echo !empty($c["reminders"]["enabled"])?"1":"0";' "$CONFIG" 2>/dev/null || echo 0)
  if [[ "$NEEDS_REMINDER" == 1 ]]; then
    (exec 8>&-; exec "$PHP_BIN" "$PHP_ROOT/bin/chat-reminders.php" --loop) &
    REMINDER=$!
  fi
  printf '%s %s %s %s\n' "$SERVE" "$WORKER" "$CREDENTIAL" "$REMINDER" > "$STATE/processes"
  STABLE=0
  CHANGED=0
  EXPECT_BUSINESS=0
  READY_WAIT=0
  [[ "$CONFIG" != "$STATE/production.text.json" ]] || EXPECT_BUSINESS=1
  while (( STOP == 0 )); do
    if ! kill -0 "$SERVE" 2>/dev/null || ! kill -0 "$WORKER" 2>/dev/null \
      || { [[ "$NEEDS_CREDENTIAL" == 1 ]] && ! kill -0 "$CREDENTIAL" 2>/dev/null; }; then
      set_error 'CHAT_RUN_CHILD_EXITED'; echo 'CHAT_RUN_CHILD_EXITED: restarting managed chat processes'; break
    fi
    if (( STABLE % 15 == 0 )) && [[ "$NEEDS_REMINDER" == 1 ]] && ! kill -0 "$REMINDER" 2>/dev/null; then
      wait "$REMINDER" 2>/dev/null || true
      echo 'CHAT_REMINDER_CHILD_EXITED: restarting reminder worker'
      (exec 8>&-; exec "$PHP_BIN" "$PHP_ROOT/bin/chat-reminders.php" --loop) &
      REMINDER=$!
      printf '%s %s %s %s\n' "$SERVE" "$WORKER" "$CREDENTIAL" "$REMINDER" > "$STATE/processes"
    fi
    [[ $(readlink "$BIN/current" 2>/dev/null || true) == "$TARGET" ]] || { CHANGED=1; echo 'CHAT_RUN_RELEASE_CHANGED'; break; }
    if (( STABLE >= 2 )) && [[ -n "$CANDIDATE" ]]; then
      if healthy 9501 /livez 200 &&
        { (( EXPECT_BUSINESS == 0 )) || healthy 9502 /readyz 200; }; then
        printf '%s\n' "$CANDIDATE" > "$STATE/go-web.active.tmp"
        if ! mv -f -- "$STATE/go-web.active.tmp" "$STATE/go-web.active"; then break; fi
        web_finish "$CANDIDATE" applied
        if [[ -n "$PREVIOUS" && "$PREVIOUS" != "$CANDIDATE" ]]; then
          old_backup=$(cat "$STATE/go-web.previous" 2>/dev/null || true)
          printf '%s\n' "$PREVIOUS" > "$STATE/go-web.previous"
          if [[ "$old_backup" =~ ^[a-f0-9]{40}$ && "$old_backup" != "$CANDIDATE" && "$old_backup" != "$PREVIOUS" ]]; then
            rm -f -- "$STATE/go-web/$old_backup/chatd" "$STATE/go-web/$old_backup/release.chatgo"
            rmdir -- "$STATE/go-web/$old_backup" 2>/dev/null || true
          fi
        fi
        CANDIDATE=''
        STABLE=2
      elif (( STABLE >= 12 )); then
        break
      else
        STABLE=$((STABLE+1)); sleep 1; continue
      fi
    fi
    if (( STABLE == 2 )); then
      if (( EXPECT_BUSINESS == 1 )) && ! healthy 9502 /readyz 200; then
        # Do not acknowledge a managed business runtime before the real readiness probe.
        READY_WAIT=$((READY_WAIT+1))
        if (( READY_WAIT < 30 )); then sleep 1; continue; fi
        set_error 'CHAT_RUN_BUSINESS_NOT_READY'
        echo 'CHAT_RUN_BUSINESS_NOT_READY'
        break
      fi
      printf '%s\n' "$REVISION" > "$STATE/.running-sha.$$"
      mv -f "$STATE/.running-sha.$$" "$STATE/running-sha"
      if [[ -n "$REQUEST" && -d "$RELOAD_STATE" ]]; then printf '%s\n' "$REQUEST" > "$RELOAD_STATE/runtime-reload.applied"; fi
      if (( WEB_UPDATE == 1 )); then
        if (( EXPECT_BUSINESS == 1 )); then
          printf '%s\n' "$REVISION" > "$STATE/go-business.active"
        else
          rm -f -- "$STATE/go-business.active"
        fi
      fi
      clear_error
      echo "CHAT_RUN_STARTED: $REVISION"
    fi
    if (( PROD_PENDING == 1 && STABLE > 0 && STABLE % 60 == 0 )); then
      echo 'CHAT_RUN_RETRY_PENDING_CONFIG'; break
    fi
    if (( STABLE % 5 == 0 )); then
      if (( WEB_UPDATE == 1 )) && [[ -z "$CANDIDATE" && -f "$PHP_ROOT/storage/private/chat-go-update/pending.chatgo" ]]; then
        staged=$("$PHP_BIN" "$CHAT/deploy/web-update.php" "$PHP_ROOT" stage) || staged=''
        if [[ "$staged" =~ ^[a-f0-9]{40}$ && "$staged" != "$REVISION" ]]; then
          candidate_exe="$STATE/go-web/$staged/chatd"
          if ! "$PHP_BIN" "$CHAT/deploy/web-update.php" "$PHP_ROOT" verify "$staged"; then
            web_finish "$staged" CHAT_UPDATE_FAILED
            continue
          fi
          if (( EXPECT_BUSINESS == 1 )); then
            if [[ $(cat "$STATE/go-business.active" 2>/dev/null) == "$REVISION" ]]; then
              # The running config is signed for REVISION. The next pass builds
              # and probes a new signed config before promoting the candidate.
              CHANGED=2; break
            fi
          else
            "$candidate_exe" check-config --config "$CONFIG" && { CHANGED=2; break; }
          fi
          web_finish "$staged" CHAT_UPDATE_FAILED
        elif [[ "$staged" == "$REVISION" ]]; then
          web_finish "$staged" applied
        fi
      fi
      LATEST_REQUEST=$(runtime_request)
      if [[ -n "$LATEST_REQUEST" && "$LATEST_REQUEST" != "$REQUEST" ]]; then
        CHANGED=2; echo 'CHAT_RUN_SETTINGS_CHANGED'; break
      fi
    fi
    STABLE=$((STABLE+1))
    sleep 1
  done
  stop_pair
  [[ -z "$CANDIDATE" ]] || web_finish "$CANDIDATE" rolled_back
  if (( STOP == 0 && CHANGED == 1 )); then
    exec /bin/bash "$CHAT/deploy/bt-run.sh" "$@"
  fi
  if (( STOP == 0 )); then sleep 2; fi
done
