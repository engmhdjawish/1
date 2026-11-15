#!/usr/bin/env bash
# Re-apply twa-manifest.json to the Gradle project (icons, shortcuts, strings).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
printf '\n' | npx --yes @bubblewrap/cli@1.23.0 update --skipVersionUpgrade
echo "Android project regenerated from twa-manifest.json"