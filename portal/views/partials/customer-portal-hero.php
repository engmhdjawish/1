<?php

declare(strict_types=1);

/** @var array<string, mixed> $customer */
/** @var array<string, mixed> $profile */
/** @var string $pageTitle */

$customerName = trim((string) ($profile['name_ar'] ?? $customer['name_ar'] ?? 'عميل'));
$initial = function_exists('mb_substr') ? mb_substr($customerName, 0, 1) : substr($customerName, 0, 1);
?>
<header class="customer-portal__hero">
  <div class="flex items-center gap-3 min-w-0">
    <div class="customer-portal__avatar" aria-hidden="true"><?= h($initial) ?></div>
    <div class="min-w-0">
      <h1 class="customer-portal__title"><?= h($pageTitle) ?></h1>
      <p class="customer-portal__subtitle" dir="ltr"><?= h((string) ($customer['phone'] ?? '')) ?></p>
    </div>
  </div>
  <a href="/store.php" class="store-btn store-btn--secondary shrink-0">
    <span class="material-symbols-outlined text-base" aria-hidden="true">storefront</span>
    المتجر
  </a>
</header>
