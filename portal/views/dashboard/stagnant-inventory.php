<?php

declare(strict_types=1);

use Portal\Support\PriceFx;

/** @var array<string, mixed> $report */
/** @var list<array<string, mixed>> $seasons */
/** @var array<string, mixed>|null $selectedSeason */
/** @var array<string, mixed>|null $currentSeason */
/** @var string $seasonId */
/** @var int $minAgeDays */
/** @var int|null $maxAgeDays */
/** @var string $sort */
/** @var int $page */
/** @var int $pageSize */
/** @var int $totalPages */
/** @var int $totalCount */
/** @var string $asOf */
/** @var string $keyword */
/** @var list<string> $selectedStoreGuids */
/** @var list<string> $selectedCompanies */
/** @var list<string> $selectedMaterialTypes */
/** @var list<string> $selectedGroupGuids */
/** @var array<string, mixed> $materialFilterOptions */
/** @var string|null $materialFilterOptionsError */
/** @var string|null $error */
/** @var string|null $flash */
/** @var string $flashType */

$items = is_array($report['items'] ?? null) ? $report['items'] : [];
$selectedStoreGuids = array_map('strval', $selectedStoreGuids ?? []);
$selectedCompanies = array_map('strval', $selectedCompanies ?? []);
$selectedMaterialTypes = array_map('strval', $selectedMaterialTypes ?? []);
$selectedGroupGuids = array_map('strval', $selectedGroupGuids ?? []);
$selectedStoreSet = array_fill_keys($selectedStoreGuids, true);
$selectedCompanySet = array_fill_keys($selectedCompanies, true);
$selectedTypeSet = array_fill_keys($selectedMaterialTypes, true);
$selectedGroupSet = array_fill_keys($selectedGroupGuids, true);
$keyword = (string) ($keyword ?? '');
$maxAgeDays = $maxAgeDays ?? null;
$hasExtraFilters = $selectedStoreGuids !== [] || $selectedCompanies !== [] || $selectedMaterialTypes !== [] || $selectedGroupGuids !== [];

$fmtMoney = static function (?float $value, int $decimals = 2): string {
    if ($value === null) {
        return '—';
    }

    return number_format($value, $decimals);
};
$fmtDate = static function (string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '—';
    }
    $ts = strtotime($value);

    return $ts ? date('Y-m-d', $ts) : $value;
};

$storeOptions = [];
foreach (($materialFilterOptions['stores'] ?? []) as $store) {
    if (!is_array($store)) {
        continue;
    }
    $guid = trim((string) ($store['guid'] ?? $store['Guid'] ?? ''));
    if ($guid === '') {
        continue;
    }
    $label = trim((string) ($store['name'] ?? $store['Name'] ?? $guid));
    $code = trim((string) ($store['code'] ?? $store['Code'] ?? ''));
    $storeOptions[] = [
        'value' => $guid,
        'label' => $code !== '' ? ($code . ' — ' . $label) : $label,
    ];
}
$companyOptions = array_values(array_unique(array_merge($materialFilterOptions['manufacturers'] ?? [], $selectedCompanies)));
$typeOptions = array_values(array_unique(array_merge($materialFilterOptions['materialTypes'] ?? [], $selectedMaterialTypes)));
$groupOptions = [];
foreach (($materialFilterOptions['groups'] ?? []) as $group) {
    if (!is_array($group)) {
        continue;
    }
    $guid = trim((string) ($group['guid'] ?? $group['Guid'] ?? ''));
    if ($guid === '') {
        continue;
    }
    $label = trim((string) ($group['name'] ?? $group['Name'] ?? $guid));
    $code = trim((string) ($group['code'] ?? $group['Code'] ?? ''));
    $groupOptions[] = [
        'value' => $guid,
        'label' => $code !== '' ? ($code . ' — ' . $label) : $label,
    ];
}

