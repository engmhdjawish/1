#!/usr/bin/env bash
# One-shot setup + manual run for material ZIP worker on the VPS.
set -euo pipefail

PORTAL_DIR="${PORTAL_DIR:-/var/www/jawish-portal}"
PHP_BIN="${PHP_BIN:-/usr/bin/php8.5}"

echo "Portal: $PORTAL_DIR"
echo "PHP:    $PHP_BIN"

mkdir -p "$PORTAL_DIR/storage/zip-jobs"
if ! chown -R www-data:www-data "$PORTAL_DIR/storage/zip-jobs"; then
  echo "WARN: could not chown storage/zip-jobs to www-data — run: chown -R www-data:www-data $PORTAL_DIR/storage/zip-jobs" >&2
fi
chmod -R u+rwX,g+rwX "$PORTAL_DIR/storage/zip-jobs" 2>/dev/null || true

if [[ ! -f "$PORTAL_DIR/scripts/build-material-zip-job.php" ]]; then
  echo "ERROR: missing $PORTAL_DIR/scripts/build-material-zip-job.php — run deploy/portal/publish.sh first" >&2
  exit 1
fi

echo "Installing cron..."
tee /etc/cron.d/jawish-material-zip-worker >/dev/null <<EOF
* * * * * www-data cd $PORTAL_DIR && $PHP_BIN scripts/build-material-zip-job.php >> storage/zip-jobs/worker.log 2>&1

EOF
chmod 644 /etc/cron.d/jawish-material-zip-worker

echo "Running worker once as www-data..."
sudo -u www-data "$PHP_BIN" "$PORTAL_DIR/scripts/build-material-zip-job.php" || true

echo
echo "=== diagnose ==="
"$PHP_BIN" "$PORTAL_DIR/scripts/diagnose-zip-worker.php" || true

echo
echo "=== worker.log (last 20 lines) ==="
tail -20 "$PORTAL_DIR/storage/zip-jobs/worker.log" 2>/dev/null || echo "(no worker.log yet)"
