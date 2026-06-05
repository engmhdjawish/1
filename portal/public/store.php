<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Auth\CustomerSession;
use Portal\Services\ApiClient;
use Portal\Services\StorePolicyService;

require dirname(__DIR__) . '/views/helpers.php';

$policy = StorePolicyService::guestPolicy();
if (CustomerSession::check()) {
    $customer = CustomerSession::customer();
    $policy = [
        'show_price' => $customer['show_price'],
        'show_quantity' => $customer['show_quantity'],
        'allow_cart' => $customer['allow_cart'],
        'allow_order' => $customer['allow_order'],
        'name_ar' => 'عميل مسجّل',
    ];
}

$products = [];
$apiError = null;
if ($policy) {
    try {
        $result = ApiClient::get('/api/materials', [
            'page' => (int) ($_GET['page'] ?? 1),
            'pageSize' => 24,
            'keyword' => trim($_GET['q'] ?? '') ?: null,
        ]);
        if ($result['ok']) {
            $products = $result['data']['items'] ?? [];
        } else {
            $apiError = 'تعذر جلب المواد من API (رمز ' . $result['status'] . ')';
        }
    } catch (\Throwable $exception) {
        $apiError = $exception->getMessage();
    }
}

ob_start();
?>
<div class="bg-white rounded-xl p-6 shadow-sm border">
  <h1 class="text-xl font-bold mb-2">المتجر</h1>
  <p class="text-sm text-gray-600 mb-4">سياسة العرض: <?= h($policy['name_ar'] ?? 'غير مضبوطة') ?></p>
  <?php if ($apiError): ?><p class="text-red-600 text-sm mb-4"><?= h($apiError) ?></p><?php endif; ?>
  <form method="get" class="mb-4 flex gap-2">
    <input name="q" value="<?= h($_GET['q'] ?? '') ?>" class="border rounded px-3 py-2 flex-1" placeholder="بحث...">
    <button class="bg-primary text-white px-4 rounded">بحث</button>
  </form>
  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
    <?php foreach ($products as $item): ?>
      <article class="border rounded-lg p-3">
        <div class="font-semibold"><?= h($item['name'] ?? '-') ?></div>
        <div class="text-xs text-gray-500"><?= h($item['code'] ?? '') ?></div>
        <?php if (!empty($policy['show_price'])): ?>
          <div class="text-primary font-bold mt-2"><?= format_money((float) ($item['unitSalePriceSyp'] ?? 0), true) ?> ل.س</div>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>
  <?php if ($products === [] && !$apiError): ?>
    <p class="text-gray-500 mt-4">لا توجد نتائج.</p>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
$title = 'المتجر';
require dirname(__DIR__) . '/views/layout.php';
