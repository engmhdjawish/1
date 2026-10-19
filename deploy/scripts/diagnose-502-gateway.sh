#!/usr/bin/env bash
# Quick 502 Bad Gateway triage for Jawish Portal (nginx + PHP-FPM).
# Run on the production server over SSH.
set -uo pipefail

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
WEB_DOMAIN="${WEB_DOMAIN:-}"

step "502 triage — Portal at $PORTAL_PUBLISH_DIR"

if [[ -z "$WEB_DOMAIN" && -d /etc/nginx/sites-enabled ]]; then
  # Do NOT grep -v '_' on the whole line — that drops every server_name _; and yields empty Host.
  WEB_DOMAIN="$(grep -Rh '^\s*server_name\s' /etc/nginx/sites-enabled/ 2>/dev/null \
    | grep -vE 'server_name\s+_;' \
    | grep -vE 'server_name\s+localhost\s*;' \
    | head -n 1 \
    | sed -E 's/.*server_name\s+([^;]+);.*/\1/' \
    | awk '{print $1}' || true)"
fi

if [[ -d /etc/nginx/sites-enabled ]]; then
  shopt -s nullglob
  bak_files=(/etc/nginx/sites-enabled/*.bak* /etc/nginx/sites-enabled/*~)
  if [[ ${#bak_files[@]} -gt 0 ]]; then
    warn "Backup configs in sites-enabled load twice and break vhosts — move them out:"
    printf '  %s\n' "${bak_files[@]}"
    warn "Example: sudo mkdir -p /etc/nginx/disabled && sudo mv /etc/nginx/sites-enabled/*.bak* /etc/nginx/disabled/"
  fi
fi
if [[ -n "$WEB_DOMAIN" ]]; then
  ok "Testing vhost Host: $WEB_DOMAIN (curl to 127.0.0.1 without Host often returns 404 — that is normal)"
else
  warn "WEB_DOMAIN unknown — set in deploy.env or pass: WEB_DOMAIN=jawish.example.com $0"
fi

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
  warn "List sockets: ls -la /run/php/"
fi

echo ""
step "All fastcgi_pass in nginx (HTTP *and* HTTPS blocks must match)"
if [[ -d /etc/nginx ]]; then
  grep -Rn 'fastcgi_pass' /etc/nginx/sites-enabled/ /etc/nginx/conf.d/ 2>/dev/null || warn "No fastcgi_pass found"
  bad_socks="$(grep -Rh 'fastcgi_pass' /etc/nginx/sites-enabled/ /etc/nginx/conf.d/ 2>/dev/null | grep -v "$PHP_FPM_SOCK" | grep -v '#.*fastcgi_pass' || true)"
  if [[ -n "$bad_socks" ]]; then
    warn "Some fastcgi_pass lines do NOT use $PHP_FPM_SOCK — certbot often duplicates a 443 server block:"
    echo "$bad_socks"
  fi
fi

curl_code() {
  local url="$1"
  shift
  curl -sS -o /dev/null -w '%{http_code}' --max-time 20 "$@" "$url" 2>/dev/null || echo 000
}

report_http() {
  local label="$1"
  local code="$2"
  if [[ "$code" == "502" ]]; then
    fail "$label → HTTP 502"
  elif [[ "$code" =~ ^(200|301|302|303|307|308)$ ]]; then
    ok "$label → HTTP $code"
  else
    warn "$label → HTTP $code"
  fi
}

echo ""
step "HTTP tests"
report_http "127.0.0.1/ (default server — often 404)" "$(curl_code 'http://127.0.0.1/')"

if [[ -n "$WEB_DOMAIN" ]]; then
  report_http "127.0.0.1/ with Host $WEB_DOMAIN" "$(curl_code 'http://127.0.0.1/' -H "Host: $WEB_DOMAIN")"
  report_http "127.0.0.1/index.php with Host" "$(curl_code 'http://127.0.0.1/index.php' -H "Host: $WEB_DOMAIN")"
  report_http "127.0.0.1/store.php with Host" "$(curl_code 'http://127.0.0.1/store.php' -H "Host: $WEB_DOMAIN")"
fi

echo ""
step "HTTPS tests (visitors often use https://)"
if [[ -n "$WEB_DOMAIN" ]]; then
  report_http "https://127.0.0.1/ with Host (insecure)" "$(curl_code 'https://127.0.0.1/' -k -H "Host: $WEB_DOMAIN")"
  report_http "https://127.0.0.1/index.php with Host" "$(curl_code 'https://127.0.0.1/index.php' -k -H "Host: $WEB_DOMAIN")"
fi

echo ""
step "Portal paths on disk"
if [[ -f "$PORTAL_PUBLISH_DIR/public/index.php" ]]; then
  ok "index.php exists"
else
  fail "Missing $PORTAL_PUBLISH_DIR/public/index.php — wrong nginx root?"
fi
if [[ -f "$PORTAL_PUBLISH_DIR/vendor/autoload.php" ]]; then
  ok "vendor/autoload.php exists"
else
  warn "Missing vendor/ — run: cd $PORTAL_PUBLISH_DIR && composer install --no-dev"
fi

echo ""
step "Recent nginx upstream errors"
if [[ -r "$NGINX_ERROR_LOG" ]]; then
  if grep -q 'failed (11: Resource temporarily unavailable)' "$NGINX_ERROR_LOG" 2>/dev/null; then
    fail "PHP-FPM pool saturated (errno 11) — not a wrong socket path"
    warn "Run: sudo systemctl restart $PHP_FPM_UNIT"
    warn "Then: sudo bash deploy/scripts/tune-php-fpm-jawish.sh"
    warn "Audit: bash deploy/scripts/diagnose-fpm-saturation.sh"
  fi
  grep -E 'connect\(\) to unix:|upstream prematurely closed|recv\(\) failed|Primary script unknown' "$NGINX_ERROR_LOG" 2>/dev/null | tail -n 12 || ok "No matching lines in error log"
else
  warn "Run: sudo tail -n 40 $NGINX_ERROR_LOG"
fi

echo ""
step "Recent PHP-FPM log (crashes → 502 on .php only)"
for log in "/var/log/php8.5-fpm.log" "/var/log/php${PHP_FPM_UNIT%-fpm}.log"; do
  if [[ -r "$log" ]]; then
    tail -n 15 "$log"
    break
  fi
done
if ! journalctl -u "$PHP_FPM_UNIT" -n 12 --no-pager 2>/dev/null | tail -n 12; then
  warn "journalctl unavailable for $PHP_FPM_UNIT"
fi

echo ""
step "Portal CLI"
if [[ -d "$PORTAL_PUBLISH_DIR" && -f "$PORTAL_PUBLISH_DIR/scripts/run-system-diagnostic.php" ]]; then
  php_bin="${PORTAL_PHP_CLI_BIN:-/usr/bin/php8.5}"
  if command -v "$php_bin" >/dev/null 2>&1; then
    "$php_bin" "$PORTAL_PUBLISH_DIR/scripts/check-environment.php" 2>/dev/null | head -n 25 || true
  fi
fi

echo ""
ok "If HTTP Host tests OK but HTTPS → 502: fix fastcgi_pass inside the listen 443 { } block (certbot duplicate)."
ok "If index.php → 502 but static files OK: PHP fatal — see FPM log above."
