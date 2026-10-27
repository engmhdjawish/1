#!/usr/bin/env bash
# Raise PHP-FPM concurrency for Jawish Portal (store + crawlers). Run on VPS as root.
set -euo pipefail

DEPLOY_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck disable=SC1091
source "$DEPLOY_ROOT/lib/common.sh"

if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
  fail "Run as root: sudo bash $0"
fi

PHP_VERSION="${PHP_VERSION:-8.5}"
POOL="${PHP_FPM_POOL:-www}"
POOL_FILE="/etc/php/${PHP_VERSION}/fpm/pool.d/${POOL}.conf"
MAX_CHILDREN="${PM_MAX_CHILDREN:-40}"
MAX_REQUESTS="${PM_MAX_REQUESTS:-400}"
TERMINATE_TIMEOUT="${REQUEST_TERMINATE_TIMEOUT:-120s}"

[[ -f "$POOL_FILE" ]] || die "Missing $POOL_FILE"

cp -a "$POOL_FILE" "${POOL_FILE}.bak.$(date +%Y%m%d%H%M%S)"

set_kv() {
  local key="$1"
  local val="$2"
  if grep -qE "^;\s*${key}\s*=" "$POOL_FILE" || grep -qE "^${key}\s*=" "$POOL_FILE"; then
    sed -i -E "s|^;?\s*${key}\s*=.*|${key} = ${val}|" "$POOL_FILE"
  else
    echo "${key} = ${val}" >> "$POOL_FILE"
  fi
}

set_kv "pm" "dynamic"
set_kv "pm.max_children" "$MAX_CHILDREN"
set_kv "pm.start_servers" "8"
set_kv "pm.min_spare_servers" "4"
set_kv "pm.max_spare_servers" "16"
set_kv "pm.max_requests" "$MAX_REQUESTS"
set_kv "request_terminate_timeout" "$TERMINATE_TIMEOUT"

systemctl restart "php${PHP_VERSION}-fpm"
ok "Updated $POOL_FILE — pm.max_children=$MAX_CHILDREN, restart php${PHP_VERSION}-fpm"

echo "Verify:"
echo "  bash deploy/scripts/diagnose-fpm-saturation.sh"
echo "  curl -sSk -o /dev/null -w '%{http_code}\n' -H 'Host: jawishco.sy' https://127.0.0.1/index.php"
