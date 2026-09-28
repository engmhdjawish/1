#!/usr/bin/env bash
# Keep on-disk PHP session files for the portal login window.
# Debian/Ubuntu sessionclean ignores ini_set() and deletes sess_* from php.ini
# (often 24 minutes). It does read conf.d, and keeps the longest lifetime
# when several SAPIs share one save_path.
set -euo pipefail

days="${PORTAL_SESSION_LIFETIME_DAYS:-30}"
if ! [[ "$days" =~ ^[0-9]+$ ]]; then
  days=30
fi
if (( days < 7 )); then days=7; fi
if (( days > 365 )); then days=365; fi
seconds=$((days * 86400))

if [[ ! -d /etc/php ]]; then
  echo "No /etc/php — skipped."
  exit 0
fi

wrote=0
denied=0
shopt -s nullglob
for dir in /etc/php/*/*/conf.d; do
  [[ -d "$dir" ]] || continue
  target="$dir/99-jawish-session.ini"
  if [[ ! -w "$dir" ]]; then
    denied=1
    continue
  fi
  cat > "$target" <<EOF
; Jawish portal — keep session files for ${days} days.
; sessionclean reads this value. ini_set() inside the site does not affect it.
session.gc_maxlifetime = ${seconds}
EOF
  echo "wrote $target"
  wrote=1
done

script_path="$(cd "$(dirname "$0")" && pwd)/install-php-session-lifetime.sh"
if (( wrote == 0 && denied == 1 )); then
  echo "Need root to keep PHP session files for ${days} days. Run:"
  echo "  sudo PORTAL_SESSION_LIFETIME_DAYS=${days} bash ${script_path}"
elif (( wrote == 0 )); then
  echo "No PHP conf.d directory found."
fi

exit 0
