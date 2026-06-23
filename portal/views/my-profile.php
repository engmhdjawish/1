<?php

declare(strict_types=1);

/** @var array<string, mixed> $customer */
/** @var array<string, mixed> $profile */
/** @var string|null $flash */
/** @var string $flashType */

$pageTitle = 'الملف الشخصي';
?>
<div class="customer-portal">
  <?php require __DIR__ . '/partials/customer-portal-hero.php'; ?>

  <?php if ($flash): ?>
    <p class="mb-4 rounded-xl border px-4 py-3 text-sm <?= $flashType === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-red-200 bg-red-50 text-red-700' ?>">
      <?= h($flash) ?>
    </p>
  <?php endif; ?>

  <div class="customer-profile-grid">
    <article class="customer-form-card">
      <h2 class="customer-form-card__title">
        <span class="material-symbols-outlined" aria-hidden="true">badge</span>
        بيانات الحساب
      </h2>
      <p class="text-sm text-gray-600 mb-4">بياناتك الأساسية ثابتة ولا يمكن تعديلها من هنا. للتحديث تواصل مع الإدارة.</p>
      <dl class="customer-order-dl">
        <div>
          <dt>الاسم</dt>
          <dd><?= h((string) ($profile['name_ar'] ?? $customer['name_ar'] ?? '')) ?></dd>
        </div>
        <div>
          <dt>رقم الهاتف</dt>
          <dd dir="ltr"><?= h((string) ($customer['phone'] ?? '')) ?></dd>
        </div>
        <div>
          <dt>البريد الإلكتروني</dt>
          <dd dir="ltr"><?= h((string) (($profile['email'] ?? '') !== '' ? $profile['email'] : '—')) ?></dd>
        </div>
      </dl>
    </article>

    <form method="post" class="customer-form-card">
      <input type="hidden" name="action" value="change_password">
      <h2 class="customer-form-card__title">
        <span class="material-symbols-outlined" aria-hidden="true">lock</span>
        كلمة المرور
      </h2>
      <label>
        كلمة المرور الحالية
        <input type="password" name="current_password" required autocomplete="current-password">
      </label>
      <label>
        كلمة المرور الجديدة
        <input type="password" name="new_password" required minlength="6" autocomplete="new-password">
      </label>
      <button type="submit" class="store-btn store-btn--secondary">تحديث كلمة المرور</button>
    </form>
  </div>
</div>
