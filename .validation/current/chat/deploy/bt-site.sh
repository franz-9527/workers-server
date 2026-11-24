#!/usr/bin/env bash
# Sourced by bt-deploy.sh and bt-run.sh after resolving the physical checkout.
chat_php_cli() {
  local candidate
  local -a candidates
  if [[ -n "${CHAT_PHP_BIN:-}" ]]; then
    candidates=("$CHAT_PHP_BIN")
  else
    candidates=(/www/server/php/83/bin/php "$(command -v php || true)" /usr/bin/php /usr/local/bin/php /www/server/php/*/bin/php)
  fi
  for candidate in "${candidates[@]}"; do
    [[ -n "$candidate" && -x "$candidate" ]] || continue
    if "$candidate" -r 'exit(PHP_SAPI === "cli" && PHP_VERSION_ID >= 80336 && extension_loaded("pdo_mysql") ? 0 : 1);' 2>/dev/null; then
      PHP_BIN="$candidate"; return 0
    fi
  done
  echo 'CHAT_PRODUCTION_PHP_CLI_NOT_FOUND: need PHP CLI 8.3.36+ with pdo_mysql; set CHAT_PHP_BIN if installed at a custom path'; return 2
}

chat_site() {
  CHAT_SITE=test
  PHP_ROOT="$ROOT/server"
  if [[ $2 == 0 ]]; then
    if [[ "$ROOT" == /www/wwwroot/worker || ( -n "${CHAT_PHP_ROOT:-}" && "$ROOT" == "$CHAT_PHP_ROOT" ) ]]; then
      echo 'CHAT_DEPLOY_PRODUCTION_FLAG_REQUIRED'; return 2
    fi
    return 0
  fi
  if [[ $2 != 1 || $1 != --production ]]; then echo 'CHAT_DEPLOY_USAGE: optional --production'; return 2; fi

  CHAT_SITE=production
  chat_php_cli || return $?
  PHP_ROOT=$(cd -- "${CHAT_PHP_ROOT:-/www/wwwroot/worker}" && pwd -P) || return 2
  if [[ "$ROOT" == "$PHP_ROOT" ]]; then
    # The standalone package uses a real chat/ directory in the installed PHP site.
    local owner
    owner=$(stat -c "%U:%G:%a" -- "$CHAT") || return 2
    if [[ "$CHAT" != "$PHP_ROOT/chat" || ( "$owner" != root:www:750 && "$owner" != www:www:750 ) ]]; then
      echo 'CHAT_DEPLOY_PRODUCTION_CHAT_DIRECTORY_PERMISSIONS: expected root:www or www:www with mode 0750'; return 2
    fi
  else
    case "$ROOT/" in "$PHP_ROOT/"*|/www/wwwroot/*)
      echo 'CHAT_DEPLOY_PRODUCTION_PRIVATE_CHECKOUT_REQUIRED'; return 2 ;;
    esac
  fi
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
