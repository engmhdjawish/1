<?php

declare(strict_types=1);

/** @var string $title */
/** @var string $content */
/** @var array<string, mixed>|null $user */
/** @var string|null $currentRoute */

$currentRoute ??= parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '/dashboard/index.php';
$navigation = [
    'الإدارة' => [
        '/dashboard/index.php' => 'لوحة التحكم',
        '/dashboard/customers.php' => 'إدارة العملاء',
        '/dashboard/orders.php' => 'إدارة الطلبات',
        '/dashboard/share-links.php' => 'روابط المشاركة',
        '/dashboard/home-sections.php' => 'أقسام الرئيسية',
        '/dashboard/users.php' => 'المستخدمون والأدوار',
        '/dashboard/settings.php' => 'الإعدادات',
    ],
    'المحاسبة' => [
        '/dashboard/accounting.php' => 'لوحة المحاسب',
        '/dashboard/accounting-sync.php' => 'طابور المزامنة',
        '/dashboard/accounting-reports.php' => 'التقارير المالية',
        '/dashboard/accounting-statement.php' => 'كشف حساب عميل',
    ],
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($title) ?> — لوحة التحكم</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: { extend: { colors: { primary: '#D81921' } } }
    };
  </script>
</head>
<body class="bg-gray-100 min-h-screen">
  <header class="bg-white border-b px-4 py-3">
    <div class="max-w-7xl mx-auto flex items-center justify-between gap-4">
      <div class="flex items-center gap-3">
        <strong class="text-primary">لوحة التحكم</strong>
        <a href="/index.php" class="text-xs text-gray-500 hover:text-primary">عرض الموقع</a>
      </div>
      <div class="flex items-center gap-3">
        <span class="text-sm text-gray-600"><?= h($user['display_name_ar'] ?? '') ?></span>
        <a href="/logout.php" class="text-sm text-red-600">خروج</a>
      </div>
    </div>
  </header>
  <div class="max-w-7xl mx-auto grid grid-cols-1 md:grid-cols-[260px_1fr] gap-4 p-4">
    <aside class="bg-white border rounded-xl p-4 text-sm space-y-4 h-fit">
      <?php foreach ($navigation as $groupTitle => $items): ?>
        <section>
          <h3 class="text-xs text-gray-500 mb-2"><?= h($groupTitle) ?></h3>
          <div class="space-y-1">
            <?php foreach ($items as $route => $label): ?>
              <?php $isActive = $currentRoute === $route; ?>
              <a
                href="<?= h($route) ?>"
                class="block rounded px-2 py-1.5 transition <?= $isActive ? 'bg-red-50 text-primary font-semibold' : 'text-gray-700 hover:bg-gray-50' ?>"
              >
                <?= h($label) ?>
              </a>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endforeach; ?>
    </aside>
    <main class="min-w-0"><?= $content ?></main>
  </div>
</body>
</html>
