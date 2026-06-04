<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Services\ApiClient;
use Portal\Services\ShareLinkService;

require dirname(__DIR__) . '/views/helpers.php';

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$shareLink = $token !== '' ? ShareLinkService::getByPublicToken($token) : null;
$error = null;
$apiError = null;

if ($token === '') {
    $error = 'يرجى فتح الصفحة باستخدام رابط مشاركة صحيح يحتوي على token.';
}

if ($token !== '' && $shareLink === null) {
    $error = 'الرابط غير صالح أو غير نشط أو منتهي الصلاحية.';
}

$requiresPassword = (bool) (($shareLink['require_password'] ?? 0) ? true : false);
if (!isset($_SESSION['share_link_access']) || !is_array($_SESSION['share_link_access'])) {
    $_SESSION['share_link_access'] = [];
}
$hasAccess = !$requiresPassword || !empty($_SESSION['share_link_access'][$token]);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unlock' && $shareLink !== null) {
    $userName = trim((string) ($_POST['access_username'] ?? ''));
    $password = trim((string) ($_POST['access_password'] ?? ''));
    if (ShareLinkService::verifyProtectedAccess($token, $userName, $password)) {
        $_SESSION['share_link_access'][$token] = true;
        $hasAccess = true;
    } else {
        $error = 'بيانات الدخول غير صحيحة.';
    }
}

$parseList = static function (string $key): array {
    $raw = $_GET[$key] ?? [];
    $values = is_array($raw) ? $raw : explode(',', (string) $raw);
    $result = [];
    foreach ($values as $value) {
        $item = trim((string) $value);
        if ($item !== '') {
            $result[] = $item;
        }
    }
    return array_values(array_unique($result));
};

$shareOptions = is_array($shareLink) ? (array) ($shareLink['options'] ?? []) : [];
$allowClientFilters = (bool) (($shareOptions['allow_client_filters'] ?? true) ? true : false);
$allowSorting = (bool) (($shareOptions['allow_sorting'] ?? true) ? true : false);
$includeResultFilters = (bool) (($shareOptions['include_result_filters'] ?? true) ? true : false);
$defaultSort = trim((string) ($shareOptions['default_sort'] ?? 'number:asc'));
$defaultSort = $defaultSort !== '' ? $defaultSort : 'number:asc';

$forcedMaterialTypes = array_map('strval', is_array($shareLink) ? ($shareLink['forced_material_types'] ?? []) : []);
$forcedAgeCategories = array_map('strval', is_array($shareLink) ? ($shareLink['forced_age_categories'] ?? []) : []);
$forcedManufacturers = array_map('strval', is_array($shareLink) ? ($shareLink['forced_manufacturers'] ?? []) : []);
$forcedSizeRanges = array_map('strval', is_array($shareLink) ? ($shareLink['forced_size_ranges'] ?? []) : []);
$forcedCountryOrigins = array_map('strval', is_array($shareLink) ? ($shareLink['forced_country_origins'] ?? []) : []);

$selectedMaterialTypes = $allowClientFilters ? $parseList('materialTypes') : [];
$selectedAgeCategories = $allowClientFilters ? $parseList('ageCategories') : [];
$selectedManufacturers = $allowClientFilters ? $parseList('manufacturers') : [];
$selectedSizeRanges = $allowClientFilters ? $parseList('sizeRanges') : [];
$selectedCountryOrigins = $allowClientFilters ? $parseList('countryOfOrigins') : [];

$mergeConstrainedValues = static function (array $forced, array $selected, bool &$hasConflict): array {
    if ($forced === []) {
        return $selected;
    }
    if ($selected === []) {
        return $forced;
    }

    $forcedMap = [];
    foreach ($forced as $value) {
        $forcedMap[mb_strtolower($value)] = $value;
    }
    $intersection = [];
    foreach ($selected as $value) {
        $key = mb_strtolower($value);
        if (isset($forcedMap[$key])) {
            $intersection[] = $forcedMap[$key];
        }
    }
    $intersection = array_values(array_unique($intersection));
    if ($intersection === []) {
        $hasConflict = true;
    }
    return $intersection;
};

