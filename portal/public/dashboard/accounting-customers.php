<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\AccountingApiService;

WebSession::requirePermission('accounting.customers.view');
require dirname(__DIR__, 2) . '/views/helpers.php';

$query = [
    'keyword' => trim((string) ($_GET['keyword'] ?? '')),
    'customerGuid' => trim((string) ($_GET['customerGuid'] ?? '')),
];

$error = null;
$customers = [];
$selectedCustomer = null;
$accountSummary = null;
$user = WebSession::user();
$currentRoute = '/dashboard/accounting-customers.php';

try {
    if ($query['keyword'] !== '') {
        $result = AccountingApiService::searchCustomers($query['keyword'], 1, 30);
        $customers = $result['items'];
    }

    if ($query['customerGuid'] !== '') {
        $selectedCustomer = AccountingApiService::getCustomer($query['customerGuid']);
        $accountSummary = AccountingApiService::getAccountSummary(null, $query['customerGuid']);
    }
} catch (\Throwable $exception) {
    $error = $exception->getMessage();
}

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/accounting-customers.php';
$content = ob_get_clean();
$title = 'عملاء الأمين';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
