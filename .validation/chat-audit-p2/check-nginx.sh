#!/usr/bin/env bash
set -euo pipefail
src="$(dirname "$0")/deploy/nginx.conf.example"
cfg=/tmp/chat-audit-p2-nginx.conf
{
  echo 'error_log stderr;'
  echo 'pid /tmp/chat-audit-p2-nginx.pid;'
  echo 'events {}'
  echo 'http {'
  echo 'access_log off;'
  grep '^limit_.*_zone ' "$src"
  echo 'server {'
  echo 'listen 127.0.0.1:18080;'
  sed '/^limit_.*_zone /d' "$src"
  echo '}'
  echo '}'
} > "$cfg"
nginx -t -c "$cfg" -p /tmp
grep -q 'limit_req zone=chat_ws_handshake burst=60 nodelay;' "$src"
grep -q 'limit_conn chat_ws_ip 64;' "$src"
grep -q 'limit_conn chat_ws_global 400;' "$src"
