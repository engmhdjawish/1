<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\AccountingApiService;

WebSession::requirePermission('orders.view');
require dirname(__DIR__, 2) . '/views/helpers.php';

$query = [
    'customerSearch' => trim((string) ($_GET['customerSearch'] ?? '')),
    'customerGuid' => trim((string) ($_GET['customerGuid'] ?? '')),
    'fromDate' => trim((string) ($_GET['fromDate'] ?? '')),
    'toDate' => trim((string) ($_GET['toDate'] ?? '')),
    'page' => max(1, (int) ($_GET['page'] ?? 1)),
    'pageSize' => 100,
];

$error = null;
$customerMatches = [];
$selectedCustomerName = null;
$summary = null;
$entries = [];
$totalDebit = 0.0;
$totalCredit = 0.0;
$closingBalance = 0.0;

try {
    if ($query['customerGuid'] === '' && $query['customerSearch'] !== '') {
        $lookup = AccountingApiService::searchCustomers($query['customerSearch'], 1, 20);
        $customerMatches = $lookup['items'];
        if (count($customerMatches) === 1 && !empty($customerMatches[0]['guid'])) {
            $query['customerGuid'] = (string) $customerMatches[0]['guid'];
            $selectedCustomerName = (string) ($customerMatches[0]['customerName'] ?? '');
        }
    }

    if ($query['customerGuid'] !== '') {
        if ($selectedCustomerName === null) {
            $customer = AccountingApiService::getCustomer($query['customerGuid']);
            $selectedCustomerName = (string) ($customer['customerName'] ?? '');
        }

        $summary = AccountingApiService::getStatement([
            'customerGuid' => $query['customerGuid'],
            'fromDate' => $query['fromDate'],
            'toDate' => $query['toDate'],
            'page' => $query['page'],
            'pageSize' => $query['pageSize'],
        ]);
        $entries = is_array($summary['entries'] ?? null) ? $summary['entries'] : [];

        foreach ($entries as $entry) {
            $totalDebit += (float) ($entry['debit'] ?? 0);
            $totalCredit += (float) ($entry['credit'] ?? 0);
        }

        $closingBalance = (float) ($summary['openingBalance'] ?? 0);
        if ($entries !== []) {
            $last = $entries[array_key_last($entries)];
            $closingBalance = (float) ($last['runningBalance'] ?? $closingBalance);
        }
    }
} catch (\Throwable $exception) {
    $error = $exception->getMessage();
}

$user = WebSession::user();
$currentRoute = '/dashboard/accounting-statement.php';

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/accounting-statement.php';
$content = ob_get_clean();
$title = 'كشف حساب';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
