<?php

declare(strict_types=1);

/** @var array<string, mixed> $user */
/** @var array<string, mixed> $profile */
/** @var string|null $flash */
/** @var string $flashType */
?>
<section class="max-w-2xl">
  <h1 class="text-2xl font-extrabold mb-1">حسابي</h1>
  <p class="text-sm text-text-muted mb-6">إدارة بيانات الدخول وكلمة المرور لحساب الموظف.</p>

  <?php if ($flash): ?>
    <p class="mb-4 rounded-xl border px-4 py-3 text-sm <?= $flashType === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-red-200 bg-red-50 text-red-700' ?>">
      <?= h($flash) ?>
    </p>
  <?php endif; ?>

  <div class="rounded-xl border border-border-subtle bg-surface-white p-5 mb-6 space-y-3">
    <h2 class="font-bold text-primary">بيانات الحساب</h2>
    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
      <div>
        <dt class="text-text-muted">الاسم المعروض</dt>
        <dd class="font-bold"><?= h((string) ($profile['display_name_ar'] ?? $user['display_name_ar'] ?? '')) ?></dd>
      </div>
      <div>
        <dt class="text-text-muted">اسم المستخدم</dt>
        <dd class="font-mono" dir="ltr"><?= h((string) ($profile['user_name'] ?? $user['user_name'] ?? '')) ?></dd>
      </div>
      <div>
        <dt class="text-text-muted">البريد الإلكتروني</dt>
        <dd><?= h((string) ($profile['email'] ?? '—')) ?></dd>
      </div>
      <div>
        <dt class="text-text-muted">الدور</dt>
        <dd><?= h((string) ($user['role_label'] ?? 'موظف')) ?></dd>
      </div>
    </dl>
  </div>

  <form method="post" class="rounded-xl border border-border-subtle bg-surface-white p-5 space-y-4">
    <input type="hidden" name="action" value="change_password">
    <h2 class="font-bold text-primary">تغيير كلمة المرور</h2>
    <label class="block text-sm">
      <span class="text-text-muted">كلمة المرور الحالية</span>
      <input type="password" name="current_password" required autocomplete="current-password" class="mt-1 h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary">
    </label>
    <label class="block text-sm">
      <span class="text-text-muted">كلمة المرور الجديدة</span>
      <input type="password" name="new_password" required minlength="6" autocomplete="new-password" class="mt-1 h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary">
    </label>
    <button type="submit" class="h-11 px-5 rounded-xl bg-primary text-white font-bold hover:brightness-110">حفظ كلمة المرور</button>
  </form>
</section>
