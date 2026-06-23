<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\AccountingApiService;

WebSession::requireAnyPermission(['accounting.statement.view', 'orders.view']);
require dirname(__DIR__, 2) . '/views/helpers.php';

$query = [
    'customerSearch' => trim((string) ($_GET['customerSearch'] ?? '')),
    'accountSearch' => trim((string) ($_GET['accountSearch'] ?? '')),
    'customerGuid' => trim((string) ($_GET['customerGuid'] ?? '')),
    'accountGuid' => trim((string) ($_GET['accountGuid'] ?? '')),
    'fromDate' => trim((string) ($_GET['fromDate'] ?? '')),
    'toDate' => trim((string) ($_GET['toDate'] ?? '')),
    'page' => max(1, (int) ($_GET['page'] ?? 1)),
    'pageSize' => max(10, min(100, (int) ($_GET['pageSize'] ?? 50))),
];

$error = null;
$customerMatches = [];
$accountMatches = [];
$selectedCustomerName = null;
$selectedAccountName = null;
$summary = null;
$entries = [];
$totalCount = 0;
$totalPages = 1;
$openingBalance = 0.0;
$totalDebit = 0.0;
$totalCredit = 0.0;
$closingBalance = 0.0;
$showStatement = false;

$resolveAccountLabel = static function (array $account): string {
    $name = trim((string) ($account['name'] ?? ''));
    $code = trim((string) ($account['code'] ?? ''));
    $number = (string) ($account['number'] ?? '');

    return $name !== ''
        ? $name . ($code !== '' ? ' (' . $code . ')' : ($number !== '' ? ' #' . $number : ''))
        : ($code !== '' ? $code : $number);
};

try {
    $shouldSearch = $query['customerGuid'] === '' && $query['accountGuid'] === '';
    if ($shouldSearch && $query['customerSearch'] !== '') {
        $customerMatches = AccountingApiService::searchCustomers($query['customerSearch'], 1, 20)['items'];
        if (count($customerMatches) === 1 && !empty($customerMatches[0]['guid'])) {
            $query['customerGuid'] = (string) $customerMatches[0]['guid'];
            $selectedCustomerName = (string) ($customerMatches[0]['customerName'] ?? '');
        }
    }

    if ($shouldSearch && $query['accountSearch'] !== '') {
        $accountMatches = AccountingApiService::searchAccounts($query['accountSearch'], 1, 20)['items'];
        if (count($accountMatches) === 1 && !empty($accountMatches[0]['guid'])) {
            $query['accountGuid'] = (string) $accountMatches[0]['guid'];
            $selectedAccountName = $resolveAccountLabel($accountMatches[0]);
        }
    }

    if ($query['customerGuid'] !== '' && $query['accountGuid'] === '') {
        $customer = AccountingApiService::getCustomer($query['customerGuid']);
        $selectedCustomerName = (string) ($customer['customerName'] ?? $selectedCustomerName ?? '');
        $linkedAccountGuid = trim((string) ($customer['accountGuid'] ?? ''));
        if ($linkedAccountGuid !== '') {
            $query['accountGuid'] = $linkedAccountGuid;
        }
    }

    if ($query['accountGuid'] !== '') {
        $account = AccountingApiService::getAccount($query['accountGuid']);
        $selectedAccountName = $resolveAccountLabel($account);
        if ($query['accountSearch'] === '' && $selectedAccountName !== '') {
            $query['accountSearch'] = $selectedAccountName;
        }
    }

    if ($query['customerGuid'] !== '' && $selectedCustomerName === null) {
        $customer = AccountingApiService::getCustomer($query['customerGuid']);
        $selectedCustomerName = (string) ($customer['customerName'] ?? '');
    }

    $showStatement = $query['accountGuid'] !== '' || $query['customerGuid'] !== '';
    if ($showStatement) {
        $statementParams = [
            'accountGuid' => $query['accountGuid'],
            'customerGuid' => $query['customerGuid'],
            'fromDate' => $query['fromDate'],
            'toDate' => $query['toDate'],
            'page' => $query['page'],
            'pageSize' => $query['pageSize'],
        ];

        $summary = AccountingApiService::getStatement($statementParams);
        $entries = is_array($summary['entries'] ?? null) ? $summary['entries'] : [];
        $totalCount = (int) ($summary['totalCount'] ?? count($entries));
        $totalPages = max(1, (int) ceil($totalCount / $query['pageSize']));
        $openingBalance = (float) ($summary['openingBalance'] ?? 0);

        if ($selectedCustomerName === null && !empty($summary['customerName'])) {
            $selectedCustomerName = (string) $summary['customerName'];
        }
        if ($selectedAccountName === null && !empty($summary['accountGuid'])) {
            try {
                $account = AccountingApiService::getAccount((string) $summary['accountGuid']);
                $selectedAccountName = $resolveAccountLabel($account);
            } catch (\Throwable) {
                $selectedAccountName = null;
            }
        }

        $totalsSource = $entries;
        if ($totalCount > 0 && $totalCount <= 2000 && $totalCount > count($entries)) {
            $full = AccountingApiService::getStatement(array_merge($statementParams, [
                'page' => 1,
                'pageSize' => $totalCount,
            ]));
            $totalsSource = is_array($full['entries'] ?? null) ? $full['entries'] : $entries;
        }

        foreach ($totalsSource as $entry) {
            $totalDebit += (float) ($entry['debit'] ?? 0);
            $totalCredit += (float) ($entry['credit'] ?? 0);
        }

        if ($totalsSource !== []) {
            $last = $totalsSource[array_key_last($totalsSource)];
            $closingBalance = (float) ($last['runningBalance'] ?? $openingBalance);
        } else {
            $closingBalance = $openingBalance;
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
$title = 'كشف الحساب';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
