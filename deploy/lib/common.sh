#!/usr/bin/env bash
# Shared helpers for deploy scripts (Linux/macOS)

set -euo pipefail

DEPLOY_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPO_ROOT="$(cd "$DEPLOY_ROOT/.." && pwd)"

color() {
  local c="$1"; shift
  printf "\033[%sm%s\033[0m\n" "$c" "$*"
}

step() { color "1;36" "==> $*"; }
ok()   { color "1;32" "✓ $*"; }
warn() { color "1;33" "! $*"; }
fail() { color "1;31" "✗ $*"; }

die() {
  fail "$1"
  exit "${2:-1}"
}

require_cmd() {
  local name="$1"
  command -v "$name" >/dev/null 2>&1 || die "الأمر غير موجود: $name"
}

load_deploy_env() {
  local env_file="${1:-$DEPLOY_ROOT/deploy.env}"
  if [[ ! -f "$env_file" ]]; then
    die "ملف الإعداد غير موجود: $env_file — شغّل المعالج أولاً أو انسخ deploy.env.example"
  fi
  # shellcheck disable=SC1090
  set -a
  source "$env_file"
  set +a
}

save_deploy_env_kv() {
  local key="$1"
  local value="$2"
  local env_file="${3:-$DEPLOY_ROOT/deploy.env}"
  touch "$env_file"
  if grep -q "^${key}=" "$env_file" 2>/dev/null; then
    sed -i "s|^${key}=.*|${key}=${value}|" "$env_file"
  else
    echo "${key}=${value}" >> "$env_file"
  fi
}

prompt() {
  local label="$1"
  local default="${2:-}"
  local reply
  if [[ -n "$default" ]]; then
    read -r -p "$label [$default]: " reply
    echo "${reply:-$default}"
  else
    read -r -p "$label: " reply
    echo "$reply"
  fi
}

prompt_secret() {
  local label="$1"
  local reply
  read -r -s -p "$label: " reply
  echo
  echo "$reply"
}

generate_secret() {
  if command -v openssl >/dev/null 2>&1; then
    openssl rand -base64 48 | tr -d '/+=' | head -c 48
  else
    head -c 48 /dev/urandom | base64 | tr -d '/+=' | head -c 48
  fi
}

render_template() {
  local template="$1"
  local output="$2"
  local content
  content="$(cat "$template")"
  local vars
  vars=$(grep -oE '\{\{[A-Z0-9_]+\}\}' "$template" | sort -u)
  while IFS= read -r token; do
    [[ -z "$token" ]] && continue
    local key="${token#\{\{}"; key="${key%\}\}}"
    local val="${!key:-}"
    content="${content//$token/$val}"
  done <<< "$vars"
  mkdir -p "$(dirname "$output")"
  printf '%s\n' "$content" > "$output"
}

copy_portal_tree() {
  local src="${PORTAL_SOURCE_DIR:-$REPO_ROOT/portal}"
  local dest="$1"
  step "نسخ ملفات الموقع إلى $dest"
  mkdir -p "$dest"
  rsync -a --delete \
    --exclude '.env' \
    --exclude 'storage/amine-api-token.json' \
    --exclude 'storage/material-images/' \
    --exclude 'storage/site-media/' \
    --exclude 'vendor/' \
    --exclude '.git/' \
    "$src/" "$dest/"
  mkdir -p "$dest/storage/material-images/thumbnails" "$dest/storage/site-media" "$dest/storage/fonts"
  chmod -R u+rwX,g+rwX "$dest/storage" 2>/dev/null || true
  ok "تم نسخ الموقع"
}

write_portal_env() {
  local dest="$1"
  local env_file="$dest/.env"
  step "إنشاء $env_file"
  cat > "$env_file" <<EOF
PORTAL_DB_HOST=${PORTAL_DB_HOST}
PORTAL_DB_PORT=${PORTAL_DB_PORT}
PORTAL_DB_NAME=${PORTAL_DB_NAME}
PORTAL_DB_USER=${PORTAL_DB_USER}
PORTAL_DB_PASSWORD=${PORTAL_DB_PASSWORD}

AMINE_API_BASE_URL=${API_URL}
AMINE_API_USERNAME=${AMINE_API_USERNAME}
AMINE_API_PASSWORD=${AMINE_API_PASSWORD}

PORTAL_APP_URL=${PORTAL_APP_URL}
PORTAL_SESSION_NAME=${PORTAL_SESSION_NAME:-portal_session}
PORTAL_STORAGE_PATH=${PORTAL_STORAGE_PATH:-}
PORTAL_REPO_DOCS_PATH=${PORTAL_REPO_DOCS_PATH:-../docs}
PORTAL_DETAILS_FONT_PATH=${PORTAL_DETAILS_FONT_PATH:-}
EOF
  chmod 600 "$env_file"
  ok "تم إنشاء .env"
}
