<?php

declare(strict_types=1);

/** @var string $title */
/** @var string $content */
/** @var array<string, mixed>|null $user */
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($title) ?> — لوحة التحكم</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config = { theme: { extend: { colors: { primary: '#D81921' } } } } };</script>
</head>
<body class="bg-gray-100 min-h-screen">
  <header class="bg-white border-b px-4 py-3 flex justify-between items-center">
    <strong class="text-primary">لوحة التحكم</strong>
    <span class="text-sm text-gray-600"><?= h($user['display_name_ar'] ?? '') ?></span>
    <a href="/logout.php" class="text-sm text-red-600">خروج</a>
  </header>
  <div class="flex max-w-6xl mx-auto">
    <aside class="w-56 bg-white border-l min-h-screen p-4 text-sm space-y-2">
      <a href="/dashboard/index.php" class="block hover:text-primary">الرئيسية</a>
      <a href="/dashboard/customers.php" class="block hover:text-primary">عملاء الويب</a>
    </aside>
    <main class="flex-1 p-6"><?= $content ?></main>
  </div>
</body>
</html>
