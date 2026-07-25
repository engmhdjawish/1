<?php

declare(strict_types=1);

/** @var array<string, mixed>|null $shareContext */
/** @var array<string, mixed> $catalog */
/** @var array<string, mixed> $displayOptions */
/** @var string|null $shareToken */
/** @var array{ok?: bool, message?: string}|string|null $cartNotice */

use Portal\Services\ShareCartService;

$shareContext = is_array($shareContext ?? null) ? $shareContext : [];
$token = trim((string) ($shareContext['token'] ?? ''));
$hasAccess = (bool) ($shareContext['has_access'] ?? false);
$requiresPassword = (bool) ($shareContext['requires_password'] ?? false);
$pageError = trim((string) ($shareContext['error'] ?? ''));
$constraintConflict = (bool) ($shareContext['constraint_conflict'] ?? false);
$allowCart = (bool) ($displayOptions['allow_cart'] ?? false);
$cartCount = ($token !== '' && $allowCart) ? ShareCartService::itemCount($token) : 0;
?>

<?php if ($pageError !== ''): ?>
  <div class="store-empty-state store-empty-state--maintenance mb-4" role="alert">
    <span class="material-symbols-outlined store-empty-state__icon" aria-hidden="true">link_off</span>
    <strong class="store-empty-state__title">تعذّر فتح الرابط</strong>
    <p class="store-empty-state__text"><?= h($pageError) ?></p>
  </div>
<?php elseif ($requiresPassword && !$hasAccess): ?>
  <section class="store-page-head" aria-label="رابط مشاركة محمي">
    <h1 class="store-page-head__title">
      <span class="material-symbols-outlined" aria-hidden="true">lock</span>
      <?= h((string) ($shareContext['name_ar'] ?? 'رابط مشاركة')) ?>
    </h1>
    <p class="store-page-head__meta">هذا الرابط محمي بكلمة مرور — أدخل بيانات الوصول للمتابعة.</p>
  </section>
  <form method="post" class="max-w-md mx-auto rounded-2xl border border-border-subtle bg-white p-5 space-y-3 shadow-sm" autocomplete="off">
    <input type="hidden" name="action" value="unlock">
    <input type="hidden" name="token" value="<?= h($token) ?>">
    <label class="block text-sm">
      <span class="text-text-muted block mb-1">اسم المستخدم</span>
      <input name="access_username" autocomplete="off" class="h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary">
    </label>
    <label class="block text-sm">
      <span class="text-text-muted block mb-1">كلمة المرور</span>
      <input type="password" name="access_password" autocomplete="current-password" class="h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary">
    </label>
    <button class="h-11 w-full rounded-xl bg-primary text-white font-bold hover:brightness-110 transition">دخول للرابط</button>
  </form>
<?php else: ?>
  <?php if ($constraintConflict): ?>
    <p class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
      الفلاتر المختارة لا تتطابق مع قيود هذا الرابط. جرّب إعادة ضبط الفلاتر.
    </p>
  <?php endif; ?>

  <?php if ($hasAccess): ?>
    <?php require __DIR__ . '/store-catalog.php'; ?>
  <?php endif; ?>
<?php endif; ?>
