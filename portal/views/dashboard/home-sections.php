<?php

declare(strict_types=1);

/** @var array{total: int, active: int, manual: int, filter: int} $stats */
/** @var list<array<string, mixed>> $sections */
/** @var array<string, mixed>|null $editSection */
/** @var string $editId */
/** @var string|null $flash */
/** @var string $flashType */

$filterTypeLabels = [
    'keyword' => 'كلمة بحث',
    'material_type' => 'نوع المادة',
    'manufacturer' => 'الشركة المصنعة',
    'target_category' => 'فئة مستهدفة',
    'age_category' => 'فئة عمرية',
];
?>
<section class="flex flex-col md:flex-row justify-between md:items-center gap-4 mb-6">
  <div>
    <h1 class="text-2xl font-extrabold text-slate-900">إدارة أقسام الصفحة الرئيسية</h1>
    <p class="text-sm text-text-muted mt-1">ضبط البانرات وترتيب الأقسام وقواعد اختيار المنتجات للواجهة العامة.</p>
  </div>
  <div class="flex flex-wrap gap-3">
    <article class="bg-white border border-border-subtle rounded-xl px-4 py-3 min-w-24 text-center">
      <p class="text-xl font-extrabold"><?= (int) $stats['total'] ?></p>
      <p class="text-xs text-text-muted">إجمالي الأقسام</p>
    </article>
    <article class="bg-white border border-border-subtle rounded-xl px-4 py-3 min-w-24 text-center">
      <p class="text-xl font-extrabold text-green-700"><?= (int) $stats['active'] ?></p>
      <p class="text-xs text-text-muted">أقسام نشطة</p>
    </article>
    <article class="bg-white border border-border-subtle rounded-xl px-4 py-3 min-w-24 text-center">
      <p class="text-xl font-extrabold text-blue-700"><?= (int) $stats['filter'] ?></p>
      <p class="text-xs text-text-muted">وضع filter</p>
    </article>
  </div>
</section>

<?php if ($flash): ?>
  <p class="mb-4 rounded-xl border px-4 py-3 text-sm <?= $flashType === 'error' ? 'bg-red-50 border-red-200 text-red-700' : 'bg-green-50 border-green-200 text-green-700' ?>">
    <?= h($flash) ?>
  </p>
<?php endif; ?>

