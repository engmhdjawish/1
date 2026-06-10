<?php

declare(strict_types=1);

/** @var string $title */
/** @var string $content */
/** @var array<string, mixed>|null $user */
/** @var string|null $currentRoute */

$currentRoute ??= parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '/dashboard/index.php';
$navigation = [
    'الإدارة' => [
        '/dashboard/index.php' => ['label' => 'لوحة التحكم', 'icon' => 'dashboard'],
        '/dashboard/customers.php' => ['label' => 'إدارة العملاء', 'icon' => 'group'],
        '/dashboard/orders.php' => ['label' => 'إدارة الطلبات', 'icon' => 'shopping_cart'],
        '/dashboard/share-links.php' => ['label' => 'روابط المشاركة', 'icon' => 'share'],
        '/dashboard/home-sections.php' => ['label' => 'أقسام الرئيسية', 'icon' => 'home_storage'],
        '/dashboard/site-media.php' => ['label' => 'مكتبة الصور', 'icon' => 'photo_library'],
        '/dashboard/users.php' => ['label' => 'المستخدمون والأدوار', 'icon' => 'badge'],
        '/dashboard/settings.php' => ['label' => 'الإعدادات', 'icon' => 'settings'],
    ],
    'المحاسبة' => [
        '/dashboard/accounting.php' => ['label' => 'لوحة المحاسب', 'icon' => 'account_balance'],
        '/dashboard/accounting-sync.php' => ['label' => 'طابور المزامنة', 'icon' => 'sync'],
        '/dashboard/accounting-reports.php' => ['label' => 'التقارير المالية', 'icon' => 'analytics'],
        '/dashboard/accounting-statement.php' => ['label' => 'كشف حساب عميل', 'icon' => 'receipt_long'],
    ],
];
$bottomNav = [
    '/dashboard/index.php' => ['label' => 'الرئيسية', 'icon' => 'dashboard'],
    '/dashboard/orders.php' => ['label' => 'الطلبات', 'icon' => 'shopping_cart'],
    '/dashboard/customers.php' => ['label' => 'العملاء', 'icon' => 'group'],
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?= h($title) ?> — لوحة التحكم</title>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/dashboard/dashboard.css">
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      darkMode: "class",
      theme: {
        extend: {
          colors: {
            primary: '#D81921',
            'surface-white': '#ffffff',
            'surface-low': '#f3f3f5',
            'surface-bg': '#f6f6f8',
            'border-subtle': '#E5E7EB',
            'text-muted': '#5d3f3c',
            'status-active': '#28A745',
            'status-rejected': '#EF4444',
            'status-pending': '#FFC107'
          }
        }
      }
    };
  </script>
  <style>
    body { font-family: 'Manrope', sans-serif; background-color: #f6f6f8; }
    .material-symbols-outlined {
      font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
      font-size: 22px;
      line-height: 1;
    }
    .material-symbols-outlined.fill {
      font-variation-settings: 'FILL' 1, 'wght' 500, 'GRAD' 0, 'opsz' 24;
    }
  </style>
</head>
<body class="min-h-screen text-slate-900 dashboard-app">
  <div id="dashboard-page-loader" aria-hidden="true"><div class="dash-spinner"></div></div>
  <div id="dashboard-toast-root" aria-live="polite"></div>

  <header class="sticky top-0 z-50 h-16 bg-surface-white shadow-sm border-b border-border-subtle">
    <div class="h-full px-3 sm:px-4 lg:px-10 flex items-center justify-between gap-3">
      <div class="flex items-center gap-2 sm:gap-4 min-w-0">
        <button
          type="button"
          id="dashboard-menu-btn"
          class="lg:hidden inline-flex items-center justify-center w-10 h-10 rounded-xl hover:bg-surface-low text-slate-700 shrink-0"
          aria-label="فتح القائمة"
        >
          <span class="material-symbols-outlined">menu</span>
        </button>
        <a href="/dashboard/index.php" class="font-extrabold text-primary text-base sm:text-lg truncate">Jawish Trading</a>
        <nav class="hidden lg:flex items-center gap-4 text-sm shrink-0">
          <a href="/dashboard/index.php" data-dashboard-route="/dashboard/index.php" class="<?= $currentRoute === '/dashboard/index.php' ? 'text-primary font-bold border-b-2 border-primary pb-1' : 'text-text-muted hover:text-primary' ?>">لوحة التحكم</a>
          <a href="/dashboard/orders.php" data-dashboard-route="/dashboard/orders.php" class="<?= $currentRoute === '/dashboard/orders.php' ? 'text-primary font-bold border-b-2 border-primary pb-1' : 'text-text-muted hover:text-primary' ?>">الطلبات</a>
          <a href="/dashboard/customers.php" data-dashboard-route="/dashboard/customers.php" class="<?= $currentRoute === '/dashboard/customers.php' ? 'text-primary font-bold border-b-2 border-primary pb-1' : 'text-text-muted hover:text-primary' ?>">العملاء</a>
        </nav>
      </div>
      <div class="flex items-center gap-2 sm:gap-3 shrink-0">
        <div class="hidden md:flex flex-col items-end">
          <span class="text-sm font-bold"><?= h($user['display_name_ar'] ?? '') ?></span>
          <span class="text-xs text-text-muted">مدير النظام</span>
        </div>
        <a href="/logout.php" data-dashboard-no-nav class="inline-flex items-center justify-center w-9 h-9 rounded-full hover:bg-red-50 text-red-600 transition" title="تسجيل الخروج">
          <span class="material-symbols-outlined">logout</span>
        </a>
      </div>
    </div>
  </header>

  <div id="dashboard-drawer-backdrop" aria-hidden="true"></div>
  <nav id="dashboard-drawer" aria-label="قائمة لوحة التحكم">
    <div class="px-2 py-3 border-b border-border-subtle mb-3">
      <h2 class="font-bold text-primary">لوحة التحكم</h2>
      <p class="text-xs text-text-muted mt-1">نظام إدارة البوابة</p>
    </div>
    <?php $navContext = 'drawer'; require __DIR__ . '/partials/navigation.php'; ?>
    <div class="mt-4 pt-4 border-t border-border-subtle">
      <a href="/index.php" data-dashboard-no-nav class="flex items-center justify-center gap-2 bg-primary text-white rounded-xl py-2.5 font-bold hover:brightness-110 transition">
        <span class="material-symbols-outlined">public</span>
        عرض الموقع
      </a>
    </div>
  </nav>

  <div class="flex">
    <aside class="hidden lg:flex fixed top-16 right-0 h-[calc(100vh-64px)] w-64 bg-surface-white border-l border-border-subtle flex-col p-4 z-40 overflow-y-auto">
      <div class="px-2 py-4 border-b border-border-subtle mb-4">
        <h2 class="font-bold text-primary">لوحة التحكم</h2>
        <p class="text-xs text-text-muted mt-1">نظام إدارة البوابة</p>
      </div>
      <?php $navContext = 'sidebar'; require __DIR__ . '/partials/navigation.php'; ?>
      <div class="mt-auto pt-4 border-t border-border-subtle">
        <a href="/index.php" data-dashboard-no-nav class="flex items-center justify-center gap-2 bg-primary text-white rounded-xl py-2.5 font-bold hover:brightness-110 transition">
          <span class="material-symbols-outlined">public</span>
          عرض الموقع
        </a>
      </div>
    </aside>

    <main
      id="dashboard-main"
      data-dashboard-main
      data-current-route="<?= h($currentRoute) ?>"
      class="flex-1 lg:mr-64 p-3 sm:p-4 md:p-6 lg:p-8 min-w-0 w-full max-w-[1600px]"
    ><?= $content ?></main>
  </div>

  <nav id="dashboard-bottom-nav" class="lg:hidden" aria-label="تنقل سريع">
    <?php foreach ($bottomNav as $route => $item): ?>
      <?php $isActive = $currentRoute === $route; ?>
      <a href="<?= h($route) ?>" data-dashboard-route="<?= h($route) ?>" class="<?= $isActive ? 'is-active' : '' ?>">
        <span class="material-symbols-outlined"><?= h($item['icon']) ?></span>
        <span><?= h($item['label']) ?></span>
      </a>
    <?php endforeach; ?>
    <button type="button" id="dashboard-bottom-menu-btn" aria-label="المزيد">
      <span class="material-symbols-outlined">apps</span>
      <span>المزيد</span>
    </button>
  </nav>

  <script src="/assets/dashboard/dashboard.js" defer></script>
</body>
</html>
