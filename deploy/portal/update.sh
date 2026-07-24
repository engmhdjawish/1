#!/usr/bin/env bash
# تحديث الموقع على VPS دون فقدان .env أو قاعدة البيانات
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

cd "$REPO_ROOT"

step() { printf '\033[1;36m==> %s\033[0m\n' "$*"; }
ok()   { printf '\033[1;32m✓ %s\033[0m\n' "$*"; }
die()  { printf '\033[1;31m✗ %s\033[0m\n' "$1"; exit "${2:-1}"; }

step "جلب آخر التحديثات من Git"
git fetch origin
git checkout main
git pull origin main
ok "الفرع: $(git rev-parse --short HEAD) — $(git log -1 --format=%s)"

if [[ ! -f "$REPO_ROOT/deploy/deploy.env" ]]; then
  die "ملف deploy/deploy.env غير موجود — أنشئه من deploy.env.example أولاً"
fi

step "نشر الموقع (migrate — يحافظ على .env وقاعدة البيانات)"
export PORTAL_DB_SETUP=migrate
bash "$SCRIPT_DIR/publish.sh"

step "إعادة تحميل PHP-FPM و nginx"
if command -v systemctl >/dev/null 2>&1; then
  for svc in php8.5-fpm php8.4-fpm php8.3-fpm php-fpm; do
    if systemctl is-active --quiet "$svc" 2>/dev/null; then
      sudo systemctl reload "$svc" && ok "تم reload $svc" && break
    fi
  done
  if systemctl is-active --quiet nginx 2>/dev/null; then
    sudo nginx -t && sudo systemctl reload nginx
    ok "تم reload nginx"
  fi
fi

ok "اكتمل التحديث — https://www.jawishco.sy"
echo "  commit: $(git rev-parse --short HEAD)"
echo "  web root: $(grep -E '^PORTAL_PUBLISH_DIR=' deploy/deploy.env 2>/dev/null | cut -d= -f2- || echo /var/www/jawish-portal)/public"
