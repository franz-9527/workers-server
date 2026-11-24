#!/usr/bin/env bash
set -euo pipefail
source_dir="$(pwd)"
work="$(mktemp -d)"
trap 'rm -rf -- "$work"' EXIT
export FIXTURE_ROOT="$work"
mkdir -p "$work/chat/deploy" "$work/server/config"
cp "$source_dir/chat/deploy/"{bt-deploy.sh,bt-site.sh,release-retention.sh} "$work/chat/deploy/"
printf '%s\n' "<?php return ['app'=>['env'=>'testing']];" > "$work/server/config/installed.php"
cat > "$work/php" <<'SH'
#!/usr/bin/env bash
exec docker run --rm -v "$FIXTURE_ROOT:$FIXTURE_ROOT:ro" review-php:8.3.36 php "$@"
SH
chmod +x "$work/php"
cd "$work"
git init -q -b main
git config user.name fixture
git config user.email fixture@example.invalid
git add chat
git commit -qm fixture
export CHAT_PHP_BIN="$work/php" CHAT_RUN_USER="$(id -un)" CHAT_CONFIG_MANUAL=0
check_failure(){
  local expected="$1" output
  if output=$(bash chat/deploy/bt-deploy.sh 2>&1); then echo "unexpected deploy success"; exit 1; fi
  echo "$output" | grep -F "$expected"
  test ! -e chat/bin/deployed-sha
  echo "PASS $expected"
}
check_failure "CHAT_DEPLOY_REF_MISMATCH: origin/main is missing"
git update-ref refs/remotes/origin/main HEAD
git switch -qc chat
check_failure "CHAT_DEPLOY_REF_MISMATCH: HEAD must equal origin/main"
git switch -q main
printf '\n# dirty\n' >> chat/deploy/bt-site.sh
check_failure "CHAT_DEPLOY_TRACKED_CHANGES"
git restore chat/deploy/bt-site.sh
echo fixture > chat/new-fixture.txt
git add chat/new-fixture.txt
git commit -qm ahead
check_failure "CHAT_DEPLOY_REF_MISMATCH: HEAD must equal origin/main"
