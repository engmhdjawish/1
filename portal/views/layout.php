<?php

declare(strict_types=1);

use Portal\Auth\CustomerSession;
use Portal\Auth\WebSession;
use Portal\Services\PortalSettingsService;

/** @var string $title */
/** @var string $content */
/** @var array<string, string>|null $companyContext */
/** @var string|null $companyLogoUrl */

require_once __DIR__ . '/helpers.php';

$companyContext ??= PortalSettingsService::companySettings();
$companyLogoUrl ??= PortalSettingsService::companyLogoUrl($companyContext);
$siteName = trim((string) ($companyContext['company_name'] ?? '')) !== ''
    ? (string) $companyContext['company_name']
    : 'جاويش للتجارة';

$customer = CustomerSession::check() ? CustomerSession::customer() : null;
$staffLoggedIn = WebSession::check();

$navLinks = [
    ['href' => '/index.php', 'label' => 'الرئيسية'],
    ['href' => '/store.php', 'label' => 'المتجر'],
    ['href' => '/about.php', 'label' => 'من نحن'],
];

$whatsapp = preg_replace('/\D+/', '', (string) ($companyContext['company_whatsapp'] ?? ''));
$whatsappLink = $whatsapp !== '' ? 'https://wa.me/' . $whatsapp : '';
?>
<!DOCTYPE html>
<html class="light" lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($title) ?> — <?= h($siteName) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined&display=swap" rel="stylesheet">
    <link href="/css/material-image-frame.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      tailwind.config = {
        theme: {
          extend: {
            colors: {
              primary: '#D81921',
              'surface-bg': '#f6f6f8',
              'surface-card': '#ffffff',
              'text-main': '#111827',
              'text-muted': '#4b5563'
            }
          }
        }
      };
    </script>
    <style>
      body { font-family: Manrope, sans-serif; background: #f6f6f8; color: #111827; }
      .site-link { color: #374151; }
      .site-link:hover, .site-link.is-active { color: #D81921; }
      .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
      .material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 500, 'GRAD' 0, 'opsz' 24; vertical-align: middle; line-height: 1; }
      #publicNavDrawer { transition: transform 0.25s ease; }
      #publicNavDrawer.is-open { transform: translateX(0); }
      #publicNavOverlay.is-open { opacity: 1; pointer-events: auto; }
    </style>
</head>
<body class="min-h-screen text-text-main bg-surface-bg flex flex-col">
<header class="bg-surface-card border-b border-gray-200 sticky top-0 z-30 shadow-sm">
  <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between gap-3">
    <div class="flex items-center gap-2 min-w-0">
      <button
        type="button"
        id="openPublicNavBtn"
        class="md:hidden inline-flex items-center justify-center w-10 h-10 rounded-xl border border-gray-200 hover:bg-gray-50"
        aria-controls="publicNavDrawer"
        aria-expanded="false"
        aria-label="فتح القائمة"
      >
        <span class="material-symbols-outlined">menu</span>
      </button>
      <a href="/index.php" class="font-extrabold text-primary text-base sm:text-lg inline-flex items-center gap-2 min-w-0">
        <?php if (!empty($companyLogoUrl)): ?>
          <img src="<?= h((string) $companyLogoUrl) ?>" alt="" class="h-9 w-9 rounded-lg object-contain bg-white border border-gray-100 shrink-0">
        <?php endif; ?>
        <span class="truncate"><?= h($siteName) ?></span>
      </a>
    </div>

    <nav class="hidden md:flex items-center gap-5 text-sm font-semibold">
      <?php foreach ($navLinks as $link): ?>
        <?php $path = parse_url($link['href'], PHP_URL_PATH) ?: ''; ?>
        <a href="<?= h($link['href']) ?>" class="site-link <?= ($_SERVER['REQUEST_URI'] ?? '') === $path || str_starts_with((string) ($_SERVER['REQUEST_URI'] ?? ''), $path . '?') ? 'is-active' : '' ?>">
          <?= h($link['label']) ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <div class="flex items-center gap-2 shrink-0">
      <?php if ($customer): ?>
        <span class="hidden sm:inline text-sm font-bold text-gray-700 max-w-[140px] truncate"><?= h((string) ($customer['name_ar'] ?? '')) ?></span>
        <a href="/logout.php" class="h-10 inline-flex items-center gap-1 rounded-xl border border-gray-200 px-3 text-sm font-bold hover:border-primary" title="تسجيل الخروج">
          <span class="material-symbols-outlined text-base" aria-hidden="true">logout</span>
          <span class="hidden sm:inline">خروج</span>
        </a>
      <?php else: ?>
        <a href="/login.php?type=customer" class="hidden sm:inline-flex h-10 items-center rounded-xl border border-gray-200 px-3 text-sm font-bold hover:border-primary">دخول</a>
        <a href="/register.php" class="h-10 inline-flex items-center rounded-xl bg-primary text-white px-3 text-sm font-bold hover:brightness-110">تسجيل</a>
      <?php endif; ?>
      <?php if ($staffLoggedIn): ?>
        <a href="/dashboard/index.php" class="hidden lg:inline-flex h-10 items-center rounded-xl border border-gray-200 px-3 text-xs font-bold text-gray-600 hover:border-primary">لوحة التحكم</a>
      <?php endif; ?>
    </div>
  </div>
</header>

<div id="publicNavOverlay" class="md:hidden fixed inset-0 z-40 bg-black/40 opacity-0 pointer-events-none transition" aria-hidden="true"></div>
<aside id="publicNavDrawer" class="md:hidden fixed top-0 right-0 z-50 h-full w-[min(88vw,300px)] bg-white border-l border-gray-200 shadow-2xl flex flex-col translate-x-full" aria-hidden="true">
  <div class="flex items-center justify-between px-4 py-4 border-b border-gray-200">
    <span class="font-bold text-primary"><?= h($siteName) ?></span>
    <button type="button" id="closePublicNavBtn" class="w-9 h-9 rounded-full hover:bg-red-50 inline-flex items-center justify-center" aria-label="إغلاق">
      <span class="material-symbols-outlined">close</span>
    </button>
  </div>
  <nav class="p-4 space-y-1 text-sm font-semibold">
    <?php foreach ($navLinks as $link): ?>
      <a href="<?= h($link['href']) ?>" data-public-nav-link="1" class="block rounded-xl px-3 py-3 hover:bg-gray-50"><?= h($link['label']) ?></a>
    <?php endforeach; ?>
    <?php if ($customer): ?>
      <div class="pt-3 mt-3 border-t border-gray-100 text-gray-600 px-3"><?= h((string) ($customer['name_ar'] ?? '')) ?></div>
      <a href="/logout.php" data-public-nav-link="1" class="block rounded-xl px-3 py-3 text-red-600 hover:bg-red-50">تسجيل الخروج</a>
    <?php else: ?>
      <a href="/login.php?type=customer" data-public-nav-link="1" class="block rounded-xl px-3 py-3 hover:bg-gray-50">دخول العملاء</a>
      <a href="/register.php" data-public-nav-link="1" class="block rounded-xl px-3 py-3 text-primary hover:bg-red-50">تسجيل عميل جديد</a>
    <?php endif; ?>
    <?php if ($staffLoggedIn): ?>
      <a href="/dashboard/index.php" data-public-nav-link="1" class="block rounded-xl px-3 py-3 text-gray-600 hover:bg-gray-50">لوحة التحكم</a>
    <?php endif; ?>
  </nav>
</aside>

<main class="flex-1 max-w-7xl w-full mx-auto px-4 py-6 md:py-8">
  <?= $content ?>
</main>

<footer class="bg-white border-t border-gray-200 mt-auto">
  <div class="max-w-7xl mx-auto px-4 py-8 grid grid-cols-1 md:grid-cols-3 gap-6 text-sm">
    <div>
      <h2 class="font-extrabold text-primary mb-2"><?= h($siteName) ?></h2>
      <p class="text-gray-600 leading-relaxed">متجر إلكتروني لتصفح المواد والطلب حسب سياسة حسابك.</p>
    </div>
    <div>
      <h3 class="font-bold mb-2">روابط سريعة</h3>
      <div class="space-y-1">
        <a href="/index.php" class="block site-link">الرئيسية</a>
        <a href="/store.php" class="block site-link">المتجر</a>
        <a href="/about.php" class="block site-link">من نحن</a>
        <?php if (!$customer): ?>
          <a href="/register.php" class="block site-link">تسجيل عميل</a>
        <?php endif; ?>
      </div>
    </div>
    <div>
      <h3 class="font-bold mb-2">تواصل</h3>
      <div class="space-y-1 text-gray-600">
        <?php if (trim((string) ($companyContext['company_phone'] ?? '')) !== ''): ?>
          <div dir="ltr"><?= h((string) $companyContext['company_phone']) ?></div>
        <?php endif; ?>
        <?php if (trim((string) ($companyContext['company_mobile'] ?? '')) !== ''): ?>
          <div dir="ltr"><?= h((string) $companyContext['company_mobile']) ?></div>
        <?php endif; ?>
        <?php if ($whatsappLink !== ''): ?>
          <a href="<?= h($whatsappLink) ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-emerald-700 font-bold mt-2">
            <span class="material-symbols-outlined text-base" aria-hidden="true">chat</span>
            واتساب
          </a>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="border-t border-gray-100 py-4 text-center text-xs text-gray-500">
    © <?= date('Y') ?> <?= h($siteName) ?>
  </div>
</footer>

<script>
  (() => {
    const drawer = document.getElementById('publicNavDrawer');
    const overlay = document.getElementById('publicNavOverlay');
    const openBtn = document.getElementById('openPublicNavBtn');
    const closeBtn = document.getElementById('closePublicNavBtn');
    if (!drawer || !overlay || !openBtn || !closeBtn) return;
    const setOpen = (open) => {
      drawer.classList.toggle('is-open', open);
      overlay.classList.toggle('is-open', open);
      drawer.setAttribute('aria-hidden', open ? 'false' : 'true');
      overlay.setAttribute('aria-hidden', open ? 'false' : 'true');
      openBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      document.body.style.overflow = open ? 'hidden' : '';
    };
    openBtn.addEventListener('click', () => setOpen(true));
    closeBtn.addEventListener('click', () => setOpen(false));
    overlay.addEventListener('click', () => setOpen(false));
    drawer.querySelectorAll('[data-public-nav-link]').forEach((link) => link.addEventListener('click', () => setOpen(false)));
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape') setOpen(false); });
  })();
</script>
<?php require __DIR__ . '/partials/product-quick-view.php'; ?>
</body>
</html>
