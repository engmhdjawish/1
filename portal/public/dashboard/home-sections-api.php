<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\ApiClient;

header('Content-Type: application/json; charset=utf-8');

WebSession::requireAnyPermission(['home_sections.manage', 'special_offers.manage']);

$q = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = max(1, min(48, (int) ($_GET['pageSize'] ?? 24)));

if ($q === '') {
    echo json_encode([
        'ok' => true,
        'items' => [],
        'page' => $page,
        'pageSize' => $pageSize,
        'total' => 0,
        'hasMore' => false,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$items = [];
$total = 0;
$hasMore = false;

try {
    $response = ApiClient::get('/api/materials', [
        'page' => $page,
        'pageSize' => $pageSize,
        'search' => $q,
    ]);
    if ($response['ok']) {
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $rows = is_array($data['items'] ?? null) ? $data['items'] : [];
        $total = max(0, (int) ($data['totalCount'] ?? $data['TotalCount'] ?? 0));
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $guid = trim((string) ($row['materialGuid'] ?? $row['MaterialGuid'] ?? $row['guid'] ?? $row['Guid'] ?? ''));
            if ($guid === '') {
                continue;
            }
            $name = trim((string) ($row['name'] ?? $row['Name'] ?? ''));
            $code = trim((string) ($row['materialCode'] ?? $row['MaterialCode'] ?? ''));
            $label = $name !== '' ? $name . ($code !== '' ? ' (' . $code . ')' : '') : $guid;
            $items[] = ['value' => $guid, 'label' => $label];
        }
        $hasMore = ($page * $pageSize) < $total;
    }
} catch (\Throwable) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'تعذر البحث عن المواد.'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'items' => $items,
    'page' => $page,
    'pageSize' => $pageSize,
    'total' => $total,
    'hasMore' => $hasMore,
], JSON_UNESCAPED_UNICODE);
