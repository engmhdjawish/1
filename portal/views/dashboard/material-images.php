<?php

declare(strict_types=1);

/** @var array{images_dir: string, thumbnails_dir: string} $paths */
/** @var array{local_count: int, thumbnail_count: int} $stats */
/** @var list<array<string, mixed>> $files */
/** @var string|null $flash */
/** @var string $flashType */
/** @var array<string, string> $settingsForm */

$paths = is_array($paths ?? null) ? $paths : ['images_dir' => '', 'thumbnails_dir' => ''];
$stats = is_array($stats ?? null) ? $stats : ['local_count' => 0, 'thumbnail_count' => 0];
$files = is_array($files ?? null) ? $files : [];
$settingsForm = is_array($settingsForm ?? null) ? $settingsForm : [];
?>
<section class="mb-6">
  <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
    <div>
      <h1 class="text-2xl font-extrabold">صور المواد المحلية</h1>
      <p class="text-sm text-text-muted mt-1">ارفع صور المواد بنفس أسماء ملفات الأمين. عند العرض يُجلب اسم الملف من API الأمين ثم تُقرأ النسخة المحلية.</p>
    </div>
    <div class="flex flex-wrap gap-2 text-xs">
      <span class="inline-flex items-center gap-1 rounded-full px-3 py-1.5 border border-border-subtle bg-white">
        محلي: <strong><?= (int) ($stats['local_count'] ?? 0) ?></strong>
      </span>
      <span class="inline-flex items-center gap-1 rounded-full px-3 py-1.5 border border-border-subtle bg-white">
        ثامبنيل: <strong><?= (int) ($stats['thumbnail_count'] ?? 0) ?></strong>
      </span>
    </div>
  </div>
</section>

<?php if (!empty($flash)): ?>
  <p class="mb-4 rounded-xl border px-4 py-3 text-sm <?= ($flashType ?? 'success') === 'error' ? 'bg-red-50 border-red-200 text-red-700' : 'bg-green-50 border-green-200 text-green-700' ?>">
    <?= h((string) $flash) ?>
  </p>
<?php endif; ?>

<section class="grid gap-4 lg:grid-cols-2 mb-6">
  <article class="rounded-xl border border-border-subtle bg-white p-4">
    <h2 class="font-bold mb-2">مسارات التخزين</h2>
    <p class="text-xs text-text-muted mb-3">اترك الحقول فارغة لاستخدام المجلد الافتراضي داخل <code dir="ltr">portal/storage/material-images</code>.</p>
    <form method="post" class="space-y-3">
      <input type="hidden" name="action" value="save_settings">
      <label class="text-xs block">
        <span class="text-text-muted">مجلد الصور الأصلية</span>
        <input name="material_images_dir" value="<?= h((string) ($settingsForm['material_images_dir'] ?? '')) ?>" class="mt-1 h-9 w-full rounded-lg border border-border-subtle px-3 text-sm font-mono" dir="ltr" placeholder="D:\site\media\materials">
      </label>
      <label class="text-xs block">
        <span class="text-text-muted">مجلد الثامبنيل</span>
        <input name="material_thumbnails_dir" value="<?= h((string) ($settingsForm['material_thumbnails_dir'] ?? '')) ?>" class="mt-1 h-9 w-full rounded-lg border border-border-subtle px-3 text-sm font-mono" dir="ltr" placeholder="D:\site\media\thumbs">
      </label>
      <button class="h-9 px-4 rounded-lg bg-primary text-white text-xs font-bold">حفظ المسارات</button>
    </form>
    <dl class="mt-4 text-[11px] text-text-muted space-y-1">
      <div><dt class="inline font-bold">المسار الفعلي للصور:</dt> <dd class="inline font-mono" dir="ltr"><?= h((string) ($paths['images_dir'] ?? '')) ?></dd></div>
      <div><dt class="inline font-bold">المسار الفعلي للثامبنيل:</dt> <dd class="inline font-mono" dir="ltr"><?= h((string) ($paths['thumbnails_dir'] ?? '')) ?></dd></div>
    </dl>
  </article>

  <article class="rounded-xl border border-border-subtle bg-white p-4">
    <h2 class="font-bold mb-2">رفع صور جديدة</h2>
    <p class="text-xs text-text-muted mb-3">اختر ملفاً أو أكثر. يُحفظ <strong>بنفس اسم الملف</strong> ويُستبدل الموجود. الامتدادات: JPG, PNG, GIF, WebP.</p>
    <form method="post" enctype="multipart/form-data" class="space-y-3">
      <input type="hidden" name="action" value="upload">
      <input type="file" name="files[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple required class="block w-full text-sm">
      <button class="h-9 px-4 rounded-lg bg-primary text-white text-xs font-bold">رفع واستبدال</button>
    </form>
  </article>
</section>

<section class="rounded-xl border border-border-subtle bg-white overflow-hidden">
  <div class="px-4 py-3 border-b border-border-subtle bg-surface-low/60 flex items-center justify-between">
    <h2 class="font-bold">الملفات المحلية</h2>
    <span class="text-xs text-text-muted"><?= count($files) ?> ملف</span>
  </div>
  <?php if ($files === []): ?>
    <p class="p-4 text-sm text-text-muted">لا توجد صور محلية بعد. ارفع ملفات بنفس أسماء صور الأمين.</p>
  <?php else: ?>
    <div class="overflow-auto">
      <table class="w-full text-sm min-w-[900px]">
        <thead class="text-text-muted border-b border-border-subtle">
          <tr>
            <th class="text-right p-3">معاينة</th>
            <th class="text-right p-3">اسم الملف</th>
            <th class="text-right p-3">الحجم</th>
            <th class="text-right p-3">آخر تعديل</th>
            <th class="text-right p-3">ثامبنيل</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($files as $file): ?>
            <tr class="border-b border-border-subtle last:border-0">
              <td class="p-3">
                <img src="<?= h((string) ($file['preview_url'] ?? '')) ?>" alt="" class="w-12 h-12 rounded-lg object-cover bg-surface-low border border-border-subtle" loading="lazy">
              </td>
              <td class="p-3 font-mono text-xs" dir="ltr"><?= h((string) ($file['file_name'] ?? '')) ?></td>
              <td class="p-3"><?= h(number_format(((int) ($file['size_bytes'] ?? 0)) / 1024, 1)) ?> KB</td>
              <td class="p-3 text-text-muted"><?= h((string) ($file['modified_at'] ?? '')) ?></td>
              <td class="p-3"><?= !empty($file['has_thumbnail']) ? 'نعم' : '—' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
