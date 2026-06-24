<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $roles */
/** @var list<array<string, mixed>> $permissions */
/** @var array<string, list<array<string, mixed>>> $permissionsByCategory */
/** @var list<array<string, mixed>> $users */
/** @var array{total: int, active: int, inactive: int, admins: int} $stats */
/** @var array{q: string, role: string, active: string} $filters */
/** @var array<string, mixed>|null $editUser */
/** @var array<string, mixed>|null $editRole */
/** @var string|null $flash */
/** @var string $flashType */
/** @var list<array{code: string, role_code: string, name_ar: string, description_ar: string, permissions: list<string>}> $taskRoles */
/** @var array<string, string> $roleIdsByCode */
/** @var array<string, string> $permissionLabelsByCode */

$editing = $editUser !== null;
$editingRole = $editRole !== null;
$selectedRoleIds = array_map('strval', $editUser['role_ids'] ?? []);
$selectedPermissionIds = array_map('strval', $editRole['permission_ids'] ?? []);
?>
<section class="flex flex-col md:flex-row justify-between md:items-center gap-4 mb-6">
  <div>
    <h1 class="text-2xl font-extrabold text-slate-900">المستخدمون والأدوار</h1>
    <p class="text-sm text-text-muted mt-1">إدارة موظفي البوابة، تعيين الأدوار، وتفعيل الحسابات.</p>
  </div>
  <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
    <article class="bg-white border border-border-subtle rounded-xl px-4 py-3 min-w-24 text-center">
      <p class="text-2xl font-extrabold text-primary"><?= (int) ($stats['total'] ?? 0) ?></p>
      <p class="text-xs text-text-muted">إجمالي المستخدمين</p>
    </article>
    <article class="bg-white border border-border-subtle rounded-xl px-4 py-3 min-w-24 text-center">
      <p class="text-2xl font-extrabold text-green-700"><?= (int) ($stats['active'] ?? 0) ?></p>
      <p class="text-xs text-text-muted">نشط</p>
    </article>
    <article class="bg-white border border-border-subtle rounded-xl px-4 py-3 min-w-24 text-center">
      <p class="text-2xl font-extrabold text-red-600"><?= (int) ($stats['inactive'] ?? 0) ?></p>
      <p class="text-xs text-text-muted">معطل</p>
    </article>
    <article class="bg-white border border-border-subtle rounded-xl px-4 py-3 min-w-24 text-center">
      <p class="text-2xl font-extrabold text-slate-800"><?= (int) ($stats['admins'] ?? 0) ?></p>
      <p class="text-xs text-text-muted">مديرو نظام</p>
    </article>
  </div>
</section>

<?php require __DIR__ . '/partials/flash.php'; ?>

