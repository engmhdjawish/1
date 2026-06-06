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
        '/dashboard/material-images.php' => ['label' => 'صور المواد', 'icon' => 'inventory_2'],
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
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($title) ?> — لوحة التحكم</title>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
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
<body class="min-h-screen text-slate-900">
  <header class="sticky top-0 z-50 h-16 bg-surface-white shadow-sm border-b border-border-subtle">
    <div class="h-full px-4 lg:px-10 flex items-center justify-between gap-4">
      <div class="flex items-center gap-4">
        <span class="font-extrabold text-primary text-lg">Jawish Trading</span>
        <nav class="hidden lg:flex items-center gap-4 text-sm">
          <a href="/dashboard/index.php" class="<?= $currentRoute === '/dashboard/index.php' ? 'text-primary font-bold border-b-2 border-primary pb-1' : 'text-text-muted hover:text-primary' ?>">لوحة التحكم</a>
          <a href="/dashboard/orders.php" class="<?= $currentRoute === '/dashboard/orders.php' ? 'text-primary font-bold border-b-2 border-primary pb-1' : 'text-text-muted hover:text-primary' ?>">الطلبات</a>
          <a href="/dashboard/customers.php" class="<?= $currentRoute === '/dashboard/customers.php' ? 'text-primary font-bold border-b-2 border-primary pb-1' : 'text-text-muted hover:text-primary' ?>">العملاء</a>
        </nav>
      </div>
      <div class="flex items-center gap-3">
        <div class="hidden md:flex flex-col items-end">
          <span class="text-sm font-bold"><?= h($user['display_name_ar'] ?? '') ?></span>
          <span class="text-xs text-text-muted">مدير النظام</span>
        </div>
        <a href="/logout.php" class="inline-flex items-center justify-center w-9 h-9 rounded-full hover:bg-red-50 text-red-600 transition">
          <span class="material-symbols-outlined">logout</span>
        </a>
      </div>
    </div>
  </header>
  <div class="flex">
    <aside class="hidden lg:flex fixed top-16 right-0 h-[calc(100vh-64px)] w-64 bg-surface-white border-l border-border-subtle flex-col p-4 z-40">
      <div class="px-2 py-4 border-b border-border-subtle mb-4">
        <h2 class="font-bold text-primary">لوحة التحكم</h2>
        <p class="text-xs text-text-muted mt-1">نظام إدارة البوابة</p>
      </div>
      <?php foreach ($navigation as $groupTitle => $items): ?>
        <section class="mb-4">
          <h3 class="text-xs text-text-muted mb-2 px-2"><?= h($groupTitle) ?></h3>
          <div class="space-y-1">
            <?php foreach ($items as $route => $item): ?>
              <?php $isActive = $currentRoute === $route; ?>
              <a
                href="<?= h($route) ?>"
                class="flex items-center gap-3 rounded-lg px-3 py-2.5 transition <?= $isActive ? 'bg-primary/10 text-primary font-bold border-r-4 border-primary' : 'text-text-muted hover:bg-surface-low' ?>"
              >
                <span class="material-symbols-outlined <?= $isActive ? 'fill' : '' ?>"><?= h($item['icon']) ?></span>
                <span class="text-sm"><?= h($item['label']) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endforeach; ?>
      <div class="mt-auto pt-4 border-t border-border-subtle">
        <a href="/index.php" class="flex items-center justify-center gap-2 bg-primary text-white rounded-xl py-2.5 font-bold hover:brightness-110 transition">
          <span class="material-symbols-outlined">public</span>
          عرض الموقع
        </a>
      </div>
    </aside>
    <main class="flex-1 lg:mr-64 p-4 md:p-6 lg:p-8 min-w-0"><?= $content ?></main>
  </div>
</body>
</html>
