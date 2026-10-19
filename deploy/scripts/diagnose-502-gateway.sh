#!/usr/bin/env bash
# Quick 502 Bad Gateway triage for Jawish Portal (nginx + PHP-FPM).
# Run on the production server over SSH.
set -euo pipefail

DEPLOY_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck disable=SC1091
source "$DEPLOY_ROOT/lib/common.sh"

if [[ -f "$DEPLOY_ROOT/deploy.env" ]]; then
  load_deploy_env "$DEPLOY_ROOT/deploy.env"
fi

PORTAL_PUBLISH_DIR="${PORTAL_PUBLISH_DIR:-/var/www/jawish-portal}"
PHP_FPM_UNIT="${PHP_FPM_UNIT:-php8.5-fpm}"
PHP_FPM_SOCK="${PHP_FPM_SOCK:-$(default_php_fpm_sock)}"
NGINX_ERROR_LOG="${NGINX_ERROR_LOG:-/var/log/nginx/error.log}"

step "502 triage — Portal at $PORTAL_PUBLISH_DIR"

echo ""
step "Services"
systemctl is-active nginx >/dev/null 2>&1 && ok "nginx active" || fail "nginx not active — sudo systemctl start nginx"
systemctl is-active "$PHP_FPM_UNIT" >/dev/null 2>&1 && ok "$PHP_FPM_UNIT active" || {
  fail "$PHP_FPM_UNIT not active — sudo systemctl start $PHP_FPM_UNIT"
}

echo ""
step "PHP-FPM socket"
if [[ -S "$PHP_FPM_SOCK" ]]; then
  ok "Socket exists: $PHP_FPM_SOCK"
else
  fail "Missing socket: $PHP_FPM_SOCK"
  warn "Fix nginx fastcgi_pass to match: ls /run/php/*.sock"
  warn "Then: sudo systemctl restart $PHP_FPM_UNIT && sudo nginx -t && sudo systemctl reload nginx"
fi

echo ""
step "Local HTTP (bypasses DNS/SSL)"
http_code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 15 http://127.0.0.1/ 2>/dev/null || echo 000)"
if [[ "$http_code" == "502" ]]; then
  fail "127.0.0.1 returned HTTP 502 — nginx cannot reach PHP-FPM (socket/service/crash)"
elif [[ "$http_code" =~ ^(200|301|302|303|307|308)$ ]]; then
  ok "127.0.0.1 HTTP $http_code"
else
  warn "127.0.0.1 HTTP $http_code — check nginx root and PHP errors"
fi

echo ""
step "Recent nginx upstream errors (if readable)"
if [[ -r "$NGINX_ERROR_LOG" ]]; then
  grep -E 'connect\(\) to unix:|upstream prematurely closed|recv\(\) failed' "$NGINX_ERROR_LOG" 2>/dev/null | tail -n 8 || ok "No matching upstream errors in last grep"
else
  warn "Cannot read $NGINX_ERROR_LOG — run: sudo tail -n 30 $NGINX_ERROR_LOG"
fi

echo ""
step "Portal CLI (works even when the site returns 502)"
if [[ -d "$PORTAL_PUBLISH_DIR" && -f "$PORTAL_PUBLISH_DIR/scripts/run-system-diagnostic.php" ]]; then
  php_bin="${PORTAL_PHP_CLI_BIN:-/usr/bin/php8.5}"
  if command -v "$php_bin" >/dev/null 2>&1; then
    "$php_bin" "$PORTAL_PUBLISH_DIR/scripts/run-system-diagnostic.php" | head -n 40
  else
    warn "PHP CLI not found at $php_bin"
  fi
else
  warn "Portal publish dir not found: $PORTAL_PUBLISH_DIR"
fi

echo ""
ok "Done — if socket/nginx mismatch: redeploy with fixed template (PHP_FPM_SOCK) or edit sites-enabled fastcgi_pass"
