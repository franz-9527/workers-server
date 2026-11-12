#!/usr/bin/env bash
# Sourced by bt-deploy.sh and bt-run.sh after resolving the physical checkout.
chat_site() {
  CHAT_SITE=test
  PHP_ROOT="$ROOT/server"
  if [[ $2 == 0 ]]; then return 0; fi
  if [[ $2 != 1 || $1 != --production ]]; then echo 'CHAT_DEPLOY_USAGE: optional --production'; return 2; fi

  CHAT_SITE=production
  PHP_ROOT=$(cd -- "${CHAT_PHP_ROOT:-/www/wwwroot/worker}" && pwd -P) || return 2
  case "$ROOT/" in "$PHP_ROOT/"*|/www/wwwroot/*)
    echo 'CHAT_DEPLOY_PRODUCTION_PRIVATE_CHECKOUT_REQUIRED'; return 2 ;;
  esac
  if [[ -e "$ROOT/server/config/installed.php" || ! -f "$PHP_ROOT/config/installed.php" ]] ||
    ! "$PHP_BIN" -r '$c=require $argv[1]; exit(($c["app"]["env"]??"")==="production"?0:2);' "$PHP_ROOT/config/installed.php"; then
    echo 'CHAT_DEPLOY_PRODUCTION_SITE_REQUIRED'; return 2
  fi
}

chat_site_config() {
  [[ -f "$1" ]] || return 2
  "$PHP_BIN" -r '
    $c=json_decode(file_get_contents($argv[1]),true,64);
    if(($c["env"]??"")!==$argv[2])exit(2);
    if($argv[2]==="production"&&(($c["http"]["listen"]??"")!=="127.0.0.1:9501"||($c["http"]["internal_listen"]??"")!=="127.0.0.1:9502"))exit(2);
  ' "$1" "$CHAT_SITE"
}
