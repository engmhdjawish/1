<?php

declare(strict_types=1);

/** @var array<string, mixed> $catalog */
/** @var array<string, mixed> $displayOptions */
/** @var bool $isCustomer */

$catalog = is_array($catalog ?? null) ? $catalog : [];
$displayOptions = is_array($displayOptions ?? null) ? $displayOptions : [];
$filters = is_array($catalog['filters'] ?? null) ? $catalog['filters'] : [];
$products = is_array($catalog['products'] ?? null) ? $catalog['products'] : [];
$resultFilters = is_array($catalog['resultFilters'] ?? null) ? $catalog['resultFilters'] : [];
$materialTypeOptions = is_array($resultFilters['materialTypes'] ?? null) ? $resultFilters['materialTypes'] : [];
$manufacturerOptions = is_array($resultFilters['manufacturers'] ?? null) ? $resultFilters['manufacturers'] : [];
$selectedMaterialTypes = is_array($filters['materialTypes'] ?? null) ? $filters['materialTypes'] : [];
$selectedManufacturers = is_array($filters['manufacturers'] ?? null) ? $filters['manufacturers'] : [];
$availabilityValue = $filters['isAvailable'] === true ? '1' : ($filters['isAvailable'] === false ? '0' : '');

$buildStoreUrl = static function (int $targetPage) use ($filters): string {
    return store_url([
        'page' => $targetPage,
        'q' => (string) ($filters['q'] ?? ''),
        'sort' => (string) ($filters['sort'] ?? ''),
        'isAvailable' => $filters['isAvailable'] === true ? '1' : ($filters['isAvailable'] === false ? '0' : ''),
        'materialTypes' => is_array($filters['materialTypes'] ?? null) ? $filters['materialTypes'] : [],
        'manufacturers' => is_array($filters['manufacturers'] ?? null) ? $filters['manufacturers'] : [],
    ]);
};
?>
<section class="mb-6">
  <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
    <div>
      <h1 class="text-3xl font-extrabold text-slate-900">المتجر</h1>
      <p class="text-sm text-gray-600 mt-1">تصفّح المواد، ابحث بالاسم أو الكود، وافتح تفاصيل كل مادة.</p>
    </div>
    <?php if ($isCustomer): ?>
      <span class="inline-flex items-center gap-2 rounded-full bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-2 text-sm font-bold">
        <span class="material-symbols-outlined text-base" aria-hidden="true">verified_user</span>
        حساب عميل مفعّل
      </span>
    <?php endif; ?>
  </div>
</section>

<?php if (!empty($catalog['apiError'])): ?>
  <p class="mb-4 rounded-xl border bg-red-50 border-red-200 text-red-700 px-4 py-3 text-sm"><?= h((string) $catalog['apiError']) ?></p>
<?php endif; ?>

