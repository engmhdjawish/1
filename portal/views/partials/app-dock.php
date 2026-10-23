<?php

declare(strict_types=1);

use Portal\Services\HomeLayoutService;
use Portal\Services\PortalSettingsService;

/** @var string $dockContext home|store */
/** @var list<string>|null $dockItems */
/** @var bool $dockAllowCart */
/** @var float|null $dockCartPackages */
/** @var string|null $dockOfferHref */
/** @var string|null $dockClass hn-dock|lab-dock */

$dockContext = (string) ($dockContext ?? '');
$dockClass = (string) ($dockClass ?? 'hn-dock');
$dockItems = HomeLayoutService::normalizeDockItems($dockItems ?? null);
$dockAllowCart = (bool) ($dockAllowCart ?? false);
$dockCartPackages = (float) ($dockCartPackages ?? 0);
$dockOfferHref = (string) ($dockOfferHref ?? '');
try {
    $dockCustomer = \Portal\Auth\CustomerSession::check();
    $dockSignedIn = \Portal\Auth\CustomerSession::isLoggedIn();
} catch (Throwable) {
    $dockCustomer = false;
    $dockSignedIn = false;
}
try {
    $dockStaff = !$dockSignedIn && \Portal\Auth\WebSession::check();
} catch (Throwable) {
    $dockStaff = false;
}
$dockWhatsapp = preg_replace('/\D+/', '', (string) (PortalSettingsService::companySettings()['company_whatsapp'] ?? '')) ?? '';

$dockEntries = [];
foreach ($dockItems as $dockKey) {
    $meta = HomeLayoutService::DOCK_ITEMS[$dockKey];
    $entry = ['key' => $dockKey, 'label' => $meta['label'], 'icon' => $meta['icon'], 'href' => '', 'attr' => '', 'active' => false, 'badge' => false];
    switch ($dockKey) {
        case 'home':
            $entry['href'] = '/home-next.php';
            $entry['active'] = $dockContext === 'home';
            break;
        case 'sections':
            if ($dockContext === 'home') {
                $entry['attr'] = 'data-hn-sheet-open';
            } else {
                $entry['href'] = '/home-next.php#hn-sections';
            }
            break;
        case 'store':
            $entry['href'] = '/store-lab.php';
            $entry['active'] = $dockContext === 'store';
            break;
        case 'search':
            if ($dockContext === 'home') {
                $entry['attr'] = 'data-hn-focus-search';
            } else {
                $entry['href'] = '/store-lab.php#sl-search-input';
                $entry['attr'] = 'data-lab-dock-search';
            }
            break;
        case 'offers':
            if ($dockOfferHref === '') {
                continue 2;
            }
            $entry['href'] = $dockOfferHref;
            break;
        case 'cart':
            if (!$dockAllowCart) {
                continue 2;
            }
            $entry['href'] = '/store-cart.php';
            $entry['badge'] = true;
            break;
        case 'whatsapp':
            if ($dockWhatsapp === '') {
                continue 2;
            }
            $entry['href'] = 'https://wa.me/' . $dockWhatsapp;
            $entry['attr'] = 'target="_blank" rel="noopener"';
            break;
        case 'orders':
            if ($dockStaff) {
                if (!\Portal\Auth\WebSession::hasPermission('orders.view')) {
                    continue 2;
                }
                $entry['href'] = '/dashboard/orders.php';
                $entry['label'] = 'الطلبات';
                break;
            }
            $entry['href'] = $dockCustomer ? '/my-orders.php' : '/track-order.php';
            $entry['label'] = $dockCustomer ? 'طلباتي' : 'تتبّع طلب';
            break;
        case 'account':
            if ($dockSignedIn || $dockStaff) {
                $entry['label'] = 'حسابي';
                $entry['icon'] = $dockStaff ? 'badge' : 'storefront';
                $entry['attr'] = 'data-site-account-open aria-haspopup="menu" aria-controls="siteAccountMenu" aria-expanded="false"';
                break;
            }
            $entry['href'] = portal_login_url('customer');
            $entry['label'] = 'دخول';
            break;
    }
    $dockEntries[] = $entry;
}
?>
<nav class="<?= h($dockClass) ?>" aria-label="تنقّل سريع" data-app-dock>
  <?php foreach ($dockEntries as $entry): ?>
    <?php
      $itemClass = $dockClass . '__item' . ($entry['active'] ? ' is-active' : '');
      $current = $entry['active'] ? ' aria-current="page"' : '';
      $attr = $entry['attr'] !== '' ? ' ' . $entry['attr'] : '';
    ?>
    <?php if ($entry['href'] === ''): ?>
      <button type="button" class="<?= h($itemClass) ?>"<?= $attr ?>>
    <?php else: ?>
      <a href="<?= h($entry['href']) ?>" class="<?= h($itemClass) ?>"<?= $current . $attr ?>>
    <?php endif; ?>
      <?php if ($entry['badge']): ?>
        <span class="<?= h($dockClass) ?>__icon">
          <span class="material-symbols-outlined" aria-hidden="true"><?= h($entry['icon']) ?></span>
          <span class="<?= h($dockClass) ?>__badge<?= $dockCartPackages > 0 ? '' : ' hidden' ?>" data-store-cart-badge><?= h(format_packages_display($dockCartPackages)) ?></span>
        </span>
      <?php else: ?>
        <span class="material-symbols-outlined" aria-hidden="true"><?= h($entry['icon']) ?></span>
      <?php endif; ?>
      <span><?= h($entry['label']) ?></span>
    <?php if ($entry['href'] === ''): ?>
      </button>
    <?php else: ?>
      </a>
    <?php endif; ?>
  <?php endforeach; ?>
</nav>
