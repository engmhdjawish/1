<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\MaterialImageZipService;

WebSession::requireAnyPermission(['images.upload', 'orders.view']);
require dirname(__DIR__, 2) . '/views/helpers.php';

$mode = trim((string) ($_GET['mode'] ?? 'materials'));

try {
    if ($mode === 'invoice') {
        $typeGuid = trim((string) ($_GET['typeGuid'] ?? ''));
        $typeName = trim((string) ($_GET['type'] ?? ''));
        $number = (int) ($_GET['number'] ?? 0);
        if ($number <= 0) {
            throw new \RuntimeException('أدخل رقم الفاتورة.');
        }

        $billGuid = MaterialImageZipService::findInvoiceGuid(
            $typeGuid !== '' ? $typeGuid : null,
            $typeName !== '' ? $typeName : null,
            $number
        );
        if ($billGuid === null) {
            throw new \RuntimeException('لم يتم العثور على فاتورة بهذا النوع والرقم.');
        }

        MaterialImageZipService::streamApiZip(
            '/api/material-images/download/bills/' . rawurlencode($billGuid),
            [],
            'invoice-' . $number . '-images'
        );
        exit;
    }

    if ($mode === 'bill') {
        $billGuid = trim((string) ($_GET['billGuid'] ?? ''));
        if ($billGuid === '') {
            throw new \RuntimeException('معرّف الفاتورة مطلوب.');
        }

        MaterialImageZipService::streamApiZip(
            '/api/material-images/download/bills/' . rawurlencode($billGuid),
            [],
            'bill-images'
        );
        exit;
    }

    if ($mode === 'linked') {
        $linked = trim((string) ($_GET['linked'] ?? 'true'));
        $query = ['linked' => $linked === 'false' ? 'false' : 'true'];
        if (trim((string) ($_GET['materialGuid'] ?? '')) !== '') {
            $query['materialGuid'] = trim((string) $_GET['materialGuid']);
        }
        MaterialImageZipService::streamApiZip('/api/material-images/download', $query, 'material-images');
        exit;
    }

  /** @var array<string, scalar|null> $query */
    $query = [];
    $allowed = [
        'search', 'storeGuid', 'storeGuids', 'countryOfOrigin', 'countryOfOrigins',
        'manufacturer', 'manufacturers', 'sizeRange', 'sizeRanges', 'materialType',
        'materialTypes', 'ageCategory', 'ageCategories', 'groupGuid', 'groupGuids',
        'minWarehouseQuantity', 'maxWarehouseQuantity', 'isAvailable',
    ];
    foreach ($allowed as $key) {
        if (!isset($_GET[$key])) {
            continue;
        }
        $value = $_GET[$key];
        if (is_array($value)) {
            continue;
        }
        $text = trim((string) $value);
        if ($text !== '') {
            $query[$key] = $text;
        }
    }

    MaterialImageZipService::streamApiZip('/api/material-images/download/materials', $query, 'filtered-material-images');
} catch (\Throwable $exception) {
    if (!headers_sent()) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'ok' => false,
        'message' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
