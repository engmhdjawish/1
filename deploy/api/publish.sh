#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$0")/../lib/common.sh"

load_deploy_env "${1:-}"

API_PUBLISH_DIR="${API_PUBLISH_DIR:-/var/www/existingdb-api}"
step "نشر API إلى $API_PUBLISH_DIR"

require_cmd dotnet
mkdir -p "$API_PUBLISH_DIR"

dotnet restore "$REPO_ROOT/ExistingDbWebApi.sln"
dotnet publish "$REPO_ROOT/src/ExistingDb.Api/ExistingDb.Api.csproj" \
  -c Release \
  -o "$API_PUBLISH_DIR" \
  --no-restore

templates="$DEPLOY_ROOT/templates/api"
render_template "$templates/appsettings.Production.json.template" \
  "$API_PUBLISH_DIR/appsettings.Production.json"
render_template "$templates/api.env.template" \
  "$API_PUBLISH_DIR/api.env"

chmod 600 "$API_PUBLISH_DIR/api.env" 2>/dev/null || true

if [[ -f "$templates/existingdb-api.service.template" ]]; then
  render_template "$templates/existingdb-api.service.template" \
    "$DEPLOY_ROOT/output/existingdb-api.service"
  ok "قالب systemd: deploy/output/existingdb-api.service"
fi

ok "تم نشر API — شغّل: dotnet $API_PUBLISH_DIR/ExistingDb.Api.dll"
echo "   أو: sudo cp deploy/output/existingdb-api.service /etc/systemd/system/ && sudo systemctl enable --now existingdb-api"
