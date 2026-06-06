<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\ApiClient;

WebSession::requirePermission('orders.view');

header('Content-Type: application/json; charset=utf-8');

$action = trim((string) ($_GET['action'] ?? ''));
$guid = trim((string) ($_GET['guid'] ?? ''));

try {
    $result = match ($action) {
        'customers' => ApiClient::get('/api/customers', array_filter([
            'search' => trim((string) ($_GET['search'] ?? '')),
            'page' => max(1, (int) ($_GET['page'] ?? 1)),
            'pageSize' => min(50, max(1, (int) ($_GET['pageSize'] ?? 20))),
        ], static fn ($value) => $value !== '' && $value !== null)),
        'statement' => ApiClient::get('/api/accounts/statement', array_filter([
            'customerGuid' => trim((string) ($_GET['customerGuid'] ?? '')),
            'accountGuid' => trim((string) ($_GET['accountGuid'] ?? '')),
            'fromDate' => trim((string) ($_GET['fromDate'] ?? '')),
            'toDate' => trim((string) ($_GET['toDate'] ?? '')),
            'page' => max(1, (int) ($_GET['page'] ?? 1)),
            'pageSize' => min(500, max(1, (int) ($_GET['pageSize'] ?? 100))),
        ], static fn ($value) => $value !== '' && $value !== null)),
        'invoice' => $guid === ''
            ? ['ok' => false, 'status' => 400, 'error' => 'guid مطلوب']
            : ApiClient::get('/api/bills/invoices/' . rawurlencode($guid)),
        'voucher' => $guid === ''
            ? ['ok' => false, 'status' => 400, 'error' => 'guid مطلوب']
            : ApiClient::get('/api/bills/vouchers/' . rawurlencode($guid)),
        default => ['ok' => false, 'status' => 400, 'error' => 'إجراء غير معروف'],
    };

    http_response_code($result['status'] ?? ($result['ok'] ? 200 : 500));
    echo json_encode([
        'ok' => (bool) ($result['ok'] ?? false),
        'status' => $result['status'] ?? 0,
        'data' => $result['data'] ?? null,
        'error' => $result['error'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (\Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'status' => 500,
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
