#!/usr/bin/env bash
# Verify visitor-log changes are deployed on this server.
# Usage: bash deploy/scripts/verify-visitor-log-deploy.sh
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PORTAL_DIR="${PORTAL_PUBLISH_DIR:-/var/www/jawish-portal}"

step() { printf '\033[1;36m==> %s\033[0m\n' "$*"; }
ok()   { printf '\033[1;32m✓ %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m! %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31m✗ %s\033[0m\n' "$*"; }

step "Git branch in repo ($REPO_ROOT)"
branch="$(git -C "$REPO_ROOT" rev-parse --abbrev-ref HEAD 2>/dev/null || echo '?')"
commit="$(git -C "$REPO_ROOT" log -1 --oneline 2>/dev/null || echo '?')"
echo "  Branch: $branch"
echo "  Commit: $commit"

if git -C "$REPO_ROOT" merge-base --is-ancestor 74560ef HEAD 2>/dev/null; then
  ok "Repo includes visitor-log hub commit"
else
  fail "Repo does NOT include visitor-log hub — checkout cursor/visitor-log-identity-3bf8 then publish"
fi

if git -C "$REPO_ROOT" merge-base --is-ancestor ea2accc HEAD 2>/dev/null; then
  ok "Repo includes visitor identity commit"
else
  warn "Repo missing identity features (names, map links) — use cursor/visitor-log-identity-3bf8"
fi

step "Published portal files ($PORTAL_DIR)"
check_file() {
  local rel="$1"
  local needle="${2:-}"
  local path="$PORTAL_DIR/$rel"
  if [[ ! -f "$path" ]]; then
    fail "Missing $path"
    return 1
  fi
  ok "Found $rel"
  if [[ -n "$needle" ]] && ! grep -q "$needle" "$path" 2>/dev/null; then
    fail "$rel does not contain expected marker: $needle"
    return 1
  fi
  if [[ -n "$needle" ]]; then
    ok "  marker OK: $needle"
  fi
}

check_file "public/css/visitor-log.css" "visitor-log__tabs" || true
check_file "views/dashboard/visitor-analytics.php" "عرض على الخريطة" || true
check_file "public/dashboard/visitor-analytics.php" "VisitorLogService" || true
check_file "public/dashboard/sessions.php" "visitor-analytics.php?tab=now" || true

if [[ -f "$PORTAL_DIR/scripts/run-migrations.php" ]] && grep -q '013-orders-visitor-session' "$PORTAL_DIR/scripts/run-migrations.php" 2>/dev/null; then
  ok "Migration 013 registered in published copy"
else
  warn "Migration 013 not in published copy — republish from identity branch"
fi

step "Quick fix if checks failed"
cat <<'EOF'
  cd /opt/jawish   # or your repo path
  git fetch origin
  git checkout cursor/visitor-log-identity-3bf8
  git pull origin cursor/visitor-log-identity-3bf8
  PORTAL_DB_SETUP=migrate bash deploy/portal/publish.sh
  sudo systemctl reload php8.5-fpm
  bash deploy/scripts/verify-visitor-log-deploy.sh
EOF
