<?php

declare(strict_types=1);

/** @var string|null $flash */
/** @var string $flashType */
/** @var string $activeCategory */
/** @var list<array<string, mixed>> $assets */

use Portal\Services\SiteMediaService;

$labels = SiteMediaService::CATEGORY_LABELS;
?>
<section class="flex flex-col md:flex-row justify-between md:items-center gap-3 mb-4">
  <div>
    <h1 class="text-xl font-extrabold text-slate-900">مكتبة صور الموقع</h1>
    <p class="text-sm text-text-muted mt-1">ارفع وصفّ بنرات وإعلانات وشعارات الموقع واستخدمها في الأقسام دون الحاجة لرابط خارجي.</p>
  </div>
  <a href="/dashboard/home-sections.php" class="h-9 px-4 inline-flex items-center rounded-lg border border-border-subtle bg-white text-xs font-bold text-slate-700 hover:bg-slate-50">العودة للأقسام</a>
</section>

<?php if ($flash): ?>
  <p class="mb-3 rounded-lg border px-3 py-2 text-sm <?= $flashType === 'error' ? 'bg-red-50 border-red-200 text-red-700' : 'bg-green-50 border-green-200 text-green-700' ?>">
    <?= h($flash) ?>
  </p>
<?php endif; ?>

<div class="flex flex-wrap gap-2 mb-3">
  <a href="/dashboard/site-media.php" class="h-8 px-3 inline-flex items-center rounded-lg border text-xs font-bold <?= $activeCategory === '' ? 'bg-primary text-white border-primary' : 'border-border-subtle bg-white' ?>">الكل</a>
  <?php foreach (SiteMediaService::CATEGORIES as $category): ?>
    <a href="/dashboard/site-media.php?category=<?= urlencode($category) ?>" class="h-8 px-3 inline-flex items-center rounded-lg border text-xs font-bold <?= $activeCategory === $category ? 'bg-primary text-white border-primary' : 'border-border-subtle bg-white' ?>">
      <?= h($labels[$category] ?? $category) ?>
    </a>
  <?php endforeach; ?>
</div>

<article class="bg-white border border-border-subtle rounded-xl p-3 mb-4">
  <h2 class="font-bold text-sm mb-2">رفع صورة جديدة</h2>
  <form method="post" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-4 gap-2 items-end">
    <input type="hidden" name="action" value="upload">
    <label class="text-xs md:col-span-2">
      <span class="text-text-muted block mb-0.5">الملف</span>
      <input type="file" name="file" required accept="image/jpeg,image/png,image/webp,image/gif,image/svg+xml" class="block w-full text-xs">
    </label>
    <label class="text-xs">
      <span class="text-text-muted block mb-0.5">التصنيف</span>
      <select name="category" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm">
        <?php foreach (SiteMediaService::CATEGORIES as $category): ?>
          <option value="<?= h($category) ?>" <?= $activeCategory === $category ? 'selected' : '' ?>><?= h($labels[$category] ?? $category) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="text-xs">
      <span class="text-text-muted block mb-0.5">عنوان (اختياري)</span>
      <input type="text" name="title_ar" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm">
    </label>
    <button type="submit" class="h-9 px-4 rounded-lg bg-primary text-white text-xs font-bold md:col-span-4 md:justify-self-end">رفع</button>
  </form>
</article>

<section class="bg-white border border-border-subtle rounded-xl p-3">
  <?php if ($assets === []): ?>
    <p class="text-sm text-text-muted text-center py-8">لا توجد صور بعد في هذا التصنيف.</p>
  <?php else: ?>
    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
      <?php foreach ($assets as $asset): ?>
        <article class="rounded-lg border border-border-subtle overflow-hidden bg-surface-low">
          <a href="<?= h((string) ($asset['url'] ?? '')) ?>" target="_blank" class="block aspect-[4/3] bg-white overflow-hidden">
            <img src="<?= h((string) ($asset['url'] ?? '')) ?>" alt="" class="h-full w-full object-cover" loading="lazy">
          </a>
          <div class="p-2 space-y-1">
            <div class="text-xs font-bold truncate"><?= h((string) ($asset['title_ar'] ?? $asset['file_name'] ?? 'صورة')) ?></div>
            <div class="text-[11px] text-text-muted"><?= h((string) ($asset['category_label'] ?? '')) ?></div>
            <div class="text-[10px] text-text-muted break-all"><?= h((string) ($asset['url'] ?? '')) ?></div>
            <form method="post" onsubmit="return confirm('حذف هذه الصورة؟')">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= h((string) ($asset['id'] ?? '')) ?>">
              <input type="hidden" name="redirect_category" value="<?= h($activeCategory) ?>">
              <button type="submit" class="h-7 px-2 rounded border border-red-200 text-[11px] font-bold text-red-700 hover:bg-red-50">حذف</button>
            </form>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
