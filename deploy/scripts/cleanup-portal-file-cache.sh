#!/usr/bin/env bash
# Prune portal storage/cache (ResponseCache JSON + PWA tmp) and optional PHP sessions.
# Run weekly on the VPS as root when inode usage climbs.
set -euo pipefail

PORTAL_DIR="${PORTAL_DIR:-/var/www/jawish-portal}"
PHP_BIN="${PHP_BIN:-/usr/bin/php8.5}"
SESSION_DIR="${PHP_SESSION_DIR:-/var/lib/php/sessions}"
SESSION_DAYS="${PHP_SESSION_MAX_AGE_DAYS:-14}"

echo "==> Inodes before"
df -hi /

if [[ -x "$PHP_BIN" && -f "$PORTAL_DIR/scripts/prune-response-cache.php" ]]; then
  "$PHP_BIN" "$PORTAL_DIR/scripts/prune-response-cache.php" || true
else
  echo "==> Fallback: delete cache JSON not modified in 2 days (keeps pwa-build.json)"
  find "$PORTAL_DIR/storage/cache" -type f -name '*.json' ! -name 'pwa-build.json' -mtime +2 -delete 2>/dev/null || true
fi

find "$PORTAL_DIR/storage/cache" -type f -name '*.tmp' -mtime +1 -delete 2>/dev/null || true

if [[ -d "$SESSION_DIR" ]]; then
  before="$(find "$SESSION_DIR" -type f 2>/dev/null | wc -l)"
  find "$SESSION_DIR" -type f -mtime "+${SESSION_DAYS}" -delete 2>/dev/null || true
  after="$(find "$SESSION_DIR" -type f 2>/dev/null | wc -l)"
  echo "==> PHP sessions: $before -> $after"
fi

echo "==> Inodes after"
df -hi /
