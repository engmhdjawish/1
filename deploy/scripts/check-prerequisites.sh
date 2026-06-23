#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$0")/../lib/common.sh"

step "فحص متطلبات النشر"

missing=0

check() {
  local cmd="$1" label="$2"
  if command -v "$cmd" >/dev/null 2>&1; then
    ok "$label: $($cmd --version 2>/dev/null | head -1 || echo موجود)"
  else
    fail "$label: غير موجود ($cmd)"
    missing=1
  fi
}

check dotnet ".NET SDK"
check php "PHP"
check composer "Composer"

if command -v psql >/dev/null 2>&1; then
  ok "PostgreSQL client: $(psql --version | head -1)"
else
  warn "psql غير موجود — مطلوب لترحيل قاعدة الموقع"
fi

php_exts=(pdo_pgsql curl mbstring openssl gd)
for ext in "${php_exts[@]}"; do
  if php -r "exit(extension_loaded('$ext')?0:1);"; then
    ok "امتداد PHP: $ext"
  else
    fail "امتداد PHP مفقود: $ext"
    missing=1
  fi
done

if [[ -f "$REPO_ROOT/ExistingDbWebApi.sln" ]]; then
  ok "حل API: ExistingDbWebApi.sln"
else
  fail "لم يُعثر على ExistingDbWebApi.sln"
  missing=1
fi

if [[ -d "$REPO_ROOT/portal/public" ]]; then
  ok "مجلد الموقع: portal/public"
else
  fail "لم يُعثر على portal/public"
  missing=1
fi

if [[ $missing -eq 0 ]]; then
  ok "جميع المتطلبات الأساسية متوفرة"
  exit 0
fi

die "بعض المتطلبات ناقصة — راجع القائمة أعلاه"
exit 1
