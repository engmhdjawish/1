<?php

declare(strict_types=1);

/** @var array{base_url: string, ok: bool, status: int, message: string} $apiHealth */
/** @var string $apiBaseUrl */

$apiHealth = is_array($apiHealth ?? null) ? $apiHealth : ['ok' => false, 'message' => ''];
$apiBaseUrl = trim((string) ($apiBaseUrl ?? ''));
?>
<section class="mb-6">
  <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
    <div>
      <h1 class="text-2xl font-extrabold">إدارة API الأمين</h1>
      <p class="text-sm text-text-muted mt-1 max-w-3xl">تحكم بحالة الخدمة، مسار صور الأمين، حسابات الدخول، والصلاحيات. يستخدم حساب الربط من <code dir="ltr">AMINE_API_*</code> في ملف البيئة.</p>
    </div>
    <div class="flex flex-wrap gap-2 text-xs">
      <span class="inline-flex items-center gap-1 rounded-full px-3 py-1.5 border border-border-subtle bg-white">
        الاتصال:
        <?php if (!empty($apiHealth['ok'])): ?>
          <strong class="text-status-active">متصل</strong>
        <?php else: ?>
          <strong class="text-status-rejected">غير متصل</strong>
        <?php endif; ?>
      </span>
      <?php if ($apiBaseUrl !== ''): ?>
        <span class="inline-flex items-center gap-1 rounded-full px-3 py-1.5 border border-border-subtle bg-white font-mono" dir="ltr"><?= h($apiBaseUrl) ?></span>
      <?php endif; ?>
    </div>
  </div>
</section>

<p id="pageFlash" class="hidden mb-4 rounded-xl border px-4 py-3 text-sm"></p>

<div class="flex flex-wrap gap-2 mb-4">
  <button type="button" class="amine-tab h-9 px-4 rounded-lg bg-primary text-white text-xs font-bold" data-tab="service">الخدمة والمسارات</button>
  <button type="button" class="amine-tab h-9 px-4 rounded-lg border border-border-subtle bg-white text-xs font-bold" data-tab="users">حسابات API</button>
  <button type="button" class="amine-tab h-9 px-4 rounded-lg border border-border-subtle bg-white text-xs font-bold" data-tab="roles">الأدوار والصلاحيات</button>
</div>

<section id="tab-service" class="grid gap-4 lg:grid-cols-2">
  <article class="rounded-xl border border-border-subtle bg-white p-4">
    <h2 class="font-bold mb-2">حالة الخدمة</h2>
    <p class="text-xs text-text-muted mb-4">عند الإيقاف، يرد API برمز 503 على العمليات التشغيلية — وتبقى لوحة الإدارة ومسارات الدخول متاحة.</p>
    <div class="flex flex-wrap items-center gap-3 mb-3">
      <span id="serviceStatusLabel" class="text-sm font-bold">...</span>
      <button type="button" id="enableServiceBtn" class="h-9 px-4 rounded-lg bg-primary text-white text-xs font-bold">تشغيل</button>
      <button type="button" id="disableServiceBtn" class="h-9 px-4 rounded-lg border border-red-200 bg-red-50 text-xs font-bold text-red-700">إيقاف مؤقت</button>
    </div>
  </article>

  <article class="rounded-xl border border-border-subtle bg-white p-4">
    <h2 class="font-bold mb-2">مسار صور الأمين</h2>
    <p class="text-xs text-text-muted mb-3">مجلد واحد للصور الأصلية على سيرفر الأمين. الثامبنيل يُولَّد على الموقع فقط.</p>
    <form id="imagesPathForm" class="space-y-3">
      <label class="text-xs block">
        <span class="text-text-muted">Images:Directory</span>
        <input id="imagesDirectoryInput" class="mt-1 h-9 w-full rounded-lg border border-border-subtle px-3 text-sm font-mono" dir="ltr" placeholder="C:\images">
      </label>
      <button type="submit" class="h-9 px-4 rounded-lg bg-primary text-white text-xs font-bold">حفظ المسار</button>
    </form>
  </article>
</section>

