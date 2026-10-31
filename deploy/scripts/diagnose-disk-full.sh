#!/usr/bin/env bash
# Disk full → PostgreSQL warnings, HTTP 500 on PHP pages, empty php-fpm "Fatal" grep.
set -uo pipefail

DEPLOY_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck disable=SC1091
source "$DEPLOY_ROOT/lib/common.sh"

PORTAL_PUBLISH_DIR="${PORTAL_PUBLISH_DIR:-/var/www/jawish-portal}"

step "Disk usage (df)"
df -hT / /var /var/lib/postgresql 2>/dev/null || df -h

echo ""
step "Largest dirs under /var (may need sudo for full tree)"
du -xh /var --max-depth=1 2>/dev/null | sort -hr | head -n 15 || warn "Run: sudo du -xh /var --max-depth=1 | sort -hr | head"

echo ""
step "Portal storage"
if [[ -d "$PORTAL_PUBLISH_DIR/storage" ]]; then
  du -sh "$PORTAL_PUBLISH_DIR/storage"/* 2>/dev/null | sort -hr | head -n 12 || true
fi

echo ""
step "PostgreSQL log hint"
if command -v journalctl >/dev/null 2>&1; then
  journalctl -u postgresql -n 5 --no-pager 2>/dev/null | grep -i 'no space' || ok "No recent 'no space' in postgresql journal (last 5 lines skipped if none)"
fi

echo ""
ok "Free space: journalctl --vacuum-size=200M; apt clean; prune old zip-jobs/material-image-worker logs"
ok "After cleanup: sudo systemctl restart postgresql php8.5-fpm"