<section class="grid grid-cols-1 xl:grid-cols-[1fr_430px] gap-5 mb-6">
  <article class="bg-white border border-border-subtle rounded-2xl p-5">
    <div class="flex items-center justify-between mb-4">
      <h2 class="font-bold text-lg"><?= $editId !== '' ? 'تعديل القسم' : 'إضافة قسم جديد' ?></h2>
      <?php if ($editId !== ''): ?>
        <a href="/dashboard/home-sections.php" class="text-sm text-text-muted hover:text-primary">إلغاء التعديل</a>
      <?php endif; ?>
    </div>
    <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-3">
      <input type="hidden" name="action" value="save_section">
      <input type="hidden" name="id" value="<?= h((string) ($editSection['id'] ?? '')) ?>">
      <label class="text-sm md:col-span-2">
        <span class="text-text-muted block mb-1">عنوان القسم</span>
        <input name="title_ar" required value="<?= h((string) ($editSection['title_ar'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary" placeholder="مثال: وصلنا حديثًا">
      </label>
      <label class="text-sm">
        <span class="text-text-muted block mb-1">Slug</span>
        <input name="slug" value="<?= h((string) ($editSection['slug'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary" placeholder="new-arrivals">
      </label>
      <label class="text-sm">
        <span class="text-text-muted block mb-1">ترتيب العرض</span>
        <input type="number" min="0" name="sort_order" value="<?= h((string) ($editSection['sort_order'] ?? '0')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary">
      </label>
      <label class="text-sm md:col-span-2">
        <span class="text-text-muted block mb-1">وصف مختصر</span>
        <input name="subtitle_ar" value="<?= h((string) ($editSection['subtitle_ar'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary" placeholder="أفضل المنتجات الجديدة لهذا الأسبوع">
      </label>
      <label class="text-sm md:col-span-2">
        <span class="text-text-muted block mb-1">رابط صورة البانر</span>
        <input name="banner_image_url" value="<?= h((string) ($editSection['banner_image_url'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary" placeholder="https://.../banner.jpg">
      </label>
      <label class="text-sm">
        <span class="text-text-muted block mb-1">وضع العرض</span>
        <select name="display_mode" class="h-11 w-full rounded-xl border border-border-subtle px-3 focus:border-primary focus:ring-primary">
          <option value="filter" <?= (($editSection['display_mode'] ?? 'filter') === 'filter') ? 'selected' : '' ?>>فلترة تلقائية</option>
          <option value="manual" <?= (($editSection['display_mode'] ?? '') === 'manual') ? 'selected' : '' ?>>اختيار يدوي</option>
        </select>
      </label>
      <label class="text-sm">
        <span class="text-text-muted block mb-1">عدد المنتجات</span>
        <input type="number" min="1" max="48" name="max_products" value="<?= h((string) ($editSection['max_products'] ?? '12')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary">
      </label>
      <label class="text-sm md:col-span-2 inline-flex items-center gap-2">
        <input type="checkbox" name="is_active" <?= $editSection === null || !empty($editSection['is_active']) ? 'checked' : '' ?> class="rounded border-border-subtle text-primary focus:ring-primary">
        <span>القسم نشط على الصفحة الرئيسية</span>
      </label>
      <div class="md:col-span-2 flex justify-end">
        <button class="h-11 px-6 rounded-xl bg-primary text-white font-bold hover:brightness-110 transition">
          <?= $editId !== '' ? 'حفظ التعديلات' : 'إضافة القسم' ?>
        </button>
      </div>
    </form>
  </article>

  <article class="bg-white border border-border-subtle rounded-2xl p-5">
    <h3 class="font-bold mb-3">إدارة الفلاتر</h3>
    <?php if ($editSection === null): ?>
      <p class="text-sm text-text-muted">اختر قسمًا من القائمة (تعديل) لإضافة فلاتر مخصصة له.</p>
    <?php else: ?>
      <p class="text-sm text-text-muted mb-3">
        الفلاتر الحالية للقسم: <strong><?= h((string) ($editSection['title_ar'] ?? '')) ?></strong>
      </p>
      <div class="flex flex-wrap gap-2 mb-4">
        <?php foreach (($editSection['filters'] ?? []) as $filter): ?>
          <form method="post" class="inline-flex items-center gap-1 bg-surface-low rounded-full px-3 py-1 text-xs">
            <input type="hidden" name="action" value="remove_filter">
            <input type="hidden" name="filter_id" value="<?= h((string) ($filter['id'] ?? '')) ?>">
            <input type="hidden" name="section_id" value="<?= h((string) ($editSection['id'] ?? '')) ?>">
            <span><?= h($filterTypeLabels[(string) ($filter['filter_type'] ?? '')] ?? (string) ($filter['filter_type'] ?? 'فلتر')) ?>:</span>
            <strong><?= h((string) ($filter['value_ar'] ?? '')) ?></strong>
            <button class="text-red-600 hover:text-red-700">×</button>
          </form>
        <?php endforeach; ?>
        <?php if (($editSection['filters'] ?? []) === []): ?>
          <span class="text-xs text-text-muted">لا توجد فلاتر حتى الآن.</span>
        <?php endif; ?>
      </div>
      <form method="post" class="grid grid-cols-1 gap-3">
        <input type="hidden" name="action" value="add_filter">
        <input type="hidden" name="section_id" value="<?= h((string) ($editSection['id'] ?? '')) ?>">
        <label class="text-sm">
          <span class="text-text-muted block mb-1">نوع الفلتر</span>
          <select name="filter_type" class="h-10 w-full rounded-xl border border-border-subtle px-3 focus:border-primary focus:ring-primary">
            <?php foreach ($filterTypeLabels as $key => $label): ?>
              <option value="<?= h($key) ?>"><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="text-sm">
          <span class="text-text-muted block mb-1">قيمة الفلتر</span>
          <input name="value_ar" class="h-10 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary" placeholder="مثال: رجالي">
        </label>
        <button class="h-10 rounded-xl bg-primary text-white font-bold hover:brightness-110 transition">إضافة فلتر</button>
      </form>
    <?php endif; ?>
  </article>
