<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $links */
/** @var array<string, mixed> $filters */
/** @var int $activeCount */
?>
<section class="bg-white border rounded-xl p-5 mb-4">
  <h1 class="text-xl font-bold mb-2">إدارة روابط المشاركة</h1>
  <p class="text-sm text-gray-600">عرض الروابط النشطة والمنتهية مع سياسات الوصول المطبقة.</p>
</section>

<section class="grid gap-3 grid-cols-2 mb-4">
  <article class="bg-white border rounded-lg p-3">
    <div class="text-xs text-gray-500">إجمالي الروابط في الصفحة</div>
    <div class="text-xl font-bold mt-1"><?= count($links) ?></div>
  </article>
  <article class="bg-white border rounded-lg p-3">
    <div class="text-xs text-gray-500">الروابط النشطة</div>
    <div class="text-xl font-bold mt-1 text-green-700"><?= $activeCount ?></div>
  </article>
</section>

<section class="bg-white border rounded-xl p-4 mb-4">
  <form method="get" class="grid md:grid-cols-3 gap-3 items-end">
    <label class="text-sm">
      <span class="text-gray-600">بحث</span>
      <input type="text" name="q" value="<?= h((string) ($filters['q'] ?? '')) ?>" class="mt-1 w-full border rounded px-3 py-2" placeholder="اسم الرابط أو التوكن">
    </label>
    <label class="text-sm">
      <span class="text-gray-600">الحالة</span>
      <select name="active" class="mt-1 w-full border rounded px-3 py-2">
        <option value="">الكل</option>
        <option value="1" <?= ($filters['active'] ?? '') === '1' ? 'selected' : '' ?>>نشط</option>
        <option value="0" <?= ($filters['active'] ?? '') === '0' ? 'selected' : '' ?>>متوقف</option>
      </select>
    </label>
    <button class="bg-primary text-white rounded px-4 py-2">تطبيق</button>
  </form>
</section>

<section class="bg-white border rounded-xl overflow-hidden">
  <?php if ($links === []): ?>
    <p class="p-4 text-sm text-gray-500">لا توجد روابط مطابقة.</p>
  <?php else: ?>
    <div class="overflow-auto">
      <table class="w-full text-sm min-w-[900px]">
        <thead class="bg-gray-50 text-gray-600 border-b">
          <tr>
            <th class="text-right p-3">الاسم</th>
            <th class="text-right p-3">التوكن</th>
            <th class="text-right p-3">السياسة</th>
            <th class="text-right p-3">كلمة مفتاحية</th>
            <th class="text-right p-3">أدنى كمية</th>
            <th class="text-right p-3">الانتهاء</th>
            <th class="text-right p-3">الحالة</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($links as $row): ?>
            <tr class="border-b last:border-0">
              <td class="p-3 font-semibold"><?= h((string) ($row['name_ar'] ?? '')) ?></td>
              <td class="p-3 text-xs"><?= h((string) ($row['public_token'] ?? '')) ?></td>
              <td class="p-3"><?= h((string) ($row['access_policy_name_ar'] ?? '')) ?></td>
              <td class="p-3"><?= h((string) ($row['keyword'] ?? '—')) ?></td>
              <td class="p-3"><?= number_format((float) ($row['min_quantity'] ?? 0), 0, '.', ',') ?></td>
              <td class="p-3 text-xs text-gray-600"><?= h((string) ($row['expires_at'] ?? 'غير محدد')) ?></td>
              <td class="p-3">
                <?php if (!empty($row['is_active'])): ?>
                  <span class="inline-flex rounded-full bg-green-50 text-green-700 px-2 py-1 text-xs">نشط</span>
                <?php else: ?>
                  <span class="inline-flex rounded-full bg-gray-100 text-gray-700 px-2 py-1 text-xs">متوقف</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
