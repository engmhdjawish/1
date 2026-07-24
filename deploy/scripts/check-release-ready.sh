#!/usr/bin/env bash
# فحص جاهزية النشر — شغّله قبل تحديث السيرفر.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$REPO_ROOT"

step() { printf '\033[1;36m==> %s\033[0m\n' "$*"; }
ok()   { printf '\033[1;32m✓ %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m! %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31m✗ %s\033[0m\n' "$*"; exit 1; }

step "جلب آخر التحديثات"
git fetch origin

branch="$(git rev-parse --abbrev-ref HEAD)"
if [[ "$branch" != "main" ]]; then
  warn "أنت على الفرع «$branch» وليس main — للإنتاج انشر من main فقط"
fi

local_main="$(git rev-parse main)"
remote_main="$(git rev-parse origin/main)"
if [[ "$local_main" != "$remote_main" ]]; then
  warn "main المحلي ≠ origin/main — نفّذ: git checkout main && git pull origin main"
fi

ok "آخر commit على origin/main:"
git log -1 --oneline origin/main

unmerged="$(git branch -r --no-merged origin/main | sed 's/^[[:space:]]*//' | grep -E '^origin/cursor/' || true)"
if [[ -n "$unmerged" ]]; then
  warn "فروع cursor/* لم تُدمج في main بعد (قد تحتوي ميزات ناقصة):"
  echo "$unmerged" | sed 's/^/  /'
  echo
  warn "راجع هذه الفروع وادمج ما تحتاجه في main قبل النشر."
else
  ok "لا توجد فروع cursor/* معلّقة بدون دمج"
fi

if [[ -f portal/scripts/run-migrations.php ]]; then
  pending="$(php portal/scripts/run-migrations.php --list 2>/dev/null | grep -c 'pending' || true)"
  if [[ "${pending:-0}" -gt 0 ]]; then
    warn "توجد ترحيلات قاعدة بيانات لم تُطبّق بعد على هذا السيرفر/البيئة"
  fi
fi

echo
ok "أوامر النشر الموصى بها على VPS:"
echo "  cd /opt/jawish"
echo "  git checkout main && git pull origin main"
echo "  bash deploy/scripts/check-release-ready.sh"
echo "  ls /var/www/jawish-portal/images/images | wc -l   # تأكد > 0"
echo "  PORTAL_DB_SETUP=migrate bash deploy/portal/publish.sh"
echo "  sudo systemctl reload php8.5-fpm"
echo "  sudo bash deploy/scripts/enable-nginx-gzip.sh   # مرة واحدة إن لم يكن gzip مفعّلاً"
