#!/usr/bin/env bash
# معالج نشر جاويش — API + الموقع (Linux/macOS)
set -euo pipefail

DEPLOY_ROOT="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib/common.sh
source "$DEPLOY_ROOT/lib/common.sh"

ACTION="${1:-menu}"
DB_SETUP="${2:-fresh}"

banner() {
  echo
  echo "╔══════════════════════════════════════╗"
  echo "║   معالج نشر جاويش — API + الموقع    ║"
  echo "╚══════════════════════════════════════╝"
  echo
}

configure_wizard() {
  local env_file="$DEPLOY_ROOT/deploy.env"
  if [[ ! -f "$env_file" ]]; then
    cp "$DEPLOY_ROOT/deploy.env.example" "$env_file"
  fi
  # shellcheck disable=SC1090
  source "$env_file"

  step "إعداد API"
  API_PUBLISH_DIR="$(prompt 'مجلد نشر API' "${API_PUBLISH_DIR:-/var/www/existingdb-api}")"
  API_PORT="$(prompt 'منفذ API' "${API_PORT:-5000}")"
  API_BIND_HOST="$(prompt 'عنوان الربط' "${API_BIND_HOST:-0.0.0.0}")"
  API_URL="$(prompt 'رابط API للموقع' "${API_URL:-http://127.0.0.1:$API_PORT}")"
  MAIN_DB_CONNECTION="$(prompt 'سلسلة MainDb (SQL Server)' "$MAIN_DB_CONNECTION")"
  API_MANAGEMENT_DB_CONNECTION="$(prompt 'سلسلة ApiManagementDb' "$API_MANAGEMENT_DB_CONNECTION")"

  if [[ -z "${JWT_SIGNING_KEY:-}" || "$JWT_SIGNING_KEY" == REPLACE* ]]; then
    JWT_SIGNING_KEY="$(generate_secret)"
    ok "تم توليد مفتاح JWT"
  fi

  read -r -p "إنشاء admin للـ API عند أول تشغيل؟ (y/N): " seed
  if [[ "$seed" =~ ^[yY] ]]; then
    SEED_ADMIN_ENABLED=true
    SEED_ADMIN_PASSWORD="$(prompt_secret 'كلمة مرور admin API')"
  else
    SEED_ADMIN_ENABLED=false
  fi

  step "إعداد الموقع"
  PORTAL_PUBLISH_DIR="$(prompt 'مجلد نشر الموقع' "${PORTAL_PUBLISH_DIR:-/var/www/jawish-portal}")"
  PORTAL_APP_URL="$(prompt 'رابط الموقع' "${PORTAL_APP_URL:-http://127.0.0.1}")"
  PORTAL_DB_HOST="$(prompt 'PostgreSQL host' "${PORTAL_DB_HOST:-127.0.0.1}")"
  PORTAL_DB_PORT="$(prompt 'PostgreSQL port' "${PORTAL_DB_PORT:-5432}")"
  PORTAL_DB_NAME="$(prompt 'اسم القاعدة' "${PORTAL_DB_NAME:-portal_db}")"
  PORTAL_DB_USER="$(prompt 'مستخدم PostgreSQL' "${PORTAL_DB_USER:-portal}")"
  PORTAL_DB_PASSWORD="$(prompt_secret 'كلمة مرور PostgreSQL')"
  AMINE_API_USERNAME="$(prompt 'مستخدم خدمة API' "${AMINE_API_USERNAME:-portal-service}")"
  AMINE_API_PASSWORD="$(prompt_secret 'كلمة مرور portal-service')"
  WEB_DOMAIN="$(prompt 'نطاق الموقع (nginx)' "${WEB_DOMAIN:-localhost}")"

  read -r -p "إنشاء مستخدم لوحة التحكم؟ (Y/n): " mkadmin
  if [[ ! "$mkadmin" =~ ^[nN] ]]; then
    PORTAL_ADMIN_USER="$(prompt 'مستخدم اللوحة' "${PORTAL_ADMIN_USER:-admin}")"
    PORTAL_ADMIN_PASSWORD="$(prompt_secret 'كلمة مرور اللوحة')"
    PORTAL_ADMIN_DISPLAY_NAME="$(prompt 'الاسم العربي' "${PORTAL_ADMIN_DISPLAY_NAME:-مدير النظام}")"
  fi

  cat > "$env_file" <<EOF
DEPLOY_ENV=production
API_PUBLISH_DIR=$API_PUBLISH_DIR
API_URL=$API_URL
API_PORT=$API_PORT
API_BIND_HOST=$API_BIND_HOST
MAIN_DB_CONNECTION=$MAIN_DB_CONNECTION
API_MANAGEMENT_DB_CONNECTION=$API_MANAGEMENT_DB_CONNECTION
JWT_SIGNING_KEY=$JWT_SIGNING_KEY
SEED_ADMIN_ENABLED=$SEED_ADMIN_ENABLED
SEED_ADMIN_USERNAME=${SEED_ADMIN_USERNAME:-admin}
SEED_ADMIN_EMAIL=${SEED_ADMIN_EMAIL:-admin@example.local}
SEED_ADMIN_PASSWORD=${SEED_ADMIN_PASSWORD:-}
IMAGES_DIRECTORY=${IMAGES_DIRECTORY:-/var/images}
PORTAL_SOURCE_DIR=${PORTAL_SOURCE_DIR:-}
PORTAL_PUBLISH_DIR=$PORTAL_PUBLISH_DIR
PORTAL_APP_URL=$PORTAL_APP_URL
PORTAL_DB_HOST=$PORTAL_DB_HOST
PORTAL_DB_PORT=$PORTAL_DB_PORT
PORTAL_DB_NAME=$PORTAL_DB_NAME
PORTAL_DB_USER=$PORTAL_DB_USER
PORTAL_DB_PASSWORD=$PORTAL_DB_PASSWORD
AMINE_API_USERNAME=$AMINE_API_USERNAME
AMINE_API_PASSWORD=$AMINE_API_PASSWORD
PORTAL_SESSION_NAME=${PORTAL_SESSION_NAME:-portal_session}
PORTAL_STORAGE_PATH=${PORTAL_STORAGE_PATH:-}
PORTAL_ADMIN_USER=${PORTAL_ADMIN_USER:-}
PORTAL_ADMIN_PASSWORD=${PORTAL_ADMIN_PASSWORD:-}
PORTAL_ADMIN_DISPLAY_NAME=${PORTAL_ADMIN_DISPLAY_NAME:-مدير النظام}
WEB_SERVER=${WEB_SERVER:-nginx}
WEB_DOMAIN=$WEB_DOMAIN
WEB_SSL=${WEB_SSL:-false}
EOF
  chmod 600 "$env_file"
  ok "تم حفظ deploy/deploy.env"
}

run_check() {
  bash "$DEPLOY_ROOT/scripts/check-prerequisites.sh"
}

run_api() {
  [[ -f "$DEPLOY_ROOT/deploy.env" ]] || configure_wizard
  run_check
  bash "$DEPLOY_ROOT/api/publish.sh"
}

run_portal() {
  [[ -f "$DEPLOY_ROOT/deploy.env" ]] || configure_wizard
  run_check
  PORTAL_DB_SETUP="$DB_SETUP" bash "$DEPLOY_ROOT/portal/publish.sh"
}

run_migrate() {
  load_deploy_env "$DEPLOY_ROOT/deploy.env" 2>/dev/null || true
  local dir="${PORTAL_PUBLISH_DIR:-$REPO_ROOT/portal}"
  pushd "$dir" >/dev/null
  php scripts/run-migrations.php
  popd >/dev/null
}

run_full() {
  configure_wizard
  run_check
  bash "$DEPLOY_ROOT/api/publish.sh"
  PORTAL_DB_SETUP="$DB_SETUP" bash "$DEPLOY_ROOT/portal/publish.sh"
  echo
  ok "اكتمل النشر — راجع deploy/README.md للخطوات التالية"
}

menu() {
  while true; do
    banner
    echo "  1) فحص المتطلبات"
    echo "  2) إعداد كامل (API + الموقع)"
    echo "  3) نشر API فقط"
    echo "  4) نشر الموقع فقط"
    echo "  5) ترحيل قاعدة بيانات الموقع"
    echo "  6) تعديل الإعدادات"
    echo "  0) خروج"
    echo
    read -r -p "اختر رقم: " choice
    case "$choice" in
      1) run_check; read -r -p "Enter للمتابعة..." _ ;;
      2) run_full; read -r -p "Enter للمتابعة..." _ ;;
      3) run_api; read -r -p "Enter للمتابعة..." _ ;;
      4)
        read -r -p "قاعدة البيانات fresh/migrate/skip [fresh]: " db
        DB_SETUP="${db:-fresh}"
        run_portal
        read -r -p "Enter للمتابعة..." _ ;;
      5) run_migrate; read -r -p "Enter للمتابعة..." _ ;;
      6) configure_wizard; read -r -p "Enter للمتابعة..." _ ;;
      0) exit 0 ;;
      *) warn "خيار غير صالح" ;;
    esac
  done
}

case "$ACTION" in
  check) run_check ;;
  api) run_api ;;
  portal) run_portal ;;
  migrate) run_migrate ;;
  full) run_full ;;
  menu|*) menu ;;
esac
