#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
repo_root=$(cd .. && pwd -P)
site=/www/wwwroot/worker
legacy_state=/var/lib/bixuan-chat-prod
state=$site/chat/bin/state
source_sha=$(tr -d '\r\n' < SOURCE_SHA)
[[ "$source_sha" =~ ^[a-f0-9]{40}$ ]] || { echo 'QA_SOURCE_SHA_INVALID' >&2; exit 2; }
while read -r expected path; do
  [[ "$expected" =~ ^[a-f0-9]{40}$ && -n "$path" ]] || { echo 'QA_SOURCE_MANIFEST_INVALID' >&2; exit 2; }
  actual=$(git -C "$repo_root" hash-object "$path")
  [[ "$actual" == "$expected" ]] || { echo "QA_SOURCE_SNAPSHOT_MISMATCH: $path" >&2; exit 2; }
done < SOURCE_MANIFEST
echo "Testing workers source SHA: $source_sha"

sudo id www >/dev/null 2>&1 || sudo useradd --system --user-group www
sudo mkdir -p "$site" "$legacy_state" /www/backup-control/bixuan-chat/production /www/server/php/83/bin /var/lib/chatqa-bin
sudo chown www:www "$legacy_state"
sudo chmod 0700 "$legacy_state"
# Simulate the legacy launcher's permissive lock file; migration must normalize it safely.
sudo -u www -- bash -c 'umask 077; : > "$1/run.lock"; printf "%s\n" "legacy-state-fixture" > "$1/legacy-sentinel"' _ "$legacy_state"
sudo chmod 0644 "$legacy_state/run.lock"
sudo chown root:www /www/backup-control/bixuan-chat/production
sudo chmod 2750 /www/backup-control/bixuan-chat/production
sudo cp -R server/. "$site/"
sudo cp -R chat "$site/"
sudo ln -s "$(command -v php)" /www/server/php/83/bin/php
sudo chown root:www "$site/chat"
sudo chmod 0750 "$site/chat"
sudo chown 999:999 /var/lib/chatqa-bin
sudo chmod 0750 /var/lib/chatqa-bin
sudo mkdir -p "$site/storage/private"
sudo chown -R www:www "$site/storage"
sudo touch "$site/storage/install.lock"

docker run -d --name chatqa-mysql -e MYSQL_ROOT_PASSWORD=fixture -e MYSQL_ROOT_HOST=% \
  -p 127.0.0.1:3306:3306 -v /var/lib/chatqa-bin:/var/lib/chatqa-bin -v /www/backup-control:/www/backup-control:ro mysql:5.7.44 \
  --server-id=991 --log-bin=/var/lib/chatqa-bin/mysql-bin --binlog-format=MIXED >/dev/null
docker run -d --name chatqa-redis -p 127.0.0.1:6379:6379 redis:7-alpine >/dev/null
trap 'docker logs --tail 20 chatqa-mysql || true' ERR
ready=0
for _ in $(seq 1 45); do
  if docker exec chatqa-mysql mysql -h127.0.0.1 -uroot -pfixture -e 'SELECT 1' >/dev/null 2>&1; then ready=1; break; fi
  if [[ $(docker inspect -f '{{.State.Running}}' chatqa-mysql) != true ]]; then break; fi
  sleep 1
done
[[ $ready == 1 ]]
mysql() { docker exec -i chatqa-mysql mysql -h127.0.0.1 -uroot -pfixture "$@"; }
mysql -e "CREATE DATABASE chat_fixture CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER 'chat_fixture'@'%' IDENTIFIED BY 'fixture-only'; GRANT ALL ON chat_fixture.* TO 'chat_fixture'@'%';"
mysql chat_fixture < server/database/database.sql
mysql chat_fixture -e "INSERT INTO schema_migrations(version,migration_name,checksum_sha256,status,applied_at) VALUES ('$(tr -d '\r\n' < server/VERSION)','fixture',REPEAT('0',64),'success',NOW()); INSERT INTO system_settings(setting_key,setting_value,value_type,group_name,is_public,created_at,updated_at) VALUES ('wechat.miniapp_appid','wxfixture','string','wechat',0,NOW(),NOW()),('chat.enabled','1','string','chat',0,NOW(),NOW()),('wechat.miniapp_secret','isolated-fixture-secret','string','wechat',0,NOW(),NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value); INSERT INTO admin_users(id,username,password_hash,role,status,must_change_password,created_at,updated_at) VALUES(1,'fixture_admin','fixture-not-a-login-hash','super_admin','active',0,NOW(),NOW());"
version=$(tr -d '\r\n' < server/VERSION)
sudo tee "$site/config/installed.php" >/dev/null <<PHP
<?php
return ['installed'=>true,'app'=>['env'=>'production','version'=>'$version','url'=>'https://chat-fixture.invalid','key'=>str_repeat('k',32)],
'database'=>['host'=>'127.0.0.1','port'=>3306,'database'=>'chat_fixture','username'=>'chat_fixture','password'=>'fixture-only','charset'=>'utf8mb4']];
PHP
if ! command -v mysqlbinlog >/dev/null; then
  sudo apt-get -qq update
  sudo apt-get -qq install -y mysql-client
