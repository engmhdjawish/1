#!/usr/bin/env bash
# Print SHA-256 certificate fingerprint for assetlinks.json / Play Console.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
KEYSTORE="${1:-$ROOT/android.keystore}"
ALIAS="${2:-portal}"

if [[ ! -f "$KEYSTORE" ]]; then
  echo "Keystore not found: $KEYSTORE" >&2
  exit 1
fi

keytool -list -v -keystore "$KEYSTORE" -alias "$ALIAS" 2>/dev/null \
  | awk -F: '/SHA256:/{gsub(/^[ \t]+/, "", $0); print $0}' \
  | sed 's/SHA256: //' \
  | tr '[:lower:]' '[:upper:]'