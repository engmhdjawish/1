<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\ApiClient;

header('Content-Type: application/json; charset=utf-8');

WebSession::requirePermission('home_sections.manage');

$q = trim((string) ($_GET['q'] ?? ''));
if ($q === '') {
    echo json_encode(['ok' => true, 'items' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$items = [];
try {
    $response = ApiClient::get('/api/materials', [
        'page' => 1,
        'pageSize' => 24,
        'search' => $q,
    ]);
    if ($response['ok']) {
        $rows = is_array($response['data']['items'] ?? null) ? $response['data']['items'] : [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $guid = trim((string) ($row['materialGuid'] ?? $row['MaterialGuid'] ?? ''));
            if ($guid === '') {
                continue;
            }
            $name = trim((string) ($row['name'] ?? $row['Name'] ?? ''));
            $code = trim((string) ($row['materialCode'] ?? $row['MaterialCode'] ?? ''));
            $label = $name !== '' ? $name . ($code !== '' ? ' (' . $code . ')' : '') : $guid;
            $items[] = ['value' => $guid, 'label' => $label];
        }
    }
} catch (\Throwable) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'تعذر البحث عن المواد.'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