$hasConstraintConflict = false;
$queryMaterialTypes = $mergeConstrainedValues($forcedMaterialTypes, $selectedMaterialTypes, $hasConstraintConflict);
$queryAgeCategories = $mergeConstrainedValues($forcedAgeCategories, $selectedAgeCategories, $hasConstraintConflict);
$queryManufacturers = $mergeConstrainedValues($forcedManufacturers, $selectedManufacturers, $hasConstraintConflict);
$querySizeRanges = $mergeConstrainedValues($forcedSizeRanges, $selectedSizeRanges, $hasConstraintConflict);
$queryCountryOrigins = $mergeConstrainedValues($forcedCountryOrigins, $selectedCountryOrigins, $hasConstraintConflict);

$baseKeyword = trim((string) (is_array($shareLink) ? ($shareLink['keyword'] ?? '') : ''));
$userKeyword = $allowClientFilters ? trim((string) ($_GET['q'] ?? '')) : '';
$search = trim($baseKeyword . ' ' . $userKeyword);
$search = $search !== '' ? $search : null;

$baseMinQuantity = (float) (is_array($shareLink) ? ($shareLink['min_quantity'] ?? 0) : 0);
$userMinQuantity = $allowClientFilters ? (float) ($_GET['minWarehouseQuantity'] ?? 0) : 0;
$effectiveMinQuantity = max($baseMinQuantity, $userMinQuantity);

$selectedSort = $allowSorting
    ? trim((string) ($_GET['sort'] ?? $defaultSort))
    : $defaultSort;
$selectedSort = $selectedSort !== '' ? $selectedSort : 'number:asc';

$page = max(1, (int) ($_GET['page'] ?? 1));
$products = [];
$resultFilters = [];

if ($shareLink !== null && $hasAccess && !$hasConstraintConflict) {
    try {
        $params = array_filter([
            'page' => $page,
            'pageSize' => 24,
            'search' => $search,
            'materialTypes' => $queryMaterialTypes !== [] ? implode(',', $queryMaterialTypes) : null,
            'ageCategories' => $queryAgeCategories !== [] ? implode(',', $queryAgeCategories) : null,
            'manufacturers' => $queryManufacturers !== [] ? implode(',', $queryManufacturers) : null,
            'sizeRanges' => $querySizeRanges !== [] ? implode(',', $querySizeRanges) : null,
            'countryOfOrigins' => $queryCountryOrigins !== [] ? implode(',', $queryCountryOrigins) : null,
            'minWarehouseQuantity' => $effectiveMinQuantity > 0 ? $effectiveMinQuantity : null,
            'sort' => $selectedSort,
            'includeResultFilters' => ($allowClientFilters && $includeResultFilters) ? 1 : 0,
        ], static fn ($value) => $value !== null && $value !== '');

        $materials = ApiClient::get('/api/materials', $params);
        if ($materials['ok']) {
            $products = $materials['data']['items'] ?? [];
            $resultFilters = $materials['data']['resultFilters'] ?? [];
        } else {
            $apiError = 'تعذر جلب المواد من API (رمز ' . (int) ($materials['status'] ?? 0) . ')';
        }
    } catch (\Throwable $exception) {
        $apiError = $exception->getMessage();
    }
}

$showImages = (bool) (($shareOptions['show_images'] ?? true) ? true : false);
$priceMode = (string) ($shareOptions['price_mode'] ?? 'both');
if (!(is_array($shareLink) && (($shareLink['show_price'] ?? 0) ? true : false))) {
    $priceMode = 'none';
}
$showPriceSyp = in_array($priceMode, ['both', 'syp'], true);
$showPriceUsd = in_array($priceMode, ['both', 'usd'], true);
$showQuantity = (bool) (is_array($shareLink) && (($shareLink['show_quantity'] ?? 0) ? true : false));