fi
binlog=$(command -v mysqlbinlog)
"$binlog" --no-defaults --version
sudo env CHAT_RECOVERY_DB_USER=root CHAT_RECOVERY_DB_PASSWORD=fixture php "$site/chat/deploy/init-production-recovery.php" "$binlog" --check
(while :; do mysql chat_fixture -e "UPDATE system_settings SET updated_at=NOW(6) WHERE setting_key='chat.enabled'" >/dev/null 2>&1; sleep .15; done) &
writer=$!
trap 'kill "$writer" 2>/dev/null || true; docker logs --tail 20 chatqa-mysql || true' ERR
sudo env CHAT_RECOVERY_DB_USER=root CHAT_RECOVERY_DB_PASSWORD=fixture php "$site/chat/deploy/init-production-recovery.php" "$binlog"
kill "$writer"
wait "$writer" 2>/dev/null || true
trap - ERR
sudo -u www php -d "open_basedir=$site/:/tmp/:/www/backup-control/bixuan-chat/production/" -r 'require $argv[1]; if (WorkerAdmin\Infrastructure\Chat\ChatRecoveryGate::status(app_config("chat"),"production")!=="ready") exit(1); echo "PHP recovery gate is readable\n";' "$site/bootstrap.php"
sudo -u www php -d "open_basedir=$site/:/tmp/" -r 'require $argv[1]; if (WorkerAdmin\Infrastructure\Chat\ChatRecoveryGate::status(app_config("chat"),"production")!=="unreadable") exit(1); echo "PHP reports an inaccessible recovery gate separately\n";' "$site/bootstrap.php"

# Exercise the same managed first-enable path used by the super-administrator UI.
sudo -u www php -r '
require $argv[1];
$db=db();
$cipher=(new WorkerAdmin\Foundation\ApplicationContainer($db))->privateCipher();
$config=(array)app_config("chat",[]);
$signing=new WorkerAdmin\Application\Chat\ChatSigningKeyManager($db,$cipher);
$state=$signing->status($config);
if(empty($state["configured"])) $state=$signing->generate($config,1,"super_admin");
if(empty($state["configured"])||empty($state["public_keys"])) throw new RuntimeException("CHAT_QA_SIGNING_SETUP_FAILED");
$runtime=new WorkerAdmin\Application\Chat\ChatGoRuntimeConfig($db,$cipher);
$runtime->prepareProduction(1,"super_admin");
$direct=$db->fetchValue("SELECT setting_value FROM system_settings WHERE setting_key=:key LIMIT 1",["key"=>"chat.go_production_text_requested"]);
$helper=setting("chat.go_production_text_requested","0");
if((string)$direct!=="1"||(string)$helper!=="1") throw new RuntimeException("CHAT_QA_PRODUCTION_REQUEST_NOT_PERSISTED direct=".(string)$direct." helper=".(string)$helper);
echo "Managed PHP signing and production release key initialized; request=".$helper."\n";
' "$site/bootstrap.php"

cd chat
go test ./internal/bootstrap ./internal/dependencies
bash -n deploy/bt-run.sh
bash -n deploy/bt-deploy.sh
bash -n deploy/update-production.sh
go build -trimpath -ldflags="-X github.com/franz-9527/workers/chat/internal/bootstrap.sourceRevision=$source_sha -X github.com/franz-9527/workers/chat/internal/bootstrap.sourceDirty=false" -o /tmp/chatqa ./cmd/chatd
cd ..

