<?php

declare(strict_types=1);

/** @var string $labDockActive home|store */
/** @var bool $labDockAllowCart */

$labDockActive = (string) ($labDockActive ?? '');
$labDockAllowCart = (bool) ($labDockAllowCart ?? false);
try {
    $labDockCustomer = \Portal\Auth\CustomerSession::check();
} catch (Throwable) {
    $labDockCustomer = false;
}
$labDockAccountHref = $labDockCustomer ? '/account.php' : portal_login_url('customer');
?>
<nav class="lab-dock" aria-label="تنقّل سريع">
  <a href="/index.php" class="lab-dock__item<?= $labDockActive === 'home' ? ' is-active' : '' ?>"<?= $labDockActive === 'home' ? ' aria-current="page"' : '' ?>>
    <span class="material-symbols-outlined" aria-hidden="true">home</span>
    <span>الرئيسية</span>
  </a>
  <a href="/store.php" class="lab-dock__item<?= $labDockActive === 'store' ? ' is-active' : '' ?>"<?= $labDockActive === 'store' ? ' aria-current="page"' : '' ?>>
    <span class="material-symbols-outlined" aria-hidden="true">storefront</span>
    <span>المتجر</span>
  </a>
  <a href="/store.php#sl-search-input" class="lab-dock__item" data-lab-dock-search>
    <span class="material-symbols-outlined" aria-hidden="true">search</span>
    <span>بحث</span>
  </a>
  <?php if ($labDockAllowCart): ?>
    <a href="/store-cart.php" class="lab-dock__item">
      <span class="material-symbols-outlined" aria-hidden="true">shopping_cart</span>
      <span>السلة</span>
    </a>
  <?php endif; ?>
  <a href="<?= h($labDockAccountHref) ?>" class="lab-dock__item">
    <span class="material-symbols-outlined" aria-hidden="true">person</span>
    <span><?= $labDockCustomer ? 'حسابي' : 'دخول' ?></span>
  </a>
</nav>
