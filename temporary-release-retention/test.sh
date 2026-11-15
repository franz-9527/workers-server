#!/usr/bin/env bash
set -euo pipefail
here=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
source "$here/release-retention.sh"
bash -n "$here/release-retention.sh"
bash -n "$here/bt-deploy.sh"
grep -Fq 'source "$CHAT/deploy/release-retention.sh"' "$here/bt-deploy.sh"
[[ $(grep -Fc 'chat_prune_test_releases "$CHAT_SITE" "$BIN" "$STATE" "$SHA"' "$here/bt-deploy.sh") == 1 ]]

tmp=$(mktemp -d)
trap 'rm -rf -- "$tmp"' EXIT
bin="$tmp/bin"; state="$bin/state"; releases="$bin/releases"
mkdir -p "$releases" "$state"
sha(){ printf '%040x' "$1"; }

for i in $(seq 1 10); do
  d="$releases/$(sha "$i")"
  mkdir "$d"
  printf 'fixture\n' > "$d/marker"
  touch -d "@$i" "$d"
done
mkdir "$releases/manual-note"
ln -s manual-note "$releases/$(sha 11)"
ln -s "releases/$(sha 1)" "$bin/current"
printf '%s\n' "$(sha 2)" > "$bin/deployed-sha"
printf '%s\n' "$(sha 3)" > "$state/running-sha"

out=$(chat_prune_test_releases test "$bin" "$state" "$(sha 3)")
printf '%s\n' "$out"
for i in 1 2 3 6 7 8 9 10; do test -d "$releases/$(sha "$i")"; done
for i in 4 5; do test ! -e "$releases/$(sha "$i")"; done
test -d "$releases/manual-note"
test -L "$releases/$(sha 11)"
[[ $(find "$releases" -mindepth 1 -maxdepth 1 -type d -name '????????????????????????????????????????' | wc -l) -eq 8 ]]
echo '[PASS] confirmed test deployment keeps active pointers plus five newest releases'

mkdir "$releases/$(sha 12)"
chat_prune_test_releases test "$bin" "$state" "$(sha 2)" >/dev/null
test -d "$releases/$(sha 12)"
echo '[PASS] running SHA mismatch disables cleanup'

mkdir "$releases/$(sha 13)"
chat_prune_test_releases production "$bin" "$state" "$(sha 3)" >/dev/null
test -d "$releases/$(sha 13)"
echo '[PASS] production deployment never auto-prunes'

rm -rf "$releases"
mkdir -p "$tmp/real-releases"
ln -s "$tmp/real-releases" "$releases"
msg=$(chat_prune_test_releases test "$bin" "$state" "$(sha 3)")
[[ "$msg" == *'CHAT_DEPLOY_RELEASE_PRUNE_SKIPPED'* ]]
echo '[PASS] symlinked releases root is fail-safe'

echo '[PASS] release retention isolated regression complete'
