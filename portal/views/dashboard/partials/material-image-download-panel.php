<?php

declare(strict_types=1);

/** @var array<string, mixed> $materialFilterOptions */
/** @var string|null $materialFilterOptionsError */
/** @var list<array<string, mixed>> $invoiceTypes */
/** @var string|null $invoiceTypesError */
?>
<div data-material-images-download-panel>
  <p class="mb-6 text-sm text-text-muted max-w-3xl leading-relaxed">
    حمّل صور الأصناف كملف ZIP مضغوط — مناسب للمشاركة على واتساب. التحميل يتم ببث مباشر دون تحميل كامل الملف في ذاكرة السيرفر.
  </p>

  <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
    <section class="rounded-2xl border border-border-subtle bg-white overflow-hidden">
      <div class="px-4 py-3 border-b border-border-subtle bg-surface-low/60">
        <h2 class="font-bold">تحميل حسب فلاتر المواد</h2>
        <p class="text-xs text-text-muted mt-1">نفس فلاتر المتجر: مجموعة، مصنع، نوع، مخزن...</p>
      </div>
      <form class="p-4 space-y-3" method="get" action="/api/material-images-zip.php" target="_blank">
        <input type="hidden" name="mode" value="materials">

        <label class="block text-sm">
          <span class="font-bold text-slate-700">بحث</span>
          <input type="search" name="search" class="mt-1 h-10 w-full rounded-lg border border-border-subtle px-3 text-sm" placeholder="رمز أو اسم المادة">
        </label>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <label class="block text-sm">
            <span class="font-bold text-slate-700">نوع المادة</span>
            <select name="materialType" class="mt-1 h-10 w-full rounded-lg border border-border-subtle px-3 text-sm">
              <option value="">الكل</option>
              <?php foreach (($materialFilterOptions['materialTypes'] ?? []) as $option): ?>
                <option value="<?= h((string) $option) ?>"><?= h((string) $option) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="block text-sm">
            <span class="font-bold text-slate-700">الفئة العمرية</span>
            <select name="ageCategory" class="mt-1 h-10 w-full rounded-lg border border-border-subtle px-3 text-sm">
              <option value="">الكل</option>
              <?php foreach (($materialFilterOptions['ageCategories'] ?? []) as $option): ?>
                <option value="<?= h((string) $option) ?>"><?= h((string) $option) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="block text-sm">
            <span class="font-bold text-slate-700">الشركة المصنعة</span>
            <select name="manufacturer" class="mt-1 h-10 w-full rounded-lg border border-border-subtle px-3 text-sm">
              <option value="">الكل</option>
              <?php foreach (($materialFilterOptions['manufacturers'] ?? []) as $option): ?>
                <option value="<?= h((string) $option) ?>"><?= h((string) $option) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="block text-sm">
            <span class="font-bold text-slate-700">بلد المنشأ</span>
            <select name="countryOfOrigin" class="mt-1 h-10 w-full rounded-lg border border-border-subtle px-3 text-sm">
              <option value="">الكل</option>
              <?php foreach (($materialFilterOptions['countryOfOrigins'] ?? []) as $option): ?>
                <option value="<?= h((string) $option) ?>"><?= h((string) $option) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>

        <label class="block text-sm">
          <span class="font-bold text-slate-700">المجموعة</span>
          <select name="groupGuid" class="mt-1 h-10 w-full rounded-lg border border-border-subtle px-3 text-sm">
            <option value="">الكل</option>
            <?php foreach (($materialFilterOptions['groups'] ?? []) as $group): ?>
              <?php if (!is_array($group)) continue; ?>
              <option value="<?= h((string) ($group['guid'] ?? $group['Guid'] ?? '')) ?>">
                <?= h((string) ($group['name'] ?? $group['Name'] ?? '')) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <label class="block text-sm">
          <span class="font-bold text-slate-700">المخزن</span>
          <select name="storeGuid" class="mt-1 h-10 w-full rounded-lg border border-border-subtle px-3 text-sm">
            <option value="">الكل</option>
            <?php foreach (($materialFilterOptions['stores'] ?? []) as $store): ?>
              <?php if (!is_array($store)) continue; ?>
              <option value="<?= h((string) ($store['guid'] ?? $store['Guid'] ?? '')) ?>">
                <?= h((string) ($store['name'] ?? $store['Name'] ?? '')) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <?php if (!empty($materialFilterOptionsError)): ?>
          <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2"><?= h((string) $materialFilterOptionsError) ?></p>
        <?php endif; ?>

        <button type="submit" class="h-11 px-5 rounded-xl bg-primary text-white text-sm font-bold inline-flex items-center gap-2">
          <span class="material-symbols-outlined text-lg">download</span>
          تحميل ZIP للنتائج
        </button>
      </form>
    </section>

    <section class="rounded-2xl border border-border-subtle bg-white overflow-hidden">
      <div class="px-4 py-3 border-b border-border-subtle bg-surface-low/60">
        <h2 class="font-bold">تحميل صور فاتورة أمين</h2>
        <p class="text-xs text-text-muted mt-1">حدّد نوع الفاتورة ورقمها لسحب صور أصنافها — مثالي للواتساب</p>
      </div>
      <form class="p-4 space-y-3" method="get" action="/api/material-images-zip.php" target="_blank">
        <input type="hidden" name="mode" value="invoice">

        <label class="block text-sm">
          <span class="font-bold text-slate-700">نوع الفاتورة</span>
          <select name="typeGuid" class="mt-1 h-10 w-full rounded-lg border border-border-subtle px-3 text-sm" required>
            <option value="">اختر النوع</option>
            <?php foreach ($invoiceTypes as $type): ?>
              <?php if (!is_array($type)) continue; ?>
              <option value="<?= h((string) ($type['guid'] ?? $type['typeGuid'] ?? '')) ?>">
                <?= h((string) ($type['name'] ?? $type['typeName'] ?? $type['code'] ?? 'نوع')) ?>
                <?php if (!empty($type['count'])): ?> (<?= (int) $type['count'] ?>)<?php endif; ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <label class="block text-sm">
          <span class="font-bold text-slate-700">رقم الفاتورة</span>
          <input type="number" name="number" min="1" required class="mt-1 h-10 w-full rounded-lg border border-border-subtle px-3 text-sm" placeholder="مثال: 1523">
        </label>

        <?php if (!empty($invoiceTypesError)): ?>
          <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2"><?= h((string) $invoiceTypesError) ?></p>
        <?php endif; ?>

        <button type="submit" class="h-11 px-5 rounded-xl bg-primary text-white text-sm font-bold inline-flex items-center gap-2">
          <span class="material-symbols-outlined text-lg">receipt_long</span>
          تحميل صور الفاتورة
        </button>
      </form>
    </section>
  </div>

  <section class="mt-6 rounded-2xl border border-border-subtle bg-white overflow-hidden">
    <div class="px-4 py-3 border-b border-border-subtle bg-surface-low/60">
      <h2 class="font-bold">تحميل سريع</h2>
    </div>
    <div class="p-4 flex flex-wrap gap-3">
      <a href="/api/material-images-zip.php?mode=linked&amp;linked=true" target="_blank" class="h-10 px-4 rounded-xl border border-border-subtle bg-white text-sm font-bold inline-flex items-center gap-2 hover:bg-surface-low">
        <span class="material-symbols-outlined text-lg">link</span>
        كل الصور المرتبطة
      </a>
      <a href="/api/material-images-zip.php?mode=linked&amp;linked=false" target="_blank" class="h-10 px-4 rounded-xl border border-border-subtle bg-white text-sm font-bold inline-flex items-center gap-2 hover:bg-surface-low">
        <span class="material-symbols-outlined text-lg">link_off</span>
        الصور غير المرتبطة
      </a>
    </div>
  </section>
</div>
