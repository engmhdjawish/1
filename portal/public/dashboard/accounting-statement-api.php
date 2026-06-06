<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\AccountingApiService;

WebSession::requirePermission('orders.view');

header('Content-Type: application/json; charset=utf-8');

$action = trim((string) ($_GET['action'] ?? ''));
$guid = trim((string) ($_GET['guid'] ?? ''));

try {
    $data = match ($action) {
        'customers' => AccountingApiService::searchCustomers(
            trim((string) ($_GET['search'] ?? $_GET['keyword'] ?? '')),
            max(1, (int) ($_GET['page'] ?? 1)),
            min(50, max(1, (int) ($_GET['pageSize'] ?? 20))),
        ),
        'accounts' => AccountingApiService::searchAccounts(
            trim((string) ($_GET['search'] ?? $_GET['keyword'] ?? '')),
            max(1, (int) ($_GET['page'] ?? 1)),
            min(50, max(1, (int) ($_GET['pageSize'] ?? 20))),
        ),
        'statement' => AccountingApiService::getStatement(array_filter([
            'customerGuid' => trim((string) ($_GET['customerGuid'] ?? '')),
            'accountGuid' => trim((string) ($_GET['accountGuid'] ?? '')),
            'fromDate' => trim((string) ($_GET['fromDate'] ?? '')),
            'toDate' => trim((string) ($_GET['toDate'] ?? '')),
            'page' => max(1, (int) ($_GET['page'] ?? 1)),
            'pageSize' => min(500, max(1, (int) ($_GET['pageSize'] ?? 50))),
        ], static fn ($value) => $value !== '' && $value !== null)),
        'invoice' => $guid === ''
            ? throw new \InvalidArgumentException('guid مطلوب')
            : AccountingApiService::getInvoice($guid),
        'voucher' => $guid === ''
            ? throw new \InvalidArgumentException('guid مطلوب')
            : AccountingApiService::getVoucher($guid),
        default => throw new \InvalidArgumentException('إجراء غير معروف'),
    };

    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (\Throwable $exception) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