<form method="get" class="mb-6 bg-white rounded-2xl border border-gray-200 shadow-sm p-4 md:p-5 space-y-4">
  <input type="hidden" name="page" value="1">
  <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
    <label class="text-sm md:col-span-2">
      <span class="text-gray-600 block mb-1 font-medium">بحث</span>
      <input name="q" value="<?= h((string) ($filters['q'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-gray-300 px-3" placeholder="اسم المادة أو الكود">
    </label>
    <label class="text-sm">
      <span class="text-gray-600 block mb-1 font-medium">الترتيب</span>
      <select name="sort" class="h-11 w-full rounded-xl border border-gray-300 px-3">
        <?php foreach ([
            'number:asc' => 'الرقم تصاعدي',
            'number:desc' => 'الرقم تنازلي',
            'name:asc' => 'الاسم',
            '-unitSalePriceSyp' => 'السعر',
        ] as $value => $label): ?>
          <option value="<?= h($value) ?>" <?= ((string) ($filters['sort'] ?? '') === $value) ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <div class="text-sm">
      <span class="text-gray-600 block mb-1 font-medium">التوفر</span>
      <div class="flex flex-wrap gap-2">
        <?php foreach (['' => 'الكل', '1' => 'متوفر', '0' => 'غير متوفر'] as $value => $label): ?>
          <?php $isActive = $availabilityValue === (string) $value; ?>
          <label class="cursor-pointer">
            <input type="radio" class="peer sr-only" name="isAvailable" value="<?= h((string) $value) ?>" <?= $isActive ? 'checked' : '' ?>>
            <span class="inline-flex px-3 py-2 rounded-full text-sm font-bold border transition border-gray-300 bg-white text-gray-700 hover:border-primary peer-checked:bg-primary peer-checked:text-white peer-checked:border-primary"><?= h($label) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <?php if ($materialTypeOptions !== []): ?>
    <fieldset class="rounded-xl border border-gray-200 p-3">
      <legend class="text-sm font-bold text-gray-700 px-1">نوع المادة</legend>
      <div class="flex flex-wrap gap-2 mt-2">
        <?php foreach ($materialTypeOptions as $option): ?>
          <?php
            $value = (string) ($option['value'] ?? '');
            if ($value === '') continue;
            $isChecked = in_array($value, $selectedMaterialTypes, true);
            $count = $option['count'] ?? null;
          ?>
          <label class="cursor-pointer">
            <input type="checkbox" class="peer sr-only" name="materialTypes[]" value="<?= h($value) ?>" <?= $isChecked ? 'checked' : '' ?>>
            <span class="inline-flex items-center px-3 py-1.5 rounded-full text-sm font-bold border transition border-gray-300 bg-white text-gray-700 hover:border-primary peer-checked:bg-primary peer-checked:text-white peer-checked:border-primary">
              <?= h($value) ?><?= $count !== null ? ' (' . (int) $count . ')' : '' ?>
            </span>
          </label>
        <?php endforeach; ?>
      </div>
    </fieldset>
  <?php endif; ?>

  <?php if ($manufacturerOptions !== []): ?>
    <fieldset class="rounded-xl border border-gray-200 p-3">
      <legend class="text-sm font-bold text-gray-700 px-1">الشركة</legend>
      <div class="flex flex-wrap gap-2 mt-2">
        <?php foreach ($manufacturerOptions as $option): ?>
          <?php
            $value = (string) ($option['value'] ?? '');
            if ($value === '') continue;
            $isChecked = in_array($value, $selectedManufacturers, true);
            $count = $option['count'] ?? null;
          ?>
          <label class="cursor-pointer">
            <input type="checkbox" class="peer sr-only" name="manufacturers[]" value="<?= h($value) ?>" <?= $isChecked ? 'checked' : '' ?>>
            <span class="inline-flex items-center px-3 py-1.5 rounded-full text-sm font-bold border transition border-gray-300 bg-white text-gray-700 hover:border-primary peer-checked:bg-primary peer-checked:text-white peer-checked:border-primary">
              <?= h($value) ?><?= $count !== null ? ' (' . (int) $count . ')' : '' ?>
            </span>
          </label>
        <?php endforeach; ?>
      </div>
    </fieldset>
  <?php endif; ?>

  <div class="flex flex-wrap gap-2 justify-end">
    <button class="h-11 rounded-xl bg-primary text-white px-6 font-bold">تطبيق</button>
    <a href="/store.php" class="h-11 inline-flex items-center rounded-xl border border-gray-300 px-6 text-sm font-semibold">إعادة ضبط</a>
  </div>
</form>

<?php if ((int) ($catalog['totalCount'] ?? 0) > 0): ?>
  <p class="text-sm text-gray-600 mb-4">
    عرض <?= (int) ($catalog['rangeStart'] ?? 0) ?>–<?= (int) ($catalog['rangeEnd'] ?? 0) ?> من <?= (int) ($catalog['totalCount'] ?? 0) ?> مادة
    <?php if ((int) ($catalog['totalPages'] ?? 1) > 1): ?>
      <span class="text-gray-400">(صفحة <?= (int) ($catalog['page'] ?? 1) ?> من <?= (int) ($catalog['totalPages'] ?? 1) ?>)</span>
    <?php endif; ?>
  </p>
<?php endif; ?>

<?php if ($products === [] && empty($catalog['apiError'])): ?>
  <div class="rounded-2xl border border-dashed border-gray-300 bg-white p-10 text-center text-gray-500">
    لا توجد نتائج مطابقة لبحثك.
  </div>
<?php else: ?>
  <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
    <?php foreach ($products as $item): ?>
      <?php if (!is_array($item)) continue; ?>
      <?php require __DIR__ . '/partials/product-card.php'; ?>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php
$page = (int) ($catalog['page'] ?? 1);
$totalPages = (int) ($catalog['totalPages'] ?? 1);
$buildUrl = static fn (int $targetPage): string => $buildStoreUrl($targetPage);
require __DIR__ . '/partials/catalog-pagination.php';
?>

<style>
  .line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }
</style>
