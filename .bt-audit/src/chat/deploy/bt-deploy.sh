#!/usr/bin/env bash
# Existing BaoTa Git checkout -> immutable Linux build -> atomic test-site update.
set -euo pipefail
set +x
umask 027
CHAT=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)
ROOT=$(git -C "$CHAT" rev-parse --show-toplevel)
[[ "$ROOT/chat" == "$CHAT" ]] || { echo 'CHAT_DEPLOY_LAYOUT'; exit 2; }
[[ $(uname -s) == Linux ]] || { echo 'CHAT_DEPLOY_LINUX_REQUIRED'; exit 2; }
# Administrator-only shell environment, ignored by Git; never printed.
if [[ -f "$CHAT/deploy.local.env" ]]; then set -a; source "$CHAT/deploy.local.env"; set +a; fi
PHP_BIN=${CHAT_PHP_BIN:-/www/server/php/83/bin/php}
RUN_USER=${CHAT_RUN_USER:-www}
CONFIG="$CHAT/chat.json"
BIN="$CHAT/bin"
[[ "$RUN_USER" =~ ^[a-z_][a-z0-9_-]*$ ]] && id "$RUN_USER" >/dev/null || { echo 'CHAT_DEPLOY_RUN_USER'; exit 2; }
mkdir -p "$BIN/releases" "$BIN/state"
chmod 755 "$BIN" "$BIN/releases"
if [[ $EUID == 0 ]]; then chown "$RUN_USER" "$BIN/state"; fi
chmod 750 "$BIN/state"
exec 9>"$BIN/deploy.lock"
flock -n 9 || { echo 'CHAT_DEPLOY_BUSY'; exit 1; }
BRANCH=$(git -C "$ROOT" branch --show-current)
[[ "$BRANCH" == chat || "$BRANCH" == main ]] || { echo 'CHAT_DEPLOY_BRANCH_REQUIRED: use chat or main'; exit 2; }
SHA=$(git -C "$ROOT" rev-parse --verify HEAD)
# This helper is deliberately test-only; it never migrates SQL or enables features.
"$PHP_BIN" -r '$c=require $argv[1]; if(($c["app"]["env"]??"")!=="testing"){fwrite(STDERR,"CHAT_DEPLOY_TEST_SITE_REQUIRED\n");exit(2);}' "$ROOT/server/config/installed.php"
if [[ ! -e "$CONFIG" ]]; then
  "$PHP_BIN" -r '$c=json_decode(file_get_contents($argv[1]),false,64,JSON_THROW_ON_ERROR);$c->env="test";$c->dependencies->redis->key_prefix="bxchat:test:";$p=$argv[2];$h=fopen($p,"x");if(!$h)exit(2);fwrite($h,json_encode($c,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");fclose($h);' "$CHAT/config.example.json" "$CONFIG"
  if [[ $EUID == 0 ]]; then chgrp "$(id -gn "$RUN_USER")" "$CONFIG"; fi
  chmod 640 "$CONFIG"
  echo 'CHAT_DEPLOY_TEST_CONFIG_CREATED (features remain disabled)'
fi
"$PHP_BIN" -r '$c=json_decode(file_get_contents($argv[1]),true,64,JSON_THROW_ON_ERROR);if(($c["env"]??"")!=="test"){fwrite(STDERR,"CHAT_DEPLOY_TEST_CONFIG_REQUIRED\n");exit(2);}' "$CONFIG"
export GOTOOLCHAIN=local CGO_ENABLED=0
GO_BIN=${CHAT_GO_BIN:-}
REQUIRED="go$(git -C "$ROOT" show "$SHA:chat/go.mod" | awk '$1=="go"{print $2}')"
if [[ -z "$GO_BIN" ]] && command -v go >/dev/null; then GO_BIN=$(command -v go); fi
if [[ -z "$GO_BIN" || $("$GO_BIN" env GOVERSION 2>/dev/null || true) != "$REQUIRED" ]]; then
  GO_BIN=''
  for directory in /usr/local/go /www/server/go; do
    [[ -d "$directory" ]] || continue
    while IFS= read -r candidate; do
      if [[ $("$candidate" env GOVERSION 2>/dev/null || true) == "$REQUIRED" ]]; then GO_BIN=$candidate; break; fi
    done < <(find "$directory" -maxdepth 7 -type f -path '*/bin/go' 2>/dev/null)
    [[ -z "$GO_BIN" ]] || break
  done