# Seed an older installed binary so the real signed updater exercises the migration and switch.
base=$(printf '0%.0s' {1..40})
sudo install -d -m 0755 "$site/chat/bin/releases/$base"
sudo install -m 0755 /tmp/chatqa "$site/chat/bin/releases/$base/chatd"
sudo ln -s "releases/$base" "$site/chat/bin/current"
sudo ln -s current/chatd "$site/chat/bin/chatd"
sudo install -d -m 0700 -o root -g root "$site/chat/update"
sudo bash -s -- "$site/chat/update" "$PWD" "$site" <<'SH'
set -eu
update=$1
sources=$2
site=$3
cd "$update"
mkdir -p deploy payload site
for f in bt-run.sh bt-site.sh production-runtime.php init-production-recovery.php update-production.sh web-update.php; do
  cp "$sources/chat/deploy/$f" "deploy/$f"
done
cp "$sources/chat/chat.production.example.json" .
for f in bootstrap.php app/Application/Chat/ChatGoUpdate.php app/Application/Chat/ChatRuntimePaths.php app/Application/Chat/ChatRuntimeControl.php app/Http/Admin/Modules/ChatSettingsAdminModule.php views/admin/chat-settings.php app/Infrastructure/Chat/ChatRecoveryGate.php app/Infrastructure/Chat/ChatRecoveryCheckpoint.php app/Application/Chat/ChatGoRuntimeConfig.php app/Application/Chat/ChatGoRuntimeStatus.php bin/chat-recovery.php; do
  mkdir -p "site/$(dirname "$f")"
  cp "$sources/server/$f" "site/$f"
done
cp /tmp/chatqa payload/chatd
/tmp/chatqa version > payload/version.json
printf '%032d' 0 | base64 > deploy/release-public.key
find . -type f ! -name SHA256SUMS.txt -printf "%P\0" | sort -z | xargs -0 sha256sum > SHA256SUMS.txt
SH

sudo bash "$site/chat/update/deploy/update-production.sh"
echo "QA runtime state: $(sudo stat -c '%U:%G:%a' "$state")"
echo "QA lock state: $(sudo stat -c '%U:%G:%a' "$state/run.lock")"
echo "QA production status: $(sudo cat "$site/storage/private/chat-runtime-control/production-text-status" 2>/dev/null || echo missing)"
sudo ls -la "$state"
sudo ls -la "$legacy_state.migrated" || true
sudo -u www php -r 'require $argv[1]; $direct=db()->fetchValue("SELECT setting_value FROM system_settings WHERE setting_key=:key LIMIT 1",["key"=>"chat.go_production_text_requested"]); $helper=setting("chat.go_production_text_requested","0"); echo "Post-deploy request direct=".(string)$direct." helper=".(string)$helper."\n";' "$site/bootstrap.php"
set -x
test "$(sudo readlink "$site/chat/bin/current")" = "releases/$source_sha"
test "$(sudo stat -c '%U:%G:%a' "$state")" = "www:www:700"
test "$(sudo stat -c '%a' "$state/run.lock")" = "600"
sudo test -f "$state/legacy-sentinel"
sudo test -f "$legacy_state.migrated/legacy-sentinel"
test "$(sudo stat -c '%a' "$legacy_state.migrated/run.lock")" = "600"
sudo test ! -e "$legacy_state"
sudo test ! -e "$state/production.local.env"
sudo test -s "$state/production.text.json"
sudo test -s "$state/production.release.json"
set +x
echo "Legacy state migration preserved private data and normalized run.lock"

probe_config() {
  sudo -u www bash -c 'source "$1"; [[ ! -s "$2" ]] || source "$2"; exec /tmp/chatqa check-config --config "$3" --probe' _ \
    "$state/production.runtime.env" "$state/production.text.env" "$1"
}
probe_config "$state/production.text.json" | python3 -c 'import json,sys;x=json.load(sys.stdin);assert x["business_enabled"] and x["infrastructure_ready"] and x["mysql"]["state"]=="ready" and x["redis"]["state"]=="ready",x'

# Seed a synthetic WeChat token cache; no vendor network call is made.
sudo -u www php -r '
$dir=$argv[1]."/storage/private/wechat-tokens";
if(!is_dir($dir) && !mkdir($dir,0700,true)) exit(1);
$app="wxfixture"; $env="production"; $now=time();
$file=$dir."/".hash("sha256",$env."\n".$app).".json";
file_put_contents($file,json_encode(["schema_version"=>1,"env"=>$env,"app_id"=>$app,"token"=>"fixture_token_for_export","issued_at"=>$now,"expires_at"=>$now+1200,"credential_hash"=>hash("sha256",$env."\n".$app."\nisolated-fixture-secret")]));
chmod($file,0600);
' "$site"