<section class="grid grid-cols-1 xl:grid-cols-3 gap-5 mb-5">
  <article class="xl:col-span-1 bg-white border border-border-subtle rounded-2xl p-5">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-base font-extrabold text-slate-900"><?= $editing ? 'تعديل مستخدم' : 'إضافة مستخدم جديد' ?></h2>
      <?php if ($editing): ?>
        <a href="/dashboard/users.php" class="text-xs text-primary hover:underline">إلغاء التعديل</a>
      <?php endif; ?>
    </div>
    <form method="post" data-dashboard-ajax data-dashboard-reload class="space-y-4">
      <input type="hidden" name="action" value="save_user">
      <input type="hidden" name="id" value="<?= h((string) ($editUser['id'] ?? '')) ?>">

      <label class="block text-sm">
        <span class="text-text-muted block mb-1">اسم المستخدم</span>
        <input name="user_name" required value="<?= h((string) ($editUser['user_name'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary" placeholder="portal-admin">
      </label>

      <label class="block text-sm">
        <span class="text-text-muted block mb-1">الاسم المعروض</span>
        <input name="display_name_ar" required value="<?= h((string) ($editUser['display_name_ar'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary" placeholder="مدير النظام">
      </label>

      <label class="block text-sm">
        <span class="text-text-muted block mb-1">البريد الإلكتروني (اختياري)</span>
        <input type="email" name="email" value="<?= h((string) ($editUser['email'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary" placeholder="admin@company.com">
      </label>

      <label class="block text-sm">
        <span class="text-text-muted block mb-1"><?= $editing ? 'كلمة مرور جديدة (اتركها فارغة للإبقاء على الحالية)' : 'كلمة المرور' ?></span>
        <input type="password" name="plain_password" <?= $editing ? '' : 'required' ?> class="h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary" placeholder="********">
      </label>

      <fieldset>
        <legend class="text-sm text-text-muted mb-2">قوالب المهام (تعيين سريع)</legend>
        <p class="text-xs text-text-muted mb-3">اختر مهمة جاهزة لتعيين الدور المناسب. يمكنك بعدها إضافة أدوار أخرى يدوياً.</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 max-h-56 overflow-auto border border-border-subtle rounded-xl p-3 bg-surface-low" id="taskRoleTemplates">
          <?php foreach ($taskRoles as $task): ?>
            <?php
              $taskCode = (string) ($task['role_code'] ?? '');
              $roleId = (string) ($roleIdsByCode[$taskCode] ?? '');
            ?>
            <button
              type="button"
              class="task-role-template text-right rounded-xl border border-border-subtle bg-white px-3 py-2.5 hover:border-primary/40 hover:bg-primary/5 transition disabled:opacity-50 disabled:cursor-not-allowed"
              data-role-id="<?= h($roleId) ?>"
              data-role-code="<?= h($taskCode) ?>"
              <?= $roleId === '' ? 'disabled title="تعذر تحميل الدور — أعد تحميل الصفحة"' : '' ?>
            >
              <span class="block text-sm font-bold text-slate-900"><?= h((string) ($task['name_ar'] ?? '')) ?></span>
              <span class="block text-[11px] text-text-muted mt-0.5 leading-relaxed"><?= h((string) ($task['description_ar'] ?? '')) ?></span>
            </button>
          <?php endforeach; ?>
        </div>
        <p id="taskRoleTemplateHint" class="text-[11px] text-primary mt-2 hidden">تم تعيين الدور في قائمة «الأدوار» أدناه — يمكنك إضافة أدوار أخرى قبل الحفظ.</p>
      </fieldset>

      <fieldset>
        <legend class="text-sm text-text-muted mb-2">الأدوار</legend>
        <div class="space-y-2 max-h-44 overflow-auto border border-border-subtle rounded-xl p-3 bg-surface-low" id="userRoleCheckboxes">
          <?php foreach ($roles as $role): ?>
            <?php $roleId = (string) ($role['id'] ?? ''); ?>
            <label class="flex items-center justify-between gap-3 text-sm">
              <span class="flex items-center gap-2">
                <input type="checkbox" name="role_ids[]" value="<?= h($roleId) ?>" <?= in_array($roleId, $selectedRoleIds, true) ? 'checked' : '' ?> class="rounded border-border-subtle text-primary focus:ring-primary">
                <span class="font-semibold"><?= h((string) ($role['name_ar'] ?? '')) ?></span>
              </span>
              <span class="text-xs text-text-muted"><?= (int) ($role['permissions_count'] ?? 0) ?> صلاحية</span>
            </label>
          <?php endforeach; ?>
        </div>
      </fieldset>

      <label class="inline-flex items-center gap-2 text-sm">
        <input type="checkbox" name="is_active" value="1" <?= $editing ? ((int) ($editUser['is_active'] ?? 0) === 1 ? 'checked' : '') : 'checked' ?> class="rounded border-border-subtle text-primary focus:ring-primary">
        <span>الحساب نشط</span>
      </label>
      <?php if ($editing): ?>
        <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
          عند تغيير كلمة المرور تأكد من تفعيل «الحساب نشط» واستخدم نفس <strong>اسم المستخدم</strong> عند الدخول.
        </p>
      <?php endif; ?>

      <button class="w-full h-11 rounded-xl bg-primary text-white font-bold hover:brightness-110 transition">
        <?= $editing ? 'حفظ التعديلات' : 'إنشاء المستخدم' ?>
      </button>
    </form>
  </article>

  <article class="xl:col-span-2 bg-white border border-border-subtle rounded-2xl overflow-hidden">
    <div class="p-4 border-b border-border-subtle bg-surface-low">
      <form method="get" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
        <label class="text-sm md:col-span-2">
          <span class="text-text-muted block mb-1">بحث</span>
          <input name="q" value="<?= h($filters['q'] ?? '') ?>" class="h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary" placeholder="اسم المستخدم، الاسم المعروض، البريد...">
        </label>
        <label class="text-sm">
          <span class="text-text-muted block mb-1">الدور</span>
          <select name="role" class="h-11 w-full rounded-xl border border-border-subtle px-3 focus:border-primary focus:ring-primary">
            <option value="">كل الأدوار</option>
            <?php foreach ($roles as $role): ?>
              <?php $roleId = (string) ($role['id'] ?? ''); ?>
              <option value="<?= h($roleId) ?>" <?= ($filters['role'] ?? '') === $roleId ? 'selected' : '' ?>>
                <?= h((string) ($role['name_ar'] ?? '')) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="text-sm">
          <span class="text-text-muted block mb-1">الحالة</span>
          <select name="active" class="h-11 w-full rounded-xl border border-border-subtle px-3 focus:border-primary focus:ring-primary">
            <option value="">الكل</option>
            <option value="1" <?= ($filters['active'] ?? '') === '1' ? 'selected' : '' ?>>نشط</option>
            <option value="0" <?= ($filters['active'] ?? '') === '0' ? 'selected' : '' ?>>معطل</option>
          </select>
        </label>
        <div class="md:col-span-4 flex justify-end">
          <button class="h-11 rounded-xl bg-primary text-white font-bold px-6 hover:brightness-110 transition">تطبيق</button>
        </div>
      </form>
    </div>

    <div class="overflow-auto">
      <table class="w-full min-w-[980px] text-sm">
        <thead class="bg-white border-b border-border-subtle text-text-muted">
          <tr>
            <th class="px-5 py-4 text-right font-bold">المستخدم</th>
            <th class="px-5 py-4 text-right font-bold">البريد</th>
            <th class="px-5 py-4 text-right font-bold">الأدوار</th>
            <th class="px-5 py-4 text-right font-bold">الحالة</th>
            <th class="px-5 py-4 text-right font-bold">آخر دخول</th>
            <th class="px-5 py-4 text-right font-bold text-left">إجراءات</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border-subtle">
          <?php if ($users === []): ?>
            <tr>
              <td colspan="6" class="px-5 py-8 text-center text-text-muted">لا توجد نتائج مطابقة للفلاتر.</td>
            </tr>
          <?php endif; ?>
          <?php foreach ($users as $row): ?>
            <?php $isActive = (int) ($row['is_active'] ?? 0) === 1; ?>
            <tr class="hover:bg-slate-50 transition">
              <td class="px-5 py-4">
                <div class="font-bold text-slate-900"><?= h((string) ($row['display_name_ar'] ?? '—')) ?></div>
                <div class="text-xs text-text-muted mt-1"><?= h((string) ($row['user_name'] ?? '')) ?></div>
              </td>
              <td class="px-5 py-4 text-text-muted"><?= h((string) (($row['email'] ?? '') !== '' ? $row['email'] : '—')) ?></td>
              <td class="px-5 py-4 text-text-muted"><?= h((string) (($row['roles_label'] ?? '') !== '' ? $row['roles_label'] : 'بدون دور')) ?></td>
              <td class="px-5 py-4">
                <span class="px-3 py-1 rounded-full text-xs font-bold <?= $isActive ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
                  <?= $isActive ? 'نشط' : 'معطل' ?>
                </span>
              </td>
              <td class="px-5 py-4 text-xs text-text-muted"><?= h((string) (($row['last_login_at'] ?? '') !== '' ? $row['last_login_at'] : 'لم يسجل دخول بعد')) ?></td>
              <td class="px-5 py-4">
                <div class="flex items-center justify-end gap-2">
                  <a
                    href="/dashboard/users.php?edit=<?= urlencode((string) ($row['id'] ?? '')) ?>&q=<?= urlencode((string) ($filters['q'] ?? '')) ?>&role=<?= urlencode((string) ($filters['role'] ?? '')) ?>&active=<?= urlencode((string) ($filters['active'] ?? '')) ?>"
                    class="h-9 px-3 inline-flex items-center rounded-lg border border-border-subtle text-xs font-bold text-text-muted hover:bg-surface-low"
                  >
                    تعديل
                  </a>
                  <form method="post" data-dashboard-ajax data-dashboard-reload>
                    <input type="hidden" name="action" value="toggle_active">
                    <input type="hidden" name="id" value="<?= h((string) ($row['id'] ?? '')) ?>">
                    <input type="hidden" name="next_active" value="<?= $isActive ? '0' : '1' ?>">
                    <button type="submit" class="dashboard-btn h-9 px-3 rounded-lg text-xs font-bold <?= $isActive ? 'bg-red-600 text-white' : 'bg-green-600 text-white' ?>">
                      <?= $isActive ? 'تعطيل' : 'تفعيل' ?>
                    </button>
                  </form>
                  <?php if ((string) ($row['id'] ?? '') !== (string) ($currentUserId ?? '')): ?>
                    <form method="post" onsubmit="return confirm('هل أنت متأكد من حذف هذا المستخدم نهائياً؟')">
                      <input type="hidden" name="action" value="delete_user">
                      <input type="hidden" name="id" value="<?= h((string) ($row['id'] ?? '')) ?>">
                      <button class="h-9 px-3 rounded-lg border border-red-200 text-xs font-bold text-red-700 hover:bg-red-50">
                        حذف
                      </button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </article>
