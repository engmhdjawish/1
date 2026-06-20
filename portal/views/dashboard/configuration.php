<?php

declare(strict_types=1);

/** @var array<string, list<array<string, mixed>>> $configurationGroups */
?>
<section class="mb-6">
  <div class="flex flex-wrap items-start justify-between gap-3">
    <div>
      <h1 class="text-2xl font-extrabold text-slate-900">الإعدادات والتهيئة</h1>
      <p class="text-sm text-text-muted mt-1 max-w-2xl">
        إعدادات لا تُستخدم يومياً: محتوى الموقع، صلاحيات الموظفين، وبيانات الشركة. ارجع إلى
        <a href="/dashboard/index.php" class="text-primary font-bold hover:underline">لوحة العمل</a>
        للمهام اليومية.
      </p>
    </div>
    <a href="/dashboard/index.php" class="h-10 px-4 inline-flex items-center gap-2 rounded-xl border border-border-subtle bg-white text-sm font-bold text-slate-700 hover:bg-slate-50">
      <span class="material-symbols-outlined text-lg">arrow_forward</span>
      العودة للعمل اليومي
    </a>
  </div>
</section>

<?php foreach ($configurationGroups as $groupTitle => $items): ?>
  <section class="mb-8">
    <h2 class="text-sm font-bold text-text-muted mb-3 px-1"><?= h($groupTitle) ?></h2>
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
      <?php foreach ($items as $item): ?>
        <a
          href="<?= h((string) ($item['route'] ?? '#')) ?>"
          class="group bg-white border border-border-subtle rounded-2xl p-5 hover:border-primary/30 hover:shadow-md transition flex flex-col gap-3 no-underline text-inherit"
        >
          <div class="flex items-center gap-3">
            <span class="w-11 h-11 rounded-xl bg-surface-low text-primary flex items-center justify-center group-hover:bg-primary/10 transition">
              <span class="material-symbols-outlined"><?= h((string) ($item['icon'] ?? 'settings')) ?></span>
            </span>
            <div>
              <h3 class="font-bold text-slate-900"><?= h((string) ($item['label'] ?? '')) ?></h3>
              <p class="text-xs text-text-muted mt-0.5">إعدادات</p>
            </div>
          </div>
          <?php if (!empty($item['description'])): ?>
            <p class="text-sm text-text-muted leading-relaxed"><?= h((string) $item['description']) ?></p>
          <?php endif; ?>
          <span class="text-sm text-primary font-bold mt-auto inline-flex items-center gap-1">
            فتح
            <span class="material-symbols-outlined text-base">chevron_left</span>
          </span>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
<?php endforeach; ?>
