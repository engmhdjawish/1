<?php

declare(strict_types=1);

/** @var string $context card|cart|preview|inline */
/** @var string|null $redirect */

$context = trim((string) ($context ?? 'card'));
if ($context === '') {
    $context = 'card';
}
$redirect = isset($redirect) && trim((string) $redirect) !== ''
    ? (string) $redirect
    : portal_request_path();
$loginUrl = portal_login_url('customer', $redirect);
$registerUrl = '/register.php' . ($redirect !== '' ? ('?redirect=' . rawurlencode($redirect)) : '');
?>
<div class="store-price-lock store-price-lock--<?= h($context) ?>" role="note">
  <span class="store-price-lock__badge" aria-hidden="true">
    <span class="material-symbols-outlined">lock</span>
  </span>
  <div class="store-price-lock__copy">
    <strong class="store-price-lock__title">السعر مقفول</strong>
    <span class="store-price-lock__hint">سجّل الدخول أو أنشئ حساباً لعرض الأسعار</span>
  </div>
  <div class="store-price-lock__actions">
    <a href="<?= h($loginUrl) ?>" class="store-price-lock__btn store-price-lock__btn--primary">دخول</a>
    <a href="<?= h($registerUrl) ?>" class="store-price-lock__btn store-price-lock__btn--ghost">حساب جديد</a>
  </div>
</div>
