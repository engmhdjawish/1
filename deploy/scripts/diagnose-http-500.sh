#!/usr/bin/env bash
# HTTP 500 — PHP application error (not nginx/FPM saturation).
# Run on the VPS: sudo bash deploy/scripts/diagnose-http-500.sh
set -uo pipefail

DEPLOY_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck disable=SC1091
source "$DEPLOY_ROOT/lib/common.sh"

PORTAL_PUBLISH_DIR="${PORTAL_PUBLISH_DIR:-/var/www/jawish-portal}"
PHP_BIN="${PORTAL_PHP_CLI_BIN:-/usr/bin/php8.5}"
HOST="${WEB_DOMAIN:-jawishco.sy}"
FPM_LOG="${PHP_FPM_LOG:-/var/log/php8.5-fpm.log}"
NGINX_ERROR_LOG="${NGINX_ERROR_LOG:-/var/log/nginx/error.log}"

step "HTTP 500 triage — $PORTAL_PUBLISH_DIR"

echo ""
step "Quick HTTP codes (same Host as visitors)"
for path in /index.php /store.php /manifest.php /api/app-version.php; do
  code="$(curl -sSk -o /dev/null -w '%{http_code}' -H "Host: $HOST" "https://127.0.0.1${path}" 2>/dev/null || echo 000)"
  echo "  $path → $code"
done

echo ""
step "PHP CLI bootstrap"
if [[ -x "$PHP_BIN" || command -v "$PHP_BIN" >/dev/null 2>&1 ]]; then
  if (cd "$PORTAL_PUBLISH_DIR" && "$PHP_BIN" scripts/check-environment.php 2>&1 | head -n 35); then
    ok "check-environment ran"
  else
    fail "check-environment failed — DB, extensions, or vendor"
  fi
  if (cd "$PORTAL_PUBLISH_DIR" && "$PHP_BIN" -r "require 'bootstrap.php'; echo 'bootstrap ok'.PHP_EOL;" 2>&1); then
    ok "bootstrap loads"
  else
    fail "bootstrap.php fatal — see message above"
  fi
else
  warn "PHP CLI not found: $PHP_BIN"
fi

echo ""
step "Recent PHP-FPM fatals (most 500s appear here)"
if [[ -r "$FPM_LOG" ]]; then
  grep -E 'PHP Fatal|PHP Parse|Uncaught|Allowed memory|Maximum execution' "$FPM_LOG" 2>/dev/null | tail -n 15 || warn "No fatals in $FPM_LOG tail grep"
  tail -n 8 "$FPM_LOG" 2>/dev/null || true
else
  warn "Try: sudo journalctl -u php8.5-fpm -n 40 --no-pager"
  journalctl -u php8.5-fpm -n 20 --no-pager 2>/dev/null || true
fi

echo ""
step "nginx error log (upstream sent invalid header / FastCGI)"
if [[ -r "$NGINX_ERROR_LOG" ]]; then
  grep -E 'FastCGI sent in stderr|upstream sent too big header|Primary script unknown' "$NGINX_ERROR_LOG" 2>/dev/null | tail -n 10 || ok "No FastCGI stderr lines in recent grep"
fi

echo ""
step "Deploy sanity"
[[ -f "$PORTAL_PUBLISH_DIR/vendor/autoload.php" ]] && ok "vendor/" || warn "Run: cd $PORTAL_PUBLISH_DIR && composer install --no-dev"
[[ -f "$PORTAL_PUBLISH_DIR/public/assets/pwa-update.js" ]] && ok "PWA assets present" || warn "Missing client assets — run publish.sh / npm build"

echo ""
ok "If fatals mention missing column/table: PORTAL_DB_SETUP=migrate bash deploy/portal/publish.sh"
ok "Paste the last PHP Fatal line here for a targeted fix."
