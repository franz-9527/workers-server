#!/usr/bin/env bash
set -euo pipefail
src="$(realpath .validation/source)"
fixture="$(mktemp -d)"
trap 'rm -rf "$fixture"' EXIT
site=/www/wwwroot/worker
# This is a fresh ephemeral hosted runner; never touch a preexisting site.
[[ ! -e "$site" ]]
getent group www >/dev/null || groupadd www
id www >/dev/null 2>&1 || useradd -g www -M -s /usr/sbin/nologin www
install -d -m 0755 "$site" "$site/config"
install -d -m 0750 -o root -g www "$site/chat"
install -d -m 0700 -o root -g root "$site/chat/update"
install -d -m 0700 -o www -g www "$site/storage/private"
# Use real PHP CLI in the installer, not an emulated version.
export CHAT_PHP_BIN="$TEST_PHP"
"$CHAT_PHP_BIN" -r 'exit(PHP_VERSION_ID>=80336&&PHP_VERSION_ID<80400&&extension_loaded("pdo_mysql")?0:2);'
cp .validation/fixture-bootstrap.php "$site/bootstrap.php"
printf '<?php return ["app"=>["env"=>"production"]];\n' > "$site/config/installed.php"
echo '2.8.3' > "$site/VERSION"
echo off > "$site/fixture-mode"
cd "$fixture"
git init -q
git config user.email fixture@example.invalid
git config user.name fixture
mkdir -p chat/deploy chat/helpers/images chat/helpers/media server chat/bin
cp "$src/chat/deploy/"*.sh "$src/chat/deploy/production-runtime.php" chat/deploy/
cp "$src/chat/chat.production.example.json" chat/
printf '<?php\n' > chat/deploy/web-update.php
printf '<?php\n' > chat/deploy/init-production-recovery.php
touch chat/deploy/nginx.conf.example chat/helpers/preflight.py chat/helpers/images/inspect.py chat/helpers/media/inspect.py
cp "$site/VERSION" server/VERSION
git add chat server
git commit -qm synthetic
sha=$(git rev-parse HEAD)
cat > chat/bin/chatd <<EOF
#!/bin/bash
if [[ "\$1" == version ]]; then
 echo '{"source_revision":"$sha","source_dirty":"false","goos":"linux","goarch":"amd64"}'
 exit
fi
[[ "\$1" == check-config ]] || exit 2
python3 - "\$3" <<'PY'
import json,sys
c=json.load(open(sys.argv[1]))
mode=open('/www/wwwroot/worker/fixture-mode').read().strip()
if mode=='base-fail' and c['messages']['enabled']: sys.exit(1)
if mode=='optional-preflight' and c['images']['enabled']: sys.exit(1)
PY
EOF
chmod +x chat/bin/chatd
bash chat/deploy/pack-production.sh dist
archive="$fixture/dist/workers-chat-go-linux-amd64-${sha:0:12}.zip"
unzip -t "$archive"
unzip -q "$archive" -d "$site/chat/update"
echo "$sha" > "$site/SOURCE-COMMIT.txt"
run_install(){ bash "$site/chat/update/deploy/update-production.sh"; }
fail_install(){ if run_install; then echo "unexpected installation success" >&2; exit 1; fi; }
run_install
[[ $(readlink "$site/chat/bin/current") == "releases/$sha" ]]
[[ $(readlink "$site/chat/bin/chatd") == current/chatd ]]
[[ ! -f "$site/chat/deploy/release-public.key" ]]
echo 'PASS first install without web upload public key'
state="$site/chat/bin/state"
control="$site/storage/private/chat-runtime-control"
run_runtime(){ runuser -u www -- "$CHAT_PHP_BIN" "$site/chat/deploy/production-runtime.php" "$site" "$state" "$sha"; }
echo optional-error > "$site/fixture-mode"
run_runtime
python3 - "$state" <<'PY'
import json,sys,pathlib
p=pathlib.Path(sys.argv[1])
for name in ('base','text'):
 c=json.loads((p/f'production.{name}.json').read_text())
 assert c['messages']['enabled'] and c['auth']['enabled'] and c['safety']['enabled']
 assert not c['images']['enabled'] and not c['calls']['enabled']
 assert pathlib.Path(c['release']['file']).is_file()
PY
grep -q OPTIONAL_CONFIG "$control/production-feature-status"
echo 'PASS optional renderer error keeps text'
echo optional-preflight > "$site/fixture-mode"
run_install
echo 'PASS optional preflight fallback'
echo missing > "$site/fixture-mode"
set +e
run_runtime
rc=$?
set -e
[[ $rc == 4 && ! -e "$state/production.text.env" ]]
grep -q REDIS "$control/production-text-status"
echo 'PASS core missing configuration remains pending'
echo base-fail > "$site/fixture-mode"
fail_install
[[ $(readlink "$site/chat/bin/current") == "releases/$sha" ]]
[[ -f "$site/chat/update-backup/failed/current" || -d "$site/chat/update-backup/failed" ]]
echo 'PASS base preflight failure keeps prior release'
echo off > "$site/fixture-mode"
echo wrong > "$site/SOURCE-COMMIT.txt"
fail_install
echo "$sha" > "$site/SOURCE-COMMIT.txt"
echo 'PASS mismatched PHP source rejected'
printf '\n# tampered\n' >> "$site/chat/update/deploy/bt-run.sh"
fail_install
echo 'PASS corrupt package rejected'
