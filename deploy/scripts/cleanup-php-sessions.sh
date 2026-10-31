#!/usr/bin/env bash
# Remove stale PHP session files (safe if session lifetime ≤ 30 days in portal).
# Run as root on the VPS when df -hi shows IUse% near 100%.
set -euo pipefail

SESSION_DIR="${PHP_SESSION_DIR:-/var/lib/php/sessions}"
DAYS="${PHP_SESSION_MAX_AGE_DAYS:-14}"

if [[ ! -d "$SESSION_DIR" ]]; then
  echo "No $SESSION_DIR"
  exit 0
fi

before="$(find "$SESSION_DIR" -type f 2>/dev/null | wc -l)"
echo "Session files before: $before"

find "$SESSION_DIR" -type f -mtime "+${DAYS}" -delete

after="$(find "$SESSION_DIR" -type f 2>/dev/null | wc -l)"
echo "Session files after:  $after (removed $((before - after)))"
df -hi /
