#!/usr/bin/env bash
# Test visitor-log PR branches on VPS before merging to main.
#
# Usage (from repo root, e.g. /opt/jawish or /var/www/jawish-repo):
#   bash deploy/scripts/test-visitor-log-branches.sh
#   bash deploy/scripts/test-visitor-log-branches.sh --publish   # also publish + reload php-fpm
#
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$REPO_ROOT"

PUBLISH=false
if [[ "${1:-}" == "--publish" ]]; then
  PUBLISH=true
fi

step() { printf '\033[1;36m==> %s\033[0m\n' "$*"; }
ok()   { printf '\033[1;32m✓ %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m! %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31m✗ %s\033[0m\n' "$*"; exit 1; }

ORIG_BRANCH="$(git rev-parse --abbrev-ref HEAD)"
RESTORE=true
cleanup() {
  if [[ "$RESTORE" == true && "$(git rev-parse --abbrev-ref HEAD)" != "$ORIG_BRANCH" ]]; then
    step "Restoring branch $ORIG_BRANCH"
    git checkout "$ORIG_BRANCH" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

step "Fetching latest branches"
git fetch origin

run_branch_tests() {
  local branch="$1"
  local identity_flag="$2"
  local label="$3"

  step "Testing $label ($branch)"
  git checkout "$branch"
  git pull origin "$branch" 2>/dev/null || true

  if [[ "$PUBLISH" == true ]]; then
    step "Publishing $branch to portal"
    PORTAL_DB_SETUP=migrate bash deploy/portal/publish.sh
    if systemctl is-active --quiet php8.5-fpm 2>/dev/null; then
      sudo systemctl reload php8.5-fpm
      ok "php8.5-fpm reloaded"
    elif systemctl is-active --quiet php8.3-fpm 2>/dev/null; then
      sudo systemctl reload php8.3-fpm
      ok "php8.3-fpm reloaded"
    else
      warn "php-fpm service not found — reload manually if needed"
    fi
  else
    step "Running DB migrations only"
    php portal/scripts/run-migrations.php
  fi

  php portal/scripts/test-visitor-log.php $identity_flag
  ok "$label tests passed"
  echo
}

if ! command -v php >/dev/null 2>&1; then
  fail "php CLI not found — install php-cli on this server"
fi

run_branch_tests "cursor/visitor-log-hub-3bf8" "" "Visitor log hub (PR #172)"
run_branch_tests "cursor/visitor-log-identity-3bf8" "--identity" "Visitor identity (PR #173)"

step "Manual checks in browser (staff login required)"
cat <<'EOF'
  1. /dashboard/visitor-analytics.php?tab=now
     - Online guests appear while browsing the public store
     - Customer names show for logged-in customers
  2. /dashboard/visitor-analytics.php?tab=log
     - Sessions list shows names (not just «عميل/زائر»)
     - Click a session → timeline + map link if GPS/IP coords exist
     - Filter: ?customer_id=<uuid> from a customer profile
  3. Place a guest test order on the store
     - Re-open log → guest should show name from order on same device
  4. /dashboard/visitor-analytics.php?tab=insights
     - Map markers → popup → «عرض على Google Maps»
EOF

ok "Branch smoke tests complete — review browser checklist above before merging"
