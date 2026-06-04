<?php

declare(strict_types=1);

/** @var string $title */
/** @var string $content */
?>
<!DOCTYPE html>
<html class="light" lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($title) ?> — جاويش للتجارة</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700&display=swap" rel="stylesheet">
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
      .site-link:hover { color: #D81921; }
      .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
    </style>
</head>
<body class="min-h-screen text-text-main bg-surface-bg">
<header class="bg-surface-card border-b border-gray-200 sticky top-0 z-20">
  <div class="max-w-6xl mx-auto px-4 py-3 flex items-center justify-between gap-4">
    <a href="/index.php" class="font-bold text-primary text-lg">جاويش للتجارة</a>
    <nav class="flex flex-wrap gap-3 text-sm font-medium">
      <a href="/index.php" class="site-link">الرئيسية</a>
      <a href="/store.php" class="site-link">المتجر</a>
      <a href="/login.php" class="site-link">دخول</a>
      <a href="/register.php" class="site-link">تسجيل عميل</a>
      <a href="/dashboard/index.php" class="site-link">لوحة التحكم</a>
    </nav>
  </div>
</header>
<main class="max-w-6xl mx-auto px-4 py-8">
  <?= $content ?>
</main>
</body>
</html>
