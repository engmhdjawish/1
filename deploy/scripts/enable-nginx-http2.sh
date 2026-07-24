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
  echo "No nginx SSL server blocks found." >&2
  exit 1
fi

for conf in "${CONF_FILES[@]}"; do
  echo "Patching $conf"
  cp -a "$conf" "${conf}.bak.$(date +%Y%m%d%H%M%S)"
  sed -i 's/ ssl http2;/ ssl;/g' "$conf"
  if grep -q 'listen.*443.*ssl' "$conf" && ! grep -q 'http2 on;' "$conf"; then
    sed -i '0,/listen.*443.*ssl;/s//&\n    http2 on;/' "$conf"
  fi
done

nginx -t
systemctl reload nginx

echo ""
echo "Done. Verify with:"
echo "  curl -sI --http2 https://www.jawishco.sy | head -1"
echo "Expected: HTTP/2 200"
