<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $sections */
?>
<div class="space-y-10">
  <section class="bg-white rounded-xl p-6 shadow-sm border border-gray-100">
    <h1 class="text-2xl font-bold mb-2">مرحباً بكم في جاويش للتجارة</h1>
    <p class="text-gray-600">تصفّح الأقسام أدناه أو ادخل <a href="/store.php" class="text-primary font-semibold">المتجر العام</a>.</p>
  </section>

  <?php foreach ($sections as $section): ?>
    <section class="bg-white rounded-xl p-6 shadow-sm border border-gray-100">
      <div class="flex items-center justify-between mb-4">
        <h2 class="text-xl font-bold"><?= h($section['title_ar']) ?></h2>
        <?php if (!empty($section['subtitle_ar'])): ?>
          <span class="text-sm text-gray-500"><?= h($section['subtitle_ar']) ?></span>
        <?php endif; ?>
      </div>
      <?php $products = $section['products'] ?? []; ?>
      <?php if ($products === []): ?>
        <p class="text-gray-500 text-sm">لا توجد منتجات معروضة (فعّل القسم من لوحة التحكم أو تحقق من اتصال API).</p>
      <?php else: ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          <?php foreach ($products as $item): ?>
            <article class="border border-gray-100 rounded-lg p-3">
              <div class="font-semibold text-sm mb-1"><?= h($item['name'] ?? $item['code'] ?? '-') ?></div>
              <div class="text-xs text-gray-500"><?= h($item['code'] ?? '') ?></div>
              <?php if (isset($item['unitSalePriceSyp'])): ?>
                <div class="mt-2 text-primary font-bold"><?= format_money((float) $item['unitSalePriceSyp'], true) ?> ل.س</div>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>
</div>
