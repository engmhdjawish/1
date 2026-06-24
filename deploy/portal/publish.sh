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

if [[ "${PORTAL_DB_SETUP:-fresh}" == "fresh" ]]; then
  step "إنشاء قاعدة البيانات (مخطط + بذور)"
  php scripts/setup-database.php
  step "ترحيلات إضافية"
  php scripts/run-migrations.php
elif [[ "${PORTAL_DB_SETUP:-}" == "migrate" ]]; then
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
popd >/dev/null

templates="$DEPLOY_ROOT/templates/portal"
mkdir -p "$DEPLOY_ROOT/output"
render_template "$templates/nginx-site.conf.template" \
  "$DEPLOY_ROOT/output/nginx-jawish-portal.conf"
cp "$templates/iis-web.config.template" \
  "$PORTAL_PUBLISH_DIR/public/web.config"

ok "تم تجهيز الموقع"
echo "  nginx: deploy/output/nginx-jawish-portal.conf"
echo "  جذر الويب: $PORTAL_PUBLISH_DIR/public"