$queryBase = [
    'minAgeDays' => $minAgeDays,
    'maxAgeDays' => $maxAgeDays,
    'sort' => $sort,
    'pageSize' => $pageSize,
    'seasonId' => $seasonId !== '' ? $seasonId : 'all',
    'asOf' => $asOf,
    'keyword' => $keyword,
];
$buildUrl = static function (array $overrides) use ($queryBase, $selectedStoreGuids, $selectedCompanies, $selectedMaterialTypes, $selectedGroupGuids): string {
    $q = array_merge($queryBase, $overrides);
    foreach ($q as $key => $value) {
        if ($value === '' || $value === null) {
            unset($q[$key]);
        }
    }
    $url = '/dashboard/stagnant-inventory.php?' . http_build_query($q);
    foreach ($selectedStoreGuids as $guid) {
        $url .= '&store_guids[]=' . rawurlencode($guid);
    }
    foreach ($selectedCompanies as $company) {
        $url .= '&companies[]=' . rawurlencode($company);
    }
    foreach ($selectedMaterialTypes as $type) {
        $url .= '&material_types[]=' . rawurlencode($type);
    }
    foreach ($selectedGroupGuids as $guid) {
        $url .= '&group_guids[]=' . rawurlencode($guid);
    }

    return $url;
};

$defaultDiscount = 15.0;
/** @var array<string, list<array<string, mixed>>> $offerMembershipByGuid */
$offerMembershipByGuid = is_array($offerMembershipByGuid ?? null) ? $offerMembershipByGuid : [];
?>
<section class="mb-5" data-stagnant-inventory-root>
  <div class="flex flex-wrap items-end justify-between gap-3">
    <div>
      <h1 class="text-2xl font-extrabold text-slate-900">المخزون الراكد</h1>
      <p class="text-sm text-text-muted mt-1">الدولار ثابت · الليرة تُحسب من سعر الصرف مع التقريب لأعلى 5 · عدّل النسبة أو السعر.</p>
    </div>
    <a href="/dashboard/special-offers.php" class="h-9 px-3 inline-flex items-center gap-1.5 rounded-lg border border-border-subtle bg-white text-sm font-bold text-slate-700 hover:bg-slate-50" data-dashboard-nav-link>
      العروض الخاصة
    </a>
  </div>
</section>

<?php if ($flash): ?>
  <p class="mb-4 rounded-xl border px-4 py-3 text-sm <?= $flashType === 'error' ? 'border-red-200 bg-red-50 text-red-700' : 'border-emerald-200 bg-emerald-50 text-emerald-800' ?>">
    <?= h($flash) ?>
  </p>
<?php endif; ?>

<?php if ($error): ?>
  <p class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"><?= h($error) ?></p>
<?php endif; ?>

<?php if ($materialFilterOptionsError): ?>
  <p class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"><?= h($materialFilterOptionsError) ?></p>
<?php endif; ?>

