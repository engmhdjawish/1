#!/usr/bin/env bash
# Build a signed AAB for Play Console (run on a machine with JDK + Android SDK).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if [[ ! -f twa-manifest.json ]]; then
  echo "Missing twa-manifest.json in $ROOT" >&2
  exit 1
fi

if [[ -z "${ANDROID_HOME:-}" && -z "${ANDROID_SDK_ROOT:-}" ]]; then
  if [[ -f "$HOME/.bubblewrap/config.json" ]]; then
    export ANDROID_HOME="$(python3 -c "import json; print(json.load(open('$HOME/.bubblewrap/config.json')).get('androidSdkPath',''))" 2>/dev/null || true)"
  fi
fi

if [[ ! -f android.keystore ]]; then
  echo "Create android.keystore first (see README.md)." >&2
  exit 1
fi

npx --yes @bubblewrap/cli@1.23.0 build \
  --manifest="$ROOT/twa-manifest.json" \
  --signingKeyPath="$ROOT/android.keystore" \
  --signingKeyAlias="${ANDROID_KEY_ALIAS:-portal}"

echo ""
echo "Release bundle:"
echo "  $ROOT/app/build/outputs/bundle/release/app-release.aab"
echo ""
echo "Upload that file to Google Play Console."