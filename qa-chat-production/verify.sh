#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
site=/www/wwwroot/worker
source_sha=ea22d377c9e7af55fa9499230259b0b1483a403e
export CHAT_ACCESS_SEED=$(printf "%032d" 0 | base64 -w0)
sudo id www >/dev/null 2>&1 || sudo useradd --system --user-group www
sudo mkdir -p "$site" /var/lib/bixuan-chat-prod /www/backup-control/bixuan-chat/production /www/server/php/83/bin /var/lib/chatqa-bin
sudo chown www:www /var/lib/bixuan-chat-prod
sudo chmod 700 /var/lib/bixuan-chat-prod
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
trap 'sudo cat /tmp/chatqa-binlog-error 2>/dev/null || true; docker logs --tail 20 chatqa-mysql' ERR
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
mysql chat_fixture -e "INSERT INTO schema_migrations(version,migration_name,checksum_sha256,status,applied_at) VALUES ('$(tr -d '\r\n' < server/VERSION)','fixture',REPEAT('0',64),'success',NOW()); INSERT INTO system_settings(setting_key,setting_value,value_type,group_name,is_public,created_at,updated_at) VALUES ('wechat.miniapp_appid','wxfixture','string','wechat',0,NOW(),NOW()),('chat.enabled','1','string','chat',0,NOW(),NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);"
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
trap 'kill "$writer" 2>/dev/null || true; sudo cat /tmp/chatqa-binlog-error 2>/dev/null || true' EXIT
sudo env CHAT_RECOVERY_DB_USER=root CHAT_RECOVERY_DB_PASSWORD=fixture php "$site/chat/deploy/init-production-recovery.php" "$binlog"
kill "$writer"
trap - EXIT
sudo -u www php -d "open_basedir=$site/:/tmp/:/www/backup-control/bixuan-chat/production/" -r 'require $argv[1]; if (WorkerAdmin\Infrastructure\Chat\ChatRecoveryGate::status(app_config("chat"),"production")!=="ready") exit(1); echo "PHP recovery gate is readable\n";' "$site/bootstrap.php"
sudo -u www php -d "open_basedir=$site/:/tmp/" -r 'require $argv[1]; if (WorkerAdmin\Infrastructure\Chat\ChatRecoveryGate::status(app_config("chat"),"production")!=="unreadable") exit(1); echo "PHP reports an inaccessible recovery gate separately\n";' "$site/bootstrap.php"
export CHAT_WECHAT_CALLBACK_TOKEN=FixtureToken20260001 CHAT_WECHAT_CALLBACK_AES_KEY=AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA
sudo -u www --preserve-env=CHAT_WECHAT_CALLBACK_TOKEN,CHAT_WECHAT_CALLBACK_AES_KEY php "$site/chat/deploy/production-runtime.php" "$site" /var/lib/bixuan-chat-prod
cd chat
go test ./internal/bootstrap ./internal/dependencies
go build -trimpath -ldflags="-X github.com/franz-9527/workers/chat/internal/bootstrap.sourceRevision=$source_sha -X github.com/franz-9527/workers/chat/internal/bootstrap.sourceDirty=false" -o /tmp/chatqa ./cmd/chatd
state=/var/lib/bixuan-chat-prod
probe_config() {
  sudo -u www bash -c 'source "$1"; [[ ! -s "$2" ]] || source "$2"; export CHAT_RELEASE_KEY="$3"; exec /tmp/chatqa check-config --config "$4" --probe' _ \
    "$state/production.runtime.env" "$state/production.text.env" "$release_key" "$1"
}
release_key=$(printf '%032d' 1 | base64 -w0)
set +e
sudo -u www --preserve-env=CHAT_WECHAT_CALLBACK_TOKEN,CHAT_WECHAT_CALLBACK_AES_KEY,CHAT_ACCESS_SEED env CHAT_PROD_TEXT=1 php "$site/chat/deploy/production-runtime.php" "$site" "$state" > /tmp/chatqa-unreleased.log 2>&1
missing_rc=$?
set -e
test "$missing_rc" = 4
grep -q 'CHAT_PRODUCTION_TEXT_PENDING: RELEASE' /tmp/chatqa-unreleased.log
# Fixture seal is signed with a throwaway key and never leaves this runner.
epoch=$(sudo cat "$site/config/chat-production-recovery.json")
release_path="$state/fixture-release.json"
sudo env QA_EPOCH="$epoch" QA_REVISION="$source_sha" QA_RELEASE_KEY="$release_key" QA_RELEASE_PATH="$release_path" python3 - <<'PY'
import base64, hashlib, hmac, json, os, time
env = os.environ
body = json.dumps(dict(schema=1, environment="production", epoch=env["QA_EPOCH"],
    revision=env["QA_REVISION"], expires_at=int(time.time())+600,
    capabilities=["text","governance"]), separators=(",",":")).encode()
with open(env["QA_RELEASE_PATH"], "w") as f:
    json.dump(dict(payload=base64.b64encode(body).decode(),
        mac=hmac.new(base64.b64decode(env["QA_RELEASE_KEY"]), body, hashlib.sha256).hexdigest()), f)
PY
sudo chown www:www "$release_path"
sudo chmod 600 "$release_path"
sudo -u www --preserve-env=CHAT_WECHAT_CALLBACK_TOKEN,CHAT_WECHAT_CALLBACK_AES_KEY,CHAT_ACCESS_SEED env CHAT_PROD_TEXT=1 CHAT_PROD_RELEASE_FILE="$release_path" CHAT_RELEASE_KEY="$release_key" php "$site/chat/deploy/production-runtime.php" "$site" "$state"
probe_config "$state/production.text.json" | python3 -c 'import json,sys;x=json.load(sys.stdin);assert x["business_enabled"] and x["infrastructure_ready"] and x["mysql"]["state"]=="ready" and x["redis"]["state"]=="ready",x'
sudo -u www bash -c 'source "$1"; source "$2"; export CHAT_RELEASE_KEY="$3"; exec /tmp/chatqa serve --config "$4"' _ \
  "$state/production.runtime.env" "$state/production.text.env" "$release_key" "$state/production.text.json" &
trap 'sudo pkill -x chatqa || true' EXIT
for _ in $(seq 1 15); do if curl -fsS http://127.0.0.1:9502/readyz; then break; fi; sleep 1; done
test "$(curl -sS -o /dev/null -w '%{http_code}' http://127.0.0.1:9501/chat/api/v1/av/callback)" = 403
sudo -u www php -d "open_basedir=$site/:/tmp/:/www/backup-control/bixuan-chat/production/" -r 'require $argv[1]; $s=(new WorkerAdmin\Application\Chat\ChatGoRuntimeStatus)->status(); if (empty($s["business_ready"])) exit(1); echo "PHP sees Go business ready\n";' "$site/bootstrap.php"
echo 'Recovery gate, callback and Go business readiness verified'