</section>

<section class="bg-white border border-border-subtle rounded-2xl overflow-hidden">
  <?php if ($sections === []): ?>
    <p class="p-6 text-sm text-text-muted text-center">لا توجد أقسام بعد.</p>
  <?php else: ?>
    <div class="overflow-auto">
      <table class="w-full min-w-[980px] text-sm">
        <thead class="bg-surface-low border-b border-border-subtle text-text-muted">
          <tr>
            <th class="px-5 py-4 text-right font-bold">العنوان</th>
            <th class="px-5 py-4 text-right font-bold">Slug</th>
            <th class="px-5 py-4 text-right font-bold">الوضع</th>
            <th class="px-5 py-4 text-right font-bold">الفلاتر</th>
            <th class="px-5 py-4 text-right font-bold">المنتجات اليدوية</th>
            <th class="px-5 py-4 text-right font-bold">الترتيب</th>
            <th class="px-5 py-4 text-right font-bold">الحالة</th>
            <th class="px-5 py-4 text-left font-bold">إجراءات</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border-subtle">
          <?php foreach ($sections as $section): ?>
            <tr class="hover:bg-slate-50 transition">
              <td class="px-5 py-4">
                <div class="font-bold"><?= h((string) ($section['title_ar'] ?? '')) ?></div>
                <div class="text-xs text-text-muted mt-1"><?= h((string) ($section['subtitle_ar'] ?? '')) ?></div>
              </td>
              <td class="px-5 py-4 font-mono text-xs"><?= h((string) ($section['slug'] ?? '')) ?></td>
              <td class="px-5 py-4"><?= h((string) ($section['display_mode'] ?? '')) ?></td>
              <td class="px-5 py-4"><?= (int) ($section['filters_count'] ?? 0) ?></td>
              <td class="px-5 py-4"><?= (int) ($section['products_count'] ?? 0) ?></td>
              <td class="px-5 py-4"><?= (int) ($section['sort_order'] ?? 0) ?></td>
              <td class="px-5 py-4">
                <?php if (!empty($section['is_active'])): ?>
                  <span class="inline-flex rounded-full bg-green-100 text-green-700 px-3 py-1 text-xs font-bold">نشط</span>
                <?php else: ?>
                  <span class="inline-flex rounded-full bg-slate-100 text-slate-700 px-3 py-1 text-xs font-bold">متوقف</span>
                <?php endif; ?>
              </td>
              <td class="px-5 py-4">
                <div class="flex items-center justify-end gap-2">
                  <a href="/dashboard/home-sections.php?edit=<?= urlencode((string) $section['id']) ?>" class="h-9 px-3 rounded-lg border border-border-subtle text-xs text-text-muted bg-white hover:bg-surface-low">تعديل</a>
                  <form method="post">
                    <input type="hidden" name="action" value="toggle_section">
                    <input type="hidden" name="id" value="<?= h((string) $section['id']) ?>">
                    <input type="hidden" name="next_active" value="<?= !empty($section['is_active']) ? '0' : '1' ?>">
                    <button class="h-9 px-3 rounded-lg text-xs font-bold <?= !empty($section['is_active']) ? 'bg-slate-800 text-white' : 'bg-primary text-white' ?>">
                      <?= !empty($section['is_active']) ? 'إيقاف' : 'تفعيل' ?>
                    </button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
