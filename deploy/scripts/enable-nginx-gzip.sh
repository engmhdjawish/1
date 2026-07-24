#!/usr/bin/env bash
# Enable gzip compression for Jawish portal nginx site configs.
# Run on the VPS as root: bash deploy/scripts/enable-nginx-gzip.sh
set -euo pipefail

if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
  echo "Run as root (sudo)." >&2
  exit 1
fi

mapfile -t CONF_FILES < <(
  grep -rl 'jawish\|/var/www/jawish-portal' /etc/nginx/sites-enabled/ /etc/nginx/conf.d/ 2>/dev/null | sort -u || true
)
if [[ ${#CONF_FILES[@]} -eq 0 ]]; then
  mapfile -t CONF_FILES < <(find /etc/nginx/sites-enabled/ -maxdepth 1 -type f 2>/dev/null | sort -u || true)
fi

if [[ ${#CONF_FILES[@]} -eq 0 ]]; then
  echo "No nginx site configs found." >&2
  exit 1
fi

patched=0
for conf in "${CONF_FILES[@]}"; do
  if grep -q 'gzip on;' "$conf"; then
    echo "Skip (gzip already enabled): $conf"
    continue
  fi

  echo "Patching $conf"
  cp -a "$conf" "${conf}.bak.$(date +%Y%m%d%H%M%S)"
  python3 - "$conf" <<'PY'
import re
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text(encoding="utf-8")
block = """
    gzip on;
    gzip_vary on;
    gzip_proxied any;
    gzip_comp_level 5;
    gzip_min_length 256;
    gzip_types
        text/plain
        text/css
        text/javascript
        application/javascript
        application/json
        application/xml
        image/svg+xml;
"""
new_text = re.sub(r"(client_max_body_size\s+\d+M;)", r"\1" + block, text)
path.write_text(new_text, encoding="utf-8")
PY
  patched=$((patched + 1))
done

if [[ $patched -eq 0 ]]; then
  echo "No files changed."
  exit 0
fi

nginx -t
systemctl reload nginx

echo ""
echo "Done. Verify with:"
echo "  curl -sI -H 'Accept-Encoding: gzip' https://www.jawishco.sy/assets/store-cart.js | grep -i content-encoding"
echo "Expected: content-encoding: gzip"
