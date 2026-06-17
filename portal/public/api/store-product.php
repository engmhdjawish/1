<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Services\ProductDisplayService;
use Portal\Services\StoreCatalogService;

require dirname(__DIR__, 2) . '/views/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$guid = trim((string) ($_GET['guid'] ?? ''));
$offerSlug = trim((string) ($_GET['offer'] ?? ''));

if ($guid === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'معرّف المادة مطلوب.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$product = StoreCatalogService::findMaterial($guid, $offerSlug !== '' ? $offerSlug : null);
if ($product === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'المادة غير موجودة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'product' => ProductDisplayService::quickViewPayload($product, StoreCatalogService::displayOptions()),
], JSON_UNESCAPED_UNICODE);