ob_start();
?>
<div class="bg-white rounded-xl p-6 shadow-sm border">
  <h1 class="text-2xl font-extrabold mb-2"><?= h((string) ($shareLink['name_ar'] ?? 'رابط مشاركة')) ?></h1>
  <p class="text-sm text-gray-600 mb-4">سياسة الوصول: <?= h((string) ($shareLink['access_policy_name_ar'] ?? '—')) ?></p>

  <?php if ($error): ?>
    <p class="mb-4 rounded border bg-red-50 border-red-200 text-red-700 px-3 py-2 text-sm"><?= h($error) ?></p>
  <?php endif; ?>
  <?php if ($apiError): ?>
    <p class="mb-4 rounded border bg-red-50 border-red-200 text-red-700 px-3 py-2 text-sm"><?= h($apiError) ?></p>
  <?php endif; ?>
  <?php if ($hasConstraintConflict): ?>
    <p class="mb-4 rounded border bg-amber-50 border-amber-200 text-amber-700 px-3 py-2 text-sm">الفلاتر المختارة لا تتطابق مع قيود هذا الرابط.</p>
  <?php endif; ?>

  <?php if ($shareLink !== null && !$hasAccess): ?>
    <form method="post" class="max-w-md rounded-xl border border-gray-200 p-4 space-y-3">
      <input type="hidden" name="action" value="unlock">
      <input type="hidden" name="token" value="<?= h($token) ?>">
      <label class="block text-sm">
        <span class="text-gray-600 block mb-1">اسم المستخدم</span>
        <input name="access_username" class="h-11 w-full rounded border border-gray-300 px-3">
      </label>
      <label class="block text-sm">
        <span class="text-gray-600 block mb-1">كلمة المرور</span>
        <input type="password" name="access_password" class="h-11 w-full rounded border border-gray-300 px-3">
      </label>
      <button class="h-11 rounded bg-primary text-white px-5 font-bold">دخول للرابط</button>
    </form>
  <?php endif; ?>

  <?php if ($shareLink !== null && $hasAccess): ?>
    <?php if ($allowClientFilters): ?>
      <form method="get" class="mb-5 grid grid-cols-1 md:grid-cols-4 gap-3">
        <input type="hidden" name="token" value="<?= h($token) ?>">
        <label class="text-sm md:col-span-2">
          <span class="text-gray-600 block mb-1">بحث</span>
          <input name="q" value="<?= h((string) ($_GET['q'] ?? '')) ?>" class="h-11 w-full rounded border border-gray-300 px-3" placeholder="اسم المادة أو الكود">
        </label>
        <label class="text-sm">
          <span class="text-gray-600 block mb-1">أقل كمية</span>
          <input type="number" name="minWarehouseQuantity" min="0" step="0.01" value="<?= h((string) ($_GET['minWarehouseQuantity'] ?? '')) ?>" class="h-11 w-full rounded border border-gray-300 px-3">
        </label>
        <?php if ($allowSorting): ?>
          <label class="text-sm">
            <span class="text-gray-600 block mb-1">الترتيب</span>
            <select name="sort" class="h-11 w-full rounded border border-gray-300 px-3">
              <option value="number:asc" <?= $selectedSort === 'number:asc' ? 'selected' : '' ?>>رقم المادة تصاعدي</option>
              <option value="number:desc" <?= $selectedSort === 'number:desc' ? 'selected' : '' ?>>رقم المادة تنازلي</option>
              <option value="materialType:asc,manufacturer:asc" <?= $selectedSort === 'materialType:asc,manufacturer:asc' ? 'selected' : '' ?>>النوع ثم الشركة</option>
              <option value="ageCategory:asc,materialType:asc" <?= $selectedSort === 'ageCategory:asc,materialType:asc' ? 'selected' : '' ?>>العمر ثم النوع</option>
            </select>
          </label>
        <?php endif; ?>

        <?php
          $facetMap = [
              'materialTypes' => 'نوع المادة',
              'ageCategories' => 'الفئة العمرية',
              'manufacturers' => 'الشركة',
              'sizeRanges' => 'القياس',
              'countryOfOrigins' => 'بلد المنشأ',
          ];
        ?>
        <?php foreach ($facetMap as $facetKey => $facetTitle): ?>
          <?php $values = $resultFilters[$facetKey] ?? []; ?>
          <?php if ($values !== []): ?>
            <fieldset class="md:col-span-2 rounded border border-gray-200 p-3">
              <legend class="text-sm text-gray-600 px-1"><?= h($facetTitle) ?></legend>
              <div class="grid grid-cols-2 gap-2 mt-2 text-sm">
                <?php foreach ($values as $facet): ?>
                  <?php
                    $facetValue = (string) ($facet['value'] ?? '');
                    $isChecked = in_array($facetValue, (array) ($_GET[$facetKey] ?? []), true);
                  ?>
                  <label class="inline-flex items-center gap-2">
                    <input type="checkbox" name="<?= h($facetKey) ?>[]" value="<?= h($facetValue) ?>" <?= $isChecked ? 'checked' : '' ?>>
                    <span><?= h($facetValue) ?> <small class="text-gray-500">(<?= (int) ($facet['count'] ?? 0) ?>)</small></span>
                  </label>
                <?php endforeach; ?>
              </div>
            </fieldset>
          <?php endif; ?>
        <?php endforeach; ?>

        <div class="md:col-span-4 flex gap-2 justify-end">
          <button class="h-11 rounded bg-primary text-white px-6 font-bold">تطبيق الفلاتر</button>
          <a href="/share.php?token=<?= urlencode($token) ?>" class="h-11 inline-flex items-center rounded border border-gray-300 px-6 text-sm">إعادة ضبط</a>
        </div>
      </form>
    <?php endif; ?>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
      <?php foreach ($products as $item): ?>
        <article class="border rounded-lg p-3 bg-white">
          <?php if ($showImages): ?>
            <div class="h-24 rounded bg-gray-100 flex items-center justify-center text-gray-500 text-xs mb-3">
              <?php if (!empty($item['productImageGuid'])): ?>
                <img
                  src="/api/image.php?id=<?= urlencode((string) $item['productImageGuid']) ?>&thumb=1"
                  alt="<?= h((string) ($item['name'] ?? 'صورة مادة')) ?>"
                  class="h-24 w-full object-cover rounded"
                  loading="lazy"
                >
              <?php else: ?>
                بدون صورة
              <?php endif; ?>
            </div>
          <?php endif; ?>
          <div class="font-semibold"><?= h((string) ($item['name'] ?? '-')) ?></div>
          <div class="text-xs text-gray-500"><?= h((string) ($item['materialCode'] ?? '')) ?></div>
          <div class="text-xs text-gray-500 mt-1">
            <?= h((string) ($item['manufacturer'] ?? '')) ?><?= !empty($item['materialType']) ? ' • ' . h((string) $item['materialType']) : '' ?>
          </div>

          <?php if ($showPriceSyp): ?>
            <div class="text-primary font-bold mt-2">
              <?= format_money((float) ($item['unitSalePriceSyp'] ?? 0), true) ?> ل.س
            </div>
          <?php endif; ?>
          <?php if ($showPriceUsd): ?>
            <div class="text-emerald-700 font-bold mt-1">
              $<?= number_format((float) ($item['unitSalePriceUsd'] ?? 0), 2, '.', ',') ?>
            </div>
          <?php endif; ?>
          <?php if ($showQuantity): ?>
            <div class="text-xs text-gray-500 mt-1">
              الكمية: <?= number_format((float) ($item['warehouseQuantity'] ?? 0), 2, '.', ',') ?>
            </div>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>

    <?php if ($products === [] && !$apiError && !$hasConstraintConflict): ?>
      <p class="text-gray-500 mt-4">لا توجد نتائج مطابقة.</p>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
$title = 'رابط مشاركة';
require dirname(__DIR__) . '/views/layout.php';
