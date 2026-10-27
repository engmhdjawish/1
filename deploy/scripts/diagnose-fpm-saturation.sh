#!/usr/bin/env bash
# PHP-FPM pool exhausted → nginx error:
#   connect() to unix:...php8.5-fpm.sock failed (11: Resource temporarily unavailable)
set -uo pipefail

DEPLOY_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck disable=SC1091
source "$DEPLOY_ROOT/lib/common.sh"

PHP_FPM_UNIT="${PHP_FPM_UNIT:-php8.5-fpm}"
POOL="${PHP_FPM_POOL:-www}"
POOL_FILE="${PHP_FPM_POOL_FILE:-/etc/php/8.5/fpm/pool.d/${POOL}.conf}"
NGINX_ERROR_LOG="${NGINX_ERROR_LOG:-/var/log/nginx/error.log}"

step "PHP-FPM saturation check ($PHP_FPM_UNIT)"

if systemctl is-active "$PHP_FPM_UNIT" >/dev/null 2>&1; then
  ok "$PHP_FPM_UNIT is active"
else
  fail "$PHP_FPM_UNIT is not active"
fi

worker_count="$(pgrep -fc 'php-fpm: pool' 2>/dev/null || echo 0)"
echo "Running FPM pool workers: $worker_count"

if [[ -f "$POOL_FILE" ]]; then
  echo ""
  grep -E '^(pm\s*=|pm\.max_children|pm\.start_servers|pm\.min_spare_servers|pm\.max_spare_servers|pm\.max_requests|request_terminate_timeout)\s*=' "$POOL_FILE" || true
else
  warn "Pool file not found: $POOL_FILE"
fi

echo ""
step "Recent nginx socket errors (errno 11 = pool full / backlog)"
if [[ -r "$NGINX_ERROR_LOG" ]]; then
  count11="$(grep -c 'failed (11: Resource temporarily unavailable)' "$NGINX_ERROR_LOG" 2>/dev/null || echo 0)"
  echo "Total errno-11 lines in log (may be large): $count11"
  grep 'failed (11: Resource temporarily unavailable)' "$NGINX_ERROR_LOG" 2>/dev/null | tail -n 5 || ok "No errno 11 in tail"
else
  warn "Cannot read $NGINX_ERROR_LOG"
fi

echo ""
step "nginx duplicate server_name (wrong vhost + double load)"
if [[ -d /etc/nginx/sites-enabled ]]; then
  grep -Rh 'server_name' /etc/nginx/sites-enabled/ 2>/dev/null | sort | uniq -c | sort -nr | head -n 8 || true
  shopt -s nullglob
  for f in /etc/nginx/sites-enabled/*.bak*; do
    warn "Remove backup from sites-enabled: $f"
  done
fi

echo ""
ok "Immediate relief: sudo systemctl restart $PHP_FPM_UNIT"
ok "Then raise pm.max_children in $POOL_FILE — see deploy/scripts/tune-php-fpm-jawish.sh"
