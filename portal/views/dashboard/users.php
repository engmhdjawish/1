<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $roles */
/** @var list<array<string, mixed>> $users */
/** @var array{total: int, active: int, inactive: int, admins: int} $stats */
/** @var array{q: string, role: string, active: string} $filters */
/** @var array<string, mixed>|null $editUser */
/** @var string|null $flash */
/** @var string $flashType */

$editing = $editUser !== null;
$selectedRoleIds = array_map('strval', $editUser['role_ids'] ?? []);
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

<?php if ($flash): ?>
  <p class="mb-4 rounded-xl border px-4 py-3 text-sm <?= $flashType === 'error' ? 'bg-red-50 border-red-200 text-red-700' : 'bg-green-50 border-green-200 text-green-700' ?>">
    <?= h($flash) ?>
  </p>
<?php endif; ?>

<section class="grid grid-cols-1 xl:grid-cols-3 gap-5 mb-5">
  <article class="xl:col-span-1 bg-white border border-border-subtle rounded-2xl p-5">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-base font-extrabold text-slate-900"><?= $editing ? 'تعديل مستخدم' : 'إضافة مستخدم جديد' ?></h2>
      <?php if ($editing): ?>
        <a href="/dashboard/users.php" class="text-xs text-primary hover:underline">إلغاء التعديل</a>
      <?php endif; ?>
    </div>
    <form method="post" class="space-y-4">
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
        <legend class="text-sm text-text-muted mb-2">الأدوار</legend>
        <div class="space-y-2 max-h-44 overflow-auto border border-border-subtle rounded-xl p-3 bg-surface-low">
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
                  <form method="post">
                    <input type="hidden" name="action" value="toggle_active">
                    <input type="hidden" name="id" value="<?= h((string) ($row['id'] ?? '')) ?>">
                    <input type="hidden" name="next_active" value="<?= $isActive ? '0' : '1' ?>">
                    <button class="h-9 px-3 rounded-lg text-xs font-bold <?= $isActive ? 'bg-red-600 text-white' : 'bg-green-600 text-white' ?>">
                      <?= $isActive ? 'تعطيل' : 'تفعيل' ?>
                    </button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </article>
</section>