fi
[[ -n "$GO_BIN" && $("$GO_BIN" env GOVERSION) == "$REQUIRED" ]] || { echo "CHAT_DEPLOY_INSTALL_REQUIRED_TOOLCHAIN: $REQUIRED (or set CHAT_GO_BIN)"; exit 2; }
WORK=$(mktemp -d "$BIN/.build.XXXXXXXX")
cleanup(){ case "$WORK" in "$BIN"/.build.*) rm -rf -- "$WORK";; esac; }
trap cleanup EXIT
# Always build committed bytes, independent of unrelated installed.php/runtime files.
git -C "$ROOT" archive "$SHA" chat | tar -xf - -C "$WORK"
mkdir "$WORK/release"
(
  cd "$WORK/chat"
  "$GO_BIN" mod verify
  "$GO_BIN" build -mod=readonly -trimpath -buildvcs=false \
    -ldflags="-buildid= -X github.com/franz-9527/workers/chat/internal/bootstrap.sourceRevision=$SHA -X github.com/franz-9527/workers/chat/internal/bootstrap.sourceDirty=false" \
    -o "$WORK/release/chatd" ./cmd/chatd
)
chmod 755 "$WORK/release" "$WORK/release/chatd"
"$WORK/release/chatd" version > "$WORK/release/version.json"
"$PHP_BIN" -r '$v=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);if(($v["source_revision"]??"")!==$argv[2]||($v["source_dirty"]??"")!=="false"||($v["goos"]??"")!=="linux")exit(2);' "$WORK/release/version.json" "$SHA"
"$WORK/release/chatd" check-config --config "$CONFIG"
[[ $(git -C "$ROOT" rev-parse HEAD) == "$SHA" && $(git -C "$ROOT" branch --show-current) == "$BRANCH" ]] || { echo 'CHAT_DEPLOY_CHECKOUT_CHANGED'; exit 1; }
sha256sum "$WORK/release/chatd" | awk '{print $1}' > "$WORK/release/sha256"
if [[ -d "$BIN/releases/$SHA" ]]; then
  cmp "$WORK/release/sha256" <(sha256sum "$BIN/releases/$SHA/chatd" | awk '{print $1}') || { echo 'CHAT_DEPLOY_EXISTING_BUILD_MISMATCH'; exit 1; }
else
  mv "$WORK/release" "$BIN/releases/$SHA"
fi
OLD=$(readlink "$BIN/current" 2>/dev/null || true)
switch_to(){ ln -s "$1" "$BIN/.current.$$"; mv -Tf "$BIN/.current.$$" "$BIN/current"; }
switch_to "releases/$SHA"
if [[ ! -e "$BIN/chatd" && ! -L "$BIN/chatd" ]]; then ln -s current/chatd "$BIN/chatd"; fi
printf '%s\n' "$SHA" > "$BIN/deployed-sha"
# The foreground launcher observes current and gracefully replaces both children.
if [[ -e "$BIN/state/run.lock" ]] && ! flock -n "$BIN/state/run.lock" true; then
  for ((i=0;i<60;i++)); do
    if [[ $(cat "$BIN/state/running-sha" 2>/dev/null || true) == "$SHA" ]]; then
      echo "CHAT_DEPLOY_RUNNING: $SHA"; exit 0
    fi
    sleep 1
  done
  if [[ "$OLD" =~ ^releases/[a-f0-9]{40}$ ]]; then
    switch_to "$OLD"
    printf '%s\n' "${OLD#releases/}" > "$BIN/deployed-sha"
    echo 'CHAT_DEPLOY_START_FAILED: previous Go release restored; PHP/SQL are not rolled back'
  else
    echo 'CHAT_DEPLOY_START_FAILED: inspect Go project logs'
  fi
  exit 1
fi
echo "CHAT_DEPLOY_BUILT: $SHA; create/start the BaoTa Go project once"
