#!/usr/bin/env bash
# Enable HTTP/2 on an existing Jawish nginx + certbot setup (nginx 1.25+).
# Run on the VPS as root: bash deploy/scripts/enable-nginx-http2.sh
set -euo pipefail

if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
  echo "Run as root (sudo)." >&2
  exit 1
fi

mapfile -t CONF_FILES < <(grep -rl 'listen.*443.*ssl' /etc/nginx/sites-enabled/ /etc/nginx/conf.d/ 2>/dev/null | sort -u || true)
if [[ ${#CONF_FILES[@]} -eq 0 ]]; then
  echo "No nginx SSL server blocks found under sites-enabled or conf.d." >&2
  exit 1
fi

for conf in "${CONF_FILES[@]}"; do
  echo "Patching $conf"
  cp -a "$conf" "${conf}.bak.$(date +%Y%m%d%H%M%S)"
  sed -i -E 's/listen ([[:space:]]*\[::\]:)?443 ssl http2;/listen \1443 ssl;/g' "$conf"
  # Insert http2 on; after first listen 443 ssl line if missing
  if ! grep -q 'http2 on;' "$conf"; then
    sed -i -E '/listen ([[:space:]]*\[::\]:)?443 ssl;/a\    http2 on;' "$conf"
  fi
done

nginx -t
systemctl reload nginx

echo ""
echo "Verify:"
curl -sI --http2 https://www.jawishco.sy 2>/dev/null | head -1 || curl -sI https://www.jawishco.sy | head -1
