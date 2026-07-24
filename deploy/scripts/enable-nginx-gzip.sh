#!/usr/bin/env bash
# Enable gzip compression for Jawish portal nginx site configs.
# Run on the VPS as root:
#   bash deploy/scripts/enable-nginx-gzip.sh
#   bash deploy/scripts/enable-nginx-gzip.sh /etc/nginx/sites-available/jawish-portal
set -euo pipefail

if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
  echo "Run as root (sudo)." >&2
  exit 1
fi

GZIP_BLOCK=$'    gzip on;\n    gzip_vary on;\n    gzip_proxied any;\n    gzip_comp_level 5;\n    gzip_min_length 256;\n    gzip_types\n        text/plain\n        text/css\n        text/javascript\n        application/javascript\n        application/json\n        application/xml\n        image/svg+xml;'

discover_configs() {
  local -a found=()
  local -a seeds=()
  local dir f

  if [[ $# -gt 0 ]]; then
    printf '%s\n' "$@"
    return
  fi

  for dir in /etc/nginx/sites-enabled /etc/nginx/sites-available /etc/nginx/conf.d; do
    [[ -d "$dir" ]] || continue
    while IFS= read -r -d '' f; do
      seeds+=("$f")
    done < <(find -L "$dir" -maxdepth 1 -type f ! -name '*.bak.*' -print0 2>/dev/null || true)
  done

  for f in /etc/nginx/sites-available/jawish-portal /etc/nginx/sites-enabled/jawish-portal; do
    [[ -f "$f" ]] && seeds+=("$f")
  done

  if [[ ${#seeds[@]} -eq 0 ]] && command -v nginx >/dev/null 2>&1; then
    while IFS= read -r f; do
      [[ -n "$f" && -f "$f" ]] && seeds+=("$f")
    done < <(nginx -T 2>/dev/null | sed -n 's/^# configuration file \(.*\):$/\1/p' || true)
  fi

  for f in "${seeds[@]}"; do
    [[ -f "$f" ]] || continue
    if grep -qiE 'jawish|jawishco\.sy|/var/www/jawish-portal' "$f" 2>/dev/null; then
      found+=("$f")
    fi
  done

  if [[ ${#found[@]} -eq 0 ]]; then
    for f in "${seeds[@]}"; do
      [[ -f "$f" ]] || continue
      if grep -qE 'listen[[:space:]]+443.*ssl|listen[[:space:]]+\[::\]:443.*ssl' "$f" 2>/dev/null; then
        found+=("$f")
      fi
    done
  fi

  if [[ ${#found[@]} -eq 0 && ${#seeds[@]} -gt 0 ]]; then
    found=("${seeds[@]}")
  fi

  printf '%s\n' "${found[@]}" | awk '!seen[$0]++'
}

patch_config() {
  local conf="$1"
  python3 - "$conf" "$GZIP_BLOCK" <<'PY'
import re
import sys
from pathlib import Path

path = Path(sys.argv[1])
block = sys.argv[2] + "\n"
text = path.read_text(encoding="utf-8")

if "gzip on;" in text:
    print("skip-already")
    raise SystemExit(0)

if re.search(r"client_max_body_size\s+\d+M;", text):
    new_text = re.sub(r"(client_max_body_size\s+\d+M;)", r"\1\n" + block, text)
else:
    new_text, count = re.subn(
        r"(root\s+[^;]+;)",
        r"\1\n" + block,
        text,
        count=1,
    )
    if count == 0:
        new_text, count = re.subn(
            r"(server_name\s+[^;]+;)",
            r"\1\n" + block,
            text,
            count=1,
        )
    if count == 0:
        print("skip-no-anchor", file=sys.stderr)
        raise SystemExit(2)

path.write_text(new_text, encoding="utf-8")
print("patched")
PY
}

mapfile -t CONF_FILES < <(discover_configs "$@")

if [[ ${#CONF_FILES[@]} -eq 0 ]]; then
  echo "No nginx site configs found." >&2
  echo "Try:" >&2
  echo "  ls -la /etc/nginx/sites-enabled/ /etc/nginx/sites-available/" >&2
  echo "  sudo bash deploy/scripts/enable-nginx-gzip.sh /etc/nginx/sites-available/jawish-portal" >&2
  exit 1
fi

echo "Found nginx config(s):"
printf '  - %s\n' "${CONF_FILES[@]}"

patched=0
for conf in "${CONF_FILES[@]}"; do
  [[ -f "$conf" ]] || continue
  if grep -q 'gzip on;' "$conf"; then
    echo "Skip (gzip already enabled): $conf"
    continue
  fi

  echo "Patching $conf"
  cp -a "$conf" "${conf}.bak.$(date +%Y%m%d%H%M%S)"
  result="$(patch_config "$conf" || true)"
  case "$result" in
    patched)
      patched=$((patched + 1))
      ;;
    skip-already)
      echo "Skip (gzip already enabled): $conf"
      ;;
    *)
      echo "Could not patch $conf (missing client_max_body_size/root/server_name anchor)." >&2
      ;;
  esac
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
