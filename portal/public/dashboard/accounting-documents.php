<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\AccountingApiService;

WebSession::requireAnyPermission(['accounting.documents.view', 'orders.view']);
require dirname(__DIR__, 2) . '/views/helpers.php';

$kind = trim((string) ($_GET['kind'] ?? 'invoices'));
if (!in_array($kind, ['invoices', 'vouchers'], true)) {
    $kind = 'invoices';
}

$guid = trim((string) ($_GET['guid'] ?? ''));
$filters = [
    'keyword' => trim((string) ($_GET['keyword'] ?? '')),
    'typeGuid' => trim((string) ($_GET['typeGuid'] ?? '')),
    'fromDate' => trim((string) ($_GET['fromDate'] ?? '')),
    'toDate' => trim((string) ($_GET['toDate'] ?? '')),
    'page' => max(1, (int) ($_GET['page'] ?? 1)),
    'pageSize' => 50,
];

$error = null;
$rows = [];
$types = [];
$totalCount = 0;
$page = 1;
$pageSize = 50;
$totalPages = 1;
$user = WebSession::user();
$currentRoute = '/dashboard/accounting-documents.php';

try {
    if ($guid !== '') {
        $details = $kind === 'invoices'
            ? AccountingApiService::getInvoice($guid)
            : AccountingApiService::getVoucher($guid);

        $document = is_array($details['document'] ?? null) ? $details['document'] : [];
        $items = is_array($details['items'] ?? null) ? $details['items'] : [];
        $entryLines = is_array($details['entryLines'] ?? null) ? $details['entryLines'] : [];
        $meta = [
            'linesCount' => (int) ($details['linesCount'] ?? count($items)),
            'totalQuantity' => $details['totalQuantity'] ?? null,
        ];

        ob_start();
        require dirname(__DIR__, 2) . '/views/dashboard/partials/accounting-document-detail.php';
        $content = ob_get_clean();
        $title = $kind === 'invoices' ? 'تفاصيل فاتورة' : 'تفاصيل سند';
        require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
        return;
    }

    $types = $kind === 'invoices'
        ? AccountingApiService::invoiceTypes()
        : AccountingApiService::voucherTypes();

    $listParams = [
        'keyword' => $filters['keyword'],
        'typeGuid' => $filters['typeGuid'],
        'fromDate' => $filters['fromDate'],
        'toDate' => $filters['toDate'],
        'page' => $filters['page'],
        'pageSize' => $filters['pageSize'],
    ];

    $list = $kind === 'invoices'
        ? AccountingApiService::listInvoices($listParams)
        : AccountingApiService::listVouchers($listParams);

    $rows = $list['items'];
    $totalCount = $list['totalCount'];
    $page = $list['page'];
    $pageSize = $list['pageSize'];
    $totalPages = max(1, (int) ceil($totalCount / max(1, $pageSize)));
} catch (\Throwable $exception) {
    $error = $exception->getMessage();
}

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/accounting-documents.php';
$content = ob_get_clean();
$title = $kind === 'invoices' ? 'الفواتير' : 'السندات';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