</section>

<section class="mb-5 bg-white border border-border-subtle rounded-2xl overflow-hidden">
  <div class="p-4 border-b border-border-subtle bg-surface-low">
    <h2 class="text-base font-extrabold text-slate-900">قوالب المهام والصلاحيات</h2>
    <p class="text-xs text-text-muted mt-1">مرجع سريع لتوزيع المهام بين الموظفين. الأدوار أدناه قابلة للتعديل من جدول «الأدوار والصلاحيات».</p>
  </div>
  <div class="overflow-auto">
    <table class="w-full min-w-[920px] text-sm">
      <thead class="bg-white border-b border-border-subtle text-text-muted">
        <tr>
          <th class="px-5 py-4 text-right font-bold">المهمة</th>
          <th class="px-5 py-4 text-right font-bold">الدور</th>
          <th class="px-5 py-4 text-right font-bold">الصلاحيات</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-border-subtle">
        <?php foreach ($taskRoles as $task): ?>
          <tr class="hover:bg-slate-50 transition">
            <td class="px-5 py-4">
              <div class="font-bold text-slate-900"><?= h((string) ($task['name_ar'] ?? '')) ?></div>
              <div class="text-xs text-text-muted mt-1"><?= h((string) ($task['description_ar'] ?? '')) ?></div>
            </td>
            <td class="px-5 py-4 font-mono text-xs text-text-muted" dir="ltr"><?= h((string) ($task['role_code'] ?? '')) ?></td>
            <td class="px-5 py-4 text-xs text-text-muted leading-relaxed">
              <?php
                $labels = [];
                foreach ($task['permissions'] ?? [] as $permissionCode) {
                    $labels[] = $permissionLabelsByCode[$permissionCode] ?? $permissionCode;
                }
                echo h(implode(' · ', $labels));
              ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="grid grid-cols-1 xl:grid-cols-3 gap-5">
  <article class="xl:col-span-1 bg-white border border-border-subtle rounded-2xl p-5">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-base font-extrabold text-slate-900"><?= $editingRole ? 'تعديل دور' : 'إضافة دور جديد' ?></h2>
      <?php if ($editingRole): ?>
        <a href="/dashboard/users.php?q=<?= urlencode((string) ($filters['q'] ?? '')) ?>&role=<?= urlencode((string) ($filters['role'] ?? '')) ?>&active=<?= urlencode((string) ($filters['active'] ?? '')) ?>" class="text-xs text-primary hover:underline">إلغاء التعديل</a>
      <?php endif; ?>
    </div>
    <form method="post" class="space-y-4">
      <input type="hidden" name="action" value="save_role">
      <input type="hidden" name="id" value="<?= h((string) ($editRole['id'] ?? '')) ?>">

      <label class="block text-sm">
        <span class="text-text-muted block mb-1">اسم الدور</span>
        <input name="name_ar" required value="<?= h((string) ($editRole['name_ar'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary" placeholder="موظف مخزن">
      </label>

      <label class="block text-sm">
        <span class="text-text-muted block mb-1">رمز الدور (إنجليزي)</span>
        <input
          name="code"
          <?= $editingRole && (int) ($editRole['is_system'] ?? 0) === 1 ? 'readonly' : ($editingRole ? 'readonly' : 'required') ?>
          value="<?= h((string) ($editRole['code'] ?? '')) ?>"
          class="h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary font-mono text-sm <?= ($editingRole && (int) ($editRole['is_system'] ?? 0) === 1) || $editingRole ? 'bg-surface-low' : '' ?>"
          dir="ltr"
          placeholder="warehouse_staff"
        >
        <?php if ($editingRole): ?>
          <span class="text-[11px] text-text-muted mt-1 block">لا يمكن تغيير رمز الدور بعد الإنشاء.</span>
        <?php else: ?>
          <span class="text-[11px] text-text-muted mt-1 block">مثال: warehouse_staff — أحرف إنجليزية صغيرة وأرقام وشرطة سفلية.</span>
        <?php endif; ?>
      </label>

      <label class="block text-sm">
        <span class="text-text-muted block mb-1">الوصف (اختياري)</span>
        <textarea name="description_ar" rows="2" class="w-full rounded-xl border border-border-subtle px-4 py-2 focus:border-primary focus:ring-primary text-sm" placeholder="صلاحيات هذا الدور..."><?= h((string) ($editRole['description_ar'] ?? '')) ?></textarea>
      </label>

      <fieldset>
        <legend class="text-sm text-text-muted mb-2">الصلاحيات</legend>
        <div class="space-y-3 max-h-72 overflow-auto border border-border-subtle rounded-xl p-3 bg-surface-low">
          <?php foreach ($permissionsByCategory as $category => $categoryPermissions): ?>
            <div>
              <p class="text-xs font-bold text-slate-700 mb-1"><?= h($category) ?></p>
              <div class="space-y-1.5">
                <?php foreach ($categoryPermissions as $permission): ?>
                  <?php $permissionId = (string) ($permission['id'] ?? ''); ?>
                  <label class="flex items-start gap-2 text-sm">
                    <input
                      type="checkbox"
                      name="permission_ids[]"
                      value="<?= h($permissionId) ?>"
                      <?= in_array($permissionId, $selectedPermissionIds, true) ? 'checked' : '' ?>
                      class="mt-1 rounded border-border-subtle text-primary focus:ring-primary"
                    >
                    <span>
                      <span class="font-semibold block"><?= h((string) ($permission['name_ar'] ?? '')) ?></span>
                      <span class="text-[11px] text-text-muted font-mono" dir="ltr"><?= h((string) ($permission['code'] ?? '')) ?></span>
                    </span>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </fieldset>

      <button class="w-full h-11 rounded-xl bg-primary text-white font-bold hover:brightness-110 transition">
        <?= $editingRole ? 'حفظ الدور' : 'إنشاء الدور' ?>
      </button>
    </form>
  </article>

  <article class="xl:col-span-2 bg-white border border-border-subtle rounded-2xl overflow-hidden">
    <div class="p-4 border-b border-border-subtle bg-surface-low">
      <h2 class="text-base font-extrabold text-slate-900">الأدوار والصلاحيات</h2>
      <p class="text-xs text-text-muted mt-1">أدوار النظام الافتراضية يمكن تعديل صلاحياتها لكن لا يمكن حذفها.</p>
    </div>
    <div class="overflow-auto">
      <table class="w-full min-w-[860px] text-sm">
        <thead class="bg-white border-b border-border-subtle text-text-muted">
          <tr>
            <th class="px-5 py-4 text-right font-bold">الدور</th>
            <th class="px-5 py-4 text-right font-bold">الرمز</th>
            <th class="px-5 py-4 text-right font-bold">المستخدمون</th>
            <th class="px-5 py-4 text-right font-bold">الصلاحيات</th>
            <th class="px-5 py-4 text-right font-bold">النوع</th>
            <th class="px-5 py-4 text-right font-bold text-left">إجراءات</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border-subtle">
          <?php if ($roles === []): ?>
            <tr>
              <td colspan="6" class="px-5 py-8 text-center text-text-muted">لا توجد أدوار.</td>
            </tr>
          <?php endif; ?>
          <?php foreach ($roles as $role): ?>
            <?php $isSystem = (int) ($role['is_system'] ?? 0) === 1; ?>
            <tr class="hover:bg-slate-50 transition">
              <td class="px-5 py-4">
                <div class="font-bold text-slate-900"><?= h((string) ($role['name_ar'] ?? '—')) ?></div>
                <?php if (!empty($role['description_ar'])): ?>
                  <div class="text-xs text-text-muted mt-1"><?= h((string) $role['description_ar']) ?></div>
                <?php endif; ?>
              </td>
              <td class="px-5 py-4 font-mono text-xs text-text-muted" dir="ltr"><?= h((string) ($role['code'] ?? '')) ?></td>
              <td class="px-5 py-4"><?= (int) ($role['users_count'] ?? 0) ?></td>
              <td class="px-5 py-4"><?= (int) ($role['permissions_count'] ?? 0) ?></td>
              <td class="px-5 py-4">
                <span class="px-3 py-1 rounded-full text-xs font-bold <?= $isSystem ? 'bg-blue-100 text-blue-700' : 'bg-slate-100 text-slate-700' ?>">
                  <?= $isSystem ? 'نظام' : 'مخصص' ?>
                </span>
              </td>
              <td class="px-5 py-4">
                <div class="flex items-center justify-end gap-2">
                  <a
                    href="/dashboard/users.php?edit_role=<?= urlencode((string) ($role['id'] ?? '')) ?>&q=<?= urlencode((string) ($filters['q'] ?? '')) ?>&role=<?= urlencode((string) ($filters['role'] ?? '')) ?>&active=<?= urlencode((string) ($filters['active'] ?? '')) ?>"
                    class="h-9 px-3 inline-flex items-center rounded-lg border border-border-subtle text-xs font-bold text-text-muted hover:bg-surface-low"
                  >
                    تعديل
                  </a>
                  <?php if (!$isSystem): ?>
                    <form method="post" onsubmit="return confirm('حذف الدور؟ لا يمكن التراجع.')">
                      <input type="hidden" name="action" value="delete_role">
                      <input type="hidden" name="id" value="<?= h((string) ($role['id'] ?? '')) ?>">
                      <button class="h-9 px-3 rounded-lg border border-red-200 text-xs font-bold text-red-700 hover:bg-red-50" <?= (int) ($role['users_count'] ?? 0) > 0 ? 'disabled title="أزل الدور عن المستخدمين أولاً"' : '' ?>>
                        حذف
                      </button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </article>
</section>
