#!/usr/bin/env bash
# One foreground BaoTa Go project owns the serve/worker pair.
set -uo pipefail
set +x
CHAT=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)
BIN="$CHAT/bin"
STATE="$BIN/state"
mkdir -p "$STATE" || exit 2
if [[ $(readlink "/proc/$/fd/8" 2>/dev/null || true) != "$STATE/run.lock" ]]; then exec 8>"$STATE/run.lock"; fi
flock -n 8 || { echo 'CHAT_RUN_ALREADY_STARTED'; exit 2; }
STOP=0
SERVE=''
WORKER=''
trap 'STOP=1' TERM INT HUP
clear_status(){ rm -f -- "$STATE/running-sha" "$STATE/processes"; }
stop_pair(){
  clear_status
  local pid n alive
  for pid in "$SERVE" "$WORKER"; do [[ -z "$pid" ]] || kill -TERM "$pid" 2>/dev/null || true; done
  for ((n=0;n<45;n++)); do
    alive=0
    for pid in "$SERVE" "$WORKER"; do if [[ -n "$pid" ]] && kill -0 "$pid" 2>/dev/null; then alive=1; fi; done
    (( alive == 1 )) || break
    sleep 1
  done
  for pid in "$SERVE" "$WORKER"; do
    if [[ -n "$pid" ]]; then kill -KILL "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; fi
  done
  SERVE=''; WORKER=''
}
trap stop_pair EXIT
while (( STOP == 0 )); do
  TARGET=$(readlink "$BIN/current" 2>/dev/null || true)
  if [[ ! "$TARGET" =~ ^releases/[a-f0-9]{40}$ || ! -x "$BIN/$TARGET/chatd" ]]; then
    echo 'CHAT_RUN_NO_COMPILED_RELEASE'; sleep 3; continue
  fi
  if [[ -f "$CHAT/deploy.local.env" ]]; then set -a; source "$CHAT/deploy.local.env"; set +a; fi
  PHP_BIN=${CHAT_PHP_BIN:-/www/server/php/83/bin/php}
  if ! "$PHP_BIN" -r '$c=json_decode(file_get_contents($argv[1]),true,64,JSON_THROW_ON_ERROR);exit(($c["env"]??"")==="test"?0:2);' "$CHAT/chat.json"; then
    echo 'CHAT_RUN_TEST_CONFIG_REQUIRED'; sleep 3; continue
  fi
  if ! "$BIN/$TARGET/chatd" check-config --config "$CHAT/chat.json"; then sleep 3; continue; fi
  (exec 8>&-; exec "$BIN/$TARGET/chatd" serve --config "$CHAT/chat.json") &
  SERVE=$!
  (exec 8>&-; exec "$BIN/$TARGET/chatd" worker --config "$CHAT/chat.json") &
  WORKER=$!
  printf '%s %s\n' "$SERVE" "$WORKER" > "$STATE/processes"
  STABLE=0
  CHANGED=0
  while (( STOP == 0 )); do
    if ! kill -0 "$SERVE" 2>/dev/null || ! kill -0 "$WORKER" 2>/dev/null; then
      echo 'CHAT_RUN_CHILD_EXITED: restarting pair'; break
    fi
    [[ $(readlink "$BIN/current" 2>/dev/null || true) == "$TARGET" ]] || { CHANGED=1; echo 'CHAT_RUN_RELEASE_CHANGED'; break; }
    if (( STABLE == 2 )); then
      printf '%s\n' "${TARGET#releases/}" > "$STATE/.running-sha.$$"
      mv -f "$STATE/.running-sha.$$" "$STATE/running-sha"
      echo "CHAT_RUN_STARTED: ${TARGET#releases/}"
    fi
    STABLE=$((STABLE+1))
    sleep 1
  done
  stop_pair
  if (( STOP == 0 && CHANGED == 1 )); then
    # Keep the panel PID/lock, but load the newly synced launcher too.
    exec /bin/bash "$CHAT/deploy/bt-run.sh"
  fi
  if (( STOP == 0 )); then sleep 2; fi
done
