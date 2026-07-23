#!/usr/bin/env bash
# Run website/API load simulation (Node runner).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCENARIO="${1:-api-browse}"
shift || true

if ! command -v node >/dev/null 2>&1; then
  echo "[FAIL] Node.js is required (18+)." >&2
  exit 1
fi

exec node "$ROOT/run.mjs" --scenario "$SCENARIO" "$@"