<section id="tab-users" class="hidden space-y-4">
  <article class="rounded-xl border border-border-subtle bg-white p-4">
    <h2 class="font-bold mb-3">إنشاء حساب API</h2>
    <form id="createUserForm" class="grid gap-3 md:grid-cols-2">
      <label class="text-xs block"><span class="text-text-muted">اسم المستخدم</span><input name="user_name" required class="mt-1 h-9 w-full rounded-lg border border-border-subtle px-3 text-sm"></label>
      <label class="text-xs block"><span class="text-text-muted">البريد</span><input name="email" type="email" required class="mt-1 h-9 w-full rounded-lg border border-border-subtle px-3 text-sm"></label>
      <label class="text-xs block"><span class="text-text-muted">الاسم المعروض</span><input name="display_name" required class="mt-1 h-9 w-full rounded-lg border border-border-subtle px-3 text-sm"></label>
      <label class="text-xs block"><span class="text-text-muted">كلمة المرور</span><input name="password" type="password" required class="mt-1 h-9 w-full rounded-lg border border-border-subtle px-3 text-sm"></label>
      <label class="text-xs block md:col-span-2"><span class="text-text-muted">الدور (ID)</span><input name="role_ids" value="4" class="mt-1 h-9 w-full rounded-lg border border-border-subtle px-3 text-sm font-mono" dir="ltr" placeholder="4 = Viewer"></label>
      <button type="submit" class="h-9 px-4 rounded-lg bg-primary text-white text-xs font-bold md:col-span-2 md:justify-self-start">إنشاء</button>
    </form>
  </article>

  <article class="rounded-xl border border-border-subtle bg-white overflow-hidden">
    <div class="px-4 py-3 border-b border-border-subtle bg-surface-low/60"><h2 class="font-bold">حسابات API</h2></div>
    <div class="overflow-auto">
      <table class="w-full text-sm min-w-[760px]">
        <thead class="bg-surface-low text-text-muted border-b border-border-subtle">
          <tr>
            <th class="text-right p-3">المستخدم</th>
            <th class="text-right p-3">الأدوار</th>
            <th class="text-right p-3">الحالة</th>
            <th class="text-right p-3">إجراءات</th>
          </tr>
        </thead>
        <tbody id="usersTableBody" class="divide-y divide-border-subtle">
          <tr><td colspan="4" class="p-6 text-center text-text-muted">جاري التحميل...</td></tr>
        </tbody>
      </table>
    </div>
  </article>
</section>

<section id="tab-roles" class="hidden space-y-4">
  <article class="rounded-xl border border-border-subtle bg-white p-4">
    <div class="flex flex-wrap items-end gap-3">
      <label class="text-xs block">
        <span class="text-text-muted">اختر دوراً</span>
        <select id="roleSelect" class="mt-1 h-9 min-w-[220px] rounded-lg border border-border-subtle px-2 text-sm"></select>
      </label>
      <button type="button" id="saveRolePermissionsBtn" class="h-9 px-4 rounded-lg bg-primary text-white text-xs font-bold">حفظ صلاحيات الدور</button>
    </div>
  </article>
  <article class="rounded-xl border border-border-subtle bg-white p-4">
    <div id="permissionsMatrix" class="grid gap-2 md:grid-cols-2 lg:grid-cols-3 text-sm"></div>
  </article>
</section>

