<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Services\StoreCatalogService;
use Portal\Support\StoreCartRequest;

require dirname(__DIR__) . '/views/helpers.php';

$cartNotice = StoreCartRequest::handleAddToCartPost();
$guid = trim((string) ($_GET['guid'] ?? ''));
$returnUrl = trim((string) ($_GET['return'] ?? ''));
$offerSlug = trim((string) ($_GET['offer'] ?? ''));
$product = $guid !== '' ? StoreCatalogService::findMaterial($guid, $offerSlug !== '' ? $offerSlug : null) : null;

if ($product === null) {
    http_response_code(404);
    ob_start();
    ?>
    <section class="max-w-lg mx-auto text-center bg-white rounded-2xl border p-8">
      <span class="material-symbols-outlined text-5xl text-gray-300" aria-hidden="true">search_off</span>
      <h1 class="text-xl font-bold mt-4">المادة غير موجودة</h1>
      <p class="text-sm text-gray-600 mt-2">تعذر العثور على هذه المادة أو لم تعد متاحة.</p>
      <a href="/store.php" class="inline-flex mt-5 h-11 items-center rounded-xl bg-primary text-white px-5 font-bold">العودة للمتجر</a>
    </section>
    <?php
    $content = ob_get_clean();
    $title = 'مادة غير موجودة';
    require dirname(__DIR__) . '/views/layout.php';
    exit;
}

$displayOptions = StoreCatalogService::displayOptions();

ob_start();
require dirname(__DIR__) . '/views/product.php';
$content = ob_get_clean();
$title = (string) ($product['name'] ?? 'تفاصيل المادة');
require dirname(__DIR__) . '/views/layout.php';
