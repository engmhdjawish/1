#!/usr/bin/env bash
# When HTTP → 301 and HTTPS → 502: certbot often split port 80 (redirect only)
# and port 443 (SSL) without copying PHP locations. Run on the VPS as root.
set -uo pipefail

DEPLOY_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck disable=SC1091
source "$DEPLOY_ROOT/lib/common.sh"

CONF="${1:-/etc/nginx/sites-enabled/jawish-portal}"

if [[ ! -f "$CONF" ]]; then
  fail "Config not found: $CONF"
fi

step "Audit: $CONF"

listen80="$(grep -cE '^\s*listen\s+(\[::\]:)?80\s*;' "$CONF" || true)"
listen443="$(grep -cE '^\s*listen\s+(\[::\]:)?443\s+ssl' "$CONF" || true)"
fastcgi="$(grep -c 'fastcgi_pass' "$CONF" || true)"
root_lines="$(grep -n '^\s*root\s' "$CONF" || true)"

echo "listen 80 blocks (lines): $listen80"
echo "listen 443 ssl blocks (lines): $listen443"
echo "fastcgi_pass count: $fastcgi"
echo ""
echo "root directives:"
echo "$root_lines"

echo ""
if [[ "${listen443:-0}" -gt 0 && "${fastcgi:-0}" -lt "${listen443:-0}" ]]; then
  fail "HTTPS server block(s) likely missing PHP — need one fastcgi_pass per listen 443 server { }"
  warn "Fix: copy location / and location ~ \\.php\$ from the HTTP portal block into the SSL block"
  warn "See deploy/JAWISH-PRODUCTION-WORKFLOW.md section «502 على HTTPS فقط»"
elif [[ "${listen443:-0}" -gt 0 && "${fastcgi:-0}" -ge 1 ]]; then
  ok "fastcgi_pass present — if still 502, run: sudo tail -n 20 /var/log/nginx/error.log"
else
  warn "No listen 443 ssl in this file — check other files in sites-enabled/"
  grep -Rl 'listen.*443' /etc/nginx/sites-enabled/ 2>/dev/null || true
fi

echo ""
step "Snippet: all listen + server_name in this file"
grep -nE 'listen |server_name ' "$CONF" || true