<script>
(() => {
  const API = '/dashboard/amine-api-api.php';
  const pageFlash = document.getElementById('pageFlash');
  const tabs = document.querySelectorAll('.amine-tab');
  const panels = {
    service: document.getElementById('tab-service'),
    users: document.getElementById('tab-users'),
    roles: document.getElementById('tab-roles'),
  };

  let roles = [];
  let permissions = [];
  let selectedRoleId = 0;

  function showFlash(message, isError = false) {
    pageFlash.textContent = message;
    pageFlash.className = `mb-4 rounded-xl border px-4 py-3 text-sm ${isError ? 'bg-red-50 border-red-200 text-red-700' : 'bg-green-50 border-green-200 text-green-700'}`;
    pageFlash.classList.remove('hidden');
  }

  async function postAction(action, payload = {}) {
    const res = await fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ action, ...payload }),
    });
    return res.json();
  }

  function activateTab(name) {
    tabs.forEach((btn) => {
      const active = btn.dataset.tab === name;
      btn.classList.toggle('bg-primary', active);
      btn.classList.toggle('text-white', active);
      btn.classList.toggle('border-border-subtle', !active);
      btn.classList.toggle('bg-white', !active);
    });
    Object.entries(panels).forEach(([key, panel]) => panel.classList.toggle('hidden', key !== name));
    if (name === 'users') loadUsers();
    if (name === 'roles') loadRoles();
  }

  async function loadOverview() {
    const res = await fetch(`${API}?action=overview`);
    const data = await res.json();
    const enabled = !!(data.service?.enabled);
    document.getElementById('serviceStatusLabel').textContent = enabled ? 'الخدمة: تعمل' : 'الخدمة: متوقفة';
    document.getElementById('serviceStatusLabel').className = `text-sm font-bold ${enabled ? 'text-status-active' : 'text-status-rejected'}`;
    document.getElementById('imagesDirectoryInput').value = data.images?.images_directory || '';
    if (!data.service?.ok && data.service?.message) {
      showFlash(data.service.message, true);
    }
  }

  document.getElementById('enableServiceBtn')?.addEventListener('click', async () => {
    const result = await postAction('set-service', { enabled: true });
    showFlash(result.message || '', !result.ok);
    if (result.ok) loadOverview();
  });

  document.getElementById('disableServiceBtn')?.addEventListener('click', async () => {
    if (!confirm('إيقاف API الأمين مؤقتاً؟')) return;
    const result = await postAction('set-service', { enabled: false });
    showFlash(result.message || '', !result.ok);
    if (result.ok) loadOverview();
  });

  document.getElementById('imagesPathForm')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const result = await postAction('save-images-path', {
      images_directory: document.getElementById('imagesDirectoryInput')?.value || '',
    });
    showFlash(result.message || '', !result.ok);
  });

  async function loadUsers() {
    const tbody = document.getElementById('usersTableBody');
    const res = await fetch(`${API}?action=users`);
    const data = await res.json();
    if (!data.ok) {
      tbody.innerHTML = `<tr><td colspan="4" class="p-6 text-center text-red-700">${escapeHtml(data.message || 'فشل التحميل')}</td></tr>`;
      return;
    }
    const users = data.users || [];
    if (!users.length) {
      tbody.innerHTML = '<tr><td colspan="4" class="p-6 text-center text-text-muted">لا يوجد مستخدمون.</td></tr>';
      return;
    }
    tbody.innerHTML = users.map((user) => {
      const rolesText = (user.roles || []).join(', ');
      const active = user.isActive !== false;
      return `<tr>
        <td class="p-3">
          <div class="font-bold">${escapeHtml(user.displayName || user.userName || '')}</div>
          <div class="text-xs text-text-muted font-mono" dir="ltr">${escapeHtml(user.userName || '')}</div>
        </td>
        <td class="p-3 text-xs">${escapeHtml(rolesText)}</td>
        <td class="p-3"><span class="text-xs font-bold ${active ? 'text-status-active' : 'text-status-rejected'}">${active ? 'نشط' : 'معطّل'}</span></td>
        <td class="p-3">
          <div class="flex flex-wrap gap-2">
            <button type="button" class="h-8 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold" data-toggle-user="${escapeHtml(user.id)}" data-active="${active ? '0' : '1'}">${active ? 'تعطيل' : 'تفعيل'}</button>
            <button type="button" class="h-8 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold" data-reset-user="${escapeHtml(user.id)}">كلمة مرور</button>
          </div>
        </td>
      </tr>`;
    }).join('');

    tbody.querySelectorAll('[data-toggle-user]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const userId = btn.getAttribute('data-toggle-user');
        const isActive = btn.getAttribute('data-active') === '1';
        const result = await postAction('update-user', { user_id: userId, is_active: isActive });
        showFlash(result.message || '', !result.ok);
        if (result.ok) loadUsers();
      });
    });

    tbody.querySelectorAll('[data-reset-user]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const userId = btn.getAttribute('data-reset-user');
        const password = prompt('كلمة المرور الجديدة:');
        if (!password) return;
        const result = await postAction('reset-password', { user_id: userId, password });
        showFlash(result.message || '', !result.ok);
      });
    });
  }

  document.getElementById('createUserForm')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const roleIds = String(new FormData(form).get('role_ids') || '4').split(',').map((v) => parseInt(v.trim(), 10)).filter((v) => !Number.isNaN(v));
    const result = await postAction('create-user', {
      user_name: form.user_name.value,
      email: form.email.value,
      display_name: form.display_name.value,
      password: form.password.value,
      role_ids: roleIds,
    });
    showFlash(result.message || '', !result.ok);
    if (result.ok) {
      form.reset();
      form.role_ids.value = '4';
      loadUsers();
    }
  });

  async function loadRoles() {
    const res = await fetch(`${API}?action=roles`);
    const data = await res.json();
    if (!data.ok) {
      showFlash(data.message || 'تعذر تحميل الأدوار.', true);
      return;
    }
    roles = data.roles || [];
    permissions = data.permissions || [];
    const select = document.getElementById('roleSelect');
    select.innerHTML = roles.map((role) => `<option value="${role.id}">${escapeHtml(role.name)} — ${escapeHtml(role.description || '')}</option>`).join('');
    selectedRoleId = parseInt(select.value || '0', 10);
    await renderRolePermissions();
  }

  async function renderRolePermissions() {
    selectedRoleId = parseInt(document.getElementById('roleSelect')?.value || '0', 10);
    const res = await fetch(`${API}?action=role-permissions&role_id=${selectedRoleId}`);
    const data = await res.json();
    const selected = new Set((data.permission_ids || []).map(String));
    const matrix = document.getElementById('permissionsMatrix');
    matrix.innerHTML = permissions.map((perm) => {
      const checked = selected.has(String(perm.id)) ? 'checked' : '';
      return `<label class="flex items-start gap-2 rounded-lg border border-border-subtle px-3 py-2">
        <input type="checkbox" class="perm-checkbox mt-0.5" value="${perm.id}" ${checked}>
        <span><span class="font-mono text-xs" dir="ltr">${escapeHtml(perm.code)}</span><span class="block text-xs text-text-muted">${escapeHtml(perm.name || '')}</span></span>
      </label>`;
    }).join('');
  }

  document.getElementById('roleSelect')?.addEventListener('change', () => renderRolePermissions());
  document.getElementById('saveRolePermissionsBtn')?.addEventListener('click', async () => {
    const permissionIds = Array.from(document.querySelectorAll('.perm-checkbox:checked')).map((el) => parseInt(el.value, 10));
    const result = await postAction('save-role-permissions', { role_id: selectedRoleId, permission_ids: permissionIds });
    showFlash(result.message || '', !result.ok);
  });

  function escapeHtml(value) {
    return String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  tabs.forEach((btn) => btn.addEventListener('click', () => activateTab(btn.dataset.tab || 'service')));
  activateTab('service');
  loadOverview();
})();
</script>
