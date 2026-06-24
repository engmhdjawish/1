<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\MaterialImageZipService;

WebSession::requireAnyPermission(['images.upload', 'images.view', 'orders.view']);
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

        MaterialImageZipService::streamLocalInvoiceImagesZip($billGuid, 'invoice-' . $number . '-images');
        exit;
    }

    if ($mode === 'bill') {
        $billGuid = trim((string) ($_GET['billGuid'] ?? ''));
        if ($billGuid === '') {
            throw new \RuntimeException('معرّف الفاتورة مطلوب.');
        }

        MaterialImageZipService::streamLocalInvoiceImagesZip($billGuid, 'bill-images');
        exit;
    }

    if ($mode === 'linked') {
        $linked = trim((string) ($_GET['linked'] ?? 'true')) !== 'false';
        $materialGuid = trim((string) ($_GET['materialGuid'] ?? ''));
        MaterialImageZipService::streamLocalLinkedImagesZip(
            $linked,
            $materialGuid !== '' ? $materialGuid : null
        );
        exit;
    }

    $splitBy = trim((string) ($_GET['splitBy'] ?? ''));
    if ($splitBy !== '') {
        MaterialImageZipService::streamSplitMaterialZips($_GET);
        exit;
    }

    $archiveName = trim((string) ($_GET['archiveName'] ?? ''));
    MaterialImageZipService::streamLocalMaterialImagesZip(
        $_GET,
        $archiveName !== '' ? $archiveName : 'filtered-material-images'
    );
} catch (\Throwable $exception) {
    if (!headers_sent()) {
        $message = $exception->getMessage();
        $status = str_contains($message, 'لم يتم العثور') || str_contains($message, 'لا توجد') ? 404 : 400;
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'ok' => false,
        'message' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