<form method="get" action="/dashboard/stagnant-inventory.php" class="mb-4 rounded-xl border border-border-subtle bg-white p-4 shadow-sm space-y-3" data-dashboard-filter>
  <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-6 gap-3 items-end">
    <label class="text-sm sm:col-span-2">
      <span class="text-text-muted">بحث</span>
      <input type="search" name="keyword" value="<?= h($keyword) ?>" placeholder="كود أو اسم..." class="mt-1 w-full h-10 rounded-xl border border-border-subtle px-3 text-sm">
    </label>
    <label class="text-sm">
      <span class="text-text-muted">عمر من (يوم)</span>
      <input type="number" name="minAgeDays" min="1" max="3650" value="<?= (int) $minAgeDays ?>" class="mt-1 w-full h-10 rounded-xl border border-border-subtle px-3 text-sm">
    </label>
    <label class="text-sm">
      <span class="text-text-muted">عمر إلى (يوم)</span>
      <input type="number" name="maxAgeDays" min="1" max="3650" value="<?= $maxAgeDays !== null ? (int) $maxAgeDays : '' ?>" class="mt-1 w-full h-10 rounded-xl border border-border-subtle px-3 text-sm">
    </label>
    <label class="text-sm">
      <span class="text-text-muted">حتى تاريخ</span>
      <input type="date" name="asOf" value="<?= h($asOf) ?>" class="mt-1 w-full h-10 rounded-xl border border-border-subtle px-3 text-sm">
    </label>
    <label class="text-sm">
      <span class="text-text-muted">الترتيب</span>
      <select name="sort" class="mt-1 w-full h-10 rounded-xl border border-border-subtle px-3 text-sm">
        <option value="age:desc" <?= $sort === 'age:desc' ? 'selected' : '' ?>>الأقدم أولاً</option>
        <option value="age:asc" <?= $sort === 'age:asc' ? 'selected' : '' ?>>الأحدث أولاً</option>
        <option value="qty:desc" <?= $sort === 'qty:desc' ? 'selected' : '' ?>>الكمية ↓</option>
        <option value="qty:asc" <?= $sort === 'qty:asc' ? 'selected' : '' ?>>الكمية ↑</option>
        <option value="name:asc" <?= $sort === 'name:asc' ? 'selected' : '' ?>>الاسم ↑</option>
        <option value="name:desc" <?= $sort === 'name:desc' ? 'selected' : '' ?>>الاسم ↓</option>
        <option value="price:desc" <?= $sort === 'price:desc' ? 'selected' : '' ?>>المبيع ↓</option>
        <option value="price:asc" <?= $sort === 'price:asc' ? 'selected' : '' ?>>المبيع ↑</option>
        <option value="purchase:desc" <?= $sort === 'purchase:desc' ? 'selected' : '' ?>>الشراء ↓</option>
        <option value="purchase:asc" <?= $sort === 'purchase:asc' ? 'selected' : '' ?>>الشراء ↑</option>
      </select>
    </label>
  </div>

  <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-6 gap-3 items-end">
    <label class="text-sm sm:col-span-2">
      <span class="text-text-muted">الموسم</span>
      <select name="seasonId" class="mt-1 w-full h-10 rounded-xl border border-border-subtle px-3 text-sm">
        <option value="all" <?= $seasonId === '' || $seasonId === 'all' ? 'selected' : '' ?>>كل المواسم</option>
        <?php if ($currentSeason): ?>
          <option value="current" <?= $seasonId === 'current' || ($selectedSeason['id'] ?? '') === ($currentSeason['id'] ?? '') ? 'selected' : '' ?>>
            الموسم الحالي — <?= h((string) ($currentSeason['title_ar'] ?? $currentSeason['name_ar'] ?? '')) ?>
          </option>
        <?php endif; ?>
        <?php foreach ($seasons as $season): ?>
          <?php
            $sid = (string) ($season['id'] ?? '');
            if ($sid === '') {
                continue;
            }
          ?>
          <option value="<?= h($sid) ?>" <?= $seasonId === $sid ? 'selected' : '' ?>>
            <?= h((string) ($season['name_ar'] ?? $sid)) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="text-sm">
      <span class="text-text-muted">عدد الصفوف</span>
      <select name="pageSize" class="mt-1 w-full h-10 rounded-xl border border-border-subtle px-3 text-sm">
        <?php foreach ([20, 50, 100] as $size): ?>
          <option value="<?= $size ?>" <?= (int) $pageSize === $size ? 'selected' : '' ?>><?= $size ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <div class="sm:col-span-2 xl:col-span-3 flex flex-wrap gap-2 xl:justify-end">
      <input type="hidden" name="page" value="1">
      <button type="submit" class="h-10 px-5 rounded-xl bg-primary text-white text-sm font-bold hover:brightness-110">تطبيق</button>
      <a href="/dashboard/stagnant-inventory.php" class="h-10 px-4 inline-flex items-center rounded-xl border border-border-subtle text-sm font-bold text-slate-700 hover:bg-slate-50" data-dashboard-nav-link>إعادة ضبط</a>
    </div>
  </div>

  <details class="rounded-xl border border-border-subtle bg-slate-50/40" <?= $hasExtraFilters ? 'open' : '' ?>>
    <summary class="cursor-pointer select-none px-3 py-2.5 text-sm font-bold text-slate-800">مستودع · مجموعة · شركة · نوع</summary>
    <div class="px-3 pb-3 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3">
      <label class="text-sm">
        <span class="text-text-muted">المستودعات</span>
        <select name="store_guids[]" multiple size="6" class="mt-1 w-full rounded-xl border border-border-subtle px-2 py-1.5 text-sm leading-6 bg-white">
          <?php foreach ($storeOptions as $opt): ?>
            <option value="<?= h($opt['value']) ?>" <?= isset($selectedStoreSet[$opt['value']]) ? 'selected' : '' ?>><?= h($opt['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="text-sm">
        <span class="text-text-muted">المجموعات</span>
        <select name="group_guids[]" multiple size="6" class="mt-1 w-full rounded-xl border border-border-subtle px-2 py-1.5 text-sm leading-6 bg-white">
          <?php foreach ($groupOptions as $opt): ?>
            <option value="<?= h($opt['value']) ?>" <?= isset($selectedGroupSet[$opt['value']]) ? 'selected' : '' ?>><?= h($opt['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="text-sm">
        <span class="text-text-muted">الشركة</span>
        <select name="companies[]" multiple size="6" class="mt-1 w-full rounded-xl border border-border-subtle px-2 py-1.5 text-sm leading-6 bg-white">
          <?php foreach ($companyOptions as $opt): ?>
            <option value="<?= h($opt) ?>" <?= isset($selectedCompanySet[$opt]) ? 'selected' : '' ?>><?= h($opt) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="text-sm">
        <span class="text-text-muted">النوع</span>
        <select name="material_types[]" multiple size="6" class="mt-1 w-full rounded-xl border border-border-subtle px-2 py-1.5 text-sm leading-6 bg-white">
          <?php foreach ($typeOptions as $opt): ?>
            <option value="<?= h($opt) ?>" <?= isset($selectedTypeSet[$opt]) ? 'selected' : '' ?>><?= h($opt) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
  </details>
</form>

<div class="mb-3 flex flex-wrap items-center justify-between gap-2 text-sm text-text-muted">
  <p>
    <span class="font-bold text-slate-800"><?= (int) $totalCount ?></span> مادة
    <?php if ($selectedSeason): ?>
      · <?= h((string) ($selectedSeason['name_ar'] ?? $selectedSeason['title_ar'] ?? '')) ?>
    <?php endif; ?>
    · عمر <?= (int) $minAgeDays ?><?= $maxAgeDays !== null ? '–' . (int) $maxAgeDays : '+' ?> يوم
  </p>
  <?php if ($totalPages > 1): ?>
    <div class="flex items-center gap-2">
      <?php if ($page > 1): ?>
        <a class="px-3 py-1.5 rounded-lg border border-border-subtle hover:bg-slate-50 font-bold" data-dashboard-nav-link href="<?= h($buildUrl(['page' => $page - 1])) ?>">السابق</a>
      <?php endif; ?>
      <span><?= (int) $page ?> / <?= (int) $totalPages ?></span>
      <?php if ($page < $totalPages): ?>
        <a class="px-3 py-1.5 rounded-lg border border-border-subtle hover:bg-slate-50 font-bold" data-dashboard-nav-link href="<?= h($buildUrl(['page' => $page + 1])) ?>">التالي</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<form method="post" action="/dashboard/stagnant-inventory.php" id="stagnant-offer-form" data-dashboard-ajax data-stagnant-offer-form>
  <input type="hidden" name="action" value="create_offer">
  <input type="hidden" name="minAgeDays" value="<?= (int) $minAgeDays ?>">
  <input type="hidden" name="maxAgeDays" value="<?= $maxAgeDays !== null ? (int) $maxAgeDays : '' ?>">
  <input type="hidden" name="sort" value="<?= h($sort) ?>">
  <input type="hidden" name="page" value="<?= (int) $page ?>">
  <input type="hidden" name="pageSize" value="<?= (int) $pageSize ?>">
  <input type="hidden" name="seasonId" value="<?= h($seasonId !== '' ? $seasonId : 'all') ?>">
  <input type="hidden" name="asOf" value="<?= h($asOf) ?>">
  <input type="hidden" name="keyword" value="<?= h($keyword) ?>">
  <?php foreach ($selectedStoreGuids as $guid): ?>
    <input type="hidden" name="store_guids[]" value="<?= h($guid) ?>">
  <?php endforeach; ?>
  <?php foreach ($selectedCompanies as $company): ?>
    <input type="hidden" name="companies[]" value="<?= h($company) ?>">
  <?php endforeach; ?>
  <?php foreach ($selectedMaterialTypes as $type): ?>
    <input type="hidden" name="material_types[]" value="<?= h($type) ?>">
  <?php endforeach; ?>
  <?php foreach ($selectedGroupGuids as $guid): ?>
    <input type="hidden" name="group_guids[]" value="<?= h($guid) ?>">
  <?php endforeach; ?>

  <div class="sticky top-2 z-10 mb-4 rounded-xl border border-border-subtle bg-white/95 backdrop-blur p-3 shadow-sm space-y-3" data-stagnant-tray>
    <div class="flex flex-wrap items-end gap-3">
      <label class="text-sm">
        <span class="text-text-muted">عنوان العرض</span>
        <input type="text" name="offer_title" value="عرض تصريف راكد <?= h(date('Y-m-d')) ?>" class="mt-1 block w-56 h-10 rounded-xl border border-border-subtle px-3 text-sm">
      </label>
      <label class="text-sm">
        <span class="text-text-muted">خصم افتراضي %</span>
        <input type="text" inputmode="decimal" name="discount_percent" value="<?= h((string) $defaultDiscount) ?>" data-stagnant-default-discount class="mt-1 block w-28 h-10 rounded-xl border border-border-subtle px-3 text-sm" autocomplete="off">
      </label>
      <input type="hidden" name="save_mode" value="draft" data-stagnant-save-mode>
      <button type="submit" class="h-10 px-4 rounded-xl border border-border-subtle bg-white text-sm font-bold text-slate-800 hover:bg-slate-50 disabled:opacity-50" data-stagnant-submit data-stagnant-mode="draft" data-loading-label="جاري الحفظ…" disabled>
        حفظ مسودة (<span data-stagnant-selected-count>0</span>)
      </button>
      <button type="submit" class="h-10 px-4 rounded-xl bg-emerald-600 text-white text-sm font-bold hover:brightness-110 disabled:opacity-50" data-stagnant-submit data-stagnant-mode="activate" data-loading-label="جاري التفعيل…" disabled>
        تفعيل العرض
      </button>
      <span class="text-sm font-bold text-slate-600 hidden" data-stagnant-saving-hint aria-live="polite">جاري الحفظ…</span>
      <button type="button" class="h-10 px-3 rounded-xl border border-border-subtle text-sm font-bold text-slate-700 hover:bg-slate-50" data-stagnant-add-visible>إضافة الظاهرة</button>
      <button type="button" class="h-10 px-3 rounded-xl border border-border-subtle text-sm font-bold text-slate-700 hover:bg-slate-50" data-stagnant-clear-tray>تفريغ المرشّحين</button>
    </div>
    <div>
      <div class="flex items-center justify-between gap-2 mb-2">
        <p class="text-sm font-extrabold text-slate-800">ملخّص المرشّحين</p>
        <p class="text-xs text-text-muted">الجدول ثابت — المرشّحون يُضافون في نهاية هذه القائمة فقط</p>
      </div>
      <div class="rounded-xl border border-dashed border-border-subtle bg-slate-50/70 min-h-[4.5rem] max-h-56 overflow-y-auto" data-stagnant-tray-list>
        <p class="text-xs text-text-muted px-3 py-4 text-center" data-stagnant-tray-empty>لا مواد مرشّحة بعد — اضغط «إضافة» بجانب المادة.</p>
      </div>
    </div>
  </div>

  <?php if ($items === []): ?>
    <div class="rounded-xl border border-border-subtle bg-white px-4 py-12 text-center text-text-muted">لا توجد نتائج.</div>
  <?php else: ?>
    <div class="rounded-xl border border-border-subtle bg-white overflow-x-auto shadow-sm">
      <table class="w-full min-w-[1240px] text-sm border-collapse">
        <thead>
          <tr class="bg-slate-50 text-xs font-bold text-slate-600 border-b border-border-subtle">
            <th class="px-2 py-2.5 w-20 text-start">صورة</th>
            <th class="px-2 py-2.5 text-start min-w-[200px]">المادة</th>
            <th class="px-2 py-2.5 text-start w-20">كمية</th>
            <th class="px-2 py-2.5 text-start w-20">عمر</th>
            <th class="px-2 py-2.5 text-start w-24">مبيع $</th>
            <th class="px-2 py-2.5 text-start w-24">مبيع ل.س</th>
            <th class="px-2 py-2.5 text-start w-20">شراء $</th>
            <th class="px-2 py-2.5 text-start w-24">خصم %</th>
            <th class="px-2 py-2.5 text-start w-28">بعد الخصم $</th>
            <th class="px-2 py-2.5 text-start w-28">بعد الخصم ل.س</th>
            <th class="px-2 py-2.5 text-start min-w-[9rem]">عرض قائم</th>
            <th class="px-2 py-2.5 text-start w-28">للعرض</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border-subtle" data-stagnant-cards>
          <?php foreach ($items as $item): ?>
            <?php
              $guid = strtolower((string) ($item['materialGuid'] ?? ''));
              $imageGuid = (string) ($item['productImageGuid'] ?? '');
              $name = (string) ($item['name'] ?? '');
              $code = (string) ($item['materialCode'] ?? '');
              $meta = trim(implode(' · ', array_filter([
                  (string) ($item['company'] ?? ''),
                  (string) ($item['materialType'] ?? ''),
                  (string) ($item['groupName'] ?? ''),
              ], static fn ($v) => $v !== '')));
              $qty = (float) ($item['stagnantRemainingQty'] ?? 0);
              $ageRaw = $item['maxAgeDays'] ?? null;
              $ageLabel = $ageRaw === null || $ageRaw === '' ? '—' : number_format((float) $ageRaw, 0);
              $saleUsd = isset($item['unitSalePriceUsd']) ? (float) $item['unitSalePriceUsd'] : null;
              $saleSyp = isset($item['unitSalePriceSyp']) ? (float) $item['unitSalePriceSyp'] : null;
              $purchase = isset($item['unitPurchasePriceUsd']) ? (float) $item['unitPurchasePriceUsd'] : null;
              $currencyVal = isset($item['currencyVal']) ? (float) $item['currencyVal'] : null;
              $rate = PriceFx::inferRate($saleSyp, $saleUsd, $currencyVal);
              $saleAfterUsd = $saleUsd !== null
                  ? PriceFx::applyPercentToUsd($saleUsd, $defaultDiscount)
                  : null;
              $saleAfterSyp = ($saleAfterUsd !== null && $rate > 0.0)
                  ? PriceFx::sypFromUsd($saleAfterUsd, $rate)
                  : null;
              $thumbUrl = $imageGuid !== '' ? '/api/image.php?id=' . rawurlencode($imageGuid) . '&thumb=1' : '';
              $fullUrl = $imageGuid !== '' ? '/api/image.php?id=' . rawurlencode($imageGuid) . '&thumb=0' : '';
              $memberships = is_array($offerMembershipByGuid[$guid] ?? null) ? $offerMembershipByGuid[$guid] : [];
            ?>
            <tr
              class="stagnant-card hover:bg-slate-50/80 transition align-middle"
              data-stagnant-card
              data-material-guid="<?= h($guid) ?>"
              data-sale-usd="<?= $saleUsd !== null ? h((string) $saleUsd) : '' ?>"
              data-sale-syp="<?= $saleSyp !== null ? h((string) $saleSyp) : '' ?>"
              data-rate="<?= $rate > 0 ? h((string) $rate) : '' ?>"
              data-purchase-usd="<?= $purchase !== null ? h((string) $purchase) : '' ?>"
              data-qty="<?= h((string) $qty) ?>"
              data-age="<?= h($ageLabel) ?>"
              data-name="<?= h($name !== '' ? $name : $guid) ?>"
              data-code="<?= h($code) ?>"
              data-meta="<?= h($meta) ?>"
              data-image-full="<?= h($fullUrl) ?>"
              data-image-thumb="<?= h($thumbUrl) ?>"
            >
              <td class="px-2 py-2">
                <?php if ($fullUrl !== ''): ?>
                  <button
                    type="button"
                    class="block w-16 h-16 rounded-lg overflow-hidden bg-slate-100 border border-border-subtle cursor-zoom-in"
                    data-stagnant-preview
                    aria-label="معاينة وتحديد العرض"
                  >
                    <img
                      src="<?= h($thumbUrl) ?>"
                      alt="<?= h($name) ?>"
                      loading="lazy"
                      decoding="async"
                      class="w-full h-full object-cover"
                      onerror="this.closest('button')?.classList.add('opacity-40'); this.remove()"
                    >
                  </button>
                <?php else: ?>
                  <button
                    type="button"
                    class="w-16 h-16 rounded-lg bg-slate-100 border border-dashed border-slate-300"
                    data-stagnant-preview
                    aria-label="معاينة وتحديد العرض"
                    title="بدون صورة"
                  ></button>
                <?php endif; ?>
              </td>
              <td class="px-2 py-2 min-w-0">
                <button type="button" class="text-start w-full" data-stagnant-preview>
                  <div class="flex flex-wrap items-center gap-1.5">
                    <span class="font-bold text-slate-900 leading-snug hover:text-primary"><?= h($name !== '' ? $name : $guid) ?></span>
                    <span class="stagnant-tray-badge" data-stagnant-tray-badge hidden>مرشّح</span>
                  </div>
                  <?php if ($code !== ''): ?>
                    <div class="text-xs text-text-muted font-mono mt-0.5" dir="ltr"><?= h($code) ?></div>
                  <?php endif; ?>
                  <?php if ($meta !== ''): ?>
                    <div class="text-[11px] text-text-muted mt-0.5 truncate max-w-[280px]"><?= h($meta) ?></div>
                  <?php endif; ?>
                </button>
              </td>
              <td class="px-2 py-2 font-bold tabular-nums"><?= h(number_format($qty, 0)) ?></td>
              <td class="px-2 py-2 font-extrabold tabular-nums text-amber-700"><?= h($ageLabel) ?></td>
              <td class="px-2 py-2 tabular-nums font-bold text-slate-900" dir="ltr"><?= h($fmtMoney($saleUsd)) ?></td>
              <td class="px-2 py-2 tabular-nums text-slate-700" dir="ltr"><?= h($fmtMoney($saleSyp, 0)) ?></td>
              <td class="px-2 py-2 tabular-nums text-slate-600" dir="ltr"><?= h($fmtMoney($purchase)) ?></td>
              <td class="px-2 py-2">
                <input
                  type="text"
                  inputmode="decimal"
                  value="<?= h((string) $defaultDiscount) ?>"
                  data-stagnant-item-discount
                  class="w-20 h-9 rounded-lg border border-border-subtle px-2 text-sm tabular-nums"
                  title="نسبة الخصم"
                  autocomplete="off"
                >
              </td>
              <td class="px-2 py-2">
                <input
                  type="text"
                  inputmode="decimal"
                  value="<?= $saleAfterUsd !== null ? h(number_format($saleAfterUsd, 2, '.', '')) : '' ?>"
                  data-stagnant-item-price
                  class="w-24 h-9 rounded-lg border border-border-subtle px-2 text-sm tabular-nums"
                  title="سعر البيع بالدولار بعد الخصم"
                  autocomplete="off"
                  <?= $saleUsd === null || $saleUsd <= 0 ? 'disabled' : '' ?>
                >
              </td>
              <td class="px-2 py-2">
                <span class="inline-block min-w-[4.5rem] tabular-nums font-bold text-emerald-800" dir="ltr" data-stagnant-item-syp>
                  <?= h($saleAfterSyp !== null ? number_format($saleAfterSyp, 0) : '—') ?>
                </span>
              </td>
              <td class="px-2 py-2">
                <?php if ($memberships === []): ?>
                  <span class="text-[11px] text-text-muted">—</span>
                <?php else: ?>
                  <div class="flex flex-col gap-1">
                    <?php foreach ($memberships as $offerRow): ?>
                      <?php
                        if (!is_array($offerRow)) {
                            continue;
                        }
                        $offerStatus = (string) ($offerRow['status'] ?? 'paused');
                        $offerTitle = (string) ($offerRow['title_ar'] ?? '');
                        $discountLabel = (string) ($offerRow['discount_label'] ?? '');
                        $offerId = (string) ($offerRow['id'] ?? '');
                      ?>
                      <a
                        href="/dashboard/special-offers.php?edit=<?= urlencode($offerId) ?>&amp;tab=<?= $offerStatus === 'active' ? 'active' : 'paused' ?>"
                        class="stagnant-offer-chip stagnant-offer-chip--<?= h($offerStatus) ?>"
                        title="<?= h($offerTitle) ?>"
                        data-dashboard-nav-link
                      >
                        <span class="stagnant-offer-chip__title"><?= h($offerTitle) ?></span>
                        <span class="stagnant-offer-chip__meta">
                          <?= match ($offerStatus) {
                              'active' => 'نشط',
                              'ended' => 'منتهٍ',
                              default => 'مسودة',
                          } ?>
                          <?php if ($discountLabel !== ''): ?> · <?= h($discountLabel) ?><?php endif; ?>
                        </span>
                      </a>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </td>
              <td class="px-2 py-2">
                <button
                  type="button"
                  class="h-9 px-3 rounded-lg bg-primary text-white text-xs font-extrabold hover:brightness-110 disabled:opacity-50"
                  data-stagnant-add
                >إضافة</button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</form>

<div
  id="stagnant-preview-modal"
  class="stagnant-preview-modal"
  hidden
  aria-hidden="true"
  data-stagnant-preview-modal
>
  <div class="stagnant-preview-modal__backdrop" data-stagnant-preview-close></div>
  <div
    class="stagnant-preview-modal__panel"
    role="dialog"
    aria-modal="true"
    aria-labelledby="stagnant-preview-title"
  >
    <div class="stagnant-preview-modal__header">
      <div class="stagnant-preview-modal__nav">
        <button type="button" class="stagnant-preview-modal__nav-btn" data-stagnant-preview-prev title="المادة السابقة">السابق</button>
        <span class="text-xs font-bold text-slate-500 tabular-nums" data-stagnant-preview-position></span>
        <button type="button" class="stagnant-preview-modal__nav-btn" data-stagnant-preview-next title="المادة التالية">التالي</button>
      </div>
      <button type="button" class="stagnant-preview-modal__close" data-stagnant-preview-close aria-label="إغلاق">×</button>
    </div>

    <div class="stagnant-preview-modal__body">
      <div class="stagnant-preview-modal__media">
        <img src="" alt="" data-stagnant-preview-image class="hidden">
        <div data-stagnant-preview-image-empty class="text-slate-400 text-sm font-bold py-16">لا توجد صورة</div>
      </div>

      <div class="stagnant-preview-modal__side">
        <div>
          <h2 id="stagnant-preview-title" class="text-lg font-extrabold text-slate-900 leading-snug" data-stagnant-preview-name></h2>
          <p class="text-xs font-mono text-text-muted mt-1" dir="ltr" data-stagnant-preview-code></p>
          <p class="text-xs text-text-muted mt-1 leading-snug" data-stagnant-preview-meta></p>
        </div>

        <div class="stagnant-preview-modal__stats">
          <div class="stagnant-preview-modal__stat">
            <div class="stagnant-preview-modal__stat-label">الكمية</div>
            <div class="stagnant-preview-modal__stat-value" data-stagnant-preview-qty>—</div>
          </div>
          <div class="stagnant-preview-modal__stat stagnant-preview-modal__stat--age">
            <div class="stagnant-preview-modal__stat-label">العمر (يوم)</div>
            <div class="stagnant-preview-modal__stat-value" data-stagnant-preview-age>—</div>
          </div>
          <div class="stagnant-preview-modal__stat">
            <div class="stagnant-preview-modal__stat-label">مبيع $</div>
            <div class="stagnant-preview-modal__stat-value" dir="ltr" data-stagnant-preview-sale-usd>—</div>
          </div>
          <div class="stagnant-preview-modal__stat">
            <div class="stagnant-preview-modal__stat-label">مبيع ل.س</div>
            <div class="stagnant-preview-modal__stat-value" dir="ltr" data-stagnant-preview-sale-syp>—</div>
          </div>
          <div class="stagnant-preview-modal__stat" style="grid-column: 1 / -1">
            <div class="stagnant-preview-modal__stat-label">شراء $</div>
            <div class="stagnant-preview-modal__stat-value" dir="ltr" data-stagnant-preview-purchase>—</div>
          </div>
        </div>

        <button type="button" class="stagnant-preview-modal__done" style="background:#D81921" data-stagnant-preview-add>
          إضافة إلى المرشّحين
        </button>

        <div class="stagnant-preview-modal__pricing">
          <div class="stagnant-preview-modal__field">
            <label for="stagnant-preview-discount">خصم %</label>
            <input
              id="stagnant-preview-discount"
              type="text"
              inputmode="decimal"
              autocomplete="off"
              data-stagnant-preview-discount
            >
          </div>
          <div class="stagnant-preview-modal__field">
            <label for="stagnant-preview-price">بعد الخصم $</label>
            <input
              id="stagnant-preview-price"
              type="text"
              inputmode="decimal"
              autocomplete="off"
              data-stagnant-preview-price
            >
          </div>
        </div>

        <div class="stagnant-preview-modal__syp" data-stagnant-preview-syp-box>
          <span>بعد الخصم ل.س</span>
          <span dir="ltr" data-stagnant-preview-syp>—</span>
        </div>

        <div class="stagnant-preview-modal__warn" data-stagnant-preview-below-cost hidden>
          تحذير: السعر بعد الخصم أقل من سعر الشراء
        </div>

        <button type="button" class="stagnant-preview-modal__done" data-stagnant-preview-close>تم</button>
      </div>
    </div>
  </div>
</div>