sudo -u www bash "$site/chat/deploy/bt-run.sh" --production >/tmp/chatqa-launch.log 2>&1 &
launcher=$!
cleanup_launcher() {
  sudo cat /tmp/chatqa-launch.log || true
  kill "$launcher" 2>/dev/null || true
  wait "$launcher" 2>/dev/null || true
}
trap cleanup_launcher EXIT
ready=0
ready_http=000
for _ in $(seq 1 30); do
  ready_http=$(curl -sS -o /tmp/chatqa-ready.json -w '%{http_code}' http://127.0.0.1:9502/readyz || true)
  if [[ "$ready_http" == 200 ]]; then ready=1; break; fi
  sleep 1
done
echo "Go readiness HTTP=$ready_http body=$(cat /tmp/chatqa-ready.json 2>/dev/null || true)"
sudo cat /tmp/chatqa-launch.log || true
[[ $ready == 1 ]] || { echo 'QA_GO_READINESS_FAILED'; exit 1; }
live_http=$(curl -sS -o /tmp/chatqa-live.txt -w '%{http_code}' http://127.0.0.1:9501/livez || true)
echo "Go liveness HTTP=$live_http"
test "$live_http" = 200
callback_http=$(curl -sS -o /tmp/chatqa-callback.txt -w '%{http_code}' http://127.0.0.1:9501/chat/api/v1/av/callback || true)
echo "Optional media callback without L1 configuration HTTP=$callback_http"
test "$callback_http" = 404
! grep -q 'healthy 9501 /chat/api/v1/av/callback' chat/deploy/bt-run.sh
sudo -u www php -d "open_basedir=$site/:/tmp/:/www/backup-control/bixuan-chat/production/" -r 'require $argv[1]; $s=(new WorkerAdmin\Application\Chat\ChatGoRuntimeStatus)->status(); if (empty($s["business_ready"])) { fwrite(STDERR,"PHP_GO_BUSINESS_NOT_READY ".json_encode($s)."\n"); exit(1); } echo "PHP sees Go business ready\n";' "$site/bootstrap.php"
text_status=$(sudo cat "$site/storage/private/chat-runtime-control/production-text-status")
echo "Go production text status=$text_status"
test "$text_status" = READY

# A second BaoTa launcher must fail immediately on the same exclusive lock.
second_log=$(mktemp)
set +e
sudo -u www bash "$site/chat/deploy/bt-run.sh" --production >"$second_log" 2>&1
second_rc=$?
set -e
echo "Second launcher exit=$second_rc output=$(cat "$second_log")"
test "$second_rc" = 2
grep -q 'CHAT_RUN_ALREADY_STARTED' "$second_log"
test "$(sudo stat -c '%a' "$state/run.lock")" = 600
echo "BaoTa launcher exclusive lock verified"

# A blocked recovery marker must close business readiness without interrupting callback dispatch.
gate=/www/backup-control/bixuan-chat/production/gate.json
sudo sed -i 's/"state":"ready"/"state":"blocked"/' "$gate"
sudo chown root:www "$gate"
sudo chmod 640 "$gate"
test "$(curl -sS -o /dev/null -w '%{http_code}' http://127.0.0.1:9502/readyz)" = 503
test "$(curl -sS -o /dev/null -w '%{http_code}' http://127.0.0.1:9501/chat/api/v1/av/callback)" = 404
sudo -u www php -d "open_basedir=$site/:/tmp/:/www/backup-control/bixuan-chat/production/" -r 'require $argv[1]; if (WorkerAdmin\Infrastructure\Chat\ChatRecoveryGate::status(app_config("chat"),"production")!=="blocked") exit(1);' "$site/bootstrap.php"
sudo sed -i 's/"state":"blocked"/"state":"ready"/' "$gate"
sudo chown root:www "$gate"
sudo chmod 640 "$gate"
for _ in $(seq 1 15); do if curl -fsS http://127.0.0.1:9502/readyz >/dev/null; then break; fi; sleep 1; done
curl -fsS http://127.0.0.1:9502/readyz >/dev/null
echo 'Recovery blocks business routes without interrupting callback dispatch'

cleanup_launcher
trap - EXIT
docker rm -f chatqa-redis chatqa-mysql >/dev/null
echo "Isolated production chat deployment verified for source SHA $source_sha"
