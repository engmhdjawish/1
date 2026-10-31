#!/usr/bin/env bash
# Disk or inode exhaustion → "No space left on device", HTTP 500, empty php-fpm Fatal grep.
set -uo pipefail

DEPLOY_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck disable=SC1091
source "$DEPLOY_ROOT/lib/common.sh"

PORTAL_PUBLISH_DIR="${PORTAL_PUBLISH_DIR:-/var/www/jawish-portal}"

step "Block space (df -h)"
df -hT / /var /var/lib/postgresql 2>/dev/null || df -h

echo ""
step "Inodes (df -hi) — 100% IUse with free GB = millions of tiny files"
df -hi / 2>/dev/null || df -i /
iuse="$(df -i / 2>/dev/null | awk 'NR==2 {gsub(/%/,"",$5); print $5}')"
if [[ -n "$iuse" && "$iuse" -ge 95 ]]; then
  fail "Inode usage ${iuse}% — PostgreSQL/PHP sessions/cache often fail before block space fills"
fi

echo ""
step "File counts in common culprits"
count_files() {
  local dir="$1"
  if [[ -d "$dir" ]]; then
    printf '  %s: ' "$dir"
    find "$dir" -xdev -type f 2>/dev/null | wc -l
  fi
}
count_files /var/lib/php/sessions
count_files "$PORTAL_PUBLISH_DIR/storage/cache"
count_files "$PORTAL_PUBLISH_DIR/storage/material-image-worker"
count_files "$PORTAL_PUBLISH_DIR/storage/zip-jobs"
count_files /var/log

echo ""
step "Largest block usage under /var"
du -xh /var --max-depth=1 2>/dev/null | sort -hr | head -n 12 || warn "sudo du -xh /var --max-depth=1 | sort -hr | head"

echo ""
step "Portal storage (size)"
if [[ -d "$PORTAL_PUBLISH_DIR/storage" ]]; then
  du -sh "$PORTAL_PUBLISH_DIR/storage"/* 2>/dev/null | sort -hr | head -n 12 || true
fi

echo ""
ok "Inode cleanup: old PHP sessions, storage/cache/*.tmp, stale zip-jobs"
ok "Example: find /var/lib/php/sessions -type f -mtime +14 -delete"
ok "After cleanup: df -hi / && systemctl restart php8.5-fpm postgresql"
