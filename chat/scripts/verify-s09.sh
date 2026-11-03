#!/usr/bin/env bash
# S09 acceptance only. No production database, no deployment, no schema
# writes outside explicitly isolated random test databases.
set -euo pipefail

fail() { printf 'CHAT_S09_VERIFY_BLOCKED: %s\n' "$1" >&2; exit 1; }
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$root"

[[ "${CHAT_AUTH_TEST_INTEGRATION:-}" == "1" ]] || fail "isolated integration switch must be exactly 1"
[[ "${CHAT_INTEGRATION_MYSQL_ADDR:-}" =~ ^127\.0\.0\.1:[0-9]+$ ]] || fail "MySQL must be explicitly loopback only"
[[ "${CHAT_INTEGRATION_REDIS_ADDR:-}" =~ ^127\.0\.0\.1:[0-9]+$ ]] || fail "Redis must be explicitly loopback only"
[[ "${CHAT_AUTH_TEST_REDIS_ADDRESS:-}" =~ ^127\.0\.0\.1:[0-9]+$ ]] || fail "Redis identity integration address must be loopback only"
[[ -n "${CHAT_INTEGRATION_ADMIN_PASSWORD:-}" ]] || fail "explicit isolated MySQL fixture administrator credential missing"
[[ -n "${CHAT_AUTH_TEST_PHP:-}" && -x "${CHAT_AUTH_TEST_PHP:-/nonexistent}" ]] || fail "explicit PHP binary path required"
[[ "${CHAT_AUTH_TEST_MYSQL_USER_HOST:-}" == "127.0.0.1" || "${CHAT_AUTH_TEST_MYSQL_USER_HOST:-}" == "%" ]] || fail "isolated SQL fixture user host not allowed"

[[ "$(go version)" =~ ^go[[:space:]]version[[:space:]]go1\.27\.3[[:space:]] ]] || fail "requires exact Go 1.27.3"
[[ "$("$CHAT_AUTH_TEST_PHP" -r 'echo PHP_VERSION;')" == "8.3.36" ]] || fail "requires exact PHP 8.3.36"

# Root privileges are restricted to ephemeral, randomly named test databases.
# Never interpolate the database address into SQL; parse its numeric port.
mysql_port="${CHAT_INTEGRATION_MYSQL_ADDR##*:}"
CHAT_S09_FIXTURE_PORT="$mysql_port" "$CHAT_AUTH_TEST_PHP" -r '
  $port = (int) getenv("CHAT_S09_FIXTURE_PORT");
  $db = new PDO("mysql:host=127.0.0.1;port=".$port.";charset=utf8mb4",
                "root", (string)getenv("CHAT_INTEGRATION_ADMIN_PASSWORD"),
                [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  $version = (string)$db->query("SELECT VERSION()")->fetchColumn();
  if (!preg_match("/^5\\.7\\.44(?:[-.].*)?$/D", $version)) {
    fwrite(STDERR, "CHAT_S09_VERIFY_BLOCKED: exact MySQL 5.7.44 required\n");
    exit(1);
  }
  echo "Isolated MySQL 5.7.44 verified\n";
'

echo 'S09: PHP contracts, upgrade checks and ephemeral MySQL fixtures'
"$CHAT_AUTH_TEST_PHP" server/tests/chat_schema_contract.php
"$CHAT_AUTH_TEST_PHP" server/tests/chat_identity_contract.php
"$CHAT_AUTH_TEST_PHP" server/tests/chat_policy_contract.php
"$CHAT_AUTH_TEST_PHP" server/tests/chat_text_security_contract.php

echo 'S09: Go module integrity, vet, compile and race-tested integration'
(
  cd chat
  export GOTOOLCHAIN=local
  go mod verify
  go vet ./...
  bash build.sh
  go test -race -count=1 ./...
)
echo 'CHAT_S09_ACCEPTANCE_PASSED (only for the exact checked-out revision)'
