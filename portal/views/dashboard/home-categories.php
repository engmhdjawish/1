<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $categories */
/** @var array<string, mixed> $editCategory */
/** @var string $editId */
/** @var bool $showForm */
/** @var bool $isNew */
/** @var string|null $flash */
/** @var string $flashType */
require __DIR__ . '/partials/material-icon-picker.php';

$showForm = $showForm ?? false;
$isNew = $isNew ?? false;
$editId = trim((string) ($editId ?? ''));
$iconValue = (string) ($editCategory['icon_key'] ?? 'category');
?>

<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
  <div>
    <h1 class="text-xl font-extrabold">فئات الرئيسية</h1>
    <p class="text-sm text-text-muted mt-1">اختصارات سريعة في الصفحة الرئيسية — الاسم، الأيقونة، والرابط.</p>
  </div>
  <?php if (!$showForm): ?>
    <a href="/dashboard/home-categories.php?new=1" class="h-9 px-4 inline-flex items-center rounded-lg bg-primary text-white text-xs font-extrabold hover:brightness-110">فئة جديدة</a>
  <?php endif; ?>
</div>

<?php if ($flash !== null): ?>
  <div class="mb-4 rounded-lg border px-4 py-3 text-sm <?= $flashType === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-red-200 bg-red-50 text-red-800' ?>">
    <?= h($flash) ?>
  </div>
<?php endif; ?>

<?php if ($showForm): ?>
  <?php if ($editId !== ''): ?>
    <form method="post" id="hc-delete-form" class="hidden" data-dashboard-confirm="هل أنت متأكد من حذف هذه الفئة؟">
      <input type="hidden" name="action" value="delete_category">
      <input type="hidden" name="id" value="<?= h($editId) ?>">
    </form>
  <?php endif; ?>

  <form method="post" class="space-y-3 mb-6">
    <input type="hidden" name="action" value="save_category">
    <input type="hidden" name="id" value="<?= h((string) ($editCategory['id'] ?? '')) ?>">

    <article class="bg-white border border-border-subtle rounded-xl p-4">
      <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
        <h2 class="font-bold text-base"><?= $editId !== '' ? 'تعديل الفئة' : 'فئة جديدة' ?></h2>
        <div class="flex flex-wrap items-center gap-2">
          <a href="/dashboard/home-categories.php" class="h-9 px-4 inline-flex items-center rounded-lg border border-border-subtle bg-white text-xs font-bold text-slate-700 hover:bg-slate-50">إلغاء</a>
          <?php if ($editId !== ''): ?>
            <button type="submit" form="hc-delete-form" class="h-9 px-4 rounded-lg border border-red-300 bg-white text-xs font-bold text-red-700 hover:bg-red-50">حذف</button>
          <?php endif; ?>
          <button type="submit" class="h-9 px-5 rounded-lg bg-primary text-white text-xs font-extrabold hover:brightness-110">حفظ</button>
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <label class="text-xs md:col-span-2">
          <span class="text-text-muted block mb-0.5">اسم الفئة *</span>
          <input name="label_ar" required value="<?= h((string) ($editCategory['label_ar'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm" placeholder="مثال: شحاطة">
        </label>
        <div class="text-xs md:col-span-2">
          <?php portal_render_material_icon_picker_field('hc-category-icon', 'icon_key', $iconValue); ?>
        </div>
        <label class="text-xs md:col-span-2">
          <span class="text-text-muted block mb-0.5">الرابط</span>
          <input name="link_url" value="<?= h((string) ($editCategory['link_url'] ?? '/store.php')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm" dir="ltr" placeholder="/store.php أو /store.php#offers">
        </label>
        <label class="text-xs">
          <span class="text-text-muted block mb-0.5">ترتيب العرض</span>
          <input type="number" min="0" name="sort_order" value="<?= h((string) ($editCategory['sort_order'] ?? '0')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm">
        </label>
        <label class="text-xs flex items-center gap-2 pt-6">
          <input type="checkbox" name="is_active" <?= !empty($editCategory['is_active']) ? 'checked' : '' ?> class="rounded border-border-subtle text-primary">
          <span>نشطة في الرئيسية</span>
        </label>
      </div>
    </article>
  </form>
  <?php portal_render_material_icon_picker_modal(); ?>
<?php endif; ?>

<div class="bg-white border border-border-subtle rounded-xl overflow-hidden">
  <table class="w-full text-sm">
    <thead class="bg-slate-50 text-xs text-text-muted">
      <tr>
        <th class="text-start p-3">الفئة</th>
        <th class="text-start p-3">أيقونة</th>
        <th class="text-start p-3">الرابط</th>
        <th class="text-start p-3">ترتيب</th>
        <th class="text-start p-3">الحالة</th>
        <th class="text-start p-3">إجراء</th>
      </tr>
    </thead>
    <tbody>
      <?php if ($categories === []): ?>
        <tr><td colspan="6" class="p-6 text-center text-text-muted">لا توجد فئات بعد.</td></tr>
      <?php else: ?>
        <?php foreach ($categories as $row): ?>
          <tr class="border-t border-border-subtle">
            <td class="p-3 font-bold"><?= h((string) ($row['label_ar'] ?? '')) ?></td>
            <td class="p-3">
              <span class="inline-flex items-center gap-1">
                <span class="material-symbols-outlined text-primary text-base"><?= h((string) ($row['icon_key'] ?? 'category')) ?></span>
                <code class="text-xs"><?= h((string) ($row['icon_key'] ?? '')) ?></code>
              </span>
            </td>
            <td class="p-3"><code class="text-xs" dir="ltr"><?= h((string) ($row['link_url'] ?? '')) ?></code></td>
            <td class="p-3"><?= (int) ($row['sort_order'] ?? 0) ?></td>
            <td class="p-3"><?= !empty($row['is_active']) ? 'نشطة' : 'موقوفة' ?></td>
            <td class="p-3">
              <div class="flex flex-wrap gap-2">
                <a href="/dashboard/home-categories.php?edit=<?= urlencode((string) ($row['id'] ?? '')) ?>" class="h-8 px-3 inline-flex items-center rounded-lg border border-slate-300 bg-white text-xs font-bold">تعديل</a>
                <form method="post" class="inline">
                  <input type="hidden" name="action" value="toggle_category">
                  <input type="hidden" name="id" value="<?= h((string) ($row['id'] ?? '')) ?>">
                  <input type="hidden" name="next_active" value="<?= !empty($row['is_active']) ? '0' : '1' ?>">
                  <button type="submit" class="h-8 px-3 rounded-lg border border-slate-300 bg-white text-xs font-bold"><?= !empty($row['is_active']) ? 'إيقاف' : 'تفعيل' ?></button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>
