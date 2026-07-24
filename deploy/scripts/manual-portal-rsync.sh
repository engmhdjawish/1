#!/usr/bin/env bash
# نشر يدوي آمن للموقع — لا يحذف مجلد الصور ولا storage.
#
# Usage:
#   bash deploy/scripts/manual-portal-rsync.sh
#   bash deploy/scripts/manual-portal-rsync.sh /var/www/jawish-portal
#
# Prefer the full publish script when possible:
#   PORTAL_DB_SETUP=migrate bash deploy/portal/publish.sh
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
SOURCE="${PORTAL_SOURCE_DIR:-$REPO_ROOT/portal}"
DEST="${1:-${PORTAL_PUBLISH_DIR:-/var/www/jawish-portal}}"

if [[ ! -d "$SOURCE" ]]; then
  echo "مجلد المصدر غير موجود: $SOURCE" >&2
  exit 1
fi

mkdir -p "$DEST"

echo "==> نسخ آمن من $SOURCE إلى $DEST"
echo "    (يستثني: .env, images/, storage/, vendor/)"

rsync -av --delete \
  --exclude '.env' \
  --exclude 'images/' \
  --exclude 'storage/amine-api-token.json' \
  --exclude 'storage/material-images/' \
  --exclude 'storage/site-media/' \
  --exclude 'vendor/' \
  --exclude '.git/' \
  "$SOURCE/" "$DEST/"

mkdir -p "$DEST/storage/material-images/thumbnails" "$DEST/storage/site-media" "$DEST/storage/fonts"
if [[ -d "$DEST/images" ]]; then
  mkdir -p "$DEST/images/images/_processed" "$DEST/images/images/thumbnails" 2>/dev/null || true
fi

echo "==> composer install"
pushd "$DEST" >/dev/null
composer install --no-dev --optimize-autoloader --no-interaction
popd >/dev/null

echo "✓ اكتمل النشر اليدوي الآمن"
