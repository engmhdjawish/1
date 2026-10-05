#!/usr/bin/env bash
# One-shot setup + manual run for material image sync/repair worker on Linux.
set -euo pipefail

PORTAL_DIR="${PORTAL_DIR:-/var/www/jawish-portal}"
PHP_BIN="${PHP_BIN:-/usr/bin/php8.5}"

echo "Portal: $PORTAL_DIR"
echo "PHP:    $PHP_BIN"

mkdir -p "$PORTAL_DIR/storage/material-image-worker"
if ! chown -R www-data:www-data "$PORTAL_DIR/storage/material-image-worker"; then
  echo "WARN: could not chown storage/material-image-worker — run: chown -R www-data:www-data $PORTAL_DIR/storage/material-image-worker" >&2
fi
chmod -R u+rwX,g+rwX "$PORTAL_DIR/storage/material-image-worker" 2>/dev/null || true

if [[ ! -f "$PORTAL_DIR/scripts/run-material-image-sync-worker.php" ]]; then
  echo "ERROR: missing $PORTAL_DIR/scripts/run-material-image-sync-worker.php — run deploy/portal/publish.sh first" >&2
  exit 1
fi

echo "Installing cron..."
tee /etc/cron.d/jawish-material-image-sync-worker >/dev/null <<EOF
* * * * * www-data cd $PORTAL_DIR && $PHP_BIN scripts/run-material-image-sync-worker.php >> storage/material-image-worker/worker.log 2>&1

EOF
chmod 644 /etc/cron.d/jawish-material-image-sync-worker

echo "Running worker once as www-data..."
sudo -u www-data "$PHP_BIN" "$PORTAL_DIR/scripts/run-material-image-sync-worker.php" || true

echo
echo "=== status.json ==="
cat "$PORTAL_DIR/storage/material-image-worker/status.json" 2>/dev/null || echo "(no status yet)"

echo
echo "=== worker.log (last 15 lines) ==="
tail -15 "$PORTAL_DIR/storage/material-image-worker/worker.log" 2>/dev/null || echo "(no worker.log yet)"
