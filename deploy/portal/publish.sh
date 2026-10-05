#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$0")/../lib/common.sh"

load_deploy_env "${1:-}"

PORTAL_PUBLISH_DIR="${PORTAL_PUBLISH_DIR:-/var/www/jawish-portal}"
step "تجهيز نشر الموقع في $PORTAL_PUBLISH_DIR"

copy_portal_tree "$PORTAL_PUBLISH_DIR"
env_preserve=""
if [[ "${PORTAL_DB_SETUP:-}" != "fresh" && -f "$PORTAL_PUBLISH_DIR/.env" ]]; then
  env_preserve="preserve"
fi
write_portal_env "$PORTAL_PUBLISH_DIR" "$env_preserve"

pushd "$PORTAL_PUBLISH_DIR" >/dev/null
composer install --no-dev --optimize-autoloader --no-interaction

# الافتراضي migrate — fresh يعيد تشغيل البذور وقد يمسح سياسات الوصول المخصصة
if [[ "${PORTAL_DB_SETUP:-migrate}" == "fresh" ]]; then
  step "إنشاء قاعدة البيانات (مخطط + بذور)"
  php scripts/setup-database.php
  step "ترحيلات إضافية"
  php scripts/run-migrations.php
elif [[ "${PORTAL_DB_SETUP:-migrate}" == "migrate" ]]; then
  step "ترحيل قاعدة البيانات"
  php scripts/run-migrations.php
else
  warn "تخطي إعداد DB — عيّن PORTAL_DB_SETUP=fresh أو migrate"
fi

if [[ "${PORTAL_DB_SETUP:-}" != "migrate" && -n "${PORTAL_ADMIN_USER:-}" && -n "${PORTAL_ADMIN_PASSWORD:-}" ]]; then
  php scripts/create-admin.php \
    "$PORTAL_ADMIN_USER" \
    "$PORTAL_ADMIN_PASSWORD" \
    "${PORTAL_ADMIN_DISPLAY_NAME:-مدير النظام}"
fi

php scripts/check-environment.php

if command -v npm >/dev/null 2>&1 && [[ -f package.json ]]; then
  step "بناء CSS (Tailwind + bundles)"
  npm run build:css --silent 2>/dev/null || npm run build:css
fi

popd >/dev/null

templates="$DEPLOY_ROOT/templates/portal"
mkdir -p "$DEPLOY_ROOT/output"
render_template "$templates/nginx-site.conf.template" \
  "$DEPLOY_ROOT/output/nginx-jawish-portal.conf"
cp "$templates/iis-web.config.template" \
  "$PORTAL_PUBLISH_DIR/public/web.config"
if [[ -f "$templates/material-zip-worker.cron" ]]; then
  sed "s|__PORTAL_DIR__|$PORTAL_PUBLISH_DIR|g; s|__PHP_BIN__|${PORTAL_PHP_CLI_BIN:-/usr/bin/php8.5}|g" \
    "$templates/material-zip-worker.cron" > "$DEPLOY_ROOT/output/material-zip-worker.cron"
fi
if [[ -f "$templates/material-image-sync-worker.cron" ]]; then
  sed "s|__PORTAL_DIR__|$PORTAL_PUBLISH_DIR|g; s|__PHP_BIN__|${PORTAL_PHP_CLI_BIN:-/usr/bin/php8.5}|g" \
    "$templates/material-image-sync-worker.cron" > "$DEPLOY_ROOT/output/material-image-sync-worker.cron"
fi

session_days="30"
if [[ -f "$PORTAL_PUBLISH_DIR/.env" ]]; then
  env_days="$(grep -E '^PORTAL_SESSION_LIFETIME_DAYS=' "$PORTAL_PUBLISH_DIR/.env" | tail -n 1 | cut -d= -f2- | tr -d " \"'" || true)"
  if [[ "$env_days" =~ ^[0-9]+$ ]]; then
    session_days="$env_days"
  fi
fi
if [[ -f "$DEPLOY_ROOT/scripts/install-php-session-lifetime.sh" ]]; then
  PORTAL_SESSION_LIFETIME_DAYS="$session_days" bash "$DEPLOY_ROOT/scripts/install-php-session-lifetime.sh"
fi

ok "تم تجهيز الموقع"
echo "  nginx: deploy/output/nginx-jawish-portal.conf"
echo "  جذر الويب: $PORTAL_PUBLISH_DIR/public"
echo "  ZIP worker cron: deploy/output/material-zip-worker.cron"
echo "  Image sync worker cron: deploy/output/material-image-sync-worker.cron"
